# Entry imports

A user can import entries into a showcase from an XML file that was exported from the same board (see
[exports.md](exports.md)). The import is part of the forum, not the admin control panel. It runs the same validator and
the same create path as the forum create form, so an imported entry is what the form would have stored for that user.

## Who may import

An import needs the group permission **Can Import Entries** (`entries_can_import`) of the showcase, and the permission to
post entries (**Can Post Entries**), which the validator needs as well. The permission is set per showcase and per
usergroup, like the other entry permissions, and it is off by default.

* An administrator grants it in the ACP, in the permissions of the showcase.
* It is separate from **Can Export Entries** (`entries_can_export`): a group may export without importing, or import
  without exporting.
* Guests never import, whatever the permission says.
* A group whose members must solve a captcha to post cannot import: the validator would ask for a captcha for every
  entry, so the import page refuses up front.
* The permission is checked on the server for every page and every request of the import. The links to the import page
  are shown only to users who have it.

## Pages and flow

The import page is `/{showcase}/import`. The main page of the showcase shows an **Import entries** button to users with
the permission.

1. The form takes one XML file and has two buttons: **Validate only** checks the whole file and changes nothing;
   **Import** checks the file and writes the entries.
2. The file is checked in stages. Each stage reports every problem it finds, then stops. Nothing is written before the
   last stage.
3. The page shows the report of the request (see [Report](#report)). The report is part of the response to the POST and
   is not stored, so there is no result page to open later.

Each import or validation is one request. An import of the same user in the same showcase is refused for the flood time
(60 seconds by default, the showcase setting `import_export_flood_seconds`; 0 switches the check off). The flood check
counts the import log rows of the user (below).

## The file

The file is an XML document in PluginLibrary's dialect, the same one the export writes: a root `<array>` with two
elements, `header` and `entries`. A file with any other root element, or one that is not well formed, is refused.

### Header

| Key | Type | Meaning |
|---|---|---|
| `format` | string | Always `myshowcase-entries`. |
| `format_version` | int | `1` or `2`. Version 2 writes the file fields as URLs (see [File fields](#file-fields)). A higher number is refused. |
| `myshowcase_version` | string | The version of MyShowcase that wrote the file. Informational. |
| `generated` | string | When the file was written (ISO 8601, UTC). Informational. |
| `showcase` | array | `route_identifier` must be the route identifier of the showcase the file is imported into. `name` is informational. |
| `collection` | array | `name` must be the name of the showcase's collection. |
| `entry_count` | int | Must equal the number of entries in the file. |
| `fields` | list | The fields of the export (`field_key`, `html_type`, `field_type`). Informational: the entries decide what is read. |
| `rules` | array | The options the export was made with. Informational. |

A file is for the same board only. Its showcase must be the one of the import target: a file written for the blog and
imported into the gallery is refused with the names of both showcases. A collection name that differs from the target
collection's is a warning in the report (the import goes on, and every field key is still checked against the target
collection).

### Entries

`entries` is a list. Each entry is an array with:

* `subject` (optional): the subject, when the showcase uses subjects;
* `message` (optional): the message, when the showcase uses messages;
* `fields` (optional): an array of `field_key` to value, one per field of the collection.

Nothing else is read from an entry. The author, the dates, the status, the counters, the flags and the identifiers of
the file are ignored: every entry is created as the importing user, with the status a new entry of that user gets from
the forum (see [Status](#status)).

Field keys match exactly (case-sensitive). A key that is not a field of the collection is refused. A key of a field the
user may not fill (not enabled for the user's group, or read only) is refused. A key of a file field holds the URL of
its file, see [File fields](#file-fields).

### Values by field type

The export writes each field as its stored value. The import reads the same values back.

| Field type | Written as | Read as |
|---|---|---|
| Text, text area, URL, e-mail, telephone, colour, date and time fields | the stored string | the string, checked by the validator as the form checks it |
| Number fields (integer column) | an integer, `type="INT"` | the number as a string, checked by the validator |
| Select, radio and checkbox fields | the option's **value id** (`value_id`), not its label, as an integer | the id, stored as the form stores it |
| Empty value | an empty element | the empty string: an optional field is stored empty |
| A null value | the marker of a null (see `XmlFile`) | treated as absent (see [Absent fields](#absent-fields)) |
| File fields (format version 2) | the public URL of the file, or an empty element when the exporting user may not download the file | the URL is downloaded and checked (see [File fields](#file-fields)) |

The file is for the same board, which is why select values are ids: the option ids of the board are the ones the file
was written with. The validator checks a select value only as a number. It does not check that the option exists, and
neither does the form, so an unknown id is stored as the form would store it.

Subjects and messages are stored as text. A line break in a message is kept (carriage returns are written as character
references); characters that XML 1.0 cannot hold were removed when the file was written.

### File fields

A file field holds the URL of its file. The URL is the public address the board serves the file at, so a browser can
open it. An export writes it for every file the exporting user could download, by the rules of the download route; any
other file is written empty and counted in the export's notes. No filesystem path is ever written.

An import takes each file from its URL and attaches it through the same pipeline as the form: the board's attachment
types, the sizes of the field and of the type, the thumbnails of images and the checks of a valid image all apply, and the
file's name and content type are checked, not the ones the server claims. An entry of the gallery showcase, for example:

```xml
<element><key>0</key><value><array>
  <element><key>fields</key><value><array>
    <element><key>title</key><value>Picture</value></element>
    <element><key>image</key><value>https://placehold.co/600x450.png?text=Gallery%20Entry</value></element>
  </array></value></element>
</array></value></element>
```

The downloads showcase takes its `archive` the same way, from a URL of a ZIP file.

**The setting.** The board's administrator switches the import of files from URLs off with the global setting
**Import files from URLs** (`myshowcase_import_external_files`, yes or no, no by default). When it is off, an import whose
file fields hold any URL, one of this board's own files included, is refused as a whole, with nothing imported. The
setting does not change exports, which always
write the URLs.

**Where a URL points.** A URL of this board's own download route is not fetched: the file is copied from the board's
storage, if the importing user may download that file (the same rules as the download route). A URL of any other address
is fetched over the network, under the rules below. A look-alike address (another host with the same path) is an
external address.

**The rules of a fetch.** Every URL and every address it leads to is checked before a connection is made:

* the URL is at most 2048 characters, has no spaces, control characters or non-ASCII characters, and no `#` part;
* the scheme is `http` or `https`, the port is 80 or 443 (or the board's own port for the board's own files), and there is
  no user name or password in the address;
* every address the host name resolves to must be public. Loopback, private, link-local (including the cloud metadata
  address), unique-local, carrier-grade NAT, multicast, reserved, documentation and benchmarking addresses are refused, and
  so is the IPv4 address inside an IPv4-mapped, IPv4-compatible or NAT64 IPv6 address. An IP address written in decimal,
  octal or hexadecimal form is read as the address it is;
* the connection goes to the address that was checked, so a second answer of the name server cannot change the target;
* a redirect is followed at most three times, and each redirect is checked the same way; a redirect that changes the scheme
  or the port is refused;
* certificates are verified; no cookie and no credential of the board or of the user is ever sent. The User-Agent is
  `MyShowcase-Import/` and the plugin's version.

The file is downloaded into a temporary folder of the server and is removed on every path: success, refusal, error and an
interrupted request.

**Limits.** The limits are constants of the code, not settings (`ExternalFileLimits`):

| Limit | Value |
|---|---|
| Size of one file | the smallest of the field's maximum size, the type's maximum size and 50 MB |
| Size of all files of one import | 100 MB |
| Files per import | 25 |
| Connect time | 5 seconds per file |
| Time per file | 30 seconds |
| Time of the whole import | 60 % of PHP's `max_execution_time`; 120 seconds when PHP has no limit. An import that cannot fit its files in that time does not start. |
| Redirects | 3 per file |
| URL length | 2048 characters |

The file count is checked before any download: an import with more than 25 files is refused without a download.

**Failure.** Any file that cannot be taken fails the whole import, with the entry, the field and the reason in the
report (for example "the file of the field "Image" could not be imported: the address is not a public one, so it is not
fetched"). Nothing is written, and no file, attachment row or import log row is left behind. The reasons are: the scheme,
the port, the user name, the host, the characters, the length, the fragment, a private address, a name that does not
resolve, a secure connection that is not verified, a server that cannot be reached, too many redirects, a file too large,
a download too slow, a server that does not return the file, a file of a type the board does not allow, an empty or
invalid image, the import's budget, the setting being off, a server without the cURL extension, and a file of this board
that the user may not download.

**What the protections do not cover.** A fetched file is checked against its type and size, not scanned for content. The
board's administrator decides which types are allowed (the attachment types of the board).

### Absent fields

A field that the file leaves out, or writes as null, gets the value the form pre-fills for it: its default value, the
current time for a timestamp default, a new UUID for a UUID default. A field without a default is stored empty. A required
field without a default is then refused by the validator, as it is by the form.

### Example

A file with one blog entry that uses every blog field (the category is the value id of an option of the blog's
category field; the field keys are those of the shipped blog collection):

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!-- MyShowcase entries export :: 2026-11-12T09:30:00+00:00 -->
<array>
  <element>
    <key>header</key>
    <value>
      <array>
        <element><key>format</key><value>myshowcase-entries</value></element>
        <element><key>format_version</key><value type="INT">1</value></element>
        <element><key>myshowcase_version</key><value>3.0.0</value></element>
        <element><key>generated</key><value>2026-11-12T09:30:00+00:00</value></element>
        <element>
          <key>showcase</key>
          <value><array>
            <element><key>route_identifier</key><value>blog</value></element>
            <element><key>name</key><value>Blog</value></element>
          </array></value>
        </element>
        <element>
          <key>collection</key>
          <value><array>
            <element><key>name</key><value>Blog</value></element>
          </array></value>
        </element>
        <element><key>entry_count</key><value type="INT">1</value></element>
      </array>
    </value>
  </element>
  <element>
    <key>entries</key>
    <value>
      <array>
        <element>
          <key type="INT">0</key>
          <value>
            <array>
              <element><key>subject</key><value>Getting started</value></element>
              <element><key>message</key><value>The first post of the blog.</value></element>
              <element>
                <key>fields</key>
                <value>
                  <array>
                    <element><key>category</key><value type="INT">1</value></element>
                    <element><key>summary</key><value>A short summary, at most 300 characters.</value></element>
                    <element><key>tags</key><value>intro,setup</value></element>
                    <element><key>cover_image</key><value>https://example.com/cover.png</value></element>
                    <element><key>reading_time</key><value type="INT">4</value></element>
                    <element><key>series</key><value>Starter series</value></element>
                    <element><key>series_part</key><value type="INT">1</value></element>
                    <element><key>meta_description</key><value>A description for search engines, at most 160 characters.</value></element>
                    <element><key>source_url</key><value>https://example.com/source</value></element>
                  </array>
                </value>
              </element>
            </array>
          </value>
        </element>
      </array>
    </value>
  </element>
</array>
<!-- End of MyShowcase export. -->
```

## Validation

Every entry goes through the forum's own validator, EntriesManager::validateEntry(), with the values the form would post.
The import adds no value rules of its own. What the form refuses, the import refuses, with the same message. For the
shipped blog collection that includes:

* a required field left out (the category);
* a value that does not match the field's pattern: a cover image or source that is not an `http` or `https` address, a
  reading time of 0 or above 999, a series part that is not a number;
* a value longer than the field's maximum: a summary above 300 characters, a meta description above 160 characters;
* a subject or message outside the showcase's limits;
* the per-user entry limit (below).

A `<script>` in a summary is stored as text. It is escaped when the entry is shown, as any entry is.

Two checks are made once for the whole file, before any entry is written:

* the structure: the header, the entry count, the field keys, the number of entries (at most 5000 per import, the export's
  maximum), and the showcase and collection names;
* the entry limit of the user: the entries already in the showcase, plus the entries of the file, must not exceed the
  user's limit (the permission **Limit Entry Post Amount** of the group; 0 is unlimited). The check counts the approved
  and awaiting entries of the user, as the form does.

Moderation applies as it does to the form: an entry of a user whose group moderates new entries waits for approval.

There is no duplicate detection. Importing the same file twice creates its entries twice, as posting the same entry
twice in the form does.

## All or nothing

An import writes every entry or none.

1. **Validate first.** Every entry is checked before anything is written. If one entry fails, for any reason, the import
   fails and nothing is written. The report lists each refused entry by its position in the file (1 is the first) with the
   validator's messages.
2. **One transaction for the writes.** The entries are inserted in one transaction, each through the same insert the form
   uses. The import log row is written in the same transaction. Then the transaction is committed.
3. **Undo on failure.** A failure while writing rolls the transaction back, deletes the rows the import recorded by their
   id, and deletes any row carrying the import's batch hash. A shutdown function does the same when the request ends
   before the commit (for example after a database error that ends the request).

Per engine:

| Engine | Transaction | Notes |
|---|---|---|
| MySQL / MariaDB | `START TRANSACTION` ... `COMMIT` / `ROLLBACK` | The entry tables are InnoDB; the plugin converts them on activation. Verified in strict mode (MySQL 8.4). |
| PostgreSQL | `BEGIN` ... `COMMIT` / `ROLLBACK` | Implemented; **not verified** in the test runs of this change (no server was reachable). |
| SQLite | `BEGIN` ... `COMMIT` / `ROLLBACK` | Verified. |

What the transaction does not cover: nothing else is written by the import. There are no counters or caches to update
(the counts are queries), and the search index and the hooks run inside the insert, as they do for the form.

## Limits

The size of an import file is capped by two values, and the smaller one applies:

* **The memory cap:** a fortieth of the PHP memory limit, never more than 5 MB and never less than 256 KB. The parser
  needs about 28 times the size of the file, so the cap keeps the parse within the memory limit (`ImportLimits::sizeCap()`).
* **The request cap:** PHP's `upload_max_filesize` and `post_max_size`, when they are set (`ImportLimits::effectiveCap()`).
  A file over `post_max_size` arrives with no fields at all, so the page says the file is too large.

The export uses the same effective cap, so a file the export writes can be imported again on the same server.

The file is checked against the cap before it is parsed. A file with a DOCTYPE (see [Security](#security)) is refused before the parser sees it.

## Report

The report shows the status of the request and its numbers:

* **Completed**: every entry was imported. The report shows how many.
* **Validated**: "Validate only" was used and every entry passed. Nothing was written.
* **Failed**: nothing was written. The report lists the problems of the file (for example an unknown field, with the
  entries that use it), the refused entries with their messages, or the reason the file could not be read.

A message about the file never shows a path, and the text of the XML is not shown: a parse error gives the line number.

The import log of the showcase (`myshowcase_logs`, type 3) holds one row per completed import: the user, the time, the
name of the file and the number of entries. The flood check reads it.

## What is not imported

* **Attachments that are not file fields.** Only the file of a file field is taken from its URL (see [File fields](#file-fields)).
  Other attachments of an entry are not exported and not imported.
* **Comments and edit history.**
* **The author and the dates.** The importing user is the author. The dates are the ones a new entry gets from the forum.
* **Status, counters, flags and identifiers.** They come from the forum, as for a new entry. The public identifier of an
  imported entry is new.

## Security

* **No external entities and no DOCTYPE.** A file that declares a DOCTYPE is refused before it is parsed. That covers
  external entities (a file or a network address read by the parser) and entity expansion (a "billion laughs" file).
  The parser is also run with no network access and without entity substitution (`XmlFile`).
* **The size is checked before the parse**, against the effective cap.
* **The upload is removed on every path**: success, refusal and error. The file is read from PHP's temporary location and
  is never stored under the web root.
* **The post key** is checked on the server for the import, as for every state-changing request of the forum.
* **Permissions** are checked on the server for every request: a guest is refused, and so is a member without
  **Can Import Entries** (the import form and the POST).
* **Files from URLs** are fetched under the rules of [File fields](#file-fields). Nothing in a file can change the rules
  of the fetch: the address, the host and the redirects are checked by the server on every hop.
* **Values** are stored through the repository the form uses and are escaped when they are shown.
* **Error messages** do not contain paths, server names or the text of the XML.

## Tests

The unit tests need no board: `php tests/import/run.php` (limits, the file check, the report, the upload reader and the
write-failure log), and `php tests/import/fetcher/run.php` (the URL rules, a table of addresses, the fetcher with a fake
resolver and transport, the acceptance of a file, and the budget). Neither needs a network.

The HTTP test installs a board, creates the blog, gallery and downloads showcases from their JSON, creates entries through
the form, exports and imports them, takes files from URLs (placehold.co and a ZIP of GitHub, the only external hosts it
contacts), and checks the refusals, the security measures and the file fields:

```bash
python3 tests/import/http/run.py --engine sqlite --mybb /path/to/mybb-1.9 --scratch /path/to/scratch --pluginlibrary-file /path/to/pluginlibrary.php
```

The network checks use placehold.co and github.com only. A local listener of the run records every request it receives, so
the address checks can show that nothing was contacted.

Packages (one ZIP file that holds one entry and its files) are not implemented yet; their design is in
[import-packages.md](import-packages.md).

For MySQL the test creates a database of its own (`msc_test_<random>`) and drops it at the end. The database settings are
the ones of `tests/json-sync/README.md`. The MyBB tree needs its Composer dependencies in `vendor/`.
