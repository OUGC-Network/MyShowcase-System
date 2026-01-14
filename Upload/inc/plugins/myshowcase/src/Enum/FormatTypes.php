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

enum FormatTypes
{
    public const noFormat = 0;
    public const numberFormat = 1;
    public const numberFormat1 = 2;
    public const numberFormat2 = 3;
    public const htmlSpecialCharactersUni = 4;
    public const stripTags = 5;
    public const ParserUrlAuto = 100;
    public const ParseVideoYoutube = 101;
}