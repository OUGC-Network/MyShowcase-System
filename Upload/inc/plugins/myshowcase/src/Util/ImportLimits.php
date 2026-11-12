<?php

declare(strict_types=1);

namespace MyShowcase\Util;

/**
 * The size an exported or imported file may have (design doc, section 7). PluginLibrary needs about 28 times the file's
 * size in memory to read it, so the cap is a fortieth of the PHP memory limit, never more than 5 MB and never less than
 * 256 KB. An export that would be larger is refused, which keeps every stored export importable on the same server.
 */
class ImportLimits
{
    public const CEILING = 5 * 1024 * 1024;

    public const FLOOR = 256 * 1024;

    public const MEMORY_DIVISOR = 40;

    public static function sizeCap(?string $memory_limit = null): int
    {
        $bytes = self::bytesOf($memory_limit ?? (string)ini_get('memory_limit'));

        if ($bytes < 0) {
            return self::CEILING;
        }

        return max(self::FLOOR, min(self::CEILING, intdiv($bytes, self::MEMORY_DIVISOR)));
    }

    /**
     * Bytes in a php.ini size ("128M", "1G", "512K", "-1", "134217728"); -1 stays -1 (no limit).
     */
    public static function bytesOf(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int)$value;

        return match (strtoupper(substr($value, -1))) {
            'G' => $number * 1024 * 1024 * 1024,
            'M' => $number * 1024 * 1024,
            'K' => $number * 1024,
            default => $number,
        };
    }
}
