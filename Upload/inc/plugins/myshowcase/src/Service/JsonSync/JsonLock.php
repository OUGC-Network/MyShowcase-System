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

use function MyShowcase\Plugin\Functions\fieldsGet;
use function MyShowcase\Plugin\Functions\showcaseGet;

/**
 * Which columns of a linked row its JSON file manages, and what the file sets them to. The write services use it
 * to put the file's values back whatever a request says; the forms use it to disable inputs.
 */
final class JsonLock
{
    /** @var array<string, array{mtime: int, size: int, result: array}> */
    private static array $cache = [];

    public function __construct(private readonly JsonFiles $files)
    {
    }

    public static function default(): self
    {
        return new self(JsonFiles::default());
    }

    /** The validated content of a file: ['valid' => bool, 'data' => ?array, ...] (see JsonSync::inspect). */
    public function file(string $relativePath): array
    {
        $path = $this->files->resolve($relativePath);

        if ($path === null) {
            return ['exists' => false, 'valid' => false, 'errors' => [], 'data' => null];
        }

        clearstatcache(true, $path);

        $stamp = ['mtime' => (int)filemtime($path), 'size' => (int)filesize($path)];

        $cached = self::$cache[$path] ?? null;

        if ($cached === null || $cached['mtime'] !== $stamp['mtime'] || $cached['size'] !== $stamp['size']) {
            $cached = $stamp + ['result' => JsonSync::inspect($this->files, $relativePath)];

            self::$cache[$path] = $cached;
        }

        return $cached['result'];
    }

    /**
     * The values the file sets for the managed columns of a row.
     *
     * @param string $type collection, showcase or field
     * @param array $row The row as it is in the database (json_file, and field_key for a field)
     * @return array<string, mixed> Empty when the row is not linked or its file can not be used.
     */
    public function values(string $type, array $row): array
    {
        $file = (string)($row['json_file'] ?? '');

        if ($file === '') {
            return [];
        }

        $loaded = $this->file($file);

        if (!$loaded['valid']) {
            return [];
        }

        $data = $loaded['data'];

        if ($type === 'collection') {
            return ['name' => $data['name']];
        }

        if ($type === 'showcase') {
            $values = array_intersect_key($data, JsonSchema::showcaseColumns());

            $collection = JsonState::collectionRow(JsonFiles::relativePath('collection', (string)$data['collection']));

            if ($collection !== null) {
                $values['collection_id'] = (int)$collection['collection_id'];
            }

            return $values;
        }

        foreach ($data['fields'] as $entry) {
            if ($entry['field_key'] === ($row['field_key'] ?? null)) {
                return JsonPlanner::fieldColumns($entry);
            }
        }

        return [];
    }

    /** @return string[] */
    public function managedColumns(string $type, array $row): array
    {
        return array_keys($this->values($type, $row));
    }

    /** The data with every managed column set to the file's value. */
    public function enforce(string $type, array $row, array $data): array
    {
        return array_merge($data, $this->values($type, $row));
    }

    /**
     * The columns the file sets for one option value of a linked field (value, and display_order or display_style
     * when the file gives them).
     *
     * @return array<string, mixed>
     */
    public function optionValues(array $fieldRow, int $valueId): array
    {
        $file = (string)($fieldRow['json_file'] ?? '');

        if ($file === '') {
            return [];
        }

        $loaded = $this->file($file);

        if (!$loaded['valid']) {
            return [];
        }

        foreach ($loaded['data']['fields'] as $entry) {
            if ($entry['field_key'] !== $fieldRow['field_key']) {
                continue;
            }

            foreach ($entry['options'] ?? [] as $option) {
                if ((int)$option['value_id'] === $valueId) {
                    return array_intersect_key($option, array_flip(['value', 'display_order', 'display_style']));
                }
            }
        }

        return [];
    }

    /** json_* columns never come from a request. */
    public static function stripJson(array $data): array
    {
        return array_diff_key($data, array_flip(JsonSchema::JSON_COLUMNS));
    }
}
