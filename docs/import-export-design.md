# MyShowcase entries import / export: design (revision 2)

Status: **design complete, all decisions taken, awaiting go-ahead to implement**. No plugin code has been changed.
Branch: `feature/import-export-design` (from `main` @ `21bc2b0`). Plugin version: `3.0.0` (`VERSION_CODE` 3000;
`CLAUDE.md` still says 2.5.2).

Revision 2 replaces the ACP-based design with a **forum-side feature** built on one principle:

> **Imported entries pass through the exact same entry validator the forum create form uses, and that validator is the
> only per-entry verification.** The import adds no value checks of its own. If the validator does not check something
> for the form, the import does not check it either.

---

## 0. Key findings

1. **The validator enforces no uniqueness at all** (section B.3). Not on any custom field, not on the subject, not on
   `entry_slug_custom`. The only uniqueness logic is the *generation* of the random `public_id`, which retries until
   it finds an unused token. **Consequence: importing the same file twice creates every entry twice.** Two entries
   created through the form with identical content also coexist today. This design does not change that.
2. **The validator skips fields whose key is not set on the entry** (section B.3). A required field that is simply
   absent is *not* rejected by it. To get "a required field absent from the file is rejected by the validator", the
   import must apply defaults and then present every required field to the validator as an empty value (the
   *defaulting step*, design 6). This is preparation of the input, not an extra check.

   **Update:** the per-field checks (required, regular expression, numeric, length) were not merely skipping absent
   fields: they never ran on the form at all, because they looped over `$entry->getFields()`, which nothing ever fills,
   so an entry with a required field left empty was saved. This is **fixed in commit `2c3b800`** (branch
   `feature/import-export`), which runs the checks over the collection's field entities. The validator-only import design
   depends on that fix: without it, "the validator rejects a missing required field" would have been false.
3. **The validator object accumulates state across calls** (`validation_errors` and `validation_data_is_valid` are
   never reset by `validateSet()`), and errors are keyed by message name, so two missing required fields produce a
   single `required_field` error (the last one wins). Validating many entries needs a fresh `EntriesManager` per entry,
   and a useful per-entry error list needs the form path fixed to key errors by message *and* arguments (a small
   improvement that also benefits the form; flagged as a prerequisite, section Implementation outline).
4. **Moderation queue, flood limits and per-user limits are not enforced by the form today** (moderation and the per-user limit are fixed by the prerequisite refactors, T21; the flood check is not). `EntriesModerateNew` has
   an empty branch in `EntriesController::create()` (the assignment is commented out), and `EntriesFloodTime` /
   `EntriesLimitPost*` exist as permission definitions but nothing reads them. New entries therefore get the column
   default `status = 1` (approved). "Exactly what the form would give this user" means the import gets the same
   outcome, and if the form later starts enforcing these, the import must pick it up by sharing the same code (B.5).
5. **PluginLibrary is not installed on the test board** (`~/Documents/Git/mybb19/inc/plugins/` has only
   `index.html`). It must be installed there before implementation (A.7). The measurements below used the copy in
   `myshowcase-worktrees/_deps/MyBB-PluginLibrary` (`version` `14-dev`, `$version = 13`, identical to
   `_boards/t0b-sqlite`) on PHP 8.5.12 / libxml 2.15.
6. **MyBB's database layer has no transaction API** on any of the three engines (section D).
7. **XML 1.0 cannot represent most control characters, even as character references** (verified: `&#1;` makes the
   parser fail with `Invalid character`). The requested "encode control characters as character references" is
   possible for `\r` (and tab/newline) only; other control characters must be removed (A.6).

---

## A. PluginLibrary XML methods

File `inc/plugins/pluginlibrary.php`, class `PluginLibrary`, global `$PL`.

### A.1 `xml_export`

```php
public function xml_export($data, $filename = false,
    $comment = 'MyBB PluginLibrary XML-Export :: {time}', $endcomment = 'End of file.')
```

* `$data`: nested array or scalar. An array becomes `<array><element><key>K</key><value>V</value></element>...</array>`.
* Returns the XML string (declaration `encoding="UTF-8"`, a comment with `{time}` replaced by `date('c', TIME_NOW)`,
  the data, a trailing comment).
* **A truthy `$filename` makes it send download headers, echo, and `exit`.** We always pass `false` and write the string
  to disk ourselves.

| PHP value | Output | Notes |
|---|---|---|
| string | `<value>text</value>` | shorter of `htmlspecialchars()` or CDATA (with `]]>` split). `''` gives `<value></value>`. |
| bool / int / float | `<value type="BOOL\|INT\|FLOAT">` | false is an empty element. |
| array | nested `<array>` | empty array gives `<array>\n</array>`. |
| **null** | **element omitted** | `_xml_array` skips any element whose value renders as `''`. |

### A.2 `xml_import`

```php
public function xml_import($xml, &$error = null)
```

Takes the XML **string**, returns the array or `null` with `$error` (`line`, `code`, `error`, `message`) on a parse
failure, or `['message' => 'XML is valid, but there is no data to import.']` for valid non-PL XML. Uses
`xml_parse_into_struct` (whole document in memory) and a stack walk; only the `type` attribute is read.

### A.3 Measured behaviour

| Probe | Result |
|---|---|
| Round trip of `< > & " '`, `]]>`, newlines, UTF-8 incl. CJK/emoji, `'0'`, `''`, ints, floats, bools, empty arrays, int keys | Identical except `null` (dropped) and `\r\n` (becomes `\n`). |
| `\x01` in a value | Export writes **invalid XML**; import fails (`Invalid character`). |
| Invalid UTF-8 | Silently replaced with U+FFFD. |
| Truncated XML | `null` + `Tag not finished` with line number and source line. |
| ISO-8859-1 declared / UTF-8 BOM | Both accepted. |
| External entity (`file://`) | **Not resolved** (empty string). Internal entities are expanded. A six-level "billion laughs" was capped by libxml and silently truncated. |
| 2.2 MB / 2,000 entries | 0.2 s, 65 MB peak. |
| 5.6 MB / 5,000 entries | 0.5 s, **159 MB peak** (about 28x). With `memory_limit=128M`: fatal error, not an error array. |
| **11.7 MB / 10,000 entries** | **Returns `null` with `message: "Unknown"`** (no line/code). Peak 341 MB. |
| Warnings (PHP 8.5) | Per element: `Undefined array key "attributes"`, `strtoupper(): Passing null` (deprecated); on error `Undefined variable $result`; `xml_parser_free()` deprecated. Hundreds of thousands of notices at 5,000 entries. |

### A.4 Limits and safety

* No size/depth/count limit of its own; practical limits are `memory_limit` (about 28x the file) and a hard failure near
  10 MB. Export holds both the data array and the string in memory.
* XXE: not reproducible on this stack (PHP >= 8.0 / libxml >= 2.9 disable external entity loading), but the default
  cannot be assumed on every host, so the wrapper rejects any DOCTYPE (A.5).

### A.5 The wrapper (`Util/XmlFile`)

PluginLibrary's two methods remain the XML engine; they are only called through this thin layer.

**Export (`write(array $data): string`)**

1. Walk the data: UTF-8 validate (`mb_scrub`); remove every character not legal in XML 1.0
   (`[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]`).
2. Encode `\r` as a character reference: replace `\r` with the now-free token `\x01` before PL, then after PL turn each
   token into `&#13;`, using `]]>&#13;<![CDATA[` when the token sits inside a CDATA section (PL picks CDATA when it is
   shorter). Tested: values containing `\r\n`, `\r`, `]]>` round-trip byte-exact; plain `htmlspecialchars` output
   needs no change for tab/newline.
3. Mark nulls explicitly: a PHP `null` becomes the one-element array `['__null__' => true]`, which PL writes as
   `<array><element><key>__null__</key><value type="BOOL">1</value></element></array>`. Tested: restores to `null`
   on read, including nested.
4. Call `$PL->xml_export($data, false, $comment, $endcomment)`; never pass a filename.

**Import (`read(string $xml): array`, throws `XmlFileException` with line/code/message)**

1. Reject if the input contains `<!DOCTYPE` or `<!ENTITY` (case-insensitive, after stripping a BOM).
2. Parse once with `DOMDocument::loadXML($xml, LIBXML_NONET)` (no `LIBXML_NOENT`, no `LIBXML_DTDLOAD`) under
   `libxml_use_internal_errors(true)` to get a reliable well-formedness error with line/column and to confirm the root
   is `<array>`.
3. Size and memory guard (design 7) before step 2.
4. Run `$PL->xml_import($xml, $error)` under a temporary `set_error_handler` that swallows `E_WARNING`, `E_NOTICE` and
   `E_DEPRECATED` raised from `pluginlibrary.php` only (other errors pass through). Treat a `null` result as failure
   and build the message from `$error` (replace PL's `"Unknown"` with our own text).
5. Walk the result and turn every `['__null__' => true]` back into `null`.

**Upstream:** PluginLibrary's per-element PHP 8.x warnings (`attributes` undefined key, `strtoupper(null)`), the
undefined `$result` on error, the dropped `null`, the silent failure around 10 MB, and the deprecated
`xml_parser_free()` should be reported to the PluginLibrary project (mybbgroup). The wrapper should stay even after
fixes, because the supported PL version floor is 13.

### A.6 Control characters

`\r` is preserved via `&#13;` (A.5). Tab and newline are legal and preserved natively. The other C0 controls
(`\x00`-`\x08`, `\x0B`, `\x0C`, `\x0E`-`\x1F`) cannot be encoded in XML 1.0 in any form, so they are **removed on export**
and the export summary records how many characters were removed (shown with the export's rules). They never occur in
text typed into a browser form, so the practical loss is nil.

### A.7 PluginLibrary availability

* Required for implementation and testing: copy `pluginlibrary.php` from
  `~/Documents/Git/myshowcase-worktrees/_deps/MyBB-PluginLibrary/inc/plugins/` into
  `~/Documents/Git/mybb19/inc/plugins/` (the plugin already requires PL >= 13 via `pluginInformation()['pl']`).
* The plugin currently loads PL only in the ACP (`loadPluginLibrary()` in `admin.php`, `PLUGINLIBRARY` constant in
  `myshowcase.php`). The front-end pages must load it themselves (`require_once PLUGINLIBRARY` if `$PL` is not set) and
  **show a clear error page when the file is missing or `$PL->version < 13`**, for both the export and import pages, never
  a fatal error.

---

## B. MyShowcase data model

### B.1 Tables

| Table | Purpose |
|---|---|
| `myshowcase_collections` | Field sets: `collection_id`, `name`, JSON-sync columns. |
| `myshowcase_fields` | One row per field. Unique `(collection_id, field_key)`. |
| `myshowcase_field_data` | Selectable values for select/radio/checkbox fields (`value_id`, `value`, group permissions). |
| `myshowcase_config` | One row per showcase (`showcase_id`, `collection_id`, `route_identifier`, settings). |
| `myshowcase_data{showcase_id}` | **Entries**, one table per showcase: base columns plus one column per field (`field_key`). |
| `myshowcase_permissions` | Per-showcase, per-group (and per-user) boolean/numeric permissions, one column per permission. |
| `myshowcase_moderators` | Per-showcase moderators. |
| `myshowcase_logs` | Generic log (`showcase_id`, `type_id`, `unique_id`, `user_id`, `log_time`, `log_data`). |
| comments, attachments, history tables | Out of scope (B.4). |

*Showcase vs collection:* several showcases may share one collection; ids differ between boards. The export header
carries the collection **name** (for the warning-only name check) and the field catalogue.

### B.2 Field flags

| Need | Column |
|---|---|
| Key (matching key, entries-table column) | `field_key` VARCHAR(30) |
| Required | `is_required` |
| Default | `default_value` + `default_type` (`0 AsDefined`, `1 IsNull`, `2 CurrentTimestamp`, `3 UUID`) |
| Type | `html_type`, `field_type` (`file` fields hold attachments, not column data) |
| Enabled / read-only / who may fill / who may view | `is_enabled`, `read_only`, `allowed_groups_fill`, `allowed_groups_view` |
| Per-view flags | `display_in_view_page`, `display_in_main_page`, `display_in_create_update_page` |

There is **no uniqueness flag**, and none is added (see Core principle).

### B.3 What the validator does (`EntriesManager::validateEntry()`)

Checked, in order, for an insert:

* captcha, if the user's group requires it (`MainRequireCaptcha`);
* the entry's user exists and the user holds `EntriesCanPost`;
* subject length and message length against the showcase settings (and unset when those are disabled);
* post icon exists; dateline, status, closed/sticky, counters, signature/smilies flags are sane;
* ip address valid;
* **per custom field**, only for fields where the key is set on the entry, the field is viewable in the form for the
  current user (enabled, `allowed_groups_fill`), and not `read_only`: the field's regular expression; `is_required`
  (rejects `empty()`, which includes `'0'`, and for `file` fields requires an attachment); then, if the value is empty
  and `default_value` is not `''`, the default is substituted; numeric check for integer column types; scalar check,
  minimum length and maximum length for char/text types;
* `filter_on_save` (UUID generation) and `entry_slug_custom` generation;
* the `entry_validate_*` hooks.

**Not checked anywhere: uniqueness of any kind.** `public_id` is generated by a retry loop in `validationEntryInsert()`.
No field, subject or `entry_slug_custom` value is compared against existing entries. There is no unique index except on
`public_id` (and the random `post_hash` is not unique-indexed). **Importing the same file twice therefore creates
duplicates, exactly as submitting the same form twice does.** Also not checked: that a select/radio value exists in
`myshowcase_field_data` (only `is_numeric`), which is why the import does not check it either.

Other consequences of "same validator":

* A required `file` field is satisfied by its URL once the file has been checked and is to be attached (T28; the validator counts the pending files).
  for such a showcase and import fails with the validator's own message. This is stated on the import page.
* A group that needs a captcha cannot import: the validator would demand a captcha for each entry. The import page
  refuses up front with a clear message instead of changing the validator.
* The validator skips (does not reject) values for fields the user cannot fill; `insertEntry()` would still store
  them. See Decision O1.

### B.4 Entry-level data and scope

Imported entry content is **`subject`, `message` and custom field values only.** Everything else is what the form gives
the current user: `user_id`/`username` = current user, `dateline` = now, `status` = what the form path assigns (column
default today, see Finding 4), `ipaddress` = current IP, new `public_id`/`post_hash`, counters 0, `is_closed`/`is_sticky`
defaults. The file's author, dates, status, flags, counters are neither exported nor read. **Out of scope for v1:**
attachments that are not file fields, comments, edit history. A `file` field is imported from its URL (T28).

### B.5 Insert path and sharing code with the form

The form builds its entry with `EntriesManager::generateEntry()`, loads field objects through
`BaseController::loadEntryFields()` (protected, in the controller), validates, then calls `validationEntryInsert()`
(which calls `EntriesRepository::insertEntry()`, applies defaults for unset keys, fires hooks). The import uses the same
three calls per entry. Prerequisite refactor: move `loadEntryFields()`-style field construction and the "status for a
new entry" decision into a shared service so form and import cannot diverge.

---

## C. Front-end structure and permissions

* **Routing:** Symfony routes in `src/Routing/Routing.php` with names in `Enum/Routes.php`
  (e.g. `/{showcase}/create` mapped to `EntriesController::create`). Pages are rendered with Twig templates from
  `view/templates/*.twig` through `RenderManager::templateGet()` (the template name is the file name without `.twig`).
* **Permissions:** `Enum/UserPermissions.php` constants, checked with `$this->context->current_user->hasPermission(...)`.
  Registered in `Plugin/Core.php` (`myshowcase_permissions` column), `Security/PermissionsFactory.php` (admin form
  metadata), `Entity/PermissionEntity.php` (constructor property), admin language strings
  (`admin/myshowcase.lang.php`), and `showcaseDefaultPermissions()` in `Plugin/Functions.php`.
* **Visibility rule to reuse** (`EntriesManager::visibilityWhereClause()`, see `Documentation/visibility.md`): approved
  entries are visible to everyone who may view the showcase; awaiting-approval entries need `entries_can_view_unapproved`
  and soft-deleted ones `entries_can_view_deleted`, and then only the user's own, or any user's for a moderator. Field visibility: `CustomFieldEntity::isViewableInMain/Entry()`
  (`is_enabled` + `allowed_groups_view` via `is_member`).
* **Scheduled task:** `inc/tasks/myshowcase.php` (`task_myshowcase`) already runs per-showcase maintenance; retention
  cleanup goes there.
* **CSRF:** existing POST handlers call `verify_post_check($mybb->get_input('my_post_key'))`; templates receive
  `mybb.post_code` as `my_post_key`.

---

## Design

### 1. XML schema

PluginLibrary fixes the XML dialect (`<array><element><key/><value/></element></array>`), so the "schema" is the PHP
structure below. Top level: `header`, `entries`.

`header`:

| Key | Type | Meaning |
|---|---|---|
| `format` | string | Always `myshowcase-entries`. |
| `format_version` | int | `1`. A higher number is rejected. |
| `myshowcase_version` | string | Writer version (shown in the import report). |
| `generated` | string | ISO 8601 UTC. |
| `showcase` | array | `route_identifier`, `name` (informational). |
| `collection` | array | `name`. A mismatch with the target collection is **a warning only**. |
| `entry_count` | int | Must equal the real number of entries (structural). |
| `fields` | list | Exported fields: `field_key`, `html_type`, `field_type` (informational; matching uses the keys in each entry). |
| `rules` | array | `limit`, `limit_cap` (the 5,000 in force), `order_by`, `order_dir`, `fields` (selected keys), `options` (every form option chosen), `removed_characters`. |

`entries`: a list; each item has optional `subject`, optional `message`, and `fields` (`field_key => value`). Nothing else
is read.

Full sample, produced with the real `PluginLibrary::xml_export` through the wrapper logic described in A.5 (note the
`&#13;` in entry 1's message and the explicit null marker for `summary` in entry 2):

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!-- MyShowcase entries export :: 2026-11-12T09:30:00+00:00 -->
<array>
  <element>
    <key>__null__</key>
    <value type="BOOL">1</value>
  </element>
</array>
<!-- End of MyShowcase export. -->
NULL
bool(true)
bool(true)
NULL
string(17) "Invalid character"
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
                      <value>subject</value>
                    </element>
                    <element>
                      <key type="INT">1</key>
                      <value>message</value>
                    </element>
                    <element>
                      <key type="INT">2</key>
                      <value>category</value>
                    </element>
                    <element>
                      <key type="INT">3</key>
                      <value>summary</value>
                    </element>
                    <element>
                      <key type="INT">4</key>
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
                      <key>include_awaiting_approval</key>
                      <value type="INT">0</value>
                    </element>
                    <element>
                      <key>include_soft_deleted</key>
                      <value type="INT">0</value>
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
                <key>subject</key>
                <value>First post &amp; more</value>
              </element>
              <element>
                <key>message</key>
                <value><![CDATA[Hello <b>world</b>]]>&#13;<![CDATA[
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
                <key>subject</key>
                <value>Second post</value>
              </element>
              <element>
                <key>message</key>
                <value>Body text</value>
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
                      <value>
                        <array>
                          <element>
                            <key>__null__</key>
                            <value type="BOOL">1</value>
                          </element>
                        </array>
                      </value>
                    </element>
                    <element>
                      <key>cover_image</key>
                      <value></value>
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

* **Default directory:** `inc/plugins/myshowcase/exports/`, created on first export with `.htaccess` (`Require all denied`,
  same text as `imports/.htaccess`) and `index.html`. Setting `exportsDirectory` (in the `SETTINGS` constant block of
  `myshowcase.php`, like `slugLength`) can point to an absolute path **outside the web root**, which is recommended; the
  page documentation says so because `.htaccess` is ignored by nginx.
* **Disk name:** `{export_id}-{route_identifier}-{YYYYMMDD-HHMMSS}-{32 random hex}.xml`; unguessable and never taken from
  user input. **Download name** (header only): `myshowcase-{route_identifier}-{YYYYMMDD-HHMMSS}.xml`.
* **Cap:** 5,000 entries per export (a limit above it is **clamped** to 5,000 and the user is told; `0` means as many as the
  cap allows), and the generated file must not exceed the **import size cap** of the same server
  (design 7). The exporter builds the XML, measures it, and if it is larger than the cap it **refuses to save** and tells
  the user how many entries fit (estimated from the average entry size) so they can lower the limit or select fewer
  fields. Every stored export is therefore re-importable on that server.

### 3. Permissions (new, per showcase, per usergroup)

Two new boolean permissions, **disabled by default**:

| Permission | Constant | Column in `myshowcase_permissions` |
|---|---|---|
| Can export entries | `UserPermissions::EntriesCanExport = 'entries_can_export'` | TINYINT unsigned, `default` 0 |
| Can import entries | `UserPermissions::EntriesCanImport = 'entries_can_import'` | TINYINT unsigned, `default` 0 |

Registered at each place in C: column in `Core.php` with `'default' => 0`; entry in `PermissionsFactory`
(`is_group_permission => true`, `is_user_permission => false`, `is_moderator_permission => false`, `category =>
'entries'`, `input_type => FormTypes::PermissionBoolean`); property in `PermissionEntity`; `false` in
`showcaseDefaultPermissions()`; admin language strings. Existing installs: `dbVerifyTables()` in plugin activation adds
missing columns (to be confirmed by activating on a populated test board), and the column default 0 leaves every
existing group disabled. Guests never qualify (uid 0 is refused regardless of the permission). Importing additionally
requires what the validator requires: `EntriesCanPost` (checked by the validator itself).

### 4. Export visibility (what an export contains)

An export is generated with the exporting user's own view of the showcase, never more:

* **Entries:** one visibility rule, the same for the listing, the detail page, the export and every other place that loads
  entries (branch `security/moderation-access`). Approved entries are visible to everyone who may view the showcase.
  Awaiting-approval entries need `entries_can_view_unapproved`, soft-deleted ones `entries_can_view_deleted` (before this change
  nothing read them). A user without the permission sees none of that status, **not even their own**. With the permission, a
  non-moderator sees **their own** entries of that status and never other users'; a moderator sees **any** user's entries of
  that status. The rule lives in `EntriesManager::canViewStatus($status, $owner_id)` and nowhere else;
  `visibilityWhereClause()` is built from it. The moderation action permissions (`entries_can_approve`, `entries_can_restore`, ...)
  are not used for viewing.
  **One moderator notion:** `EntriesManager::isModerator()`, the only place that decides it. A user is a moderator of the
  showcase when a `myshowcase_moderators` row for it matches them (`group_id` is the type: 0 = row for a user, `user_id` is the
  uid; 1 = row for a usergroup, `user_id` is the usergroup id, matched against all the user's groups), or when their usergroup has
  the MyBB super-moderator or administrator flag (`issupermod`, `cancp`). The flags in the moderators row (`entries_can_view`,
  `can_manage_*`) are not part of this notion.
  The export form offers the awaiting/deleted options only when the rule lets the user see some entries of that status, the
  exporter applies `visibilityWhereClause()`, and a forged POST asking for more is ignored, so an export holds exactly what the
  listing shows that user, per group type. (This replaces both the first version, which used `entries_can_view`, and the
  Stage A version, which used `entries_can_approve`/`entries_can_restore`.)
* **Fields:** only fields the user may view (`is_enabled` and `allowed_groups_view` via `is_member`). `file` fields are never
  exported (out of scope). See Decision O1 for whether the field list is additionally limited to fields the user may fill.
* Subject and message are included only when enabled for the showcase.

### 5. Export records, ownership and storage

Table `myshowcase_exports` (added to `TABLES_DATA`, installer conventions as for the other tables: typed column arrays,
`unsigned`, `default`, DATETIME for dates, no engine-specific SQL; created by `dbVerifyTables()` on activation, so it
works on MySQL, PostgreSQL and SQLite through `SchemaManager::createTableSql`):

| Column | Type | Notes |
|---|---|---|
| `export_id` | INT unsigned, auto_increment, PK | |
| `showcase_id`, `collection_id` | INT unsigned | |
| `user_id` | INT unsigned | **owner** (creator) |
| `created_at` | DATETIME | |
| `file_name` | VARCHAR(120), unique_key | disk name |
| `file_size` | INT unsigned | bytes |
| `file_hash` | VARCHAR(64) | SHA-256 |
| `entry_count` | INT unsigned | |
| `entry_limit` | INT unsigned | rule: limit (0 = up to the cap) |
| `order_by` | VARCHAR(30) | rule: sort column or field key |
| `order_direction` | VARCHAR(4) | rule: `asc` / `desc` |
| `selected_fields` | VARCHAR(1000) | rule: comma-separated keys |
| `rules` | TEXT, null | rule: complete `header.rules` as JSON (every form option, removed-character count) |
| `plugin_version` | VARCHAR(20) | |
| `file_deleted` | TINYINT unsigned | set when the file is removed; row kept as history until pruned |

Index on `(user_id, showcase_id, created_at)`. There is no download counter, so the download request changes no state.

**Ownership:** exports belong to their creator. Only the creator can list, download and delete them; every query is
filtered by `user_id = current user`, so a guessed `export_id` of someone else's export returns "not found".
**Admins and super moderators: no access** (recommended, see Decision O2): an export is a snapshot of what its creator was
allowed to see, and a moderator reading someone else's snapshot could expose entries or fields hidden from their own
group. Board owners keep direct database/file access, and the retention task removes old files without reading them.

**Per-user cap:** at most **10** stored (non-deleted) exports per user per showcase (per-showcase config value
`exports_per_user_maximum`, default 10). Creating an 11th is refused with a message to delete an older one.
**Flood check:** one export or import per **60 seconds** per user per showcase (config value
`import_export_flood_seconds`, default 60; 0 disables), checked against the latest `created_at` for exports and the latest
import log row for imports. Neither value is enforced for the form today (Finding 4), so these are new, import/export-only
settings added as `myshowcase_config` columns (ACP showcase form picks them up through the existing `category` /
`input_type` metadata).

### 6. Import pipeline (validate everything, then write)

Page: `/{showcase}/import`. Each stage reports **all** problems it finds in that stage, then stops. Nothing is written
before stage 7.

1. **Gate:** user holds `EntriesCanImport` and `EntriesCanPost`, is logged in, PluginLibrary present (A.7), group needs no
   captcha, flood check passes. The per-user entry limit is checked with the number of entries in the file once it is
   parsed (stage 5): existing count + N must not exceed the limit, otherwise the import fails as a whole (T21). POST only for the submit; `verify_post_check`.
2. **Upload:** `UPLOAD_ERR_OK`, `is_uploaded_file`, size > 0 and <= cap (design 7); memory estimate (30 x size) fits
   `memory_limit` minus current usage. File is read from the PHP temp path and never stored in the web root.
3. **File type (XML only):** client extension `.xml` (case-insensitive); MIME by `finfo` is `application/xml`, `text/xml`
   or `text/plain`; first non-BOM byte is `<`; and it must **parse** (stage 4). Anything else is rejected before parsing.
4. **Parse:** the wrapper's `read()` (DOCTYPE rejected, DOM well-formed, PL import, null markers restored).
5. **Structural checks** (the only import-specific checks; all run before any validation):
   * `header.format === 'myshowcase-entries'`, `format_version <= 1`, `entries` is a list, `entry_count` equals the real
     count, each entry is an array whose `fields` (if present) is an array;
   * **every field key used in any entry's `fields` must exist in the target collection, matched exactly
     (case-sensitive).** Any unknown key **fails the import**; the error lists each unknown key and the entry numbers that
     use it. (A key naming a `file` field is checked here as the others, and its URL is read by the importer, T28.) **Fillable fields only
     (T18, T23):** a key must also name a field the importing user may fill, `CustomFieldEntity::canFill()` (enabled, not
     read-only, in a group listed in `allowed_groups_fill`); the same rule the form now applies to posted input.
   * `header.collection.name` differing from the target collection name is a **warning** in the report, not an error (T3).
     `header.showcase.route_identifier` differing from the target showcase's identifier **refuses the file**.
6. **Defaulting step, then validation, per entry, in file order**, with a **fresh `EntriesManager` per entry**:
   1. Build the entry data from the file: `subject`, `message`, and the `fields` map. A `null` value is treated as absent.
   2. **Defaults for absent fields (T20):** for every enabled collection field whose key is absent (or null) in this
      entry, fill in the configured default, the same value the form pre-fills in its input
      (`AsDefined` value, current timestamp, UUID; the shared field-values code from the prerequisite refactors). **A
      required field that is absent from the file is therefore filled with its default and passes.** It is rejected
      only when it has no default: it then gets `''` so the validator sees it and rejects it (Finding 2). Optional absent
      fields with no default stay unset (column default, as for the form).
   3. `generateEntry()` + `validateSet(…, INSERT)` + `validateEntry()` as the form does. Collect the error list; keep the
      validated `EntryEntity`.
   4. Present-but-empty values are passed as they are (the validator decides; for a required field `''` is rejected,
      for an optional field it is stored empty and the default is **not** applied).
7. **All-or-nothing decision:** if the validator rejected **any** entry, for any reason, the import **fails and nothing is
   written**. The report lists each failing entry by its **position in the file** (1-based, with `subject` for
   orientation) and the validator's error messages. Entries that passed are listed only as a count.
8. **Insert** (only when every entry passed; section D): begin transaction; for each validated entry call
   `validateSet()` then `validationEntryInsert()` (which runs `insertEntry()` and the insert hooks); write one
   `myshowcase_logs` row (new log type "import", `log_data` JSON summary); commit; then refresh any caches/counters a normal
   create refreshes. On exception: roll back and run the compensating delete.

**Validate only:** the import page has a "Validate only" button that runs stages 1 to 7 and shows the report without
stage 8 (same code path).

**Duplicates:** there is no duplicate detection (Finding 1). The import page says so, and shows the file's SHA-256 and the
time of the user's previous import of the same hash (from the log) as a *hint only*; it does not block.

### 7. Limits

* **Import size cap = `memory_limit / 40`**, but never above **5 MB**, never below a floor of 256 KB (bytes parsed from
  `ini_get('memory_limit')`; `-1` means the 5 MB ceiling). Measured overhead is about 28x file size (A.3), so 40x leaves
  headroom for the validator's own memory. Examples: 128M gives 3.2 MB, 256M and above give 5 MB, 32M gives 0.8 MB.
* **Effective cap:** the formula above and no more than PHP lets one request carry (the smaller of `upload_max_filesize` and
  `post_max_size`; 0 or -1 is no limit): `ImportLimits::effectiveCap()`. On a server whose upload limit is smaller than the
  formula the export would otherwise produce files that cannot be uploaded again, and an upload over `post_max_size` arrives
  with no fields at all (the import page recognises that and says "file too large").
* **Export cap:** 5,000 entries and the effective cap above (design 2).
* **Time:** validation does several queries per entry (user lookup, slug field data), so the page raises its time limit
  with `set_time_limit` where allowed and processes the entries in one request; this is the reason for the 5,000-entry cap.

### 8. Download, deletion, CSRF, routes

* **All state-changing actions are POST with `my_post_key`:** create export, delete export, validate/import. The only GET
  routes are the two pages and the download, which changes nothing.
* **Routes** (names in `Enum/Routes.php`):

  | Route | Method | Path | Purpose |
  |---|---|---|---|
  | `ExportMain` | GET and POST | `/{showcase}/export` | GET: form and the user's stored exports with their rules; POST: generate |
  | `ExportView` | GET | `/{showcase}/export/{export_id}` | detail page of one export, with its rules |
  | `ExportDownload` | GET | `/{showcase}/export/{export_id}/download` | stream the file |
  | `ExportDelete` | POST | `/{showcase}/export/{export_id}/delete` | delete |
  | `ImportMain` | GET and POST | `/{showcase}/import` | GET: upload form; POST: validate / import, shows the report |

  The router (like the existing `EntryCreate` route) does not tell GET from POST, so there is no separate `ExportCreate` or
  `ImportSubmit` route: the controller decides on the request method, and `ExportDelete` refuses anything but a POST.

* **Download:** looks the row up by `export_id` **and** `user_id = current user` **and** `file_deleted = 0`, requires
  `EntriesCanExport` (so revoking the permission also stops downloads), resolves the path with `realpath` inside the exports
  directory, compares `hash_file('sha256')` with `file_hash` (mismatch aborts), then streams with
  `Content-Type: application/xml; charset=UTF-8`, `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`,
  `Cache-Control: private, no-store`, `Content-Length`. No public URL exists.

### 9. Rules display

Every export row (list on the export page) shows its rules from the stored columns and JSON: **limit** (and the number of
entries actually written), **sort order** (field and direction), **selected fields** (labels), and **every form option used**
(entry-status options if the exporter was a moderator, plus the count of control characters removed). The same data is in
the file's `header.rules`, so a file can be understood without the board.

**Export form options:** entry limit; sort by (entry date, last update, subject, views, or any exportable field) and
direction; fields to include (default all exportable); include awaiting-approval entries and include soft-deleted entries.
The two inclusion options follow the standing visibility rule: a non-moderator gets their own awaiting-approval or
soft-deleted entries only with `entries_can_view_unapproved` or `entries_can_view_deleted`; a moderator gets any user's.

### 10. Retention

30 days of storage plus manual delete. The `task_myshowcase` scheduled task deletes the files of exports older than
**30 days** (`SETTINGS['exportsRetentionDays']`; 0 disables) and sets `file_deleted = 1`; history rows are removed 30 days
after that. Manual delete (POST) removes the file immediately and the row. Uninstall removes the table and directory.

### 11. Import report

Rendered on the import page after a POST, from the `ImportReport` value object:

```
Import of "entries-blog.xml" into Blog                         COMPLETED | FAILED (nothing was imported) | VALIDATED (no changes made)
  Entries in file ....... 120
  Imported .............. 120
  Warnings
    The file was exported from collection "Blog 2"; this showcase uses "Blog".
Failures (only when FAILED)
  Unknown field(s): legacy_tag (entries 4, 9 and 17 more)
  Entry 7 ("Second post"):  Required field "Category" is missing.
  Entry 15 ("Cover test"):  The value of "Summary" is longer than the maximum of 500 characters.
  ...
```

Per-entry error text is exactly what the validator returns (after the prerequisite fix in Finding 3, one line per
distinct error). On COMPLETED the entries are created as the current user and follow the form's moderation outcome.

### 12. Templates and language strings

**Twig templates** (new, in `view/templates/`; `custom_template_prefix` overrides keep working):

| Template | Purpose |
|---|---|
| `pageExport.twig` | export form + list of the user's exports, errors |
| `pageExportRules.twig` | partial: the rules block of one export (limit, sort, fields, options) |
| `pageExportRow.twig` | partial: one export row with download and delete (POST form with `my_post_key`) |
| `pageImport.twig` | upload form, validate-only button, notes (no duplicate detection, size cap, XML only, same board only) |
| `pageImportReport.twig` | partial: the result report |
| `pageImportExportMissingLibrary.twig` | the "PluginLibrary is required" error shown by both pages |
| `pageMain.twig` (edit) | "Export" / "Import" buttons, shown by permission |

**Front-end language strings** in `inc/languages/english/myshowcase.lang.php` (prefix `myshowcase_import_export_`):

| Group | Keys |
|---|---|
| Buttons / titles | `button_export`, `button_import`, `title_export`, `title_import` |
| Export form | `export_limit`, `export_limit_description`, `export_sort_by`, `export_sort_direction`, `export_fields`, `export_include_awaiting`, `export_include_deleted`, `export_submit` |
| Export list | `col_created`, `col_entries`, `col_size`, `col_rules`, `download`, `delete`, `delete_confirm`, `none_stored`, `rules_limit`, `rules_sort`, `rules_fields`, `rules_options`, `rules_removed_characters` |
| Export messages | `export_done`, `export_too_large` ({1} entries fit), `export_empty`, `export_cap_reached` ({1} stored exports), `export_flood` ({1} seconds), `export_deleted` |
| Import form | `import_file`, `import_validate_only`, `import_submit`, `import_note_xml_only`, `import_note_same_board`, `import_note_size` ({1}), `import_note_duplicates` |
| Import errors | `error_no_file`, `error_upload`, `error_extension`, `error_mime`, `error_too_large` ({1}), `error_memory`, `error_parse` ({1} line, {2} text), `error_doctype`, `error_format`, `error_version`, `error_count_mismatch`, `error_unknown_fields` ({1}), `error_captcha_group`, `error_flood`, `error_entry_limit` ({1} limit, {2} existing, {3} in file) |
| Report | `report_title`, `report_completed`, `report_failed`, `report_validated`, `report_total`, `report_imported`, `report_warnings`, `report_failures`, `report_entry_position` ({1} position, {2} subject), `warning_collection_name` ({1} found, {2} expected), `report_hash_seen` ({1} date) |
| Library / access | `error_library_missing`, `error_library_version`, `error_no_permission`, `error_not_found` |
| Logs | `log_import` |

**Admin language strings** (`inc/languages/english/admin/myshowcase.lang.php`):
`myshowcase_admin_permissions_entries_can_export` / `_description`,
`myshowcase_admin_permissions_entries_can_import` / `_description`, and the labels for the new showcase settings
(`exports_per_user_maximum`, `import_export_flood_seconds`).

---

## D. Database portability and all-or-nothing writes

Established from the code in `mybb19/inc` (read, **not executed** against MySQL or PostgreSQL boards):

* No transaction methods in `db_mysqli`, `db_mysql_pdo`, `db_pgsql`, `db_pgsql_pdo`, `db_sqlite`, `db_pdo`. `write_query()`
  passes raw SQL through on all of them, so `START TRANSACTION` (MySQL/MariaDB) and `BEGIN` (PostgreSQL, SQLite) / `COMMIT` /
  `ROLLBACK` work as raw statements. `SchemaManager::engine()` already maps the driver to `mysql` / `pgsql` / `sqlite`.
* **MySQL/MariaDB:** `$table_type = "innodb"` for tables MyBB creates, and the plugin converts MyISAM tables on activation
  (`dbConvertTablesToInnoDb()`), so entry tables are transactional. Caveat: with a read/write split (`read_link` /
  `write_link`), `BEGIN` must go through `write_query()` and reads inside the transaction may hit the replica; imports read
  very little (slug existence checks), and the helper issues them through `write_query` as well.
* **PostgreSQL:** `write_query()` sends on the write link; a failed statement aborts the transaction, which is the wanted
  behaviour. `insert_id()` derives the sequence from the last `INSERT INTO` statement, which works inside a transaction.
* **SQLite:** `write_query()` calls the PDO `query()`; `BEGIN` / `COMMIT` / `ROLLBACK` run as plain statements. A nested
  `BEGIN` fails, so the helper first checks it has not already started one itself (MyBB does not start one).
* **Error behaviour differs:** the SQLite driver's `error()` throws `DbException`; the MySQLi driver's `query()` calls
  `error()` and `exit`s. So a failure on MySQL ends the request rather than throwing.

Layered guarantee:

1. **Validate everything first** (stages 1 to 7). A rejected entry means zero writes on every engine.
2. **One transaction** around stage 8 via an `ImportTransaction` helper (`begin`/`commit`/`rollback`, engine-specific
   statement text, no-op when the entries table is not transactional). On MySQL/PostgreSQL, an `exit`/crash before `COMMIT`
   rolls back when the connection closes.
3. **Compensating delete as backup:** track each inserted `entry_id`; on exception delete the tracked rows; and register a
   shutdown function (runs after `exit`) that deletes them if `COMMIT` was not reached. This covers MyISAM tables and the
   engines that exit instead of throwing.

Accepted gap: a concurrent normal create is unaffected, and there is no cross-request locking (not needed: no uniqueness
rule exists).

---

## Implementation outline (later step, not part of this one)

1. **Prerequisites in existing code (T21), implemented first on branch `feature/import-export`:**
   1. validator error keying (Finding 3): each error reported individually;
   2. a clean validator instance per entry, no state carried between calls;
   3. shared code used by both the form and the import: building an entry's field values including defaults, deciding a
      new entry's status, deciding which entries and fields a user can view and which fields they can fill;
   4. **moderation:** implement the empty new-entry branch in `EntriesController::create()` (Finding 4) in the shared
      status decision, so form posts and imports both land in the moderation queue for users who require it;
   5. **per-user entry limit:** wire up the existing limit (Finding 4) in the shared create path, with a check a caller can
      ask ("are N more entries allowed?") so an import counts existing entries + N against the limit.
   The flood check is deliberately not wired up for the form in this step.
   6. **validator checks custom fields** (Finding 2): fixed in `2c3b800`;
   7. **posted input is allowlisted** (T23): the form takes only the values it legitimately exposes to the user, through
      `EntriesManager::allowedEntryInput()`; user, status, views, dates, slug, counters and ip address come only from the
      server. The import follows the same rule, so its input is limited to the same set (subject, message, fillable
      collection fields) and nothing else is read from the file;
   8. **update moderation** takes effect (`EntriesModerateUpdates`), and **0 beats any other value** when group
      permissions that mean unlimited at 0 are merged, so the entry limit the import checks is the effective one.
2. `Util/XmlFile` (wrapper), `ExportsRepository`, `EntriesExporter`, `EntriesImporter`, `ImportReport`,
   `ImportTransaction`.
3. Permissions (3), table `myshowcase_exports`, config columns, `SETTINGS` entries.
4. Controller `ImportExportController`, routes, Twig templates, language strings, scheduled-task purge.
5. Verification: no test suite exists. Script it against the `_boards/t0b-*` fixtures (SQLite exists). MySQL and PostgreSQL
   boards are needed to confirm Section D beyond reading the code.

---

## Known issues, deferred

Found while preparing the import and not fixed in this track:

1. **The field-class factory fatals on load.** `FieldFactory::create()` cannot load the field classes
   (`Fields/*Field.php`), so no field object can be instantiated; `EntryEntity::getFields()` is never filled either. The
   validator therefore works through the collection's field entities (`CustomFieldEntity`, commit `2c3b800`), and the import
   does the same. Nothing in this design may rely on `FieldsInterface` objects.
2. **A user with no permission rows gets 0 on the zero-is-unlimited limits**, which means unlimited (for example
   `entries_limit_post_amount`). Harmless today because such a user also holds no `entries_can_post` and cannot create
   entries; it would matter if a future permission lets users without rows create entries.
3. **`STATUS_CLOSED` is 0 and `STATUS_OPEN` is 1** (`EntriesManager`) while the `is_closed` column means 1 = closed. The import
   must go through the existing status methods only (`newEntryStatus()`, the form's `allowedEntryInput()`) and never use
   these constants.
4. **`buildAttachmentsUpload()` returns `''` on its first line and its caller in `EntriesController` is commented out**, so the
   attachments quota note is unreachable. The earlier "no TypeError" check on `getPermission()` covered only that code
   path (called directly), not a rendered page.
5. **Deleting an entry leaves its attachments.** The attachment (and comment) cleanup listeners are registered in the constructors of
   the attachments and comments managers, which are created lazily, so in a delete request they are never registered and the
   `EntryDeletedEvent` finds no listener. The same applies to comments.
6. **`AttachmentsRepository::deleteAttachment()` does not delete the files**: it reads `$this->attachments_uploads_path`, a property
   the repository does not have.
7. **`AccessLogsRepository::deleteAccessLog()` reads columns the table does not have** (`access_log_name`, `thumbnail_name`, `status`),
   so it fails after its `attachment_id` typo was fixed.
8. **Upload error messages can be blank**: `uploadAttachment` never loads `messages.lang.php`, which holds `error_attachtype`,
   `error_reachedattachquota` and the other texts it returns.
9. **The per-entry, per-comment and upload/download amount limits are not enforced** anywhere (only the upload quota is).
10. **The comment form's hidden `post_hash` field is empty**: the controller sets the hash on the entry object, the template reads the
    comment's.
11. **Search keywords treat `%` and `_` as wildcards** (they are escaped for SQL but not for `LIKE`).
12. **The scheduled task's pruning loop fails**: `task_myshowcase()` reads a `prune_time` setting that the showcase table no longer has.
    The exports retention was moved ahead of it so it still runs.
13. **FIXED (`2ac66dd` on `security/moderation-access`): the listing showed every status to anyone with `entries_can_view`.**
    The listing, detail page and export now use the rule of section 4. Completed in `0db3bfc`: the update page, attachment
    upload/download, the comment position count and the postbit attachment embeds. **Still open:** the search routes point at a
    `Search` class that does not exist (nothing to leak, nothing works); comments keep their own rule (`comments_can_view`),
    unrelated to the entry status, so comments of an entry the viewer cannot see are only protected by the entry check on the
    way in; the attachment lists on the entry page still carry a "todo, status clause" for the status of the *attachments*
    (not entries).
14. **FIXED (`cefc866`): inline moderation checked no permission.** Every tool maps to its existing permission
    (`EntriesManager::MODERATION_TOOLS`) and is checked per entry (`canModerateEntry()`: permission held, status visible to the
    user, and own entry or moderator). Entries not allowed are skipped and reported. Still open: the entry view page's moderation
    forms post to MyBB's core `moderation.php` (no MyShowcase handler), and the per-entry soft-delete/delete/restore links and the
    comment moderation have no handler at all.
15. **FIXED (`2ac66dd`): `myshowcase_moderators` membership had no effect.** It is now read by `isModerator()`. Still open: the
    front-page "moderators" display reads the two columns the wrong way round, and the ACP moderator list builds its edit links
    from the wrong columns (`group_id`/`showcase_id` instead of `user_id`).

Found while fixing 13–15 (branch `security/moderation-access`), not fixed there:

16. **Search is not implemented**: the search routes point at a `Search` class that does not exist, so every search request errors.
    Affects: search by keyword/user; nothing leaks because nothing runs.
17. **Comments follow `comments_can_view`, not the entry rule**: comment lists use `commentVisibilityWhereClause()` (approved, plus
    the user's own awaiting comments). Affects: comment status visibility is independent of the entry visibility rule.
18. **The entry view page's moderation forms post to MyBB's core `moderation.php`**, which has no MyShowcase handler. Affects: the
    inline moderation tools shown on a single entry page do nothing useful.
19. **The per-entry delete/restore links and comment moderation have no handler** (the `delete=`/`action=` links on the entry update
    route; no comment moderation action exists). Affects: those links and any comment moderation UI.
20. **The front-page moderator display and the ACP moderator list links read the moderators columns the wrong way round**
    (`group_id` is the type and `user_id` the user or usergroup id). Affects: who is shown as moderator on the front page; the user
    and group links in the ACP moderator list point at the wrong ids.
21. **`BaseController::verifyPermission()` names `UserPermissions::EntriesCanModerate`, which does not exist.** It is only evaluated
    when the user lacks `entries_can_view` but has `main_can_view` (the `||` short-circuits otherwise), and then ends in a fatal
    error. Callers: `BaseController::redirect()` (comment redirect routes) and `CommentsController` (comment create/update). Not
    a plain typo (the intent, "moderators may enter", now belongs to `EntriesManager::isModerator()`), so left for a decision.
22. **The ACP moderator delete link is a GET** while the handler only runs for POST requests; it works through MyBB's delete
    confirmation (which posts it) but a plain GET of the link does nothing.
23. **Attachment download compares `$current_user->user_id`** (the permission-check block) where the rest of the code uses `uid`; if
    that property is not set, the owner shortcut for `attachments_can_download` never applies. Not verified further.

**Note for the import:** its rollback (the compensating delete of section D) must delete the inserted rows **directly by entry id**
(`EntriesRepository::deleteEntry()`), not through the controller's delete path: that path dispatches `EntryDeletedEvent`, whose
listeners are not registered in the request (issue 5) and would leave attachments and comments or fail halfway.

---

## Decisions taken

All of the following are final unless you change them.

**From your instructions**

| # | Decision |
|---|---|
| T1 | Validation principle: the forum create-form validator is the only per-entry verification; no import-specific value checks. |
| T2 | No uniqueness flag, no uniqueness checks or normalisation, no duplicate handling anywhere in the design. Finding recorded: the validator enforces no uniqueness; importing the same file twice duplicates the entries. |
| T3 | Only structural pre-check: every field key in the file must exist in the target collection (exact match); any unknown key fails the import. Collection name mismatch is a warning. |
| T4 | Absent fields get their defaults before validation; a required field absent from the file is rejected by the validator and fails the import. |
| T5 | All-or-nothing: every entry goes through the validator first; any rejection fails the whole import and writes nothing; the report lists each failing entry's file position and its validator errors. |
| T6 | Writes: raw-SQL transaction plus compensating delete (and a shutdown-function guard). Assessed from code for MySQL, PostgreSQL, SQLite (section D); not executed. |
| T7 | Authorship and status: all entries are created as the current user; no author, dates or status are imported or exported; entries get what the form gives that user. |
| T8 | Forum-side feature; no ACP module, no `import_export` admin permission. |
| T9 | Two new per-showcase, per-usergroup permissions, `entries_can_export` and `entries_can_import`, both disabled by default, registered through the existing permission system and installer. |
| T10 | Export visibility: only entries and fields the user could view; awaiting/soft-deleted only for moderators of that showcase; fields hidden from the user's group excluded. |
| T11 | Ownership: creator only can list, download, delete. Per-user cap on stored exports (10), flood check on exports and imports (60 s), both configurable per showcase. 30-day retention plus manual delete. |
| T12 | Rules display per export: limit, sort order, selected fields and every form option used. |
| T13 | All state-changing actions are POST with `my_post_key`. |
| T14 | Front-end pages, Twig templates and language strings (section 12). |
| T15 | PluginLibrary must be installed on the test board for implementation; both pages show a clear error when it is missing. |
| T16 | Limits: import cap = `memory_limit / 40`, ceiling 5 MB; 5,000 entries per export; export refuses to save a file larger than the import cap. XML only: extension, MIME and successful parse. |
| T17 | Wrapper: rejects DOCTYPE; encodes `\r` as a character reference; marks nulls explicitly; suppresses PluginLibrary's warnings. Upstream report noted. |
| T18 | **O1 resolved (a):** the export offers only fields the user can both view and fill; the structural check also rejects keys the user cannot fill, so exports are re-importable and imports cannot bypass fill permissions. |
| T19 | **O2 resolved (a):** no admin or super-moderator access to other users' exports; creator only, cleanup by retention. |
| T20 | **Required fields and defaults:** a required field missing from the file is filled with its default value, as the form does with its pre-filled input. It is rejected only if it has no default. (Flow: design 6, stage 6.2.) |
| T21 | **Moderation and per-user limit are prerequisite refactors.** Fixing the empty new-entry moderation branch and wiring up the per-user entry limit both live in the shared status/create path, so form posts and imports both go through moderation and count against the limit. For an import the limit check includes the number of entries being imported (existing count + N must not exceed the limit), and an import that would exceed it fails as a whole, consistent with all-or-nothing. |
| T22 | **Same board only.** Imports are meant for the board that exported them; that is why select values are exported as ids only (old #8). Importing into another board is unsupported, and the import page states this (`import_note_same_board`). |
| T23 | **Posted input is allowlisted** on the create and edit form (commit `570aafb` on `feature/import-export`): subject and message when enabled, post icon, signature and smilies options, close/sticky only with `EntriesCanClose`/`EntriesCanSticky`, and the collection fields the user can fill. Everything server-controlled is never taken from the post. The import's structural check (exact field keys, fillable fields only) follows the same rule. |
| T24 | **Superseded by T26.** (Stage A had used `entries_can_approve`/`entries_can_restore`.) |
| T26 | **One moderator notion and one visibility rule** (`EntriesManager::isModerator()`, `canViewStatus()`; section 4): awaiting/deleted entries need `entries_can_view_unapproved`/`entries_can_view_deleted`, own entries for a non-moderator, any user's for a moderator; export, listing and detail agree. |
| T25 | **Stage B import as built:** pipeline of section 6 with these points settled in code. Both the per-user limit and the validator failures are reported together (the limit is checked after validation, for the whole file); the post-insert steps of the create path are all inside `EntriesManager::validationEntryInsert()` (slug generation, the insert with its defaults and hooks, attachment linking by post hash, slug refetch, the end hook), which the import calls for every entry, so nothing else is shared or missing: there are no counters or caches to update (counts are queries). "Only check the file" is kept. A file that was imported before by the same user is flagged as a hint. |
| T26 | **Null and empty values:** the form posts an empty optional field as `''`, which is stored as `''` (`0` for an integer column), never as NULL. The import does the same: a null or missing value takes the field's default or `''`. A NULL in the source database therefore arrives as `''`. |
| T27 | **Rollback by entry id:** the compensating delete removes the inserted rows with a direct `DELETE ... WHERE entry_id IN (...)` (plus the post hashes the import generated, to find the one row whose id was not yet known if the request ends right after its insert), never through the controller delete path; the shutdown guard does the same after an `exit`. |
| T28 | **File fields carry URLs (D48).** Format version 2 writes each file field as the public URL of its file, for a file the exporting user may download (the rules of the download route, in `AttachmentAccess`); any other file is written empty and counted in the export's notes, and no path is ever written. The import takes each file from its URL, checks it as an upload is checked (the board's types by extension and by the detected MIME type, the field and type sizes, a valid image), and attaches it through the form's attachment pipeline after the entry is saved. A failure of any file fails the whole import; nothing is stored. |
| T29 | **One global setting (D51).** `myshowcase_import_external_files` (yes or no, default no), created on activation with the value no and kept on re-activation (PluginLibrary keeps the value). A missing value is read as no. When it is off, a file with any file URL is refused as a whole by the structural check, the board's own file URLs included; exports are not affected. It is read in one place, `ImportSettings`. |
| T30 | **The fetcher's rules (D53).** http and https only; ports 80 and 443; no userinfo; a URL of at most 2048 characters without spaces, control characters or `#`; every address the host resolves to must be public (the ranges of `AddressClass`, including the IPv4 forms inside IPv6 addresses and the obfuscated IPv4 literals); the connection is pinned to the validated address; each redirect is checked the same way, at most three, and keeps the scheme and the port; TLS is verified; no cookie or credential is sent. The body is streamed to a temporary file and stopped at its size limit; the time limits and the import budget (25 files, 100 MB, 60 % of `max_execution_time`) are constants, not settings. Failures carry reason codes; no message carries an address, a body, a header or a path. |
| T31 | **The board's own file URLs are resolved internally (D52).** A URL that is exactly the board's download route for a file of this showcase, on the board's own base (which may use the board's port), is read from the board's storage, after the importing user's download permission has been checked as the route checks it. It is never fetched. A URL of another host, or of the same path on another host, is an external URL. |
| T32 | **Format version 2.** The importer reads versions 1 and 2 and refuses a higher one. Only version 2 files carry file URLs. The export's header rules state that file fields hold URLs. |
| T33 | **ZIP packages deferred.** One ZIP file that holds one entry and its files is designed in `Documentation/import-packages.md` for a later implementation. It is not implemented. A file may name its files by URL or by a package reference (see that document). |
| T34 | **The attachment pipeline's seams.** The form's attachment pipeline accepts a file the importer has made and checked (`local_file`, which no request can set) in place of an uploaded one, and copies it. The validator counts the pending files of an import for their fields (`pending_file_counts`, empty outside an import). The attachments repository's update flag is no longer readonly, so one instance can insert several attachments. The form's own paths are unchanged. |
| T35 | **One rule set for attachment access (D58).** `AttachmentAccess::decide()` is the only place where the rules of the download route are written: the main permission, the visibility of the owning entry (for everyone, the owner included), the view and download permissions and the status of the attachment for a user who is not the owner, the attachment type and its allowed groups. A thumbnail needs no download permission. The route answers its denials from the result (404 for a hidden file, 403 for a missing permission). The export gives a URL, and the import resolves an own-board URL, only for a file the decision allows; the import never copies a file the route would refuse. |
| T36 | **Files of an import are removed when it fails (D59).** The transaction records every file the import stores (a file and its thumbnail). Rollback and the shutdown guard remove them with the rows, so a request that ends before COMMIT leaves neither a row nor a file. Only a file directly in the showcase's upload folder is removed; links and other paths are never touched. |

**From the previous numbered list (accepted unless overridden)**

| Old # | Outcome |
|---|---|
| 1 Unique-field flag | **Dropped** (overridden by T2). |
| 2 Duplicate policy | **Dropped** (overridden by T2). |
| 3 Required + default | **Changed:** because defaults are applied before validation (T4), a required field with an effective default is satisfied by it. This **conflicts with the earlier recommendation** (3a: still an error); your rule wins. |
| 4 Collection match | Name mismatch is a warning (T3). Overrode the earlier hard-fail recommendation. |
| 5 Unknown fields | **Fails** the import (T3). Overrode the earlier "ignore and warn". |
| 6 Author mapping | **Dropped** (T7). |
| 7 Status on import | **Dropped** (T7). |
| 8 Select values | **Changed:** export `value_id` only. Label resolution would be an import-specific value check (T1). |
| 9 Attachments/comments/history | Comments and history stay out. **Changed (T28):** a `file` field is imported from its URL; a required file field is satisfied by a URL that passes the checks. |
| 10 Storage | Accepted: default directory with deny rules and random names, setting for an outside-web-root path. |
| 11 Retention | Accepted (30 days + manual). |
| 12 Size limits | **Replaced** by T16. |
| 13 Where it lives | **Replaced** by T8, T9, T14. |
| 14 PL wrapper | Accepted and extended (T17). |
| 15 Validate-only | Accepted (design 6). |
| 16 Audit trail | Accepted: one `myshowcase_logs` row per import. |
| 17 Transactions | Accepted (T6). |
| 18 Export form options | **Changed:** author/date/flag options removed (T7); statuses only for moderators; options listed in design 9. |

---

## Decisions needed

None. O1 and O2 were answered (T18, T19).
