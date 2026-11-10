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

use JetBrains\PhpStorm\NoReturn;
use MyShowcase\Enum\CacheDataTypes;
use MyShowcase\Service\CacheManager;

use function MyShowcase\Admin\pluginActivation;
use function MyShowcase\Admin\pluginDeactivation;
use function MyShowcase\Admin\pluginInformation;
use function MyShowcase\Admin\pluginIsInstalled;
use function MyShowcase\Admin\pluginUninstallation;
use function MyShowcase\Plugin\Functions\hooksAdd;

use const MyShowcase\ROOT;

if (!function_exists('_d')) {
    function _d(): never
    {
        echo '<pre>';
        debug_print_backtrace();
        var_dump(func_get_args());
        echo '</pre>';
        exit;
    }

    function _d2(): void
    {
        echo '<pre>';
        debug_print_backtrace();
        var_dump(func_get_args());
        echo '</pre>';
    }
}

defined('IN_MYBB') || die('This file cannot be accessed directly.');

// You can uncomment the lines below to avoid storing some settings in the DB
define('MyShowcase\SETTINGS', [
    //'key' => '',
    'superModeratorGroups' => '3,4',
    'slugLength' => 10,
    'slugBcryptCost' => 10,
    'ViewsCountSpider' => false,
    'ViewsCountGuests' => true,
    'ViewsCountAuthor' => false,
    'parserMyCodeAffectsLength' => true,
]);

define('MyShowcase\DEBUG', true);

define('MyShowcase\ROOT', constant('MYBB_ROOT') . 'inc/plugins/myshowcase');

define('MyShowcase\VERSION', '3.0.0');

define('MyShowcase\VERSION_CODE', 3000);

/**
 * PSR-4 Autoloader for the /src/ directory.
 *
 * @param string $class The fully-qualified class name.
 * @return bool True if the class was loaded, false otherwise.
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'MyShowcase\\';

    $baseDir = ROOT . '/src/';

    $len = strlen($prefix);

    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);

    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

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

require_once ROOT . '/vendor/autoload.php';

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
    global $db, $cache;

    (new CacheManager($db, $cache))->update(CacheDataTypes::Config);

    return true;
}

function update_myshowcase_fields(): bool
{
    global $db, $cache;

    (new CacheManager($db, $cache))->update(CacheDataTypes::Fields);

    return true;
}

function update_myshowcase_collections(): bool
{
    global $db, $cache;

    (new CacheManager($db, $cache))->update(CacheDataTypes::Collections);

    return true;
}

function update_myshowcase_moderators(): bool
{
    global $db, $cache;

    (new CacheManager($db, $cache))->update(CacheDataTypes::Moderators);

    return true;
}

function update_myshowcase_permissions(): bool
{
    global $db, $cache;

    (new CacheManager($db, $cache))->update(CacheDataTypes::Permissions);

    return true;
}

global $mybb;

$mybb->binary_fields['myshowcase_attachments_download_logs']['ipaddress'] = true;

$mybb->binary_fields['myshowcase_comments']['ipaddress'] = true;