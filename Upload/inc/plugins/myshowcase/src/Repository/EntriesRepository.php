<?php

declare(strict_types=1);

namespace MyShowcase\Repository;

use DB_MySQLi;
use DB_PgSQL;
use DB_SQLite;
use MyShowcase\Event\Event\EntryDeletedEvent;
use MyShowcase\Enum\FieldDefaultTypes;
use MyShowcase\Event\EventDispatcher;
use MysqlPdoDbDriver;
use PostgresPdoDbDriver;
use Random\RandomException;

use function MyShowcase\Plugin\Functions\cacheGet;
use function MyShowcase\Plugin\Functions\fieldTypeMatchBinary;
use function MyShowcase\Plugin\Functions\fieldTypeMatchChar;
use function MyShowcase\Plugin\Functions\fieldTypeMatchDateTime;
use function MyShowcase\Plugin\Functions\fieldTypeMatchFloat;
use function MyShowcase\Plugin\Functions\fieldTypeMatchInt;
use function MyShowcase\Plugin\Functions\fieldTypeMatchText;
use function MyShowcase\Plugin\Functions\generateUUIDv4;
use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\sanitizeTableFieldValue;

use const MyShowcase\Plugin\Core\CACHE_TYPE_FIELDS;
use const MyShowcase\Plugin\Core\DATA_TABLE_STRUCTURE;

class EntriesRepository
{
    private ?int $entry_id;

    private array $insert_data = [];

    private array $entry_data = [];

    private readonly bool $is_update;

    public function __construct(
        private readonly DB_SQLite|DB_PgSQL|PostgresPdoDbDriver|MysqlPdoDbDriver|DB_MySQLi $db,
        private readonly int $showcase_id,
        private readonly EventDispatcher $event_dispatcher,
    ) {
    }

    /**
     * @return iterable<array>
     */
    public function queryEntries(
        array $where_clauses = [],
        array|string $query_fields = [],
        array $query_options = [],
        array $query_tables = []
    ): iterable {
        $query_tables = array_merge(["myshowcase_data{$this->showcase_id} entry_data"], $query_tables);

        if (is_array($query_fields)) {
            $query_fields = implode(', ', $query_fields);
        }

        $query = $this->db->simple_select(
            implode(" LEFT JOIN {$this->db->table_prefix}", $query_tables),
            $query_fields,
            implode(' AND ', $where_clauses),
            $query_options
        );

        while ($entry_data = $this->db->fetch_array($query)) {
            yield $entry_data;
        }
    }

    public function queryEntry(
        array $where_clauses = [],
        array|string $query_fields = [],
        array $query_options = [],
        array $query_tables = []
    ): array {
        $query_options['limit'] = 1;

        $entry_object = [];

        foreach (
            $this->queryEntries(
                $where_clauses,
                $query_fields,
                $query_options,
                $query_tables
            ) as $entry_data
        ) {
            $entry_object = $entry_data;

            break;
        }

        return $entry_object;
    }

    public function queryEntryCount(
        array $where_clauses = [],
        array $query_tables = []
    ): int {
        return $this->queryEntry(
            $where_clauses,
            'COUNT(entry_id) AS total_entries',
            query_tables: $query_tables
        )['total_entries'];
    }

    public function getContentReports(int $entry_id): array
    {
        global $db;

        $query = $db->simple_select(
            'reportedcontent',
            'uid, reporters',
            "type='showcase_entries' AND id='{$this->showcase_id}' AND id2='{$entry_id}' AND reportstatus!='1'"
        );

        if (!$db->num_rows($query)) {
            return [];
        }

        $report_data = $db->fetch_array($query);

        $reporters = my_unserialize($report_data['reporters']);

        if (empty($reporters) || !is_array($reporters)) {
            return [];
        }

        return array_filter(array_map(function ($uid): int {
            return (int)$uid;
        }, $reporters));
    }

    /**
     * @throws RandomException
     */
    public function insertEntry(array $entry_data, bool $is_update = false, ?int $entry_id = null): int
    {
        $this->entry_data = $entry_data;

        $this->entry_id = $entry_id;

        $this->insert_data = [];

        $this->is_update = $is_update;

        foreach (DATA_TABLE_STRUCTURE['myshowcase_data'] as $field_name => $field_definition) {
            if (isset($this->entry_data[$field_name])) {
                $this->insert_data[$field_name] = sanitizeTableFieldValue(
                    $this->entry_data[$field_name],
                    $field_definition['type']
                );
            } elseif (!$this->is_update) {
                $this->insert_data[$field_name] = sanitizeTableFieldValue(
                    $field_definition['default'] ?? '',
                    $field_definition['type']
                );
            }
        }

        foreach (cacheGet(CACHE_TYPE_FIELDS)[$this->config['field_set_id']] ?? [] as $field_id => $field_definition) {
            if (!isset($this->entry_data[$field_definition['field_key']]) && !$this->is_update) {
                switch ($field_definition['default_type']) {
                    case FieldDefaultTypes::AsDefined:
                        $this->entry_data[$field_definition['field_key']] = $this->db->escape_string(
                            $field_definition['default_value']
                        );
                        break;
                    case FieldDefaultTypes::CurrentTimestamp:
                        $this->entry_data[$field_definition['field_key']] = $this->db->escape_string(
                            date('Y-m-d H:i:s', TIME_NOW)
                        );
                        break;
                    case FieldDefaultTypes::UUID:
                        $this->entry_data[$field_definition['field_key']] = $this->db->escape_string(generateUUIDv4());
                        break;
                }
            }

            if (isset($this->entry_data[$field_definition['field_key']])) {
                if (fieldTypeMatchInt($field_definition['field_type'])) {
                    $this->insert_data[$field_definition['field_key']] = (int)$this->entry_data[$field_definition['field_key']];
                } elseif (fieldTypeMatchFloat($field_definition['field_type'])) {
                    $this->insert_data[$field_definition['field_key']] = (float)$this->entry_data[$field_definition['field_key']];
                } elseif (fieldTypeMatchChar($field_definition['field_type']) ||
                    fieldTypeMatchText($field_definition['field_type']) ||
                    fieldTypeMatchDateTime($field_definition['field_type'])) {
                    $this->insert_data[$field_definition['field_key']] = $this->db->escape_string(
                        $this->entry_data[$field_definition['field_key']]
                    );
                } elseif (fieldTypeMatchBinary($field_definition['field_type'])) {
                    $this->insert_data[$field_definition['field_key']] = $this->db->escape_binary(
                        $this->entry_data[$field_definition['field_key']]
                    );
                }
            }
        }

        hooksRun('entry_insert_update_end', $this);

        if ($this->is_update) {
            $this->db->update_query(
                'myshowcase_data' . $this->showcase_id,
                $this->insert_data,
                "entry_id='{$this->entry_id}'"
            );

            return $this->entry_id;
        } else {
            $this->entry_id = (int)$this->db->insert_query('myshowcase_data' . $this->showcase_id, $this->insert_data);
        }

        return $this->entry_id;
    }

    /**
     * @throws RandomException
     */
    public function updateEntry(array $entry_data, int $entry_id): int
    {
        return $this->insertEntry($entry_data, true, $entry_id);
    }

    public function deleteEntry(int $entry_id): void
    {
        $this->entry_id = $entry_id;

        hooksRun('entry_delete', $this);

        $this->db->delete_query(
            'myshowcase_data' . $this->showcase_id,
            "entry_id='{$this->entry_id}'"
        );

        $this->event_dispatcher->dispatch(new EntryDeletedEvent($this->entry_id), EntryDeletedEvent::NAME);
    }
}