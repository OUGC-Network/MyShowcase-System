# MyShowcase entries import / export: design

Status: **proposal, awaiting approval**. No plugin code has been changed.
Branch: `feature/import-export-design` (from `main` @ `21bc2b0`).
Plugin version at time of writing: `3.0.0` (`VERSION_CODE` 3000; the `CLAUDE.md` claim of 2.5.2 is stale).

---

## 0. Findings that change the brief

Read these first. Five of them affect scope or require a decision.

1. **PluginLibrary is not in the test board.** `/Users/omarg/Documents/Git/mybb19` has no `pluginlibrary.php`
   (`inc/plugins/` holds only `index.html`). Copies exist at
   `~/Documents/Git/myshowcase-worktrees/_deps/MyBB-PluginLibrary/inc/plugins/pluginlibrary.php`
   (`version` string `14-dev`, class property `$version = 13`) and the identical file in
   `_boards/t0b-sqlite/inc/plugins/`. Everything in section A was measured on that file, on PHP 8.5.12 / libxml 2.15.
   The plugin already requires PL >= 13 (`pluginInformation()['pl']['version']`) and loads it only from the ACP
   (`loadPluginLibrary()` in `admin.php`). Nothing on the front end loads PL.
2. **There is no "unique" flag on fields.** `myshowcase_fields` has `is_required`, `default_value`, `default_type`,
   `enable_slug` and others, but nothing like `is_unique`. The only unique constraints are `entry_slug` (random
   token, not user data) and `(collection_id, field_key)` on the field definitions. Requirement 2 ("if the collection
   has unique fields") therefore needs a **new `is_unique` column** first. See Decision 1.
3. **PluginLibrary's XML import has defects that matter here** (A.4): it drops `null`, fails silently around 10 MB
   (`message: "Unknown"`), needs about 28x the file size in memory, throws PHP 8.x warnings per element, returns an
   undefined variable on error, and silently turns `\r\n` into `\n`. We can still use its two methods, but only behind
   a thin wrapper that pre-validates input and limits size.
4. **MyBB's DB layer has no transaction API** on any of the three engines (no `begin`/`commit`/`rollback` in
   `db_mysqli`, `db_pgsql`, `db_sqlite`, `db_pdo`). The all-or-nothing guarantee is therefore built primarily on
   *validate everything first*, with a raw-SQL transaction as a second layer (section D).
5. **"Showcase" and "collection" are different things.** A *collection* (`myshowcase_collections`) is the field set. A
   *showcase* (`myshowcase_config`) points at a collection and owns the entries table `myshowcase_data{showcase_id}`.
   Several showcases can share one collection, and ids differ between boards. The header and the match check must
   handle both (design 1 and 6).

---

## A. PluginLibrary XML methods

File: `inc/plugins/pluginlibrary.php`, class `PluginLibrary`, global `$PL`.

### A.1 `xml_export`

```php
public function xml_export(
    $data,
    $filename = false,
    $comment = 'MyBB PluginLibrary XML-Export :: {time}',
    $endcomment = 'End of file.'
)
```

* `$data`: an array (nested arrays allowed) or a scalar. An array becomes
  `<array><element><key>K</key><value>V</value></element>...</array>`; a scalar becomes a bare `<value>`.
* Returns the XML as a string (`<?xml version="1.0" encoding="UTF-8"?>`, an XML comment built from `$comment` with
  `{time}` replaced by `date('c', TIME_NOW)`, the data, then `$endcomment` as a trailing comment).
* **If `$filename` is truthy it sends download headers, echoes the XML and calls `exit`.** We must always pass
  `false` and write the string to disk ourselves (design 2 and 4).
* Returns `''` when the data produces no XML (for example an empty scalar `null`).

Per-value encoding (`_xml_tag`):

| PHP value | Output | Notes |
|---|---|---|
| string | `<value>text</value>` | picks the shorter of `htmlspecialchars()` or a CDATA block (`]]>` split correctly). Empty string gives `<value></value>`. |
| bool | `<value type="BOOL">1</value>` / `...></value>` | false is an empty element. |
| int | `<value type="INT">5</value>` | |
| float | `<value type="FLOAT">1.5</value>` | |
| array | nested `<array>` | empty array gives `<array>\n</array>`. |
| **null** | **element omitted entirely** | `_xml_array` skips any element whose value tag renders as `''`. |

Keys use the same rules (`<key type="INT">7</key>` for integer keys), so integer-keyed lists round-trip.

### A.2 `xml_import`

```php
public function xml_import($xml, &$error = null)
```

* Input: the XML **string** (not a path). Output: the decoded array, or `null` on failure with `$error` filled in.
* Parser: `xml_parser_create()` plus `xml_parse_into_struct()`. The whole document is read into memory, then walked
  with a stack. Attributes: only `type` (`BOOL`/`INT`/`FLOAT`, case-insensitive) is read; everything else is a string.
  Unknown tags are ignored. The root must be `<array>` (or `<value>` for a scalar).
* Error shape on a parse failure: `['line', 'code' (offending source line), 'error' (expat code), 'message']`.
  On a well-formed document that is not PL-shaped: `['line' => -1, 'code' => -1, 'error' => -1, 'message' => 'XML is
  valid, but there is no data to import.']`.

### A.3 Measured behaviour (PHP 8.5.12, libxml 2.15)

| Probe | Result |
|---|---|
| Round-trip of strings with `< > & " '`, `]]>`, newlines, UTF-8 (accents, CJK, emoji), `'0'`, `''`, ints, floats, bools, empty arrays, int keys | Identical, **except** `null` values (dropped) and `\r\n` (becomes `\n`, XML line-end normalisation). |
| Control character (`\x01`) in a value | Export succeeds and writes an **invalid XML file**; import fails with `Invalid character` at that line. Must be stripped on export. |
| Invalid UTF-8 bytes | Silently replaced by U+FFFD; no error. |
| Valid but non-PL XML (`<root><a>1</a></root>`) | `null` + the "no data to import" error. |
| Truncated XML | `null` + `Tag not finished` with line number and source line. |
| ISO-8859-1 declared document | Converted to UTF-8 correctly. |
| UTF-8 BOM prefix | Accepted. |
| 2.2 MB / 2,000 entries | 0.2 s, 65 MB peak. |
| 5.6 MB / 5,000 entries | 0.5 s, **159 MB peak** (about 28x file size). With `memory_limit=128M` this is a fatal error, not an error array. |
| **11.7 MB / 10,000 entries** | **Import returns `null` with `['message' => 'Unknown']`** (no line or code). Peak 341 MB. |
| 20,000 small entries, 10 MB | Export 0.12 s; import 0.9 s, 329 MB peak. |
| Warnings | On PHP 8.5 every element emits `Undefined array key "attributes"`, `strtoupper(): Passing null` (deprecated) and, on error, `Undefined variable $result`, plus `xml_parser_free()` deprecated. At 5,000 entries that is hundreds of thousands of notices through MyBB's error handler. |

### A.4 Size and parsing limits

* No size, depth or element-count limit of its own. The practical limits are `memory_limit` (about 28x the file) and
  a hard failure with a useless message at roughly 10 MB.
* Export builds the whole string in memory too, and the data array is held at the same time, so the export cap
  (design 2) is on entry count.
* Duplicate keys silently overwrite; the walk is "sloppy" (malformed nesting inside valid XML can produce undefined
  index notices instead of an error).

### A.5 XXE and entity expansion

Tested with a `file://` external entity, an internal entity, and a six-level "billion laughs" document:

* **External entities are not resolved** (`&x;` pointing at `file:///tmp/...` came back as an empty string). PHP >= 8.0
  with libxml >= 2.9 disables external entity loading by default, so XXE file disclosure is not reproducible here.
* **Internal entities are expanded.** The laughs document did not exhaust memory; libxml's own amplification guard cut
  it off, and the value was silently truncated to 30 bytes. Safe, but the outcome is data corruption, not an error.
* The hosting environment is outside our control (older libxml, or a PHP build that re-enables entity loading). We
  should not rely on the default.

**Mitigation (part of the wrapper, design 6):**

1. Before calling PL, reject any input containing `<!DOCTYPE` or `<!ENTITY` (case-insensitive, scanned on the raw
   bytes after removing a BOM). Our own exports never contain either.
2. Parse once with `DOMDocument::loadXML($xml, LIBXML_NONET)` (deliberately **not** passing `LIBXML_NOENT` or
   `LIBXML_DTDLOAD`) under `libxml_use_internal_errors(true)`, to get a well-formedness check with precise
   line/column errors and to confirm the root is `<array>`. This adds parse time but little memory next to PL's
   struct; files above the size cap are rejected before this step.
3. Enforce the size and memory caps before parsing.
4. Run `xml_import` inside a temporary error handler that swallows `E_WARNING`/`E_NOTICE`/`E_DEPRECATED` from
   `pluginlibrary.php`, and treat a `null` return with `$error` as failure.

### A.6 Required wrapper behaviour (export side)

* Strip characters illegal in XML 1.0 (`[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]`) from every
  string before export, and validate UTF-8 (`mb_check_encoding`, else `mb_scrub`).
* Never rely on `null`: write empty strings for NULL columns. Consequence: the format cannot distinguish "NULL" from
  "empty". That is acceptable because MyShowcase entry columns that matter are NOT NULL with defaults
  (`message` is the only nullable column).
* Warn in the docs that `\r\n` becomes `\n`.

---

## B. MyShowcase data model

Source: `inc/plugins/myshowcase/Plugin/Core.php` (`TABLES_DATA`, `DATA_TABLE_STRUCTURE`),
`src/Entity/*`, `src/Repository/EntriesRepository.php`, `src/Service/EntriesManager.php`.

### B.1 Tables

| Table | Purpose |
|---|---|
| `myshowcase_collections` | `collection_id`, `name`, JSON-sync columns (`json_file`, `json_version`, `json_synced_at`, `json_hash`). |
| `myshowcase_fields` | One row per field in a collection. Unique on `(collection_id, field_key)`. |
| `myshowcase_field_data` | Selectable values of select/radio/checkbox fields: `value_id`, `value`, `display_order`, group permissions. |
| `myshowcase_config` | One row per showcase: `showcase_id`, `collection_id`, `name`, `route_identifier`, settings, `attachments_uploads_path`. |
| `myshowcase_data{showcase_id}` | **Entries**, one table per showcase, created from `DATA_TABLE_STRUCTURE['myshowcase_data']` plus one column per field. |
| `myshowcase_data_history{id}` | Entry edit history (`entry_history_id`, `entry_id`, `update_dateline`, `edit_user_id`, `subject`, flags, `ipaddress`). |
| `myshowcase_comments`, `_comments_history` | Comments and their edit history. |
| `myshowcase_attachments` (+ `_history`, `_cdn`, `_shared`, `_download_logs`) | Attachments, linked by `showcase_id`, `entry_id`, `comment_id`, `field_id`. |
| `myshowcase_logs` | Generic log: `showcase_id`, `type_id`, `unique_id`, `user_id`, `log_time` DATETIME, `log_data`. |

### B.2 Field flags (table `myshowcase_fields`)

| Need | Column | Notes |
|---|---|---|
| Key / name used as the entries-table column | `field_key` VARCHAR(30) | Used as the column name; unique per collection. This is the matching key (design 10). |
| Required | `is_required` TINYINT | Enforced in `EntriesManager::validateEntry()` only when the key is present on the entry and the field is viewable in the form (`isViewableInForm`) and not `read_only`. The test is PHP `empty()`, so the string `'0'` counts as empty (existing quirk; the importer will not copy it). |
| Unique | **does not exist** | See Finding 2 and Decision 1. |
| Default | `default_value` VARCHAR(250) + `default_type` | `default_type`: `0 AsDefined`, `1 IsNull`, `2 CurrentTimestamp`, `3 UUID` (`Enum/FieldDefaultTypes`). Applied at insert by `EntriesRepository::applyCustomFieldDefault()`, **only for keys that are not set** in the entry's extra data (`!isset`). Present-but-empty values are therefore stored as given, not replaced by the default. |
| Type | `html_type` (`Enum/FieldHtmlTypes`) and `field_type` (SQL type of the column) | `file` fields store attachments, not column data. `select`/`radio`/`checkbox` store a `value_id` that points to `myshowcase_field_data`. |
| Enabled / read-only | `is_enabled`, `read_only` | |
| Slug source | `enable_slug` | Feeds `entry_slug_custom`. |

### B.3 Entry-level columns that are not collection fields

Base columns of `myshowcase_data{N}` (`DATA_TABLE_STRUCTURE`):

| Column | Import handling (design 9) |
|---|---|
| `entry_id` (PK, auto-increment) | Never imported. New id from `insert_query` (all engines now use the id `insert_query` returns). `source_entry_id` is kept in the file for the report only. |
| `entry_slug` (UNIQUE random token) | Never imported; generated with the same loop `EntriesManager` uses (`generateCommentSlug` + existence check). |
| `entry_slug_custom` | Regenerated from the slug-enabled fields or subject, same as the form path. |
| `post_hash` | New value. |
| `user_id`, `username` | Mapped (design 9). |
| `subject`, `message` | Imported. Subject/message length limits and enable flags (`entries_enable_subject`, `entries_enable_message`) follow the showcase settings. |
| `icon_id` | Imported only if the id exists in the `posticons` cache, else 0. |
| `dateline`, `update_dateline` (DATETIME) | Imported if valid and not in the future, else now. |
| `status` (`ContentStatuses`: 0 AwaitingApproval, 1 VisibleApproved, 2 SoftDeleted) | Imported as given, overridable (design 9). |
| `is_closed`, `is_sticky`, `allow_signature`, `disable_smilies` | Imported if valid (0/1). |
| `views`, `comments_*`, `attachments_*`, `last_comment_*`, `edit_user_id`, `update_note` | Reset to 0 / empty: they describe data that is not imported. |
| `ipaddress` (VARBINARY(16)) | Importer's IP. Never taken from the file. |

Attachments (`myshowcase_attachments`), comments, and edit history are keyed by entry id and need file storage or
extra tables in the file. **Out of scope for v1** (design 9).

### B.4 Insert path to reuse

`EntriesRepository::insertEntry(EntryEntity, array $custom_fields)` already does: base-column defaults, the "absent
key gets its default" rule, type casting and escaping, the `entry_insert_update_end` hook, and
`omitEmptyAutoIncrement` for SQLite/PostgreSQL. Import should build `EntryEntity` objects and call it, rather than
writing SQL, so third-party hooks keep working. Open implementation item: confirm which counters/caches the front
end updates after a normal create (showcase entry totals, `CacheManager`) and replay them once per import.

---

## C. Existing admin / front-end structure

* **ACP module** `admin/modules/myshowcase/` with `module_meta.php` (menu, `myshowcase_action_handler()`,
  `myshowcase_admin_permissions()`), `summary.php`, `fields.php`, `json_sync.php`, `help.php`. Permissions are ACP
  permissions declared in `myshowcase_admin_permissions()` (`summary`, `new`, `edit`, `fields`, `json_sync`, `cache`).
  The ACP checks the permission before it includes the module file, as the header comment of `json_sync.php` notes.
* **Pattern to copy:** `json_sync.php`. Post-key check on every action (`verify_post_check`), file access only through
  a class that resolves names against a directory (`JsonFiles`), a 512 KB file cap, `flash_message` + `admin_redirect`.
  The showcase list (`AdminManager`, around line 1500) uses a `PopupMenu` per showcase where "Export JSON" is already an
  item; **Export entries** and **Import entries** fit the same menu.
* **Front end:** Symfony routes (`Enum/Routes.php`, controllers under `src/Controller/`), permissions through
  `PermissionManager` / `UserPermissions` and per-showcase moderator rules. There is no existing upload for a
  non-attachment file. The front-end attachment upload (`AttachmentsManager::uploadAttachment`, FilePond/flow.js) is
  tied to entry attachments, per-showcase upload paths and image processing, so it is not a good fit.
* **Existing protected-directory convention:** `inc/plugins/myshowcase/imports/` with `.htaccess` (`Require all denied`)
  and `index.html`. Nginx ignores `.htaccess` (the board ships `htaccess-nginx.txt`), so the file name must also be
  unguessable (design 2).
* **Recommendation:** ACP module, new action file `import_export.php`, new ACP permission `import_export`.
  Reasons: import creates entries on behalf of other users, bypasses flood and per-group posting limits, and writes
  in bulk, so it is an administrator operation; ACP gives permission checks, post keys, forms and a file input for
  free; no Twig templates are needed. A front-end version for showcase moderators is a possible v2 and would reuse the
  same services.
* **Upload handling to reuse:** the plain MyBB ACP form (`Form` with `enctype=multipart/form-data`,
  `$_FILES['xml_file']`), validated with `is_uploaded_file`, then read from the temp path, never moved into the web
  root. `AttachmentsManager::uploadFile()` is not needed.

---

## D. Database portability and the all-or-nothing guarantee

Facts verified in `mybb19/inc`:

* No `begin`/`commit`/`rollback`/`transaction` in any `db_*.php`. `write_query()` passes raw SQL through on all three
  engines, so `START TRANSACTION` (MySQL/MariaDB), `BEGIN` (PostgreSQL, SQLite), `COMMIT`, `ROLLBACK` work as raw
  statements.
* MySQL tables are InnoDB by default (`$table_type = "innodb"`) and the plugin converts MyISAM tables on activation
  (`dbConvertTablesToInnoDb()`, `SchemaManager::convertToInnoDbSql`). Entry tables are therefore transactional unless an
  admin changed the engine.
* A failed query in MyBB calls the error handler which stops the request; an uncommitted transaction is rolled back by
  every engine when the connection closes.

Layered guarantee:

1. **Validate everything first (authoritative, engine-independent).** Parse, check file, check collection, check
   required, check unique (database and in-file), resolve authors and select values, build the full list of rows in
   memory. If anything that must fail fails, **no write has happened**, so there is nothing to roll back on any engine.
2. **One transaction around the inserts.** A `ImportTransaction` helper issues the right begin/commit/rollback per
   `SchemaManager::engine()` (`mysql`, `pgsql`, `sqlite`), and does nothing when the entries table is not
   transactional. Commit only after the last row and the log row are written.
3. **Compensating delete.** Track every inserted `entry_id`; if an insert throws after validation passed (for example a
   column limit hit in a third-party hook, or a race), delete the tracked rows inside `finally` before reporting the
   failure. This covers MyISAM and any case where step 2 was unavailable.

Known gaps, accepted: (a) a concurrent normal create can insert a duplicate between the unique check and our insert
(window is milliseconds; there is no per-field unique index to enforce it, and adding one is not portable for
case-insensitive checks); (b) SQLite rejects a nested `BEGIN`, so the helper must check whether MyBB already opened
one (it does not today); (c) PostgreSQL aborts the whole transaction on any failed statement, which is the behaviour
we want.

---

## Design

### 1. XML schema

PluginLibrary fixes the XML dialect: the root is `<array>`, with `<element><key/><value/></element>` pairs. We
therefore do **not** invent element names; the "schema" is the PHP array structure below, encoded by `xml_export`.
A top-level `format` marker identifies our files.

Top level: `header` and `entries`.

`header` (all values scalars or arrays of scalars):

| Key | Type | Meaning |
|---|---|---|
| `format` | string | Always `myshowcase-entries`. Import rejects anything else. |
| `format_version` | int | `1`. Import rejects a higher number. |
| `myshowcase_version` | string | Plugin version that wrote the file (informational; shown in the import report). |
| `generated` | string | ISO 8601 UTC date of generation. |
| `showcase` | array | `route_identifier`, `name` of the source showcase. |
| `collection` | array | `name`, `json_file`, `json_version` (the last two empty if not JSON-synced). |
| `entry_count` | int | Number of items in `entries`; checked against the real count. |
| `fields` | list | One item per exported field: `field_key`, `html_type`, `field_type`, `is_required`, `is_unique`. This is the file's own field catalogue and is what the compatibility check uses. |
| `rules` | array | The export rules (design 3): `limit`, `order_by`, `order_dir`, `fields` (selected keys), `options` (every form option chosen). Informational on import. |

`entries`: a list. Each entry:

| Key | Meaning |
|---|---|
| `source_entry_id` | Original id, report only. |
| `author` | array: `user_id`, `username`. Present when "include author" was chosen. |
| `dateline`, `update_dateline` | ISO 8601 UTC. Present when "include dates" was chosen. |
| `status`, `is_closed`, `is_sticky` | ints. Present when "include flags" was chosen. |
| `subject`, `message` | strings; only when the showcase has them enabled. |
| `fields` | array `field_key => value`. **Absent key means "not in the file"** (design 8). |

Sample (generated with the real `PluginLibrary::xml_export`, then re-imported to confirm it parses back to 2 entries):

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!-- MyShowcase entries export :: 2026-11-12T09:30:00+00:00 -->
<array>
  <element>
    <key>header</key>
    <value>
      <array>
        <element>
          <key>format</key>
          <value>myshowcase-entries</value>
        </element>
        <element>
          <key>format_version</key>
          <value type="INT">1</value>
        </element>
        <element>
          <key>myshowcase_version</key>
          <value>3.0.0</value>
        </element>
        <element>
          <key>generated</key>
          <value>2026-11-12T09:30:00+00:00</value>
        </element>
        <element>
          <key>showcase</key>
          <value>
            <array>
              <element>
                <key>route_identifier</key>
                <value>blog</value>
              </element>
              <element>
                <key>name</key>
                <value>Blog</value>
              </element>
            </array>
          </value>
        </element>
        <element>
          <key>collection</key>
          <value>
            <array>
              <element>
                <key>name</key>
                <value>Blog</value>
              </element>
              <element>
                <key>json_file</key>
                <value>collections/blog.json</value>
              </element>
              <element>
                <key>json_version</key>
                <value>1.0.0</value>
              </element>
            </array>
          </value>
        </element>
        <element>
          <key>entry_count</key>
          <value type="INT">2</value>
        </element>
        <element>
          <key>fields</key>
          <value>
            <array>
              <element>
                <key type="INT">0</key>
                <value>
                  <array>
                    <element>
                      <key>field_key</key>
                      <value>category</value>
                    </element>
                    <element>
                      <key>html_type</key>
                      <value>select</value>
                    </element>
                    <element>
                      <key>field_type</key>
                      <value>int</value>
                    </element>
                    <element>
                      <key>is_required</key>
                      <value type="INT">1</value>
                    </element>
                    <element>
                      <key>is_unique</key>
                      <value type="INT">0</value>
                    </element>
                  </array>
                </value>
              </element>
              <element>
                <key type="INT">1</key>
                <value>
                  <array>
                    <element>
                      <key>field_key</key>
                      <value>summary</value>
                    </element>
                    <element>
                      <key>html_type</key>
                      <value>textarea</value>
                    </element>
                    <element>
                      <key>field_type</key>
                      <value>text</value>
                    </element>
                    <element>
                      <key>is_required</key>
                      <value type="INT">0</value>
                    </element>
                    <element>
                      <key>is_unique</key>
                      <value type="INT">0</value>
                    </element>
                  </array>
                </value>
              </element>
              <element>
                <key type="INT">2</key>
                <value>
                  <array>
                    <element>
                      <key>field_key</key>
                      <value>cover_image</value>
                    </element>
                    <element>
                      <key>html_type</key>
                      <value>url</value>
                    </element>
                    <element>
                      <key>field_type</key>
                      <value>varchar</value>
                    </element>
                    <element>
                      <key>is_required</key>
                      <value type="INT">0</value>
                    </element>
                    <element>
                      <key>is_unique</key>
                      <value type="INT">1</value>
                    </element>
                  </array>
                </value>
              </element>
            </array>
          </value>
        </element>
        <element>
          <key>rules</key>
          <value>
            <array>
              <element>
                <key>limit</key>
                <value type="INT">100</value>
              </element>
              <element>
                <key>order_by</key>
                <value>dateline</value>
              </element>
              <element>
                <key>order_dir</key>
                <value>desc</value>
              </element>
              <element>
                <key>fields</key>
                <value>
                  <array>
                    <element>
                      <key type="INT">0</key>
                      <value>category</value>
                    </element>
                    <element>
                      <key type="INT">1</key>
                      <value>summary</value>
                    </element>
                    <element>
                      <key type="INT">2</key>
                      <value>cover_image</value>
                    </element>
                  </array>
                </value>
              </element>
              <element>
                <key>options</key>
                <value>
                  <array>
                    <element>
                      <key>statuses</key>
                      <value>
                        <array>
                          <element>
                            <key type="INT">0</key>
                            <value>approved</value>
                          </element>
                        </array>
                      </value>
                    </element>
                    <element>
                      <key>include_author</key>
                      <value type="INT">1</value>
                    </element>
                    <element>
                      <key>include_dates</key>
                      <value type="INT">1</value>
                    </element>
                    <element>
                      <key>include_flags</key>
                      <value type="INT">1</value>
                    </element>
                    <element>
                      <key>select_values</key>
                      <value>ids</value>
                    </element>
                  </array>
                </value>
              </element>
            </array>
          </value>
        </element>
      </array>
    </value>
  </element>
  <element>
    <key>entries</key>
    <value>
      <array>
        <element>
          <key type="INT">0</key>
          <value>
            <array>
              <element>
                <key>source_entry_id</key>
                <value type="INT">41</value>
              </element>
              <element>
                <key>author</key>
                <value>
                  <array>
                    <element>
                      <key>user_id</key>
                      <value type="INT">3</value>
                    </element>
                    <element>
                      <key>username</key>
                      <value>omar</value>
                    </element>
                  </array>
                </value>
              </element>
              <element>
                <key>dateline</key>
                <value>2026-10-01T08:15:00+00:00</value>
              </element>
              <element>
                <key>update_dateline</key>
                <value>2026-10-02T10:00:00+00:00</value>
              </element>
              <element>
                <key>status</key>
                <value type="INT">1</value>
              </element>
              <element>
                <key>is_closed</key>
                <value type="INT">0</value>
              </element>
              <element>
                <key>is_sticky</key>
                <value type="INT">0</value>
              </element>
              <element>
                <key>subject</key>
                <value>First post &amp; more</value>
              </element>
              <element>
                <key>message</key>
                <value><![CDATA[Hello <b>world</b>
Second line]]></value>
              </element>
              <element>
                <key>fields</key>
                <value>
                  <array>
                    <element>
                      <key>category</key>
                      <value type="INT">1</value>
                    </element>
                    <element>
                      <key>summary</key>
                      <value>Short summary</value>
                    </element>
                    <element>
                      <key>cover_image</key>
                      <value>https://example.com/a.png</value>
                    </element>
                  </array>
                </value>
              </element>
            </array>
          </value>
        </element>
        <element>
          <key type="INT">1</key>
          <value>
            <array>
              <element>
                <key>source_entry_id</key>
                <value type="INT">42</value>
              </element>
              <element>
                <key>author</key>
                <value>
                  <array>
                    <element>
                      <key>user_id</key>
                      <value type="INT">0</value>
                    </element>
                    <element>
                      <key>username</key>
                      <value>Guest</value>
                    </element>
                  </array>
                </value>
              </element>
              <element>
                <key>dateline</key>
                <value>2026-10-05T12:00:00+00:00</value>
              </element>
              <element>
                <key>status</key>
                <value type="INT">1</value>
              </element>
              <element>
                <key>is_closed</key>
                <value type="INT">0</value>
              </element>
              <element>
                <key>is_sticky</key>
                <value type="INT">1</value>
              </element>
              <element>
                <key>subject</key>
                <value>Second post</value>
              </element>
              <element>
                <key>message</key>
                <value></value>
              </element>
              <element>
                <key>fields</key>
                <value>
                  <array>
                    <element>
                      <key>category</key>
                      <value type="INT">2</value>
                    </element>
                    <element>
                      <key>summary</key>
                      <value></value>
                    </element>
                    <element>
                      <key>cover_image</key>
                      <value>https://example.com/b.png</value>
                    </element>
                  </array>
                </value>
              </element>
            </array>
          </value>
        </element>
      </array>
    </value>
  </element>
</array>
<!-- End of MyShowcase export. -->
```

### 2. Storage location and filenames

* **Default directory:** `inc/plugins/myshowcase/exports/`, with a `.htaccess` (`Require all denied`, same text as
  `imports/.htaccess`) and an `index.html`, created on first export. This follows the existing `imports/` convention and
  works on shared hosting.
* **Setting `myshowcase_exports_directory`** (ACP setting): lets the admin point at an absolute path **outside the web
  root**, which is the recommended production setup. The ACP page shows a warning when the directory is inside the
  web root and the server is not Apache (cannot rely on `.htaccess`).
* **Filename on disk:** `{export_id}-{route_identifier}-{YYYYMMDD-HHMMSS}-{32 hex random}.xml`, for example
  `17-blog-20261112-093000-9f2c...e1.xml`. The random token makes the path unguessable even if the directory is
  served by mistake. `route_identifier` is already restricted to safe characters; it is additionally passed through
  `[^a-z0-9_-]` stripping.
* **Download name** (sent in `Content-Disposition`): `myshowcase-{route_identifier}-{YYYYMMDD-HHMMSS}.xml`.
  Disk name and download name are independent; the download name is built at request time, never taken from user input.
* **Size caps:** export at most **5,000 entries** per file by default (setting). That keeps the file under about 6 MB
  and the import within about 160 MB, matching the measurements in A.3.

### 3. Export records table `myshowcase_exports`

Added to `TABLES_DATA` in `Plugin/Core.php`, following the existing conventions (typed column arrays, `unsigned`,
`default`, DATETIME for dates as in `myshowcase_logs`, `unique_key` / `unique_keys`, no engine-specific SQL). The
installer path `dbVerifyTables()` called from `pluginActivation()` creates missing tables on all three engines through
`SchemaManager::createTableSql`, so an upgrade is: add the definition, bump `VERSION_CODE`, re-activate.

| Column | Type | Notes |
|---|---|---|
| `export_id` | INT unsigned, auto_increment, PK | |
| `showcase_id` | INT unsigned, default 0 | |
| `collection_id` | INT unsigned, default 0 | |
| `user_id` | INT unsigned, default 0 | creator uid |
| `created_at` | DATETIME | creation date |
| `file_name` | VARCHAR(120), default '', unique_key | disk name |
| `file_size` | INT unsigned, default 0 | bytes |
| `file_hash` | VARCHAR(64), default '' | SHA-256 of the file |
| `entry_count` | INT unsigned, default 0 | entries written |
| `entry_limit` | INT unsigned, default 0 | rule: limit (0 = all, up to the cap) |
| `order_by` | VARCHAR(30), default '' | rule: column or field key |
| `order_direction` | VARCHAR(4), default '' | rule: `asc` / `desc` |
| `selected_fields` | VARCHAR(1000), default '' | rule: comma-separated field keys |
| `rules` | TEXT, null | rule: the complete `header.rules` array as JSON, so every form option is retained and displayable |
| `plugin_version` | VARCHAR(20), default '' | |
| `downloads` | INT unsigned, default 0 | |
| `file_deleted` | TINYINT unsigned, default 0 | set when retention removes the file; the row stays as history |

Index on `(showcase_id, created_at)` for the list screen. `rules` is JSON in a TEXT column (TEXT can be NULL without a
default, matching `message` in the entries table); the discrete columns exist so the list screen can sort and show
the headline rules without decoding JSON.

**Export form options** (stored in `rules.options`, shown in the list as "Rules"): entry limit; order by (entry date,
last update, subject, views, or any field key) and direction; selected fields (default all enabled); entry
statuses (approved, awaiting approval, soft-deleted; default approved only); include author; include dates; include
flags (status, closed, sticky); select-field values as ids or labels (Decision 8); include disabled fields (default
no).

### 4. Download mechanism

* ACP action `import_export.php?action=download&export_id=N&my_post_key=...`. The ACP guard has already authenticated the
  admin and checked the `import_export` permission; the action additionally verifies the post key.
* Looks the row up by `export_id`, refuses when `file_deleted` is set, resolves `exports_directory . '/' . file_name`
  with `realpath` and checks it is inside the exports directory, compares the stored `file_hash` with `hash_file` (a
  mismatch aborts with an error rather than serving a modified file), then streams it with
  `Content-Type: application/xml; charset=UTF-8`, `Content-Disposition: attachment; filename="..."`,
  `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`, and `Content-Length`. Increments `downloads`.
* No public URL ever exists. The existing `System/PartialFileServlet.php` is for range requests on attachments and is
  not needed for files this small.

### 5. Retention

Both, with safe defaults.

* **Manual:** a Delete action per export (removes the file and sets `file_deleted = 1`; the history row is kept).
  Bulk delete of all exports older than N days from the list screen.
* **Automatic:** a step in the existing scheduled task `inc/tasks/myshowcase.php` deletes the files of exports older
  than **30 days** (setting `myshowcase_exports_retention_days`; `0` disables auto-deletion). The row remains with
  `file_deleted = 1` so the "rules" history stays visible.
* Uninstall removes the table and the exports directory (consistent with the other plugin tables).

### 6. Import validation order

Nothing is written until step 9. Every step reports *all* problems it finds in that step before stopping, then
stops at the end of the step. Steps 1 to 6 are file-level and fatal; 7 to 8 are row-level.

1. **Request:** post key; the ACP permission (already done by the ACP); showcase chosen and exists; its entries table
   exists (`showcaseDataTableExists`).
2. **Upload:** `UPLOAD_ERR_OK`, `is_uploaded_file`, size >= 1 byte and <= max (default 5 MB, setting), and an
   estimated memory need (30 x size) below `memory_limit` minus current usage. Otherwise a clear error.
3. **Type:** extension is `.xml` (case-insensitive, from the *client* name, used only for this check); MIME from
   `finfo` is `application/xml`, `text/xml`, or `text/plain` (many servers report XML as plain text); the first
   non-BOM byte is `<`. The stored/processed file is read from the temp path only; the client file name is never used
   for any filesystem operation.
4. **Parse:** wrapper from A.5 (reject DOCTYPE/ENTITY, DOM well-formedness check, then `$PL->xml_import`). Failure
   message includes line number and the offending source line.
5. **Structure:** top-level `header` and `entries` arrays; `header.format === 'myshowcase-entries'`;
   `format_version <= 1`; `entries` is a list; `entry_count` equals the real count; every `entries[n].fields` is an
   array.
6. **Collection match:** `header.collection.name` equals the target collection's name (case-insensitive, trimmed), see
   Decision 4. File fields (`header.fields[]`) that are not in the target collection are listed as a warning
   (design 10). Field type conflicts (`html_type` differs for the same `field_key`) are fatal.
7. **Required check (all-or-nothing):** for every required, enabled, non-`file` field of the target collection, every
   entry must contain the key with a non-empty trimmed value (design 8). All missing fields are collected,
   *grouped by field with the count and the first entry numbers*, and the import fails with
   `missing required fields: category (entries 1, 4, 9 and 17 more), title (all entries)`. No partial import.
8. **Value validation and unique check:**
   * per value: cast and validate against `field_type`, `maximum_length`, `regular_expression`, and, for select/radio
     fields, that the value maps to an existing `value_id` (design 3, Decision 8); invalid values are fatal row errors
     reported with entry number and field, because importing them would break the entry;
   * for each field with `is_unique`: duplicates **inside the file** (case-insensitive trimmed comparison, first
     occurrence wins), then duplicates **against the database** with one batched query per field
     (`WHERE field IN (...)` in chunks of 500, so it is a single code path on MySQL, PostgreSQL and SQLite);
   * the duplicate policy of design 7 is applied here, producing the *skip list*.
9. **Insert:** begin transaction, insert the surviving entries through `EntriesRepository::insertEntry`, write one
   `myshowcase_logs` row, commit; on exception run the compensating delete (section D). After commit, refresh
   caches/counters once.

A **"Validate only"** button runs steps 1 to 8 and shows the report without step 9 (Decision 15).

### 7. Duplicate policy

**Recommended default: skip the colliding entry and report it**, with a checkbox "Fail the whole import if any entry
is a duplicate" (off by default).

Why: re-importing the same file, or an overlapping export, then adds only what is new and is safe to repeat. Failing
the whole import on one collision makes large files effectively unimportable once a board has any overlapping data,
and offers the admin no way forward except editing XML by hand. Required-field problems are different: they mean the
*file* is incomplete or from the wrong collection, so the brief's all-or-nothing rule is the right one there.
Duplicates mean the *data* partly exists already.

Detail: an entry is a duplicate if **any** `is_unique` field value collides (with the database or with an earlier entry
in the file). The report lists entry number, field, value and what it collided with ("existing entry #12" /
"entry 7 in this file"). Skipped entries count in the result but write nothing.

### 8. What "missing" means

| Situation in the file | Required field | Optional field |
|---|---|---|
| **Absent**: no `fields.{key}` element in that entry | Import fails (design 6, step 7). Decision 3 covers fields that also have a default. | Field gets its configured default (`default_type` applied by `applyCustomFieldDefault`; for `IsNull` the column default). |
| **Present but empty**: `<value></value>` (also a value of only spaces) | Counts as missing: import fails. `'0'` is **not** empty (unlike the form validator's `empty()`; the importer uses `trim($v) === ''`). | Stored as empty. The default is **not** applied, because the file explicitly says empty. |
| Present with a value | Validated and imported. | Validated and imported. |

Because `xml_export` drops `null`, the exporter writes `''` for NULL, and the two cases above are the only two states a
file can express. The distinction is per entry: a field can be absent from one entry and present in another (sparse
files are valid). `header.fields` is informational; it is not required to list a key for it to be used. `file`-type
fields are excluded from the required check on import (they cannot be supplied; Decision 9).

### 9. Non-field data on import

| Data | Rule |
|---|---|
| **Author** | Resolve in this order: (1) a user whose `username` equals `author.username` (case-insensitive) on the target board; (2) otherwise the importing admin. The original `user_id` from the file is **never trusted** because ids differ between boards (uid 3 on the source is somebody else on the target). `username` column is set from the resolved user, like the form path does. The report counts how many entries fell back to the importer. Guest (`user_id` 0, `Guest`) stays guest. Decision 6. |
| **Dates** | `dateline` / `update_dateline` kept if parseable and not later than now; otherwise now. `update_dateline` never earlier than `dateline`. Stored as DATETIME in UTC via `castTableFieldValue`. |
| **Approval status** | The file's `status` is used if it is 0, 1 or 2; missing means 1 (approved) for a bulk import by an admin. The form offers "Import as: keep file status / force approved / force awaiting approval" (default keep). Soft-deleted entries are not exported by default; if imported they keep status 2. Decision 7. |
| `is_closed`, `is_sticky`, `allow_signature`, `disable_smilies` | Kept when 0/1, else defaults. |
| **Counters and pointers** (`views`, `comments_*`, `attachments_*`, `last_comment_*`, `edit_user_id`, `update_note`) | Reset. |
| **Attachments** | **Out of v1.** `file` fields are not exported; if present in a file they are ignored with a warning. |
| **Comments** | **Out of v1.** |
| **Edit history** | **Out of v1.** |
| `entry_slug`, `post_hash`, `ipaddress` | Generated locally. |

### 10. Field matching

* Match by **`field_key`**, exactly (case-sensitive; keys are already restricted to column-safe names). Field ids are
  not exported or trusted: they differ between boards and are internal.
* A key in the file that the target collection does not have: **ignored, listed in a warning** ("3 fields not in this
  collection were ignored: x, y, z") and the import continues. Rationale: it lets a richer export be loaded into a
  trimmed collection. A checkbox "Fail on unknown fields" makes it fatal (Decision 5).
* A target field not present anywhere in the file: absent, so default/required rules apply.
* Disabled target fields (`is_enabled = 0`) are not imported into and never counted as required.
* Select-type values: the file may carry `value_id` (default) or the label; the importer accepts either and resolves
  against `myshowcase_field_data` for that field. Not resolvable is a fatal row error.

### 11. Import result report

Shown on the same ACP page after submit (flash message plus a table), and recorded in `myshowcase_logs`:

```
Import of "entries-blog-20261112.xml" into Blog (showcase #1)           FAILED / COMPLETED / VALIDATED (no changes made)
  Entries in file ........ 120
  Imported ............... 112
  Skipped (duplicates) ...   6
  Skipped (other) ........   2
  Authors mapped by name . 98 | fell back to importer: 14
  Warnings ............... 2
Skipped entries
  #7   cover_image "https://example.com/a.png" duplicates existing entry #12
  #15  cover_image "https://example.com/b.png" duplicates entry #9 in this file
Warnings
  Fields not in this collection were ignored: legacy_tag
  14 entries were assigned to the importing user (author not found)
Errors (only when FAILED)
  Missing required fields: category (entries 1, 4, 9 and 17 more); title (all entries)
```

The report also states plainly that on FAILED **nothing was imported**.

### 12. Language strings and templates

**Templates:** none in v1. The ACP uses MyBB's `Form` / `Table` / `PopupMenu` classes like the existing modules. (Front-end
moderator import would add `pageImportExport*.twig` templates in `view/templates/`.)

**Language files:**

* `inc/languages/english/admin/myshowcase_import_export.lang.php` (new, loaded with the module, pattern of
  `myshowcase_json_sync.lang.php`).
* Additions to `admin/myshowcase_module_meta.lang.php`: menu item `myshowcase_admin_import_export`, permission label
  `myshowcase_admin_perm_import_export`.
* Additions to `admin/myshowcase.lang.php`: settings titles/descriptions (exports directory, retention days, max
  entries per export, max import size) and the popup items "Export entries" / "Import entries".

Key groups (English text to be written in implementation):

| Group | Keys (prefix `myshowcase_import_export_`) |
|---|---|
| Page and tabs | `title`, `tab_export`, `tab_import`, `tab_history` |
| Export form | `export_limit`, `export_order_by`, `export_order_direction`, `export_fields`, `export_statuses`, `export_include_author`, `export_include_dates`, `export_include_flags`, `export_select_values`, `export_include_disabled`, `export_submit`, `export_done`, `export_empty` |
| History table | `col_created`, `col_user`, `col_entries`, `col_size`, `col_rules`, `col_hash`, `col_downloads`, `download`, `delete`, `delete_confirm`, `file_deleted`, `rules_summary` |
| Import form | `import_showcase`, `import_file`, `import_status_mode`, `import_fail_on_duplicate`, `import_fail_on_unknown`, `import_validate_only`, `import_submit` |
| Errors | `error_no_file`, `error_upload`, `error_not_xml`, `error_mime`, `error_too_large`, `error_memory`, `error_parse` ({line}, {code}), `error_doctype`, `error_format`, `error_version`, `error_count_mismatch`, `error_collection_mismatch` ({expected}, {found}), `error_missing_required` ({fields}), `error_field_type`, `error_invalid_value`, `error_pl_missing`, `error_directory`, `error_download_missing`, `error_download_hash`, `error_invalid_post_key` |
| Report | `report_title`, `report_total`, `report_imported`, `report_skipped_duplicates`, `report_skipped_other`, `report_authors_mapped`, `report_authors_fallback`, `report_validated_only`, `report_failed_nothing_imported`, `report_duplicate_row` ({entry},{field},{value},{other}), `warning_unknown_fields`, `warning_file_fields_ignored` |
| Logs | `log_import`, `log_export` |

New ACP permission key: `import_export` (added to `myshowcase_admin_permissions()` and `myshowcase_action_handler()`).

---

## Implementation outline (for a later step, not part of this one)

1. Prerequisite: `is_unique` column on `myshowcase_fields` (`Core.php`, ACP field form, `JsonSchema`/`JsonValidator`,
   language strings), plus enforcement in `EntriesManager::validateEntry()` (Decision 1).
2. `Service/ImportExport/XmlFile` (PL wrapper: sanitise, size/memory guard, DOCTYPE rejection, error handler).
3. `Service/ImportExport/EntriesExporter`, `ExportsRepository`, table `myshowcase_exports`, directory manager,
   scheduled-task purge.
4. `Service/ImportExport/EntriesImporter` (validation pipeline, report), `ImportTransaction`.
5. ACP module `import_export.php`, language files, settings, popup menu entries, permission.
6. Tests: there are none in the repo today. Add a scripted verification against the three engines using the existing
   `_boards/t0b-*` fixtures (SQLite is already scripted; MySQL and PostgreSQL boards needed for the portability claim).
   Install PluginLibrary in the `mybb19` test board first (Finding 1).

---

## Decisions needed

Answer by number. The recommended option is first.

1. **Unique fields do not exist yet.** (a) Add an `is_unique` flag to fields as a prerequisite and enforce it on
   normal create/update as well as on import *(recommended)*; (b) add the flag and enforce it on import only;
   (c) treat only the slug-enabled fields as unique instead of adding a flag.
2. **Duplicate policy.** (a) Skip the colliding entry and report it, with an optional "fail on any duplicate" checkbox
   *(recommended)*; (b) always fail the whole import on any duplicate.
3. **A required field that is absent but has a configured default.** (a) Still an error, exactly as the brief states
   *(recommended)*; (b) treat the default as satisfying the requirement.
4. **Collection match rule.** (a) The file's collection `name` must equal the target collection's name, case-insensitive,
   no override *(recommended)*; (b) same, but with an "import anyway" override checkbox; (c) match on field-key
   compatibility only and ignore the name.
5. **Fields in the file that the target collection lacks.** (a) Ignore and warn, with an optional "fail on unknown
   fields" checkbox *(recommended)*; (b) always fail.
6. **Author mapping.** (a) By username on the target board, else the importing admin; the file's uid is never trusted
   *(recommended)*; (b) by uid if the user exists, else the importer; (c) always the importing admin.
7. **Entry status on import.** (a) Keep the file's status with a form override (keep / force approved / force awaiting)
   *(recommended)*; (b) always import as awaiting approval; (c) always as approved.
8. **Select/radio/checkbox values in the file.** (a) Export `value_id` by default with an option for labels; import
   accepts either *(recommended)*; (b) always labels; (c) always ids.
9. **Attachments, comments and edit history in v1.** (a) Out of scope; `file` fields are not exported, ignored on
   import with a warning, and excluded from the required check *(recommended)*; (b) include attachments as
   base64 (large, hits the PL size ceiling immediately).
10. **Export storage.** (a) `inc/plugins/myshowcase/exports/` with `.htaccess`, random file names, and a setting for an
    absolute path outside the web root *(recommended)*; (b) require the admin to configure an outside-web-root path
    before exporting is enabled.
11. **Retention.** (a) Manual delete plus automatic deletion of files after 30 days (setting; 0 = keep), history rows
    kept *(recommended)*; (b) manual only; (c) automatic only.
12. **Size limits.** (a) Export 5,000 entries per file, import 5 MB (both settings) *(recommended; derived from the
    measurements: PL needs about 28x memory and fails near 10 MB)*; (b) higher caps, accepting `memory_limit` fatals
    on typical 128 MB hosts; (c) lower caps (2 MB / 2,000 entries) for safety on small hosts.
13. **Where it lives and who may use it.** (a) ACP only, new `import_export` ACP permission; any admin holding it may
    download any export *(recommended)*; (b) ACP plus a front-end tool for showcase moderators in v1.
14. **PluginLibrary hardening.** (a) Use PL's two methods only behind a wrapper that strips illegal characters, rejects
    DOCTYPE/ENTITY, caps size, mutes its notices, and treats `null` + error as failure *(recommended)*; (b) call PL
    directly and document the limitations; (c) write our own XML reader/writer (departs from the brief).
15. **Validate-only mode.** (a) Include a "Validate only" button that runs everything except the insert
    *(recommended; the validation pipeline is the same code)*; (b) omit it in v1.
16. **Import audit trail.** (a) One `myshowcase_logs` row per import (new log type, JSON summary in `log_data`), no
    new table *(recommended)*; (b) a dedicated `myshowcase_imports` table symmetrical with exports; (c) no record.
17. **Transactions.** (a) Validate-first, then a raw-SQL transaction where supported, plus compensating delete
    *(recommended)*; (b) validate-first plus compensating delete only (no raw transaction statements).
18. **Export form options.** (a) The set in design 3 (limit, order, fields, statuses, author, dates, flags, select-value
    mode, include disabled) *(recommended)*; (b) add more filters (author, date range); say which.
