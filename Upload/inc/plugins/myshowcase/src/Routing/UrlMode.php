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
 * The URL option of a showcase (decisions D224, D229, D233): whether the links the plugin generates for a showcase are plain
 * (the showcase.php segment stays in them) or friendly (the segment is removed, which needs a server rewrite). Both forms are
 * always routed; this option only chooses the links.
 *
 * Automatic follows MyBB core's own rule (inc/init.php): friendly when the board's seourls setting is yes, or when it is auto
 * and the request carries SEO_SUPPORT that core accepts (isset, and equal to 1), and the plugin's own marker
 * SHOWCASE_SEO_SUPPORT (set, and equal to 1: the rewrite snippets of the documentation set it). This is the one place that rule
 * is written.
 */
final class UrlMode
{
    public const PLAIN = 0;

    public const AUTOMATIC = 1;

    public const FRIENDLY = 2;

    public const DEFAULT = self::AUTOMATIC;

    public static function isValid(int $mode): bool
    {
        return $mode === self::PLAIN || $mode === self::AUTOMATIC || $mode === self::FRIENDLY;
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

        $coreRule = $seourls === 'yes' || ($seourls === 'auto' && $seoSupport !== null && $seoSupport == 1);

        return $coreRule && $marker !== null && $marker == 1;
    }
}
