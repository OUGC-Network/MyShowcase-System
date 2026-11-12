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

    public const FORMAT_VERSION = 1;

    public function __construct(private readonly ShowcaseContext $context)
    {
    }

    /** The most entries one export holds. */
    public function maximumEntries(): int
    {
        return max(1, (int)(SETTINGS['exportsMaximumEntries'] ?? 5000));
    }

    /**
     * The fields the user may export: enabled, visible to their group, fillable by their group (so what they export
     * they could also import) and not an attachment field.
     *
     * @return Collection<CustomFieldEntity>
     */
    public function exportableFields(): Collection
    {
        return $this->context->entries_manager->fillableFields()->filter(
            static fn(CustomFieldEntity $field): bool => $field->canView() && $field->html_type !== FieldHtmlTypes::File
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
     * @return array{xml: string, entry_count: int, rules: array<string, mixed>}
     * @throws ExportException when nothing matches, or the file would be larger than the import size cap
     */
    public function build(array $rules, XmlFile $xml_file): array
    {
        $rows = $this->readEntries($rules);

        if ($rows === []) {
            throw new ExportException(ExportException::REASON_EMPTY);
        }

        $fields = $this->selectedFields($rules);

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

        $cap = ImportLimits::sizeCap();

        if (strlen($xml) > $cap) {
            $header_only = strlen($xml_file->write(['header' => $data['header'], 'entries' => []]));

            $per_entry = max(1, (int)ceil((strlen($xml) - $header_only) / count($entries)));

            throw new ExportException(
                ExportException::REASON_TOO_LARGE,
                max(0, intdiv($cap - $header_only, $per_entry))
            );
        }

        return ['xml' => $xml, 'entry_count' => count($entries), 'rules' => $rules];
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

        foreach ($rules['fields'] as $key) {
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
            'rules' => $rules,
        ];
    }
}
