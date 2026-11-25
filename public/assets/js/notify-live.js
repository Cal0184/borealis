// 1.15 · Notices while a page stays open.
//
// Before 1.15 the bell only changed when a page loaded. Someone working in a form (where leaving
// asks for confirmation) never loaded a page, so new notices did not show up until they left; and a
// window.confirm() froze the page altogether. Now:
//  - the page asks /api/notifications/status every minute while visible, at once when it becomes
//    visible again, when the connection returns and when the service worker received a push;
//  - the request is marked as background (it does not keep the session alive) and never navigates:
//    if the session ended, a banner says so and the form stays as it is;
//  - new notices show as a toast only when this device does not get system notifications (to avoid
//    showing the same notice twice); the badge always updates;
//  - the service worker asks the page whether it has unsaved changes before following a notice.
import { appUrl, meta, toast } from './core.js?v=1.16.3';

const POLL_MS = 60000;
let latest = null;
let timer = null;
let stopped = false;
let busy = false;

const hasUnsaved = () => !!document.querySelector('dialog[open] [data-dirty], #drawer.open [data-dirty], form[data-dirty]:not([hidden])');
const systemNotices = () => 'Notification' in window && Notification.permission === 'granted' && 'serviceWorker' in navigator && !!navigator.serviceWorker.controller;

function paint(unread) {
  const slot = document.getElementById('topbar-notif');
  if (slot) slot.innerHTML = unread ? `<span class="badge success notif-badge" data-unread="${unread}">${unread > 99 ? '99+' : unread}</span>` : '';
  document.querySelectorAll('[data-nav-unread]').forEach((el) => { el.textContent = unread ? String(unread) : ''; el.hidden = !unread; });
}

function sessionEnded() {
  stopped = true;
  if (document.getElementById('session-ended')) return;
  const bar = document.createElement('div');
  bar.id = 'session-ended';
  bar.className = 'session-banner';
  bar.setAttribute('role', 'status');
  bar.innerHTML = 'Tu sesión terminó. Lo que escribiste sigue aquí: <a target="_blank" rel="noopener">inicia sesión en otra pestaña</a> y vuelve para guardar.';
  bar.querySelector('a').href = appUrl('/login');
  document.body.prepend(bar);
  // 1.16: a banner can sit behind an open dialog; the notice (above dialogs) says it too
  if (document.querySelector('dialog[open]')) toast('Tu sesión terminó. Lo que escribiste sigue aquí: inicia sesión en otra pestaña y vuelve para guardar.', { type: 'error', timeout: 15000 });
  // when the person signs in elsewhere, this tab notices on the next focus
  window.addEventListener('focus', () => { stopped = false; check(true); }, { once: true });
}

export async function check(force = false) {
  if (busy || (stopped && !force)) return;
  if (!force && document.visibilityState !== 'visible') return;
  busy = true;
  try {
    const res = await fetch(appUrl('/api/notifications/status', latest ? { after: latest } : {}), {
      credentials: 'same-origin', cache: 'no-store',
      headers: { Accept: 'application/json', 'X-Requested-With': 'fetch', 'X-Borealis-Background': '1', 'X-CSRF-Token': meta('csrf-token') },
    });
    if (res.status === 401) { sessionEnded(); return; }
    if (!res.ok) return;
    const d = await res.json();
    document.getElementById('session-ended')?.remove();
    paint(d.unread || 0);
    if (latest !== null && d.items?.length && !systemNotices()) {
      for (const n of d.items.slice(0, 2).reverse()) toast(`${n.title}: ${n.body}`, { link: { href: n.url, label: 'Ver' }, timeout: 8000 });
      chime();
    }
    latest = Math.max(latest || 0, d.latest || 0);
  } catch { /* offline: the 'online' event tries again */ } finally { busy = false; }
}

// 1.16.1 · a short two-note chime for a new notice shown inside an open page (the system
// notification already has the device's own sound). Off when the person turned sound off. Browsers
// only allow it after the person has clicked or typed on the page once; otherwise it stays silent.
let audio = null;
function chime() {
  if (meta('borealis-sound') !== '1') return;
  try {
    audio ??= new (window.AudioContext || window.webkitAudioContext)();
    if (audio.state === 'suspended') audio.resume();
    const t = audio.currentTime;
    [[880, 0], [1318.5, 0.13]].forEach(([f, d]) => {
      const o = audio.createOscillator(); const g = audio.createGain();
      o.type = 'sine'; o.frequency.value = f;
      g.gain.setValueAtTime(0.0001, t + d); g.gain.exponentialRampToValueAtTime(0.18, t + d + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, t + d + 0.35);
      o.connect(g).connect(audio.destination); o.start(t + d); o.stop(t + d + 0.4);
    });
  } catch { /* no audio here */ }
}

function schedule() {
  clearInterval(timer);
  timer = setInterval(() => check(), POLL_MS);
}

if (document.querySelector('#topbar-notif')) {
  check(true);
  schedule();
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') check(); });
  window.addEventListener('online', () => { stopped = false; check(true); });
  navigator.serviceWorker?.addEventListener('message', (e) => {
    const m = e.data || {};
    if (m.type === 'borealis-push') check(true);
    // the worker asks before following a notice: with unsaved changes it opens a new tab instead
    if (m.type === 'borealis-dirty?' && e.ports?.[0]) e.ports[0].postMessage({ dirty: hasUnsaved() });
  });
}
