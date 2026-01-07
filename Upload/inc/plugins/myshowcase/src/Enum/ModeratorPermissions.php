<?php

declare(strict_types=1);

namespace MyShowcase\Enum;

enum ModeratorPermissions
{
    public const CanManageEntries = 'can_manage_entries';
    public const CanManageComments = 'can_manage_comments';
    public const CanManageAttachments = 'can_manage_attachments';
    public const CanManageReports = 'can_manage_reports';
    public const CanManageLogs = 'can_manage_logs';
}