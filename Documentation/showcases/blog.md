# Blog showcase

A blog showcase holds posts. Each entry is one post with a subject (the title), a message (the body), comments and
the custom fields below. The showcase and its collection are described by JSON files, so the same blog can be created
on any board and kept in step with the files.

## Import files

| File | Kind |
|---|---|
| `Upload/inc/plugins/myshowcase/imports/JSON/collections/blog.json` | Collection "Blog": the nine custom fields |
| `Upload/inc/plugins/myshowcase/imports/JSON/showcases/blog.json` | Showcase configuration |

The showcase file does not carry a name or a route identifier. They are entered when the showcase is created from the
file (see [Importing](#importing)).

## Importing

Import the collection first, then the showcase.

1. In the ACP, open **MyShowcase System**, then **Collections**. Choose **Create from JSON file**, pick
   `collections/blog.json`, choose **Preview** and then **Confirm and apply**. The form is
   `index.php?module=myshowcase-json_sync&action=create&type=collection` (the post key is added by the ACP).
2. Open **Showcases** and choose **Create from JSON file**. Pick `showcases/blog.json` and enter:
   * **Name**: `Blog`
   * **Route identifier**: `blog`
   * **Template set**: *Global templates (none)*
   The form is `index.php?module=myshowcase-json_sync&action=create&type=showcase`.
3. Check the preview, then choose **Confirm and apply**. The Showcases and Collections lists show **In sync** for both
   rows.

Engine path (the same plan the ACP runs): `JsonSync::apply('collections/blog.json')`, then
`JsonSync::apply('showcases/blog.json', ['name' => 'Blog', 'route_identifier' => 'blog'])`, both in
`MyShowcase\Service\JsonSync\JsonSync`. The apply returns the plan, the steps that ran and the first failure.

Applying a second time changes nothing.

## Showcase configuration set by the file

| Setting | Value | Meaning |
|---|---|---|
| `description` | a short text | Shown on the showcase page |
| `entries_enable_subject` | 1 | Entries have a title |
| `entries_enable_message` | 1 | Entries have a body |
| `comments_enable` | 1 | Comments are allowed |
| `main_per_page` | 5 | Entries per page on the list |
| `main_sort_default_field` | `dateline` | The list is sorted by the date of the post |
| `main_sort_direction` | 0 | Newest first (1 would be oldest first) |

The file sets only these keys. Every other setting keeps its value on the board and stays editable.

## Custom fields

| Key | Type (html / db) | Purpose | Validation | List | Entry page | Default |
|---|---|---|---|---|---|---|
| `category` | select / int | Section of the blog | Required; one of News, Tutorials, Opinion, Announcements | yes | yes | none |
| `summary` | textarea / text | Introduction shown in the list | At most 300 characters | yes | yes | empty |
| `tags` | text / varchar(120) | Comma-separated keywords, searchable | At most 120 characters | yes | yes | empty |
| `cover_image` | url / varchar(255) | Picture shown with the post | Optional; must be an `http://` or `https://` address without spaces | yes | yes | empty |
| `reading_time` | number / int | Minutes needed to read | Optional; a whole number from 1 to 999 | yes | yes | empty |
| `series` | text / varchar(80) | Name of a series of posts | Optional; 1 to 80 characters | no | yes | empty |
| `series_part` | number / int | Position of the post in its series | Optional; a whole number from 1 to 999 | no | yes | empty |
| `meta_description` | text / varchar(160) | Sentence for search engines and link previews | Optional; at most 160 characters | no | yes | empty |
| `source_url` | url / varchar(255) | Original article of a translation or repost | Optional; `http://` or `https://` without spaces | no | yes | empty |

Every field is open to every group for filling in and for viewing (`allowed_groups_fill` and `allowed_groups_view` are
`all`). The "Default" column is the value a new entry gets when the form leaves the field empty.

The optional patterns accept an empty value: a pattern is checked for optional fields too, so each one allows nothing
for an empty field.

`series` and `series_part` are meaningful together; the file does not require one when the other is set.

## Permissions

The import does not set permissions. An administrator must still grant the blog permissions to the groups that post
and comment (the permission seeding is a later step). Until then, only groups with permissions set by hand can create
entries.

## Updating the blog

* A field change in the file is applied when the file version is raised (see
  [imports/README.md](../../Upload/inc/plugins/myshowcase/imports/README.md#workflow)). The current version is `1.1.0`.
* A field removed from the file keeps its column and entries and becomes a custom field ("Custom, not in JSON"). It is
  not deleted.
* A renamed field keeps its data when `previous_keys` lists its old key.
* A field whose type changes is refused when the data does not fit.

## Tests

* `php tests/json-sync/run.php`: validation, every field's regular expression, the groups, the patterns of the cover
  image, the reading time and the downloads options.
* `python3 tests/json-sync/http/run.py --engine sqlite --mybb <board>`: creates the blog from its files through the ACP
  and checks the linked rows, the nine fields, the sync states and that a second apply changes nothing.
