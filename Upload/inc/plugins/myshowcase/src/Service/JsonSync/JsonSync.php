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

use MyShowcase\Service\CacheManager;

use function MyShowcase\Plugin\Functions\collectionGet;
use function MyShowcase\Plugin\Functions\fieldDataGet;
use function MyShowcase\Plugin\Functions\fieldsGet;
use function MyShowcase\Plugin\Functions\showcaseGet;

/** Entry point of the JSON sync: lists, validates, plans, applies and exports. Phase 3 puts the ACP on top of it. */
final class JsonSync
{
    private JsonFiles $files;

    public function __construct(private readonly CacheManager $cacheManager, ?JsonFiles $files = null)
    {
        $this->files = $files ?? JsonFiles::default();
    }

    public function files(): JsonFiles
    {
        return $this->files;
    }

    /**
     * @return array{exists: bool, valid: bool, errors: array, data: ?array, version?: string, hash?: string}
     */
    public function load(string $relativePath): array
    {
        $type = JsonFiles::typeOf($relativePath);

        $read = $this->files->read($relativePath);

        if ($type === null) {
            return ['exists' => false, 'valid' => false, 'errors' => [['path' => '$', 'message' => 'not a valid file name']], 'data' => null];
        }

        if (!$read['exists']) {
            return ['exists' => false, 'valid' => false, 'errors' => [], 'data' => null];
        }

        if ($read['data'] === null) {
            return ['exists' => true, 'valid' => false, 'errors' => [['path' => '$', 'message' => (string)$read['error']]], 'data' => null];
        }

        $errors = JsonValidator::validate($read['data'], $type);

        $result = ['exists' => true, 'valid' => $errors === [], 'errors' => $errors, 'data' => $read['data']];

        if ($errors === []) {
            $result['version'] = $read['data']['version'];

            $result['hash'] = JsonHash::hash($read['data']);
        }

        return $result;
    }

    /** Status of a collection row or a showcase row (see JsonStatus) against the file it is linked to. */
    public function statusOf(array $row): string
    {
        $file = $this->load((string)$row['json_file']);

        return JsonStatus::of($row, $file);
    }

    /**
     * @return array{type: string, file: string, version: string, hash: string, steps: array, warnings: array, errors: array}
     */
    public function plan(string $relativePath, array $options = []): array
    {
        $file = $this->load($relativePath);

        $empty = ['type' => (string)JsonFiles::typeOf($relativePath), 'file' => $relativePath, 'version' => '', 'hash' => '', 'steps' => [], 'warnings' => []];

        if (!$file['valid']) {
            $messages = $file['exists']
                ? array_map(static fn($error) => "{$error['path']} {$error['message']}", $file['errors'])
                : ['The file does not exist.'];

            return $empty + ['errors' => $messages];
        }

        if ($empty['type'] === 'collection') {
            return JsonPlanner::collection($file['data'], $relativePath, JsonState::collection($relativePath, $this->files));
        }

        return JsonPlanner::showcase(
            $file['data'],
            $relativePath,
            JsonState::showcase($file['data'], $relativePath, $this->files, isset($options['showcase_id']) ? (int)$options['showcase_id'] : null)
        );
    }

    /**
     * Applies a file. Nothing is written when the file is invalid; a failing step stops the run and the version
     * is not recorded.
     *
     * @return array{ok: bool, done: string[], failed: ?array, error: ?string, collection_id: ?int, showcase_id: ?int, plan: array}
     */
    public function apply(string $relativePath, array $options = []): array
    {
        $plan = $this->plan($relativePath, $options);

        $file = $this->load($relativePath);

        $result = (new JsonApplier($this->cacheManager))->apply($plan, $file['data'] ?? [], $options);

        return $result + ['plan' => $plan];
    }

    public function exportCollection(int $collectionId): ?array
    {
        $rows = collectionGet(["collection_id='{$collectionId}'"], JsonState::columnNames('myshowcase_collections'));

        if (!isset($rows[$collectionId])) {
            return null;
        }

        $options = [];

        foreach (
            fieldDataGet(
                ["collection_id='{$collectionId}'"],
                ['field_id', 'value_id', 'value', 'display_order', 'display_style']
            ) as $option
        ) {
            $options[(int)$option['field_id']][] = $option;
        }

        return JsonExporter::collection(
            $rows[$collectionId],
            array_values(fieldsGet(["collection_id='{$collectionId}'"], JsonState::columnNames('myshowcase_fields'))),
            $options
        );
    }

    public function exportShowcase(int $showcaseId): ?array
    {
        $rows = showcaseGet(["showcase_id='{$showcaseId}'"], JsonState::columnNames('myshowcase_config'), [], false);

        if (!isset($rows[$showcaseId])) {
            return null;
        }

        $collectionId = (int)$rows[$showcaseId]['collection_id'];

        $collection = collectionGet(["collection_id='{$collectionId}'"], JsonState::columnNames('myshowcase_collections'))[$collectionId] ?? [];

        $basename = ($collection['json_file'] ?? '') !== ''
            ? JsonFiles::basenameOf($collection['json_file'])
            : (string)($collection['name'] ?? 'collection');

        return JsonExporter::showcase($rows[$showcaseId], $basename);
    }
}
