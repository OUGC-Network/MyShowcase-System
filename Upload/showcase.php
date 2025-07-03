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

use MyShowcase\Plugin\RouterUrls;
use Pecee\Http\Middleware\Exceptions\TokenMismatchException;
use Pecee\SimpleRouter\Exceptions\NotFoundHttpException;
use Pecee\SimpleRouter\SimpleRouter;

use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\renderGetObject;
use function MyShowcase\Plugin\Functions\showcaseGetObjectByScriptName;

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

if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/attachment/') ||
    str_contains($_SERVER['REQUEST_URI'] ?? '', '/thumbnail/')) {
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
        @mb_internal_encoding($lang->settings['charset']);
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

    $closed_bypass = ['refresh_captcha', 'validate_captcha'];
} else {
    $minimalLoad = false;

    require_once $change_dir . '/global.php';
}

hooksRun('script_file_start');

//change directory back to current where script is
chdir($currentWorkingDirectoryPath);

require_once ROOT . '/Controllers/Base.php';
require_once ROOT . '/vendor/autoload.php'; // router
require_once ROOT . '/vendor/pecee/simple-router/helpers.php';

$showcaseObject = showcaseGetObjectByScriptName(THIS_SCRIPT);

$requestBaseUriExtra = '';
/*
switch ($showcaseObject->config['filter_force_field']) {
    case FILTER_TYPE_USER_ID:
        $requestBaseUriExtra = '/user/{user_id}';

        break;
    default:
        break;
}
*/
if ($minimalLoad) {
    require_once ROOT . '/Controllers/Attachments.php';
} else {
    require_once ROOT . '/Controllers/Entries.php';
    require_once ROOT . '/Controllers/Comments.php';
}

hooksRun('script_file_intermediate');

foreach (
    [
        $showcaseObject->selfPhpScript . $requestBaseUriExtra . '/',
        $showcaseObject->selfPhpScript . $requestBaseUriExtra . '/limit/{limit}',
        $showcaseObject->selfPhpScript . $requestBaseUriExtra . '/limit/{limit}/start/{start}',
    ] as $route
) {
    SimpleRouter::get(
        $route,
        ['MyShowcase\Controllers\Entries', 'mainView']
    )->name(RouterUrls::Main);
}
/*
SimpleRouter::all($showcaseObject->selfPhpScript . $requestBaseUriExtra . '/abc/123', function ($param1, $param2) {
    // param1 = abc
    // param2 = 123
    _dump($param1, $param2);
})->setMatch('/\/([\w]+)\/?([0-9]+)?\/?/is');
*/
SimpleRouter::get(
    $showcaseObject->selfPhpScript . $requestBaseUriExtra . '/unapproved',
    ['MyShowcase\Controllers\Entries', 'mainUnapproved']
)->name(RouterUrls::MainUnapproved);

SimpleRouter::form(
    $showcaseObject->selfPhpScript . $requestBaseUriExtra . '/search',
    ['MyShowcase\Controllers\Search', 'mainSearch']
)->name(RouterUrls::Search);

SimpleRouter::get(
    $showcaseObject->selfPhpScript . $requestBaseUriExtra . '/search/{search_hash}',
    ['MyShowcase\Controllers\Search', 'mainSearch']
)->name(RouterUrls::Search);

SimpleRouter::get(
    $showcaseObject->selfPhpScript . '/user/{user_id}',
    ['MyShowcase\Controllers\Entries', 'mainUser']
)->name(RouterUrls::MainUser)->where(['id' => '[0-9]+']);

SimpleRouter::get(
    $showcaseObject->selfPhpScript . $requestBaseUriExtra . '/page/{page_id}',
    ['MyShowcase\Controllers\Entries', 'mainPage']
)->name(RouterUrls::MainPage);

SimpleRouter::post(
    $showcaseObject->selfPhpScript . '/approve',
    ['MyShowcase\Controllers\Entries', 'approveEntries']
)->name(RouterUrls::MainApprove);

SimpleRouter::post(
    $showcaseObject->selfPhpScript . '/unapprove',
    ['MyShowcase\Controllers\Entries', 'unapproveEntries']
)->name(RouterUrls::MainUnapprove);

SimpleRouter::post(
    $showcaseObject->selfPhpScript . '/soft_delete',
    ['MyShowcase\Controllers\Entries', 'softDeleteEntries']
)->name(RouterUrls::MainSoftDelete);

SimpleRouter::post(
    $showcaseObject->selfPhpScript . '/restore',
    ['MyShowcase\Controllers\Entries', 'restoreEntries']
)->name(RouterUrls::MainRestore);

SimpleRouter::post(
    $showcaseObject->selfPhpScript . '/delete',
    ['MyShowcase\Controllers\Entries', 'deleteEntries']
)->name(RouterUrls::MainDelete);

SimpleRouter::form(
    $showcaseObject->selfPhpScript . '/create',
    ['MyShowcase\Controllers\Entries', 'createEntry']
)->name(RouterUrls::EntryCreate);

SimpleRouter::get(
    $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}',
    ['MyShowcase\Controllers\Entries', 'viewEntry']
)->name(RouterUrls::EntryView);

SimpleRouter::form(
    $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/update',
    ['MyShowcase\Controllers\Entries', 'updateEntry']
)->name(RouterUrls::EntryUpdate);

SimpleRouter::get(
    $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/page/{page_id}',
    ['MyShowcase\Controllers\Entries', 'viewEntry']
)->name(RouterUrls::EntryViewPage);

SimpleRouter::post(
    $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/approve',
    ['MyShowcase\Controllers\Entries', 'approveEntry']
)->name(RouterUrls::EntryApprove);

SimpleRouter::post(
    $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/unapprove',
    ['MyShowcase\Controllers\Entries', 'unapproveEntry']
)->name(RouterUrls::EntryUnapprove);

SimpleRouter::post(
    $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/soft_delete',
    ['MyShowcase\Controllers\Entries', 'softDeleteEntry']
)->name(RouterUrls::EntrySoftDelete);

SimpleRouter::post(
    $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/restore',
    ['MyShowcase\Controllers\Entries', 'restoreEntry']
)->name(RouterUrls::EntryRestore);

SimpleRouter::post(
    $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/delete',
    ['MyShowcase\Controllers\Entries', 'deleteEntry']
)->name(RouterUrls::EntryDelete);

SimpleRouter::form(
    $showcaseObject->selfPhpScript . '/comment/{comment_slug}',
    ['MyShowcase\Controllers\Comments', 'redirect']
)->name(RouterUrls::Comment);

SimpleRouter::form(
    $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/comment',
    ['MyShowcase\Controllers\Comments', 'create']
)->name(RouterUrls::CommentCreate);

SimpleRouter::get(
    $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/comment/{comment_slug}',
    ['MyShowcase\Controllers\Comments', 'view']
)->name(RouterUrls::CommentView);

SimpleRouter::form(
    $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/comment/{comment_slug}/update',
    ['MyShowcase\Controllers\Comments', 'update']
)->name(RouterUrls::CommentUpdate);

SimpleRouter::post(
    $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/comment/{comment_slug}/approve',
    ['MyShowcase\Controllers\Comments', 'approve']
)->name(RouterUrls::CommentApprove);

SimpleRouter::post(
    $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/comment/{comment_slug}/unapprove',
    ['MyShowcase\Controllers\Comments', 'unapprove']
)->name(RouterUrls::CommentUnapprove);

SimpleRouter::post(
    $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/comment/{comment_slug}/soft_delete',
    ['MyShowcase\Controllers\Comments', 'softDelete']
)->name(RouterUrls::CommentSoftDelete);

SimpleRouter::post(
    $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/comment/{comment_slug}/restore',
    ['MyShowcase\Controllers\Comments', 'restore']
)->name(RouterUrls::CommentRestore);

SimpleRouter::post(
    $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/comment/{comment_slug}/delete',
    ['MyShowcase\Controllers\Comments', 'delete']
)->name(RouterUrls::CommentDelete);

SimpleRouter::get(
    $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/attachment/{attachment_hash}',
    ['MyShowcase\Controllers\Attachments', 'downloadAttachment']
)->name(RouterUrls::AttachmentView);

SimpleRouter::get(
    $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/thumbnail/{attachment_hash}',
    ['MyShowcase\Controllers\Attachments', 'viewThumbnail']
)->name(RouterUrls::ThumbnailView);

hooksRun('script_file_end');

try {
    SimpleRouter::start();
} catch (TokenMismatchException|NotFoundHttpException|\Pecee\SimpleRouter\Exceptions\HttpException|Exception $e) {
    error($e->getMessage());
}

exit;