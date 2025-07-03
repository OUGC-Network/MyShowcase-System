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

use function MyShowcase\Plugin\Functions\cacheUpdate;
use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\loadLanguage;
use function MyShowcase\Plugin\Functions\permissionsDelete;
use function MyShowcase\Plugin\Functions\permissionsGet;
use function MyShowcase\Plugin\Functions\sanitizeTableFieldValue;
use function MyShowcase\Plugin\Functions\showcaseGet;
use function MyShowcase\Admin\buildPermissionsRow;

use const MyShowcase\Plugin\Core\CACHE_TYPE_ATTACHMENT_TYPES;
use const MyShowcase\Plugin\Core\CACHE_TYPE_PERMISSIONS;
use const MyShowcase\Plugin\Core\FIELDS_DATA;
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
    global $usergroup;

    foreach (permissionsGet(["group_id='{$usergroup['gid']}'"]) as $permissionID => $permissionData) {
        permissionsDelete($permissionID);
    }

    cacheUpdate(CACHE_TYPE_PERMISSIONS);

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

function admin_user_groups_edit_graph(): bool
{
    global $lang, $form, $mybb;

    loadLanguage();
    loadLanguage('myshowcase_summary');

    $tablesData = TABLES_DATA;

    $dataFields = FIELDS_DATA['usergroups'];

    echo '<div id="tab_myshowcase">';

    $formContainer = new FormContainer($lang->MyShowcaseGroupsTab);

    $formFields = $formFieldsRate = $formFieldsIncome = [];

    $hookArguments = [
        'tablesData' => &$tablesData,
        'dataFields' => &$dataFields,
        'formFields' => &$formFields,
        'formFieldsRate' => &$formFieldsRate,
        'formFieldsIncome' => &$formFieldsIncome
    ];

    $hookArguments = hooksRun('admin_user_groups_edit_graph_start', $hookArguments);

    $rowObjects = [];

    foreach ($tablesData['myshowcase_permissions'] as $fieldName => $fieldData) {
        if (!isset($fieldData['isPermission'])) {
            continue;
        }

        //$sectionKey = ucfirst($formSection);
        //formSection

        //$key = str_replace(' ', '', ucwords(str_replace('_', ' ', $fieldName)));

        //$rowObjects[$fieldData['formCategory']][$fieldName] = $lang->{'myShowcaseAdminSummaryPermissionsField' . $key};

        $rowObjects[$fieldData['formCategory']][$fieldName] = $fieldData;
    }

    foreach ($rowObjects as $formSection => $fieldObjects) {
        $sectionKey = ucfirst($formSection);

        $settingCode = '';

        foreach ($fieldObjects as $fieldName => $fieldData) {
            $key = str_replace(' ', '', ucwords(str_replace('_', ' ', $fieldName)));

            $settingCode .= buildPermissionsRow(
                $form,
                'myshowcase_' . $fieldName,
                $fieldData,
                $key,
                '',
                true,
                'myShowcaseAdminSummaryPermissionsField'
            );
        }

        $formContainer->output_row(
            $lang->{'myShowcaseAdminSummaryPermissionsFieldGroup' . $sectionKey},
            '',
            $settingCode
        );
    }


    $hookArguments = hooksRun('admin_user_groups_edit_graph_end', $hookArguments);

    $formContainer->end();

    echo '</div>';

    return true;
}

function admin_user_groups_edit_commit(): bool
{
    global $mybb, $db;
    global $updated_group;

    $dataFields = TABLES_DATA['myshowcase_permissions'];

    $hook_arguments = [
        'dataFields' => &$dataFields,
    ];

    $hook_arguments = hooksRun('admin_user_groups_edit_commit_start', $hook_arguments);

    foreach ($dataFields as $dataFieldKey => $dataFieldData) {
        if (!isset($dataFieldData['isPermission'])) {
            continue;
        }

        if (isset($mybb->input['myshowcase_' . $dataFieldKey])) {
            $updated_group['myshowcase_' . $dataFieldKey] = sanitizeTableFieldValue(
                $mybb->get_input('myshowcase_' . $dataFieldKey),
                $dataFieldData['type']
            );
        } else {
            $updated_group['myshowcase_' . $dataFieldKey] = $dataFieldData['default'];
        }
    }

    return true;
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

    cacheUpdate(CACHE_TYPE_ATTACHMENT_TYPES);
}

function admin_config_attachment_types_delete_commit(): void
{
    cacheUpdate(CACHE_TYPE_ATTACHMENT_TYPES);
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