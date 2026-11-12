# MyShowcase JSON sync

Collections and showcases can be described in JSON files and kept in step with the board. A file is the source of
the values it sets; the ACP shows what would change, and nothing is written until an administrator confirms.
The reference files are [`JSON/collections/blog.json`](JSON/collections/blog.json) and
[`JSON/showcases/blog.json`](JSON/showcases/blog.json).

## Folder layout

```
imports/
  .htaccess            denies web access
  JSON/
    collections/*.json   one file per collection, fields and options embedded
    showcases/*.json     one file per showcase configuration
  PHP/
    collections/<name>/<x.y.z>.php   update steps
    showcases/<name>/<x.y.z>.php
```

* A file name must match `^[a-z0-9_\-]+\.json$`. Files are only found by scanning the two JSON folders; a path from a
  request is never opened directly, and a file must resolve (`realpath`) inside `imports/JSON`.
* A file is at most 512 KB.
* A file is referred to by its path relative to `imports/JSON`, for example `collections/blog.json`.

## Collection files

```json
{
    "type": "collection",
    "version": "1.0.0",
    "name": "Blog",
    "fields": [
        {
            "field_key": "category",
            "label": "Category",
            "html_type": "select",
            "field_type": "int",
            "maximum_length": 10,
            "options": [{"value_id": 1, "value": "News", "display_order": 1}]
        }
    ]
}
```

* `type`, `version` (`x.y.z`), `name` and `fields` are required.
* A field has `field_key`, `label`, `html_type` and `field_type`, plus any column of `myshowcase_fields` listed
  below. `field_type` is the database type of the column in the entries table (`int`, `varchar`, `text`, ...).
* `label` is a text of up to 30 characters, or a map of language directory to text (`{"english": "Category"}`).
* `options` (select, radio and checkbox fields only) is a list of `{"value_id", "value"}` plus `display_order` and
  `display_style`.
* `previous_keys` is an optional list of earlier `field_key`s of the field (see Identity).
* `allowed_groups_fill` and `allowed_groups_view` can only be `"all"` (stored as `-1`).
* Property names are the column names of the table; an unknown property is an error. Never allowed: `field_id`,
  `collection_id` and the `json_*` columns.

## Showcase files

```json
{
    "type": "showcase",
    "version": "1.0.0",
    "collection": "blog",
    "entries_enable_subject": 1,
    "main_per_page": 5
}
```

* `collection` is the name of a collection file without `.json` (`blog` is `collections/blog.json`).
* Any other property is a column of `myshowcase_config`, with its type (whole numbers for numbers, text for text).
* Never allowed: ids, `collection_id`, the `json_*` columns, `custom_theme_id`, `attachments_uploads_path`,
  `attachments_watermark_file` (these differ between boards), `password`.
* Per-showcase properties are never in a file: `name`, `route_identifier`, `custom_template_prefix` and
  `showcase_url`. They are entered when the showcase is created from the file and stay editable afterwards.

A property a file does not mention is not managed: it keeps its value and its input stays editable.

## Identity

* A row is linked to a file by `json_file` (the relative path). A collection file is linked to at most one
  collection; any number of showcases can be linked to one showcase file.
* Fields belong to the collection's file; a field is identified in the file by its `field_key`. Fields with an empty
  `json_file` are the board's own ("Custom, not in JSON").
* To rename a field, change its `field_key` and list the old key in `previous_keys`: the existing row (and its
  column in every entries table) is renamed instead of a second field being added.
* A showcase can only be linked when its collection is linked to the collection file it names.

## Versions, hashes and status

* `json_version` is the file's `version` when it was last applied; `json_hash` is the SHA-256 of the file with the
  object keys sorted recursively and encoded with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`. A field row
  hashes its own entry. `json_synced_at` is the time of the last apply.
* Status of a linked row:

| Status | Meaning | Action |
|---|---|---|
| In sync | same version, same hash | none |
| Update available | the file's version is higher | Apply update |
| Changed without a new version | same version, different hash | Apply anyway (warns) |
| Row is newer than the file | the row's version is higher | detach only |
| File missing | the file does not exist | detach only |
| File is invalid | the file fails validation (errors with their JSON path are shown) | fix the file |
| Collection is not linked ... | a showcase whose collection was detached | link the collection |

## Applying

The plan, in order: collection properties; field renames; field additions; field updates; fields no longer in the
file; options; PHP update steps; a rebuild of the entries table of every showcase using the collection; the version
and hash; the caches. Applying stops at the first step that fails and then writes neither version nor hash; every
step looks at the database first, so applying again is safe.

**Sync never deletes rows, columns or entries.** A field that leaves a file keeps its row and column and becomes a
custom field; options missing from a file are kept. A type change is refused (and reported) when the data does not
fit, and never truncates or rounds. Deleting a field a file manages is refused in the ACP: remove it from the file
and apply, or detach the collection.

## PHP update steps

`PHP/collections/<name>/<x.y.z>.php` runs when a collection linked to `collections/<name>.json` moves from a version
below `x.y.z` to a version of at least `x.y.z`, in ascending order. The file returns a callable:

```php
<?php

declare(strict_types=1);

return function (int $collectionId, string $oldVersion, string $newVersion, array $file): void {
    global $db;

    // Example: give the new field "author_note" a placeholder (a column the file does not manage).
    $db->update_query(
        'myshowcase_fields',
        ['placeholder' => $db->escape_string('Who wrote this?')],
        "collection_id='{$collectionId}' AND field_key='author_note'"
    );
};
```

`$file` is the decoded JSON. Throwing an exception stops the apply and nothing is recorded. Write steps so that
running them twice is harmless. Showcase steps are the same, with the showcase id.

## Hooks

After each step is written, `hooksRun('import_update_<type>', $payload)` fires, that is the MyBB hook
`myshowcase_system_import_update_collection` or `myshowcase_system_import_update_showcase`, with the payload
`['type' => 'collection|showcase', 'row_id' => int, 'step' => 'field_add', 'description' => '...',
'old_version' => '1.0.0', 'new_version' => '1.1.0']`.

## Workflow

1. Edit the file and raise its `version`.
2. Deploy the files.
3. In the ACP the row shows "Update available". Open Apply update, read the plan and its warnings, confirm.

Other actions: link an existing row (choose the file in its "JSON file" select), detach (choose None; only the
`json_*` columns change), create from a file (new collection or showcase page), and Export JSON (downloads the
row as `<name>.json`; nothing is written to `imports/`). Review an export before committing it.

## Security

* Only administrators with the `json_sync` ACP permission can use these actions, and every action checks the post key.
* Property whitelists, size limit, `JSON_THROW_ON_ERROR` decoding and the file name rule keep a file from setting
  anything but showcase and collection configuration; JSON files never contain code.
* PHP update steps are code that runs with the board's rights: deploy them like any plugin file and keep
  `imports/` out of the web root's writable and downloadable paths (the shipped `.htaccess` denies requests;
  with other servers, deny access to the folder).
