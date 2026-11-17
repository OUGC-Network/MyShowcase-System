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

$l = [
    'myshowcase_datahandler_invalid_entry' => 'The selected entry is invalid.',
    'myshowcase_datahandler_custom_slug_too_short' => 'The entry custom slug is too short. Minimum length is {1} characters.',
    'myshowcase_datahandler_custom_slug_too_long' => 'The entry custom slug is too long. Maximum length is {1} characters.',
    'myshowcase_datahandler_invalid_user' => 'The entry user is invalid.',
    'myshowcase_datahandler_invalid_views' => 'The entry views count is invalid.',
    'myshowcase_datahandler_invalid_comments' => 'The entry comments count is invalid.',
    'myshowcase_datahandler_invalid_status' => 'The entry status is invalid.',
    'myshowcase_datahandler_invalid_edit_user' => 'The entry edit user is invalid.',
    'myshowcase_datahandler_invalid_dateline' => 'The entry dateline is invalid.',
    'myshowcase_datahandler_invalid_update_dateline' => 'The entry edit stamp is invalid.',
    'myshowcase_datahandler_invalid_value' => 'The field "{1}" has an invalid value.',
    'myshowcase_datahandler_multiple_values' => 'The field "{1}" accepts one selected option only.',
    'myshowcase_datahandler_integer_range' => 'The field "{1}" must be a whole number from {2} to {3}.',
    'myshowcase_datahandler_number_minimum' => 'The field "{1}" must be at least {2}.',
    'myshowcase_datahandler_number_maximum' => 'The field "{1}" must be at most {2}.',
    'myshowcase_datahandler_value_minimum' => 'The field "{1}" must be at least {2}.',
    'myshowcase_datahandler_value_maximum' => 'The field "{1}" must be at most {2}.',
    'myshowcase_datahandler_required_field' => 'A required field is missing: {1}.',
    'myshowcase_datahandler_four_byte_character' => 'The field {1} holds a character this database cannot store: a four-byte UTF-8 character such as an emoji. Remove it, or ask the administrator to set the database encoding to utf8mb4.',
    'myshowcase_datahandler_invalid_length_minimum' => 'The field "{1}" has an invalid length. Make sure it at least {2} characters long.',
    'myshowcase_datahandler_file_type' => 'The file "{1}" is not of a type this field takes: {2}.',
    'myshowcase_datahandler_file_count_maximum' => 'The field "{1}" takes at most {2} files.',
    'myshowcase_datahandler_invalid_length_maximum' => 'The field "{1}" has an invalid length. Make sure it is no more than {2} characters long.',
];