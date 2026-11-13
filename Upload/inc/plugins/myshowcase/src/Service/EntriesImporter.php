<?php

declare(strict_types=1);

namespace MyShowcase\Service;

use MyShowcase\Entity\CustomFieldEntity;
use MyShowcase\Entity\EntryEntity;
use MyShowcase\Enum\FieldDefaultTypes;
use MyShowcase\Enum\FieldHtmlTypes;
use MyShowcase\ShowcaseContext;
use MyShowcase\Util\StringUtil;
use MyShowcase\Util\UuidUtil;
use MyShowcase\Util\XmlFile;
use MyShowcase\Util\XmlFileException;
use Throwable;

use function MyShowcase\Plugin\Functions\collectionGet;

/**
 * Imports the entries of an export file into the showcase (design doc, section 6 and decisions T1 to T7, T20 to T27).
 *
 * The order is fixed: parse, structural checks (ImportFileCheck), then for every entry, in file order, the same calls
 * the forum create form makes (generateEntry(), validateSet(), validateEntry()). Only when every entry passed, and the user
 * is within the entry limit for all of them together, are the entries written: one transaction (ImportTransaction), each
 * entry through validationEntryInsert() (the same insert, slug and hooks as a form post), and one import log row.
 *
 * Every entry is created as the importing user, with the status the form would give that user (newEntryStatus()).
 * Nothing but the subject, the message and the fillable fields of the file is read: allowedEntryInput() takes what the
 * form takes, and the rest comes from the server.
 */
class EntriesImporter
{
    /** type_id of the import rows of myshowcase_logs (0 entry, 1 comment, 2 attachment). */
    public const LOG_TYPE_IMPORT = 3;

    public function __construct(private readonly ShowcaseContext $context)
    {
    }

    /**
     * The fields the importing user may use in a file: their keys (every field of the collection, file fields included)
     * and the ones they may fill.
     *
     * @return array{known_keys: string[], fillable_keys: string[], file_keys: string[]}
     */
    public function keys(): array
    {
        $known = [];
        $fillable = [];
        $files = [];

        foreach ($this->context->custom_fields_collection as $field) {
            $known[] = $field->field_key;

            if ($field->html_type === FieldHtmlTypes::File) {
                $files[] = $field->field_key;
            } elseif ($field->canFill()) {
                $fillable[] = $field->field_key;
            }
        }

        return ['known_keys' => $known, 'fillable_keys' => $fillable, 'file_keys' => $files];
    }

    /**
     * @param string $xml_text the content of the uploaded file, already checked by ImportUpload
     */
    public function run(string $xml_text, XmlFile $xml_file, string $file_name, bool $validate_only = false): ImportReport
    {
        $showcase_name = (string)$this->context->showcase->name;

        try {
            $data = $xml_file->read($xml_text);
        } catch (XmlFileException $exception) {
            return ImportReport::refused($file_name, $showcase_name, [[
                'code' => $this->parseCode($exception),
                'args' => [$exception->xml_line],
            ]]);
        }

        $keys = $this->keys();

        $collection_name = $this->collectionName();

        $warnings = ImportFileCheck::warnings($data, ['collection_name' => $collection_name]);

        $problems = ImportFileCheck::problems($data, [
            'route_identifier' => (string)$this->context->showcase->route_identifier,
            'collection_name' => $collection_name,
            'known_keys' => $keys['known_keys'],
            'fillable_keys' => $keys['fillable_keys'],
            'file_keys' => $keys['file_keys'],
            'maximum_entries' => (new EntriesExporter($this->context))->maximumEntries(),
            'subject_enabled' => (bool)$this->context->showcase->entries_enable_subject,
            'message_enabled' => (bool)$this->context->showcase->entries_enable_message,
        ]);

        if ($problems !== []) {
            return ImportReport::refused($file_name, $showcase_name, $problems, $warnings);
        }

        $manager = $this->context->entries_manager;

        $entries = $data['entries'];

        $count = count($entries);

        $batch_hash = (new StringUtil())->generatePublicId();

        $status = $manager->newEntryStatus();

        $validated = [];

        $failures = [];

        foreach ($entries as $index => $raw) {
            $position = $index + 1;

            $entry = new EntryEntity();

            $manager->attachFields($entry);

            $entry_data = array_merge(
                (array)$entry,
                $manager->allowedEntryInput($this->postedValues($raw)),
                [
                    'entry_id' => $entry->entry_id,
                    'user_id' => (int)$this->context->current_user->uid,
                    'post_hash' => $batch_hash,
                ],
                ['status' => $status]
            );

            $preview = $manager->generateEntry($entry_data);

            $manager->validateSet($preview, EntriesManager::VALIDATION_METHOD_INSERT);

            if ($manager->validateEntry()) {
                $validated[] = $preview;
            } else {
                $failures[] = [
                    'position' => $position,
                    'subject' => (string)($raw['subject'] ?? ''),
                    'errors' => array_values($manager->validateGetError()),
                ];
            }
        }

        $limit_problems = $this->limitProblems($count);

        if ($failures !== [] || $limit_problems !== []) {
            return new ImportReport(
                ImportReport::FAILED,
                $file_name,
                $showcase_name,
                $count,
                0,
                $limit_problems,
                $failures,
                $warnings,
                count($validated),
            );
        }

        if ($validate_only) {
            return new ImportReport(ImportReport::VALIDATED, $file_name, $showcase_name, $count, 0, [], [], $warnings, $count);
        }

        return $this->write($validated, $file_name, $showcase_name, $batch_hash, $data, $warnings);
    }

    /**
     * Inserts the validated entries in one transaction. Any failure rolls the whole import back.
     *
     * @param EntryEntity[] $validated
     */
    private function write(array $validated, string $file_name, string $showcase_name, string $batch_hash, array $data, array $warnings): ImportReport
    {
        $manager = $this->context->entries_manager;

        $transaction = new ImportTransaction(
            $this->context->database,
            (int)$this->context->showcase->showcase_id,
            $batch_hash,
            SchemaManager::engine()
        );

        try {
            $transaction->begin();

            foreach ($validated as $entry) {
                $manager->validateSet($entry, EntriesManager::VALIDATION_METHOD_INSERT);

                $saved = $manager->validationEntryInsert();

                $transaction->record((int)$saved->entry_id);
            }

            $this->logImport($file_name, count($validated), $data);

            $transaction->commit();
        } catch (Throwable $exception) {
            $transaction->rollback();

            self::logWriteFailure($exception);

            return new ImportReport(
                ImportReport::FAILED,
                $file_name,
                $showcase_name,
                count($validated),
                0,
                [['code' => 'write', 'args' => []]],
                [],
                $warnings,
                count($validated),
            );
        }

        return new ImportReport(
            ImportReport::COMPLETED,
            $file_name,
            $showcase_name,
            count($validated),
            count($validated),
            [],
            [],
            $warnings,
            count($validated),
        );
    }

    /**
     * The values of one entry as the form would post them: the subject and message as given, and every fillable field:
     * the value of the file, or the field's default when the file leaves it out (T20). A file value of null is an absent one.
     *
     * @return array<string, mixed>
     */
    private function postedValues(array $raw): array
    {
        $posted = [];

        if (isset($raw['subject'])) {
            $posted['subject'] = $this->scalarText($raw['subject']);
        }

        if (isset($raw['message'])) {
            $posted['message'] = $this->scalarText($raw['message']);
        }

        $values = is_array($raw['fields'] ?? null) ? $raw['fields'] : [];

        foreach ($this->context->custom_fields_collection as $field) {
            if (!$field->canFill()) {
                continue;
            }

            $value = $values[$field->field_key] ?? null;

            $posted[$field->field_key] = $value === null ? $this->defaultValue($field) : $this->scalarText($value);
        }

        return $posted;
    }

    /**
     * The value a field gets when the file does not give one: the same pre-fill as the form (T20). A field with no
     * default is '' and the validator decides (a required one is then refused).
     */
    private function defaultValue(CustomFieldEntity $field): string
    {
        return match ((int)$field->default_type) {
            FieldDefaultTypes::AsDefined => (string)$field->default_value,
            FieldDefaultTypes::CurrentTimestamp => date('Y-m-d H:i:s', TIME_NOW),
            FieldDefaultTypes::UUID => (new UuidUtil())->generateUuidV4(),
            default => '',
        };
    }

    private function scalarText(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * The per-user entry limit, checked for the whole file (T21): entries already there plus those in the file must fit.
     *
     * @return list<array{code: string, args: list<mixed>}>
     */
    private function limitProblems(int $count): array
    {
        $manager = $this->context->entries_manager;

        $limit = $manager->entryPostLimit();

        if ($limit === 0 || $manager->canPostEntries($count)) {
            return [];
        }

        return [['code' => 'entry_limit', 'args' => [$limit, $manager->userEntriesCount(), $count]]];
    }

    /**
     * Writes the cause of a failed write to the board's error log, as the other errors of the board are written. The user
     * sees only the generic report (decision T6).
     */
    public static function logWriteFailure(Throwable $exception): void
    {
        global $error_handler;

        $line = self::describeFailure($exception);

        if (is_object($error_handler) && method_exists($error_handler, 'log_error')) {
            $error_handler->log_error(E_WARNING, 'MyShowcase import: the entries could not be written. ' . $line, $exception->getFile(), $exception->getLine());

            return;
        }

        error_log('MyShowcase import: the entries could not be written. ' . $line);
    }

    /**
     * The class, the message and the place of an exception, for the log. The message has its quoted literals removed: a
     * database error carries the query, and the query carries the values of the file. No path of the server beyond the
     * file's base name is written.
     */
    public static function describeFailure(Throwable $exception): string
    {
        $message = preg_replace(["/'(?:[^'\\\\]|\\\\.)*'/", '/"(?:[^"\\\\]|\\\\.)*"/'], '?', $exception->getMessage()) ?? '';

        return sprintf(
            '%s: %s (%s:%d)',
            $exception::class,
            substr($message, 0, 200),
            basename($exception->getFile()),
            $exception->getLine()
        );
    }

    /**
     * One row of myshowcase_logs for the import: who, when, which file (its name and its SHA-256) and how many entries.
     */
    private function logImport(string $file_name, int $entries, array $data): void
    {
        $this->context->database->insert_query('myshowcase_logs', [
            'showcase_id' => (int)$this->context->showcase->showcase_id,
            'type_id' => self::LOG_TYPE_IMPORT,
            'unique_id' => 0,
            'user_id' => (int)$this->context->current_user->uid,
            'log_time' => gmdate('Y-m-d H:i:s', TIME_NOW),
            'log_data' => json_encode([
                'file' => substr(basename($file_name), 0, 120),
                'entries' => $entries,
                'exported_at' => (string)($data['header']['generated'] ?? ''),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
        ]);
    }

    private function collectionName(): string
    {
        $collection = collectionGet(
            ["collection_id='" . (int)$this->context->showcase->collection_id . "'"],
            ['name']
        )[$this->context->showcase->collection_id] ?? [];

        return (string)($collection['name'] ?? '');
    }

    private function parseCode(XmlFileException $exception): string
    {
        return match ($exception->reason) {
            XmlFileException::REASON_DOCTYPE => 'doctype',
            XmlFileException::REASON_TOO_LARGE => 'too_large',
            XmlFileException::REASON_NOT_EXPORT => 'not_export',
            default => 'parse',
        };
    }
}
