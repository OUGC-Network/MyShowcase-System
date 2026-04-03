const CACHE_NAME = 'app-cache-v1';
const urlsToCache = [
    '/',
    '/styles/main.css',
    '/scripts/myshowcase.js',
    '/scripts/myshowcase_inline.js',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.addAll(urlsToCache))
    );
});

self.addEventListener('fetch', (event) => {
    event.respondWith(
        caches.match(event.request)
            .then((response) => response || fetch(event.request))
    );
});
