# Showcase documents

Each shipped import file describes one showcase and its collection. The documents here say what a showcase is for,
which files import it, how to import it, the configuration and fields it sets, and what an administrator still has to
grant.

| Document | Showcase | Import files |
|---|---|---|
| [blog.md](blog.md) | Blog: posts with a summary, tags, series and sources | `imports/JSON/collections/blog.json`, `imports/JSON/showcases/blog.json` |
| [gallery.md](gallery.md) | Gallery: images with a title, a caption and a category | `imports/JSON/collections/gallery.json`, `imports/JSON/showcases/gallery.json` |
| [downloads.md](downloads.md) | Downloads: files with a version, a checksum and a changelog | `imports/JSON/collections/downloads.json`, `imports/JSON/showcases/downloads.json` |

The format of the files, the sync states and the ACP pages are described in
[imports/README.md](../../Upload/inc/plugins/myshowcase/imports/README.md). The design of the gallery (approved, not yet
implemented) is in [schema/gallery-schema-changes.md](../schema/gallery-schema-changes.md).
