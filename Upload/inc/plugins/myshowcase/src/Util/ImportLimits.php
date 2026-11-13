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
     * The cap that applies to an upload: the memory based size cap, or PHP's own request limit when that is smaller.
     * PHP refuses an upload over upload_max_filesize, and over post_max_size with no fields at all; an export the
     * server cannot upload again would be useless. 0 or -1 in either setting means no limit of its own.
     */
    public static function effectiveCap(
        ?string $memory_limit = null,
        ?string $upload_max_filesize = null,
        ?string $post_max_size = null
    ): int {
        $cap = self::sizeCap($memory_limit);

        $request_limits = [
            $upload_max_filesize ?? (string)ini_get('upload_max_filesize'),
            $post_max_size ?? (string)ini_get('post_max_size'),
        ];

        foreach ($request_limits as $request_limit) {
            $bytes = self::bytesOf($request_limit);

            if ($bytes > 0 && $bytes < $cap) {
                $cap = $bytes;
            }
        }

        return $cap;
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
