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

namespace MyShowcase\Enum;

enum FormTypes
{
    public const Checkbox = 'checkbox';
    public const Number = 'numeric';
    public const Select = 'select';
    public const SelectMultipleGroups = 'select_multiple_groups';
    public const SelectTimeInterval = 'select_time_interval';
    public const SelectFieldset = 'select_fieldset';
    public const SelectTheme = 'select_theme';
    public const SelectRulesType = 'select_rules_type';
    public const SelectFilterField = 'select_filter_field';
    public const SelectSortField = 'select_sort_field';
    public const SelectPrefixType = 'select_prefix_type';
    public const SelectAttachmentsMainRender = 'select_attachments_render_type';
    public const SelectWatermarkLocation = 'select_watermark_location';
    public const SelectAttachmentsDisplayType = 'select_attachments_display_type';
    public const Text = 'text';
    public const TextArea = 'textarea';
    public const YesNo = 'yes_no';
    public const Callback = 'callback';
    public const PermissionBoolean = 'permission_boolean';
}