// sw.js – Service Worker for ChristianPDF PWA
const CACHE_NAME = 'christianpdf-v1';
const STATIC_ASSETS = [
  '/',
  '/index.php',
  '/styles.css',
  '/manifest.json',
  '/icons/icon-192.png',
  '/icons/icon-512.png'
];

// Install: pre-cache static assets
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => cache.addAll(STATIC_ASSETS))
  );
  self.skipWaiting();
});

// Activate: remove old caches
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
    )
  );
  self.clients.claim();
});

// Fetch: network-first for PHP pages, cache-first for static assets
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);

  // Skip non-GET and cross-origin requests
  if (event.request.method !== 'GET' || url.origin !== self.location.origin) return;

  // For file downloads inside /data/, always go network
  if (url.pathname.startsWith('/data/')) {
    event.respondWith(fetch(event.request));
    return;
  }

  // Network-first for PHP navigation pages
  if (url.pathname.endsWith('.php') || url.pathname === '/') {
    event.respondWith(
      fetch(event.request)
        .then(res => {
          const clone = res.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(event.request, clone));
          return res;
        })
        .catch(() => caches.match(event.request))
    );
    return;
  }

  // Cache-first for CSS, images, icons, manifest
  event.respondWith(
    caches.match(event.request).then(cached => cached || fetch(event.request))
  );
});
