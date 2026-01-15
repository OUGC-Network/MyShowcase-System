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

namespace MyShowcase\Plugin\Core;

use MyShowcase\Enum\ModeratorPermissions;
use MyShowcase\Enum\UserPermissions;
use MyShowcase\Enum\FormTypes;

use const MyShowcase\SETTINGS;

const SHOWCASE_STATUS_DISABLED = 0;

const SHOWCASE_STATUS_ENABLED = 1;

const MODERATOR_TYPE_USER = 0;

const MODERATOR_TYPE_GROUP = 1;

const URL = 'index.php?module=myshowcase-summary';

const ALL_UNLIMITED_VALUE = -1;

const REPORT_STATUS_PENDING = 0;

const ERROR_TYPE_NOT_INSTALLED = 1;

const ERROR_TYPE_NOT_CONFIGURED = 1;

const CHECK_BOX_IS_CHECKED = 1;

const GUEST_GROUP_ID = 1;

const TABLES_DATA = [
    'myshowcase_subscriptions' => [
        'subscription_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'auto_increment' => true,
            'primary_key' => true
        ],
        'showcase_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 1
        ],
        'scope_type' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1
        ],
        'user_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'entry_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'notification_type' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1
        ],
        'dateline' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
    ],
    'myshowcase_attachments' => [
        'attachment_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'auto_increment' => true,
            'primary_key' => true
        ],
        'attachment_hash' => [
            'type' => 'VARCHAR',
            'size' => 36,
            'default' => '',
            'unique_key' => true
        ],
        'showcase_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 1
        ],
        'entry_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'comment_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'field_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'post_hash' => [
            'type' => 'VARCHAR',
            'size' => 36,
            'default' => '',
        ],
        'user_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'description' => [
            'type' => 'VARCHAR',
            'size' => 250,
            'default' => ''
        ],
        'dateline' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'status' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1
        ],
        'edit_stamp' => [ // todo, should be attachment_history_id
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'edit_user_id' => [ // todo, should be entry_history_id
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'file_name' => [
            'type' => 'VARCHAR',
            'size' => 120,
            'default' => ''
        ],
        'mime_type' => [
            'type' => 'VARCHAR',
            'size' => 120,
            'default' => ''
        ],
        'file_size' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'attachment_name' => [
            'type' => 'VARCHAR',
            'size' => 120,
            'default' => ''
        ],
        'downloads' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'thumbnail_name' => [
            'type' => 'VARCHAR',
            'size' => 120,
            'default' => ''
        ],
        'thumbnail_dimensions' => [
            'type' => 'VARCHAR',
            'size' => 20,
            'default' => ''
        ],
        'dimensions' => [
            'type' => 'VARCHAR',
            'size' => 20,
            'default' => ''
        ],
        'ipaddress' => [
            'type' => 'VARBINARY',
            'size' => 16,
            'default' => '',
        ],
    ],
    'myshowcase_attachments_history' => [
        'attachment_history_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'auto_increment' => true,
            'primary_key' => true
        ],
        'attachment_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 1
        ],
        'edit_stamp' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'edit_user_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'edit_note' => [
            'type' => 'VARCHAR',
            'size' => 150,
            'default' => ''
        ],
        'description' => [
            'type' => 'VARCHAR',
            'size' => 250,
            'default' => ''
        ],
        'file_name' => [
            'type' => 'VARCHAR',
            'size' => 120,
            'default' => ''
        ],
        'mime_type' => [
            'type' => 'VARCHAR',
            'size' => 120,
            'default' => ''
        ],
        'file_size' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'attachment_name' => [
            'type' => 'VARCHAR',
            'size' => 120,
            'default' => ''
        ],
        'status' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1
        ],
        'thumbnail_name' => [
            'type' => 'VARCHAR',
            'size' => 120,
            'default' => ''
        ],
        'thumbnail_dimensions' => [
            'type' => 'VARCHAR',
            'size' => 20,
            'default' => ''
        ],
        'dimensions' => [
            'type' => 'VARCHAR',
            'size' => 20,
            'default' => ''
        ],
    ],
    'myshowcase_attachments_cdn' => [
        'log_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'auto_increment' => true,
            'primary_key' => true
        ],
        'session_id' => [
            'type' => 'VARCHAR',
            'size' => 32,
            'default' => ''
        ],
        'attachment_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'cdn_url' => [
            'type' => 'TEXT',
            'null' => true
        ],
        'dateline' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'unique_keys' => ['session_attachment_id' => ['session_id', 'attachment_id']],
    ],
    'myshowcase_attachments_shared' => [
        'attachment_share_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'auto_increment' => true,
            'primary_key' => true
        ],
        'attachment_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 1
        ],
        'entry_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ]
    ],
    'myshowcase_attachments_download_logs' => [
        'log_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'auto_increment' => true,
            'primary_key' => true
        ],
        'user_id' => [
            'type' => 'INT',
            'unsigned' => true
        ],
        'attachment_id' => [
            'type' => 'INT',
            'unsigned' => true
        ],
        'ipaddress' => [
            'type' => 'VARBINARY',
            'size' => 16,
            'default' => ''
        ],
        'dateline' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ]
    ],
    'myshowcase_comments' => [
        'comment_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'auto_increment' => true,
            'primary_key' => true
        ],
        'comment_slug' => [
            'type' => 'VARCHAR',
            'size' => SETTINGS['slugLength'],
            'unique_key' => true
        ],
        'showcase_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 1
        ],
        'entry_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'post_hash' => [
            'type' => 'VARCHAR',
            'size' => 36,
            'default' => '',
        ],
        'user_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'username' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'parent_comment_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'message' => [
            'type' => 'TEXT',
            'null' => true,
            'enable_search' => true,
        ],
        'icon_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'dateline' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'status' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        'attachments_pending' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'attachments_approved' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'attachments_deleted' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'edit_stamp' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'edit_user_id' => [ // todo, should be entry_history_id
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'edit_note' => [
            'type' => 'VARCHAR',
            'size' => 120,
            'default' => '',
        ],
        'allow_signature' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        'disable_smilies' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        // todo, update old data from varchar to varbinary
        'ipaddress' => [
            'type' => 'VARBINARY',
            'size' => 16,
            'default' => '',
        ],
    ],
    'myshowcase_comments_history' => [
        'comment_history_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'auto_increment' => true,
            'primary_key' => true
        ],
        'comment_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 1
        ],
        'edit_stamp' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'edit_user_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'edit_note' => [
            'type' => 'VARCHAR',
            'size' => 150,
            'default' => ''
        ],
        'ipaddress' => [
            'type' => 'VARBINARY',
            'size' => 16,
            'default' => ''
        ],
        'message' => [
            'type' => 'TEXT',
            'null' => true
        ],
        'status' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1
        ],
    ],
    'myshowcase_config' => [
        'showcase_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'auto_increment' => true,
            'primary_key' => true
        ],
        'field_set_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
            'category' => 'general',
            'input_type' => FormTypes::SelectFieldset,
        ],
        'name' => [
            'type' => 'VARCHAR',
            'size' => 50,
            'default' => '',
            'category' => 'general',
            'input_type' => FormTypes::Text,
        ],
        'description' => [
            'type' => 'VARCHAR',
            'size' => 255,
            'default' => '',
            'category' => 'general',
            'input_type' => FormTypes::Text,
        ],
        'route_identifier' => [
            'type' => 'VARCHAR',
            'size' => 50,
            'default' => '',
            'category' => 'general',
            'input_type' => FormTypes::Text,
        ],
        'showcase_url' => [
            'type' => 'VARCHAR',
            'size' => 255,
            'default' => '',
            'category' => 'general',
            'input_type' => FormTypes::Text,
        ],
        'password' => [
            'type' => 'VARCHAR',
            'size' => 255,
            'default' => '',
            'category' => 'general',
            'input_type' => FormTypes::Text,
        ],
        'password_minutes_interval' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 15,
            'category' => 'general',
            'input_type' => FormTypes::Number,
        ],
        'password_ip_check' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'general',
            'input_type' => FormTypes::YesNo,
        ],
        'custom_theme_force' => [ // if force & no custom theme selected, force default theme
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'category' => 'general',
            'input_type' => FormTypes::YesNo,
        ],
        'custom_theme_id' => [
            'type' => 'VARCHAR',
            'size' => 10,
            'default' => '',
            'category' => 'general',
            'input_type' => FormTypes::SelectTheme,
        ],
        'custom_template_prefix' => [
            'type' => 'VARCHAR',
            'size' => 50,
            'default' => '',
            'category' => 'general',
            'input_type' => FormTypes::Text,
        ],
        'rules_type' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'category' => 'general',
            'input_type' => FormTypes::SelectRulesType,
        ],
        'rules_title' => [
            'type' => 'VARCHAR',
            'size' => 200,
            'default' => '',
            'category' => 'general',
            'input_type' => FormTypes::Text,
        ],
        'rules_content' => [
            'type' => 'TEXT',
            'null' => true,
            'category' => 'general',
            'input_type' => FormTypes::TextArea,
            'max' => 65535,
        ],
        'is_enabled' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'category' => 'general',
            'input_type' => FormTypes::YesNo,
        ],
        'display_order' => [ // mean to be useful for building a header link, etc
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
            'category' => 'general',
            'input_type' => FormTypes::Number,
            'max' => 4294967295,
        ],
        'main_display_moderators' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'main',
            'input_type' => FormTypes::YesNo,
        ],
        'main_display_users_browsing' => [ // 'showforumviewing'
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'main',
            'input_type' => FormTypes::YesNo,
        ],
        'main_per_page' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 10,
            'category' => 'main',
            'input_type' => FormTypes::Number,
            'class' => 'field150',
            'max' => 255,
        ],
        'main_filter_force_field' => [ // force view entries by uid, etc
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'category' => 'main',
            'input_type' => FormTypes::SelectFilterField,
        ],
        'main_sort_default_field' => [
            'type' => 'VARCHAR',
            'size' => 10,
            'default' => '',
            'category' => 'main',
            'input_type' => FormTypes::SelectSortField,
        ],
        'main_sort_direction' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'main',
            'input_type' => FormTypes::YesNo,
        ],
        'parser_allow_html' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'category' => 'parser',
            'input_type' => FormTypes::YesNo,
        ],
        'parser_allow_mycode' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'parser',
            'input_type' => FormTypes::YesNo,
        ],
        'parser_allow_smiles' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'parser',
            'input_type' => FormTypes::YesNo,
        ],
        'parser_allow_image_code' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'parser',
            'input_type' => FormTypes::YesNo,
        ],
        'parser_allow_video_code' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'parser',
            'input_type' => FormTypes::YesNo,
        ],
        'parser_affects_message_length' => [ // todo, strip all tags using preview function
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'category' => 'parser',
            'input_type' => FormTypes::YesNo,
        ],
        'entries_allow_post_icons' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'entries',
            'input_type' => FormTypes::YesNo,
        ],
        'entries_allow_prefixes' => [ // 1= enable, 2 = require
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'entries',
            'input_type' => FormTypes::SelectPrefixType,
        ],
        'entries_enable_subject' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'entries',
            'input_type' => FormTypes::YesNo,
        ],
        'entries_subject_minimum_length' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'entries',
            'input_type' => FormTypes::Number,
            'class' => 'field150',
            'max' => 255,
        ],
        'entries_subject_maximum_length' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 120,
            'category' => 'entries',
            'input_type' => FormTypes::Number,
            'class' => 'field150',
            'max' => 120,
        ],
        'entries_enable_message' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'entries',
            'input_type' => FormTypes::YesNo,
        ],
        'entries_message_minimum_length' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'entries',
            'input_type' => FormTypes::Number,
            'class' => 'field150',
            'max' => 255,
        ],
        'entries_message_maximum_length' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
            'category' => 'entries',
            'input_type' => FormTypes::Number,
            'class' => 'field150',
            'max' => 65535,
        ],
        'entries_message_build_editor' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'entries',
            'input_type' => FormTypes::YesNo,
        ],
        'entries_display_avatars' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'entries',
            'input_type' => FormTypes::YesNo,
        ],
        'entries_display_profile_fields' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'entries',
            'input_type' => FormTypes::YesNo,
        ],
        'entries_display_stars' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'entries',
            'input_type' => FormTypes::YesNo,
        ],
        'entries_display_group_image' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'entries',
            'input_type' => FormTypes::YesNo,
        ],
        'entries_display_details' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'entries',
            'input_type' => FormTypes::YesNo,
        ],
        'entries_display_signatures' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'entries',
            'input_type' => FormTypes::YesNo,
        ],
        'entries_display_quick_reply' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'entries',
            'input_type' => FormTypes::YesNo,
        ],
        'entries_display_empty_fields' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'entries',
            'input_type' => FormTypes::YesNo,
        ],
        'entries_display_users_browsing' => [ // 'showforumviewing'
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'entries',
            'input_type' => FormTypes::YesNo,
        ],
        'entries_allow_reputations' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'entries',
            'input_type' => FormTypes::YesNo,
        ],
        'entries_allow_edit_reasons' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'entries',
            'input_type' => FormTypes::YesNo
        ],
        'comments_enable' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'comments',
            'input_type' => FormTypes::YesNo,
        ],
        'comments_allow_post_icons' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'comments',
            'input_type' => FormTypes::YesNo,
        ],
        'comments_message_minimum_length' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'comments',
            'input_type' => FormTypes::Number,
            'class' => 'field150',
            'max' => 255,
        ],
        'comments_message_maximum_length' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
            'category' => 'comments',
            'input_type' => FormTypes::Number,
            'class' => 'field150',
            'max' => 65535,
        ],
        'comments_message_build_editor' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'comments',
            'input_type' => FormTypes::YesNo,
        ],
        'comments_display_avatars' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'comments',
            'input_type' => FormTypes::YesNo,
        ],
        'comments_display_profile_fields' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'comments',
            'input_type' => FormTypes::YesNo,
        ],
        'comments_display_stars' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'comments',
            'input_type' => FormTypes::YesNo,
        ],
        'comments_display_group_image' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'comments',
            'input_type' => FormTypes::YesNo,
        ],
        'comments_display_details' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'comments',
            'input_type' => FormTypes::YesNo,
        ],
        'comments_display_signatures' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'comments',
            'input_type' => FormTypes::YesNo,
        ],
        'comments_allow_reputations' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'comments',
            'input_type' => FormTypes::YesNo,
        ],
        'comments_enable_edit_reasons' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'comments',
            'input_type' => FormTypes::YesNo,
        ],
        'comments_per_page' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 10,
            'category' => 'comments',
            'input_type' => FormTypes::Number,
            'class' => 'field150',
            'max' => 255,
        ],
        'comments_sort_direction' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'comments',
            'input_type' => FormTypes::YesNo,
        ],
        'comments_enable_quotes' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'comments',
            'input_type' => FormTypes::YesNo,
        ],
        'comments_maximum_nested_quotes' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'comments',
            'input_type' => FormTypes::Number,
            'class' => 'field150',
            'max' => 255,
        ],
        'attachments_allow_in_entries' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'attachments',
            'input_type' => FormTypes::YesNo,
        ],
        'attachments_allow_in_comments' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'attachments',
            'input_type' => FormTypes::YesNo,
        ],
        'attachments_uploads_path' => [
            'type' => 'VARCHAR',
            'size' => 255,
            'default' => '',
            'category' => 'attachments',
            'input_type' => FormTypes::Text,
        ],
        'attachments_main_render' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'category' => 'attachments',
            'input_type' => FormTypes::SelectAttachmentsMainRender,
        ],
        'attachments_main_render_as_lightbox' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'attachments',
            'input_type' => FormTypes::YesNo,
        ],
        'attachments_enable_embeds' => [ // allow using attachments from other entries
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'attachments',
            'input_type' => FormTypes::YesNo,
        ],
        'attachments_watermark_file' => [
            'type' => 'VARCHAR',
            'null' => true,
            'size' => 50,
            'default' => '',
            'category' => 'attachments',
            'input_type' => FormTypes::Text,
        ],
        'attachments_watermark_location' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'attachments',
            'input_type' => FormTypes::SelectWatermarkLocation,
            'form_function' => '\MyShowcase\Plugin\Functions\generateWatermarkLocationsSelectArray',
        ],
        'attachments_thumbnails_width' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 200,
            'category' => 'attachments',
            'input_type' => FormTypes::Number,
            'class' => 'field150',
            'max' => 255,
        ],
        'attachments_thumbnails_height' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 200,
            'category' => 'attachments',
            'input_type' => FormTypes::Number,
            'class' => 'field150',
            'max' => 255,
        ],
        'attachments_display_type' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'attachments',
            'input_type' => FormTypes::SelectAttachmentsDisplayType,
        ],
        'subscriptions_enable' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'category' => 'entries',
            'input_type' => FormTypes::YesNo,
        ],
        'subscriptions_message_length' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 255,
            'category' => 'entries',
            'input_type' => FormTypes::Number,
            'class' => 'field150',
            'max' => 255,
        ],
    ],
    'myshowcase_access_logs' => [
        'access_log_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'auto_increment' => true,
            'primary_key' => true
        ],
        'showcase_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'user_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'dateline' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'is_valid' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 1,
        ],
        'ipaddress' => [
            'type' => 'VARBINARY',
            'size' => 16,
            'default' => '',
        ],
    ],
    'myshowcase_fieldsets' => [
        'set_id' => [ // todo, rename to fieldset_id
            'type' => 'INT',
            'unsigned' => true,
            'auto_increment' => true,
            'primary_key' => true
        ],
        'set_name' => [
            'type' => 'VARCHAR',
            'size' => 50,
            'default' => ''
        ],
    ],
    'myshowcase_permissions' => [
        'permission_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'auto_increment' => true,
            'primary_key' => true
        ],
        'showcase_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'group_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'user_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        UserPermissions::MainCanView => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        UserPermissions::EntriesCanView => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        UserPermissions::EntriesCanViewOnlyOwn => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::EntriesCanViewUnapproved => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        UserPermissions::EntriesCanViewDeleted => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::EntriesCanPost => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        UserPermissions::EntriesCanUpdate => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        UserPermissions::EntriesCanOpen => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::EntriesCanClose => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::EntriesCanSticky => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::EntriesCanUnstick => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::EntriesCanApprove => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::EntriesCanUnapprove => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::EntriesCanCopy => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        UserPermissions::EntriesCanSoftDelete => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        UserPermissions::EntriesCanRestore => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::EntriesCanDelete => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::EntriesCanViewDeletionNotices => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        UserPermissions::EntriesModerateNew => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::EntriesModerateUpdates => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::EntriesLimitViewAmount => [
            'type' => 'SMALLINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::EntriesLimitViewInterval => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::EntriesLimitViewIntervalAmount => [
            'type' => 'SMALLINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::EntriesLimitPostAmount => [
            'type' => 'SMALLINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::EntriesLimitPostInterval => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::EntriesLimitPostIntervalAmount => [
            'type' => 'SMALLINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::EntriesReputationLimitAmount => [
            'type' => 'SMALLINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::EntriesReputationLimitInterval => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::EntriesReputationLimitIntervalAmount => [
            'type' => 'SMALLINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::EntriesFloodTime => [
            'type' => 'SMALLINT',
            'unsigned' => true,
            'default' => 60,
        ],
        UserPermissions::CommentsCanView => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        UserPermissions::CommentsCanViewOnlyOwn => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::CommentsCanViewUnapproved => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        UserPermissions::CommentsCanViewDeleted => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::CommentsCanPost => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        UserPermissions::CommentsCanPostInClosedEntries => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::CommentsCanUpdate => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        UserPermissions::CommentsCanApprove => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::CommentsCanUnapprove => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::CommentsCanMove => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::CommentsCanSoftDelete => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        UserPermissions::CommentsCanRestore => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::CommentsCanDelete => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::CommentsCanViewDeletionNotices => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        UserPermissions::CommentsModerateNew => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::CommentsModerateUpdates => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::CommentsLimitPostAmount => [
            'type' => 'SMALLINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::CommentsLimitPostInterval => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::CommentsLimitPostIntervalAmount => [
            'type' => 'SMALLINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::CommentsReputationLimitAmount => [
            'type' => 'SMALLINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::CommentsReputationLimitInterval => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::CommentsReputationLimitIntervalAmount => [
            'type' => 'SMALLINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::CommentsFloodTime => [
            'type' => 'SMALLINT',
            'unsigned' => true,
            'default' => 60,
        ],
        UserPermissions::AttachmentsCanView => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        UserPermissions::AttachmentsCanViewOnlyOwn => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::AttachmentsCanViewUnapproved => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        UserPermissions::AttachmentsCanViewDeleted => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::AttachmentsCanUpload => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        UserPermissions::AttachmentsCanDownload => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        UserPermissions::AttachmentsCanUpdate => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        UserPermissions::AttachmentsCanApprove => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::AttachmentsCanUnapprove => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::AttachmentsCanSoftDelete => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        UserPermissions::AttachmentsCanRestore => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::AttachmentsCanDelete => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::AttachmentsCanViewDeletionNotices => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        UserPermissions::AttachmentsModerateNew => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::AttachmentsModerateUpdates => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::AttachmentsLimitUploadAmount => [
            'type' => 'SMALLINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::AttachmentsLimitUploadInterval => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::AttachmentsLimitUploadIntervalAmount => [
            'type' => 'SMALLINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::AttachmentsLimitUploadQuotaAmount => [
            'type' => 'SMALLINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::AttachmentsLimitUploadQuotaInterval => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::AttachmentsLimitUploadQuotaIntervalAmount => [
            'type' => 'SMALLINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::AttachmentsLimitDownloadAmount => [
            'type' => 'SMALLINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::AttachmentsLimitDownloadInterval => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::AttachmentsLimitDownloadIntervalAmount => [
            'type' => 'SMALLINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::AttachmentsLimitDownloadQuotaAmount => [
            'type' => 'SMALLINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::AttachmentsLimitDownloadQuotaInterval => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::AttachmentsLimitDownloadQuotaIntervalAmount => [
            'type' => 'SMALLINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::AttachmentsLimitPerEntry => [
            'type' => 'SMALLINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::AttachmentsLimitPerComment => [
            'type' => 'SMALLINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::AttachmentsUploadFloodTime => [
            'type' => 'SMALLINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::AttachmentsDownloadFloodTime => [
            'type' => 'SMALLINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::AttachmentsQuota => [
            'type' => 'SMALLINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::AttachmentsWaterMarkImages => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::CanSearchEntries => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        UserPermissions::CanSearchComments => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        UserPermissions::CanSearchAttachments => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        UserPermissions::CanViewIPs => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::CanUseCustomModerationTools => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::CanManageReports => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::CanManageLogs => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::CanBypassDownloadLimits => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        UserPermissions::CanBypassModeration => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        //'unique_keys' => ['showcase_group_user_id' => ['showcase_id', 'group_id', 'user_id']],
    ],
    'myshowcase_moderators' => [
        'moderator_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'auto_increment' => true,
            'primary_key' => true
        ],
        'showcase_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'user_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'group_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'is_group' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0
        ],
        ModeratorPermissions::EntriesCanView => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0
        ],
        ModeratorPermissions::CommentsCanView => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0
        ],
        ModeratorPermissions::CanManageAttachments => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0
        ],
        ModeratorPermissions::CanManageReports => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0
        ],
        ModeratorPermissions::CanManageLogs => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0
        ],
        //'unique_keys' => ['id_uid_isgroup' => ['showcase_id', 'showcasuser_ide_id', 'is_group']]
    ],
    'myshowcase_fields' => [
        'field_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'auto_increment' => true,
            'primary_key' => true
        ],
        'set_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'field_key' => [
            'type' => 'VARCHAR',
            'size' => 30,
            'default' => '',
            'input_type' => FormTypes::Text,
            'formRequired' => true,
        ],
        'field_label' => [
            'type' => 'VARCHAR',
            'size' => 30,
            'default' => '',
            'input_type' => FormTypes::Text,
        ],
        'placeholder' => [
            'type' => 'VARCHAR',
            'size' => 120,
            'default' => '',
            'input_type' => FormTypes::Text,
        ],
        'description' => [
            'type' => 'VARCHAR',
            'size' => 250,
            'default' => '',
            'input_type' => FormTypes::Text,
        ],
        'html_type' => [
            'type' => 'VARCHAR',
            'size' => 15,
            'default' => '',
            'input_type' => FormTypes::Select,
            'formFunction' => '\MyShowcase\Plugin\Functions\fieldHtmlTypes',
        ],
        'field_type' => [
            'type' => 'VARCHAR',
            'size' => 10,
            'default' => '',
            'input_type' => FormTypes::Select,
            'formFunction' => '\MyShowcase\Plugin\Functions\fieldTypesGet',
        ],
        'file_capture' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'input_type' => FormTypes::Select,
            'formFunction' => '\MyShowcase\Plugin\Functions\fileCaptureTypes',
        ],
        'allow_multiple_values' => [//checkbox, select, text area new line, text box comma
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'input_type' => FormTypes::YesNo,
        ],
        'regular_expression' => [
            'type' => 'VARCHAR',
            'size' => 500,
            'default' => '',
            'input_type' => FormTypes::Text,
        ],
        'input_pattern' => [
            'type' => 'VARCHAR',
            'size' => 500,
            'default' => '',
            'input_type' => FormTypes::Text,
        ],
        'display_in_create_update_page' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'input_type' => FormTypes::YesNo,
        ],
        'display_in_view_page' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'input_type' => FormTypes::YesNo,
        ],
        'display_in_main_page' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'input_type' => FormTypes::YesNo,
        ],
        'minimum_length' => [
            'type' => 'MEDIUMINT',
            'unsigned' => true,
            'default' => 0,
            'input_type' => FormTypes::Number,
        ],
        'maximum_length' => [
            'type' => 'MEDIUMINT',
            'unsigned' => true,
            'default' => 0,
            'formType' => FormTypes::Number,
        ],
        'minimum_size' => [
            'type' => 'MEDIUMINT',
            'unsigned' => true,
            'default' => 0,
            'formType' => FormTypes::Number,
        ],
        'maximum_size' => [
            'type' => 'MEDIUMINT',
            'unsigned' => true,
            'default' => 0,
            'formType' => FormTypes::Number,
        ],
        'step_size' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formType' => FormTypes::Number,
        ],
        'input_size' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formType' => FormTypes::Number,
        ],
        'allowed_groups_fill' => [
            'type' => 'TEXT',
            'null' => true,
            'formType' => FormTypes::SelectMultipleGroups,
            'formMultiple' => true,
        ],
        'allowed_groups_view' => [
            'type' => 'TEXT',
            'null' => true,
            'formType' => FormTypes::SelectMultipleGroups,
            'formMultiple' => true,
        ],
        'default_value' => [
            'type' => 'VARCHAR',
            'size' => 250,
            'default' => '',
            'formType' => FormTypes::Text,
        ],
        'default_type' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formType' => FormTypes::Select,
            'formFunction' => '\MyShowcase\Plugin\Functions\fieldDefaultTypes',
        ],
        'display_order' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formType' => FormTypes::Number,
        ],
        'render_order' => [
            'unsigned' => true,
            'type' => 'TINYINT',
            'default' => 0,
            'formType' => FormTypes::Number,
        ],
        // todo, remove this legacy updating the database and updating the format field to TINYINT
        'format' => [
            'type' => 'VARCHAR',
            'size' => 10,
            'default' => 0,
            'formType' => FormTypes::Select,
            'formFunction' => '\MyShowcase\Plugin\Functions\formatTypes',
        ],
        'filter_on_save' => [ //uuid, etc
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formType' => FormTypes::Select,
            'formFunction' => '\MyShowcase\Plugin\Functions\filterTypes',
        ],
        'is_enabled' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'quickSetting' => true,
        ],
        'is_required' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formType' => FormTypes::YesNo,
        ],
        'parse' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formType' => FormTypes::YesNo,
        ],
        'parser_allow_html' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formType' => FormTypes::YesNo,
        ],
        'parser_allow_mycode' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formType' => FormTypes::YesNo,
        ],
        'parser_allow_smiles' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formType' => FormTypes::YesNo,
        ],
        'parser_allow_image_code' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formType' => FormTypes::YesNo,
        ],
        'parser_allow_video_code' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formType' => FormTypes::YesNo,
        ],
        'enable_search' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formType' => FormTypes::YesNo,
        ],
        'enable_slug' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formType' => FormTypes::YesNo,
        ],
        'enable_subject' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formType' => FormTypes::YesNo,
        ],
        'enable_description' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formType' => FormTypes::YesNo,
        ],
        'enable_editor' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formType' => FormTypes::YesNo,
        ],
        'read_only' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formType' => FormTypes::YesNo,
        ],
        'unique_keys' => ['set_field_name' => ['set_id', 'field_key']]
        //'unique_keys' => ['setid_fid' => ['set_id', 'field_id']]
    ],
    'myshowcase_field_data' => [
        'field_data_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'auto_increment' => true,
            'primary_key' => true
        ],
        'set_id' => [
            'type' => 'INT',
            'unsigned' => true,
        ],
        'field_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'field_key' => [
            'type' => 'VARCHAR',
            'size' => 30,
            'default' => '',
            //'unique_keys' => true,
        ],
        'value_id' => [
            'type' => 'VARCHAR',
            'size' => 120,
            'default' => 0,
            'formType' => FormTypes::Text,
        ],// todo, drop this redundant field
        'value' => [
            'type' => 'VARCHAR',
            'size' => 120,
            'default' => '',
            'formType' => FormTypes::Text,
        ],
        'display_style' => [
            'type' => 'VARCHAR',
            'size' => 200,
            'default' => '',
            'formType' => FormTypes::Text,
        ],
        'display_order' => [
            'type' => 'SMALLINT',
            'unsigned' => true,
            'default' => 0,
            'formType' => FormTypes::Number
        ],
        'allowed_groups_fill' => [
            'type' => 'TEXT',
            'null' => true,
            'formType' => FormTypes::SelectMultipleGroups,
            'formMultiple' => true,
        ],
        'allowed_groups_view' => [
            'type' => 'TEXT',
            'null' => true,
            'formType' => FormTypes::SelectMultipleGroups,
            'formMultiple' => true,
        ],
        'unique_keys' => ['set_field_value_id' => ['set_id', 'field_id', 'value_id']]
    ],
    'myshowcase_logs' => [
        'log_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'auto_increment' => true,
            'primary_key' => true
        ],
        'showcase_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'type_id' => [ // entry, comment, attachment
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'unique_id' => [
            'type' => 'INT',
            'unsigned' => true
        ],
        'user_id' => [
            'type' => 'INT',
            'unsigned' => true
        ],
        'log_time' => [
            'type' => 'INT',
            'unsigned' => true
        ],
        'log_data' => [ // comments old message, etc
            'type' => 'TEXT',
            'null' => true
        ],
    ],
    'myshowcase_reads' => [
        'showcase_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'entry_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'user_id' => [
            'type' => 'INT',
            'unsigned' => true
        ],
        'dateline' => [
            'type' => 'INT',
            'unsigned' => true
        ],
    ],
];
// todo, Extra Forum Permissions integration
// todo, integrate with ougc Online Users List
// todo, attachment display type, thumbnail_name, full, link

const FIELDS_DATA = [
    'usergroups' => [
        'myshowcase_' . UserPermissions::MainCanView => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::CanSearchEntries => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::EntriesCanView => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::EntriesCanPost => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::EntriesCanUpdate => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::EntriesCanDelete => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::CommentsCanView => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::CommentsCanPost => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::CommentsCanUpdate => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::CommentsCanDelete => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::AttachmentsCanView => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::AttachmentsCanUpload => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::AttachmentsCanUpdate => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::AttachmentsCanDelete => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::AttachmentsCanDownload => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::AttachmentsLimitUploadQuotaAmount => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::AttachmentsWaterMarkImages => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::EntriesCanViewDeletionNotices => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::EntriesModerateNew => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'lowest' => true,
        ],
        'myshowcase_' . UserPermissions::EntriesModerateUpdates => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'lowest' => true,
        ],
        'myshowcase_' . UserPermissions::CommentsModerateNew => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'lowest' => true,
        ],
        'myshowcase_' . UserPermissions::CommentsModerateUpdates => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'lowest' => true,
        ],
        'myshowcase_' . UserPermissions::AttachmentsModerateNew => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'lowest' => true,
        ],
        'myshowcase_' . UserPermissions::AttachmentsModerateUpdates => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'lowest' => true,
        ],
    ],
    'attachtypes' => [
        'myshowcase_ids' => [
            'type' => 'TEXT',
            'null' => true
        ],
        'myshowcase_image_minimum_dimensions' => [
            'type' => 'VARCHAR',
            'size' => 20,
            'null' => true
        ],
        'myshowcase_image_maximum_dimensions' => [
            'type' => 'VARCHAR',
            'size' => 20,
            'null' => true
        ],
    ]
];

// todo, general settings
// maximum entry subject
// maximum comment length
// enable entry length

// todo, field settings
// limit files per field
// watermark images
// generate thumbnails

// todo, display a notice for unapproved entries/comments/attachments

// todo, add field setting to order entries by (i.e: sticky)
// todo, add field setting to block entries by (i.e: closed)
// todo, add field setting to record changes by (i.e: history)
// todo, add field setting to search fields data (i.e: enable_search)
// todo, DVZ Stream
// todo, NewPoints integration, income,
// integrate to AdRem
// todo, users see own unapproved content
// todo, build Pages menu as if inside a showcase (add_breadcrum, force theme, etc)
// ougc Private Threads integration
const DATA_TABLE_STRUCTURE = [
    'myshowcase_data' => [
        'entry_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'auto_increment' => true,
            'primary_key' => true,
        ],
        'entry_slug' => [
            'type' => 'VARCHAR',
            'size' => SETTINGS['slugLength'],
            'unique_key' => true,
        ],
        'entry_slug_custom' => [
            'type' => 'VARCHAR',
            'size' => 120,
            'default' => '',
        ],
        'post_hash' => [
            'type' => 'VARCHAR',
            'size' => 36,
            'default' => '',
        ],
        'user_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'username' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'subject' => [
            'type' => 'VARCHAR',
            'size' => 120,
            'default' => '',
            'enable_search' => true,
        ],
        'message' => [
            'type' => 'TEXT',
            'null' => true,
            'enable_search' => true,
        ],
        'prefix_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'icon_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'dateline' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'status' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        'is_closed' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'is_sticky' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'views' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'comments_pending' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'comments_approved' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'comments_deleted' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'attachments_pending' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'attachments_approved' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'attachments_deleted' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'edit_stamp' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'edit_user_id' => [ // todo, should be entry_history_id
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'edit_note' => [
            'type' => 'VARCHAR',
            'size' => 120,
            'default' => '',
        ],
        'last_comment_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'last_comment_user_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'last_comment_username' => [
            'type' => 'VARCHAR',
            'size' => 120,
            'default' => '',
        ],
        'allow_signature' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        'disable_smilies' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        'ipaddress' => [
            'type' => 'VARBINARY',
            'size' => 16,
            'default' => '',
        ],
        //'unique_keys' => ['entry_slug_custom' => 'entry_slug_custom']
    ],
    'myshowcase_data_history' => [
        'entry_history_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'auto_increment' => true,
            'primary_key' => true
        ],
        'entry_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'edit_stamp' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'edit_user_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'entry_slug_custom' => [
            'type' => 'VARCHAR',
            'size' => 120,
            'default' => '',
        ],
        'subject' => [
            'type' => 'VARCHAR',
            'size' => 120,
            'default' => '',
        ],
        'is_closed' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0
        ],
        'is_sticky' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1
        ],
        'status' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1
        ],
        'ipaddress' => [
            'type' => 'VARBINARY',
            'size' => 16,
            'default' => ''
        ],
    ],
];