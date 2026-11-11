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

/** Where a linked row stands against its file. Pure: nothing is read or written here. */
final class JsonStatus
{
    public const IN_SYNC = 'in_sync';
    public const UPDATE_AVAILABLE = 'update_available';
    public const UNVERSIONED_CHANGE = 'unversioned_change';
    public const DOWNGRADE = 'downgrade';
    public const FILE_MISSING = 'file_missing';
    public const INVALID = 'invalid';
    public const CUSTOM = 'custom';
    /** A showcase whose collection is not linked to the collection file the showcase file names. */
    public const COLLECTION_DETACHED = 'collection_detached';

    /**
     * @param array{json_version: string, json_hash: string} $row
     * @param array{exists: bool, valid: bool, version?: string, hash?: string} $file
     */
    public static function of(array $row, array $file): string
    {
        if (!$file['exists']) {
            return self::FILE_MISSING;
        }

        if (!$file['valid']) {
            return self::INVALID;
        }

        $comparison = version_compare((string)$file['version'], (string)$row['json_version']);

        if ($comparison > 0) {
            return self::UPDATE_AVAILABLE;
        }

        if ($comparison < 0) {
            return self::DOWNGRADE;
        }

        return hash_equals((string)$row['json_hash'], (string)($file['hash'] ?? ''))
            ? self::IN_SYNC
            : self::UNVERSIONED_CHANGE;
    }

    /**
     * Status of a field row. A row with no json_file is the user's own ("custom"); a linked row whose field is gone
     * from the file is reported as file_missing for that field.
     *
     * @param array{json_file: string, json_version: string, json_hash: string} $row
     * @param array{exists: bool, valid: bool, version?: string}|null $file The collection file
     * @param string|null $entryHash Hash of the field's entry in the file, null if the file has no such field
     */
    public static function ofField(array $row, ?array $file, ?string $entryHash): string
    {
        if ($row['json_file'] === '') {
            return self::CUSTOM;
        }

        if ($file === null || !$file['exists'] || $entryHash === null) {
            return self::FILE_MISSING;
        }

        return self::of($row, $file + ['hash' => $entryHash]);
    }

    /**
     * The columns the JSON of a row manages, for the forms to lock. Columns the file does not mention stay editable.
     *
     * @param string $type collection, showcase or field
     * @param array $content The decoded file (collection, showcase) or the field's entry (field)
     * @return string[]
     */
    public static function managedColumns(string $type, array $content): array
    {
        if ($type === 'collection') {
            return ['name'];
        }

        if ($type === 'showcase') {
            return array_values(array_merge(
                ['collection_id'],
                array_intersect(array_keys($content), array_keys(JsonSchema::showcaseColumns()))
            ));
        }

        $columns = array_intersect(array_keys($content), array_keys(JsonSchema::fieldColumns()));

        return array_values(array_unique(array_merge($columns, ['field_label'])));
    }
}
