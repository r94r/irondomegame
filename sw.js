// Iron Dome – Service Worker
// index.html: network-first with 3s timeout (falls back to cache on poor/no signal)
// Other assets: cache-first
// API calls: network only

const CACHE = 'irondome-v6';
const ASSETS = ['./', './manifest.json'];

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

  // Navigation (index.html): network-first with 3s timeout, fall back to cache.
  // Poor/no signal in shelters causes fetch to hang — timeout ensures the app loads.
  if(e.request.mode === 'navigate'){
    e.respondWith(
      Promise.race([
        fetch(e.request).then(res => {
          caches.open(CACHE).then(c => c.put(e.request, res.clone()));
          return res;
        }),
        new Promise((_, reject) => setTimeout(() => reject(new Error('sw-timeout')), 3000))
      ]).catch(() => caches.match(e.request))
    );
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
