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
$l['myShowcaseMainTableHeadView'] = 'View';

//error messages

//moderation words
$l['myshowcase_save'] = 'Save';
$l['myshowcase_unapproved_count'] = 'The {1} Showcase has {2} unapproved entries.';

//query results
$l['myshowcase_invalid_id'] = 'The specified Entry ID is invalid.';

//view options
$l['myshowcase_viewing_user'] = 'Viewing entry for &quot;{1}&quot;';

//new/edit myshowcase
$l['myShowcaseButtonEntryUpdate'] = 'Update Entry';
$l['myShowcaseButtonCommentCreate'] = 'Post Comment';
$l['myShowcaseButtonCommentUpdate'] = 'Update Comment';

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

    'myshowcase_redirect_password_cleared' => 'The stored password for this showcase has been cleared.',

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

    'myshowcase_main_table_row_comments' => 'Comments',
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

    'myshowcase_main_sort_entries' => 'Sort Entries',
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

    'myShowcaseNewEditFormTitle' => 'New Entry',
    'myShowcaseNewEditFormTitleDescription' => 'Create a new entry in the showcase.',
    'myShowcaseNewEditFormEntrySubject' => 'Post Subject',
    'myShowcaseNewEditFormEntrySubjectPlaceholder' => 'Entry subject...',

    'myShowcaseNewEditFormEntryMultiQuoteSingular' => 'You have selected one post from another thread.',
    'myShowcaseNewEditFormEntryMultiQuotePlural' => 'You have selected {1} posts from other threads.',
    'myShowcaseNewEditFormEntryMultiQuoteDeselectSingular' => 'deselect this post',
    'myShowcaseNewEditFormEntryMultiQuoteDeselectPlural' => 'deselect these posts',
    'myShowcaseNewEditFormEntryMultiQuotePlusSingular' => 'Quote this post too',
    'myShowcaseNewEditFormEntryMultiQuotePlusPlural' => 'Quote these posts too',

    'myShowcaseNewEditFormEntryPrefixesNoPrefix' => 'No Prefix',

    'myShowcaseNewEditFormField' => 'Field',
    'myShowcaseNewEditFormValue' => 'Value',

    'myShowcaseNewEditFormButtonCreateEntry' => 'Create Entry',
    'myShowcaseNewEditFormButtonUpdateEntry' => 'Update Entry',
    'myShowcaseNewEditFormButtonPreviewEntry' => 'Preview Entry',

    'myShowcaseNewEditEntryPreview' => 'Entry Preview',

    'myShowcaseCommentCreateUpdateFormButtonUpdate' => 'Update Comment',

    'myShowcaseCommentCreateUpdatePreview' => 'Comment Preview',

    'myShowcaseEntryFieldValueEmpty' => '(no value)',
    'myShowcaseEntryFieldValueCheckBoxYes' => 'Yes',
    'myShowcaseEntryFieldValueCheckBoxNo' => 'No',

    'myShowcaseEntrySearchEntry' => 'Search Entry',
    'myShowcaseEntryEnterKeywords' => 'Enter Keywords...',

    'myShowcaseEntryNextOldest' => 'Next Oldest',
    'myShowcaseEntryNextNewest' => 'Next Newest',

    'myShowcaseEntryEditedBy' => 'This entry was last modified: {1} by',
    'myShowcaseEntryDeletedMessage' => 'This entry by {1} has been deleted.',

    'myShowcaseEntryAttachmentsFiles' => 'Attached Files',
    'myShowcaseEntryAttachmentsImages' => 'Image(s)',
    'myShowcaseEntryAttachmentsThumbnail' => 'Thumbnail(s)',

    'myShowcaseEntryButtonEdit' => 'Edit',
    'myShowcaseEntryButtonEditDescription' => 'Edit this entry',
    'myShowcaseEntryButtonWarn' => 'Warn',
    'myShowcaseEntryButtonWarnDescription' => 'Warn the author for this entry',
    'myShowcaseEntryButtonPurseSpammer' => 'Purge Spammer',
    'myShowcaseEntryButtonPurseSpammerDescription' => 'Purge spammer',
    'myShowcaseEntryButtonReport' => 'Report',
    'myShowcaseEntryButtonReportDescription' => 'Report comment',
    'myShowcaseEntryButtonApprove' => 'Approve',
    'myShowcaseEntryButtonApproveDescription' => 'Approve entry',
    'myShowcaseEntryButtonApproveConfirm' => 'Are you sure you want to approve this entry?',
    'myShowcaseEntryButtonUnapprove' => 'Unapprove',
    'myShowcaseEntryButtonUnapproveDescription' => 'Unapprove entry',
    'myShowcaseEntryButtonUnapproveConfirm' => 'Are you sure you want to unapprove this entry?',
    'myShowcaseEntryButtonSoftDelete' => 'Soft Delete',
    'myShowcaseEntryButtonSoftDeleteDescription' => 'Soft delete entry',
    'myShowcaseEntryButtonSoftDeleteConfirm' => 'Are you sure you want to soft delete this entry?',
    'myShowcaseEntryButtonRestore' => 'Restore',
    'myShowcaseEntryButtonRestoreDescription' => 'Restore entry',
    'myShowcaseEntryButtonRestoreConfirm' => 'Are you sure you want to restore this entry?',
    'myShowcaseEntryButtonDelete' => 'Delete',
    'myShowcaseEntryButtonDeleteDescription' => 'Delete entry',
    'myShowcaseEntryButtonDeleteConfirm' => 'Are you sure you want to delete this entry?',

    'myShowcaseCommentDetailsComments' => 'Comments',
    'myShowcaseCommentDetailsEntries' => 'Entries',
    'myShowcaseCommentDetailsJoined' => 'Joined',
    'myShowcaseCommentDetailsReputation' => 'Reputation:',
    'myShowcaseCommentDetailsWarningLevel' => 'Warning Level:',
    'myShowcaseCommentButtonEmail' => 'Email',
    'myShowcaseCommentButtonEmailDescription' => 'Send this user an email',
    'myShowcaseCommentButtonPrivateMessage' => 'PM',
    'myShowcaseCommentButtonPrivateMessageDescription' => 'Send this user a private message',
    'myShowcaseCommentButtonWebsite' => 'Website',
    'myShowcaseCommentButtonWebsiteDescription' => "Visit this user's website",

    'myShowcaseCommentEditedBy' => 'This comment was last modified: {1} by',
    'myShowcaseCommentDeletedMessage' => 'This entry by {1} has been deleted.',
    'myShowcaseCommentDeletedSButtonShow' => 'Show this Comment',

    'myShowcaseCommentButtonEdit' => 'Edit',
    'myShowcaseCommentButtonEditDescription' => 'Edit this comment',
    'myShowcaseCommentButtonWarn' => 'Warn',
    'myShowcaseCommentButtonWarnDescription' => 'Warn the author for this comment',
    'myShowcaseCommentButtonPurseSpammer' => 'Purge Spammer',
    'myShowcaseCommentButtonPurseSpammerDescription' => 'Purge spammer',
    'myShowcaseCommentButtonReport' => 'Report',
    'myShowcaseCommentButtonReportDescription' => 'Report comment',
    'myShowcaseCommentButtonApprove' => 'Approve',
    'myShowcaseCommentButtonApproveDescription' => 'Approve comment',
    'myShowcaseCommentButtonApproveConfirm' => 'Are you sure you want to approve this comment?',
    'myShowcaseCommentButtonUnapprove' => 'Unapprove',
    'myShowcaseCommentButtonUnapproveDescription' => 'Unapprove comment',
    'myShowcaseCommentButtonUnapproveConfirm' => 'Are you sure you want to unapprove this comment?',
    'myShowcaseCommentButtonSoftDelete' => 'Soft Delete',
    'myShowcaseCommentButtonSoftDeleteDescription' => 'Soft delete comment',
    'myShowcaseCommentButtonSoftDeleteConfirm' => 'Are you sure you want to soft delete this comment?',
    'myShowcaseCommentButtonDelete' => 'Delete',
    'myShowcaseCommentButtonDeleteDescription' => 'Delete comment',
    'myShowcaseCommentButtonDeleteConfirm' => 'Are you sure you want to delete this comment?',
    'myShowcaseCommentButtonRestore' => 'Restore',
    'myShowcaseCommentButtonRestoreDescription' => 'Restore comment',
    'myShowcaseCommentButtonRestoreConfirm' => 'Are you sure you want to restore this comment?',

    'myShowcaseCommentCreateMessage' => 'Message:',
    'myShowcaseCommentCreateMessageOptionSignature' => 'Signature',
    'myShowcaseCommentCreateMessageOptionDisableSmiles' => 'Disable Smiles',
    'myShowcaseCommentCreateMessagePlaceholder' => 'Compose your message...',
    'myShowcaseCommentCreateButton' => 'Post Comment',

    'myShowcaseCommentCreated' => 'Your comment has been created successfully.<br />You will now be returned back to where you came from.',
    'myShowcaseCommentCreatedStatus' => 'Your comment has been created successfully.<br />You will now be returned back to where you came from.',
    'myShowcaseCommentUpdated' => 'Your comment has been updated successfully.<br />You will now be returned back to where you came from.',
    'myShowcaseCommentUpdatedStatus' => 'Your comment has been updated successfully.<br />You will now be returned back to where you came from.',

    'myShowcaseEntryEntryCreated' => 'Your entry has been created successfully.<br />You will now be redirected to the entry.',
    'myShowcaseEntryEntryCreatedStatus' => 'Your entry has been created successfully.<br />You will now be returned back to where you came from.',
    'myShowcaseEntryEntryUpdated' => 'Your entry has been updated successfully.<br />You will now be redirected to the entry.',
    'myShowcaseEntryEntryUpdatedStatus' => 'Your entry has been updated successfully.<br />You will now be returned back to where you came from.',
    'myShowcaseEntryEntryApproved' => 'Your entry has been approved successfully.<br />You will now be returned back to where you came from.',
    'myShowcaseEntryEntryUnapproved' => 'The entry has been unapproved successfully.<br />You will now be returned back to where you came from.',
    'myShowcaseEntryEntrySoftDeleted' => 'The entry has been soft deleted successfully.<br />You will now be returned back to where you came from.',
    'myShowcaseEntryEntryDeleted' => 'The entry has been deleted successfully.<br />You will now be returned back to where you came from.',

    'myShowcaseReportCommentInvalid' => 'This comment either does not exist or is not allowed to be reported.',
    'myShowcaseReportCommentContent' => '<a href="{1}">Comment</a> from {2}',
    'myShowcaseReportCommentContentEntryUser' => '<br /><span class="smalltext">On {1}\'s entry</span>',

    'myShowcaseReportEntryInvalid' => 'This entry either does not exist or is not allowed to be reported.',
    'myShowcaseReportEntryContent' => '<a href="{1}">Entry</a> from {2}',

    'myShowcaseAttachmentsWatermarkInput' => 'Watermark upload images.',

    'myShowcaseAttachmentsUploadErrorMinimumDimensions' => 'The minimum image dimensions for {1} are {2}x{3} (width x height).',
    'myShowcaseAttachmentsUploadErrorMaximumDimensions' => 'The maximum image dimensions for {1} are {2}x{3} (width x height).',
    'myShowcaseAttachmentsUploadErrorUploadFailed' => 'Upload failed. Please try again.',
    'myShowcaseAttachmentsUploadErrorInvalidType' => 'The attachment file type is invalid.',
    'myShowcaseAttachmentsUploadErrorThumbnailFailure' => 'There was an problem generating a thumbnail for the selected attachment.',

    'myShowcaseAttachmentsUsageQuote' => 'Your allocated attachment usage quota is {1}.',
    'myShowcaseAttachmentsUsageQuoteUnlimited' => 'Unlimited',
    'myShowcaseAttachmentsUsageDetails' => 'You are currently using <strong>{1}</strong>.',
    'myShowcaseAttachmentsViewMyAttachments' => '[View My Attachments]',

    'myShowcaseReportErrorInvalidEntry' => 'This entry does not exist.',
    'myShowcaseReportErrorInvalidComment' => 'This comment does not exist.',

    'myshowcase_validation_errors_entry_invalid_subject_length' => 'Please make sure the subject is between {1} and {2} characters long.',
    'myshowcase_validation_errors_entry_invalid_message_length' => 'Please make sure the message is between {1} and {2} characters long.',

    'myshowcase_validation_errors_comment_invalid_subject_length' => 'Please make sure the subject is between {1} and {2} characters long.',
    'myshowcase_validation_errors_comment_invalid_message_length' => 'Please make sure the message is between {1} and {2} characters long.',
]);