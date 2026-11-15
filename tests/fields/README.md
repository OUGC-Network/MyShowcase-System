# Field type tests

The field tests run every custom field kind of the plugin through the matrix of stages (definition, create form,
validation, storage, entry page, edit round trip, escaping) on a scratch board, for one database engine at a time.
They do not repair anything: a cell that fails is listed in `known_failures.json` with its cause, and the suite is green
only while the failing cells are exactly that list.

## What is in this folder

* `collections/fieldkit.json` is the FieldKit collection: 39 fields, one for every selectable HTML type (poll and rating are reserved for later features and are not in the file), with the
  variants of the matrix (required and optional, defaults as defined and generated, minimum and maximum lengths, a
  validation pattern, the bounds of number, range, date, datetime-local, time, month and week, read-only, a fill and view
  restriction, parser settings on and off, select, radio and checkbox options). The bounds are the value properties
  `minimum_value` and `maximum_value`. The fields `fk_number_free` (no bounds: a negative value is accepted),
  `fk_date_bounded`, `fk_datetime_bounded`, `fk_time_bounded`, `fk_month_bounded` and `fk_week_bounded` have bounds and
  their cases are in `http/run.py`. The blank optional cases of the date, datetime-local and time fields are known
  failures on the engines that refuse an empty date value (see `known_failures.json`).
* `showcases/fieldkit.json` is the showcase created from it (route `fieldkit`; the name and route are supplied at creation).
* `http/run.py` installs the board, creates the showcase through the ACP's JSON sync, creates and edits entries through the
  forum form, and writes one cell per field and stage into `matrix-<engine>.json` in the scratch directory.
* `http/upgrade_test.py` (stage `u`, one run per engine) installs a board with the BASE commit's plugin, stores a
  collection and two fields, then activates the working tree's plugin through the ACP and checks that the columns
  `minimum_value` and `maximum_value` exist with the empty default, the stored rows are unchanged, and a second activation
  changes nothing. The base commit is `--base` (default `16e928c`, the commit this part starts from).
* `http/acp_bounds_test.py` (stage `a`, the bounds through the ACP) saves number, range, date, time and week fields
  through the field screens with a valid pair, a non-integer bound, a minimum above the maximum, a range without a
  maximum, an invalid calendar date, a week the year does not have, and a text field with bounds (saved empty); then it
  edits a field and checks the edit screen. Run it like `run.py`, without `--matrix-out`.
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

* `a` definition: the ACP edit form of the stored definition. The matrix does not automate it (`notrun`); the bounds
  are covered by `http/acp_bounds_test.py`.
* `b` create form: the input, its label and options, the required marker, `min`, `max`, `pattern`, `readonly`, the
  pre-filled default (as defined or generated), and the visibility for a user outside the restricted group.
* `c` validation on POST: valid values, blank optional and blank required values, length and numeric bounds, patterns,
  option ids that do not belong to the field, several checked boxes, markup, multibyte text, emoji, overlong values.
* `d` storage: the value that the database holds after the create.
* `e` entry page: the stored value is shown, option fields show the option label, and nothing is printed raw.
* `f` main list. Not automated yet (`notrun`).
* `g` edit round trip: every field is pre-filled with its stored value, and a save without change keeps every value.
* `h` delete and data type change, on throwaway data (run last, after stage `l`): an entry is deleted through the forum's
  inline moderation at the URL the form posts to, with the trailing slash (cell `place:entry-delete|h`: the row and its attachments
  go); a user without the delete permission is refused with a real message and the entry stays (`place:entry-delete-refused|h`); an option that entries use is deleted in the ACP (`fk_select|h`); the
  ACP refuses to delete a field that a JSON file manages, and the same field, unmanaged as data, is deleted and its column
  dropped (`fk_text_opt|h`); the types are changed through the JSON sync: a shrink that loses values, a text-to-number change
  that loses values (both must be refused as a whole) and a number-to-text change that loses nothing (`fk_text_req|h`,
  `fk_text_pattern|h`, `fk_number|h`). The original definition is applied again after each change.
* `i` XML export and import: the entries of valid values are exported and imported into the same showcase (the import of
  files from URLs is switched on through the fixture for the run, with the wait the server's opcache needs); every copy must
  hold the stored value. The file fields are compared through the download route: the stored bytes, name, MIME type and size,
  and the entry page shows the file. With the setting off the same file is refused as a whole (decision D57).
* `j` JSON-sync round trip: the ACP exports the collection, the exported file replaces the collection file and is applied
  again; every stored property is compared before and after. The export leaves out the properties that hold the column
  default, which the importer takes as the default.
* `k` reserved and long keys. Not automated yet (`notrun`).
* `l` escaping: a value with markup is never printed raw. The entry page is checked per field (stage `e`); the edit form and
  the main list are checked with the payloads of one entry (cells `place:edit-form|l` and `place:main-list|l`).
* `m` the update rule (decision D122), over HTTP, on the select field `fk_select`, with throwaway options and entries of the
  restricted user (`fkrestricted`), removed at the end. The option is changed as data to a group the owner is not in, then:
  an unchanged save keeps the stored option (`place:update-a|m`); a change to an option the group may not fill is refused
  (`place:update-b|m`); a change to an open option is accepted (`place:update-c|m`); a change to an option of another field is
  refused (`place:update-o|m`); a file of the owner's entries exported and imported as an insert is refused as a whole, and
  no copy is stored (`place:xml-import|m`); with the stored option deleted as data, an unchanged save is accepted and the
  entry page answers 200 without its label (`place:update-d|m`, `place:update-d-page|m`); a change to a deleted option id is
  refused (`place:update-e|m`); an insert with the not-allowed option is refused (`place:insert-denied|m`). The XML case proves
  only that the whole file is refused: the import summary does not name the option.

A cell that cannot run is `notrun` with its reason and the remaining steps. The place cells `place:entry-delete|h` and `place:entry-delete-refused|h` are not
field cells: they record the forum's answers to the entry delete.

* `x` and `y` (run last): `x` (decision D146) a request that matches no route is answered with the page-not-found sentence
  (`place:page-not-found|x`); `y` (decision D145) the list sorted by a date field, in both directions, puts the blank date last
  (`place:sort-blank-last-asc|y`, `place:sort-blank-last-desc|y`).

Blank values (decision D129): a blank optional date, time or datetime value is stored as NULL, and its column is nullable
(a required field never stores a blank: the validator refuses it first). The stage `c` cells of those fields check it.

The inventory of the properties is in `inventory.md`.

## Known failures

`known_failures.json` lists each failing cell with a one-line reason, the file and line of the cause, and the
classification of the repair: `repair in Part 2` (an existing field type or flow), `needs decision` (the behaviour
depends on a choice), or `needs F2 or new storage` (not repairable with the current code). When a repair fixes a cell, remove
its entry in the same commit.
