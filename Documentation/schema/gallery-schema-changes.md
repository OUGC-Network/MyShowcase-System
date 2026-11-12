# Gallery schema changes

This document describes the database changes planned for gallery-type showcases: category option metadata,
multi-select tags, folders (albums), image variants and password hardening. Nothing here is implemented yet; the
status of every item is given in its group and in the [change log](#change-log).

The tables are defined in `Upload/inc/plugins/myshowcase/Plugin/Core.php` (`TABLES_DATA`) and created or altered by
`Upload/inc/plugins/myshowcase/src/Service/SchemaManager.php` when the plugin is activated. How JSON files describe
collections and showcases is documented in [`Upload/inc/plugins/myshowcase/imports/README.md`](../../Upload/inc/plugins/myshowcase/imports/README.md).

Status values used here:

* **Approved — not implemented**: agreed, waiting for its implementation track.
* **Already satisfied by the existing schema**: agreed, and the current schema already provides it; no change is
  needed.

## Contents

* [Existing schema this builds on](#existing-schema-this-builds-on)
* [How column types are created on each engine](#how-column-types-are-created-on-each-engine)
* [Schema mechanics that affect every group](#schema-mechanics-that-affect-every-group)
* [Group A — Category option metadata](#group-a--category-option-metadata)
* [Group B — Multi-select and tags](#group-b--multi-select-and-tags)
* [Group C — Folders](#group-c--folders)
* [Group D — Image pipeline and attachment variants](#group-d--image-pipeline-and-attachment-variants)
* [Group E — Password hardening](#group-e--password-hardening)
* [Template sets](#template-sets)
* [Implementation order and dependencies](#implementation-order-and-dependencies)
* [Change log](#change-log)

## Existing schema this builds on

Table names below are given without the board prefix (`mybb_` by default).

| Table | Primary key | Role |
|---|---|---|
| `myshowcase_config` | `showcase_id` | One row per showcase (settings). |
| `myshowcase_collections` | `collection_id` | Field sets ("collections"). |
| `myshowcase_fields` | `field_id` | Fields of a collection; unique `(collection_id, field_key)`. |
| `myshowcase_field_data` | `field_data_id` | Options of select, radio and checkbox fields. |
| `myshowcase_data{showcase_id}` | `entry_id` | Entries of one showcase, one column per field. |
| `myshowcase_attachments` | `attachment_id` | Uploaded files; `attachment_hash` unique. |
| `myshowcase_permissions` | `permission_id` | Group permissions; `showcase_id = 0` holds the global group defaults. |
| `myshowcase_moderators` | `moderator_id` | Showcase moderators and their five flags. |
| `myshowcase_access_logs` | `access_log_id` | Password unlocks and failed attempts. |

Conventions in the existing schema that the new columns follow:

* **Primary keys** are one auto-increment `INT UNSIGNED` column named after the row: `attachment_id`,
  `attachment_history_id`, `attachment_share_id`, `field_data_id`, `entry_history_id` (three log tables use
  `log_id`). No table has a multi-column primary key. A pivot table gets a surrogate key plus a unique key on its
  columns, as `myshowcase_attachments_shared` does (`attachment_share_id`, unique
  `session_attachment_id (session_id, attachment_id)`), like MyBB core tables.
* **Timestamps** are `dateline` (created) and `update_dateline` (changed), type `DATETIME`, for example in
  `myshowcase_attachments`, `myshowcase_comments` and the entry tables. The JSON sync columns use `json_synced_at INT`.
  There are no `created_at` / `updated_at` columns.
* **Authors** are stored in `user_id` (`INT UNSIGNED`), never `created_by` or `uid`.
* **Content status** uses `ContentStatuses` (`src/Enum/ContentStatuses.php`): `0` awaiting approval
  (`AwaitingApproval`), `1` visible (`VisibleApproved`), `2` soft deleted (`SoftDeleted`; `IsDraft` is also `2`).
* **Flags** are `TINYINT UNSIGNED` named `is_*` (`is_enabled`, `is_required`), `enable_*` (`enable_search`,
  `enable_slug`) or `display_in_*` (`display_in_main_page`).
* **Group lists** are `VARCHAR(120)` comma-separated group ids, default `''`, `-1` meaning all groups
  (`allowed_groups_fill`, `allowed_groups_view`).
* **Slugs** carry the name of their row: `entry_slug`, `comment_slug`. Parent references name their table:
  `parent_comment_id`.
* **Permission columns** are named `<area>_can_<verb>` (`entries_can_post`, `attachments_can_download`) and are
  declared in three places: the column in `TABLES_DATA['myshowcase_permissions']`, the constant in
  `src/Enum/UserPermissions.php`, and the metadata in `src/Security/PermissionsFactory.php` (plus a parameter of
  `src/Entity/PermissionEntity.php`).
* **Showcase settings** are grouped by a `category` (`general`, `main`, `parser`, `entries`, `comments`,
  `attachments`, `subscriptions`) that also decides the ACP tab; column names start with their category
  (`attachments_thumbnails_width`). The save code lists the categories explicitly
  (`src/Service/AdminManager.php`), so a new category needs code, not only columns.

## How column types are created on each engine

`SchemaManager::columnDefinition()` and `typeSql()` turn a `TABLES_DATA` definition into SQL:

| Definition | MySQL / MariaDB | PostgreSQL | SQLite |
|---|---|---|---|
| `TINYINT` unsigned | `TINYINT UNSIGNED` (0–255) | `smallint` (signed) | `INTEGER` |
| `SMALLINT` unsigned | `SMALLINT UNSIGNED` (0–65535) | `smallint` (signed, **max 32767**) | `INTEGER` |
| `INT` unsigned | `INT UNSIGNED` | `integer` (signed, max 2147483647) | `INTEGER` |
| `INT` (signed) | `INT` | `integer` | `INTEGER` |
| `INT` unsigned, auto-increment, primary key | `INT UNSIGNED NOT NULL AUTO_INCREMENT` + `PRIMARY KEY` | `serial` + `PRIMARY KEY` | `INTEGER PRIMARY KEY` |
| `VARCHAR(n)` | `VARCHAR(n)` | `VARCHAR(n)` | `VARCHAR(n)` (text affinity, length not enforced) |
| `DATETIME` | `DATETIME` | `timestamp` | `DATETIME` |

* `UNSIGNED` is only written on MySQL.
* Every column is `NOT NULL` unless its definition has a `null` key. A nullable column without a `default` is
  created as `NULL` and defaults to NULL on all three engines.
* Integer defaults are written as numbers, other defaults as quoted strings.
* When a `NOT NULL` column without a default is **added** to an existing table, PostgreSQL and SQLite get an empty
  default (`0`, `''` or `'1970-01-01 00:00:00'`). All new columns below have explicit defaults.
* Index names are prefixed with the table name on PostgreSQL and SQLite (hashed above 63 characters). MySQL adds
  indexes with `ALTER TABLE … ADD [UNIQUE] KEY`; PostgreSQL and SQLite use `CREATE [UNIQUE] INDEX`.

## Schema mechanics that affect every group

* **Activation is the upgrade path.** `pluginActivation()` (`admin.php`) adds missing tables and columns
  (`dbVerifyTables(TABLES_DATA, false)`), then runs the "RUN UPDATES" block, then creates indexes including the
  unique ones (`dbVerifyIndexes`). Backfills that must run before a unique index belong in the "RUN UPDATES" block.
* **Unique indexes are skipped when duplicates exist.** `SchemaManager::verifyIndexes()` counts duplicates first and
  shows a warning instead of creating the index; the index is tried again on the next activation.
* **No multi-column primary keys.** `SchemaManager::schemaOf()` keeps one primary-key column (the last one with
  `primary_key`), and `createTableSql()` writes `PRIMARY KEY (<one column>)`. The new tables
  `myshowcase_field_values`, `myshowcase_folder_entries` and `myshowcase_attachment_variants` therefore each get an
  auto-increment surrogate key (`INT UNSIGNED`, `auto_increment`, `primary_key`) and declare their composite
  uniqueness in `unique_keys`, with extra lookups in `keys`. `SchemaManager` needs no change for this.
* **Entry ids are only unique within one showcase.** Each showcase has its own `myshowcase_data{showcase_id}` table
  with its own `entry_id` sequence, and one collection (and its fields) can be used by several showcases. Any table
  that stores an `entry_id` must also identify the showcase, directly or through a row that belongs to one showcase.
* **Attachment ids are unique across showcases.** All showcases share one `myshowcase_attachments` table with one
  auto-increment `attachment_id`; `showcase_id` is an ordinary column of it.
* **JSON exposure is automatic.** `JsonSchema` (`src/Service/JsonSync/JsonSchema.php`) turns every typed,
  non-auto-increment column of `myshowcase_config`, `myshowcase_fields` and `myshowcase_field_data` into an allowed
  JSON key, except the columns in its exclusion lists. `JsonValidator` accepts integers (TINYINT, SMALLINT,
  MEDIUMINT, INT, from 0 to the unsigned maximum of the column type, so widening a column widens the accepted range)
  and VARCHAR/CHAR (up to `max`, else `size`); any other type is rejected. A new column on these three tables is
  therefore a new JSON key unless it is excluded. Other tables are not synced.
* **Group-list columns in JSON.** `JsonSchema::GROUP_COLUMNS` (today `allowed_groups_fill`, `allowed_groups_view`)
  holds the field columns that store group lists, because group ids differ between boards:
  * `JsonValidator` accepts only the string `"all"` for them (error `can only be "all"`);
  * `JsonPlanner::fieldColumns()` stores `"all"` as `-1`;
  * `JsonExporter` writes `"all"` when the stored list contains `-1` and omits the key otherwise;
  * on options (`myshowcase_field_data`) both group columns are excluded from `JsonSchema::optionColumns()`, and an
    option whose group list is empty follows its field.

---

## Group A — Category option metadata

**Status: Approved — not implemented.** Implementing track: **T1** (option metadata), with **T3** (category filter
bar) using `display_in_filter_bar`.

### Purpose

Give dropdown options what a gallery category needs: a URL slug, a parent for subcategories, an image, a
description and an active flag, and let a field show option images and act as a filter bar on the main list.

### Decisions this group follows

* Entries keep storing the `field_data_id` of the chosen option (decision 5a). `value_id` is the stable key used by
  JSON files.
* Hierarchy depth is unlimited in the data; the first UI shows two levels. Filtering by a parent includes all its
  descendants.

### `myshowcase_field_data` — new columns

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| `value_slug` | VARCHAR(100) | NOT NULL | `''` | URL-safe key of the option, unique per field after the backfill. |
| `parent_field_data_id` | INT UNSIGNED | NOT NULL | `0` | `field_data_id` of the parent option in the same field; `0` = top level. |
| `image` | VARCHAR(255) | NOT NULL | `''` | Image path relative to the board root. |
| `description` | VARCHAR(500) | NOT NULL | `''` | Description of the option. |
| `is_enabled` | TINYINT UNSIGNED | NOT NULL | `1` | `0` hides the option from forms and filters; entries that use it keep it. |

Existing columns of the table: `field_data_id` (PK), `collection_id`, `field_id`, `field_key`, `value_id`
(VARCHAR(120), default `0`, marked "todo, drop" in `Core.php`), `value` (VARCHAR(120)), `display_style`
(VARCHAR(200)), `display_order` (SMALLINT UNSIGNED), `allowed_groups_fill`, `allowed_groups_view`.

The names follow the existing conventions: the slug carries the name of the option value (`value_id`, `value`,
`value_slug`), the parent reference names its table (`parent_field_data_id`, like `parent_comment_id`), and the
enable flag is `is_enabled`, as on fields and showcases.

### Indexes

| Index | Columns | Kind | Notes |
|---|---|---|---|
| (new) | `(field_id, value_id)` | UNIQUE | Created only after the backfill. `Core.php` has a commented-out `set_field_value_id` key on `(collection_id, field_id, value_id)`; `field_id` already implies the collection, so the approved key replaces it. |
| (new) | `(field_id, value_slug)` | UNIQUE | Created only after the backfill. |
| (new) | `(field_id, parent_field_data_id)` | INDEX | Children of an option. |

### `myshowcase_fields` — new columns

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| `display_option_image` | TINYINT UNSIGNED | NOT NULL | `0` | Render option images on cards and chips, and use them as category covers. |
| `display_in_filter_bar` | TINYINT UNSIGNED | NOT NULL | `0` | Show the field as a filter bar on the main list. |

Both follow the `display_*` / `display_in_*` naming of field flags (`display_in_main_page`).

### Engine mapping

`VARCHAR(n)` columns are `VARCHAR(n)` everywhere (no length check on SQLite). `INT UNSIGNED` is `INT UNSIGNED` /
`integer` / `INTEGER`; `TINYINT UNSIGNED` is `TINYINT UNSIGNED` / `smallint` / `INTEGER`. The unique index on
`(field_id, value_slug)` is at most 4 + 400 bytes on MySQL utf8mb4, within InnoDB's key limit.

### Migration and backfill

Runs in the "RUN UPDATES" block of activation, before `dbVerifyIndexes`, and changes nothing on a second run:

1. **`value_id`**: per field, read the options ordered by `display_order, field_data_id`. Keep every `value_id` that
   is a positive integer and not yet used in that field; give each empty, `0`, non-numeric or duplicate one the next
   free integer (highest used + 1).
2. **`value_slug`**: per field, in the same order, set an empty slug to the slug of `value` (lower case, URL-safe,
   the plugin's `StringUtil` slug rules); if that is empty use `option-{field_data_id}`; if it is already used in the
   field append `-2`, `-3`, ….
3. `parent_field_data_id`, `image`, `description` and `is_enabled` start at their defaults.
4. `dbVerifyIndexes` then creates the unique indexes. If duplicates remain (for example rows added by hand), the
   index is skipped with a warning and retried on the next activation.

Changing stored `value_id` values is a stored-value change; JSON files reference options by `value_id`.

### JSON sync exposure

* Automatic option keys (integer/VARCHAR): `value_slug`, `image`, `description`, `is_enabled`.
* **Exclude `parent_field_data_id`** from `JsonSchema::optionColumns()`: it holds a board-local `field_data_id` and is
  never exported. A JSON option references its parent through a new key **`parent_value_id`**, holding the parent's
  `value_id` in the same field. The applier resolves it in a second pass, after every option of the field exists,
  and the validator checks that the parent exists in the same field and that there is no cycle. The exporter writes
  `parent_value_id` from the parent row's `value_id`. `parent_value_id` is not a column; it is a JSON schema
  addition of track T1.
* Automatic field keys: `display_option_image`, `display_in_filter_bar`.
* `JsonLock` already locks the option columns a file sets; the new keys are locked the same way.

### Permissions

None.

### Dependencies

None before it. Group B builds on it.

---

## Group B — Multi-select and tags

**Status: Approved — not implemented.** Implementing tracks: **T2** (multi-select storage) and **T2b** (creatable
tags with moderation).

### Purpose

Let a field hold several options (tags), stored so that filtering and counting by option use indexes, and let
permitted groups create new options that are moderated before others see them.

### New table `myshowcase_field_values`

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| `field_value_id` | INT UNSIGNED, auto-increment | NOT NULL | — | Primary key. |
| `showcase_id` | INT UNSIGNED | NOT NULL | — | Showcase of the entry. |
| `entry_id` | INT UNSIGNED | NOT NULL | — | Entry (row of `myshowcase_data{showcase_id}`). |
| `field_id` | INT UNSIGNED | NOT NULL | — | Field. |
| `field_data_id` | INT UNSIGNED | NOT NULL | — | Chosen option. |

| Key | Columns | Kind |
|---|---|---|
| primary | `field_value_id` | PRIMARY KEY |
| (new) | `(showcase_id, entry_id, field_id, field_data_id)` | UNIQUE |
| (new) | `(field_id, field_data_id, showcase_id)` | INDEX |

This table is the source of truth for multi-value fields. The entry column of the field keeps a comma-separated
copy of the `field_data_id` values for display and search only, written in the same transaction.

The unique key starts with `showcase_id` because `entry_id` is only unique within one showcase, and a collection's
fields can serve several showcases: without it, two showcases that share a collection could produce the same
`(entry_id, field_id, field_data_id)` for different entries. The index serves filtering and counting entries by
option within a showcase.

Engine mapping: all five columns are `INT UNSIGNED` / `integer` / `INTEGER`; `field_value_id` is `serial` on
PostgreSQL and `INTEGER PRIMARY KEY` on SQLite.

### `myshowcase_fields` — multiple values reuse `allow_multiple_values`

Multi-select uses the existing column `allow_multiple_values TINYINT UNSIGNED NOT NULL DEFAULT 0`; no new flag is
added. It is editable in the ACP field form and is already a JSON field key. Today select and checkbox fields
ignore it: it is only honoured as the HTML `multiple` attribute of email and file inputs,
`FieldTrait::fieldAcceptsMultipleValues()` returns `false` before reading it, and the entry form rejects several
values for select and checkbox fields. Track T2 must honour it for select and checkbox fields.

### `myshowcase_fields` — new columns

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| `enable_option_create` | TINYINT UNSIGNED | NOT NULL | `0` | Permitted groups may create new options from the entry form. |
| `allowed_groups_create` | VARCHAR(120) | NOT NULL | `''` | Comma-separated usergroup ids allowed to create options; `-1` = all groups. |

Both follow the existing conventions: `enable_*` for field flags, and `VARCHAR(120)` `allowed_groups_*` group lists.

### `myshowcase_field_data` — new columns

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| `status` | TINYINT UNSIGNED | NOT NULL | `1` | `ContentStatuses` value: `0` awaiting approval, `1` visible (approved), `2` soft deleted. |
| `user_id` | INT UNSIGNED | NOT NULL | `0` | Creator; `0` = administrator or JSON file. Lets creators see their own pending tags. |

`status` uses the entry status constants exactly (`ContentStatuses::AwaitingApproval`, `VisibleApproved`,
`SoftDeleted`). A rejected user-created tag becomes soft deleted (`2`) and is purged later by a task. No existing
task does this: the only plugin task, `task_myshowcase()` (`Upload/inc/tasks/myshowcase.php`), prunes entries older
than a showcase's `prune_time`. Purging soft-deleted options is new work of track T2b.

### Migration and backfill

* Existing option rows get `status = 1` and `user_id = 0` (column defaults).
* Fields switched to multiple values: copy each entry's current single `field_data_id` into `myshowcase_field_values`
  (skip empty and `0`), and keep the entry column as the comma-separated copy.
* Existing fields keep `allow_multiple_values` as it is, so nothing changes until an administrator enables it on a
  select or checkbox field.

### JSON sync exposure

* `allow_multiple_values` is already a field key.
* Automatic field key: `enable_option_create`.
* `allowed_groups_create` is added to `JsonSchema::GROUP_COLUMNS` and follows the
  [group-list rule](#schema-mechanics-that-affect-every-group): only `"all"` is accepted, stored as `-1`, and exported
  as `"all"` only when the stored list contains `-1`.
* **Exclude `status` and `user_id`** from `JsonSchema::optionColumns()`: they are board-local.
* User-created (board-local) options are never exported or locked; the exporter skips rows with `user_id > 0`
  or `status <> 1`.
* Deleting options that a JSON file manages is refused in the ACP (decision N11a, track **T0c**).
* `myshowcase_field_values` is not synced.

### Permissions

Creating options is controlled per field by `enable_option_create` and `allowed_groups_create`; new options are
pending unless the user may bypass moderation (`can_bypass_moderation`, an existing permission column).

### Cleanup

Rows of `myshowcase_field_values` must be deleted when their entry, field or option is deleted. **Prerequisite:** the
entry-deleted event is broken today. `EntryDeletedEvent` is only dispatched by inline moderation; its listeners use an
undefined `$this->context`; and pruning, user deletion and showcase deletion delete entries without dispatching it
(`src/Event/Listener/EntryDeleted*Listener.php`, `Plugin/Functions.php`, `Hooks/Shared.php`, `inc/tasks/myshowcase.php`).

### Dependencies

After group A (option slugs and unique `value_id`), and after the entry-deleted event repair.

---

## Group C — Folders

**Status: Approved — not implemented.** Implementing track: **T5** (folders), after **PW1/PW2** (password gate fixes
and scoped gate, group E).

### Purpose

Generic albums: users and moderators group entries of one showcase into folders, with a per-showcase label
("Albums", "Series", …), optional nesting, visibility and an optional password.

**Naming:** the user-facing label is set per showcase. Never call folders "collections"; that term means field sets.

### Decisions this group follows

* Folder passwords reuse the showcase password logic, generalised into a scoped gate (`scope_type` / `scope_id` on
  the access log).
* A folder belongs to one showcase.
* An entry may be in several folders by default (`folders_multiple_membership = 1`).
* Whether folder visibility hides entries outside the folder is **open (decision N12)**: until it is decided, folder
  visibility and passwords gate the folder page and its listing only.

### New table `myshowcase_folders`

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| `folder_id` | INT UNSIGNED, auto-increment | NOT NULL | — | Primary key. |
| `showcase_id` | INT UNSIGNED | NOT NULL | — | Showcase the folder belongs to. |
| `user_id` | INT UNSIGNED | NOT NULL | `0` | Owner; `0` = shared folder managed by moderators. |
| `parent_folder_id` | INT UNSIGNED | NOT NULL | `0` | Parent folder; `0` = top level. |
| `name` | VARCHAR(120) | NOT NULL | — | Name. |
| `folder_slug` | VARCHAR(120) | NOT NULL | — | URL key, unique per showcase and owner. |
| `description` | VARCHAR(500) | NOT NULL | `''` | Description. |
| `cover_entry_id` | INT UNSIGNED | NOT NULL | `0` | Entry used as cover; `0` = automatic. |
| `visibility` | TINYINT UNSIGNED | NOT NULL | `0` | `0` public, `1` members, `2` private, `3` password. |
| `password` | VARCHAR(255) | NOT NULL | `''` | Password hash, same scheme as the showcase password after group E. |
| `display_order` | INT | NOT NULL | `0` | Order among siblings. |
| `entry_count` | INT UNSIGNED | NOT NULL | `0` | Cached number of entries; rebuilt by a tool (the MyBB recount and rebuild page offers hooks for it). |
| `dateline` | DATETIME | NOT NULL | — | Created (existing convention). |
| `update_dateline` | DATETIME | NULL | NULL | Last changed (existing convention). |

| Key | Columns | Kind |
|---|---|---|
| primary | `folder_id` | PRIMARY KEY |
| (new) | `(showcase_id, user_id)` | INDEX |
| (new) | `(showcase_id, parent_folder_id, display_order)` | INDEX |
| (new) | `(showcase_id, user_id, folder_slug)` | UNIQUE |

The slug and parent names follow `entry_slug`, `comment_slug` and `parent_comment_id`. `name` and `folder_slug` have
no default; the code always supplies them.

### New table `myshowcase_folder_entries`

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| `folder_entry_id` | INT UNSIGNED, auto-increment | NOT NULL | — | Primary key. |
| `folder_id` | INT UNSIGNED | NOT NULL | — | Folder. |
| `entry_id` | INT UNSIGNED | NOT NULL | — | Entry of the folder's showcase. |
| `display_order` | INT | NOT NULL | `0` | Order inside the folder. |
| `dateline` | DATETIME | NOT NULL | — | When the entry was added (existing convention). |

| Key | Columns | Kind |
|---|---|---|
| primary | `folder_entry_id` | PRIMARY KEY |
| (new) | `(folder_id, entry_id)` | UNIQUE |
| (new) | `entry_id` | INDEX |

A folder belongs to one showcase, so `(folder_id, entry_id)` is unique. `entry_id` on its own is not: looking up the
folders of an entry must join `myshowcase_folders` on `showcase_id`.

### `myshowcase_config` — new columns

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| `folders_enabled` | TINYINT UNSIGNED | NOT NULL | `0` | Folders are available in this showcase. |
| `folders_label_singular` | VARCHAR(50) | NOT NULL | `''` | Label, for example "Album"; empty = language string. |
| `folders_label_plural` | VARCHAR(50) | NOT NULL | `''` | Plural label; empty = language string. |
| `folders_max_depth` | TINYINT UNSIGNED | NOT NULL | `1` | Levels of nesting allowed. |
| `folders_multiple_membership` | TINYINT UNSIGNED | NOT NULL | `1` | An entry may be in several folders. |
| `folders_max_per_user` | SMALLINT UNSIGNED | NOT NULL | `0` | Folders per user; `0` = unlimited. |

These follow the category-prefix convention with a new category `folders`, which needs a `case` in the showcase save
code and its language strings (see [conventions](#existing-schema-this-builds-on)).

### `myshowcase_permissions` — new columns

All `TINYINT UNSIGNED NOT NULL`.

| Column | Default intent | Meaning |
|---|---|---|
| `folders_can_create` | registered `1`, guests `0` | Create own folders. |
| `folders_can_manage` | `0`; moderators and administrators `1` | Manage shared folders and others' folders. |
| `folders_can_add_others_entries` | `0` | Add entries of other users to one's own folders. |

The names follow the `<area>_can_<verb>` convention of permission columns.

How the defaults work today: the column default (`TABLES_DATA`) fills existing rows when the column is added. Only
groups that have a row (global rows have `showcase_id = 0`) get it; a group without a row resolves every permission
to "no" (`UsersManager::userPermissionsGet()`). Different values per group (registered `1`, guests `0`, moderator and
administrator groups `1`) therefore need an update of the existing global rows during activation, which is a
stored-value change. Showcase moderators (`myshowcase_moderators`) have their own five flags and are not applied by the
permission resolution today. Each column also needs its `UserPermissions` constant, `PermissionsFactory` entry and
`PermissionEntity` parameter.

### `myshowcase_access_logs` — new columns

Existing columns: `access_log_id` (PK), `showcase_id`, `user_id`, `dateline` (DATETIME), `is_valid` (INT UNSIGNED,
default 1), `ipaddress` (VARBINARY(16)).

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| `scope_type` | TINYINT UNSIGNED | NOT NULL | `0` | `0` showcase, `1` folder. |
| `scope_id` | INT UNSIGNED | NOT NULL | `0` | Id of the showcase or folder. |

Index: `(scope_type, scope_id, user_id)`.

Backfill (activation, idempotent): `UPDATE myshowcase_access_logs SET scope_type = 0, scope_id = showcase_id WHERE
scope_id = 0`.

### Engine mapping

`INT` / `INT UNSIGNED` / `TINYINT UNSIGNED` / `SMALLINT UNSIGNED` / `VARCHAR` / `DATETIME` as in the
[type table](#how-column-types-are-created-on-each-engine). `folder_id` and `folder_entry_id` are `serial` on
PostgreSQL and `INTEGER PRIMARY KEY` on SQLite.

### JSON sync exposure

* Automatic showcase keys: all six `folders_*` columns (integer or VARCHAR, portable values).
* Folders, folder entries, permissions and access logs are not synced. Counter and runtime columns such as
  `myshowcase_folders.entry_count` are never exported.

### Dependencies

After group E and the scoped password gate; after the entry-deleted event repair (folder entries must be removed when
an entry is deleted, and `entry_count` adjusted).

---

## Group D — Image pipeline and attachment variants

**Status: Approved — not implemented.** Implementing track: **T6** (image pipeline).

### Purpose

Keep every uploaded file as the private original and serve generated copies: thumbnails, a medium size and a display
copy that is stripped of metadata (EXIF, GPS) and optionally watermarked.

### Design

* **Type-agnostic.** Generation goes through a generator registry keyed by MIME type.
  * Version 1 ships one generator for raster images (JPEG, PNG, GIF, WebP) using GD, and Imagick when it is
    available.
  * SVG gets no variants and is never resized (blocked, or download-only, by default).
  * Other types get no variant rows. Future generators (for example the first page of a PDF or a video poster frame)
    need no schema change.
* **Display fallback:** display → medium → thumb → file-type icon.
* **Deferred generation:** generation may run in a MyBB task (`status` pending → ready or failed).
* **The attachment row stays the original.** There is no "original" variant row. Originals are private and served
  through a permission-checked endpoint.

### New table `myshowcase_attachment_variants`

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| `variant_id` | INT UNSIGNED, auto-increment | NOT NULL | — | Primary key. |
| `attachment_id` | INT UNSIGNED | NOT NULL | — | Original attachment. |
| `variant` | VARCHAR(20) | NOT NULL | — | `thumb`, `medium`, `display`. |
| `file_name` | VARCHAR(255) | NOT NULL | `''` | Stored file of the variant. |
| `width` | INT UNSIGNED | NULL | NULL | Width in pixels; NULL until generated or when not applicable. |
| `height` | INT UNSIGNED | NULL | NULL | Height in pixels. |
| `file_size` | INT UNSIGNED | NOT NULL | `0` | Bytes. |
| `mime_type` | VARCHAR(100) | NOT NULL | `''` | MIME type of the variant. |
| `status` | TINYINT UNSIGNED | NOT NULL | `0` | `0` pending, `1` ready, `2` failed. |
| `dateline` | DATETIME | NOT NULL | — | When the variant was written (existing convention). |

| Key | Columns | Kind |
|---|---|---|
| primary | `variant_id` | PRIMARY KEY |
| (new) | `(attachment_id, variant)` | UNIQUE |
| (new) | `status` | INDEX |

The unique key needs no `showcase_id`: attachment ids are unique across showcases (one `myshowcase_attachments`
table with one auto-increment `attachment_id`). `variant_id` is `serial` on PostgreSQL and `INTEGER PRIMARY KEY` on
SQLite.

**Nullable `width` / `height`.** A definition with a `null` key and no `default` is created as a nullable column
defaulting to NULL on MySQL, PostgreSQL and SQLite (`SchemaManager::columnDefinition()`); the column-change planner
compares nullability as well. The JSON validator is not involved: this table is not synced. Code that reads these
columns must accept NULL (the plugin's cache layer skips NULL values, `CacheManager::update(Config)` does so for
`attachments_watermark_file`).

### `myshowcase_config` — new columns

All in the existing `attachments` category.

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| `attachments_medium_width` | SMALLINT UNSIGNED | NOT NULL | `1280` (suggested) | Medium box width. |
| `attachments_medium_height` | SMALLINT UNSIGNED | NOT NULL | `1280` (suggested) | Medium box height. |
| `attachments_strip_metadata` | TINYINT UNSIGNED | NOT NULL | `1` | Strip EXIF/GPS from generated copies. |
| `attachments_watermark_enable` | TINYINT UNSIGNED | NOT NULL | `0` | Watermark the display copy. |
| `attachments_watermark_opacity` | TINYINT UNSIGNED | NOT NULL | `50` | Opacity, 0–100. |

### `myshowcase_config` — changed existing columns

| Column | Today | After | Meaning |
|---|---|---|---|
| `attachments_thumbnails_width` | TINYINT UNSIGNED, default 200, ACP `max` 255 | SMALLINT UNSIGNED, default 200 | Thumbnail box width; the 255 px cap goes away. |
| `attachments_thumbnails_height` | TINYINT UNSIGNED, default 200, ACP `max` 255 | SMALLINT UNSIGNED, default 200 | Thumbnail box height. |
| `attachments_watermark_file` | VARCHAR(50), nullable, default `''` | VARCHAR(255), nullable, default `''` | Watermark image path relative to the board root. |

Kept unchanged: `attachments_watermark_location` (TINYINT UNSIGNED, default 1) is the watermark position. Its values
are `WatermarkLocations` (`AttachmentsManager::WATERMARK_LOCATION_*`), placed by `AttachmentsManager` with a 5 px
margin:

| Value | Constant | Position |
|---|---|---|
| `1` | `LowerLeft` | Bottom left corner. |
| `2` | `LowerRight` | Bottom right corner. |
| `3` | `Center` | Centred horizontally and vertically. |
| `4` | `UpperLeft` | Top left corner. |
| `5` | `UpperRight` | Top right corner. |

Activation sets any other stored value to `0` (`admin.php`, "RUN UPDATES"), which matches no position. Today the
upload code watermarks the stored original itself, when the upload asks for it, the uploader's group has
`attachments_watermark_images` and the watermark file exists; with this group the watermark moves to the display
copy.

Sizes: SMALLINT is signed on PostgreSQL (max 32767), while the JSON validator takes its range from the column type
and accepts up to 65535 for SMALLINT. Give the size columns (the widened thumbnail columns and the medium sizes) an
ACP form maximum well below 32767, for example 10000; the form `max` does not limit JSON values.

**Migration on each engine.** Widening is a type change that `SchemaManager` applies during activation:

* MySQL / MariaDB: `ALTER TABLE … MODIFY` to `SMALLINT UNSIGNED` and `VARCHAR(255)`.
* PostgreSQL: TINYINT and SMALLINT are both created as `smallint`, so the thumbnail columns need no change;
  `attachments_watermark_file` gets `ALTER COLUMN … TYPE VARCHAR(255)`.
* SQLite: both integer types are `INTEGER`, so the thumbnail columns need no change; the declared `VARCHAR(255)` is
  applied by the table rebuild (`sqliteRebuildStatements()`), and the length is not enforced either way.

Existing values are kept: thumbnail sizes are at most 255, watermark paths at most 50 characters. The JSON
validator's accepted range for the thumbnail columns widens from 0–255 to 0–65535 automatically.

### `myshowcase_permissions` — new column

| Column | Type | Default intent | Meaning |
|---|---|---|---|
| `attachments_can_download_original` | TINYINT UNSIGNED NOT NULL | `0` for guests and registered, `1` for moderators and administrators | Download the private original instead of the display copy. |

The name follows the existing `attachments_can_download`. Per-group defaults need the same activation update as the
folder permissions (see group C).

### Migration and backfill

* No existing data changes. Existing attachments get variant rows when the rebuild tool or the task generates them;
  today's `thumbnail_name` keeps working until then.
* The widened thumbnail and watermark-file columns keep their values.

### JSON sync exposure

* Automatic showcase keys: `attachments_medium_width`, `attachments_medium_height`, `attachments_strip_metadata`,
  `attachments_watermark_enable`, `attachments_watermark_opacity`.
* `attachments_thumbnails_width`, `attachments_thumbnails_height` and `attachments_watermark_location` are already
  showcase keys.
* `attachments_watermark_file` stays excluded (`JsonSchema::SHOWCASE_EXCLUDED`): it is a board path.
* `myshowcase_attachment_variants` is not synced.

### Dependencies

Independent of A, B, C and E. Needs the entry-deleted event repair so that variants and files are removed with their
attachment.

---

## Group E — Password hardening

**Status: Approved — not implemented**, except the width of `myshowcase_config.password`: **Already satisfied by the
existing schema**. Implementing track: **PW1** (password gate fixes), followed by **PW2** (scoped gate used by
group C).

### Purpose

Store showcase (and later folder) passwords as hashes, revoke unlocks when a password changes, and give guests their
own unlocks.

### `myshowcase_config.password`

* **Column width: Already satisfied by the existing schema.** The column is already
  `VARCHAR(255) NOT NULL DEFAULT ''` (`Core.php`), so no widening is done; a `password_hash()` value fits.
* The ACP shows set / change / clear and never the value. Today it is a plain text box that shows the stored value.
* Existing plain-text passwords are hashed during activation. **Idempotency:** a value is treated as already hashed
  when `password_get_info($value)['algo']` is not null (bcrypt `$2y$…`, Argon2 `$argon2…`); only other non-empty
  values are hashed. Running activation again changes nothing.
* JSON already excludes `password` (`JsonSchema::SHOWCASE_EXCLUDED`).

### `myshowcase_access_logs` — new columns

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| `password_fingerprint` | VARCHAR(64) | NOT NULL | `''` | Fingerprint of the stored password hash at unlock time (for example a SHA-256 hex digest); a changed password no longer matches, which revokes the unlock. |
| `session_id` | VARCHAR(32) | NOT NULL | `''` | MyBB session id of the visitor, so a guest's unlock is not shared with all guests. Matches `sessions.sid VARCHAR(32)`. |

Existing rows keep empty values and no longer match after the upgrade, so visitors enter the password again once.

### Related open decisions

* **N6** — brute-force limiting (count failed attempts per user or IP in a time window).
* **N7** — CSRF token on the unlock form.
* **N8** — type of the unlock timestamp: `myshowcase_access_logs.dateline` is `DATETIME`, but the gate writes and
  compares a Unix timestamp.

### JSON sync exposure

None (`myshowcase_access_logs` is not synced; `password` is excluded).

### Dependencies

Before group C reuses the gate.

---

## Template sets

**No schema change.**

* `myshowcase_config.custom_template_prefix` (VARCHAR(50), default `''`) selects a folder under
  `Upload/inc/plugins/myshowcase/view/templates/`.
* `RenderManager::templateGet()` renders `<prefix>/<name>.twig` when it exists and the global
  `@ext.myshowcase/<name>.twig` otherwise. Templates included from another template always come from the global set.
* The column is excluded from JSON files (`JsonSchema::SHOWCASE_EXCLUDED`).
* The ACP chooses the set from a dropdown (`FormTypes::SelectTemplateSet`, delivered in track G1), both on the
  General tab of a showcase and when a showcase is created from a JSON file:
  * The options are "Global templates (none)" (the empty value) and every folder of `view/templates/` whose name is
    already a normalized template prefix, lower case letters and digits (`ShowcaseService::templateSets()`).
  * A stored prefix whose folder was removed stays selected and is listed as "<name> (missing)"
    (`SelectableLists::templateSetsList()`); the showcase renders with the global templates and saving keeps it.
  * `ShowcaseService::validate()` refuses a new value that is not one of the folders
    (`myshowcase_admin_error_invalid_template_set`) and a set another showcase already uses.

## Implementation order and dependencies

| Order | Work | Track | Needs |
|---|---|---|---|
| 1 | Repair the entry-deleted event (dispatch on every delete path, fix the listeners) — **done** (track G2: `EntriesManager::deleteEntry()` dispatches `EntryDeletedEvent` with the showcase, entry and author ids; the listeners are registered by `Showcase`) | prerequisite | — |
| 2 | Group E — password hardening | PW1 | — |
| 3 | Scoped password gate (`scope_type` / `scope_id`) | PW2 | E |
| 4 | Group A — option metadata | T1 | — |
| 5 | Category filter bar (`display_in_filter_bar`) | T3 | A |
| 6 | Group B — multi-select and tags | T2, T2b | A, entry-deleted repair, T0c guard for JSON-managed options |
| 7 | Group C — folders | T5 | E, PW2, entry-deleted repair |
| 8 | Group D — image pipeline | T6 | entry-deleted repair (independent of A, B, C, E) |

## Change log

| Date | Group | Change | Status | Approved by |
|---|---|---|---|---|
| 2026-11-11 | A | `myshowcase_field_data`: `value_slug`, `parent_field_data_id`, `image`, `description`, `is_enabled`; unique `(field_id, value_id)` and `(field_id, value_slug)` after backfill; index `(field_id, parent_field_data_id)` | Approved — not implemented | Uriel |
| 2026-11-11 | A | `myshowcase_fields`: `display_option_image`, `display_in_filter_bar` | Approved — not implemented | Uriel |
| 2026-11-11 | B | New table `myshowcase_field_values` | Approved — not implemented | Uriel |
| 2026-11-11 | B | `myshowcase_fields`: `enable_option_create`, `allowed_groups_create`; multiple values reuse the existing `allow_multiple_values` | Approved — not implemented | Uriel |
| 2026-11-11 | B | `myshowcase_field_data`: `status` | Approved — not implemented | Uriel |
| 2026-11-11 | B | `myshowcase_field_data`: `user_id` | Approved — not implemented | Uriel |
| 2026-11-11 | C | New tables `myshowcase_folders`, `myshowcase_folder_entries` | Approved — not implemented | Uriel |
| 2026-11-11 | C | `myshowcase_config`: `folders_enabled`, `folders_label_singular`, `folders_label_plural`, `folders_max_depth`, `folders_multiple_membership`, `folders_max_per_user` | Approved — not implemented | Uriel |
| 2026-11-11 | C | `myshowcase_permissions`: `folders_can_create`, `folders_can_manage`, `folders_can_add_others_entries` | Approved — not implemented | Uriel |
| 2026-11-11 | C | `myshowcase_access_logs`: `scope_type`, `scope_id`, index `(scope_type, scope_id, user_id)` | Approved — not implemented | Uriel |
| 2026-11-11 | D | New table `myshowcase_attachment_variants` | Approved — not implemented | Uriel |
| 2026-11-11 | D | `myshowcase_config`: `attachments_medium_width`, `attachments_medium_height`, `attachments_strip_metadata`, `attachments_watermark_enable`, `attachments_watermark_opacity`; widen `attachments_thumbnails_width` and `attachments_thumbnails_height` to SMALLINT UNSIGNED; position stays `attachments_watermark_location` | Approved — not implemented | Uriel |
| 2026-11-11 | D | `myshowcase_config`: widen `attachments_watermark_file` to VARCHAR(255) | Approved — not implemented | Uriel |
| 2026-11-11 | D | `myshowcase_permissions`: `attachments_can_download_original` | Approved — not implemented | Uriel |
| 2026-11-11 | E | `myshowcase_config.password` is wide enough for a `password_hash()` value (already VARCHAR(255)) | Already satisfied by the existing schema | Uriel |
| 2026-11-11 | E | Hash existing plain-text `myshowcase_config.password` values on activation | Approved — not implemented | Uriel |
| 2026-11-11 | E | `myshowcase_access_logs`: `password_fingerprint`, `session_id` | Approved — not implemented | Uriel |
| 2026-11-11 | A–D | S1 naming: `slug` → `value_slug`, `parent_id` → `parent_field_data_id`, `is_active` → `is_enabled`, `created_by` → `user_id` (field data); `option_image_display` → `display_option_image`, `filter_display` → `display_in_filter_bar`, `creatable` → `enable_option_create`, `creatable_groups` → `allowed_groups_create VARCHAR(120)` (fields); `slug` → `folder_slug`, `parent_id` → `parent_folder_id` (folders); `can_create_folders` → `folders_can_create`, `can_manage_folders` → `folders_can_manage`, `can_add_others_entries` → `folders_can_add_others_entries`, `can_download_original` → `attachments_can_download_original` (permissions); `image_medium_width/height` → `attachments_medium_width/height`, `image_strip_metadata` → `attachments_strip_metadata`, `image_watermark` → `attachments_watermark_enable`, `image_watermark_opacity` → `attachments_watermark_opacity` (config); timestamps are `dateline` / `update_dateline`, authors `user_id` | Approved — not implemented | Uriel |
| 2026-11-11 | B, D, E | S2 reuse: multi-select uses `allow_multiple_values` (no `allow_multiple`); `password` already VARCHAR(255); widen `attachments_thumbnails_width/height` to SMALLINT UNSIGNED (no `image_thumb_*`); widen `attachments_watermark_file` to VARCHAR(255) (no `image_watermark_file`); keep `attachments_watermark_location` (no `image_watermark_position`) | Approved — not implemented | Uriel |
| 2026-11-11 | B, C, D | S3 keys: no multi-column primary keys; surrogate keys `field_value_id`, `folder_entry_id`, `variant_id` with unique `(showcase_id, entry_id, field_id, field_data_id)`, `(folder_id, entry_id)`, `(attachment_id, variant)`; indexes `(field_id, field_data_id, showcase_id)`, `entry_id`, `status` | Approved — not implemented | Uriel |
| 2026-11-11 | B | S4: the `myshowcase_field_values` unique key includes `showcase_id` (entry ids are unique only per showcase) | Approved — not implemented | Uriel |
| 2026-11-11 | B | S5: `myshowcase_field_data.status` uses `ContentStatuses` (`0` awaiting approval, `1` visible, `2` soft deleted); rejected tags are soft deleted and purged by a new task in T2b | Approved — not implemented | Uriel |
| 2026-11-11 | A, B, C, D | S6 JSON: options reference their parent through the new key `parent_value_id` (T1), `parent_field_data_id` is never exported; `allowed_groups_create` joins `JsonSchema::GROUP_COLUMNS`; excluded: `myshowcase_field_data.status`, `myshowcase_field_data.user_id`, `attachments_watermark_file`, counter and runtime columns such as `myshowcase_folders.entry_count` | Approved — not implemented | Uriel |
| 2026-11-11 | B, D | Approvals: `myshowcase_field_data.user_id` (formerly `created_by`); the watermark file, carried out as widening `attachments_watermark_file` (formerly `image_watermark_file`) | Approved — not implemented | Uriel |
