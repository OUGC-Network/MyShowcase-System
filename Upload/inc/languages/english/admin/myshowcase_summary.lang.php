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

//main words
$l['myshowcase_summary_id'] = 'ID';
$l['myshowcase_summary_name'] = 'Name';
$l['myshowcase_summary_slug'] = 'Slug';
$l['myshowcase_summary_description'] = 'Description';
$l['myshowcase_summary_entries_count'] = 'Record Count';
$l['myshowcase_summary_attachments_count'] = 'Attachments';
$l['myshowcase_summary_attachments_size'] = 'Files Size';
$l['myshowcase_summary_comment_count'] = 'Comments';
$l['myshowcase_summary_image_folder'] = 'Image Folder';
$l['myshowcase_summary_main_file'] = 'Script File';
$l['myshowcase_summary_forum_folder'] = 'Relative Path';
$l['myshowcase_summary_field_set'] = 'Field Set';

//status
$l['myshowcase_summary_status'] = 'Status';
$l['myshowcase_summary_status_enabled'] = 'Enabled';
$l['myshowcase_summary_status_disabled'] = 'Disabled';
$l['myshowcase_summary_status_notable'] = 'Data table for this showcase does not exist';

//editing
$l['myshowcase_summary_existing'] = 'Existing Showcases';

//messages
$l['myshowcase_summary_no_myshowcases'] = 'There are no showcases present.';
$l['myshowcase_summary_not_specified'] = 'Not Specified';

//control-options
$l['myshowcase_summary_edit'] = 'Edit';
$l['myshowcase_summary_delete'] = 'Delete';
$l['myshowcase_summary_enable'] = 'Enable';
$l['myshowcase_summary_disable'] = 'Disable';
$l['myshowcase_summary_createtable'] = 'Create Table';
$l['myshowcase_summary_rebuildtable'] = 'Rebuild Table';
$l['myshowcase_summary_deletetable'] = 'Drop Table';
$l['myshowcase_summary_seo'] = 'Show SEO';

$l = array_merge([
    'myShowcaseAdminSummary' => 'Summary',
    'myShowcaseAdminSummaryDescription' => 'View and manage showcases.',

    'myShowcaseAdminSummaryNew' => 'Add New Showcase',
    'myShowcaseAdminSummaryNewDescription' => 'Add a new showcase.',

    'myShowcaseAdminSummaryEdit' => 'Edit Showcase',
    'myShowcaseAdminSummaryEditDescription' => 'Edit a new showcase.',

    'myShowcaseAdminSummaryEditModeratorPermissions' => 'Moderator Permissions',
    'myShowcaseAdminSummaryEditModeratorPermissionsDescription' => '',
    'myShowcaseAdminSummaryEditPermissionsGroup' => 'Group',
    'myShowcaseAdminSummaryEditPermissionsCanView' => 'Can View?',
    'myShowcaseAdminSummaryEditPermissionsCanAdd' => 'Can Add?',
    'myShowcaseAdminSummaryEditPermissionsCanEdit' => 'Can Edit?',
    'myShowcaseAdminSummaryEditPermissionsCanComment' => 'Can Comment?',
    'myShowcaseAdminSummaryEditPermissionsCanAttach' => 'Can Attach?',
    'myShowcaseAdminSummaryEditPermissionsCanDeleteOwnComment' => 'Can Delete Own Comments?',
    'myShowcaseAdminSummaryEditPermissionsCanDeleteAuthorComment' => 'Can Delete Author Comments?',
    'myShowcaseAdminSummaryEditPermissionsCanViewComments' => 'Can View Comments?',
    'myShowcaseAdminSummaryEditPermissionsCanViewAttachments' => 'Can View Attachments?',
    'myShowcaseAdminSummaryEditPermissionsCanSearch' => 'Can Search Showcases?',
    'myShowcaseAdminSummaryEditPermissionsCanWatermark' => 'Can Watermark Images?',
    'myShowcaseAdminSummaryEditPermissionsAttachmentLimit' => 'Max Attachments per showcase entry',
    'myShowcaseAdminSummaryEditModeratorPermissionsAssigned' => 'Moderators Assigned to "{1}"',
    'myShowcaseAdminSummaryEditModeratorPermissionsName' => 'Name',
    'myShowcaseAdminSummaryEditModeratorPermissionsCanApprove' => 'Can Approve?',
    'myShowcaseAdminSummaryEditModeratorPermissionsCanDelete' => 'Can Delete?',
    'myShowcaseAdminSummaryEditModeratorPermissionsCanEdit' => 'Can Edit?',
    'myShowcaseAdminSummaryEditModeratorPermissionsCanDeleteAuthorComment' => 'Can Delete Author Comments?',
    'myShowcaseAdminSummaryEditModeratorPermissionsControls' => 'Controls',
    'myShowcaseAdminSummaryEditModeratorPermissionsEmpty' => 'There are no moderators assigned.',
    'myShowcaseAdminSummaryEditModeratorPermissionsAddGroup' => 'Add a user group as Moderators',
    'myShowcaseAdminSummaryEditModeratorPermissionsAddGroupGroup' => 'User Group',
    'myShowcaseAdminSummaryEditModeratorPermissionsAddGroupGroupDescription' => 'Select a user group to add as a Moderator from the list below.',
    'myShowcaseAdminSummaryEditModeratorPermissionsCanApproveEntries' => 'Can approve entries in this showcase?',
    'myShowcaseAdminSummaryEditModeratorPermissionsCanEditEntries' => 'Can edit entries in this showcase?',
    'myShowcaseAdminSummaryEditModeratorPermissionsCanDeleteEntries' => 'Can delete entries in this showcase?',
    'myShowcaseAdminSummaryEditModeratorPermissionsCanDeleteComments' => 'Can delete comments in this showcase?',
    'myShowcaseAdminSummaryEditModeratorPermissionsAddUser' => 'Add a user as a Moderator',
    'myShowcaseAdminSummaryEditModeratorPermissionsAddUserUsername' => 'User',
    'myShowcaseAdminSummaryEditModeratorPermissionsAddUserUsernameDescription' => 'Username of the moderator to be added.',

    'myShowcaseAdminErrorInvalidShowcase' => 'Invalid showcase',
    'myShowcaseAdminErrorNoFieldSets' => 'Can not create a new showcase as there are no field sets defined.',
    'myShowcaseAdminErrorDuplicatedName' => 'The selected name is already in use.',
    'myShowcaseAdminErrorDuplicatedScriptFile' => 'The selected script file is already in use.',
    'myShowcaseAdminErrorDuplicatedTemplatePrefix' => 'The selected template prefix is already in use.',
    'myShowcaseAdminErrorTableDelete' => "This showcase's data table contains data and can not be deleted separately. If you wish to delete the populated table, delete the entire showcase.",
    'myShowcaseAdminErrorTableCreate' => 'Creating the showcase data table was unsuccessful.',
    'myShowcaseAdminErrorShowcaseDelete' => 'The showcase deletion failed.',
    'myShowcaseAdminErrorInvalidGroup' => 'Invalid user group',

    'myShowcaseAdminSuccessNewShowcase' => 'The showcase was successfully added.',
    'myShowcaseAdminSuccessEnabledShowcase' => 'The showcase was successfully is_enabled.',
    'myShowcaseAdminSuccessDisabledShowcase' => 'The showcase was successfully disabled.',
    'myShowcaseAdminSuccessTableCreated' => 'The showcase data table was successfully created.',
    'myShowcaseAdminSuccessTableRebuilt' => 'The showcase data table was successfully rebuilt.',
    'myShowcaseAdminSuccessTableDropped' => 'The showcase data table was successfully dropped.',
    'myShowcaseAdminSuccessShowcaseDeleted' => 'The showcase was successfully deleted.',
    'myShowcaseAdminSuccessShowcaseUpdated' => 'The showcase settings were successfully updated.',
    'myShowcaseAdminSuccessShowcaseEditOther' => 'The showcase additional settings were successfully edited.',
    'myShowcaseAdminSuccessShowcaseEditPermissions' => 'The showcase custom permissions were successfully edited.',
    'myShowcaseAdminSuccessShowcaseEditModerators' => 'The showcase moderators were successfully edited.',
    'myShowcaseAdminSuccessShowcaseEditModeratorDelete' => 'The moderator was successfully removed.',

    'myShowcaseAdminConfirmTableDrop' => 'Are you sure you want to drop the table for the "{1}" showcase?',
    'myShowcaseAdminConfirmShowcaseDelete' => 'Are you sure you want to delete the "{1}" showcase? All comments, attachment references and showcase entries will be permanently deleted.',
    'myShowcaseAdminConfirmModeratorDelete' => 'Are you sure you want to remove this moderator?',

    'myShowcaseAdminSummaryViewRewrites' => 'View Rewrites',
    'myShowcaseAdminSummaryViewRewritesDescription' => 'If you are using the built-in MyBB SEO or other SEO plugin, you will need to enter the information below into your .htaccess file. These settings are specific to the "{1}" showcase. If there are other showcases, the SEO settings for those will need to be added as well. If the "{2}" showcase name is changed, these .htaccess settings will need to be updated. After changing the name, return to this page to obtain the new settings and update the .htaccess file as appropriate.<br /><br />',
    'myShowcaseAdminSummaryViewRewritesMain' => 'RewriteRule ^{1}\.html$ {2} [L,QSA]<br />',
    'myShowcaseAdminSummaryViewRewritesPage' => 'RewriteRule ^{1}-page-([0-9]+)\.html$ {2}?page=$1 [L,QSA]<br />',
    'myShowcaseAdminSummaryViewRewritesView' => 'RewriteRule ^{1}-view-([0-9]+)\.html$ {2}?action=view&entry_id=$1 [L,QSA]<br />',
    'myShowcaseAdminSummaryViewRewritesNew' => 'RewriteRule ^{1}-new\.html$ {2}?action=new [L,QSA]<br />',
    'myShowcaseAdminSummaryViewRewritesAttachment' => 'RewriteRule ^{1}-attachment-([0-9]+)\.html$ {2}?action=attachment&attachment_id=$1 [L,QSA]<br />',
    'myShowcaseAdminSummaryViewRewritesEntry' => 'RewriteRule ^{1}-item-([0-9]+)\.php$ {2}?action=item&attachment_id=$1 [L,QSA]<br />',

    'myShowcaseAdminUserGroup' => 'Usergroup',

    'myshowcase_admin_summary_create_update_category_general' => 'General',
    'myshowcase_admin_summary_create_update_category_general_description' => 'General Options',
    'myshowcase_admin_summary_create_update_category_main' => 'Main',
    'myshowcase_admin_summary_create_update_category_main_description' => 'Main Options',
    'myshowcase_admin_summary_create_update_category_parser' => 'Parser',
    'myshowcase_admin_summary_create_update_category_parser_description' => 'Parser Options',
    'myshowcase_admin_summary_create_update_category_entries' => 'Entries',
    'myshowcase_admin_summary_create_update_category_entries_description' => 'Entries Options',
    'myshowcase_admin_summary_create_update_category_comments' => 'Comments',
    'myshowcase_admin_summary_create_update_category_comments_description' => 'Comments Options',
    'myshowcase_admin_summary_create_update_category_attachments' => 'Attachments',
    'myshowcase_admin_summary_create_update_category_attachments_description' => 'Attachments Options',
    'myshowcase_admin_summary_create_update_category_subscriptions' => 'Subscriptions',
    'myshowcase_admin_summary_create_update_category_subscriptions_description' => 'Subscriptions Options',
    'myshowcase_admin_summary_create_update_category_permissions' => 'Permissions',
    'myshowcase_admin_summary_create_update_category_permissions_description' => 'Permissions Options',
    'myshowcase_admin_summary_create_update_category_moderators' => 'Moderators',
    'myshowcase_admin_summary_create_update_category_moderators_description' => 'Moderators Options',

    'myshowcase_admin_summary_create_update_field_set_id' => 'Field Set',
    'myshowcase_admin_summary_create_update_field_set_id_description' => 'The field set used to define the structure of the showcase data table. This cannot be changed after the table is created.',
    'myshowcase_admin_summary_create_update_name' => 'Name',
    'myshowcase_admin_summary_create_update_name_description' => 'The name used in links, titles, and references for this showcase.',
    'myshowcase_admin_summary_create_update_description' => 'Description',
    'myshowcase_admin_summary_create_update_description_description' => 'A brief description of the showcase’s purpose or content.',
    'myshowcase_admin_summary_create_update_route_identifier' => 'Route Identifier',
    'myshowcase_admin_summary_create_update_route_identifier_description' => 'A unique identifier for SEO-friendly URLs (e.g., <code>showcase</code> or <code>gallery</code>).',
    'myshowcase_admin_summary_create_update_showcase_url' => 'Url to Showcase',
    'myshowcase_admin_summary_create_update_showcase_url_description' => 'The URL path where the showcase will be accessible (e.g., <code>htpps://mysite.tld/forum</code>).',
    'myshowcase_admin_summary_create_update_password' => 'Require Access Password',
    'myshowcase_admin_summary_create_update_password_description' => 'Optional password to restrict access to this showcase. Leave empty for no password protection.',
    'myshowcase_admin_summary_create_update_password_minutes_interval' => 'Access Password Minutes Interval',
    'myshowcase_admin_summary_create_update_password_minutes_interval_description' => 'Duration (in minutes) for which the password confirmation remains valid.',
    'myshowcase_admin_summary_create_update_password_ip_check' => 'Access Password IP Check',
    'myshowcase_admin_summary_create_update_password_ip_check_description' => "If enabled, the password confirmation will be tied to the user's IP address for added security.",
    'myshowcase_admin_summary_create_update_custom_theme_force' => 'Force Custom Theme',
    'myshowcase_admin_summary_create_update_custom_theme_force_description' => 'If enabled, this showcase will always use the custom theme, overriding the default.',
    'myshowcase_admin_summary_create_update_custom_theme_id' => 'Custom Theme',
    'myshowcase_admin_summary_create_update_custom_theme_id_description' => 'Select the custom theme to use for this showcase.',
    'myshowcase_admin_summary_create_update_custom_theme_id_none' => '(none)',
    'myshowcase_admin_summary_create_update_custom_theme_template_prefix' => 'Theme Template Prefix',
    'myshowcase_admin_summary_create_update_custom_theme_template_prefix_description' => 'Prefix for custom theme templates (e.g., "showcase_" for "showcase_template.html").',
    'myshowcase_admin_summary_create_update_custom_template_prefix' => 'Custom Template Prefix',
    'myshowcase_admin_summary_create_update_custom_template_prefix_description' => 'Prefix for custom templates used in this showcase.',
    'myshowcase_admin_summary_create_update_rules_type' => 'Rules Type',
    'myshowcase_admin_summary_create_update_rules_type_description' => 'The type of rules to display in this showcase.',
    'myshowcase_admin_summary_create_update_general_rule_type_none' => 'None showcase rules',
    'myshowcase_admin_summary_create_update_general_rule_type_main' => 'Show in main page',
    'myshowcase_admin_summary_create_update_general_rule_type_main_create_update' => 'Show in main, create, and update pages',
    'myshowcase_admin_summary_create_update_general_rule_type_link' => 'Link to rules in main, create, and update pages',

    'myshowcase_admin_summary_create_update_rules_title' => 'Rules Title',
    'myshowcase_admin_summary_create_update_rules_title_description' => 'Title for the rules section displayed in the showcase.',
    'myshowcase_admin_summary_create_update_rules_content' => 'Rules Content',
    'myshowcase_admin_summary_create_update_rules_content_description' => 'The content or link of the rules to be displayed. If using a link, ensure it starts with <code>https://</code>.',
    'myshowcase_admin_summary_create_update_is_enabled' => 'Enabled',
    'myshowcase_admin_summary_create_update_is_enabled_description' => 'Toggle to enable or disable this showcase. When disabled, user permissions will be the most restrictive, ignoring all permission configurations.',
    'myshowcase_admin_summary_create_update_display_order' => 'Display Order',
    'myshowcase_admin_summary_create_update_display_order_description' => 'Determines the order in which this showcase is displayed and built relative to others.',

    'myshowcase_admin_summary_create_update_main_display_moderators' => 'Display Moderators',
    'myshowcase_admin_summary_create_update_main_display_moderators_description' => 'Show moderators in the showcase view.',
    'myshowcase_admin_summary_create_update_main_display_users_browsing' => 'Display Users Browsing',
    'myshowcase_admin_summary_create_update_main_display_users_browsing_description' => 'Show users currently browsing the showcase.',
    'myshowcase_admin_summary_create_update_main_per_page' => 'Entries Per Page',

    'myshowcase_admin_summary_create_update_main_per_page_description' => 'Number of entries to display per page.',
    'myshowcase_admin_summary_create_update_main_filter_force_field' => 'Force Filter Field',
    'myshowcase_admin_summary_create_update_main_filter_force_field_none' => '(none)',
    'myshowcase_admin_summary_create_update_main_filter_force_field_user_id' => 'User ID (profile showcase)',

    'myshowcase_admin_summary_create_update_main_filter_force_field_description' => 'Force a specific field to be used as the default filter.',
    'myshowcase_admin_summary_create_update_main_sort_default_field' => 'Default Sort Field',
    'myshowcase_admin_summary_create_update_main_filter_sort_field_dateline' => 'Date Created',
    'myshowcase_admin_summary_create_update_main_filter_sort_field_username' => 'Username',
    'myshowcase_admin_summary_create_update_main_filter_sort_field_user_identifier' => 'User Identifier',
    'myshowcase_admin_summary_create_update_main_filter_sort_field_views' => 'Views',
    'myshowcase_admin_summary_create_update_main_filter_sort_field_comments_approved' => 'Approved Comments',
    'myshowcase_admin_summary_create_update_main_filter_sort_field_last_comment' => 'Last Comment',

    'myshowcase_admin_summary_create_update_main_sort_default_field_description' => 'The default field used for sorting entries in the showcase.',
    'myshowcase_admin_summary_create_update_main_sort_direction' => 'Ascending Sort Direction',
    'myshowcase_admin_summary_create_update_main_sort_direction_description' => 'Set the default sort direction to ascending for entries in the showcase.',

    'myshowcase_admin_summary_create_update_parser_allow_html_in_entries' => 'Allow HTML in Entries',
    'myshowcase_admin_summary_create_update_parser_allow_html_in_entries_description' => 'Allow HTML in messages.',
    'myshowcase_admin_summary_create_update_parser_allow_mycode_in_entries' => 'Allow MyCode in Entries',
    'myshowcase_admin_summary_create_update_parser_allow_mycode_in_entries_description' => 'Allow custom MyCode formatting in messages.',
    'myshowcase_admin_summary_create_update_parser_allow_smiles_in_entries' => 'Allow Smilies in Entries',
    'myshowcase_admin_summary_create_update_parser_allow_smiles_in_entries_description' => 'Allow smilies in messages.',
    'myshowcase_admin_summary_create_update_parser_allow_image_code_in_entries' => 'Allow Image Codes in Entries',
    'myshowcase_admin_summary_create_update_parser_allow_image_code_in_entries_description' => 'Allow embedded image codes in messages.',
    'myshowcase_admin_summary_create_update_parser_allow_video_code_in_entries' => 'Allow Video Codes in Entries',
    'myshowcase_admin_summary_create_update_parser_allow_video_code_in_entries_description' => 'Allow embedded video codes in messages.',
    'myshowcase_admin_summary_create_update_parser_affects_message_length_in_entries' => 'MyCode Affects Minimum Message Length in Entries?',
    'myshowcase_admin_summary_create_update_parser_affects_message_length_in_entries_description' => 'Do you want MyCode to be counted as part of the limitation when users use MyCode in messages?',

    'myshowcase_admin_summary_create_update_parser_allow_html_in_comments' => 'Allow HTML in Comments',
    'myshowcase_admin_summary_create_update_parser_allow_html_in_comments_description' => 'Allow HTML in messages.',
    'myshowcase_admin_summary_create_update_parser_allow_mycode_in_comments' => 'Allow MyCode in Comments',
    'myshowcase_admin_summary_create_update_parser_allow_mycode_in_comments_description' => 'Allow custom MyCode formatting in messages.',
    'myshowcase_admin_summary_create_update_parser_allow_smiles_in_comments' => 'Allow Smilies in Comments',
    'myshowcase_admin_summary_create_update_parser_allow_smiles_in_comments_description' => 'Allow smilies/emoticons in messages.',
    'myshowcase_admin_summary_create_update_parser_allow_image_code_in_comments' => 'Allow Image Codes in Comments',
    'myshowcase_admin_summary_create_update_parser_allow_image_code_in_comments_description' => 'Allow embedded image codes in messages.',
    'myshowcase_admin_summary_create_update_parser_allow_video_code_in_comments' => 'Allow Video Codes in Comments',
    'myshowcase_admin_summary_create_update_parser_allow_video_code_in_comments_description' => 'Allow embedded video codes in messages.',
    'myshowcase_admin_summary_create_update_parser_affects_message_length_in_comments' => 'MyCode Affects Minimum Message Length in Comments?',
    'myshowcase_admin_summary_create_update_parser_affects_message_length_in_comments_description' => 'Do you want MyCode to be counted as part of the limitation when users use MyCode in messages?',

    'myshowcase_admin_summary_create_update_entries_allow_post_icons' => 'Allow Post Icons',
    'myshowcase_admin_summary_create_update_entries_allow_post_icons_description' => 'Allow users to add icons to their posts.',

    'myshowcase_admin_summary_create_update_entries_enable_subject' => 'Enable Subject',
    'myshowcase_admin_summary_create_update_entries_enable_subject_description' => 'Enable the subject/title field for entries.',
    'myshowcase_admin_summary_create_update_entries_subject_minimum_length' => 'Subject Minimum Length',
    'myshowcase_admin_summary_create_update_entries_subject_minimum_length_description' => 'Minimum number of characters required for the subject.',
    'myshowcase_admin_summary_create_update_entries_subject_maximum_length' => 'Subject Maximum Length',
    'myshowcase_admin_summary_create_update_entries_subject_maximum_length_description' => 'Maximum number of characters allowed for the subject.',
    'myshowcase_admin_summary_create_update_entries_enable_message' => 'Enable Message',
    'myshowcase_admin_summary_create_update_entries_enable_message_description' => 'Enable the message/content field for entries.',
    'myshowcase_admin_summary_create_update_entries_message_minimum_length' => 'Message Minimum Length',
    'myshowcase_admin_summary_create_update_entries_message_minimum_length_description' => 'Minimum number of characters required for the message.',
    'myshowcase_admin_summary_create_update_entries_message_maximum_length' => 'Message Maximum Length',
    'myshowcase_admin_summary_create_update_entries_message_maximum_length_description' => 'Maximum number of characters allowed for the message.',
    'myshowcase_admin_summary_create_update_entries_message_build_editor' => 'Use Rich Text Editor',
    'myshowcase_admin_summary_create_update_entries_message_build_editor_description' => 'Enable a rich text editor for message content.',
    'myshowcase_admin_summary_create_update_entries_display_avatars' => 'Display Avatars',
    'myshowcase_admin_summary_create_update_entries_display_avatars_description' => 'Show user avatars in entries.',
    'myshowcase_admin_summary_create_update_entries_display_profile_fields' => 'Display Profile Fields',
    'myshowcase_admin_summary_create_update_entries_display_profile_fields_description' => 'Show custom profile fields in entries.',
    'myshowcase_admin_summary_create_update_entries_display_stars' => 'Display Stars',
    'myshowcase_admin_summary_create_update_entries_display_stars_description' => 'Show user reputation stars in entries.',
    'myshowcase_admin_summary_create_update_entries_display_group_image' => 'Display Group Image',
    'myshowcase_admin_summary_create_update_entries_display_group_image_description' => 'Show user group images in entries.',
    'myshowcase_admin_summary_create_update_entries_display_details' => 'Display Details',
    'myshowcase_admin_summary_create_update_entries_display_details_description' => 'Show additional user details.',
    'myshowcase_admin_summary_create_update_entries_display_signatures' => 'Display Signatures',
    'myshowcase_admin_summary_create_update_entries_display_signatures_description' => 'Show user signatures in entries.',
    'myshowcase_admin_summary_create_update_entries_display_empty_fields' => 'Display Empty Fields',
    'myshowcase_admin_summary_create_update_entries_display_empty_fields_description' => 'Show fields even if they are empty.',
    'myshowcase_admin_summary_create_update_entries_display_users_browsing' => 'Display Users Browsing Entry',
    'myshowcase_admin_summary_create_update_entries_display_users_browsing_description' => 'Show users currently browsing this entry.',
    'myshowcase_admin_summary_create_update_entries_display_reputations' => 'Display Reputations',
    'myshowcase_admin_summary_create_update_entries_display_reputations_description' => 'Display users reputation points in entries.',
    'myshowcase_admin_summary_create_update_entries_enable_update_notes' => 'Enable Update Notes',
    'myshowcase_admin_summary_create_update_entries_enable_update_notes_description' => 'Allow users to provide an update reason when editing entries.',

    'myshowcase_admin_summary_create_update_comments_enable' => 'Enable Comments',
    'myshowcase_admin_summary_create_update_comments_enable_description' => 'Allow comments on entries.',
    'myshowcase_admin_summary_create_update_comments_enable_quick_reply' => 'Display Quick Reply',
    'myshowcase_admin_summary_create_update_comments_enable_quick_reply_description' => 'Show a quick reply box for entries.',
    'myshowcase_admin_summary_create_update_comments_allow_post_icons' => 'Allow Post Icons in Comments',
    'myshowcase_admin_summary_create_update_comments_allow_post_icons_description' => 'Allow users to add icons to their comments.',
    'myshowcase_admin_summary_create_update_comments_message_minimum_length' => 'Comment Minimum Length',
    'myshowcase_admin_summary_create_update_comments_message_minimum_length_description' => 'Minimum number of characters required for comments.',
    'myshowcase_admin_summary_create_update_comments_message_maximum_length' => 'Comment Maximum Length',
    'myshowcase_admin_summary_create_update_comments_message_maximum_length_description' => 'Maximum number of characters allowed for comments.',
    'myshowcase_admin_summary_create_update_comments_message_build_editor' => 'Use Rich Text Editor for Comments',
    'myshowcase_admin_summary_create_update_comments_message_build_editor_description' => 'Enable a rich text editor for comment content.',
    'myshowcase_admin_summary_create_update_comments_display_avatars' => 'Display Avatars in Comments',
    'myshowcase_admin_summary_create_update_comments_display_avatars_description' => 'Show user avatars in comments.',
    'myshowcase_admin_summary_create_update_comments_display_profile_fields' => 'Display Profile Fields in Comments',
    'myshowcase_admin_summary_create_update_comments_display_profile_fields_description' => 'Show custom profile fields in comments.',
    'myshowcase_admin_summary_create_update_comments_display_stars' => 'Display Stars in Comments',
    'myshowcase_admin_summary_create_update_comments_display_stars_description' => 'Show user reputation stars in comments.',
    'myshowcase_admin_summary_create_update_comments_display_group_image' => 'Display Group Image in Comments',
    'myshowcase_admin_summary_create_update_comments_display_group_image_description' => 'Show user group images in comments.',
    'myshowcase_admin_summary_create_update_comments_display_details' => 'Display Comment Details',
    'myshowcase_admin_summary_create_update_comments_display_details_description' => 'Show additional user details.',
    'myshowcase_admin_summary_create_update_comments_display_signatures' => 'Display Signatures in Comments',
    'myshowcase_admin_summary_create_update_comments_display_signatures_description' => 'Show user signatures in comments.',
    'myshowcase_admin_summary_create_update_comments_display_reputations' => 'Display Reputations',
    'myshowcase_admin_summary_create_update_comments_display_reputations_description' => 'Display users reputation points in comments.',
    'myshowcase_admin_summary_create_update_comments_enable_update_notes' => 'Enable Update Notes',
    'myshowcase_admin_summary_create_update_comments_enable_edit_reasons_description' => 'Allow users to provide an update reason when editing comments.',
    'myshowcase_admin_summary_create_update_comments_per_page' => 'Comments Per Page',
    'myshowcase_admin_summary_create_update_comments_per_page_description' => 'Number of comments to display per page.',
    'myshowcase_admin_summary_create_update_comments_sort_direction' => 'Ascending Sort Direction',
    'myshowcase_admin_summary_create_update_comments_sort_direction_description' => 'Set the default sort direction to ascending for comments in entries.',
    'myshowcase_admin_summary_create_update_comments_enable_quotes' => 'Enable Quotes in Comments',
    'myshowcase_admin_summary_create_update_comments_enable_quotes_description' => 'Allow users to quote other comments.',
    'myshowcase_admin_summary_create_update_comments_maximum_nested_quotes' => 'Maximum Nested Quotes',
    'myshowcase_admin_summary_create_update_comments_maximum_nested_quotes_description' => 'Maximum number of nested quotes allowed in comments.',

    'myshowcase_admin_summary_create_update_attachments_allow_in_entries' => 'Allow Attachments in Entries',
    'myshowcase_admin_summary_create_update_attachments_allow_in_entries_description' => 'Allow users to attach files to their entries.',
    'myshowcase_admin_summary_create_update_attachments_allow_in_comments' => 'Allow Attachments in Comments',
    'myshowcase_admin_summary_create_update_attachments_allow_in_comments_description' => 'Allow users to attach files to their comments.',
    'myshowcase_admin_summary_create_update_attachments_uploads_path' => 'Uploads Path',
    'myshowcase_admin_summary_create_update_attachments_uploads_path_description' => 'Directory where uploaded files are stored.',
    'myshowcase_admin_summary_create_update_attachments_main_render' => 'Attachments in Main Page',
    'myshowcase_admin_summary_create_update_attachments_main_render_description' => 'Display attachments in the main content area.',
    'myshowcase_admin_summary_create_update_attachments_main_render_none' => '(none)',
    'myshowcase_admin_summary_create_update_attachments_main_render_all' => 'All Attachments',
    'myshowcase_admin_summary_create_update_attachments_main_render_images' => 'Images Only',
    'myshowcase_admin_summary_create_update_attachments_main_render_first_image' => 'First Image Only',

    'myshowcase_admin_summary_create_update_attachments_main_render_as_lightbox' => 'Render Images as Lightbox',
    'myshowcase_admin_summary_create_update_attachments_main_render_as_lightbox_description' => 'Display image attachments using a lightbox effect.',
    'myshowcase_admin_summary_create_update_attachments_enable_embeds' => 'Enable Embeds',
    'myshowcase_admin_summary_create_update_attachments_enable_embeds_description' => 'Allow attachments to be embedded in content.',
    'myshowcase_admin_summary_create_update_attachments_watermark_file' => 'Watermark File',
    'myshowcase_admin_summary_create_update_attachments_watermark_file_description' => 'Image file to use as a watermark for attachments.',
    'myshowcase_admin_summary_create_update_attachments_watermark_location' => 'Watermark Location',
    'myshowcase_admin_summary_create_update_attachments_watermark_location_description' => 'Position of the watermark on attachments (e.g., "bottom-right").',
    'myshowcase_admin_summary_create_update_attachments_watermark_location_lower_left' => 'Lower Left',
    'myshowcase_admin_summary_create_update_attachments_watermark_location_lower_right' => 'Lower Right',
    'myshowcase_admin_summary_create_update_attachments_watermark_location_center' => 'Center',
    'myshowcase_admin_summary_create_update_attachments_watermark_location_upper_left' => 'Upper Left',
    'myshowcase_admin_summary_create_update_attachments_watermark_location_upper_right' => 'Upper Right',

    'myshowcase_admin_summary_create_update_attachments_thumbnails_width' => 'Thumbnail Width',
    'myshowcase_admin_summary_create_update_attachments_thumbnails_width_description' => 'Width of generated thumbnails in pixels.',
    'myshowcase_admin_summary_create_update_attachments_thumbnails_height' => 'Thumbnail Height',
    'myshowcase_admin_summary_create_update_attachments_thumbnails_height_description' => 'Height of generated thumbnails in pixels.',
    'myshowcase_admin_summary_create_update_attachments_display_type' => 'Display Type',
    'myshowcase_admin_summary_create_update_attachments_display_type_description' => 'How attachments are displayed.',
    'myshowcase_admin_summary_create_update_attachments_display_type_links' => 'Normal Links',
    'myshowcase_admin_summary_create_update_attachments_display_type_thumbnails' => 'Thumbnails for Images',
    'myshowcase_admin_summary_create_update_attachments_display_type_full_images' => 'Full Images',

    'myshowcase_admin_summary_create_update_subscriptions_enable' => 'Enable Subscriptions',
    'myshowcase_admin_summary_create_update_subscriptions_enable_description' => 'Allow users to subscribe to updates for this showcase.',
    'myshowcase_admin_summary_create_update_subscriptions_message_length' => 'Subscription Message Length',
    'myshowcase_admin_summary_create_update_subscriptions_message_length_description' => 'Maximum length of subscription notification messages.',

    ///
    ///
    ///
    //
    //
    ///
    ///
    ///
    'myShowcaseAdminSummaryPermissionsFieldCanView' => 'Can View',
    'myShowcaseAdminSummaryPermissionsFieldCanViewEntries' => 'Can View Entries',
    'myShowcaseAdminSummaryPermissionsFieldCanCreateEntries' => 'Can Create Entries',
    'myShowcaseAdminSummaryPermissionsFieldCanUpdateEntries' => 'Can Update Entries',
    'myShowcaseAdminSummaryPermissionsFieldCanDeleteEntries' => 'Can Delete Entries',
    'myShowcaseAdminSummaryPermissionsFieldCanViewComments' => 'Can View Comments',
    'myShowcaseAdminSummaryPermissionsFieldCanCreateComments' => 'Can Post Comments',
    'myShowcaseAdminSummaryPermissionsFieldCanUpdateComments' => 'Can Update Comments',
    'myShowcaseAdminSummaryPermissionsFieldCanDeleteComments' => 'Can Delete Comments',
    'myShowcaseAdminSummaryPermissionsFieldCanViewAttachments' => 'Can View Attachments',
    'myShowcaseAdminSummaryPermissionsFieldCanUploadAttachments' => 'Can Upload Attachments',
    'myShowcaseAdminSummaryPermissionsFieldCanUpdateAttachments' => 'Can Update Attachments',
    'myShowcaseAdminSummaryPermissionsFieldCanDeleteAttachments' => 'Can Delete Attachments',
    'myShowcaseAdminSummaryPermissionsFieldCanDownloadAttachments' => 'Can Download Attachments',
    'myShowcaseAdminSummaryPermissionsFieldAttachmentsUploadQuote' => 'Attachment Upload Quota',
    'myShowcaseAdminSummaryPermissionsFieldAttachmentsUploadQuoteDescription' => 'Here you can set the attachment quota that each user in this group will receive. If set to 0, there is no limit.',
    'myShowcaseAdminSummaryPermissionsFieldCanWatermarkAttachments' => 'Can Watermark Attachments',
    'myShowcaseAdminSummaryPermissionsFieldAttachmentsFilesLimit' => 'Attachments Files Limit',
    'myShowcaseAdminSummaryPermissionsFieldAttachmentsFilesLimitDescription' => 'Here you can set the attachment files limit that each user in this group will receive. If set to 0, there is no limit.',
    'myShowcaseAdminSummaryPermissionsFieldCanViewSoftDeletedNotice' => 'Can View Soft Deleted Notice',
    'myShowcaseAdminSummaryPermissionsFieldModerateEntryCreate' => 'Moderate Entry Create',
    'myShowcaseAdminSummaryPermissionsFieldModerateEntryUpdate' => 'Moderate Entry Update',
    'myShowcaseAdminSummaryPermissionsFieldModerateCommentsCreate' => 'Moderate Comments Create',
    'myShowcaseAdminSummaryPermissionsFieldModerateCommentsUpdate' => 'Moderate Comments Update',
    'myShowcaseAdminSummaryPermissionsFieldModerateAttachmentsUpload' => 'Moderate Attachments Upload',
    'myShowcaseAdminSummaryPermissionsFieldModerateAttachmentsUpdate' => 'Moderate Attachments Upload',
    'myShowcaseAdminSummaryPermissionsFieldCanSearch' => 'Can Search',

    'myShowcaseAdminSummaryPermissionsFormGroup' => 'Group',
    'myShowcaseAdminSummaryPermissionsFormPermissions' => 'Custom Permissions',
    'myShowcaseAdminSummaryPermissionsFormDisallowedActions' => 'Disallowed Actions',
    'myShowcaseAdminSummaryPermissionsFormAllowedActions' => 'Allowed Actions',
    'myShowcaseAdminSummaryPermissionsFormAInheritActions' => 'Inherit Actions',
    'myShowcaseAdminSummaryPermissionsFormNeverActions' => 'Never Actions',
    'myShowcaseAdminSummaryPermissionsFormInherited' => 'inherited',
    'myShowcaseAdminSummaryPermissionsFormCustom' => 'custom',
    'myShowcaseAdminSummaryPermissionsFormEdit' => 'Edit Custom Permissions',
    'myShowcaseAdminSummaryPermissionsFormClear' => 'Clear Custom Permissions',
    'myShowcaseAdminSummaryPermissionsFormSet' => 'Set Custom Permissions',
    'myShowcaseAdminSummaryPermissionsFormSave' => 'Save Custom Permissions',

    'myShowcaseAdminSummaryPermissionsFormCustomPermissions' => 'Custom Permissions',
    'myShowcaseAdminSummaryPermissionsFormCustomPermissionsDescription' => 'Here you can modify the full custom permissions for an individual group for a single showcase.',

    'myShowcaseAdminSummaryPermissionsFormCustomPermissionsSuccess' => 'The showcase custom permissions have been saved successfully.',

    'myShowcaseAdminSummaryPermissionsFormConfirmClear' => 'Are you sure you wish to clear this custom permission?',

    'myShowcaseAdminSummaryPermissionsFormButtonSubmit' => 'Save Custom Permissions',

    'myShowcaseAdminSummaryPermissionsClearConfirm' => 'Are you sure you wish to clear this custom permission?',
    'myShowcaseAdminSummaryPermissionsClearSuccess' => 'The custom permissions for this showcase have been cleared successfully.',

], $l);
