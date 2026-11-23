# Default permissions

This page lists the MyShowcase permissions that every user group starts with, and the values a fresh installation
gives them. The values come from the table definition `myshowcase_permissions` in `Plugin/Core.php` (`TABLES_DATA`): each
column's `default` is the value of a group that has no row of its own.

## Where the defaults apply

- **Installation and activation.** `pluginActivation()` in `admin.php` calls
  `PermissionsRepository::seedMissingGlobalRows()`, which gives every user group a global row (`showcase_id` 0) with the
  defaults below. A row that already exists is not changed, so activating the plugin again adds only the rows that are missing
  (decision D217).
- **A group created later.** It has no row, so the plugin reads the defaults when it checks a permission
  (`Service/UsersManager.php`, the global permissions of a user's groups). The ACP's group form shows the same values for such a
  group, so the form and the checks agree.
- **Per showcase.** A row for one showcase (`showcase_id` not 0) replaces the global values of its group for that showcase.
  Nothing in the installation creates those rows.

Change a permission in ACP > Users and Groups > Usergroups (the MyShowcase tab of a group) only where you want a value other than
the default.

## How to read the values

- `1` allows the action, `0` does not. A `can_*` column is a yes or no switch.
- A limit column of `0` means no limit (for example `entries_limit_post_amount`). Only some limits are enforced yet; see
  [pending-work.md](pending-work.md) NEW-6 for the list.
- A flood time is in seconds; `0` means no wait.

## Main list and the showcase

| Permission | Default |
|---|---|
| `main_can_view` | 1 |
| `main_require_captcha` | 0 |

## Entries

| Permission | Default |
|---|---|
| `entries_can_view` | 1 |
| `entries_can_view_only_own` | 0 |
| `entries_can_view_unapproved` | 1 |
| `entries_can_view_deleted` | 0 |
| `entries_can_post` | 0 |
| `entries_can_update` | 0 |
| `entries_parser_allow_html` | 0 |
| `entries_can_open` | 0 |
| `entries_can_close` | 0 |
| `entries_can_sticky` | 0 |
| `entries_can_unsticky` | 0 |
| `entries_can_approve` | 0 |
| `entries_can_unapprove` | 0 |
| `entries_can_copy` | 0 |
| `entries_can_soft_delete` | 0 |
| `entries_can_restore` | 0 |
| `entries_can_delete` | 0 |
| `entries_can_export` | 0 |
| `entries_can_import` | 0 |
| `entries_can_view_deletion_notices` | 1 |
| `entries_moderate_new` | 0 |
| `entries_moderate_updates` | 0 |
| `entries_limit_view_amount` | 0 |
| `entries_limit_view_interval` | 0 |
| `entries_limit_view_interval_amount` | 0 |
| `entries_limit_post_amount` | 0 |
| `entries_limit_post_interval` | 0 |
| `entries_limit_post_interval_amount` | 0 |
| `entries_reputation_limit_amount` | 0 |
| `entries_reputation_limit_interval` | 0 |
| `entries_reputation_limit_interval_amount` | 0 |
| `entries_flood_time` | 60 |

## Comments

| Permission | Default |
|---|---|
| `comments_can_view` | 1 |
| `comments_can_view_only_own` | 0 |
| `comments_can_view_unapproved` | 1 |
| `comments_can_view_deleted` | 0 |
| `comments_can_post` | 0 |
| `comments_can_post_in_closed_entries` | 0 |
| `comments_can_update` | 0 |
| `comments_parser_allow_html` | 0 |
| `comments_can_approve` | 0 |
| `comments_can_unapprove` | 0 |
| `comments_can_move` | 0 |
| `comments_can_soft_delete` | 0 |
| `comments_can_restore` | 0 |
| `comments_can_delete` | 0 |
| `comments_can_view_deletion_notices` | 1 |
| `comments_moderate_new` | 0 |
| `comments_moderate_updates` | 0 |
| `comments_limit_post_amount` | 0 |
| `comments_limit_post_interval` | 0 |
| `comments_limit_post_interval_amount` | 0 |
| `comments_reputation_limit_amount` | 0 |
| `comments_reputation_limit_interval` | 0 |
| `comments_reputation_limit_interval_amount` | 0 |
| `comments_flood_time` | 60 |

## Attachments

| Permission | Default |
|---|---|
| `attachments_can_view` | 1 |
| `attachments_can_view_only_own` | 0 |
| `attachments_can_view_unapproved` | 1 |
| `attachments_can_view_deleted` | 0 |
| `attachments_can_upload` | 0 |
| `attachments_can_download` | 1 |
| `attachments_can_update` | 0 |
| `attachments_can_approve` | 0 |
| `attachments_can_unapprove` | 0 |
| `attachments_can_soft_delete` | 0 |
| `attachments_can_restore` | 0 |
| `attachments_can_delete` | 0 |
| `attachments_can_view_deletion_notices` | 1 |
| `attachments_moderate_new` | 0 |
| `attachments_moderate_updates` | 0 |
| `attachments_limit_upload_amount` | 0 |
| `attachments_limit_upload_interval` | 0 |
| `attachments_limit_upload_interval_amount` | 0 |
| `attachments_limit_upload_quota_amount` | 0 |
| `attachments_limit_upload_quota_interval` | 0 |
| `attachments_limit_upload_quota_interval_amount` | 0 |
| `attachments_limit_download_amount` | 0 |
| `attachments_limit_download_interval` | 0 |
| `attachments_limit_download_interval_amount` | 0 |
| `attachments_limit_download_quota_amount` | 0 |
| `attachments_limit_download_quota_interval` | 0 |
| `attachments_limit_download_quota_interval_amount` | 0 |
| `attachments_limit_per_entry` | 0 |
| `attachments_limit_per_comment` | 0 |
| `attachments_upload_flood_time` | 0 |
| `attachments_download_flood_time` | 0 |
| `attachments_quota` | 0 |
| `attachments_watermark_images` | 0 |

## Search, moderation and tools

| Permission | Default |
|---|---|
| `can_search_entries` | 1 |
| `can_search_comments` | 1 |
| `can_search_attachments` | 1 |
| `can_view_ips` | 0 |
| `can_use_custom_moderation_tools` | 0 |
| `can_manage_reports` | 0 |
| `can_manage_logs` | 0 |
| `can_bypass_download_limits` | 0 |
| `can_bypass_moderation` | 0 |

The moderator rows of a showcase (`myshowcase_moderators`) are a separate setting: the entries and comments flags of a
moderator row say which areas that user or group moderates. They are not part of the table above.

## Related

- [README.txt](README.txt): the installation and the upgrade notes.
- [visibility.md](visibility.md): who may see what, by these permissions.
