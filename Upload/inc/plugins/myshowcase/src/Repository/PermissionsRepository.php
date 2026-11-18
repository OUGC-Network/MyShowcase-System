<?php

declare(strict_types=1);

namespace MyShowcase\Repository;

use DB_MySQLi;
use DB_PgSQL;
use DB_SQLite;
use MyShowcase\Entity\ShowcaseEntity;
use MysqlPdoDbDriver;
use PostgresPdoDbDriver;

use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\omitEmptyAutoIncrement;
use function MyShowcase\Plugin\Functions\sanitizeTableFieldValue;

use const MyShowcase\Plugin\Core\TABLES_DATA;

class PermissionsRepository
{
    private ?int $permission_id;
    private array $insert_data = [];
    private array $permission_data = [];
    private readonly bool $is_update;

    public function __construct(
        private readonly DB_SQLite|DB_PgSQL|PostgresPdoDbDriver|MysqlPdoDbDriver|DB_MySQLi $database,
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
    public function queryPermissions(
        array $where_clauses = [],
        array|string $query_fields = [],
        array $query_options = [],
    ): iterable {
        if (is_array($query_fields)) {
            $query_fields = implode(', ', $query_fields);
        }

        $where_clauses[] = "showcase_id='" . ($this->showcase->showcase_id ?? 0.) . "'";

        $query = $this->database->simple_select(
            'myshowcase_permissions',
            $query_fields,
            implode(' AND ', $where_clauses),
            $query_options
        );

        while ($entry = $this->database->fetch_array($query)) {
            yield $entry;
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
            ) as $entry
        ) {
            $entry_object = $entry;

            break;
        }

        return $entry_object;
    }

    public function queryPermissionCount(
        array $where_clauses = [],
    ): int {
        $where_clauses[] = "showcase_id='" . ($this->showcase->showcase_id ?? 0.) . "'";

        return $this->queryPermission(
            $where_clauses,
            'COUNT(permission_id) AS total_permissions',
        )['total_permissions'];
    }

    /** The columns that identify a row; every other column of the table is a permission. */
    private const IDENTIFIER_COLUMNS = ['permission_id', 'showcase_id', 'group_id', 'moderator_id', 'user_id'];

    /**
     * The defaults of a global row (showcase_id 0): every permission column with its default from TABLES_DATA. These are the
     * values the ACP group form shows for a group that has no row (decision D217), so a seeded row and the form agree.
     *
     * @return array<string, mixed>
     */
    public static function defaultValues(): array
    {
        $values = [];

        foreach (TABLES_DATA['myshowcase_permissions'] as $field_name => $field_definition) {
            if (in_array($field_name, self::IDENTIFIER_COLUMNS, true) || !array_key_exists('default', $field_definition)) {
                continue;
            }

            $values[$field_name] = $field_definition['default'];
        }

        return $values;
    }

    /**
     * Decision D217: every user group has a global row (showcase_id 0) from the installation and every activation on. A group
     * that has no row gets one with the defaults; a row that exists is never changed, so a second call adds nothing. Returns
     * the number of rows added. Per-showcase rows are not touched.
     */
    public function seedMissingGlobalRows(): int
    {
        $with_row = [];

        $query = $this->database->simple_select('myshowcase_permissions', 'group_id', "showcase_id='0'");

        while ($row = $this->database->fetch_array($query)) {
            $with_row[(int)$row['group_id']] = true;
        }

        $groups = [];

        $query = $this->database->simple_select('usergroups', 'gid');

        while ($group = $this->database->fetch_array($query)) {
            $groups[] = (int)$group['gid'];
        }

        $added = 0;

        foreach ($groups as $group_id) {
            if (isset($with_row[$group_id])) {
                continue;
            }

            // one repository per row: the flag of an insert is readonly and set once per instance
            (new self($this->database))->insertPermission(['group_id' => $group_id] + self::defaultValues());

            $added++;
        }

        return $added;
    }

    public function insertPermission(
        array $permission_data,
        bool $is_update = false,
        ?int $permission_id = null
    ): int {
        $this->permission_data = $permission_data;

        $this->permission_id = $permission_id;

        $this->insert_data = [
            'showcase_id' => $this->showcase->showcase_id ?? 0,
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
            $this->database->update_query(
                'myshowcase_permissions',
                $this->insert_data,
                "permission_id='{$this->permission_id}'"
            );

            return $this->permission_id;
        } else {
            $this->permission_id = (int)$this->database->insert_query('myshowcase_permissions', omitEmptyAutoIncrement($this->insert_data, TABLES_DATA['myshowcase_permissions']));
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

        $this->database->delete_query(
            'myshowcase_permissions',
            "showcase_id='" . ($this->showcase->showcase_id ?? 0.) . "' AND permission_id='{$this->permission_id}'"
        );
    }
}