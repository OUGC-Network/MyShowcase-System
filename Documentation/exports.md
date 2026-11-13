# Entry exports

A user can export entries of a showcase to an XML file and keep it on the board for later download. The files are
stored on disk; the table that lists them is described in [schema/exports.md](schema/exports.md).

## The XML wrapper

`MyShowcase\Util\XmlFile` (`Upload/inc/plugins/myshowcase/src/Util/XmlFile.php`) wraps PluginLibrary's XML export and
import. It writes the file with a comment that names the export and the time, and reads it back with the same
PluginLibrary parser. `XmlFileException` is raised for files it cannot write or read. The unit tests run without a board:
`php tests/xml-file/run.php`.

## Storage folder

The export files are written to the folder set by the global board setting **Folder for stored exports**
(`myshowcase_exports_directory`, in the MyShowcase settings group).

* The value is a path **relative to the forum folder** (`MYBB_ROOT`), for example `uploads/other/exports`.
* The default, used when the value is empty, is `uploads/myshowcase/exports/`.
* Surrounding whitespace is dropped. Duplicate and trailing slashes and `.` segments are removed.
* The value is accepted only when it:
  * is not absolute and has no drive letter or stream wrapper (`://`);
  * has no `..` segment, no backslash, no NUL byte, and only the characters `A-Z a-z 0-9 _ . / -`;
  * resolves, after `realpath()` of its nearest existing part, to a folder inside the forum folder;
  * is a **dedicated** folder (see below).
* The value is checked when the settings are saved, and again every time it is read.

### Saving the setting

When the board settings are saved, an invalid value is not written: the value stored before stays, and the settings page
says why. The page has one success message per save, so the reason is added to that message rather than shown as a
separate error. A valid value is stored in its normalized form.

### Dedicated folder

A folder can hold exports only when it does not exist yet, or when it is a real directory (not a link) whose contents
are nothing but:

* export files named like `{export id}-{route identifier}-{YYYYMMDD-HHMMSS}-{32 hex}.xml`;
* an `.htaccess` whose content is exactly the plugin's deny file;
* an `index.html`, with any content.

A subfolder, any other file, a link, or an `.htaccess` that is not the plugin's makes the folder unusable. The default
folder is then used instead, and the export page shows an error. If the default folder is not dedicated either, no
export is written. Files that the plugin did not write are never overwritten or deleted.

The checks are in one place, `ExportsStorage::normalizeDirectory()` and `ExportsStorage::isDedicatedFolder()`, used by
the setting's validation, the save check and the storage.

## Protection

Each exports folder gets two files when it is created:

* `.htaccess`: denies all requests (both the Apache 2.4 and the 2.2 syntax);
* `index.html`: an empty page.

An existing `index.html` is kept. An existing `.htaccess` is kept only when it is the plugin's deny file; otherwise
nothing is written to the folder.

Only Apache reads `.htaccess`. On nginx (or any other server) the folder must be denied in the server configuration.
Add this to the server block of the board:

```nginx
location ^~ /uploads/myshowcase/exports/ {
    deny all;
}
```

If the forum is installed in a subfolder, put that folder's prefix before the path, for example
`location ^~ /forum/uploads/myshowcase/exports/`. If the folder is changed in the settings, use its path instead of
`uploads/myshowcase/exports/`.

Export files are written with mode `0640`; the folder with mode `0750`.

The export page shows a note about the protection whenever the folder is below the forum folder, which every valid
folder is.

## Download through the route

A download is never a link to a stored file. The link carries only the export id:

* the route is `ExportDownload`, with the parameter `export_id`; the link never carries the file name or any path;
* the export must belong to the current user and to this showcase (`user_id` and `showcase_id` of the row). Any other
  user, and a guest, gets the same 404 answer as for an export that does not exist;
* a missing row, a row with `file_deleted = 1`, a missing file, or a file whose SHA-256 no longer matches the row answer
  404;
* the file name comes from the row and must match the file-name pattern below. The path is resolved with `realpath()`,
  and the file is served only when it lies directly inside the exports folder;
* the response carries `Content-Type: application/xml; charset=UTF-8`, `Content-Disposition: attachment` with a download
  name built only from the showcase's route identifier and the export date, `Content-Length`,
  `X-Content-Type-Options: nosniff` and `Cache-Control: private, no-store`.

## File name

`{export id}-{route identifier}-{YYYYMMDD-HHMMSS}-{32 random hex characters}.xml`, for example
`12-gallery-20261112-093015-0c1f...e9.xml`. Nothing in the name comes from a request: the route identifier keeps only
the characters `a-z 0-9 _ -`. The name is never shown to a user; the download name is built separately.

## Retention

The scheduled task `myshowcase` deletes the files of exports that are older than **30 days**. The retention setting
(`exportsRetentionDays` in `Upload/inc/plugins/myshowcase.php`) sets the number of days; 0 keeps files.

The retention runs first in the task, before the pruning of old entries. For each expired export the file is deleted
and its row is marked `file_deleted = 1`. Rows marked deleted are removed after twice the retention period (60 days
by default), so the history of recent exports stays visible to the user.

## Changing the folder

Exports are looked up in the folder that is set when they are read. After the folder is changed:

* new exports are written to the new folder;
* exports made before the change stay in the old folder. They are not served, since the download looks in the new
  folder, and the retention cannot delete them. Retention still marks their rows as deleted, so the files remain on
  disk until they are removed by hand. Nothing is moved automatically.

## Uninstall

Uninstalling with **Drop MyShowcase tables when uninstalling** enabled removes, from the exports folder:

* the export files (the names that match the pattern above);
* the `.htaccess`, only when it is the plugin's deny file;
* the `index.html`, only when the folder held nothing else (no other files, no subfolders, no links) besides export
  files and the plugin's own `.htaccess`;
* the folder itself, only when it is then empty.

Links are never followed. Parent folders are never removed: the default folder's parent, `uploads/myshowcase/`, is
created by the plugin but is left in place on uninstall, like every other folder above the exports folder.

Without that option, the files and the table are kept, so a reinstall finds the exports again.
