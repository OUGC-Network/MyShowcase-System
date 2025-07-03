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

use MyShowcase\Plugin\FormTypes;
use MyShowcase\System\FieldTypes;
use MyShowcase\System\ModeratorPermissions;

use function MyShowcase\Admin\buildPermissionsRow;
use function MyShowcase\Admin\dbVerifyTables;
use function MyShowcase\Plugin\Functions\attachmentGet;
use function MyShowcase\Plugin\Functions\cacheGet;
use function MyShowcase\Plugin\Functions\cacheUpdate;
use function MyShowcase\Plugin\Functions\castTableFieldValue;
use function MyShowcase\Plugin\Functions\commentsGet;
use function MyShowcase\Plugin\Functions\dataTableStructureGet;
use function MyShowcase\Plugin\Functions\fieldsetGet;
use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\loadLanguage;
use function MyShowcase\Plugin\Functions\moderatorGet;
use function MyShowcase\Plugin\Functions\moderatorsDelete;
use function MyShowcase\Plugin\Functions\moderatorsInsert;
use function MyShowcase\Plugin\Functions\moderatorsUpdate;
use function MyShowcase\Plugin\Functions\permissionsDelete;
use function MyShowcase\Plugin\Functions\permissionsGet;
use function MyShowcase\Plugin\Functions\permissionsInsert;
use function MyShowcase\Plugin\Functions\permissionsUpdate;
use function MyShowcase\Plugin\Functions\showcaseDataTableDrop;
use function MyShowcase\Plugin\Functions\showcaseDataTableExists;
use function MyShowcase\Plugin\Functions\showcaseDelete;
use function MyShowcase\Plugin\Functions\showcaseGet;
use function MyShowcase\Plugin\Functions\entryGet;
use function MyShowcase\Plugin\Functions\showcaseInsert;
use function MyShowcase\Plugin\Functions\showcaseUpdate;
use function MyShowcase\Plugin\Functions\urlHandlerBuild;

use const MyShowcase\Plugin\Functions\TABLES_DATA;
use const MyShowcase\Plugin\Functions\CACHE_TYPE_CONFIG;
use const MyShowcase\Plugin\Functions\CACHE_TYPE_FIELD_SETS;
use const MyShowcase\Plugin\Functions\CACHE_TYPE_MODERATORS;
use const MyShowcase\Plugin\Functions\CACHE_TYPE_PERMISSIONS;
use const MyShowcase\Plugin\Functions\MODERATOR_TYPE_GROUP;
use const MyShowcase\Plugin\Functions\MODERATOR_TYPE_USER;
use const MyShowcase\Plugin\Functions\SHOWCASE_STATUS_DISABLED;
use const MyShowcase\Plugin\Functions\SHOWCASE_STATUS_ENABLED;

if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.');
}

global $lang, $cache, $db, $plugins, $mybb;
global $page;

loadLanguage();

$tablesData = TABLES_DATA;

$page->add_breadcrumb_item($lang->myShowcaseSystem, urlHandlerBuild());

$page->add_breadcrumb_item($lang->myShowcaseAdminSummary, urlHandlerBuild());

$showcaseID = $mybb->get_input('showcaseID', MyBB::INPUT_INT);

$pageTabs = [
    'myShowcaseAdminSummary' => [
        'title' => $lang->myShowcaseAdminSummary,
        'link' => urlHandlerBuild(),
        'description' => $lang->myShowcaseAdminSummaryDescription
    ],
    'myShowcaseAdminSummaryNew' => [
        'title' => $lang->myShowcaseAdminSummaryNew,
        'link' => urlHandlerBuild(['action' => 'add']),
        'description' => $lang->myShowcaseAdminSummaryNewDescription
    ],
];

if ($mybb->get_input('action') == 'edit') {
    $pageTabs['myShowcaseAdminSummaryEdit'] = [
        'title' => $lang->myShowcaseAdminSummaryEdit,
        'link' => urlHandlerBuild(['action' => 'edit', 'showcaseID' => $showcaseID]),
        'description' => $lang->myShowcaseAdminSummaryEditDescription
    ];
}

$groupsCache = $cache->read('usergroups') ?? [];

$fieldsetObjects = (function (): array {
    return array_map(function ($fieldsetData) {
        return $fieldsetData['set_name'];
    }, cacheGet(CACHE_TYPE_FIELD_SETS));
})();

$cachedPermissions = cacheGet(CACHE_TYPE_PERMISSIONS);

hooksRun('admin_summary_start');

$page->extra_header .= <<<EOL
<style type="text/css">
    .user_settings_bit label {
        font-weight: normal;
    }
</style>
EOL;

if ($mybb->input['action'] == 'clear_permission') {
    $showcaseID = $mybb->get_input('showcaseID', MyBB::INPUT_INT);
    $permissionID = $mybb->get_input('permissionID', MyBB::INPUT_INT);

    $permissionData = permissionsGet(
        ["showcase_id='{$showcaseID}'", "permission_id='{$permissionID}'"]
    );

    if (!empty($mybb->input['no']) || !$permissionData) {
        admin_redirect(
            urlHandlerBuild(
                ['action' => 'edit', 'type' => 'permissions', 'showcaseID' => $showcaseID]
            ) . '#tab_permissions'
        );
    }

    $plugins->run_hooks('admin_forum_management_clear_permission');

    if ($mybb->request_method === 'post') {
        permissionsDelete($permissionID);

        hooksRun('admin_summary_clear_permissions_post');

        cacheUpdate(CACHE_TYPE_PERMISSIONS);

        flash_message($lang->myShowcaseAdminSummaryPermissionsClearSuccess, 'success');

        admin_redirect(
            urlHandlerBuild(
                ['action' => 'edit', 'type' => 'permissions', 'showcaseID' => $showcaseID]
            ) . '#tab_permissions'
        );
    } else {
        $page->output_confirm_action(
            urlHandlerBuild(
                [
                    'action' => 'clear_permission',
                    'showcaseID' => $showcaseID,
                    'permissionID' => $permissionID,
                    'my_post_key' => $mybb->post_code
                ]
            ),
            $lang->myShowcaseAdminSummaryPermissionsClearConfirm
        );
    }
} elseif ($mybb->input['action'] == 'permissions') {
    $showcaseData = showcaseGet(
        ["showcase_id='{$showcaseID}'"],
        array_keys($tablesData['myshowcase_config']),
        ['limit' => 1]
    );

    if (!$showcaseData) {
        flash_message($lang->myShowcaseAdminErrorInvalidShowcase, 'error');

        admin_redirect($pageTabs['myShowcaseAdminSummary']['link']);
    }

    $groupID = $mybb->get_input('groupID', MyBB::INPUT_INT);

    $permissionID = $mybb->get_input('permissionID', MyBB::INPUT_INT);

    $whereClauses = ["showcase_id='{$showcaseID}'"];

    if ($groupID) {
        $whereClauses[] = "group_id='{$groupID}'";
    }

    if ($permissionID) {
        $whereClauses[] = "permission_id='{$permissionID}'";
    }

    $plugins->run_hooks('admin_forum_management_permissions');

    $permission_data = permissionsGet(
        $whereClauses,
        array_keys($tablesData['myshowcase_permissions']),
        ['limit' => 1]
    );

    if (!$groupID && !empty($permission_data['showcase_id'])) {
        $permissionID = $permission_data['permission_id'];

        $showcaseID = $permission_data['showcase_id'];

        $groupID = $permission_data['group_id'];
    }

    $inputPermissions = $mybb->get_input('permissions', MyBB::INPUT_ARRAY);

    $showcasePermissionsUrl = urlHandlerBuild(
            ['action' => 'permissions', 'showcaseID' => $showcaseID]
        ) . '#tab_permissions';

    $isModal = $mybb->get_input('ajax', MyBB::INPUT_INT);

    if ($mybb->request_method === 'post') {
        $insertData = $field_list = [];

        foreach ($tablesData['myshowcase_permissions'] as $fieldName => $fieldData) {
            if (!isset($fieldData['isPermission'])) {
                continue;
            }

            if (isset($inputPermissions[$fieldName])) {
                $insertData[$fieldName] = castTableFieldValue(
                    $inputPermissions[$fieldName],
                    $fieldData['type']
                );
            } else {
                $insertData[$fieldName] = $fieldData['default'];
            }
        }

        if (!$permissionID) {
            $insertData['showcase_id'] = $showcaseID;

            $insertData['group_id'] = $groupID;
        }

        $inputPermissions = $mybb->get_input('permissions', MyBB::INPUT_ARRAY);

        $plugins->run_hooks('admin_forum_management_permissions_commit');

        if ($permissionID) {
            permissionsUpdate($showcaseID, $insertData, $permissionID);
        } else {
            permissionsInsert($showcaseID, $insertData);
        }

        cacheUpdate(CACHE_TYPE_PERMISSIONS);

        log_admin_action($showcaseID, $showcaseData['name']);

        if ($isModal) {
            echo json_encode(
                "<script type=\"text/javascript\">$('#row_{$groupID}').html('" . str_replace(["'", "\t", "\n"],
                    ["\\'", '', ''],
                    retrieveSinglePermissionsRow($groupID, $showcaseID)
                ) . "'); QuickPermEditor.init({$groupID});</script>"
            );

            die;
        } else {
            flash_message($lang->myShowcaseAdminSummaryPermissionsFormCustomPermissionsSuccess, 'success');

            admin_redirect($showcasePermissionsUrl);
        }
    }

    if (!$isModal) {
        $sub_tabs = [];

        $page->add_breadcrumb_item(
            $lang->myShowcaseAdminSummaryPermissionsFormPermissions,
            $showcasePermissionsUrl
        );

        $permissionsUrl = urlHandlerBuild(
            [
                'action' => 'permissions',
                'showcaseID' => $showcaseID,
                'permissionID' => $permissionID,
                'groupID' => $groupID
            ]
        );

        $sub_tabs['edit_permissions'] = [
            'title' => $lang->myShowcaseAdminSummaryPermissionsFormCustomPermissions,
            'link' => $permissionsUrl,
            'description' => $lang->myShowcaseAdminSummaryPermissionsFormCustomPermissionsDescription
        ];

        $page->add_breadcrumb_item($lang->myShowcaseAdminSummaryPermissionsFormCustomPermissions);

        $page->output_header($lang->myShowcaseAdminSummaryPermissionsFormCustomPermissions);

        $page->output_nav_tabs($sub_tabs, 'edit_permissions');
    } else {
        echo "
		<div class=\"modal\" style=\"width: auto\">
		<script src=\"jscripts/tabs.js\" type=\"text/javascript\"></script>\n
		<script type=\"text/javascript\">
<!--
$(function() {
	$(\"#modal_form\").on(\"click\", \"#savePermissions\", function(e) {
		e.preventDefault();

		var datastring = $(\"#modal_form\").serialize();
		$.ajax({
			type: \"POST\",
			url: $(\"#modal_form\").attr('action'),
			data: datastring,
			dataType: \"json\",
			success: function(data) {
				$(data).filter(\"script\").each(function(e) {
					eval($(this).text());
				});
				$.modal.close();
			},
			error: function(){
			}
		});
	});
});
// -->
		</script>
		<div style=\"overflow-y: auto; max-height: 400px\">";
    }

    if (!empty($mybb->input['permissionID']) || (!empty($mybb->input['groupID']) && !empty($mybb->input['showcaseID']))) {
        if (!$isModal) {
            $permissionsUrl = urlHandlerBuild(
                [
                    'action' => 'permissions',
                    'showcaseID' => $showcaseID,
                ]
            );

            $form = new Form($permissionsUrl, 'post');
        } else {
            $permissionsUrl = urlHandlerBuild(
                [
                    'action' => 'permissions',
                    'showcaseID' => $showcaseID,
                    'permissionID' => $permissionID,
                    'groupID' => $groupID,
                    'ajax' => 1
                ]
            );

            $form = new Form(
                $permissionsUrl, 'post', 'modal_form'
            );
        }

        echo $form->generate_hidden_field('usecustom', '1');

        $customperms = permissionsGet(
            $whereClauses,
            array_keys($tablesData['myshowcase_permissions'])
        );

        if (!empty($permission_data['permission_id'])) {
            $permission_data['usecustom'] = 1;
        } elseif (empty($customperms['permission_id'])) {
            $permission_data = usergroup_permissions($groupID);

            foreach ($permission_data as $permissionKey => $permissionValue) {
                if (str_starts_with($permissionKey, 'myshowcase_')) {
                    $permission_data[str_replace('myshowcase_', '', $permissionKey)] = $permissionValue;
                }

                unset($permission_data[$permissionKey]);
            }
        } else {
            $permission_data = $cachedPermissions[$showcaseID][$groupID];
        }

        if ($groupID) {
            echo $form->generate_hidden_field('groupID', $groupID);
        }

        if ($permissionID) {
            echo $form->generate_hidden_field('permissionID', $permissionID);
        }

        $field_list = [];

        foreach ($tablesData['myshowcase_permissions'] as $fieldName => $fieldData) {
            if (!isset($fieldData['isPermission'])) {
                continue;
            }

            $key = str_replace(' ', '', ucwords(str_replace('_', ' ', $fieldName)));

            $field_list[$fieldData['formCategory']][$fieldName] = $lang->{'myShowcaseAdminSummaryPermissionsField' . $key};
        }

        $tabs = [];

        foreach (array_keys($field_list) as $tabKey) {
            $languageKey = str_replace(' ', '', ucwords(str_replace('_', ' ', $tabKey)));

            $tabs[$tabKey] = $lang->{'myShowcaseAdminSummaryPermissionsFieldGroup' . $languageKey};
        }

        if ($isModal) {
            $page->output_tab_control($tabs, false, 'tabs2');
        } else {
            $page->output_tab_control($tabs);
        }

        $existing_permissions = [];

        if (isset($cachedPermissions[$showcaseID])) {
            foreach ($cachedPermissions[$showcaseID] as $showcasePermissions) {
                $existing_permissions[$showcasePermissions['group_id']] = $showcasePermissions;
            }
        }

        if (!$existing_permissions) {
            $default_checked = true;
        }

        foreach (array_keys($field_list) as $tabKey) {
            $lang_group = 'group_' . $tabKey;

            echo "<div id=\"tab_" . $tabKey . "\">\n";

            $form_container = new FormContainer(
                "\"" . htmlspecialchars_uni(
                    $groupsCache[$groupID]['title']
                ) . "\" " . $lang->myShowcaseAdminSummaryPermissionsFormCustomPermissions
            );

            $fields = [];

            foreach ($field_list[$tabKey] as $permissionName => $permissionTitle) {
                $fieldData = $tablesData['myshowcase_permissions'][$permissionName];

                $key = str_replace(' ', '', ucwords(str_replace('_', ' ', $permissionName)));

                switch ($fieldData['formType']) {
                    case FormTypes::Number:
                        $formInput = '<div class="group_settings_bit">';

                        $formInput .= $lang->{'myShowcaseAdminSummaryPermissionsField' . $key};

                        $formInput .= '<br /><small class="input">';

                        $formInput .= $lang->{'myShowcaseAdminSummaryPermissionsField' . $key . 'Description'};

                        $formInput .= '</small><br />';

                        $formInput .= $form->generate_numeric_field(
                            "permissions[{$permissionName}]",
                            isset($permission_data[$permissionName]) ? (int)$permission_data[$permissionName] : 0,
                            ['id' => $permissionName, 'class' => $fieldData['formClass'] ?? '']
                        );

                        $formInput .= '</div>';

                        $fields[] = $formInput;

                        break;
                    case FormTypes::CheckBox:
                        $fields[] = $form->generate_check_box(
                            "permissions[{$permissionName}]",
                            1,
                            $permissionTitle,
                            ['checked' => !empty($permission_data[$permissionName]), 'id' => $permissionName]
                        );

                        break;
                }
            }

            $form_container->output_row(
                '',
                '',
                "<div class=\"forum_settings_bit\">" . implode(
                    "</div><div class=\"forum_settings_bit\">",
                    $fields
                ) . '</div>'
            );

            $form_container->end();

            echo '</div>';
        }

        if ($isModal) {
            $buttons[] = $form->generate_submit_button(
                $lang->cancel,
                ['onclick' => '$.modal.close(); return false;']
            );

            $buttons[] = $form->generate_submit_button(
                $lang->myShowcaseAdminSummaryPermissionsFormSave,
                ['id' => 'savePermissions']
            );

            $form->output_submit_wrapper($buttons);

            $form->end();

            echo '</div>';

            echo '</div>';
        } else {
            $buttons[] = $form->generate_submit_button($lang->myShowcaseAdminSummaryPermissionsFormSave);

            $form->output_submit_wrapper($buttons);

            $form->end();
        }
    }

    if (!$isModal) {
        $page->output_footer();
    }
} elseif ($mybb->get_input('action') === 'add' || $mybb->get_input('action') === 'edit') {
    $isAddPage = $mybb->get_input('action') === 'add';

    $isEditPage = $mybb->get_input('action') === 'edit';

    if ($isEditPage) {
        $showcaseData = showcaseGet(
            ["showcase_id='{$showcaseID}'"],
            array_keys($tablesData['myshowcase_config']),
            ['limit' => 1]
        );
    }

    if ($isEditPage && !$showcaseData) {
        flash_message($lang->myShowcaseAdminErrorInvalidShowcase, 'error');

        admin_redirect($pageTabs['myShowcaseAdminSummary']['link']);
    }

    $existingShowcases = cacheGet(CACHE_TYPE_CONFIG);

    $permissionsCache = cacheGet(CACHE_TYPE_PERMISSIONS);

    $errorMessages = [];

    if ($mybb->request_method === 'post') {
        $defaultPermissions = [];

        foreach ($tablesData['myshowcase_permissions'] as $fieldName => $fieldDefinition) {
            if (isset($fieldDefinition['isPermission'])) {
                $defaultPermissions[$fieldName] = $fieldDefinition;
            }
        }

        switch ($mybb->get_input('type')) {
            case 'main':
            case 'other':
                $insertData = [];

                foreach ($tablesData['myshowcase_config'] as $fieldName => $fieldDefinition) {
                    if (!isset($fieldDefinition['formType']) ||
                        $fieldDefinition['formCategory'] !== $mybb->get_input('type')) {
                        continue;
                    }

                    if (isset($mybb->input[$fieldName])) {
                        $insertData[$fieldName] = castTableFieldValue(
                            $mybb->input[$fieldName],
                            $fieldDefinition['type']
                        );
                    } elseif ($fieldDefinition['formType'] === FormTypes::CheckBox) {
                        $insertData[$fieldName] = $fieldDefinition['default'];
                    }
                }

                if (isset($insertData['field_set_id']) && showcaseDataTableExists($showcaseID)) {
                    unset($insertData['field_set_id']);
                }

                if (isset($insertData['name']) && (!$insertData['name'] || in_array(
                            $insertData['name'],
                            array_column($existingShowcases, 'name')
                        ) && (function (
                            string $showcaseName
                        ) use ($showcaseID, $existingShowcases): bool {
                            $duplicatedName = false;

                            foreach ($existingShowcases as $showcaseData) {
                                if ($showcaseData['name'] === $showcaseName && $showcaseData['showcase_id'] !== $showcaseID) {
                                    $duplicatedName = true;
                                }
                            }

                            return $duplicatedName;
                        })(
                            $insertData['name']
                        ))) {
                    $errorMessages[] = $lang->myShowcaseAdminErrorDuplicatedName;
                }

                if (!empty($insertData['script_name']) && (!$insertData['script_name'] || in_array(
                            $insertData['script_name'],
                            array_column($existingShowcases, 'script_name')
                        ) && (function (
                            string $scriptName
                        ) use ($showcaseID, $existingShowcases): bool {
                            $duplicateScript = false;

                            foreach ($existingShowcases as $showcaseData) {
                                if ($showcaseData['script_name'] === $scriptName && $showcaseData['showcase_id'] !== $showcaseID) {
                                    $duplicateScript = true;
                                }
                            }

                            return $duplicateScript;
                        })(
                            $insertData['script_name']
                        ))) {
                    $errorMessages[] = $lang->myShowcaseAdminErrorDuplicatedScriptFile;
                }

                if (isset($insertData['custom_theme_template_prefix'])) {
                    $insertData['custom_theme_template_prefix'] = preg_replace(
                        '/[^\da-z]/i',
                        '',
                        my_strtolower($insertData['custom_theme_template_prefix'])
                    );
                }

                if (!empty($insertData['custom_theme_template_prefix']) && (!$insertData['custom_theme_template_prefix'] || in_array(
                            $insertData['custom_theme_template_prefix'],
                            array_column($existingShowcases, 'custom_theme_template_prefix')
                        ) && (function (
                            string $templatePrefix
                        ) use ($showcaseID, $existingShowcases): bool {
                            $duplicatedTemplatePrefix = false;

                            foreach ($existingShowcases as $showcaseData) {
                                if ($showcaseData['custom_theme_template_prefix'] === $templatePrefix && $showcaseData['showcase_id'] !== $showcaseID) {
                                    $duplicatedTemplatePrefix = true;
                                }
                            }

                            return $duplicatedTemplatePrefix;
                        })(
                            $insertData['custom_theme_template_prefix']
                        ))) {
                    $errorMessages[] = $lang->myShowcaseAdminErrorDuplicatedTemplatePrefix;
                }

                hooksRun('admin_summary_add_edit_post_main_other');

                if (!$errorMessages) {
                    if ($isAddPage) {
                        $showcaseID = showcaseInsert($insertData);
                    } else {
                        showcaseUpdate($insertData, $showcaseID);
                    }

                    cacheUpdate(CACHE_TYPE_CONFIG);

                    if ($isAddPage) {
                        log_admin_action(['showcaseID' => $showcaseID]);

                        flash_message($lang->myShowcaseAdminSuccessNewShowcase, 'success');
                    } else {
                        log_admin_action(['showcaseID' => $showcaseID, 'type' => $mybb->get_input('type')]);

                        flash_message($lang->myShowcaseAdminSuccessShowcaseUpdated, 'success');
                    }

                    admin_redirect(
                        urlHandlerBuild(
                            ['action' => 'edit', 'type' => $mybb->get_input('type'), 'showcaseID' => $showcaseID]
                        ) . '#tab_' . $mybb->get_input('type')
                    );
                }
                break;
            case 'permissions':
                $insertData = [];

                if (!empty($mybb->input['default_permissions'])) {
                    $inherit = $mybb->input['default_permissions'];
                } else {
                    $inherit = [];
                }

                foreach ($mybb->input as $permissionName => $permissionValue) {
                    // Make sure we're only skipping inputs that don't start with "fields_" and aren't fields_default_ or fields_inherit_
                    if (!str_contains($permissionName, 'fields_') ||
                        (str_contains($permissionName, 'fields_default_') ||
                            str_contains($permissionName, 'fields_inherit_'))) {
                        continue;
                    }

                    $groupID = (int)str_replace('fields_', '', $permissionName);

                    if ($mybb->input['fields_default_' . $groupID] == $permissionValue && $mybb->input['fields_inherit_' . $groupID]) {
                        $inherit[$groupID] = 1;

                        continue;
                    }

                    $inherit[$groupID] = 0;

                    // If it isn't an array then it came from the javascript form
                    if (!is_array($permissionValue)) {
                        $permissionValue = explode(',', $permissionValue);

                        $permissionValue = array_flip($permissionValue);

                        foreach ($permissionValue as $fieldName => $value) {
                            $permissionValue[$fieldName] = 1;
                        }
                    }

                    foreach ($tablesData['myshowcase_permissions'] as $fieldName => $fieldData) {
                        if (!isset($fieldData['isPermission']) || empty($fieldData['draggingPermission'])) {
                            continue;
                        }

                        if (isset($permissionValue[$fieldName])) {
                            $insertData[$fieldName][$groupID] = castTableFieldValue(
                                $permissionValue[$fieldName],
                                $fieldData['type']
                            );
                        } else {
                            $insertData[$fieldName][$groupID] = $fieldData['default'];
                        }
                    }
                }

                saveQuickPermissions($showcaseID, $insertData);

                cacheUpdate(CACHE_TYPE_PERMISSIONS);

                log_admin_action(['showcaseID' => $showcaseID, 'type' => $mybb->get_input('type')]);

                flash_message($lang->myShowcaseAdminSuccessShowcaseEditPermissions, 'success');

                admin_redirect(
                    urlHandlerBuild(
                        ['action' => 'edit', 'type' => 'permissions', 'showcaseID' => $showcaseID]
                    ) . '#tab_permissions'
                );

                break;
            case 'moderators':
                if ($mybb->get_input('edit') == 'permissions') {
                    $moderatorPermissions = $mybb->get_input('permissions', MyBB::INPUT_ARRAY);

                    foreach ($moderatorPermissions as $moderatorID => $permissions) {
                        $permissions = array_map('intval', $permissions);

                        $moderatorData = [
                            ModeratorPermissions::CanManageEntries => $permissions[ModeratorPermissions::CanManageEntries] ?? 0,
                            ModeratorPermissions::CanManageEntries => $permissions[ModeratorPermissions::CanManageEntries] ?? 0,
                            ModeratorPermissions::CanManageEntries => $permissions[ModeratorPermissions::CanManageEntries] ?? 0,
                            ModeratorPermissions::CanManageComments => $permissions[ModeratorPermissions::CanManageComments] ?? 0,
                        ];

                        moderatorsUpdate($showcaseID, $moderatorData, $moderatorID);
                    }
                } elseif ($mybb->get_input('add') == 'group') {
                    $groupID = $mybb->get_input('usergroup', MyBB::INPUT_INT);

                    $query = $db->simple_select('usergroups', 'gid', "gid='{$groupID}'");

                    if (!$db->num_rows($query)) {
                        flash_message($lang->myShowcaseAdminErrorInvalidGroup, 'error');

                        admin_redirect(
                            urlHandlerBuild(
                                ['action' => 'edit', 'type' => 'moderators', 'showcaseID' => $showcaseID]
                            ) . '#tab_moderators'
                        );
                    }

                    $moderatorData = [
                        'showcase_id' => $showcaseID,
                        'user_id' => $groupID,
                        'is_group' => MODERATOR_TYPE_GROUP,
                        ModeratorPermissions::CanManageEntries => $mybb->get_input(
                            ModeratorPermissions::CanManageEntries,
                            MyBB::INPUT_INT
                        ),
                        ModeratorPermissions::CanManageComments => $mybb->get_input(
                            ModeratorPermissions::CanManageComments,
                            MyBB::INPUT_INT
                        ),
                        ModeratorPermissions::CanManageAttachments => $mybb->get_input(
                            ModeratorPermissions::CanManageAttachments,
                            MyBB::INPUT_INT
                        ),
                        ModeratorPermissions::CanManageReports => $mybb->get_input(
                            ModeratorPermissions::CanManageReports,
                            MyBB::INPUT_INT
                        ),
                    ];

                    foreach (
                        array_flip(
                            (new ReflectionClass(ModeratorPermissions::class))->getConstants()
                        ) as $key => $value
                    ) {
                        _dump($key, $value);
                    }

                    moderatorsInsert($showcaseID, $moderatorData);
                } elseif ($mybb->get_input('add') == 'user') {
                    $userData = get_user_by_username($mybb->get_input('username'));

                    if (empty($userData['uid'])) {
                        flash_message($lang->myShowcaseAdminErrorInvalidGroup, 'error');

                        admin_redirect(
                            urlHandlerBuild(
                                ['action' => 'edit', 'type' => 'moderators', 'showcaseID' => $showcaseID]
                            ) . '#tab_moderators'
                        );
                    }

                    $moderatorData = [
                        'showcase_id' => $showcaseID,
                        'user_id' => (int)$userData['uid'],
                        'is_group' => MODERATOR_TYPE_USER,
                        ModeratorPermissions::CanManageEntries => $mybb->get_input(
                            ModeratorPermissions::CanManageEntries,
                            MyBB::INPUT_INT
                        ),
                        ModeratorPermissions::CanManageComments => $mybb->get_input(
                            ModeratorPermissions::CanManageComments,
                            MyBB::INPUT_INT
                        ),
                        ModeratorPermissions::CanManageAttachments => $mybb->get_input(
                            ModeratorPermissions::CanManageAttachments,
                            MyBB::INPUT_INT
                        ),
                        ModeratorPermissions::CanManageReports => $mybb->get_input(
                            ModeratorPermissions::CanManageReports,
                            MyBB::INPUT_INT
                        ),
                    ];

                    moderatorsInsert($showcaseID, $moderatorData);
                }

                cacheUpdate(CACHE_TYPE_MODERATORS);

                log_admin_action(['showcaseID' => $showcaseID, 'type' => $mybb->get_input('type')]);

                flash_message($lang->myShowcaseAdminSuccessShowcaseEditModerators, 'success');

                admin_redirect(
                    urlHandlerBuild(['action' => 'edit', 'type' => 'moderators', 'showcaseID' => $showcaseID]
                    ) . '#tab_moderators'
                );

                break;
            case 'delete':
                moderatorsDelete($mybb->get_input('moderator_id', MyBB::INPUT_INT));

                cacheUpdate(CACHE_TYPE_MODERATORS);

                log_admin_action(['showcaseID' => $showcaseID, 'type' => $mybb->get_input('type')]);

                flash_message($lang->myShowcaseAdminSuccessShowcaseEditModeratorDelete, 'success');

                admin_redirect(
                    urlHandlerBuild(['action' => 'edit', 'type' => 'moderators', 'showcaseID' => $showcaseID]
                    ) . '#tab_moderators'
                );
                break;
        }
    }

    $page->add_breadcrumb_item(
        $lang->myShowcaseAdminSummaryEdit,
        urlHandlerBuild(['action' => $isAddPage ? 'add' : 'edit', 'showcaseID' => $showcaseID])
    );

    $page->extra_header .= "<script src=\"jscripts/quick_perm_editor.js\" type=\"text/javascript\"></script>\n";

    $page->output_header($lang->myShowcaseAdminSummaryEdit);

    if ($errorMessages) {
        $page->output_inline_error($errorMessages);
    }

    $tabs = [
        'main' => $lang->myshowcase_admin_main_options,
    ];

    if ($isEditPage) {
        $tabs['other'] = $lang->myshowcase_admin_other_options;

        $tabs['permissions'] = $lang->myshowcase_admin_permissions;

        $tabs['moderators'] = $lang->myshowcase_admin_moderators;
    }

    hooksRun('admin_summary_add_edit_start');

    if ($isAddPage) {
        $page->output_nav_tabs($pageTabs, 'myShowcaseAdminSummaryNew');
    } else {
        $page->output_nav_tabs($pageTabs, 'myShowcaseAdminSummaryEdit');
    }

    $page->output_tab_control($tabs);

    //myshowcase_get_group_permissions($showcaseID);
    $groupPermissions = $permissionsCache[$showcaseID] ?? [];

    $mybb->input = array_merge($showcaseData ?? [], $mybb->input);

    $rowObjects = [
        'main' => [
            'single' => [],
            'grouped' => [],
        ],
        'other' => [
            'single' => [],
            'grouped' => [],
        ]
    ];

    foreach ($tablesData['myshowcase_config'] as $fieldName => $fieldData) {
        if (!isset($fieldData['formCategory'])) {
            continue;
        }

        if (isset($fieldData['formSection'])) {
            $rowObjects[$fieldData['formCategory']]['grouped'][$fieldData['formSection']][$fieldName] = $fieldData;
        } else {
            $rowObjects[$fieldData['formCategory']]['single'][$fieldName] = $fieldData;
        }
    }

    //main options tab
    echo "<div id=\"tab_main\">\n";

    $form = new Form(
        urlHandlerBuild(
            ['action' => $isAddPage ? 'add' : 'edit', 'type' => 'main', 'showcaseID' => $showcaseID]
        ) . '#tab_main',
        'post',
        $isAddPage ? 'add' : 'edit'
    );

    $formContainer = new FormContainer($lang->myshowcase_admin_main_options);

    foreach ($rowObjects['main']['single'] as $fieldName => $fieldData) {
        $key = str_replace(' ', '', ucwords(str_replace('_', ' ', $fieldName)));

        $formContainer->output_row(
            $lang->{'myShowcaseAdminSummaryAddEdit' . $key},
            $lang->{'myShowcaseAdminSummaryAddEdit' . $key . 'Description'},
            buildPermissionsRow($form, $fieldName, $fieldData, $key, 'Main')
        );
    }

    foreach ($rowObjects['main']['grouped'] as $formSection => $fieldObjects) {
        $sectionKey = ucfirst($formSection);

        $settingCode = '';

        foreach ($fieldObjects as $fieldName => $fieldData) {
            $key = str_replace(' ', '', ucwords(str_replace('_', ' ', $fieldName)));

            $settingCode .= buildPermissionsRow($form, $fieldName, $fieldData, $key, 'Main', true);
        }

        $formContainer->output_row(
            $lang->{'myShowcaseAdminSummaryAddEdit' . $sectionKey},
            '',
            $settingCode
        );
    }

    $formContainer->end();

    $form->output_submit_wrapper([
        $form->generate_submit_button($lang->myShowcaseAdminButtonSubmit),
        $form->generate_reset_button($lang->myShowcaseAdminButtonReset)
    ]);

    $form->end();

    echo "</div>\n";

    if ($isEditPage) {
        //other options tab
        echo "<div id=\"tab_other\">\n";

        $form = new Form(
            urlHandlerBuild(['action' => 'edit', 'type' => 'other', 'showcaseID' => $showcaseID]) . '#tab_other',
            'post',
            'edit'
        );

        $formContainer = new FormContainer($lang->myshowcase_admin_other_options);

        foreach ($rowObjects['other']['single'] as $fieldName => $fieldData) {
            $key = str_replace(' ', '', ucwords(str_replace('_', ' ', $fieldName)));

            $formContainer->output_row(
                $lang->{'myShowcaseAdminSummaryAddEdit' . $key},
                $lang->{'myShowcaseAdminSummaryAddEdit' . $key . 'Description'},
                buildPermissionsRow($form, $fieldName, $fieldData, $key, 'Other')
            );
        }

        foreach ($rowObjects['other']['grouped'] as $formSection => $fieldObjects) {
            $sectionKey = ucfirst($formSection);

            $settingCode = '';

            foreach ($fieldObjects as $fieldName => $fieldData) {
                $key = str_replace(' ', '', ucwords(str_replace('_', ' ', $fieldName)));

                $settingCode .= buildPermissionsRow(
                    $form,
                    $fieldName,
                    $fieldData,
                    $key,
                    $sectionKey,
                    true
                );
            }

            $formContainer->output_row(
                $lang->{'myShowcaseAdminSummaryAddEdit' . $sectionKey},
                '',
                $settingCode
            );
        }

        $formContainer->end();

        $form->output_submit_wrapper([
            $form->generate_submit_button($lang->myShowcaseAdminButtonSubmitOther),
            $form->generate_reset_button($lang->myShowcaseAdminButtonReset)
        ]);

        $form->end();

        echo "</div>\n";

        //permissions tab
        echo "<div id=\"tab_permissions\">\n";

        $form = new Form(
            urlHandlerBuild(
                ['action' => 'edit', 'type' => 'permissions', 'showcaseID' => $showcaseID]
            ) . '#tab_permissions',
            'post',
            'edit'
        );

        echo $form->generate_hidden_field('showcaseID', $showcaseID);

        foreach (
            permissionsGet(
                ["showcase_id='{$showcaseID}'"],
                array_keys($tablesData['myshowcase_permissions'])
            ) as $existing
        ) {
            $existing_permissions[$existing['group_id']] = $existing;
        }

        $cachedPermissions = cacheGet(CACHE_TYPE_PERMISSIONS);

        $field_list = [];

        foreach ($tablesData['myshowcase_permissions'] as $fieldName => $fieldData) {
            if (!isset($fieldData['isPermission']) || empty($fieldData['draggingPermission'])) {
                continue;
            }

            $key = str_replace(' ', '', ucwords(str_replace('_', ' ', $fieldName)));

            $field_list[$fieldName] = $lang->{'myShowcaseAdminSummaryPermissionsField' . $key};
        }

        $groupIDs = [];

        $form_container = new FormContainer(
            $lang->myShowcaseAdminSummaryPermissionsFormPermissions
        );
        $form_container->output_row_header(
            $lang->myShowcaseAdminSummaryPermissionsFormGroup,
            ['class' => 'align_center', 'style' => 'width: 30%']
        );
        $form_container->output_row_header(
            $lang->myShowcaseAdminSummaryPermissionsFormAllowedActions,
            ['class' => 'align_center']
        );
        $form_container->output_row_header(
            $lang->myShowcaseAdminSummaryPermissionsFormDisallowedActions,
            ['class' => 'align_center']
        );
        $form_container->output_row_header(
            $lang->controls,
            ['class' => 'align_center', 'style' => 'width: 120px', 'colspan' => 2]
        );

        $inputPermissions = $mybb->get_input('permissions', MyBB::INPUT_ARRAY);

        if ($mybb->request_method == 'post') {
            foreach ($groupsCache as $groupData) {
                $groupID = (int)$groupData['gid'];

                if (isset($mybb->input['fields_' . $groupID])) {
                    $input_permissions = $mybb->input['fields_' . $groupID];

                    if (!is_array($input_permissions)) {
                        // Converting the comma separated list from Javascript form into a variable
                        $input_permissions = explode(',', $input_permissions);
                    }
                    foreach ($input_permissions as $input_permission) {
                        $inputPermissions[$groupID][$input_permission] = 1;
                    }
                }
            }
        }

        foreach ($groupsCache as $groupData) {
            $groupID = (int)$groupData['gid'];

            $perms = [];

            if (isset($mybb->input['default_permissions'])) {
                if ($mybb->input['default_permissions'][$groupID]) {
                    if ($existing_permissions[$groupID]) {
                        $perms = $existing_permissions[$groupID];

                        $default_checked = false;
                    } elseif (is_array(
                            $cachedPermissions
                        ) && $cachedPermissions[$showcaseID][$groupID]) {
                        $perms = $cachedPermissions[$showcaseID][$groupID];

                        $default_checked = true;
                    } elseif (is_array(
                            $cachedPermissions
                        ) && $cachedPermissions[$showcaseID][$groupID]) {
                        $perms = $cachedPermissions[$showcaseID][$groupID];

                        $default_checked = true;
                    }
                }

                if (!$perms) {
                    $default_checked = true;
                }
            } else {
                if (isset($existing_permissions) &&
                    is_array($existing_permissions) &&
                    !empty($existing_permissions[$groupID])) {
                    $perms = $existing_permissions[$groupID];

                    $default_checked = false;
                } elseif (is_array(
                        $cachedPermissions
                    ) && !empty($cachedPermissions[$showcaseID][$groupID])) {
                    $perms = $cachedPermissions[$showcaseID][$groupID];

                    $default_checked = true;
                } elseif (is_array(
                        $cachedPermissions
                    ) && !empty($cachedPermissions[$showcaseID][$groupID])) {
                    $perms = $cachedPermissions[$showcaseID][$groupID];

                    $default_checked = true;
                }

                if (!$perms) {
                    $perms = $groupData;

                    foreach ($perms as $permissionKey => $permissionValue) {
                        if (str_starts_with($permissionKey, 'myshowcase_')) {
                            $perms[str_replace('myshowcase_', '', $permissionKey)] = $permissionValue;
                        }

                        unset($perms[$permissionKey]);
                    }

                    $default_checked = true;
                }
            }

            $checkedPermissions = [];

            foreach ($field_list as $permissionName => $permissionTitle) {
                if ($inputPermissions) {
                    if (isset($inputPermissions[$groupID][$permissionName])) {
                        $checkedPermissions[$permissionName] = 1;
                    } else {
                        $checkedPermissions[$permissionName] = 0;
                    }
                } elseif (!empty($perms[$permissionName])) {
                    $checkedPermissions[$permissionName] = 1;
                } else {
                    $checkedPermissions[$permissionName] = 0;
                }
            }

            $groupTitle = htmlspecialchars_uni($groupData['title']);

            if (!empty($default_checked)) {
                $inheritedText = $lang->myShowcaseAdminSummaryPermissionsFormInherited;
            } else {
                $inheritedText = $lang->myShowcaseAdminSummaryPermissionsFormCustom;
            }

            $form_container->output_cell(
                "<strong>{$groupTitle}</strong> <small style=\"vertical-align: middle;\">({$inheritedText})</small>"
            );

            $field_select = "<div class=\"quick_perm_fields\">\n";

            $field_select .= "<div class=\"enabled\"><ul id=\"fields_enabled_{$groupID}\">\n";

            foreach ($checkedPermissions as $permissionName => $permissionValue) {
                if ($permissionValue) {
                    $field_select .= "<li id=\"field-{$permissionName}\">{$field_list[$permissionName]}</li>";
                }
            }

            $field_select .= "</ul></div>\n";

            $field_select .= "<div class=\"disabled\"><ul id=\"fields_disabled_{$groupID}\">\n";

            foreach ($checkedPermissions as $permissionName => $permissionValue) {
                if (!$permissionValue) {
                    $field_select .= "<li id=\"field-{$permissionName}\">{$field_list[$permissionName]}</li>";
                }
            }
            $field_select .= "</ul></div></div>\n";
            $field_select .= $form->generate_hidden_field(
                'fields_' . $groupID,
                implode(',', array_keys($checkedPermissions, '1')),
                ['id' => 'fields_' . $groupID]
            );
            $field_select .= $form->generate_hidden_field(
                'fields_inherit_' . $groupID,
                isset($default_checked) ? (int)$default_checked : 0,
                ['id' => 'fields_inherit_' . $groupID]
            );
            $field_select .= $form->generate_hidden_field(
                'fields_default_' . $groupID,
                implode(',', array_keys($checkedPermissions, '1')),
                ['id' => 'fields_default_' . $groupID]
            );
            $field_select = str_replace("'", "\\'", $field_select);
            $field_select = str_replace("\n", '', $field_select);

            $field_select = "<script type=\"text/javascript\">
//<![CDATA[
document.write('" . str_replace('/', '\/', $field_select) . "');
//]]>
</script>\n";

            $field_options = $field_selected = [];

            foreach ($field_list as $permissionName => $permissionTitle) {
                $field_options[$permissionName] = $permissionTitle;

                if ($checkedPermissions[$permissionName]) {
                    $field_selected[] = $permissionName;
                }
            }

            $field_select .= '<noscript>' . $form->generate_select_box(
                    'fields_' . $groupID . '[]',
                    $field_options,
                    $field_selected,
                    ['id' => 'fields_' . $groupID . '[]', 'multiple' => true]
                ) . "</noscript>\n";

            $form_container->output_cell($field_select, ['colspan' => 2]);

            $permissionsUrl = urlHandlerBuild(
                [
                    'action' => 'permissions',
                    'showcaseID' => $showcaseID,
                    'permissionID' => $perms['permission_id'] ?? 0,
                    'groupID' => $groupID
                ]
            );

            $permissionsModalUrl = urlHandlerBuild(
                [
                    'action' => 'permissions',
                    'showcaseID' => $showcaseID,
                    'permissionID' => $perms['permission_id'] ?? 0,
                    'groupID' => $groupID,
                    'ajax' => 1
                ]
            );

            if (empty($default_checked)) {
                $form_container->output_cell(
                    "<a href=\"{$permissionsUrl}\" onclick=\"MyBB.popupWindow('{$permissionsModalUrl}', null, true); return false;\">{{ lang.myShowcaseAdminSummaryPermissionsFormEdit }}</a>",
                    ['class' => 'align_center']
                );

                $clearPermissionsUrl = urlHandlerBuild(
                    [
                        'action' => 'clear_permission',
                        'showcaseID' => $showcaseID,
                        'permissionID' => $perms['permission_id'],
                        'my_post_key' => $mybb->post_code
                    ]
                );

                $form_container->output_cell(
                    "<a href=\"{$clearPermissionsUrl}\" onclick=\"return AdminCP.deleteConfirmation(this, '{{ lang.myShowcaseAdminSummaryPermissionsFormConfirmClear }}')\">{$lang->myShowcaseAdminSummaryPermissionsFormClear }}</a>",
                    ['class' => 'align_center']
                );
            } else {
                $form_container->output_cell(
                    "<a href=\"{$permissionsUrl}\" onclick=\"MyBB.popupWindow('{$permissionsModalUrl}', null, true); return false;\">{{ lang.myShowcaseAdminSummaryPermissionsFormSet }}</a>",
                    ['class' => 'align_center', 'colspan' => 2]
                );
            }

            $form_container->construct_row(['id' => 'row_' . $groupID]);

            $groupIDs[] = $groupID;

            unset($default_checked);
        }

        $form_container->end();

        $buttons[] = $form->generate_submit_button($lang->myShowcaseAdminSummaryPermissionsFormButtonSubmit);
        $form->output_submit_wrapper($buttons);
        $form->end();

        // Write in our JS based field selector
        echo "<script type=\"text/javascript\">\n<!--\n";
        foreach ($groupIDs as $groupID) {
            echo '$(function() { QuickPermEditor.init(' . $groupID . "); });\n";
        }
        echo "// -->\n</script>\n";

        echo "</div>\n";

        echo "<div id=\"tab_moderators\">\n";

        $form = new Form(
            urlHandlerBuild(['action' => 'edit', 'type' => 'moderators', 'showcaseID' => $showcaseID]
            ) . '#tab_moderators',
            'post',
            'management'
        );

        echo $form->generate_hidden_field('edit', 'permissions');

        $formContainer = new FormContainer(
            $lang->sprintf($lang->myShowcaseAdminSummaryEditModeratorPermissionsAssigned, $showcaseData['name'])
        );

        $formContainer->output_row_header($lang->myShowcaseAdminSummaryEditModeratorPermissionsName);

        $formContainer->output_row_header(
            $lang->myShowcaseAdminSummaryEditModeratorPermissionsCanApprove,
            ['width' => '10%', 'class' => 'align_center']
        );

        $formContainer->output_row_header(
            $lang->myShowcaseAdminSummaryEditModeratorPermissionsCanEdit,
            ['width' => '10%', 'class' => 'align_center']
        );

        $formContainer->output_row_header(
            $lang->myShowcaseAdminSummaryEditModeratorPermissionsCanDelete,
            ['width' => '10%', 'class' => 'align_center']
        );

        $formContainer->output_row_header(
            $lang->myShowcaseAdminSummaryEditModeratorPermissionsCanDeleteAuthorComment,
            ['width' => '15%', 'class' => 'align_center']
        );

        $formContainer->output_row_header(
            $lang->myShowcaseAdminSummaryEditModeratorPermissionsControls,
            ['width' => '10%', 'class' => 'align_center']
        );

        $fieldObjects = moderatorGet(
            ["showcase_id='{$showcaseID}'"],
            [
                'moderator_id',
                'user_id',
                'is_group',
                ModeratorPermissions::CanManageEntries,
                ModeratorPermissions::CanManageEntries,
                ModeratorPermissions::CanManageEntries,
                ModeratorPermissions::CanManageComments
            ],
            ['order_by' => 'is_group']
        );

        while ($moderatorData = $db->fetch_array($query)) {
            if (!empty($moderatorData['is_group'])) {
                $moderatorData['img'] = "<img src=\"styles/{$page->style}/images/icons/group.png\" alt=\"{{ lang.myshowcase_moderators_group }}\" title=\"{$lang->myshowcase_moderators_group }}\" />";

                foreach ($groupsCache as $groupData) {
                    if ($groupData['gid'] == $moderatorData['user_id']) {
                        $moderatorData['title'] = $groupData['title'];
                    }
                }

                $formContainer->output_cell(
                    "{$moderatorData['img']} <a href=\"index.php?module=user-groups&amp;action=edit&amp;group_id={$moderatorData['group_id']}\">" . htmlspecialchars_uni(
                        $moderatorData['title']
                    ) . '</a>'
                );
            } else {
                $moderatorData['img'] = "<img src=\"styles/{$page->style}/images/icons/user.png\" alt=\"{{ lang.myshowcase_moderators_user }}\" title=\"{$lang->myshowcase_moderators_user }}\" />";

                $userData = get_user($moderatorData['user_id']);

                $formContainer->output_cell(
                    "{$moderatorData['img']} <a href=\"index.php?module=user-users&amp;action=edit&amp;user_id={$moderatorData['showcase_id']}\">" . htmlspecialchars_uni(
                        $userData['username']
                    ) . '</a>'
                );
            }

            $formContainer->output_cell(
                $form->generate_check_box(
                    "permissions[{$moderatorData['moderator_id']}][can_manage_attachments]",
                    1,
                    '',
                    [
                        'checked' => $moderatorData[ModeratorPermissions::CanManageEntries],
                        'id' => "modapprove{$moderatorData['moderator_id']}"
                    ]
                ),
                ['class' => 'align_center']
            );

            $formContainer->output_cell(
                $form->generate_check_box(
                    "permissions[{$moderatorData['moderator_id']}][canmodedit]",
                    1,
                    '',
                    [
                        'checked' => $moderatorData[ModeratorPermissions::CanManageEntries],
                        'id' => "modedit{$moderatorData['moderator_id']}"
                    ]
                ),
                ['class' => 'align_center']
            );

            $formContainer->output_cell(
                $form->generate_check_box(
                    "permissions[{$moderatorData['moderator_id']}][canmoddelete]",
                    1,
                    '',
                    [
                        'checked' => $moderatorData[ModeratorPermissions::CanManageEntries],
                        'id' => "moddelete{$moderatorData['moderator_id']}"
                    ]
                ),
                ['class' => 'align_center']
            );

            $formContainer->output_cell(
                $form->generate_check_box(
                    "permissions[{$moderatorData['moderator_id']}][can_manage_comments]",
                    1,
                    '',
                    [
                        'checked' => $moderatorData[ModeratorPermissions::CanManageComments],
                        'id' => "moddelcomment{$moderatorData['moderator_id']}"
                    ]
                ),
                ['class' => 'align_center']
            );

            $deleteModeratorUrl = urlHandlerBuild([
                    'action' => 'edit',
                    'type' => 'delete',
                    'showcaseID' => $showcaseID,
                    'moderator_id' => $moderatorData['moderator_id'],
                    'my_post_key' => $mybb->post_code
                ]) . '#tab_moderators';

            $formContainer->output_cell(
                "<a href=\"{$deleteModeratorUrl}\" onclick=\"return AdminCP.deleteConfirmation(this, '{{ lang.myShowcaseAdminConfirmModeratorDelete }}')\">{$lang->myShowcaseAdminButtonDelete }}</a>",
                ['class' => 'align_center']
            );

            $formContainer->construct_row();
        }

        if (!$formContainer->num_rows()) {
            $formContainer->output_cell(
                $lang->myShowcaseAdminSummaryEditModeratorPermissionsEmpty,
                ['colspan' => 6, 'class' => 'align_center']
            );

            $formContainer->construct_row();
        }

        $formContainer->end();

        $form->output_submit_wrapper(
            [$form->generate_submit_button($lang->myShowcaseAdminButtonSubmitSaveModeratorPermissions)]
        );

        $form->end();

        echo '<br />';

        // Add Usergropups
        $form = new Form(
            urlHandlerBuild(['action' => 'edit', 'type' => 'moderators', 'showcaseID' => $showcaseID]
            ) . '#tab_moderators',
            'post',
            'management'
        );

        echo $form->generate_hidden_field('add', 'group');

        $formContainer = new FormContainer($lang->myShowcaseAdminSummaryEditModeratorPermissionsAddGroup);

        $formContainer->output_row(
            $lang->myShowcaseAdminSummaryEditModeratorPermissionsAddGroupGroup . ' <em>*</em>',
            $lang->myShowcaseAdminSummaryEditModeratorPermissionsAddGroupGroupDescription,
            $form->generate_select_box(
                'usergroup',
                (function () use ($groupsCache, $lang): array {
                    $groupObjects = [];

                    foreach ($groupsCache as $groupData) {
                        $groupID = $groupData['gid'];

                        $groupTitle = htmlspecialchars_uni($groupData['title']);

                        $groupObjects[$groupID] = $lang->myShowcaseAdminUserGroup . ' ' . $groupID . ': ' . $groupTitle;
                    }

                    return $groupObjects;
                })(),
                $mybb->get_input('usergroup'),
                ['id' => 'usergroup']
            ),
            'usergroup'
        );

        $rowOptions = [
            $form->generate_check_box(
                'gcan_manage_attachments',
                1,
                $lang->myShowcaseAdminSummaryEditModeratorPermissionsCanApproveEntries,
                ['checked' => 1]
            ) . '<br />',
            $form->generate_check_box(
                'gcanmodedit',
                1,
                $lang->myShowcaseAdminSummaryEditModeratorPermissionsCanEditEntries,
                ['checked' => 1]
            ) . '<br />',
            $form->generate_check_box(
                'gcanmoddelete',
                1,
                $lang->myShowcaseAdminSummaryEditModeratorPermissionsCanDeleteEntries,
                ['checked' => 1]
            ) . '<br />',
            $form->generate_check_box(
                'gcan_manage_comments',
                1,
                $lang->myShowcaseAdminSummaryEditModeratorPermissionsCanDeleteComments,
                ['checked' => 1]
            )
        ];

        $formContainer->output_row(
            $lang->myShowcaseAdminSummaryEditModeratorPermissions,
            $lang->myShowcaseAdminSummaryEditModeratorPermissionsDescription,
            "<div class=\"group_settings_bit\">" . implode(
                "</div><div class=\"group_settings_bit\">",
                $rowOptions
            ) . '</div>'
        );

        $formContainer->end();

        $form->output_submit_wrapper(
            [$form->generate_submit_button($lang->myShowcaseAdminButtonSubmitAddModeratorGroup)]
        );

        $form->end();

        echo '<br />';

        //add Users
        $form = new Form(
            urlHandlerBuild(['action' => 'edit', 'type' => 'moderators', 'showcaseID' => $showcaseID]
            ) . '#tab_moderators',
            'post',
            'management'
        );

        echo $form->generate_hidden_field('add', 'user');

        $formContainer = new FormContainer($lang->myShowcaseAdminSummaryEditModeratorPermissionsAddUser);

        $formContainer->output_row(
            $lang->myShowcaseAdminSummaryEditModeratorPermissionsAddUserUsername . ' <em>*</em>',
            $lang->myShowcaseAdminSummaryEditModeratorPermissionsAddUserUsernameDescription,
            $form->generate_text_box(
                'username',
                '',
                ['id' => 'username']
            ),
            'username'
        );

        $rowOptions = [
            $form->generate_check_box(
                'ucan_manage_attachments',
                1,
                $lang->myShowcaseAdminSummaryEditModeratorPermissionsCanApproveEntries,
                ['checked' => 1]
            ) . '<br />',
            $form->generate_check_box(
                'ucanmodedit',
                1,
                $lang->myShowcaseAdminSummaryEditModeratorPermissionsCanEditEntries,
                ['checked' => 1]
            ) . '<br />',
            $form->generate_check_box(
                'ucanmoddelete',
                1,
                $lang->myShowcaseAdminSummaryEditModeratorPermissionsCanDeleteEntries,
                ['checked' => 1]
            ) . '<br />',
            $form->generate_check_box(
                'ucan_manage_comments',
                1,
                $lang->myShowcaseAdminSummaryEditModeratorPermissionsCanDeleteComments,
                ['checked' => 1]
            )
        ];

        $formContainer->output_row(
            $lang->myShowcaseAdminSummaryEditModeratorPermissions,
            $lang->myShowcaseAdminSummaryEditModeratorPermissionsDescription,
            "<div class=\"group_settings_bit\">" . implode(
                "</div><div class=\"group_settings_bit\">",
                $rowOptions
            ) . '</div>'
        );


        $formContainer->end();

        // Autocompletion for usernames
        echo '
		<link rel="stylesheet" href="../jscripts/select2/select2.css">
		<script type="text/javascript" src="../jscripts/select2/select2.min.js?ver=1804"></script>
		<script type="text/javascript">
		<!--
		$("#username").select2({
			placeholder: "' . $lang->search_for_a_user . '",
			minimumInputLength: 2,
			multiple: false,
			ajax: { // instead of writing the function to execute the request we use Select2\'s convenient helper
				url: "../xmlhttp.php?action=get_users",
				dataType: \'json\',
				data: function (term, page) {
					return {
						query: term, // search_field term
					};
				},
				results: function (data, page) { // parse the results into the format expected by Select2.
					// since we are using custom formatting functions we do not need to alter remote JSON data
					return {results: data};
				}
			},
			initSelection: function(element, callback) {
				var query = $(element).val();
				if (query !== "") {
					$.ajax("../xmlhttp.php?action=get_users&getone=1", {
						data: {
							query: query
						},
						dataType: "json"
					}).done(function(data) { callback(data); });
				}
			},
		});

		$(\'[for=username]\').on(\'click\', function(){
			$("#username").select2(\'open\');
			return false;
		});
		// -->
		</script>';

        $form->output_submit_wrapper([$form->generate_submit_button($lang->myShowcaseAdminButtonSubmitAddModeratorUser)]
        );

        $form->end();

        echo "</div>\n";
    }

    hooksRun('admin_summary_add_edit_end');

    $page->output_footer();
} elseif (in_array($mybb->get_input('action'), ['enable', 'disable'])) {
    $enableShowcase = $mybb->get_input('action') === 'enable';

    $showcaseData = showcaseGet(["showcase_id='{$showcaseID}'"], [], ['limit' => 1]);

    if (!$showcaseData) {
        flash_message($lang->myShowcaseAdminErrorInvalidShowcase, 'error');

        admin_redirect($pageTabs['myShowcaseAdminSummary']['link']);
    }

    $insertData = ['enabled' => $enableShowcase ? SHOWCASE_STATUS_ENABLED : SHOWCASE_STATUS_DISABLED];

    hooksRun('admin_summary_enable_disable');

    showcaseUpdate($insertData, $showcaseID);

    log_admin_action(['showcaseID' => $showcaseID, 'enableShowcase' => $enableShowcase]);

    cacheUpdate(CACHE_TYPE_CONFIG);

    flash_message(
        $enableShowcase ? $lang->myShowcaseAdminSuccessEnabledShowcase : $lang->myShowcaseAdminSuccessDisabledShowcase,
        'success'
    );

    admin_redirect($pageTabs['myShowcaseAdminSummary']['link']);
} elseif (in_array($mybb->get_input('action'), ['tableCreate', 'tableRebuild'])) {
    $createTable = $mybb->get_input('action') === 'tableCreate';

    $showcaseData = showcaseGet(["showcase_id='{$showcaseID}'"], ['field_set_id'], ['limit' => 1]);

    if (!$showcaseData || $createTable && showcaseDataTableExists($showcaseID)) {
        flash_message($lang->myShowcaseAdminErrorInvalidShowcase, 'error');

        admin_redirect(urlHandlerBuild());
    }

    $dataTableStructure = dataTableStructureGet($showcaseID);

    hooksRun('admin_summary_table_create_rebuild_start');

    dbVerifyTables(["myshowcase_data{$showcaseID}" => $dataTableStructure]);

    if (showcaseDataTableExists($showcaseID)) {
        log_admin_action(['showcaseID' => $showcaseID, 'createTable' => $createTable]);

        if ($createTable) {
            flash_message($lang->myShowcaseAdminSuccessTableCreated, 'success');
        } else {
            flash_message($lang->myShowcaseAdminSuccessTableRebuilt, 'success');
        }
    } else {
        flash_message($lang->myShowcaseAdminErrorTableCreate, 'error');
    }

    admin_redirect(urlHandlerBuild());
} elseif ($mybb->get_input('action') === 'tableDrop') {
    $showcaseData = showcaseGet(["showcase_id='{$showcaseID}'"], ['name'], ['limit' => 1]);

    if (!$showcaseData) {
        flash_message($lang->myShowcaseAdminErrorInvalidShowcase, 'error');

        admin_redirect(urlHandlerBuild());
    }

    hooksRun('admin_summary_table_drop_start');

    $showcaseName = $showcaseData['name'];

    if (entryGet($showcaseID)) {
        flash_message($lang->myShowcaseAdminErrorTableDrop, 'error');

        admin_redirect(urlHandlerBuild());
    }

    if ($mybb->request_method === 'post') {
        if (!verify_post_check($mybb->get_input('my_post_key'), true)) {
            flash_message($lang->myShowcaseAdminErrorInvalidPostKey, 'error');

            admin_redirect(urlHandlerBuild());
        }

        if (isset($mybb->input['no'])) {
            admin_redirect(urlHandlerBuild());
        }

        hooksRun('admin_summary_table_drop_post');

        if (showcaseDataTableExists($showcaseID)) {
            showcaseDataTableDrop($showcaseID);

            showcaseUpdate(['enabled' => 0], $showcaseID);
        }

        flash_message($lang->myShowcaseAdminSuccessTableDropped, 'success');

        admin_redirect(urlHandlerBuild());
    }

    $page->output_confirm_action(
        urlHandlerBuild(['action' => 'tableDrop', 'showcaseID' => $showcaseID]),
        $lang->sprintf($lang->myShowcaseAdminConfirmTableDrop, $showcaseName)
    );
} elseif ($mybb->get_input('action') === 'viewRewrites') {
    $showcaseData = showcaseGet(["showcase_id='{$showcaseID}'"],
        ['name', 'script_name'],
        ['limit' => 1]);

    if (!$showcaseData) {
        flash_message($lang->myShowcaseAdminErrorInvalidShowcase, 'error');

        admin_redirect($pageTabs['myShowcaseAdminSummary']['link']);
    }

    $page->add_breadcrumb_item(
        $lang->myshowcase_admin_show_seo,
        urlHandlerBuild(['action' => 'viewRewrites', 'showcaseID' => $showcaseID])
    );

    $page->output_header($lang->myShowcaseAdminSummary);

    $showcaseName = strtolower($showcaseData['name']);

    //cleaning showcase name for redirect
    //some cleaning borrowed from Google SEO plugin
    $pattern = '!"#$%&\'( )*+,-./:;<=>?@[\]^_`{|}~';

    $pattern = preg_replace(
        "/[\\\\\\^\\-\\[\\]\\/]/u",
        "\\\\\\0",
        $pattern
    );

    // Cut off punctuation at beginning and end.
    $showcaseName = preg_replace(
        "/^[$pattern]+|[$pattern]+$/u",
        '',
        $showcaseName
    );

    // Replace middle punctuation with one separator.
    $showcaseName = preg_replace(
        "/[$pattern]+/u",
        '-',
        $showcaseName
    );

    echo $lang->sprintf(
        $lang->myShowcaseAdminSummaryViewRewritesDescription,
        $showcaseData['name'],
        $showcaseData['name']
    );

    echo $lang->sprintf(
        $lang->myShowcaseAdminSummaryViewRewritesMain,
        $showcaseName,
        $showcaseData['script_name']
    );

    echo $lang->sprintf(
        $lang->myShowcaseAdminSummaryViewRewritesPage,
        $showcaseName,
        $showcaseData['script_name']
    );

    echo $lang->sprintf(
        $lang->myShowcaseAdminSummaryViewRewritesView,
        $showcaseName,
        $showcaseData['script_name']
    );

    echo $lang->sprintf(
        $lang->myShowcaseAdminSummaryViewRewritesNew,
        $showcaseName,
        $showcaseData['script_name']
    );

    echo $lang->sprintf(
        $lang->myShowcaseAdminSummaryViewRewritesAttachment,
        $showcaseName,
        $showcaseData['script_name']
    );

    echo $lang->sprintf(
        $lang->myShowcaseAdminSummaryViewRewritesEntry,
        $showcaseName,
        $showcaseData['script_name']
    );

    $page->output_footer();
} elseif ($mybb->get_input('action') == 'delete') {
    $showcaseData = showcaseGet(["showcase_id='{$showcaseID}'"], [], ['limit' => 1]);

    if (!$showcaseData) {
        flash_message($lang->myShowcaseAdminErrorInvalidShowcase, 'error');

        admin_redirect(urlHandlerBuild());
    }

    hooksRun('admin_summary_delete_start');

    $showcaseName = $showcaseData['name'];

    if ($mybb->request_method === 'post') {
        if (!verify_post_check($mybb->get_input('my_post_key'), true)) {
            flash_message($lang->myShowcaseAdminErrorInvalidPostKey, 'error');

            admin_redirect(urlHandlerBuild());
        }

        if (isset($mybb->input['no'])) {
            admin_redirect(urlHandlerBuild());
        }

        hooksRun('admin_summary_delete_post');

        showcaseDelete($showcaseID);

        cacheUpdate(CACHE_TYPE_CONFIG);

        cacheUpdate(CACHE_TYPE_PERMISSIONS);

        log_admin_action(['showcaseID' => $showcaseID]);

        if (showcaseGet(["showcase_id='{$showcaseID}'"], [], ['limit' => 1])) {
            flash_message($lang->myShowcaseAdminErrorShowcaseDelete, 'error');
        } else {
            flash_message($lang->myShowcaseAdminSuccessShowcaseDeleted, 'success');
        }

        admin_redirect(urlHandlerBuild());
    }

    $page->output_confirm_action(
        urlHandlerBuild(['action' => 'delete', 'showcaseID' => $showcaseID]),
        $lang->sprintf($lang->myShowcaseAdminConfirmShowcaseDelete, $showcaseName)
    );
} else {
    hooksRun('admin_summary_main_start');

    $page->output_header($lang->myShowcaseAdminSummary);

    $page->output_nav_tabs($pageTabs, 'myShowcaseAdminSummary');

    $formContainer = new FormContainer($lang->myshowcase_summary_existing);

    $formContainer->output_row_header($lang->myshowcase_summary_id, ['width' => '2%', 'class' => 'align_center']);

    $formContainer->output_row_header(
        $lang->myshowcase_summary_name,
        ['width' => '10%', 'class' => 'align_center']
    );

    $formContainer->output_row_header(
        $lang->myshowcase_summary_slug,
        ['width' => '10%', 'class' => 'align_center']
    );

    $formContainer->output_row_header(
        $lang->myshowcase_summary_description,
        ['width' => '17%', 'class' => 'align_center']
    );

    $formContainer->output_row_header(
        $lang->myshowcase_summary_entries_count,
        ['width' => '5%', 'class' => 'align_center']
    );

    $formContainer->output_row_header(
        $lang->myshowcase_summary_comment_count,
        ['width' => '5%', 'class' => 'align_center']
    );

    $formContainer->output_row_header(
        $lang->myshowcase_summary_attachments_count,
        ['width' => '5%', 'class' => 'align_center']
    );

    $formContainer->output_row_header(
        $lang->myshowcase_summary_attachments_size,
        ['width' => '5%', 'class' => 'align_center']
    );

    $formContainer->output_row_header(
        $lang->myshowcase_summary_main_file,
        ['width' => '5%', 'class' => 'align_center']
    );

    $formContainer->output_row_header(
        $lang->myshowcase_summary_image_folder,
        ['width' => '5%', 'class' => 'align_center']
    );

    $formContainer->output_row_header(
        $lang->myshowcase_summary_field_set,
        ['width' => '5%', 'class' => 'align_center']
    );

    $formContainer->output_row_header(
        $lang->myshowcase_summary_status,
        ['width' => '2%', 'class' => 'align_center']
    );

    $formContainer->output_row_header($lang->controls, ['width' => '5%', 'class' => 'align_center']);

    $showcaseObjects = showcaseGet([], array_keys($tablesData['myshowcase_config']), ['order_by' => 'display_order']);

    if (!$showcaseObjects) {
        $formContainer->output_cell($lang->myshowcase_summary_no_myshowcases, ['colspan' => 9]);

        $formContainer->construct_row();
    } else {
        foreach ($showcaseObjects as $showcaseID => $showcaseData) {
            $showcaseTotalEntries = $showcaseTotalAttachments = $showcaseTotalFilesSize = $showcaseTotalComments = 0;

            $fieldsetID = (int)$showcaseData['field_set_id'];

            $showcaseDataTableExists = showcaseDataTableExists($showcaseID);

            if ($showcaseDataTableExists) {
                $showcaseTotalEntries = entryGet(
                    $showcaseID,
                    queryFields: ['COUNT(entry_id) AS showcaseTotalEntries'],
                    queryOptions: ['group_by' => 'entry_id']
                )['showcaseTotalEntries'] ?? 0;

                $showcaseTotalAttachments = attachmentGet(
                    ["showcase_id='{$showcaseID}'"],
                    ['COUNT(attachment_id) AS showcaseTotalAttachments'],
                    ['group_by' => 'showcase_id, attachment_id']
                )['showcaseTotalAttachments'] ?? 0;

                $showcaseTotalFilesSize = attachmentGet(
                    ["showcase_id='{$showcaseID}'"],
                    ['SUM(file_size) AS showcaseTotalFilesSize'],
                    ['group_by' => 'showcase_id, attachment_id, file_size']
                )['showcaseTotalFilesSize'] ?? 0;

                $showcaseTotalComments = commentsGet(
                    ["showcase_id='{$showcaseID}'"],
                    ['COUNT(comment_id) AS showcaseTotalComments'],
                    ['group_by' => 'showcase_id, comment_id']
                )['showcaseTotalComments'] ?? 0;
            }

            // Build popup menu
            $popup = new PopupMenu("myshowcase_{$showcaseID}", $lang->options);

            $popup->add_item(
                $lang->myshowcase_summary_edit,
                urlHandlerBuild(['action' => 'edit', 'showcaseID' => $showcaseID])
            );

            //grab status images at same time
            if (!empty($showcaseData['enabled'])) {
                $statusImage = "styles/{$page->style}/images/icons/bullet_on.png";

                $statusText = $lang->myshowcase_summary_status_enabled;

                if ($showcaseDataTableExists) {
                    $popup->add_item(
                        $lang->myshowcase_summary_disable,
                        urlHandlerBuild(['action' => 'disable', 'showcaseID' => $showcaseID])
                    );
                }
            } else {
                $statusImage = "styles/{$page->style}/images/icons/bullet_off.png";

                $statusText = $lang->myshowcase_summary_status_disabled;

                if ($showcaseDataTableExists) {
                    $popup->add_item(
                        $lang->myshowcase_summary_enable,
                        urlHandlerBuild(['action' => 'enable', 'showcaseID' => $showcaseID])
                    );
                }
            }

            //override status if table does not exist
            if (!$showcaseDataTableExists) {
                $statusImage = "styles/{$page->style}/images/icons/error.png";

                $statusText = $lang->myshowcase_summary_status_notable;

                $popup->add_item(
                    $lang->myshowcase_summary_createtable,
                    urlHandlerBuild(['action' => 'tableCreate', 'showcaseID' => $showcaseID])
                );
            } else //add delete table popup item
            {
                $popup->add_item(
                    $lang->myshowcase_summary_rebuildtable,
                    urlHandlerBuild(['action' => 'tableRebuild', 'showcaseID' => $showcaseID])
                );

                $popup->add_item(
                    $lang->myshowcase_summary_deletetable,
                    urlHandlerBuild(['action' => 'tableDrop', 'showcaseID' => $showcaseID])
                );
            }

            $popup->add_item(
                $lang->myshowcase_summary_seo,
                urlHandlerBuild(['action' => 'viewRewrites', 'showcaseID' => $showcaseData['showcase_id']])
            );

            $popup->add_item(
                $lang->myshowcase_summary_delete,
                urlHandlerBuild(['action' => 'delete', 'showcaseID' => $showcaseID])
            );

            $showcaseData['attachments_uploads_path'] = $showcaseData['attachments_uploads_path'] ?? $lang->myshowcase_summary_not_specified;

            $formContainer->output_cell($showcaseID, ['class' => 'align_center']);

            $formContainer->output_cell($showcaseData['name']);

            $formContainer->output_cell($showcaseData['script_name']);

            $formContainer->output_cell($showcaseData['description']);

            $formContainer->output_cell($showcaseTotalEntries, ['class' => 'align_center']);

            $formContainer->output_cell($showcaseTotalComments, ['class' => 'align_center']);

            $formContainer->output_cell($showcaseTotalAttachments, ['class' => 'align_center']);

            $formContainer->output_cell(
                get_friendly_size($showcaseTotalFilesSize),
                ['class' => 'align_center']
            );

            $formContainer->output_cell($showcaseData['script_name'], ['class' => 'align_center']);

            $formContainer->output_cell($showcaseData['attachments_uploads_path'], ['class' => 'align_center']);

            $formContainer->output_cell(
                fieldsetGet(
                    ["set_id='{$fieldsetID}'"],
                    ['set_name'],
                    ['limit' => 1]
                )['set_name'] . ' (ID=' . $fieldsetID . ')',
                ['class' => 'align_center']
            );

            $formContainer->output_cell(
                '<img src="' . $statusImage . '" title="' . $statusText . '">',
                ['class' => 'align_center']
            );

            $formContainer->output_cell($popup->fetch(), ['class' => 'align_center']);

            $formContainer->construct_row();
        }
    }

    $formContainer->end();

    hooksRun('admin_summary_main_end');

    $page->output_footer();
}

/**
 * @param int $groupID
 *
 * @return string
 */
function retrieveSinglePermissionsRow(int $groupID): string
{
    global $mybb, $lang;
    global $tablesData, $showcaseID, $groupsCache;

    $groupData = $groupsCache[$groupID];

    foreach (
        permissionsGet(
            ["showcase_id='{$showcaseID}'"],
            array_keys($tablesData['myshowcase_permissions'])
        ) as $existing
    ) {
        $existing_permissions[$existing['group_id']] = $existing;
    }

    $cachedPermissions = cacheGet(CACHE_TYPE_PERMISSIONS);

    $field_list = [];

    foreach ($tablesData['myshowcase_permissions'] as $fieldName => $fieldData) {
        if (!isset($fieldData['isPermission'])) {
            continue;
        }

        $key = str_replace(' ', '', ucwords(str_replace('_', ' ', $fieldName)));

        $field_list[$fieldName] = $lang->{'myShowcaseAdminSummaryPermissionsField' . $key};
    }

    $form = new Form('', '', '', 0, '', true);

    $form_container = new FormContainer();

    $perms = [];

    if ($existing_permissions[$groupID]) {
        $perms = $existing_permissions[$groupID];

        $default_checked = false;
    } elseif ($cachedPermissions[$showcaseID][$groupID]) {
        $perms = $cachedPermissions[$showcaseID][$groupID];

        $default_checked = true;
    }

    if (!$perms) {
        $perms = $groupData;

        $default_checked = true;
    }

    foreach ($field_list as $forum_permission => $forum_perm_title) {
        if ($perms[$forum_permission] == 1) {
            $perms_checked[$forum_permission] = 1;
        } else {
            $perms_checked[$forum_permission] = 0;
        }
    }

    $groupTitle = htmlspecialchars_uni($groupData['title']);

    if (!empty($default_checked)) {
        $inherited_text = $lang->myShowcaseAdminSummaryPermissionsFormInherited;
    } else {
        $inherited_text = $lang->myShowcaseAdminSummaryPermissionsFormCustom;
    }

    $form_container->output_cell(
        "<strong>{$groupTitle}</strong> <small style=\"vertical-align: middle;\">({$inherited_text})</small>"
    );

    $field_select = "<div class=\"quick_perm_fields\">\n";

    $field_select .= "<div class=\"enabled\"><ul id=\"fields_enabled_{$groupID}\">\n";

    foreach ($perms_checked as $perm => $value) {
        if ($value == 1) {
            $field_select .= "<li id=\"field-{$perm}\">{$field_list[$perm]}</li>";
        }
    }

    $field_select .= "</ul></div>\n";

    $field_select .= "<div class=\"disabled\"><ul id=\"fields_disabled_{$groupID}\">\n";

    foreach ($perms_checked as $perm => $value) {
        if ($value == 0) {
            $field_select .= "<li id=\"field-{$perm}\">{$field_list[$perm]}</li>";
        }
    }

    $field_select .= "</ul></div></div>\n";

    $field_select .= $form->generate_hidden_field(
        'fields_' . $groupID,
        implode(',', array_keys($perms_checked, 1)),
        ['id' => 'fields_' . $groupID]
    );

    $field_select = str_replace("\n", '', $field_select);

    $form_container->output_cell($field_select, ['colspan' => 2]);

    $permissionsUrl = urlHandlerBuild(
        [
            'action' => 'permissions',
            'showcaseID' => $showcaseID,
            'permissionID' => $perms['permission_id'] ?? 0,
            'groupID' => $groupID
        ]
    );

    $permissionsModalUrl = urlHandlerBuild(
        [
            'action' => 'permissions',
            'showcaseID' => $showcaseID,
            'permissionID' => $perms['permission_id'] ?? 0,
            'groupID' => $groupID,
            'ajax' => 1
        ]
    );

    if (empty($default_checked)) {
        $form_container->output_cell(
            "<a href=\"{$permissionsUrl}\" onclick=\"MyBB.popupWindow('{$permissionsModalUrl}', null, true); return false;\">{{ lang.myShowcaseAdminSummaryPermissionsFormEdit }}</a>",
            ['class' => 'align_center']
        );

        $clearPermissionsUrl = urlHandlerBuild(
            [
                'action' => 'clear_permission',
                'showcaseID' => $showcaseID,
                'permissionID' => $perms['permission_id'],
                'my_post_key' => $mybb->post_code
            ]
        );

        $form_container->output_cell(
            "<a href=\"{$clearPermissionsUrl}\" onclick=\"return AdminCP.deleteConfirmation(this, '{{ lang.myShowcaseAdminSummaryPermissionsFormConfirmClear }}')\">{$lang->myShowcaseAdminSummaryPermissionsFormClear }}</a>",
            ['class' => 'align_center']
        );
    } else {
        $form_container->output_cell(
            "<a href=\"{$permissionsUrl}\" onclick=\"MyBB.popupWindow('{$permissionsModalUrl}', null, true); return false;\">{{ lang.myShowcaseAdminSummaryPermissionsFormSet }}</a>",
            ['class' => 'align_center', 'colspan' => 2]
        );
    }

    $form_container->construct_row();

    return $form_container->output_row_cells(0, true);
}

/**
 * @param int $fid
 */
function saveQuickPermissions(int $showcaseID, array $permissionsData): void
{
    global $db, $inherit, $cache;
    global $tablesData, $showcaseID, $groupsCache, $cachedPermissions;

    $permission_fields = [];

    foreach ($tablesData['myshowcase_permissions'] as $fieldName => $fieldData) {
        if (!isset($fieldData['isPermission']) || empty($fieldData['draggingPermission'])) {
            continue;
        }

        $permission_fields[$fieldName] = $fieldData['default'];
    }

    $groupPermissionFields = $permission_fields;

    foreach ($groupsCache as $groupData) {
        $groupID = (int)$groupData['gid'];

        $existing_permissions = [];

        foreach ($cachedPermissions[$showcaseID] as $showcasePermissions) {
            $existing_permissions[$showcasePermissions['group_id']] = $showcasePermissions;
        }

        if (!$existing_permissions) {
            foreach ($permission_fields as $field => $value) {
                $existing_permissions[$field] = $groupData['myshowcase_' . $field];
            }
        }

        permissionsDelete(
            (int)(permissionsGet(
                ["showcase_id='{$showcaseID}'", "group_id='{$groupID}'"],
                queryOptions: ['limit' => 1]
            )['permission_id'] ?? 0)
        );

        // Only insert the new ones if we're using custom permissions
        if (empty($inherit[$groupID])) {
            $insertData = [
                'showcase_id' => $showcaseID,
                'group_id' => $groupID,
            ];

            foreach ($permissionsData as $permissionsName => $permissionsDataValue) {
                if (isset($permissionsDataValue[$groupID])) {
                    $insertData[$permissionsName] = $permissionsDataValue[$groupID];
                }
            }

            foreach ($permission_fields as $permissionsName => $value) {
                if (isset($insertData[$permissionsName])) {
                    continue;
                }

                $insertData[$permissionsName] = isset($existing_permissions[$permissionsName]) ? (int)$existing_permissions[$permissionsName] : 0;
            }

            permissionsInsert($showcaseID, $insertData);
        }
    }

    $cache->update_forumpermissions();
}

//todo review hooks here