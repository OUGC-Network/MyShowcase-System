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

use MyShowcase\Plugin\FormTypes;
use MyShowcase\System\ModeratorPermissions;
use MyShowcase\System\UserPermissions;

const VERSION = '3.0.0';

const VERSION_CODE = 3000;

const SHOWCASE_STATUS_DISABLED = 0;

const SHOWCASE_STATUS_ENABLED = 1;

const UPLOAD_STATUS_INVALID = 1;

const UPLOAD_STATUS_FAILED = 2;

const CACHE_TYPE_CONFIG = 'config';

const CACHE_TYPE_FIELDS = 'fields';

const CACHE_TYPE_FIELD_SETS = 'fieldsets';

const CACHE_TYPE_MODERATORS = 'moderators';

const CACHE_TYPE_PERMISSIONS = 'permissions';

const CACHE_TYPE_FIELD_DATA = 'field_data';

const CACHE_TYPE_ATTACHMENT_TYPES = 'attachment_types';

const MODERATOR_TYPE_USER = 0;

const MODERATOR_TYPE_GROUP = 1;

const ATTACHMENT_THUMBNAIL_ERROR = 3;

const ATTACHMENT_IMAGE_TOO_SMALL_FOR_THUMBNAIL = 4;

const ATTACHMENT_THUMBNAIL_SMALL = 1;

const URL = 'index.php?module=myshowcase-summary';

const ALL_UNLIMITED_VALUE = -1;

const REPORT_STATUS_PENDING = 0;

const ERROR_TYPE_NOT_INSTALLED = 1;

const ERROR_TYPE_NOT_CONFIGURED = 1;

const CHECK_BOX_IS_CHECKED = 1;

const ORDER_DIRECTION_ASCENDING = 'asc';

const ORDER_DIRECTION_DESCENDING = 'desc';

const COMMENT_STATUS_PENDING_APPROVAL = 0;

const COMMENT_STATUS_VISIBLE = 1;

const COMMENT_STATUS_SOFT_DELETED = 2;

const ENTRY_STATUS_PENDING_APPROVAL = 0;

const ENTRY_STATUS_VISIBLE = 1;

const ENTRY_STATUS_SOFT_DELETED = 2;

const ATTACHMENT_STATUS_PENDING_APPROVAL = 0;

const ATTACHMENT_STATUS_VISIBLE = 1;

const ATTACHMENT_STATUS_SOFT_DELETED = 2;

const DATA_HANDLER_METHOD_INSERT = 'insert';

const DATA_HANDLER_METHOD_UPDATE = 'update';

const GUEST_GROUP_ID = 1;

const FILTER_TYPE_NONE = 0;

const FILTER_TYPE_USER_ID = 1;

const UPLOAD_ERROR_FAILED = 1;

const WATERMARK_LOCATION_LOWER_LEFT = 1;

const WATERMARK_LOCATION_LOWER_RIGHT = 2;

const WATERMARK_LOCATION_CENTER = 3;

const WATERMARK_LOCATION_UPPER_LEFT = 4;

const WATERMARK_LOCATION_UPPER_RIGHT = 5;

const HTTP_CODE_PERMANENT_REDIRECT = 301;

const TABLES_DATA = [
    'myshowcase_attachments' => [
        'attachment_id' => [
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
        'post_hash' => [
            'type' => 'VARCHAR',
            'size' => 36,
            'default' => '',
        ],
        'attachment_hash' => [
            'type' => 'VARCHAR',
            'size' => 36,
            'default' => '',
            'unique_key' => true
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
        'dateline' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'status' => [
            'type' => 'TINYINT',
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
        'edit_stamp' => [ // todo, should be attachment_history_id
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
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
            'size' => SETTINGS['slugLength'] * 2,
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
        'user_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'post_hash' => [
            'type' => 'VARCHAR',
            'size' => 36,
            'default' => '',
        ],
        // todo, update old data from varchar to varbinary
        'ipaddress' => [
            'type' => 'VARBINARY',
            'size' => 16,
            'default' => ''
        ],
        'comment' => [
            'type' => 'TEXT',
            'null' => true
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
        'edit_user_id' => [ // todo, should be comment_history_id
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        /*'reply_to_comment_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'reply_to_attachment_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'display_signature' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1
        ],
        'display_smiles' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1
        ],*/
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
        'ipaddress' => [
            'type' => 'VARBINARY',
            'size' => 16,
            'default' => ''
        ],
        'comment' => [
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
        'name' => [
            'type' => 'VARCHAR',
            'size' => 50,
            'default' => '',
            'formCategory' => 'main',
            'formType' => FormTypes::Text,
        ],
        'description' => [
            'type' => 'VARCHAR',
            'size' => 255,
            'default' => '',
            'formCategory' => 'main',
            'formType' => FormTypes::Text,
        ],
        'script_name' => [
            'type' => 'VARCHAR',
            'size' => 50,
            'default' => '',
            'formCategory' => 'main',
            'formType' => FormTypes::Text,
        ],
        'field_set_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'main',
            'formType' => FormTypes::Select,
            'form_function' => '\MyShowcase\Plugin\Core\generateFieldSetSelectArray',
        ],
        /*'relative_path' => [
            'type' => 'VARCHAR',
            'size' => 255,
            'default' => '',
            'formCategory' => 'main',
            'formType' => \MyShowcase\Plugin\FormTypes::Text,
        ],*/
        'enabled' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'main',
            'formType' => FormTypes::YesNo,
        ],
        'enable_friendly_urls' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'main',
            'formType' => FormTypes::YesNo,
        ],
        'display_order' => [ // mean to be useful for building a header link, etc
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'main',
            'formType' => FormTypes::Number,
        ],
        /*'enable_dvz_stream_entries' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'main',
            'formType' => \MyShowcase\Plugin\Core\\MyShowcase\Plugin\FormTypes::YesNo,
        ],
        'enable_dvz_stream_comments' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'main',
            'formType' => \MyShowcase\Plugin\Core\\MyShowcase\Plugin\FormTypes::YesNo,
        ],
        'custom_theme_force' => [ // if force & no custom theme selected, force default theme
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'main',
            'formType' => \MyShowcase\Plugin\Core\\MyShowcase\Plugin\FormTypes::YesNo,
        ],
        'custom_theme_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'main',
            'formType' => \MyShowcase\Plugin\Core\\MyShowcase\Plugin\FormTypes::Select,
        ],*/
        'custom_theme_template_prefix' => [
            'type' => 'VARCHAR',
            'size' => 50,
            'default' => '',
            'formCategory' => 'main',
            'formType' => FormTypes::Text,
        ],
        /*'order_default_field' => [ // dateline, username, custom fields, etc
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'main',
            'formType' => \MyShowcase\Plugin\Core\\MyShowcase\Plugin\FormTypes::Select,
            'form_function' => '\MyShowcase\Plugin\Core\generateFilterFieldsSelectArray',
        ],
        'filter_force_field' => [ // force view entries by uid, etc
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'main',
            'formType' => \MyShowcase\Plugin\FormTypes::Select,
            'form_function' => '\MyShowcase\Plugin\Core\generateFilterFieldsSelectArray',
        ],
        'order_default_direction' => [ // asc, desc
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'main',
            'formType' => \MyShowcase\Plugin\Core\\MyShowcase\Plugin\FormTypes::YesNo,
        ],
        'entries_grouping' => [ // inserts template between entry rows in the main page
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'main',
            'formSection' => 'entries',
            'formType' => \MyShowcase\Plugin\FormTypes::Number,
        ],*/
        'entries_per_page' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'entries',
            'formType' => FormTypes::Number,
            'formClass' => 'field150',
        ],
        'parser_allow_html' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'parser',
            'formType' => FormTypes::CheckBox,
        ],
        'parser_allow_mycode' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'parser',
            'formType' => FormTypes::CheckBox,
        ],
        'parser_allow_smiles' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'parser',
            'formType' => FormTypes::CheckBox,
        ],
        'parser_allow_image_code' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'parser',
            'formType' => FormTypes::CheckBox,
        ],
        'parser_allow_video_code' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'parser',
            'formType' => FormTypes::CheckBox,
        ],
        /*'display_moderators_list' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'display',
            'formType' => \MyShowcase\Plugin\FormTypes::CheckBox,
        ],
        'display_stats' => [ // a duplicate of the index table 'showindexstats'
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'display',
            'formType' => \MyShowcase\Plugin\FormTypes::CheckBox,
        ],
        'display_users_browsing_main' => [ // 'showforumviewing'
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'display',
            'formType' => \MyShowcase\Plugin\FormTypes::CheckBox,
        ],
        'display_users_browsing_entries' => [ // 'showforumviewing'
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'display',
            'formType' => \MyShowcase\Plugin\FormTypes::CheckBox,
        ],*/
        'display_empty_fields' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'display',
            'formType' => FormTypes::CheckBox,
        ],
        /*'display_in_posts' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'display',
            'formType' => \MyShowcase\Plugin\FormTypes::CheckBox,
        ],
        'display_profile_fields' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'display',
            'formType' => \MyShowcase\Plugin\FormTypes::CheckBox,
        ],*/
        'display_avatars_entries' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'display',
            'formType' => FormTypes::CheckBox,
        ],
        'display_avatars_comments' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'display',
            'formType' => FormTypes::CheckBox,
        ],
        'display_stars_entries' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'display',
            'formType' => FormTypes::CheckBox,
        ],
        'display_stars_comments' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'display',
            'formType' => FormTypes::CheckBox,
        ],
        'display_group_image_entries' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'display',
            'formType' => FormTypes::CheckBox,
        ],
        'display_group_image_comments' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'display',
            'formType' => FormTypes::CheckBox,
        ],
        'display_user_details_entries' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'display',
            'formType' => FormTypes::CheckBox,
        ],
        'display_user_details_comments' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'display',
            'formType' => FormTypes::CheckBox,
        ],
        'display_signatures_entries' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'display',
            'formType' => FormTypes::CheckBox,
        ],
        'display_signatures_comments' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'display',
            'formType' => FormTypes::CheckBox,
        ],
        'moderate_entries_create' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'moderation',
            'formType' => FormTypes::CheckBox,
        ],
        'moderate_entries_update' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'moderation',
            'formType' => FormTypes::CheckBox,
        ],
        'moderate_comments_create' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'moderation',
            'formType' => FormTypes::CheckBox,
        ],
        'moderate_comments_update' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'moderation',
            'formType' => FormTypes::CheckBox,
        ],
        'moderate_attachments_upload' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'moderation',
            'formType' => FormTypes::CheckBox,
        ],
        'moderate_attachments_update' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'moderation',
            'formType' => FormTypes::CheckBox,
        ],
        'comments_allow' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'comments',
            'formType' => FormTypes::CheckBox,
        ],
        /*
        'display_recursive_comments' => [ // 'showforumviewing'
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'display',
            'formType' => \MyShowcase\Plugin\FormTypes::CheckBox,
        ],
        'comments_allow_quotes' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'comments',
            'formType' => \MyShowcase\Plugin\FormTypes::CheckBox,
        ],*/
        /*'comments_quick_form' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'display',
            'formType' => \MyShowcase\Plugin\FormTypes::CheckBox,
        ],*/
        'comments_build_editor' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'comments',
            'formType' => FormTypes::CheckBox,
        ],
        'comments_minimum_length' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'comments',
            'formType' => FormTypes::Number,
            'formClass' => 'field150',
        ],
        'comments_maximum_length' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'comments',
            'formType' => FormTypes::Number,
            'formClass' => 'field150',
        ],
        'comments_per_page' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'comments',
            'formType' => FormTypes::Number,
            'formClass' => 'field150',
        ],
        /*'comments_direction' => [ // reverse order
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'comments',,
            'formType' => \MyShowcase\Plugin\FormTypes::CheckBox,
        ],*/
        'attachments_allow_entries' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'attachments',
            'formType' => FormTypes::CheckBox,
        ],
        /*'attachments_allow_comments' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'attachments',
            'formType' => \MyShowcase\Plugin\FormTypes::CheckBox,
        ],*/
        'attachments_uploads_path' => [
            'type' => 'VARCHAR',
            'size' => 255,
            'default' => '',
            'formCategory' => 'other',
            'formSection' => 'attachments',
            'formType' => FormTypes::Text,
            'formClass' => 'field150',
        ],
        'attachments_limit_entries' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'attachments',
            'formType' => FormTypes::Number,
            'formClass' => 'field150',
        ],
        /*'attachments_limit_comments' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'attachments',
            'formType' => \MyShowcase\Plugin\FormTypes::Number,
            'formClass' => 'field150',
        ],*/
        /*'attachments_enable_sharing' => [ // allow using attachments from other entries
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'attachments',
            'formType' => \MyShowcase\Plugin\FormTypes::Number,
            'formClass' => 'field150',
        ],*/
        'attachments_grouping' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'attachments',
            'formType' => FormTypes::Number,
            'formClass' => 'field150',
        ],
        'attachments_main_render_first' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'attachments',
            'formType' => FormTypes::CheckBox,
        ],
        /*'attachments_main_render_default_image' => [
            'type' => 'VARCHAR',
            'null' => true,
            'size' => 50,
            'default' => '',
            'formCategory' => 'other',
            'formSection' => 'attachments',
            'formType' => \MyShowcase\Plugin\FormTypes::Text,
            'formClass' => 'field150',
        ],*/
        'attachments_watermark_file' => [
            'type' => 'VARCHAR',
            'null' => true,
            'size' => 50,
            'default' => '',
            'formCategory' => 'other',
            'formSection' => 'attachments',
            'formType' => FormTypes::Text,
            'formClass' => 'field150',
        ],
        'attachments_watermark_location' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'attachments',
            'formType' => FormTypes::Select,
            'form_function' => '\MyShowcase\Plugin\Core\generateWatermarkLocationsSelectArray',
        ],
        /*'attachments_portal_build_widget' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'attachments',
            'formType' => \MyShowcase\Plugin\FormTypes::Number,
            'formClass' => 'field150',
        ],
        'attachments_parse_in_content' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'attachments',
            'formType' => \MyShowcase\Plugin\FormTypes::CheckBox,
        ],*/
        'attachments_thumbnails_width' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'attachments',
            'formType' => FormTypes::Number,
            'formClass' => 'field150',
        ],
        'attachments_thumbnails_height' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formCategory' => 'other',
            'formSection' => 'attachments',
            'formType' => FormTypes::Number,
            'formClass' => 'field150',
        ],
    ],
    'myshowcase_fieldsets' => [
        'set_id' => [
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
        UserPermissions::CanView => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'isPermission' => true,
            'draggingPermission' => true,
            'formType' => FormTypes::CheckBox,
            'formCategory' => 'general',
        ],
        UserPermissions::CanSearch => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'isPermission' => true,
            'formType' => FormTypes::CheckBox,
            'formCategory' => 'general',
        ],
        UserPermissions::CanViewEntries => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'isPermission' => true,
            'draggingPermission' => true,
            'formType' => FormTypes::CheckBox,
            'formCategory' => 'entries',
        ],
        UserPermissions::CanCreateEntries => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'isPermission' => true,
            'draggingPermission' => true,
            'formType' => FormTypes::CheckBox,
            'formCategory' => 'entries',
        ],
        UserPermissions::CanUpdateEntries => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'isPermission' => true,
            'formType' => FormTypes::CheckBox,
            'formCategory' => 'entries',
        ],
        UserPermissions::CanDeleteEntries => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'isPermission' => true,
            'formType' => FormTypes::CheckBox,
            'formCategory' => 'entries',
        ],
        UserPermissions::CanViewComments => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'isPermission' => true,
            'draggingPermission' => true,
            'formType' => FormTypes::CheckBox,
            'formCategory' => 'comments',
        ],
        UserPermissions::CanCreateComments => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'isPermission' => true,
            'draggingPermission' => true,
            'formType' => FormTypes::CheckBox,
            'formCategory' => 'comments',
        ],
        UserPermissions::CanUpdateComments => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'isPermission' => true,
            'formType' => FormTypes::CheckBox,
            'formCategory' => 'comments',
        ],
        UserPermissions::CanDeleteComments => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'isPermission' => true,
            'formType' => FormTypes::CheckBox,
            'formCategory' => 'comments',
        ],
        UserPermissions::CanViewAttachments => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'isPermission' => true,
            'draggingPermission' => true,
            'formType' => FormTypes::CheckBox,
            'formCategory' => 'attachments',
        ],
        UserPermissions::CanUploadAttachments => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'isPermission' => true,
            'draggingPermission' => true,
            'formType' => FormTypes::CheckBox,
            'formCategory' => 'attachments',
        ],
        UserPermissions::CanUpdateAttachments => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'isPermission' => true,
            'formType' => FormTypes::CheckBox,
            'formCategory' => 'attachments',
        ],
        UserPermissions::CanDeleteAttachments => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'isPermission' => true,
            'formType' => FormTypes::CheckBox,
            'formCategory' => 'attachments',
        ],
        UserPermissions::CanDownloadAttachments => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'isPermission' => true,
            'draggingPermission' => true,
            'formType' => FormTypes::CheckBox,
            'formCategory' => 'attachments',
        ],
        UserPermissions::AttachmentsUploadQuote => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
            'isPermission' => true,
            'zeroUnlimited' => true,
            'formType' => FormTypes::Number,
            'formCategory' => 'attachments',
        ],
        UserPermissions::CanWaterMarkAttachments => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'isPermission' => true,
            'formType' => FormTypes::CheckBox,
            'formCategory' => 'attachments',
        ],
        UserPermissions::AttachmentsFilesLimit => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
            'isPermission' => true,
            'zeroUnlimited' => true,
            'formType' => FormTypes::Number,
            'formCategory' => 'attachments',
        ],
        UserPermissions::CanViewSoftDeletedNotice => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'isPermission' => true,
            'formType' => FormTypes::CheckBox,
            'formCategory' => 'moderation',
        ],
        UserPermissions::ModerateEntryCreate => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'isPermission' => true,
            'lowest' => true,
            'formType' => FormTypes::CheckBox,
            'formCategory' => 'moderation',
        ],
        UserPermissions::ModerateEntryUpdate => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'isPermission' => true,
            'lowest' => true,
            'formType' => FormTypes::CheckBox,
            'formCategory' => 'moderation',
        ],
        UserPermissions::ModerateCommentsCreate => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'isPermission' => true,
            'lowest' => true,
            'formType' => FormTypes::CheckBox,
            'formCategory' => 'moderation',
        ],
        UserPermissions::ModerateCommentsUpdate => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'isPermission' => true,
            'lowest' => true,
            'formType' => FormTypes::CheckBox,
            'formCategory' => 'moderation',
        ],
        UserPermissions::ModerateAttachmentsUpload => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'isPermission' => true,
            'lowest' => true,
            'formType' => FormTypes::CheckBox,
            'formCategory' => 'moderation',
        ],
        UserPermissions::ModerateAttachmentsUpdate => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'isPermission' => true,
            'lowest' => true,
            'formType' => FormTypes::CheckBox,
            'formCategory' => 'moderation',
        ],
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
        'is_group' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0
        ],
        ModeratorPermissions::CanManageEntries => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0
        ],
        ModeratorPermissions::CanManageComments => [
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
        ModeratorPermissions::CaManageLogs => [
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
            'formType' => FormTypes::Text,
            'formRequired' => true,
        ],
        'field_label' => [
            'type' => 'VARCHAR',
            'size' => 30,
            'default' => '',
            'formType' => FormTypes::Text,
        ],
        'placeholder' => [
            'type' => 'VARCHAR',
            'size' => 120,
            'default' => '',
            'formType' => FormTypes::Text,
        ],
        'description' => [
            'type' => 'VARCHAR',
            'size' => 250,
            'default' => '',
            'formType' => FormTypes::Text,
        ],
        'html_type' => [
            'type' => 'VARCHAR',
            'size' => 15,
            'default' => '',
            'formType' => FormTypes::Select,
            'formFunction' => '\MyShowcase\Plugin\Core\fieldHtmlTypes',
        ],
        'field_type' => [
            'type' => 'VARCHAR',
            'size' => 10,
            'default' => '',
            'formType' => FormTypes::Select,
            'formFunction' => '\MyShowcase\Plugin\Core\fieldTypesGet',
        ],
        'file_capture' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formType' => FormTypes::Select,
            'formFunction' => '\MyShowcase\Plugin\Core\fileCaptureTypes',
        ],
        'allow_multiple_values' => [//checkbox, select, text area new line, text box comma
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formType' => FormTypes::YesNo,
        ],
        'regular_expression' => [
            'type' => 'VARCHAR',
            'size' => 500,
            'default' => '',
            'formType' => FormTypes::Text,
        ],
        'display_in_create_update_page' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'formType' => FormTypes::YesNo,
        ],
        'display_in_view_page' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'formType' => FormTypes::YesNo,
        ],
        'display_in_main_page' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
            'formType' => FormTypes::YesNo,
        ],
        'minimum_length' => [
            'type' => 'MEDIUMINT',
            'unsigned' => true,
            'default' => 0,
            'formType' => FormTypes::Number,
        ],
        'maximum_length' => [
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
            'formFunction' => '\MyShowcase\Plugin\Core\fieldDefaultTypes',
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
            'formFunction' => '\MyShowcase\Plugin\Core\formatTypes',
        ],
        'filter_on_save' => [ //uuid, etc
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'formType' => FormTypes::Select,
            'formFunction' => '\MyShowcase\Plugin\Core\filterTypes',
        ],
        'enabled' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'quickSetting' => true,
        ],
        'is_required' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'quickSetting' => true,
        ],
        'parse' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'quickSetting' => true,
        ],
        'enable_search' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'quickSetting' => true,
        ],
        'enable_slug' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'quickSetting' => true,
        ],
        'enable_subject' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'quickSetting' => true,
        ],
        'enable_editor' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
            'quickSetting' => true,
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
        ],
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
];
// todo, Extra Forum Permissions integration
// todo, integrate with ougc Online Users List
// todo, attachment display type, thumbnail_name, full, link

const FIELDS_DATA = [
    'usergroups' => [
        'myshowcase_' . UserPermissions::CanView => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::CanSearch => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::CanViewEntries => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::CanCreateEntries => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::CanUpdateEntries => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::CanDeleteEntries => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::CanViewComments => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::CanCreateComments => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::CanUpdateComments => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::CanDeleteComments => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::CanViewAttachments => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::CanUploadAttachments => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::CanUpdateAttachments => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::CanDeleteAttachments => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::CanDownloadAttachments => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::AttachmentsUploadQuote => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::CanWaterMarkAttachments => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::AttachmentsFilesLimit => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::CanViewSoftDeletedNotice => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::ModerateEntryCreate => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::ModerateEntryUpdate => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::ModerateCommentsCreate => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::ModerateCommentsUpdate => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::ModerateAttachmentsUpload => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'myshowcase_' . UserPermissions::ModerateAttachmentsUpdate => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
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
            'primary_key' => true
        ],
        'entry_slug' => [
            'type' => 'VARCHAR',
            'size' => SETTINGS['slugLength'] * 2,
            'unique_key' => true
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
        /*'category_primary' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'category_secondary' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],*/
        'user_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'dateline' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        'views' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'comments' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'is_closed' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0
        ],
        'is_featured' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1
        ],
        /*
        notes text NOT NULL,*/
        'status' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1
        ],
        'edit_user_id' => [ // todo, should be entry_history_id
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],
        /*'approved' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0
        ],
        'approved_by' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0
        ],*/
        'entry_hash' => [
            'type' => 'VARCHAR',
            'size' => 36,
            'default' => ''
        ],
        'ipaddress' => [
            'type' => 'VARBINARY',
            'size' => 16,
            'default' => ''
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
        'is_featured' => [
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