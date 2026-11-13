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
setting (default no), the single rule set for attachment access and the stored-file guard. The PHP binary is the one
named `php` on the `PATH`: to run the suite on another version, put a directory with a `php` link first on the `PATH`.

Use `--port` when another server uses the default port (18092 for SQLite, 18091 for MySQL). Exit code 1 if a check fails.

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
