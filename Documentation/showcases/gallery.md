# Gallery showcase

A gallery showcase holds images. Each entry is one image with a title, an optional caption and a category. The entries
are shown as thumbnails on the list and as full images on the entry page, and every entry can have comments.

The design of the gallery (category option metadata, tags, folders, image variants and password hardening) is in
[schema/gallery-schema-changes.md](../schema/gallery-schema-changes.md). That document is the source of the design; this
one describes what the shipped import files create today and where they differ from it (see
[What the files do not provide yet](#what-the-files-do-not-provide-yet)).

## Import files

| File | Kind |
|---|---|
| `Upload/inc/plugins/myshowcase/imports/JSON/collections/gallery.json` | Collection "Gallery": four fields, six category options |
| `Upload/inc/plugins/myshowcase/imports/JSON/showcases/gallery.json` | Showcase configuration |

The showcase file does not carry a name or a route identifier. They are entered when the showcase is created from the
file: name `Gallery`, route identifier `gallery`.

## Importing

Import the collection first, then the showcase. The steps are the same as for the blog (see
[blog.md](blog.md#importing)); the form is opened from the **Collections** page and the **Showcases** page of the
MyShowcase System in the ACP, with the file and the values below:

1. Collection: **Create from JSON file**, `collections/gallery.json`, **Preview**, then **Confirm and apply**.
2. Showcase: **Create from JSON file**, `showcases/gallery.json`, **Name** `Gallery`, **Route identifier** `gallery`,
   **Template set** *Global templates (none)*, then **Preview** and **Confirm and apply**.

The engine path is `JsonSync::apply('collections/gallery.json')` and
`JsonSync::apply('showcases/gallery.json', ['name' => 'Gallery', 'route_identifier' => 'gallery'])`.

The gallery needs the board to allow image attachments: the attachment types of the board (JPEG, PNG, GIF and WebP) are
board settings, not part of the file.

## Showcase configuration set by the file

| Setting | Value | Meaning |
|---|---|---|
| `main_per_page` | 24 | Images per page on the list |
| `main_sort_default_field` | `dateline` | Sorted by the date of the entry |
| `main_sort_direction` | 0 | Newest first (1 would be oldest first) |
| `entries_enable_subject`, `entries_enable_message` | 0 | The title field is the subject; there is no separate message |
| `entries_display_empty_fields` | 0 | Empty fields are hidden on the entry page |
| `entries_display_signatures`, `entries_display_profile_fields` | 0 | No signatures or profile fields |
| `comments_enable`, `comments_enable_quick_reply` | 1 | Comments with a quick reply |
| `comments_per_page` | 20 | Comments per page |
| `attachments_allow_in_entries` | 1 | Images are uploaded with the entry |
| `attachments_allow_in_comments` | 0 | Images are not uploaded with comments |
| `attachments_main_render` | 3 | The first image is shown on the list |
| `attachments_display_type` | 2 | Full images on the entry page |
| `attachments_main_render_as_lightbox` | 1 | Images open in a lightbox |
| `attachments_thumbnails_width`, `attachments_thumbnails_height` | 255 | Thumbnail size |
| `subscriptions_enable` | 1 | Subscriptions are allowed |

The file sets only these keys; every other setting keeps its value on the board.

## Custom fields

| Key | Type (html / db) | Purpose | Validation | List | Entry page | Default |
|---|---|---|---|---|---|---|
| `title` | text / varchar(100) | Title of the image; the subject of the entry, with a slug | Required; 1 to 100 characters | yes | yes | none |
| `image` | file / varchar(255) | The image itself | Required; at most 10 MB; the board's image types | yes | yes | none |
| `caption` | textarea / text | Optional description; MyCode and smilies | At most 2000 characters | yes | yes | empty |
| `category` | select / int | Category of the image | Optional; one of the six options | yes | yes | none |

The category options (value id, value, display order): 1 Architecture, 2 Nature, 3 People, 4 Animals, 5 Travel,
6 Other. Their value ids are stable: JSON files and the design refer to them by id.

Every field is open to every group for filling in and for viewing.

## Permissions

The import does not set permissions. An administrator must still grant the upload, comment and view permissions of the
gallery to the groups that use it. Until permission seeding is in place (a later step), grant them by hand.

## What the files do not provide yet

The design in [schema/gallery-schema-changes.md](../schema/gallery-schema-changes.md) is approved but not implemented
on this branch. The files create what the current schema holds, so the following differ from the design:

| Area | Design (approved, not implemented) | What the files create |
|---|---|---|
| Category options (Group A) | Each option has a URL slug, a parent, an image, a description and an active flag; a filter bar on the list | Six flat options with value id, value and display order. No image, slug, parent or filter bar. |
| Tags (Group B) | A multiple-choice tags field with its own storage and moderated new tags | No tags field. The file has no tags field on purpose; the tests assert it. |
| Folders or albums (Group C) | Folders per showcase with a label, nesting, visibility and a password | No folder settings and no folder tables. The file sets none of the folder keys. |
| Image pipeline (Group D) | A medium size, metadata stripping, watermark options and an original-download permission | Thumbnails and full images only. The watermark file is not in the file: it is a board value and is excluded from JSON files by design. |
| Password hardening (Group E) | Hashed showcase passwords, access log fields | The `password` key is excluded from JSON files by design. Nothing in the file sets a password. |
| Permissions | Folder and original-download permissions, and the permission seeding | Not seeded by the file (deferred). |

"Creator" in the design is the entry's author, stored in `user_id` as in every showcase; the file does not need to
set it.

When a group is implemented, its keys are added to the JSON schema and the files are updated with a new version (see
[imports/README.md](../../Upload/inc/plugins/myshowcase/imports/README.md#workflow)). The custom gallery template set is a
separate piece of work and is not part of these files.

## Updating the gallery

* Field changes apply when the file version is raised. The current version is `1.0.0`.
* A field removed from the file keeps its column and its images and becomes a custom field.
* The category options that a file does not list are kept.

## Tests

* `php tests/json-sync/run.php`: the four fields, the six options and their ids, the title as the required subject, the
  image as a required file field, and the showcase settings.
* `python3 tests/json-sync/http/run.py --engine sqlite --mybb <board>`: creates the gallery from its files and checks
  the linked rows and the settings.
