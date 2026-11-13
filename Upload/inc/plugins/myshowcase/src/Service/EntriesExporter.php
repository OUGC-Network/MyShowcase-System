<?php

declare(strict_types=1);

namespace MyShowcase\Service;

use MyShowcase\Collection\Collection;
use MyShowcase\Entity\CustomFieldEntity;
use MyShowcase\Enum\ContentStatuses;
use MyShowcase\Enum\FieldHtmlTypes;
use MyShowcase\ShowcaseContext;
use MyShowcase\Util\ImportLimits;
use MyShowcase\Util\XmlFile;

use function MyShowcase\Plugin\Functions\collectionGet;
use function MyShowcase\Plugin\Functions\fieldTypeMatchFloat;
use function MyShowcase\Plugin\Functions\fieldTypeMatchInt;

use const MyShowcase\SETTINGS;
use const MyShowcase\VERSION;

/**
 * Builds the XML of an entries export (design doc, sections 1, 4 and 9).
 *
 * An export holds what the exporting user could see on the showcase and nothing else: the entries the listing would
 * show them (approved ones, plus awaiting approval and soft-deleted ones only for a moderator who asks for them), and
 * the collection fields they can both view and fill. Whatever is chosen is written into the file's header as the
 * export's rules.
 */
class EntriesExporter
{
    /** Columns of the entries table an export can be sorted by, besides the exportable fields. */
    public const SORT_COLUMNS = ['dateline', 'update_dateline', 'subject', 'views'];

    public const FORMAT = 'myshowcase-entries';

    /**
     * Version 2 writes every file field as the public URL of its file (decision D48). Version 1 files, which have no file
     * fields, are still imported.
     */
    public const FORMAT_VERSION = 2;

    public function __construct(private readonly ShowcaseContext $context)
    {
    }

    /** The most entries one export holds. */
    public function maximumEntries(): int
    {
        return max(1, (int)(SETTINGS['exportsMaximumEntries'] ?? 5000));
    }

    /**
     * The fields the user may export: enabled, visible to their group and fillable by their group (so what they export
     * they could also import). A file field is exported as the URL of its file (format version 2).
     *
     * @return Collection<CustomFieldEntity>
     */
    public function exportableFields(): Collection
    {
        return $this->context->entries_manager->fillableFields()->filter(
            static fn(CustomFieldEntity $field): bool => $field->canView()
        );
    }

    /**
     * What can be chosen as content: 'subject' and 'message' when the showcase has them, then the exportable fields.
     *
     * @return string[] keys
     */
    public function selectableKeys(): array
    {
        $keys = [];

        if ($this->context->showcase->entries_enable_subject) {
            $keys[] = 'subject';
        }

        if ($this->context->showcase->entries_enable_message) {
            $keys[] = 'message';
        }

        foreach ($this->exportableFields() as $field) {
            $keys[] = $field->field_key;
        }

        return $keys;
    }

    /**
     * The sort choices: key => label (a language string name for the core columns, the field's label otherwise).
     *
     * @return array<string, string>
     */
    public function sortChoices(): array
    {
        $language = $this->context->language;

        $choices = [
            'dateline' => $language->myshowcase_import_export_sort_dateline,
            'update_dateline' => $language->myshowcase_import_export_sort_update_dateline,
            'views' => $language->myshowcase_import_export_sort_views,
        ];

        if ($this->context->showcase->entries_enable_subject) {
            $choices['subject'] = $language->myshowcase_import_export_sort_subject;
        }

        foreach ($this->exportableFields() as $field) {
            // A file field is not a column of the entries table: it cannot be a sort order.
            if ($field->html_type === FieldHtmlTypes::File) {
                continue;
            }

            $choices[$field->field_key] = $this->context->entries_manager->fieldLabel($field);
        }

        return $choices;
    }

    /**
     * Turns what the form posted into the export's rules: every value is checked against what the user may choose, and
     * what is not allowed is dropped or replaced by the default.
     *
     * @param array<string, mixed> $input
     * @return array{limit: int, limit_cap: int, order_by: string, order_dir: string, fields: string[], options: array<string, int>, removed_characters: int}
     */
    public function rulesFromInput(array $input): array
    {
        $cap = $this->maximumEntries();

        $limit = max(0, (int)($input['limit'] ?? 0));

        $sort_choices = $this->sortChoices();

        $order_by = (string)($input['order_by'] ?? '');

        if (!isset($sort_choices[$order_by])) {
            $order_by = 'dateline';
        }

        $order_dir = strtolower((string)($input['order_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $selectable = $this->selectableKeys();

        $requested = array_values(array_filter(
            array_map('strval', (array)($input['fields'] ?? [])),
            static fn(string $key): bool => in_array($key, $selectable, true)
        ));

        // Nothing chosen means everything the user can export, in the showcase's own order.
        $fields = $requested ? array_values(array_intersect($selectable, $requested)) : $selectable;

        return [
            'limit' => min($limit, $cap),
            'limit_cap' => $cap,
            'order_by' => $order_by,
            'order_dir' => $order_dir,
            'fields' => $fields,
            'options' => [
                'include_awaiting_approval' => $this->context->entries_manager->canViewStatus(ContentStatuses::AwaitingApproval) &&
                !empty($input['include_awaiting_approval']) ? 1 : 0,
                'include_soft_deleted' => $this->context->entries_manager->canViewStatus(ContentStatuses::SoftDeleted) &&
                !empty($input['include_soft_deleted']) ? 1 : 0,
            ],
            'removed_characters' => 0,
        ];
    }

    /**
     * Reads the entries the rules select and writes the XML.
     *
     * @param array<string, mixed> $rules from rulesFromInput()
     * @return array{xml: string, entry_count: int, rules: array<string, mixed>, notes: array{files_without_url: int, files_not_in_value: int}}
     * @throws ExportException when nothing matches, or the file would be larger than the import size cap
     */
    public function build(array $rules, XmlFile $xml_file): array
    {
        $rows = $this->readEntries($rules);

        if ($rows === []) {
            throw new ExportException(ExportException::REASON_EMPTY);
        }

        $fields = $this->selectedFields($rules);

        $file_fields = array_values(array_filter(
            $fields,
            static fn(CustomFieldEntity $field): bool => $field->html_type === FieldHtmlTypes::File
        ));

        $files = $this->fileUrls($rows, $file_fields);

        $entries = [];

        foreach ($rows as $row) {
            $entry = [];

            foreach (['subject', 'message'] as $core_key) {
                if (in_array($core_key, $rules['fields'], true)) {
                    $entry[$core_key] = $row[$core_key] ?? null;
                }
            }

            $entry['fields'] = [];

            foreach ($fields as $field) {
                if ($field->html_type === FieldHtmlTypes::File) {
                    // A file field holds the URL of its file, or nothing when the user may not download one (section 9).
                    $entry['fields'][$field->field_key] = $files['urls'][(int)$row['entry_id']][$field->field_key] ?? '';

                    continue;
                }

                $entry['fields'][$field->field_key] = $this->valueOf($row[$field->field_key] ?? null, $field);
            }

            $entries[] = $entry;
        }

        $data = ['header' => $this->header($rules, $fields, count($entries)), 'entries' => $entries];

        $removed = 0;

        $xml = $xml_file->write($data, removed_characters: $removed);

        if ($removed > 0) {
            // The count is part of the rules, which are part of the file: write again with the number in place.
            $data['header']['rules']['removed_characters'] = $removed;

            $xml = $xml_file->write($data, removed_characters: $removed);
        }

        $rules['removed_characters'] = $removed;

        $cap = ImportLimits::effectiveCap();

        if (strlen($xml) > $cap) {
            $header_only = strlen($xml_file->write(['header' => $data['header'], 'entries' => []]));

            $per_entry = max(1, (int)ceil((strlen($xml) - $header_only) / count($entries)));

            throw new ExportException(
                ExportException::REASON_TOO_LARGE,
                max(0, intdiv($cap - $header_only, $per_entry))
            );
        }

        return [
            'xml' => $xml,
            'entry_count' => count($entries),
            'rules' => $rules,
            // Files the export did not write as a URL, counted for the user (never written into the file).
            'notes' => $files['notes'],
        ];
    }

    /**
     * @param array<string, mixed> $rules
     * @return CustomFieldEntity[]
     */
    private function selectedFields(array $rules): array
    {
        $selected = [];

        foreach ($this->exportableFields() as $field) {
            if (in_array($field->field_key, $rules['fields'], true)) {
                $selected[] = $field;
            }
        }

        return $selected;
    }

    /**
     * @param array<string, mixed> $rules
     * @return array<int, array<string, mixed>>
     */
    private function readEntries(array $rules): array
    {
        $statuses = [ContentStatuses::VisibleApproved];

        if (!empty($rules['options']['include_awaiting_approval'])) {
            $statuses[] = ContentStatuses::AwaitingApproval;
        }

        if (!empty($rules['options']['include_soft_deleted'])) {
            $statuses[] = ContentStatuses::SoftDeleted;
        }

        // What the listing would show this user, narrowed to the statuses asked for.
        $where_clauses = ["entry_data.status IN ('" . implode("','", array_map('intval', $statuses)) . "')"];

        if ($visibility_clause = $this->context->entries_manager->visibilityWhereClause()) {
            $where_clauses[] = $visibility_clause;
        }

        $columns = ['entry_data.entry_id'];

        $file_keys = $this->fileFieldKeys();

        foreach ($rules['fields'] as $key) {
            // A file field is not a column: its value is read from the attachments (fileUrls()).
            if (in_array($key, $file_keys, true)) {
                continue;
            }

            // The keys were checked against selectableKeys(): they are core columns or field keys of the collection.
            $columns[] = 'entry_data.' . $key;
        }

        $limit = $rules['limit'] > 0 ? $rules['limit'] : $rules['limit_cap'];

        $direction = $rules['order_dir'] === 'asc' ? 'ASC' : 'DESC';

        $rows = [];

        foreach (
            $this->context->entries_repository->queryEntries(
                $where_clauses,
                implode(', ', array_unique($columns)),
                [
                    // The id breaks ties so that the same rules give the same file.
                    'order_by' => "entry_data.{$rules['order_by']} {$direction}, entry_data.entry_id",
                    'order_dir' => $direction,
                    'limit' => $limit,
                ]
            ) as $row
        ) {
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return string[] the keys of the exportable file fields */
    private function fileFieldKeys(): array
    {
        $keys = [];

        foreach ($this->exportableFields() as $field) {
            if ($field->html_type === FieldHtmlTypes::File) {
                $keys[] = $field->field_key;
            }
        }

        return $keys;
    }

    /**
     * The public URL of the file of each file field value (decision D48). The value of a field is the first file of that
     * field that the exporting user may download (the same rules as the download route); every other file is counted in
     * the notes, and a file with no URL leaves the value empty. No filesystem path is ever written.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param CustomFieldEntity[] $file_fields
     * @return array{urls: array<int, array<string, string>>, notes: array{files_without_url: int, files_not_in_value: int}}
     */
    private function fileUrls(array $rows, array $file_fields): array
    {
        $result = ['urls' => [], 'notes' => ['files_without_url' => 0, 'files_not_in_value' => 0]];

        if ($file_fields === [] || $rows === []) {
            return $result;
        }

        $entry_ids = array_map(static fn(array $row): int => (int)$row['entry_id'], $rows);

        $field_ids = array_map(static fn(CustomFieldEntity $field): int => (int)$field->field_id, $file_fields);

        $field_keys = [];

        foreach ($file_fields as $field) {
            $field_keys[(int)$field->field_id] = $field->field_key;
        }

        $attachments = [];

        foreach (
            $this->context->attachments_repository->queryAttachments(
                [
                    'entry_id IN (' . implode(',', array_map('intval', $entry_ids)) . ')',
                    'field_id IN (' . implode(',', array_map('intval', $field_ids)) . ')',
                ],
                ['attachment_id', 'public_id', 'attachment_name', 'user_id', 'status', 'mime_type', 'entry_id', 'field_id'],
                ['order_by' => 'attachment_id', 'order_dir' => 'ASC']
            ) as $attachment
        ) {
            $attachments[(int)$attachment['entry_id']][(int)$attachment['field_id']][] = $attachment;
        }

        $access = new AttachmentAccess($this->context);

        $types = $access->attachmentTypes();

        foreach ($attachments as $entry_id => $by_field) {
            foreach ($by_field as $field_id => $list) {
                if (!isset($field_keys[$field_id])) {
                    continue;
                }

                $chosen = false;

                foreach ($list as $attachment) {
                    if (!$access->mayDownload($attachment, $types)) {
                        $result['notes']['files_without_url']++;

                        continue;
                    }

                    if ($chosen !== false) {
                        $result['notes']['files_not_in_value']++;

                        continue;
                    }

                    $url = $access->publicUrl((string)$attachment['public_id'], (string)$attachment['attachment_name']);

                    if ($url === '') {
                        $result['notes']['files_without_url']++;

                        continue;
                    }

                    $chosen = $url;

                    $result['urls'][$entry_id][$field_keys[$field_id]] = $url;
                }
            }
        }

        return $result;
    }

    private function valueOf(mixed $value, CustomFieldEntity $field): mixed
    {
        if ($value === null) {
            return null;
        }

        if (fieldTypeMatchInt($field->field_type)) {
            return (int)$value;
        }

        if (fieldTypeMatchFloat($field->field_type)) {
            return (float)$value;
        }

        return (string)$value;
    }

    /**
     * @param array<string, mixed> $rules
     * @param CustomFieldEntity[] $fields
     * @return array<string, mixed>
     */
    private function header(array $rules, array $fields, int $entry_count): array
    {
        $showcase = $this->context->showcase;

        $collection = collectionGet(["collection_id='" . (int)$showcase->collection_id . "'"], ['name'])[$showcase->collection_id] ?? [];

        return [
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'myshowcase_version' => VERSION,
            'generated' => gmdate('c'),
            'showcase' => ['route_identifier' => (string)$showcase->route_identifier, 'name' => (string)$showcase->name],
            'collection' => ['name' => (string)($collection['name'] ?? '')],
            'entry_count' => $entry_count,
            'fields' => array_map(
                static fn(CustomFieldEntity $field): array => [
                    'field_key' => $field->field_key,
                    'html_type' => $field->html_type,
                    'field_type' => $field->field_type,
                ],
                $fields
            ),
            // Version 2: a file field holds the public URL of its file, or is empty when the exporting user may not download
            // it. The importer reads the URL and downloads the file (section 5.9).
            'rules' => $rules + ['file_fields' => 'public URL of the file; empty when the exporting user may not download it'],
        ];
    }
}
