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

use MyShowcase\Enum\UserPermissions;
use MyShowcase\Enum\Routes;

use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\showcaseGetObjectByScriptName;

use const MyShowcase\Plugin\Core\DEBUG;
use const MyShowcase\ROOT;

/*
 * Only user edits required
*/

$forumDirectoryPath = ''; //no trailing slash

/*
 * Stop editing
*/

const IN_MYBB = true;

const IN_SHOWCASE = true;

const SHOWCASE_FILE_VERSION_CODE = 3000;

define('THIS_SCRIPT', substr($_SERVER['SCRIPT_NAME'], -mb_strpos(strrev($_SERVER['SCRIPT_NAME']), '/')));

$currentWorkingDirectoryPath = getcwd();

$change_dir = './';

if (!chdir($forumDirectoryPath) && !empty($forumDirectoryPath)) {
    if (is_dir($forumDirectoryPath)) {
        $change_dir = $forumDirectoryPath;
    } else {
        exit("{$forumDirectoryPath} is invalid!");
    }
}

//change working directory to allow board includes to work
$forumDirectoryPathTrailing = ($forumDirectoryPath === '' ? '' : $forumDirectoryPath . '/');

$templatelist = '';

$request_uri = $_SERVER['REQUEST_URI'] ?? '';

if (str_contains($request_uri, '/attachment/') ||
    str_contains($request_uri, '/thumbnail/') ||
    str_contains($request_uri, '/attachment?') ||
    str_contains($request_uri, '/files?') ||
    str_contains($request_uri, '/upload?')) {
    define('NO_ONLINE', 1);

    global $mybb, $lang, $db, $cache, $templates;

    $minimalLoad = true;

    require_once $change_dir . '/inc/init.php';

    $shutdown_queries = $shutdown_functions = [];

    header('Expires: Sat, 1 Jan 2000 01:00:00 GMT');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');

    require_once MYBB_ROOT . 'inc/class_session.php';

    $session = new session();

    $session->init();

    $mybb->session = &$session;

    if (!isset($mybb->settings['bblanguage'])) {
        $mybb->settings['bblanguage'] = 'english';
    }
    if (isset($mybb->user['language']) && $lang->language_exists($mybb->user['language'])) {
        $mybb->settings['bblanguage'] = $mybb->user['language'];
    }

    $lang->set_language($mybb->settings['bblanguage']);

    if (function_exists('mb_internal_encoding') && !empty($lang->settings['charset'])) {
        mb_internal_encoding($lang->settings['charset']);
    }

    $templateList = '';

    if ($templateList) {
        $templates->cache($db->escape_string($templateList));
    }

    if ($lang->settings['charset']) {
        $charset = $lang->settings['charset'];
    } else {
        $charset = 'UTF-8';
    }

    $lang->load('global');

    $lang->load('messages');

    $closed_bypass = ['refresh_captcha', 'validate_captcha'];
} else {
    $minimalLoad = false;

    require_once $change_dir . '/global.php';
}

function_exists('MyShowcase\Plugin\Functions\hooksRun') || error();

hooksRun('script_file_start');

//change directory back to current where script is
chdir($currentWorkingDirectoryPath);

require_once ROOT . '/Controllers/Base.php';
require_once ROOT . '/vendor/autoload.php';

if ($minimalLoad) {
    require_once ROOT . '/Controllers/Attachments.php';
} else {
    require_once ROOT . '/Controllers/Entries.php';
    require_once ROOT . '/Controllers/Comments.php';
}

hooksRun('script_file_intermediate');

$showcaseObject = showcaseGetObjectByScriptName(THIS_SCRIPT);

if (!$showcaseObject->user_permissions[UserPermissions::CanView]) {
    error_no_permission();
}

hooksRun('script_file_end');

try {
    $parameters = $showcaseObject->routing->getMatch();

    $controller = $parameters['_controller'];

    unset($parameters['_controller'], $parameters['_route']);

    $controller($parameters);
} catch (Exception $e) {
    error(DEBUG ? $e->getMessage() . "<pre>{$e->getTraceAsString()}</pre>" : '');
}

exit;