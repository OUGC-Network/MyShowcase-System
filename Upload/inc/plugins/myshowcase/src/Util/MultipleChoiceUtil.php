<?php

declare(strict_types=1);

namespace MyShowcase\Util;

use MyShowcase\Enum\FieldHtmlTypes;
use MyShowcase\Enum\FieldTypes;

/**
 * The rule of the multiple-choice fields (decisions D184 and D187).
 *
 * A checkbox field is always multi-valued. A select field is multi-valued when its allow_multiple_values flag is 1. Radio and
 * every other type are single-valued, and the server ignores the flag for them (D131). A multi-valued field keeps its values
 * in the child table (myshowcase_field_values) and a cache in its data column, so that column must be TEXT: any other type is
 * refused, never converted.
 */
final class MultipleChoiceUtil
{
    /** The database type of the data column of a multi-valued field (D187). */
    public const COLUMN_TYPE = FieldTypes::Text;

    /**
     * The most files a multiple-file field holds (decision D197). The plugin has no per-field file count, so the cap is this
     * one documented constant.
     */
    public const MAX_FILES = 10;

    /** Whether a field of this HTML type and flag takes several values. */
    public static function isMultiple(string $html_type, mixed $allow_multiple_values): bool
    {
        if ($html_type === FieldHtmlTypes::CheckBox) {
            return true;
        }

        // A file field takes several files when its flag is 1 (decision D197).
        if ($html_type === FieldHtmlTypes::File) {
            return (int)$allow_multiple_values === 1;
        }

        return $html_type === FieldHtmlTypes::Select && (int)$allow_multiple_values === 1;
    }

    /** Whether the database type declared for the field fits its rule: only a multi-valued field has a requirement. */
    public static function columnTypeFits(string $html_type, mixed $allow_multiple_values, string $field_type): bool
    {
        if (!self::isMultiple($html_type, $allow_multiple_values)) {
            return true;
        }

        return strtolower($field_type) === self::COLUMN_TYPE;
    }
}
