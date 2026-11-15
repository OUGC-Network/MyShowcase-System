# Entries import tests

## Unit tests (no database, no MyBB)

```
php tests/import/run.php
php tests/import/fetcher/run.php
```

`run.php` covers the upload checks, the external files setting (default no), the URL joins, and the stored-file guard of
the import transaction (a fake database). `fetcher/run.php` covers the fetcher's rules with a fake transport and a fake
resolver: no network is used. Run both on PHP 8.2 and on the highest PHP the board supports (8.5).

## HTTP suite (a real board, one database engine)

```
python3 tests/import/http/run.py --engine sqlite|mysql --mybb <source tree> --scratch <directory>
```

It installs a scratch board for one engine, creates the blog, gallery and downloads showcases through the ACP, creates
entries through the forum form, exports them and imports them back, and checks the refusals, the permissions, the
setting (default no), the single rule set for attachment access and the stored-file guard. The option tokens are checked
twice: `section_option_tokens` (format 3, a format 2 file with an option value refused whole, an unknown token) and
`section_option_numbering` (decision D128): the blog's category options are copied so that each field_data_id differs from
its value_id; the export holds the value_id, the import stores the field_data_id of the option with that value_id and the
entry page shows its label; a field_data_id used as a token, a value of another field's option and a blank required value
are refused; the shipped downloads entries hold the value 1 option of their category, platform and license fields.
`section_option_portability` (decisions D128, D137) copies the category options to a second numbering on the same board and
imports a file with the same value ids: each copy holds the field_data_id of its value id in the second numbering and the
label of the original. This is an option-only check on one board. It is not a second board and it does not compare the
users, groups or file URLs of a file, so it does not show whole-file portability.
`section_downloads_round_trip` (decision D142) exports the downloads entries and imports the file into the same board: each copy holds the
field_data_id of the option with the value id of the file for category, platform and license, and the same file name and size. Then the
entries are removed as data and the same file is imported: it is refused as a whole, because the own-board file of a removed entry has no
visible entry (decision D58), and nothing is stored. The fresh entry is imported from the GitHub ZIP of dev-1.9 (network needed; a slow
download fails the check, as section F6 does).
`tests/import/http/second_board.py` (decision D142, c) is a real second board: board A exports its blog entries, and board B, a second
install where the gallery collection is created first and five extra options are added before the blog's options (so collection, field and
option ids differ), imports the file as another user. Per entry: each option holds B's field_data_id of the option with the exported value id,
the label shown on B's entry page is the one of A, and the text fields are equal. Users, groups and file URLs are not compared (D137).
It runs on SQLite and MySQL, two installs per run: `python3 tests/import/http/second_board.py --engine sqlite|mysql --mybb DIR --scratch DIR`.
The blank optional option values and the format 2 file with only blanks are covered by the field matrix (stage `i`, decision D142). The PHP binary is the one
named `php` on the `PATH`: to run the suite on another version, put a directory with a `php` link first on the `PATH`.

Use `--port` when another server uses the default port (18092 for SQLite, 18091 for MySQL). Exit code 1 if a check fails.

## Access matrix (users, attachments and modes of the download route)

```
python3 tests/import/http/access_matrix.py --engine sqlite|mysql|pgsql --mybb <source tree> --scratch <directory> [--old-upload <directory>]
```

It installs the NEW board (this working tree's `Upload/`) and, with `--old-upload`, an OLD board from the Upload folder of
the previous route. Export that folder from the repository with `git archive <branch> Upload` into a scratch directory, for
example `git -C <worktree> archive main Upload | tar -x -C <directory>/old-src`, and pass `<directory>/old-src/Upload`.
Both boards get the same users, groups, permission rows, attachment types and attachments (created through the entry form
and the upload route, with locally generated PNG and ZIP bytes). For every user, attachment and mode (file or thumbnail)
the script compares the HTTP status and the SHA-256 of the body of both boards, records the answer of
`AttachmentAccess::decide()` (the test-only probe `fixtures/msc_test_probe.php` is installed into the NEW board only), and
imports the own-board file URL of each attachment with the setting on. It exits with 1 if a cell differs from the rules.
The result of every cell is written to `<directory>/matrix-<engine>.json`.

A user without the main view permission gets the front controller's own no-permission page, which fails with a 503 in the
MyBB build of this batch; the same happens on the previous route, so that cell is marked as explained, not as a difference.

## Abnormal end of an import

```
python3 tests/import/http/abort_test.py --engine sqlite|mysql|pgsql --mybb <source tree> --scratch <directory>
```

The request of a two-entry import is ended by the test-only plugin `fixtures/msc_test_abort.php` (installed into the
temporary board only): by `exit` and by a fatal error on the second entry, which is after the first entry's file was
stored and recorded. The test checks that nothing is left after the request: no entry row, no attachment row, no import log
row, no file and no subfolder of the upload folder, and no temporary folder of the import. A normal import then has to
complete. A third case ends the request after a file was copied and before it is recorded: that window is accepted and
not covered by the guard, so the test only reports what is left in it.

## PostgreSQL

The matrix and the abnormal-end test accept `--engine pgsql`. They use the throwaway database `msc_test_<random>` of the
server named by `PGHOST`, `PGPORT` and `PGUSER` (and `PSQL_BIN` for the client program), and drop it at the end. The run
needs a server that may create and drop databases: start a throwaway instance from the installed binaries for the run, with
its own data directory in the scratch directory, and stop it afterwards.

## Building the source tree

The suite copies a MyBB 1.9 source tree into its scratch directory and never changes the source. Build the source tree
from the MyBB 1.9 branch, with its own Composer dependencies:

```
mkdir -p <directory>/mybb-src
git -C <path to the mybb19 repository> archive dev-1.9 | tar -x -C <directory>/mybb-src
composer install --no-interaction --working-dir=<directory>/mybb-src
```

Do not copy a `vendor/` folder from another board or from a working copy of `mybb19`. The `dev-1.9` lock file pins
`scssphp/scssphp` at 1.13.0, and the theme compile breaks with another version (the `mybb19` working copy has 2.1.0).
A vendor folder must come from the board's own lock file, which `composer install` inside the board produces.

The `mybb19` repository is read only: the recipe reads it with `git archive`, and never reads the configuration files
(`inc/config.php`, `inc/settings.php`) of any board.

## Network

The import tests may contact only `placehold.co`, `github.com` and `codeload.github.com`, over https, without credentials
or cookies. `composer install` may contact `packagist.org`, `repo.packagist.org`, `api.github.com`, `github.com` and
`codeload.github.com`, for installs only. The private-address and SSRF cases are tested with the fake transport and with a
local server bound to 127.0.0.1 that exists only to show that nothing was requested from it.

## Databases

* SQLite needs nothing else.
* MySQL (or MariaDB) and PostgreSQL need a server that the run may use for a throwaway database only. The suite creates
  `msc_test_<random>` and drops it at the end. The variables are `MYSQL_BIN`, `MYSQL_HOST`, `MYSQL_PORT`, `MYSQL_USER`
  and `MYSQL_PASSWORD` (see `tests/json-sync/README.md`). Never point the suite at a database that holds data you keep.
* A throwaway server can be started from the locally installed binaries with its own data directory, bound to 127.0.0.1
  on a free port, and stopped and removed when the run is over.
