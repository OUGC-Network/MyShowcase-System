<?php

declare(strict_types=1);

namespace MyShowcase\Entity;

use MyShowcase\Collection\Collection;

use const INF;

class Permission
{
    public const BOOLEAN_TYPE_DISALLOW = 0;

    public const BOOLEAN_TYPE_ALLOW = 1;

    public const BOOLEAN_TYPE_INHERIT = 2;

    public const BOOLEAN_TYPE_NEVER = 3;

    public const NUMERIC_TYPE_UNLIMITED = 0;

    public const NUMERIC_TYPE_LOWER = 0;

    public const NUMERIC_TYPE_INFINITY = INF;

    public const TIME_INTERVAL_TYPE_SECONDS = 0;

    public const TIME_INTERVAL_TYPE_MINUTES = 1;

    public const TIME_INTERVAL_TYPE_HOURS = 2;

    public const TIME_INTERVAL_TYPE_DAYS = 3;

    public const GROUP_GUESTS = 1;

    private Collection $permissions;

    public function __construct(
        public ?int $permission_id = null,
        public ?int $showcase_id = null,
        public ?int $main_can_view = null,
        public ?int $group_id = null,
        public ?int $user_id = null,
        public ?int $entries_can_view = null,
        public ?int $entries_can_view_only_own = null,
        public ?int $entries_can_view_unapproved = null,
        public ?int $entries_can_view_deleted = null,
        public ?int $entries_can_post = null,
        public ?int $entries_can_update = null,
        public ?int $entries_can_open = null,
        public ?int $entries_can_close = null,
        public ?int $entries_can_sticky = null,
        public ?int $entries_can_unsticky = null,
        public ?int $entries_can_approve = null,
        public ?int $entries_can_unapprove = null,
        public ?int $entries_can_copy = null,
        public ?int $entries_can_soft_delete = null,
        public ?int $entries_can_restore = null,
        public ?int $entries_can_delete = null,
        public ?int $entries_can_view_deletion_notices = null,
        public ?int $entries_moderate_new = null,
        public ?int $entries_moderate_updates = null,
        public ?int $entries_limit_view_amount = null,
        public ?int $entries_limit_view_interval = null,
        public ?int $entries_limit_view_interval_amount = null,
        public ?int $entries_limit_post_amount = null,
        public ?int $entries_limit_post_interval = null,
        public ?int $entries_limit_post_interval_amount = null,
        public ?int $entries_reputation_limit_amount = null,
        public ?int $entries_reputation_limit_interval = null,
        public ?int $entries_reputation_limit_interval_amount = null,
        public ?int $entries_flood_time = null,
        public ?int $comments_can_view = null,
        public ?int $comments_can_view_only_own = null,
        public ?int $comments_can_view_unapproved = null,
        public ?int $comments_can_view_deleted = null,
        public ?int $comments_can_post = null,
        public ?int $comments_can_post_in_closed_entries = null,
        public ?int $comments_can_update = null,
        public ?int $comments_can_approve = null,
        public ?int $comments_can_unapprove = null,
        public ?int $comments_can_move = null,
        public ?int $comments_can_soft_delete = null,
        public ?int $comments_can_restore = null,
        public ?int $comments_can_delete = null,
        public ?int $comments_can_view_deletion_notices = null,
        public ?int $comments_moderate_new = null,
        public ?int $comments_moderate_updates = null,
        public ?int $comments_limit_post_amount = null,
        public ?int $comments_limit_post_interval = null,
        public ?int $comments_limit_post_interval_amount = null,
        public ?int $comments_reputation_limit_amount = null,
        public ?int $comments_reputation_limit_interval = null,
        public ?int $comments_reputation_limit_interval_amount = null,
        public ?int $comments_flood_time = null,
        public ?int $attachments_can_view = null,
        public ?int $attachments_can_view_only_own = null,
        public ?int $attachments_can_view_unapproved = null,
        public ?int $attachments_can_view_deleted = null,
        public ?int $attachments_can_upload = null,
        public ?int $attachments_can_download = null,
        public ?int $attachments_can_update = null,
        public ?int $attachments_can_approve = null,
        public ?int $attachments_can_unapprove = null,
        public ?int $attachments_can_soft_delete = null,
        public ?int $attachments_can_restore = null,
        public ?int $attachments_can_delete = null,
        public ?int $attachments_can_view_deletion_notices = null,
        public ?int $attachments_moderate_new = null,
        public ?int $attachments_moderate_updates = null,
        public ?int $attachments_limit_upload_amount = null,
        public ?int $attachments_limit_upload_interval = null,
        public ?int $attachments_limit_upload_interval_amount = null,
        public ?int $attachments_limit_upload_quota_amount = null,
        public ?int $attachments_limit_upload_quota_interval = null,
        public ?int $attachments_limit_upload_quota_interval_amount = null,
        public ?int $attachments_limit_download_amount = null,
        public ?int $attachments_limit_download_interval = null,
        public ?int $attachments_limit_download_interval_amount = null,
        public ?int $attachments_limit_download_quota_amount = null,
        public ?int $attachments_limit_download_quota_interval = null,
        public ?int $attachments_limit_download_quota_interval_amount = null,
        public ?int $attachments_limit_per_entry = null,
        public ?int $attachments_limit_per_comment = null,
        public ?int $attachments_upload_flood_time = null,
        public ?int $attachments_download_flood_time = null,
        public ?int $attachments_quota = null,
        public ?int $attachments_watermark_images = null,
        public ?int $can_search_entries = null,
        public ?int $can_search_comments = null,
        public ?int $can_search_attachments = null,
        public ?int $can_view_ips = null,
        public ?int $can_use_custom_moderation_tools = null,
        public ?int $can_manage_reports = null,
        public ?int $can_manage_logs = null,
        public ?int $can_bypass_download_limits = null,
        public ?int $can_bypass_moderation = null,
        public ?array $extra_data = null,
    ) {
        if ($this->extra_data === null) {
            $this->extra_data = [];
        }

        $this->permissions = new Collection();
    }

    public function setExtraData(string $name, int|null $value): self
    {
        $this->extra_data[$name] = $value;

        return $this;
    }

    public function getExtraData(string $name): int|null|false
    {
        if (!isset($this->extra_data[$name])) {
            return false;
        }

        return $this->extra_data[$name];
    }
}