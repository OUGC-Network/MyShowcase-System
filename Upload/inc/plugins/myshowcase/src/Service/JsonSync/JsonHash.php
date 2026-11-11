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

/** The one form a decoded file is hashed in. */
final class JsonHash
{
    /** Keys sorted recursively (lists keep their order), then encoded without escaping slashes and Unicode. */
    public static function canonical(mixed $data): string
    {
        return (string)json_encode(self::sorted($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function hash(mixed $data): string
    {
        return hash('sha256', self::canonical($data));
    }

    private static function sorted(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        $data = array_map([self::class, 'sorted'], $data);

        if (!array_is_list($data)) {
            ksort($data, SORT_STRING);
        }

        return $data;
    }
}
