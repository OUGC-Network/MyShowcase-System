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

enum UserPermissions
{
    public const CanView = 'can_view';
    public const CanSearch = 'can_search';
    public const CanViewEntries = 'can_view_entries';
    public const CanCreateEntries = 'can_create_entries';
    public const CanUpdateEntries = 'can_update_entries';
    public const CanDeleteEntries = 'can_delete_entries';
    public const CanViewComments = 'can_view_comments';
    public const CanCreateComments = 'can_create_comments';
    public const CanUpdateComments = 'can_update_comments';
    public const CanDeleteComments = 'can_delete_comments';
    public const CanViewAttachments = 'can_view_attachments';
    public const CanUploadAttachments = 'can_upload_attachments';
    public const CanUpdateAttachments = 'can_update_attachments';
    public const CanDeleteAttachments = 'can_delete_attachments';
    public const CanDownloadAttachments = 'can_download_attachments';
    public const AttachmentsUploadQuote = 'attachments_upload_quote'; // total size
    //public const AttachmentsDownloadQuote = 'attachments_download_quote'; // per day ?
    public const CanWaterMarkAttachments = 'can_watermark_attachments';
    public const AttachmentsFilesLimit = 'attachments_files_limit'; // # of files
    public const CanViewSoftDeletedNotice = 'can_view_soft_deleted_notice';
    public const ModerateEntryCreate = 'moderate_entry_create';
    public const ModerateEntryUpdate = 'moderate_entry_update';
    public const ModerateCommentsCreate = 'moderate_comments_create';
    public const ModerateCommentsUpdate = 'moderate_comments_update';
    public const ModerateAttachmentsUpload = 'moderate_attachments_upload';
    public const ModerateAttachmentsUpdate = 'moderate_attachments_update';
    //public const CanDeleteAuthorComments = 'can_delete_author_comments';
    // public const NewPointsIncomeEntry = 'newpoints_income_entry';// highest
    //public const NewPointsIncomeComment = 'newpoints_income_comment';// highest
    //public const NewPointsChargeEntry = 'newpoints_charge_entry'; // lowest
    //public const NewPointsChargeComment = 'newpoints_charge_comment';// lowest
}

