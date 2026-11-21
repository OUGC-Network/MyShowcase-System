<?php

declare(strict_types=1);

namespace MyShowcase\Enum;

enum ModeratorPermissions
{
    public const EntriesCanView = 'entries_can_view';
    public const CanManageComments = 'can_manage_comments';
}