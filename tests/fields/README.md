# Field type tests

The field tests run every custom field kind of the plugin through the matrix of stages (definition, create form,
validation, storage, entry page, edit round trip, escaping) on a scratch board, for one database engine at a time.
They do not repair anything: a cell that fails is listed in `known_failures.json` with its cause, and the suite is green
only while the failing cells are exactly that list.

## What is in this folder

* `collections/fieldkit.json` is the FieldKit collection: one field for every HTML type the plugin offers, with the
  variants of the matrix (required and optional, defaults as defined and generated, minimum and maximum lengths, a
  validation pattern, numeric bounds, read-only, a fill and view restriction, parser settings on and off, select, radio and
  checkbox options).
* `showcases/fieldkit.json` is the showcase created from it (route `fieldkit`; the name and route are supplied at creation).
* `http/run.py` installs the board, creates the showcase through the ACP's JSON sync, creates and edits entries through the
  forum form, and writes one cell per field and stage into `matrix-<engine>.json` in the scratch directory.
* `http/fixture.php` is the board-side test data: the test users, a group that may fill and view the restricted field,
  the permission rows of the showcase, the PNG attachment type and the uploads folder. It is run from the board root and is
  not part of the plugin.
* `known_failures.json` maps each engine to the failing cells and the cause of each (file and line).

## Running

```
python3 tests/fields/http/run.py --engine sqlite --mybb <MyBB 1.9 source tree> --scratch <directory>
python3 tests/fields/http/run.py --engine mysql  --mybb <MyBB 1.9 source tree> --scratch <directory>
python3 tests/fields/http/run.py --engine pgsql  --mybb <MyBB 1.9 source tree> --scratch <directory>
```

The MyBB source tree is a copy of `dev-1.9` with its own Composer dependencies (see `tests/import/README.md`, "Building the
source tree"). The PHP binary is the one named `php` on the `PATH`: to run on another version, put a directory holding a `php`
link first on the `PATH`. The MySQL and PostgreSQL runs use the variables of `tests/import/README.md` (`MYSQL_*`,
`PGHOST`, `PGPORT`, `PGUSER`, `PSQL_BIN`); the database `msc_test_<random>` is created for the run and dropped at its end.

The exit code is 0 only when the failing cells of the engine equal its list in `known_failures.json`. A cell that fails and
is not listed, or a listed cell that passes, exits with 1: a new defect, a harness error, or a repair that must also remove its
entry from the list.

The run prints the counts per result, the distinct PHP messages of the run's error log, and the cells that differ from the list.

## Stages

Each field is checked at these stages. A cell is `pass`, `fail`, `na` (not applicable: for example no entry was stored) or
`notrun` (the stage is not automated yet; the reason is written with the cell).

* `a` definition: the ACP edit form of the stored definition. Not automated yet.
* `b` create form: the input, its label and options, the required marker, `min`, `max`, `pattern`, `readonly`, the
  pre-filled default (as defined or generated), and the visibility for a user outside the restricted group.
* `c` validation on POST: valid values, blank optional and blank required values, length and numeric bounds, patterns,
  option ids that do not belong to the field, several checked boxes, markup, multibyte text, emoji, overlong values.
* `d` storage: the value that the database holds after the create.
* `e` entry page: the stored value is shown, option fields show the option label, and nothing is printed raw.
* `f` main list. Not automated yet.
* `g` edit round trip: every field is pre-filled with its stored value, and a save without change keeps every value.
* `h` delete and data type change. Not automated yet.
* `i` XML export and import. Not automated yet.
* `j` JSON-sync round trip. Not automated yet.
* `k` reserved and long keys. Not automated yet.
* `l` escaping: a value with markup is never printed raw.

## Known failures

`known_failures.json` lists each failing cell with a one-line reason, the file and line of the cause, and the
classification of the repair: `repair in Part 2` (an existing field type or flow), `needs decision` (the behaviour
depends on a choice), or `needs F2 or new storage` (not repairable with the current code). When a repair fixes a cell, remove
its entry in the same commit.
