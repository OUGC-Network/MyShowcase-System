<?php

declare(strict_types=1);

/*
 * The router of the test server for friendly URLs (decision D224). A server rewrite does this on a real board: a request for a
 * path that is no file of the board goes to showcase.php, with the path as its path info. Static files are served as they are.
 * Test data only: it is not part of the plugin.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path !== '/' && file_exists(__DIR__ . $path) && !is_dir(__DIR__ . $path)) {
    return false;
}

if (str_starts_with($path, '/showcase.php') || str_starts_with($path, '/index.php') || $path === '/') {
    return false;
}

$_SERVER['SCRIPT_NAME'] = '/showcase.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/showcase.php';
$_SERVER['PHP_SELF'] = '/showcase.php';
chdir(__DIR__);
require __DIR__ . '/showcase.php';
