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

/**
 * Collections
 */

//main words
$l['myshowcase_fields_title'] = 'Collections';
$l['myshowcase_fields_new'] = 'New Collection';

//headers
$l['myshowcase_fields_id'] = 'ID';
$l['myshowcase_fields_name'] = 'Name';
$l['myshowcase_fields_count'] = 'Field Count';
$l['myshowcase_fields_used_by'] = 'In Use By';
$l['myshowcase_fields_assigned_to'] = 'Assigned To';

//editing
$l['myshowcase_fields_save_changes'] = 'Save Changes';

//messages
$l['myshowcase_fields_invalid_name'] = 'The selected collection name is already in use.';
$l['myshowcase_fields_invalid_id'] = 'The selected collection ID is invalid.';
$l['myshowcase_fields_no_collections'] = 'There are no collections present.';
$l['myshowcase_fields_already_exists'] = 'The selected collection name or file is already in use.';

$l['myshowcase_fields_add_success'] = 'The collection was successfully added.';
$l['myshowcase_fields_edit_success'] = 'The collection was successfully edited.';
$l['myshowcase_fields_delete_success'] = 'The collection was successfully deleted.';
$l['myshowcase_fields_update_success'] = 'The collection was successfully updated or added.';

$l['myshowcase_fields_add_failed'] = 'The collection not added.';
$l['myshowcase_fields_edit_failed'] = 'The collection was not edited.';
$l['myshowcase_fields_delete_failed'] = 'The collection was not deleted.';

$l['myshowcase_fields_confirm'] = 'Confirm';
$l['myshowcase_fields_confirm_delete'] = 'Confirm Delete';

$l['myshowcase_fields_no_fields'] = 'The specified collection has no fields.';

$l['myshowcase_fields_lang_not_writable'] = 'The language folder, "{1}",  is not writable. Please corect and try again.';
$l['myshowcase_fields_lang_exists_no'] = 'The default language file for this collection does not exist.';
$l['myshowcase_fields_lang_exists_yes'] = 'The default language file for this collection exists.';
$l['myshowcase_fields_lang_exists_write'] = 'The default language file for this collection exists but is not writable.';
$l['myshowcase_fields_lang_exists'] = 'Language File';

/**
 * FIELDS
 */

$l['myshowcase_field_new'] = 'New Field';
$l['myshowcase_field_list'] = 'Current Fields';

//headers
$l['myshowcase_field_fdid'] = 'FDID';
$l['myshowcase_field_value'] = 'Value';
$l['myshowcase_field_valueid'] = 'Value ID';

$l['myshowcase_collection_fields'] = 'Fields';
$l['myshowcase_field_options'] = 'Options';
$l['myshowcase_field_delete'] = 'Delete';
$l['myshowcase_field_edit_options'] = 'Edit Options';

//messages
$l['myshowcase_field_invalid_name'] = 'The selected field name is invalid or already in use.';
$l['myshowcase_field_invalid_id'] = 'The selected field ID is invalid.';
$l['myshowcase_field_required_not_filled'] = 'The required fields are not populated or are invalid.';

$l['myshowcase_field_add_success'] = 'The field was successfully added.';
$l['myshowcase_field_edit_success'] = 'The field was successfully edited.';
$l['myshowcase_field_update_success'] = 'The field was successfully updated or added.';

$l['myshowcase_field_add_opt_success'] = 'The field option was successfully added.';
$l['myshowcase_field_edit_opt_success'] = 'The field option was successfully edited.';

$l['myshowcase_field_delete_opt_success'] = 'The field option was successfully deleted.';
$l['myshowcase_field_delete_opt_confirm'] = 'Are you sure you want to delete the option "{1}"? {2} entries use it: they keep the stored value and show the field as empty.';
$l['myshowcase_field_update_opt_success'] = 'The field option was successfully updated or added.';

$l['myshowcase_field_confirm'] = 'Confirm';
$l['myshowcase_field_confirm_delete'] = 'Confirm Delete';

$l['myshowcase_field_confirm_delete_long'] = 'You are about to delete a field titled "{1}"';
$l['myshowcase_field_no_options'] = 'The specified field has no options.';
$l['myshowcase_field_new_option'] = 'New Field Option';

$l['myshowcase_field_option_value_id'] = 'Value Identifier';
$l['myshowcase_field_option_value'] = 'Display Value';
$l['myshowcase_field_display_style'] = 'Display Style';
$l['myshowcase_field_display_order'] = 'Display Order';
$l['myshowcase_field_AllowedGroupsFill'] = 'Allowed Fill Groups';
$l['myshowcase_field_AllowedGroupsView'] = 'Allowed View Groups';
$l['myshowcase_field_invalid_opt'] = 'The selected field option is invalid.';

$l['myshowcase_field_year_order'] = 'For the DATE field the MIN and MAX values need to be in the proper order.';

$l = array_merge([
    'myshowcase_admin_field_sets' => 'Collections',
    'myshowcase_admin_field_sets_description' => 'View and manage fields sets for showcases.',

    'myshowcase_admin_field_sets_new' => 'New Collection',
    'myshowcase_admin_field_sets_new_description' => 'Create a new collection for showcases.',
    'myshowcase_admin_field_sets_new_form_name' => 'Name',
    'myshowcase_admin_field_sets_new_form_name_description' => 'The name of the collection.',

    'myshowcase_admin_field_sets_edit' => 'Edit Collection',
    'myshowcase_admin_field_sets_edit_description' => 'Edit a collection for showcases.',

    'myshowcase_admin_fields' => 'Fields',
    'myshowcase_admin_fields_description' => 'View and manage fields for showcases.',

    'myshowcase_admin_fields_new' => 'New Field',
    'myshowcase_admin_fields_new_description' => 'Create a new field for this collection.',
    'myshowcase_admin_fields_create_update_form_field_key' => 'Field Key',
    'myshowcase_admin_fields_create_update_form_field_key_description' => 'The unique table field key for this field.',
    'myshowcase_admin_fields_create_update_form_placeholder' => 'Placeholder',
    'myshowcase_admin_fields_create_update_form_placeholder_description' => 'The placeholder to use for this field. Leave empty to use the language system.',
    'myshowcase_admin_fields_create_update_form_field_label' => 'Label',
    'myshowcase_admin_fields_create_update_form_field_label_description' => 'The label to use for this field. Leave empty to use the language system.',
    'myshowcase_admin_fields_create_update_form_description' => 'Description',
    'myshowcase_admin_fields_create_update_form_description_description' => 'The description for this field, displayed in the create and update pages',
    'myshowcase_admin_fields_create_update_form_html_type' => 'HTML Type',
    'myshowcase_admin_fields_create_update_form_html_type_description' => 'The HTML type of the field.',
    'myshowcase_admin_fields_create_update_form_field_type' => 'Database Type',
    'myshowcase_admin_fields_create_update_form_field_type_description' => 'The database table field type.',
    'myshowcase_admin_fields_create_update_form_file_capture' => 'File Capture',
    'myshowcase_admin_fields_create_update_form_file_capture_description' => 'Select a capture method for file fields.',
    'myshowcase_admin_fields_create_update_form_allow_multiple_values' => 'Allow Multiple Values',
    'myshowcase_admin_fields_create_update_form_allow_multiple_values_description' => 'If is_enabled, users will be able to input multiple values for this field.<br/>
    For text, this means to separate values using a comma.<br/>
    For text area, this means to separate values using per line.',
    'myshowcase_admin_fields_create_update_form_regular_expression' => 'Regular Expression',
    'myshowcase_admin_fields_create_update_form_regular_expression_description' => 'Enter a regular expression that should be matched to validate the user input. You must make sure the regular expression is valid and safe. Empty for anything.<br/>
    It must be a valid PHP regular expression, to be passed on to the <a href="https://www.php.net/manual/en/function.preg-match.php">`preg_match()` function as the `pattern` argument</a>.<br />
     <b>Example:</b> /^[a-zA-Z0-9\s]+$/',
    'myshowcase_admin_fields_create_update_form_input_pattern' => 'Input Pattern',
    'myshowcase_admin_fields_create_update_form_input_pattern_description' => 'Enter a regular expression that should be used for the HTML input field pattern element. You must make sure the regular expression is valid and safe—no validation is performed. Empty for anything.<br/>
    It must be a valid JavaScript regular expression, as used by the <a href="https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/RegExp">RegExp</a> type, and as documented in the MDN Web Docs <a href="https://developer.mozilla.org/en-US/docs/Web/JavaScript/Guide/Regular_expressions">guide on regular expressions</a>.<br />
     <b>Example:</b> ^[A-Za-z0-9\s]+$',
    'myshowcase_admin_fields_create_update_form_display_in_create_update_page' => 'Display in Create/Update Page',
    'myshowcase_admin_fields_create_update_form_display_in_create_update_page_description' => 'Display this field in the create/update page.',
    'myshowcase_admin_fields_create_update_form_display_in_view_page' => 'Display in View Page',
    'myshowcase_admin_fields_create_update_form_display_in_view_page_description' => 'Display this field in the view page.',
    'myshowcase_admin_fields_create_update_form_display_in_main_page' => 'Display in Main Page',
    'myshowcase_admin_fields_create_update_form_display_in_main_page_description' => 'Display this field in the main page.',
    'myshowcase_admin_fields_create_update_form_minimum_length' => 'Minimum Length',
    'myshowcase_admin_fields_create_update_form_minimum_length_description' => 'The minimum length of the field.',
    'myshowcase_admin_fields_create_update_form_maximum_length' => 'Maximum Length',
    'myshowcase_admin_fields_create_update_form_maximum_length_description' => 'The maximum length of the field.',
    'myshowcase_admin_fields_create_update_form_minimum_size' => 'Minimum Size',
    'myshowcase_admin_fields_create_update_form_minimum_size_description' => 'The minimum file size of the field.',
    'myshowcase_admin_fields_create_update_form_maximum_size' => 'Maximum Size',
    'myshowcase_admin_fields_create_update_form_maximum_l_size_description' => 'The maximum file size of the field in kilobytes (1000 KB =1 MB).',
    'myshowcase_admin_fields_create_update_form_step_size' => 'Step Size',
    'myshowcase_admin_fields_create_update_form_step_size_description' => 'The step size for numeric fields in kilobytes (1000 KB =1 MB).',
    'myshowcase_admin_fields_create_update_form_input_size' => 'Input Size',
    'myshowcase_admin_fields_create_update_form_input_size_description' => 'The input size for text based input fields.',
    'myshowcase_admin_fields_create_update_form_allowed_groups_fill' => 'Allowed Fill Groups',
    'myshowcase_admin_fields_create_update_form_allowed_groups_fill_description' => 'The groups that can fill this field.',
    'myshowcase_admin_fields_create_update_form_allowed_groups_view' => 'Allowed View Groups',
    'myshowcase_admin_fields_create_update_form_allowed_groups_view_description' => 'The groups that can view this field.',
    'myshowcase_admin_fields_create_update_form_default_value' => 'Default Value',
    'myshowcase_admin_fields_create_update_form_default_value_description' => 'The default value of the field.',
    'myshowcase_admin_fields_create_update_form_default_type' => 'Default Type',
    'myshowcase_admin_fields_create_update_form_default_type_description' => 'The default value type for this field.',
    'myshowcase_admin_fields_create_update_form_default_type_as_defined' => 'As Defined',
    'myshowcase_admin_fields_create_update_form_default_type_null' => 'NULL',
    'myshowcase_admin_fields_create_update_form_default_type_time_stamp' => 'Time Stamp',
    'myshowcase_admin_fields_create_update_form_default_type_uuid' => 'UUID',
    'myshowcase_admin_fields_create_update_form_display_order' => 'Form Order',
    'myshowcase_admin_fields_create_update_form_display_order_description' => 'The order for rendering this field in the create and update pages.',
    'myshowcase_admin_fields_create_update_form_render_order' => 'Render Order',
    'myshowcase_admin_fields_create_update_form_render_order_description' => 'The order for rendering this field in the main and view pages.',
    'myshowcase_admin_fields_create_update_form_format' => 'Format',
    'myshowcase_admin_fields_create_update_form_format_description' => 'Apply a function format on display.',
    'myshowcase_admin_fields_create_update_form_is_required' => 'Is Required',
    'myshowcase_admin_fields_create_update_form_is_required_description' => 'Make this field required.',
    'myshowcase_admin_fields_create_update_form_parse' => 'Parse',
    'myshowcase_admin_fields_create_update_form_parse_description' => 'Parse the content of this field using the MyBB parser.',
    'myshowcase_admin_summary_add_edit_parser' => 'Parser Options',
    'myshowcase_admin_fields_create_update_form_parser_enable' => 'Enable Parser',
    'myshowcase_admin_fields_create_update_form_parser_allow_html' => 'Allow HTML',
    'myshowcase_admin_fields_create_update_form_parser_allow_mycode' => 'Allow MyCode',
    'myshowcase_admin_fields_create_update_form_parser_allow_smiles' => 'Allow Smiles',
    'myshowcase_admin_fields_create_update_form_parser_allow_image_code' => 'Allow Image MyCode',
    'myshowcase_admin_fields_create_update_form_parser_allow_video_code' => 'Allow Video MyCode',
    'myshowcase_admin_fields_create_update_form_parser_affects_message_length' => 'Parser Affects Message Length',
    'myshowcase_admin_fields_create_update_form_enable_search' => 'Enable Search',
    'myshowcase_admin_fields_create_update_form_enable_search_description' => 'Enable searching the content of this field.',
    'myshowcase_admin_fields_create_update_form_enable_slug' => 'Enable Slug',
    'myshowcase_admin_fields_create_update_form_enable_slug_description' => 'Enable generating slugs from this field.',
    'myshowcase_admin_fields_create_update_form_enable_subject' => 'Enable Subject',
    'myshowcase_admin_fields_create_update_form_enable_description' => 'Enable Description.',
    'myshowcase_admin_fields_create_update_form_enable_subject_description' => 'Enable using this field as part of the subject.',
    'myshowcase_admin_fields_create_update_form_enable_editor' => 'Enable Editor',
    'myshowcase_admin_fields_create_update_form_enable_editor_description' => 'Enable the rich text editor for this field.',
    'myshowcase_admin_fields_create_update_form_read_only' => 'Read Only',
    'myshowcase_admin_fields_create_update_form_read_only_description' => 'Make this field read only, users will not be able to edit it.',
    'myshowcase_admin_fields_create_update_form_filter_on_save' => 'Filter On Save',
    'myshowcase_admin_fields_create_update_form_filter_on_save_description' => 'Apply a function filter on save.',

    'myshowcase_admin_fields_edit' => 'Edit Field',
    'myshowcase_admin_fields_edit_description' => 'Edit a field for this collection.',

    'myshowcase_admin_fields_options' => 'Field Options',
    'myshowcase_admin_fields_options_description' => 'View and manage field options for this field.',

    'myshowcase_admin_error_invalid_collection' => 'Invalid collection',
    'myshowcase_admin_error_duplicated_name' => 'The selected name is already in use.',
    'myshowcase_admin_error_collection_delete_failed' => 'Can not perform that action, specified collection is being used by a showcase.',
    'myshowcase_admin_error_collection_delete' => 'The collection was not deleted.',
    'myshowcase_admin_error_invalid_field' => 'Invalid field',
    'myshowcase_admin_error_field_delete_failed' => 'The field deletion failed.',
    'myshowcase_admin_error_invalid_min_max' => 'Invalid minimum/maximum values.',

    'myshowcase_admin_success_new_collection' => 'The collection was successfully added.',
    'myshowcase_admin_success_edit_collection' => 'The collection was successfully updated.',
    'myshowcase_admin_success_collection_deleted' => 'The collection was successfully deleted.',
    'myshowcase_admin_success_new_field' => 'The field was successfully added.',
    'myshowcase_admin_success_edit_field' => 'The field was successfully updated.',
    'myshowcase_admin_success_field_deleted' => 'The field was successfully deleted.',

    'myshowcase_admin_confirm_collection_delete' => 'Are you sure you want to delete the "{1}" collection? ',
    'myshowcase_admin_confirm_field_delete' => 'Are you sure you want to delete the "{1}" field?',
    'myshowcase_admin_confirm_field_delete_existing' => '<br/>The values stored in this field are lost in every showcase that uses the collection: {1}. The entries themselves are kept.',

    'myshowcase_admin_fields_table_header_id' => 'FID',
    'myshowcase_admin_fields_table_header_name' => 'Field key',
    'myshowcase_admin_fields_table_header_label' => 'Field label',
    'myshowcase_admin_fields_table_header_html_type' => 'HTML type',
    'myshowcase_admin_fields_table_header_field_type' => 'Field type',
    'myshowcase_admin_fields_table_header_display_order' => 'Display order',
    'myshowcase_admin_fields_table_header_render_order' => 'Render order',
    'myshowcase_admin_fields_table_header_is_enabled' => 'Enabled',
    'myshowcase_admin_fields_table_header_is_required' => 'Required',
    'myshowcase_admin_fields_table_header_parser_enable' => 'Parser',
    'myshowcase_admin_fields_table_header_enable_search' => 'Search',
    'myshowcase_admin_fields_table_header_enable_slug' => 'Slug',
    'myshowcase_admin_fields_table_header_enable_subject' => 'Subject',
    'myshowcase_admin_fields_table_header_enable_editor' => 'Editor',
], $l);