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

use MyShowcase\Routing\UrlMode;
use function MyShowcase\Plugin\Functions\fieldHtmlTypes;
use function MyShowcase\Plugin\Functions\fieldTypesGet;

use MyShowcase\Enum\FieldHtmlTypes;
use MyShowcase\Service\FieldService;
use MyShowcase\Util\FieldBoundUtil;

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

        // decision D152: the same key rule as the ACP field form (FieldService::fieldKeyProblem)
        $keyProblem = is_string($key) ? FieldService::fieldKeyProblem($key) : 'missing';

        if ($keyProblem === 'missing') {
            $errors[] = self::error("{$path}.field_key", 'is required');
        } elseif ($keyProblem === 'format') {
            $errors[] = self::error("{$path}.field_key", 'must start with a lower case letter and hold only lower case letters, digits and single "_" between them');
        } elseif ($keyProblem === 'length') {
            $errors[] = self::error("{$path}.field_key", 'is longer than ' . FieldService::KEY_MAXIMUM_LENGTH . ' characters');
        } elseif ($keyProblem === 'reserved') {
            $errors[] = self::error("{$path}.field_key", "\"{$key}\" is a reserved name: a column of the entries table or a word of SQL");
        }

        foreach (['html_type', 'field_type'] as $required) {
            if (!isset($entry[$required]) || !is_string($entry[$required])) {
                $errors[] = self::error("{$path}.{$required}", 'is required');
            }
        }

        if (isset($entry['html_type']) && is_string($entry['html_type']) && in_array($entry['html_type'], \MyShowcase\Plugin\Functions\reservedHtmlTypes(), true)) {
            $errors[] = self::error("{$path}.html_type", 'is reserved for a later feature and cannot be used yet');
        } elseif (isset($entry['html_type']) && is_string($entry['html_type']) && !isset(fieldHtmlTypes()[$entry['html_type']])) {
            $errors[] = self::error("{$path}.html_type", 'is not a supported HTML type');
        }

        if (isset($entry['field_type']) && is_string($entry['field_type']) &&
            !isset(fieldTypesGet()[strtolower($entry['field_type'])])) {
            $errors[] = self::error("{$path}.field_type", 'is not a supported database type');
        }

        // decisions D184 and D187: a field with several values (checkbox, or select with allow_multiple_values) is text
        if (isset($entry['html_type'], $entry['field_type']) && is_string($entry['html_type']) && is_string($entry['field_type']) &&
            !\MyShowcase\Util\MultipleChoiceUtil::columnTypeFits($entry['html_type'], $entry['allow_multiple_values'] ?? 0, $entry['field_type'])) {
            $errors[] = self::error("{$path}.field_type", 'must be text for a field with several values');
        }

        foreach ($columns as $name => $definition) {
            if (!array_key_exists($name, $entry)) {
                continue;
            }

            if (in_array($name, JsonSchema::GROUP_COLUMNS, true)) {
                if ($entry[$name] !== 'all') {
                    // decision D148: a file names no group; the restrictions of a board are board-local
                    $errors[] = self::error("{$path}.{$name}", 'can only be "all": group restrictions are board-local and are not written to a collection file');
                }

                continue;
            }

            foreach (self::value($entry[$name], $definition, "{$path}.{$name}") as $error) {
                $errors[] = $error;
            }
        }

        // The bounds are for the types that have them (decision D124), with the same checks as the ACP form.
        // An empty bound is no bound, so it is allowed for any type.
        $boundNames = array_values(array_filter(
            ['minimum_value', 'maximum_value'],
            static fn(string $name): bool => isset($entry[$name]) && $entry[$name] !== ''
        ));
        $htmlType = is_string($entry['html_type'] ?? null) ? $entry['html_type'] : '';

        if ($boundNames !== [] && !FieldBoundUtil::isBoundedType($htmlType)) {
            foreach ($boundNames as $name) {
                $errors[] = self::error("{$path}.{$name}", 'is only for the ' . FieldBoundUtil::typeNames() . ' types');
            }
        } elseif ($boundNames !== []) {
            $minimum = (string)($entry['minimum_value'] ?? '');
            $maximum = (string)($entry['maximum_value'] ?? '');

            foreach (['minimum_value' => $minimum, 'maximum_value' => $maximum] as $name => $value) {
                if ($value !== '' && !FieldBoundUtil::formatIsValid($htmlType, $value)) {
                    $errors[] = self::error("{$path}.{$name}", 'does not have the format of the field type');
                }
            }

            if ($htmlType === FieldHtmlTypes::Range && ($minimum === '' || $maximum === '')) {
                $errors[] = self::error($path, 'a range field needs both minimum_value and maximum_value');
            } elseif ($minimum !== '' && $maximum !== '' && FieldBoundUtil::formatIsValid($htmlType, $minimum) &&
                FieldBoundUtil::formatIsValid($htmlType, $maximum) && FieldBoundUtil::isInverted($htmlType, $minimum, $maximum)) {
                $errors[] = self::error("{$path}.minimum_value", 'is above maximum_value');
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
                    if (!is_string($previous) || FieldService::fieldKeyProblem($previous) === 'missing' || FieldService::fieldKeyProblem($previous) === 'format') {
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
                if (is_string($option['value_id']) && str_contains($option['value_id'], ',')) {
                    // decision D196: the comma separates the stored values of a field, so a value id can not hold one
                    $errors[] = self::error("{$optionPath}.value_id", 'must not contain a comma');
                } elseif (!is_int($option['value_id']) || $option['value_id'] < 0) {
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

        // the links option is one of its three choices (decision D224); a column type alone allows any small number
        if (array_key_exists('url_mode', $data) && !(is_int($data['url_mode']) && UrlMode::isValid($data['url_mode']))) {
            $errors[] = self::error('$.url_mode', 'must be 0 (No), 1 (Automatic) or 2 (Yes)');
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
