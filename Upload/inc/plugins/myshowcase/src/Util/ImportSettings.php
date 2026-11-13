<?php

declare(strict_types=1);

namespace MyShowcase\Util;

/**
 * The global setting that switches the import of external files off (decision D51). Every reader of the setting goes
 * through this class, so the name and the default are in one place.
 *
 * The default is yes: an import file whose file fields carry URLs is imported, as the forum's own settings allow.
 * Exports are never affected by the setting.
 */
final class ImportSettings
{
    /** The MyBB setting (in the plugin's settings group, created by admin.php). */
    public const EXTERNAL_FILES = 'myshowcase_import_external_files';

    public static function externalFilesEnabled(): bool
    {
        global $mybb;

        $value = $mybb->settings[self::EXTERNAL_FILES] ?? 1;

        return (int)$value === 1;
    }
}
