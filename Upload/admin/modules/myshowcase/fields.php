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

use MyShowcase\Enum\CacheDataTypes;
use MyShowcase\Enum\FieldHtmlTypes;
use MyShowcase\Enum\FormTypes;
use MyShowcase\Service\CacheManager;
use MyShowcase\Util\UrlUtil;

use function MyShowcase\Admin\buildDbFieldDefinition;
use function MyShowcase\Admin\languageModify;
use function MyShowcase\Plugin\Functions\dataTableStructureGet;
use function MyShowcase\Plugin\Functions\fieldDataDelete;
use function MyShowcase\Plugin\Functions\fieldDataGet;
use function MyShowcase\Plugin\Functions\fieldDataInsert;
use function MyShowcase\Plugin\Functions\fieldDataUpdate;
use function MyShowcase\Plugin\Functions\fieldsDelete;
use function MyShowcase\Plugin\Functions\fieldsetDelete;
use function MyShowcase\Plugin\Functions\fieldsetGet;
use function MyShowcase\Plugin\Functions\fieldsetInsert;
use function MyShowcase\Plugin\Functions\fieldsetUpdate;
use function MyShowcase\Plugin\Functions\fieldsGet;
use function MyShowcase\Plugin\Functions\fieldsInsert;
use function MyShowcase\Plugin\Functions\fieldsUpdate;
use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\loadLanguage;
use function MyShowcase\Plugin\Functions\showcaseDataTableExists;
use function MyShowcase\Plugin\Functions\showcaseDataTableFieldDrop;
use function MyShowcase\Plugin\Functions\showcaseDataTableFieldExists;
use function MyShowcase\Plugin\Functions\showcaseDataTableFieldRename;
use function MyShowcase\Plugin\Functions\showcaseGet;

use const MyShowcase\Plugin\Core\TABLES_DATA;

if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.');
}

global $lang, $cache, $db, $plugins, $mybb;
global $page;

$cache_manager = new CacheManager($db, $cache,);

loadLanguage();

$url = new UrlUtil('index.php?module=myshowcase-fields');

$page->add_breadcrumb_item($lang->myShowcaseSystem, $url->build());

$page->add_breadcrumb_item($lang->myShowcaseAdminFieldSets, $url->build());

//get path to non-admin language folder currently in use
$languagePath = str_replace('admin', '', $lang->path . '/' . $lang->language);

$fieldID = $mybb->get_input('field_id', MyBB::INPUT_INT);

$fieldsetID = $mybb->get_input('set_id', MyBB::INPUT_INT);

$fieldOptionValue = $mybb->get_input('value_id', MyBB::INPUT_INT);

$pageAction = $mybb->get_input('action');

$pageTabs = [
    'myShowcaseAdminFieldSets' => [
        'title' => $lang->myShowcaseAdminFieldSets,
        'link' => $url->build(),
        'description' => $lang->myShowcaseAdminFieldSetsDescription
    ],
];

if (in_array($pageAction, ['viewFields', 'newField', 'editField', 'editOption'])) {
    $pageTabs['myShowcaseAdminFields'] = [
        'title' => $lang->myShowcaseAdminFields,
        'link' => $url->build(['action' => 'viewFields', 'set_id' => $fieldsetID]),
        'description' => $lang->myShowcaseAdminFieldsDescription
    ];

    if (in_array($pageAction, ['editOption'])) {
        $pageTabs['myShowcaseAdminFieldsOptions'] = [
            'title' => $lang->myShowcaseAdminFieldsOptions,
            'link' => $url->build(['action' => 'editOption', 'set_id' => $fieldsetID, 'field_id' => $fieldID]),
            'description' => $lang->myShowcaseAdminFieldsOptionsDescription
        ];
    } else {
        $pageTabs['myShowcaseAdminFieldsNew'] = [
            'title' => $lang->myShowcaseAdminFieldsNew,
            'link' => $url->build(['action' => 'newField', 'set_id' => $fieldsetID]),
            'description' => $lang->myShowcaseAdminFieldsNewDescription
        ];

        if ($pageAction === 'editField') {
            $pageTabs['myShowcaseAdminFieldsEdit'] = [
                'title' => $lang->myShowcaseAdminFieldsEdit,
                'link' => $url->build(['action' => 'editField', 'set_id' => $fieldsetID, 'field_id' => $fieldID]),
                'description' => $lang->myShowcaseAdminFieldsEditDescription
            ];
        }
    }
} else {
    $pageTabs['myShowcaseAdminFieldSetsNew'] = [
        'title' => $lang->myShowcaseAdminFieldSetsNew,
        'link' => $url->build(['action' => 'new']),
        'description' => $lang->myShowcaseAdminFieldSetsNewDescription
    ];

    if ($pageAction === 'edit') {
        $pageTabs['myShowcaseAdminFieldSetsEdit'] = [
            'title' => $lang->myShowcaseAdminFieldSetsEdit,
            'link' => $url->build(['action' => 'edit', 'set_id' => $fieldsetID]),
            'description' => $lang->myShowcaseAdminFieldSetsEditDescription
        ];
    }
}

$groupsSelectFunction = function (string $settingKey) use ($mybb, $lang): string {
    global $form;

    $selectedValues = $mybb->get_input($settingKey, MyBB::INPUT_ARRAY);

    $groupChecked = ['all' => '', 'custom' => '', 'none' => ''];

    if (in_array(-1, $selectedValues)) {
        $groupChecked['all'] = 'checked="checked"';
    } elseif (count($selectedValues) > 0) {
        $groupChecked['custom'] = 'checked="checked"';
    } else {
        $groupChecked['none'] = 'checked="checked"';
    }

    print_selection_javascript();

    return <<<EOL
                    <dl style="margin-top: 0; margin-bottom: 0; width: 100%">
                        <dt>
                            <label style="display: block;">
                                <input type="radio" name="{$settingKey}_type" value="all" {$groupChecked['all']} class="{$settingKey}_forums_groups_check" onclick="checkAction('{$settingKey}');" style="vertical-align: middle;" />
                                <strong>{$lang->all_groups}</strong>
                            </label>
                        </dt>
                        <dt>
                            <label style="display: block;">
                                <input type="radio" name="{$settingKey}_type" value="custom" {$groupChecked['custom']} class="{$settingKey}_forums_groups_check" onclick="checkAction('{$settingKey}');" style="vertical-align: middle;" />
                                <strong>{$lang->select_groups}</strong>
                            </label>
                        </dt>
                        <dd style="margin-top: 4px;" id="{$settingKey}_forums_groups_custom" class="{$settingKey}_forums_groups">
                            <table cellpadding="4">
                                <tr>
                                    <td valign="top"><small>{$lang->groups_colon}</small></td>
                                    <td>{$form->generate_group_select(
        "{$settingKey}[]",
        $selectedValues,
        ['id' => $settingKey, 'multiple' => true, 'size' => 5]
    )}</td>
                                </tr>
                            </table>
                        </dd>
                        <dt>
                            <label style="display: block;"><input type="radio" name="{$settingKey}_type" value="none" {$groupChecked['none']} class="{$settingKey}_forums_groups_check" onclick="checkAction('{$settingKey}');" style="vertical-align: middle;" /> 
                            <strong>{$lang->none}</strong></label>
                        </dt>
                    </dl>
                    <script type="text/javascript">
                        checkAction('{$settingKey}');
                    </script>
    EOL;
};

$tablesData = TABLES_DATA;

if (in_array($pageAction, ['newField', 'editField'])) {
    $newPage = $pageAction === 'newField';

    hooksRun('admin_field_new_edit_start');

    $fieldsetData = fieldsetGet(["set_id='{$fieldsetID}'"], ['set_name'], ['limit' => 1]);

    if (!$newPage && empty($fieldsetData)) {
        flash_message($lang->myShowcaseAdminErrorInvalidFieldset, 'error');

        admin_redirect($pageTabs['myShowcaseAdminFieldSets']['link']);
    }

    $queryFields = $tablesData['myshowcase_fields'];

    unset($queryFields['unique_keys']);

    $fieldDefinition = fieldsGet(["field_id='{$fieldID}'"], array_keys($queryFields), ['limit' => 1]);

    if (!$newPage && empty($fieldDefinition)) {
        flash_message($lang->myShowcaseAdminErrorInvalidField, 'error');

        admin_redirect($url->build(['action' => 'viewFields', 'set_id' => $fieldsetID]));
    }

    $page->output_header($pageTabs[$newPage ? 'myShowcaseAdminFieldsNew' : 'myShowcaseAdminFieldsEdit']['title']);

    $page->output_nav_tabs($pageTabs, $newPage ? 'myShowcaseAdminFieldsNew' : 'myShowcaseAdminFieldsEdit');

    $canEditLanguageFile = true;

    if (!is_writable($languagePath) ||
        (!is_writable($languagePath . '/myshowcase_fs' . $fieldsetID . '.lang.php') &&
            file_exists($languagePath . '/myshowcase_fs' . $fieldsetID . '.lang.php'))) {
        $canEditLanguageFile = false;
    }

    $fieldIsInUse = false;

    $showcaseObjects = showcaseGet(["field_set_id='{$fieldsetID}'"]);

    if (!$newPage && $showcaseObjects) {
        foreach ($showcaseObjects as $showcaseID => $showcaseData) {
            if (!showcaseDataTableExists($showcaseID)) {
                break;
            }

            if (showcaseDataTableFieldExists($showcaseID, $fieldDefinition['field_key'])) {
                $fieldIsInUse = true;

                break;
            }
        }
    }

    if ($mybb->request_method === 'post') {
        if (!verify_post_check($mybb->get_input('my_post_key'), true)) {
            flash_message($lang->myShowcaseAdminErrorInvalidPostKey, 'error');

            admin_redirect($url->build());
        }

        foreach (['allowed_groups_fill', 'allowed_groups_view'] as $settingName) {
            if ($mybb->get_input($settingName . '_type') === 'all') {
                $mybb->input[$settingName] = [-1];
            } elseif ($mybb->get_input($settingName . '_type') !== 'custom') {
                $mybb->input[$settingName] = [];
            }
        }

        $insertData = [
            'set_id' => $fieldsetID,
        ];

        (function () use ($mybb, $tablesData, &$insertData): void {
            foreach ($tablesData['myshowcase_fields'] as $fieldName => $fieldDefinition) {
                if (!isset($fieldDefinition['input_type'])) {
                    continue;
                }

                switch ($fieldDefinition['input_type']) {
                    case FormTypes::Text:
                    case FormTypes::Select:
                        $insertData[$fieldName] = $mybb->get_input($fieldName);

                        break;
                    case FormTypes::YesNo:
                    case FormTypes::Number:
                        $insertData[$fieldName] = $mybb->get_input($fieldName, MyBB::INPUT_INT);

                        break;
                    case FormTypes::SelectMultipleGroups:
                        $insertData[$fieldName] = $mybb->get_input($fieldName, MyBB::INPUT_ARRAY);

                        break;
                }
            }
        })();

        if (isset($insertData['field_key'])) {
            $insertData['field_key'] = trim(
                my_strtolower(
                    preg_replace(
                        '#[^\w]#',
                        '_',
                        $insertData['field_key']
                    )
                ),
                '_'
            );
        }

        $errorMessages = [];

        if (!$insertData['field_key']) {
            $errorMessages[] = $lang->myShowcaseAdminErrorMissingRequiredFields;
        }

        if ($insertData['minimum_length'] > $insertData['maximum_length']) {
            $errorMessages[] = $lang->myShowcaseAdminErrorInvalidMinMax;
        }

        $existingFields = $cache_manager->get(
            CacheDataTypes::Fields
        )[$fieldsetID] ?? [];

        if (in_array($insertData['field_key'], array_column($existingFields, 'field_key')) &&
            (function (string $field_key) use ($fieldsetID, $existingFields, $fieldID): bool {
                $duplicatedName = false;

                foreach ($existingFields as $fieldDefinition) {
                    if ($fieldDefinition['field_key'] === $field_key &&
                        /*$fieldDefinition['set_id'] !== $fieldsetID &&*/
                        $fieldDefinition['field_id'] !== $fieldID) {
                        $duplicatedName = true;
                    }
                }

                return $duplicatedName;
            })(
                $insertData['field_key']
            )) {
            $errorMessages[] = $lang->myShowcaseAdminErrorDuplicatedName;
        }

        $fieldDefaultOption = 0;

        if (!$newPage && $insertData['field_key'] === $fieldDefinition['field_key']) {
            unset($insertData['field_key']);
        }

        if (!empty($insertData['regular_expression']) &&
            preg_match($insertData['regular_expression'], '') === false) {
            $errorMessages[] = $lang->myShowcaseAdminErrorRegularExpression;
        }

        if ($errorMessages) {
            $page->output_inline_error($errorMessages);
        } else {
            $insertData = hooksRun('admin_field_new_edit_post', $insertData);

            if ($newPage) {
                $fieldID = fieldsInsert($insertData);
            } else {
                if ($fieldIsInUse && isset($insertData['field_key']) && $insertData['field_key'] !== $fieldDefinition['field_key']) {
                    foreach (showcaseGet(["field_set_id='{$fieldsetID}'"]) as $showcaseID => $showcaseData) {
                        if (showcaseDataTableExists($showcaseID)) {
                            $dataTableStructure = dataTableStructureGet($showcaseID);

                            if (showcaseDataTableFieldExists($showcaseID, $fieldDefinition['field_key']) &&
                                !showcaseDataTableFieldExists($showcaseID, $insertData['field_key'])) {
                                showcaseDataTableFieldRename(
                                    $showcaseID,
                                    $fieldDefinition['field_key'],
                                    $insertData['field_key'],
                                    buildDbFieldDefinition($dataTableStructure[$fieldDefinition['field_key']])
                                );
                            }
                        }
                    }
                }

                fieldsUpdate($insertData, $fieldID);

                if (isset($fieldDefinition['field_key']) &&
                    in_array($fieldDefinition['html_type'], [
                        FieldHtmlTypes::CheckBox,
                        FieldHtmlTypes::Radio,
                        FieldHtmlTypes::Select,
                    ])) {
                    foreach (
                        fieldDataGet(
                            ["field_id='{$fieldID}'", "set_id='{$fieldsetID}'"]
                        ) as $fieldDataID => $fieldDataData
                    ) {
                        fieldDataUpdate(['field_key' => $fieldDefinition['field_key']], $fieldDataID);
                    }
                }
            }

            $fieldOptionID = 0;

            if ($fieldDefaultOption) {
                $fieldOptionData = [
                    'set_id' => $fieldsetID,
                    'field_id' => $fieldID,
                    'field_key' => $db->escape_string($mybb->get_input('field_key')),
                    'value' => 'Not Specified'
                ];

                $fieldOptionID = fieldDataInsert($fieldOptionData);
            }

            if ($canEditLanguageFile) {
                languageModify(
                    'myshowcase_fs' . $fieldsetID,
                    [
                        'myshowcase_fieldset_field_' . ($insertData['field_key'] ?? $fieldDefinition['field_key']) =>
                            $mybb->get_input('label') ?? $mybb->get_input('field_key')
                    ]
                );
            }

            $cache_manager->update(CacheDataTypes::Fields);

            $cache_manager->update(CacheDataTypes::FieldData);

            log_admin_action(['fieldsetID' => $fieldsetID, 'fieldID' => $fieldID, 'fieldOptionID' => $fieldOptionID]);

            if ($newPage) {
                flash_message($lang->myShowcaseAdminSuccessNewField, 'success');
            } else {
                flash_message($lang->myShowcaseAdminSuccessEditField, 'success');
            }

            admin_redirect($url->build(['action' => 'viewFields', 'set_id' => $fieldsetID]));
        }
    } elseif (!empty($fieldDefinition)) {
        foreach (['allowed_groups_fill', 'allowed_groups_view'] as $settingKey) {
            $mybb->input[$settingKey] = explode(',', $fieldDefinition[$settingKey] ?? '');
        }
    }

    foreach (['allowed_groups_fill', 'allowed_groups_view'] as $settingName) {
        $mybb->input[$settingName] = array_filter(
            array_map('intval', $mybb->get_input($settingName, MyBB::INPUT_ARRAY))
        );
    }

    $mybb->input = array_merge($fieldDefinition, $mybb->input);

    $form = new Form(
        $pageTabs[$newPage ? 'myShowcaseAdminFieldsNew' : 'myShowcaseAdminFieldsEdit']['link'],
        'post',
        $newPage ? 'myShowcaseAdminFieldsNew' : 'myShowcaseAdminFieldsEdit'
    );

    echo $form->generate_hidden_field('my_post_key', $mybb->post_code);

    $formContainer = new FormContainer(
        $pageTabs[$newPage ? 'myShowcaseAdminFieldsNew' : 'myShowcaseAdminFieldsEdit']['title']
    );

    foreach ($tablesData['myshowcase_fields'] as $fieldName => $fieldDefinition) {
        if (!isset($fieldDefinition['input_type']) || isset($fieldDefinition['quickSetting'])) {
            continue;
        }

        $formInput = '';

        switch ($fieldDefinition['input_type']) {
            case FormTypes::Text:
                $formInput .= $form->generate_text_box(
                    $fieldName,
                    $mybb->get_input($fieldName),
                    ['id' => $fieldName . '" maxlength="' . ($fieldDefinition['size'] ?? '')]
                );

                break;
            case FormTypes::Number:
                $formInput = $form->generate_numeric_field(
                    $fieldName,
                    $mybb->get_input($fieldName, MyBB::INPUT_INT),
                    [
                        'id' => $fieldName,
                        'min' => empty($fieldDefinition['unsigned']) ? '' : 0,
                        'max' => $fieldDefinition['size'] ?? '',
                        'class' => $fieldDefinition['class'] ?? ''
                    ]
                );

                break;
            case FormTypes::Select:
                $formInput = $form->generate_select_box(
                    $fieldName,
                    $fieldDefinition['formFunction'](),
                    $mybb->get_input($fieldName),
                    [
                        'id' => $fieldName,
                        'class' => $fieldDefinition['class'] ?? ''
                    ]
                );

                break;
            case FormTypes::SelectMultipleGroups:
                $formInput = $groupsSelectFunction($fieldName);

                break;
            case FormTypes::YesNo:
                $formInput = $form->generate_yes_no_radio(
                    $fieldName,
                    $mybb->get_input($fieldName, MyBB::INPUT_INT),
                    [
                        'id' => $fieldName,
                        'class' => $fieldDefinition['class'] ?? ''
                    ]
                );

                break;
        }

        $key = str_replace(' ', '', ucwords(str_replace('_', ' ', $fieldName)));

        $formContainer->output_row(
            ($lang->{'myShowcaseAdminFieldsCreateUpdateForm' . $key} ?? 'myShowcaseAdminFieldsCreateUpdateForm' . $key) . (empty($fieldDefinition['formRequired']) ? '' : '<em>*</em>'),
            $lang->{'myShowcaseAdminFieldsCreateUpdateForm' . $key . 'Description'},
            $formInput,
            $fieldName,
            ['id' => $fieldName . '_row']
        );
    }

    hooksRun('admin_field_new_edit_end');

    $formContainer->end();

    $form->output_submit_wrapper([
        $form->generate_submit_button($lang->myShowcaseAdminButtonSubmit),
        $form->generate_reset_button($lang->myShowcaseAdminButtonReset)
    ]);

    $form->end();

    //checkbox|color|date|datetime-local|email|file|month|number|password|radio|range|search|tel|text|time|url|week|textarea|select|select2_users|select2_entries|select2_threads
    /*
    echo '<script type="text/javascript" src="./jscripts/peeker.js?ver=1821"></script>
	<script type="text/javascript">
		$(function() {
				new Peeker($("#html_type"), $("#placeholder_row"), /email|password|search|tel|text|url|textarea/, false);
				new Peeker($("#html_type"), $("#file_capture_row"), /file/, false);
				new Peeker($("#html_type"), $("#allow_multiple_values_row"), /checkbox|email|file|text|textarea|select|select2_users|select2_entries|select2_threads/, false);
				new Peeker($("#html_type"), $("#regular_expression_row"), /email|password|search|tel|text|url/, false);
				new Peeker($("#html_type"), $("#minimum_length_row"), /date|datetime-local|month|number|password|range|search|tel|text|time|url|week|textarea|select|select2_users|select2_entries|select2_threads/, false);
				new Peeker($("#html_type"), $("#maximum_length_row"), /date|datetime-local|month|number|password|range|search|tel|text|time|url|week|textarea|select|select2_users|select2_entries|select2_threads/, false);
				
				new Peeker($("#html_type"), $("#step_size_row"), /date|datetime-local|month|number|range|time|week/, false);
				
		});
	</script>';
    */

    $page->output_footer();
} elseif (in_array($pageAction, ['new', 'edit'])) {
    $newPage = $pageAction === 'new';

    hooksRun('admin_fieldset_new_edit_start');

    $fieldsetData = fieldsetGet(["set_id='{$fieldsetID}'"], ['set_name'], ['limit' => 1]);

    if (!$newPage && empty($fieldsetData)) {
        flash_message($lang->myShowcaseAdminErrorInvalidShowcase, 'error');

        admin_redirect($pageTabs['myShowcaseAdminFieldSets']['link']);
    }

    $page->output_header($lang->myShowcaseAdminFieldSets);

    $page->output_nav_tabs($pageTabs, $newPage ? 'myShowcaseAdminFieldSetsNew' : 'myShowcaseAdminFieldSetsEdit');

    if ($mybb->request_method === 'post') {
        if (!verify_post_check($mybb->get_input('my_post_key'), true)) {
            flash_message($lang->myShowcaseAdminErrorInvalidPostKey, 'error');

            admin_redirect($url->build());
        }

        $errorMessages = [];

        if (!$mybb->get_input('set_name')) {
            $errorMessages[] = $lang->myShowcaseAdminErrorMissingRequiredFields;
        }

        $existingFieldSets = $cache_manager->get(
            CacheDataTypes::Fieldsets
        );

        if (in_array($mybb->get_input('set_name'), array_column($existingFieldSets, 'set_name')) && (function (
                string $showcaseName
            ) use ($fieldsetID, $existingFieldSets): bool {
                $duplicatedName = false;

                foreach ($existingFieldSets as $fieldsetData) {
                    if ($fieldsetData['set_name'] === $showcaseName && $fieldsetData['set_id'] !== $fieldsetID) {
                        $duplicatedName = true;
                    }
                }

                return $duplicatedName;
            })(
                $mybb->get_input('set_name')
            )) {
            $errorMessages[] = $lang->myShowcaseAdminErrorDuplicatedName;
        }

        if (!is_writable($languagePath)) {
            $errorMessages[] = $lang->sprintf(
                $lang->myshowcase_fields_lang_not_writable,
                $lang->path . '/' . $lang->language . '/'
            );
        }

        $fieldsetData = [
            'set_name' => $db->escape_string($mybb->get_input('set_name')),
        ];

        if ($errorMessages) {
            $page->output_inline_error($errorMessages);
        } else {
            $fieldsetData = hooksRun('admin_fieldset_new_edit_post', $fieldsetData);

            if ($newPage) {
                $fieldsetID = fieldsetInsert($fieldsetData);
            } else {
                fieldsetUpdate($fieldsetData, $fieldsetID);
            }

            $cache_manager->update(CacheDataTypes::Config);

            $cache_manager->update(CacheDataTypes::Fields);

            $cache_manager->update(CacheDataTypes::FieldData);

            $cache_manager->update(CacheDataTypes::Fieldsets);

            log_admin_action(['fieldsetID' => $fieldsetID]);

            if ($newPage) {
                flash_message($lang->myShowcaseAdminSuccessNewFieldset, 'success');
            } else {
                flash_message($lang->myShowcaseAdminSuccessEditFieldset, 'success');
            }

            admin_redirect($url->build());
        }
    }

    $mybb->input = array_merge($fieldsetData, $mybb->input);

    $form = new Form(
        $pageTabs[$newPage ? 'myShowcaseAdminFieldSetsNew' : 'myShowcaseAdminFieldSetsEdit']['link'],
        'post',
        $newPage ? 'myShowcaseAdminFieldSetsNew' : 'myShowcaseAdminFieldSetsEdit'
    );

    echo $form->generate_hidden_field('my_post_key', $mybb->post_code);

    $formContainer = new FormContainer(
        $pageTabs[$newPage ? 'myShowcaseAdminFieldSetsNew' : 'myShowcaseAdminFieldSetsEdit']['title']
    );

    $formContainer->output_row(
        $lang->myShowcaseAdminFieldSetsNewFormName . '<em>*</em>',
        $lang->myShowcaseAdminFieldSetsNewFormNameDescription,
        $form->generate_text_box('set_name', $mybb->get_input('set_name'), ['id' => 'set_name']),
        'set_name'
    );

    hooksRun('admin_fieldset_new_edit_end');

    $formContainer->end();

    $form->output_submit_wrapper([
        $form->generate_submit_button($lang->myShowcaseAdminButtonSubmit),
        $form->generate_reset_button($lang->myShowcaseAdminButtonReset)
    ]);

    $form->end();

    $page->output_footer();
} elseif ($pageAction === 'viewFields') {
    $fieldsetData = fieldsetGet(["set_id='{$fieldsetID}'"], ['set_name'], ['limit' => 1]);

    if (empty($fieldsetData)) {
        flash_message($lang->myshowcase_fields_invalid_id, 'error');

        admin_redirect($url->build());
    }

    $page->add_breadcrumb_item(
        $lang->sprintf($lang->myshowcase_admin_edit_fieldset, $fieldsetData['set_name']),
        $url->build()
    );

    $page->output_header($lang->myshowcase_admin_fields);

    $page->output_nav_tabs($pageTabs, 'myShowcaseAdminFields');

    hooksRun('admin_view_fields_start');

    if ($mybb->request_method === 'post') {
        foreach (fieldsGet(["set_id='{$fieldsetID}'"]) as $fieldID => $insertData) {
            $insertData = [];

            foreach ($tablesData['myshowcase_fields'] as $fieldName => $fieldDefinition) {
                if (empty($fieldDefinition['quickSetting'])) {
                    continue;
                }

                $insertData[$fieldName] = $mybb->get_input($fieldName, MyBB::INPUT_ARRAY)[$fieldID] ?? 0;
            }

            $insertData = hooksRun('admin_view_fields_update_post', $insertData);

            if ($insertData) {
                fieldsUpdate($insertData, $fieldID);
            }
        }

        flash_message($lang->myshowcase_field_update_success, 'success');

        admin_redirect($url->build(['action' => 'viewFields', 'set_id' => $fieldsetID]));
    }

    $form = new Form(
        $url->build(['action' => 'viewFields', 'set_id' => $fieldsetID]),
        'post',
        'viewFields'
    );

    $formContainer = new FormContainer($lang->myshowcase_field_list);

    $formContainer->output_row_header(
        $lang->myShowcaseAdminFieldsTableHeaderID,
        ['width' => '1%', 'class' => 'align_center']
    );

    $formContainer->output_row_header(
        $lang->myShowcaseAdminFieldsTableHeaderName,
        ['class' => 'align_center']
    );

    $formContainer->output_row_header(
        $lang->myShowcaseAdminFieldsTableHeaderLabel,
        ['class' => 'align_center']
    );

    $formContainer->output_row_header(
        $lang->myShowcaseAdminFieldsTableHeaderHtmlType,
        ['width' => '5%', 'class' => 'align_center']
    );

    $formContainer->output_row_header(
        $lang->myShowcaseAdminFieldsTableHeaderFieldType,
        ['width' => '5%', 'class' => 'align_center']
    );

    foreach ($tablesData['myshowcase_fields'] as $fieldName => $fieldDefinition) {
        if (empty($fieldDefinition['quickSetting'])) {
            continue;
        }

        $key = str_replace(' ', '', ucwords(str_replace('_', ' ', $fieldName)));

        $formContainer->output_row_header(
            $lang->{'myShowcaseAdminFieldsTableHeader' . $key},
            ['width' => '4%', 'class' => 'align_center']
        );
    }

    $formContainer->output_row_header($lang->controls, ['width' => '5%', 'class' => 'align_center']);

    $maximumViewOrder = 0;

    $queryFields = $tablesData['myshowcase_fields'];

    unset($queryFields['unique_keys']);

    $fieldObjects = fieldsGet(["set_id='{$fieldsetID}'"], array_keys($queryFields), ['order_by' => 'display_order']);

    if (!$fieldObjects) {
        $formContainer->output_cell(
            $lang->myshowcase_fields_no_fields,
            ['class' => 'align_center', 'colspan' => 11]
        );

        $formContainer->construct_row();
    } else {
        $l = [];

        //include_once $languagePath . '/myshowcase_fs' . $fieldsetID . '.lang.php';

        $maximumViewOrder = 1;

        foreach ($fieldObjects as $fieldID => $fieldDefinition) {
            $formContainer->output_cell(my_number_format($fieldID), ['class' => 'align_center']);

            $viewOptionsUrl = '';

            if (in_array($fieldDefinition['html_type'], [
                FieldHtmlTypes::CheckBox,
                FieldHtmlTypes::Radio,
                FieldHtmlTypes::Select,
            ])) {
                $viewOptionsUrl = $url->build(
                    ['action' => 'editOption', 'field_id' => $fieldID, 'set_id' => $fieldsetID]
                );
            }

            $formContainer->output_cell(
                $viewOptionsUrl ? "<a href='{$viewOptionsUrl}'>{$fieldDefinition['field_key']}</a>" : $fieldDefinition['field_key'],
                ['class' => 'align_left']
            );

            if (empty($fieldDefinition['field_label'])) {
                $fieldDefinition['field_label'] = $l['myshowcase_fieldset_field_' . $fieldDefinition['field_key']] ?? '';
            }

            $formContainer->output_cell(
                $fieldDefinition['field_label'],
                ['class' => 'align_left']
            );

            $formContainer->output_cell(
                $fieldDefinition['html_type'],
                ['class' => 'align_center']
            );

            $formContainer->output_cell(
                $fieldDefinition['field_type'],
                ['class' => 'align_center']
            );

            (function (array $values) use ($tablesData, $fieldID, $formContainer, $form) {
                foreach ($tablesData['myshowcase_fields'] as $fieldName => $fieldDefinition) {
                    if (empty($fieldDefinition['quickSetting'])) {
                        continue;
                    }

                    $formContainer->output_cell(
                        $form->generate_check_box(
                            $fieldName . '[' . $fieldID . ']',
                            1,
                            '',
                            ['checked' => $values[$fieldName]],
                        ),
                        ['class' => 'align_center']
                    );
                }
            })(
                $fieldDefinition
            );

            $popup = new PopupMenu("field_{$fieldID}", $lang->options);

            $popup->add_item(
                $lang->edit,
                $url->build(['action' => 'editField', 'field_id' => $fieldID, 'set_id' => $fieldsetID]),
            );

            $popup->add_item(
                $lang->myshowcase_field_delete,
                $url->build(['action' => 'deleteField', 'field_id' => $fieldID, 'set_id' => $fieldsetID]),
            );

            if (in_array($fieldDefinition['html_type'], [
                FieldHtmlTypes::CheckBox,
                FieldHtmlTypes::Radio,
                FieldHtmlTypes::Select,
            ])) {
                $popup->add_item(
                    $lang->myshowcase_field_edit_options,
                    $url->build(['action' => 'editOption', 'field_id' => $fieldID, 'set_id' => $fieldsetID]),
                );
            }

            $maximumViewOrder = max($maximumViewOrder, $fieldDefinition['display_order']);

            $formContainer->output_cell($popup->fetch(), ['class' => 'align_center']);

            $formContainer->construct_row();
        }
    }

    $formContainer->end();

    $buttons[] = $form->generate_submit_button($lang->myshowcase_fields_save_changes);

    $form->output_submit_wrapper($buttons);

    $form->end();

    hooksRun('admin_view_fields_end');

    $page->output_footer();
} elseif ($pageAction === 'delete') {
    $fieldsetData = fieldsetGet(["set_id='{$fieldsetID}'"], ['set_name'], ['limit' => 1]);

    if (!$fieldsetData) {
        flash_message($lang->myShowcaseAdminErrorInvalidFieldset, 'error');

        admin_redirect($url->build());
    }

    $showcaseObjects = showcaseGet(["field_set_id='{$fieldsetID}'"], [], ['limit' => 1]);

    if (!empty($showcaseObjects)) {
        flash_message($lang->myShowcaseAdminErrorFieldsetDeleteFailed, 'error');

        admin_redirect($url->build());
    }

    hooksRun('admin_fieldset_delete_start');

    $fieldsetName = $fieldsetData['set_name'];

    if ($mybb->request_method === 'post') {
        if (!verify_post_check($mybb->get_input('my_post_key'), true)) {
            flash_message($lang->myShowcaseAdminErrorInvalidPostKey, 'error');

            admin_redirect($url->build());
        }

        if (isset($mybb->input['no'])) {
            admin_redirect($url->build());
        }

        hooksRun('admin_fieldset_delete_post');

        foreach (fieldsGet(["set_id='{$fieldsetID}'"]) as $fieldID => $fieldDefinition) {
            fieldsDelete($fieldsetID);
        }

        fieldsetDelete($fieldsetID);

        foreach ((array)$lang->get_languages() as $langfolder => $langname) {
            $languageFile = $lang->path . '/' . $langfolder . '/myshowcase_fs' . $fieldsetID . '.lang.php';

            if (file_exists($languageFile)) {
                unlink($languageFile);
            }
        }

        $cache_manager->update(CacheDataTypes::Config);

        $cache_manager->update(CacheDataTypes::Fields);

        $cache_manager->update(CacheDataTypes::FieldData);

        $cache_manager->update(CacheDataTypes::Fieldsets);

        log_admin_action(['fieldsetID' => $fieldsetID]);

        if (fieldsetGet(["set_id='{$fieldsetID}'"])) {
            flash_message($lang->myShowcaseAdminErrorFieldsetDelete, 'error');
        } else {
            flash_message($lang->myShowcaseAdminSuccessFieldsetDeleted, 'success');
        }

        admin_redirect($url->build());
    }

    $page->output_confirm_action(
        $url->build(['action' => 'delete', 'set_id' => $fieldsetID]),
        $lang->sprintf($lang->myShowcaseAdminConfirmFieldsetDelete, $fieldsetName)
    );
} elseif ($pageAction === 'editOption') {
    $fieldDefinition = fieldsGet(
        ["field_id='{$fieldID}'", "set_id='{$fieldsetID}'"],
        ['field_key', 'html_type'],
        ['limit' => 1]
    );

    if (!$fieldDefinition || !in_array($fieldDefinition['html_type'], [
            FieldHtmlTypes::CheckBox,
            FieldHtmlTypes::Radio,
            FieldHtmlTypes::Select,
        ])) {
        flash_message($lang->myshowcase_field_invalid_id, 'error');

        admin_redirect($url->build(['action' => 'viewFields', 'set_id' => $fieldsetID]));
    }

    $can_edit = true;

    foreach (showcaseGet(["field_set_id='{$fieldsetID}'"]) as $showcaseID => $showcaseData) {
        if (showcaseDataTableExists($showcaseID)) {
            $can_edit = false;
        }
    }

    $fieldOptionData = fieldsetGet(["set_id='{$fieldsetID}'"], ['set_name'], ['limit' => 1]);

    $page->add_breadcrumb_item(
        $lang->sprintf(
            $lang->myshowcase_admin_edit_fieldopt,
            $fieldDefinition['field_key'],
            $fieldOptionData['set_name']
        ),
        $url->build()
    );

    $page->output_header($lang->myshowcase_admin_fields);

    $page->output_nav_tabs($pageTabs, 'myShowcaseAdminFieldsOptions');

    hooksRun('admin_option_edit_start');

    //user clicked Save button
    if ($mybb->request_method === 'post') {
        //apply changes to existing fields first
        $fieldOptionObjects = fieldDataGet(
            ["set_id='{$fieldsetID}'", "field_id='{$fieldID}'"],
            ['field_data_id']
        );

        $valueInput = $mybb->get_input('value', MyBB::INPUT_ARRAY);

        $displayStyleInput = $mybb->get_input('display_style', MyBB::INPUT_ARRAY);

        $displayOrderInput = $mybb->get_input('display_order', MyBB::INPUT_ARRAY);

        if (!$mybb->get_input('create', MyBB::INPUT_INT)) {
            foreach ($fieldOptionObjects as $fieldOptionID => $fieldOptionData) {
                $fieldDataID = (int)$fieldOptionData['field_data_id'];

                $insertData = [];

                (function () use ($mybb, $tablesData, &$insertData, $fieldDataID): void {
                    foreach ($tablesData['myshowcase_field_data'] as $fieldName => $fieldDefinition) {
                        if (!isset($fieldDefinition['input_type'])) {
                            continue;
                        }

                        switch ($fieldDefinition['input_type']) {
                            case FormTypes::Text:
                                $insertData[$fieldName] = $mybb->get_input(
                                    $fieldName,
                                    MyBB::INPUT_ARRAY
                                )[$fieldDataID];

                                break;
                            case FormTypes::Number:
                                $insertData[$fieldName] = (int)$mybb->get_input(
                                    $fieldName,
                                    MyBB::INPUT_ARRAY
                                )[$fieldDataID];

                                break;
                            case FormTypes::SelectMultipleGroups:
                                $insertData[$fieldName] = implode(
                                    ',',
                                    (array)($mybb->get_input(
                                        $fieldName,
                                        MyBB::INPUT_ARRAY
                                    )[$fieldDataID] ?? [])
                                );

                                if (my_strpos(',' . $insertData[$fieldName] . ',', ',-1,') !== false) {
                                    $insertData[$fieldName] = -1;
                                }

                                break;
                        }
                    }
                })();

                fieldDataUpdate($insertData, $fieldDataID);
            }
        }

        if ($mybb->get_input('value') &&
            $fieldOptionValue &&
            $mybb->get_input('display_order', MyBB::INPUT_INT)) {
            $insertData = [
                'set_id' => $fieldsetID,
                'field_id' => $fieldID,
                'field_key' => $db->escape_string($mybb->get_input('field_key')),
            ];

            (function () use ($mybb, $tablesData, &$insertData): void {
                foreach ($tablesData['myshowcase_field_data'] as $fieldName => $fieldDefinition) {
                    if (!isset($fieldDefinition['input_type'])) {
                        continue;
                    }

                    switch ($fieldDefinition['input_type']) {
                        case FormTypes::Text:
                            $insertData[$fieldName] = $mybb->get_input($fieldName);

                            break;
                        case FormTypes::Number:
                            $insertData[$fieldName] = $mybb->get_input($fieldName, MyBB::INPUT_INT);

                            break;
                        case FormTypes::SelectMultipleGroups:
                            $insertData[$fieldName] = implode(',', $mybb->get_input($fieldName, MyBB::INPUT_ARRAY));

                            break;
                    }
                }
            })();

            fieldDataInsert($insertData);
        }

        hooksRun('admin_option_edit_post');

        $cache_manager->update(CacheDataTypes::Config);

        $cache_manager->update(CacheDataTypes::Fields);

        $cache_manager->update(CacheDataTypes::FieldData);

        $cache_manager->update(CacheDataTypes::Fieldsets);

        flash_message($lang->myshowcase_field_update_opt_success, 'success');

        admin_redirect(
            $url->build(['action' => 'editOption', 'set_id' => $fieldsetID, 'field_id' => $fieldID]),
        );
    }

    $form = new Form(
        $url->build(['action' => 'editOption', 'set_id' => $fieldsetID, 'field_id' => $fieldID]),
        'post',
        'editOption'
    );

    $formContainer = new FormContainer($lang->myshowcase_field_list);

    $formContainer->output_row_header(
        $lang->myshowcase_field_fdid,
        ['width' => '1%', 'class' => 'align_center']
    );

    $formContainer->output_row_header(
        $lang->myshowcase_field_option_value_id,
        ['width' => '10%', 'class' => 'align_center']
    );

    $formContainer->output_row_header(
        $lang->myshowcase_field_option_value,
        ['width' => '10%', 'class' => 'align_center']
    );

    $formContainer->output_row_header(
        $lang->myshowcase_field_display_style,
        ['width' => '15%', 'class' => 'align_center']
    );

    $formContainer->output_row_header(
        $lang->myshowcase_field_AllowedGroupsFill,
        ['width' => '10%', 'class' => 'align_center']
    );

    $formContainer->output_row_header(
        $lang->myshowcase_field_AllowedGroupsView,
        ['width' => '10%', 'class' => 'align_center']
    );

    $formContainer->output_row_header(
        $lang->myshowcase_field_display_order,
        ['width' => '5%', 'class' => 'align_center']
    );

    $formContainer->output_row_header($lang->controls, ['width' => '5%', 'class' => 'align_center']);

    $maximumViewOrder = 0;

    $queryFields = array_flip(array_keys($tablesData['myshowcase_field_data']));

    unset($queryFields['unique_keys']);

    $fieldOptionObjects = fieldDataGet(
        ["set_id='{$fieldsetID}'", "field_id='{$fieldID}'"],
        array_flip($queryFields),
        ['order_by' => 'display_order']
    );

    if (!$fieldOptionObjects) {
        $formContainer->output_cell(
            $lang->myshowcase_field_no_options,
            ['class' => 'align_center', 'colspan' => 6]
        );

        $formContainer->construct_row();
    } else {
        $groupObjects = (function () use ($lang): array {
            global $cache;

            $groupList = [
                -1 => $lang->all_groups
            ];

            foreach ((array)$cache->read('usergroups') as $groupData) {
                $groupList[(int)$groupData['gid']] = strip_tags($groupData['title']);
            }

            return $groupList;
        })();

        $maximumViewOrder = 1;

        $maximumViewOrder = 0;

        foreach ($fieldOptionObjects as $fieldOptionID => $fieldOptionData) {
            $fieldDataID = (int)$fieldOptionData['field_data_id'];

            $maximumViewOrder = max($maximumViewOrder, $fieldOptionData['display_order']);

            $formContainer->output_cell(
                my_number_format($fieldDataID),
                ['class' => 'align_center']
            );

            $formContainer->output_cell(
                $form->generate_text_box(
                    "value_id[{$fieldDataID}]",
                    $fieldOptionData['value_id'],
                    ['id' => "value_id[{$fieldDataID}]", 'class' => 'field150']
                ),
                ['class' => 'align_center']
            );

            $formContainer->output_cell(
                $form->generate_text_box(
                    "value[{$fieldDataID}]",
                    $fieldOptionData['value'],
                    ['id' => "value[{$fieldDataID}]", 'class' => 'field150']
                ),
                ['class' => 'align_center']
            );

            $formContainer->output_cell(
                $form->generate_text_box(
                    "display_style[{$fieldDataID}]",
                    $fieldOptionData['display_style'],
                    ['id' => "display_style[{$fieldDataID}]", 'class' => 'field250']
                ),
                ['class' => 'align_center']
            );

            $formContainer->output_cell(
                $form->generate_select_box(
                    "allowed_groups_fill[{$fieldDataID}][]",
                    $groupObjects,
                    explode(',', $fieldOptionData['allowed_groups_fill'] ?? ''),
                    ['id' => 'allowed_groups_fill_' . $fieldDataID, 'multiple' => true, 'size' => 5]
                ),
                ['style' => 'text-align: center;']
            );

            $formContainer->output_cell(
                $form->generate_select_box(
                    "allowed_groups_view[{$fieldDataID}][]",
                    $groupObjects,
                    explode(',', $fieldOptionData['allowed_groups_view'] ?? ''),
                    ['id' => 'allowed_groups_view' . $fieldDataID, 'multiple' => true, 'size' => 5]
                ),
                ['style' => 'text-align: center;']
            );

            $formContainer->output_cell(
                $form->generate_numeric_field(
                    "display_order[{$fieldDataID}]",
                    $fieldOptionData['display_order'],
                    ['id' => "display_order[{$fieldDataID}]", 'class' => 'align_center field50', 'min' => 0]
                ),
                ['class' => 'align_center']
            );

            $popup = new PopupMenu("field_{$fieldDataID}", $lang->options);

            $popup->add_item(
                $lang->myshowcase_field_delete,
                $url->build([
                    'action' => 'deleteOption',
                    'field_data_id' => $fieldDataID
                ]),
            );

            $formContainer->output_cell($popup->fetch(), ['class' => 'align_center']);

            $formContainer->construct_row();
        }
    }

    $formContainer->end();

    $form->output_submit_wrapper([$form->generate_submit_button($lang->myshowcase_fields_save_changes)]);

    $form->end();

    echo '<br />';

    $form = new Form(
        $url->build(['action' => 'editOption', 'set_id' => $fieldsetID, 'field_id' => $fieldID]),
        'post',
        'editOption'
    );

    $formContainer = new FormContainer($lang->myshowcase_field_new_option);

    $formContainer->output_row_header(
        $lang->myshowcase_field_option_value . ' *',
        ['width' => '65', 'class' => 'align_center']
    );

    $formContainer->output_row_header(
        $lang->myshowcase_field_display_order,
        ['width' => '65', 'class' => 'align_center']
    );

    $formContainer->output_cell(
        $form->generate_text_box('value', '', ['id' => 'value', 'class' => 'field150']),
        ['class' => 'align_center']
    );

    echo $form->generate_hidden_field('field_key', $fieldDefinition['field_key']);

    echo $form->generate_hidden_field('value_id', ++$maximumViewOrder);

    echo $form->generate_hidden_field('create', 1);

    $formContainer->output_cell(
        $form->generate_numeric_field(
            'display_order',
            ++$maximumViewOrder,
            ['id' => 'display_order', 'class' => 'align_center field50', 'min' => 0]
        ),
        ['class' => 'align_center']
    );

    $formContainer->construct_row();

    hooksRun('admin_option_edit_end');

    $formContainer->end();

    $form->output_submit_wrapper([$form->generate_submit_button($lang->myshowcase_fields_save_changes)]);

    $form->end();
} elseif ($pageAction == 'deleteOption') {
    $fieldDataID = $mybb->get_input('field_data_id', MyBB::INPUT_INT);

    $fieldsetData = fieldsetGet(["field_data_id='{$fieldDataID}'"], ['set_name'], ['limit' => 1]);

    if (!$fieldsetData) {
        flash_message($lang->myShowcaseAdminErrorInvalidFieldset, 'error');

        admin_redirect($url->build());
    }

    $fieldOptionData = fieldDataGet(["field_data_id='{$fieldDataID}'"], ['field_key']);

    if (!$fieldOptionData) {
        flash_message($lang->myshowcase_field_invalid_opt, 'error');

        admin_redirect(
            $url->build(['action' => 'editOption', 'field_data_id' => $fieldDataID]),
        );
    }

    $showcaseObjects = showcaseGet(["field_data_id='{$fieldDataID}'"], [], ['limit' => 1]);

    if (!empty($showcaseObjects)) {
        flash_message($lang->myshowcase_fields_in_use, 'error');

        admin_redirect(
            $url->build(['action' => 'editOption', 'field_data_id' => $fieldDataID]),
        );
    }

    hooksRun('admin_option_delete_start');

    if ($mybb->request_method === 'post') {
        if (!verify_post_check($mybb->get_input('my_post_key'), true)) {
            flash_message($lang->myShowcaseAdminErrorInvalidPostKey, 'error');

            admin_redirect(
                $url->build(['action' => 'editOption', 'field_data_id' => $fieldDataID]),
            );
        }

        if (isset($mybb->input['no'])) {
            admin_redirect(
                $url->build(['action' => 'editOption', 'field_data_id' => $fieldDataID]),
            );
        }

        hooksRun('admin_option_delete_post');

        fieldDataDelete($fieldDataID);

        $cache_manager->update(CacheDataTypes::Config);

        $cache_manager->update(CacheDataTypes::Fields);

        $cache_manager->update(CacheDataTypes::FieldData);

        $cache_manager->update(CacheDataTypes::Fieldsets);

        log_admin_action(['field_data_id' => $fieldDataID]);

        flash_message($lang->myshowcase_field_delete_opt_success, 'success');

        admin_redirect(
            $url->build(['action' => 'editOption', 'field_data_id' => $fieldDataID]),
        );
    }

    $page->output_confirm_action(
        $url->build([
            'action' => 'deleteOption',
            'field_data_id' => $fieldDataID
        ]),
        $lang->sprintf($lang->myShowcaseAdminConfirmFieldsetDelete, $fieldsetData['set_name'])
    );
} elseif ($pageAction == 'deleteField') {
    $fieldsetData = fieldsetGet(["set_id='{$fieldsetID}'"], ['set_name'], ['limit' => 1]);

    if (!$fieldsetData) {
        flash_message($lang->myshowcase_fields_invalid_id, 'error');

        admin_redirect($url->build());
    }

    $fieldDefinition = fieldsGet(["set_id='{$fieldsetID}'", "field_id='{$fieldID}'"], ['field_key'], ['limit' => 1]);

    if (empty($fieldDefinition['field_id'])) {
        flash_message($lang->myshowcase_field_invalid_id, 'error');
        admin_redirect(
            $url->build(['action' => 'viewFields', 'set_id' => $fieldsetID]),
        );
    }

    $showcaseObjects = showcaseGet(["field_set_id='{$fieldsetID}'"]);

    $field_key = $fieldDefinition['field_key'] ?? $fieldDefinition['field_id'];

    $fieldIsInUse = false;

    foreach ($showcaseObjects as $showcaseID => $showcaseData) {
        if (!showcaseDataTableExists($showcaseID)) {
            break;
        }

        if (showcaseDataTableFieldExists($showcaseID, $field_key)) {
            $fieldIsInUse = true;

            break;
        }
    }

    hooksRun('admin_field_delete_start');

    if ($mybb->request_method === 'post') {
        if (!verify_post_check($mybb->get_input('my_post_key'), true)) {
            flash_message($lang->myShowcaseAdminErrorInvalidPostKey, 'error');

            admin_redirect($url->build());
        }

        if (isset($mybb->input['no'])) {
            admin_redirect($url->build(['action' => 'viewFields', 'set_id' => $fieldsetID]));
        }

        hooksRun('admin_field_delete_post');

        languageModify(
            'myshowcase_fs' . $fieldsetID,
            [],
            ['myshowcase_fieldset_field_' . $field_key => '']
        );

        if ($fieldIsInUse) {
            foreach ($showcaseObjects as $showcaseID => $showcaseData) {
                if (!showcaseDataTableExists($showcaseID)) {
                    break;
                }

                if (showcaseDataTableFieldExists($showcaseID, $field_key)) {
                    showcaseDataTableFieldDrop($showcaseID, $field_key);
                }
            }
        }

        fieldsDelete($fieldID);

        $cache_manager->update(CacheDataTypes::Config);

        $cache_manager->update(CacheDataTypes::Fields);

        $cache_manager->update(CacheDataTypes::FieldData);

        $cache_manager->update(CacheDataTypes::Fieldsets);

        log_admin_action(['fieldsetID' => $fieldsetID, 'fieldID' => $fieldID]);

        if (fieldsGet(["set_id='{$fieldsetID}'", "field_id='{$fieldID}'"])) {
            flash_message($lang->myShowcaseAdminErrorFieldDeleteFailed, 'error');
        } else {
            flash_message($lang->myShowcaseAdminSuccessFieldDeleted, 'success');
        }

        admin_redirect($url->build(['action' => 'viewFields', 'set_id' => $fieldsetID]));
    }

    $page->output_confirm_action(
        $url->build(['action' => 'deleteField', 'field_id' => $fieldID, 'set_id' => $fieldsetID]),
        $lang->sprintf(
            $lang->myShowcaseAdminConfirmFieldDelete,
            $field_key
        ) . ($fieldIsInUse ? $lang->myShowcaseAdminConfirmFieldDeleteExisting : '')
    );
} else {
    $page->output_header($lang->myshowcase_admin_fields);

    $page->output_nav_tabs($pageTabs, 'myShowcaseAdminFieldSets');

    hooksRun('admin_fields_start');

    $formContainer = new FormContainer($lang->myshowcase_fields_title);

    $formContainer->output_row_header($lang->myshowcase_fields_id, ['width' => '1%', 'class' => 'align_center']);

    $formContainer->output_row_header($lang->myshowcase_fields_name, ['width' => '15%', 'class' => 'align_center']
    );

    $formContainer->output_row_header(
        $lang->myshowcase_fields_count,
        ['class' => 'align_center']
    );

    $formContainer->output_row_header(
        $lang->myshowcase_fields_assigned_to,
        ['width' => '10%', 'class' => 'align_center']
    );

    $formContainer->output_row_header(
        $lang->myshowcase_fields_used_by,
        ['width' => '10%', 'class' => 'align_center']
    );

    $formContainer->output_row_header(
        $lang->myshowcase_fields_lang_exists,
        ['width' => '10%', 'class' => 'align_center']
    );

    $formContainer->output_row_header($lang->controls, ['width' => '10%', 'class' => 'align_center']);

    $fieldsetObjects = fieldsetGet([], ['set_id', 'set_name'], ['order_by' => 'set_name']);

    if (!$fieldsetObjects) {
        $formContainer->output_cell($lang->myshowcase_fields_no_fieldsets, ['colspan' => 6]);

        $formContainer->construct_row();
    } else {
        foreach ($fieldsetObjects as $fieldsetID => $result) {
            $viewOptionsUrl = $url->build(['action' => 'viewFields', 'set_id' => $fieldsetID]);

            foreach (showcaseGet(["field_set_id='{$fieldsetID}'"]) as $showcaseID => $showcaseData) {
                if (showcaseDataTableExists($showcaseID)) {
                    $can_edit = false;
                }
            }

            $totalUsedOn = showcaseGet(
                ["field_set_id='{$fieldsetID}'"],
                ['COUNT(showcase_id) AS totalUsedOn'],
                ['limit' => 1, 'group_by' => 'showcase_id']
            )['totalUsedOn'] ?? 0;

            $totalFields = fieldsGet(
                ["set_id='{$fieldsetID}'"],
                ['COUNT(field_id) AS totalFields'],
                ['limit' => 1, 'group_by' => 'field_id']
            )['totalFields'] ?? 0;

            $totalTablesUsedOn = 0;

            foreach (showcaseGet(["field_set_id='{$fieldsetID}'"]) as $showcaseID => $showcaseData) {
                if (showcaseDataTableExists($showcaseID)) {
                    ++$totalTablesUsedOn;
                }
            }

            // Build popup menu
            $popup = new PopupMenu("fieldset_{$fieldsetID}", $lang->options);

            $popup->add_item(
                $lang->edit,
                $url->build(['action' => 'edit', 'set_id' => $fieldsetID]),
            );

            $popup->add_item(
                'View Fields',
                $viewOptionsUrl,
            );

            $popup->add_item(
                $lang->delete,
                $url->build(['action' => 'delete', 'set_id' => $fieldsetID]),
            );

            //grab status images for language file
            $languageFile = $languagePath . '/myshowcase_fs' . $fieldsetID . '.lang.php';

            if (file_exists($languageFile)) {
                if (is_writable($languageFile)) {
                    $statusImage = "styles/{$page->style}/images/icons/tick.png";

                    $statusText = $lang->myshowcase_fields_lang_exists_yes;
                } else {
                    $statusImage = "styles/{$page->style}/images/icons/warning.png";

                    $statusText = $lang->myshowcase_fields_lang_exists_write;
                }
            } else {
                $statusImage = "styles/{$page->style}/images/icons/error.png";

                $statusText = $lang->myshowcase_fields_lang_exists_no;
            }

            $formContainer->output_cell($fieldsetID, ['class' => 'align_center']);

            $formContainer->output_cell(
                "<a href='{$viewOptionsUrl}'>{$result['set_name']}</a>",
            );

            $formContainer->output_cell($totalFields, ['class' => 'align_center']);

            $formContainer->output_cell($totalUsedOn, ['class' => 'align_center']);

            $formContainer->output_cell($totalTablesUsedOn, ['class' => 'align_center']);

            $formContainer->output_cell(
                '<img src="' . $statusImage . '" title="' . $statusText . '">',
                ['class' => 'align_center']
            );

            $formContainer->output_cell($popup->fetch(), ['class' => 'align_center']);

            $formContainer->construct_row();
        }
    }

    hooksRun('admin_fields_end');

    $formContainer->end();

    $page->output_footer();
}

//todo review hooks here