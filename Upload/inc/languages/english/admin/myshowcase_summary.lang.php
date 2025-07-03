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
    'myShowcaseAdminErrorRegularExpression' => 'The selected regular expression seems to be invalid.',
    'myShowcaseAdminErrorDuplicatedScriptFile' => 'The selected script file is already in use.',
    'myShowcaseAdminErrorDuplicatedTemplatePrefix' => 'The selected template prefix is already in use.',
    'myShowcaseAdminErrorTableDelete' => "This showcase's data table contains data and can not be deleted separately. If you wish to delete the populated table, delete the entire showcase.",
    'myShowcaseAdminErrorTableCreate' => 'Creating the showcase data table was unsuccessful.',
    'myShowcaseAdminErrorShowcaseDelete' => 'The showcase deletion failed.',
    'myShowcaseAdminErrorInvalidGroup' => 'Invalid user group',

    'myShowcaseAdminSuccessNewShowcase' => 'The showcase was successfully added.',
    'myShowcaseAdminSuccessEnabledShowcase' => 'The showcase was successfully enabled.',
    'myShowcaseAdminSuccessDisabledShowcase' => 'The showcase was successfully disabled.',
    'myShowcaseAdminSuccessTableCreated' => 'The showcase data table was successfully created.',
    'myShowcaseAdminSuccessTableRebuilt' => 'The showcase data table was successfully rebuilt.',
    'myShowcaseAdminSuccessTableDropped' => 'The showcase data table was successfully dropped.',
    'myShowcaseAdminSuccessShowcaseDeleted' => 'The showcase was successfully deleted.',
    'myShowcaseAdminSuccessShowcaseUpdated' => 'The showcase settings were successfully updated.',
    'myShowcaseAdminSuccessShowcaseEditOther' => 'The showcase additional settings were successfully edited.',
    'myShowcaseAdminSuccessShowcaseEditPermissions' => 'The showcase permissions were successfully edited.',
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

    'myShowcaseAdminSummaryAddEditName' => 'Name',
    'myShowcaseAdminSummaryAddEditNameDescription' => 'This is the name used in links and titles of the showcase.',
    'myShowcaseAdminSummaryAddEditDescription' => 'Description',
    'myShowcaseAdminSummaryAddEditDescriptionDescription' => 'Simple description of the showcase. Not used externally, for admin use only.',
    'myShowcaseAdminSummaryAddEditScriptName' => 'Script Name',
    'myShowcaseAdminSummaryAddEditScriptNameDescription' => 'Script for this showcase. Default: <code>showcase.php</code>.',
    'myShowcaseAdminSummaryAddEditFieldSetId' => 'Field Set',
    'myShowcaseAdminSummaryAddEditFieldSetIdDescription' => 'Field set used to define the showcase data table. This cannot be changed after creating the data table.',
    'myShowcaseAdminSummaryAddEditRelativePath' => 'Relative Path',
    'myShowcaseAdminSummaryAddEditRelativePathDescription' => 'This is path from the forum index page to the showcase. (empty is okay if file is in same folder as forum).',
    'myShowcaseAdminSummaryAddEditDisplayOrder' => 'Display Order',
    'myShowcaseAdminSummaryAddEditDisplayOrderDescription' => 'The display and build order for this showcase.',
    'myShowcaseAdminSummaryAddEditEnabled' => 'Enabled',
    'myShowcaseAdminSummaryAddEditEnabledDescription' => 'Enable or disable this showcase.',
    'myShowcaseAdminSummaryAddEditEnableFriendlyUrls' => 'Enable Friendly Urls',
    'myShowcaseAdminSummaryAddEditEnableFriendlyUrlsDescription' => 'Enable SEO friendly urls for this showcase.',
    'myShowcaseAdminSummaryAddEditCustomThemeTemplatePrefix' => 'Theme Template Prefix',
    'myShowcaseAdminSummaryAddEditCustomThemeTemplatePrefixDescription' => 'Template prefix to fetch custom templates.',
    'myShowcaseAdminSummaryAddEditOrderDefaultField' => 'Entries Order Default Field',
    'myShowcaseAdminSummaryAddEditOrderDefaultFieldDescription' => 'Order entries in the main page using a default field.',
    'myShowcaseAdminSummaryAddEditFilterForceField' => 'Force Default Filter',
    'myShowcaseAdminSummaryAddEditFilterForceFieldDescription' => 'Force a filter field to view entries.',
    'myShowcaseAdminSummaryAddEditFilterForceFieldUserID' => 'User ID (profile showcase)',

    'myShowcaseAdminSummaryAddEditEntriesEntriesPerPage' => 'Entries Options',
    'myShowcaseAdminSummaryAddEditEntriesEntriesPerPageDescription' => 'Entries Per Page',
    'myShowcaseAdminSummaryAddEditEntries' => 'The amount of entries to display per page.',
    'myShowcaseAdminSummaryAddEditParser' => 'Parser Options',
    'myShowcaseAdminSummaryAddEditParserParserAllowHtml' => 'Allow HTML',
    'myShowcaseAdminSummaryAddEditParserParserAllowMycode' => 'Allow MyCode',
    'myShowcaseAdminSummaryAddEditParserParserAllowSmiles' => 'Allow Smiles',
    'myShowcaseAdminSummaryAddEditParserParserAllowImageCode' => 'Allow Image MyCode',
    'myShowcaseAdminSummaryAddEditParserParserAllowVideoCode' => 'Allow Video MyCode',
    'myShowcaseAdminSummaryAddEditDisplay' => 'Display Options',
    'myShowcaseAdminSummaryAddEditDisplayDisplayEmptyFields' => 'Display empty fields',
    'myShowcaseAdminSummaryAddEditDisplayDisplayAvatarsEntries' => 'Display avatars in entries',
    'myShowcaseAdminSummaryAddEditDisplayDisplayAvatarsComments' => 'Display avatars in comments',
    'myShowcaseAdminSummaryAddEditDisplayDisplayStarsEntries' => 'Display stars in entries',
    'myShowcaseAdminSummaryAddEditDisplayDisplayStarsComments' => 'Display stars in comments',
    'myShowcaseAdminSummaryAddEditDisplayDisplayGroupImageEntries' => 'Display group images in entries',
    'myShowcaseAdminSummaryAddEditDisplayDisplayGroupImageComments' => 'Display group images in comments',
    'myShowcaseAdminSummaryAddEditDisplayDisplayUserDetailsEntries' => 'Display user details in entries',
    'myShowcaseAdminSummaryAddEditDisplayDisplayUserDetailsComments' => 'Display user details in comments',
    'myShowcaseAdminSummaryAddEditDisplayDisplaySignaturesEntries' => 'Display signatures in entries',
    'myShowcaseAdminSummaryAddEditDisplayDisplaySignaturesComments' => 'Display signatures in comments',
    'myShowcaseAdminSummaryAddEditModeration' => 'Moderation Options',
    'myShowcaseAdminSummaryAddEditModerationModerateEntriesCreate' => 'Moderate new entries',
    'myShowcaseAdminSummaryAddEditModerationModerateEntriesUpdate' => 'Moderate entry updates',
    'myShowcaseAdminSummaryAddEditModerationModerateCommentsCreate' => 'Moderate new comments',
    'myShowcaseAdminSummaryAddEditModerationModerateCommentsUpdate' => 'Moderate comment updates',
    'myShowcaseAdminSummaryAddEditModerationModerateAttachmentsUpload' => 'Moderate new attachments',
    'myShowcaseAdminSummaryAddEditModerationModerateAttachmentsUpdate' => 'Moderate attachment updates',
    'myShowcaseAdminSummaryAddEditComments' => 'Comments Options',
    'myShowcaseAdminSummaryAddEditCommentsCommentsAllow' => 'Allow comments',
    'myShowcaseAdminSummaryAddEditCommentsCommentsBuildEditor' => 'Enable editor for comments',
    'myShowcaseAdminSummaryAddEditCommentsCommentsMinimumLength' => 'Comments Minimum Length',
    'myShowcaseAdminSummaryAddEditCommentsCommentsMinimumLengthDescription' => 'The minimum length for posting comments.',
    'myShowcaseAdminSummaryAddEditCommentsCommentsMaximumLength' => 'Comments Maximum Length',
    'myShowcaseAdminSummaryAddEditCommentsCommentsMaximumLengthDescription' => 'The maximum length for posting comments.',
    'myShowcaseAdminSummaryAddEditCommentsCommentsPerPage' => 'Comments Per Page',
    'myShowcaseAdminSummaryAddEditCommentsCommentsPerPageDescription' => 'The amount of comments to display per page.',
    'myShowcaseAdminSummaryAddEditAttachments' => 'Attachments Options',
    'myShowcaseAdminSummaryAddEditAttachmentsAttachmentsAllowEntries' => 'Enable attachments in entries',
    'myShowcaseAdminSummaryAddEditAttachmentsAttachmentsUploadsPath' => 'Attachments Upload Path',
    'myShowcaseAdminSummaryAddEditAttachmentsAttachmentsUploadsPathDescription' => 'The path to upload attachments. Example: <code>./uploads/showcase</code>',
    'myShowcaseAdminSummaryAddEditAttachmentsAttachmentsLimitEntries' => 'Attachments limit in entries',
    'myShowcaseAdminSummaryAddEditAttachmentsAttachmentsLimitEntriesDescription' => 'Maximum attachments allowed per entry.',
    'myShowcaseAdminSummaryAddEditAttachmentsAttachmentsGrouping' => 'Attachments grouping in entries',
    'myShowcaseAdminSummaryAddEditAttachmentsAttachmentsGroupingDescription' => 'Number of attachments to display per row. Use 0 to allow browser to control.',
    'myShowcaseAdminSummaryAddEditAttachmentsAttachmentsMainRenderFirst' => 'Render first entry attachment in main page',
    'myShowcaseAdminSummaryAddEditAttachmentsAttachmentsWatermarkFile' => 'Watermark Image',
    'myShowcaseAdminSummaryAddEditAttachmentsAttachmentsWatermarkFileDescription' => 'Specify the path to the watermark image. If this is a valid file and the user chooses to watermark an attachment, this file will be used as the watermark. Applies to new image attachments only.',
    'myShowcaseAdminSummaryAddEditAttachmentsAttachmentsWatermarkLocation' => 'Watermark Location',
    'myShowcaseAdminSummaryAddEditAttachmentsAttachmentsWatermarkLocationDescription' => 'This setting is only applied to new image attachments. Existing images are not watermarked.',
    'myShowcaseAdminSummaryAddEditAttachmentsAttachmentsThumbnailsWidth' => 'Attached Thumbnail Maximum Height',
    'myShowcaseAdminSummaryAddEditAttachmentsAttachmentsThumbnailsWidthDescription' => 'Enter the width that attached thumbnails should be generated at.',
    'myShowcaseAdminSummaryAddEditAttachmentsAttachmentsThumbnailsHeight' => 'Attached Thumbnail Maximum Width',
    'myShowcaseAdminSummaryAddEditAttachmentsAttachmentsThumbnailsHeightDescription' => 'Enter the height that attached thumbnails should be generated at.',

    'myShowcaseAdminSummaryEditFormWaterMarkLocationUpperLeft' => 'Upper Left',
    'myShowcaseAdminSummaryEditFormWaterMarkLocationUpperRight' => 'Upper Right',
    'myShowcaseAdminSummaryEditFormWaterMarkLocationCenter' => 'Center',
    'myShowcaseAdminSummaryEditFormWaterMarkLocationLowerLeft' => 'Lower Left',
    'myShowcaseAdminSummaryEditFormWaterMarkLocationLowerRight' => 'Lower Right',
    'myShowcaseAdminSummaryEditFormListViewLink' => 'List View Link',
    'myShowcaseAdminSummaryEditFormListViewLinkDescription' => 'Enabled this feature if you want to replace the default image above with the first showcase attachment, if available. If you are experiencing slow performance, disable this feature.',
    'myShowcaseAdminSummaryEditFormListViewLinkUseAttach' => 'Replace Default List Image above with thumbnail of showcase entry',

    'myShowcaseAdminSummaryPermissionsFieldGroupGeneral' => 'General',
    'myShowcaseAdminSummaryPermissionsFieldGroupEntries' => 'Entries',
    'myShowcaseAdminSummaryPermissionsFieldGroupComments' => 'Comments',
    'myShowcaseAdminSummaryPermissionsFieldGroupAttachments' => 'Attachments',
    'myShowcaseAdminSummaryPermissionsFieldGroupModeration' => 'Moderation',

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
    'myShowcaseAdminSummaryPermissionsFormPermissions' => 'Permissions',
    'myShowcaseAdminSummaryPermissionsFormAllowedActions' => 'Overview: Allowed Actions',
    'myShowcaseAdminSummaryPermissionsFormDisallowedActions' => 'Overview: Disallowed Actions',
    'myShowcaseAdminSummaryPermissionsFormInherited' => 'inherited',
    'myShowcaseAdminSummaryPermissionsFormCustom' => 'custom',
    'myShowcaseAdminSummaryPermissionsFormEdit' => 'Edit Permissions',
    'myShowcaseAdminSummaryPermissionsFormClear' => 'Clear Custom Permissions',
    'myShowcaseAdminSummaryPermissionsFormSet' => 'Set Custom Permissions',
    'myShowcaseAdminSummaryPermissionsFormSave' => 'Save Custom Permissions',

    'myShowcaseAdminSummaryPermissionsFormCustomPermissions' => 'Custom Permissions',
    'myShowcaseAdminSummaryPermissionsFormCustomPermissionsDescription' => 'Here you can modify the full permissions for an individual group for a single showcase.',

    'myShowcaseAdminSummaryPermissionsFormCustomPermissionsSuccess' => 'The showcase permissions have been saved successfully.',

    'myShowcaseAdminSummaryPermissionsFormConfirmClear' => 'Are you sure you wish to clear this custom permission?',

    'myShowcaseAdminSummaryPermissionsFormButtonSubmit' => 'Save Permissions',

    'myShowcaseAdminSummaryPermissionsClearConfirm' => 'Are you sure you wish to clear this custom permission?',
    'myShowcaseAdminSummaryPermissionsClearSuccess' => 'The custom permissions for this showcase have been cleared successfully.',

], $l);
