<?php
/**
 * MyShowcase Plugin for MyBB - Main Plugin
 * Copyright 2012 CommunityPlugins.com, All Rights Reserved
 *
 * Website: https://github.com/Sama34/MyShowcase-System
 * License: Creative Commons Attribution-NonCommerical ShareAlike 3.0
 * http://creativecommons.org/licenses/by-nc-sa/3.0/legalcode
 *
 */

declare(strict_types=1);

namespace MyShowcase\Service\JsonSync;

use MyShowcase\Service\SchemaManager;

use function MyShowcase\Plugin\Functions\collectionGet;
use function MyShowcase\Plugin\Functions\fieldDataGet;
use function MyShowcase\Plugin\Functions\fieldsGet;
use function MyShowcase\Plugin\Functions\showcaseDataTableExists;
use function MyShowcase\Plugin\Functions\showcaseGet;

use const MyShowcase\Plugin\Core\TABLES_DATA;

/** Reads what the planner needs from the database. Reads only. */
final class JsonState
{
    /** Columns of a table definition, without the table-level keys. */
    public static function columnNames(string $table): array
    {
        return array_keys(array_filter(TABLES_DATA[$table], static fn($definition) => is_array($definition) && isset($definition['type'])));
    }

    /** The collection row linked to a file (at most one), or null. */
    public static function collectionRow(string $file): ?array
    {
        global $db;

        $rows = collectionGet(["json_file='{$db->escape_string($file)}'"], self::columnNames('myshowcase_collections'));

        return $rows ? reset($rows) : null;
    }

    /** The state a collection plan is built from. */
    public static function collection(string $file, JsonFiles $files, ?array $row = null): array
    {
        $row ??= self::collectionRow($file);

        $state = [
            'row' => $row,
            'fields' => [],
            'options' => [],
            'showcases' => [],
            'php_steps' => $files->phpSteps('collection', JsonFiles::basenameOf($file)),
            'language_writable' => null,
        ];

        global $lang;

        if (is_object($lang) && isset($lang->path, $lang->language)) {
            $path = str_replace('admin', '', $lang->path . '/' . $lang->language);

            $state['language_writable'] = is_writable($path);
        }

        if ($row === null) {
            return $state;
        }

        $collectionId = (int)$row['collection_id'];

        foreach (fieldsGet(["collection_id='{$collectionId}'"], self::columnNames('myshowcase_fields')) as $fieldId => $field) {
            $state['fields'][$field['field_key']] = $field;
        }

        foreach (
            fieldDataGet(
                ["collection_id='{$collectionId}'"],
                ['field_id', 'value_id', 'value', 'display_order', 'display_style']
            ) as $option
        ) {
            $state['options'][(int)$option['field_id']][(int)$option['value_id']] = $option;
        }

        foreach (showcaseGet(["collection_id='{$collectionId}'"], ['name'], [], false) as $showcaseId => $showcase) {
            $state['showcases'][$showcaseId] = [
                'name' => $showcase['name'],
                'data_table' => showcaseDataTableExists($showcaseId, true),
            ];
        }

        $fields = $state['fields'];

        $state['losses'] = static function (int $showcaseId, string $fieldKey, array $columns) use ($fields): ?string {
            $current = $fields[$fieldKey] ?? null;

            if ($current === null) {
                return null;
            }

            $definition = [
                'type' => $columns['field_type'] ?? $current['field_type'],
                'size' => (int)($columns['maximum_length'] ?? $current['maximum_length']),
            ];

            return SchemaManager::conversionLossFor('myshowcase_data' . $showcaseId, $fieldKey, $definition);
        };

        return $state;
    }

    /** The state a showcase plan is built from. */
    public static function showcase(array $decoded, string $file, JsonFiles $files, ?int $showcaseId): array
    {
        $collectionFile = JsonFiles::relativePath('collection', (string)$decoded['collection']);

        $read = $files->read($collectionFile);

        $valid = $read['data'] !== null && JsonValidator::validate($read['data'], 'collection') === [];

        $showcase = null;

        if ($showcaseId) {
            $rows = showcaseGet(["showcase_id='{$showcaseId}'"], self::columnNames('myshowcase_config'), [], false);

            $showcase = $rows[$showcaseId] ?? null;
        }

        return [
            'showcase' => $showcase,
            'collection' => self::collection($collectionFile, $files),
            'collection_file' => $valid ? $read['data'] : null,
            'collection_file_path' => $collectionFile,
            'data_table' => $showcaseId ? showcaseDataTableExists($showcaseId, true) : false,
        ];
    }
}
