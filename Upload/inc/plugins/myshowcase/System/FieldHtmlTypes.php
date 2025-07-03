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

namespace MyShowcase\System;

class FieldHtmlTypes
{
    public const CheckBox = 'checkbox'; // build select2?
    public const Color = 'color';
    public const Date = 'date';
    public const DateTimeLocal = 'datetime-local';
    public const Email = 'email';
    public const File = 'file';
    public const Month = 'month';
    public const Number = 'number';
    public const Password = 'password';
    public const Radio = 'radio';
    public const Range = 'range';
    public const Search = 'search'; // select 2 users search ?
    public const Telephone = 'tel';
    public const Text = 'text';
    public const Time = 'time';
    public const Url = 'url';
    public const Week = 'week';
    public const TextArea = 'textarea';
    public const Select = 'select'; // build select2?
    public const SelectUsers = 'select2_users';
    public const SelectEntries = 'select2_entries';
    public const SelectThreads = 'select2_threads';

    //public const Poll = 'poll'; allow multiple

    // ? tab vs block ? (section) ?
    // custom page field type (Pages)
    // user field type allows for group filter
}