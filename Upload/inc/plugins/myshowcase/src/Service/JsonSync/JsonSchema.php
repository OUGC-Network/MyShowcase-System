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

use MyShowcase\Enum\FieldHtmlTypes;

use const MyShowcase\Plugin\Core\TABLES_DATA;

/** What a JSON file may contain: the whitelists, taken from the TABLES_DATA columns. */
final class JsonSchema
{
    public const JSON_COLUMNS = ['json_file', 'json_version', 'json_synced_at', 'json_hash'];

    /** Never in a showcase file: ids, the sync columns, board-specific values and the per-showcase values. */
    public const SHOWCASE_EXCLUDED = [
        'showcase_id', 'collection_id', 'custom_theme_id', 'attachments_uploads_path', 'attachments_watermark_file',
        'name', 'route_identifier', 'custom_template_prefix', 'showcase_url', 'password',
    ];

    public const FIELD_EXCLUDED = ['field_id', 'collection_id', 'field_label'];

    /** html_type values whose fields have option values. */
    public const OPTION_TYPES = [FieldHtmlTypes::CheckBox, FieldHtmlTypes::Radio, FieldHtmlTypes::Select];

    public const GROUP_COLUMNS = ['allowed_groups_fill', 'allowed_groups_view'];

    public static function showcaseColumns(): array
    {
        return self::columns(TABLES_DATA['myshowcase_config'], self::SHOWCASE_EXCLUDED);
    }

    public static function fieldColumns(): array
    {
        return self::columns(TABLES_DATA['myshowcase_fields'], self::FIELD_EXCLUDED);
    }

    /** Option values (myshowcase_field_data) the file may set. */
    public static function optionColumns(): array
    {
        return self::columns(
            TABLES_DATA['myshowcase_field_data'],
            ['field_data_id', 'collection_id', 'field_id', 'field_key', 'allowed_groups_fill', 'allowed_groups_view']
        );
    }

    private static function columns(array $table, array $excluded): array
    {
        $columns = [];

        foreach ($table as $name => $definition) {
            if (is_array($definition) && isset($definition['type']) && empty($definition['auto_increment']) &&
                !in_array($name, $excluded, true) && !in_array($name, self::JSON_COLUMNS, true)) {
                $columns[$name] = $definition;
            }
        }

        return $columns;
    }

    /** The default value of a column as the string the database would hold. */
    public static function defaultOf(array $definition): string
    {
        return (string)($definition['default'] ?? '');
    }
}
