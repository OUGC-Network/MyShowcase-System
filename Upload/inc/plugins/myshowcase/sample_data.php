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

//----------------------------------------------------------
//
// sample entries for the fieldset, based on 4x4 trucks
//
// schema:
//
// field_key - string, lowercase name of fieldset
//----------------------------------------------------------

use MyShowcase\Enum\FieldHtmlTypes;
use MyShowcase\Enum\FieldTypes;

global

$custom_fieldsets;

$custom_fieldsets = [];

$custom_fieldsets[] = ['Trucks'];

//----------------------------------------------------------
//
// sample entries for the fields, based on 4x4 trucks
//
// schema:
//
// set_id - int, 1 is default for new installs
// field_key - string, lowercase name of field
// html_type - string, html input type. acceptable types are textarea, textbox, radio, checkbox and db. 'db' type uses myshowcase_field_data for obtaining options
// enabled - int, 1 to enable field, 0 to disable
// field_type - type of field to create in the table for this field. acceptable types are int, timestamp, varchar, text
// minimum_length - int, min allowed length of the data
// maximum_length - int, max allowed length of the data, also length/size of field (except for text fields)
// is_required - int, 1 to require input, 0 to ignore.
// parse - int, 1 to run the field through the parser, 0 otherwise
// display_order - int, order in which fields are displayed during view
// render_order - int, order in which fields are listed in the list view (default view). '-1' excludes field from list view
// enable_search - int, 1 to enable the field in search_field option at bottom of page, 0 to no include
//----------------------------------------------------------
global $custom_fields;

$custom_fields = [];

$custom_fields[] = [
    1,
    'year',
    FieldHtmlTypes::Text,
    1,
    FieldTypes::Integer,
    0,
    4,
    1,
    0,
    1,
    1,
    1
];
$custom_fields[] = [
    1,
    'make',
    FieldHtmlTypes::Select,
    1,
    FieldTypes::Integer,
    0,
    2,
    1,
    0,
    2,
    2,
    1
];
$custom_fields[] = [
    1,
    'model',
    FieldHtmlTypes::Text,
    1,
    FieldTypes::VarChar,
    0,
    30,
    1,
    0,
    3,
    3,
    1
];
$custom_fields[] = [
    1,
    'engine',
    FieldHtmlTypes::Text,
    1,
    FieldTypes::VarChar,
    0,
    15,
    0,
    0,
    4,
    4,
    1
];
$custom_fields[] = [
    1,
    'transspeed',
    FieldHtmlTypes::Select,
    1,
    FieldTypes::Integer,
    0,
    2,
    0,
    0,
    5,
    -1,
    0
];
$custom_fields[] = [
    1,
    'transtype',
    FieldHtmlTypes::Select,
    1,
    FieldTypes::Integer,
    0,
    2,
    0,
    0,
    6,
    -1,
    0
];
$custom_fields[] = [
    1,
    'gearratio',
    FieldHtmlTypes::Text,
    1,
    FieldTypes::VarChar,
    0,
    10,
    0,
    0,
    7,
    -1,
    0
];
$custom_fields[] = [
    1,
    'frontdiff',
    FieldHtmlTypes::Text,
    1,
    FieldTypes::VarChar,
    0,
    40,
    0,
    0,
    8,
    -1,
    0
];
$custom_fields[] = [
    1,
    'reardiff',
    FieldHtmlTypes::Text,
    1,
    FieldTypes::VarChar,
    0,
    40,
    0,
    0,
    9,
    -1,
    0
];
$custom_fields[] = [
    1,
    'cabstyle',
    FieldHtmlTypes::Select,
    1,
    FieldTypes::Integer,
    0,
    2,
    0,
    0,
    10,
    -1,
    0
];
$custom_fields[] = [
    1,
    'bedsize',
    FieldHtmlTypes::Select,
    1,
    FieldTypes::Integer,
    0,
    2,
    0,
    0,
    11,
    -1,
    0
];
$custom_fields[] = [
    1,
    'tiresize',
    FieldHtmlTypes::Text,
    1,
    FieldTypes::VarChar,
    0,
    20,
    0,
    0,
    12,
    -1,
    0
];
$custom_fields[] = [
    1,
    'tiremodel',
    FieldHtmlTypes::Text,
    1,
    FieldTypes::VarChar,
    0,
    30,
    0,
    0,
    13,
    -1,
    1
];
$custom_fields[] = [
    1,
    'wheelsize',
    FieldHtmlTypes::Text,
    1,
    FieldTypes::VarChar,
    0,
    10,
    0,
    0,
    14,
    -1,
    0
];
$custom_fields[] = [
    1,
    'wheelmodel',
    FieldHtmlTypes::Text,
    1,
    FieldTypes::VarChar,
    0,
    30,
    0,
    0,
    15,
    -1,
    1
];
$custom_fields[] = [
    1,
    'susplift',
    FieldHtmlTypes::Select,
    1,
    FieldTypes::Integer,
    0,
    2,
    0,
    0,
    16,
    -1,
    0
];
$custom_fields[] = [
    1,
    'suspmodel',
    FieldHtmlTypes::Text,
    1,
    FieldTypes::VarChar,
    0,
    50,
    0,
    0,
    17,
    -1,
    1
];
$custom_fields[] = [
    1,
    'bodylift',
    FieldHtmlTypes::Select,
    1,
    FieldTypes::Integer,
    0,
    2,
    0,
    0,
    18,
    -1,
    0
];
$custom_fields[] = [
    1,
    'bodymodel',
    FieldHtmlTypes::Text,
    1,
    FieldTypes::VarChar,
    0,
    50,
    0,
    0,
    19,
    -1,
    0
];
$custom_fields[] = [
    1,
    'other',
    FieldHtmlTypes::TextArea,
    1,
    FieldTypes::Text,
    0,
    15,
    0,
    0,
    20,
    -1,
    1
];

//----------------------------------------------------------
//
// sample data for db type fields defiend above
// based on 4x4 truck components.
// HIGHLY RECOMMENDED to include the Not Specified records
//
// schema:
//
// field_key - string, lowercase name of field
// id - int, id for this item in the assocaited field. this is the value stored in the garage_data table's related field
// value - string, display value
// disporderorder - int, order in which data is displayed in input form during new/edit
//----------------------------------------------------------
global $custom_field_data;

$custom_field_data = [];

$custom_field_data[] = [2, 'make', 1, 'Acura', 1];
$custom_field_data[] = [2, 'make', 2, 'AM General', 2];
$custom_field_data[] = [2, 'make', 3, 'Chevrolet', 3];
$custom_field_data[] = [2, 'make', 4, 'Datsun', 4];
$custom_field_data[] = [2, 'make', 5, 'Dodge', 5];
$custom_field_data[] = [2, 'make', 6, 'Eagle', 6];
$custom_field_data[] = [2, 'make', 7, 'Ford', 7];
$custom_field_data[] = [2, 'make', 8, 'Geo', 8];
$custom_field_data[] = [2, 'make', 9, 'GMC', 9];
$custom_field_data[] = [2, 'make', 10, 'Honda', 10];
$custom_field_data[] = [2, 'make', 11, 'Hyundai', 11];
$custom_field_data[] = [2, 'make', 12, 'Infinity', 12];
$custom_field_data[] = [2, 'make', 13, 'International', 13];
$custom_field_data[] = [2, 'make', 14, 'Isuzu', 14];
$custom_field_data[] = [2, 'make', 15, 'Jeep', 15];
$custom_field_data[] = [2, 'make', 16, 'Kia', 16];
$custom_field_data[] = [2, 'make', 17, 'Land Rover', 17];
$custom_field_data[] = [2, 'make', 18, 'Lexus', 18];
$custom_field_data[] = [2, 'make', 19, 'Lincoln', 19];
$custom_field_data[] = [2, 'make', 20, 'Mazda', 20];
$custom_field_data[] = [2, 'make', 21, 'Mercedes', 21];
$custom_field_data[] = [2, 'make', 22, 'Mercury', 22];
$custom_field_data[] = [2, 'make', 23, 'Mitsubishi', 23];
$custom_field_data[] = [2, 'make', 24, 'Nissan', 24];
$custom_field_data[] = [2, 'make', 25, 'Oldsmobile', 25];
$custom_field_data[] = [2, 'make', 26, 'Pontiac', 26];
$custom_field_data[] = [2, 'make', 27, 'Subaru', 27];
$custom_field_data[] = [2, 'make', 28, 'Suzuki', 28];
$custom_field_data[] = [2, 'make', 29, 'Toyota', 29];
$custom_field_data[] = [2, 'make', 30, 'Volkswagen', 30];
$custom_field_data[] = [2, 'make', 31, 'Volvo', 31];
$custom_field_data[] = [2, 'make', 32, 'Willys', 32];
$custom_field_data[] = [2, 'make', 0, 'Not Specified', 0];
$custom_field_data[] = [6, 'transtype', 1, 'Auto', 1];
$custom_field_data[] = [6, 'transtype', 2, 'Manual', 2];
$custom_field_data[] = [6, 'transtype', 0, 'Not Specified', 0];
$custom_field_data[] = [11, 'bedsize', 1, 'Short', 1];
$custom_field_data[] = [11, 'bedsize', 2, 'Long', 2];
$custom_field_data[] = [11, 'bedsize', 3, 'Flat Bed', 3];
$custom_field_data[] = [11, 'bedsize', 4, 'No Bed (SUV)', 4];
$custom_field_data[] = [11, 'bedsize', 0, 'Not Specified', 0];
$custom_field_data[] = [18, 'bodylift', 1, 'None', 1];
$custom_field_data[] = [18, 'bodylift', 2, '1 in.', 2];
$custom_field_data[] = [18, 'bodylift', 3, '2 in.', 3];
$custom_field_data[] = [18, 'bodylift', 4, '3 in.', 4];
$custom_field_data[] = [18, 'bodylift', 5, '4 in.', 5];
$custom_field_data[] = [18, 'bodylift', 0, 'Not Specified', 0];
$custom_field_data[] = [10, 'cabstyle', 1, 'Regular Cab', 1];
$custom_field_data[] = [10, 'cabstyle', 2, 'Ext. Cab 2dr', 2];
$custom_field_data[] = [10, 'cabstyle', 3, 'Ext. Cab 3dr', 3];
$custom_field_data[] = [10, 'cabstyle', 4, 'Ext. Cab 4dr', 4];
$custom_field_data[] = [10, 'cabstyle', 5, 'Crew Cab', 5];
$custom_field_data[] = [10, 'cabstyle', 6, '2 dr. SUV', 6];
$custom_field_data[] = [10, 'cabstyle', 7, '4 dr. SUV', 7];
$custom_field_data[] = [10, 'cabstyle', 0, 'Not Specified', 0];
$custom_field_data[] = [16, 'susplift', 1, 'None', 1];
$custom_field_data[] = [16, 'susplift', 2, '1 in.', 2];
$custom_field_data[] = [16, 'susplift', 3, '2 in.', 3];
$custom_field_data[] = [16, 'susplift', 4, '3 in.', 4];
$custom_field_data[] = [16, 'susplift', 5, '4 in.', 5];
$custom_field_data[] = [16, 'susplift', 6, '5 in.', 6];
$custom_field_data[] = [16, 'susplift', 7, '6 in.', 7];
$custom_field_data[] = [16, 'susplift', 8, '7 in.', 8];
$custom_field_data[] = [16, 'susplift', 9, '8 in.', 9];
$custom_field_data[] = [16, 'susplift', 10, '9 in.', 10];
$custom_field_data[] = [16, 'susplift', 11, '10 in.', 11];
$custom_field_data[] = [16, 'susplift', 12, '11 in.', 12];
$custom_field_data[] = [16, 'susplift', 13, '12 in.', 13];
$custom_field_data[] = [16, 'susplift', 14, '13+ in.', 14];
$custom_field_data[] = [16, 'susplift', 0, 'Not Specified', 0];
$custom_field_data[] = [5, 'transspeed', 1, '2', 1];
$custom_field_data[] = [5, 'transspeed', 2, '3', 2];
$custom_field_data[] = [5, 'transspeed', 3, '4', 3];
$custom_field_data[] = [5, 'transspeed', 4, '5', 4];
$custom_field_data[] = [5, 'transspeed', 5, '6', 5];
$custom_field_data[] = [5, 'transspeed', 0, 'Not Specified', 0];