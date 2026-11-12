# Public identifiers (`public_id`)

Every record that a page shows, links to, or receives back from the browser is identified there by its `public_id`.
The tables are defined in `Upload/inc/plugins/myshowcase/Plugin/Core.php` (`TABLES_DATA`, `DATA_TABLE_STRUCTURE`);
table names below are given without the board prefix.

## Convention

* **Internal ids** (`entry_id`, `comment_id`, `attachment_id`, `entry_history_id`, ...) are the auto-increment primary
  keys. They are used for joins and for references between tables only: they are never rendered in a page, a URL, an
  HTML id or anchor, a MyCode or a JSON response, and never read from the request.
* **`public_id`** is what is rendered and what is accepted from the browser: route parameters, form fields, inline
  moderation selections, `[attachment=...]` codes, anchors and element ids in the templates.
* The column is called `public_id` in every table. Where a route or a request carries more than one, the parameter is
  named after its row: `entry_public_id`, `comment_public_id`, `attachment_public_id`
  (`/{showcase}/view/{entry_public_id}/update/comment/{comment_public_id}`). Controller arguments use the same names,
  because the router passes route parameters as named arguments.
* `entry_slug_custom` is the readable part of an entry address, built from the subject or the fields with
  `enable_slug`. It is not an identifier: entries are always looked up by `public_id`.
* `post_hash` is a temporary token that ties uploads to an entry or comment that is not saved yet. It is not a record
  identifier and is not unique, but it has the same shape as a `public_id` and comes from the same generator.

## The generator

`MyShowcase\Util\StringUtil` (`src/Util/StringUtil.php`):

* `generatePublicId(?int $length = null)` returns `$length` lowercase hexadecimal characters drawn from
  `random_bytes()`. The default length is the `slugLength` setting (`SETTINGS` in `Upload/inc/plugins/myshowcase.php`,
  10 by default), which is also the size of every `public_id` and `post_hash` column (`VARCHAR(slugLength)`).
* `publicIdPattern()` is the matching regular expression fragment (`[0-9a-f]{slugLength}`), used to find
  `[attachment=...]` codes in forum posts.
* `isValidPublicId()` checks a whole value against that pattern; the entry form uses it to accept a posted
  `post_hash` only when it has the generated shape.

The generator does not guarantee uniqueness. Each insert draws a value and draws again while a row already uses it
(`EntriesManager::validationEntryInsert()`, `CommentsManager::validationCommentInsert()`,
`AttachmentsManager::uploadAttachment()`); the unique key is the last guard. No UUIDs are used for these values.

## Tables with `public_id`

| Table | `public_id` | Filled by |
|---|---|---|
| `myshowcase_data{showcase_id}` (`DATA_TABLE_STRUCTURE['myshowcase_data']`) | `VARCHAR(slugLength)`, unique | entry insert |
| `myshowcase_comments` | `VARCHAR(slugLength)`, unique | comment insert |
| `myshowcase_attachments` | `VARCHAR(slugLength)`, unique | upload |
| `myshowcase_comments_history` | `VARCHAR(slugLength)` NOT NULL, unique | nothing yet, see below |
| `myshowcase_attachments_history` | `VARCHAR(slugLength)` NOT NULL, unique | nothing yet, see below |
| `DATA_TABLE_STRUCTURE['myshowcase_data_history']` | `VARCHAR(slugLength)` NOT NULL, unique | definition only, see below |

`post_hash` is `VARCHAR(slugLength)` in `myshowcase_data{showcase_id}`, `myshowcase_comments` and
`myshowcase_attachments`, without a unique key.

Every unique key is named `<column>_uq`; on PostgreSQL and SQLite, where index names are global,
`SchemaManager::indexName()` prefixes it with the table name (`mybb_myshowcase_comments_public_id_uq`). The columns
have no default: PostgreSQL and SQLite refuse an insert that does not supply `public_id`, and so does MySQL in strict
mode.

Changes to these definitions are applied by reinstalling the plugin; activation does not migrate existing rows.

## Partial and future work

* **History records.** The history tables have their `public_id` column and unique key, but no code writes history
  rows yet, and no table is created from `DATA_TABLE_STRUCTURE['myshowcase_data_history']` (no per-showcase entry
  history table exists). Whatever writes history rows must set `public_id` with `generatePublicId()` on every insert.
  Public URLs, routes, pages and permissions for history records do not exist.
* **Tables without `public_id`.** Not converted yet: `myshowcase_attachments_shared`, `myshowcase_config` (the
  showcase), `myshowcase_collections`, `myshowcase_subscriptions`, the log tables (`myshowcase_logs`,
  `myshowcase_access_logs`, `myshowcase_attachments_download_logs`) and `myshowcase_field_data`.
* **`edit_user_id`.** The `edit_user_id` columns carry the note `todo, should be entry_history_id` in `Core.php`
  (on `myshowcase_data`, `myshowcase_comments` and `myshowcase_attachments`, whose `update_dateline` also notes
  `attachment_history_id`): an edited record would reference its history row rather than the editing user. This is
  still open.
