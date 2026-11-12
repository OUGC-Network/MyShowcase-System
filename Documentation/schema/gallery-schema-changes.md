# Gallery schema changes

This document describes the database changes planned for gallery-type showcases: category option metadata,
multi-select tags, folders (albums), image variants and password hardening. Nothing here is implemented yet; the
status of every item is given in its group and in the [change log](#change-log).

The tables are defined in `Upload/inc/plugins/myshowcase/Plugin/Core.php` (`TABLES_DATA`) and created or altered by
`Upload/inc/plugins/myshowcase/src/Service/SchemaManager.php` when the plugin is activated. How JSON files describe
collections and showcases is documented in [`Upload/inc/plugins/myshowcase/imports/README.md`](../../Upload/inc/plugins/myshowcase/imports/README.md).

Status values used here:

* **Approved — not implemented**: agreed, waiting for its implementation track.
* **Proposed — pending approval**: suggested, not agreed yet.

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

* **Timestamps** are `dateline` (created) and `update_dateline` (changed), type `DATETIME`, for example in
  `myshowcase_attachments`, `myshowcase_comments` and the entry tables. The JSON sync columns use `json_synced_at INT`.
  There are no `created_at` / `updated_at` columns.
* **Authors** are stored in `user_id` (`INT UNSIGNED`), never `created_by` or `uid`.
* **Content status** uses `ContentStatuses` (`src/Enum/ContentStatuses.php`): `0` awaiting approval, `1` visible,
  `2` soft deleted.
* **Flags** are `TINYINT UNSIGNED` named `is_*` (`is_enabled`, `is_required`), `enable_*` (`enable_search`,
  `enable_slug`) or `display_in_*` (`display_in_main_page`).
* **Group lists** are `VARCHAR(120)` comma-separated group ids, `-1` meaning all groups (`allowed_groups_fill`,
  `allowed_groups_view`).
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
* **Composite primary keys are not supported by `TABLES_DATA` today.** `SchemaManager::schemaOf()` keeps one
  primary-key column (the last one with `primary_key`), and `createTableSql()` writes `PRIMARY KEY (<one column>)`.
  The approved tables `myshowcase_field_values`, `myshowcase_folder_entries` and `myshowcase_attachment_variants` use
  composite primary keys, so their implementing track must either:
  1. teach `SchemaManager` a table-level composite primary key (the SQLite rebuild path,
     `sqliteRebuildStatements()`, already preserves composite primary keys it finds), or
  2. use an auto-increment surrogate key plus a `unique_keys` entry on the composite columns.
  This document keeps the approved composite keys; the choice is an implementation decision of each track.
* **Entry ids are only unique within one showcase.** Each showcase has its own `myshowcase_data{showcase_id}` table
  with its own `entry_id` sequence, and one collection (and its fields) can be used by several showcases. Any table
  that stores an `entry_id` must also identify the showcase, directly or through a row that belongs to one showcase.
* **JSON exposure is automatic.** `JsonSchema` (`src/Service/JsonSync/JsonSchema.php`) turns every typed,
  non-auto-increment column of `myshowcase_config`, `myshowcase_fields` and `myshowcase_field_data` into an allowed
  JSON key, except the columns in its exclusion lists. `JsonValidator` accepts integers (TINYINT, SMALLINT,
  MEDIUMINT, INT, from 0 to the unsigned maximum) and VARCHAR/CHAR (up to `max`, else `size`); any other type is
  rejected. Group columns (`JsonSchema::GROUP_COLUMNS`) only accept `"all"`. A new column on these three tables is
  therefore a new JSON key unless it is excluded. Other tables are not synced.

---

## Group A — Category option metadata

**Status: Approved — not implemented.** Implementing track: **T1** (option metadata), with **T3** (category filter
bar) using `filter_display`.

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
| `slug` | VARCHAR(100) | NOT NULL | `''` | URL-safe key of the option, unique per field after the backfill. |
| `parent_id` | INT UNSIGNED | NOT NULL | `0` | `field_data_id` of the parent option in the same field; `0` = top level. |
| `image` | VARCHAR(255) | NOT NULL | `''` | Image path relative to the board root. |
| `description` | VARCHAR(500) | NOT NULL | `''` | Description of the option. |
| `is_active` | TINYINT UNSIGNED | NOT NULL | `1` | `0` hides the option from forms and filters; entries that use it keep it. |

Existing columns of the table: `field_data_id` (PK), `collection_id`, `field_id`, `field_key`, `value_id`
(VARCHAR(120), default `0`, marked "todo, drop" in `Core.php`), `value` (VARCHAR(120)), `display_style`
(VARCHAR(200)), `display_order` (SMALLINT UNSIGNED), `allowed_groups_fill`, `allowed_groups_view`.

> **Naming note — `slug`.** Slugs elsewhere carry their row name (`entry_slug`, `comment_slug`), and this table
> names its columns after the option value (`value_id`, `value`). Convention-aligned alternative: `value_slug`.
>
> **Naming note — `parent_id`.** The existing parent column is `parent_comment_id`. Convention-aligned alternative:
> `parent_field_data_id`.
>
> **Naming note — `is_active`.** Existing enable flags are named `is_enabled` (fields, showcases).
> Convention-aligned alternative: `is_enabled`.

### Indexes

| Index | Columns | Kind | Notes |
|---|---|---|---|
| (new) | `(field_id, value_id)` | UNIQUE | Created only after the backfill. `Core.php` has a commented-out `set_field_value_id` key on `(collection_id, field_id, value_id)`; `field_id` already implies the collection, so the approved key replaces it. |
| (new) | `(field_id, slug)` | UNIQUE | Created only after the backfill. |
| (new) | `(field_id, parent_id)` | INDEX | Children of an option. |

### `myshowcase_fields` — new columns

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| `option_image_display` | TINYINT UNSIGNED | NOT NULL | `0` | Render option images on cards and chips, and use them as category covers. |
| `filter_display` | TINYINT UNSIGNED | NOT NULL | `0` | Show the field as a filter bar on the main list. |

> **Naming note.** Field flags are named `display_in_*` (`display_in_main_page`) or `enable_*` (`enable_search`).
> Convention-aligned alternatives: `display_option_image` and `display_in_filter_bar`.

### Engine mapping

`VARCHAR(n)` columns are `VARCHAR(n)` everywhere (no length check on SQLite). `INT UNSIGNED` is `INT UNSIGNED` /
`integer` / `INTEGER`; `TINYINT UNSIGNED` is `TINYINT UNSIGNED` / `smallint` / `INTEGER`. The unique index on
`(field_id, slug)` is at most 4 + 400 bytes on MySQL utf8mb4, within InnoDB's key limit.

### Migration and backfill

Runs in the "RUN UPDATES" block of activation, before `dbVerifyIndexes`, and changes nothing on a second run:

1. **`value_id`**: per field, read the options ordered by `display_order, field_data_id`. Keep every `value_id` that
   is a positive integer and not yet used in that field; give each empty, `0`, non-numeric or duplicate one the next
   free integer (highest used + 1).
2. **`slug`**: per field, in the same order, set an empty slug to the slug of `value` (lower case, URL-safe, the
   plugin's `StringUtil` slug rules); if that is empty use `option-{field_data_id}`; if it is already used in the
   field append `-2`, `-3`, ….
3. `parent_id`, `image`, `description` and `is_active` start at their defaults.
4. `dbVerifyIndexes` then creates the unique indexes. If duplicates remain (for example rows added by hand), the
   index is skipped with a warning and retried on the next activation.

Changing stored `value_id` values is a stored-value change; JSON files reference options by `value_id`.

### JSON sync exposure

* Automatic option keys (integer/VARCHAR): `slug`, `image`, `description`, `is_active`.
* **Exclude `parent_id`** from `JsonSchema::optionColumns()`: it holds a board-local `field_data_id`. A JSON file
  needs a parent reference by `value_id` instead (for example a `parent` key holding the parent's `value_id`),
  resolved by the applier in a second pass, with validation that the parent exists in the same field and that there
  is no cycle. That key is a JSON schema addition of track T1.
* Automatic field keys: `option_image_display`, `filter_display`.
* `JsonLock` already locks the option columns a file sets; the new keys are locked the same way.

### Permissions

None.

### Dependencies

None before it. Group B builds on it.

---

## Group B — Multi-select and tags

**Status: Approved — not implemented**, except `myshowcase_field_data.created_by`: **Proposed — pending approval**.
Implementing tracks: **T2** (multi-select storage) and **T2b** (creatable tags with moderation).

### Purpose

Let a field hold several options (tags), stored so that filtering and counting by option use indexes, and let
permitted groups create new options that are moderated before others see them.

### New table `myshowcase_field_values`

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| `entry_id` | INT UNSIGNED | NOT NULL | — | Entry (row of `myshowcase_data{showcase_id}`). |
| `field_id` | INT UNSIGNED | NOT NULL | — | Field. |
| `field_data_id` | INT UNSIGNED | NOT NULL | — | Chosen option. |
| `showcase_id` | INT UNSIGNED | NOT NULL | — | Showcase of the entry. |

| Key | Columns | Kind |
|---|---|---|
| primary | `(entry_id, field_id, field_data_id)` | PRIMARY KEY (composite; see [mechanics](#schema-mechanics-that-affect-every-group)) |
| (new) | `(field_id, field_data_id)` | INDEX |
| (new) | `(showcase_id, field_data_id)` | INDEX |

This table is the source of truth for multi-value fields. The entry column of the field keeps a comma-separated
copy of the `field_data_id` values for display and search only, written in the same transaction.

> **Implementation note — key collision.** `entry_id` is only unique within one showcase, and a collection's
> fields can be shared by several showcases. Two showcases that share a collection can therefore produce the same
> `(entry_id, field_id, field_data_id)` for different entries. Convention-aligned alternative: put `showcase_id` in
> the primary key, `(showcase_id, entry_id, field_id, field_data_id)`.

Engine mapping: all four columns are `INT UNSIGNED` / `integer` / `INTEGER`.

### `myshowcase_fields` — new columns

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| `allow_multiple` | TINYINT UNSIGNED | NOT NULL | `0` | Only if no usable existing flag exists — **see the finding below**. |
| `creatable` | TINYINT UNSIGNED | NOT NULL | `0` | Permitted groups may create new options from the entry form. |
| `creatable_groups` | VARCHAR(255) | NOT NULL | `''` | Comma-separated usergroup ids allowed to create options. |

> **Existing flag — `allow_multiple_values`.** `myshowcase_fields` already has
> `allow_multiple_values TINYINT UNSIGNED NOT NULL DEFAULT 0`. It is editable in the ACP field form and is a JSON
> field key. Today it is only honoured as the HTML `multiple` attribute of email and file inputs:
> `FieldTrait::fieldAcceptsMultipleValues()` returns `false` before reading it, and the entry form rejects several
> values for select and checkbox fields. Since the approval of `allow_multiple` was conditional on there being no
> usable existing flag, the implementing track should reuse `allow_multiple_values` for select and checkbox fields
> and not create `allow_multiple`.
>
> **Naming note — `creatable`.** Field flags are named `enable_*`. Convention-aligned alternative:
> `enable_option_create`.
>
> **Naming note — `creatable_groups`.** Group lists are `VARCHAR(120)` named `allowed_groups_*`, with `-1` meaning
> all groups. Convention-aligned alternative: `allowed_groups_create VARCHAR(120)`.

### `myshowcase_field_data` — new columns

| Column | Type | Null | Default | Status | Meaning |
|---|---|---|---|---|---|
| `status` | TINYINT UNSIGNED | NOT NULL | `1` | Approved — not implemented | `1` approved, `0` pending moderation, `2` rejected. |
| `created_by` | INT UNSIGNED | NOT NULL | `0` | **Proposed — pending approval** | uid of the creator; `0` = admin or JSON file. Needed so creators can see their own pending tags. |

> **Naming note — `status`.** `ContentStatuses` uses `0` awaiting approval and `1` visible, like this column, but
> `2` means soft deleted there, not rejected. Keep the meanings separate in code, or use `ContentStatuses` values.
>
> **Naming note — `created_by`.** Authors are stored in `user_id` everywhere else. Convention-aligned alternative:
> `user_id`.

### Migration and backfill

* Existing option rows get `status = 1` and `created_by = 0` (column defaults).
* Fields switched to multiple values: copy each entry's current single `field_data_id` into `myshowcase_field_values`
  (skip empty and `0`), and keep the entry column as the comma-separated copy.
* Existing fields keep `allow_multiple_values` as it is, so nothing changes until an administrator enables it on a
  select or checkbox field.

### JSON sync exposure

* `allow_multiple_values` is already a field key.
* Automatic field key: `creatable`. **`creatable_groups` must be excluded** or added to
  `JsonSchema::GROUP_COLUMNS` (group ids differ between boards; group columns only accept `"all"`).
* **Exclude `status` and `created_by`** from `JsonSchema::optionColumns()`: they are board-local.
* User-created (board-local) options are never exported or locked; the exporter skips rows with `created_by > 0`
  or `status <> 1`.
* Deleting options that a JSON file manages is refused in the ACP (decision N11a, track **T0c**).
* `myshowcase_field_values` is not synced.

### Permissions

Creating options is controlled per field by `creatable` and `creatable_groups`; new options are pending unless the
user may bypass moderation (`can_bypass_moderation`, an existing permission column).

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
| `parent_id` | INT UNSIGNED | NOT NULL | `0` | Parent folder; `0` = top level. |
| `name` | VARCHAR(120) | NOT NULL | — | Name. |
| `slug` | VARCHAR(120) | NOT NULL | — | URL key, unique per showcase and owner. |
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
| (new) | `(showcase_id, parent_id, display_order)` | INDEX |
| (new) | `(showcase_id, user_id, slug)` | UNIQUE |

> **Naming note — `slug`, `parent_id`.** Existing names are `entry_slug`, `comment_slug` and `parent_comment_id`.
> Convention-aligned alternatives: `folder_slug` and `parent_folder_id`.

`name` and `slug` have no default; the code always supplies them.

### New table `myshowcase_folder_entries`

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| `folder_id` | INT UNSIGNED | NOT NULL | — | Folder. |
| `entry_id` | INT UNSIGNED | NOT NULL | — | Entry of the folder's showcase. |
| `display_order` | INT | NOT NULL | `0` | Order inside the folder. |
| `dateline` | DATETIME | NOT NULL | — | When the entry was added (existing convention). |

| Key | Columns | Kind |
|---|---|---|
| primary | `(folder_id, entry_id)` | PRIMARY KEY (composite; see [mechanics](#schema-mechanics-that-affect-every-group)) |
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
| `can_create_folders` | registered `1`, guests `0` | Create own folders. |
| `can_manage_folders` | `0`; moderators and administrators `1` | Manage shared folders and others' folders. |
| `can_add_others_entries` | `0` | Add entries of other users to one's own folders. |

> **Naming note.** Permission columns are named `<area>_can_<verb>`. Convention-aligned alternatives:
> `folders_can_create`, `folders_can_manage`, `folders_can_add_others_entries`.

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
[type table](#how-column-types-are-created-on-each-engine). `folder_id` is `serial` on PostgreSQL and
`INTEGER PRIMARY KEY` on SQLite.

### JSON sync exposure

* Automatic showcase keys: all six `folders_*` columns (integer or VARCHAR, portable values).
* Folders, folder entries, permissions and access logs are not synced.

### Dependencies

After group E and the scoped password gate; after the entry-deleted event repair (folder entries must be removed when
an entry is deleted, and `entry_count` adjusted).

---

## Group D — Image pipeline and attachment variants

**Status: Approved — not implemented**, except `myshowcase_config.image_watermark_file`: **Proposed — pending
approval**. Implementing track: **T6** (image pipeline).

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
| primary | `(attachment_id, variant)` | PRIMARY KEY (composite; see [mechanics](#schema-mechanics-that-affect-every-group)) |
| (new) | `status` | INDEX |

**Nullable `width` / `height`.** A definition with a `null` key and no `default` is created as a nullable column
defaulting to NULL on MySQL, PostgreSQL and SQLite (`SchemaManager::columnDefinition()`); the column-change planner
compares nullability as well. The JSON validator is not involved: this table is not synced. Code that reads these
columns must accept NULL (the plugin's cache layer skips NULL values, `CacheManager::update(Config)` does so for
`attachments_watermark_file`).

### `myshowcase_config` — new columns

| Column | Type | Null | Default | Status | Meaning |
|---|---|---|---|---|---|
| `image_thumb_width` | SMALLINT UNSIGNED | NOT NULL | `320` (suggested) | Approved — not implemented | Thumbnail box width. |
| `image_thumb_height` | SMALLINT UNSIGNED | NOT NULL | `320` (suggested) | Approved — not implemented | Thumbnail box height. |
| `image_medium_width` | SMALLINT UNSIGNED | NOT NULL | `1280` (suggested) | Approved — not implemented | Medium box width. |
| `image_medium_height` | SMALLINT UNSIGNED | NOT NULL | `1280` (suggested) | Approved — not implemented | Medium box height. |
| `image_strip_metadata` | TINYINT UNSIGNED | NOT NULL | `1` | Approved — not implemented | Strip EXIF/GPS from generated copies. |
| `image_watermark` | TINYINT UNSIGNED | NOT NULL | `0` | Approved — not implemented | Watermark the display copy. |
| `image_watermark_position` | VARCHAR(20) | NOT NULL | `'bottom-right'` | Approved — not implemented | Watermark position. |
| `image_watermark_opacity` | TINYINT UNSIGNED | NOT NULL | `50` | Approved — not implemented | Opacity, 0–100. |
| `image_watermark_file` | VARCHAR(255) | NOT NULL | `''` | **Proposed — pending approval** | Watermark image path relative to the board root. |

Sizes: SMALLINT is signed on PostgreSQL (max 32767) while the JSON validator accepts up to 65535 for SMALLINT; give
these columns a form maximum well below 32767 (for example 10000). The current thumbnail columns are TINYINT, which
caps thumbnails at **255 px** on MySQL.

> **Existing columns — thumbnails and watermark.** The `attachments` category already has:
> * `attachments_thumbnails_width` and `attachments_thumbnails_height`: TINYINT UNSIGNED, default 200, max 255;
>   used for today's single thumbnail.
> * `attachments_watermark_file`: VARCHAR(50), nullable, default `''`; excluded from JSON as board-specific.
> * `attachments_watermark_location`: TINYINT UNSIGNED, default 1, values of `WatermarkLocations` (1 lower left,
>   2 lower right, 3 center, 4 upper left, 5 upper right).
>
> **Naming note.** Showcase settings are prefixed with their category, and these overlap existing columns.
> Convention-aligned alternatives:
> * widen `attachments_thumbnails_width/height` to SMALLINT instead of adding `image_thumb_width/height`;
> * name the new sizes `attachments_medium_width/height`;
> * keep `attachments_watermark_location` (TINYINT enum) instead of `image_watermark_position` (VARCHAR);
> * widen `attachments_watermark_file` to VARCHAR(255) instead of adding `image_watermark_file`;
> * name the remaining flags `attachments_strip_metadata`, `attachments_watermark_enable` and
>   `attachments_watermark_opacity`.
>
> Widening a column is a type change that `SchemaManager` applies on all three engines (MySQL `MODIFY`, PostgreSQL
> `ALTER … TYPE`, SQLite table rebuild); TINYINT → SMALLINT is a no-op on PostgreSQL and SQLite.

### `myshowcase_permissions` — new column

| Column | Type | Default intent | Meaning |
|---|---|---|---|
| `can_download_original` | TINYINT UNSIGNED NOT NULL | `0` for guests and registered, `1` for moderators and administrators | Download the private original instead of the display copy. |

> **Naming note.** Convention-aligned alternative: `attachments_can_download_original` (next to the existing
> `attachments_can_download`).

Per-group defaults need the same activation update as the folder permissions (see group C).

### Migration and backfill

* No existing data changes. Existing attachments get variant rows when the rebuild tool or the task generates them;
  today's `thumbnail_name` keeps working until then.
* If the thumbnail columns are widened instead of duplicated, existing values (≤ 255) are kept.

### JSON sync exposure

* Automatic showcase keys: `image_thumb_width`, `image_thumb_height`, `image_medium_width`, `image_medium_height`,
  `image_strip_metadata`, `image_watermark`, `image_watermark_position`, `image_watermark_opacity`.
* **Exclude `image_watermark_file`** (add it to `JsonSchema::SHOWCASE_EXCLUDED`): a board path, like
  `attachments_watermark_file`, which is already excluded.
* `myshowcase_attachment_variants` is not synced.

### Dependencies

Independent of A, B, C and E. Needs the entry-deleted event repair so that variants and files are removed with their
attachment.

---

## Group E — Password hardening

**Status: Approved — not implemented.** Implementing track: **PW1** (password gate fixes), followed by **PW2** (scoped
gate used by group C).

### Purpose

Store showcase (and later folder) passwords as hashes, revoke unlocks when a password changes, and give guests their
own unlocks.

### `myshowcase_config.password`

* **Already `VARCHAR(255) NOT NULL DEFAULT ''`** (`Core.php`), so the widening is not needed; a `password_hash()`
  value fits.
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
* Choosing the set through an ACP dropdown of existing folders is planned in track G1. Today the ACP shows a text
  input.

## Implementation order and dependencies

| Order | Work | Track | Needs |
|---|---|---|---|
| 1 | Repair the entry-deleted event (dispatch on every delete path, fix the listeners) | prerequisite | — |
| 2 | Group E — password hardening | PW1 | — |
| 3 | Scoped password gate (`scope_type` / `scope_id`) | PW2 | E |
| 4 | Group A — option metadata | T1 | — |
| 5 | Category filter bar (`filter_display`) | T3 | A |
| 6 | Group B — multi-select and tags | T2, T2b | A, entry-deleted repair, T0c guard for JSON-managed options |
| 7 | Group C — folders | T5 | E, PW2, entry-deleted repair |
| 8 | Group D — image pipeline | T6 | entry-deleted repair (independent of A, B, C, E) |

## Change log

| Date | Group | Change | Status | Approved by |
|---|---|---|---|---|
| 2026-11-11 | A | `myshowcase_field_data`: `slug`, `parent_id`, `image`, `description`, `is_active`; unique `(field_id, value_id)` and `(field_id, slug)` after backfill; index `(field_id, parent_id)` | Approved — not implemented | Uriel |
| 2026-11-11 | A | `myshowcase_fields`: `option_image_display`, `filter_display` | Approved — not implemented | Uriel |
| 2026-11-11 | B | New table `myshowcase_field_values` | Approved — not implemented | Uriel |
| 2026-11-11 | B | `myshowcase_fields`: `allow_multiple` (only if no usable flag; `allow_multiple_values` exists), `creatable`, `creatable_groups` | Approved — not implemented | Uriel |
| 2026-11-11 | B | `myshowcase_field_data`: `status` | Approved — not implemented | Uriel |
| 2026-11-11 | B | `myshowcase_field_data`: `created_by` | Proposed — pending approval | — |
| 2026-11-11 | C | New tables `myshowcase_folders`, `myshowcase_folder_entries` | Approved — not implemented | Uriel |
| 2026-11-11 | C | `myshowcase_config`: `folders_enabled`, `folders_label_singular`, `folders_label_plural`, `folders_max_depth`, `folders_multiple_membership`, `folders_max_per_user` | Approved — not implemented | Uriel |
| 2026-11-11 | C | `myshowcase_permissions`: `can_create_folders`, `can_manage_folders`, `can_add_others_entries` | Approved — not implemented | Uriel |
| 2026-11-11 | C | `myshowcase_access_logs`: `scope_type`, `scope_id`, index `(scope_type, scope_id, user_id)` | Approved — not implemented | Uriel |
| 2026-11-11 | D | New table `myshowcase_attachment_variants` | Approved — not implemented | Uriel |
| 2026-11-11 | D | `myshowcase_config`: `image_thumb_width`, `image_thumb_height`, `image_medium_width`, `image_medium_height`, `image_strip_metadata`, `image_watermark`, `image_watermark_position`, `image_watermark_opacity` | Approved — not implemented | Uriel |
| 2026-11-11 | D | `myshowcase_config`: `image_watermark_file` | Proposed — pending approval | — |
| 2026-11-11 | D | `myshowcase_permissions`: `can_download_original` | Approved — not implemented | Uriel |
| 2026-11-11 | E | `myshowcase_config.password` stores a `password_hash()` value (column already VARCHAR(255)); hash existing values on activation | Approved — not implemented | Uriel |
| 2026-11-11 | E | `myshowcase_access_logs`: `password_fingerprint`, `session_id` | Approved — not implemented | Uriel |
