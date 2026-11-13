# Entry packages (design, not implemented)

> **Status: not implemented.** This document is the design for a later step. The entries import reads XML files only
> (see [import.md](import.md)), and file fields are taken from URLs. Nothing here is in the code yet.

A package is one ZIP file that imports **one entry**: the entry's values in an XML file, and the files of its file fields
inside the package. It is for the files that a URL cannot give: a file on a machine that is not reachable from the server,
or a file that should not be fetched from anywhere.

## Layout

```
package.zip
├── manifest.json              the package's description and the checksums of its files
├── entry.xml                  the entries format (version 2) with exactly one entry
├── files/
│   └── <field_key>/
│       └── <file name>        one file per file field of the entry that has a file
└── attachments/               reserved for later use (other attachments of the entry); must be empty in version 1
```

### `entry.xml`

The entries format of [import.md](import.md), with `format_version` 2 and exactly one entry. The header names the showcase
and the collection exactly as an entries file does. A file field holds either a URL (fetched under the rules of
[File fields](import.md#file-fields)) or a package reference:

```
package:files/<field_key>/<file name>
```

### `manifest.json`

```json
{
  "package_format": 1,
  "showcase": "gallery",
  "collection": "Gallery",
  "entry": "entry.xml",
  "files": [
    {"path": "files/image/600x450.png", "field_key": "image", "size": 4812, "sha256": "<64 hex characters>"}
  ]
}
```

* `package_format`: 1. A higher number is refused.
* `showcase` and `collection`: the route identifier and the collection name of the target. They must agree with the
  header of `entry.xml` and with the showcase the package is imported into.
* `files`: one object per file in the package, with its path (exactly the path in the archive), the field it belongs to,
  its size in bytes and its SHA-256. A file that is in the archive but not in the list, or the reverse, refuses the package.

## Names

* Names are ASCII: letters, digits, `.`, `-` and `_`. A name starts with a letter or a digit (no leading dot), and has at
  most 128 characters.
* Names are case-sensitive and compared exactly. Two names that differ only in case are duplicates and refuse the package.
* A path has exactly the layout above. Separators are `/` only; a backslash, a drive letter, an absolute path, a `..`
  segment or an empty segment refuses the package.
* A field key in a path is the field key of the entry's collection, and must be a file field.

## Limits

| Limit | Value (proposed) |
|---|---|
| Entries in a package | exactly one |
| Files in a package | 25 |
| Compressed size of the package | the upload cap of the import (see [import.md](import.md#limits)) |
| Uncompressed size of all files | 100 MB |
| Size of one file | the smallest of the field's maximum size, the type's maximum size and 50 MB |
| Compression ratio of any file | at most 100 to 1 |
| Entries of the archive (files and folders) | at most 40 |

The limits are constants, as the external file limits are (see `ExternalFileLimits`).

## Security

* **Zip slip and absolute paths.** Names are checked against the layout before anything is written. The archive is never
  extracted with a library's `extract` method; each file is read by its name and written to a name this code chose.
* **Symbolic links and special files** are refused: an entry whose external attributes mark a link or a device refuses the
  package.
* **Duplicate names** refuse the package, whatever their case.
* **Zip bombs.** The declared uncompressed size of every entry and the total are checked before extraction, and the bytes
  actually read are counted: a file that is larger than declared refuses the package.
* **Nested archives.** The archive is not opened again: a file that is itself an archive (a ZIP attachment) is an
  attachment, stored as it is, and its content is never read.
* **Encryption** is refused.
* **Types.** Every file passes the checks an upload passes: the board's attachment types by extension and by the detected
  MIME type, the sizes, and a valid image for an image type. An executable type is not an allowed type unless the board
  allows it.
* **Checksums.** Every file's SHA-256 must match the manifest; a mismatch refuses the package.

## Import flow

1. **Upload.** The import page accepts a `.zip` file as well as the `.xml` file, with the MIME type `application/zip`,
   the same size cap and the same permission and post-key checks as the XML import.
2. **Extract to a temporary area.** The archive is read entry by entry under the rules above, into the temporary area of
   the import (the same area as the URL files). Nothing is written to the uploads folder.
3. **Verify.** The manifest is read and checked, every file's size and SHA-256 are checked against it, and `entry.xml` is
   read with the XML reader of the import (the same refusals: DOCTYPE, size, well-formedness).
4. **Validate.** The entry goes through the same structural checks, the same validator and the same per-entry steps as an
   entry of an XML file. A package reference names a file of the package; a URL names a file to fetch.
5. **Write.** All or nothing, as an XML import: one transaction, the entry through the entry insert, and each file through
   the attachment pipeline with the local-file seam (see T34 in [the design](../docs/import-export-design.md)). A failure
   removes the entry, the attachments, the files stored and the temporary files.
6. **Clean up.** The temporary area is removed on every path.

A package and a plain XML file share every step after the extraction. The only difference is where the files of file
fields come from.

## URL references and package references

A file field holds one reference: a URL, or a `package:` reference. Both may appear in the entry of one package, field by
field. The setting that switches the import of external files off (`myshowcase_import_external_files`) applies to the URL
references only; a package reference needs no network and is not affected by it.

## Export counterpart (later)

"Export as package" writes the same layout for one entry: the entry's XML (format version 2, with `package:` references to
the files it writes into `files/`), the manifest with the checksums, and the files the exporting user may download. It
uses the rules of the export of file fields (see [exports.md](exports.md#file-fields)).

## Test ideas

* A valid package imports one entry; its files are stored with the bytes of the archive (SHA-256 of each).
* A package whose manifest lists a file the archive lacks, or the reverse, is refused.
* Each name rule has a refusal: a backslash, `..`, an absolute path, a leading dot, a name of 129 characters, two names that
  differ in case, a duplicate name.
* A symbolic link entry and an encrypted entry are refused.
* A file of 1 KB declared and 2 MB inflated is refused while it is read, and the temporary area is empty afterwards.
* A package with 26 files is refused before extraction.
* A package with an archive inside is stored as an attachment and not opened.
* A file of a type the board does not allow is refused as a URL file is.
* A failure after the entry was written removes the entry, the attachment rows and the files.
* A URL and a package reference in one entry are both taken.
* The setting switched off refuses a URL and accepts a package reference.
