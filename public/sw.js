const CACHE_NAME = 'o365kiosk-v1';
const PRECACHE_URLS = [
    '/offline.html',
    '/manifest.webmanifest',
    '/icons/icon-192.svg',
    '/icons/icon-512.svg',
];

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

    const requestUrl = new URL(event.request.url);
    if (requestUrl.origin !== self.location.origin) {
        return;
    }

    // Documents: network first, fallback to offline page.
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request)
                .then((response) => {
                    const responseCopy = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(event.request, responseCopy));
                    return response;
                })
                .catch(async () => (await caches.match(event.request)) || caches.match('/offline.html')),
        );
        return;
    }

    // Static assets: stale-while-revalidate.
    event.respondWith(
        caches.match(event.request).then((cached) => {
            const fetched = fetch(event.request)
                .then((response) => {
                    if (response && response.status === 200) {
                        const responseCopy = response.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(event.request, responseCopy));
                    }
                    return response;
                })
                .catch(() => cached);

            return cached || fetched;
        }),
    );
});
