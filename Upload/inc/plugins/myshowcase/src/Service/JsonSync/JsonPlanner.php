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
 * Builds the ordered plan of what applying a file would do. Pure: the current state of the database is passed in
 * (see JsonState), nothing is read or written here. A plan never deletes a row, a column or an entry.
 *
 * Step: id, type, description, warnings (list of text) and data (what the applier needs).
 */
final class JsonPlanner
{
    /** Field columns whose change alters the column of the entries table. */
    private const STRUCTURE_COLUMNS = [
        'field_type', 'maximum_length', 'default_value', 'default_type', 'is_required',
    ];

    /**
     * @param array $state row (collection row or null), fields (field_key => row), options (field_id =>
     *        value_id => row), showcases (showcase_id => [name, data_table]), php_steps (version => path),
     *        language_writable (bool), losses (callable(int $showcaseId, string $fieldKey, array $columns): ?string)
     * @return array{type: string, file: string, version: string, hash: string, steps: array, warnings: array, errors: array}
     */
    public static function collection(array $decoded, string $file, array $state): array
    {
        $plan = self::emptyPlan('collection', $file, $decoded);

        $row = $state['row'] ?? null;

        $existing = $state['fields'] ?? [];

        $fileKeys = array_column($decoded['fields'], 'field_key');

        $steps = [];

        // 1. collection properties
        if ($row === null) {
            $steps[] = self::step('collection_create', "Create the collection \"{$decoded['name']}\"", ['name' => $decoded['name']]);
        } elseif ($row['name'] !== $decoded['name']) {
            $steps[] = self::step(
                'collection_update',
                "Rename the collection from \"{$row['name']}\" to \"{$decoded['name']}\"",
                ['name' => $decoded['name']]
            );
        }

        // 2. renames, 3. additions, 4. updates
        $renames = [];

        $adds = [];

        $updates = [];

        foreach ($decoded['fields'] as $entry) {
            $key = $entry['field_key'];

            $from = null;

            if (!isset($existing[$key])) {
                foreach ($entry['previous_keys'] ?? [] as $previous) {
                    if (isset($existing[$previous]) && !in_array($previous, $fileKeys, true) && !isset($renames[$previous])) {
                        $from = $previous;

                        break;
                    }
                }
            }

            $current = $from !== null ? $existing[$from] : ($existing[$key] ?? null);

            if ($current !== null && $current['json_file'] !== '' && $current['json_file'] !== $file) {
                $plan['errors'][] = "The field \"{$key}\" belongs to {$current['json_file']}.";

                continue;
            }

            $columns = self::fieldColumns($entry);

            if ($from !== null) {
                $renames[$from] = $key;

                $steps[] = self::step(
                    'field_rename',
                    "Rename the field \"{$from}\" to \"{$key}\" (its column is renamed in every showcase of the collection)",
                    ['from' => $from, 'to' => $key]
                );
            }

            if ($current === null) {
                $adds[$key] = $entry;
            } else {
                $changed = array_keys(array_filter(
                    $columns,
                    static fn($value, $name) => (string)($current[$name] ?? '') !== (string)$value,
                    ARRAY_FILTER_USE_BOTH
                ));

                if ($from !== null) {
                    $changed = array_values(array_diff($changed, ['field_key']));
                }

                if ($changed || $current['json_file'] !== $file) {
                    $warnings = [];

                    if ($current['json_file'] === '') {
                        $warnings[] = "The field \"{$key}\" is your own field now; it will be managed by the file.";
                    }

                    $updates[$key] = ['entry' => $entry, 'changed' => $changed, 'warnings' => $warnings];
                }
            }
        }

        foreach ($adds as $key => $entry) {
            $steps[] = self::step('field_add', "Add the field \"{$key}\"", ['entry' => $entry]);
        }

        foreach ($updates as $key => $update) {
            $steps[] = self::step(
                'field_update',
                "Update the field \"{$key}\"" . ($update['changed'] ? ' (' . implode(', ', $update['changed']) . ')' : ''),
                ['entry' => $update['entry'], 'changed' => $update['changed']],
                $update['warnings']
            );
        }

        // 5. fields no longer in the file stay as the user's own
        foreach ($existing as $key => $current) {
            if ($current['json_file'] === $file && !in_array($key, $fileKeys, true) && !isset($renames[$key])) {
                $steps[] = self::step(
                    'field_unlink',
                    "The field \"{$key}\" is not in the file any more: its row and column are kept and it becomes your own field",
                    ['field_key' => $key],
                    ["The field \"{$key}\" becomes custom."]
                );
            }
        }

        // 6. options
        foreach ($decoded['fields'] as $entry) {
            if (empty($entry['options'])) {
                continue;
            }

            $key = $entry['field_key'];

            $current = $existing[$key] ?? ($existing[array_search($key, $renames, true) ?: ''] ?? null);

            $known = $current ? ($state['options'][(int)$current['field_id']] ?? []) : [];

            $add = [];

            $update = [];

            foreach ($entry['options'] as $option) {
                $optionRow = $known[$option['value_id']] ?? null;

                if ($optionRow === null) {
                    $add[] = $option;
                } elseif (self::optionDiffers($optionRow, $option)) {
                    $update[] = $option;
                }
            }

            $kept = array_diff(array_keys($known), array_column($entry['options'], 'value_id'));

            if ($add || $update) {
                $steps[] = self::step(
                    'option_sync',
                    "Options of \"{$key}\": " . count($add) . ' added, ' . count($update) . ' updated',
                    ['field_key' => $key, 'options' => $entry['options']],
                    $kept ? ["Options {$key}: " . implode(', ', $kept) . ' are not in the file and are kept.'] : []
                );
            } elseif ($kept) {
                $plan['warnings'][] = "Options of \"{$key}\": " . implode(', ', $kept) . ' are not in the file and are kept.';
            }
        }

        // 7. PHP update steps
        $rowVersion = (string)($row['json_version'] ?? '');

        if ($row !== null && $rowVersion !== '') {
            foreach ($state['php_steps'] ?? [] as $version => $path) {
                if (version_compare($version, $rowVersion, '>') && version_compare($version, $decoded['version'], '<=')) {
                    $steps[] = self::step('php_update', "Run the PHP update step for {$version}", ['version' => $version, 'path' => $path]);
                }
            }
        }

        // 8. data-table rebuild
        // Also when only the version differs: a run that stopped before this step must still build the columns of the
        // fields it had already written. Rebuilding a table that is up to date changes nothing.
        $structural = $steps !== [] || $rowVersion !== $decoded['version'] ||
            (string)($row['json_hash'] ?? '') !== $plan['hash'];

        if ($structural) {
            $using = array_filter($state['showcases'] ?? [], static fn($showcase) => !empty($showcase['data_table']));

            foreach ($using as $showcaseId => $showcase) {
                $warnings = [];

                foreach ($updates as $key => $update) {
                    if (array_intersect($update['changed'], self::STRUCTURE_COLUMNS) && isset($state['losses'])) {
                        $loss = ($state['losses'])((int)$showcaseId, $key, self::fieldColumns($update['entry']));

                        if ($loss !== null) {
                            $warnings[] = "{$showcase['name']}: the column {$key} does not fit the new type ({$loss}); the rebuild will stop.";
                        }
                    }
                }

                $steps[] = self::step(
                    'data_table_rebuild',
                    "Rebuild the entries table of the showcase \"{$showcase['name']}\"",
                    ['showcase_id' => (int)$showcaseId],
                    $warnings
                );
            }
        }

        if (($state['language_writable'] ?? true) === false) {
            $plan['warnings'][] = 'The language directory is not writable: field labels will not be written.';
        }

        // 9. version, 10. caches
        if ($steps || $rowVersion !== $decoded['version'] || (string)($row['json_hash'] ?? '') !== $plan['hash']) {
            $steps[] = self::step(
                'write_version',
                "Record version {$decoded['version']} of the file",
                ['version' => $decoded['version'], 'hash' => $plan['hash']]
            );

            $steps[] = self::step('cache_refresh', 'Refresh the caches', []);
        }

        $plan['steps'] = $steps;

        return $plan;
    }

    /**
     * @param array $state showcase (row or null), collection (collection state, see collection()), collection_file
     *        (decoded collection file or null), collection_file_path, data_table (bool)
     */
    public static function showcase(array $decoded, string $file, array $state): array
    {
        $plan = self::emptyPlan('showcase', $file, $decoded);

        $row = $state['showcase'] ?? null;

        $steps = [];

        $collectionState = $state['collection'] ?? ['row' => null];

        if (($state['collection_file'] ?? null) === null) {
            $plan['errors'][] = "The collection file \"{$decoded['collection']}.json\" does not exist or is not valid.";

            return $plan;
        }

        if (($collectionState['row'] ?? null) === null) {
            $collectionPlan = self::collection($state['collection_file'], $state['collection_file_path'], $collectionState);

            $plan['errors'] = array_merge($plan['errors'], $collectionPlan['errors']);

            $plan['warnings'] = array_merge($plan['warnings'], $collectionPlan['warnings']);

            // The collection steps keep the collection file as their own scope when they are applied.
            $steps = array_map(static fn(array $step) => $step + ['scope' => 'collection'], $collectionPlan['steps']);

            $plan['nested'] = [
                'file' => $state['collection_file_path'],
                'decoded' => $state['collection_file'],
                'version' => $state['collection_file']['version'],
            ];
        }

        $columns = array_intersect_key($decoded, JsonSchema::showcaseColumns());

        if ($row === null) {
            $steps[] = self::step('showcase_create', 'Create the showcase', ['columns' => $columns]);

            $steps[] = self::step('data_table_create', 'Create the entries table', []);
        } else {
            $changed = array_keys(array_filter(
                $columns,
                static fn($value, $name) => (string)($row[$name] ?? '') !== (string)$value,
                ARRAY_FILTER_USE_BOTH
            ));

            if ($changed || $row['json_file'] !== $file) {
                $steps[] = self::step(
                    'showcase_update',
                    'Update the showcase (' . implode(', ', $changed) . ')',
                    ['columns' => $columns]
                );
            }

            if (empty($state['data_table'])) {
                $steps[] = self::step('data_table_create', 'Create the entries table', []);
            }
        }

        $rowVersion = (string)($row['json_version'] ?? '');

        if ($steps || $rowVersion !== $decoded['version'] || (string)($row['json_hash'] ?? '') !== $plan['hash']) {
            $steps[] = self::step(
                'showcase_write_version',
                "Record version {$decoded['version']} of the file",
                ['version' => $decoded['version'], 'hash' => $plan['hash']]
            );

            $steps[] = self::step('cache_refresh', 'Refresh the caches', []);
        }

        $plan['steps'] = $steps;

        return $plan;
    }

    /** The myshowcase_fields columns an entry sets: its whitelisted columns plus field_label, groups as -1. */
    public static function fieldColumns(array $entry): array
    {
        $columns = [];

        foreach (array_keys(JsonSchema::fieldColumns()) as $name) {
            if (array_key_exists($name, $entry)) {
                $columns[$name] = in_array($name, JsonSchema::GROUP_COLUMNS, true) ? '-1' : $entry[$name];
            }
        }

        $label = $entry['label'] ?? '';

        $columns['field_label'] = is_array($label) ? (string)($label['english'] ?? reset($label)) : (string)$label;

        return $columns;
    }

    private static function optionDiffers(array $row, array $option): bool
    {
        foreach (['value', 'display_order', 'display_style'] as $name) {
            if (array_key_exists($name, $option) && (string)($row[$name] ?? '') !== (string)$option[$name]) {
                return true;
            }
        }

        return false;
    }

    private static function emptyPlan(string $type, string $file, array $decoded): array
    {
        return [
            'type' => $type,
            'file' => $file,
            'version' => $decoded['version'],
            'hash' => JsonHash::hash($decoded),
            'steps' => [],
            'warnings' => [],
            'errors' => [],
        ];
    }

    private static function step(string $type, string $description, array $data, array $warnings = []): array
    {
        return [
            'id' => $type . ':' . md5(serialize($data)),
            'type' => $type,
            'description' => $description,
            'warnings' => $warnings,
            'data' => $data,
        ];
    }
}
