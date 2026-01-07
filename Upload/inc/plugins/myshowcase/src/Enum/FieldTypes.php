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

enum FieldTypes
{
    public const TinyInteger = 'tinyint';
    public const SmallInteger = 'smallint';
    public const MediumInteger = 'mediumint';
    public const Integer = 'int';
    public const BigInteger = 'bigint';
    public const Decimal = 'decimal';
    public const Float = 'float';
    public const Double = 'double';
    public const Char = 'char';
    public const VarChar = 'varchar';
    public const TinyText = 'tinytext';
    public const Text = 'text';
    public const MediumText = 'mediumtext';
    public const Date = 'date';
    public const Time = 'time';
    public const DateTime = 'datetime';
    public const TimeStamp = 'timestamp';
    public const Binary = 'binary';
    public const VarBinary = 'varbinary';
    //public const Real = 'real';
    //public const Bit = 'bit';
    //public const Boolean = 'boolean';
    //public const Serial = 'serial';
    //public const Year = 'year';
    //public const TinyBlob = 'tinyblob';
    //public const MediumBlob = 'mediumblob';
    //public const Blob = 'blob';
    //public const LongBob = 'longblob';
    //public const Enum = 'enum';
    //public const Set = 'set';
}