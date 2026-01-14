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

namespace MyShowcase\Enum;

enum CacheDataTypes
{
    public const Config = 1;
    public const Fields = 2;
    public const Fieldsets = 3;
    public const Moderators = 4;
    public const Permissions = 5;
    public const FieldData = 6;
    public const AttachmentTypes = 7;
}