<?php

namespace MyShowcase\Enum;

enum ContentStatuses
{
    public const AwaitingApproval = 0;
    public const VisibleApproved = 1;
    public const SoftDeleted = 2;
    public const IsDraft = 2;
}