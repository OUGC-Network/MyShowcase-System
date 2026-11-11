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
 * The JSON and PHP files the sync reads, and nothing else: files are only found by scanning the two directories,
 * a name must match ^[a-z0-9_\-]+\.json$ and must resolve (realpath) inside imports/JSON.
 */
final class JsonFiles
{
    public const MAX_BYTES = 524288;

    /** Type of a file => directory below imports/JSON (and imports/PHP). */
    public const DIRECTORIES = ['collection' => 'collections', 'showcase' => 'showcases'];

    public function __construct(private readonly string $importsDirectory)
    {
    }

    public static function default(): self
    {
        return new self(\MyShowcase\ROOT . '/imports');
    }

    /** "collections/blog.json" for ("collection", "blog"). */
    public static function relativePath(string $type, string $basename): string
    {
        return self::DIRECTORIES[$type] . '/' . $basename . '.json';
    }

    /** "blog" for "collections/blog.json". */
    public static function basenameOf(string $relativePath): string
    {
        return basename($relativePath, '.json');
    }

    /** The type ("collection" or "showcase") a relative path belongs to, or null if it is not a valid path. */
    public static function typeOf(string $relativePath): ?string
    {
        foreach (self::DIRECTORIES as $type => $directory) {
            if (preg_match('#^' . $directory . '/[a-z0-9_\-]+\.json$#', $relativePath)) {
                return $type;
            }
        }

        return null;
    }

    /** @return array<string, string> file name => relative path, for the files of one type. */
    public function list(string $type): array
    {
        $directory = $this->importsDirectory . '/JSON/' . (self::DIRECTORIES[$type] ?? '');

        $files = [];

        if (!isset(self::DIRECTORIES[$type]) || !is_dir($directory)) {
            return $files;
        }

        foreach (scandir($directory) ?: [] as $name) {
            if (preg_match('/^[a-z0-9_\-]+\.json$/', $name)) {
                $relative = self::DIRECTORIES[$type] . '/' . $name;

                if ($this->resolve($relative) !== null) {
                    $files[$name] = $relative;
                }
            }
        }

        ksort($files);

        return $files;
    }

    /** Absolute path of an existing JSON file below imports/JSON, or null. */
    public function resolve(string $relativePath): ?string
    {
        if (self::typeOf($relativePath) === null) {
            return null;
        }

        $base = realpath($this->importsDirectory . '/JSON');

        $path = realpath($this->importsDirectory . '/JSON/' . $relativePath);

        if ($base === false || $path === false || !is_file($path) ||
            !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $path;
    }

    /**
     * @return array{exists: bool, data: ?array, error: ?string, raw: ?string}
     */
    public function read(string $relativePath): array
    {
        $path = $this->resolve($relativePath);

        if ($path === null) {
            return ['exists' => false, 'data' => null, 'error' => null, 'raw' => null];
        }

        if (filesize($path) > self::MAX_BYTES) {
            return ['exists' => true, 'data' => null, 'error' => 'The file is larger than 512 KB.', 'raw' => null];
        }

        $raw = (string)file_get_contents($path);

        return ['exists' => true] + self::decode($raw);
    }

    /** @return array{data: ?array, error: ?string, raw: string} */
    public static function decode(string $raw): array
    {
        if (strlen($raw) > self::MAX_BYTES) {
            return ['data' => null, 'error' => 'The file is larger than 512 KB.', 'raw' => $raw];
        }

        try {
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return ['data' => null, 'error' => 'Invalid JSON: ' . $exception->getMessage(), 'raw' => $raw];
        }

        if (!is_array($data) || array_is_list($data)) {
            return ['data' => null, 'error' => 'The top level of the file must be an object.', 'raw' => $raw];
        }

        return ['data' => $data, 'error' => null, 'raw' => $raw];
    }

    /**
     * PHP update steps of a file: imports/PHP/{collections|showcases}/{basename}/{version}.php
     *
     * @return array<string, string> version => absolute path, in ascending version order.
     */
    public function phpSteps(string $type, string $basename): array
    {
        $directory = $this->importsDirectory . '/PHP/' . (self::DIRECTORIES[$type] ?? '') . '/' . $basename;

        $steps = [];

        if (!isset(self::DIRECTORIES[$type]) || !preg_match('/^[a-z0-9_\-]+$/', $basename) || !is_dir($directory)) {
            return $steps;
        }

        foreach (scandir($directory) ?: [] as $name) {
            if (preg_match('/^(\d+\.\d+\.\d+)\.php$/', $name, $matches)) {
                $steps[$matches[1]] = $directory . '/' . $name;
            }
        }

        uksort($steps, 'version_compare');

        return $steps;
    }
}
