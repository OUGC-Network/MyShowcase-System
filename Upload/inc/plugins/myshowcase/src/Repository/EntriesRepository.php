<?php

declare(strict_types=1);

namespace MyShowcase\Repository;

use DB_MySQLi;
use DB_PgSQL;
use DB_SQLite;
use MyBB;
use MyShowcase\Collection\Collection;
use MyShowcase\Entity\CustomFieldEntity;
use MyShowcase\Entity\EntryEntity;
use MyShowcase\Util\FieldDefaultUtil;
use MyShowcase\Enum\FieldHtmlTypes;
use MyShowcase\Enum\SortDirections;
use MyShowcase\Entity\ShowcaseEntity;
use MysqlPdoDbDriver;
use PostgresPdoDbDriver;
use Random\RandomException;

use function MyShowcase\Plugin\Functions\fieldTypeMatchBinary;
use function MyShowcase\Plugin\Functions\fieldTypeMatchChar;
use function MyShowcase\Plugin\Functions\fieldTypeMatchDateTime;
use function MyShowcase\Plugin\Functions\fieldTypeMatchFloat;
use function MyShowcase\Plugin\Functions\fieldTypeMatchInt;
use function MyShowcase\Plugin\Functions\fieldTypeMatchText;
use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\newRowDefault;
use function MyShowcase\Plugin\Functions\sanitizeTableFieldValue;

use function MyShowcase\Plugin\Functions\omitEmptyAutoIncrement;

use const MyShowcase\Plugin\Core\DATA_TABLE_STRUCTURE;

class EntriesRepository
{
    private const FIRST_PAGE = 1;
    private ?int $entry_id;
    private array $insert_data = [];
    private EntryEntity $entry;
    private bool $is_update;

    public function __construct(
        private readonly DB_SQLite|DB_PgSQL|PostgresPdoDbDriver|MysqlPdoDbDriver|DB_MySQLi $database,
        private readonly MyBB $mybb,
        private ?ShowcaseEntity $showcase = null,
    ) {
    }

    public function setShowcase(ShowcaseEntity $showcase): void
    {
        $this->showcase = $showcase;
    }

    /**
     * @return iterable<array>
     */
    public function queryEntries(
        array $where_clauses = [],
        array|string $query_fields = [],
        array $query_options = [],
        array $query_tables = [],
    ): iterable {
        $query_tables = array_merge(["myshowcase_data{$this->showcase->showcase_id} entry_data"], $query_tables);

        if (is_array($query_fields)) {
            $query_fields = implode(', ', $query_fields);
        }

        try {
            $query = $this->database->simple_select(
                implode(" LEFT JOIN {$this->database->table_prefix}", $query_tables),
                $query_fields,
                implode(' AND ', $where_clauses),
                $query_options
            );
        } catch (\Exception $e) {
            error($e->getMessage());
        }

        while ($entry = $this->database->fetch_array($query)) {
            yield $entry;
        }
    }

    public function queryEntry(
        array $where_clauses = [],
        array|string $query_fields = [],
        array $query_options = [],
        array $query_tables = [],
    ): array {
        $query_options['limit'] = 1;

        $entry_object = [];

        foreach (
            $this->queryEntries(
                $where_clauses,
                $query_fields,
                $query_options,
                $query_tables,
            ) as $entry
        ) {
            $entry_object = $entry;

            break;
        }

        return $entry_object;
    }

    public function queryEntryCount(
        array $where_clauses = [],
        array $query_tables = [],
    ): int {
        return (int)$this->queryEntry(
            $where_clauses,
            'COUNT(entry_id) AS total_entries',
            query_tables: $query_tables,
        )['total_entries'];
    }

    public function getContentReports(int $entry_id): array
    {
        $query = $this->database->simple_select(
            'reportedcontent',
            'uid, reporters',
            "type='showcase_entries' AND id='{$this->showcase->showcase_id}' AND id2='{$entry_id}' AND reportstatus!='1'"
        );

        if (!$this->database->num_rows($query)) {
            return [];
        }

        $report_data = $this->database->fetch_array($query);

        $reporters = my_unserialize($report_data['reporters']);

        if (empty($reporters) || !is_array($reporters)) {
            return [];
        }

        return array_filter(
            array_map(function ($uid): int {
                return (int)$uid;
            }, $reporters)
        );
    }

    /**
     * @throws RandomException
     */
    public function insertEntry(EntryEntity $entry, array $custom_fields = [], bool $is_update = false): int
    {
        $this->entry = $entry;
        $this->entry_id = $entry->entry_id;
        $this->insert_data = [];
        $this->is_update = $is_update;

        $this->prepareBaseEntryData();
        $this->prepareCustomFieldData($custom_fields);

        hooksRun('entry_insert_update_end', $this);

        if ($this->is_update) {
            $this->database->update_query(
                'myshowcase_data' . $this->showcase->showcase_id,
                $this->insert_data,
                "entry_id='{$entry->entry_id}'"
            );
        } else {
            $entry->entry_id = (int)$this->database->insert_query(
                'myshowcase_data' . $this->showcase->showcase_id,
                omitEmptyAutoIncrement($this->insert_data, DATA_TABLE_STRUCTURE['myshowcase_data'])
            );
        }

        return $entry->entry_id;
    }

    private function prepareBaseEntryData(): void
    {
        foreach (DATA_TABLE_STRUCTURE['myshowcase_data'] as $field_name => $field_definition) {
            if (isset($this->entry->$field_name) && $this->entry->$field_name !== null) {
                $this->insert_data[$field_name] = sanitizeTableFieldValue(
                    $this->entry->$field_name,
                    $field_definition['type']
                );
            } elseif (!$this->is_update) {
                $this->insert_data[$field_name] = sanitizeTableFieldValue(
                    newRowDefault($field_definition),
                    $field_definition['type']
                );
            }
        }
    }

    private function prepareCustomFieldData(array $custom_fields): void
    {
        foreach ($custom_fields as $field_definition) {
            $key = $field_definition['field_key'];

            if (!isset($this->entry->extra_data[$key]) && !$this->is_update) {
                $this->applyCustomFieldDefault($key, $field_definition);
            }

            if (isset($this->entry->extra_data[$key])) {
                $this->insert_data[$key] = $this->castCustomFieldValue(
                    $this->entry->extra_data[$key],
                    $field_definition['field_type']
                );
            }
        }
    }

    /**
     * @throws RandomException
     */
    private function applyCustomFieldDefault(string $key, array $field_definition): void
    {
        $this->entry->extra_data[$key] = FieldDefaultUtil::valueFor($field_definition);
    }

    private function castCustomFieldValue(mixed $value, string $field_type): mixed
    {
        if (fieldTypeMatchInt($field_type)) {
            return (int)$value;
        }

        if (fieldTypeMatchFloat($field_type)) {
            return (float)$value;
        }

        if (fieldTypeMatchBinary($field_type)) {
            return $this->database->escape_binary($value);
        }

        if (fieldTypeMatchChar($field_type) ||
            fieldTypeMatchText($field_type) ||
            fieldTypeMatchDateTime($field_type)
        ) {
            return $this->database->escape_string($value);
        }

        return $value;
    }

    /**
     * @throws RandomException
     */
    public function updateEntry(EntryEntity $entry, array $custom_fields = []): int
    {
        return $this->insertEntry($entry, $custom_fields, true);
    }

    public function deleteEntry(int $entry_id): void
    {
        $this->entry_id = $entry_id;

        hooksRun('entry_delete', $this);

        $this->database->delete_query(
            'myshowcase_data' . $this->showcase->showcase_id,
            "entry_id='{$this->entry_id}'"
        );

        $this->database->delete_query(
            'myshowcase_reads',
            "showcase_id='{$this->showcase->showcase_id}' AND entry_id='{$this->entry_id}'"
        );
    }

    public function shutDownQueryIncrementViews(int $entry_id): void
    {
        $this->database->shutdown_query(
            "UPDATE {$this->database->table_prefix}myshowcase_data{$this->showcase->showcase_id} SET views=views+1 WHERE entry_id='{$entry_id}'"
        );
    }

    public function querySessions(): array
    {
        return [];
    }

    public function querySessionsCount(): int
    {
        return 0;
    }

    public function fetchEntries(
        Collection $collection_fields,
        array $where_clauses = [],
        array $query_options = []
    ): array {
        [$query_fields, $query_tables] = $this->buildQueryFieldsAndTables($collection_fields);

        $query_options = $this->normalizeSortOptions($query_options, $collection_fields);

        hooksRun('entry_fetch_start', $this);

        $total_entries = $this->queryEntryCount($where_clauses, $query_tables);
        $query_options = $this->applyPagination($query_options, $total_entries);

        hooksRun('entry_fetch_intermediate', $this);

        $entry_objects = [];

        foreach ($this->queryEntries($where_clauses, $query_fields, $query_options, $query_tables) as $entry) {
            $entry_objects[] = $entry;
        }

        hooksRun('entry_fetch_end', $this);

        return $entry_objects;
    }

    private function buildQueryFieldsAndTables(Collection $collection_fields): array
    {
        $query_fields = array_map(
            fn(string $name): string => str_starts_with($name, 'entry_data.') ? $name : 'entry_data.' . $name,
            array_keys(DATA_TABLE_STRUCTURE['myshowcase_data'])
        );

        $query_tables = [];

        /** @var CustomFieldEntity $field */
        foreach ($collection_fields as $field) {
            if (in_array($field->html_type, [FieldHtmlTypes::CheckBox, FieldHtmlTypes::Radio, FieldHtmlTypes::Select]
            )) {
                $alias = "table_{$field->field_key}";
                $query_tables[] = "myshowcase_field_data {$alias} ON ({$alias}.field_data_id = entry_data.{$field->field_key} AND {$alias}.field_id = '{$field->field_id}')";
            }

            $query_fields[] = $field->field_key;
        }

        return [$query_fields, $query_tables];
    }

    /**
     * Columns an entry list may be sorted by: the fixed columns below (a few under the name the sort links use) and
     * the keys of the collection's fields. Anything else sorts by the posting date.
     */
    private function sortColumn(?string $requested, Collection $collection_fields): string
    {
        $columns = [
            'dateline' => 'dateline',
            'username' => 'username',
            'user_id' => 'user_id',
            'views' => 'views',
            'subject' => 'subject',
            'comments' => 'comments_approved',
            'comments_approved' => 'comments_approved',
            // There is no last_comment column: the id of the last comment is the closest recency value.
            'last_comment' => 'last_comment_id',
        ];

        /** @var CustomFieldEntity $field */
        foreach ($collection_fields as $field) {
            $columns[$field->field_key] ??= $field->field_key;
        }

        return $columns[(string)$requested] ?? 'dateline';
    }

    private function normalizeSortOptions(array $query_options, Collection $collection_fields): array
    {
        if (!in_array($query_options['order_dir'] ?? '', [SortDirections::Ascending, SortDirections::Descending])) {
            $query_options['order_dir'] = SortDirections::Ascending;
        }

        $order_field = $this->sortColumn(
            $query_options['order_by'] ?? $this->showcase->main_sort_default_field,
            $collection_fields
        );
        $order_dir = $query_options['order_dir'];

        $query_options['order_by'] = "entry_data.{$order_field} {$order_dir}"
            . ", entry_data.is_sticky {$order_dir}"
            . ', entry_data.entry_id';

        return $query_options;
    }

    private function applyPagination(array $query_options, int $total_entries): array
    {
        // A single-entry lookup is not a page of a list: it must neither use nor reset the page of the request.
        if (!empty($query_options['single_entry'])) {
            unset($query_options['single_entry']);

            return $query_options;
        }

        if (!isset($query_options['limit']) || $query_options['limit'] <= 0) {
            return $query_options;
        }

        $limit = $query_options['limit'];
        $max_page = (int)ceil($total_entries / $limit);

        if ($this->mybb->input['page'] > $max_page) {
            $this->mybb->input['page'] = self::FIRST_PAGE;
        }

        if ($this->mybb->input['page']) {
            $query_options['limit_start'] = ($this->mybb->input['page'] - 1) * $limit;
        }

        return $query_options;
    }

    /** The number of entries fetchEntries() would list: the same tables, so conditions on option values work. */
    public function countEntries(Collection $collection_fields, array $where_clauses = []): int
    {
        return $this->queryEntryCount($where_clauses, $this->buildQueryFieldsAndTables($collection_fields)[1]);
    }

    public function fetchEntry(Collection $collection_fields, array $where_clauses = []): array
    {
        return $this->fetchEntries($collection_fields, $where_clauses, ['limit' => 1, 'single_entry' => true])[0] ?? [];
    }
}