/**
 * Reminder Note - Service Worker
 * Strategy:
 *  - Install: pre-cache the app shell.
 *  - Activate: clean up old caches.
 *  - Fetch: network-first for /api/*, stale-while-revalidate for static assets.
 *
 * VERSION is bumped each time this file is regenerated (see deploy script).
 * For dev builds we add a query string fallback so a hard refresh evicts the
 * stale shell deterministically.
 */

const VERSION = 'v2-2026-05-06';
const SHELL_CACHE = `rn-shell-${VERSION}`;
const RUNTIME_CACHE = `rn-runtime-${VERSION}`;

const SHELL_URLS = [
    './',
    './index.html',
    './login.html',
    './manifest.webmanifest',
    './css/style.css',
    './css/calendar.css',
    './dist/app.js',
    './assets/icons/icon.svg',
    './assets/icons/icon-192.png',
    './assets/icons/icon-512.png',
    './assets/icons/badge.svg',
];

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(SHELL_CACHE);
        await cache.addAll(SHELL_URLS).catch(() => {});
        await self.skipWaiting();
    })());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const keys = await caches.keys();
        await Promise.all(
            keys
                .filter(k => k !== SHELL_CACHE && k !== RUNTIME_CACHE)
                .map(k => caches.delete(k))
        );
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;

    const url = new URL(req.url);

    if (url.pathname.includes('/api/')) {
        event.respondWith(networkFirst(req));
        return;
    }

    if (req.destination === 'document') {
        event.respondWith(navigationHandler(req));
        return;
    }

    event.respondWith(staleWhileRevalidate(req));
});

async function networkFirst(req) {
    try {
        const res = await fetch(req);
        return res;
    } catch {
        return new Response(JSON.stringify({ error: { code: 'offline', message: '当前离线' } }), {
            status: 503, headers: { 'Content-Type': 'application/json' },
        });
    }
}

async function navigationHandler(req) {
    try {
        const res = await fetch(req);
        const cache = await caches.open(RUNTIME_CACHE);
        cache.put(req, res.clone());
        return res;
    } catch {
        const cache = await caches.open(SHELL_CACHE);
        return (await cache.match('./index.html')) || (await cache.match('./login.html')) || Response.error();
    }
}

async function staleWhileRevalidate(req) {
    const cache = await caches.open(RUNTIME_CACHE);
    const cached = await cache.match(req);
    const fetchPromise = fetch(req).then(res => {
        if (res && res.status === 200 && (res.type === 'basic' || res.type === 'default')) {
            cache.put(req, res.clone());
        }
        return res;
    }).catch(() => null);
    return cached || (await fetchPromise) || Response.error();
}

self.addEventListener('message', (event) => {
    if (event.data?.type === 'SKIP_WAITING') self.skipWaiting();
});
