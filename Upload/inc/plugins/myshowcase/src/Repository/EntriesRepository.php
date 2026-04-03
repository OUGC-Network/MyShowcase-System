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
use MyShowcase\Enum\FieldDefaultTypes;
use MyShowcase\Enum\FieldHtmlTypes;
use MyShowcase\Enum\SortDirections;
use MyShowcase\Entity\ShowcaseEntity;
use MyShowcase\Util\UuidUtil;
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
use function MyShowcase\Plugin\Functions\sanitizeTableFieldValue;

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

        $query = $this->database->simple_select(
            implode(" LEFT JOIN {$this->database->table_prefix}", $query_tables),
            $query_fields,
            implode(' AND ', $where_clauses),
            $query_options
        );

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
        return $this->queryEntry(
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

        foreach (DATA_TABLE_STRUCTURE['myshowcase_data'] as $field_name => $field_definition) {
            if (isset($this->entry->$field_name) && $this->entry->$field_name !== null) {
                $this->insert_data[$field_name] = sanitizeTableFieldValue(
                    $this->entry->$field_name,
                    $field_definition['type']
                );
            } elseif (!$this->is_update) {
                $this->insert_data[$field_name] = sanitizeTableFieldValue(
                    $field_definition['default'] ?? '',
                    $field_definition['type']
                );
            }
        }

        foreach ($custom_fields as $field_id => $field_definition) {
            if (!isset($this->entry->extra_data[$field_definition['field_key']]) && !$this->is_update) {
                switch ($field_definition['default_type']) {
                    case FieldDefaultTypes::AsDefined:
                        $this->entry->extra_data[$field_definition['field_key']] = $field_definition['default_value'];

                        break;
                    case FieldDefaultTypes::CurrentTimestamp:
                        $this->entry->extra_data[$field_definition['field_key']] = date('Y-m-d H:i:s', TIME_NOW);

                        break;
                    case FieldDefaultTypes::UUID:
                        $this->entry->extra_data[$field_definition['field_key']] = (new UuidUtil())->generateUuidV4();

                        break;
                }
            }

            if (isset($this->entry->extra_data[$field_definition['field_key']])) {
                if (fieldTypeMatchInt($field_definition['field_type'])) {
                    $this->insert_data[$field_definition['field_key']] = (int)$this->entry->extra_data[$field_definition['field_key']];
                } elseif (fieldTypeMatchFloat($field_definition['field_type'])) {
                    $this->insert_data[$field_definition['field_key']] = (float)$this->entry->extra_data[$field_definition['field_key']];
                } elseif (fieldTypeMatchChar($field_definition['field_type']) ||
                    fieldTypeMatchText($field_definition['field_type']) ||
                    fieldTypeMatchDateTime($field_definition['field_type'])) {
                    $this->insert_data[$field_definition['field_key']] = $this->database->escape_string(
                        $this->entry->extra_data[$field_definition['field_key']]
                    );
                } elseif (fieldTypeMatchBinary($field_definition['field_type'])) {
                    $this->insert_data[$field_definition['field_key']] = $this->database->escape_binary(
                        $this->entry->extra_data[$field_definition['field_key']]
                    );
                }
            }
        }

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
                $this->insert_data
            );
        }

        return $entry->entry_id;
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
        Collection $fieldset_fields,
        array $where_clauses = [],
        array $query_options = []
    ): array {
        $query_tables = [];

        $query_fields = array_merge(
            array_map(function (string $field_name): string {
                return str_starts_with($field_name, 'entry_data.') ? $field_name : 'entry_data.' . $field_name;
            }, array_keys(DATA_TABLE_STRUCTURE['myshowcase_data'])),
            []
        );

        /**
         * @var CustomFieldEntity $field
         */
        foreach ($fieldset_fields as $field_id => $field) {
            if (in_array($field->html_type, [
                FieldHtmlTypes::CheckBox,
                FieldHtmlTypes::Radio,
                FieldHtmlTypes::Select
            ])) {
                $query_tables[] = "myshowcase_field_data table_{$field->field_key} ON (table_{$field->field_key}.field_data_id = entry_data.{$field->field_key} AND table_{$field->field_key}.field_id = '{$field->field_id}')";
            }

            $query_fields[] = $field->field_key;
        }

        if (!in_array($query_options['order_dir'] ?? '', [SortDirections::Ascending, SortDirections::Descending])) {
            $query_options['order_dir'] = SortDirections::Ascending;
        }

        $query_options['order_by'] = $query_options['order_by'] ?? $this->showcase->main_sort_default_field;

        $query_options['order_by'] = "entry_data.{$query_options['order_by']} " . ($query_options['order_dir'] ?? '') . ', entry_data.is_sticky ' . ($query_options['order_dir'] ?? '') . ', entry_data.entry_id';

        hooksRun('entry_fetch_start', $this);

        $total_entries = $this->queryEntryCount(
            $where_clauses,
            $query_tables
        );

        if (isset($query_options['limit']) && $query_options['limit'] > 0) {
            if ($this->mybb->input['page'] > ceil($total_entries / $query_options['limit'])) {
                $this->mybb->input['page'] = self::FIRST_PAGE;
            }

            if ($this->mybb->input['page']) {
                $query_options['limit_start'] = ($this->mybb->input['page'] - 1) * $query_options['limit'];
            }
        }

        hooksRun('entry_fetch_intermediate', $this);

        $entry_objects = [];

        foreach (
            $this->queryEntries(
                $where_clauses,
                $query_fields,
                $query_options,
                $query_tables
            ) as $entry
        ) {
            $entry_objects[] = $entry;
        }

        hooksRun('entry_fetch_end', $this);

        return $entry_objects;
    }

    public function fetchEntry(Collection $fieldset_fields, array $where_clauses = []): array
    {
        return $this->fetchEntries($fieldset_fields, $where_clauses, ['limit' => 1])[0] ?? [];
    }
}