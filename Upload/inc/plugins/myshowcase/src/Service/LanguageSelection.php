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

    public const BASENAME_MAX_LENGTH = 50;

    /** Safe basename: lower-case letters, digits, underscores; first character alphanumeric. */
    public const BASENAME_PATTERN = '/^[a-z0-9][a-z0-9_]*$/';

    /** Absolute path to inc/languages (Upload/inc/languages when ROOT is inc/plugins/myshowcase). */
    public static function languagesRootPath(): string
    {
        return dirname(\MyShowcase\ROOT, 2) . '/languages';
    }

    public static function isValidBasename(string $basename): bool
    {
        return $basename !== ''
            && strlen($basename) <= self::BASENAME_MAX_LENGTH
            && preg_match(self::BASENAME_PATTERN, $basename) === 1;
    }

    /**
     * Normalizes administrator input for storage: trim and lower-case when the basename is valid, otherwise unchanged.
     */
    public static function normalizeSubmittedBasename(string $raw): string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return '';
        }

        $lower = strtolower($raw);

        if (!self::isValidBasename($lower)) {
            return $raw;
        }

        return $lower;
    }

    /**
     * Reusable language file basenames installed under inc/languages/&lt;lang&gt;/myshowcase_showcase_*.lang.php.
     * Only lowercase basenames that match the on-disk filename are discovered.
     *
     * @return string[]
     */
    public static function languageSets(): array
    {
        $languagesRoot = self::languagesRootPath();
        $sets = [];
        $patternSuffix = '/' . self::LANGUAGE_FILE_PREFIX . '*.lang.php';

        foreach ((array)glob($languagesRoot . '/*', GLOB_ONLYDIR) ?: [] as $languageDir) {
            if (!is_dir($languageDir)) {
                continue;
            }

            foreach ((array)glob($languageDir . $patternSuffix) ?: [] as $file) {
                if (!is_file($file)) {
                    continue;
                }

                $basename = basename($file, '.lang.php');
                $name = substr($basename, strlen(self::LANGUAGE_FILE_PREFIX));

                if ($name !== '' && self::isValidBasename($name)) {
                    $sets[$name] = $name;
                }
            }
        }

        $discovered = array_values($sets);
        sort($discovered, SORT_STRING);

        return $discovered;
    }

    /** MyBB language section name (filename without .lang.php) for a stored basename, or empty when unset/invalid/missing. */
    public static function languageSectionForPrefix(string $prefix): string
    {
        $prefix = strtolower(trim($prefix));

        if ($prefix === '' || !self::isValidBasename($prefix) || !in_array($prefix, self::languageSets(), true)) {
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