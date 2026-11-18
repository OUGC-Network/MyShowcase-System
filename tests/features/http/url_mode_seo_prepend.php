<?php

declare(strict_types=1);

// The server that stands for a server with mod_rewrite: MyBB core reads SEO_SUPPORT from the request (decision D224).
// Test data only: it is set on the server that the test starts with the variable URL_MODE_SEO=1.
if (getenv('URL_MODE_SEO') === '1') {
    $_SERVER['SEO_SUPPORT'] = 1;
}
