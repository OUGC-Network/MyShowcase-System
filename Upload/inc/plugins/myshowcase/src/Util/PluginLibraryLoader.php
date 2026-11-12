<?php

declare(strict_types=1);

namespace MyShowcase\Util;

use PluginLibrary;

use const PLUGINLIBRARY;

/**
 * Loads MyBB's PluginLibrary on the front end (the plugin loads it in the ACP only) and says what is wrong when it
 * cannot be used: the file is missing, or it is older than the version the plugin needs. The export and import pages
 * ask this first and show the matching language string instead of failing.
 */
class PluginLibraryLoader
{
    /** The oldest PluginLibrary the plugin works with (its $version property). */
    public const MINIMUM_VERSION = 13;

    public const PROBLEM_MISSING = 'library_missing';

    public const PROBLEM_VERSION = 'library_version';

    /**
     * @return PluginLibrary|string The library, or the PROBLEM_* code that explains why it cannot be used.
     */
    public function load(): PluginLibrary|string
    {
        global $PL;

        if (!($PL instanceof PluginLibrary)) {
            if (!defined('PLUGINLIBRARY') || !is_file(PLUGINLIBRARY)) {
                return self::PROBLEM_MISSING;
            }

            require_once PLUGINLIBRARY;
        }

        if (!($PL instanceof PluginLibrary)) {
            return self::PROBLEM_MISSING;
        }

        if ((int)$PL->version < self::MINIMUM_VERSION) {
            return self::PROBLEM_VERSION;
        }

        return $PL;
    }
}
