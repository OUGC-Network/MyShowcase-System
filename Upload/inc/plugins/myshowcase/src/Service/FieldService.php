<?php
/**
 * MyShowcase Plugin for MyBB - Main Plugin
 * Copyright 2012 CommunityPlugins.com, All Rights Reserved
 *
 * Website: https://github.com/Sama34/MyShowcase-System
 * License: Creative Commons Attribution-NonCommerical ShareAlike 3.0
 * http://creativecommons.org/licenses/by-nc-sa/3.0/legalcode
 * File: \inc\plugins\myshowcase\src\Service\FieldService.php
 *
 */

declare(strict_types=1);

namespace MyShowcase\Service;

use MyShowcase\Enum\CacheDataTypes;
use MyShowcase\Enum\FieldHtmlTypes;
use MyShowcase\Service\JsonSync\JsonLock;
use MyShowcase\Util\FieldBoundUtil;
use MyShowcase\Util\MultipleChoiceUtil;

use function MyShowcase\Admin\dbVerifyTables;
use function MyShowcase\Admin\languageModify;
use function MyShowcase\Plugin\Functions\dataTableStructureGet;
use function MyShowcase\Plugin\Functions\fieldDataGet;
use function MyShowcase\Plugin\Functions\fieldDataInsert;
use function MyShowcase\Plugin\Functions\fieldDataUpdate;
use function MyShowcase\Plugin\Functions\fieldHtmlTypes;
use function MyShowcase\Plugin\Functions\fieldsGet;
use function MyShowcase\Plugin\Functions\fieldsInsert;
use function MyShowcase\Plugin\Functions\fieldsUpdate;
use function MyShowcase\Plugin\Functions\fieldTypeMatchInt;
use function MyShowcase\Plugin\Functions\fieldTypesGet;
use function MyShowcase\Plugin\Functions\showcaseDataTableExists;
use function MyShowcase\Plugin\Functions\showcaseDataTableFieldExists;
use function MyShowcase\Plugin\Functions\showcaseGet;

use const MyShowcase\Plugin\Core\DATA_TABLE_STRUCTURE;

/**
 * Validates and writes fields (myshowcase_fields), their option values (myshowcase_field_data) and the language
 * entries of their labels. Used by the ACP field pages and by the JSON sync.
 */
final class FieldService
{
    public const KEY_MAXIMUM_LENGTH = 30;

    public function __construct(private readonly CacheManager $cacheManager)
    {
    }

    /** The form of a field key the database holds: lower case, word characters only, no leading or trailing "_". */
    public static function normalizeKey(string $fieldKey): string
    {
        return trim(my_strtolower((string)preg_replace('#[^\w]#', '_', $fieldKey)), '_');
    }

    /** Names a field key can not have because the data table already has a column of that name. */
    public static function reservedKeys(): array
    {
        return array_merge(array_keys(DATA_TABLE_STRUCTURE['myshowcase_data']), ['keys', 'full_keys', 'unique_keys']);
    }

    /**
     * Words that a field key can not be: an SQL word that an engine refuses as an unquoted column name (decisions D153 and D158).
     * Each candidate word of the engines' own keyword sources (MySQL 8.4 information_schema.KEYWORDS with RESERVED = 1,
     * PostgreSQL pg_get_keywords() with category R or T, the SQLite documented keyword list, and the MariaDB 13.0.2
     * reserved-word documentation) was created as an unquoted column on each engine (field matrix, stage k, cells
     * place:key-sql-words-create|k). The list holds every word that failed on at least one engine, together with the 19 words
     * that the full pipeline proved (decision D152). Sorted; one list for all engines. A word that an engine accepts unquoted but
     * another refuses is refused on all of them. The MariaDB source is probed on MariaDB 13.0.2 only; the words it adds are
     * the nine that no other source has (sources in tests/fields/sql_keyword_sources.json).
     */
    public const SQL_RESERVED_KEYS = [
        'accessible',
        'add',
        'all',
        'alter',
        'analyse',
        'analyze',
        'and',
        'any',
        'array',
        'as',
        'asc',
        'asensitive',
        'asymmetric',
        'authorization',
        'autoincrement',
        'before',
        'between',
        'bigint',
        'binary',
        'blob',
        'both',
        'by',
        'call',
        'cascade',
        'case',
        'cast',
        'change',
        'char',
        'character',
        'check',
        'collate',
        'collation',
        'column',
        'commit',
        'concurrently',
        'condition',
        'constraint',
        'continue',
        'convert',
        'create',
        'cross',
        'cume_dist',
        'current_catalog',
        'current_date',
        'current_role',
        'current_schema',
        'current_time',
        'current_timestamp',
        'current_user',
        'cursor',
        'database',
        'databases',
        'day_hour',
        'day_microsecond',
        'day_minute',
        'day_second',
        'dec',
        'decimal',
        'declare',
        'default',
        'deferrable',
        'delayed',
        'delete',
        'delete_domain_id',
        'dense_rank',
        'desc',
        'describe',
        'deterministic',
        'distinct',
        'distinctrow',
        'div',
        'do',
        'do_domain_ids',
        'double',
        'drop',
        'dual',
        'each',
        'else',
        'elseif',
        'empty',
        'enclosed',
        'end',
        'escape',
        'escaped',
        'except',
        'exists',
        'exit',
        'explain',
        'false',
        'fetch',
        'first_value',
        'float',
        'float4',
        'float8',
        'for',
        'force',
        'foreign',
        'freeze',
        'from',
        'full',
        'fulltext',
        'function',
        'generated',
        'get',
        'grant',
        'group',
        'grouping',
        'groups',
        'having',
        'high_priority',
        'hour_microsecond',
        'hour_minute',
        'hour_second',
        'if',
        'ignore',
        'ignore_domain_ids',
        'ilike',
        'in',
        'index',
        'infile',
        'initially',
        'inner',
        'inout',
        'insensitive',
        'insert',
        'int',
        'int1',
        'int2',
        'int3',
        'int4',
        'int8',
        'integer',
        'intersect',
        'interval',
        'into',
        'io_after_gtids',
        'io_before_gtids',
        'is',
        'isnull',
        'iterate',
        'join',
        'json_table',
        'key',
        'keys',
        'kill',
        'lag',
        'last_value',
        'lateral',
        'lead',
        'leading',
        'leave',
        'left',
        'like',
        'limit',
        'linear',
        'lines',
        'load',
        'localtime',
        'localtimestamp',
        'lock',
        'long',
        'longblob',
        'longtext',
        'loop',
        'low_priority',
        'match',
        'maxvalue',
        'mediumblob',
        'mediumint',
        'mediumtext',
        'middleint',
        'minute_microsecond',
        'minute_second',
        'mod',
        'modifies',
        'natural',
        'no_write_to_binlog',
        'not',
        'nothing',
        'notnull',
        'nth_value',
        'ntile',
        'null',
        'numeric',
        'of',
        'offset',
        'on',
        'only',
        'optimize',
        'optimizer_costs',
        'option',
        'optionally',
        'or',
        'order',
        'out',
        'outer',
        'outfile',
        'over',
        'overlaps',
        'page_checksum',
        'parse_vcol_expr',
        'partition',
        'percent_rank',
        'placing',
        'precision',
        'primary',
        'procedure',
        'purge',
        'range',
        'rank',
        'read',
        'read_write',
        'reads',
        'real',
        'recursive',
        'ref_system_id',
        'references',
        'regexp',
        'release',
        'rename',
        'repeat',
        'replace',
        'require',
        'resignal',
        'restrict',
        'return',
        'returning',
        'revoke',
        'right',
        'rlike',
        'row',
        'row_number',
        'rows',
        'schema',
        'schemas',
        'second_microsecond',
        'select',
        'sensitive',
        'separator',
        'session_user',
        'set',
        'show',
        'signal',
        'similar',
        'smallint',
        'some',
        'spatial',
        'specific',
        'sql',
        'sql_big_result',
        'sql_calc_found_rows',
        'sql_small_result',
        'sqlexception',
        'sqlstate',
        'sqlwarning',
        'ssl',
        'starting',
        'stats_auto_recalc',
        'stats_persistent',
        'stats_sample_pages',
        'stored',
        'straight_join',
        'symmetric',
        'system',
        'system_user',
        'table',
        'tablesample',
        'terminated',
        'then',
        'tinyblob',
        'tinyint',
        'tinytext',
        'to',
        'trailing',
        'transaction',
        'trigger',
        'true',
        'undo',
        'union',
        'unique',
        'unlock',
        'unsigned',
        'update',
        'usage',
        'use',
        'user',
        'using',
        'utc_date',
        'utc_time',
        'utc_timestamp',
        'values',
        'varbinary',
        'varchar',
        'varcharacter',
        'variadic',
        'varying',
        'verbose',
        'virtual',
        'when',
        'where',
        'while',
        'window',
        'with',
        'write',
        'xor',
        'year_month',
        'zerofill',
    ];

    /** The names a field key can not have: the columns of the entries table and the SQL words of SQL_RESERVED_KEYS. */
    public static function reservedFieldKeys(): array
    {
        return array_values(array_unique(array_merge(self::reservedKeys(), self::SQL_RESERVED_KEYS)));
    }

    /**
     * Pure: the problem with a field key, or null when it is a key the board can use (decision D152). The key must already be
     * in the form the database holds: a lower case letter first, then lower case letters and digits, with single "_" between
     * them; at most KEY_MAXIMUM_LENGTH characters; not a reserved name. Nothing is changed: a key is refused, never converted.
     */
    public static function fieldKeyProblem(string $key): ?string
    {
        if ($key === '') {
            return 'missing';
        }

        if (preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/', $key) !== 1) {
            return 'format';
        }

        if (strlen($key) > self::KEY_MAXIMUM_LENGTH) {
            return 'length';
        }

        if (in_array($key, self::reservedFieldKeys(), true)) {
            return 'reserved';
        }

        return null;
    }

    /**
     * @param array $data The field columns being saved. field_key must already be normalized; columns that are not
     *                    present are not checked.
     * @param string|null $currentKey The key the field has now (null for a new field). The reserved-name check only
     *                    applies when the key is new or changes.
     * @return array<int, array{key: string, message: string}> Language key and English text of every problem.
     */
    public function validate(array $data, int $collectionId, int $fieldId = 0, ?string $currentKey = null): array
    {
        global $db;

        $errors = [];

        $fieldKey = (string)($data['field_key'] ?? '');

        if ($fieldKey === '') {
            $errors[] = [
                'key' => 'myshowcase_admin_error_missing_required_fields',
                'message' => 'A required field is missing.'
            ];
        } else {
            $keyProblem = self::fieldKeyProblem($fieldKey);

            if ($keyProblem === 'format') {
                $errors[] = [
                    'key' => 'myshowcase_admin_error_field_key_format',
                    'message' => 'The field key must start with a lower case letter and hold only lower case letters, digits and single underscores between them.'
                ];
            }

            if ($keyProblem === 'length') {
                $errors[] = [
                    'key' => 'myshowcase_admin_error_field_key_too_long',
                    'message' => 'The field key can not be longer than ' . self::KEY_MAXIMUM_LENGTH . ' characters.'
                ];
            }

            if ($keyProblem === 'reserved' && $fieldKey !== $currentKey) {
                $errors[] = [
                    'key' => 'myshowcase_admin_error_field_key_reserved',
                    'message' => "The field key {$fieldKey} is a reserved name: a column of the entries table or a word of SQL."
                ];
            }

            // No LIMIT 1 here: on SQLite and PostgreSQL the helpers return [false] for "no row" in that mode.
            if (fieldsGet(
                [
                    "collection_id='{$collectionId}'",
                    "field_key='{$db->escape_string($fieldKey)}'",
                    "field_id<>'{$fieldId}'"
                ],
                ['field_key']
            )) {
                $errors[] = [
                    'key' => 'myshowcase_admin_error_duplicated_name',
                    'message' => 'The selected name is already in use.'
                ];
            }
        }

        if (($data['minimum_length'] ?? 0) > ($data['maximum_length'] ?? 0)) {
            $errors[] = ['key' => 'myshowcase_admin_error_invalid_min_max', 'message' => 'Invalid minimum/maximum values.'];
        }

        // The bounds are checked for the types that have them only; for the other types they are saved as empty.
        $htmlType = (string)($data['html_type'] ?? '');

        if (FieldBoundUtil::isBoundedType($htmlType)) {
            $minimumValue = (string)($data['minimum_value'] ?? '');
            $maximumValue = (string)($data['maximum_value'] ?? '');
            $bounds = ['minimum_value' => $minimumValue, 'maximum_value' => $maximumValue];

            foreach ($bounds as $bound => $value) {
                if ($value !== '' && !FieldBoundUtil::formatIsValid($htmlType, $value)) {
                    $errors[] = [
                        'key' => 'myshowcase_admin_error_bound_format',
                        'message' => 'A minimum or maximum value does not have the format of its field type.'
                    ];
                }
            }

            if ($htmlType === FieldHtmlTypes::Range && ($minimumValue === '' || $maximumValue === '')) {
                $errors[] = [
                    'key' => 'myshowcase_admin_error_range_bounds',
                    'message' => 'A range field needs both a minimum and a maximum value.'
                ];
            } elseif ($minimumValue !== '' && $maximumValue !== '' &&
                FieldBoundUtil::formatIsValid($htmlType, $minimumValue) &&
                FieldBoundUtil::formatIsValid($htmlType, $maximumValue) &&
                FieldBoundUtil::isInverted($htmlType, $minimumValue, $maximumValue)) {
                $errors[] = [
                    'key' => 'myshowcase_admin_error_bounds_order',
                    'message' => 'The minimum value is above the maximum value.'
                ];
            }
        }

        if (!empty($data['regular_expression']) && @preg_match($data['regular_expression'], '') === false) {
            $errors[] = [
                'key' => 'myshowcase_admin_error_regular_expression',
                'message' => 'The regular expression is not valid.'
            ];
        }

        if (isset($data['html_type']) && in_array((string)$data['html_type'], \MyShowcase\Plugin\Functions\reservedHtmlTypes(), true)) {
            $errors[] = [
                'key' => 'myshowcase_admin_error_reserved_html_type',
                'message' => 'The HTML type is reserved for a later feature and cannot be used yet.'
            ];
        } elseif (isset($data['html_type']) && !isset(fieldHtmlTypes()[(string)$data['html_type']])) {
            $errors[] = [
                'key' => 'myshowcase_admin_error_invalid_html_type',
                'message' => 'The HTML type is not one of the supported types.'
            ];
        }

        if (isset($data['field_type']) && !isset(fieldTypesGet()[strtolower((string)$data['field_type'])])) {
            $errors[] = [
                'key' => 'myshowcase_admin_error_invalid_field_type',
                'message' => 'The database type is not one of the supported types.'
            ];
        }

        // The rules of the multiple-choice fields (decisions D184, D187, D180), with the stored values of an update.
        $old = $fieldId ? (fieldsGet(
            ["field_id='{$fieldId}'"],
            ['html_type', 'allow_multiple_values', 'field_type']
        )[$fieldId] ?? []) : [];

        $htmlType = (string)($data['html_type'] ?? $old['html_type'] ?? '');
        $allowMultiple = $data['allow_multiple_values'] ?? $old['allow_multiple_values'] ?? 0;
        $fieldType = (string)($data['field_type'] ?? $old['field_type'] ?? '');

        if (!MultipleChoiceUtil::columnTypeFits($htmlType, $allowMultiple, $fieldType)) {
            $errors[] = [
                'key' => 'myshowcase_admin_error_multiple_column_type',
                'message' => 'A field with several values must have the database type text, so that its values fit.'
            ];
        }

        if ($old && MultipleChoiceUtil::isMultiple((string)$old['html_type'], $old['allow_multiple_values']) !==
            MultipleChoiceUtil::isMultiple($htmlType, $allowMultiple) && $this->fieldHasValues($fieldId)) {
            $errors[] = [
                'key' => 'myshowcase_admin_error_multiple_toggle_in_use',
                'message' => 'The several-values setting of this field cannot change while an entry holds a value for it.'
            ];
        }

        return $errors;
    }

    /**
     * Whether an entry of any showcase holds a value of the field: a row of the child table, or a data column that is not
     * blank (decision D180). Used to refuse changing the several-values setting of a field.
     */
    public function fieldHasValues(int $fieldId): bool
    {
        global $db;

        $field = fieldsGet(["field_id='{$fieldId}'"], ['collection_id', 'field_key', 'field_type'])[$fieldId] ?? null;

        if (!$field) {
            return false;
        }

        $child = $db->fetch_array($db->simple_select('myshowcase_field_values', 'COUNT(*) AS n', "field_id='{$fieldId}'"));

        if ((int)$child['n'] > 0) {
            return true;
        }

        $key = (string)$field['field_key'];
        $blank = fieldTypeMatchInt((string)$field['field_type']) ? "{$key}<>0" : "{$key}<>''";

        foreach (array_keys(showcaseGet(["collection_id='" . (int)$field['collection_id'] . "'"])) as $showcaseID) {
            if (!showcaseDataTableExists((int)$showcaseID) || !showcaseDataTableFieldExists((int)$showcaseID, $key)) {
                continue;
            }

            $row = $db->fetch_array($db->simple_select(
                'myshowcase_data' . (int)$showcaseID,
                'COUNT(*) AS n',
                "{$key} IS NOT NULL AND {$blank}"
            ));

            if ((int)$row['n'] > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Brings the data table of every showcase of the collection in line with the field definitions, so that a changed
     * database type or several-values setting becomes the column type, through the same column change (with its loss check)
     * the ACP rebuild uses.
     */
    public function rebuildShowcaseTables(int $collectionId): void
    {
        foreach (array_keys(showcaseGet(["collection_id='{$collectionId}'"])) as $showcaseID) {
            if (showcaseDataTableExists((int)$showcaseID)) {
                dbVerifyTables(['myshowcase_data' . (int)$showcaseID => dataTableStructureGet((int)$showcaseID)], false);
            }
        }
    }

    /**
     * Inserts a field, or updates it when $fieldId is given. A changed field_key renames the column in the entries
     * table of every showcase that uses the collection, the key stored with the field's option values and the
     * language entry of the label.
     *
     * Hooks field_insert_update_end (fieldsInsert) fires as before.
     *
     * @param array<string, string> $labels Language directory => label text; empty to leave the language file alone.
     * @return int The id of the field.
     */
    public function save(array $data, int $collectionId, int $fieldId = 0, array $labels = [], bool $fromSync = false): int
    {
        $old = $fieldId ? (fieldsGet(
            ["field_id='{$fieldId}'"],
            ['field_key', 'html_type', 'json_file', 'field_type', 'allow_multiple_values']
        )[$fieldId] ?? []) : [];

        // Only the sync writes the json_* columns; a linked field always ends up with the values of its file.
        if (!$fromSync) {
            $data = JsonLock::stripJson($data);

            if ($old && $old['json_file'] !== '') {
                $data = JsonLock::default()->enforce('field', $old, $data);
            }
        }


        $renamed = $old && isset($data['field_key']) && $data['field_key'] !== $old['field_key'];

        if (!$fieldId) {
            $fieldId = fieldsInsert(['collection_id' => $collectionId] + $data);
        } else {
            if ($renamed) {
                $this->renameDataColumns($collectionId, $old['field_key'], $data['field_key']);
            }

            fieldsUpdate($data, $fieldId);

            // A changed database type or several-values setting changes the column (decisions D184, D187).
            $newHtmlType = (string)($data['html_type'] ?? $old['html_type']);
            $newAllow = $data['allow_multiple_values'] ?? $old['allow_multiple_values'];

            if ($old && (($data['field_type'] ?? $old['field_type']) !== $old['field_type']) ||
                MultipleChoiceUtil::isMultiple((string)$old['html_type'], $old['allow_multiple_values']) !==
                MultipleChoiceUtil::isMultiple($newHtmlType, $newAllow)) {
                $this->rebuildShowcaseTables($collectionId);
            }

            if ($renamed && in_array($old['html_type'], [
                    FieldHtmlTypes::CheckBox,
                    FieldHtmlTypes::Radio,
                    FieldHtmlTypes::Select,
                ])) {
                foreach (
                    fieldDataGet(["field_id='{$fieldId}'", "collection_id='{$collectionId}'"]) as $fieldDataID => $fieldDataData
                ) {
                    fieldDataUpdate(['field_key' => $data['field_key']], $fieldDataID);
                }
            }
        }

        $fieldKey = $data['field_key'] ?? $old['field_key'] ?? '';

        if ($labels && $this->canWriteLanguage($collectionId)) {
            foreach ($labels as $language => $label) {
                languageModify(
                    'myshowcase_fs' . $collectionId,
                    ['myshowcase_collection_field_' . $fieldKey => $label],
                    $renamed ? ['myshowcase_collection_field_' . $old['field_key'] => ''] : [],
                    (string)$language
                );
            }
        }

        $this->cacheManager->update(CacheDataTypes::Fields);

        $this->cacheManager->update(CacheDataTypes::FieldData);

        return $fieldId;
    }

    /** Renames the column of a field in the entries table of every showcase that uses the collection. */
    public function renameDataColumns(int $collectionId, string $oldKey, string $newKey): void
    {
        foreach (showcaseGet(["collection_id='{$collectionId}'"]) as $showcaseID => $showcaseData) {
            if (!showcaseDataTableExists($showcaseID)) {
                continue;
            }

            $dataTableStructure = dataTableStructureGet($showcaseID);

            if (showcaseDataTableFieldExists($showcaseID, $oldKey) &&
                !showcaseDataTableFieldExists($showcaseID, $newKey)) {
                // SchemaManager renames natively on SQLite and PostgreSQL and closes open statements first (MyBB's
                // rename_column emulation fails with "table is locked" on SQLite).
                SchemaManager::renameColumn(
                    'myshowcase_data' . $showcaseID,
                    $oldKey,
                    $newKey,
                    $dataTableStructure[$oldKey]
                );
            }
        }
    }

    /** Whether the language file of the collection (and its directory) can be written. */
    public function canWriteLanguage(int $collectionId): bool
    {
        global $lang;

        $languagePath = str_replace('admin', '', $lang->path . '/' . $lang->language);

        $file = $languagePath . '/myshowcase_fs' . $collectionId . '.lang.php';

        return is_writable($languagePath) && (!file_exists($file) || is_writable($file));
    }

    /**
     * Inserts an option value of a select, radio or checkbox field, or updates it when $fieldDataId is given.
     * The caches are refreshed by refreshOptionCaches() once the caller has written all the options it has.
     *
     * @return int The id of the option row.
     */
    public function saveOption(array $data, int $fieldDataId = 0, bool $fromSync = false): int
    {
        if (!$fromSync && $fieldDataId) {
            $option = fieldDataGet(["field_data_id='{$fieldDataId}'"], ['field_id', 'value_id'])[$fieldDataId] ?? null;

            $field = $option ? (fieldsGet(["field_id='{$option['field_id']}'"], ['field_key', 'json_file'])[(int)$option['field_id']] ?? null) : null;

            if ($field !== null && $field['json_file'] !== '') {
                $data = array_merge($data, JsonLock::default()->optionValues($field, (int)$option['value_id']));
            }
        }

        if ($fieldDataId) {
            return fieldDataUpdate($data, $fieldDataId);
        }

        return fieldDataInsert($data);
    }

    /**
     * Why a field can not be deleted, or null when it can. A field a JSON file manages has to be removed from the
     * file (and the file applied) or its collection detached; fields of your own can be deleted.
     *
     * @return array{key: string, message: string}|null
     */
    public function deletionBlock(int $fieldId): ?array
    {
        $field = fieldsGet(["field_id='{$fieldId}'"], ['field_key', 'json_file'])[$fieldId] ?? null;

        if ($field === null || $field['json_file'] === '') {
            return null;
        }

        return [
            'key' => 'myshowcase_admin_error_field_delete_managed',
            'message' => "The field {$field['field_key']} is managed by {$field['json_file']}. Remove it from the file and apply the file, or detach the collection from the file.",
        ];
    }

    public function refreshOptionCaches(): void
    {
        $this->cacheManager->update(CacheDataTypes::Fields);

        $this->cacheManager->update(CacheDataTypes::FieldData);
    }
}
