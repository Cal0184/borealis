// Mobile and desktop notifications (1.8) with the browser's standard Web Push.
// Registers the service worker (sw.js, needed also to install Borealis on the Home Screen),
// asks for permission only when the person presses «Activar», and keeps the server informed.
import { api, basePath, onInit, toast } from './core.js?v=1.16.6';

const supported = () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
const isIOS = () => /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
const standalone = () => window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;

function b64ToBytes(s) {
  const b = atob((s + '==='.slice((s.length + 3) % 4)).replace(/-/g, '+').replace(/_/g, '/'));
  return Uint8Array.from(b, (c) => c.charCodeAt(0));
}
const bytesToB64 = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

async function registration() {
  if (!('serviceWorker' in navigator)) return null;
  try { return await navigator.serviceWorker.register(`${basePath}/sw.js`, { scope: `${basePath}/` }); } catch { return null; }
}
if ('serviceWorker' in navigator && window.isSecureContext) registration(); // also enables «Add to Home Screen»

async function current() {
  const reg = await registration();
  return reg ? reg.pushManager.getSubscription() : null;
}

// 1.14 · keep the server in step with this browser, without asking anything:
//  - the browser has a subscription → tell the server once a day (it may have dropped it, or the
//    person's switch was off), so notices keep arriving while Borealis is closed;
//  - permission was given here before but the subscription is gone (browser reset, new keys) →
//    subscribe again silently (no prompt: the permission is already granted).
const store = { get: (k) => { try { return localStorage.getItem(k); } catch { return null; } }, set: (k, v) => { try { localStorage.setItem(k, v); } catch { /* private mode */ } } };
const subBody = (sub) => ({ endpoint: sub.endpoint, p256dh: bytesToB64(sub.getKey('p256dh')), auth: bytesToB64(sub.getKey('auth')), device: deviceName() });
async function sync() {
  if (!window.isSecureContext || !supported() || Notification.permission !== 'granted') return;
  try {
    const reg = await registration();
    if (!reg) return;
    await navigator.serviceWorker.ready;
    let sub = await reg.pushManager.getSubscription();
    // 1.16: the permission is the person's choice. With it granted, this device gets notices unless
    // the person turned them off here («Desactivar» stores '0'); before 1.16 only a device where
    // «Activar» had been pressed earlier was re-linked, so a new browser stayed silent.
    if (!sub && store.get('borealis-push') !== '0') {
      const { key } = await api('/api/push/key', { method: 'GET' });
      sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToBytes(key) });
      store.set('borealis-push-sync', '');
    }
    if (!sub) return;
    periodic(reg);
    const day = new Date().toISOString().slice(0, 10) + '|' + sub.endpoint.slice(-24);
    if (store.get('borealis-push-sync') === day) return;
    await api('/api/push/subscription', { body: { ...subBody(sub), sync: 1 } });
    store.set('borealis-push-sync', day);
    store.set('borealis-push', '1');
  } catch { /* offline or signed out: next visit */ }
}
sync();

// 1.16 · ask the browser to wake Borealis up now and then, even closed (installed app on Chrome,
// Edge or Android). Each wake-up calls cron/ping, which keeps the engine and the job running.
// Where the browser does not offer it (Firefox, Safari, a tab that is not installed) nothing happens.
async function periodic(reg) {
  try {
    if (!('periodicSync' in reg)) return;
    const st = await navigator.permissions.query({ name: 'periodic-background-sync' });
    if (st.state !== 'granted') return;
    await reg.periodicSync.register('borealis-ping', { minInterval: 15 * 60 * 1000 });
  } catch { /* not available here */ }
}

// 1.14 · a short invitation (only on Inicio) the first time, while the browser has not been asked.
onInit((root) => {
  root.querySelectorAll?.('[data-push-nudge]').forEach((el) => {
    if (el.dataset.ready) return;
    el.dataset.ready = '1';
    // 1.16: «Ahora no» hides it for 7 days (before: forever, and the person never saw it again)
    const later = Number(store.get('borealis-push-nudge-until') || 0);
    const show = window.isSecureContext && supported() && Notification.permission === 'default' && Date.now() > later;
    el.hidden = !show;
    el.querySelector('[data-push-nudge-on]')?.addEventListener('click', async () => {
      const box = { querySelector: () => null };
      el.hidden = true;
      await enable(box);
    });
    el.querySelector('[data-push-nudge-off]')?.addEventListener('click', () => { store.set('borealis-push-nudge-until', String(Date.now() + 7 * 86400000)); el.hidden = true; });
  });
});

async function paint(box) {
  const st = box.querySelector('[data-push-status]');
  const on = box.querySelector('[data-push-on]');
  const off = box.querySelector('[data-push-off]');
  const test = box.querySelector('[data-push-test]');
  const ios = box.querySelector('[data-push-ios]');
  const show = (a, b, c) => { on.hidden = !a; off.hidden = !b; test.hidden = !c; };
  if (!window.isSecureContext) { st.textContent = 'Las notificaciones necesitan que Borealis se abra con https://.'; show(0, 0, 0); return; }
  if (!supported()) {
    st.textContent = isIOS() && !standalone() ? 'En iPhone o iPad hay que añadir Borealis a la pantalla de inicio primero.' : 'Este navegador no admite notificaciones web.';
    ios.hidden = !isIOS(); show(0, 0, 0); return;
  }
  if (Notification.permission === 'denied') { st.textContent = 'Bloqueaste las notificaciones para Borealis. Permítelas en los ajustes del sitio de tu navegador y vuelve a intentarlo.'; show(0, 0, 0); return; }
  const sub = await current();
  if (sub) { st.textContent = 'Activadas en este dispositivo.'; show(0, 1, 1); } else { st.textContent = 'Desactivadas en este dispositivo.'; show(1, 0, box.dataset.hasDevices === '1'); }
  ios.hidden = !(isIOS() && !standalone());
}

async function enable(box) {
  try {
    const perm = await Notification.requestPermission(); // only after the person presses the button
    if (perm !== 'granted') { toast('No se activaron: el navegador no dio permiso.', { type: 'error' }); return paint(box); }
    const { key } = await api('/api/push/key', { method: 'GET' });
    const reg = await registration();
    if (!reg) throw new Error('No se pudo preparar el servicio de notificaciones del navegador.');
    await navigator.serviceWorker.ready;
    let sub = await reg.pushManager.getSubscription();
    sub ??= await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToBytes(key) });
    await api('/api/push/subscription', { body: subBody(sub) });
    store.set('borealis-push', '1');
    periodic(reg);
    const flag = box.querySelector('[data-push-flag]');
    if (flag) flag.value = '1';
    const cats = box.querySelector('[data-push-cats]');
    if (cats) cats.hidden = false;
    if (box.dataset) box.dataset.hasDevices = '1';
    // the server already turned the switch on (1.14); saving the form only keeps the page in step
    if (flag) box.closest?.('form')?.requestSubmit();
    toast('Notificaciones activadas en este dispositivo');
  } catch (e) {
    toast(e.message || 'No se pudieron activar las notificaciones.', { type: 'error' });
  }
  if (box.dataset) paint(box);
}

async function disable(box) {
  const sub = await current();
  try {
    await api('/api/push/unsubscribe', { body: { endpoint: sub?.endpoint || '' } });
    await sub?.unsubscribe();
    store.set('borealis-push', '0');
    toast('Notificaciones desactivadas en este dispositivo');
  } catch (e) { toast(e.message, { type: 'error' }); }
  paint(box);
}

function deviceName() {
  const ua = navigator.userAgent;
  const os = /Android/.test(ua) ? 'Android' : isIOS() ? 'iPhone/iPad' : /Windows/.test(ua) ? 'Windows' : /Mac/.test(ua) ? 'Mac' : /Linux/.test(ua) ? 'Linux' : 'Otro';
  const br = /Edg\//.test(ua) ? 'Edge' : /Firefox\//.test(ua) ? 'Firefox' : /Chrome\//.test(ua) ? 'Chrome' : /Safari\//.test(ua) ? 'Safari' : 'Navegador';
  return `${br} · ${os}`;
}

document.addEventListener('click', (e) => {
  const box = e.target.closest('[data-push-box]');
  if (!box) return;
  if (e.target.closest('[data-push-on]')) enable(box);
  else if (e.target.closest('[data-push-off]')) disable(box);
  else if (e.target.closest('[data-push-test-closed]')) api('/api/push/test', { body: { delay: 30 } }).then((d) => toast(d.message, { timeout: 12000 })).catch((err) => toast(err.message, { type: 'error' }));
  else if (e.target.closest('[data-push-test]')) api('/api/push/test').then((d) => toast(d.message)).catch((err) => toast(err.message, { type: 'error' }));
});
function paintAll(root = document) {
  root.querySelectorAll('[data-push-box]').forEach(paint);
  root.querySelectorAll('[data-push-state]').forEach(async (el) => {
    if (!supported()) { el.textContent = 'No disponible aquí'; return; }
    el.textContent = (await current()) ? 'Activadas aquí' : 'Desactivadas aquí';
  });
}
onInit(paintAll); // a saved form re-renders its section: paint the new copy too

// 1.9 · «Borealis como aplicación»: install button where the browser offers it (Chrome, Edge,
// Android), instructions for iPhone/iPad, nothing when it is already installed.
let installEvent = null;
window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  installEvent = e;
  document.querySelectorAll('[data-pwa-box]').forEach(paintInstall);
});
window.addEventListener('appinstalled', () => { installEvent = null; document.querySelectorAll('[data-pwa-box]').forEach(paintInstall); });
function paintInstall(box) {
  const show = (sel, on) => { const el = box.querySelector(sel); if (el) el.hidden = !on; };
  const done = standalone();
  show('[data-pwa-done]', done);
  show('[data-pwa-install]', !done && !!installEvent);
  show('[data-pwa-ios]', !done && !installEvent && isIOS());
  show('[data-pwa-manual]', !done && !installEvent && !isIOS());
}
onInit((root) => {
  const box = root.matches?.('[data-pwa-box]') ? root : root.querySelector?.('[data-pwa-box]');
  if (!box || box.dataset.ready) return;
  box.dataset.ready = '1';
  paintInstall(box);
  box.querySelector('[data-pwa-install]')?.addEventListener('click', async () => {
    if (!installEvent) return;
    installEvent.prompt();
    const { outcome } = await installEvent.userChoice;
    installEvent = null;
    paintInstall(box);
    if (outcome === 'accepted') toast('Borealis se instaló en este dispositivo');
  });
});

// 1.16.2 · Administración › Correo: a provider fills server, port and encryption
document.addEventListener('change', (e) => {
  const sel = e.target.closest?.('[data-mail-preset]');
  if (!sel) return;
  const o = sel.selectedOptions[0];
  const form = sel.closest('form');
  if (!o?.value || !form) return;
  if (o.dataset.host) form.elements.host.value = o.dataset.host;
  form.elements.port.value = o.dataset.port;
  const enc = form.querySelector('[data-mail-enc]');
  if (enc) enc.value = o.dataset.enc;
  form.elements.transport.value = 'smtp';
});
document.addEventListener('change', (e) => {
  if (e.target.matches?.('.mail-form [name="port"]')) { const enc = e.target.form.querySelector('[data-mail-enc]'); if (enc) enc.value = e.target.value === '465' ? 'ssl' : 'tls'; }
});
