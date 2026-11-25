// Task detail drawer: opens a task over the current view without losing filters or scroll.
import { appUrl, isDirty, runInit, onInit, toast, refresh, confirmAction } from './core.js?v=1.16.5';

const drawer = () => document.getElementById('drawer');
let lastOpener = null;

function setParam(key) {
  const u = new URL(location.href);
  if (key) u.searchParams.set('task', key); else u.searchParams.delete('task');
  history.replaceState(history.state, '', u);
}

export async function openTask(key, opener = null) {
  // On the full task page, links to other tasks simply navigate.
  if (document.querySelector('#page [data-task-detail]') && !drawer()?.classList.contains('open')) {
    location.href = appUrl('/t/' + key);
    return;
  }
  const d = drawer();
  if (!d) { location.href = appUrl('/t/' + key); return; }
  if (d.classList.contains('open') && isDirty(d)) {
    if (await confirmAction('Tienes cambios sin guardar en esta tarea. ¿Abrir la otra de todos modos?', { label: 'Abrir sin guardar' })) { closeDrawer(true, true); openTask(key, opener); }
    return;
  }
  const src = appUrl('/t/' + encodeURIComponent(key), { drawer: 1 });
  const body = document.getElementById('drawer-body');
  body.innerHTML = '<div class="drawer-loading" role="status">Cargando…</div>';
  d.classList.add('open');
  d.setAttribute('aria-hidden', 'false');
  document.querySelector('.drawer-scrim')?.classList.add('open');
  document.body.classList.add('drawer-open');
  lastOpener = opener || document.activeElement;
  try {
    const res = await fetch(src, { credentials: 'same-origin' });
    if (res.status === 401) { location.href = appUrl('/login', { next: location.pathname + location.search }); return; }
    if (!res.ok) throw new Error(res.status === 404 ? 'La tarea ya no existe o no tienes acceso a ella.' : 'No se pudo abrir la tarea.');
    body.innerHTML = await res.text();
    d.dataset.src = src;
    setParam(key);
    runInit(body);
    applySavedTab(body);
    body.querySelector('.title-input, [data-drawer-close]')?.focus({ preventScroll: true });
  } catch (err) {
    closeDrawer(true);
    toast(err.message, { type: 'error' });
  }
}

/** Closes the drawer. Returns false if the user chose to keep unsaved changes. */
export function closeDrawer(force = false, switching = false) {
  const d = drawer();
  if (!d?.classList.contains('open')) {
    // Full task page: the task is gone, go back to its project.
    if (force && document.querySelector('#page [data-task-detail]')) {
      const back = document.querySelector('#page .breadcrumbs a');
      location.href = back ? back.href : appUrl('/');
    }
    return true;
  }
  if (!force && isDirty(d)) {
    confirmAction('Tienes cambios sin guardar en esta tarea. ¿Cerrar de todos modos?', { label: 'Cerrar sin guardar' }).then((yes) => { if (yes) closeDrawer(true, switching); });
    return false;
  }
  d.classList.remove('open');
  d.setAttribute('aria-hidden', 'true');
  delete d.dataset.src;
  document.querySelector('.drawer-scrim')?.classList.remove('open');
  document.body.classList.remove('drawer-open');
  document.getElementById('drawer-body').innerHTML = '';
  if (!switching) {
    setParam(null);
    if (lastOpener?.isConnected) lastOpener.focus({ preventScroll: true });
  }
  return true;
}

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && drawer()?.classList.contains('open') && !document.querySelector('dialog[open], .menu.open, .mention-pop')) {
    closeDrawer();
  }
});

document.addEventListener('borealis:gone', () => {
  closeDrawer(true);
  toast('Esta tarea ya no está disponible.', { type: 'error' });
  refresh();
});

// Deep link: ?task=KEY opens the drawer on load.
export function openFromUrl() {
  const key = new URL(location.href).searchParams.get('task');
  if (key && /^[A-Z0-9]{1,12}-\d+$/i.test(key)) openTask(key);
}

// 1.15 · task activity tabs (Comentarios · Horas · Seguimiento · Historial), drawer and full page
function selectTab(tab, focus = false) {
  const box = tab.closest('[data-td-tabs]');
  if (!box) return;
  box.querySelectorAll('[data-td-tab]').forEach((t) => {
    const on = t === tab;
    t.setAttribute('aria-selected', on ? 'true' : 'false');
    t.tabIndex = on ? 0 : -1;
  });
  box.querySelectorAll('[data-td-pane]').forEach((p) => { p.hidden = p.dataset.tdPane !== tab.dataset.tdTab; });
  if (focus) tab.focus();
}
// the last tab chosen stays chosen for the next task (whoever logs hours keeps «Horas» at hand)
let currentTab = null;
function rememberTab(name) { currentTab = name; try { sessionStorage.setItem('borealis.tdTab', name); } catch { /* private mode */ } }
export function applySavedTab(root = document) {
  let name = currentTab;
  if (!name) try { name = sessionStorage.getItem('borealis.tdTab'); } catch { /* private mode */ }
  const tab = name && root.querySelector(`[data-td-tabs] [data-td-tab="${name}"]`);
  if (tab) selectTab(tab);
}
document.addEventListener('click', (e) => {
  const tab = e.target instanceof Element ? e.target.closest('[data-td-tab]') : null;
  if (tab) { selectTab(tab); rememberTab(tab.dataset.tdTab); }
});
document.addEventListener('keydown', (e) => {
  const tab = e.target instanceof Element ? e.target.closest('[data-td-tab]') : null;
  if (!tab || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) return;
  const all = [...tab.parentElement.querySelectorAll('[data-td-tab]')];
  const i = all.indexOf(tab);
  const next = e.key === 'Home' ? all[0] : e.key === 'End' ? all[all.length - 1] : all[(i + (e.key === 'ArrowRight' ? 1 : all.length - 1)) % all.length];
  e.preventDefault();
  selectTab(next, true);
  rememberTab(next.dataset.tdTab);
});
applySavedTab();
// a refresh after saving re-renders the task: keep the tab the person was using
onInit((root) => { if (root.querySelector?.('[data-td-tabs]')) applySavedTab(root); });
