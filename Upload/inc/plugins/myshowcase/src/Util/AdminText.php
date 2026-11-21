<?php

declare(strict_types=1);

namespace MyShowcase\Util;

/**
 * The text of an ACP message: the key is read from an admin language file (the file is loaded once), and the values fill the
 * placeholders {1}, {2}, ... of the text. The services that have no language object of their own use it (decision MSG-b).
 */
final class AdminText
{
    /** @var array<string, bool> */
    private static array $loaded = [];

    public static function text(string $file, string $key, string ...$values): string
    {
        global $lang;

        if (!isset(self::$loaded[$file]) && is_object($lang) && method_exists($lang, 'load')) {
            $lang->load($file, false, true);

            self::$loaded[$file] = true;
        }

        $text = (string)($lang->{$key} ?? $key);

        foreach ($values as $index => $value) {
            $text = str_replace('{' . ($index + 1) . '}', $value, $text);
        }

        return $text;
    }
}
