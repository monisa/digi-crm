/*
 * DigiFox Pro CRM service worker: makes the CRM installable as an app and shows a
 * friendly page when there is no internet. It does NOT cache the app or any data,
 * so every deployment and every API answer is always fresh.
 */
const CACHE = 'dfp-offline-v1';
const OFFLINE = '/offline.html';

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll([OFFLINE, '/icon-192.png'])).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  const url = new URL(req.url);
  // Only page loads of the app itself; API calls, files and other sites go straight to the network.
  if (req.mode !== 'navigate' || url.origin !== location.origin || url.pathname.startsWith('/api')) return;
  e.respondWith(fetch(req).catch(() => caches.match(OFFLINE)));
});
