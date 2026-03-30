// Iron Dome – Service Worker
// index.html: network-first, falls back to cache only when offline (no network at all)
// Other assets: cache-first
// API calls: network only

const CACHE = 'irondome-v6';
const ASSETS = ['./index.html', './manifest.json'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(ASSETS)));
  self.skipWaiting();
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', e => {
  // API calls: network only (never cache)
  if(e.request.url.includes('api.php')) return;

  // Navigation (index.html): network-first, fall back to cache only if offline.
  // Online users always get fresh content; shelter users can still open the app.
  if(e.request.mode === 'navigate'){
    e.respondWith(fetch(e.request).catch(() => caches.match('./index.html')));
    return;
  }

  // Everything else (images, manifest): cache-first, fall back to network
  e.respondWith(
    caches.match(e.request).then(cached => {
      if(cached) return cached;
      return fetch(e.request).then(res => {
        caches.open(CACHE).then(c => c.put(e.request, res.clone()));
        return res;
      });
    })
  );
});
