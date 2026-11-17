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
use MyShowcase\Repository\FieldValuesRepository;
use MyShowcase\Util\FieldDefaultUtil;
use MyShowcase\Util\MultipleChoiceUtil;
use Throwable;
use MyShowcase\Enum\FieldHtmlTypes;
use MyShowcase\Enum\SortDirections;
use MyShowcase\Entity\ShowcaseEntity;
use MysqlPdoDbDriver;
use PostgresPdoDbDriver;
use Random\RandomException;

use function MyShowcase\Plugin\Functions\fieldTypeMatchBinary;
use function MyShowcase\Plugin\Functions\fieldTypeMatchChar;
use function MyShowcase\Plugin\Functions\fieldsGet;
use function MyShowcase\Plugin\Functions\fieldTypeMatchDateTime;
use function MyShowcase\Plugin\Functions\isNullDateTypeValue;
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

    /** The blank date, time and datetime fields of the entry being written, stored as NULL (decision D129). */
    private array $null_columns = [];
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

        $rows = [];

        while ($entry = $this->database->fetch_array($query)) {
            $rows[] = $entry;
        }

        // The values of a multiple-choice field come from its child rows, not from the cache of the entry column (decision
        // D185): the option ids of the entry, in option order, so that the display reads them like a single value.
        $wanted = [];

        foreach ($this->multipleFieldIds() as $key => $field_id) {
            if (preg_match('/\b' . preg_quote($key, '/') . '\b/', (string)$query_fields) === 1) {
                $wanted[$key] = $field_id;
            }
        }

        if ($wanted !== [] && $rows !== []) {
            $rows = $this->withMultipleValues($rows, $wanted);
        }

        foreach ($rows as $entry) {
            yield $entry;
        }
    }

    /**
     * The multiple-choice fields of the showcase's collection: field key => field id (decision D184).
     *
     * @return array<string, int>
     */
    private function multipleFieldIds(): array
    {
        if ($this->multiple_field_ids === null) {
            $this->multiple_field_ids = [];

            foreach (fieldsGet(
                ["collection_id='" . (int)$this->showcase->collection_id . "'"],
                ['field_key', 'html_type', 'allow_multiple_values']
            ) as $field_id => $field) {
                if (MultipleChoiceUtil::isMultiple((string)$field['html_type'], $field['allow_multiple_values'])) {
                    $this->multiple_field_ids[(string)$field['field_key']] = (int)$field_id;
                }
            }
        }

        return $this->multiple_field_ids;
    }

    /**
     * Replaces the cache of each multiple-choice field in the rows by the option ids of the entry's child rows, in option
     * order and joined by the cache delimiter. A field with no value is ''.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, int> $wanted field key => field id
     * @return array<int, array<string, mixed>>
     */
    private function withMultipleValues(array $rows, array $wanted): array
    {
        $entry_ids = [];

        foreach ($rows as $row) {
            if (isset($row['entry_id'])) {
                $entry_ids[] = (int)$row['entry_id'];
            }
        }

        $ids_by_entry = [];

        if ($entry_ids !== []) {
            $query = $this->database->query(
                "SELECT v.entry_id, v.field_id, d.field_data_id FROM {$this->database->table_prefix}myshowcase_field_values v " .
                "INNER JOIN {$this->database->table_prefix}myshowcase_field_data d ON d.field_id=v.field_id AND d.value_id=v.value_id " .
                "WHERE v.showcase_id='" . (int)$this->showcase->showcase_id . "' AND v.entry_id IN (" . implode(',', array_unique($entry_ids)) . ") " .
                "AND v.field_id IN (" . implode(',', array_values($wanted)) . ") " .
                'ORDER BY v.entry_id, v.field_id, d.display_order, d.field_data_id'
            );

            while ($row = $this->database->fetch_array($query)) {
                $ids_by_entry[(int)$row['entry_id']][(int)$row['field_id']][] = (string)$row['field_data_id'];
            }
        }

        foreach ($rows as $index => $row) {
            foreach ($wanted as $key => $field_id) {
                if (!array_key_exists($key, $row)) {
                    continue;
                }

                $ids = $ids_by_entry[(int)($row['entry_id'] ?? 0)][$field_id] ?? [];
                $rows[$index][$key] = implode(FieldValuesRepository::CACHE_DELIMITER, $ids);
            }
        }

        return $rows;
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
    /**
     * Set while the caller already holds a transaction (the importer, decision D188): the repository then writes no
     * transaction of its own.
     */
    private bool $caller_transaction = false;

    /** @var array<string, int>|null the multiple-choice fields of the collection, see multipleFieldIds() */
    private ?array $multiple_field_ids = null;

    public function setCallerTransaction(bool $on): void
    {
        $this->caller_transaction = $on;
    }

    public function callerTransaction(): bool
    {
        return $this->caller_transaction;
    }

    /**
     * Writes the entry, then the rows and the cache of its multiple-choice fields (decisions D61 and D188). When the
     * entry has such fields and no caller holds a transaction, the whole write is one transaction here: a failed write
     * leaves neither the entry nor a row nor a cache.
     */
    public function insertEntry(EntryEntity $entry, array $custom_fields = [], bool $is_update = false): int
    {
        $own_transaction = !$this->caller_transaction && $entry->multiple_values !== [];
        $values = new FieldValuesRepository($this->database);

        if ($own_transaction) {
            $values->begin();
        }

        try {
            $entry_id = $this->writeEntry($entry, $custom_fields, $is_update);

            foreach ($entry->multiple_values as $field_key => $value_ids) {
                foreach ($custom_fields as $field_id => $field_definition) {
                    if ($field_definition['field_key'] === $field_key) {
                        $values->replace((int)$this->showcase->showcase_id, $entry_id, (int)$field_id, $value_ids);
                    }
                }
            }

            if ($own_transaction) {
                $values->commit();
            }
        } catch (Throwable $exception) {
            if ($own_transaction) {
                $values->rollback();
            }

            throw $exception;
        }

        return $entry_id;
    }

    private function writeEntry(EntryEntity $entry, array $custom_fields, bool $is_update): int
    {
        $this->entry = $entry;
        $this->entry_id = $entry->entry_id;
        $this->insert_data = [];
        $this->null_columns = [];
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

        $this->writeNullColumns((int)$entry->entry_id);

        return $entry->entry_id;
    }

    /**
     * Decision D129: a blank date, time or datetime value is stored as NULL. MyBB's query builder writes a PHP null as an
     * empty string, so the columns are set to the literal NULL in a query of their own, after the row is written.
     */
    private function writeNullColumns(int $entry_id): void
    {
        if ($this->null_columns === []) {
            return;
        }

        $this->database->update_query(
            'myshowcase_data' . $this->showcase->showcase_id,
            array_fill_keys($this->null_columns, 'NULL'),
            "entry_id='{$entry_id}'",
            '',
            true
        );
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

            if (isset($this->entry->extra_data[$key]) &&
                isNullDateTypeValue($this->entry->extra_data[$key], $field_definition['field_type'])) {
                unset($this->insert_data[$key]);
                $this->null_columns[] = $key;
            } elseif (isset($this->entry->extra_data[$key])) {
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

        (new FieldValuesRepository($this->database))->deleteForEntry(
            (int)$this->showcase->showcase_id,
            (int)$this->entry_id
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
        // A multiple-choice field has no option join: its values are read from the child rows (withMultipleValues()).
        $multiple = $this->multipleFieldIds();

        $query_fields = array_map(
            fn(string $name): string => str_starts_with($name, 'entry_data.') ? $name : 'entry_data.' . $name,
            array_keys(DATA_TABLE_STRUCTURE['myshowcase_data'])
        );

        $query_tables = [];

        /** @var CustomFieldEntity $field */
        foreach ($collection_fields as $field) {
            if (in_array($field->html_type, [FieldHtmlTypes::CheckBox, FieldHtmlTypes::Radio, FieldHtmlTypes::Select], true) &&
                !isset($multiple[$field->field_key])) {
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
            // A multiple-choice field is not sortable (decision D181): its column holds a cache, not one value.
            if (MultipleChoiceUtil::isMultiple((string)$field->html_type, $field->allow_multiple_values)) {
                continue;
            }

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

        // Decision D145: a blank date, time or datetime value is NULL, and sorts last in both directions on every engine
        // (a key of "IS NULL" in ascending order, before the column's own order). Other columns are sorted as before.
        $blank_last = '';

        foreach ($collection_fields as $field) {
            if ($field->field_key === $order_field && fieldTypeMatchDateTime($field->field_type)) {
                $blank_last = "entry_data.{$order_field} IS NULL, ";
            }
        }

        $query_options['order_by'] = $blank_last . "entry_data.{$order_field} {$order_dir}"
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