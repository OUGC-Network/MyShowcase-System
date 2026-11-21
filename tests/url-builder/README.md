# URL builder guard

`guard_test.py` reads the plugin's PHP and Twig files, without a board, and fails when a link is built outside the one
builder (`Routing::base()` and `Routing::url()`, decisions D224, D237, D268). It looks for `generateUrl(`, `showcase_url` and
the template prefix `url.showcase`. Every occurrence that stays outside the builder is listed in `allow-list.json` with its
count and a reason; a new call, or a removed one, changes the count and the run fails. A negative control adds a call to a
copy of the builder file and requires the run to report it.

    python3 tests/url-builder/guard_test.py      (exit 0 when clean)

A second scan (decision D244) reads every template under `view/templates` (every set folder) and every script under
`jscripts`, and reports a link that is not absolute and not built from the board address or the builder:

* a value that starts with `//` (protocol-relative);
* a value that starts with `/` (host-root);
* a value that is a file name ending in `.php` (relative, for example `newreply.php?tid=...`);
* an empty `url_showcase` (the script's showcase base, which every page that loads the script must define).

The allow-list's `relative` entries give the exact count per file and kind, with a reason. Only the Batch 5 (PWA) items are
listed there: the `<link rel="manifest" href="/manifest.json">` of the eight templates that link it. A negative control adds a
relative link to a copy of `pageView.twig` and requires the run to report it.

It needs no database, no server and no MyBB tree.
