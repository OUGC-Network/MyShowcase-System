<?php

declare(strict_types=1);

namespace MyShowcase\Repository;

use DB_MySQLi;
use DB_PgSQL;
use DB_SQLite;
use MysqlPdoDbDriver;
use PostgresPdoDbDriver;

use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\sanitizeTableFieldValue;

use const MyShowcase\Plugin\Core\TABLES_DATA;

class PermissionsRepository
{
    public const SCOPE_TYPE_SHOWCASE = 1;

    public const SCOPE_TYPE_ENTRY = 2;

    private ?int $permission_id;

    private array $insert_data = [];

    private array $permission_data = [];

    private readonly bool $is_update;

    public function __construct(
        private readonly DB_SQLite|DB_PgSQL|PostgresPdoDbDriver|MysqlPdoDbDriver|DB_MySQLi $db,
        private readonly array $config = ['showcase_id' => 0],
    ) {
    }

    /**
     * @return iterable<array>
     */
    public function queryPermissions(
        array $where_clauses = [],
        array|string $query_fields = [],
        array $query_options = [],
    ): iterable {
        if (is_array($query_fields)) {
            $query_fields = implode(', ', $query_fields);
        }

        $where_clauses[] = "showcase_id='{$this->config['showcase_id']}'";

        $query = $this->db->simple_select(
            'myshowcase_permissions',
            $query_fields,
            implode(' AND ', $where_clauses),
            $query_options
        );

        while ($entry_data = $this->db->fetch_array($query)) {
            yield $entry_data;
        }
    }

    public function queryPermission(
        array $where_clauses = [],
        array|string $query_fields = [],
        array $query_options = [],
    ): array {
        $query_options['limit'] = 1;

        $entry_object = [];

        foreach (
            $this->queryPermissions(
                $where_clauses,
                $query_fields,
                $query_options,
            ) as $entry_data
        ) {
            $entry_object = $entry_data;

            break;
        }

        return $entry_object;
    }

    public function queryPermissionCount(
        array $where_clauses = [],
    ): int {
        $where_clauses[] = "showcase_id='{$this->config['showcase_id']}'";

        return $this->queryPermission(
            $where_clauses,
            'COUNT(permission_id) AS total_permissions',
        )['total_permissions'];
    }

    public function insertPermission(
        array $permission_data,
        bool $is_update = false,
        ?int $permission_id = null
    ): int {
        $this->permission_data = $permission_data;

        $this->permission_id = $permission_id;

        $this->insert_data = [
            'showcase_id' => $this->config['showcase_id'],
        ];

        $this->is_update = $is_update;

        foreach (TABLES_DATA['myshowcase_permissions'] as $field_name => $field_definition) {
            if (isset($this->permission_data[$field_name])) {
                $this->insert_data[$field_name] = sanitizeTableFieldValue(
                    $this->permission_data[$field_name],
                    $field_definition['type']
                );
                $this->insert_data[$field_name] = $this->permission_data[$field_name];
            }
        }

        hooksRun('permission_insert_update', $this);

        if ($this->is_update) {
            $this->db->update_query(
                'myshowcase_permissions',
                $this->insert_data,
                "permission_id='{$this->permission_id}'"
            );

            return $this->permission_id;
        } else {
            $this->permission_id = (int)$this->db->insert_query(
                'myshowcase_permissions',
                $this->insert_data
            );
        }

        return $this->permission_id;
    }

    public function updatePermission(array $permission_data, int $permission_id): int
    {
        return $this->insertPermission($permission_data, true, $permission_id);
    }

    public function deletePermission(int $permission_id): void
    {
        $this->permission_id = $permission_id;

        hooksRun('permission_delete', $this);

        $this->db->delete_query(
            'myshowcase_permissions',
            "showcase_id='{$this->config['showcase_id']}' AND permission_id='{$this->permission_id}'"
        );
    }
}