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
* The value is checked every time it is read. It is accepted only when it:
  * is not absolute and has no drive letter or stream wrapper (`://`);
  * has no `..` segment, no backslash, no NUL byte, and only the characters `A-Z a-z 0-9 _ . / -`;
  * resolves, after `realpath()` of its nearest existing part, to a folder inside the forum folder.
  Duplicate and trailing slashes and `.` segments are removed.
* A value that fails the check is replaced by the default for that request, and the export page shows an error.
  Exports are never written outside the forum folder.
* The folder is created on the first export, with mode `0750` and any missing parents inside the forum folder.

The check is in one place, `ExportsStorage::normalizeDirectory()`, which the setting and the storage both use.

## Protection

Each exports folder gets two files when it is created:

* `.htaccess`: denies all requests (both the Apache 2.4 and the 2.2 syntax);
* `index.html`: an empty page.

Only Apache reads `.htaccess`. On nginx (or any other server) the folder must be denied in the server configuration.
Add this to the server block of the board, before any general `location`:

```nginx
location ~ ^/uploads/myshowcase/exports/ {
    deny all;
    return 404;
}
```

If the folder is changed in the settings, use its path in place of `uploads/myshowcase/exports/`.

Export files are written with mode `0640`.

## Download through the route

A download is never a link to a stored file. The link carries only the export id:

* the route is `ExportDownload`, with the parameter `export_id`; the link never carries the file name or any path;
* the export must belong to the current user and to this showcase (`user_id` and `showcase_id` of the row). Any other
  user, and a guest, gets the same 404 answer as for an export that does not exist;
* a missing row, a row with `file_deleted = 1`, a missing file, or a file whose SHA-256 no longer matches the row answer
  404;
* the file name comes from the row and must match the file-name pattern below. The path is resolved with `realpath()`,
  and the file is served only when it lies directly inside the exports folder;
* the response carries `Content-Type: application/xml`, `Content-Disposition: attachment` with a download name built
  only from the showcase's route identifier and the export date, `Content-Length`, `X-Content-Type-Options: nosniff`
  and `Cache-Control: private, no-store`.

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

## Uninstall

Uninstalling with **Drop MyShowcase tables when uninstalling** enabled:

* removes the export files in the exports folder (the names that match the pattern above), the `.htaccess` and
  `index.html` that the plugin wrote there, and the folder itself if it is then empty;
* never follows a link, and never removes a parent folder or any other file in `uploads/`.

Without that option, the files and the table are kept, so a reinstall finds the exports again.
