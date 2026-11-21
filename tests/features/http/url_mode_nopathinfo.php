<?php

declare(strict_types=1);

/*
 * The router of a test server that does not route PATH_INFO, as a common nginx "location ~ \.php$" block does (decision D268):
 * a request for showcase.php with a path after the script name is not found, while showcase.php with its query string is
 * served as it is. Static files are served as they are. Test data only: it is not part of the plugin.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (str_starts_with($path, '/showcase.php/')) {
    http_response_code(404);
    echo 'not found: this server does not route PATH_INFO';

    return true;
}

return false;
