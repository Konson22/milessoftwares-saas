/*
 * M&E's service worker (ADR-0019 §23), scope /mel/.
 *
 * It caches only static, content-hashed build files (scripts, styles,
 * fonts, images under /mel/build/assets/), the icons and a data-free
 * offline page. It never caches pages, JSON, the API, exports, photos or
 * anything else that can carry workspace data: those always go to the
 * network, and only a failed page load is answered with the offline page.
 */

const VERSION = 'mel-static-v1';
const SHELL = ['offline.html', 'icons/icon-192.png', 'icons/icon-512.png', 'manifest.webmanifest'];

const base = new URL('./', self.location.href); // .../mel/

/** Whether a request may be answered from, or stored in, the cache. */
function cacheable(request) {
    if (request.method !== 'GET') return false;
    const url = new URL(request.url);
    if (url.origin !== base.origin) return false;
    if (url.search !== '') return false;
    const path = url.pathname;
    if (!path.startsWith(base.pathname)) return false;
    const rest = path.slice(base.pathname.length);
    if (rest.startsWith('build/assets/')) {
        return /\.(js|css|woff2?|ttf|png|jpe?g|svg|webp|ico)$/.test(rest);
    }
    return SHELL.includes(rest);
}

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(VERSION).then((cache) => cache.addAll(SHELL.map((p) => new URL(p, base).href))),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((k) => k.startsWith('mel-') && k !== VERSION).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

// The page asks to switch to a waiting version once the person agrees.
self.addEventListener('message', (event) => {
    if (event.data === 'skip-waiting') self.skipWaiting();
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.mode === 'navigate') {
        // Pages carry data: network only. Offline, the data-free page.
        event.respondWith(
            fetch(request).catch(() => caches.match(new URL('offline.html', base).href).then((r) => r || Response.error())),
        );
        return;
    }

    if (!cacheable(request)) return; // the browser handles it, uncached

    event.respondWith(
        caches.match(request).then(
            (hit) =>
                hit ||
                fetch(request).then((response) => {
                    if (response.ok && response.type === 'basic' && !/no-store|private/.test(response.headers.get('Cache-Control') || '')) {
                        const copy = response.clone();
                        event.waitUntil(caches.open(VERSION).then((cache) => cache.put(request, copy)));
                    }
                    return response;
                }),
        ),
    );
});

// For tests (tests in resources/js load this file in a sandbox).
self.__melCacheable = cacheable;
