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

//basic headers for index table
$l['myshowcase_main_table_head_view'] = 'View';

//error messages

//moderation words
$l['myshowcase_save'] = 'Save';
$l['myshowcase_unapproved_count'] = 'The {1} Showcase has {2} unapproved entries.';

//query results
$l['myshowcase_invalid_id'] = 'The specified Entry ID is invalid.';
$l['myshowcase_entry_hidden_no_permission'] = 'You do not have permission to view or edit this entry while it is awaiting approval or has been deleted.';

//view options
$l['myshowcase_viewing_user'] = 'Viewing entry for &quot;{1}&quot;';

//new/edit myshowcase
$l['myshowcase_button_entry_update'] = 'Update Entry';
$l['myshowcase_button_comment_create'] = 'Post Comment';
$l['myshowcase_button_comment_update'] = 'Update Comment';

//attachments
$l['myshowcase_attachments'] = 'Attachments';

//comment language
$l['myshowcase_comment_text_limit'] = 'Message should be between {1} and {2} characters long.';

//who's onine and portal

$l['viewing_myshowcase_list'] = "Viewing <a href=\"../{1}\">{2} List</a>";
$l['viewing_myshowcase'] = "Viewing <a href=\"../{1}\">{2} entry</a> of <a href=\"{3}\">{4}</a>";
$l['viewing_myshowcase_new'] = "Creating <a href=\"../{1}\">New {2}</a>";
$l['viewing_myshowcase_edit'] = 'Editing a {1} entry';
$l['viewing_myshowcase_attach'] = 'Viewing Attachment';

$l = array_merge($l, [
    // Access page
    'myshowcase_password_required' => 'Password Required',
    'myshowcase_password_wrong_password' => 'The password you entered is incorrect. Please try again.',
    'myshowcase_password_enter_password_below' => 'Enter the showcase password',
    'myshowcase_password_note' => "You must enter this showcase's password to gain access",
    'myshowcase_password_verify_password' => 'Verify Showcase Password',
    'myshowcase_password_gate_denied' => 'Access denied. Enter the showcase password on the showcase page first.',

    'myshowcase_redirect_password_cleared' => 'The stored password for this showcase has been cleared.<br />You will now be returned to your previous location.',

    'myshowcase_main_moderated_by' => 'Moderated by',

    'myshowcase_main_users_browsing' => 'Users browsing this showcase',
    'myshowcase_main_users_browsing_guests' => '{1} Guest(s)',

    'myshowcase_main_search' => 'Search Entries',
    'myshowcase_main_search_keywords' => 'Enter Keywords...',

    'myshowcase_main_inline_moderation' => 'Inline Moderation',
    'myshowcase_main_inline_moderation_go' => 'Go',
    'myshowcase_main_inline_moderation_select_a_tool' => 'Select A Tool',
    'myshowcase_main_inline_moderation_standard_tool' => 'Standard Tools',
    'myshowcase_main_inline_moderation_open' => 'Open Entries',
    'myshowcase_main_inline_moderation_close' => 'Close Entries',
    'myshowcase_main_inline_moderation_stick' => 'Stick Entries',
    'myshowcase_main_inline_moderation_unstick' => 'Unstick Entries',
    'myshowcase_main_inline_moderation_copy' => 'Copy Entries',
    'myshowcase_main_inline_moderation_approve' => 'Approve Entries',
    'myshowcase_main_inline_moderation_unapprove' => 'Unapprove Entries',
    'myshowcase_main_inline_moderation_soft_delete' => 'Soft Delete Entries',
    'myshowcase_main_inline_moderation_restore' => 'Restore Entries',
    'myshowcase_main_inline_moderation_delete' => 'Delete Entries Permanently',
    'myshowcase_main_inline_moderation_clear' => 'Clear',
    'myshowcase_main_inline_moderation_no_tool_selected' => 'Please select a tool to perform moderation action.',
    'myshowcase_main_inline_moderation_no_entries_selected' => 'You need to select one or more entries to perform the moderation action on.',

    'myshowcase_main_button_entry_create' => 'Post Entry',
    'myshowcase_main_button_sort_entries' => 'Sort Entries',
    'myshowcase_main_button_search' => 'Search Entries',

    'myshowcase_main_button_read' => 'Mark Read',
    'myshowcase_main_button_subscribe' => 'Subscribe',
    'myshowcase_main_button_unsubscribe' => 'Unsubscribe',
    'myshowcase_main_button_clear_stored_password' => 'Clear Password',

    'myshowcase_main_table_head_entry' => 'Entry',
    'myshowcase_main_table_head_author' => 'Author',
    'myshowcase_main_table_head_comments' => 'Comments',
    'myshowcase_main_table_head_last_comment' => 'Last Comment',

    'myshowcase_main_table_separator_sticky' => 'Important Entries',
    'myshowcase_main_table_separator_normal' => 'Normal Entries',
    'myshowcase_main_table_separator_selected_page_start' => 'All',
    'myshowcase_main_table_separator_selected_page_end' => 'entries on this page are selected.',
    'myshowcase_main_table_separator_selected_all_start' => 'All',
    'myshowcase_main_table_separator_selected_all_end' => 'entries in this forum are selected.',
    'myshowcase_main_table_separator_selected_all_select_start' => 'Select all',
    'myshowcase_main_table_separator_selected_all_select_end' => 'entries in this forum.',
    'myshowcase_main_table_separator_selected_all_clear' => 'Clear All',

    'myshowcase_main_table_row_comment' => 'Comment',
    'myshowcase_main_table_row_comments' => 'Comments',
    'myshowcase_main_table_row_view' => 'View',
    'myshowcase_main_table_row_views' => 'Views',
    'myshowcase_main_table_row_closed_entry' => 'Cosed Entry',
    'myshowcase_main_table_row_sticky_entry' => 'Important Entry',
    'myshowcase_main_table_row_deleted_entry' => 'Deleted Entry',
    'myshowcase_main_table_row_awaiting_approval' => 'Awaiting Approval',
    'myshowcase_main_table_row_go_to_first_unread' => 'Go to first unread comment',
    'myshowcase_main_table_row_contains_comments_by_you' => 'Contains comments by you.',
    'myshowcase_main_table_row_by' => 'by',
    'myshowcase_main_table_row_last_comment_by' => 'Last comment by',

    'myshowcase_main_table_row_attachment_file_name' => 'Filename',
    'myshowcase_main_table_row_attachment_size' => 'Size',
    'myshowcase_main_table_row_attachment_downloads' => 'Downloads',
    'myshowcase_main_table_row_attachments_count_single' => 'This entry contains 1 attachment.',
    'myshowcase_main_table_row_attachments_count_plural' => 'This entry contains {1} attachments.',

    'myshowcase_main_table_empty' => 'Sorry, but there are currently no entries in this showcase with the specified date and time limiting options.',
    'myshowcase_main_table_empty_search' => 'The search returned no results. Please try another search term.',

    'myshowcase_main_sort_entries' => 'Sort and Filter Options',
    'myshowcase_main_sort_field' => 'Sort By',
    'myshowcase_main_sort_field_dateline' => 'Dateline',
    'myshowcase_main_sort_field_username' => 'Username',
    'myshowcase_main_sort_field_views' => 'Views',
    'myshowcase_main_sort_field_comments' => 'Comments',
    'myshowcase_main_sort_direction' => 'Direction',
    'myshowcase_main_sort_direction_asc' => 'Ascending',
    'myshowcase_main_sort_direction_desc' => 'Descending',

    'myshowcase_main_sort_days_1' => 'From: Today',
    'myshowcase_main_sort_days_5' => 'From: 5 Days Ago',
    'myshowcase_main_sort_days_25' => 'From: 25 Days Ago',
    'myshowcase_main_sort_days_100' => 'From: 100 Days Ago',

    'myshowcase_main_inline_moderation_none_allowed' => 'You may not use this tool on any of the selected entries.',
    'myshowcase_main_inline_moderation_skipped' => '%1$d selected entries were skipped because you may not do this to them.',
    'myshowcase_main_inline_moderation_redirect_opened' => 'The selected entries have been opened.<br />You will now be returned to your previous location.',
    'myshowcase_main_inline_moderation_redirect_closed' => 'The selected entries have been closed.<br />You will now be returned to your previous location.',
    'myshowcase_main_inline_moderation_redirect_stuck' => 'The selected entries have been stuck.<br />You will now be returned to your previous location.',
    'myshowcase_main_inline_moderation_redirect_unstuck' => 'The selected entries have been unstuck.<br />You will now be returned to your previous location.',
    'myshowcase_main_inline_moderation_redirect_approved' => 'The selected entries have been approved.<br />You will now be returned to your previous location.',
    'myshowcase_main_inline_moderation_redirect_unapproved' => 'The selected entries have been unapproved.<br />You will now be returned to your previous location.',
    'myshowcase_main_inline_moderation_redirect_soft_deleted' => 'The selected entries have been soft-deleted.<br />You will now be returned to your previous location.',
    'myshowcase_main_inline_moderation_redirect_restored' => 'The selected entries have been restored.<br />You will now be returned to your previous location.',
    'myshowcase_main_inline_moderation_redirect_deleted' => 'The selected entries have been deleted.<br />You will now be returned to your previous location.',

    'myshowcase_create_update_entry_title_create' => 'New Entry in {1}',
    'myshowcase_create_update_entry_your_message' => 'Your Message',
    'myshowcase_create_update_entry_post_options' => 'Post Options',

    'myshowcase_create_update_entry_post_options_signature' => 'Signature:',
    'myshowcase_create_update_entry_post_options_signature_description' => 'include your signature. (registered users only)',
    'myshowcase_create_update_entry_post_options_disable_smilies' => 'Disable Smilies:',
    'myshowcase_create_update_entry_post_options_disable_smilies_description' => 'disable smilies from showing in this post.',

    'myshowcase_view_status_awaiting' => 'Awaiting Approval',
    'myshowcase_view_status_visible' => 'Visible',
    'myshowcase_view_status_deleted' => 'Deleted',

    'myshowcase_view_entry_button_subscribe' => 'Subscribe',
    'myshowcase_view_entry_button_unsubscribe' => 'Unsubscribe',
    'myshowcase_view_entry_button_create_comment' => 'Post Comment',
    'myshowcase_view_entry_button_entry_closed' => 'Closed',

    'myshowcase_view_entry_mode_threaded' => 'Threaded',
    'myshowcase_view_entry_mode_linear' => 'Linear',

    'myshowcase_view_entry_next_oldest' => 'Next Oldest',
    'myshowcase_view_entry_next_newest' => 'Next Newest',

    'myshowcase_view_entry_comments_none' => 'There are no comments at this time.',
    'myshowcase_view_entry_comments_not_logged_in' => 'You must be logged in to add a comment.',

    'myshowcase_view_entry_comment_form_title' => 'Quick Comment',
    'myshowcase_view_entry_comment_form_my_profile' => 'Go to My Profile',
    'myshowcase_view_entry_comment_form_message' => 'Compose your message...',
    'myshowcase_view_entry_comment_form_allow_signature' => 'Signature',
    'myshowcase_view_entry_comment_form_disable_smilies' => 'Disable Smilies',
    'myshowcase_view_entry_comment_form_close_entry' => 'Close Entry',
    'myshowcase_view_entry_comment_form_sticky_entry' => 'Stick Entry',
    'myshowcase_view_entry_comment_form_preview' => 'Preview Comment',

    'myshowcase_view_entry_search' => 'Search Entry',
    'myshowcase_view_entry_search_keywords' => 'Enter Keywords',

    'myshowcase_view_entry_users_browsing_entry' => 'Users browsing this entry',
    'myshowcase_view_entry_users_browsing_entry_reading' => 'Reading&hellip;',

    'myshowcase_view_inline_moderation' => 'Moderation Options',
    'myshowcase_view_inline_moderation_go' => 'Go',
    'myshowcase_view_inline_moderation_standard_tool' => 'Standard Tools',
    'myshowcase_view_inline_moderation_open' => 'Open Entry',
    'myshowcase_view_inline_moderation_close' => 'Close Entry',
    'myshowcase_view_inline_moderation_stick' => 'Stick Entry',
    'myshowcase_view_inline_moderation_unstick' => 'Unstick Entry',
    'myshowcase_view_inline_moderation_copy' => 'Copy Entry',
    'myshowcase_view_inline_moderation_approve' => 'Approve Entry',
    'myshowcase_view_inline_moderation_unapprove' => 'Unapprove Entry',
    'myshowcase_view_inline_moderation_soft_delete' => 'Soft Delete Entry',
    'myshowcase_view_inline_moderation_restore' => 'Restore Entry',
    'myshowcase_view_inline_moderation_delete' => 'Delete Entry Permanently',
    'myshowcase_view_inline_moderation_clear' => 'Clear',

    'myshowcase_view_inline_moderation_comments' => 'Inline Comment Moderation',
    'myshowcase_view_inline_moderation_comments_go' => 'Go',
    'myshowcase_view_inline_moderation_comments_standard_tool' => 'Standard Tools',
    'myshowcase_view_inline_moderation_comments_approve' => 'Approve Comments',
    'myshowcase_view_inline_moderation_comments_unapprove' => 'Unapprove Comments',
    'myshowcase_view_inline_moderation_comments_move' => 'Move Comments',
    'myshowcase_view_inline_moderation_comments_soft_delete' => 'Soft Delete Comments',
    'myshowcase_view_inline_moderation_comments_restore' => 'Restore Comments',
    'myshowcase_view_inline_moderation_comments_delete' => 'Delete Comments Permanently',
    'myshowcase_view_inline_moderation_comments_clear' => 'Clear',
    'myshowcase_view_inline_moderation_comments_no_tool_selected' => 'Please select a tool to perform moderation action.',
    'myshowcase_view_inline_moderation_comments_no_entry_selected' => 'You need to select one or more comments to perform the moderation action on.',

    'myshowcase_view_attachments' => 'Attached Files',
    'myshowcase_view_attachments_thumbnails' => 'Thumbnail(s)',
    'myshowcase_view_attachments_images' => 'Image(s)',
    'myshowcase_view_entry_button_deleted_show' => '{1} unapproved attachments.',
    'myshowcase_view_attachments_unapproved_single' => '1 unapproved attachment.',

    'myshowcase_create_update_attachment_error_missing_file' => 'You did not select a file to upload.',
    'myshowcase_create_update_attachment_error_size_too_low' => 'The file {1} is too small. The minimum file size allowed is {2}.',
    'myshowcase_create_update_attachment_error_size_too_large' => 'The file {1} is too large. The maximum file size allowed is {2}.',

    'myshowcase_redirect_subscribed_to_entry' => 'You have successfully subscribed to the entry.<br />You will now be returned to your previous location.',
    'myshowcase_redirect_unsubscribed_to_entry' => 'You have successfully unsubscribed from the entry.<br />You will now be returned to your previous location.',
    ///
    ///
    ///
    ///
    ///
    ///
    ///
    ///
    ///
    ///
    ///
    'myshowcase_view_entry_button_ignored_deleted_show' => 'Show this Entry',

    'myshowcase_view_entry_status_online' => 'Online',
    'myshowcase_view_entry_status_offline' => 'Offline',
    'myshowcase_view_entry_status_away' => 'Away',

    'myshowcase_view_entry_edited_note' => 'This entry was last modified: {1} by',

    'myshowcase_view_entry_currently_ignoring_user' => 'The contents of this message are hidden because {1} is on your <a href=\"{2}/usercp.php?action=editlists\">ignore list</a>.',
    'myshowcase_view_entry_under_moderation' => 'The entry made by you is under moderation and currently not visible publicly. The entry will be visible to everyone once a moderator approves it.',
    'myshowcase_view_entry_deleted_user' => 'This entry by {1} has been deleted',
    'myshowcase_view_entry_action_quick_restore' => 'Restore',
    'myshowcase_view_entry_action_quick_restore_title' => 'Restore this entry',
    'myshowcase_view_entry_action_quote' => 'Reply',
    'myshowcase_view_entry_action_quote_title' => 'Quote this message in a reply',
    'myshowcase_view_entry_action_multi_quote' => 'Quote',
    'myshowcase_view_entry_action_multi_quote_title' => 'Quote this entry',
    'myshowcase_view_entry_action_rate' => 'Rate',
    'myshowcase_view_entry_action_rate_title' => 'Give Reputation to this user',
    'myshowcase_view_entry_action_edit' => 'Edit',
    'myshowcase_view_entry_action_edit_title' => 'Quick Edit',
    'myshowcase_view_entry_options' => 'Entry Options',
    'myshowcase_view_entry_options_full_edit' => 'Full Edit',
    'myshowcase_view_entry_options_quick_delete' => 'Delete',
    'myshowcase_view_entry_options_quick_delete_title' => 'Delete this entry',
    'myshowcase_view_entry_options_quick_delete_permanently' => 'Delete Permanently',
    'myshowcase_view_entry_options_quick_delete_permanently_title' => 'Delete this entry permanently',
    'myshowcase_view_entry_options_report' => 'Report',
    'myshowcase_view_entry_options_report_title' => 'Report this entry to a moderator',
    'myshowcase_view_entry_options_warn' => 'Warn',
    'myshowcase_view_entry_options_warn_title' => 'Warn the author for this entry',
    'myshowcase_view_entry_options_purge_spammer' => 'Purge Spammer',
    'myshowcase_view_entry_options_purge_spammer_title' => 'Purge Spammer',

    'myshowcase_view_comment_button_ignored_deleted_show' => 'Show this Comment',

    'myshowcase_view_comment_status_online' => 'Online',
    'myshowcase_view_comment_status_offline' => 'Offline',
    'myshowcase_view_comment_status_away' => 'Away',

    'myshowcase_view_comment_edited_note' => 'This entry was last modified: {1} by',

    'myshowcase_view_comment_currently_ignoring_user' => 'The contents of this message are hidden because {1} is on your <a href=\"{2}/usercp.php?action=editlists\">ignore list</a>.',
    'myshowcase_view_comment_under_moderation' => 'The comment made by you is under moderation and currently not visible publicly. The comment will be visible to everyone once a moderator approves it.',
    'myshowcase_view_comment_deleted_user' => 'This comment by {1} has been deleted',
    'myshowcase_view_comment_action_quick_restore' => 'Restore',
    'myshowcase_view_comment_action_quick_restore_title' => 'Restore this comment',
    'myshowcase_view_comment_action_quote' => 'Reply',
    'myshowcase_view_comment_action_quote_title' => 'Quote this message in a reply',
    'myshowcase_view_comment_action_multi_quote' => 'Quote',
    'myshowcase_view_comment_action_multi_quote_title' => 'Quote this comment',
    'myshowcase_view_comment_action_rate' => 'Rate',
    'myshowcase_view_comment_action_rate_title' => 'Give Reputation to this user',
    'myshowcase_view_comment_options' => 'Comment Options',
    'myshowcase_view_comment_options_full_edit' => 'Full Edit',
    'myshowcase_view_comment_options_quick_delete' => 'Delete',
    'myshowcase_view_comment_options_quick_delete_title' => 'Delete this comment',
    'myshowcase_view_comment_options_report' => 'Report',
    'myshowcase_view_comment_options_report_title' => 'Report this comment to a moderator',
    'myshowcase_view_comment_options_warn' => 'Warn',
    'myshowcase_view_comment_options_warn_title' => 'Warn the author for this comment',
    'myshowcase_view_comment_options_purge_spammer' => 'Purge Spammer',
    'myshowcase_view_comment_options_purge_spammer_title' => 'Purge Spammer',
    ///
    ///
    ///
    ///
    ///
    ///
    ///
    ///
    ///
    ///
    ///
    'myshowcase_entry_create_update_options_moderator' => 'Moderator Options',
    'myshowcase_entry_create_update_options_moderator_close' => 'Close Thread:',
    'myshowcase_entry_create_update_options_moderator_close_description' => 'prevent further posting in this thread.',
    'myshowcase_entry_create_update_options_moderator_close_stick' => 'Stick Thread:',
    'myshowcase_entry_create_update_options_moderator_close_stick_description' => 'stick this thread to the top of the forum.',

    'myshowcase_new_edit_form_title' => 'New Entry',
    'myshowcase_new_edit_form_title_description' => 'Create a new entry in the showcase.',
    'myshowcase_new_edit_form_entry_subject' => 'Post Subject',
    'myshowcase_new_edit_form_entry_subject_placeholder' => 'Entry subject...',

    'myshowcase_new_edit_form_entry_multi_quote_singular' => 'You have selected one post from another thread.',
    'myshowcase_new_edit_form_entry_multi_quote_plural' => 'You have selected {1} posts from other threads.',
    'myshowcase_new_edit_form_entry_multi_quote_deselect_singular' => 'deselect this post',
    'myshowcase_new_edit_form_entry_multi_quote_deselect_plural' => 'deselect these posts',
    'myshowcase_new_edit_form_entry_multi_quote_plus_singular' => 'Quote this post too',
    'myshowcase_new_edit_form_entry_multi_quote_plus_plural' => 'Quote these posts too',

    'myshowcase_new_edit_form_entry_prefixes_no_prefix' => 'No Prefix',

    'myshowcase_new_edit_form_field' => 'Field',
    'myshowcase_new_edit_form_value' => 'Value',

    'myshowcase_new_edit_form_button_create_entry' => 'Create Entry',
    'myshowcase_new_edit_form_button_update_entry' => 'Update Entry',
    'myshowcase_new_edit_form_button_preview_entry' => 'Preview Entry',

    'myshowcase_new_edit_entry_preview' => 'Entry Preview',

    'myshowcase_comment_create_update_form_button_update' => 'Update Comment',

    'myshowcase_comment_create_update_preview' => 'Comment Preview',

    'myshowcase_entry_field_value_empty' => '(no value)',
    'myshowcase_entry_field_value_check_box_yes' => 'Yes',
    'myshowcase_entry_field_value_check_box_no' => 'No',

    'myshowcase_entry_search_entry' => 'Search Entry',
    'myshowcase_entry_enter_keywords' => 'Enter Keywords...',

    'myshowcase_entry_next_oldest' => 'Next Oldest',
    'myshowcase_entry_next_newest' => 'Next Newest',

    'myshowcase_entry_edited_by' => 'This entry was last modified: {1} by',
    'myshowcase_entry_deleted_message' => 'This entry by {1} has been deleted.',

    'myshowcase_entry_attachments_files' => 'Attached Files',
    'myshowcase_entry_attachments_images' => 'Image(s)',
    'myshowcase_entry_attachments_thumbnail' => 'Thumbnail(s)',

    'myshowcase_entry_button_edit' => 'Edit',
    'myshowcase_entry_button_edit_description' => 'Edit this entry',
    'myshowcase_entry_button_warn' => 'Warn',
    'myshowcase_entry_button_warn_description' => 'Warn the author for this entry',
    'myshowcase_entry_button_purse_spammer' => 'Purge Spammer',
    'myshowcase_entry_button_purse_spammer_description' => 'Purge spammer',
    'myshowcase_entry_button_report' => 'Report',
    'myshowcase_entry_button_report_description' => 'Report comment',
    'myshowcase_entry_button_approve' => 'Approve',
    'myshowcase_entry_button_approve_description' => 'Approve entry',
    'myshowcase_entry_button_approve_confirm' => 'Are you sure you want to approve this entry?',
    'myshowcase_entry_button_unapprove' => 'Unapprove',
    'myshowcase_entry_button_unapprove_description' => 'Unapprove entry',
    'myshowcase_entry_button_unapprove_confirm' => 'Are you sure you want to unapprove this entry?',
    'myshowcase_entry_button_soft_delete' => 'Soft Delete',
    'myshowcase_entry_button_soft_delete_description' => 'Soft delete entry',
    'myshowcase_entry_button_soft_delete_confirm' => 'Are you sure you want to soft delete this entry?',
    'myshowcase_entry_button_restore' => 'Restore',
    'myshowcase_entry_button_restore_description' => 'Restore entry',
    'myshowcase_entry_button_restore_confirm' => 'Are you sure you want to restore this entry?',
    'myshowcase_entry_button_delete' => 'Delete',
    'myshowcase_entry_button_delete_description' => 'Delete entry',
    'myshowcase_entry_button_delete_confirm' => 'Are you sure you want to delete this entry?',

    'myshowcase_comment_details_comments' => 'Comments',
    'myshowcase_comment_details_entries' => 'Entries',
    'myshowcase_comment_details_joined' => 'Joined',
    'myshowcase_comment_details_reputation' => 'Reputation:',
    'myshowcase_comment_details_warning_level' => 'Warning Level:',
    'myshowcase_comment_button_email' => 'Email',
    'myshowcase_comment_button_email_description' => 'Send this user an email',
    'myshowcase_comment_button_private_message' => 'PM',
    'myshowcase_comment_button_private_message_description' => 'Send this user a private message',
    'myshowcase_comment_button_website' => 'Website',
    'myshowcase_comment_button_website_description' => "Visit this user's website",

    'myshowcase_comment_edited_by' => 'This comment was last modified: {1} by',
    'myshowcase_comment_deleted_message' => 'This entry by {1} has been deleted.',
    'myshowcase_comment_deleted_s_button_show' => 'Show this Comment',

    'myshowcase_comment_button_edit' => 'Edit',
    'myshowcase_comment_button_edit_description' => 'Edit this comment',
    'myshowcase_comment_button_warn' => 'Warn',
    'myshowcase_comment_button_warn_description' => 'Warn the author for this comment',
    'myshowcase_comment_button_purse_spammer' => 'Purge Spammer',
    'myshowcase_comment_button_purse_spammer_description' => 'Purge spammer',
    'myshowcase_comment_button_report' => 'Report',
    'myshowcase_comment_button_report_description' => 'Report comment',
    'myshowcase_comment_button_approve' => 'Approve',
    'myshowcase_comment_button_approve_description' => 'Approve comment',
    'myshowcase_comment_button_approve_confirm' => 'Are you sure you want to approve this comment?',
    'myshowcase_comment_button_unapprove' => 'Unapprove',
    'myshowcase_comment_button_unapprove_description' => 'Unapprove comment',
    'myshowcase_comment_button_unapprove_confirm' => 'Are you sure you want to unapprove this comment?',
    'myshowcase_comment_button_soft_delete' => 'Soft Delete',
    'myshowcase_comment_button_soft_delete_description' => 'Soft delete comment',
    'myshowcase_comment_button_soft_delete_confirm' => 'Are you sure you want to soft delete this comment?',
    'myshowcase_comment_button_delete' => 'Delete',
    'myshowcase_comment_button_delete_description' => 'Delete comment',
    'myshowcase_comment_button_delete_confirm' => 'Are you sure you want to delete this comment?',
    'myshowcase_comment_button_restore' => 'Restore',
    'myshowcase_comment_button_restore_description' => 'Restore comment',
    'myshowcase_comment_button_restore_confirm' => 'Are you sure you want to restore this comment?',

    'myshowcase_comment_create_message' => 'Message:',
    'myshowcase_comment_create_message_option_signature' => 'Signature',
    'myshowcase_comment_create_message_option_disable_smiles' => 'Disable Smiles',
    'myshowcase_comment_create_message_placeholder' => 'Compose your message...',
    'myshowcase_comment_create_button' => 'Post Comment',

    'myshowcase_comment_created' => 'Your comment has been created successfully.<br />You will now be returned back to where you came from.',
    'myshowcase_comment_created_status' => 'Your comment has been created successfully.<br />You will now be returned back to where you came from.',
    'myshowcase_comment_updated' => 'Your comment has been updated successfully.<br />You will now be returned back to where you came from.',
    'myshowcase_comment_updated_status' => 'Your comment has been updated successfully.<br />You will now be returned back to where you came from.',

    'myshowcase_entry_entry_created' => 'Your entry has been created successfully.<br />You will now be redirected to the entry.',
    'myshowcase_entry_limit_reached' => 'You have reached the maximum of {1} entries you can have in this showcase. Delete one of your entries to create a new one.',
    'myshowcase_import_export_error_library_missing' => 'Import and export need the PluginLibrary plugin, which is not installed on this board. Please ask an administrator to upload inc/plugins/pluginlibrary.php.',
    'myshowcase_import_export_error_library_version' => 'Import and export need a newer PluginLibrary than the one installed on this board (version {1} or later). Please ask an administrator to update it.',
    'myshowcase_import_export_button_export' => 'Export entries',
    'myshowcase_import_export_title_export' => 'Export entries',
    'myshowcase_import_export_title_export_view' => 'Export #{1}',
    'myshowcase_import_export_sort_dateline' => 'Date posted',
    'myshowcase_import_export_sort_update_dateline' => 'Last updated',
    'myshowcase_import_export_sort_views' => 'Views',
    'myshowcase_import_export_sort_subject' => 'Subject',
    'myshowcase_import_export_sort_ascending' => 'ascending',
    'myshowcase_import_export_sort_descending' => 'descending',
    'myshowcase_import_export_field_subject' => 'Subject',
    'myshowcase_import_export_field_message' => 'Message',
    'myshowcase_import_export_form_limit' => 'Number of entries',
    'myshowcase_import_export_form_limit_description' => 'How many entries to export, at most. 0 exports as many as allowed (up to {1}).',
    'myshowcase_import_export_form_sort' => 'Sort order',
    'myshowcase_import_export_form_fields' => 'Content to export',
    'myshowcase_import_export_form_fields_description' => 'Only the fields you can both see and fill in are offered, so that what you export you could also import. Attachments are not exported.',
    'myshowcase_import_export_form_options' => 'Entries to include',
    'myshowcase_import_export_form_include_awaiting' => 'Entries awaiting approval',
    'myshowcase_import_export_form_include_deleted' => 'Soft-deleted entries',
    'myshowcase_import_export_form_size_note' => 'An export cannot be larger than {1} (a limit of this server, so that every export can be imported again).',
    'myshowcase_import_export_form_submit' => 'Export',
    'myshowcase_import_export_storage_note' => 'Exported files are kept in a folder that is protected from direct access on Apache. On other web servers an administrator has to block that folder in the server configuration.',
    'myshowcase_import_export_error_exports_directory' => 'The folder for stored exports in the board settings is not a folder inside the forum folder, so the default folder is used. Please ask an administrator to correct it.',
    'myshowcase_import_export_list_title' => 'Your stored exports',
    'myshowcase_import_export_list_count' => '{1} of {2} stored exports used. Exports are deleted automatically after 30 days.',
    'myshowcase_import_export_none_stored' => 'You have no stored exports.',
    'myshowcase_import_export_col_created' => 'Created',
    'myshowcase_import_export_col_entries' => 'Entries',
    'myshowcase_import_export_col_size' => 'Size',
    'myshowcase_import_export_col_rules' => 'Rules',
    'myshowcase_import_export_download' => 'Download',
    'myshowcase_import_export_delete' => 'Delete',
    'myshowcase_import_export_delete_confirm' => 'Delete this export?',
    'myshowcase_import_export_file_deleted' => 'The file of this export has been removed.',
    'myshowcase_import_export_back_to_list' => 'Back to your exports',
    'myshowcase_import_export_detail_summary' => '{1} entries, {2}',
    'myshowcase_import_export_rules_limit' => 'Limit',
    'myshowcase_import_export_rules_limit_all' => 'all (up to {1})',
    'myshowcase_import_export_rules_sort' => 'Sort order',
    'myshowcase_import_export_rules_fields' => 'Content',
    'myshowcase_import_export_rules_options' => 'Options',
    'myshowcase_import_export_option_awaiting' => 'Awaiting approval included',
    'myshowcase_import_export_option_deleted' => 'Soft-deleted included',
    'myshowcase_import_export_yes' => 'yes',
    'myshowcase_import_export_no' => 'no',
    'myshowcase_import_export_rules_removed_characters' => '{1} characters that XML cannot hold were removed',
    'myshowcase_import_export_export_done' => 'Your export of {1} entries has been created.',
    'myshowcase_import_export_export_limit_clamped' => 'The number of entries was limited to {1}.',
    'myshowcase_import_export_export_empty' => 'No entries match: nothing was exported.',
    'myshowcase_import_export_export_too_large' => 'This export would be too large (the limit is {2}). About {1} entries fit: lower the number of entries or choose fewer fields.',
    'myshowcase_import_export_export_storage' => 'The export could not be saved: the export folder is not writable. Please tell an administrator.',
    'myshowcase_import_export_export_cap_reached' => 'You already have the maximum of {1} stored exports in this showcase. Delete one to make a new one.',
    'myshowcase_import_export_export_flood' => 'Please wait {1} seconds before making another export.',
    'myshowcase_import_export_export_deleted' => 'The export has been deleted.',
    'myshowcase_import_export_error_not_found' => 'That export does not exist.',
    'myshowcase_import_export_error_post_required' => 'This action has to be sent from its button.',
    'myshowcase_entry_entry_created_awaiting_approval' => 'Your entry has been created successfully and is awaiting approval by a moderator.<br />It will be visible to others once it is approved. You will now be redirected to the entry.',
    'myshowcase_entry_entry_created_awaiting_approval_list' => 'Your entry has been created successfully and is awaiting approval by a moderator. You can not see it until it is approved. You will now be returned to the list.',
    'myshowcase_entry_entry_updated_awaiting_approval_list' => 'Your changes have been saved and are awaiting approval by a moderator. You can not see the entry until it is approved. You will now be returned to the list.',
    'myshowcase_entry_entry_created_status' => 'Your entry has been created successfully.<br />You will now be returned back to where you came from.',
    'myshowcase_entry_entry_updated' => 'Your entry has been updated successfully.<br />You will now be redirected to the entry.',
    'myshowcase_entry_entry_updated_awaiting_approval' => 'Your changes have been saved and are awaiting approval by a moderator.<br />The entry will be visible to others again once it is approved. You will now be redirected to the entry.',
    'myshowcase_entry_entry_updated_status' => 'Your entry has been updated successfully.<br />You will now be returned back to where you came from.',
    'myshowcase_entry_entry_approved' => 'Your entry has been approved successfully.<br />You will now be returned back to where you came from.',
    'myshowcase_entry_entry_unapproved' => 'The entry has been unapproved successfully.<br />You will now be returned back to where you came from.',
    'myshowcase_entry_entry_soft_deleted' => 'The entry has been soft deleted successfully.<br />You will now be returned back to where you came from.',
    'myshowcase_entry_entry_deleted' => 'The entry has been deleted successfully.<br />You will now be returned back to where you came from.',

    'myshowcase_report_comment_invalid' => 'This comment either does not exist or is not allowed to be reported.',
    'myshowcase_report_comment_content' => '<a href="{1}">Comment</a> from {2}',
    'myshowcase_report_comment_content_entry_user' => '<br /><span class="smalltext">On {1}\'s entry</span>',

    'myshowcase_report_entry_invalid' => 'This entry either does not exist or is not allowed to be reported.',
    'myshowcase_report_entry_content' => '<a href="{1}">Entry</a> from {2}',

    'myshowcase_attachments_watermark_input' => 'Watermark upload images.',

    'myshowcase_attachments_upload_error_minimum_dimensions' => 'The minimum image dimensions for {1} are {2}x{3} (width x height).',
    'myshowcase_attachments_upload_error_maximum_dimensions' => 'The maximum image dimensions for {1} are {2}x{3} (width x height).',
    'myshowcase_attachments_upload_error_upload_failed' => 'Upload failed. Please try again.',
    'myshowcase_attachments_upload_error_invalid_type' => 'The attachment file type is invalid.',
    'myshowcase_attachments_upload_error_thumbnail_failure' => 'There was an problem generating a thumbnail for the selected attachment.',

    'myshowcase_attachments_usage_quote' => 'Your allocated attachment usage quota is {1}.',
    'myshowcase_attachments_usage_quote_unlimited' => 'Unlimited',
    'myshowcase_attachments_usage_details' => 'You are currently using <strong>{1}</strong>.',
    'myshowcase_attachments_view_my_attachments' => '[View My Attachments]',

    'myshowcase_report_error_invalid_entry' => 'This entry does not exist.',
    'myshowcase_report_error_invalid_comment' => 'This comment does not exist.',

    'myshowcase_validation_guest' => 'Guest',

    'myshowcase_validation_errors_entry_invalid_user' => 'The user does not exist.',
    'myshowcase_validation_errors_entry_no_permission' => 'The user does not have permission to post entries.',
    'myshowcase_validation_errors_entry_no_permission_current' => 'You do not have permission to post entries.',
    'myshowcase_validation_errors_entry_invalid_subject_length' => 'Please make sure the subject is between {1} and {2} characters long. It currently is {3} characters long.',
    'myshowcase_validation_errors_entry_invalid_message_length' => 'Please make sure the message is between {1} and {2} characters long. It currently is {3} characters long.',
    'myshowcase_validation_errors_entry_invalid_icon' => 'The selected entry icon is invalid.',
    'myshowcase_validation_errors_entry_invalid_dateline' => 'The selected entry creation date is invalid.',
    'myshowcase_validation_errors_entry_invalid_status' => 'The selected entry status is invalid.',
    'myshowcase_validation_errors_entry_invalid_open_status' => 'The selected entry open status is invalid.',
    'myshowcase_validation_errors_entry_invalid_priority_status' => 'The selected entry priority status is invalid.',
    'myshowcase_validation_errors_entry_invalid_views_count' => 'The entry views count is invalid.',
    'myshowcase_validation_errors_entry_invalid_comments_count' => 'The entry comments count is invalid.',
    'myshowcase_validation_errors_entry_invalid_attachments_count' => 'The entry attachments count is invalid.',
    'myshowcase_validation_errors_entry_invalid_update_dateline' => 'The entry update date is invalid.',
    'myshowcase_validation_errors_entry_invalid_update_user' => 'The update user does not exist.',
    'myshowcase_validation_errors_entry_invalid_update_note' => 'Please make sure the update note is {1} characters long at most.',
    'myshowcase_validation_errors_entry_invalid_last_comment_user' => 'The last comment user does not exist.',
    'myshowcase_validation_errors_entry_invalid_signature_status' => 'The selected entry signature status is invalid.',
    'myshowcase_validation_errors_entry_invalid_smilies_status' => 'The selected entry smilies status is invalid.',
    'myshowcase_validation_errors_entry_invalid_ipaddress' => 'The entry ipaddress is invalid.',

    'myshowcase_validation_errors_comment_invalid_subject_length' => 'Please make sure the subject is between {1} and {2} characters long.',
    'myshowcase_validation_errors_comment_invalid_message_length' => 'Please make sure the message is between {1} and {2} characters long.',
    'myshowcase_validation_errors_comment_invalid_icon' => 'The selected comment icon is invalid.',
]);