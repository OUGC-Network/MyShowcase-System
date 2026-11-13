# Downloads showcase

A downloads showcase lists files for visitors to download. Each entry is one file with its title (the subject), a
description (the message), the file itself, its version, release date, platform, license, checksum, an optional
changelog and a project link. Comments are allowed on every entry.

## Import files

| File | Kind |
|---|---|
| `Upload/inc/plugins/myshowcase/imports/JSON/collections/downloads.json` | Collection "Downloads": ten fields, 17 options |
| `Upload/inc/plugins/myshowcase/imports/JSON/showcases/downloads.json` | Showcase configuration |

The showcase file does not carry a name or a route identifier. They are entered when the showcase is created from the
file: name `Downloads`, route identifier `downloads`.

## Importing

Import the collection first, then the showcase. The steps are the same as for the blog (see
[blog.md](blog.md#importing)):

1. Collection: in the MyShowcase System, **Collections**, **Create from JSON file**, `collections/downloads.json`,
   **Preview**, then **Confirm and apply**.
2. Showcase: **Showcases**, **Create from JSON file**, `showcases/downloads.json`, **Name** `Downloads`,
   **Route identifier** `downloads`, **Template set** *Global templates (none)*, then **Preview** and
   **Confirm and apply**.

The engine path is `JsonSync::apply('collections/downloads.json')` and
`JsonSync::apply('showcases/downloads.json', ['name' => 'Downloads', 'route_identifier' => 'downloads'])`.

## Showcase configuration set by the file

| Setting | Value | Meaning |
|---|---|---|
| `main_per_page` | 20 | Entries per page on the list |
| `main_sort_default_field`, `main_sort_direction` | `dateline`, 0 | Newest first |
| `entries_enable_subject`, `entries_enable_message` | 1 | A title and a description |
| `entries_enable_update_notes` | 1 | Edits of an entry show an update note |
| `entries_display_empty_fields` | 0 | Empty fields are hidden on the entry page |
| `entries_display_signatures`, `entries_display_profile_fields`, `entries_allow_post_icons` | 0 | No signatures, profile fields or post icons |
| `comments_enable`, `comments_enable_quick_reply` | 1 | Comments with a quick reply |
| `attachments_allow_in_entries` | 1 | The file is uploaded with the entry |
| `attachments_allow_in_comments` | 0 | No files in comments |
| `attachments_main_render` | 1 | Every file is linked from the list |
| `attachments_display_type` | 0 | Files are shown as links |
| `attachments_enable_embeds`, `attachments_main_render_as_lightbox` | 0 | No embedded or lightbox files |
| `subscriptions_enable` | 1 | Subscriptions are allowed |

The file sets only these keys; every other setting keeps its value on the board.

## Custom fields

| Key | Type (html / db) | Purpose | Validation | List | Entry page | Default |
|---|---|---|---|---|---|---|
| `archive` | file / varchar(255) | The downloadable file | Required; at most 50 MB (51,200 KB), and the board's limit for the file's type | yes | yes | none |
| `category` | select / int | Kind of file: Tools, Themes, Plugins, Templates, Documents, Other | Required; one of the six options | yes | yes | none |
| `version` | text / varchar(20) | Version number | Required; `1.4.2`, `2.0.0-beta` (digits and dots, optionally followed by `-` or `.` and letters or digits) | yes | yes | none |
| `release_date` | date / varchar(10) | Date of this version | Required; `YYYY-MM-DD` | yes | yes | none |
| `platform` | select / int | System the file is for: Any, Windows, macOS, Linux, Web | Required; one of the five options | yes | yes | none |
| `license` | select / int | License: Freeware, MIT, GPL-2.0-or-later, GPL-3.0, Proprietary, Other | Required; one of the six options | yes | yes | none |
| `requirements` | text / varchar(200) | Minimum requirements, for example PHP 8.2 and MyBB 1.9 | Optional; at most 200 characters | no | yes | empty |
| `sha256` | text / varchar(64) | SHA-256 checksum of the file, so a visitor can check it | Required; exactly 64 hexadecimal characters | no | yes | none |
| `changelog` | textarea / text | What changed in this version; MyCode and smilies | Optional; at most 10,000 characters | no | yes | empty |
| `project_url` | url / varchar(255) | Project or support page | Optional; `http://` or `https://` without spaces | no | yes | empty |

Every field is open to every group for filling in and for viewing.

The version pattern is checked for every entry; a version such as `v1` is refused. The checksum pattern refuses any text
that is not 64 hexadecimal characters. `release_date` is stored as text in the `YYYY-MM-DD` form, so it sorts and
compares as text.

The options of `category` and `platform` and `license` have stable value ids (1 to 6, 1 to 5, 1 to 6). A file that
adds an option keeps the ids of the existing ones.

## Attachment types and limits

* The file's size limit is the limit of the field, 50 MB. The board's attachment types (an administrator's setting in
  MyBB) also apply: the upload is refused when the type's maximum size is smaller.
* The allowed types are the board's attachment types, not part of the file. Allow the archive and package types you
  use (for example `zip`, `tar.gz`, `7z`, `pkg`, `dmg`). Do not allow executable types (for example `exe`, `bat`,
  `sh`, `app`): a type that is not allowed on the board is refused at upload.

## Permissions and moderation

The import does not set permissions. An administrator must still grant, to the groups that may use the showcase:

* the permission to post entries (and to upload files with them),
* the permission to comment,
* the permission to download the files.

New entries are not moderated by the file. The group permission *Moderate New Entries* decides whether the entries of
its members wait for approval; set it on the groups that post. Download counts and a download log are not part of the showcase configuration yet: the file
cannot turn them on.

## Updating the downloads showcase

* A new version of a file is a new entry, not an update of an existing one. The file changes the showcase
  configuration and the field definitions, not the files of the entries.
* When the collection file changes, raise its version (see
  [imports/README.md](../../Upload/inc/plugins/myshowcase/imports/README.md#workflow)). No PHP update step is needed for
  these files.
* A field removed from the file keeps its column and entries and becomes a custom field.

## What the files cannot express yet

* A multiple-choice `platform` (several systems for one file): multiple choice fields are not implemented, so `platform`
  is a single choice.
* Download rights, download limits, a download log, and the moderation of new entries are permissions and settings
  that the JSON format does not cover. Setting them by hand is the current way.
* The allowed attachment types are a MyBB setting; they are not in the JSON format.

These are gaps of the format, not defects of the files. Each one would need a change to the JSON format or to the
permissions, which is not part of these files.

## Tests

* `php tests/json-sync/run.php`: the ten fields, the checksum and version patterns, the file field with its size limit,
  the option ids, the showcase file's keys and its absence of `name` and `route_identifier`.
* `python3 tests/json-sync/http/run.py --engine sqlite --mybb <board>`: creates the downloads collection and showcase
  from their files, checks the linked rows and settings, and checks that a second apply changes no field.
