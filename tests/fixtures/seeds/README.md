# Content seeders

The six files in this folder are entries imports for the three shipped collections: a full and a minimal seed for each
showcase (blog, downloads, gallery). They are the forum's own import format (format 3, the layout the export writes), so
a real forum can import them through the forum-side import. They are kept under `tests/` and are not shipped as sample
data: nothing in `Upload/` reads them (decision D40).

| File | Showcase | Entries | What it holds |
|---|---|---|---|
| `blog-full.xml` | blog | 3 | every blog field; the optional cover image and source link blank in one entry, the series blank in another |
| `blog-minimal.xml` | blog | 1 | the required fields only (subject, message, category) |
| `downloads-full.xml` | downloads | 2 | every downloads field; two previews as an ordered list, several platforms, the optional fields blank in the second entry |
| `downloads-minimal.xml` | downloads | 1 | the required fields only |
| `gallery-full.xml` | gallery | 3 | every gallery field; the category blank in one entry, the caption blank in another |
| `gallery-minimal.xml` | gallery | 1 | the required fields only |

The subject of an entry is its title in the gallery (decision D204); the blog and downloads subjects are their own.
The archive of the downloads seeds is the tagged source archive `mybb_1841` on `codeload.github.com` (decision D54): a tag
does not change, so the `sha256` field of each entry is the digest of that archive. The image URLs are `placehold.co`.

## Running

The coverage test needs no board:

```
python3 tests/fixtures/seeds/coverage_test.py
```

It reads the shipped collection files in `Upload/inc/plugins/myshowcase/imports/JSON/collections/` and the seeds here, and
fails when a field of a collection is blank in every seed of its showcase, when a seed names a key the collection does not
define, when a required field is blank, when a value is outside its bounds or names no option, when a value is populated
for a type that cannot hold it, and when an entry has no subject. Its negative controls must be refused, or the run fails.

The seeds are imported by the import suite, which needs a board (`tests/import/README.md`):

```
python3 tests/import/http/run.py --engine sqlite --mybb <MyBB 1.9 source tree> --scratch <directory>
```

The section `S` imports every seed, checks every field of every entry against its seed (options by label, lists in
order, text and numbers as sent, files by name, type and size in display order, the entry page and the message), and then
round-trips each full seed: the export of the seeded entries imports back into the same showcase, and the copy holds the
same values. A second showcase of the same collection refuses the export (a file is imported only into the showcase it
came from), and that refusal is checked too.

The second-board script imports the three full seeds into a second board whose option ids differ, and compares them by
label (`tests/import/http/second_board.py`).

## Extending the seeds

* A new field of a shipped collection needs a value in at least one seed of its showcase. The coverage test fails until
  one exists.
* A value of an option field is the option's `value_id` (format 3, decision D128). A list of several values is an ordered
  list of `value_id`s (decision D182); a multi-file field takes an ordered list of URLs (decision D197).
* A blank optional value is an empty element. A number whose minimum is above 0 is exported blank when it is 0 (the
  stored blank); a seed should not write 0 for such a field.
* A file is a URL in the field's value. Only `http(s)` URLs import, and an image or archive URL must be of a type the field
  takes (its `file_types` and the showcase's attachment types). The board the seeds are imported into must allow those
  types for the showcase: the import suite's `files-setup` fixture does this for the test board.
* The header of a seed is the export's header: it names the route and the collection of its showcase, and the import checks
  the route against the showcase it is imported into (a file is imported only into the showcase it came from). The showcase
  itself is created from its shipped file, which carries no route (decision D39).
* After a change, run the coverage test, then the import suite and the second-board script.
