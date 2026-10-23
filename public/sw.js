/* Service Worker with offline fallback page for Avalynx Mailer */
const CACHE_NAME = 'mailer-cache-v3';
const OFFLINE_URL = '/offline.html';
const CORE_ASSETS = [
  '/',
  '/manifest.webmanifest',
  OFFLINE_URL
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(CORE_ASSETS))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(
      keys.map((k) => (k !== CACHE_NAME ? caches.delete(k) : Promise.resolve()))
    ))
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const accept = req.headers.get('accept') || '';
  const isNavigate = req.mode === 'navigate' || accept.includes('text/html');

  if (isNavigate) {
    // Show offline page when navigation fails (offline)
    event.respondWith(
      fetch(req).catch(() => caches.match(OFFLINE_URL))
    );
    return;
  }

  // Default: cache-first for other GET requests (minimal change to existing behavior)
  event.respondWith(
    caches.match(req).then((cached) => {
      return fetch(req).then((resp) => {
        // Never cache error responses: a temporarily failing asset (e.g.
        // a route that was added later) would otherwise stay broken until
        // the cache version is bumped.
        if (!resp.ok) {
          return resp;
        }
        const copy = resp.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(req, copy)).catch(() => {});
        return resp;
      }).catch(() => cached);
    })
  );
});
