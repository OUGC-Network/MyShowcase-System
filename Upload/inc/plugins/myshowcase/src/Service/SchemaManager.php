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

    /** Messages produced while verifying the schema, kept for callers that cannot show flash messages. */
    private static array $warnings = [];

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

    public static function warnings(): array
    {
        return self::$warnings;
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

    /**
     * Name of an index inside the database. MySQL scopes index names to the table, PostgreSQL and SQLite to the
     * whole database, so there they are prefixed with the table name.
     */
    public static function indexName(string $table, string $key, string $engine, string $prefix): string
    {
        if ($engine === self::ENGINE_MYSQL) {
            return $key;
        }

        $name = "{$prefix}{$table}_{$key}";

        if (strlen($name) > 63) {
            $name = substr($name, 0, 54) . '_' . substr(md5($name), 0, 8);
        }

        return $name;
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

    public static function renameTableSql(string $oldTable, string $newTable, string $engine, string $prefix): string
    {
        if ($engine === self::ENGINE_MYSQL) {
            return "RENAME TABLE `{$prefix}{$oldTable}` TO `{$prefix}{$newTable}`";
        }

        return "ALTER TABLE {$prefix}{$oldTable} RENAME TO {$prefix}{$newTable}";
    }

    /** PostgreSQL only: rename_table leaves the sequence of a serial column named after the old table. */
    public static function renameSequenceSql(string $oldSequence, string $newSequence, string $prefix): string
    {
        return "ALTER SEQUENCE IF EXISTS {$prefix}{$oldSequence} RENAME TO {$prefix}{$newSequence}";
    }

    public static function dropSequenceSql(string $sequence, string $prefix): string
    {
        return "DROP SEQUENCE IF EXISTS {$prefix}{$sequence}";
    }

    public static function dropTableSql(string $table, string $engine, string $prefix): string
    {
        return 'DROP TABLE IF EXISTS ' . self::quoteIdentifier($prefix . $table, $engine);
    }

    /**
     * @param string $kind regular, unique or fulltext
     */
    public static function createIndexSql(
        string $table,
        string $key,
        array $columns,
        string $kind,
        string $engine,
        string $prefix
    ): string {
        $name = self::indexName($table, $key, $engine, $prefix);

        $columnList = implode(', ', array_map(static fn(string $column) => self::quoteIdentifier($column, $engine), $columns));

        if ($engine === self::ENGINE_MYSQL) {
            $keyword = match ($kind) {
                'unique' => 'ADD UNIQUE KEY',
                'fulltext' => 'ADD FULLTEXT KEY',
                default => 'ADD KEY',
            };

            return "ALTER TABLE `{$prefix}{$table}` {$keyword} `{$name}` ({$columnList})";
        }

        $unique = $kind === 'unique' ? 'UNIQUE ' : '';

        return "CREATE {$unique}INDEX {$name} ON {$prefix}{$table} ({$columnList})";
    }

    /** Number of distinct values that appear more than once in the given columns (NULLs never collide). */
    public static function duplicatesCountSql(string $table, array $columns, string $engine, string $prefix): string
    {
        $list = implode(', ', array_map(static fn(string $column) => self::quoteIdentifier($column, $engine), $columns));

        $notNull = implode(' AND ', array_map(
            static fn(string $column) => self::quoteIdentifier($column, $engine) . ' IS NOT NULL',
            $columns
        ));

        $tableName = self::quoteIdentifier($prefix . $table, $engine);

        return "SELECT COUNT(*) AS duplicate_count FROM (SELECT 1 FROM {$tableName} WHERE {$notNull} GROUP BY {$list} HAVING COUNT(*) > 1) duplicates";
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

    /**
     * First row of a query. The result is freed straight away: SQLite refuses to drop or rebuild a table while a
     * statement that has not been read to the end is still open.
     */
    private static function queryRow(string $sql): array
    {
        global $db;

        $query = $db->write_query($sql);

        $row = $db->fetch_array($query);

        self::free($query);

        return is_array($row) ? $row : [];
    }

    private static function free(mixed $query): void
    {
        global $db;

        $db->free_result($query);

        if ($query instanceof \PDOStatement) {
            $query->closeCursor();
        }
    }

    private static function countRows(string $table, string $where = ''): int
    {
        global $db;

        return (int)(self::queryRow(
            "SELECT COUNT(*) AS total FROM {$db->table_prefix}{$table}" . ($where !== '' ? " WHERE {$where}" : '')
        )['total'] ?? 0);
    }

    public static function indexExists(string $table, string $key): bool
    {
        global $db;

        $engine = self::engine();

        $name = self::indexName($table, $key, $engine, $db->table_prefix);

        if ($engine === self::ENGINE_MYSQL) {
            $query = $db->write_query("SHOW INDEX FROM `{$db->table_prefix}{$table}`");

            $exists = false;

            while ($index = $db->fetch_array($query)) {
                if ($index['Key_name'] === $name) {
                    $exists = true;

                    break;
                }
            }

            self::free($query);

            return $exists;
        }

        $name = $db->escape_string($name);

        if ($engine === self::ENGINE_PGSQL) {
            return (bool)self::queryRow("SELECT indexname FROM pg_indexes WHERE indexname='{$name}'");
        }

        return (bool)self::queryRow("SELECT name FROM sqlite_master WHERE type='index' AND name='{$name}'");
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

    /**
     * Adds regular, fulltext and unique indexes that are missing.
     *
     * A unique index that cannot be created because the data holds duplicates is skipped with a warning; it is
     * attempted again the next time the plugin is activated. No data is ever changed.
     */
    public static function verifyIndexes(string $table, array $tableObject, bool $includeUnique = true): void
    {
        global $db;

        if (!$db->table_exists($table)) {
            return;
        }

        $engine = self::engine();

        $prefix = $db->table_prefix;

        $schema = self::schemaOf($tableObject);

        foreach ($schema['keys'] as $key => $columns) {
            if (self::columnsExist($table, $columns) && !self::indexExists($table, $key)) {
                self::run(self::createIndexSql($table, $key, $columns, 'regular', $engine, $prefix));
            }
        }

        foreach ($schema['full'] as $key => $columns) {
            if (self::columnsExist($table, $columns) && $db->supports_fulltext($table) &&
                !self::indexExists($table, $key)) {
                self::run(self::createIndexSql($table, $key, $columns, 'fulltext', $engine, $prefix));
            }
        }

        if (!$includeUnique) {
            return;
        }

        foreach ($schema['unique'] as $key => $columns) {
            if (!self::columnsExist($table, $columns) || self::indexExists($table, $key)) {
                continue;
            }

            $duplicates = (int)(self::queryRow(
                self::duplicatesCountSql($table, $columns, $engine, $prefix)
            )['duplicate_count'] ?? 0);

            if ($duplicates > 0) {
                self::warnKey(
                    'myshowcase_admin_warning_duplicate_unique_index',
                    [$key, $prefix . $table, implode(', ', $columns), $duplicates]
                );

                continue;
            }

            self::run(self::createIndexSql($table, $key, $columns, 'unique', $engine, $prefix));
        }
    }

    private static function columnsExist(string $table, array $columns): bool
    {
        global $db;

        foreach ($columns as $column) {
            if (!$db->field_exists($column, $table)) {
                return false;
            }
        }

        return true;
    }

    /**
     * English text of the warnings that have a language key (Upload/inc/languages/english/admin/myshowcase.lang.php),
     * with {1}, {2}, ... placeholders. It is the text used when the language file cannot be loaded.
     */
    private const WARNING_TEXT = [
        'myshowcase_admin_warning_duplicate_unique_index' => 'MyShowcase: the unique index {1} on table {2} (column {3})'
            . ' was not created because {4} value(s) are duplicated. Remove the duplicates and activate the plugin'
            . ' again to create it.',
        'myshowcase_admin_warning_innodb_version_too_old' => 'The database server is {1} {2}; converting to InnoDB'
            . ' needs MySQL 5.7.7 or MariaDB 10.2.2 or newer, so the MyShowcase tables were left as they are.',
        'myshowcase_admin_warning_innodb_version_unreadable' => 'The database server version could not be read ("{1}"),'
            . ' so the MyShowcase tables were left as they are. Convert any MyISAM table to InnoDB by hand.',
        'myshowcase_admin_warning_innodb_table_failed' => 'The table {1} could not be converted to InnoDB and was left'
            . ' as it is: {2}',
        'myshowcase_admin_warning_collections_both_tables_have_rows' => 'MyShowcase: both {1} and {2} contain rows. The'
            . ' field sets were not migrated to collections; merge the two tables manually and activate the plugin'
            . ' again.',
        'myshowcase_admin_warning_legacy_column_not_dropped' => 'MyShowcase: the old column {1} of {2} could not be'
            . ' dropped ({3}). Its values were copied to {4}.',
    ];

    /** Pure: the English text of a warning that has a language key. */
    public static function warningText(string $key, array $values): string
    {
        $text = self::WARNING_TEXT[$key];

        foreach (array_values($values) as $index => $value) {
            $text = str_replace('{' . ($index + 1) . '}', (string)$value, $text);
        }

        return $text;
    }

    /**
     * Warning text from the admin language file; the English text when the file cannot be loaded (CLI, scripts).
     */
    private static function translatedWarning(string $key, array $values): string
    {
        global $lang;

        try {
            if (is_object($lang) && method_exists($lang, 'load') && method_exists($lang, 'sprintf')) {
                if (!isset($lang->$key)) {
                    $lang->load('myshowcase', false, true);
                }

                if (isset($lang->$key) && is_string($lang->$key) && $lang->$key !== '') {
                    return $lang->sprintf($lang->$key, ...array_values(array_map('strval', $values)));
                }
            }
        } catch (\Throwable) {
        }

        return self::warningText($key, $values);
    }

    private static function warnKey(string $key, array $values): void
    {
        self::warn(self::translatedWarning($key, $values));
    }

    private static function warn(string $message): void
    {
        self::$warnings[] = $message;

        if (function_exists('flash_message')) {
            flash_message(htmlspecialchars($message, ENT_QUOTES), 'error');
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

    /** Drops a table together with the sequences PostgreSQL keeps for its serial columns. */
    public static function dropTable(string $table, array $sequenceColumns = []): void
    {
        global $db;

        $engine = self::engine();

        $prefix = $db->table_prefix;

        self::run(self::dropTableSql($table, $engine, $prefix));

        if ($engine === self::ENGINE_PGSQL) {
            foreach ($sequenceColumns as $column) {
                self::run(self::dropSequenceSql("{$table}_{$column}_seq", $prefix));
            }
        }
    }

    // ------------------------------------------------------------------------------------------------------------
    // MyISAM to InnoDB conversion (MySQL and MariaDB only)
    // ------------------------------------------------------------------------------------------------------------

    /**
     * First MySQL release on which converting is allowed. From 5.7.7 InnoDB accepts index keys up to 3072 bytes by
     * default instead of 767 bytes, which keeps wide utf8mb4 indexes from being refused.
     */
    public const MYSQL_MINIMUM_INNODB_VERSION = '5.7.7';

    /** First MariaDB release on which converting is allowed (the same index key limit as MySQL 5.7.7). */
    public const MARIADB_MINIMUM_INNODB_VERSION = '10.2.2';

    /**
     * Reads the flavour and the version out of the string `SELECT VERSION()` returns.
     *
     * MariaDB reports itself in the string ("10.6.16-MariaDB"). Some set-ups prefix it with the fake replication
     * version 5.5.5 ("5.5.5-10.6.16-MariaDB"); that prefix is not the server version.
     *
     * @return array{flavor: string, version: string}|null null when the string cannot be understood.
     */
    public static function parseServerVersion(string $versionString): ?array
    {
        $versionString = trim($versionString);

        $isMariaDb = stripos($versionString, 'MariaDB') !== false;

        if ($isMariaDb) {
            $versionString = preg_replace('/^5\.5\.5-/', '', $versionString);
        }

        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)/', $versionString, $matches)) {
            return null;
        }

        return [
            'flavor' => $isMariaDb ? 'mariadb' : 'mysql',
            'version' => (int)$matches[1] . '.' . (int)$matches[2] . '.' . (int)$matches[3],
        ];
    }

    /** True when the server version string is one on which converting to InnoDB is allowed. */
    public static function serverSupportsInnoDbConversion(string $versionString): bool
    {
        $parsed = self::parseServerVersion($versionString);

        if ($parsed === null) {
            return false;
        }

        $minimum = $parsed['flavor'] === 'mariadb'
            ? self::MARIADB_MINIMUM_INNODB_VERSION
            : self::MYSQL_MINIMUM_INNODB_VERSION;

        return version_compare($parsed['version'], $minimum, '>=');
    }

    /** True for the database types of the board that talk to MySQL or MariaDB (mysqli and mysql_pdo). */
    public static function isMysqlDatabaseType(string $databaseType): bool
    {
        return str_starts_with($databaseType, 'mysql');
    }

    /** Statement that lists the engine of each given table of the current database. */
    public static function tableEnginesSql(array $prefixedTables, callable $escape): string
    {
        $names = array_map(static fn(string $name): string => "'" . $escape($name) . "'", $prefixedTables);

        return 'SELECT TABLE_NAME AS table_name, ENGINE AS table_engine FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . implode(',', $names) . ')';
    }

    public static function convertToInnoDbSql(string $table, string $prefix): string
    {
        return 'ALTER TABLE ' . self::quoteIdentifier($prefix . $table, self::ENGINE_MYSQL) . ' ENGINE=InnoDB';
    }

    /**
     * Decides what the conversion does, without touching the database.
     *
     * @param string $versionString Result of SELECT VERSION().
     * @param array<string, string> $engineByTable Engine of each table, keyed by table name without the prefix.
     * @return array{status: string, statements: array<string, string>, warning: array|null, message: string}
     *     status is convert, skipped_version, skipped_unreadable or nothing_to_do. The statements are keyed by table
     *     name. warning is the language key and values of the warning to show, and message its English text.
     */
    public static function innoDbConversionPlan(string $versionString, array $engineByTable, string $prefix): array
    {
        $parsed = self::parseServerVersion($versionString);

        if ($parsed === null) {
            $warning = ['myshowcase_admin_warning_innodb_version_unreadable', [$versionString]];

            return [
                'status' => 'skipped_unreadable',
                'statements' => [],
                'warning' => $warning,
                'message' => self::warningText(...$warning),
            ];
        }

        if (!self::serverSupportsInnoDbConversion($versionString)) {
            $warning = [
                'myshowcase_admin_warning_innodb_version_too_old',
                [$parsed['flavor'] === 'mariadb' ? 'MariaDB' : 'MySQL', $parsed['version']],
            ];

            return [
                'status' => 'skipped_version',
                'statements' => [],
                'warning' => $warning,
                'message' => self::warningText(...$warning),
            ];
        }

        $statements = [];

        foreach ($engineByTable as $table => $engine) {
            if (strtoupper($engine) === 'MYISAM') {
                $statements[$table] = self::convertToInnoDbSql($table, $prefix);
            }
        }

        return [
            'status' => $statements ? 'convert' : 'nothing_to_do',
            'statements' => $statements,
            'warning' => null,
            'message' => '',
        ];
    }

    /**
     * Converts the given plugin tables from MyISAM to InnoDB. Tables on any other engine are skipped, and nothing
     * happens on engines other than MySQL and MariaDB.
     *
     * A refusal because of the server version, or the failure of one table, is reported as a warning and never stops
     * the caller.
     *
     * @param string[] $tables Table names without the prefix. Only plugin tables belong here, never core tables.
     * @return string[] Statements that were run.
     */
    public static function convertToInnoDb(array $tables): array
    {
        global $db;

        if (!self::isMysqlDatabaseType((string)($db->type ?? '')) || !$tables) {
            return [];
        }

        $prefix = $db->table_prefix;

        $versionString = (string)(self::queryRow('SELECT VERSION() AS server_version')['server_version'] ?? '');

        $engineByTable = [];

        $query = $db->write_query(
            self::tableEnginesSql(
                array_map(static fn(string $table): string => $prefix . $table, $tables),
                self::escaper()
            )
        );

        while ($row = $db->fetch_array($query)) {
            $engineByTable[strtolower((string)$row['table_name'])] = (string)$row['table_engine'];
        }

        self::free($query);

        $engineByPluginTable = [];

        foreach ($tables as $table) {
            if (isset($engineByTable[strtolower($prefix . $table)])) {
                $engineByPluginTable[$table] = $engineByTable[strtolower($prefix . $table)];
            }
        }

        $plan = self::innoDbConversionPlan($versionString, $engineByPluginTable, $prefix);

        if ($plan['status'] === 'skipped_version' || $plan['status'] === 'skipped_unreadable') {
            self::warnKey(...$plan['warning']);

            return [];
        }

        $executed = [];

        foreach ($plan['statements'] as $table => $statement) {
            try {
                $db->write_query($statement, 1);

                if ($db->error_number()) {
                    throw new \RuntimeException($db->error_string());
                }

                $executed[] = $statement;
            } catch (\Throwable $exception) {
                self::warnKey(
                    'myshowcase_admin_warning_innodb_table_failed',
                    [$prefix . $table, $exception->getMessage()]
                );
            }
        }

        return $executed;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Field sets to collections migration
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Detection-based, idempotent migration from the field set names (legacy and 3.0 development builds) to the
     * collection names. IDs and rows are preserved; it never deletes rows.
     *
     * @param array $tablesData TABLES_DATA
     * @return string[] Statements that were run.
     */
    public static function migrateCollections(array $tablesData): array
    {
        global $db;

        $engine = self::engine();

        $prefix = $db->table_prefix;

        $escape = self::escaper();

        $statements = [];

        $execute = static function (string $sql) use (&$statements): void {
            $statements[] = $sql;

            self::run($sql);
        };

        $oldTable = 'myshowcase_fieldsets';

        $newTable = 'myshowcase_collections';

        if ($db->table_exists($oldTable)) {
            if ($db->table_exists($newTable)) {
                $newRows = self::countRows($newTable);

                $oldRows = self::countRows($oldTable);

                if ($newRows > 0 && $oldRows > 0) {
                    self::warnKey(
                        'myshowcase_admin_warning_collections_both_tables_have_rows',
                        [$prefix . $oldTable, $prefix . $newTable]
                    );

                    return $statements;
                }

                if ($newRows === 0) {
                    $execute(self::dropTableSql($newTable, $engine, $prefix));
                } else {
                    // The old table is empty and the new one already holds the data: nothing to migrate.
                    $oldTable = null;
                }
            }

            if ($oldTable !== null) {
                $execute(self::renameTableSql($oldTable, $newTable, $engine, $prefix));

                if ($engine === self::ENGINE_PGSQL) {
                    foreach (['set_id', 'setid'] as $oldColumn) {
                        $execute(
                            self::renameSequenceSql(
                                "{$oldTable}_{$oldColumn}_seq",
                                "{$newTable}_collection_id_seq",
                                $prefix
                            )
                        );
                    }
                }
            }
        }

        $columnMap = [
            'myshowcase_collections' => [
                'collection_id' => ['set_id', 'setid', 'fieldset_id'],
                'name' => ['set_name', 'setname'],
            ],
            'myshowcase_config' => [
                'collection_id' => ['field_set_id', 'fieldsetid'],
            ],
            'myshowcase_fields' => [
                'collection_id' => ['set_id', 'setid'],
            ],
            'myshowcase_field_data' => [
                'collection_id' => ['set_id', 'setid'],
            ],
        ];

        foreach ($columnMap as $table => $columns) {
            if (!$db->table_exists($table)) {
                continue;
            }

            foreach ($columns as $newColumn => $oldColumns) {
                if (!isset($tablesData[$table][$newColumn])) {
                    continue;
                }

                if ($db->field_exists($newColumn, $table)) {
                    // An earlier activation already added the new column next to the old one (left at its default):
                    // carry the old values over and drop the old column once every row matches.
                    if ($table !== 'myshowcase_collections') {
                        self::carryOverColumn($table, $newColumn, $oldColumns, $tablesData[$table][$newColumn], $execute);
                    }

                    continue;
                }

                foreach ($oldColumns as $oldColumn) {
                    if (!$db->field_exists($oldColumn, $table)) {
                        continue;
                    }

                    $execute(
                        self::renameColumnSql(
                            $table,
                            $oldColumn,
                            $newColumn,
                            $tablesData[$table][$newColumn],
                            $engine,
                            $prefix,
                            $escape
                        )
                    );

                    break;
                }
            }
        }

        return $statements;
    }

    private static function carryOverColumn(
        string $table,
        string $newColumn,
        array $oldColumns,
        array $definition,
        callable $execute
    ): void {
        global $db;

        $prefix = $db->table_prefix;

        $emptyValue = in_array(strtolower((string)$definition['type']), self::INT_TYPES, true) ? '0' : "''";

        foreach ($oldColumns as $oldColumn) {
            if (!$db->field_exists($oldColumn, $table)) {
                continue;
            }

            $execute("UPDATE {$prefix}{$table} SET {$newColumn}={$oldColumn} WHERE {$newColumn}={$emptyValue}");

            $mismatch = self::countRows($table, "{$newColumn}<>{$oldColumn}");

            if ($mismatch === 0) {
                try {
                    $execute("ALTER TABLE {$prefix}{$table} DROP COLUMN {$oldColumn}");
                } catch (\Throwable $exception) {
                    self::warnKey(
                        'myshowcase_admin_warning_legacy_column_not_dropped',
                        [$oldColumn, $prefix . $table, $exception->getMessage(), $newColumn]
                    );
                }
            }
        }
    }
}
