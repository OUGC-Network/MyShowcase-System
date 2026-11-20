# Harness hygiene

Every harness that installs a board creates its database (MySQL, PostgreSQL), its board folder and its PHP server
through `tests/json-sync/http/board.py` (`make_board`). The rule (decision D252) has two parts:

* **make_board cleans up after itself.** If any step after the creation fails (the database, the copy of MyBB, the
  installer, the plugin links, the server), `make_board` stops the server it started, drops the run's database, removes the
  board folder and the server log of that call, and re-raises the error. A leftover is reported on stderr.
* **The board is created inside the guarded block.** A harness calls `make_board` inside its `try`, with `ctx = None`
  before it, and closes the Ctx in the `finally` only when it exists. No harness creates a board outside that block.

`cleanup_test.py` checks the first part. It makes the installer fail after the database exists (php is not on PATH, so the
install step raises for `php`), then requires that no database of the run, no listener on the run's port and no file in the
run's folder is left. The failure must be the injected one, or the check is void.

    python3 tests/harness/cleanup_test.py --engine sqlite|mysql|pgsql --mybb <MyBB 1.9 source tree> --scratch <directory>

Before the repair it failed on each engine: the board folder on all three, and the database too on MySQL and PostgreSQL.
