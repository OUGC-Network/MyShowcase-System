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

use function MyShowcase\Admin\pluginActivation;
use function MyShowcase\Admin\pluginDeactivation;
use function MyShowcase\Admin\pluginInformation;
use function MyShowcase\Admin\pluginIsInstalled;
use function MyShowcase\Admin\pluginUninstallation;
use function MyShowcase\Plugin\Functions\hooksAdd;
use function MyShowcase\Plugin\Functions\cacheUpdate;

use const MyShowcase\ROOT;
use const MyShowcase\Plugin\Core\CACHE_TYPE_CONFIG;
use const MyShowcase\Plugin\Core\CACHE_TYPE_FIELD_SETS;
use const MyShowcase\Plugin\Core\CACHE_TYPE_FIELDS;
use const MyShowcase\Plugin\Core\CACHE_TYPE_MODERATORS;
use const MyShowcase\Plugin\Core\CACHE_TYPE_PERMISSIONS;

defined('IN_MYBB') || die('This file cannot be accessed directly.');

// You can uncomment the lines below to avoid storing some settings in the DB
define('MyShowcase\Plugin\Core\SETTINGS', [
    //'key' => '',
    'superModeratorGroups' => '3,4',
    'slugLength' => 5,
    'slugBcryptCost' => 10,
    'ViewsCountSpider' => false,
    'ViewsCountGuests' => true,
    'ViewsCountAuthor' => false,
    'parserMyCodeAffectsLength' => true,
]);

define('MyShowcase\Plugin\Core\DEBUG', true);

define('MyShowcase\ROOT', constant('MYBB_ROOT') . 'inc/plugins/MyShowcase');

//require_once ROOT . '/System/FormatTypes.php';
//require_once ROOT . '/System/FilterTypes.php';
require_once ROOT . '/System/UserPermissions.php';
require_once ROOT . '/System/ModeratorPermissions.php';
require_once ROOT . '/Plugin/Core.php';
require_once ROOT . '/Plugin/Functions.php';

defined('PLUGINLIBRARY') || define('PLUGINLIBRARY', MYBB_ROOT . 'inc/plugins/pluginlibrary.php');

if (defined('IN_ADMINCP')) {
    require_once ROOT . '/admin.php';

    require_once ROOT . '/admin_hooks.php';

    hooksAdd('MyShowcase\Hooks\Admin');
} else {
    require_once ROOT . '/forum_hooks.php';

    hooksAdd('MyShowcase\Hooks\Forum');
}

require_once ROOT . '/Hooks/Shared.php';

hooksAdd('MyShowcase\Hooks\Shared');

function myshowcase_info(): array
{
    return pluginInformation();
}

function myshowcase_activate(): bool
{
    return pluginActivation();
}

function myshowcase_deactivate(): bool
{
    return pluginDeactivation();
}

function myshowcase_is_installed(): bool
{
    return pluginIsInstalled();
}

function myshowcase_uninstall(): bool
{
    return pluginUninstallation();
}

function update_myshowcase_config(): bool
{
    cacheUpdate(CACHE_TYPE_CONFIG);

    return true;
}

function update_myshowcase_fields(): bool
{
    cacheUpdate(CACHE_TYPE_FIELDS);

    return true;
}

function update_myshowcase_fieldsets(): bool
{
    cacheUpdate(CACHE_TYPE_FIELD_SETS);

    return true;
}

function update_myshowcase_moderators(): bool
{
    cacheUpdate(CACHE_TYPE_MODERATORS);

    return true;
}

function update_myshowcase_permissions(): bool
{
    cacheUpdate(CACHE_TYPE_PERMISSIONS);

    return true;
}

global $mybb;

$mybb->binary_fields['myshowcase_attachments_download_logs']['ipaddress'] = true;

$mybb->binary_fields['myshowcase_comments']['ipaddress'] = true;

if (!function_exists('_dump')) {
    function _dump(): never
    {
        echo '<pre>';

        var_dump(func_get_args());

        echo '</pre>';

        exit;
    }
}