# Stored exports (`myshowcase_exports`)

One row per export file that a user has stored. The table is defined in `Upload/inc/plugins/myshowcase/Plugin/Core.php`
(`TABLES_DATA['myshowcase_exports']`); the name is given without the board prefix.

## Columns

| Column | Type | Meaning |
|---|---|---|
| `export_id` | INT, auto-increment primary key | Internal id. The download and delete routes carry it. |
| `showcase_id` | INT unsigned | The showcase the export was made from. |
| `collection_id` | INT unsigned | The collection the export was made from. |
| `user_id` | INT unsigned | The creator. Only this user can see, download or delete the export. |
| `created_at` | DATETIME | When the export was made (UTC). Used by the retention task. |
| `file_name` | VARCHAR(120), unique | The name of the file in the exports folder (see [exports](../exports.md#file-name)). |
| `file_size` | INT unsigned | Size of the file in bytes. |
| `file_hash` | VARCHAR(64) | SHA-256 of the file. A download is refused when the file no longer matches it. |
| `entry_count` | INT unsigned | Number of entries in the file. |
| `entry_limit` | INT unsigned | Rule: the limit asked for, 0 for as many as the cap allows. |
| `order_by` | VARCHAR(30) | Rule: the sort column or field key. |
| `order_direction` | VARCHAR(4) | Rule: `asc` or `desc`. |
| `selected_fields` | VARCHAR(1000) | Rule: the field keys, comma separated. |
| `rules` | TEXT, nullable | Rule: the complete rules of the file header, as JSON. |
| `plugin_version` | VARCHAR(20) | The plugin version that wrote the file. |
| `file_deleted` | TINYINT unsigned | `1` once the retention task has removed the file. The row stays as history until it is removed. |

## Keys

* `file_name` is a unique key (`file_name_uq`), so no two rows can name the same file.
* `user_showcase_created` on (`user_id`, `showcase_id`, `created_at`) serves the list of a user's exports in a showcase,
  the per-user limit and the retention queries.
