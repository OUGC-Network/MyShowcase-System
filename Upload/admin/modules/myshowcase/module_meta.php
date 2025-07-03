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

use function MyShowcase\Plugin\Functions\hooksRun;

if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.');
}

function myshowcase_meta(): bool
{
    global $page, $lang;

    $displayOrder = 0;

    $sub_menu = [];

    $sub_menu[$displayOrder += 10] = [
        'id' => 'summary',
        'title' => $lang->myshowcase_admin_summary,
        'link' => 'index.php?module=myshowcase-summary'
    ];

    $sub_menu[$displayOrder += 10] = [
        'id' => 'fields',
        'title' => $lang->myshowcase_admin_fields,
        'link' => 'index.php?module=myshowcase-fields'
    ];

    $sub_menu[$displayOrder += 10] = [
        'id' => 'help',
        'title' => $lang->myshowcase_admin_help,
        'link' => 'index.php?module=myshowcase-help'
    ];

    /*
    if (function_exists('MyShowcase\Plugin\Core\hooksRun')) {
        $sub_menu = hooksRun('admin_module_meta_start', $sub_menu);

        foreach (showcaseGet([], ['name']) as $showcaseID => $showcaseData) {
            $sub_menu[$displayOrder += 10] = [
                'id' => "showcase{$showcaseID}",
                'title' => $showcaseData['name'],
                'link' => urlHandlerBuild(['action' => 'edit', 'showcase_id' => $showcaseID], '&')
            ];
        }

        $sub_menu = hooksRun('admin_module_meta_end', $sub_menu);
    }*/

    $page->add_menu_item(
        $lang->myshowcase_admin_myshowcase,
        'myshowcase',
        'index.php?module=myshowcase',
        60,
        $sub_menu
    );

    return true;
}

function myshowcase_action_handler(string $action): string
{
    global $page;

    $page->active_module = 'myshowcase';

    $actions = [
        'summary' => ['active' => 'summary', 'file' => 'summary.php'],
        'new' => ['active' => 'new', 'file' => 'summary.php'],
        'fields' => ['active' => 'fields', 'file' => 'fields.php'],
        'help' => ['active' => 'help', 'file' => 'help.php']
    ];

    $actions = hooksRun('admin_action_handler', $actions);

    if (isset($actions[$action])) {
        $page->active_action = $actions[$action]['active'];

        return $actions[$action]['file'];
    } else {
        $page->active_action = 'summary';

        return 'summary.php';
    }
}

function myshowcase_admin_permissions(): array
{
    global $lang;

    $admin_permissions = [
        'summary' => $lang->myshowcase_admin_perm_summary,
        'new' => $lang->myshowcase_admin_perm_new,
        'edit' => $lang->myshowcase_admin_perm_edit,
        'fields' => $lang->myshowcase_admin_perm_fields,
        'cache' => $lang->myshowcase_admin_perm_cache,
        'help' => $lang->myshowcase_admin_perm_help,
    ];

    $admin_permissions = hooksRun('admin_permissions', $admin_permissions);

    return ['name' => $lang->myshowcase_admin_myshowcase, 'permissions' => $admin_permissions, 'display_order' => 60];
}

//todo review hooks here