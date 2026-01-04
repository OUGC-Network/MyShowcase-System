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

global $showcaseName, $showcase_lower;

//$l['nav_myshowcase'] = $showcaseName; //for breadcrumb
//$l['myshowcase'] = $showcaseName; //for rest of myshowcase
$l['latest_myshowcases'] = "Latest {$showcaseName}";
$l['myshowcase_showcase'] = 'Showcase';

//headerinclude / JS confirmations

//basic headers for index table
$l['myShowcaseMainTableHeadView'] = 'View';
$l['myShowcaseMainTableHeadAuthor'] = 'Author';

$l['myShowcaseMainTableRowLastComment'] = 'Last comment';

//error messages
$l['myshowcase_disabled'] = 'This system is disabled.';
$l['myshowcase_not_authorized'] = 'You are not authorized to perform that action.';
$l['myshowcase_comment_error'] = 'The attempted action failed.';
$l['myshowcase_db_no_data'] = 'No results returned for table lookup. Data is missing.';
$l['myshowcase_feature_disabled'] = 'This feature is disabled.';
$l['myshowcase_comment_empty'] = 'The data submitted contains no text.';

//moderation words
$l['myshowcase_no_myshowcaseselected'] = 'There are no selected entries';
$l['myshowcase_nav_multidelete'] = 'Inline Deletion';
$l['myshowcase_edit'] = 'Edit';
$l['myshowcase_save'] = 'Save';
$l['myshowcase_delete'] = 'Delete';
$l['myshowcase_delete_multi'] = 'Delete Entries';
$l['confirm_delete_myshowcases'] = 'Are you sure you wish to delete the selected entry? Once an entry has been deleted it cannot be restored and any attachments and comment within that entry are also deleted.';
$l['myshowcase_unapproved_count'] = 'The {1} Showcase has {2} unapproved entries.';
$l['myshowcase_unapproved_exist_title'] = 'Unapproved Entries';
$l['myshowcase_unapproved_link'] = 'Click to view unapproved.';

//sorting words
$l['myshowcase_order'] = 'Order By:';
$l['myShowcaseMainSearch'] = 'Search:';
$l['myShowcaseMainSearchFor'] = 'for:';

$l['myshowcase_asc'] = 'asc';
$l['myshowcase_desc'] = 'desc';

$l['myShowcaseMainSearchMatch'] = 'exact match';

//query results
$l['myshowcase_invalid_id'] = 'The specified Entry ID is invalid.';
$l['myshowcase_invalid_comment_id'] = 'The specified Comment ID is invalid.';
$l['myshowcase_invalid_aid'] = 'The specified Attachment ID is invalid.';
$l['myshowcase_not_specified'] = 'Not Specified';

//view options
$l['myshowcase_viewing_user'] = 'Viewing entry for &quot;{username}&quot;';
$l['myshowcase_viewing_attachment'] = 'Viewing Attachment of &quot;{username}&quot;';

//new/edit myshowcase
$l['myShowcaseButtonEntryUpdate'] = 'Update Entry';
$l['myShowcaseButtonCommentCreate'] = 'Post Comment';
$l['myShowcaseButtonCommentUpdate'] = 'Update Comment';
$l['myshowcase_post'] = 'Post';
$l['myshowcase_edit_user'] = 'Edit entry of &quot;{username}&quot;';
$l['myshowcase_editing_user'] = 'Editing entry';
$l['myshowcase_editing_number'] = "<span class=\"smalltext\">This field is going to be formatted as numeric. Non-numeric content will result in a zero value.</span>";
$l['myshowcase_watermark'] = 'Watermark?';

//redirects
$l['redirect_myshowcase_return'] = "<br /><br />Alternatively, <a href=\"{1}\">return to the list</a>.";
$l['redirect_myshowcase_new'] = 'Thank you, your entry has been successfully built.';
$l['redirect_myshowcase'] = '<br />You will now be taken to your entry.';
$l['redirect_myshowcase_approve'] = 'The selected entries have been approved.';
$l['redirect_myshowcase_unapprove'] = 'The selected entries have been unapproved.';
$l['redirect_myshowcase_delete'] = 'The selected entries have been deleted.';
$l['redirect_myshowcase_back'] = 'You will now be redirected back to your original location.';

//attachments
$l['myshowcase_unlimited'] = 'unlimited';
$l['myshowcase_attachments'] = 'Attachments';
$l['myshowcase_new_attachments'] = "Optionally you may attach one or more attachments to this showcase. Please select the file and click 'Add Attachment' to upload it.";
$l['myshowcase_add_attachments'] = 'Add Attachment';
$l['myshowcase_attach_quota'] = 'You are currently using <strong>{1}</strong> of your allowable images ({2})';
$l['myshowcase_attachments_none'] = 'There are no attachments at this time.';
$l['myshowcase_attachment_alt'] = 'Attachment {1} of {2}';
$l['myshowcase_attachment_uploaded'] = '<strong>Date Uploaded:</strong> ';
$l['myshowcase_attachment_filename'] = '<strong>Filename:</strong> ';

$l['myshowcase_entry_approved'] = 'The entry has been approved.<br>You will now be redirected back to where you came from.';
$l['myshowcase_entry_unapproved'] = 'The entry has been unapproved.<br>You will now be redirected back to where you came from.';

//comment language
$l['myshowcase_comments_add'] = 'Add a comment';
$l['myshowcase_posted_at'] = 'posted the following comment at';
$l['myshowcase_comment_edit'] = 'Edit';
$l['myshowcase_comment_delete'] = 'Delete';
$l['myshowcase_comment_soft_delete'] = 'Soft Delete';
$l['myshowcase_comment_restore'] = 'Restore';
$l['myshowcase_comment_approve'] = 'Approve';
$l['myshowcase_comment_unapprove'] = 'Unapprove';
$l['myshowcase_comment_show_all'] = 'Show all {count} comments';
$l['myshowcase_comment_text_limit'] = 'Message should be between {1} and {2} characters long.';
$l['myshowcase_comment_deleted'] = 'The selected comment has been deleted.<br>You will now be redirected back to where you came from.';
$l['myshowcase_comment_added'] = 'Your comment has been added.<br>You will now be redirected back to where you came from.';
$l['myshowcase_comment_approved'] = 'The comment has been approved.<br>You will now be redirected back to where you came from.';
$l['myshowcase_comment_unapproved'] = 'The comment has been unapproved.<br>You will now be redirected back to where you came from.';
$l['myshowcase_comment_soft_deleted'] = 'The comment has been soft deleted.<br>You will now be redirected back to where you came from.';
$l['myshowcase_comment_deleted'] = 'The comment has been deleted.<br>You will now be redirected back to where you came from.';

$l['myshowcase_comment_more'] = '... (visit the entry to read more..)';

$l['myshowcase_comment_emailsubject'] = 'New comment to your {1}';

$l['myshowcase_comment_email'] = "{1},

{2} has just commented on your entry in '{3}'. 

Here is an excerpt of the message:
--
{4}
--

To view the comment, you can go to the following URL:
{5}

Thank you,
{6} Staff

------------------------------------------
Unsubscription Information:

If you would not like to receive any more notifications of new comments to your entries, visit the following URL in your browser and uncheck Receive emails from the Administrators:
{7}/usercp.php?action=options

------------------------------------------";

//misc
$l['myshowcase_from'] = 'From';
$l['myshowcase_jumpto'] = 'Jump to:';
$l['myshowcase_top'] = 'Goto Top';
$l['myshowcase_cancel'] = 'Cancel';

$l['myshowcase_day'] = 'Day';
$l['myshowcase_month'] = 'Month';
$l['myshowcase_year'] = 'Year';

//who's onine and portal
$l['latest_myshowcases'] = 'Latest Showcases';

$l['viewing_myshowcase_list'] = "Viewing <a href=\"../{1}\">{2} List</a>";
$l['viewing_myshowcase'] = "Viewing <a href=\"../{1}\">{2} entry</a> of <a href=\"{3}\">{4}</a>";
$l['viewing_myshowcase_new'] = "Creating <a href=\"../{1}\">New {2}</a>";
$l['viewing_myshowcase_edit'] = 'Editing a {1} entry';
$l['viewing_myshowcase_attach'] = "Viewing <a href=\"../{1}\">Attachment</a> in a <a href=\"../{2}\">{3} entry</a> of <a href=\"{4}\">{5}</a>";

$l = array_merge($l, [
    'myshowcase_main_moderated_by' => 'Moderated by',

    'myshowcase_main_users_browsing' => 'Users browsing this showcase',
    'myshowcase_main_users_browsing_guests' => '{1} Guest(s)',

    'myshowcase_main_search' => 'Search Entries',
    'myshowcase_main_search_keywords' => 'Enter Keywords...',

    'myshowcase_main_inline_moderation' => 'Inline Moderation',
    'myshowcase_main_inline_moderation_go' => 'Go',
    'myshowcase_main_inline_moderation_standard_tool' => 'Standard Tools',
    'myshowcase_main_inline_moderation_open' => 'Open Entries',
    'myshowcase_main_inline_moderation_close' => 'Close Entries',
    'myshowcase_main_inline_moderation_stick' => 'Stick Entries',
    'myshowcase_main_inline_moderation_unstick' => 'Unstick Entries',
    'myshowcase_main_inline_moderation_approve' => 'Approve Entries',
    'myshowcase_main_inline_moderation_unapprove' => 'Unapprove Entries',
    'myshowcase_main_inline_moderation_soft_delete' => 'Soft Delete Entries',
    'myshowcase_main_inline_moderation_restore' => 'Restore Entries',
    'myshowcase_main_inline_moderation_delete' => 'Delete Entries',
    'myshowcase_main_inline_moderation_clear' => 'Clear',
    'myshowcase_main_inline_moderation_no_tool_selected' => 'Please select a tool to perform moderation action.',
    'myshowcase_main_inline_moderation_no_entry_selected' => 'You need to select one or more entries to perform the moderation action on.',

    'myshowcase_main_button_entry_create' => 'Post Entry',
    'myshowcase_main_button_sort_entries' => 'Sort Entries',
    'myshowcase_main_button_search' => 'Search Entries',

    'myshowcase_main_table_head_entry' => 'Entry',
    'myshowcase_main_table_head_author' => 'Author',
    'myshowcase_main_table_head_comments' => 'Comments',
    'myshowcase_main_table_head_last_comment' => 'Last Comment',

    'myshowcase_main_table_separator_sticky' => 'Important Threads',
    'myshowcase_main_table_separator_normal' => 'Normal Threads',
    'myshowcase_main_table_separator_selected_page' => 'All <strong>{1}</strong> entries on this page are selected.',
    'myshowcase_main_table_separator_selected_pall' => 'All <strong>{1}</strong> entries in this forum are selected.',
    'myshowcase_main_table_separator_selected_pall_clear' => 'Clear Selection',

    'myshowcase_main_table_row_comments' => 'Comments',
    'myshowcase_main_table_row_views' => 'Views',
    'myshowcase_main_table_row_closed_entry' => 'Cosed Entry',
    'myshowcase_main_table_row_sticky_entry' => 'Important Entry',
    'myshowcase_main_table_row_deleted_entry' => 'Deleted Entry',
    'myshowcase_main_table_row_awaiting_approval' => 'Awaiting Approval',
    'myshowcase_main_table_row_go_to_first_unread' => 'Go to first unread comment',
    'myshowcase_main_table_row_contains_comments_by_you' => 'Contains comments by you.',
    'myshowcase_main_table_row_by' => 'by',
    'myshowcase_main_table_row_last_comment_by' => 'Last post by',

    'myshowcase_main_table_row_attachment_file_name' => 'Filename',
    'myshowcase_main_table_row_attachment_size' => 'Size',
    'myshowcase_main_table_row_attachment_downloads' => 'Downloads',
    'myshowcase_main_table_row_attachments_count_single' => 'This thread contains 1 attachment.',
    'myshowcase_main_table_row_attachments_count_plural' => 'This thread contains {1} attachments.',

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

    'myshowcase_create_update_entry_title_create' => 'New Thread in {1}',
    'myshowcase_create_update_entry_your_message' => 'Your Message',

    'myshowcase_view_entry_button_create_comment' => 'Post Comment',
    'myshowcase_view_entry_button_entry_closed' => 'Closed',

    'myshowcase_view_entry_comments_none' => 'There are no comments at this time.',
    'myshowcase_view_entry_go_to_my_profile' => 'Go to My Profile',
    'myshowcase_view_entry_quick_comment_message' => 'Compose your message...',
    'myshowcase_view_entry_comments_not_logged_in' => 'You must be logged in to add a comment.',

    'myshowcase_view_entry_comment_form_title' => 'Quick Comment',
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
    'myshowcase_view_inline_moderation_approve' => 'Approve Entry',
    'myshowcase_view_inline_moderation_unapprove' => 'Unapprove Entry',
    'myshowcase_view_inline_moderation_soft_delete' => 'Soft Delete Entry',
    'myshowcase_view_inline_moderation_restore' => 'Restore Entry',
    'myshowcase_view_inline_moderation_delete' => 'Delete Entry',
    'myshowcase_view_inline_moderation_clear' => 'Clear',

    'myshowcase_view_inline_moderation_comments' => 'Inline Comment Moderation',
    'myshowcase_view_inline_moderation_comments_go' => 'Go',
    'myshowcase_view_inline_moderation_comments_standard_tool' => 'Standard Tools',
    'myshowcase_view_inline_moderation_comments_approve' => 'Approve Comments',
    'myshowcase_view_inline_moderation_comments_unapprove' => 'Unapprove Comments',
    'myshowcase_view_inline_moderation_comments_soft_delete' => 'Soft Delete Comments',
    'myshowcase_view_inline_moderation_comments_restore' => 'Restore Comments',
    'myshowcase_view_inline_moderation_comments_delete' => 'Delete Comments',
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
    'myshowcase_view_entry_options' => 'Entry Options',
    'myshowcase_view_entry_options_full_edit' => 'Full Edit',
    'myshowcase_view_entry_options_quick_delete' => 'Delete',
    'myshowcase_view_entry_options_quick_delete_title' => 'Delete this thread',
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
    'myshowcase_view_comment_options_quick_delete_title' => 'Delete this thread',
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

    'myShowcaseCommentDetailsPosts' => 'Posts',
    'myShowcaseCommentDetailsThreads' => 'Threads',
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
]);