# Final regression: the runners, their order and what each needs

Decision D352. This file lists every runner under `tests/`, the exact command to run it from the repository root, the
engines it supports, its port block, the order it runs in, the result it prints, and what it needs. The driver
`tests/run_final_regression.py` runs the same list in the same order. Nothing here has been executed in the run that wrote
it (decision D348): the owner runs the suite (D349).

## Requirements

- **PHP 8.2** for every PHP runner and for every board runner (the board runners call `php` on the PATH). The driver takes
  the binary with `--php` and puts its folder first on the PATH.
- **Python 3**, standard library only, for the Python runners. Their commands below say `python3`; the driver uses the
  interpreter it runs under.
- **A MyBB 1.9 source tree with its vendor/ folder** for every board runner (`--mybb`, or the `MYBB_SOURCE` variable). The
  tree is copied, never changed.
- **PluginLibrary 13** for the board runners that install the real library: `--pluginlibrary-file FILE`, or the
  `PLUGINLIBRARY` variable, or the default `../_deps/MyBB-PluginLibrary/inc/plugins/pluginlibrary.php` next to the
  repository. When none of these is a file, the runner downloads it once from its official repository (this needs the
  network).
- **MySQL or MariaDB** (engine `mysql`) and **PostgreSQL** (engine `pgsql`): servers the owner starts. The runners never
  start them. Their ports are named on the command line (`--mysql-port`, `--pg-port`, the driver has no default). The runners
  read `MYSQL_BIN`, `MYSQL_HOST`, `MYSQL_PORT`, `MYSQL_USER`, `MYSQL_PASSWORD`, `PSQL_BIN`, `PGHOST`, `PGPORT`, `PGUSER`
  (`tests/json-sync/README.md`); the driver sets the host and the ports, `--pg-user` sets the role.
- **SQLite** needs nothing beyond PHP's `pdo_sqlite`.

## Ports

Each board runner has its own default port block, so runners in one run never share a port. The runners are serial: each
starts and stops its own PHP server. The blocks below are the defaults. The driver passes no port unless a runner needs one.

| Runner | sqlite | mysql | pgsql |
|---|---|---|---|
| `tests/json-sync/http/run.py` | 8082 | 8081 | 8083 |
| `tests/features/http/run.py` | 8085 | 8084 | 8086 |
| `tests/fields/http/run.py` | 18390 | 18391 | 18392 |
| `tests/fields/http/acp_bounds_test.py` | 18400 | 18401 | 18402 |
| `tests/import/http/run.py` | 18092 | 18091 | 18093 |
| `tests/import/http/second_board.py` | 18094 (board A), 18095 (board B) | the same | the same |
| `tests/import/http/abort_test.py` | 18300 | 18310 | 18320 |
| `tests/import/http/access_matrix.py` | 18200 | 18210 | 18220 |
| `tests/features/http/url_absolute.py`, `pwa.py`, `moderator_areas.py` | 18950 | 18951 | 18952 |
| `tests/features/http/url_mode.py` | 18900 | 18901 | 18902 |
| `tests/features/http/attachment_rules.py` | 18900 | 18901 | 18902 |
| `tests/features/http/showcase_password.py` | 18940 | 18941 | 18942 |
| `tests/features/http/password_throttle.py` | 18920 | 18921 | 18922 |
| `tests/features/http/unapproved_attachments.py`, `moderation_restore.py` | 18960 | 18961 | 18962 |
| `tests/features/http/quote_dates.py`, `new5_board.py` | 18970 | 18971 | 18972 |
| `tests/features/http/forum_embed.py` | 18980 | 18981 | 18982 |
| `tests/features/http/guest_grant.py` | 18990 | 18991 | 18992 |
| `tests/features/http/fresh_install.py` | 18710 | 18711 | 18712 |
| `tests/harness/cleanup_test.py` | 18996 | 18996 | 18996 |
| `tests/harness/interrupt_test.py` (the `--port` the driver passes) | 18997 | - | - |

The runners that share a block (for example `url_mode.py` and `attachment_rules.py`) run at different times, so they do not
collide. Ports 3306 and 5432 are never used by the driver; the MySQL and PostgreSQL servers listen where the owner starts
them (the runbook in the hand-over file uses 3307 and 5433).

## Order

1. The engine-free runners (section A), once each.
2. For each selected engine, in the order sqlite, mysql, pgsql: the board runners (section B) that support that engine.

## A. Engine-free runners (no database, no MyBB)

Each prints its own summary line; exit code 0 is the pass criterion for all runners.

| # | Runner | Command (from the repository root) | Summary line it prints |
|---|---|---|---|
| A1 | `tests/util/run.php` | `php tests/util/run.php` | `N passed, M failed` |
| A2 | `tests/util/password_grant.php` (the password-grant unit test) | `php tests/util/password_grant.php` | `N passed, M failed` |
| A3 | `tests/post-hash/run.php` | `php tests/post-hash/run.php` | `N passed, M failed` |
| A4 | `tests/url-mode/run.php` (the Automatic rule of the URL option) | `php tests/url-mode/run.php` | the runner's own lines |
| A5 | `tests/xml-file/run.php` | `php tests/xml-file/run.php` | `N passed, M failed` |
| A6 | `tests/exports-storage/run.php` | `php tests/exports-storage/run.php` | `checks passed: N` |
| A7 | `tests/import/run.php` | `php tests/import/run.php` | `N passed, M failed` |
| A8 | `tests/import/fetcher/run.php` | `php tests/import/fetcher/run.php` | `N passed, M failed` |
| A9 | `tests/json-sync/run.php` | `php tests/json-sync/run.php` | the runner's own lines |
| A10 | `tests/fields/unit/run.php` | `php tests/fields/unit/run.php` | `N passed, M failed` |
| A11 | `tests/status/field_factory_create.php` | `php tests/status/field_factory_create.php` | `N passed, M failed (field_factory_create, K checks)` |
| A12 | `tests/status/access_log_delete.php` | `php tests/status/access_log_delete.php` | `N passed, M failed (access_log_delete, K checks)` |
| A13 | `tests/fixtures/seeds/coverage_test.py` | `python3 tests/fixtures/seeds/coverage_test.py` | the runner's own lines |
| A14 | `tests/url-builder/guard_test.py` (the static guard of the one URL builder) | `python3 tests/url-builder/guard_test.py` | exit 0 when clean |
| A15 | `tests/status/status_codes.py` (B8a) | `python3 tests/status/status_codes.py` | `N passed, M failed (error statuses, K calls)` |
| A16 | `tests/status/strings.py` (B8b) | `python3 tests/status/strings.py` | `N passed, M failed (strings, K checks)` |
| A17 | `tests/status/legacy.py` (LEG) | `python3 tests/status/legacy.py` | `N passed, M failed (legacy, K checks)` |
| A18 | `tests/status/acp_moderator_delete.py` (static check of the ACP moderator delete, NEW5B item 22) | `python3 tests/status/acp_moderator_delete.py` | `N passed, M failed (acp_moderator_delete, K checks)` |

## B. Board runners (one board per run, one engine per run)

Every board runner is started by the driver as
`python3 <runner> --engine <E> --mybb <MyBB tree> --scratch <folder>` plus the arguments in its row. Run one by hand the same way
from the repository root. The scratch folder is new for each run and may be deleted afterwards.

| # | Runner | Engines | Extra arguments | Result it prints |
|---|---|---|---|---|
| B1 | `tests/fields/http/run.py` (the field matrix; known failures in `tests/fields/http/known_failures.json`) | sqlite, mysql, pgsql | none | a matrix in `matrix-<engine>.json`; exit 0 only when the failing cells equal the known-failure list |
| B2 | `tests/fields/http/acp_bounds_test.py` (stage a of the matrix) | sqlite, mysql, pgsql | none | the checks, exit 0 when all pass |
| B3 | `tests/features/http/run.py` | sqlite, mysql, pgsql | none (the PluginLibrary as in Requirements) | the checks and `N passed, M failed` |
| B4 | `tests/features/http/url_mode.py` | sqlite, mysql, pgsql | none | the checks |
| B5 | `tests/features/http/url_absolute.py` | sqlite, mysql, pgsql | none | the checks |
| B6 | `tests/features/http/quote_dates.py` | sqlite, mysql, pgsql | none | the checks |
| B7 | `tests/features/http/fresh_install.py` | sqlite, mysql, pgsql | none | the checks |
| B8 | `tests/features/http/pwa.py` (root board and the subfolder board `/sub/`, PWA-SUB) | sqlite, mysql, pgsql | none | the checks, both boards |
| B9 | `tests/features/http/showcase_password.py` | sqlite, mysql, pgsql | none | the checks |
| B10 | `tests/features/http/guest_grant.py` | sqlite, mysql, pgsql | none | the checks |
| B11 | `tests/features/http/password_throttle.py` | sqlite, mysql, pgsql | none | the checks |
| B12 | `tests/features/http/moderator_areas.py` | sqlite, mysql, pgsql | none | the checks |
| B13 | `tests/features/http/moderation_restore.py` (R19 and D337) | sqlite, mysql, pgsql | none | the checks; passed 6/6 per engine before D348 on the earlier commit |
| B14 | `tests/features/http/new5_board.py` (items 1, 2, 10, 11, 18, 20, 22) | sqlite, mysql, pgsql | none | the checks, `N passed, M failed` |
| B15 | `tests/features/http/unapproved_attachments.py` | sqlite, mysql, pgsql | none | the checks |
| B16 | `tests/features/http/attachment_rules.py` | sqlite, mysql, pgsql | none | the checks |
| B17 | `tests/features/http/forum_embed.py` | sqlite, mysql, pgsql | none | the checks |
| B18 | `tests/json-sync/http/run.py` (the HTTP flow of the JSON sync) | sqlite, mysql, pgsql | none (`--pluginlibrary real` by default) | the flow's checks |
| B19 | `tests/import/http/run.py` (the HTTP tests of the entries import) | sqlite, mysql, pgsql | none | the checks |
| B20 | `tests/import/http/second_board.py` (two boards, D142 c) | sqlite, mysql, pgsql | none | the checks |
| B21 | `tests/import/http/abort_test.py` (an import that ends early, D59) | sqlite, mysql, pgsql | none; it fetches its files from placehold.co | the checks; exit 1 when case 1 or 2 leaves anything behind |
| B22 | `tests/import/http/access_matrix.py` (the download route, D58) | sqlite, mysql, pgsql | none (`--old-upload` is optional and not used here) | the matrix; exit 1 on a failed cell |
| B23 | `tests/harness/cleanup_test.py` (cleanup after a failed install, D252) | sqlite, mysql, pgsql | none | the checks; nothing left behind |
| B24 | `tests/harness/interrupt_test.py --script tests/features/http/run.py --port 18997` | sqlite only | `--script`, `--port` | the checks (D256) |
| B25 | `tests/harness/interrupt_test.py --script tests/features/http/quote_dates.py --port 18997` | sqlite only | `--script`, `--port` | the checks |
| B26 | `tests/harness/interrupt_test.py --script tests/features/http/moderation_restore.py --port 18997` | sqlite only | `--script`, `--port` | the checks |
| B27 | `tests/harness/interrupt_test.py --script tests/features/http/new5_board.py --port 18997` | sqlite only | `--script`, `--port` | the checks |

Notes for the owner:

- The interrupt harness is run for four feature harnesses (B24 to B27) on SQLite. The four are the choice of this file.
- The matrix (B1) is green only while its failing cells are exactly the list in `known_failures.json`; a new failing cell or a
  listed cell that passes makes the run exit 1.
- `tests/fields/http/msc_test_failure.php`, `tests/import/http/fixtures/`, `tests/features/http/integrity_report.php`,
  `fresh_fixture.php`, `fixture.php`, `front.py`, `features.py`, `board.py`, `acp.py`, `flows.py` and the URL and PWA router
  files are helpers that the runners load; they are not run on their own.
- `tests/json-sync/http/screenshots.py` takes screenshots with headless Chromium and is not part of the regression run.

## Driving it

```bash
python3 tests/run_final_regression.py --php /path/to/php-8.2 --logs /path/to/logs --engines sqlite,mysql,pgsql \
    --mybb /path/to/mybb-1.9 --mysql-port 3307 --pg-port 5433 --pg-user omarpg
```

`--only NAME[,NAME]` runs named runners (the names are printed in the table: the file's base name, or its path under tests/
where the base name is not unique, such as `util/run`). `--from NAME` resumes from a runner. The driver exits 0 only when
every run exits 0.
