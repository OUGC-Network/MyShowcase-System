<?php
/**
 * MyShowcase Plugin for MyBB - Main Plugin
 * Copyright 2012 CommunityPlugins.com, All Rights Reserved
 *
 * Website: https://github.com/Sama34/MyShowcase-System
 * Version 2.5.2
 * License: Creative Commons Attribution-NonCommerical ShareAlike 3.0
 * http://creativecommons.org/licenses/by-nc-sa/3.0/legalcode
 * File: \inc\plugins\myshowcase\src\Service\SchemaManager.php
 *
 */

declare(strict_types=1);

namespace MyShowcase\Service;

/**
 * Builds and runs the plugin's schema statements for every database engine MyBB supports.
 *
 * The statement builders (*Sql methods) are pure: they only need the engine name and the table prefix, so the SQL
 * that will run on an engine can be reviewed without that engine being installed. The executors run those statements
 * through the board database object.
 *
 * Engine names: mysql (mysqli, mysql_pdo), pgsql (pgsql, pgsql_pdo) and sqlite.
 */
final class SchemaManager
{
    public const ENGINE_MYSQL = 'mysql';

    public const ENGINE_PGSQL = 'pgsql';

    public const ENGINE_SQLITE = 'sqlite';

    /** Keys of a table definition that are not columns. */
    private const RESERVED_KEYS = ['unique_keys', 'keys', 'full_keys'];

    private const INT_TYPES = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'];

    private const FLOAT_TYPES = ['decimal', 'float', 'double'];

    private const CHAR_TYPES = ['char', 'varchar'];

    private const TEXT_TYPES = ['tinytext', 'text', 'mediumtext'];

    private const DATETIME_TYPES = ['date', 'time', 'datetime', 'timestamp'];

    private const BINARY_TYPES = ['binary', 'varbinary'];

    public static function engine(): string
    {
        global $db;

        $type = (string)($db->type ?? '');

        if ($type === '') {
            $class = get_class($db);

            $type = match (true) {
                stripos($class, 'sqlite') !== false => 'sqlite',
                stripos($class, 'pgsql') !== false, stripos($class, 'postgres') !== false => 'pgsql',
                default => 'mysqli',
            };
        }

        return self::engineFromType($type);
    }

    public static function engineFromType(string $type): string
    {
        return match (true) {
            str_starts_with($type, 'pgsql') => self::ENGINE_PGSQL,
            $type === 'sqlite' => self::ENGINE_SQLITE,
            default => self::ENGINE_MYSQL,
        };
    }

    // ------------------------------------------------------------------------------------------------------------
    // Pure statement builders
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Splits a table definition (as found in TABLES_DATA and DATA_TABLE_STRUCTURE) into its parts.
     *
     * Every unique_key flag found on a column becomes a unique key named <column>_uq.
     *
     * @return array{columns: array<string, array>, primary: string|null, unique: array<string, string[]>, keys: array<string, string[]>, full: array<string, string[]>}
     */
    public static function schemaOf(array $tableObject): array
    {
        $schema = ['columns' => [], 'primary' => null, 'unique' => [], 'keys' => [], 'full' => []];

        foreach ($tableObject as $name => $definition) {
            if (in_array($name, self::RESERVED_KEYS, true) || !is_array($definition) || !isset($definition['type'])) {
                continue;
            }

            $schema['columns'][$name] = $definition;

            if (!empty($definition['primary_key'])) {
                $schema['primary'] = $name;
            }

            if (!empty($definition['unique_key'])) {
                $schema['unique'][$name . '_uq'] = [$name];
            }
        }

        foreach (['unique_keys' => 'unique', 'keys' => 'keys', 'full_keys' => 'full'] as $source => $target) {
            foreach ((array)($tableObject[$source] ?? []) as $keyName => $columns) {
                $schema[$target][(string)$keyName] = array_values((array)$columns);
            }
        }

        return $schema;
    }

    /**
     * Column definition, without the column name, for the given engine.
     *
     * @param bool $addingColumn The column is added to a table that may already hold rows, so NOT NULL needs a default
     *                           on engines that refuse to add such a column.
     */
    public static function columnDefinition(
        array $definition,
        string $engine,
        callable $escape,
        bool $addingColumn = false
    ): string {
        $type = strtolower((string)$definition['type']);

        $size = $definition['size'] ?? null;

        $isAutoIncrement = !empty($definition['auto_increment']);

        if ($isAutoIncrement && $engine === self::ENGINE_SQLITE) {
            // SQLite only auto-increments an INTEGER PRIMARY KEY column.
            return 'INTEGER PRIMARY KEY';
        }

        $sql = self::typeSql($type, $size, $engine, $isAutoIncrement, !empty($definition['unsigned']));

        if (!empty($definition['unsigned']) && $engine === self::ENGINE_MYSQL &&
            (in_array($type, self::INT_TYPES, true) || in_array($type, self::FLOAT_TYPES, true))) {
            $sql .= ' UNSIGNED';
        }

        $isNullable = isset($definition['null']);

        $sql .= $isNullable ? ' NULL' : ' NOT NULL';

        if ($isAutoIncrement && $engine === self::ENGINE_MYSQL) {
            $sql .= ' AUTO_INCREMENT';
        }

        if ($isAutoIncrement) {
            return $sql;
        }

        $default = self::defaultSql($definition, $type, $engine, $escape);

        if ($default === null && $addingColumn && !$isNullable && $engine !== self::ENGINE_MYSQL) {
            $default = self::emptyDefaultSql($type);
        }

        if ($default !== null) {
            $sql .= ' DEFAULT ' . $default;
        }

        return $sql;
    }

    private static function typeSql(string $type, mixed $size, string $engine, bool $isAutoIncrement, bool $unsigned): string
    {
        $size = ($size === null || $size === '') ? null : $size;

        if (in_array($type, self::CHAR_TYPES, true) || in_array($type, self::BINARY_TYPES, true)) {
            $length = (int)$size > 0 ? (int)$size : 255;

            if (in_array($type, self::BINARY_TYPES, true)) {
                return match ($engine) {
                    self::ENGINE_PGSQL => 'bytea',
                    self::ENGINE_SQLITE => 'BLOB',
                    default => strtoupper($type) . "({$length})",
                };
            }

            return strtoupper($type) . "({$length})";
        }

        if (in_array($type, self::TEXT_TYPES, true)) {
            return $engine === self::ENGINE_MYSQL ? strtoupper($type) : 'TEXT';
        }

        if (in_array($type, self::DATETIME_TYPES, true)) {
            if ($engine === self::ENGINE_PGSQL) {
                return $type === 'datetime' ? 'timestamp' : $type;
            }

            return strtoupper($type);
        }

        if (in_array($type, self::INT_TYPES, true)) {
            if ($engine === self::ENGINE_PGSQL) {
                $pgType = match ($type) {
                    'tinyint', 'smallint' => 'smallint',
                    'bigint' => 'bigint',
                    default => 'integer',
                };

                if ($isAutoIncrement) {
                    return match ($pgType) {
                        'smallint' => 'smallserial',
                        'bigint' => 'bigserial',
                        default => 'serial',
                    };
                }

                return $pgType;
            }

            if ($engine === self::ENGINE_SQLITE) {
                return 'INTEGER';
            }

            return strtoupper($type) . ((int)$size > 0 ? '(' . (int)$size . ')' : '');
        }

        if (in_array($type, self::FLOAT_TYPES, true)) {
            $suffix = '';

            if ($size !== null && (float)$size > 0) {
                $suffix = '(' . str_replace('.', ',', (string)$size) . ')';
            }

            if ($engine === self::ENGINE_PGSQL) {
                return match ($type) {
                    'decimal' => 'numeric' . $suffix,
                    'float' => 'real',
                    default => 'double precision',
                };
            }

            return strtoupper($type) . $suffix;
        }

        return strtoupper($type);
    }

    private static function defaultSql(array $definition, string $type, string $engine, callable $escape): ?string
    {
        if (!isset($definition['default'])) {
            return null;
        }

        $default = $definition['default'];

        if (in_array($default, ['TIMESTAMP', 'UUID'], true)) {
            return null;
        }

        if (in_array($type, self::TEXT_TYPES, true) && $engine === self::ENGINE_MYSQL) {
            // MySQL does not accept literal defaults on TEXT columns.
            return null;
        }

        if ((in_array($type, self::INT_TYPES, true) || in_array($type, self::FLOAT_TYPES, true)) &&
            is_numeric($default)) {
            return in_array($type, self::INT_TYPES, true) ? (string)(int)$default : (string)(float)$default;
        }

        return "'" . $escape((string)$default) . "'";
    }

    private static function emptyDefaultSql(string $type): string
    {
        if (in_array($type, self::INT_TYPES, true) || in_array($type, self::FLOAT_TYPES, true)) {
            return '0';
        }

        if ($type === 'datetime' || $type === 'timestamp') {
            return "'1970-01-01 00:00:00'";
        }

        if ($type === 'date') {
            return "'1970-01-01'";
        }

        if ($type === 'time') {
            return "'00:00:00'";
        }

        return "''";
    }

    private static function quoteIdentifier(string $identifier, string $engine): string
    {
        return $engine === self::ENGINE_MYSQL ? "`{$identifier}`" : $identifier;
    }

    public static function createTableSql(
        string $table,
        array $schema,
        string $engine,
        string $prefix,
        callable $escape,
        string $collation = ''
    ): string {
        $lines = [];

        $primaryInline = false;

        foreach ($schema['columns'] as $name => $definition) {
            $line = self::quoteIdentifier($name, $engine) . ' ';

            $line .= self::columnDefinition($definition, $engine, $escape);

            if ($engine === self::ENGINE_SQLITE && $schema['primary'] === $name && !empty($definition['auto_increment'])) {
                $primaryInline = true;
            }

            $lines[] = $line;
        }

        if ($schema['primary'] !== null && !$primaryInline) {
            $lines[] = 'PRIMARY KEY (' . self::quoteIdentifier($schema['primary'], $engine) . ')';
        }

        $tableName = self::quoteIdentifier($prefix . $table, $engine);

        $sql = "CREATE TABLE IF NOT EXISTS {$tableName} (\n  " . implode(",\n  ", $lines) . "\n)";

        if ($engine === self::ENGINE_MYSQL) {
            $sql .= ' ENGINE=InnoDB' . $collation;
        }

        return $sql;
    }

    public static function addColumnSql(
        string $table,
        string $column,
        array $definition,
        string $engine,
        string $prefix,
        callable $escape
    ): string {
        $tableName = self::quoteIdentifier($prefix . $table, $engine);

        $columnName = self::quoteIdentifier($column, $engine);

        $keyword = $engine === self::ENGINE_MYSQL ? 'ADD' : 'ADD COLUMN';

        return "ALTER TABLE {$tableName} {$keyword} {$columnName} " .
            self::columnDefinition($definition, $engine, $escape, true);
    }

    /** MySQL only: PostgreSQL and SQLite columns are not re-typed once created. */
    public static function modifyColumnSql(
        string $table,
        string $column,
        array $definition,
        string $prefix,
        callable $escape
    ): string {
        return "ALTER TABLE `{$prefix}{$table}` MODIFY `{$column}` " .
            self::columnDefinition($definition, self::ENGINE_MYSQL, $escape);
    }

    public static function renameColumnSql(
        string $table,
        string $oldColumn,
        string $newColumn,
        array $definition,
        string $engine,
        string $prefix,
        callable $escape
    ): string {
        if ($engine === self::ENGINE_MYSQL) {
            return "ALTER TABLE `{$prefix}{$table}` CHANGE `{$oldColumn}` `{$newColumn}` " .
                self::columnDefinition($definition, $engine, $escape);
        }

        return "ALTER TABLE {$prefix}{$table} RENAME COLUMN {$oldColumn} TO {$newColumn}";
    }

    // ------------------------------------------------------------------------------------------------------------
    // Executors
    // ------------------------------------------------------------------------------------------------------------

    private static function escaper(): callable
    {
        global $db;

        return static fn(string $value): string => $db->escape_string($value);
    }

    private static function run(string $sql): void
    {
        global $db;

        if (self::engine() === self::ENGINE_SQLITE) {
            // The driver keeps every statement it ran in $db->query_objects, and a statement that was not read to
            // the end (the board's cache and PluginLibrary leave such ones) makes SQLite refuse to change the
            // schema. Closing them releases the lock.
            foreach ($db->query_objects ?? [] as $statement) {
                if ($statement instanceof \PDOStatement) {
                    $statement->closeCursor();
                }
            }

            // The driver only passes ADD and RENAME TO through; every other ALTER TABLE goes to an emulation that
            // copies the table. SQLite renames and drops columns natively, so those go straight to it.
            if (preg_match('/^ALTER TABLE \S+ (RENAME COLUMN|DROP COLUMN) /i', $sql)) {
                $db->db->query($sql)->closeCursor();

                return;
            }
        }

        $db->write_query($sql);
    }

    /** Creates a table from its definition, or adds the columns it is missing. */
    public static function verifyTable(string $table, array $tableObject): void
    {
        global $db;

        $engine = self::engine();

        $prefix = $db->table_prefix;

        $escape = self::escaper();

        $schema = self::schemaOf($tableObject);

        if (!$db->table_exists($table)) {
            self::run(
                self::createTableSql($table, $schema, $engine, $prefix, $escape, $db->build_create_table_collation())
            );

            return;
        }

        foreach ($schema['columns'] as $column => $definition) {
            if ($db->field_exists($column, $table)) {
                if ($engine === self::ENGINE_MYSQL) {
                    self::run(self::modifyColumnSql($table, $column, $definition, $prefix, $escape));
                }
            } else {
                self::run(self::addColumnSql($table, $column, $definition, $engine, $prefix, $escape));
            }
        }
    }

    /** Adds the columns a table of the core board is missing (FIELDS_DATA). */
    public static function verifyCoreColumns(string $table, array $tableObject): void
    {
        global $db;

        if (!$db->table_exists($table)) {
            return;
        }

        $engine = self::engine();

        $prefix = $db->table_prefix;

        $escape = self::escaper();

        foreach (self::schemaOf($tableObject)['columns'] as $column => $definition) {
            if ($db->field_exists($column, $table)) {
                if ($engine === self::ENGINE_MYSQL) {
                    self::run(self::modifyColumnSql($table, $column, $definition, $prefix, $escape));
                }
            } else {
                self::run(self::addColumnSql($table, $column, $definition, $engine, $prefix, $escape));
            }
        }
    }

    /** Column definition for the current engine, for callers that add, change or rename a single column. */
    public static function currentColumnDefinition(array $definition, bool $addingColumn = false): string
    {
        return self::columnDefinition($definition, self::engine(), self::escaper(), $addingColumn);
    }

    public static function addColumn(string $table, string $column, array $definition): void
    {
        global $db;

        self::run(self::addColumnSql($table, $column, $definition, self::engine(), $db->table_prefix, self::escaper()));
    }

    public static function renameColumn(string $table, string $oldColumn, string $newColumn, array $definition): void
    {
        global $db;

        self::run(
            self::renameColumnSql(
                $table,
                $oldColumn,
                $newColumn,
                $definition,
                self::engine(),
                $db->table_prefix,
                self::escaper()
            )
        );
    }

}
