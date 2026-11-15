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

* `a` definition: the ACP edit form of each field of an unmanaged copy of the FieldKit
  collection (the copy is the collection file under another name, detached from its file, and removed at the end) is loaded,
  its inputs are sent back unchanged, and no stored property of the field changes (cell `<field>|a`). The cell `place:acp-managed-edit|a`
  shows that the edit form of a managed field names its file in the JSON file row, shows the managed note on its inputs and disables them;
  `place:acp-managed-control|a` shows that the detached copy's form has none of this (so the first cell cannot pass on any page), and
  `place:stage-a-control|a` is the negative control of the comparison: one changed form value (the description) is the one property the
  comparison reports, and the value is restored. The bounds of the number, range, date, time and
  week fields are covered by `http/acp_bounds_test.py`.
* `b` create form: the input, its label and options, the required marker, `min`, `max`, `pattern`, `readonly`, the
  pre-filled default (as defined or generated), and the visibility for a user outside the restricted group. The emoji case of stage `c` (decision D103) accepts a stored value or a refusal that names the field: a MySQL connection that is not utf8mb4 refuses a four-byte character with the field's label.
* `c` validation on POST: valid values, blank optional and blank required values, length and numeric bounds, patterns,
  option ids that do not belong to the field, several checked boxes, markup, multibyte text, emoji, overlong values.
* `d` storage: the value that the database holds after the create.
* `e` entry page: the stored value is shown, option fields show the option label, and nothing is printed raw.
  The IP address of an entry (`ipaddress`): an entry created from the IPv6 loopback (a second PHP server on `[::1]`, when the machine has
  one) is stored as the 16 packed bytes of `::1` (`place:ip-ipv6-stored|e`), its page shows the address after the IP label
  (`place:ip-ipv6-entry-page|e`; the IPv4 entry of stage `y`, `place:entry-ip|y`, is checked the same way), and the main list
  (`place:ip-list-absent|e`) and the export (`place:ip-export-absent|e`) do not carry it. The moderation pages use the main list. Without
  an IPv6 loopback the cells are `na`.
* `f` the main list (cell `<field>|f`): the list's span of the field (`thread__field--<key>`) shows the value of the entry with
  `display_in_main_page` on (an option as its label), is absent with the flag off, is absent for a blank optional value (a number shows 0),
  is hidden from the member and shown to the restricted user for the restricted field, and a file field is checked against the list template (`pageMainEntry.twig`):
  the row shows the entry's first image (`thread__image`) and the file field has no span. `place:stage-f-control|f` is the negative control
  of the span check: a value that was never stored is not found in the span of a stored one.
  The entries search (`EntriesController`) matches option labels, text and character fields and numbers exactly; it has no rule for the date,
  time and datetime types, so no search cell for a date value is written.
* `g` edit round trip: every field is pre-filled with its stored value, and a save without change keeps every value.
* `h` delete and data type change, on throwaway data (run last, after stage `l`): an entry is deleted through the forum's
  inline moderation at the URL the form posts to, with the trailing slash (cell `place:entry-delete|h`: the row and its attachments
  go); a user without the delete permission is refused with a real message and the entry stays (`place:entry-delete-refused|h`); an option that entries use is deleted in the ACP (`fk_select|h`); the
  ACP refuses to delete a field that a JSON file manages, and the same field, unmanaged as data, is deleted and its column
  dropped (`fk_text_opt|h`); the types are changed through the JSON sync: a shrink that loses values, a text-to-number change
  that loses values (both must be refused as a whole) and a number-to-text change that loses nothing (`fk_text_req|h`,
  `fk_text_pattern|h`, `fk_number|h`). The original definition is applied again after each change.
  Moderation (decision D147): a moderator (the Moderators group, a moderator of the showcase, with the delete permission) deletes an entry of
  another user through the inline moderation of the list (`place:entry-delete-moderator|h`); a plain member, without the delete permission,
  is refused for another user's entry with a real message and the entry stays (`place:entry-delete-other-refused|h`).
  Dates (decision D116): a stored blank text, changed to a date field, refuses the change as a whole with the reason (`place:type-change-date-blank|h`);
  the same field with valid dates is accepted and keeps its values (`place:type-change-date-valid|h`).
* `i` XML export and import: the entries of valid values are exported and imported into the same showcase (the import of
  files from URLs is switched on through the fixture for the run, with the wait the server's opcache needs); every copy must
  hold the stored value. The file fields are compared through the download route: the stored bytes, name, MIME type and size,
  and the entry page shows the file. With the setting off the same file is refused as a whole (decision D57).
  The option cases of decision D142 (`place:option-blank-roundtrip|i`, `place:option-format2-blank|i`, `place:option-format2-value|i`):
  two entries with blank optional options are exported (the export writes each blank option empty) and imported as they were stored; the
  same export as a format 2 file with every option 0 is not refused as a whole, and each entry is refused only for the required select
  `fk_select_req` (a required 0 is a missing value; FieldKit has no showcase without one); the format 2 file with one option value is refused
  as a whole with the "export the entries again" message, and nothing is stored.
* `j` JSON-sync round trip: the ACP exports the collection, the exported file replaces the collection file and is applied
  again; every stored property is compared before and after. The export leaves out the properties that hold the column
  default, which the importer takes as the default.
* `k` the key rules (decision D152), as place cells `place:key-<case>|k`, not per field (each `<field>|k` is `na`):
  `reserved-<name>` (the ACP refuses every column of the entries table and every SQL word of the list, read from the code by
  `fixture.php reserved-keys`); `acp-*` (the ACP field form: a key of 31 characters is too long, a key of 30 is stored, uppercase,
  hyphen, space, a leading digit, a non-ASCII letter, doubled or trailing underscores, an empty key, a duplicate in the collection
  and the same key in another case are refused with their message); `json-*` (the same cases through the JSON validator, previewed);
  `word-<word>` (each SQL word that is not refused, and the 30-character key: a showcase of its own is created through the JSON sync,
  an entry is stored with a value, shown on its page, the list is sorted by the field, the export holds it, and the field is deleted
  through the ACP); a word that the list refuses is recorded by its `reserved-` cell.
  The four-byte cases of the subject and the message are `place:subject-four-byte|c` and `place:message-four-byte|c` (decision D150).
  `place:sync-table-failure|k` (decision D154): a JSON sync whose entries table cannot be created names the failed step and the engine's
  reason, and removes the showcase row that the same run wrote, so no showcase is left without its table. The seam is a view that already
  holds the name of the entries table that the showcase is about to get (test-only, created and dropped by the cell): SQLite and MySQL name
  the view in their answer, and PostgreSQL gives its own refusal of the ALTER it attempts. The cell runs on all three engines.
  `place:reserved-type-form|k` and `place:reserved-type-post|k` (the reserved HTML types poll and rating): the new-field form offers neither,
  and a post that names either is refused with the reserved-type message and stores no field.
  `place:key-sql-words-create|k` (decision D153): each of the 338 candidate words of the three engines' keyword sources
  (`sql_keyword_sources.json`) is created as an unquoted column on the engine under test, and every word the engine refuses must be
  refused by the key rule (the names of `fixture('reserved-keys')`). The list and the counts are in `inventory.md`.
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
