<?php
/**
 * MyShowcase Plugin for MyBB - Main Plugin
 * Copyright 2012 CommunityPlugins.com, All Rights Reserved
 *
 * Website: https://github.com/Sama34/MyShowcase-System
 * License: Creative Commons Attribution-NonCommerical ShareAlike 3.0
 * http://creativecommons.org/licenses/by-nc-sa/3.0/legalcode
 * File: \inc\plugins\myshowcase\src\Service\CollectionService.php
 *
 */

declare(strict_types=1);

namespace MyShowcase\Service;

use MyShowcase\Enum\CacheDataTypes;

use function MyShowcase\Plugin\Functions\collectionGet;
use function MyShowcase\Plugin\Functions\collectionInsert;
use function MyShowcase\Plugin\Functions\collectionUpdate;

/** Validates and writes collections (myshowcase_collections). */
final class CollectionService
{
    public function __construct(private readonly CacheManager $cacheManager)
    {
    }

    /** @return array<int, array{key: string, message: string}> */
    public function validate(string $name, int $collectionId = 0): array
    {
        global $db;

        $errors = [];

        if ($name === '') {
            $errors[] = [
                'key' => 'myshowcase_admin_error_missing_required_fields',
                'message' => 'A required field is missing.'
            ];
        } elseif (collectionGet(
            ["name='{$db->escape_string($name)}'", "collection_id<>'{$collectionId}'"],
            ['name']
        )) {
            $errors[] = [
                'key' => 'myshowcase_admin_error_duplicated_name',
                'message' => 'The selected name is already in use.'
            ];
        }

        return $errors;
    }

    /**
     * Inserts a collection, or updates it when $collectionId is given, and refreshes the caches it affects.
     * Hook collection_insert_update_end (collectionInsert) fires as before.
     *
     * @return int The id of the collection.
     */
    public function save(array $data, int $collectionId = 0): int
    {
        if ($collectionId) {
            collectionUpdate($data, $collectionId);
        } else {
            $collectionId = collectionInsert($data);
        }

        $this->refreshCaches();

        return $collectionId;
    }

    public function refreshCaches(): void
    {
        foreach (
            [
                CacheDataTypes::Config,
                CacheDataTypes::Fields,
                CacheDataTypes::FieldData,
                CacheDataTypes::Collections,
            ] as $cacheType
        ) {
            $this->cacheManager->update($cacheType);
        }
    }
}
