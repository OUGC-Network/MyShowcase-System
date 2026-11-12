<?php

declare(strict_types=1);

namespace MyShowcase\Entity;

use MyShowcase\Collection\Collection;

use const INF;

class PermissionEntity
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
        public ?float $main_require_captcha = null,
        public ?int $group_id = null,
        public ?int $user_id = null,
        public ?int $entries_can_view = null,
        public ?float $entries_can_view_only_own = null,
        public ?int $entries_can_view_unapproved = null,
        public ?float $entries_can_view_deleted = null,
        public ?int $entries_can_post = null,
        public ?int $entries_can_update = null,
        public ?float $entries_parser_allow_html = null,
        public ?float $entries_can_open = null,
        public ?float $entries_can_close = null,
        public ?float $entries_can_sticky = null,
        public ?float $entries_can_unsticky = null,
        public ?float $entries_can_approve = null,
        public ?float $entries_can_unapprove = null,
        public ?float $entries_can_copy = null,
        public ?float $entries_can_soft_delete = null,
        public ?float $entries_can_restore = null,
        public ?float $entries_can_delete = null,
        public ?float $entries_can_export = null,
        public ?float $entries_can_import = null,
        public ?float $entries_can_view_deletion_notices = null,
        public ?float $entries_moderate_new = null,
        public ?float $entries_moderate_updates = null,
        public ?float $entries_limit_view_amount = null,
        public ?float $entries_limit_view_interval = null,
        public null|int|float $entries_limit_view_interval_amount = null,
        public ?float $entries_limit_post_amount = null,
        public ?float $entries_limit_post_interval = null,
        public null|int|float $entries_limit_post_interval_amount = null,
        public ?float $entries_reputation_limit_amount = null,
        public ?float $entries_reputation_limit_interval = null,
        public null|int|float $entries_reputation_limit_interval_amount = null,
        public null|int|float $entries_flood_time = null,
        public ?float $comments_can_view = null,
        public ?float $comments_can_view_only_own = null,
        public ?float $comments_can_view_unapproved = null,
        public ?float $comments_can_view_deleted = null,
        public ?float $comments_can_post = null,
        public ?float $comments_can_post_in_closed_entries = null,
        public ?float $comments_can_update = null,
        public ?float $comments_parser_allow_html = null,
        public ?float $comments_can_approve = null,
        public ?float $comments_can_unapprove = null,
        public ?float $comments_can_move = null,
        public ?float $comments_can_soft_delete = null,
        public ?float $comments_can_restore = null,
        public ?float $comments_can_delete = null,
        public ?float $comments_can_view_deletion_notices = null,
        public ?float $comments_moderate_new = null,
        public ?float $comments_moderate_updates = null,
        public ?float $comments_limit_post_amount = null,
        public ?float $comments_limit_post_interval = null,
        public null|int|float $comments_limit_post_interval_amount = null,
        public ?float $comments_reputation_limit_amount = null,
        public ?float $comments_reputation_limit_interval = null,
        public null|int|float $comments_reputation_limit_interval_amount = null,
        public null|int|float $comments_flood_time = null,
        public ?float $attachments_can_view = null,
        public ?float $attachments_can_view_only_own = null,
        public ?float $attachments_can_view_unapproved = null,
        public ?float $attachments_can_view_deleted = null,
        public ?float $attachments_can_upload = null,
        public ?float $attachments_can_download = null,
        public ?float $attachments_can_update = null,
        public ?float $attachments_can_approve = null,
        public ?float $attachments_can_unapprove = null,
        public ?float $attachments_can_soft_delete = null,
        public ?float $attachments_can_restore = null,
        public ?float $attachments_can_delete = null,
        public ?float $attachments_can_view_deletion_notices = null,
        public ?float $attachments_moderate_new = null,
        public ?float $attachments_moderate_updates = null,
        public ?float $attachments_limit_upload_amount = null,
        public ?float $attachments_limit_upload_interval = null,
        public null|int|float $attachments_limit_upload_interval_amount = null,
        public ?float $attachments_limit_upload_quota_amount = null,
        public ?float $attachments_limit_upload_quota_interval = null,
        public null|int|float $attachments_limit_upload_quota_interval_amount = null,
        public ?float $attachments_limit_download_amount = null,
        public ?float $attachments_limit_download_interval = null,
        public null|int|float $attachments_limit_download_interval_amount = null,
        public ?float $attachments_limit_download_quota_amount = null,
        public ?float $attachments_limit_download_quota_interval = null,
        public null|int|float $attachments_limit_download_quota_interval_amount = null,
        public ?float $attachments_limit_per_entry = null,
        public ?float $attachments_limit_per_comment = null,
        public ?float $attachments_upload_flood_time = null,
        public ?float $attachments_download_flood_time = null,
        public ?float $attachments_quota = null,
        public ?float $attachments_watermark_images = null,
        public ?float $can_search_entries = null,
        public ?float $can_search_comments = null,
        public ?float $can_search_attachments = null,
        public ?float $can_view_ips = null,
        public ?float $can_use_custom_moderation_tools = null,
        public ?float $can_manage_reports = null,
        public ?float $can_manage_logs = null,
        public ?float $can_bypass_download_limits = null,
        public ?float $can_bypass_moderation = null,
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