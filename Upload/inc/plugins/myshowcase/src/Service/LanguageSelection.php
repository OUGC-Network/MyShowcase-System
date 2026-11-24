<?php

declare(strict_types=1);

namespace MyShowcase\Service;

use MyShowcase\Entity\ShowcaseEntity;

/**
 * Per-showcase reusable custom language files (TP-D8), independent of JSON presets and template packages.
 */
final class LanguageSelection
{
    public const LANGUAGE_FILE_PREFIX = 'myshowcase_showcase_';

    /** Create-form sentinel: apply the shipped preset language file when a preset is chosen (TP-D8). */
    public const LANGUAGE_PREFIX_USE_PRESET = 'presetdefault';

    /** Shipped showcase JSON files and their default reusable language file basename (not coupled to template folders). */
    private const PRESET_SHOWCASE_LANGUAGE_FILES = [
        'showcases/blog.json' => 'article',
        'showcases/downloads.json' => 'resources',
        'showcases/gallery.json' => 'previews',
    ];

    public static function normalizeLanguagePrefix(string $prefix): string
    {
        return (string)preg_replace('/[^\da-z]/i', '', my_strtolower($prefix));
    }

    /**
     * Reusable language file basenames bundled or installed under inc/languages/<lang>/myshowcase_showcase_*.lang.php.
     *
     * @return string[]
     */
    public static function languageSets(): array
    {
        $languageRoot = dirname(\MyShowcase\ROOT, 3) . '/languages/english';
        $sets = [];
        $pattern = self::LANGUAGE_FILE_PREFIX . '*.lang.php';

        foreach ((array)glob($languageRoot . '/' . $pattern) ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }

            $basename = basename($file, '.lang.php');
            $name = substr($basename, strlen(self::LANGUAGE_FILE_PREFIX));

            if ($name !== '' && $name === self::normalizeLanguagePrefix($name)) {
                $sets[] = $name;
            }
        }

        sort($sets, SORT_STRING);

        return $sets;
    }

    public static function presetLanguageFileForShowcaseJsonFile(string $jsonFile): string
    {
        $jsonFile = ltrim(str_replace('\\', '/', $jsonFile), '/');

        $prefix = self::PRESET_SHOWCASE_LANGUAGE_FILES[$jsonFile] ?? '';

        if ($prefix === '' || !in_array($prefix, self::languageSets(), true)) {
            return '';
        }

        return $prefix;
    }

    /**
     * Resolves the stored language prefix on ACP Summary create from the raw select value and optional preset file.
     */
    public static function resolveCreateLanguagePrefix(string $rawPrefix, string $presetJsonFile): string
    {
        if ($rawPrefix === self::LANGUAGE_PREFIX_USE_PRESET) {
            $presetJsonFile = trim($presetJsonFile);

            if ($presetJsonFile === '') {
                return '';
            }

            return self::presetLanguageFileForShowcaseJsonFile($presetJsonFile);
        }

        return self::normalizeLanguagePrefix($rawPrefix);
    }

    /** MyBB language section name (filename without .lang.php) for a stored prefix, or empty when unset/invalid. */
    public static function languageSectionForPrefix(string $prefix): string
    {
        $prefix = self::normalizeLanguagePrefix($prefix);

        if ($prefix === '' || !in_array($prefix, self::languageSets(), true)) {
            return '';
        }

        return self::LANGUAGE_FILE_PREFIX . $prefix;
    }

    /** Optional per-showcase id file section (myshowcase{N}), always safe to attempt with suppress_error. */
    public static function showcaseInstanceSection(?ShowcaseEntity $showcase): string
    {
        if ($showcase === null || $showcase->showcase_id === null || $showcase->showcase_id <= 0) {
            return '';
        }

        return 'myshowcase' . (int)$showcase->showcase_id;
    }

    /**
     * Language sections to load for a showcase request, in order (later entries override earlier ones).
     *
     * @return string[]
     */
    public static function sectionsForShowcase(?ShowcaseEntity $showcase): array
    {
        if ($showcase === null) {
            return [];
        }

        $sections = [];
        $shared = self::languageSectionForPrefix($showcase->custom_language_prefix);

        if ($shared !== '') {
            $sections[] = $shared;
        }

        $instance = self::showcaseInstanceSection($showcase);

        if ($instance !== '') {
            $sections[] = $instance;
        }

        return $sections;
    }
}