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

namespace MyShowcase\Plugin;

class FormTypes
{
    public const CheckBox = 'checkbox';
    public const Number = 'numericField';
    public const Select = 'selectField';
    public const SelectMultipleGroups = 'selectMultipleGroupField';
    public const Text = 'textField';
    public const YesNo = 'yesNoField';
    public const PHP = 'phpFunction';
}