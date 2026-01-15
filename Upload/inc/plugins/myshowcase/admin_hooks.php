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

namespace MyShowcase\Hooks\Admin;

use MyBB;
use FormContainer;
use MyShowcase\Enum\CacheDataTypes;
use MyShowcase\Repository\PermissionsRepository;
use MyShowcase\Security\PermissionsFactory;
use MyShowcase\Service\CacheManager;

use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\loadLanguage;
use function MyShowcase\Plugin\Functions\sanitizeTableFieldValue;
use function MyShowcase\Plugin\Functions\showcaseGet;
use function MyShowcase\Admin\buildPermissionsRow;

use const MyShowcase\Plugin\Core\TABLES_DATA;

function admin_config_plugins_deactivate(): bool
{
    global $mybb, $page;

    if (
        $mybb->get_input('action') != 'deactivate' ||
        $mybb->get_input('plugin') != 'myshowcase' ||
        !$mybb->get_input('uninstall', MyBB::INPUT_INT)
    ) {
        return false;
    }

    if ($mybb->request_method != 'post') {
        $page->output_confirm_action(
            'index.php?module=config-plugins&amp;action=deactivate&amp;uninstall=1&amp;plugin=myshowcase'
        );
    }

    if ($mybb->get_input('no')) {
        admin_redirect('index.php?module=config-plugins');
    }

    return true;
}

/**
 * delete default permissions for any new groups.
 */
function admin_user_groups_delete_commit(): bool
{
    global $db, $cache;
    global $usergroup;

    $groupID = (int)$usergroup['gid'];

    foreach (showcaseGet() as $showcaseID => $showcaseData) {
        $permissions_repository = new PermissionsRepository($db, ['showcase_id' => $showcaseID]);

        foreach (
            $permissions_repository->queryPermission(["group_id='{$groupID}'"]) as $permissionID => $permissionData
        ) {
            $permissions_repository->deletePermission($permissionID);
        }
    }

    (new CacheManager($db, $cache))->update(CacheDataTypes::Permissions);

    return true;
}

function report_content_types(array &$contentTypes): array
{
    loadLanguage();

    $contentTypes = array_merge($contentTypes, [
        'showcase_entries',
        'showcase_comments',
    ]);

    return $contentTypes;
}

function admin_user_groups_edit_graph_tabs(array &$tabs): array
{
    global $lang;

    loadLanguage();

    $tabs['myshowcase'] = $lang->MyShowcaseGroupsTab;

    return $tabs;
}

function admin_user_groups_edit_graph(): void
{
    global $db, $lang, $form, $mybb;
    global $usergroup;

    loadLanguage();

    echo '<div id="tab_myshowcase">';

    $formContainer = new FormContainer($lang->MyShowcaseGroupsTab);

    $tables_data = [];

    $hookArguments = [
        'tables_data' => &$tables_data,
    ];

    hooksRun('admin_user_groups_edit_graph_start', $hookArguments);

    $tables_data = array_merge($tables_data, TABLES_DATA);

    $group_id = (int)$usergroup['gid'];

    $permissions_repository = new PermissionsRepository($db);

    $group_permission_data = $permissions_repository->queryPermission(
        ["group_id='{$group_id}'"],
        array_keys(TABLES_DATA['myshowcase_permissions'])
    );

    $permission_input = $mybb->get_input('showcase', MyBB::INPUT_ARRAY);

    $permission_objects = [];

    foreach (PermissionsFactory::$permission_options_map as $permission_name => $permission_definition) {
        if (empty($permission_definition['is_group_permission'])) {
            continue;
        }

        $permission_input[$permission_name] = $group_permission_data[$permission_name] ?? TABLES_DATA['myshowcase_permissions'][$permission_name]['default'];

        $permission_objects[$permission_definition['category']][$permission_name] = $permission_definition;
    }

    foreach ($permission_objects as $category => $permissions) {
        $setting_code = '';

        foreach ($permissions as $permission_name => $permission_definition) {
            $setting_code .= buildPermissionsRow(
                $mybb,
                $form,
                $lang,
                $permission_name,
                $permission_definition,
                '',
                'myshowcase_admin_permissions',
                true,
                $permission_input
            );
        }

        $formContainer->output_row(
            $lang->{'myshowcase_admin_permissions_category_' . $category} ?? $category,
            '',
            $setting_code
        );
    }

    hooksRun('admin_user_groups_edit_graph_end', $hookArguments);

    $formContainer->end();

    echo '</div>';
}

function admin_user_groups_edit_commit(): void
{
    global $mybb, $db;
    global $usergroup;

    $permission_input = $mybb->get_input('showcase', MyBB::INPUT_ARRAY);

    $tables_data = [];

    $hookArguments = [
        'tables_data' => &$tables_data,
    ];

    hooksRun('admin_user_groups_edit_commit_start', $hookArguments);

    $tables_data = array_merge($tables_data, TABLES_DATA);

    $group_id = (int)$usergroup['gid'];

    $insert_data = [
        'group_id' => $group_id,
    ];

    foreach (PermissionsFactory::$permission_options_map as $permission_name => $permission_definition) {
        if (empty($permission_definition['is_group_permission'])) {
            continue;
        }

        if (isset($permission_input[$permission_name])) {
            $insert_data[$permission_name] = sanitizeTableFieldValue(
                $permission_input[$permission_name],
                $tables_data['myshowcase_permissions'][$permission_name]['type']
            );
        }
    }

    $permissions_repository = new PermissionsRepository($db);

    $existing_permission = $permissions_repository->queryPermission(["group_id='{$group_id}'"], 'permission_id');

    if ($existing_permission) {
        $permissions_repository->updatePermission($insert_data, $existing_permission['permission_id']);
    } else {
        $permissions_repository->insertPermission($insert_data);
    }

    (new CacheManager($db, $mybb->cache))->update(CacheDataTypes::Permissions);
}

function admin_config_attachment_types_add_commit(): void
{
    global $mybb, $db;
    global $atid;

    $atid = (int)$atid;

    if (!is_array($mybb->input['myshowcase_ids'])) {
        $mybb->input['myshowcase_ids'] = explode(',', $mybb->input['myshowcase_ids']);
    }

    $db->update_query(
        'attachtypes',
        [
            'myshowcase_ids' => $db->escape_string(
                implode(',', array_map('intval', $mybb->input['myshowcase_ids']))
            ),
            'myshowcase_image_minimum_dimensions' => $db->escape_string(
                $mybb->input['myshowcase_image_minimum_dimensions']
            ),
            'myshowcase_image_maximum_dimensions' => $db->escape_string(
                $mybb->input['myshowcase_image_maximum_dimensions']
            ),
        ],
        "atid='{$atid}'"
    );
}

function admin_config_attachment_types_edit_commit20(): void
{
    global $mybb, $db;
    global $updated_type, $attachment_type;

    if (!is_array($mybb->input['myshowcase_ids'])) {
        $mybb->input['myshowcase_ids'] = explode(',', $mybb->input['myshowcase_ids']);
    }

    $updated_type['myshowcase_ids'] = $db->escape_string(
        implode(',', array_map('intval', $mybb->input['myshowcase_ids']))
    );

    $updated_type['myshowcase_image_minimum_dimensions'] = $db->escape_string(
        $mybb->input['myshowcase_image_minimum_dimensions']
    );

    $updated_type['myshowcase_image_maximum_dimensions'] = $db->escape_string(
        $mybb->input['myshowcase_image_maximum_dimensions']
    );

    $db->update_query('attachtypes', $updated_type, "atid='{$attachment_type['atid']}'");

    (new CacheManager($db, $mybb->cache))->update(CacheDataTypes::AttachmentTypes);
}

function admin_config_attachment_types_delete_commit(): void
{
    global $db, $cache;

    (new CacheManager($db, $cache))->update(CacheDataTypes::AttachmentTypes);
}

function admin_formcontainer_output_row(array &$hookArguments): array
{
    global $lang;

    if (!isset($lang->avatar_file) || !isset($lang->avatar_file_desc) || $hookArguments['label_for'] !== 'avatarfile') {
        return $hookArguments;
    }

    global $mybb;
    global $form;

    loadLanguage();

    $selectOptions = array_map(function ($showcaseData) {
        return htmlspecialchars_uni($showcaseData['name']);
    }, showcaseGet(queryFields: ['name']));

    $hookArguments['this']->output_row(
        $lang->MyShowcaseAttachmentTypesAllowedShowcases,
        $lang->MyShowcaseAttachmentTypesAllowedShowcasesDescription,
        $form->generate_select_box(
            'myshowcase_ids[]',
            $selectOptions,
            isset($mybb->input['myshowcase_ids']) ? is_array(
                $mybb->input['myshowcase_ids']
            ) ? $mybb->input['myshowcase_ids'] : explode(
                ',',
                $mybb->get_input('myshowcase_ids')
            ) : [],
            ['multiple' => 5]
        ),
        'myshowcase_ids'
    );

    $hookArguments['this']->output_row(
        $lang->MyShowcaseAttachmentTypesMinimumImageDimensions,
        $lang->MyShowcaseAttachmentTypesMinimumImageDimensionsDescription,
        $form->generate_text_box(
            'myshowcase_image_minimum_dimensions',
            $mybb->get_input('myshowcase_image_minimum_dimensions')
        ),
        'myshowcase_image_minimum_dimensions'
    );

    $hookArguments['this']->output_row(
        $lang->MyShowcaseAttachmentTypesMaximumImageDimensions,
        $lang->MyShowcaseAttachmentTypesMaximumImageDimensionsDescription,
        $form->generate_text_box(
            'myshowcase_image_maximum_dimensions',
            $mybb->get_input('myshowcase_image_maximum_dimensions')
        ),
        'myshowcase_image_maximum_dimensions'
    );

    return $hookArguments;
}