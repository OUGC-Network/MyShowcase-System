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

use function MyShowcase\Admin\languageModify;
use function MyShowcase\Plugin\Functions\dataTableStructureGet;
use function MyShowcase\Plugin\Functions\fieldDataGet;
use function MyShowcase\Plugin\Functions\fieldDataInsert;
use function MyShowcase\Plugin\Functions\fieldDataUpdate;
use function MyShowcase\Plugin\Functions\fieldHtmlTypes;
use function MyShowcase\Plugin\Functions\fieldsGet;
use function MyShowcase\Plugin\Functions\fieldsInsert;
use function MyShowcase\Plugin\Functions\fieldsUpdate;
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
            if (strlen($fieldKey) > self::KEY_MAXIMUM_LENGTH) {
                $errors[] = [
                    'key' => 'myshowcase_admin_error_field_key_too_long',
                    'message' => 'The field key can not be longer than ' . self::KEY_MAXIMUM_LENGTH . ' characters.'
                ];
            }

            if ($fieldKey !== $currentKey && in_array($fieldKey, self::reservedKeys(), true)) {
                $errors[] = [
                    'key' => 'myshowcase_admin_error_field_key_reserved',
                    'message' => "The field key {$fieldKey} is a column the entries table already has."
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

        if (!empty($data['regular_expression']) && @preg_match($data['regular_expression'], '') === false) {
            $errors[] = [
                'key' => 'myshowcase_admin_error_regular_expression',
                'message' => 'The regular expression is not valid.'
            ];
        }

        if (isset($data['html_type']) && !isset(fieldHtmlTypes()[(string)$data['html_type']])) {
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

        return $errors;
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
        $old = $fieldId ? (fieldsGet(["field_id='{$fieldId}'"], ['field_key', 'html_type', 'json_file'])[$fieldId] ?? []) : [];

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
