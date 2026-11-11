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

/**
 * Builds the JSON of existing rows in the file format. Pure: it gets the rows and returns an array; nothing is
 * read or written. Values equal to the column default are left out, so what is exported is what a file needs.
 */
final class JsonExporter
{
    /** Showcase settings that are always written, because they say what kind of showcase the file describes. */
    public const SHOWCASE_ALWAYS = ['entries_enable_subject', 'entries_enable_message', 'comments_enable'];

    /**
     * @param array $collection Collection row
     * @param array[] $fields Rows of myshowcase_fields of the collection
     * @param array<int, array[]> $options field_id => rows of myshowcase_field_data
     */
    public static function collection(array $collection, array $fields, array $options): array
    {
        usort($fields, static fn($a, $b) => [(int)$a['display_order'], (int)$a['field_id']] <=> [(int)$b['display_order'], (int)$b['field_id']]);

        return [
            'type' => 'collection',
            'version' => (string)($collection['json_version'] ?? '') !== '' ? $collection['json_version'] : '1.0.0',
            'name' => (string)$collection['name'],
            'fields' => array_map(
                static fn(array $field) => self::field($field, $options[(int)$field['field_id']] ?? []),
                $fields
            ),
        ];
    }

    /** @param string $collectionName Basename of the collection file ("blog"), or the collection name to slugify */
    public static function showcase(array $showcase, string $collectionName): array
    {
        $export = [
            'type' => 'showcase',
            'version' => (string)($showcase['json_version'] ?? '') !== '' ? $showcase['json_version'] : '1.0.0',
            'collection' => self::slug($collectionName),
        ];

        foreach (JsonSchema::showcaseColumns() as $name => $definition) {
            if (isset($showcase[$name]) && (in_array($name, self::SHOWCASE_ALWAYS, true) ||
                    (string)$showcase[$name] !== JsonSchema::defaultOf($definition))) {
                $export[$name] = self::cast($showcase[$name], $definition);
            }
        }

        return $export;
    }

    public static function field(array $field, array $options): array
    {
        $entry = [
            'field_key' => (string)$field['field_key'],
            'label' => (string)$field['field_label'],
            'html_type' => (string)$field['html_type'],
            'field_type' => (string)$field['field_type'],
        ];

        foreach (JsonSchema::fieldColumns() as $name => $definition) {
            if (isset($entry[$name]) || !isset($field[$name])) {
                continue;
            }

            if (in_array($name, JsonSchema::GROUP_COLUMNS, true)) {
                if (in_array('-1', explode(',', (string)$field[$name]), true)) {
                    $entry[$name] = 'all';
                }

                continue;
            }

            if ((string)$field[$name] !== JsonSchema::defaultOf($definition)) {
                $entry[$name] = self::cast($field[$name], $definition);
            }
        }

        if ($options) {
            usort($options, static fn($a, $b) => [(int)$a['display_order'], (int)$a['value_id']] <=> [(int)$b['display_order'], (int)$b['value_id']]);

            $entry['options'] = array_map(static function (array $option): array {
                $export = ['value_id' => (int)$option['value_id'], 'value' => (string)$option['value']];

                foreach (JsonSchema::optionColumns() as $name => $definition) {
                    if (!isset($export[$name]) && isset($option[$name]) && (string)$option[$name] !== JsonSchema::defaultOf($definition)) {
                        $export[$name] = self::cast($option[$name], $definition);
                    }
                }

                return $export;
            }, $options);
        }

        return $entry;
    }

    private static function cast(mixed $value, array $definition): int|string
    {
        return in_array(strtolower((string)$definition['type']), ['varchar', 'char'], true) ? (string)$value : (int)$value;
    }

    public static function slug(string $name): string
    {
        $slug = trim((string)preg_replace('/[^a-z0-9_\-]+/', '-', strtolower($name)), '-');

        return $slug === '' ? 'export' : $slug;
    }

    /** The text of a file: pretty printed, slashes and Unicode as they are. */
    public static function encode(array $export): string
    {
        return json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
}
