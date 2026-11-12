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
    public const MainCanView = 'main_can_view';
    public const MainRequireCaptcha = 'main_require_captcha';
    public const ParserAllowHtmlInEntries = 'entries_parser_allow_html';
    public const ParserAllowHtmlInComments = 'comments_parser_allow_html';
    public const EntriesCanView = 'entries_can_view';
    public const EntriesCanViewOnlyOwn = 'entries_can_view_only_own';
    public const EntriesCanViewUnapproved = 'entries_can_view_unapproved';
    public const EntriesCanViewDeleted = 'entries_can_view_deleted';
    public const EntriesCanPost = 'entries_can_post';
    public const EntriesCanUpdate = 'entries_can_update';
    public const EntriesCanOpen = 'entries_can_open';
    public const EntriesCanClose = 'entries_can_close';
    public const EntriesCanSticky = 'entries_can_sticky';
    public const EntriesCanUnstick = 'entries_can_unsticky';
    public const EntriesCanApprove = 'entries_can_approve';
    public const EntriesCanUnapprove = 'entries_can_unapprove';
    public const EntriesCanCopy = 'entries_can_copy';
    public const EntriesCanSoftDelete = 'entries_can_soft_delete';
    public const EntriesCanRestore = 'entries_can_restore';
    public const EntriesCanDelete = 'entries_can_delete';
    public const EntriesCanExport = 'entries_can_export';
    public const EntriesCanImport = 'entries_can_import';
    public const EntriesCanViewDeletionNotices = 'entries_can_view_deletion_notices';
    public const EntriesModerateNew = 'entries_moderate_new';
    public const EntriesModerateUpdates = 'entries_moderate_updates';
    public const EntriesLimitViewAmount = 'entries_limit_view_amount';
    public const EntriesLimitViewInterval = 'entries_limit_view_interval';
    public const EntriesLimitViewIntervalAmount = 'entries_limit_view_interval_amount';
    public const EntriesLimitPostAmount = 'entries_limit_post_amount';
    public const EntriesLimitPostInterval = 'entries_limit_post_interval';
    public const EntriesLimitPostIntervalAmount = 'entries_limit_post_interval_amount';
    public const EntriesReputationLimitAmount = 'entries_reputation_limit_amount';
    public const EntriesReputationLimitInterval = 'entries_reputation_limit_interval';
    public const EntriesReputationLimitIntervalAmount = 'entries_reputation_limit_interval_amount';
    public const EntriesFloodTime = 'entries_flood_time';
    public const CommentsCanView = 'comments_can_view';
    public const CommentsCanViewOnlyOwn = 'comments_can_view_only_own';
    public const CommentsCanViewUnapproved = 'comments_can_view_unapproved';
    public const CommentsCanViewDeleted = 'comments_can_view_deleted';
    public const CommentsCanPost = 'comments_can_post';
    public const CommentsCanPostInClosedEntries = 'comments_can_post_in_closed_entries';
    public const CommentsCanUpdate = 'comments_can_update';
    public const CommentsCanApprove = 'comments_can_approve';
    public const CommentsCanUnapprove = 'comments_can_unapprove';
    public const CommentsCanMove = 'comments_can_move';
    public const CommentsCanSoftDelete = 'comments_can_soft_delete';
    public const CommentsCanRestore = 'comments_can_restore';
    public const CommentsCanDelete = 'comments_can_delete';
    public const CommentsCanViewDeletionNotices = 'comments_can_view_deletion_notices';
    public const CommentsModerateNew = 'comments_moderate_new';
    public const CommentsModerateUpdates = 'comments_moderate_updates';
    public const CommentsLimitPostAmount = 'comments_limit_post_amount';
    public const CommentsLimitPostInterval = 'comments_limit_post_interval';
    public const CommentsLimitPostIntervalAmount = 'comments_limit_post_interval_amount';
    public const CommentsReputationLimitAmount = 'comments_reputation_limit_amount';
    public const CommentsReputationLimitInterval = 'comments_reputation_limit_interval';
    public const CommentsReputationLimitIntervalAmount = 'comments_reputation_limit_interval_amount';
    public const CommentsFloodTime = 'comments_flood_time';
    public const AttachmentsCanView = 'attachments_can_view'; // todo, if user can't view then they can't download either
    public const AttachmentsCanViewOnlyOwn = 'attachments_can_view_only_own';
    public const AttachmentsCanViewUnapproved = 'attachments_can_view_unapproved';
    public const AttachmentsCanViewDeleted = 'attachments_can_view_deleted';
    public const AttachmentsCanUpload = 'attachments_can_upload';
    public const AttachmentsCanDownload = 'attachments_can_download';
    public const AttachmentsCanUpdate = 'attachments_can_update';
    public const AttachmentsCanApprove = 'attachments_can_approve';
    public const AttachmentsCanUnapprove = 'attachments_can_unapprove';
    public const AttachmentsCanSoftDelete = 'attachments_can_soft_delete';
    public const AttachmentsCanRestore = 'attachments_can_restore';
    public const AttachmentsCanDelete = 'attachments_can_delete';
    public const AttachmentsCanViewDeletionNotices = 'attachments_can_view_deletion_notices';
    public const AttachmentsModerateNew = 'attachments_moderate_new';
    public const AttachmentsModerateUpdates = 'attachments_moderate_updates';
    public const AttachmentsLimitUploadAmount = 'attachments_limit_upload_amount';
    public const AttachmentsLimitUploadInterval = 'attachments_limit_upload_interval';
    public const AttachmentsLimitUploadIntervalAmount = 'attachments_limit_upload_interval_amount';
    public const AttachmentsLimitUploadQuotaAmount = 'attachments_limit_upload_quota_amount';
    public const AttachmentsLimitUploadQuotaInterval = 'attachments_limit_upload_quota_interval';
    public const AttachmentsLimitUploadQuotaIntervalAmount = 'attachments_limit_upload_quota_interval_amount';
    public const AttachmentsLimitDownloadAmount = 'attachments_limit_download_amount';
    public const AttachmentsLimitDownloadInterval = 'attachments_limit_download_interval';
    public const AttachmentsLimitDownloadIntervalAmount = 'attachments_limit_download_interval_amount';
    public const AttachmentsLimitDownloadQuotaAmount = 'attachments_limit_download_quota_amount';
    public const AttachmentsLimitDownloadQuotaInterval = 'attachments_limit_download_quota_interval';
    public const AttachmentsLimitDownloadQuotaIntervalAmount = 'attachments_limit_download_quota_interval_amount';
    public const AttachmentsLimitPerEntry = 'attachments_limit_per_entry';
    public const AttachmentsLimitPerComment = 'attachments_limit_per_comment';
    public const AttachmentsUploadFloodTime = 'attachments_upload_flood_time';
    public const AttachmentsDownloadFloodTime = 'attachments_download_flood_time';
    public const AttachmentsQuota = 'attachments_quota';
    public const AttachmentsWaterMarkImages = 'attachments_watermark_images';
    public const CanSearchEntries = 'can_search_entries';
    public const CanSearchComments = 'can_search_comments';
    public const CanSearchAttachments = 'can_search_attachments';
    public const CanViewIPs = 'can_view_ips';
    public const CanUseCustomModerationTools = 'can_use_custom_moderation_tools';
    public const CanManageReports = 'can_manage_reports';
    public const CanManageLogs = 'can_manage_logs';
    public const CanBypassDownloadLimits = 'can_bypass_download_limits';
    public const CanBypassModeration = 'can_bypass_moderation';
}
