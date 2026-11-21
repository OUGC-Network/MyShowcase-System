<?php

declare(strict_types=1);

namespace MyShowcase\Controller;

use MyShowcase\Enum\Routes;
use MyShowcase\Routing\Routing;
use MyShowcase\ShowcaseContext;

use const MyShowcase\VERSION_CODE;

/**
 * The web app manifest and the service worker of a showcase (B5, decision D309). Both are answered by showcase routes, in path
 * form and in query string form. The worker's default scope is the folder of its script address, so it is registered in query
 * string form at the board folder, whatever the showcase's link setting; the response also allows that folder explicitly.
 */
class PwaController
{
    /** The icons of the plugin (in the board's images folder, relative to the board) and their sizes. */
    public const ICONS = [
        'images/myshowcase-icon-192.png' => '192x192',
        'images/myshowcase-icon-512.png' => '512x512',
    ];

    /** The static files the worker keeps and answers cache first (relative to the board); a file that does not exist is left out. */
    public const PRECACHE_FILES = [
        'jscripts/myshowcase.js',
        'jscripts/myshowcase_inline.js',
        'images/myshowcase-icon-192.png',
        'images/myshowcase-icon-512.png',
    ];

    public function __construct(
        public readonly ShowcaseContext $context,
    ) {
    }

    /** The manifest: absolute start_url, scope and icon addresses, with the manifest media type. */
    public function manifest(): void
    {
        $routing = $this->context->routing;

        $icons = [];

        foreach (self::ICONS as $path => $sizes) {
            $icons[] = ['src' => $this->boardUrl($path), 'sizes' => $sizes, 'type' => 'image/png'];
        }

        $name = trim((string)$this->context->showcase->name);
        $name = $name === '' ? 'MyShowcase' : $name;

        $document = [
            'name' => $name,
            'short_name' => mb_substr($name, 0, 12),
            'start_url' => $routing->url(Routes::Main),
            // the board folder, the folder of the worker's scope, in every link form (decision D316)
            'scope' => $this->boardUrl(''),
            'display' => 'standalone',
            'icons' => $icons,
        ];

        $this->send(
            'application/manifest+json; charset=utf-8',
            json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
    }

    /** The worker script, with its version and its cache list written in (the file is pwa/service-worker.js). */
    public function serviceWorker(): void
    {
        $files = array_values(array_filter(self::PRECACHE_FILES, static fn(string $path): bool => is_file(MYBB_ROOT . $path)));

        // the version changes with the plugin and with any cached file, so a new worker replaces the old cache
        $fingerprint = VERSION_CODE;

        foreach ($files as $path) {
            $fingerprint .= "\n" . $path . ':' . md5_file(MYBB_ROOT . $path);
        }

        $urls = array_map(fn(string $path): string => $this->boardUrl($path), $files);

        $template = (string)file_get_contents(dirname(__DIR__, 2) . '/pwa/service-worker.js');

        $script = str_replace(
            ['__CACHE_VERSION__', '__PRECACHE_URLS__'],
            [substr(hash('sha256', $fingerprint), 0, 16), json_encode($urls, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)],
            $template
        );

        $this->send(
            'application/javascript; charset=utf-8',
            $script,
            ['Service-Worker-Allowed: ' . $this->context->routing->boardFolder()]
        );
    }

    /**
     * An address of the board's own files, on the origin of the worker: a worker answers the files of its own origin cache
     * first, so the cache list must name that origin (the showcase's address), with the board folder and the path.
     */
    private function boardUrl(string $path): string
    {
        $routing = $this->context->routing;

        return Routing::originOf($routing->plainBase()) . rtrim($routing->boardFolder(), '/') . '/' . ltrim($path, '/');
    }

    /**
     * Writes the answer and ends the request. The answer is never cached: a new version of the worker is picked up at once.
     *
     * @param list<string> $headers
     */
    private function send(string $contentType, string $body, array $headers = []): void
    {
        header('Content-Type: ' . $contentType);
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');

        foreach ($headers as $header) {
            header($header);
        }

        echo $body;

        exit;
    }
}
