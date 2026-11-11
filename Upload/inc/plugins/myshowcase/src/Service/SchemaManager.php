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

        $isAutoIncrement = !empty($definition['auto_increment']);

        if ($isAutoIncrement && $engine === self::ENGINE_SQLITE) {
            // SQLite only auto-increments an INTEGER PRIMARY KEY column.
            return 'INTEGER PRIMARY KEY';
        }

        $sql = self::typeSqlFor($definition, $engine);

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

    /** @return mixed What the driver returned: false or null when it reports a failure instead of throwing. */
    private static function run(string $sql): mixed
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

                return true;
            }
        }

        return $db->write_query($sql);
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
            if (!$db->field_exists($column, $table)) {
                self::run(self::addColumnSql($table, $column, $definition, $engine, $prefix, $escape));
            }
        }

        self::alterChangedColumns($table, $schema['columns']);
    }

    /**
     * Adds regular, fulltext and unique indexes that are missing.
     *
     * A unique index that cannot be created because the data holds duplicates is skipped with a warning, and so is
     * any index the database refuses (for example a key that is too long); both are attempted again the next time
     * the plugin is activated. No data is ever changed.
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
                self::createIndexOrWarn($table, $key, $columns, 'regular', $engine, $prefix);
            }
        }

        foreach ($schema['full'] as $key => $columns) {
            if (self::columnsExist($table, $columns) && $db->supports_fulltext($table) &&
                !self::indexExists($table, $key)) {
                self::createIndexOrWarn($table, $key, $columns, 'fulltext', $engine, $prefix);
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

            self::createIndexOrWarn($table, $key, $columns, 'unique', $engine, $prefix);
        }
    }

    /**
     * Runs one CREATE INDEX statement. If the database rejects it, the index is skipped and a warning names the
     * table, the index, the columns and the database's message; the caller carries on with the next index.
     */
    private static function createIndexOrWarn(
        string $table,
        string $key,
        array $columns,
        string $kind,
        string $engine,
        string $prefix
    ): void {
        global $db;

        try {
            $result = self::run(self::createIndexSql($table, $key, $columns, $kind, $engine, $prefix));

            // With error reporting switched off the MySQL drivers return false or null instead of throwing. The SQLite
            // driver returns other values on success, so only the exception is used there and on PostgreSQL.
            if ($engine === self::ENGINE_MYSQL && ($result === false || $result === null)) {
                throw new \RuntimeException((string)$db->error_string());
            }
        } catch (\Throwable $exception) {
            self::warnKey(
                'myshowcase_admin_warning_index_failed',
                [
                    $prefix . $table,
                    self::indexName($table, $key, $engine, $prefix),
                    implode(', ', $columns),
                    $exception->getMessage(),
                ]
            );
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
        'myshowcase_admin_warning_index_failed' => 'MyShowcase: the index {2} on table {1} (column(s) {3}) could not be'
            . ' created and was skipped ({4}). Fix the cause and activate the plugin again to retry it.',
        'myshowcase_admin_warning_innodb_version_too_old' => 'The database server is {1} {2}; converting to InnoDB'
            . ' needs MySQL 5.7.7 or MariaDB 10.2.2 or newer, so the MyShowcase tables were left as they are.',
        'myshowcase_admin_warning_innodb_version_unreadable' => 'The database server version could not be read ("{1}"),'
            . ' so the MyShowcase tables were left as they are. Convert any MyISAM table to InnoDB by hand.',
        'myshowcase_admin_warning_innodb_table_failed' => 'The table {1} could not be converted to InnoDB and was left'
            . ' as it is: {2}',
        'myshowcase_admin_warning_collections_both_tables_have_rows' => 'MyShowcase: both {1} and {2} contain rows. The'
            . ' field sets were not migrated to collections; merge the two tables manually and activate the plugin'
            . ' again.',
        'myshowcase_admin_warning_column_conversion_failed' => 'MyShowcase: the column {2} of table {1} could not be'
            . ' changed from {3} to {4} and was left as it is ({5}). No data was changed. Fix the data and rebuild the'
            . ' table or activate the plugin again to retry.',
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

        $columns = self::schemaOf($tableObject)['columns'];

        foreach ($columns as $column => $definition) {
            if (!$db->field_exists($column, $table)) {
                self::run(self::addColumnSql($table, $column, $definition, $engine, $prefix, $escape));
            }
        }

        self::alterChangedColumns($table, $columns);
    }

    /** Column definition for the current engine, for callers that add, change or rename a single column. */
    public static function currentColumnDefinition(array $definition, bool $addingColumn = false): string
    {
        return self::columnDefinition($definition, self::engine(), self::escaper(), $addingColumn);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Detecting and changing the type of existing columns
    // ------------------------------------------------------------------------------------------------------------

    private const FAMILY_INT = ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'];

    private const FAMILY_FRACTION = ['decimal', 'float', 'double'];

    /** One line per column change that failed: table, column, old type, new type and the database message. */
    private static array $columnErrors = [];

    /** One line per column change that was applied. */
    private static array $columnChanges = [];

    /** @return string[] */
    public static function columnErrors(): array
    {
        return self::$columnErrors;
    }

    /** @return string[] */
    public static function columnChanges(): array
    {
        return self::$columnChanges;
    }

    /** Type of a column as it is written in a column definition, for the given engine (no NULL or DEFAULT). */
    public static function typeSqlFor(array $definition, string $engine): string
    {
        $type = strtolower((string)$definition['type']);

        $sql = self::typeSql(
            $type,
            $definition['size'] ?? null,
            $engine,
            !empty($definition['auto_increment']),
            !empty($definition['unsigned'])
        );

        if (!empty($definition['unsigned']) && $engine === self::ENGINE_MYSQL &&
            (in_array($type, self::INT_TYPES, true) || in_array($type, self::FLOAT_TYPES, true))) {
            $sql .= ' UNSIGNED';
        }

        return $sql;
    }

    /**
     * Pure: one spelling for a column type, so that what the database reports (int(10) unsigned, integer,
     * character varying(255), timestamp without time zone, ...) can be compared with what the definition asks for.
     *
     * Integer display widths are dropped, as are the digits of date and time types. SQLite stores any integer type
     * as INTEGER, so all of them are the same there; unsigned only exists on MySQL.
     */
    public static function normalizeType(string $type, string $engine): string
    {
        $type = strtolower(trim((string)preg_replace('/\s+/', ' ', $type)));

        $unsigned = str_contains($type, ' unsigned') || str_starts_with($type, 'unsigned ');

        $type = trim(str_replace(['unsigned', 'zerofill'], '', $type));

        if (!preg_match('/^([a-z][a-z0-9 ]*)(?:\(\s*([^)]*?)\s*\))?\s*([a-z ]*)$/', $type, $matches)) {
            return $type;
        }

        $base = trim($matches[1] . ' ' . $matches[3]);

        $arguments = str_replace(' ', '', $matches[2] ?? '');

        $base = match ($base) {
            'integer', 'int4', 'serial' => 'int',
            'int2', 'smallserial' => 'smallint',
            'int8', 'bigserial' => 'bigint',
            'numeric' => 'decimal',
            'character varying', 'varchar2' => 'varchar',
            'character' => 'char',
            'double precision', 'float8' => 'double',
            'float4', 'real' => 'float',
            'timestamp without time zone', 'timestamp with time zone' => 'timestamp',
            'time without time zone' => 'time',
            default => $base,
        };

        if (in_array($base, self::FAMILY_INT, true)) {
            if ($engine === self::ENGINE_SQLITE) {
                return 'integer';
            }

            return $base . ($unsigned && $engine === self::ENGINE_MYSQL ? ' unsigned' : '');
        }

        if (in_array($base, ['varchar', 'char', 'varbinary', 'binary', 'decimal', 'float', 'double'], true) &&
            $arguments !== '') {
            return $base . '(' . $arguments . ')' . ($unsigned && $engine === self::ENGINE_MYSQL ? ' unsigned' : '');
        }

        return $base . ($unsigned && $engine === self::ENGINE_MYSQL ? ' unsigned' : '');
    }

    /**
     * Pure: the value of a DEFAULT clause as the database reports it (PostgreSQL: 'x'::character varying, 0,
     * nextval(...); SQLite: 'x' or 0) reduced to the plain value, or null when there is no usable default.
     */
    public static function defaultFromSql(?string $sqlDefault): ?string
    {
        if ($sqlDefault === null) {
            return null;
        }

        $value = trim($sqlDefault);

        while (preg_match('/^(.*)::[a-z][a-z0-9 ]*(?:\(\d+(?:,\s*\d+)?\))?(?:\[\])?$/is', $value, $matches)) {
            $value = trim($matches[1]);
        }

        while (preg_match('/^\((.*)\)$/s', $value, $matches)) {
            $value = trim($matches[1]);
        }

        if (strcasecmp($value, 'null') === 0 || str_starts_with(strtolower($value), 'nextval(')) {
            return null;
        }

        if (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
            return str_replace("''", "'", substr($value, 1, -1));
        }

        return $value;
    }

    /**
     * Pure: a MySQL or MariaDB default as a plain value. MariaDB 10.2.7+ reports text defaults quoted ('abc') and
     * the missing default of a nullable column as the string NULL; MySQL reports the bare value and a real NULL.
     */
    public static function mysqlDefault(?string $reported, bool $isNullable): ?string
    {
        if ($reported === null) {
            return null;
        }

        if (strlen($reported) >= 2 && $reported[0] === "'" && str_ends_with($reported, "'")) {
            return str_replace("''", "'", substr($reported, 1, -1));
        }

        if ($isNullable && $reported === 'NULL') {
            return null;
        }

        return $reported;
    }

    /** Pure: a plain default value in one form per column type (0.00 and 0 are the same number). */
    private static function canonicalDefault(?string $value, string $normalizedType): ?string
    {
        if ($value === null) {
            return null;
        }

        $base = preg_replace('/\(.*$/', '', $normalizedType);

        $base = trim(str_replace(' unsigned', '', (string)$base));

        if ((in_array($base, self::FAMILY_INT, true) || in_array($base, self::FAMILY_FRACTION, true)) &&
            is_numeric($value)) {
            return (string)(0 + $value);
        }

        return $value;
    }

    /**
     * Pure: the columns whose type, nullability or default differ from the definition.
     *
     * @param array<string, array{type: string, null: bool, default: ?string, auto: bool}> $actual As reported by
     *        actualColumns(), with defaults already reduced to plain values.
     * @param array<string, array> $columns The wanted columns (SchemaManager::schemaOf()['columns']).
     * @return array<string, array{old: string, new: string, type: bool, null: bool, default: bool}>
     */
    public static function planColumnChanges(array $actual, array $columns, string $engine): array
    {
        $plan = [];

        foreach ($columns as $name => $definition) {
            if (!isset($actual[$name]) || !empty($definition['auto_increment']) || !empty($actual[$name]['auto'])) {
                continue;
            }

            $current = $actual[$name];

            $newType = self::typeSqlFor($definition, $engine);

            $normalizedNew = self::normalizeType($newType, $engine);

            $typeChanged = self::normalizeType($current['type'], $engine) !== $normalizedNew;

            $isNullable = isset($definition['null']);

            $nullChanged = $current['null'] !== $isNullable;

            $defaultChanged = false;

            $wantedDefault = $definition['default'] ?? null;

            // TIMESTAMP and UUID are generated by the plugin, no DEFAULT clause is written for them.
            if (!in_array($wantedDefault, ['TIMESTAMP', 'UUID'], true)) {
                $type = strtolower((string)$definition['type']);

                if ($wantedDefault !== null && !(in_array($type, self::TEXT_TYPES, true) &&
                        $engine === self::ENGINE_MYSQL)) {
                    $wanted = self::canonicalDefault((string)$wantedDefault, $normalizedNew);
                } else {
                    $wanted = null;
                }

                $found = self::canonicalDefault($current['default'], $normalizedNew);

                if ($wanted !== $found) {
                    // Adding a NOT NULL column without default to PostgreSQL or SQLite gives it an empty default
                    // (addColumnSql), which is not a difference.
                    $isEmptyDefault = $wanted === null && !$isNullable && $engine !== self::ENGINE_MYSQL &&
                        $found === self::canonicalDefault(
                            self::defaultFromSql(self::emptyDefaultSql($type)),
                            $normalizedNew
                        );

                    $defaultChanged = !$isEmptyDefault;
                }
            }

            if ($typeChanged || $nullChanged || $defaultChanged) {
                $plan[$name] = [
                    'old' => $current['type'],
                    'new' => $newType,
                    'type' => $typeChanged,
                    'null' => $nullChanged,
                    'default' => $defaultChanged,
                ];
            }
        }

        return $plan;
    }

    /**
     * What the database says about the columns of a table, in table order.
     *
     * @return array<string, array{type: string, null: bool, default: ?string, auto: bool, pk: int, raw_default: ?string}>
     */
    public static function actualColumns(string $table): array
    {
        global $db;

        $engine = self::engine();

        $fullName = $db->table_prefix . $table;

        $columns = [];

        if ($engine === self::ENGINE_MYSQL) {
            $query = $db->write_query("SHOW COLUMNS FROM `{$fullName}`");

            while ($row = $db->fetch_array($query)) {
                $isNullable = strtoupper((string)$row['Null']) === 'YES';

                $default = self::mysqlDefault($row['Default'] === null ? null : (string)$row['Default'], $isNullable);

                $columns[(string)$row['Field']] = [
                    'type' => (string)$row['Type'],
                    'null' => $isNullable,
                    'default' => $default,
                    'raw_default' => $default,
                    'auto' => str_contains(strtolower((string)$row['Extra']), 'auto_increment'),
                    'pk' => strtoupper((string)$row['Key']) === 'PRI' ? 1 : 0,
                ];
            }

            self::free($query);

            return $columns;
        }

        $escapedName = $db->escape_string($fullName);

        if ($engine === self::ENGINE_PGSQL) {
            $query = $db->write_query(
                'SELECT column_name, data_type, character_maximum_length, numeric_precision, numeric_scale,'
                . ' is_nullable, column_default, is_identity FROM information_schema.columns'
                . " WHERE table_name='{$escapedName}' AND table_schema = ANY (current_schemas(false))"
                . ' ORDER BY ordinal_position'
            );

            while ($row = $db->fetch_array($query)) {
                $type = strtolower((string)$row['data_type']);

                if ($type === 'character varying' || $type === 'character') {
                    $type = ($type === 'character' ? 'char' : 'varchar') .
                        ($row['character_maximum_length'] ? '(' . (int)$row['character_maximum_length'] . ')' : '');
                } elseif ($type === 'numeric' && $row['numeric_precision'] !== null) {
                    $type = 'numeric(' . (int)$row['numeric_precision'] . ',' . (int)$row['numeric_scale'] . ')';
                }

                $rawDefault = $row['column_default'] === null ? null : (string)$row['column_default'];

                $columns[(string)$row['column_name']] = [
                    'type' => $type,
                    'null' => strtoupper((string)$row['is_nullable']) === 'YES',
                    'default' => self::defaultFromSql($rawDefault),
                    'raw_default' => $rawDefault,
                    'auto' => ($rawDefault !== null && str_starts_with(strtolower($rawDefault), 'nextval(')) ||
                        strtoupper((string)$row['is_identity']) === 'YES',
                    'pk' => 0,
                ];
            }

            self::free($query);

            return $columns;
        }

        $query = $db->write_query("PRAGMA table_info('{$escapedName}')");

        while ($row = $db->fetch_array($query)) {
            $rawDefault = $row['dflt_value'] === null ? null : (string)$row['dflt_value'];

            $columns[(string)$row['name']] = [
                'type' => (string)$row['type'],
                'null' => (int)$row['notnull'] === 0,
                'default' => self::defaultFromSql($rawDefault),
                'raw_default' => $rawDefault,
                'auto' => false,
                'pk' => (int)$row['pk'],
            ];
        }

        self::free($query);

        // A single INTEGER PRIMARY KEY is the rowid and counts as auto-increment (columnDefinition() writes it so).
        $primary = array_keys(array_filter($columns, static fn(array $column) => $column['pk'] > 0));

        if (count($primary) === 1 && strtolower($columns[$primary[0]]['type']) === 'integer') {
            $columns[$primary[0]]['auto'] = true;
        }

        return $columns;
    }

    /**
     * Pure: the PostgreSQL statements that bring one column to its definition. Run them in a transaction.
     *
     * A default has to be dropped before the type changes (it may not convert), and is set again afterwards.
     *
     * @return string[]
     */
    public static function pgsqlAlterColumnStatements(
        string $table,
        string $column,
        array $definition,
        array $change,
        string $prefix,
        callable $escape
    ): array {
        $target = "{$prefix}{$table}";

        $alter = "ALTER TABLE {$target} ALTER COLUMN {$column}";

        $statements = [];

        $newType = self::typeSqlFor($definition, self::ENGINE_PGSQL);

        $default = self::defaultSql(
            $definition,
            strtolower((string)$definition['type']),
            self::ENGINE_PGSQL,
            $escape
        );

        if ($change['type']) {
            $statements[] = "{$alter} DROP DEFAULT";

            $statements[] = "{$alter} TYPE {$newType} USING {$column}::{$newType}";

            if ($default !== null) {
                $statements[] = "{$alter} SET DEFAULT {$default}";
            }
        } elseif ($change['default']) {
            $statements[] = $default !== null ? "{$alter} SET DEFAULT {$default}" : "{$alter} DROP DEFAULT";
        }

        if ($change['null']) {
            $statements[] = isset($definition['null']) ? "{$alter} DROP NOT NULL" : "{$alter} SET NOT NULL";
        }

        return $statements;
    }

    /**
     * Pure: the statements of the SQLite table rebuild (https://www.sqlite.org/lang_altertable.html, "Making Other
     * Kinds Of Table Schema Changes"): create the new table, copy every row, drop the old table, rename the new
     * one. Columns the definition does not know keep their current definition, so no data is lost.
     *
     * @return array{create: string, copy: string, columns: string[], temporary: string}
     */
    public static function sqliteRebuildStatements(
        string $table,
        array $actual,
        array $columns,
        array $plan,
        string $prefix,
        callable $escape
    ): array {
        $lines = [];

        $primary = [];

        foreach ($actual as $name => $column) {
            if ($column['pk'] > 0) {
                $primary[$column['pk']] = $name;
            }
        }

        ksort($primary);

        $inlinePrimary = count($primary) === 1 && strtolower($actual[reset($primary)]['type']) === 'integer';

        foreach ($actual as $name => $column) {
            if (isset($plan[$name]) && isset($columns[$name])) {
                $lines[] = "\"{$name}\" " . self::columnDefinition($columns[$name], self::ENGINE_SQLITE, $escape);

                continue;
            }

            $line = "\"{$name}\"" . ($column['type'] !== '' ? ' ' . $column['type'] : '');

            if ($inlinePrimary && $column['pk'] > 0) {
                $line .= ' PRIMARY KEY';
            } else {
                if (!$column['null']) {
                    $line .= ' NOT NULL';
                }

                if ($column['raw_default'] !== null) {
                    $line .= ' DEFAULT ' . $column['raw_default'];
                }
            }

            $lines[] = $line;
        }

        if ($primary !== [] && !$inlinePrimary) {
            $lines[] = 'PRIMARY KEY (' . implode(', ', array_map(static fn(string $name) => "\"{$name}\"", $primary)) . ')';
        }

        $temporary = "{$prefix}{$table}__rebuild";

        $names = array_keys($actual);

        $list = implode(', ', array_map(static fn(string $name) => "\"{$name}\"", $names));

        return [
            'create' => "CREATE TABLE {$temporary} (\n  " . implode(",\n  ", $lines) . "\n)",
            'copy' => "INSERT INTO {$temporary} ({$list}) SELECT {$list} FROM {$prefix}{$table}",
            'columns' => $names,
            'temporary' => $temporary,
        ];
    }

    /**
     * Brings the existing columns of a table to their definitions (type, nullability, default) on every engine. A
     * column that cannot be converted is left as it is and reported by a warning (see columnErrors()); nothing is
     * ever truncated, rounded or dropped to make a conversion fit.
     *
     * Second and later runs find nothing to change, so no table is rewritten.
     */
    private static function alterChangedColumns(string $table, array $columns): void
    {
        global $db;

        // MyBB's drivers throw a DbException for a failed query only while error_reporting is on; with it off they
        // call exit() after the failed query, which would skip the rollback and the warning. Make sure the exception
        // is thrown (it is caught below) and give the setting back afterwards.
        $previousReporting = $db->error_reporting ?? 1;

        $db->error_reporting = 1;

        try {
            self::alterChangedColumnsGuarded($table, $columns);
        } finally {
            $db->error_reporting = $previousReporting;
        }
    }

    private static function alterChangedColumnsGuarded(string $table, array $columns): void
    {
        global $db;

        $engine = self::engine();

        $actual = self::actualColumns($table);

        $plan = self::planColumnChanges($actual, $columns, $engine);

        if ($plan === []) {
            return;
        }

        $prefix = $db->table_prefix;

        if ($engine === self::ENGINE_SQLITE) {
            try {
                self::rebuildSqliteTable($table, $actual, $columns, $plan);

                foreach ($plan as $column => $change) {
                    self::$columnChanges[] = "{$prefix}{$table}.{$column}: {$change['old']} -> {$change['new']}";
                }
            } catch (\Throwable $exception) {
                foreach ($plan as $column => $change) {
                    self::columnFailed($table, $column, $change, $exception->getMessage());
                }
            }

            return;
        }

        foreach ($plan as $column => $change) {
            try {
                if ($engine === self::ENGINE_MYSQL) {
                    self::changeMysqlColumn($table, $column, $columns[$column], $change, $actual[$column]);
                } else {
                    self::changePgsqlColumn($table, $column, $columns[$column], $change, $actual[$column]);
                }

                self::$columnChanges[] = "{$prefix}{$table}.{$column}: {$change['old']} -> {$change['new']}";
            } catch (\Throwable $exception) {
                self::columnFailed($table, $column, $change, $exception->getMessage());
            }
        }
    }

    private static function columnFailed(string $table, string $column, array $change, string $message): void
    {
        global $db;

        $values = [$db->table_prefix . $table, $column, $change['old'], $change['new'], $message];

        self::$columnErrors[] = self::warningText('myshowcase_admin_warning_column_conversion_failed', $values);

        self::warnKey('myshowcase_admin_warning_column_conversion_failed', $values);
    }

    /**
     * Why converting the data to the new type would lose some of it, or null when it would not. The database would
     * truncate (PostgreSQL's explicit cast to varchar(n)) or round (decimal to integer) without a word.
     */
    private static function conversionLoss(
        string $table,
        string $column,
        array $definition,
        array $current,
        string $engine
    ): ?string {
        global $db;

        if ($engine === self::ENGINE_SQLITE) {
            return null;
        }

        $quote = static fn(string $name): string => $engine === self::ENGINE_MYSQL ? "`{$name}`" : $name;

        $tableName = $quote($db->table_prefix . $table);

        $columnName = $quote($column);

        $newType = self::normalizeType(self::typeSqlFor($definition, $engine), $engine);

        $oldType = self::normalizeType($current['type'], $engine);

        if (preg_match('/^(?:var)?char\((\d+)\)/', $newType, $matches)) {
            $cast = $engine === self::ENGINE_MYSQL ? 'CHAR' : 'TEXT';

            $count = (int)(self::queryRow(
                "SELECT COUNT(*) AS total FROM {$tableName} WHERE CHAR_LENGTH(CAST({$columnName} AS {$cast})) > {$matches[1]}"
            )['total'] ?? 0);

            if ($count > 0) {
                return "{$count} row(s) hold a value longer than {$matches[1]} characters";
            }
        }

        $oldBase = preg_replace('/[( ].*$/', '', $oldType);

        $newBase = preg_replace('/[( ].*$/', '', $newType);

        if (in_array($oldBase, self::FAMILY_FRACTION, true) && in_array($newBase, self::FAMILY_INT, true)) {
            $count = (int)(self::queryRow(
                "SELECT COUNT(*) AS total FROM {$tableName} WHERE {$columnName} <> FLOOR({$columnName})"
            )['total'] ?? 0);

            if ($count > 0) {
                return "{$count} row(s) hold a fractional value that an integer would round";
            }
        }

        return null;
    }

    /**
     * Why changing a column of an existing table to the given definition would lose data, or null when it would
     * not (see the column-change paths). Reads only.
     */
    public static function conversionLossFor(string $table, string $column, array $definition): ?string
    {
        $actual = self::actualColumns($table)[$column] ?? null;

        return $actual === null ? null : self::conversionLoss($table, $column, $definition, $actual, self::engine());
    }

    private static function changeMysqlColumn(
        string $table,
        string $column,
        array $definition,
        array $change,
        array $current
    ): void {
        global $db;

        $loss = $change['type'] ? self::conversionLoss($table, $column, $definition, $current, self::ENGINE_MYSQL) : null;

        if ($loss !== null) {
            throw new \RuntimeException($loss);
        }

        // A MySQL that is not in strict mode truncates and converts without an error. ALTER TABLE is atomic, so in
        // strict mode a value that does not fit leaves the table exactly as it was.
        $previous = (string)(self::queryRow('SELECT @@SESSION.sql_mode AS sql_mode')['sql_mode'] ?? '');

        self::run("SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',STRICT_ALL_TABLES')");

        try {
            $result = self::run(
                self::modifyColumnSql($table, $column, $definition, $db->table_prefix, self::escaper())
            );

            if ($result === false || $result === null) {
                throw new \RuntimeException((string)$db->error_string());
            }
        } finally {
            self::run("SET SESSION sql_mode = '" . $db->escape_string($previous) . "'");
        }
    }

    private static function changePgsqlColumn(
        string $table,
        string $column,
        array $definition,
        array $change,
        array $current
    ): void {
        global $db;

        $loss = $change['type'] ? self::conversionLoss($table, $column, $definition, $current, self::ENGINE_PGSQL) : null;

        if ($loss !== null) {
            throw new \RuntimeException($loss);
        }

        $statements = self::pgsqlAlterColumnStatements(
            $table,
            $column,
            $definition,
            $change,
            $db->table_prefix,
            self::escaper()
        );

        self::run('BEGIN');

        try {
            foreach ($statements as $statement) {
                if (self::run($statement) === false) {
                    throw new \RuntimeException((string)$db->error_string());
                }
            }

            self::run('COMMIT');
        } catch (\Throwable $exception) {
            try {
                self::run('ROLLBACK');
            } catch (\Throwable) {
            }

            throw $exception;
        }
    }

    /**
     * Rebuilds a SQLite table with the wanted column definitions, in one transaction. Rows, indexes and unique keys
     * survive; if anything fails the old table is untouched.
     */
    private static function rebuildSqliteTable(string $table, array $actual, array $columns, array $plan): void
    {
        global $db;

        $prefix = $db->table_prefix;

        $fullName = $prefix . $table;

        $escapedName = $db->escape_string($fullName);

        $master = (string)(self::queryRow(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name='{$escapedName}'"
        )['sql'] ?? '');

        // The rebuild writes the table again from what PRAGMA table_info knows; constraints it does not report would
        // silently disappear, so refuse to rebuild such a table.
        $uniqueConstraints = 0;

        $query = $db->write_query("PRAGMA index_list('{$escapedName}')");

        while ($row = $db->fetch_array($query)) {
            if (($row['origin'] ?? '') === 'u') {
                ++$uniqueConstraints;
            }
        }

        self::free($query);

        if ($uniqueConstraints > 0 || preg_match('/\b(REFERENCES|CHECK|GENERATED)\b/i', $master)) {
            throw new \RuntimeException(
                'the table has UNIQUE, CHECK, FOREIGN KEY or generated column constraints that a rebuild would not keep'
            );
        }

        $objects = [];

        $query = $db->write_query(
            "SELECT name, sql FROM sqlite_master WHERE tbl_name='{$escapedName}' AND type IN ('index', 'trigger')"
            . ' AND sql IS NOT NULL'
        );

        while ($row = $db->fetch_array($query)) {
            $objects[] = (string)$row['sql'];
        }

        self::free($query);

        $statements = self::sqliteRebuildStatements($table, $actual, $columns, $plan, $prefix, self::escaper());

        $temporary = $statements['temporary'];

        // Foreign keys have to be off while the table is swapped, and cannot be switched inside a transaction.
        $foreignKeys = (int)(self::queryRow('PRAGMA foreign_keys')['foreign_keys'] ?? 0) === 1;

        if ($foreignKeys) {
            self::run('PRAGMA foreign_keys=OFF');
        }

        $started = false;

        try {
            self::run('BEGIN');

            $started = true;

            $before = (int)(self::queryRow("SELECT COUNT(*) AS total FROM {$fullName}")['total'] ?? 0);

            self::run("DROP TABLE IF EXISTS {$temporary}");

            self::run($statements['create']);

            self::run($statements['copy']);

            $copied = (int)(self::queryRow("SELECT COUNT(*) AS total FROM {$temporary}")['total'] ?? 0);

            if ($copied !== $before) {
                throw new \RuntimeException("{$copied} of {$before} rows were copied");
            }

            // SQLite keeps whatever it is given in a column of any type. A value that did not convert is reported
            // instead of being kept as text in a number column.
            foreach ($plan as $column => $change) {
                if (!$change['type']) {
                    continue;
                }

                $type = self::normalizeType($change['new'], self::ENGINE_SQLITE);

                $allowed = match (true) {
                    $type === 'integer' => "'integer'",
                    preg_match('/^(float|double|decimal)/', $type) === 1 => "'integer', 'real'",
                    default => null,
                };

                if ($allowed === null) {
                    continue;
                }

                $bad = (int)(self::queryRow(
                    "SELECT COUNT(*) AS total FROM {$temporary} WHERE \"{$column}\" IS NOT NULL AND typeof(\"{$column}\") NOT IN ({$allowed})"
                )['total'] ?? 0);

                if ($bad > 0) {
                    throw new \RuntimeException("{$bad} row(s) of {$column} hold a value that is not a number of the new type");
                }
            }

            self::run("DROP TABLE {$fullName}");

            self::run("ALTER TABLE {$temporary} RENAME TO {$fullName}");

            foreach ($objects as $sql) {
                self::run($sql);
            }

            if ($foreignKeys && self::queryRow('PRAGMA foreign_key_check') !== []) {
                throw new \RuntimeException('the foreign key check failed after the rebuild');
            }

            self::run('COMMIT');

            $started = false;
        } catch (\Throwable $exception) {
            if ($started) {
                try {
                    self::run('ROLLBACK');
                } catch (\Throwable) {
                }
            }

            throw $exception;
        } finally {
            if ($foreignKeys) {
                self::run('PRAGMA foreign_keys=ON');
            }
        }
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
