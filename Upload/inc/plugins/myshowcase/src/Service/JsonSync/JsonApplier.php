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

use MyShowcase\Enum\CacheDataTypes;
use MyShowcase\Service\CacheManager;
use MyShowcase\Service\CollectionService;
use MyShowcase\Service\FieldService;
use MyShowcase\Service\SchemaManager;
use MyShowcase\Service\ShowcaseService;
use MyShowcase\Service\TemplateSelection;

use function MyShowcase\Admin\dbVerifyTables;
use function MyShowcase\Plugin\Functions\dataTableStructureGet;
use function MyShowcase\Plugin\Functions\fieldDataGet;
use function MyShowcase\Plugin\Functions\fieldsGet;
use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\showcaseDataTableExists;
use function MyShowcase\Plugin\Functions\showcaseDelete;
use function MyShowcase\Plugin\Functions\showcaseGet;

/**
 * Applies a plan step by step with the shared write services. Stops at the first step that fails and then does not
 * write the version or hash. Every step looks at the database first, so running a plan again is safe.
 *
 * After each step is written the hook myshowcase_system_import_update_collection or
 * myshowcase_system_import_update_showcase fires (hooksRun adds the myshowcase_system_ prefix) with
 * type, row_id, step, description, old_version and new_version.
 */
final class JsonApplier
{
    private FieldService $fields;

    private CollectionService $collections;

    private ShowcaseService $showcases;

    private array $context = [];

    /** True when this run created the showcase row (and not when it updated one). */
    private bool $createdShowcase = false;

    public function __construct(private readonly CacheManager $cacheManager)
    {
        $this->fields = new FieldService($cacheManager);

        $this->collections = new CollectionService($cacheManager);

        $this->showcases = new ShowcaseService($cacheManager);
    }

    /**
     * @param array $options For a showcase: name, route_identifier, custom_template_prefix (to create it) or
     *        showcase_id (to update it, or to finish a create that stopped half way)
     * @return array{ok: bool, done: string[], failed: ?array, error: ?string, collection_id: ?int, showcase_id: ?int}
     */
    public function apply(array $plan, array $decoded, array $options = []): array
    {
        $this->context = [
            'file' => $plan['file'],
            'decoded' => $decoded,
            'options' => $options,
            'collection_id' => null,
            'showcase_id' => isset($options['showcase_id']) ? (int)$options['showcase_id'] : null,
            'old_version' => '',
            'new_version' => $plan['version'],
        ];

        $this->createdShowcase = false;

        $result = ['ok' => true, 'done' => [], 'failed' => null, 'error' => null, 'collection_id' => null, 'showcase_id' => null];

        if ($plan['errors']) {
            return ['ok' => false, 'error' => implode(' ', $plan['errors'])] + $result;
        }

        $this->resolveContext($plan);

        foreach ($plan['steps'] as $step) {
            $scoped = ($step['scope'] ?? null) === 'collection' && isset($plan['nested']);

            $outer = $this->context;

            try {
                if ($scoped) {
                    $this->context['file'] = $plan['nested']['file'];

                    $this->context['decoded'] = $plan['nested']['decoded'];

                    $this->context['old_version'] = '';

                    $this->context['new_version'] = $plan['nested']['version'];
                }

                $this->runStep($step);

                $this->fireHook($scoped ? 'collection' : $plan['type'], $step);

                $result['done'][] = $step['id'];
            } catch (\Throwable $exception) {
                $result['ok'] = false;

                $result['failed'] = $step;

                $result['error'] = JsonUi::text('error_step_failed', JsonUi::text($step['key'], ...$step['values']), $exception->getMessage());

                $this->context = array_merge($outer, ['collection_id' => $this->context['collection_id'], 'showcase_id' => $this->context['showcase_id']]);

                break;
            }

            if ($scoped) {
                $this->context = array_merge($outer, ['collection_id' => $this->context['collection_id']]);
            }
        }

        // Decision D154: a create that fails after its row exists removes the row again with its entries table, so no
        // showcase is left without its table. The collection and the rows an update changed are not touched.
        if (!$result['ok'] && $this->createdShowcase) {
            $result['error'] .= ' ' . $this->removeCreatedShowcase();

            $this->context['showcase_id'] = null;
        }

        $result['collection_id'] = $this->context['collection_id'];

        $result['showcase_id'] = $this->context['showcase_id'];

        return $result;
    }

    private function removeCreatedShowcase(): string
    {
        global $db;

        $showcaseId = (int)$this->context['showcase_id'];

        // showcaseDelete reads the entries table first. When the engine holds that name with another object (a view on
        // PostgreSQL, which the table check counts as a table), reading it fails: then the row this run wrote is deleted
        // directly. A create writes no other row for the showcase (no permission, no moderator).
        try {
            showcaseDelete($showcaseId);
        } catch (\Throwable) {
            // the row is deleted below
        }

        if (showcaseGet(["showcase_id='{$showcaseId}'"], [], ['limit' => 1])) {
            $db->delete_query('myshowcase_config', "showcase_id='{$showcaseId}'");
        }

        $this->cacheManager->update(CacheDataTypes::Config);

        $this->cacheManager->update(CacheDataTypes::Permissions);

        return showcaseGet(["showcase_id='{$showcaseId}'"], [], ['limit' => 1])
            ? JsonUi::text('showcase_not_removed')
            : JsonUi::text('showcase_removed');
    }

    private function resolveContext(array $plan): void
    {
        if ($plan['type'] === 'collection') {
            $row = JsonState::collectionRow($plan['file']);

            if ($row === null && !empty($this->context['options']['collection_id'])) {
                $rows = \MyShowcase\Plugin\Functions\collectionGet(
                    ["collection_id='" . (int)$this->context['options']['collection_id'] . "'"],
                    JsonState::columnNames('myshowcase_collections')
                );

                $row = $rows ? reset($rows) : null;
            }

            $this->context['collection_id'] = $row ? (int)$row['collection_id'] : null;

            $this->context['old_version'] = (string)($row['json_version'] ?? '');

            return;
        }

        $row = JsonState::collectionRow(JsonFiles::relativePath('collection', (string)$this->context['decoded']['collection']));

        $this->context['collection_id'] = $row ? (int)$row['collection_id'] : null;

        if ($this->context['showcase_id']) {
            global $db;

            $showcase = \MyShowcase\Plugin\Functions\showcaseGet(
                ["showcase_id='{$this->context['showcase_id']}'"],
                ['json_version'],
                [],
                false
            );

            $this->context['old_version'] = (string)($showcase[$this->context['showcase_id']]['json_version'] ?? '');
        }
    }

    private function fireHook(string $type, array $step): void
    {
        $payload = [
            'type' => $type,
            'row_id' => $type === 'collection' ? $this->context['collection_id'] : $this->context['showcase_id'],
            'step' => $step['type'],
            'description' => JsonUi::text($step['key'], ...$step['values']),
            'old_version' => $this->context['old_version'],
            'new_version' => $this->context['new_version'],
        ];

        hooksRun('import_update_' . $type, $payload);
    }

    private function runStep(array $step): void
    {
        $data = $step['data'];

        $file = $this->context['file'];

        match ($step['type']) {
            'collection_create' => $this->collectionCreate($data['name']),
            'collection_update' => $this->collectionUpdate($data['name']),
            'field_rename' => $this->fieldRename($data['from'], $data['to']),
            'field_add', 'field_update' => $this->fieldSave($data['entry']),
            'field_unlink' => $this->fieldUnlink($data['field_key']),
            'option_sync' => $this->optionSync($data['field_key'], $data['options']),
            'php_update' => $this->phpUpdate($data['path'], $data['version']),
            'data_table_rebuild' => $this->rebuildDataTable($data['showcase_id']),
            'write_version' => $this->writeCollectionVersion($data['version'], $data['hash']),
            'showcase_create', 'showcase_update' => $this->showcaseSave($data['columns']),
            'data_table_create' => $this->rebuildDataTable((int)$this->context['showcase_id']),
            'showcase_write_version' => $this->writeShowcaseVersion($data['version'], $data['hash']),
            'cache_refresh' => $this->collections->refreshCaches(),
            default => throw new \RuntimeException("Unknown step type {$step['type']}"),
        };
    }

    private function collectionId(): int
    {
        if (!$this->context['collection_id']) {
            throw new \RuntimeException('The collection row does not exist yet.');
        }

        return (int)$this->context['collection_id'];
    }

    private function checked(array $errors): void
    {
        if ($errors) {
            throw new \RuntimeException(implode(' ', array_column($errors, 'message')));
        }
    }

    private function collectionCreate(string $name): void
    {
        if ($this->context['collection_id']) {
            return;
        }

        $this->checked($this->collections->validate($name));

        $this->context['collection_id'] = $this->collections->save(['name' => $name, 'json_file' => $this->context['file']], 0, true);
    }

    private function collectionUpdate(string $name): void
    {
        $this->checked($this->collections->validate($name, $this->collectionId()));

        $this->collections->save(['name' => $name], $this->collectionId(), true);
    }

    private function fieldRow(string $fieldKey): ?array
    {
        global $db;

        $rows = fieldsGet(
            ["collection_id='{$this->collectionId()}'", "field_key='{$db->escape_string($fieldKey)}'"],
            ['field_id', 'field_key', 'json_file']
        );

        return $rows ? reset($rows) : null;
    }

    private function fieldRename(string $from, string $to): void
    {
        $row = $this->fieldRow($from);

        if ($row === null) {
            return; // already renamed by an earlier run
        }

        $this->checked($this->fields->validate(['field_key' => $to], $this->collectionId(), (int)$row['field_id'], $from));

        $this->fields->save(['field_key' => $to], $this->collectionId(), (int)$row['field_id'], [], true);
    }

    private function fieldSave(array $entry): void
    {
        $key = $entry['field_key'];

        $row = $this->fieldRow($key);

        $columns = JsonPlanner::fieldColumns($entry) + ['json_file' => $this->context['file']];

        $label = $entry['label'];

        $labels = is_array($label) ? $label : ['english' => $label];

        if ($row === null) {
            $columns['field_key'] = $key;

            $this->checked($this->fields->validate($columns, $this->collectionId()));

            $this->fields->save($columns, $this->collectionId(), 0, $labels, true);

            return;
        }

        // Decision D148: group restrictions are board-local. An item the board already has keeps its own groups, whatever the
        // file says; only a new item takes the groups of the file (or the unrestricted default).
        foreach (JsonSchema::GROUP_COLUMNS as $groupColumn) {
            unset($columns[$groupColumn]);
        }

        $this->checked($this->fields->validate($columns + ['field_key' => $key], $this->collectionId(), (int)$row['field_id'], $key));

        $this->fields->save($columns, $this->collectionId(), (int)$row['field_id'], $labels, true);
    }

    private function fieldUnlink(string $fieldKey): void
    {
        $row = $this->fieldRow($fieldKey);

        if ($row !== null) {
            $this->fields->save(
                ['json_file' => '', 'json_version' => '', 'json_synced_at' => 0, 'json_hash' => ''],
                $this->collectionId(),
                (int)$row['field_id'],
                [],
                true
            );
        }
    }

    private function optionSync(string $fieldKey, array $options): void
    {
        $row = $this->fieldRow($fieldKey);

        if ($row === null) {
            throw new \RuntimeException("The field \"{$fieldKey}\" does not exist.");
        }

        $fieldId = (int)$row['field_id'];

        foreach ($options as $option) {
            $existing = fieldDataGet(
                ["field_id='{$fieldId}'", "value_id='" . (int)$option['value_id'] . "'"],
                ['field_data_id']
            );

            $data = array_intersect_key($option, array_flip(array_keys(JsonSchema::optionColumns())));

            $data['value_id'] = (string)$option['value_id'];

            $this->fields->saveOption(
                $data + ['collection_id' => $this->collectionId(), 'field_id' => $fieldId, 'field_key' => $fieldKey],
                $existing ? (int)array_key_first($existing) : 0,
                true
            );
        }

        $this->fields->refreshOptionCaches();
    }

    private function phpUpdate(string $path, string $version): void
    {
        $step = require $path;

        if (!is_callable($step)) {
            throw new \RuntimeException("The PHP update step {$version} does not return a callable.");
        }

        $step(
            $this->collectionId(),
            $this->context['old_version'],
            $version,
            $this->context['decoded']
        );
    }

    private function rebuildDataTable(int $showcaseId): void
    {
        if (!$showcaseId) {
            throw new \RuntimeException('The showcase row does not exist yet.');
        }

        $errors = count(SchemaManager::columnErrors());

        dbVerifyTables(["myshowcase_data{$showcaseId}" => dataTableStructureGet($showcaseId)]);

        $new = array_slice(SchemaManager::columnErrors(), $errors);

        if ($new) {
            throw new \RuntimeException(implode(' ', $new));
        }

        if (!showcaseDataTableExists($showcaseId, true)) {
            // The engine does nothing for a name that another object holds (CREATE TABLE IF NOT EXISTS): the holder is named.
            $holder = SchemaManager::nameHolder("myshowcase_data{$showcaseId}");

            throw new \RuntimeException($holder !== null
                ? JsonUi::text('error_data_table_holder', (string)$holder)
                : JsonUi::text('error_data_table_not_created'));
        }
    }

    private function writeCollectionVersion(string $version, string $hash): void
    {
        $fileColumns = ['json_version' => $version, 'json_synced_at' => time()];

        $this->collections->save($fileColumns + ['json_hash' => $hash, 'json_file' => $this->context['file']], $this->collectionId(), true);

        foreach ($this->context['decoded']['fields'] as $entry) {
            $row = $this->fieldRow($entry['field_key']);

            if ($row !== null) {
                $this->fields->save(
                    $fileColumns + ['json_hash' => JsonHash::hash($entry), 'json_file' => $this->context['file']],
                    $this->collectionId(),
                    (int)$row['field_id'],
                    [],
                    true
                );
            }
        }
    }

    private function showcaseSave(array $columns): void
    {
        $columns['collection_id'] = $this->collectionId();

        $columns['json_file'] = $this->context['file'];

        $options = $this->context['options'];

        if ($this->context['showcase_id']) {
            $this->showcases->save($columns, $this->context['showcase_id'], true);

            return;
        }

        if (array_key_exists('custom_template_prefix', $options)) {
            $templatePrefix = ShowcaseService::normalizeTemplatePrefix((string)$options['custom_template_prefix']);
        } else {
            $templatePrefix = TemplateSelection::presetPackageForShowcaseJsonFile($this->context['file']);
        }

        $identity = [
            'name' => (string)($options['name'] ?? ''),
            'route_identifier' => (string)($options['route_identifier'] ?? ''),
            'custom_template_prefix' => $templatePrefix,
        ];

        if ($identity['name'] === '' || $identity['route_identifier'] === '') {
            throw new \RuntimeException('A name and a route identifier are needed to create a showcase.');
        }

        $this->checked($this->showcases->validate($identity));

        $this->context['showcase_id'] = $this->showcases->save($columns + $identity, 0, true);

        $this->createdShowcase = true;
    }

    private function writeShowcaseVersion(string $version, string $hash): void
    {
        $this->showcases->save(
            ['json_version' => $version, 'json_hash' => $hash, 'json_synced_at' => time(), 'json_file' => $this->context['file']],
            (int)$this->context['showcase_id'],
            true
        );
    }
}
