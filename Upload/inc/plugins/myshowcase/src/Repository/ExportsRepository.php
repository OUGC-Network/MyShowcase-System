<?php

declare(strict_types=1);

namespace MyShowcase\Repository;

use DB_MySQLi;
use DB_PgSQL;
use DB_SQLite;
use MysqlPdoDbDriver;
use PostgresPdoDbDriver;

use function MyShowcase\Plugin\Functions\omitEmptyAutoIncrement;
use function MyShowcase\Plugin\Functions\sanitizeTableFieldValue;

use const MyShowcase\Plugin\Core\TABLES_DATA;

/**
 * Rows of myshowcase_exports. Not tied to one showcase: callers pass the showcase and the user in the conditions, and the
 * scheduled task that removes old exports works across all of them.
 */
class ExportsRepository
{
    public function __construct(
        private readonly DB_SQLite|DB_PgSQL|PostgresPdoDbDriver|MysqlPdoDbDriver|DB_MySQLi $database,
    ) {
    }

    /**
     * @param string[] $where_clauses
     * @param string[]|string $query_fields
     * @param array<string, mixed> $query_options
     * @return iterable<array>
     */
    public function queryExports(
        array $where_clauses = [],
        array|string $query_fields = [],
        array $query_options = []
    ): iterable {
        if (is_array($query_fields)) {
            $query_fields = $query_fields ? implode(', ', $query_fields) : '*';
        }

        $query = $this->database->simple_select(
            'myshowcase_exports',
            $query_fields,
            implode(' AND ', $where_clauses),
            $query_options
        );

        while ($export_data = $this->database->fetch_array($query)) {
            yield array_filter($export_data, 'is_string', ARRAY_FILTER_USE_KEY);
        }
    }

    /**
     * @param string[] $where_clauses
     * @return array<string, mixed> Empty when there is no such export.
     */
    public function queryExport(array $where_clauses = []): array
    {
        foreach ($this->queryExports($where_clauses, [], ['limit' => 1]) as $export_data) {
            return $export_data;
        }

        return [];
    }

    /**
     * @param string[] $where_clauses
     */
    public function queryExportCount(array $where_clauses = []): int
    {
        $query = $this->database->simple_select(
            'myshowcase_exports',
            'COUNT(export_id) AS total_exports',
            implode(' AND ', $where_clauses)
        );

        return (int)($this->database->fetch_array($query)['total_exports'] ?? 0);
    }

    /**
     * @param array<string, mixed> $export_data
     * @return int The id of the new row.
     */
    public function insertExport(array $export_data): int
    {
        return (int)$this->database->insert_query(
            'myshowcase_exports',
            omitEmptyAutoIncrement($this->rowOf($export_data, false), TABLES_DATA['myshowcase_exports'])
        );
    }

    /**
     * @param array<string, mixed> $export_data
     */
    public function updateExport(int $export_id, array $export_data): void
    {
        $this->database->update_query(
            'myshowcase_exports',
            $this->rowOf($export_data, true),
            "export_id='{$export_id}'"
        );
    }

    public function deleteExport(int $export_id): void
    {
        $this->database->delete_query('myshowcase_exports', "export_id='{$export_id}'");
    }

    /**
     * Escapes and types the values for the columns of the table; a missing column takes its default on an insert.
     *
     * @param array<string, mixed> $export_data
     * @return array<string, mixed>
     */
    private function rowOf(array $export_data, bool $is_update): array
    {
        $row = [];

        foreach (TABLES_DATA['myshowcase_exports'] as $column => $definition) {
            if (!is_array($definition) || !isset($definition['type'])) {
                continue;
            }

            if (array_key_exists($column, $export_data)) {
                $row[$column] = sanitizeTableFieldValue($export_data[$column], $definition['type']);
            } elseif (!$is_update) {
                $row[$column] = sanitizeTableFieldValue($definition['default'] ?? '', $definition['type']);
            }
        }

        return $row;
    }
}
