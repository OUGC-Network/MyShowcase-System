# URL builder guard

`guard_test.py` reads the plugin's PHP and Twig files, without a board, and fails when a link is built outside the one
builder (`Routing::base()` and `Routing::url()`, decisions D224, D237). It looks for `generateUrl(`, `showcase_url` and the
template prefix `url.showcase`. Every occurrence that stays outside the builder is listed in `allow-list.json` with its
count and a reason; a new call, or a removed one, changes the count and the run fails. A negative control adds a call to a
copy of the builder file and requires the run to report it.

    python3 tests/url-builder/guard_test.py      (exit 0 when clean)

It needs no database, no server and no MyBB tree.
