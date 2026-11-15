<?php

declare(strict_types=1);

namespace MyShowcase\Plugin\ErrorSentence;

/**
 * The sentence an error page shows (decision D146). It is read from the plugin's language object of the context, then
 * from MyBB's global language, and the English text is the last resort. It has no dependency, because the error handler
 * of the entry script runs also when the plugin could not start.
 */
function errorSentence(mixed $context_language, mixed $global_language, string $key, string $english): string
{
    foreach ([$context_language, $global_language] as $language) {
        if (is_object($language) && isset($language->$key) && is_string($language->$key) && $language->$key !== '') {
            return $language->$key;
        }
    }

    return $english;
}
