<?php

declare(strict_types=1);

namespace MyShowcase\Repository;

use DB_MySQLi;
use DB_PgSQL;
use DB_SQLite;
use MysqlPdoDbDriver;
use PostgresPdoDbDriver;
use MyShowcase\Service\SchemaManager;

/**
 * Rows of myshowcase_field_values: the selected values of the multiple-choice fields (decision D61). One row per entry, field
 * and value, keyed by the option's value_id (decisions D108 and D128). The rows are the authority of the values; the entry
 * column of the field keeps a cache of them (see cacheOf()), written in the same transaction, which is never read for
 * permissions, validation, filtering or export.
 *
 * Transactions are sent as raw statements, as ImportTransaction does: MyBB has no transaction API on any engine.
 */
class FieldValuesRepository
{
    /**
     * The separator of the cache. A value_id is a whole number (the ACP and the JSON file set it so), so it never contains
     * this character and the cache can be split without ambiguity.
     */
    public const CACHE_DELIMITER = ',';

    private const TABLE = 'myshowcase_field_values';

    private const BEGIN = [
        SchemaManager::ENGINE_MYSQL => 'START TRANSACTION',
        SchemaManager::ENGINE_PGSQL => 'BEGIN',
        SchemaManager::ENGINE_SQLITE => 'BEGIN',
    ];

    public function __construct(
        private readonly DB_SQLite|DB_PgSQL|PostgresPdoDbDriver|MysqlPdoDbDriver|DB_MySQLi $database,
    ) {
    }

    /**
     * The cache of a list of value ids: the ids in the order given, joined by the delimiter. The caller passes them in the
     * option order of the field.
     *
     * @param string[] $value_ids
     */
    public static function cacheOf(array $value_ids): string
    {
        return implode(self::CACHE_DELIMITER, $value_ids);
    }

    /**
     * The value ids of a cache, in the order stored. An empty cache is no value.
     *
     * @return string[]
     */
    public static function valuesOfCache(string $cache): array
    {
        return $cache === '' ? [] : explode(self::CACHE_DELIMITER, $cache);
    }

    /**
     * The value ids selected for one field of one entry, as the rows hold them.
     *
     * @return string[]
     */
    public function valuesOf(int $showcase_id, int $entry_id, int $field_id): array
    {
        $query = $this->database->simple_select(
            self::TABLE,
            'value_id',
            "showcase_id='{$showcase_id}' AND entry_id='{$entry_id}' AND field_id='{$field_id}'",
            ['order_by' => 'field_value_id']
        );

        $values = [];

        while ($row = $this->database->fetch_array($query)) {
            $values[] = (string)$row['value_id'];
        }

        return $values;
    }

    /**
     * Replaces the rows of one field of one entry with the given value ids. Callers run it inside begin()/commit().
     *
     * @param string[] $value_ids
     */
    public function replace(int $showcase_id, int $entry_id, int $field_id, array $value_ids): void
    {
        $this->database->delete_query(
            self::TABLE,
            "showcase_id='{$showcase_id}' AND entry_id='{$entry_id}' AND field_id='{$field_id}'"
        );

        foreach ($value_ids as $value_id) {
            $this->database->insert_query(self::TABLE, [
                'showcase_id' => $showcase_id,
                'entry_id' => $entry_id,
                'field_id' => $field_id,
                'value_id' => $this->database->escape_string((string)$value_id),
            ]);
        }
    }

    /** Removes every value of an entry (the entry is deleted). */
    public function deleteForEntry(int $showcase_id, int $entry_id): void
    {
        $this->database->delete_query(self::TABLE, "showcase_id='{$showcase_id}' AND entry_id='{$entry_id}'");
    }

    /** Removes every value of a field (the field is deleted). */
    public function deleteForField(int $field_id): void
    {
        $this->database->delete_query(self::TABLE, "field_id='{$field_id}'");
    }

    /** Removes the values that name one option of a field (the option is deleted). */
    public function deleteForOption(int $field_id, string $value_id): void
    {
        $this->database->delete_query(
            self::TABLE,
            "field_id='{$field_id}' AND value_id='" . $this->database->escape_string($value_id) . "'"
        );
    }

    /** Removes every value of a showcase (the showcase is deleted). */
    public function deleteForShowcase(int $showcase_id): void
    {
        $this->database->delete_query(self::TABLE, "showcase_id='{$showcase_id}'");
    }

    /** Opens a transaction on the board's engine. */
    public function begin(): void
    {
        $this->database->write_query(self::BEGIN[SchemaManager::engine()] ?? self::BEGIN[SchemaManager::ENGINE_MYSQL]);
    }

    public function commit(): void
    {
        $this->database->write_query('COMMIT');
    }

    public function rollback(): void
    {
        $this->database->write_query('ROLLBACK');
    }
}
