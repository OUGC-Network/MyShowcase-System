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
 * FIELDSETS
 */

//main words
$l['myshowcase_fields_title'] = 'Field Sets';
$l['myshowcase_fields_new'] = 'New Field Set';

//headers
$l['myshowcase_fields_id'] = 'ID';
$l['myshowcase_fields_name'] = 'Name';
$l['myshowcase_fields_count'] = 'Field Count';
$l['myshowcase_fields_used_by'] = 'In Use By';
$l['myshowcase_fields_assigned_to'] = 'Assigned To';

//editing
$l['myshowcase_fields_save_changes'] = 'Save Changes';

//messages
$l['myshowcase_fields_invalid_name'] = 'The selected field set name is already in use.';
$l['myshowcase_fields_invalid_id'] = 'The selected field set ID is invalid.';
$l['myshowcase_fields_no_fieldsets'] = 'There are no field sets present.';
$l['myshowcase_fields_already_exists'] = 'The selected field set name or file is already in use.';

$l['myshowcase_fields_add_success'] = 'The field set was successfully added.';
$l['myshowcase_fields_edit_success'] = 'The field set was successfully edited.';
$l['myshowcase_fields_delete_success'] = 'The field set was successfully deleted.';
$l['myshowcase_fields_update_success'] = 'The field set was successfully updated or added.';

$l['myshowcase_fields_add_failed'] = 'The field set not added.';
$l['myshowcase_fields_edit_failed'] = 'The field set was not edited.';
$l['myshowcase_fields_delete_failed'] = 'The field set was not deleted.';

$l['myshowcase_fields_confirm'] = 'Confirm';
$l['myshowcase_fields_confirm_delete'] = 'Confirm Delete';

$l['myshowcase_fields_no_fields'] = 'The specified field set has no fields.';

$l['myshowcase_fields_lang_not_writable'] = 'The language folder, "{1}",  is not writable. Please corect and try again.';
$l['myshowcase_fields_lang_exists_no'] = 'The default language file for this fieldset does not exist.';
$l['myshowcase_fields_lang_exists_yes'] = 'The default language file for this fieldset exists.';
$l['myshowcase_fields_lang_exists_write'] = 'The default language file for this fieldset exists but is not writable.';
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
    'myShowcaseAdminFieldSets' => 'Field Sets',
    'myShowcaseAdminFieldSetsDescription' => 'View and manage fields sets for showcases.',

    'myShowcaseAdminFieldSetsNew' => 'New Field Set',
    'myShowcaseAdminFieldSetsNewDescription' => 'Create a new field set for showcases.',
    'myShowcaseAdminFieldSetsNewFormName' => 'Name',
    'myShowcaseAdminFieldSetsNewFormNameDescription' => 'The name of the field set.',

    'myShowcaseAdminFieldSetsEdit' => 'Edit Field Set',
    'myShowcaseAdminFieldSetsEditDescription' => 'Edit a field set for showcases.',

    'myShowcaseAdminFields' => 'Fields',
    'myShowcaseAdminFieldsDescription' => 'View and manage fields for showcases.',

    'myShowcaseAdminFieldsNew' => 'New Field',
    'myShowcaseAdminFieldsNewDescription' => 'Create a new field for this fieldset.',
    'myShowcaseAdminFieldsCreateUpdateFormFieldKey' => 'Field Key',
    'myShowcaseAdminFieldsCreateUpdateFormFieldKeyDescription' => 'The unique table field key for this field.',
    'myShowcaseAdminFieldsCreateUpdateFormPlaceholder' => 'Placeholder',
    'myShowcaseAdminFieldsCreateUpdateFormPlaceholderDescription' => 'The placeholder to use for this field. Leave empty to use the language system.',
    'myShowcaseAdminFieldsCreateUpdateFormFieldLabel' => 'Label',
    'myShowcaseAdminFieldsCreateUpdateFormFieldLabelDescription' => 'The label to use for this field. Leave empty to use the language system.',
    'myShowcaseAdminFieldsCreateUpdateFormDescription' => 'Description',
    'myShowcaseAdminFieldsCreateUpdateFormDescriptionDescription' => 'The description for this field, displayed in the create and update pages',
    'myShowcaseAdminFieldsCreateUpdateFormHtmlType' => 'HTML Type',
    'myShowcaseAdminFieldsCreateUpdateFormHtmlTypeDescription' => 'The HTML type of the field.',
    'myShowcaseAdminFieldsCreateUpdateFormFieldType' => 'Database Type',
    'myShowcaseAdminFieldsCreateUpdateFormFieldTypeDescription' => 'The database table field type.',
    'myShowcaseAdminFieldsCreateUpdateFormFileCapture' => 'File Capture',
    'myShowcaseAdminFieldsCreateUpdateFormFileCaptureDescription' => 'Select a capture method for file fields.',
    'myShowcaseAdminFieldsCreateUpdateFormAllowMultipleValues' => 'Allow Multiple Values',
    'myShowcaseAdminFieldsCreateUpdateFormAllowMultipleValuesDescription' => 'If enabled, users will be able to input multiple values for this field.<br/>
    For text, this means to separate values using a comma.<br/>
    For text area, this means to separate values using per line.',
    'myShowcaseAdminFieldsCreateUpdateFormRegularExpression' => 'Regular Expression',
    'myShowcaseAdminFieldsCreateUpdateFormRegularExpressionDescription' => 'Enter a regular expression that should be matched to validate the user input. You must make sure the regular expression is valid and safe. Empty for anything.<br/>
    It must be a valid PHP regular expression, to be passed on to the <a href="https://www.php.net/manual/en/function.preg-match.php">`preg_match()` function as the `pattern` argument</a>.<br />
     <b>Example:</b> /^[a-zA-Z0-9\s]+$/',
    'myShowcaseAdminFieldsCreateUpdateFormInputPattern' => 'Input Pattern',
    'myShowcaseAdminFieldsCreateUpdateFormInputPatternDescription' => 'Enter a regular expression that should be used for the HTML input field pattern element. You must make sure the regular expression is valid and safe—no validation is performed. Empty for anything.<br/>
    It must be a valid JavaScript regular expression, as used by the <a href="https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/RegExp">RegExp</a> type, and as documented in the MDN Web Docs <a href="https://developer.mozilla.org/en-US/docs/Web/JavaScript/Guide/Regular_expressions">guide on regular expressions</a>.<br />
     <b>Example:</b> ^[A-Za-z0-9\s]+$',
    'myShowcaseAdminFieldsCreateUpdateFormDisplayInCreateUpdatePage' => 'Display in Create/Update Page',
    'myShowcaseAdminFieldsCreateUpdateFormDisplayInCreateUpdatePageDescription' => 'Display this field in the create/update page.',
    'myShowcaseAdminFieldsCreateUpdateFormDisplayInViewPage' => 'Display in View Page',
    'myShowcaseAdminFieldsCreateUpdateFormDisplayInViewPageDescription' => 'Display this field in the view page.',
    'myShowcaseAdminFieldsCreateUpdateFormDisplayInMainPage' => 'Display in Main Page',
    'myShowcaseAdminFieldsCreateUpdateFormDisplayInMainPageDescription' => 'Display this field in the main page.',
    'myShowcaseAdminFieldsCreateUpdateFormMinimumLength' => 'Minimum Length',
    'myShowcaseAdminFieldsCreateUpdateFormMinimumLengthDescription' => 'The minimum length of the field.',
    'myShowcaseAdminFieldsCreateUpdateFormMaximumLength' => 'Maximum Length',
    'myShowcaseAdminFieldsCreateUpdateFormMaximumLengthDescription' => 'The maximum length of the field.',
    'myShowcaseAdminFieldsCreateUpdateFormMinimumSize' => 'Minimum Size',
    'myShowcaseAdminFieldsCreateUpdateFormMinimumSizeDescription' => 'The minimum file size of the field.',
    'myShowcaseAdminFieldsCreateUpdateFormMaximumSize' => 'Maximum Size',
    'myShowcaseAdminFieldsCreateUpdateFormMaximumLSizeDescription' => 'The maximum file size of the field in kilobytes (1000 KB =1 MB).',
    'myShowcaseAdminFieldsCreateUpdateFormStepSize' => 'Step Size',
    'myShowcaseAdminFieldsCreateUpdateFormStepSizeDescription' => 'The step size for numeric fields in kilobytes (1000 KB =1 MB).',
    'myShowcaseAdminFieldsCreateUpdateFormInputSize' => 'Input Size',
    'myShowcaseAdminFieldsCreateUpdateFormInputSizeDescription' => 'The input size for text based input fields.',
    'myShowcaseAdminFieldsCreateUpdateFormAllowedGroupsFill' => 'Allowed Fill Groups',
    'myShowcaseAdminFieldsCreateUpdateFormAllowedGroupsFillDescription' => 'The groups that can fill this field.',
    'myShowcaseAdminFieldsCreateUpdateFormAllowedGroupsView' => 'Allowed View Groups',
    'myShowcaseAdminFieldsCreateUpdateFormAllowedGroupsViewDescription' => 'The groups that can view this field.',
    'myShowcaseAdminFieldsCreateUpdateFormDefaultValue' => 'Default Value',
    'myShowcaseAdminFieldsCreateUpdateFormDefaultValueDescription' => 'The default value of the field.',
    'myShowcaseAdminFieldsCreateUpdateFormDefaultType' => 'Default Type',
    'myShowcaseAdminFieldsCreateUpdateFormDefaultTypeDescription' => 'The default value type for this field.',
    'myShowcaseAdminFieldsCreateUpdateFormDefaultTypeAsDefined' => 'As Defined',
    'myShowcaseAdminFieldsCreateUpdateFormDefaultTypeNull' => 'NULL',
    'myShowcaseAdminFieldsCreateUpdateFormDefaultTypeTimeStamp' => 'Time Stamp',
    'myShowcaseAdminFieldsCreateUpdateFormDefaultTypeUUID' => 'UUID',
    'myShowcaseAdminFieldsCreateUpdateFormDisplayOrder' => 'Form Order',
    'myShowcaseAdminFieldsCreateUpdateFormDisplayOrderDescription' => 'The order for rendering this field in the create and update pages.',
    'myShowcaseAdminFieldsCreateUpdateFormRenderOrder' => 'Render Order',
    'myShowcaseAdminFieldsCreateUpdateFormRenderOrderDescription' => 'The order for rendering this field in the main and view pages.',
    'myShowcaseAdminFieldsCreateUpdateFormFormat' => 'Format',
    'myShowcaseAdminFieldsCreateUpdateFormFormatDescription' => 'Apply a function format on display.',
    'myShowcaseAdminFieldsCreateUpdateFormIsRequired' => 'Is Required',
    'myShowcaseAdminFieldsCreateUpdateFormIsRequiredDescription' => 'Make this field required.',
    'myShowcaseAdminFieldsCreateUpdateFormParse' => 'Parse',
    'myShowcaseAdminFieldsCreateUpdateFormParseDescription' => 'Parse the content of this field using the MyBB parser.',
    'myShowcaseAdminFieldsCreateUpdateFormEnableSearch' => 'Enable Search',
    'myShowcaseAdminFieldsCreateUpdateFormEnableSearchDescription' => 'Enable searching the content of this field.',
    'myShowcaseAdminFieldsCreateUpdateFormEnableSlug' => 'Enable Slug',
    'myShowcaseAdminFieldsCreateUpdateFormEnableSlugDescription' => 'Enable generating slugs from this field.',
    'myShowcaseAdminFieldsCreateUpdateFormEnableSubject' => 'Enable Subject',
    'myShowcaseAdminFieldsCreateUpdateFormEnableSubjectDescription' => 'Enable using this field as part of the subject.',
    'myShowcaseAdminFieldsCreateUpdateFormEnableEditor' => 'Enable Editor',
    'myShowcaseAdminFieldsCreateUpdateFormEnableEditorDescription' => 'Enable the rich text editor for this field.',
    'myShowcaseAdminFieldsCreateUpdateFormReadOnly' => 'Read Only',
    'myShowcaseAdminFieldsCreateUpdateFormReadOnlyDescription' => 'Make this field read only, users will not be able to edit it.',
    'myShowcaseAdminFieldsCreateUpdateFormFilterOnSave' => 'Filter On Save',
    'myShowcaseAdminFieldsCreateUpdateFormFilterOnSaveDescription' => 'Apply a function filter on save.',

    'myShowcaseAdminFieldsEdit' => 'Edit Field',
    'myShowcaseAdminFieldsEditDescription' => 'Edit a field for this fieldset.',

    'myShowcaseAdminFieldsOptions' => 'Field Options',
    'myShowcaseAdminFieldsOptionsDescription' => 'View and manage field options for this field.',

    'myShowcaseAdminErrorInvalidFieldset' => 'Invalid fieldset',
    'myShowcaseAdminErrorDuplicatedName' => 'The selected name is already in use.',
    'myShowcaseAdminErrorFieldsetDeleteFailed' => 'Can not perform that action, specified field set is being used by a showcase.',
    'myShowcaseAdminErrorFieldsetDelete' => 'The fieldset was not deleted.',
    'myShowcaseAdminErrorInvalidField' => 'Invalid field',
    'myShowcaseAdminErrorFieldDeleteFailed' => 'The field deletion failed.',
    'myShowcaseAdminErrorInvalidMinMax' => 'Invalid minimum/maximum values.',

    'myShowcaseAdminSuccessNewFieldset' => 'The fieldset was successfully added.',
    'myShowcaseAdminSuccessEditFieldset' => 'The fieldset was successfully updated.',
    'myShowcaseAdminSuccessFieldsetDeleted' => 'The fieldset was successfully deleted.',
    'myShowcaseAdminSuccessNewField' => 'The field was successfully added.',
    'myShowcaseAdminSuccessEditField' => 'The field was successfully updated.',
    'myShowcaseAdminSuccessFieldDeleted' => 'The field was successfully deleted.',

    'myShowcaseAdminConfirmFieldsetDelete' => 'Are you sure you want to delete the "{1}" fieldset? ',
    'myShowcaseAdminConfirmFieldDelete' => 'Are you sure you want to delete the "{1}" field?',
    'myShowcaseAdminConfirmFieldDeleteExisting' => '<br/>The field is already in use in a table and data could be lost.',

    'myShowcaseAdminFieldsTableHeaderID' => 'FID',
    'myShowcaseAdminFieldsTableHeaderName' => 'Field Key',
    'myShowcaseAdminFieldsTableHeaderLabel' => 'Field Label',
    'myShowcaseAdminFieldsTableHeaderHtmlType' => 'HTML Type',
    'myShowcaseAdminFieldsTableHeaderFieldType' => 'Field Type',
    'myShowcaseAdminFieldsTableHeaderEnabled' => 'Enabled',
    'myShowcaseAdminFieldsTableHeaderIsRequired' => 'Required',
    'myShowcaseAdminFieldsTableHeaderParse' => 'Parse',
    'myShowcaseAdminFieldsTableHeaderEnableSearch' => 'Search',
    'myShowcaseAdminFieldsTableHeaderEnableSlug' => 'Slug',
    'myShowcaseAdminFieldsTableHeaderEnableSubject' => 'Subject',
    'myShowcaseAdminFieldsTableHeaderEnableEditor' => 'Editor',
], $l);