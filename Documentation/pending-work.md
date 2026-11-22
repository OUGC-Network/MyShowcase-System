# Pending work

## 1. Purpose, date, base

This document records, with evidence from the repository, every task that is still pending for the MyShowcase plugin, so the
owner can plan later work from the repository alone. It was produced by reading code, documentation, tests and comments. **No
code was run** (no PHP, no web server, no database, no test), so every statement about run-time behaviour below is marked
"unverified" unless a test in the repository pins it; the evidence is always the file and line that was read.

* Date written: 2026-11-20.
* Base commit: `055ccc678440bd6c527f6a08a2eea5561cf529d4` (local `main`).
* Base tree: `c81b779e6dcc4e953dd15422bcb8c925cab9cf33`. It is the expected tree of the harness batch that made the access
  matrix green.
* Line numbers refer to that tree. Decision numbers appear only in brackets as references to the decision log kept outside the
  repository; every item is described in words.
* Status values: **Not started**, **Partial**, **Done (undocumented)**, **Done (documented)**, **Cannot tell**. Size: S
  (under a day), M (a few days), L (more than a week or several batches).
* Items marked **NEW** were found by this reading and were not on the starting list. Proposed decisions are numbered P1 to P10
  and are collected at the end of section 3; each states the default that was assumed.

### How the evidence was gathered

All 14 text files under `Documentation/` were listed and their headings read; the passages cited below were read in full. The
other documentation sources were listed by heading: the root `README`, `LANGUAGE_REFACTORING_SUMMARY.md`,
`decision-gate-template-mybb19.md`, `docs/import-export-design.md`, `Upload/CLAUDE.md`, the plugin's `imports/README.md` and the
eight `tests/**/README.md` or inventory files. No CHANGELOG file exists; the only change log is the last section of
`Documentation/README.txt` (line 279 on). A marker search (`TODO`, `FIXME`, `XXX`, `HACK`) over `Upload/` and `tests/` found 65
markers in 18 files, and three more mentions of "todo" in documentation prose. A second search for planned-behaviour phrases
("not yet", "not implemented", "reserved for later", "deferred" and similar) found 15 lines, almost all about the reserved
`poll` and `rating` field types.

## 2. Summary table

| ID | Title | Area | Status | Size | Depends on |
|---|---|---|---|---|---|
| P-URL-1 | Query string as a fourth link option | Links | Done (documented) | M | none |
| B4 | URL residuals | Links, tests, docs | Done (documented) | M | none |
| B5 | PWA: manifest and service worker | Front end | Partial | S | P4 |
| B6 | Visibility and access re-audit | Access | Partial | M | B7 |
| B7 | One moderator model | Access | Partial | M | P1 |
| B8 | Naming, language files, HTTP status | Quality | Partial | M | P2 |
| B9 | Cleanup and integrity | Quality | Partial | L | B6, B7, B8 |
| FR1 | Final regression 1 | Tests | Not started | M | P-URL-1, B4 to B9 |
| G2c | Remaining gallery integration | Gallery | Partial | M | none |
| T0c | Import-time board configuration, PHP update steps | Gallery, import | Partial | L | none |
| PW1 | Showcase password gate hardening | Gallery | Partial | M | none |
| PW2 | Folder or album password reusing the showcase logic | Gallery | Not started | M | PW1, T5 |
| T1/T3 | Category option metadata, presentation and filter | Gallery | Not started | L | none |
| T2b | User-creatable tags, moderation, purge task | Gallery | Not started | L | T1/T3 |
| T5 | Folders and albums | Gallery | Not started | L | PW1 |
| T6 | Image variants pipeline and watermark options | Gallery | Partial | L | none |
| M1 | Media library showcase, references, embeds | Media | Not started | L | T5 |
| M2 | Media in private messages | Media | Not started | M | M1 |
| TP | Template packages for blog, downloads and gallery | Templates | Partial | L | none |
| FR2 | Final regression 2 | Tests | Not started | M | G2c to T6, M1, M2, TP |
| Q1 | Quote SQL identifiers instead of the reserved-word list | Fields | Partial | M | none |
| Q2 | MariaDB keyword probe | Fields | Cannot tell | S | Q1 |
| Q3 | Zip package for one entry (restore of a deleted entry) | Import | Not started | L | none |
| Q4 | Old text IP values | Data | Not started | S | none |
| Q5 | Test the friendly-link rewrite rules on a real server | Links, tests | Not started | M | P-URL-1 |
| Q6 | Plugin's own Composer dependencies | Architecture | Partial | M | none |
| Q7 | Poll and quiz field types | Fields | Not started | L | none |
| NEW-1 | Copy entries and stored searches | Features | Not started | M | none |
| NEW-2 | Unread comments | Features | Not started | S | none |
| NEW-3 | History records and `edit_user_id` | Data | Not started | L | none |
| NEW-4 | Wish-list comments in `Core.php` | Features | Not started | L | none |
| NEW-5 | Design-document known issues not re-verified | Docs, code | Open (IA-6b): 9 is an open defect (27 limit permissions, 2 enforced; PD-IA6B-3); 19 is an open defect (restore skips the soft-deleted check); 1, 2, 10, 18, 20 traced or partly checked, not run on a board. Fixed: 7, 22. 4 kept (PD-IA6B-1). Closed by design: 3, 17. Not a defect: 16 (PD-IA6B-2). See docs/import-export-design.md | S | owner decisions on 4 and 9; fix for 19 |

## 3. Items

### P-URL-1. Query string as a fourth link option

**What it is.** A fourth value of the showcase "Links" option (`url_mode` 3): links written as
`showcase.php?route_identifier=<id>&route=<rest>`. Automatic would fall back to this form instead of the path form
(`showcase.php/<id>/...`) when friendly links are not available. Export file URLs use the showcase's own form (decision D277; the earlier wording said the query form, which is wrong). The ACP select
would offer four labelled choices with help text, and the router would accept all three forms. The reason is that the path form
needs `PATH_INFO`, which a common nginx `location ~ \.php$` block does not pass.

**Current state: Done (documented).** Built in commits c15e151 (UrlMode, four values and `linkForm()`), 5694840 (router and
builder), 7c37eba (export file URLs in the showcase's own form, own-board URLs in all three forms), c60740d (ACP select and
JSON validation), 4e1866c (documentation), with the tests in 6be01ea and 22afb8e. The bullets below record the state before
the work and are kept as the record of the plan.

* `UrlMode` knows three modes only (`Upload/inc/plugins/myshowcase/src/Routing/UrlMode.php:28-40`), with the documentation of
  Automatic in the docblock at lines 20-27.
* Validation accepts `0`, `1`, `2` only: `src/Service/ShowcaseService.php:109`, `src/Service/JsonSync/JsonValidator.php:354-355`.
* The ACP select lists three labels: `src/Util/SelectableLists.php:138-143`; the language strings are
  `Upload/inc/languages/english/admin/myshowcase_summary.lang.php:167-172` (the error text says "No, Automatic or Yes").
* The column is `Plugin/Core.php:535-540`; the JSON documentation lists `0`, `1`, `2` at `imports/README.md:108-110`.
* Public file URLs written into files use the plain form on purpose: `src/Service/AttachmentAccess.php:41`,
  `src/Routing/Routing.php:533-539`.
* A search for `route_identifier=` and `?route=` in `Upload/`, `Documentation/` and the URL tests found nothing.
* The documentation describes three modes: `Documentation/showcases/url-option.md:19-90`.
* The router fixture of the tests is `tests/features/http/url_mode_router.php`; it does not know a query form.

**What remains.**

- [ ] Define mode 3 in `UrlMode` (constant, `isValid`, the Automatic fallback rule) and keep one place for the rule.
- [ ] Teach `Routing` to build the query form, and `Routing::getMatch()` plus `showcase.php` to read it (how the match reads
  `PATH_INFO` today was not read: unverified).
- [ ] Add the fourth value to validation (`ShowcaseService`, `JsonValidator`), the ACP select and language strings, the JSON
  documentation and the export of `url_mode`.
- [x] Export file URLs in the showcase's own form (D277); old plain URLs keep working (P3).
- [ ] Update `url-option.md`, the guard allow-list (`tests/url-builder/allow-list.json`) and the router fixture.

**Acceptance.** A board without `PATH_INFO` routing can use every page and download through the query form; the URL-mode tests
cover four modes on the three engines; `guard_test.py` passes; an old export with plain file URLs still imports.

**Depends on.** Nothing. **Risks.** The query names (`route_identifier`, `route`) may collide with other query parameters on a
page (unverified); links stored in old content keep the older form; Automatic's fallback changes the links of boards that run on
the default setting.

### B4. URL residuals

**What it is.** The remaining non-absolute URLs and test and documentation leftovers of the link work.

**Current state: Done (documented).** Completed by commits 8905e7f (the residual links, the password gate's action, `url_showcase`,
the moderator's IP search link, the script escaping), 028e655 (`downloads_entry` by label), 62d0925 (the blog sentence), with
the tests in 6be01ea, 22afb8e and edad466, and the guard's relative-link scan. The Batch 5 items (the manifest and the service
worker) are unchanged and on the guard's allow-list. The findings below are the state before the work.

Relative or host-root links remained in the plugin templates (read, not run):

* `view/templates/pageViewEntry.twig:157` links to `/getip/{{ entry_data.public_id }}`; no route or script of that name exists in
  `Upload/` (a search for `getip` found only this line), so the link is both root-relative and dead (unverified at run time).
* `pageViewEntry.twig:231` and `pageViewComment.twig:161` use `newreply.php?tid=...`; `pageView.twig:172` and `:388` use
  `showthread.php?...`; `pageEntryCreateUpdateAttachments.twig:7` uses `usercp.php?action=attachments`: all relative to the
  current page.
* `pageAccess.twig:24` posts to a protocol-relative address `//{{ http_host }}{{ request_uri }}`.
* Eight templates link `<link rel="manifest" href="/manifest.json">` (for example `pageMain.twig:16`, `pageView.twig:22`):
  host-root, see B5.
* `jscripts/service-worker.js` and the registration in `jscripts/myshowcase.js:107-117` are covered in B5. The JavaScript
  `fetch(view_url)` and `fetch(update_url)` (`myshowcase.js:15`, `:75`) read their addresses from data attributes set on the
  server; whether those are absolute was not read: unverified.
* Custom template sets live under `view/templates/<prefix>/` (`Documentation/templates/template-reference.md:11-27`); the guard
  test reads the plugin's own PHP and Twig files (`tests/url-builder/README.md`), so a custom set is not covered: unverified.
* Why the existing tests pass with these links is not established: `tests/features/http/url_absolute.py:148` lists core chrome
  that it excludes, and whether the lines above are reached by it was not read.
* `tests/import/http/flows.py:634-638`: `downloads_entry` hard-codes select values `1` for category, platform and license, and
  its comment says they are "the option ids of the shipped downloads collection". Option ids are not fixed on a board (the
  access matrix had to look them up by label, see its `dl_fields`).
* `Documentation/showcases/blog.md:70-71` reads "The optional patterns accept an empty value: a pattern is checked for optional
  fields too, so each one allows nothing for an empty field." The last clause contradicts the first (it should say each one
  allows an empty value). No other passage replaced it.

**What remains.**

- [ ] Route every link above through the one link builder, or an absolute board address, and add the places to the guard.
- [ ] Decide what `/getip/...` should be: a real route, MyBB's `modcp.php?action=ipsearch` style link, or removal (P7).
- [ ] Make the JavaScript and the service worker use addresses that the server passes in.
- [ ] Add a check for custom template sets (a scan of each set folder against the same guard).
- [ ] Fix `downloads_entry` to look up the option ids by label, like `access_matrix.py`, and correct its comment.
- [ ] Correct the blog.md sentence (documentation batch, P9).

**Acceptance.** `url_absolute.py` and the guard report no relative link emitted by the plugin or a shipped custom set; the
downloads import tests pass on boards whose option ids differ from 1; the blog document reads correctly.

**Depends on.** None. **Risks.** Changing links changes cached pages; a fix to the template set guard may flag third-party sets.

### B5. PWA: manifest and service worker

**What it is.** A real web app manifest and a service-worker cache list that names files that exist. This item starts from the
finding below, so the earlier "src/ and view/" search is replaced by a whole-repository search.

**Current state: Partial.**

* The repository holds one service worker: `Upload/jscripts/service-worker.js` (21 lines). Its cache list is `'/'`,
  `'/styles/main.css'`, `'/scripts/myshowcase.js'`, `'/scripts/myshowcase_inline.js'` (lines 2-7). The plugin's scripts live in
  `jscripts/`, not `scripts/`, and the plugin ships no `styles/main.css` (unverified for the board's theme). Its fetch handler
  answers every request cache-first (lines 16-21).
* It is registered in `jscripts/myshowcase.js:107-117` with `url_showcase + '/service-worker.js'`. `url_showcase` is defined only
  in `view/templates/pageMain.twig:66` as an empty string, so the registered address is `/service-worker.js` at the host root,
  where the plugin does not place the file. `pageView.twig:27` loads `myshowcase.js` without defining `url_showcase`, so by
  reading the registration throws a ReferenceError on the entry page (unverified: not run).
* No manifest file exists in the repository (`git ls-files` lists none), yet eight templates link `/manifest.json`. The URL test
  names `manifest.json` as core chrome (`tests/features/http/url_absolute.py:148`), which suggests MyBB 1.9 core serves one:
  unverified.
* `Upload/CLAUDE.md:100` states that the worker is registered on pages.

**What remains.**

- [ ] Decide the shape (P4): drop the worker, or serve it from a route that can answer from the board root scope, with its own
  manifest.
- [ ] Correct the cache list to files that exist, and cache by version.
- [ ] Define `url_showcase` (or its replacement) on every page that loads the script, from the link builder.
- [ ] Add a test that fetches the manifest and the worker and checks their status, type and cache list.

**Acceptance.** The manifest and worker answer 200 with the right media types; the registration succeeds on the list page and
the entry page; no 404 is requested by the worker's install step. **Depends on.** P4. **Risks.** A service worker with a
cache-first handler can serve stale pages; scope rules limit where the file may sit.

### B6. Visibility and access

**What it is.** A re-audit of the visibility rules of entries, comments and attachments, and removal of an always-true condition.

**Current state: Partial.**

* The rules are documented in `Documentation/visibility.md` and enforced by `AttachmentAccess::decide()` and the entry visibility
  clauses; the batch that made `tests/import/http/access_matrix.py` green reports 112 cells and no failing cell on SQLite, MySQL
  and PostgreSQL (from that batch's report; not re-run here).
* The leftover condition is `src/Service/RenderManager.php:1164`: `if ($attachment_data->status or true) { // todo, unapproved
  attachments should display regardless`. It is always true, so the status never matters there.
* Status-clause todos remain: `RenderManager.php:353`, `Fields/FieldTrait.php:309`, `Controller/EntriesController.php:464` and
  `:1079`, `forum_hooks.php:335`; `docs/import-export-design.md:933` notes the same for the attachment lists.
* `Enum/UserPermissions.php:76`: "if user can't view then they can't download either" is a note, not a check (unverified).
* `Routing/Routing.php:425`: "maybe check that only the current user is trying to view".

**What remains.**

- [ ] Re-read each rule against `visibility.md` for entries, comments and attachments, including attachments of comments, export
  files and thumbnails.
- [ ] Replace `or true` with the intended rule (P6) and add matrix cells for it.
- [ ] Resolve the status-clause todos listed above, one by one.
- [ ] Decide the "cannot view means cannot download" rule and test it.

**Acceptance.** The access matrix covers every cell the audit adds and has no failing cell on the three engines; no `or true`
remains. **Depends on.** B7. **Risks.** Tightening a rule can hide content that boards show today.

### B7. Moderator model

**What it is.** One moderator notion. It exists; the ACP moderator flags are the loose end.

**Current state: Partial.**

* `EntriesManager::isModerator()` (`src/Service/EntriesManager.php:229`) is the single test: super moderator or administrator
  group, or a `myshowcase_moderators` row matching the user or one of their groups. `Controller/BaseController.php:171-179`
  wraps it as `isEntryModerator()` and `isCommentModerator()` (both call the same method), and `RenderManager.php:270-272` sets
  both template flags from it.
* The five ACP flags (`entries_can_view`, `can_manage_comments`, `can_manage_attachments`, `can_manage_reports`,
  `can_manage_logs`) are defined in `Plugin/Core.php:1778-1798`, written by `AdminManager.php:577-654`, cached in
  `CacheManager.php:153-181` and named in `Enum/ModeratorPermissions.php`; outside those files only
  `Plugin/Functions.php:141-145` names them (defaults). Nothing reads them to decide moderation (stated in
  `EntriesManager.php:224-225` and `Documentation/visibility.md:20-21`).
* `Enum/ModeratorPermissions.php:10` maps `CommentsCanView` to the column `can_manage_comments`: name and column disagree.
* `EntriesManager::moderatesShowcase()` (`EntriesManager.php:629-664`) is documented as "must not be used as a moderator test";
  no caller was found in `Upload/` or `tests/`: dead code.
* `src/Service/UsersManager.php:230-232` keeps `todo, get user specific permissions` and `todo, get moderator permissions`;
  `AdminManager.php:413` holds a commented-out repository line.

**What remains.**

- [ ] Decide what the flags mean (P1) and either read them in `isModerator()` per area or remove the unused ones from the ACP.
- [ ] Delete `moderatesShowcase()` if unused (confirm by search at the time).
- [ ] Rename `CommentsCanView` or its column consistently.
- [ ] Align the two documents that describe the flags (see section 4, gaps G1 and G2).

**Acceptance.** One documented notion; every ACP moderator control either has an effect covered by a test or is gone; the
documents agree with the code. **Depends on.** P1. **Risks.** Honouring the flags changes who can moderate on boards that have
rows with all flags at 0, which is what existing rows hold after upgrade (`Documentation/README.txt:122-126`).

### B8. Naming, language files, HTTP status

**What it is.** Correct status codes, consistent names, and strings moved into language files.

**Current state: Partial.**

* `BaseController::error()` defaults to status 500 (`src/Controller/BaseController.php:596`). Callers that pass no status:
  upload refusals `Controller/AttachmentsController.php:86` (no file) and `:146` (the upload result's error) answer 500;
  `CommentsController.php:108`, `:394`, `:397` and `BaseController.php:361`, `:365` ("invalid entry/comment") answer 500.
  Correct codes are passed elsewhere: 404 at `EntriesController.php:901`, `:917`, `:1459`, `AttachmentsController.php:283-388`,
  `ExportController.php:194`, `:287`; 403 at `EntriesController.php:914`, `:936`.
* Page not found: `Upload/showcase.php:186` calls MyBB's `error()` without a status. The test
  `tests/fields/http/run.py:1347-1348` pins **400** and says "MyBB's default is 400"; the 200 reported for MyBB dev-1.9 is
  unverified here (the core is not in the repository).
* An unknown entry on the edit path answers 404 (`EntriesController.php:901-917`); where an unknown entry would answer 400 was
  not found: cannot tell.
* Hard-coded English strings: `showcase.php:140` and `:163`; `AttachmentsManager.php:320` (`todo, update lang`);
  `FieldTrait.php:366` (`todo, add description for comma/line multiple separator`). Templates were not scanned.
* Naming: the language key `myshowcase_entry_button_purse_spammer` (`languages/english/myshowcase.lang.php:385`) misspells
  "purge"; summary keys mix `myShowcaseAdminSummary...` and `myshowcase_admin_summary_...` styles
  (`admin/myshowcase_summary.lang.php:76`, `:95` against `:167`); `Plugin/Core.php:1194` reads `'collection_id' => [ // todo,
  rename to collection_id`.
* Embed-collision and ACP-field findings: none are recorded in the repository (search for "collision" found only unrelated
  lines): cannot tell.

**What remains.**

- [ ] Decide the status map (P2) and apply it: 404 for unknown things, 400 for refused input, 401/403 for permission, 500 only
  for faults; pass a status to MyBB's page-not-found error.
- [ ] Update `tests/fields/http/run.py:1347-1348` with the new expectation.
- [ ] Move the hard-coded strings to the language files; fix the misspelt key with an alias period.
- [ ] Scan the templates for hard-coded text.

**Acceptance.** A table of requests and expected statuses is tested on three engines; no controller error is raised without an
explicit status; no hard-coded sentence remains in PHP or Twig outside fallbacks of the error-sentence function. **Depends on.**
P2. **Risks.** Clients and tests that rely on 500 or 400 today; renamed language keys break custom language packs.

### B9. Cleanup and integrity

**What it is.** Dead code, marker triage, orphans, binary columns and warnings.

**Current state: Partial.**

* Markers: 65 in code, listed in section 5. Dead or legacy code: `moderatesShowcase()` (B7); `AdminManager.php:413`;
  `Plugin/Core.php:1993` ("remove this legacy updating the database and updating the format field to TINYINT"), `:2157`
  ("drop this redundant field"); `admin.php:160` ("drop thumb_width, thumb_height, maximum_text_field_length"); the
  `field_data` column marked "todo, drop" (`Documentation/schema/gallery-schema-changes.md:165`).
* Stale code comments: `EntriesManager.php:226-228` says `matchesUser()` was not confirmed, but it exists
  (`src/Repository/ModeratorsRepository.php:53`); `:168` and `:1748` carry the same kind of `TODO(verify)` text.
* Binary columns: every `VARBINARY` column in `Plugin/Core.php` is an `ipaddress` column (lines 191, 333, 441, 478, 1187, 2561,
  2619); `Core.php:440` keeps `todo, update old data from varchar to varbinary`; `SchemaManager.php:1045-1046` reads
  PostgreSQL `bytea` defaults. A column audit beyond `ipaddress` was not done.
* Orphans: no test or code comment covers orphan attachments or rows after deletes (a search for "orphan" found only a test
  harness comment); import failure cleanup is covered (`tests/import/README.md:88-90`). Delete paths were not read: unverified.
* Residual PHP warnings: cannot tell (nothing was run).

**What remains.**

- [ ] Triage every marker into fix, delete, or document; remove the stale `TODO(verify)` comments.
- [ ] Add a read-only integrity report: attachments without an entry or comment, field values without a field or entry, files
  without a row, rows without a file.
- [ ] Audit binary columns and the conversion of text IP values (Q4).
- [ ] Run the harnesses with warnings on and fix what appears.

**Acceptance.** No marker remains without an item ID; the integrity report is empty on the harness boards after deletes and
imports; no warning in the PHP log during the suites. **Depends on.** B6, B7, B8. **Risks.** Removing "dead" code that a
third-party template set calls.

### FR1. Final regression 1

**What it is.** One serial run of every suite after P-URL-1 and B4 to B9.

**Current state: Not started.** The command list is not frozen. These runners exist now: `tests/exports-storage/run.php`,
`tests/fields/unit/run.php`, `tests/fields/http/run.py` and `acp_bounds_test.py`, `tests/features/http/run.py` (with
`url_mode.py`, `url_absolute.py`, `quote_dates.py`, `fresh_install.py`), `tests/fixtures/seeds/coverage_test.py`,
`tests/harness/cleanup_test.py` and `interrupt_test.py`, `tests/import/run.php`, `fetcher/run.php`, `http/run.py`,
`abort_test.py`, `access_matrix.py`, `tests/json-sync/run.php` and `http/run.py`, `tests/post-hash/run.php`,
`tests/url-builder/guard_test.py`, `tests/url-mode/run.php`, `tests/util/run.php`, `tests/xml-file/run.php`.

**What remains.** Freeze the list (P8); define PHP versions and engines per runner; run serially with one summary line each.
**Acceptance.** All runners pass on SQLite, MySQL and PostgreSQL where applicable; no leftover server, database or folder.
**Depends on.** P-URL-1, B4 to B9. **Risks.** Port and database clashes between runners.

### G2c. Remaining gallery integration

**Current state: Partial.** Evidence (code comments, no run):

* Report records: `forum_hooks.php:452-456` (`report_type()` with `todo, id1=showcase_id, id2=report_type,
  id3=comment_id/entry_id/attachment_id`); `RenderManager.php:917` (`the entry query should fetch this from the reports table`).
* Comment language keys: `CommentsController.php:397` falls back from `myshowcase_report_error_invalid_comment` to the entry key,
  although the comment key exists (`languages/english/myshowcase.lang.php:636`). What the track meant by missing keys: cannot
  tell.
* Portal template: `view/templates/portal_rand_showcase.twig` exists and is documented
  (`template-reference.md:292`); no PHP file mentions its name (unverified whether a hook loads it by other means).
* Post-bit controls and attachment display: `forum_hooks.php:332-335`, `:445-446` (cache attachment objects, use
  `asset_url()` for image attachments, integrate with link tools).

**What remains.**

- [ ] Write and read report records for entries, comments and attachments.
- [ ] Settle the comment language keys and remove the fallback.
- [ ] Wire or remove the portal template and its links (through the link builder).
- [ ] Make post-bit controls follow the same visibility rules as the entry page.

**Acceptance.** A report from a post bit reaches the reports list; the portal block links are absolute and tested; inactive
controls are not shown. **Depends on.** none (link rules from B4). **Risks.** Interaction with MyBB's own report types.

### T0c. Import-time board configuration and versioned PHP update scripts

**Current state: Partial.** Versioned PHP update steps exist: `Upload/inc/plugins/myshowcase/imports/PHP/{collections,showcases}`,
documented at `imports/README.md:165` and exercised by `tests/json-sync/http/flows.py:351-374`. What the JSON format cannot
express is stated in `Documentation/showcases/downloads.md:109-118` (download rights, limits, download log, moderation of new
entries, allowed attachment types) and `gallery.md:80-97` (permissions and their seeding "deferred").

**What remains.**

- [ ] Decide which board-level settings a showcase file may carry (attachment types, download permissions, logging) and add them
  to the JSON schema and validator.
- [ ] Document the update-script contract (arguments, order, failure) and add a failure test.
- [ ] Keep file keys that hold board values (watermark file, password) excluded, as `gallery.md` says they are by design.

**Acceptance.** The downloads and gallery files create a showcase that allows downloads and its attachment types without manual
steps; a failing update step leaves the board unchanged. **Depends on.** none. **Risks.** Writing board-level settings from a
showcase file widens what an imported file can change.

### PW1 and PW2. Showcase password gate and its reuse

**Current state: PW1 Partial, PW2 Not started.** A showcase password gate exists: `Controller/EntriesController.php:95-131`
(`verifyPassword`, `clearPassword`), the `password` column (`Plugin/Core.php:542`) and the template `pageAccess.twig`. The design
asks for hashed showcase passwords and access-log fields (`Documentation/showcases/gallery.md:80-97`, schema Group E); whether the
stored password is plain today was not read: unverified. No folder tables exist (see T5).

**What remains.** PW1: hash the stored password with a migration-free approach (new hash on next save, read both forms), log
accesses, throttle attempts. PW2: a folder password using the same functions, a folder-level `pageAccess`. **Acceptance.** No
plain password in the database after a save; a wrong password is refused and logged; folders reuse the same code path.
**Depends on.** PW2 on T5. **Risks.** Existing passwords stay readable until saved again.

### T1/T3. Category option metadata, presentation and filtering

**Current state: Not started.** The design (slug, parent, image, description, active flag, filter bar) is "approved, not
implemented" (`Documentation/schema/gallery-schema-changes.md`, Group A at line 137; `gallery.md:80-88`). The child table for
option values exists (`Plugin/Core.php:2194`), without these columns.

**What remains.** Add the columns per the document, the migration of existing options, the JSON keys, the ACP editor, the
thumbnail field and the category filter bar. **Acceptance.** The shipped gallery shows category pictures and a working filter on
three engines; old options keep their ids. **Depends on.** none. **Risks.** Option ids are not stable across boards (see B4).

### T2b. User-creatable tags, moderation, purge task

**Current state: Not started.** Multi-select values use `myshowcase_field_values` (`Core.php:2194`). No code for user-created
options, their moderation or a purge task was found (a search for "purge" found only the "Purge Spammer" strings).
`gallery.md:80-88`: "No tags field. The file has no tags field on purpose; the tests assert it."

**What remains.** A tags field type with per-showcase moderation of new tags, a status for unapproved tags, a purge task for
unused ones, and the import and export of tags. **Acceptance.** A member can add a tag; a moderator approves or rejects it; the
task removes unused tags; import and export round-trip. **Depends on.** T1/T3. **Risks.** Tag spam; uniqueness rules per
language.

### T5. Folders and albums

**Current state: Not started.** `Documentation/schema/gallery-schema-changes.md` Group C (line 352 on) designs
`myshowcase_folders` and `myshowcase_folder_entries` plus new columns; `Plugin/Core.php` has neither table (searched).
**What remains.** Tables, services, routes, permissions, ACP and the visibility levels. **Acceptance.** Folders nest, hold
entries, respect visibility and password, and appear in the access matrix. **Depends on.** PW1. **Risks.** Interaction with
entry visibility and with the export.

### T6. Image variants pipeline and watermark options

**Current state: Partial.** A basic watermark exists: permission, showcase settings and `AttachmentsManager.php:629-633`; the
file location constants are at `AttachmentsManager.php:38-42`. Thumbnails exist. The medium size, metadata stripping and the
original-download permission of Group D (`gallery-schema-changes.md:489`) are not implemented. Related todos:
`AttachmentsManager.php:220-221` (`$is_update`, `$do_watermark` marked `todo ?`) and `:547` (phpThumb).

**What remains.** Variant table and generator, metadata stripping, watermark options, original-download permission, and
clean-up when an attachment is deleted. **Acceptance.** Uploaded images produce the configured variants; deleting removes
them; EXIF is removed where configured. **Depends on.** none. **Risks.** Memory limits on large images; GD versus Imagick
differences (unverified).

### M1 and M2. Media embeds

**Current state: Not started.** A search for a media library, `[media` embeds, `source_attachment_id` and a references table
found nothing in `Upload/`.

**What remains.** M1: media-library showcase, per-user library page, references table with its own public id, sharing route
with a context check, `[media]` embeds in entries, comments and forum posts; folder privacy levels (Private, Shared users,
Buddies, Members Only, Public); reuse through cloned attachment rows with `source_attachment_id`; download counting rules. M2:
the same in private messages. **Acceptance.** A shared item embeds only where its context allows; counts follow the rules; the
access matrix covers each level. **Depends on.** T5 for folders. **Risks.** Privacy leaks across contexts; counter inflation.

### TP. Template packages

**Current state: Partial.** The mechanism for a per-showcase set exists: `myshowcase_config.custom_template_prefix`, chosen on the
General tab, with files in `view/templates/<prefix>/` (`Documentation/templates/template-reference.md:11-27`). No shipped set was
found for blog, downloads or gallery (`Documentation/showcases/gallery.md:80-101` says the gallery set is separate work), and no global or
custom toggle was found (unverified: not searched beyond the language and template folders).

**What remains.** Ship one set per example showcase in its own folder, a toggle at the bottom of showcase pages that stores one
session cookie (no database write, no new route), and the guard for custom sets (B4). **Acceptance.** A visitor can switch sets
and the choice persists for the session; every shipped set passes the URL and variable checks. **Depends on.** none (B4 for
guard). **Risks.** Sets drift from the global templates.

### FR2. Final regression 2

**Current state: Not started.** After G2c to T6, M1, M2 and TP, rerun the frozen list of FR1 plus the new suites. **Depends on.**
all of the above.

### Q1. Quote SQL identifiers instead of the reserved-word list

**Current state: Partial.** The list `FieldService::SQL_RESERVED_KEYS` (`src/Service/FieldService.php:76`) holds 307 quoted
words by count (the starting list says about 298); field keys on it are refused (`:386-389`). Schema statements already quote
identifiers (`SchemaManager.php:297`, `:334-468`); whether the data queries do was not read: unverified. **What remains.** Quote
identifiers in every data query, then shrink the list to what quoting cannot cover, and extend the field matrix. **Acceptance.**
A field named after a former reserved word works on all engines. **Risks.** A missed unquoted query.

### Q2. MariaDB keyword probe

**Current state: Cannot tell.** The docblock at `FieldService.php:66-75` says the MariaDB 13.0.2 reserved-word source was probed
on MariaDB 13.0.2 only; the starting list says it was not runtime-verified, and the field matrix runs on three other engines.
**What remains.** Confirm by running the probe on MariaDB, or correct the comment. **Depends on.** Q1.

### Q3. Zip package for one entry

**Current state: Not started.** `Documentation/import-packages.md` is a design ("not implemented", line 1-5);
`docs/import-export-design.md:1013` defers it. Needed for restoring a deleted downloads entry. **What remains.** Implement the
layout, limits and import flow of the document and its test ideas (lines 123-136). **Risks.** Archive bombs, path traversal,
symlinks.

### Q4. Old text IP values

**Current state: Not started** by policy (no migrations): `Plugin/Core.php:440`; `docs/import-export-design.md:229` ("text
values of older entries are not migrated"). **What remains.** Nothing unless the policy changes; document reading of both forms.

### Q5. Rewrite rules on a real server

**Current state: Not started.** Apache and nginx snippets are documented (`url-option.md:92-128`); the tests use PHP's built-in
server with a router (`tests/features/http/url_mode_router.php`); the only test files that mention Apache or nginx are
`tests/exports-storage/run.php` and `tests/json-sync/run.php` (about the export folder protection, not rewrites). **What
remains.** Test the snippets on real Apache and nginx, including the `PATH_INFO` case P-URL-1 addresses.

### Q6. Plugin's own Composer dependencies

**Current state: Partial.** `Upload/inc/plugins/myshowcase/composer.json` requires `symfony/form`, `symfony/dependency-injection`,
`symfony/uid` and `teamtnt/tntsearch`. Reading the code: `symfony/uid` and `teamtnt/tntsearch` are not referenced anywhere in
`Upload/inc` (an own `Util/UuidUtil.php` exists); `symfony/form` is used in one file (`BaseController.php:39-40`, `:78`);
`symfony/dependency-injection` in four (`showcase.php`, `Container.php`, `Util/BuildShowcase.php`, `AdminManager.php`). Routing,
string and event-dispatcher components are declared under `replace` yet used (`Routing/Routing.php`, `Util/StringUtil.php`,
`Event/EventDispatcher.php`), which implies another package provides them: unverified. The plugin loads `vendor/autoload.php`
(`myshowcase.php:98`); no `vendor/` folder is committed. **What remains.** Remove the two unused requirements first (P5), then
replace the form factory and the container with plain code. **Acceptance.** `composer.json` lists only what is used; the
suites pass. **Risks.** The `replace` entries hide what the board must provide.

### Q7. Poll and quiz field types

**Current state: Not started.** `poll` and `rating` are reserved: `Plugin/Functions.php:864-875`; refused with a reason by
`FieldService.php:525` and `JsonValidator.php:165`; absent from the ACP and the FieldKit collection
(`tests/fields/inventory.md:103`). Quiz has no trace. **What remains.** Schema first (shared structure for poll and quiz or
separate), then the field types, form, validation, display, import and export. **Risks.** Vote integrity and result privacy.

### NEW-1. Copy entries and stored searches

**Current state: Not started.** `Documentation/README.txt:203-205`: "Not implemented yet: copying entries (the 'Can copy entries'
permission exists but nothing uses it...) and stored searches". `tests/features/http/features.py:329` asserts the copy action is
hidden. **What remains.** Build or remove the permission and the stored-search address `/search/<hash>`.

### NEW-2. Unread comments

**Current state: Not started.** `RenderManager.php:915`, `Controller/EntriesController.php:540`, `:637` set `unread_comments` to
false with `todo, implement unread comments`. **What remains.** Track last-read per user and show it in list and entry pages.

### NEW-3. History records and `edit_user_id`

**Current state: Not started.** `Documentation/schema/public-id.md:82-94`: history tables exist in the definitions
(`Plugin/Core.php:448`, `:2569`) but nothing writes them; `edit_user_id` columns carry `todo, should be entry_history_id`
(`Core.php:143-146`, `:420`, `:2526`). **What remains.** A history writer, routes and permissions, and the key change.

### NEW-4. Wish-list comments in `Core.php`

**Current state: Not started.** Lines `Plugin/Core.php:2361-2419` list ideas as comments: Extra Forum Permissions integration,
ougc Online Users List, attachment display type, general and collection settings, per-field order/block/history/search
settings, DVZ Stream, NewPoints, "users see own unapproved content", a Pages menu built like a showcase. Decide per idea:
build, defer or delete.

### NEW-5. Design-document known issues not re-verified

**Status (IA-6, IA-6b):** re-checked one by one against the code; the verdict table is in `docs/import-export-design.md` (section "Re-check at IA-6"). Resolved: 5, 6, 7 (IA-6b), 8, 12, 13, 14, 15, 21, 23, 22 (IA-6b, static check 3645983 and fix 79d3023). Not applicable: 11. Closed by design: 3, 17. Not a defect: 16 (search is implemented in `EntriesController::search()`; the planned 404 would break a working route; PD-IA6B-2). Kept, owner decision: 4 (PD-IA6B-1). Open defects found in IA-6b: 9 (only 2 of 27 limit permissions are enforced; PD-IA6B-3, a feature decision) and 19 (restore does not check that the item was soft-deleted, so it can approve an awaiting item; not fixed, needs a failing board test first). Traced or partly checked, not run on a board: 1 (check 7fc845b, no load fatal found), 2, 10, 18, 20. NEW-5 is therefore open. NEW-6 (search) is not added: search exists.

**Current state: Cannot tell.** `docs/import-export-design.md:896-970` lists known issues such as "The field-class factory fatals
on load" (`FieldFactory::create()`), and `:1048` says no decisions are needed. They were not re-checked against the current code.
**What remains.** Re-check each known issue and move live ones into B9.

### Proposed decisions (defaults applied)

| No. | Question | Default assumed |
|---|---|---|
| P1 | What do the ACP moderator flags mean? | `isModerator()` stays the one test; the entries and comments flags decide moderation per area for non-super moderators; the attachments, reports and logs flags are hidden until a feature uses them. |
| P2 | Status code map | 404 unknown, 400 refused input, 401/403 permission, 500 faults only; page-not-found passes 404 explicitly. |
| P3 | Old plain file URLs after P-URL-1 | Settled (D277): they keep working; exports write the showcase's own form, not always the query form. |
| P4 | Service worker and manifest | Serve the worker from a showcase route with a root scope and ship an own manifest; if infeasible, drop the worker. |
| P5 | Composer dependencies | Remove `symfony/uid` and `teamtnt/tntsearch` first; keep form and container until replaced. |
| P6 | Unapproved attachments in `RenderManager` | Shown to their author and to moderators only. |
| P7 | The `/getip/...` link | Settled (D281) and done: a link to MyBB's own IP search, shown to moderators for a stored address. |
| P8 | Final regression list | Every runner listed under FR1, serial, each engine each runner supports. |
| P9 | Documentation fixes | Made in the batch that touches the code, plus one small documentation batch for the gaps in section 4. |
| P10 | `url_mode` numbering | Settled and done: the query form is value 3; Automatic's fallback is the query form. |

## 4. Documentation gaps

Each row gives the documentation side and the code side. Nothing was fixed.

| No. | Documentation says | Code or other source says |
|---|---|---|
| G1 | `Documentation/templates/template-reference.md:61-62`: the flags come from `EntriesManager::isEntryModerator()` and `isCommentModerator()` | Those methods are on the controller (`Controller/BaseController.php:171-179`); `EntriesManager` has only `isModerator()` (`EntriesManager.php:229`) |
| G2 | `Documentation/README.txt:171-177` and `template-reference.md:55-58`: users with "entries" or "comments" on, on the Moderators tab, moderate | The flags are not read (`EntriesManager.php:224-225`, `Documentation/visibility.md:20-21`) |
| G3 | `EntriesManager.php:226-228` (comment): `matchesUser()` and the repository property "have not been confirmed" | `ModeratorsRepository.php:53` defines `matchesUser()`; `visibility.md:17-18` documents it |
| G4 (fixed, 62d0925) | `Documentation/showcases/blog.md:70-71`: "each one allows nothing for an empty field" | `Documentation/import.md:87`, `:108`: an empty optional value is stored empty and a pattern accepts it |
| G5 | `Documentation/exports.md:100` and `:119`: two sections titled "File fields" | One topic written twice |
| G6 | `Documentation/import-packages.md:119`: export as package writes "format version 2" | `Documentation/import.md:43-50`: versions 1 to 3, with 3 carrying option value ids |
| G7 | `Upload/CLAUDE.md:101`: templates under `inc/plugins/myshowcase/src/view/templates/` | The folder is `Upload/inc/plugins/myshowcase/view/templates/` (`template-reference.md:11`) |
| G8 | `Upload/CLAUDE.md:100`: the service worker is registered on pages | See B5: address `/service-worker.js`, undefined `url_showcase` on the entry page, missing files in the cache list |
| G9 | `Documentation/schema/gallery-schema-changes.md:1-5`: only the "Other approved changes" are implemented | `Plugin/Core.php:2194` already defines `myshowcase_field_values` (Group B's table, `gallery-schema-changes.md:243`) |
| G10 | `Documentation/README.txt:279-372`: the change log stops at v2.5.2 | `README.txt:46-216` describes later behaviour (default permissions, moderator columns, template flags) with no change-log entry (intended version unverified) |
| G11 | `Documentation/README.txt:55-58`: permissions are the "same values the ACP group form shows" | No document tabulates the default values; the code defaults sit in `Plugin/Functions.php` and `Core.php` |
| G12 | Root `README` (13 lines) | It contains template-edit code, not a description of the plugin |
| G13 | `LANGUAGE_REFACTORING_SUMMARY.md` | A historical summary at the root; linked from no document (unverified) |
| G14 | `docs/import-export-design.md:896-970` known issues | Open (IA-6b): item 9 (PD-IA6B-3) and item 19 are open defects; items 1, 2, 10, 18, 20 are traced or partly checked; item 4 is an owner decision (PD-IA6B-1). Items 3, 7, 16, 17, 22 are settled (see NEW-5) |
| G15 (fixed, 4e1866c) | `Documentation/showcases/url-option.md:19-90` (three modes) | Rewritten for the four values (commit 4e1866c). |

## 5. TODO and FIXME inventory

Counts after IA-3 (B9a):       54 markers in the plugin's PHP, Twig and JavaScript (`Upload/`), 0 in `tests/`. Every marker is one of:
fixed, deleted, or documented with an item ID. The per-marker list of the first audit is superseded by this table.

| Disposition | What | Where it went |
|---|---|---|
| Fixed (earlier runs) | `Plugin/Core.php:1194` rename to collection_id (the column already has the name); `RenderManager.php:1164` `or true`; `forum_hooks.php` status clauses and the embed rule; `EntriesController.php` status-clause TODOs closed with a reason | IA-2 and IA-3 records (B6, B8b) |
| Fixed (IA-3) | `EntriesManager.php` TODO(verify) (verified: `linkAttachmentsByPostHash()` has the called signature) | B9a commit 7c9d2c4 |
| Deleted (IA-3) | 25 commented-out PHP statements; the dead `Showcase::moderatorPermissionsGet()` chain and `showcaseDefaultModeratorPermissions()`; two unused imports | B9a commits 7c9d2c4 and 201ab5e |
| Documented, schema (B9) | `Plugin/Core.php` `update_dateline` and `edit_user_id` should be history ids (NEW-3); `:440` varchar to varbinary (Q4); `:1993` legacy update and the TINYINT format change (kept: it runs on old boards, D1 decides); `:2157` drop the redundant field; `admin.php:160` drop old columns | pending-work B9, NEW-3, Q4 |
| Documented, planned features | `Plugin/Core.php` the lines 2361 to 2419 (Extra Forum Permissions, ougc, display type, settings, notices, ordering, search, DVZ, NewPoints, users' own unapproved content, Pages menu) | NEW-4 |
| Documented, rendering and controllers | `RenderManager.php` `:300` post-icon setting (B8), `:326` empty when previewing (B9), `:915` unread comments (NEW-2), `:917` reports table (G2c), `:1121` one route for attachments (B6), `:454` additional groups (B6); `EntriesController.php` `:464` mod options (B6), `:540`, `:637` unread comments (NEW-2), `:1223` slug loads field data (B9), `:1553` empty slug (B9); `Routing.php:425` viewing user (B6) | pending-work B6, B8, B9, NEW-2, G2c |
| Documented, forum hooks and attachments | `forum_hooks.php` `:332` cache (G2c), `:445`, `:446`, `:456` (G2c); `AttachmentsManager.php` `:220`, `:221` (B9, T6), `:547` phpThumb (T6), `:594` (B9) | G2c, T6, B9 |
| Documented, entries and fields | `EntriesManager.php` `:168` offsetUnset (B9), `:226` stale matchesUser note (B9), `:1326` data handler (B9); `FieldTrait.php:366` separator description (B8); `Plugin/Functions.php` `:656` TEXT defaults, `:718` uid key (B9); `pageEntryCreateUpdateField.twig:204` (B9) | B8, B9 |
| Documented, permissions | `UsersManager.php:230`, `:232` (B7); `PermissionsFactory.php:10` (unassigned); `UserPermissions.php:76` (B6) | B6, B7 |
| Documented, prose and planned phrases | the `Documentation/` prose lines (NEW-3, B9); the planned-behaviour phrases (Q7, NEW-1) | Q7, NEW-1, NEW-3, B9 |

`CanManageAttachments` is not referenced anywhere (nothing to remove). `moderatesShowcase()` no longer exists in the code.

## 6. Known limits accepted for now

* Field keys that match an SQL word are refused through a list until identifiers are quoted everywhere (Q1); the MariaDB part of
  the list is not verified on MariaDB here (Q2).
* A deleted downloads entry cannot be restored from a file until the zip package exists (Q3).
* Text IP values written by older versions are not converted; no migrations by policy (Q4).
* The friendly-link rewrite rules are documented and tested with PHP's built-in server only (Q5).
* The plugin still declares four Composer dependencies, two of which no code uses (Q6).
* The reserved `poll` and `rating` types are refused with a message; quiz does not exist (Q7).
* Test boards are SQLite, MySQL and PostgreSQL; the field matrix does not run on MariaDB.
* This document was written without running anything: run-time claims are marked unverified and need a test before they are
  relied on.

## Status after the integrity-audit run (commits on agent/integrity-audit)

* TZ (password throttle time zone, D285): done. Test aa9c8e8, fix 7453005. The gate writes and compares its window in UTC on
  SQLite, MySQL and PostgreSQL (tests/features/http/password_throttle.py: 4/0 on each engine, from 2/2).
* B7 (per-area moderator model, P1): done. Test b837c30, fix 441c1cb, fixture 34fbad0, documentation 712fe1e. Entries and
  comments are decided per area (EntriesManager::isModerator(area)); the attachments, reports and logs flags are removed from the
  model. tests/features/http/moderator_areas.py: 6/0 on each engine (from 4/2).
* B6 (visibility and access), part one: done for the attachments of an entry page. Test 0f771f7, fix 6f108cb (the "or true" in
  RenderManager::buildAttachments() is replaced by the P6 rule). tests/features/http/unapproved_attachments.py: 4/0 on each
  engine (from 1/3 on SQLite). Not done: the status-clause TODOs of FieldTrait (line 309), forum_hooks (line 335) and RenderManager
  (line 355), which still list unapproved attachments without the P6 rule; the download rule in AttachmentAccess::decide() for an
  owner without the entries right; the comment, export and thumbnail re-check.
* B8a, B8b, B5, B9a, B9b, DOCS (section 4 gaps G5 to G14) and FR1: not started in this run.

## Status after the integrity-audit-2 run (IA-2)

* GG (guest password grant, D296, D297): done. Tests 44acd98 (unit), e947c92 (HTTP), 609e556 (throttle test); fix 7e84cea.
  A guest's grant is a signed cookie per showcase (Util/PasswordGrant.php, key from the datacache: MyBB 1.9 has no board secret of
  its own). A guest's failed attempts are counted per address (5 inside the interval). Counts: guest_grant.py 1/2 before, 16/0 after
  on each engine; password_grant.php 19/0; password_throttle.py 4/0 on each engine.

* B6 (visibility and access, P6): done for the paths the brief lists. Test 67a9e1d (fixture) with the before state 4 passed,
  3 failed on each engine; fix 0ee39e5 (comment attachments follow the comments area; the owner needs the view right); remainder
  26377cd (forum post embeds follow the rule; status-clause TODOs closed with reasons). tests/features/http/attachment_rules.py:
  7/0 on each engine (from 4/3). unapproved_attachments.py 4/0 and moderator_areas.py 6/0 on each engine. Not covered by a test:
  the forum post embed (forum_hooks.php) has no HTTP test yet; the matrix cell for the owner without the view right is not added
  (no matrix user lacks the view right; attachment_rules.py check D covers it).
* B8a (status codes, P2): done. Static test 30dec39 (23 calls; 13/10 before). Fix f95b10f: upload and import/export refusals
  answer 400; unknown report targets 404; the version check 500; page not found 404 and the fault branches of showcase.php 500
  (MyBB's error() takes the status as its fourth argument). url_absolute.py 540/0 and fields run.py 823/0 (91 n/a) on each
  engine; run.py 147/0 each. The fields fixture's moderator row now carries the B7 flags (it had none and failed on all engines).
* B8b (names and language strings): partly done. Test and fix 5dd296f: tests/status/strings.py (undefined language keys and the
  "purse" spelling; first run 88 failures, after the fix 1883/0); the purge keys renamed with no alias; the moderator icon labels
  defined. features run.py 147/0 on each engine. Not done: the camelCase admin keys (myShowcaseAdmin*, 119 keys, 8 of them already
  exist in snake form and need a value comparison); the hard-coded English sentences of Upload/showcase.php (the errorSentence
  fallbacks, by design D146), AttachmentsManager and FieldTrait; the collection_id TODO in Plugin/Core.php.

## Status after the integrity-audit-3 run (IA-3)

* PW (showcase password stored as a hash, D306): done. Test 25b5f04 (before: 2 passed, 7 failed on each engine); fix 4cbbae8
  (ACP form never shows the value, empty keeps it, remove control clears it; gate uses password_verify; fixture hashes). After:
  showcase_password.py 9/0 on each engine; guest_grant 16/0; password_throttle 4/0; url_absolute 540/0; acp_bounds 13/0 (sqlite).
  Documentation/README.txt tells a board to set its showcase password again once.
* B8b-rest (D303): done for the camelCase admin keys. Test 2a85aa0 (strings.py check C, before: 186 failing occurrences); rename
  a261171 (119 keys, 187 occurrences; myShowcaseAdminErrorTableDrop gets a text; the seven admin labels whose camelCase key was
  undefined now show their snake text). strings.py 1934/0 after; features run.py 147/0, json-sync run.py 157/0, url_absolute 540/0,
  fields run.py 823 pass (91 n/a) on sqlite. The collection_id TODO is closed (the column already has that name). Not done: the
  user-facing literal messages in the services (FieldService, ShowcaseService, CollectionService, JsonSync, CommentsManager, and
  AttachmentsController's "File uploaded successfully"); the two files the brief names (AttachmentsManager, FieldTrait) have no
  literal sentences left.
* B6-gaps: done. Embed test d900ef4 (test), 08ec02f (plainmember as the other viewer), 9ef5d91 (fix: both loops of postbit use
  postbitMayEmbed), ac0ec85 (fix: the post-bit query leaves out the bytea address column on PostgreSQL). forum_embed.py: sqlite 5/0,
  mysql 5/0, pgsql 5/0. Before the fix on sqlite and mysql the embed leaked (E1 and E2 fail: 3 passed, 2 failed). access_matrix.py
  112 cells, 0 failing on sqlite, mysql and pgsql. The owner-without-view matrix cell is still not added (no matrix user lacks the
  view right; attachment_rules check D covers it).
* B9a (markers, dead code, composer, P5): done in part. Commits 002eb46 (symfony/uid and teamtnt/tntsearch removed from composer.json
  and the lock: the lock drops predis/predis, symfony/uid, symfony/polyfill-uuid and tntsearch; symfony/form and
  symfony/dependency-injection stay), 7c9d2c4 (the dead moderator-permission chain of Showcase.php, the unused function and imports,
  the verified TODO(verify)), 201ab5e (25 commented-out statements). Section 5 is rewritten: 54 markers remain, each fixed, deleted or
  documented with an item ID. Checks: unit suites all pass; features run.py 147/0, json-sync run.py 157/0, access_matrix 112/0 on
  sqlite; the URL guard passes with 0 sites outside the allow-list. Not done: the legacy update code of Plugin/Core.php (:1993, kept:
  it runs on boards upgraded from old versions, a compatibility decision for the owner); docs/import-export-design.md NEW-5 (not
  re-checked in this run).
* B9b (integrity report and warnings): done in part. Commit c28032a: tests/features/http/integrity_report.php (read-only; five orphan
  kinds must be zero, two information kinds), run at the end of tests/features/http/run.py. Counts: features run.py 147/0 on each
  engine with 0 orphans and no column outside TABLES_DATA; the report found 8 + 15 false orphans on the first try (a basename
  mismatch in the report, fixed in the same commit; the database had none). Warnings pass: with error_reporting E_ALL and log_errors
  on, the features run and the JSON-sync, import-matrix, url_absolute, url_mode and quote_dates suites logged no PHP warning,
  notice or deprecation on SQLite; the features run logged none on MySQL and PostgreSQL. Binary columns: one VARBINARY column in the
  plugin (ipaddress); its readers convert the PostgreSQL stream (EntriesManager, CommentsManager) or leave it out of the query
  (attachments); no reader of the access log. Not done: the import-run hook of the integrity report (the features hook exists);
  comments_history.ipaddress is written by no code path (not verified further).
* B5 (PWA, P4): held back, one step. The brief asks for the worker's default scope to be the board folder while the worker is
  served through a showcase route (scope = the directory of the script URL, /showcase.php/, not the board folder). This needs an owner
  decision on the shape (P4): a root-level script (a rewrite or a file in the board root) or a scope header. Not started.
* END (IA-3, final HEAD, see the hand-over): the listed suites pass on sqlite, mysql and pgsql (access matrix 112 cells, password,
  guest grant, throttle, moderator areas, unapproved attachments, attachment rules, forum embed, features with the integrity report,
  url_absolute, url_mode, quote_dates, fresh install, JSON-sync HTTP, acp bounds, field matrix 823 pass 91 n/a, abort and cleanup
  harnesses, second board 40/0, import HTTP 205/0 twice on sqlite); interrupt harness on sqlite for the four features harnesses: 3/0
  each. Static: unit suites, guard, strings and status tests, seeds coverage, php -l and py_compile all pass.

## IA-4 (continuation): status

### B5. PWA: manifest and service worker (IA-4)
- Done on sqlite, mysql (MySQL 8.4.7) and pgsql (PostgreSQL 18.1), this Mac (x86_64, DBngin binaries, own servers on 3307 and 5433).
- Test: `tests/features/http/pwa.py` (862f610, e41d000). Fix: eeb7470 (the worker and manifest routes, query-form registration, cache list at the worker origin, icons), 96a52ce (manifest scope is the board folder, D316).
- pwa.py: 185 passed / 0 failed on all three engines (177 / 8 before D316). url_absolute 540/0 and url_mode 615/0 on all three engines; URL guard PASS.
- Held back, not in this run: MSG, LEG, NEW5, HOOK and END (see the status file for the reasons).

## IA-5: status

### PWA-SUB: the PWA test with the board in /sub/
- The subfolder run is in tests/features/http/pwa.py (2b6d8d3, the test; f49dc05, the harness fix). Before the harness fix:
  sqlite 337 / 32 failed, mysql 345 / 24, pgsql 363 / 6. The failures were the test server serving a stale settings file
  (opcache revalidation, up to 2 seconds): the plugin was correct. After: 369 passed, 0 failed on all three engines.
- The static guard passes. No plugin code changed for this item.

### MSG: user-facing messages in src/Service and src/Controller
- Test: the strings check M (748a076, e144136). It finds 34 sentences before the move, then 32 after the allow-list of
  date formats and an HTTP header (e144136 states 47 in its message; the correct count is 32).
- MSG-a (7761ac1): the six front-end messages are keys in myshowcase.lang.php; the three redirect literals after "??" were dead.
- MSG-b (5fc99af): the 28 ACP and JSON-sync messages are keys in the admin language files; the JSON-sync step labels are keys
  resolved by the page. The helper Util/AdminText.php reads the admin files for the services without a language object.
- The strings check: 1968 passed, 0 failed after MSG-b.

