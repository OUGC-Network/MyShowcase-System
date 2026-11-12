# JSON sync tests

## Unit tests (no database, no MyBB)

```
php tests/json-sync/run.php
```

Validation, hashing, status, planning, export, file-name rules, and validation of every committed file under
`imports/JSON`.

## HTTP flow (a real board, one database engine)

```
python3 tests/json-sync/http/run.py --engine sqlite|mysql|pgsql --mybb /path/to/mybb-1.9
```

It copies the MyBB 1.9 tree you point it at into a scratch directory (the source is never modified), installs it with
MyBB's own CLI installer (`bin/cli install`) under an admin account and password generated for the run, installs
MyShowcase from this working tree (symlinks; `composer install` runs in the plugin directory if `vendor/` is missing),
installs the real PluginLibrary (downloaded once from its official repository, `--pluginlibrary stub` uses a minimal
stand-in), activates the plugin through the ACP, starts PHP's built-in server and drives the ACP over HTTP with a
cookie jar and real post keys. About 130 checks: regression of the ordinary ACP flows, create from JSON, locked inputs
and forged requests, updates, every status, link, detach, export. Exit code 1 if any check fails.

* `sqlite` needs nothing else.
* `mysql` (MySQL or MariaDB) and `pgsql` need a running server that may create and drop the database
  `myshowcase_verify_<engine>`: `MYSQL_BIN`, `MYSQL_HOST`, `MYSQL_PORT`, `MYSQL_USER`, `MYSQL_PASSWORD`;
  `PSQL_BIN`, `PGHOST`, `PGPORT`, `PGUSER` (defaults: `mysql`, 127.0.0.1:3306, root; `psql`, 127.0.0.1:5432, `$USER`).
  The client programs are used to look at the database independently of the plugin.
* `--scratch DIR` keeps the board; `--port N` changes the server port (defaults 8081 mysql, 8082 sqlite, 8083 pgsql).

Screenshots of the lists, forms, preview and a failed apply, with every status on screen:

```
pip install playwright && playwright install chromium
python3 tests/json-sync/http/run.py --engine sqlite --mybb /path/to/mybb --screenshots /some/dir
```

Everything the tests create lives in the scratch board's own copy of `imports/`; the committed files are not touched.
