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
use MyShowcase\Service\CollectionService;
use MyShowcase\Service\FieldService;
use MyShowcase\Service\JsonSync\JsonLock;
use MyShowcase\Service\JsonSync\JsonStatus;
use MyShowcase\Service\JsonSync\JsonSync;
use MyShowcase\Service\JsonSync\JsonUi;
use MyShowcase\Util\UrlUtil;

use function MyShowcase\Admin\buildDbFieldDefinition;
use function MyShowcase\Admin\languageModify;
use function MyShowcase\Plugin\Functions\dataTableStructureGet;
use function MyShowcase\Plugin\Functions\fieldDataDelete;
use function MyShowcase\Plugin\Functions\fieldDataGet;
use function MyShowcase\Plugin\Functions\fieldDataInsert;
use function MyShowcase\Plugin\Functions\fieldDataUpdate;
use function MyShowcase\Plugin\Functions\fieldsDelete;
use function MyShowcase\Plugin\Functions\collectionDelete;
use function MyShowcase\Plugin\Functions\collectionGet;
use function MyShowcase\Plugin\Functions\collectionInsert;
use function MyShowcase\Plugin\Functions\collectionUpdate;
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

$fieldService = new FieldService($cache_manager);

$collectionService = new CollectionService($cache_manager);

$jsonSync = new JsonSync($cache_manager);

$jsonLock = JsonLock::default();

loadLanguage();

$url = new UrlUtil('index.php?module=myshowcase-fields');

$page->add_breadcrumb_item($lang->myshowcase_system, $url->build());

$page->add_breadcrumb_item($lang->myshowcase_admin_field_sets, $url->build());

//get path to non-admin language folder currently in use
$languagePath = str_replace('admin', '', $lang->path . '/' . $lang->language);

$field_id = $mybb->get_input('field_id', MyBB::INPUT_INT);

$collection_id = $mybb->get_input('collection_id', MyBB::INPUT_INT);

$fieldOptionValue = $mybb->get_input('value_id', MyBB::INPUT_INT);

$pageAction = $mybb->get_input('action');

$pageTabs = [
    'myshowcase_admin_field_sets' => [
        'title' => $lang->myshowcase_admin_field_sets,
        'link' => $url->build(),
        'description' => $lang->myshowcase_admin_field_sets_description
    ],
];

if (in_array($pageAction, ['viewFields', 'newField', 'edit_field', 'edit_option'])) {
    $pageTabs['myshowcase_admin_fields'] = [
        'title' => $lang->myshowcase_admin_fields,
        'link' => $url->build(['action' => 'viewFields', 'collection_id' => $collection_id]),
        'description' => $lang->myshowcase_admin_fields_description
    ];

    if (in_array($pageAction, ['edit_option'])) {
        $pageTabs['myshowcase_admin_fields_options'] = [
            'title' => $lang->myshowcase_admin_fields_options,
            'link' => $url->build(
                ['action' => 'edit_option', 'collection_id' => $collection_id, 'field_id' => $field_id]
            ),
            'description' => $lang->myshowcase_admin_fields_options_description
        ];
    } else {
        $pageTabs['myshowcase_admin_fields_new'] = [
            'title' => $lang->myshowcase_admin_fields_new,
            'link' => $url->build(['action' => 'newField', 'collection_id' => $collection_id]),
            'description' => $lang->myshowcase_admin_fields_new_description
        ];

        if ($pageAction === 'edit_field') {
            $pageTabs['myshowcase_admin_fields_edit'] = [
                'title' => $lang->myshowcase_admin_fields_edit,
                'link' => $url->build(
                    ['action' => 'edit_field', 'collection_id' => $collection_id, 'field_id' => $field_id]
                ),
                'description' => $lang->myshowcase_admin_fields_edit_description
            ];
        }
    }
} else {
    $pageTabs['myshowcase_admin_field_sets_new'] = [
        'title' => $lang->myshowcase_admin_field_sets_new,
        'link' => $url->build(['action' => 'new']),
        'description' => $lang->myshowcase_admin_field_sets_new_description
    ];

    if ($pageAction === 'edit') {
        $pageTabs['myshowcase_admin_field_sets_edit'] = [
            'title' => $lang->myshowcase_admin_field_sets_edit,
            'link' => $url->build(['action' => 'edit', 'collection_id' => $collection_id]),
            'description' => $lang->myshowcase_admin_field_sets_edit_description
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

if (in_array($pageAction, ['newField', 'edit_field'])) {
    $newPage = $pageAction === 'newField';

    hooksRun('admin_field_new_edit_start');

    $collectionData = collectionGet(["collection_id='{$collection_id}'"], ['name'], ['limit' => 1]);

    if (!$newPage && empty($collectionData)) {
        flash_message($lang->myshowcase_admin_error_invalid_collection, 'error');

        admin_redirect($pageTabs['myshowcase_admin_field_sets']['link']);
    }

    $queryFields = $tablesData['myshowcase_fields'];

    unset($queryFields['unique_keys']);

    $field_definition = fieldsGet(["field_id='{$field_id}'"], array_keys($queryFields), ['limit' => 1]);

    if (!$newPage && empty($field_definition)) {
        flash_message($lang->myshowcase_admin_error_invalid_field, 'error');

        admin_redirect($url->build(['action' => 'viewFields', 'collection_id' => $collection_id]));
    }

    $page->output_header($pageTabs[$newPage ? 'myshowcase_admin_fields_new' : 'myshowcase_admin_fields_edit']['title']);

    $page->output_nav_tabs($pageTabs, $newPage ? 'myshowcase_admin_fields_new' : 'myshowcase_admin_fields_edit');

    $canEditLanguageFile = true;

    if (!is_writable($languagePath) ||
        (!is_writable($languagePath . '/myshowcase_fs' . $collection_id . '.lang.php') &&
            file_exists($languagePath . '/myshowcase_fs' . $collection_id . '.lang.php'))) {
        $canEditLanguageFile = false;
    }

    $fieldIsInUse = false;

    $showcaseObjects = showcaseGet(["collection_id='{$collection_id}'"]);

    if (!$newPage && $showcaseObjects) {
        foreach ($showcaseObjects as $showcase_id => $showcase_data) {
            if (!showcaseDataTableExists($showcase_id)) {
                break;
            }

            if (showcaseDataTableFieldExists($showcase_id, $field_definition['field_key'])) {
                $fieldIsInUse = true;

                break;
            }
        }
    }

    if ($mybb->request_method === 'post') {
        if (!verify_post_check($mybb->get_input('my_post_key'), true)) {
            flash_message($lang->myshowcase_admin_error_invalid_post_key, 'error');

            admin_redirect($url->build());
        }

        // A field a JSON file manages takes the file's values, whatever the form sent (disabled inputs send nothing).
        $managedValues = $newPage ? [] : $jsonLock->values('field', $field_definition);

        foreach ($managedValues as $managedColumn => $managedValue) {
            if (in_array($managedColumn, ['allowed_groups_fill', 'allowed_groups_view'], true)) {
                $mybb->input[$managedColumn . '_type'] = 'all';
            } elseif ($managedColumn !== 'field_label') {
                $mybb->input[$managedColumn] = $managedValue;
            }
        }

        foreach (['allowed_groups_fill', 'allowed_groups_view'] as $settingName) {
            if ($mybb->get_input($settingName . '_type') === 'all') {
                $mybb->input[$settingName] = [-1];
            } elseif ($mybb->get_input($settingName . '_type') !== 'custom') {
                $mybb->input[$settingName] = [];
            }
        }

        $insertData = [
            'collection_id' => $collection_id,
        ];

        (function () use ($mybb, $tablesData, &$insertData): void {
            foreach ($tablesData['myshowcase_fields'] as $field_name => $field_definition) {
                if (!isset($field_definition['input_type'])) {
                    continue;
                }

                switch ($field_definition['input_type']) {
                    case FormTypes::Text:
                    case FormTypes::Select:
                        $insertData[$field_name] = $mybb->get_input($field_name);

                        break;
                    case FormTypes::YesNo:
                    case FormTypes::Number:
                        $insertData[$field_name] = $mybb->get_input($field_name, MyBB::INPUT_INT);

                        break;
                    case FormTypes::SelectMultipleGroups:
                        $insertData[$field_name] = $mybb->get_input($field_name, MyBB::INPUT_ARRAY);

                        break;
                }
            }
        })();

        if (isset($insertData['field_key'])) {
            $insertData['field_key'] = $fieldService->normalizeKey($insertData['field_key']);
        }

        $errorMessages = [];

        foreach (
            $fieldService->validate(
                $insertData,
                $collection_id,
                $newPage ? 0 : $field_id,
                $newPage ? null : $field_definition['field_key']
            ) as $error
        ) {
            $errorMessages[] = $lang->{$error['key']} ?? $error['message'];
        }

        $fieldDefaultOption = 0;

        if (!$newPage && $insertData['field_key'] === $field_definition['field_key']) {
            unset($insertData['field_key']);
        }

        if ($errorMessages) {
            $page->output_inline_error($errorMessages);
        } else {
            $insertData = hooksRun('admin_field_new_edit_post', $insertData);

            $field_id = $fieldService->save(
                $insertData,
                $collection_id,
                $newPage ? 0 : $field_id,
                ['english' => $mybb->get_input('label')]
            );

            $fieldOptionID = 0;

            if ($fieldDefaultOption) {
                $fieldOptionData = [
                    'collection_id' => $collection_id,
                    'field_id' => $field_id,
                    'field_key' => $db->escape_string($mybb->get_input('field_key')),
                    'value' => 'Not Specified'
                ];

                $fieldOptionID = fieldDataInsert($fieldOptionData);
            }

            log_admin_action(
                ['collectionID' => $collection_id, 'fieldID' => $field_id, 'fieldOptionID' => $fieldOptionID]
            );

            if ($newPage) {
                flash_message($lang->myshowcase_admin_success_new_field, 'success');
            } else {
                flash_message($lang->myshowcase_admin_success_edit_field, 'success');
            }

            admin_redirect($url->build(['action' => 'viewFields', 'collection_id' => $collection_id]));
        }
    } elseif (!empty($field_definition)) {
        foreach (['allowed_groups_fill', 'allowed_groups_view'] as $settingKey) {
            $mybb->input[$settingKey] = explode(',', $field_definition[$settingKey] ?? '');
        }
    }

    foreach (['allowed_groups_fill', 'allowed_groups_view'] as $settingName) {
        $mybb->input[$settingName] = array_filter(
            array_map('intval', $mybb->get_input($settingName, MyBB::INPUT_ARRAY))
        );
    }

    $mybb->input = array_merge($field_definition, $mybb->input);

    $form = new Form(
        $pageTabs[$newPage ? 'myshowcase_admin_fields_new' : 'myshowcase_admin_fields_edit']['link'],
        'post',
        $newPage ? 'myshowcase_admin_fields_new' : 'myshowcase_admin_fields_edit'
    );

    echo $form->generate_hidden_field('my_post_key', $mybb->post_code);

    $formContainer = new FormContainer(
        $pageTabs[$newPage ? 'myshowcase_admin_fields_new' : 'myshowcase_admin_fields_edit']['title']
    );

    $fieldManagedColumns = $newPage ? [] : $jsonLock->managedColumns('field', $field_definition);

    if (!$newPage) {
        $formContainer->output_row(
            JsonUi::text('json_file'),
            '',
            $field_definition['json_file'] !== ''
                ? htmlspecialchars(JsonUi::text('managed_by', $field_definition['json_file']))
                : htmlspecialchars(JsonUi::text('custom')),
            'json_file_info'
        );
    }

    foreach ($tablesData['myshowcase_fields'] as $field_name => $field_definition) {
        if (!isset($field_definition['input_type']) || isset($field_definition['quick_setting'])) {
            continue;
        }

        $formInput = '';

        switch ($field_definition['input_type']) {
            case FormTypes::Text:
                $formInput .= $form->generate_text_box(
                    $field_name,
                    $mybb->get_input($field_name),
                    ['id' => $field_name . '" maxlength="' . ($field_definition['size'] ?? '')]
                );

                break;
            case FormTypes::Number:
                $formInput = $form->generate_numeric_field(
                    $field_name,
                    $mybb->get_input($field_name, MyBB::INPUT_INT),
                    [
                        'id' => $field_name,
                        'min' => empty($field_definition['unsigned']) ? '' : 0,
                        'max' => $field_definition['size'] ?? '',
                        'class' => $field_definition['class'] ?? ''
                    ]
                );

                break;
            case FormTypes::Select:
                $formInput = $form->generate_select_box(
                    $field_name,
                    $field_definition['formFunction'](),
                    $mybb->get_input($field_name),
                    [
                        'id' => $field_name,
                        'class' => $field_definition['class'] ?? ''
                    ]
                );

                break;
            case FormTypes::SelectMultipleGroups:
                $formInput = $groupsSelectFunction($field_name);

                break;
            case FormTypes::YesNo:
                $formInput = $form->generate_yes_no_radio(
                    $field_name,
                    $mybb->get_input($field_name, MyBB::INPUT_INT),
                    [
                        'id' => $field_name,
                        'class' => $field_definition['class'] ?? ''
                    ]
                );

                break;
        }

        $isManaged = in_array($field_name, $fieldManagedColumns, true) ||
            ($field_name === 'field_label' && in_array('field_label', $fieldManagedColumns, true));

        $formContainer->output_row(
            ($lang->{'myshowcase_admin_fields_create_update_form_' . $field_name} ?? 'myshowcase_admin_fields_create_update_form_' . $field_name) . (empty($field_definition['formRequired']) ? '' : '<em>*</em>'),
            $lang->{'myshowcase_admin_fields_create_update_form_' . $field_name . '_description'} . ($isManaged ? JsonUi::managedNote() : ''),
            $isManaged ? JsonUi::disable($formInput) : $formInput,
            $field_name,
            ['id' => $field_name . '_row']
        );
    }

    hooksRun('admin_field_new_edit_end');

    $formContainer->end();

    $form->output_submit_wrapper([
        $form->generate_submit_button($lang->myshowcase_admin_button_submit),
        $form->generate_reset_button($lang->myshowcase_admin_button_reset)
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

    hooksRun('admin_collection_new_edit_start');

    $collectionData = collectionGet(["collection_id='{$collection_id}'"], ['name', 'json_file', 'json_version'], ['limit' => 1]);

    if (!$newPage && empty($collectionData)) {
        flash_message($lang->myShowcaseAdminErrorInvalidShowcase, 'error');

        admin_redirect($pageTabs['myshowcase_admin_field_sets']['link']);
    }

    $page->output_header($lang->myshowcase_admin_field_sets);

    $page->output_nav_tabs(
        $pageTabs,
        $newPage ? 'myshowcase_admin_field_sets_new' : 'myshowcase_admin_field_sets_edit'
    );

    if ($newPage) {
        echo '<p><a class="button" href="' . JsonUi::url(['action' => 'create', 'type' => 'collection']) . '">'
            . htmlspecialchars(JsonUi::text('create_from_json')) . '</a></p>';
    }

    if ($mybb->request_method === 'post') {
        if (!verify_post_check($mybb->get_input('my_post_key'), true)) {
            flash_message($lang->myshowcase_admin_error_invalid_post_key, 'error');

            admin_redirect($url->build());
        }

        // Choosing another JSON file (or None) goes to the preview (or detach) page instead of saving this form.
        if (!$newPage && $mybb->get_input('json_file') !== (string)$collectionData['json_file']) {
            flash_message(JsonUi::text('other_changes_not_saved'), 'error');

            admin_redirect(
                $mybb->get_input('json_file') === ''
                    ? JsonUi::url(['action' => 'detach', 'type' => 'collection', 'id' => $collection_id], false)
                    : JsonUi::url([
                        'action' => 'preview', 'type' => 'collection', 'id' => $collection_id, 'mode' => 'link',
                        'file' => $mybb->get_input('json_file'),
                    ], false)
            );
        }

        $managedValues = $newPage ? [] : $jsonLock->values('collection', $collectionData);

        if (isset($managedValues['name'])) {
            $mybb->input['name'] = $managedValues['name'];
        }

        $errorMessages = [];

        foreach ($collectionService->validate($mybb->get_input('name'), $newPage ? 0 : $collection_id) as $error) {
            $errorMessages[] = $lang->{$error['key']} ?? $error['message'];
        }

        if (!is_writable($languagePath)) {
            $errorMessages[] = $lang->sprintf(
                $lang->myshowcase_fields_lang_not_writable,
                $lang->path . '/' . $lang->language . '/'
            );
        }

        $collectionData = [
            'name' => $db->escape_string($mybb->get_input('name')),
        ];

        if ($errorMessages) {
            $page->output_inline_error($errorMessages);
        } else {
            $collectionData = hooksRun('admin_collection_new_edit_post', $collectionData);

            $collection_id = $collectionService->save($collectionData, $newPage ? 0 : $collection_id);

            log_admin_action(['collectionID' => $collection_id]);

            if ($newPage) {
                flash_message($lang->myshowcase_admin_success_new_collection, 'success');
            } else {
                flash_message($lang->myshowcase_admin_success_edit_collection, 'success');
            }

            admin_redirect($url->build());
        }
    }

    $mybb->input = array_merge($collectionData, $mybb->input);

    $form = new Form(
        $pageTabs[$newPage ? 'myshowcase_admin_field_sets_new' : 'myshowcase_admin_field_sets_edit']['link'],
        'post',
        $newPage ? 'myshowcase_admin_field_sets_new' : 'myshowcase_admin_field_sets_edit'
    );

    echo $form->generate_hidden_field('my_post_key', $mybb->post_code);

    $formContainer = new FormContainer(
        $pageTabs[$newPage ? 'myshowcase_admin_field_sets_new' : 'myshowcase_admin_field_sets_edit']['title']
    );

    $nameIsManaged = !$newPage && in_array('name', $jsonLock->managedColumns('collection', $collectionData), true);

    $nameInput = $form->generate_text_box('name', $mybb->get_input('name'), ['id' => 'name']);

    $formContainer->output_row(
        $lang->myshowcase_admin_field_sets_new_form_name . '<em>*</em>',
        $lang->myshowcase_admin_field_sets_new_form_name_description . ($nameIsManaged ? JsonUi::managedNote() : ''),
        $nameIsManaged ? JsonUi::disable($nameInput) : $nameInput,
        'name'
    );

    if (!$newPage) {
        $formContainer->output_row(
            JsonUi::text('json_file'),
            JsonUi::text('json_file_description'),
            JsonUi::fileSelect($form, 'json_file', 'collection', (string)$collectionData['json_file']),
            'json_file'
        );
    }

    hooksRun('admin_collection_new_edit_end');

    $formContainer->end();

    $form->output_submit_wrapper([
        $form->generate_submit_button($lang->myshowcase_admin_button_submit),
        $form->generate_reset_button($lang->myshowcase_admin_button_reset)
    ]);

    $form->end();

    $page->output_footer();
} elseif ($pageAction === 'viewFields') {
    $collectionData = collectionGet(["collection_id='{$collection_id}'"], ['name', 'json_file', 'json_version', 'json_hash'], ['limit' => 1]);

    if (empty($collectionData)) {
        flash_message($lang->myshowcase_fields_invalid_id, 'error');

        admin_redirect($url->build());
    }

    $page->add_breadcrumb_item(
        $lang->sprintf($lang->myshowcase_admin_edit_collection, $collectionData['name']),
        $url->build()
    );

    $page->output_header($lang->myshowcase_admin_fields);

    $page->output_nav_tabs($pageTabs, 'myshowcase_admin_fields');

    hooksRun('admin_view_fields_start');

    if ($mybb->request_method === 'post') {
        foreach (fieldsGet(["collection_id='{$collection_id}'"]) as $field_id => $insertData) {
            $insertData = [];

            foreach ($tablesData['myshowcase_fields'] as $field_name => $field_definition) {
                if (!empty($field_definition['quick_setting']) ||
                    in_array($field_name, ['display_order', 'render_order'])) {
                    $insertData[$field_name] = $mybb->get_input($field_name, MyBB::INPUT_ARRAY)[$field_id] ?? 0;
                }
            }

            $insertData = hooksRun('admin_view_fields_update_post', $insertData);

            if ($insertData) {
                // The service puts the file's values back for the columns a JSON file manages.
                $fieldService->save($insertData, $collection_id, $field_id);
            }
        }

        flash_message($lang->myshowcase_field_update_success, 'success');

        admin_redirect($url->build(['action' => 'viewFields', 'collection_id' => $collection_id]));
    }

    $form = new Form(
        $url->build(['action' => 'viewFields', 'collection_id' => $collection_id]),
        'post',
        'viewFields'
    );

    $formContainer = new FormContainer($lang->myshowcase_field_list);

    $formContainer->output_row_header(
        $lang->myshowcase_admin_fields_table_header_id,
        ['width' => '1%', 'class' => 'align_center']
    );

    $formContainer->output_row_header(
        $lang->myshowcase_admin_fields_table_header_name,
        ['class' => 'align_center']
    );

    $formContainer->output_row_header(
        $lang->myshowcase_admin_fields_table_header_label,
        ['class' => 'align_center']
    );

    $formContainer->output_row_header(
        $lang->myshowcase_admin_fields_table_header_html_type,
        ['width' => '10%', 'class' => 'align_center']
    );

    $formContainer->output_row_header(
        $lang->myshowcase_admin_fields_table_header_field_type,
        ['width' => '10%', 'class' => 'align_center']
    );

    $formContainer->output_row_header(
        $lang->myshowcase_admin_fields_table_header_display_order,
        ['width' => '10%', 'class' => 'align_center']
    );

    $formContainer->output_row_header(
        $lang->myshowcase_admin_fields_table_header_render_order,
        ['width' => '10%', 'class' => 'align_center']
    );

    foreach ($tablesData['myshowcase_fields'] as $field_name => $field_definition) {
        if (empty($field_definition['quick_setting'])) {
            continue;
        }

        //$key = str_replace(' ', '', ucwords(str_replace('_', ' ', $field_name)));

        $formContainer->output_row_header(
            $lang->{'myshowcase_admin_fields_table_header_' . $field_name} ?? 'myshowcase_admin_fields_table_header_' . $field_name,
            ['width' => '5%', 'class' => 'align_center']
        );
    }

    $formContainer->output_row_header($lang->controls, ['width' => '5%', 'class' => 'align_center']);

    $maximumViewOrder = 0;

    $queryFields = $tablesData['myshowcase_fields'];

    unset($queryFields['unique_keys']);

    $collectionFile = $collectionData['json_file'] !== '' ? $jsonSync->load($collectionData['json_file']) : null;

    if ($collectionFile !== null) {
        echo '<p>' . JsonUi::text('json_file') . ': ' . htmlspecialchars($collectionData['json_file'])
            . JsonUi::rowInfo('collection', (int)$collection_id, $collectionData, $jsonSync->statusOf($collectionData, 'collection')) . '</p>';
    }

    $fieldObjects = fieldsGet(["collection_id='{$collection_id}'"],
        array_keys($queryFields),
        ['order_by' => 'display_order']);

    if (!$fieldObjects) {
        $formContainer->output_cell(
            $lang->myshowcase_fields_no_fields,
            ['class' => 'align_center', 'colspan' => 11]
        );

        $formContainer->construct_row();
    } else {
        $l = [];

        //include_once $languagePath . '/myshowcase_fs' . $collection_id . '.lang.php';

        $maximumViewOrder = 1;

        foreach ($fieldObjects as $field_id => $field_definition) {
            $formContainer->output_cell(my_number_format($field_id), ['class' => 'align_center']);

            $url_edit = $url->build(
                ['action' => 'edit_field', 'field_id' => $field_id, 'collection_id' => $collection_id]
            );

            $fieldBadge = '';

            if ($collectionFile !== null) {
                if ($field_definition['json_file'] === '') {
                    $fieldBadge = JsonUi::customBadge();
                } else {
                    $entryHash = null;

                    foreach ($collectionFile['data']['fields'] ?? [] as $entry) {
                        if ($entry['field_key'] === $field_definition['field_key']) {
                            $entryHash = \MyShowcase\Service\JsonSync\JsonHash::hash($entry);
                        }
                    }

                    $fieldBadge = ' ' . JsonUi::badge(JsonStatus::ofField($field_definition, $collectionFile, $entryHash));
                }
            }

            $fieldManaged = $jsonLock->managedColumns('field', $field_definition);

            $formContainer->output_cell(
                "<a href='{$url_edit}'>{$field_definition['field_key']}</a>" . $fieldBadge,
                ['class' => 'align_left']
            );

            if (empty($field_definition['field_label'])) {
                $field_definition['field_label'] = $l['myshowcase_collection_field_' . $field_definition['field_key']] ?? '';
            }

            $formContainer->output_cell(
                $field_definition['field_label'],
                ['class' => 'align_left']
            );

            $formContainer->output_cell(
                $field_definition['html_type'],
                ['class' => 'align_center']
            );

            $formContainer->output_cell(
                $field_definition['field_type'],
                ['class' => 'align_center']
            );

            $formContainer->output_cell(
                (in_array('display_order', $fieldManaged, true) ? '\\MyShowcase\\Service\\JsonSync\\JsonUi::disable' : 'strval')($form->generate_numeric_field(
                    'display_order[' . $field_id . ']',
                    (int)$field_definition['display_order'],
                    ['class' => 'align_center" style="max-width: 5em;'],
                )),
                ['class' => 'align_center']
            );

            $formContainer->output_cell(
                (in_array('render_order', $fieldManaged, true) ? '\\MyShowcase\\Service\\JsonSync\\JsonUi::disable' : 'strval')($form->generate_numeric_field(
                    'render_order[' . $field_id . ']',
                    (int)$field_definition['render_order'],
                    ['class' => 'align_center" style="max-width: 5em;'],
                )),
                ['class' => 'align_center']
            );

            (function (array $values) use ($tablesData, $field_id, $formContainer, $form, $fieldManaged) {
                foreach ($tablesData['myshowcase_fields'] as $field_name => $field_definition) {
                    if (empty($field_definition['quick_setting'])) {
                        continue;
                    }

                    $checkBox = $form->generate_check_box(
                        $field_name . '[' . $field_id . ']',
                        1,
                        '',
                        ['checked' => $values[$field_name]],
                    );

                    $formContainer->output_cell(
                        in_array($field_name, $fieldManaged, true) ? JsonUi::disable($checkBox) : $checkBox,
                        ['class' => 'align_center']
                    );
                }
            })(
                $field_definition
            );

            $popup = new PopupMenu("field_{$field_id}", $lang->options);

            if (in_array($field_definition['html_type'], [
                FieldHtmlTypes::CheckBox,
                FieldHtmlTypes::Radio,
                FieldHtmlTypes::Select,
            ])) {
                $popup->add_item(
                    $lang->myshowcase_field_options,
                    $url->build(['action' => 'edit_option', 'field_id' => $field_id, 'collection_id' => $collection_id]
                    ),
                );
            }

            $popup->add_item(
                $lang->edit,
                $url_edit,
            );

            $popup->add_item(
                $lang->myshowcase_field_delete,
                $url->build(['action' => 'delete_field', 'field_id' => $field_id, 'collection_id' => $collection_id]),
            );

            if (in_array($field_definition['html_type'], [
                FieldHtmlTypes::CheckBox,
                FieldHtmlTypes::Radio,
                FieldHtmlTypes::Select,
            ])) {
                $popup->add_item(
                    $lang->myshowcase_field_edit_options,
                    $url->build(['action' => 'edit_option', 'field_id' => $field_id, 'collection_id' => $collection_id]
                    ),
                );
            }

            $maximumViewOrder = max($maximumViewOrder, $field_definition['display_order']);

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
    $collectionData = collectionGet(["collection_id='{$collection_id}'"], ['name'], ['limit' => 1]);

    if (!$collectionData) {
        flash_message($lang->myshowcase_admin_error_invalid_collection, 'error');

        admin_redirect($url->build());
    }

    $showcaseObjects = showcaseGet(["collection_id='{$collection_id}'"], [], ['limit' => 1]);

    if (!empty($showcaseObjects)) {
        flash_message($lang->myshowcase_admin_error_collection_delete_failed, 'error');

        admin_redirect($url->build());
    }

    hooksRun('admin_collection_delete_start');

    $collectionName = $collectionData['name'];

    if ($mybb->request_method === 'post') {
        if (!verify_post_check($mybb->get_input('my_post_key'), true)) {
            flash_message($lang->myshowcase_admin_error_invalid_post_key, 'error');

            admin_redirect($url->build());
        }

        if (isset($mybb->input['no'])) {
            admin_redirect($url->build());
        }

        hooksRun('admin_collection_delete_post');

        foreach (fieldsGet(["collection_id='{$collection_id}'"]) as $field_id => $field_definition) {
            fieldsDelete($collection_id);
        }

        collectionDelete($collection_id);

        foreach ((array)$lang->get_languages() as $langfolder => $langname) {
            $languageFile = $lang->path . '/' . $langfolder . '/myshowcase_fs' . $collection_id . '.lang.php';

            if (file_exists($languageFile)) {
                unlink($languageFile);
            }
        }

        $cache_manager->update(CacheDataTypes::Config);

        $cache_manager->update(CacheDataTypes::Fields);

        $cache_manager->update(CacheDataTypes::FieldData);

        $cache_manager->update(CacheDataTypes::Collections);

        log_admin_action(['collectionID' => $collection_id]);

        if (collectionGet(["collection_id='{$collection_id}'"])) {
            flash_message($lang->myshowcase_admin_error_collection_delete, 'error');
        } else {
            flash_message($lang->myshowcase_admin_success_collection_deleted, 'success');
        }

        admin_redirect($url->build());
    }

    $page->output_confirm_action(
        $url->build(['action' => 'delete', 'collection_id' => $collection_id]),
        $lang->sprintf($lang->myshowcase_admin_confirm_collection_delete, $collectionName)
    );
} elseif ($pageAction === 'edit_option') {
    $field_definition = fieldsGet(
        ["field_id='{$field_id}'", "collection_id='{$collection_id}'"],
        ['field_key', 'html_type', 'json_file'],
        ['limit' => 1]
    );

    if (!$field_definition || !in_array($field_definition['html_type'], [
            FieldHtmlTypes::CheckBox,
            FieldHtmlTypes::Radio,
            FieldHtmlTypes::Select,
        ])) {
        flash_message($lang->myshowcase_field_invalid_id, 'error');

        admin_redirect($url->build(['action' => 'viewFields', 'collection_id' => $collection_id]));
    }

    $can_edit = true;

    foreach (showcaseGet(["collection_id='{$collection_id}'"]) as $showcase_id => $showcase_data) {
        if (showcaseDataTableExists($showcase_id)) {
            $can_edit = false;
        }
    }

    $fieldOptionData = collectionGet(["collection_id='{$collection_id}'"], ['name'], ['limit' => 1]);

    $page->add_breadcrumb_item(
        $lang->sprintf(
            $lang->myshowcase_admin_edit_fieldopt,
            $field_definition['field_key'],
            $fieldOptionData['name']
        ),
        $url->build()
    );

    $page->output_header($lang->myshowcase_admin_fields);

    $page->output_nav_tabs($pageTabs, 'myshowcase_admin_fields_options');

    hooksRun('admin_option_edit_start');

    //user clicked Save button
    if ($mybb->request_method === 'post') {
        //apply changes to existing fields first
        $fieldOptionObjects = fieldDataGet(
            ["collection_id='{$collection_id}'", "field_id='{$field_id}'"],
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
                    foreach ($tablesData['myshowcase_field_data'] as $field_name => $field_definition) {
                        if (!isset($field_definition['input_type'])) {
                            continue;
                        }

                        switch ($field_definition['input_type']) {
                            case FormTypes::Text:
                                $insertData[$field_name] = $mybb->get_input(
                                    $field_name,
                                    MyBB::INPUT_ARRAY
                                )[$fieldDataID];

                                break;
                            case FormTypes::Number:
                                $insertData[$field_name] = (int)$mybb->get_input(
                                    $field_name,
                                    MyBB::INPUT_ARRAY
                                )[$fieldDataID];

                                break;
                            case FormTypes::SelectMultipleGroups:
                                $insertData[$field_name] = implode(
                                    ',',
                                    (array)($mybb->get_input(
                                        $field_name,
                                        MyBB::INPUT_ARRAY
                                    )[$fieldDataID] ?? [])
                                );

                                if (my_strpos(',' . $insertData[$field_name] . ',', ',-1,') !== false) {
                                    $insertData[$field_name] = -1;
                                }

                                break;
                        }
                    }
                })();

                $fieldService->saveOption($insertData, $fieldDataID);
            }
        }

        if ($mybb->get_input('value') &&
            $fieldOptionValue &&
            $mybb->get_input('display_order', MyBB::INPUT_INT)) {
            $insertData = [
                'collection_id' => $collection_id,
                'field_id' => $field_id,
                'field_key' => $db->escape_string($mybb->get_input('field_key')),
            ];

            (function () use ($mybb, $tablesData, &$insertData): void {
                foreach ($tablesData['myshowcase_field_data'] as $field_name => $field_definition) {
                    if (!isset($field_definition['input_type'])) {
                        continue;
                    }

                    switch ($field_definition['input_type']) {
                        case FormTypes::Text:
                            $insertData[$field_name] = $mybb->get_input($field_name);

                            break;
                        case FormTypes::Number:
                            $insertData[$field_name] = $mybb->get_input($field_name, MyBB::INPUT_INT);

                            break;
                        case FormTypes::SelectMultipleGroups:
                            $insertData[$field_name] = implode(',', $mybb->get_input($field_name, MyBB::INPUT_ARRAY));

                            break;
                    }
                }
            })();

            $fieldService->saveOption($insertData);
        }

        hooksRun('admin_option_edit_post');

        $cache_manager->update(CacheDataTypes::Config);

        $cache_manager->update(CacheDataTypes::Fields);

        $cache_manager->update(CacheDataTypes::FieldData);

        $cache_manager->update(CacheDataTypes::Collections);

        flash_message($lang->myshowcase_field_update_opt_success, 'success');

        admin_redirect(
            $url->build(['action' => 'edit_option', 'collection_id' => $collection_id, 'field_id' => $field_id]),
        );
    }

    $form = new Form(
        $url->build(['action' => 'edit_option', 'collection_id' => $collection_id, 'field_id' => $field_id]),
        'post',
        'edit_option'
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
        ["collection_id='{$collection_id}'", "field_id='{$field_id}'"],
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

            // The columns of this option the JSON file of the field sets are shown disabled.
            $optionManaged = array_keys($jsonLock->optionValues($field_definition, (int)$fieldOptionData['value_id']));

            $optionInput = static fn(string $column, string $html): string => in_array($column, $optionManaged, true)
                ? JsonUi::disable($html)
                : $html;

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
                $optionInput('value', $form->generate_text_box(
                    "value[{$fieldDataID}]",
                    $fieldOptionData['value'],
                    ['id' => "value[{$fieldDataID}]", 'class' => 'field150']
                )),
                ['class' => 'align_center']
            );

            $formContainer->output_cell(
                $optionInput('display_style', $form->generate_text_box(
                    "display_style[{$fieldDataID}]",
                    $fieldOptionData['display_style'],
                    ['id' => "display_style[{$fieldDataID}]", 'class' => 'field250']
                )),
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
                $optionInput('display_order', $form->generate_numeric_field(
                    "display_order[{$fieldDataID}]",
                    $fieldOptionData['display_order'],
                    ['id' => "display_order[{$fieldDataID}]", 'class' => 'align_center field50', 'min' => 0]
                )),
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
        $url->build(['action' => 'edit_option', 'collection_id' => $collection_id, 'field_id' => $field_id]),
        'post',
        'edit_option'
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

    echo $form->generate_hidden_field('field_key', $field_definition['field_key']);

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

    $collectionData = collectionGet(["field_data_id='{$fieldDataID}'"], ['name'], ['limit' => 1]);

    if (!$collectionData) {
        flash_message($lang->myshowcase_admin_error_invalid_collection, 'error');

        admin_redirect($url->build());
    }

    $fieldOptionData = fieldDataGet(["field_data_id='{$fieldDataID}'"], ['field_key']);

    if (!$fieldOptionData) {
        flash_message($lang->myshowcase_field_invalid_opt, 'error');

        admin_redirect(
            $url->build(['action' => 'edit_option', 'field_data_id' => $fieldDataID]),
        );
    }

    $showcaseObjects = showcaseGet(["field_data_id='{$fieldDataID}'"], [], ['limit' => 1]);

    if (!empty($showcaseObjects)) {
        flash_message($lang->myshowcase_fields_in_use, 'error');

        admin_redirect(
            $url->build(['action' => 'edit_option', 'field_data_id' => $fieldDataID]),
        );
    }

    hooksRun('admin_option_delete_start');

    if ($mybb->request_method === 'post') {
        if (!verify_post_check($mybb->get_input('my_post_key'), true)) {
            flash_message($lang->myshowcase_admin_error_invalid_post_key, 'error');

            admin_redirect(
                $url->build(['action' => 'edit_option', 'field_data_id' => $fieldDataID]),
            );
        }

        if (isset($mybb->input['no'])) {
            admin_redirect(
                $url->build(['action' => 'edit_option', 'field_data_id' => $fieldDataID]),
            );
        }

        hooksRun('admin_option_delete_post');

        fieldDataDelete($fieldDataID);

        $cache_manager->update(CacheDataTypes::Config);

        $cache_manager->update(CacheDataTypes::Fields);

        $cache_manager->update(CacheDataTypes::FieldData);

        $cache_manager->update(CacheDataTypes::Collections);

        log_admin_action(['field_data_id' => $fieldDataID]);

        flash_message($lang->myshowcase_field_delete_opt_success, 'success');

        admin_redirect(
            $url->build(['action' => 'edit_option', 'field_data_id' => $fieldDataID]),
        );
    }

    $page->output_confirm_action(
        $url->build([
            'action' => 'deleteOption',
            'field_data_id' => $fieldDataID
        ]),
        $lang->sprintf($lang->myshowcase_admin_confirm_collection_delete, $collectionData['name'])
    );
} elseif ($pageAction == 'delete_field') {
    $collectionData = collectionGet(["collection_id='{$collection_id}'"], ['name'], ['limit' => 1]);

    if (!$collectionData) {
        flash_message($lang->myshowcase_fields_invalid_id, 'error');

        admin_redirect($url->build());
    }

    $field_definition = fieldsGet(["collection_id='{$collection_id}'", "field_id='{$field_id}'"],
        ['field_key'],
        ['limit' => 1]
    );

    if (empty($field_definition['field_id'])) {
        flash_message($lang->myshowcase_field_invalid_id, 'error');
        admin_redirect(
            $url->build(['action' => 'viewFields', 'collection_id' => $collection_id]),
        );
    }

    if (($deletionBlock = $fieldService->deletionBlock($field_id)) !== null) {
        flash_message($lang->{$deletionBlock['key']} ?? $deletionBlock['message'], 'error');

        admin_redirect($url->build(['action' => 'viewFields', 'collection_id' => $collection_id]));
    }

    $showcaseObjects = showcaseGet(["collection_id='{$collection_id}'"]);

    $field_key = $field_definition['field_key'] ?? $field_definition['field_id'];

    $fieldIsInUse = false;

    foreach ($showcaseObjects as $showcase_id => $showcase_data) {
        if (!showcaseDataTableExists($showcase_id)) {
            break;
        }

        if (showcaseDataTableFieldExists($showcase_id, $field_key)) {
            $fieldIsInUse = true;

            break;
        }
    }

    hooksRun('admin_field_delete_start');

    if ($mybb->request_method === 'post') {
        if (!verify_post_check($mybb->get_input('my_post_key'), true)) {
            flash_message($lang->myshowcase_admin_error_invalid_post_key, 'error');

            admin_redirect($url->build());
        }

        if (isset($mybb->input['no'])) {
            admin_redirect($url->build(['action' => 'viewFields', 'collection_id' => $collection_id]));
        }

        hooksRun('admin_field_delete_post');

        languageModify(
            'myshowcase_fs' . $collection_id,
            [],
            ['myshowcase_collection_field_' . $field_key => '']
        );

        if ($fieldIsInUse) {
            foreach ($showcaseObjects as $showcase_id => $showcase_data) {
                if (!showcaseDataTableExists($showcase_id)) {
                    break;
                }

                if (showcaseDataTableFieldExists($showcase_id, $field_key)) {
                    showcaseDataTableFieldDrop($showcase_id, $field_key);
                }
            }
        }

        fieldsDelete($field_id);

        $cache_manager->update(CacheDataTypes::Config);

        $cache_manager->update(CacheDataTypes::Fields);

        $cache_manager->update(CacheDataTypes::FieldData);

        $cache_manager->update(CacheDataTypes::Collections);

        log_admin_action(['collectionID' => $collection_id, 'fieldID' => $field_id]);

        if (fieldsGet(["collection_id='{$collection_id}'", "field_id='{$field_id}'"])) {
            flash_message($lang->myshowcase_admin_error_field_delete_failed, 'error');
        } else {
            flash_message($lang->myshowcase_admin_success_field_deleted, 'success');
        }

        admin_redirect($url->build(['action' => 'viewFields', 'collection_id' => $collection_id]));
    }

    $page->output_confirm_action(
        $url->build(['action' => 'delete_field', 'field_id' => $field_id, 'collection_id' => $collection_id]),
        $lang->sprintf(
            $lang->myshowcase_admin_confirm_field_delete,
            $field_key
        ) . ($fieldIsInUse ? $lang->myshowcase_admin_confirm_field_delete_existing : '')
    );
} else {
    $page->output_header($lang->myshowcase_admin_fields);

    $page->output_nav_tabs($pageTabs, 'myshowcase_admin_field_sets');

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

    $collectionObjects = collectionGet([], ['collection_id', 'name', 'json_file', 'json_version', 'json_hash'], ['order_by' => 'name']);

    if (!$collectionObjects) {
        $formContainer->output_cell($lang->myshowcase_fields_no_collections, ['colspan' => 6]);

        $formContainer->construct_row();
    } else {
        foreach ($collectionObjects as $collection_id => $result) {
            $viewOptionsUrl = $url->build(['action' => 'viewFields', 'collection_id' => $collection_id]);

            foreach (showcaseGet(["collection_id='{$collection_id}'"]) as $showcase_id => $showcase_data) {
                if (showcaseDataTableExists($showcase_id)) {
                    $can_edit = false;
                }
            }

            $totalUsedOn = showcaseGet(
                ["collection_id='{$collection_id}'"],
                ['COUNT(showcase_id) AS totalUsedOn'],
                ['limit' => 1, 'group_by' => 'showcase_id']
            )['totalUsedOn'] ?? 0;

            $totalFields = fieldsGet(
                ["collection_id='{$collection_id}'"],
                ['COUNT(field_id) AS totalFields'],
                ['limit' => 1, 'group_by' => 'field_id']
            )['totalFields'] ?? 0;

            $totalTablesUsedOn = 0;

            foreach (showcaseGet(["collection_id='{$collection_id}'"]) as $showcase_id => $showcase_data) {
                if (showcaseDataTableExists($showcase_id)) {
                    ++$totalTablesUsedOn;
                }
            }

            $url_edit = $url->build(['action' => 'edit', 'collection_id' => $collection_id]);

            // Build popup menu
            $popup = new PopupMenu("collection_{$collection_id}", $lang->options);

            $popup->add_item(
                $lang->myshowcase_collection_fields,
                $viewOptionsUrl,
            );

            $popup->add_item(
                $lang->edit,
                $url_edit,
            );

            $popup->add_item(
                $lang->delete,
                $url->build(['action' => 'delete', 'collection_id' => $collection_id]),
            );

            $popup->add_item(JsonUi::text('export'), JsonUi::url(['action' => 'export', 'type' => 'collection', 'id' => $collection_id]));

            //grab status images for language file
            $languageFile = $languagePath . '/myshowcase_fs' . $collection_id . '.lang.php';

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

            $formContainer->output_cell($collection_id, ['class' => 'align_center']);

            $formContainer->output_cell(
                "<a href='{$url_edit}'>{$result['name']}</a>" . (
                $result['json_file'] !== ''
                    ? JsonUi::rowInfo('collection', (int)$collection_id, $result, $jsonSync->statusOf($result, 'collection'))
                    : ''
                ),
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