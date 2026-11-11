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

use function MyShowcase\Plugin\Functions\fieldHtmlTypes;
use function MyShowcase\Plugin\Functions\fieldTypesGet;

use MyShowcase\Service\FieldService;

/** Checks a decoded file and returns every problem, each with its JSON path. */
final class JsonValidator
{
    private const INTEGER_MAXIMUM = [
        'tinyint' => 255, 'smallint' => 65535, 'mediumint' => 16777215, 'int' => 4294967295,
    ];

    /**
     * @return array<int, array{path: string, message: string}>
     */
    public static function validate(array $data, ?string $expectedType = null): array
    {
        $errors = [];

        $type = $data['type'] ?? null;

        if (!is_string($type) || !in_array($type, ['showcase', 'collection'], true)) {
            $errors[] = self::error('$.type', 'must be "showcase" or "collection"');
        } elseif ($expectedType !== null && $type !== $expectedType) {
            $errors[] = self::error('$.type', "must be \"{$expectedType}\" for this file");
        }

        if (!isset($data['version']) || !is_string($data['version']) ||
            !preg_match('/^\d+\.\d+\.\d+$/', $data['version'])) {
            $errors[] = self::error('$.version', 'must be a semantic version like 1.0.0');
        }

        if ($type === 'collection') {
            return array_merge($errors, self::collection($data));
        }

        if ($type === 'showcase') {
            return array_merge($errors, self::showcase($data));
        }

        return $errors;
    }

    private static function error(string $path, string $message): array
    {
        return ['path' => $path, 'message' => $message];
    }

    private static function collection(array $data): array
    {
        $errors = [];

        foreach (array_diff(array_keys($data), ['type', 'version', 'name', 'fields']) as $property) {
            $errors[] = self::error('$.' . $property, 'is not a property a collection file can have');
        }

        if (!isset($data['name']) || !is_string($data['name']) || $data['name'] === '' || strlen($data['name']) > 50) {
            $errors[] = self::error('$.name', 'is required and must be a string of 1 to 50 characters');
        }

        if (!isset($data['fields']) || !is_array($data['fields']) || !array_is_list($data['fields'])) {
            $errors[] = self::error('$.fields', 'must be a list of fields');

            return $errors;
        }

        $seen = [];

        foreach ($data['fields'] as $index => $entry) {
            $path = '$.fields[' . $index . ']';

            if (!is_array($entry) || array_is_list($entry)) {
                $errors[] = self::error($path, 'must be an object');

                continue;
            }

            foreach (self::field($entry, $path) as $error) {
                $errors[] = $error;
            }

            $key = $entry['field_key'] ?? null;

            if (is_string($key)) {
                if (isset($seen[$key])) {
                    $errors[] = self::error($path . '.field_key', "\"{$key}\" is used by field {$seen[$key]} too");
                }

                $seen[$key] = $index;
            }
        }

        // A previous key must not be another field's current key.
        foreach ($data['fields'] as $index => $entry) {
            foreach ((array)($entry['previous_keys'] ?? []) as $position => $previous) {
                if (is_string($previous) && isset($seen[$previous])) {
                    $errors[] = self::error(
                        '$.fields[' . $index . '].previous_keys[' . $position . ']',
                        "\"{$previous}\" is the key of another field in this file"
                    );
                }
            }
        }

        return $errors;
    }

    private static function field(array $entry, string $path): array
    {
        $errors = [];

        $columns = JsonSchema::fieldColumns();

        $allowed = array_merge(array_keys($columns), ['label', 'options', 'previous_keys']);

        foreach (array_diff(array_keys($entry), $allowed) as $property) {
            $errors[] = self::error(
                "{$path}.{$property}",
                in_array($property, ['field_id', 'collection_id'], true) || str_starts_with($property, 'json_')
                    ? 'is managed by the sync and can not be set'
                    : 'is not a property a field can have'
            );
        }

        $key = $entry['field_key'] ?? null;

        if (!is_string($key) || $key === '') {
            $errors[] = self::error("{$path}.field_key", 'is required');
        } elseif ($key !== FieldService::normalizeKey($key)) {
            $errors[] = self::error("{$path}.field_key", 'may only hold lower case letters, digits and "_"');
        } elseif (strlen($key) > FieldService::KEY_MAXIMUM_LENGTH) {
            $errors[] = self::error("{$path}.field_key", 'is longer than ' . FieldService::KEY_MAXIMUM_LENGTH . ' characters');
        } elseif (in_array($key, FieldService::reservedKeys(), true)) {
            $errors[] = self::error("{$path}.field_key", "\"{$key}\" is a column the entries table already has");
        }

        foreach (['html_type', 'field_type'] as $required) {
            if (!isset($entry[$required]) || !is_string($entry[$required])) {
                $errors[] = self::error("{$path}.{$required}", 'is required');
            }
        }

        if (isset($entry['html_type']) && is_string($entry['html_type']) && !isset(fieldHtmlTypes()[$entry['html_type']])) {
            $errors[] = self::error("{$path}.html_type", 'is not a supported HTML type');
        }

        if (isset($entry['field_type']) && is_string($entry['field_type']) &&
            !isset(fieldTypesGet()[strtolower($entry['field_type'])])) {
            $errors[] = self::error("{$path}.field_type", 'is not a supported database type');
        }

        foreach ($columns as $name => $definition) {
            if (!array_key_exists($name, $entry)) {
                continue;
            }

            if (in_array($name, JsonSchema::GROUP_COLUMNS, true)) {
                if ($entry[$name] !== 'all') {
                    $errors[] = self::error("{$path}.{$name}", 'can only be "all"');
                }

                continue;
            }

            foreach (self::value($entry[$name], $definition, "{$path}.{$name}") as $error) {
                $errors[] = $error;
            }
        }

        if (isset($entry['label'])) {
            $label = $entry['label'];

            if (is_string($label)) {
                if (strlen($label) > 30) {
                    $errors[] = self::error("{$path}.label", 'is longer than 30 characters');
                }
            } elseif (is_array($label) && !array_is_list($label) && $label !== []) {
                foreach ($label as $language => $text) {
                    if (!preg_match('/^[a-z0-9_\-]+$/i', (string)$language) || !is_string($text) || strlen($text) > 30) {
                        $errors[] = self::error("{$path}.label.{$language}", 'must be a text of up to 30 characters under a language directory name');
                    }
                }
            } else {
                $errors[] = self::error("{$path}.label", 'must be a text or a map of language directory to text');
            }
        } else {
            $errors[] = self::error("{$path}.label", 'is required');
        }

        if (isset($entry['previous_keys'])) {
            if (!is_array($entry['previous_keys']) || !array_is_list($entry['previous_keys'])) {
                $errors[] = self::error("{$path}.previous_keys", 'must be a list of field keys');
            } else {
                foreach ($entry['previous_keys'] as $position => $previous) {
                    if (!is_string($previous) || $previous === '' || $previous !== FieldService::normalizeKey($previous)) {
                        $errors[] = self::error("{$path}.previous_keys[{$position}]", 'must be a field key');
                    }
                }
            }
        }

        if (isset($entry['options'])) {
            $errors = array_merge($errors, self::options($entry, $path));
        }

        return $errors;
    }

    private static function options(array $entry, string $path): array
    {
        $errors = [];

        if (!in_array($entry['html_type'] ?? '', JsonSchema::OPTION_TYPES, true)) {
            return [self::error("{$path}.options", 'is only for select, radio and checkbox fields')];
        }

        if (!is_array($entry['options']) || !array_is_list($entry['options'])) {
            return [self::error("{$path}.options", 'must be a list')];
        }

        $columns = JsonSchema::optionColumns();

        $seen = [];

        foreach ($entry['options'] as $index => $option) {
            $optionPath = "{$path}.options[{$index}]";

            if (!is_array($option) || array_is_list($option)) {
                $errors[] = self::error($optionPath, 'must be an object');

                continue;
            }

            foreach (array_diff(array_keys($option), array_keys($columns)) as $property) {
                $errors[] = self::error("{$optionPath}.{$property}", 'is not a property an option can have');
            }

            foreach (['value_id', 'value'] as $required) {
                if (!array_key_exists($required, $option)) {
                    $errors[] = self::error("{$optionPath}.{$required}", 'is required');
                }
            }

            if (isset($option['value_id'])) {
                if (!is_int($option['value_id']) || $option['value_id'] < 0) {
                    $errors[] = self::error("{$optionPath}.value_id", 'must be a whole number of 0 or more');
                } elseif (isset($seen[$option['value_id']])) {
                    $errors[] = self::error("{$optionPath}.value_id", 'is used by another option of this field');
                } else {
                    $seen[$option['value_id']] = true;
                }
            }

            foreach ($columns as $name => $definition) {
                if ($name !== 'value_id' && array_key_exists($name, $option)) {
                    $errors = array_merge($errors, self::value($option[$name], $definition, "{$optionPath}.{$name}"));
                }
            }
        }

        return $errors;
    }

    private static function showcase(array $data): array
    {
        $errors = [];

        $columns = JsonSchema::showcaseColumns();

        foreach (array_diff(array_keys($data), array_merge(['type', 'version', 'collection'], array_keys($columns))) as $property) {
            $errors[] = self::error(
                '$.' . $property,
                in_array($property, JsonSchema::SHOWCASE_EXCLUDED, true) || str_starts_with($property, 'json_')
                    ? 'can not be set in a showcase file'
                    : 'is not a property a showcase file can have'
            );
        }

        if (!isset($data['collection']) || !is_string($data['collection']) ||
            !preg_match('/^[a-z0-9_\-]+$/', $data['collection'])) {
            $errors[] = self::error('$.collection', 'is required and must be the name of a collection file without ".json"');
        }

        foreach ($columns as $name => $definition) {
            if (array_key_exists($name, $data)) {
                $errors = array_merge($errors, self::value($data[$name], $definition, '$.' . $name));
            }
        }

        return $errors;
    }

    private static function value(mixed $value, array $definition, string $path): array
    {
        $type = strtolower((string)$definition['type']);

        if (isset(self::INTEGER_MAXIMUM[$type])) {
            if (!is_int($value) || $value < 0 || $value > self::INTEGER_MAXIMUM[$type]) {
                return [self::error($path, 'must be a whole number from 0 to ' . self::INTEGER_MAXIMUM[$type])];
            }

            return [];
        }

        if (in_array($type, ['varchar', 'char'], true)) {
            $maximum = (int)($definition['max'] ?? $definition['size'] ?? 255);

            if (!is_string($value) || strlen($value) > $maximum) {
                return [self::error($path, "must be a text of up to {$maximum} characters")];
            }

            return [];
        }

        return [self::error($path, 'has a type the sync does not support')];
    }
}
