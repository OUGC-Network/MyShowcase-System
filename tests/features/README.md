# Features harness

`tests/features/http` checks, on a real board, the forum-side features the gallery and the blog rely on: authorization of
every write handler, moderation of new entries, deletion, search, attachment serving, the edit form and the main list.
`run.py` installs the board, sets explicit permissions for each group (the fixture `fixture.php`, `setup`) and runs the checks
in `features.py`. The run must end with 0 failures on each engine.

    python3 tests/features/http/run.py --engine sqlite|mysql|pgsql --mybb <MyBB 1.9 source tree> --scratch <directory> [--keep]

PostgreSQL: set `PGHOST`, `PGPORT` and `PGUSER`, and `PSQL_BIN`, as for the import suite (`tests/import/README.md`).

## Fresh-install stage (decision D3)

`fresh_install.py` is a separate stage. It installs and activates the plugin on a new board through the ACP, creates the
shipped blog and gallery showcases, and never saves the global group permissions. The defaults must then be enough:

    python3 tests/features/http/fresh_install.py --engine sqlite|mysql|pgsql --mybb <MyBB 1.9 source tree> --scratch <directory>

It checks, in order:

* **S1 reach**: every group (guest, members, moderator, administrator) reaches what the defaults allow: the list, an approved
  entry, its attachment and thumbnail. The new-entry form is refused, because `entries_can_post` defaults to 0.
* **S2 cycles**: a deactivate and an activate leave one global row per user group, each equal to the defaults.
* **S3 partial**: a saved row stays byte-identical while the missing rows are added; no duplicate row.
* **S4 late group**: a group created after the install reaches the defaults, and reading it writes no row.
* **S5 idempotent**: a second activation changes no row.
* **S6 ACP form**: the group form shows the seeded values, and saving it unchanged changes no row.
* **S7 uninstall**: with the table-drop setting, the uninstall drops the permission table with its rows.
* **S8 refused main view**: with the guest group's main view refused, the list, an entry, the new-entry form, an attachment and a
  thumbnail answer 403 with the no-permission page (not 503: decision D221); a group that is not refused still reaches the list.

The expected values are the board's own defaults (`TABLES_DATA`), read through `fresh_fixture.php`; the stage writes no
permission row itself, except through the save of S3 and S6 that a group form makes. `fresh_fixture.php` is test data only.

The defaults are the ones the ACP group form shows for a group without a row. A change to a default changes the stage's
expectations with it, and the form check (S6) keeps the form and the seeded rows in agreement.

## Two checks that depend on a decision of the document

* An entry awaiting approval is hidden from its author unless the author's group has `entries_can_view_unapproved`
  (`Documentation/visibility.md`, Entries). The features check tests both sides.
* A thumbnail needs no download permission (design record T35); the full file does. The features check expects a thumbnail
  to be served and the file refused for a group without the download permission.

## URL option (decision D224)

`tests/features/http/url_mode.py` checks the links of a showcase for each Links choice (0 No, 1 Automatic, 2 Yes) against
each seourls setting (no, auto, yes), on a plain request and on a request with SEO_SUPPORT, on the list page, the entry
page and the who's-online location; both forms of an address answer the same entry; a custom prefix gives identical
forms; the ACP form saves and refuses; and the JSON-sync file carries the option. It runs on one engine per call:

    python3 tests/features/http/url_mode.py --engine sqlite|mysql|pgsql --mybb <MyBB 1.9 source tree> --scratch <directory>

The Automatic rule itself is a board-less unit test: `php tests/url-mode/run.php`.

Since the full migration, the same run also covers the comment, attachment, export, import and canonical links (as a guest
and as a member), with the prefix as stored and with an empty one, and the export file URL and the own-board check of an
import (both forms, every mode). The static guard that every link goes through the builder is `php tests/url-builder/guard_test.py`
(no board needed; its allow-list and reasons are in `tests/url-builder/allow-list.json`).

## Absolute URLs (decision D244)

`tests/features/http/url_absolute.py` installs a board and checks every URL the plugin's pages emit (href, src, action,
srcset, poster, data-*url*, meta and canonical content, url() in CSS, URL-like strings in scripts): each must be absolute,
and on the expected base (the showcase base for a route of the showcase, the board address otherwise). It covers five
showcase URL forms, two board address forms (the root, a subfolder with a trailing slash) and url_mode 0, 1 and 2, for a
guest and a member, on nine pages. MyBB's own header and theme are not checked (an explicit list in the test). The unknown
entry's status is Batch 8 and is accepted here.

    python3 tests/features/http/url_absolute.py --engine sqlite|mysql|pgsql --mybb <MyBB 1.9 source tree> --scratch <directory>

Its page set includes the list's second page and the entry's comments on page two (a page size of one), and it asserts that
each of those pages has URLs to read. The entry view API carries no URL; the attachment API answers URLs, but 403 to a
member without the entry-edit right, so the page set does not reach it (D248). MyBB's own header and theme are excluded by
an explicit list in the test.

A stored quote's date (`[quote datetime='…']`) is text a member writes, so it can be numeric or malformed. The test
`tests/features/http/quote_dates.py` stores three such quotes in comments and requires the entry page to answer 200 and
show their text, on each engine (D248). Before the repair it answered 400 on all three engines.

    python3 tests/features/http/quote_dates.py --engine sqlite|mysql|pgsql --mybb <MyBB 1.9 source tree> --scratch <directory>

A failed installation leaves nothing behind: `make_board` cleans up what it created, and the harnesses create the
board inside their guarded block (decision D252; see `tests/harness/README.md` and `tests/harness/cleanup_test.py`).
