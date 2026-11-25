// Borealis core: server communication, feedback and region refresh.
// Every change goes to the server first; the UI only reports success when the server confirms it.

export const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.content ?? '';
export const basePath = meta('base-path');
const pretty = meta('pretty-urls') !== '0';

/** Build an application URL that works with and without URL rewriting. */
export function appUrl(path, params = {}) {
  const [p, qs] = path.split('?');
  const query = new URLSearchParams(qs || '');
  for (const [k, v] of Object.entries(params)) if (v !== undefined && v !== null) query.set(k, v);
  if (pretty) {
    const q = query.toString();
    return basePath + p + (q ? '?' + q : '');
  }
  const q = new URLSearchParams({ r: p });
  for (const [k, v] of query) q.append(k, v);
  return basePath + '/index.php?' + q.toString();
}

export class ApiError extends Error {
  constructor(message, status = 0, errors = {}) {
    super(message);
    this.status = status;
    this.errors = errors || {};
  }
}

/** JSON request to the internal API. Throws ApiError with the server's reason. */
export async function api(path, { method = 'POST', body = undefined, formData = null, keepalive = false } = {}) {
  const headers = { 'X-CSRF-Token': meta('csrf-token'), Accept: 'application/json', 'X-Requested-With': 'fetch' };
  const opts = { method, headers, credentials: 'same-origin' };
  if (keepalive) opts.keepalive = true; // survives leaving the page right after
  if (formData) {
    opts.body = formData;
  } else if (body !== undefined && method !== 'GET') {
    headers['Content-Type'] = 'application/json';
    opts.body = JSON.stringify(body);
  }
  let res;
  try {
    res = await fetch(appUrl(path), opts);
  } catch {
    throw new ApiError('No hay conexión con el servidor. Tus datos no se guardaron; inténtalo de nuevo.');
  }
  let data = null;
  try { data = await res.json(); } catch { /* non-JSON */ }
  if (res.status === 401) {
    toast('Tu sesión terminó. Te llevamos a iniciar sesión…', { type: 'error' });
    setTimeout(() => { location.href = appUrl('/login', { next: location.pathname + location.search }); }, 1200);
    throw new ApiError('Sesión terminada', 401);
  }
  if (!res.ok || !data || data.ok === false) {
    const msg = data?.error || (res.status === 413 ? 'El archivo es demasiado grande para el servidor.' : `El servidor rechazó la operación (${res.status}).`);
    throw new ApiError(msg, res.status, data?.errors);
  }
  return data;
}

// ---------- Toasts ----------
export function toast(message, { type = 'success', link = null, timeout = 3800 } = {}) {
  if (!message) return;
  const box = document.getElementById('toasts');
  if (!box) return;
  const el = document.createElement('div');
  el.className = 'toast' + (type === 'error' ? ' error' : '');
  el.setAttribute('role', type === 'error' ? 'alert' : 'status');
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('class', 'icon');
  const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
  const sprite = document.querySelector('link[rel="stylesheet"]')?.href.replace(/css\/borealis\.css.*$/, 'img/icons.svg') ?? '';
  use.setAttribute('href', `${sprite}#i-${type === 'error' ? 'alert' : 'check-circle'}`);
  svg.appendChild(use);
  el.appendChild(svg);
  const span = document.createElement('span');
  span.textContent = message;
  el.appendChild(span);
  if (link) {
    const a = document.createElement('a');
    a.href = link.href;
    a.textContent = link.label;
    a.style.cssText = 'color:#6EE7B7;font-weight:600';
    if (link.task) a.dataset.openTask = link.task;
    el.appendChild(a);
  }
  const close = document.createElement('button');
  close.type = 'button';
  close.setAttribute('aria-label', 'Cerrar aviso');
  close.textContent = '×';
  const gone = () => { el.remove(); if (!box.children.length) try { box.hidePopover?.(); } catch { /* not shown */ } };
  close.onclick = gone;
  el.appendChild(close);
  box.appendChild(el);
  raiseToasts(box);
  setTimeout(gone, type === 'error' ? Math.max(timeout, 7000) : timeout);
}

// 1.16 · notices must be visible above an open dialog (confirmations, forms). A modal <dialog> sits in
// the browser's «top layer», above any z-index, so before 1.16 a notice that arrived while a
// confirmation was open stayed hidden behind it. The toast box is a popover: showing it again puts it
// on top of the top layer. Browsers without popovers keep the box as before.
function raiseToasts(box) {
  if (typeof box.showPopover !== 'function') return;
  try {
    if (box.matches(':popover-open')) box.hidePopover();
    box.showPopover();
  } catch { /* not supported */ }
}
export { raiseToasts };

// ---------- Motion preference ----------
export function motionAllowed() {
  const pref = document.documentElement.dataset.motion;
  return pref === 'full' && !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/** Discreet completion animation; skipped when motion is reduced or off. */
export function celebrate() {
  if (!motionAllowed()) return;
  const wrap = document.createElement('div');
  wrap.className = 'celebrate';
  wrap.setAttribute('aria-hidden', 'true');
  const burst = document.createElement('div');
  burst.className = 'burst';
  burst.innerHTML = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
  wrap.appendChild(burst);
  const colors = ['#10B981', '#34D399', '#0EA5E9', '#F59E0B', '#A7F3D0'];
  for (let i = 0; i < 14; i++) {
    const s = document.createElement('span');
    s.className = 'spark';
    const a = (Math.PI * 2 * i) / 14;
    const d = 70 + Math.random() * 50;
    s.style.setProperty('--x', `${Math.cos(a) * d}px`);
    s.style.setProperty('--y', `${Math.sin(a) * d}px`);
    s.style.setProperty('--c', colors[i % colors.length]);
    wrap.appendChild(s);
  }
  document.body.appendChild(wrap);
  setTimeout(() => wrap.remove(), 1000);
}

// ---------- Dialogs ----------
export function openDialog(dlg) {
  if (!dlg || dlg.open) return;
  dlg.showModal();
  const first = dlg.querySelector('[autofocus], input:not([type=hidden]):not([disabled]), select, textarea');
  first?.focus();
}

// 1.15: unsaved changes are confirmed inside Borealis (#dlg-confirm), never with window.confirm():
// a native dialog freezes the page (no live notices, no timers) until it is answered.
export function closeDialog(dlg, force = false) {
  if (!dlg?.open) return true;
  if (!force && isDirty(dlg)) {
    confirmAction('Tienes cambios sin guardar. ¿Cerrar de todos modos?', { label: 'Cerrar sin guardar' }).then((yes) => { if (yes) closeDialog(dlg, true); });
    return false;
  }
  dlg.querySelectorAll('form').forEach(clearDirty);
  dlg.close();
  return true;
}

/** Promise-based confirmation for destructive actions. */
export function confirmAction(text, { label = 'Confirmar', style = 'danger' } = {}) {
  const dlg = document.getElementById('dlg-confirm');
  if (!dlg) return Promise.resolve(false); // every page has #dlg-confirm; never fall back to a blocking dialog
  return new Promise((resolve) => {
    dlg.querySelector('[data-confirm-text]').textContent = text;
    const ok = dlg.querySelector('[data-confirm-ok]');
    const cancel = dlg.querySelector('[data-confirm-cancel]');
    ok.textContent = label;
    ok.className = 'btn ' + (style === 'primary' ? 'btn--primary' : 'btn--danger-solid');
    const done = (v) => { ok.onclick = cancel.onclick = dlg.oncancel = null; dlg.close(); resolve(v); };
    ok.onclick = () => done(true);
    cancel.onclick = () => done(false);
    dlg.oncancel = (e) => { e.preventDefault(); done(false); };
    dlg.showModal();
    cancel.focus();
  });
}

// ---------- Dirty tracking ----------
export function markDirty(el) {
  const holder = el.closest('form') || el;
  holder.dataset.dirty = '1';
}
export function clearDirty(el) {
  if (!el) return;
  delete el.dataset.dirty;
  el.querySelectorAll?.('[data-dirty]').forEach((x) => delete x.dataset.dirty);
}
export function isDirty(root) {
  return !!(root.matches?.('[data-dirty]') || root.querySelector('[data-dirty]'));
}

// ---------- Region refresh ----------
// The server renders every view from the database. After a confirmed change we re-fetch the
// current page and swap the marked regions, so names and states stay consistent everywhere
// while the user keeps their screen, filters and scroll position.
let refreshing = null;
let again = false;
const initHooks = [];
export function onInit(fn) { initHooks.push(fn); }
export function runInit(root) { initHooks.forEach((fn) => fn(root)); }

function swapRegions(fromDoc, scope) {
  scope.querySelectorAll('[data-region][id]').forEach((cur) => {
    if (!cur.isConnected) return; // replaced together with its parent region
    if (scope === document && cur.closest('#drawer')) return; // the drawer refreshes from its own source
    const next = fromDoc.getElementById(cur.id);
    if (!next) return;
    if (isDirty(cur)) return; // never wipe what the user is typing
    const openDlg = cur.querySelector('dialog[open]');
    if (openDlg) { // keep an open dialog (and what it shows); refresh once it closes
      openDlg.addEventListener('close', () => refresh(), { once: true });
      return;
    }
    const keep = [...cur.querySelectorAll('[data-keep-scroll][id]')].map((el) => [el.id, el.scrollLeft, el.scrollTop]);
    const focusId = cur.contains(document.activeElement) ? document.activeElement.id : null;
    const openIds = [...cur.querySelectorAll('details[open][id]')].map((d) => d.id); // keep pickers open while choosing
    // Controls being used ([data-live]: open, focused or with unsaved changes) are kept as the
    // very same elements, so a save never closes them, moves the focus or undoes a pending click.
    const live = [...cur.querySelectorAll('[data-live][id]')].filter((el) => el.open || el.dataset.pending || el.contains(document.activeElement));
    cur.innerHTML = next.innerHTML;
    live.forEach((old) => {
      const fresh = document.getElementById(old.id);
      if (!fresh) return;
      if (!old.dataset.pending) syncLive(old, fresh);
      fresh.replaceWith(old);
    });
    openIds.forEach((id) => { const d = document.getElementById(id); if (d) d.open = true; });
    keep.forEach(([id, l, t]) => { const el = document.getElementById(id); if (el) { el.scrollLeft = l; el.scrollTop = t; } });
    if (focusId) document.getElementById(focusId)?.focus({ preventScroll: true });
    paintSaveState();
    runInit(cur);
  });
}

/** Brings a kept control up to date with the server's version without rebuilding it. */
function syncLive(old, fresh) {
  const sOld = old.querySelector(':scope > summary');
  const sNew = fresh.querySelector(':scope > summary');
  if (sOld && sNew) sOld.innerHTML = sNew.innerHTML;
  Object.keys(fresh.dataset).forEach((k) => { if (k !== 'pending') old.dataset[k] = fresh.dataset[k]; });
  fresh.querySelectorAll('input[type=checkbox][value]').forEach((nb) => {
    const ob = old.querySelector(`input[type=checkbox][value="${CSS.escape(nb.value)}"]`);
    if (!ob) return;
    ob.defaultChecked = nb.defaultChecked;
    ob.checked = nb.checked;
    ob.disabled = nb.disabled;
    const ol = ob.closest('label');
    const nl = nb.closest('label');
    if (ol && nl) {
      ol.className = nl.className;
      if (nl.title) ol.title = nl.title; else ol.removeAttribute('title');
      [...ol.childNodes].forEach((n) => { if (n !== ob) n.remove(); });
      [...nl.childNodes].forEach((n) => { if (n !== nb) ol.appendChild(n.cloneNode(true)); });
    }
  });
  fresh.querySelectorAll('select[id]').forEach((ns) => {
    const os = old.querySelector(`#${CSS.escape(ns.id)}`);
    if (os && os !== document.activeElement && !os.dataset.dirty) os.innerHTML = ns.innerHTML;
  });
}

// ---------- Save indicator ----------
// One discreet line near what is being edited: «Guardando…», «Guardado» or «Error al guardar».
let saveNow = { state: '', text: '' };
let saveTimer = null;
let saving = 0;
export function saveState(state, text) {
  if (state === 'saving') saving++;
  else if (saving > 0) saving--;
  if (state !== 'saving' && saving > 0) state = 'saving'; // something else is still on its way
  const label = text || { saving: 'Guardando…', saved: 'Guardado', error: 'Error al guardar' }[state] || '';
  saveNow = { state, text: label };
  clearTimeout(saveTimer);
  paintSaveState();
  if (state === 'saved') saveTimer = setTimeout(() => { saveNow = { state: '', text: '' }; paintSaveState(); }, 2500);
}
function paintSaveState() {
  document.querySelectorAll('[data-save-state]').forEach((el) => {
    el.textContent = saveNow.text;
    el.dataset.state = saveNow.state;
  });
}

async function doRefresh() {
  const x = window.scrollX;
  const y = window.scrollY;
  const res = await fetch(location.href, { headers: { 'X-Borealis': 'partial' }, credentials: 'same-origin' });
  if (res.redirected && /\/login/.test(res.url)) { location.href = res.url; return; }
  const html = await res.text();
  const doc = new DOMParser().parseFromString(html, 'text/html');
  const token = doc.querySelector('meta[name="csrf-token"]')?.content;
  if (token) document.querySelector('meta[name="csrf-token"]').setAttribute('content', token);
  const title = doc.querySelector('title')?.textContent;
  if (title) document.title = title;
  if (!res.ok) {
    // The resource may be gone (e.g. archived or deleted); show the server's page.
    swapRegions(doc, document);
    return;
  }
  swapRegions(doc, document);
  window.scrollTo(x, y);
  const drawer = document.getElementById('drawer');
  if (drawer?.classList.contains('open') && drawer.dataset.src) {
    const r2 = await fetch(drawer.dataset.src, { credentials: 'same-origin' });
    if (r2.ok) {
      const d2 = new DOMParser().parseFromString(`<div id="__d">${await r2.text()}</div>`, 'text/html');
      swapRegions(d2, drawer);
    } else if (r2.status === 404) {
      drawer.dispatchEvent(new CustomEvent('borealis:gone'));
    }
  }
}

export function refresh() {
  if (refreshing) { again = true; return refreshing; }
  refreshing = doRefresh()
    .catch(() => toast('Los cambios se guardaron, pero no pudimos actualizar la vista. Recarga la página.', { type: 'error' }))
    .finally(() => {
      refreshing = null;
      if (again) { again = false; refresh(); }
    });
  return refreshing;
}

/** Common post-success handling shared by forms, buttons and inline edits. */
/** Fills a container with a read-only box holding a message to copy, plus a copy button. */
export function fillShare(slot, text, title) {
  slot.hidden = false;
  slot.innerHTML = '';
  const p = document.createElement('p');
  p.textContent = title || 'Cópialo y envíalo a la persona:';
  const box = document.createElement('textarea');
  box.className = 'textarea share-box';
  box.readOnly = true;
  box.rows = Math.min(6, text.split('\n').length + 1);
  box.defaultValue = text;
  box.value = text;
  box.onclick = () => box.select();
  const copy = document.createElement('button');
  copy.type = 'button';
  copy.className = 'btn btn--primary btn--sm mt';
  copy.textContent = 'Copiar mensaje';
  copy.onclick = () => navigator.clipboard?.writeText(text).then(() => toast('Copiado'), () => { box.select(); toast('Selecciona el texto y cópialo'); });
  slot.append(p, box, copy);
}

export function afterSuccess(data) {
  if (data?.share_dialog && data.share_text) {
    const dlg = document.getElementById('dlg-share');
    if (dlg) { fillShare(dlg.querySelector('[data-share-slot]'), data.share_text, data.share_title); openDialog(dlg); }
  }
  if (data?.celebrate) celebrate();
  const summary = data?.project_complete ? () => showProjectSummary(data.project_complete) : null;
  if (data?.completed && showWrapUp(data.completed, summary)) return;
  summary?.();
}

/** After finishing a task: offer a closing comment and the hours it took (optional). */
function showWrapUp(t, then) {
  const dlg = document.getElementById('dlg-wrapup');
  if (!dlg || dlg.open) return false;
  dlg.querySelector('[name=task_id]').value = t.id;
  dlg.querySelector('[data-wrapup-text]').textContent = `${t.key} · ${t.title}. ¿Quieres dejar un comentario de cierre o registrar las horas?`;
  const h = Math.floor(t.logged / 60), m = t.logged % 60;
  dlg.querySelector('[data-wrapup-logged]').textContent = t.logged ? `Ya hay ${h ? h + ' h ' : ''}${m ? m + ' min ' : ''}registradas en esta tarea.` : '';
  if (then) dlg.addEventListener('close', () => setTimeout(then, 150), { once: true });
  setTimeout(() => openDialog(dlg), motionAllowed() ? 500 : 0);
  return true;
}

function showProjectSummary(p) {
  const dlg = document.getElementById('dlg-summary');
  if (!dlg) return;
  dlg.querySelector('[data-summary-text]').textContent = `Todas las tareas de «${p.name}» están finalizadas.`;
  dlg.querySelector('[data-summary-detail]').textContent = `${p.total} ${p.total === 1 ? 'tarea completada' : 'tareas completadas'} en equipo. Puedes marcar el proyecto como completado para conservar su historial, o seguir añadiendo trabajo.`;
  const btn = dlg.querySelector('[data-summary-complete]');
  btn.hidden = p.state === 'completed';
  btn.onclick = async () => {
    try {
      await api(`/api/projects/${p.id}`, { method: 'PATCH', body: { state: 'completed' } });
      dlg.close();
      toast('Proyecto completado');
      refresh();
    } catch (e) { toast(e.message, { type: 'error' }); }
  };
  setTimeout(() => openDialog(dlg), motionAllowed() ? 700 : 0);
}
