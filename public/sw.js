// Borealis service worker (1.8; 1.9 adds the offline page): notifications and «Add to Home Screen».
// It does NOT cache pages, data or files of Borealis: after an update the browser always gets the new
// version, and nothing private (projects, sessions, people) is ever stored on the device. The only
// thing kept is the static «Sin conexión» page, shown when a page cannot be loaded at all.
const VERSION = '1.10.0';
const scope = self.registration.scope; // e.g. https://example.org/borealis/
const OFFLINE = 'borealis-offline-' + VERSION;

self.addEventListener('install', (e) => e.waitUntil((async () => {
  try { const c = await caches.open(OFFLINE); await c.add(new Request(new URL('offline.html', scope), { cache: 'reload' })); } catch { /* not essential */ }
  await self.skipWaiting();
})()));
self.addEventListener('activate', (e) => e.waitUntil((async () => {
  for (const k of await caches.keys()) if (k.startsWith('borealis-') && k !== OFFLINE) await caches.delete(k);
  await self.clients.claim();
})()));

// Only full page loads; always from the network (never from a cache). Without network: the static page.
self.addEventListener('fetch', (event) => {
  const r = event.request;
  if (r.mode !== 'navigate' || r.method !== 'GET') return;
  event.respondWith(fetch(r).catch(async () => (await caches.match(new URL('offline.html', scope).href)) || new Response('Sin conexión', { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } })));
});

async function pending() {
  try {
    const r = await fetch(new URL('api/push/pending', scope), { credentials: 'include', headers: { Accept: 'application/json', 'X-Requested-With': 'fetch' } });
    if (r.ok) { const d = await r.json(); if (d.items?.length) return d.items; }
  } catch { /* offline or signed out */ }
  return [{ title: 'Borealis', body: 'Tienes avisos nuevos.', url: new URL('notifications', scope).href }];
}

self.addEventListener('push', (event) => {
  event.waitUntil((async () => {
    let items = null;
    try { const d = event.data?.json(); if (d?.body) items = [d]; } catch { /* no content */ }
    items ??= await pending();
    for (const n of items.slice(0, 3)) {
      await self.registration.showNotification(n.title || 'Borealis', {
        body: n.body, tag: n.tag || `borealis-${n.id || Date.now()}`, renotify: false,
        icon: new URL('assets/img/app-192.png', scope).href, badge: new URL('assets/img/app-badge.png', scope).href,
        data: { url: n.url || new URL('notifications', scope).href, v: VERSION },
      });
    }
  })());
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = new URL(event.notification.data?.url || '', scope).href;
  event.waitUntil((async () => {
    const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const w of wins) if (w.url.startsWith(scope) && 'focus' in w) { await w.navigate(url).catch(() => {}); return w.focus(); }
    return self.clients.openWindow(url);
  })());
});
