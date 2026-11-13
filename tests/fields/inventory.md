# Field inventory

What the code does with each field definition, read from the code at base `9c5b7d6` with the PostgreSQL fix of this batch.
A cell is `verified` when the field matrix exercised it, `read` when the code reads it but no test exercised it, and `dead`
when it is stored and read nowhere, or only in code that is commented out. File and line numbers are from the plugin tree
under `Upload/inc/plugins/myshowcase/`.

## Field HTML types

| HTML type | Field class | Create form | Matrix |
|---|---|---|---|
| text, search, email, url, tel, password, textarea | TextField, SearchField, EmailField, UrlField, TelephoneField, PasswordField, TextAreaField | yes (`view/templates/pageEntryCreateUpdateField.twig`) | verified |
| number, range | NumberField, RangeField | yes; the `max` attribute is built from `maximum_length` (`:457` number, `:507` range) and `min` is not written for numbers (`:456`) | verified (the attributes are wrong) |
| date, datetime-local, time, month, week | DateField, DateTimeLocalField, TimeField, MonthField, WeekField | yes | verified |
| color | ColorField | yes | verified |
| checkbox, radio, select | CheckBoxField, RadioField, SelectField | yes; options are posted by `field_data_id` | verified |
| select2_users, select2_entries, select2_threads | SelectUsersField, SelectEntriesField, SelectThreadsField | yes (plain text input) | verified (validation only) |
| file | FileField | yes (FilePond uploader) | verified for the upload and the required count |
| rating | RatingField | no: the template has no branch, the container is empty | verified (defect) |
| poll | PollField | no: the template has no branch, the container is empty | verified (defect) |

The field types (`src/Enum/FieldTypes.php`) are tinyint, smallint, mediumint, int, bigint, decimal, float, double, char,
varchar, tinytext, text, mediumtext, date, time, datetime, timestamp, binary, varbinary. The ACP accepts the field types
listed in `fieldTypesGet()`; the JSON validator (`JsonValidator.php`) checks the same list.

## Properties of a field definition

| Property | Read where | Status |
|---|---|---|
| field_key | validator, column name, JSON sync | verified |
| label, description | create form (description always shown, `:5-6`); entry page only when `enable_description` (`pageView.twig:46`) | verified (label); description shown on the form regardless of `enable_description` |
| placeholder | create form (`:87`) | read, not exercised |
| html_type | create form, `FieldFactory`, validator | verified |
| field_type | column definition (`Plugin/Functions.php:600-651`), validator (`EntriesManager.php:1139-1166`), `castTableFieldValue` (`Plugin/Functions.php:860`) | verified |
| file_capture | create form (`:105-106`) | read, not exercised |
| allow_multiple_values | file and other templates (`:89`, `:108`), `FieldTrait.php:361,374` | read; a checkbox is posted as an array in any case |
| regular_expression | validator (`EntriesManager.php:1113-1115`), ACP validity (`FieldService.php`) | verified; a blank optional value is refused (defect) |
| input_pattern | create form `pattern` attribute | verified |
| display_in_create_update_page, display_in_view_page, display_in_main_page | `FieldTrait.php:74,87,100,379` | read; the default is verified, a hidden field is not |
| minimum_length, maximum_length | validator length checks (`EntriesManager.php:1152-1166`), column size (`Plugin/Functions.php:600-651`), form `minlength`/`maxlength`, form `min`/`max` on the date types | verified (length); number and range `min`/`max` are wrong |
| minimum_size, maximum_size | file size (`AttachmentsManager.php:254-269`), importer (`EntriesImporter.php:426`) | read for files only; NOT read for numbers or ranges: dead for numeric fields |
| step_size | range `step` attribute | verified |
| input_size | `size` attribute (`:92`, `:475`, `:525`, `:543`) | read, not exercised |
| allowed_groups_fill, allowed_groups_view | `FieldTrait`, `EntriesManager` (`is_member`) | verified (hidden from the other group, shown to its members) |
| default_value | form pre-fill, validator default substitution (`EntriesManager.php:1129`) | verified; the substitution also replaces a value of `"0"` (`empty()`) |
| default_type | `FieldDefaultUtil::valueFor` (importer, `EntriesImporter.php:532`), `EntriesController.php:1005-1022` | AsDefined live in the form; IsNull, CurrentTimestamp and UUID are commented out there (the UUID default is missing: defect) |
| display_order, render_order | order of the form; `render_order` in `pageMainEntry.twig:65` | read, not exercised |
| format | `formatField` when `parser_enable` is off (`FieldTrait.php:181-184`) | read, not exercised |
| filter_on_save | validator, UUID replacement (`EntriesManager.php:~1160`) | read, not exercised |
| is_enabled | `Showcase.php:179`, `FieldTrait.php:70,83` | read, not exercised |
| is_required | validator (`EntriesManager.php:1119`), form `required` attribute | verified; `0` counts as empty (defect) |
| parser_enable | entry page (`FieldTrait.php:170`) | verified (escaped output) |
| parser_allow_html, parser_allow_mycode, parser_allow_smiles, parser_allow_image_code, parser_allow_video_code | entry page (`FieldTrait.php:170-178`) | read; only the default (no HTML) is verified |
| parser_affects_message_length (field level) | read nowhere: the showcase has its own columns (`ShowcaseEntity`, `EntriesManager.php:940`) | DEAD at field level |
| enable_search | search highlight (`FieldTrait.php:149`), `EntriesController.php:2106` | read; search not run |
| enable_slug | entry slug (`EntriesManager.php`) | verified (the view URL slug) |
| enable_subject | form container class (`:8`) | read; the showcase flag `entries_enable_subject` decides the subject input |
| enable_description | entry page (`pageView.twig:46`) | read |
| enable_editor | editor for text, search and textarea (`:9`) | read, not exercised |
| read_only | form `readonly`, validator skip (`EntriesManager.php:~1092`) | verified (the sent value is ignored) |
| json_file, json_version, json_synced_at, json_hash | JSON sync (managed) | not part of the field matrix |
| options (`myshowcase_field_data`): value_id, value, display_style, display_order, allowed_groups_fill, allowed_groups_view | form options, entry page label (`FieldTrait.php`) | verified (labels and order); `display_style` read, not exercised |

## Properties stored but read nowhere (or only dead code)

* `parser_affects_message_length` at field level (the showcase has the property).
* `minimum_size` and `maximum_size` for number and range fields.
* The form's `default_type` values IsNull, CurrentTimestamp and UUID (commented out in `EntriesController.php:1017-1022`).
