const CACHE_NAME = 'o365kiosk-v3';
const PRECACHE_URLS = [
    '/offline.html',
    '/manifest.webmanifest',
    '/icons/icon-192.svg',
    '/icons/icon-512.svg',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
];

// Only fingerprinted/static assets are cached. HTML pages are never stored: they may contain
// admin data, CSRF tokens or kiosk access tokens that must not outlive the session.
const isStaticAsset = (url) => url.pathname.startsWith('/build/')
    || url.pathname.startsWith('/icons/')
    || url.pathname.startsWith('/flux/')
    || url.pathname.startsWith('/livewire/livewire');

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE_URLS)),
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(
            keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)),
        )),
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') {
        return;
    }

    const url = new URL(event.request.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    if (event.request.mode === 'navigate') {
        event.respondWith(fetch(event.request).catch(() => caches.match('/offline.html')));
        return;
    }

    if (! isStaticAsset(url)) {
        return;
    }

    // Static assets: stale-while-revalidate.
    event.respondWith(
        caches.match(event.request).then((cached) => {
            const fetched = fetch(event.request)
                .then((response) => {
                    if (response && response.status === 200) {
                        const copy = response.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));
                    }
                    return response;
                })
                .catch(() => cached);

            return cached || fetched;
        }),
    );
});
