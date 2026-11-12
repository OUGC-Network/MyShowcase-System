# Template reference

The forum side of MyShowcase renders Twig templates from `Upload/inc/plugins/myshowcase/view/templates/`, the
"global" set. This page lists, for every global template, what it shows, the variables it receives, the templates it
includes, the forms and inputs it posts, and the permission and moderator flags it tests. The variables were checked
against the code that renders each template (the controllers in `src/Controller/`, `src/Service/RenderManager.php` and
`forum_hooks.php`).

## How a template is chosen

`RenderManager::templateGet($name, $context)` renders one template by name:

* When the showcase has a template set (`myshowcase_config.custom_template_prefix`, chosen on the General tab of the
  showcase in the ACP) and `view/templates/<prefix>/<name>.twig` exists, that file is rendered.
* Otherwise the global `view/templates/<name>.twig` is rendered.
* Templates **included** from another template (`{% include '@ext.myshowcase/...' %}`) always come from the global
  set, whichever set the including template belongs to.

A custom template set is a folder under `view/templates/` whose name is lower case letters and digits. It holds only
the templates it overrides; every other name falls back to the global file. A set that overrides a template must keep
in step with the global template it replaces:

* the variables listed for that template below (renamed or removed variables are not passed any more);
* the form actions, input names and `my_post_key` of its forms and links, which the controllers check;
* the moderator and permission flags (`entry_moderator`, `comment_moderator`, `permissions.*`), so that a set does not
  show tools the user may not use. The controllers check every action again, so a wrong flag shows a control that
  ends on the no-permission page; it does not grant anything.

## Variables every template receives

`templateGet()` merges these into the context of every template (the template's own variables win):

| Variable | Type | Content |
|---|---|---|
| `config` | `ShowcaseEntity` | The showcase's `myshowcase_config` row (`name`, `showcase_url`, `main_per_page`, `comments_enable`, `attachments_*`, ...). `config.showcase_url` is the address of `showcase.php` for the showcase; every route below is relative to it. |
| `permissions` | array | The current user's merged group permissions (`myshowcase_permissions` columns, for example `entries_can_post`, `comments_can_soft_delete`, `main_require_captcha`). These are about the user's own content. |
| `current_user` | object | The current user (`uid`, `hasPermission()`). |
| `url.showcase` | string | Same as `config.showcase_url`. |
| `url.showcase_assets` | string | Asset address of the showcase. |
| `url.main` | string | Route of the main list (`/`). |
| `url_params` | array | Accepted query parameters of the request (`page`, `keywords`, `order_by`, `order_dir`, `threshold[days]`, ...). |
| `current_page` | int | Requested page number. |
| `showcase.version`, `version` | int | Asset cache-buster. |
| `showcase.custom_fields_collection` | collection | The fields of the showcase's collection. |
| `showcase.collection_display_fields` | array | `field_key => label` of the fields shown in the list. |
| `meta.page` | array | Open Graph entries (`property`, `content`). |
| `error_messages` | string | MyBB inline error box, or `''`. |
| `pagination` | string | MyBB `multipage()` HTML, or `''`. |

MyBB 1.9 also provides `mybb` (the `MyBB` object: `mybb.user`, `mybb.usergroup`, `mybb.settings`, `mybb.post_code`,
`mybb.input`), `lang` (the language object) and `theme` to every template.

### Moderator flags

The group permissions cover a user's own entries and comments. The entries and comments of other users also need the
showcase moderator rights: the super moderator groups, or a row on the Moderators tab of the showcase with "entries"
or "comments" on. Waiting content is shown to its author and to those moderators.

| Variable | Set by | Meaning |
|---|---|---|
| `entry_moderator` | `EntriesController::main()`, `EntriesController::view()`, `RenderManager::buildPost()` | `EntriesManager::isEntryModerator()`: the user moderates the entries of this showcase. |
| `comment_moderator` | `EntriesController::view()`, `RenderManager::buildPost()` | `EntriesManager::isCommentModerator()`: the user moderates the comments of this showcase. |
| `unapproved_entries` | `EntriesController::main()` | `''`, or `{url, text}` when the user is an entry moderator and entries wait for approval. |

Templates use these flags, not `permissions.entries_can_view`, to show moderator tools.

## Main list

### `pageMain.twig`

The main list of a showcase. Rendered by `EntriesController::main()` (route `/`, and `/search`).

* **Variables:** `normal_entries`, `stuck_entries` (arrays of `EntryEntity`, see `pageMainEntry.twig`);
  `total_entries` (int); `entry_moderator`; `unapproved_entries`; `moderators` (`users[user_id] = {user_id, username,
  group_id, display_group_id}`, `groups[group_id] = {title, group_id}`) when "Display moderators" is on;
  `users_browsing` and `users_browsing_counter` (`members`, `invisible`, `guests`); `icons_cache`; `url.clear_password`
  (with `my_post_key`), `url.create_entry` (only with `entries_can_post`), `url.sort.<column>` and `url.sort.<field_key>`.
* **Includes:** `globalMessageUnapprovedEntries.twig` (when `unapproved_entries`), `pageMainEntry.twig`,
  `pageMainEntryDeleted.twig` (a soft-deleted row for a user who is neither entry moderator nor has
  `entries_can_view_deleted`), `pageMainInlineModeration.twig` (only when `entry_moderator`).
* **Forms:** the sort / day-threshold / search form, `GET` to `config.showcase_url ~ url.main` with `sort`,
  `order_dir`, `threshold[days]`, `keywords`, `page` and the current `url_params` as hidden inputs.
* **Flags:** `entry_moderator`, `permissions.entries_can_view_deleted`, `config.main_display_moderators`.

### `pageMainEntry.twig`

One row of the list, included for each `entry`. Uses the context of `pageMain.twig`.

* **`entry`** (`EntryEntity`): `entry_slug`, `user_id`, `username`, `status` (`0` waiting, `1` visible, `2` soft
  deleted), `is_closed`, `is_sticky`, `views`, `comments_approved`, `last_comment_username`, `getFields()`,
  `getAttachments()`, and `extra_data`: `url` (EntryView route), `url_last_comment`, `subject_html` (escaped or parsed
  subject fields and subject), `main_image` (`{url, name}` of the first image of the file fields, or `false`),
  `dateline_timestamp`, `selected`, `modChecked`, `background_class`, `unread_comments`, `last_commenter_user_data`,
  `last_comment_url`, `last_comment_date`, `attachments_count`, `comments_by_you`.
* **Includes:** `pageMainEntryAttachment.twig` (when `config.attachments_main_render` and
  `permissions.attachments_can_view`).
* **Flags:** `entry_moderator` (moderator row class and row checkbox).

### `pageMainEntryDeleted.twig`

The collapsed row of a soft-deleted entry. Variables: `entry` (as above). Flags: `entry_moderator` (row checkbox).

### `pageMainEntryAttachment.twig`

One attachment of a list row. Variables: `attachment_data` (`AttachmentEntity`: `attachment_name`, `file_size`,
`downloads`, `dateline`, `has_thumbnail`, `extra_data.is_image`, `extra_data.url`, `extra_data.url_thumbnail`).

### `pageMainInlineModeration.twig`

The inline moderation box of the list, included only for entry moderators.

* **Form:** `POST` to `url.main` with `action=inline_moderation`, `moderate` (`open`, `close`, `stick`, `unstick`,
  `approve`, `unapprove`, `soft_delete`, `restore`, `delete`), `selected_all_entries`, `selected_entries` and
  `unselected_entries` (JSON lists of entry slugs filled by `jscripts/myshowcase_inline.js`), `my_post_key`, and the
  current `url_params`.
* **Checks** (`EntriesController::main()`): the post key, the action's own permission, then for every selected entry
  the D1 rule (own entry, or entry moderator). One entry the user may not moderate refuses the whole request.
* `copy` is not offered: it is not implemented.

### `globalMessageUnapprovedEntries.twig`

The "entries waiting for approval" notice. Variables: `unapprovedEntriesUrl`, `unapprovedText` (passed by the
`include` in `pageMain.twig`).

## Entry page

### `pageView.twig`

The page of one entry with its comments. Rendered by `EntriesController::view()` (route
`/view/{entry_slug}/{entry_slug_custom}`).

* **Variables:** `entry_data` (`EntryEntity`, `rendered_entry` holds the HTML of `pageViewEntry.twig`);
  `commentObjects` (`comment_id => CommentEntity`, `rendered_comment` holds the HTML of `pageViewComment.twig`);
  `entry_moderator`; `comment_moderator`; `comments_can_post`; `is_subscribed`; `threaded_mode`; `post_options`
  (`allow_signature`, `disable_smilies`); `code_buttons`; `captcha`; `online_count`, `online_users`; `meta.canonical`;
  `url.entry`, `url.entry_moderation` (EntryUpdate route of the entry), `url.comment`, `url.create_comment`,
  `url.threaded`, `url.subscribe` and `url.unsubscribe` (both with `my_post_key`), `url.oldest`, `url.newest`.
* **Includes:** `pageViewInlineModeration.twig` (only when `entry_moderator`).
* **Forms:** the quick comment form, `POST` to `config.showcase_url ~ url.create_comment` with `message`,
  `my_post_key`, `postoptions[signature]`, `postoptions[disablesmilies]`, and for entry moderators
  `modoptions[closethread]` and `modoptions[stickthread]`.
* **Flags:** `entry_moderator` (closed-entry comment button, quick-reply moderator options, moderation box),
  `permissions.comments_can_post`, `permissions.comments_can_view`, `permissions.can_search_entries`,
  `permissions.main_require_captcha`.

### `pageViewInlineModeration.twig`

The moderation box of the entry page, for entry moderators.

* **Form:** `POST` to `config.showcase_url ~ url.entry_moderation` with `my_post_key` and `action` (`open` or `close`,
  `stick` or `unstick`, `approve` or `unapprove`, `soft_delete` or `restore`, `delete`, depending on the entry's state).
* **Checks** (`EntriesController::entryPageModeration()`): the post key, the action's permission, and the D1 rule.
  No core MyBB endpoint (`moderation.php`) is used.

### `pageViewEntry.twig`

The post bit of the entry. Rendered by `RenderManager::buildPost()` (through `buildEntry()`); its HTML is
`entry_data.rendered_entry`.

* **Variables:** `entry_data` (`EntryEntity`: `entry_slug`, `status`, `dateline`, `message`, `update_note`, `icon_id`,
  `getFields()`, `extra_data.is_deleted`, `extra_data.moderator_check`, `extra_data.can_edit`, `extra_data.show_ip`,
  `extra_data.hide_ip`, `extra_data.ip_address`, `extra_data.reporters_list`, `extra_data.edit_user_data`);
  `user_data` (the author); `user_avatar` (`image`, `width_height`); `user_signature`; `user_permissions` (MyBB group
  permissions of the author); `userOnlineStatus`; `entry_attachments` (HTML of `pageViewEntryAttachments.twig`);
  `icons_cache`; `unapproved_class`; `style_visibility`; `is_ignored`, `ignored_message`, `deletedMessage`;
  `show_edited_message`; `show_purge_spammer_button`; `is_preview`; `entry_moderator`; `comment_moderator`;
  `url.entry`, `url.entry_api`, and for the author with `entries_can_update` or an entry moderator `url.update`,
  `url.update_api`, `url.soft_delete` (`delete=1`), `url.delete` (`delete=2`) and `url.restore` (`action=restore`),
  every one with `my_post_key`.
* **Includes:** `pageViewEntryField.twig` for each viewable field.
* **Links:** full edit `url.update`; soft delete `url.soft_delete` (with `permissions.entries_can_soft_delete`);
  delete permanently `url.delete` (with `permissions.entries_can_delete`); restore `url.restore` (entry moderators
  with `permissions.entries_can_restore`, shown on soft-deleted entries). All go to `EntriesController::create()` on
  the EntryUpdate route, which runs `entryPageModeration()` first.
* **Flags:** `entry_moderator` (inline checkbox, collapsed deleted post, restore), `permissions.entries_can_update`,
  `permissions.entries_can_soft_delete`, `permissions.entries_can_delete`, `permissions.entries_can_restore`,
  `permissions.comments_can_post`.

### `pageViewEntryField.twig`

One field of the entry. Variables: `field_data` (`FieldsInterface`: `getLabel()`, `getUserValue()`,
`getUserValueDisplay()`, `field_definition.html_type`, `attachments_rendered`).

### `pageViewEntryAttachments.twig`, `pageViewEntryFieldAttachments.twig`, `pageViewCommentAttachments.twig`

The attachments of an entry, of a file field and of a comment. Rendered by `RenderManager::buildAttachments()`.

* **Variables:** `attachedThumbnails`, `attachedImages`, `attachedFiles` (arrays of `AttachmentEntity`, chosen by
  `config.attachments_display_type`), `show_unapproved`, `unapproved_message`.
* **Includes:** the matching `...AttachmentsThumbnail.twig`, `...AttachmentsImage.twig`, `...AttachmentsFile.twig`
  for each item (`thumbnail`, `image`, `attachment`: `attachment_name` or `file_name`, `dateline`, `downloads`,
  `extra_data.file_size`, `extra_data.url`, `extra_data.url_thumbnail`).

### `pageViewEntryAttachment.twig`, `pageViewCommentAttachment.twig`

One attachment placed in a field value with `[attachment=<hash>]`. Variables: `attachment_id`, `attachment_data`.
Flags: `permissions.attachments_can_download`.

### `pageViewComment.twig`

The post bit of a comment. Rendered by `RenderManager::buildPost()` (through `buildComment()`); its HTML is
`comment_data.rendered_comment`.

* **Variables:** as `pageViewEntry.twig`, plus `comment_data` (`CommentEntity`: `comment_slug`, `status`, `message`,
  `extra_data.is_deleted`, `extra_data.reporters_list`) and `commentAttachments`; for the author with
  `comments_can_update` or a comment moderator: `url.update`, `url.soft_delete` (`delete=1`), `url.delete`
  (`delete=2`) and `url.restore` (`action=restore`), every one with `my_post_key`.
* **Links:** full edit `url.update`; delete `url.soft_delete` (with `permissions.comments_can_soft_delete`, or a
  comment moderator); restore `url.restore` (comment moderators with `permissions.comments_can_restore`, shown on
  soft-deleted comments).
* **Checks** (`CommentsController::update()` → `commentPageModeration()`): the post key, then
  `comments_can_soft_delete`, `comments_can_delete` or `comments_can_restore`, and the D1 rule (own comment, or comment
  moderator). Hard delete also deletes the comment's attachments and files, then recounts the entry's comments.
* **Flags:** `comment_moderator`, `permissions.comments_can_update`, `permissions.comments_can_soft_delete`,
  `permissions.comments_can_delete`, `permissions.comments_can_restore`, `permissions.comments_can_post`.

## Entry form

### `pageEntryCreateUpdate.twig`

The create and edit form of an entry. Rendered by `EntriesController::create()` (routes `/create` and
`/update/{entry_slug}`).

* **Variables:** `entry` (`EntryEntity` with its fields); `is_update_page`; `is_preview_page`; `entry_preview` (HTML of
  `pageViewEntry.twig`); `attachment_objects`; `post_options`; `post_icons`; `code_buttons`, `smiles_inserter`;
  `moderator_options` (`is_closed`, `is_sticky`); `show_moderator_options`, `show_close_options`,
  `show_sticky_options`; `captcha`; `can_use_signature`; `attachmentsUpload`; `url.crate_update` (form action),
  `url.upload` (upload route, with `my_post_key`).
* **Includes:** `pageEntryCreateUpdateField.twig` (each field shown in the form), `pageEntryCreateUpdatePostIcons.twig`,
  `pageEntryCreateUpdateSubscription.twig`, `pageEntryCreateUpdateAttachments.twig`.
* **Form:** `POST` to `config.showcase_url ~ url.crate_update` with `my_post_key`, `post_hash`, `subject`, `message`,
  the field inputs (`<field_key>` or `<field_key>[]`), `remove_attachments[]`, `icon_id`, `subscription_method`,
  `allow_signature`, `disable_smilies`, `is_closed`, `is_sticky`, `preview`, `submit`. The controller ignores the
  columns a user may not set (author, status, counters, slugs, dates).
* **Flags:** `permissions.entries_can_close`, `permissions.entries_can_sticky`, `permissions.main_require_captcha`.

### `pageEntryCreateUpdateField.twig`

One input of the form. Variables: `field_data` (`FieldsInterface`: `field_key`, `html_type`, `getValue()`,
`getSelectableValues()`, `isSelected()`, `getAttachments()`, `is_required`, `placeholder`, `minimum_length`,
`maximum_length`, ...), `url.upload`. File fields upload to `url.upload` (`AttachmentsController::uploadAttachment()`,
which answers 403 without a valid `my_post_key`) and include `pageEntryCreateUpdateFieldAttachment.twig` for each file.

### `pageEntryCreateUpdateFieldAttachment.twig`

A file already attached to a file field. Variables: `attachment_data`, `attachment_id`. Input: `remove_attachments[]`
(the attachment id); the controller only removes files of the edited entry's file fields (or of the form's
`post_hash` on create).

### `pageEntryCreateUpdatePostIcons.twig`, `pageEntryCreateUpdateSubscription.twig`

Post icon choice (`icon_id`; variables `post_icons`, `icon_data`) and subscription choice (`subscription_method`).

### `pageEntryCreateUpdateAttachments.twig`

The legacy attachments box (`attachments[]`, `newattachment`, `updateattachment`). Flags: `permissions.attachments_can_upload`,
`permissions.attachments_can_update`, `permissions.attachments_quota`. Includes MyBB's `misc/attachments_attachment.twig`.

### `pageEntryCreateUpdateAttachmentsBox.twig`, `pageCommentCreateUpdateAttachmentsBox.twig`

Upload quota box. Variables: `usageQuoteNote`, `usageDetails`, `totalUserUsage`, `watermarkSelectedElement`,
`createUpdateUrl`. Input: `attachment_watermark_file`. `RenderManager::buildAttachmentsUpload()` returns `''` at
present, so these are not rendered.

## Comment form

### `pageCommentCreateUpdateContents.twig`

The full comment form. Rendered by `CommentsController::create()` (routes `/view/{entry_slug}/{entry_slug_custom}/comment`
and `/view/{entry_slug}/update/comment/{comment_slug}`).

* **Variables:** `commentPreview` (HTML of `pageCommentCreateUpdateContentsPreview.twig`), `code_buttons`,
  `buttonText`, `createUpdateUrl`, `attachmentsUpload`, `comment_data`.
* **Form:** `POST` with `my_post_key`, `post_hash`, `message`, `preview`, `submit`.

### `pageCommentCreateUpdateContentsPreview.twig`

Variables: `commentPreview` (HTML of `pageViewComment.twig`).

## Other templates

### `pageAccess.twig`

The password form of a protected showcase. Rendered by `BaseController::verifyPassword()`. Variables:
`display_error`, `http_host`, `request_uri`. Form: `POST` to the current address with `password`.

### `postBitAttachmentThumbnail.twig`

A showcase attachment embedded in a forum post with `[attachment=<hash>]`. Rendered from `forum_hooks.php`
(`postbit`). Variables: `attachment` (`AttachmentEntity`), `url.showcase_assets`.

### `portal_rand_showcase.twig`

A random entry box for the MyBB portal. Variables: `config`, `theme`, `entry_data.extra_data.url` (EntryView route,
the same helper as the main list; the template prefixes `config.showcase_url`), `entryDescription`, `userName`,
`entryViews`, `entryComments`, `entryImage`. No code renders it at present: there is no portal hook.
