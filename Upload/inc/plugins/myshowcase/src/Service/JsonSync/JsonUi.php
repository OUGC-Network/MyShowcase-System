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

/** HTML snippets of the JSON sync for the ACP pages: badges, links and disabled inputs. */
final class JsonUi
{
    private const STYLE = [
        JsonStatus::IN_SYNC => '',
        JsonStatus::UPDATE_AVAILABLE => 'background:#fff3cd;color:#664d03;',
        JsonStatus::UNVERSIONED_CHANGE => 'background:#ffe5b4;color:#7a3e00;',
        JsonStatus::DOWNGRADE => 'background:#f8d7da;color:#842029;',
        JsonStatus::FILE_MISSING => 'background:#f8d7da;color:#842029;',
        JsonStatus::INVALID => 'background:#f8d7da;color:#842029;',
        JsonStatus::COLLECTION_DETACHED => 'background:#f8d7da;color:#842029;',
        JsonStatus::CUSTOM => 'background:#e2e3e5;color:#41464b;',
    ];

    public static function text(string $key, string ...$values): string
    {
        global $lang;

        static $loaded = false;

        if (!$loaded && is_object($lang) && method_exists($lang, 'load')) {
            $lang->load('myshowcase_json_sync', false, true);

            $loaded = true;
        }

        $text = (string)($lang->{'myshowcase_json_sync_' . $key} ?? $key);

        foreach ($values as $index => $value) {
            $text = str_replace('{' . ($index + 1) . '}', $value, $text);
        }

        return $text;
    }

    /** @param bool $html False for a redirect, true for an href */
    public static function url(array $parameters, bool $html = true): string
    {
        global $mybb;

        $parameters += ['module' => 'myshowcase-json_sync', 'my_post_key' => $mybb->post_code];

        return 'index.php?' . http_build_query($parameters, '', $html ? '&amp;' : '&');
    }

    public static function badge(string $status): string
    {
        $style = self::STYLE[$status] ?? '';

        return '<span class="myshowcase_json_status" style="display:inline-block;padding:1px 6px;border-radius:3px;font-size:11px;'
            . $style . '">' . htmlspecialchars(self::text('status_' . $status)) . '</span>';
    }

    /**
     * Badge, file, version and the action that fits the status, for a showcase or collection row.
     *
     * @param string $type collection or showcase
     */
    public static function rowInfo(string $type, int $id, array $row, string $status): string
    {
        $html = ' ' . self::badge($status) . ' <small>' . htmlspecialchars((string)$row['json_file'])
            . ' · ' . htmlspecialchars(self::text('version', (string)$row['json_version'])) . '</small>';

        if ($status === JsonStatus::UPDATE_AVAILABLE) {
            $html .= ' <a class="button" style="padding:1px 6px;" href="' . self::url(['action' => 'preview', 'type' => $type, 'id' => $id, 'mode' => 'apply']) . '">'
                . self::text('apply_update') . '</a>';
        } elseif ($status === JsonStatus::UNVERSIONED_CHANGE) {
            $html .= ' <a href="' . self::url(['action' => 'preview', 'type' => $type, 'id' => $id, 'mode' => 'apply']) . '">'
                . self::text('apply_anyway') . '</a>';
        } elseif ($status === JsonStatus::INVALID) {
            $html .= ' <a href="' . self::url(['action' => 'errors', 'file' => $row['json_file']]) . '">' . self::text('show_errors') . '</a>';
        }

        return $html;
    }

    public static function customBadge(): string
    {
        return ' ' . self::badge(JsonStatus::CUSTOM);
    }

    /** Disables every input, select and textarea of a piece of generated form HTML. */
    public static function disable(string $html): string
    {
        return (string)preg_replace('/<(input|select|textarea)(?![^>]*\sdisabled)/i', '<$1 disabled="disabled"', $html);
    }

    public static function managedNote(): string
    {
        return '<small style="color:#666;"> ' . htmlspecialchars(self::text('managed')) . '</small>';
    }

    /**
     * The "JSON file" select of an edit form: None plus the files of that type from the directory scan.
     */
    public static function fileSelect(\Form $form, string $name, string $type, string $current): string
    {
        $options = ['' => self::text('none')];

        foreach ((new JsonFiles(\MyShowcase\ROOT . '/imports'))->list($type) as $relative) {
            $options[$relative] = $relative;
        }

        if ($current !== '' && !isset($options[$current])) {
            $options[$current] = $current;
        }

        return $form->generate_select_box($name, $options, $current, ['id' => $name]);
    }
}
