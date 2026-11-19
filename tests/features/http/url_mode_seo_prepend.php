<?php

declare(strict_types=1);

// The server that stands for a server with mod_rewrite: MyBB core reads SEO_SUPPORT from the request (decision D224).
// Test data only: it is set on the server that the test starts with the variable URL_MODE_SEO=1.
if (getenv('URL_MODE_SEO') === '1') {
    $_SERVER['SEO_SUPPORT'] = 1;
}

// The plugin's own marker (decision D243): a request of a server with mod_rewrite and the documented snippets carries it.
if (getenv('URL_MODE_MARKER') === '1') {
    $_SERVER['SHOWCASE_SEO_SUPPORT'] = 1;
}
