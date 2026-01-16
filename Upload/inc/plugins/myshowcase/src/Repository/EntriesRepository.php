<?php

declare(strict_types=1);

namespace MyShowcase\Repository;

use DB_MySQLi;
use DB_PgSQL;
use DB_SQLite;
use MyBB;
use MyShowcase\Entity\Entry;
use MyShowcase\Enum\CacheDataTypes;
use MyShowcase\Event\Event\EntryDeletedEvent;
use MyShowcase\Enum\FieldDefaultTypes;
use MyShowcase\Event\EventDispatcher;
use MyShowcase\Service\CacheManager;
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
    private ?int $entry_id;

    private array $insert_data = [];

    private Entry $entry;

    private bool $is_update;

    public function __construct(
        private readonly DB_SQLite|DB_PgSQL|PostgresPdoDbDriver|MysqlPdoDbDriver|DB_MySQLi $database,
        private readonly MyBB $mybb,
        private readonly EventDispatcher $event_dispatcher,
        private readonly array $config,
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
        $query_tables = array_merge(["myshowcase_data{$this->config['showcase_id']} entry_data"], $query_tables);

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
            ) as $entry
        ) {
            $entry_object = $entry;

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
        $query = $this->database->simple_select(
            'reportedcontent',
            'uid, reporters',
            "type='showcase_entries' AND id='{$this->config['showcase_id']}' AND id2='{$entry_id}' AND reportstatus!='1'"
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
    public function insertEntry(Entry $entry, bool $is_update = false): int
    {
        $this->entry = $entry;

        $this->entry_id = $entry->entry_id;

        $this->insert_data = [];

        $this->is_update = $is_update;

        foreach (DATA_TABLE_STRUCTURE['myshowcase_data'] as $field_name => $field_definition) {
            if (isset($this->entry->$field_name)) {
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

        $fields_cache = (new CacheManager($this->database, $this->mybb->cache,))
            ->get(CacheDataTypes::Fields);

        foreach ($fields_cache[$this->config['field_set_id']] ?? [] as $field_id => $field_definition) {
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
                'myshowcase_data' . $this->config['showcase_id'],
                $this->insert_data,
                "entry_id='{$entry->entry_id}'"
            );
        } else {
            $entry->entry_id = (int)$this->database->insert_query(
                'myshowcase_data' . $this->config['showcase_id'],
                $this->insert_data
            );
        }

        return $entry->entry_id;
    }

    /**
     * @throws RandomException
     */
    public function updateEntry(Entry $entry): int
    {
        return $this->insertEntry($entry, true);
    }

    public function deleteEntry(int $entry_id): void
    {
        $this->entry_id = $entry_id;

        hooksRun('entry_delete', $this);

        $this->database->delete_query(
            'myshowcase_data' . $this->config['showcase_id'],
            "entry_id='{$this->entry_id}'"
        );

        $this->event_dispatcher->dispatch(new EntryDeletedEvent($this->entry_id), EntryDeletedEvent::NAME);
    }

    public function shutDownQueryIncrementViews(int $entry_id): void
    {
        $this->database->shutdown_query(
            "UPDATE {$this->database->table_prefix}myshowcase_data{$this->config['showcase_id']} SET views=views+1 WHERE entry_id='{$entry_id}'"
        );
    }

    public function querySessions(): array
    {
        return [];
        $time_cut = TIME_NOW - $this->mybb->settings['wolcutoff'];

        $query = $this->database->simple_select(
            "sessions s LEFT JOIN {$this->database->table_prefix}users u ON (s.uid=u.uid)",
            's.ip, s.uid, u.username, u.avatar, s.time, u.invisible, u.usergroup, u.usergroup, u.displaygroup',
            "s.uid!=='0' AND s.time>'{$time_cut}' AND location LIKE '%" . THIS_SCRIPT . "%' AND nopermission!='1'",
            ['order_by' => 'u.username ASC, s.time DESC']
        );

        $session_objects = [];

        while ($session_object = $this->database->fetch_array($query)) {
            $session_objects[] = $session_object;
        }

        return $session_objects;
    }

    public function querySessionsCount(): int
    {
        $session_objects = [];
        return 0;
        $time_cut = TIME_NOW - $this->mybb->settings['wolcutoff'];

        $query = $this->database->simple_select(
            'sessions',
            'COUNT(DISTINCT ip) AS guest_count',
            "uid='0' AND time>'{$time_cut}' AND location LIKE '%" . THIS_SCRIPT . "%' AND nopermission!='1'"
        );

        return (int)$this->database->fetch_array($query)['guest_count'];
    }
}