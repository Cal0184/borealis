// Borealis service worker (1.8; 1.9 adds the offline page): notifications and «Add to Home Screen».
// It does NOT cache pages, data or files of Borealis: after an update the browser always gets the new
// version, and nothing private (projects, sessions, people) is ever stored on the device. The only
// thing kept is the static «Sin conexión» page, shown when a page cannot be loaded at all.
const VERSION = '1.15.0';
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
    // 1.15: open pages update their bell at once (notify-live.js), whatever they are showing
    for (const w of await self.clients.matchAll({ type: 'window', includeUncontrolled: true })) w.postMessage({ type: 'borealis-push' });
  })());
});

// 1.15: does this window have unsaved changes? Asked before following a notice; a page that does not
// answer in time (frozen by a dialog, or an old version) is treated as «yes», so nothing is lost.
function hasUnsaved(w) {
  return new Promise((resolve) => {
    const ch = new MessageChannel();
    const t = setTimeout(() => resolve(true), 700);
    ch.port1.onmessage = (e) => { clearTimeout(t); resolve(!!e.data?.dirty); };
    try { w.postMessage({ type: 'borealis-dirty?' }, [ch.port2]); } catch { clearTimeout(t); resolve(true); }
  });
}

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = new URL(event.notification.data?.url || '', scope).href;
  event.waitUntil((async () => {
    const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    // a window of Borealis without unsaved changes goes to the notice; one with unsaved changes stays
    // as it is and the notice opens in a new window (before 1.15 it navigated away from the form)
    for (const w of wins) {
      if (!w.url.startsWith(scope) || !('focus' in w)) continue;
      if (await hasUnsaved(w)) break;
      const moved = await w.navigate(url).catch(() => null);
      if (moved) return moved.focus();
      break;
    }
    return self.clients.openWindow(url);
  })());
});

// 1.14 · the browser replaced the subscription by itself (expired keys, browser update): subscribe
// again with the same server key and tell Borealis, proving it is the same device with the old
// endpoint and its old «auth» secret. Works with nobody signed in; if it fails, the next visit
// re-links the device (push.js · sync).
self.addEventListener('pushsubscriptionchange', (event) => {
  event.waitUntil((async () => {
    const old = event.oldSubscription;
    const b64 = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    const key = old?.options?.applicationServerKey;
    if (!old || !key) return;
    const sub = event.newSubscription || await self.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
    await fetch(new URL('api/push/renew', scope), {
      method: 'POST', credentials: 'omit', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ old_endpoint: old.endpoint, old_auth: b64(old.getKey('auth')), endpoint: sub.endpoint, p256dh: b64(sub.getKey('p256dh')), auth: b64(sub.getKey('auth')) }),
    }).catch(() => {});
  })());
});
