<?php

declare(strict_types=1);

namespace MyShowcase\Repository;

use DB_MySQLi;
use DB_PgSQL;
use DB_SQLite;
use MyShowcase\Event\Event\AccessLogDeletedEvent;
use MyShowcase\Event\EventDispatcher;
use MyShowcase\Event\Event\AccessLogInsertedUpdatedEvent;
use MysqlPdoDbDriver;
use PostgresPdoDbDriver;

use function MyShowcase\Plugin\Functions\sanitizeTableFieldValue;

use const MyShowcase\Plugin\Core\TABLES_DATA;

class AccessLogsRepository
{
    public function __construct(
        private readonly DB_SQLite|DB_PgSQL|PostgresPdoDbDriver|MysqlPdoDbDriver|DB_MySQLi $database,
        private readonly array $config,
        private readonly EventDispatcher $event_dispatcher,
    ) {
    }

    /**
     * @return iterable<array>
     */
    public function queryAccessLogs(
        array $where_clauses = [],
        array|string $query_fields = [],
        array $query_options = []
    ): iterable {
        $where_clauses[] = "showcase_id='{$this->config['showcase_id']}'";

        if (empty($query_fields)) {
            $query_fields = 'access_log_id';
        }

        if (is_array($query_fields)) {
            $query_fields = implode(', ', $query_fields);
        }

        $query = $this->database->simple_select(
            'myshowcase_access_logs',
            $query_fields,
            implode(' AND ', $where_clauses),
            $query_options
        );

        while ($access_log_data = $this->database->fetch_array($query)) {
            yield $access_log_data;
        }
    }

    public function queryAccessLog(
        array $where_clauses = [],
        array|string $query_fields = [],
        array $query_options = []
    ): array {
        $query_options['limit'] = 1;

        $access_log_object = [];

        foreach (
            $this->queryAccessLogs(
                $where_clauses,
                $query_fields,
                $query_options
            ) as $access_log_data
        ) {
            $access_log_object = $access_log_data;

            break;
        }

        return $access_log_object;
    }

    public function queryAccessLogsCount(
        array $where_clauses = []
    ): int {
        return $this->queryAccessLog($where_clauses, 'COUNT(access_log_id) AS total_access_logs')['total_access_logs'];
    }

    public function insertAccessLog(array $access_log_data, bool $is_update = false, ?int $access_log_id = null): int
    {
        $insert_data = [
            'showcase_id' => $this->config['showcase_id'],
        ];

        foreach (TABLES_DATA['myshowcase_access_logs'] as $field_name => $field_definition) {
            if (isset($access_log_data[$field_name])) {
                $insert_data[$field_name] = sanitizeTableFieldValue(
                    $access_log_data[$field_name],
                    $field_definition['type']
                );
            }
        }

        if ($is_update) {
            $this->database->update_query('myshowcase_access_logs', $insert_data, "access_log_id='{$access_log_id}'");
        } else {
            $access_log_id = (int)$this->database->insert_query('myshowcase_access_logs', $insert_data);
        }

        $this->event_dispatcher->dispatch(
            new AccessLogInsertedUpdatedEvent($access_log_data, $is_update, $access_log_id),
            AccessLogInsertedUpdatedEvent::NAME
        );

        return $access_log_id;
    }

    public function updateAccessLog(array $update_data, int $access_log_id): int
    {
        return $this->insertAccessLog($update_data, true, $access_log_id);
    }

    public function updateAccessLogs(array $update_data, array $where_clauses): void
    {
        foreach ($this->queryAccessLogs($where_clauses) as $access_log_data) {
            $this->updateAccessLog($update_data, $access_log_data['access_log_id']);
        }
    }

    public function deleteAccessLog(int $access_log_id): void
    {
        $this->event_dispatcher->dispatch(
            new AccessLogDeletedEvent($access_log_id),
            AccessLogDeletedEvent::NAME
        );

        $access_log_data = $this->queryAccessLog(
            ["attachmen_id='{$access_log_id}'"],
            ['access_log_name', 'thumbnail_name', 'status']
        );

        $this->database->delete_query(
            'myshowcase_access_logs',
            "showcase_id='{$this->config['showcase_id']}' AND access_log_id='{$access_log_id}'"
        );
    }
}