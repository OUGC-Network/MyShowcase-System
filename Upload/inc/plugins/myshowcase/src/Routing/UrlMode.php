<?php
/**
 * MyShowcase Plugin for MyBB - Main Plugin
 * Copyright 2012 CommunityPlugins.com, All Rights Reserved
 *
 * Website: https://github.com/Sama34/MyShowcase-System
 * Version 2.5.2
 * License: Creative Commons Attribution-NonCommerical ShareAlike 3.0
 * http://creativecommons.org/licenses/by-nc-sa/3.0/legalcode
 * File: \inc\plugins\myshowcase.php
 *
 */

declare(strict_types=1);

namespace MyShowcase\Routing;

/**
 * The URL option of a showcase (decisions D224, D229, D233, D268, D269): the form of the links the plugin generates for a
 * showcase. Four values:
 *   0 Path:          showcase.php/<id>/... (needs PATH_INFO, which a plain nginx "location ~ \.php$" block does not pass);
 *   1 Automatic:     friendly when the request allows it, otherwise the query string form (the default);
 *   2 Friendly:      <id>/... without showcase.php (needs the rewrite rule);
 *   3 Query string:  showcase.php?route_identifier=<id>&route=<rest of the route>, with the other query parameters after it
 *                    (no server setup).
 * Every form is always routed; this option only chooses the links.
 *
 * Automatic follows MyBB core's own rule (inc/init.php): friendly when the board's seourls setting is yes, or when it is auto
 * and the request carries SEO_SUPPORT that core accepts (isset, and equal to 1), and the plugin's own marker
 * SHOWCASE_SEO_SUPPORT (set, and equal to 1: the rewrite snippets of the documentation set it). Without both, Automatic writes
 * the query string form. This is the one place that rule is written; no caller decides the form itself.
 */
final class UrlMode
{
    public const PLAIN = 0;

    public const AUTOMATIC = 1;

    public const FRIENDLY = 2;

    public const QUERY = 3;

    public const DEFAULT = self::AUTOMATIC;

    public const FORM_PATH = 'path';

    public const FORM_FRIENDLY = 'friendly';

    public const FORM_QUERY = 'query';

    public static function isValid(int $mode): bool
    {
        return $mode === self::PLAIN || $mode === self::AUTOMATIC || $mode === self::FRIENDLY || $mode === self::QUERY;
    }

    /**
     * The form of the links of a showcase for this request: FORM_PATH, FORM_FRIENDLY or FORM_QUERY (decisions D269, D278).
     * Path and friendly and query are chosen by the mode alone; Automatic is friendly only when usesFriendlyLinks() says so,
     * and query otherwise. A request without a web server (a task or the command line) carries no markers, so Automatic
     * writes the query form there.
     *
     * @param string $seourls the board's seourls setting: 'no', 'auto' or 'yes'
     * @param mixed $seoSupport $_SERVER['SEO_SUPPORT'] of the request; null when it is not set
     * @param mixed $marker $_SERVER['SHOWCASE_SEO_SUPPORT'] of the request; null when it is not set
     */
    public static function linkForm(int $mode, string $seourls, mixed $seoSupport, mixed $marker = null): string
    {
        if ($mode === self::PLAIN) {
            return self::FORM_PATH;
        }

        if ($mode === self::QUERY) {
            return self::FORM_QUERY;
        }

        if ($mode === self::FRIENDLY) {
            return self::FORM_FRIENDLY;
        }

        return self::usesFriendlyLinks(self::AUTOMATIC, $seourls, $seoSupport, $marker) ? self::FORM_FRIENDLY : self::FORM_QUERY;
    }

    /**
     * Whether the links of a showcase are friendly for this request (decisions D224, D243).
     *
     * Automatic is friendly only when core's rule holds (seourls yes, or auto with SEO_SUPPORT set and equal to 1) AND
     * the request carries the plugin's own marker, SHOWCASE_SEO_SUPPORT, set and equal to 1 with the same loose comparison.
     * Yes is always friendly and No always plain, whatever the request carries.
     *
     * @param string $seourls the board's seourls setting: 'no', 'auto' or 'yes'
     * @param mixed $seoSupport $_SERVER['SEO_SUPPORT'] of the request; null when it is not set
     * @param mixed $marker $_SERVER['SHOWCASE_SEO_SUPPORT'] of the request; null when it is not set
     */
    public static function usesFriendlyLinks(int $mode, string $seourls, mixed $seoSupport, mixed $marker = null): bool
    {
        if ($mode === self::PLAIN) {
            return false;
        }

        if ($mode === self::FRIENDLY) {
            return true;
        }

        if ($mode === self::QUERY) {
            return false;
        }

        $coreRule = $seourls === 'yes' || ($seourls === 'auto' && $seoSupport !== null && $seoSupport == 1);

        return $coreRule && $marker !== null && $marker == 1;
    }
}
