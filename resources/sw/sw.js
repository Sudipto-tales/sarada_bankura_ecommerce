const VERSION = '__VERSION__';
const STATIC = 'ms-static-' + VERSION;
const PAGES = 'ms-pages-v1';
const IMAGES = 'ms-img-v1';
const PRECACHE = __PRECACHE__;
const OFFLINE = __OFFLINE__;
const BASE = new URL(self.registration.scope).pathname.replace(/\/$/, '');
const NEVER = /^\/(checkout|pay|order|cart|account|signin|signout|register|admin|track|enquiry|review|saved\/|app\/download)(\/|$)/;
const MAX_PAGES = 80;
const MAX_IMAGES = 400;
const BLANK = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"/>';

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(STATIC).then((c) => Promise.all(PRECACHE.map((u) => c.add(u).catch(() => null)))).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.filter((k) => k.startsWith('ms-static-') && k !== STATIC).map((k) => caches.delete(k)));
    if (self.registration.navigationPreload) await self.registration.navigationPreload.enable();
    await self.clients.claim();
  })());
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  const path = url.pathname.slice(BASE.length) || '/';
  if (req.mode === 'navigate') {
    e.respondWith(page(e, path));
  } else if (path.startsWith('/assets/')) {
    e.respondWith(req.destination === 'image' || /\.(webp|avif|jpe?g|png|gif|svg)$/i.test(path) ? image(e) : asset(req));
  }
});

function cacheable(res) {
  const cc = res.headers.get('Cache-Control') || '';
  return res.ok && res.type === 'basic' && !res.redirected && !/no-store|private/i.test(cc);
}

async function trim(name, max) {
  const c = await caches.open(name);
  const keys = await c.keys();
  for (let i = 0; i < keys.length - max; i++) await c.delete(keys[i]);
}

async function page(e, path) {
  const req = e.request;
  try {
    const res = (await e.preloadResponse) || (await fetch(req));
    if (!NEVER.test(path) && cacheable(res)) {
      const copy = res.clone();
      e.waitUntil(caches.open(PAGES).then((c) => c.delete(req.url).then(() => c.put(req.url, copy))).then(() => trim(PAGES, MAX_PAGES)));
    }
    return res;
  } catch (err) {
    const hit = !NEVER.test(path) && (await caches.match(req.url, { cacheName: PAGES }));
    return hit || (await caches.match(OFFLINE, { cacheName: STATIC })) || new Response('Offline', { status: 503, headers: { 'Content-Type': 'text/plain' } });
  }
}

async function image(e) {
  const req = e.request;
  const hit = await caches.match(req, { cacheName: IMAGES });
  if (hit) return hit;
  try {
    const res = await fetch(req);
    if (cacheable(res)) {
      const copy = res.clone();
      e.waitUntil(caches.open(IMAGES).then((c) => c.put(req, copy)).then(() => trim(IMAGES, MAX_IMAGES)));
    }
    return res;
  } catch (err) {
    return (await caches.match(req, { ignoreSearch: true })) || new Response(BLANK, { headers: { 'Content-Type': 'image/svg+xml' } });
  }
}

async function asset(req) {
  const hit = await caches.match(req);
  if (hit) return hit;
  try {
    const res = await fetch(req);
    if (cacheable(res)) {
      const copy = res.clone();
      caches.open(STATIC).then((c) => c.put(req, copy));
    }
    return res;
  } catch (err) {
    return (await caches.match(req, { ignoreSearch: true })) || Response.error();
  }
}
