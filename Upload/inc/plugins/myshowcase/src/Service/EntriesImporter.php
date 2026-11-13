<?php

declare(strict_types=1);

namespace MyShowcase\Service;

use MyShowcase\Entity\CustomFieldEntity;
use MyShowcase\Entity\EntryEntity;
use MyShowcase\Enum\FieldDefaultTypes;
use MyShowcase\Enum\FieldHtmlTypes;
use MyShowcase\ShowcaseContext;
use MyShowcase\Util\ExternalFileException;
use MyShowcase\Util\ExternalFileLimits;
use MyShowcase\Util\ExternalUrl;
use MyShowcase\Util\ImportSettings;
use MyShowcase\Util\StringUtil;
use MyShowcase\Util\UuidUtil;
use MyShowcase\Util\XmlFile;
use MyShowcase\Util\XmlFileException;
use RuntimeException;
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

            // A file field the user may not fill is known but not fillable, so a file that names it is refused.
            if (!$field->canFill()) {
                continue;
            }

            if ($field->html_type === FieldHtmlTypes::File) {
                $files[] = $field->field_key;
            } else {
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
            'external_files' => ImportSettings::externalFilesEnabled(),
        ]);

        if ($problems !== []) {
            return ImportReport::refused($file_name, $showcase_name, $problems, $warnings);
        }

        $manager = $this->context->entries_manager;

        $entries = $data['entries'];

        $count = count($entries);

        // The limits of the files of the whole import, checked before any download (section 5.8.5).
        $file_count = $this->fileValueCount($entries, $keys['file_keys']);

        $time_budget = ExternalFileLimits::timeBudget();

        if ($file_count > 0 && $file_count > ExternalFileLimits::MAX_FILES) {
            return ImportReport::refused($file_name, $showcase_name, [[
                'code' => 'too_many_files',
                'args' => [$file_count, ExternalFileLimits::MAX_FILES],
            ]], $warnings);
        }

        if ($file_count > 0 && !ExternalFileBudget::fits($file_count, $time_budget)) {
            return ImportReport::refused($file_name, $showcase_name, [['code' => 'time_budget', 'args' => []]], $warnings);
        }

        $batch_hash = (new StringUtil())->generatePublicId();

        $status = $manager->newEntryStatus();

        $temp = new ExternalFileTempArea();

        // Whatever the request does after this point, the temporary files are removed (section 5.8.9).
        register_shutdown_function([$temp, 'cleanup']);

        $budget = new ExternalFileBudget($time_budget, microtime(true));

        $validated = [];

        $failures = [];

        try {
            foreach ($entries as $index => $raw) {
                $position = $index + 1;

                $prepared = $this->prepareFiles($raw, $temp, $budget);

                if ($prepared['errors'] !== []) {
                    $failures[] = [
                        'position' => $position,
                        'subject' => (string)($raw['subject'] ?? ''),
                        'errors' => $prepared['errors'],
                    ];

                    continue;
                }

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

                // The files are checked and will be attached: the validator counts them as attachments of their fields.
                $manager->pending_file_counts = $prepared['counts'];

                $preview = $manager->generateEntry($entry_data);

                $manager->validateSet($preview, EntriesManager::VALIDATION_METHOD_INSERT);

                $valid = $manager->validateEntry();

                $manager->pending_file_counts = [];

                if ($valid) {
                    $validated[] = ['entry' => $preview, 'files' => $prepared['files'], 'counts' => $prepared['counts']];
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
        } finally {
            $manager->pending_file_counts = [];

            $temp->cleanup();
        }
    }

    /**
     * Inserts the validated entries in one transaction, and attaches their files through the form's attachment pipeline
     * (section 5.9). Any failure rolls the whole import back, and removes every file it stored.
     *
     * @param array<int, array{entry: EntryEntity, files: array<int, array<string, mixed>>, counts: array<int, int>}> $validated
     */
    private function write(array $validated, string $file_name, string $showcase_name, string $batch_hash, array $data, array $warnings): ImportReport
    {
        $manager = $this->context->entries_manager;

        $uploads = rtrim((string)$this->context->showcase->attachments_uploads_path, '/');

        $transaction = new ImportTransaction(
            $this->context->database,
            (int)$this->context->showcase->showcase_id,
            $batch_hash,
            SchemaManager::engine()
        );

        // The paths of the files this import stored (a file and its thumbnail), removed if the import fails.
        $stored = [];

        $external = 0;

        try {
            $transaction->begin();

            foreach ($validated as $item) {
                $manager->pending_file_counts = $item['counts'];

                try {
                    $manager->validateSet($item['entry'], EntriesManager::VALIDATION_METHOD_INSERT);

                    $saved = $manager->validationEntryInsert();
                } finally {
                    $manager->pending_file_counts = [];
                }

                $transaction->record((int)$saved->entry_id);

                foreach ($item['files'] as $file) {
                    // A new manager per file: the attachments manager keeps the data of the upload it made last.
                    $result = (new AttachmentsManager($this->context))->uploadAttachment(
                        [
                            'name' => $file['name'],
                            'type' => $file['mime'],
                            'tmp_name' => $file['path'],
                            'size' => $file['size'],
                            'error' => 0,
                            'local_file' => true,
                        ],
                        $saved,
                        (int)$file['field_id'],
                        $file['definition'],
                    );

                    if (isset($result['error'])) {
                        throw new RuntimeException('attachment');
                    }

                    $stored[] = $uploads . '/' . (string)$result['file_name'];

                    if (!empty($result['thumbnail_name'])) {
                        $stored[] = $uploads . '/' . (string)$result['thumbnail_name'];
                    }

                    $transaction->recordAttachment((int)$result['attachment_id']);

                    $external++;
                }
            }

            $this->logImport($file_name, count($validated), $data, $external);

            $transaction->commit();
        } catch (Throwable $exception) {
            $transaction->rollback();

            foreach ($stored as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }

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
     * The number of file values the file holds (a non-empty value of a file field, in any entry).
     *
     * @param array<int, mixed> $entries
     * @param string[] $file_keys
     */
    private function fileValueCount(array $entries, array $file_keys): int
    {
        $count = 0;

        foreach ($entries as $entry) {
            $fields = is_array($entry['fields'] ?? null) ? $entry['fields'] : [];

            foreach ($file_keys as $key) {
                if (isset($fields[$key]) && $fields[$key] !== '') {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Takes the files of one entry: each file field value is a URL, read and checked (section 5.9, phase 1). Nothing is
     * written. A failure of any file is an error of the entry.
     *
     * @return array{files: array<int, array<string, mixed>>, errors: string[], counts: array<int, int>}
     */
    private function prepareFiles(array $raw, ExternalFileTempArea $temp, ExternalFileBudget $budget): array
    {
        $result = ['files' => [], 'errors' => [], 'counts' => []];

        $values = is_array($raw['fields'] ?? null) ? $raw['fields'] : [];

        $language = $this->context->language;

        foreach ($this->context->custom_fields_collection as $field) {
            if ($field->html_type !== FieldHtmlTypes::File || !$field->canFill()) {
                continue;
            }

            $value = $values[$field->field_key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $field_id = (int)$field->field_id;

            try {
                $file = $this->takeFile($this->scalarText($value), $field, $temp, $budget);
            } catch (ExternalFileException $exception) {
                $result['errors'][] = $language->sprintf(
                    $language->myshowcase_import_file_error,
                    $this->context->entries_manager->fieldLabel($field),
                    $this->reasonText($exception->reason)
                );

                continue;
            }

            $result['files'][] = $file + [
                'field_id' => $field_id,
                'definition' => $this->context->collection_fields[$field_id],
            ];

            $result['counts'][$field_id] = ($result['counts'][$field_id] ?? 0) + 1;
        }

        return $result;
    }

    /**
     * One file of an import: the URL's syntax, then either this board's own file (decision D52) or the fetcher, then the
     * checks an upload makes. The result is the checked file in the temporary area.
     *
     * @return array{path: string, name: string, mime: string, size: int}
     * @throws ExternalFileException
     */
    private function takeFile(string $text, CustomFieldEntity $field, ExternalFileTempArea $temp, ExternalFileBudget $budget): array
    {
        $access = new AttachmentAccess($this->context);

        $url = ExternalUrl::parse($text, $access->ownPorts($text));

        $types = $access->attachmentTypes();

        $field_bytes = $field->maximum_size > 0 ? $field->maximum_size * 1024 : 0;

        $own = $access->ownAttachment($url);

        if ($own !== null) {
            if ($access->decide($own, false, $types) !== AttachmentAccess::ALLOWED) {
                throw new ExternalFileException('not_downloadable');
            }

            $source = rtrim((string)$this->context->showcase->attachments_uploads_path, '/') . '/' . (string)$own['file_name'];

            $path = $temp->newFile('.part');

            if (!is_file($source) || !copy($source, $path)) {
                throw new ExternalFileException('unknown');
            }

            $download = new ExternalFileDownload($path, (int)filesize($path), $text);
        } else {
            if (!ImportSettings::externalFilesEnabled()) {
                throw new ExternalFileException('disabled');
            }

            if (!function_exists('curl_init')) {
                throw new ExternalFileException('curl_missing');
            }

            $budget->reserveFile();

            $max = $field_bytes > 0 ? min($field_bytes, ExternalFileLimits::CEILING_BYTES) : ExternalFileLimits::CEILING_BYTES;

            $fetcher = new ExternalFileFetcher(new DnsExternalFileResolver(), new CurlExternalFileTransport(), $temp);

            $download = $fetcher->download($text, $max, $budget->secondsLeft(microtime(true)));

            try {
                $budget->addBytes($download->bytes);
            } catch (ExternalFileException $exception) {
                @unlink($download->path);

                throw $exception;
            }
        }

        $checked = (new ExternalFileAcceptance($types))->accept($download, $url, $field_bytes);

        return ['path' => $download->path, 'name' => $checked['name'], 'mime' => $checked['mime'], 'size' => $checked['size']];
    }

    private function reasonText(string $reason): string
    {
        $language = $this->context->language;

        return (string)($language->{'myshowcase_import_file_reason_' . $reason} ?? $language->myshowcase_import_file_reason_unknown);
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

            // A file field is never posted as a value: its file is attached after the entry is saved, as the form does
            // (the validator counts the attachments of the field instead).
            if ($field->html_type === FieldHtmlTypes::File) {
                $posted[$field->field_key] = '';

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
    private function logImport(string $file_name, int $entries, array $data, int $external_files = 0): void
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
                'external_files' => $external_files,
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
