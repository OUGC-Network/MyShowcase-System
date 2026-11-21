<?php

declare(strict_types=1);

/*
 * The router of the PWA suite (PWA-SUB). The board also answers under /sub/: the test board has a symlink named sub that points
 * at the board root, so a request for /sub/<file> reads the file of the root. A request for the script or a file is served as it
 * is; any other path under /sub/ is a route, which is answered by showcase.php with the path after /sub/ (as the rewrite of a
 * server would do). Test data only: it is not part of the plugin.
 */

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';

if (str_starts_with($path, '/sub/')) {
    $rest = substr($path, 4);

    if (str_starts_with($rest, '/showcase.php') || is_file(__DIR__ . $rest)) {
        return false;
    }

    $_SERVER['REQUEST_URI'] = substr($uri, 4);
    $path = $rest;
}

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
