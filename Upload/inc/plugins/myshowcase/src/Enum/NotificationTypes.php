<?php

declare(strict_types=1);

namespace MyShowcase\Enum;

enum NotificationTypes
{
    public const NoNotification = 1;
    public const Email = 2;
    public const DirectMessage = 3;
}