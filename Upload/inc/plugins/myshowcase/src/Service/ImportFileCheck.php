<?php

declare(strict_types=1);

namespace MyShowcase\Service;

/**
 * The structural checks of an entries import (design doc, section 6, stage 5). They are the only checks the import makes
 * of its own: every value is then checked by the same validator as the forum create form (EntriesManager).
 *
 * Nothing here reads the database, so it is tested without a board (tests/import/run.php). A problem is a code and its
 * arguments; the controller turns the code into a language string, so the messages stay in the language files.
 *
 * Problem codes and arguments:
 * - format: none. The header is not the one of an entries export.
 * - version: the format_version found (not 1 or lower/higher than this version can read).
 * - showcase: the route identifier of the file, and of this showcase: the file belongs to another showcase.
 * - collection is not a problem: it is a warning (see warnings(), decision T3).
 * - entries_not_list: "entries" is not a list.
 * - empty: the file has no entries.
 * - too_many: the number of entries and the maximum.
 * - count_mismatch: header.entry_count and the number of entries.
 * - entry_shape: the first position at which an entry is not an array, or a value is not a scalar, or "fields" is not
 *   an array; the position in the file (1-based).
 * - not_enabled: the file has a subject or a message the showcase does not use.
 * - unknown_field, not_fillable: one per field key (args: key, first positions, count of positions).
 * - external_disabled: a file field holds a URL and the import of external files is off (args: first positions, count).
 * - option_version: a format 1 or 2 file carries option values, which are field_data_ids and not value_ids (decision D128):
 *   the file is refused as a whole (args: version, option values, entries with one).
 */
final class ImportFileCheck
{
    /** How many entry positions a per-key problem lists before it says "and N more". */
    public const POSITIONS_LISTED = 5;

    /**
     * @param array<mixed> $data the file's content, as XmlFile::read() returns it
     * @param array{route_identifier: string, collection_name: string, known_keys: string[], fillable_keys: string[], file_keys: string[], option_keys: string[], maximum_entries: int, subject_enabled: bool, message_enabled: bool, external_files: bool} $target
     * @return list<array{code: string, args: list<mixed>}>
     */
    public static function problems(array $data, array $target): array
    {
        $problems = [];

        $header = $data['header'] ?? null;

        if (!is_array($header) || ($header['format'] ?? null) !== EntriesExporter::FORMAT) {
            return [['code' => 'format', 'args' => []]];
        }

        $version = $header['format_version'] ?? null;

        if (!is_int($version) || $version < 1 || $version > EntriesExporter::FORMAT_VERSION) {
            $problems[] = ['code' => 'version', 'args' => [is_scalar($version) ? (string)$version : '']];
        }

        $showcase = is_array($header['showcase'] ?? null) ? $header['showcase'] : [];

        $file_route = (string)($showcase['route_identifier'] ?? '');

        if ($file_route !== $target['route_identifier']) {
            $problems[] = ['code' => 'showcase', 'args' => [$file_route, $target['route_identifier']]];
        }

        $entries = $data['entries'] ?? null;

        if (!is_array($entries) || !array_is_list($entries)) {
            $problems[] = ['code' => 'entries_not_list', 'args' => []];

            return $problems;
        }

        $count = count($entries);

        if ($count === 0) {
            $problems[] = ['code' => 'empty', 'args' => []];
        }

        if ($count > $target['maximum_entries']) {
            $problems[] = ['code' => 'too_many', 'args' => [$count, $target['maximum_entries']]];
        }

        $declared = $header['entry_count'] ?? null;

        if (!is_int($declared) || $declared !== $count) {
            $problems[] = ['code' => 'count_mismatch', 'args' => [is_scalar($declared) ? (string)$declared : '', $count]];
        }

        if (is_int($version) && $version >= 1 && $version < 3) {
            $problems = array_merge($problems, self::optionProblems($entries, $version, $target));
        }

        return array_merge($problems, self::entryProblems($entries, $target));
    }

    /**
     * A format 1 or 2 file holds the field_data_id of an option, which is board-specific: it cannot be read as a value_id
     * (decision D128). Such a file is refused as a whole. A file of those versions without option values still imports.
     *
     * @param array<mixed> $entries
     * @param array{option_keys: string[]} $target
     * @return list<array{code: string, args: list<mixed>}>
     */
    private static function optionProblems(array $entries, int $version, array $target): array
    {
        $values = 0;

        $entries_with_values = [];

        foreach ($entries as $index => $entry) {
            if (!is_array($entry['fields'] ?? null)) {
                continue;
            }

            foreach ($target['option_keys'] as $key) {
                $value = $entry['fields'][$key] ?? null;

                // A blank is '' or the stored 0 of a blank optional option field; neither is an option. A list of values is
                // an option when it is not empty.
                if (is_array($value)) {
                    if ($value === []) {
                        continue;
                    }
                } elseif ($value === null || !is_scalar($value) || (string)$value === '' || (string)$value === '0') {
                    continue;
                }

                $values++;
                $entries_with_values[$index] = true;
            }
        }

        if ($values === 0) {
            return [];
        }

        return [['code' => 'option_version', 'args' => [(string)$version, $values, count($entries_with_values)]]];
    }

    /**
     * The warnings of a file: a collection name that differs from the target's (decision T3). The import goes on; every
     * field key of the file is still checked against the target collection by problems().
     *
     * @param array<mixed> $data
     * @param array{collection_name: string} $target
     * @return list<array{code: string, args: list<mixed>}>
     */
    public static function warnings(array $data, array $target): array
    {
        $header = is_array($data['header'] ?? null) ? $data['header'] : [];

        $collection = is_array($header['collection'] ?? null) ? $header['collection'] : [];

        $file_collection = (string)($collection['name'] ?? '');

        if ($file_collection === $target['collection_name']) {
            return [];
        }

        return [['code' => 'collection', 'args' => [$file_collection, $target['collection_name']]]];
    }

    /**
     * @param array<int, mixed> $entries
     * @param array{known_keys: string[], fillable_keys: string[], file_keys: string[], subject_enabled: bool, message_enabled: bool} $target
     * @return list<array{code: string, args: list<mixed>}>
     */
    private static function entryProblems(array $entries, array $target): array
    {
        $problems = [];

        $shape_positions = [];

        $not_enabled = [];

        // Field key => the positions (1-based) of the entries that use it, by kind. A file field that holds a value holds
        // a URL (section 5.7): its syntax is checked per entry by the importer, not here.
        $unknown = [];
        $external = [];
        $not_fillable = [];

        foreach ($entries as $index => $entry) {
            $position = $index + 1;

            if (!is_array($entry)) {
                $shape_positions[$position] = true;

                continue;
            }

            foreach (['subject' => $target['subject_enabled'], 'message' => $target['message_enabled']] as $core => $enabled) {
                if (!isset($entry[$core])) {
                    continue;
                }

                if (!is_scalar($entry[$core])) {
                    $shape_positions[$position] = true;
                } elseif (!$enabled) {
                    $not_enabled[$core] = true;
                }
            }

            $fields = $entry['fields'] ?? [];

            if (!is_array($fields)) {
                $shape_positions[$position] = true;

                continue;
            }

            foreach ($fields as $key => $value) {
                $key = (string)$key;

                // A value is a scalar, except the list of a multiple-choice field (decision D182).
                if ($value !== null && !is_scalar($value) && !(is_array($value) && in_array($key, $target['list_keys'] ?? [], true))) {
                    $shape_positions[$position] = true;
                }

                if (!in_array($key, $target['known_keys'], true)) {
                    $unknown[$key][] = $position;
                } elseif (in_array($key, $target['file_keys'], true)) {
                    if ($value !== null && $value !== '') {
                        $external[$key][] = $position;
                    }
                } elseif (!in_array($key, $target['fillable_keys'], true)) {
                    $not_fillable[$key][] = $position;
                }
            }
        }

        if ($shape_positions !== []) {
            $problems[] = ['code' => 'entry_shape', 'args' => [array_key_first($shape_positions)]];
        }

        foreach (array_keys($not_enabled) as $core) {
            $problems[] = ['code' => 'not_enabled', 'args' => [$core]];
        }

        // With the import of external files off (decision D51), a file that carries any file URL is refused as a whole.
        if (!$target['external_files'] && $external !== []) {
            $positions = array_unique(array_merge(...array_values($external)));

            sort($positions);

            $problems[] = [
                'code' => 'external_disabled',
                'args' => [implode(', ', array_slice($positions, 0, self::POSITIONS_LISTED)), count($positions)],
            ];
        }

        foreach (
            [
                'unknown_field' => $unknown,
                'not_fillable' => $not_fillable,
            ] as $code => $by_key
        ) {
            foreach ($by_key as $key => $positions) {
                $problems[] = [
                    'code' => $code,
                    'args' => [$key, implode(', ', array_slice($positions, 0, self::POSITIONS_LISTED)), count($positions)],
                ];
            }
        }

        return $problems;
    }
}
