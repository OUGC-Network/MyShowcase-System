/*
 * The MyShowcase service worker (B5, decision D309). The server (PwaController::serviceWorker) writes the cache version and the
 * cache list into the two placeholders below; the file is served by a showcase route, never as a static file.
 *
 * The rules: static files of the cache list are answered cache first; every other GET request of the same origin goes to the
 * network (pages are never stored, so one viewer's page is never shown to another); a response that is not 200 is never stored;
 * requests that are not GET are left to the browser.
 */

const CACHE_NAME = 'myshowcase-__CACHE_VERSION__';
const PRECACHE_URLS = __PRECACHE_URLS__;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.addAll(PRECACHE_URLS))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((names) => Promise.all(
                names
                    .filter((name) => name.startsWith('myshowcase-') && name !== CACHE_NAME)
                    .map((name) => caches.delete(name))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (PRECACHE_URLS.includes(url.href)) {
        event.respondWith(
            caches.match(request).then((cached) => cached || fetch(request))
        );
        return;
    }

    event.respondWith(fetch(request));
});
