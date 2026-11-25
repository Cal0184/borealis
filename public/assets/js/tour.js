// Guided tutorial (1.7.1): it really moves through the pages of Borealis and points at their controls.
// Route: Inicio → Proyectos → Tareas → Sprints → Calendario → Configuración.
//
// How the progress survives a change of page (the cause of the 1.6/1.7 failure was elsewhere — old
// modules kept by the browser cache, see bin/stamp-assets.php — but the tutorial no longer depends
// on a single mechanism):
//   1. the link to the next page carries «?tour=N», read as soon as the page opens;
//   2. the tab keeps {step} in sessionStorage, in case the address loses the parameter;
//   3. the server keeps the step in the person's preferences, to resume another day or device.
// While it is open, a transparent layer covers the page: clicks cannot change real data, only the
// tutorial's own buttons work. Anterior, Siguiente, Omitir and Reiniciar are always available.
import { api, appUrl } from './core.js?v=1.16.2';

const html = document.documentElement;
const project = Number(html.dataset.tourProject) || 0;
const P = (path) => (project ? `/projects/${project}${path}` : null);
const KEY = 'borealis.tour';

// page: where the step lives (null: no project yet, the example is shown wherever the person is).
// demo: what the step shows when the real control is not on screen.
export const STEPS = [
  { page: '/', section: 'Inicio', title: 'Te damos la bienvenida a Borealis', text: 'Te acompañamos por las páginas reales: Inicio, Proyectos, Tareas, Sprints, Calendario y Configuración. Mientras dure, la página no se puede modificar; solo miramos.' },
  { page: '/', section: 'Inicio', target: '[data-tour="new-task"]', title: 'Nueva tarea', text: 'Este botón está en todas las pantallas. Solo necesita un título; el resto (responsable, sprint, tiempo estimado) es opcional.' },
  { page: '/', section: 'Inicio', target: '[data-tour="notifications"]', fallback: '[data-tour="account"]', title: 'Notificaciones', text: 'La campana avisa de asignaciones, reuniones próximas y sprints. El número indica cuántas tienes sin leer.' },
  { page: '/projects', section: 'Proyectos', target: '[data-tour="projects-filters"]', fallback: '[data-tour="projects"]', title: 'Proyectos', text: 'Los proyectos en los que participas. Busca, filtra y entra a uno para ver su tablero, lista, sprints y archivos.' },
  { page: '/tasks', section: 'Tareas', target: '[data-tour="tasks-tabs"]', title: 'Tareas', text: '«Mis tareas» reúne lo que te asignaron en los proyectos y tus tareas personales (privadas). «Equipo» muestra las de tus proyectos y «Completadas», lo terminado.' },
  { page: '/tasks', section: 'Tareas', target: '[data-tour="tasks-add"]', title: 'Tareas personales', text: 'Escribe y pulsa Enter para añadir una tarea personal. Admite subtareas, prioridad, fecha límite y notas.' },
  { page: P('/sprints'), section: 'Sprints', target: '[data-tour="sprint-new"]', fallback: '[data-tour="sprints"]', title: 'Sprints', text: 'Un sprint es un periodo de trabajo. Se crea con lo básico (nombre, objetivo y fechas) y después se planifica: tareas, responsables y horas.',
    demo: '<div class="tour-demo" aria-hidden="true"><span class="tour-demo-card">Sprint 4 · 3–14 nov</span><span class="tour-demo-bar"><i style="width:60%"></i></span></div><p class="small muted">Ejemplo de un sprint en curso. Se crean en la pestaña Sprints de cada proyecto.</p>' },
  { page: '/calendar', section: 'Calendario', target: '[data-tour="cal-views"]', title: 'Calendario', text: 'Día, semana o mes. Ves eventos, reuniones, vencimientos de tareas y el inicio y cierre de los sprints.' },
  { page: '/calendar', section: 'Calendario', target: '[data-tour="cal-scope"]', fallback: '[data-tour="cal-list"]', title: 'Personal y de equipo', text: 'Tu calendario personal solo lo ves tú. Los de equipo se comparten con sus integrantes. Alterna entre ellos o míralos juntos, cada uno con su color.' },
  { page: '/calendar', section: 'Calendario', target: '[data-tour="cal-new"]', title: 'Eventos y reuniones', text: 'Crea un evento eligiendo en qué calendario va. Las reuniones admiten participantes, enlace de videollamada y repetición.' },
  { page: '/profile', section: 'Configuración', target: '#apariencia', title: 'Configuración', text: 'En tu perfil eliges el tema, qué notificaciones recibir y, si quieres, las conexiones con otras herramientas. Todas son opcionales.' },
  { page: '/profile', section: 'Configuración', target: '#ayuda', title: '¿Lo repasamos otra vez?', text: 'Desde aquí, o desde el menú de tu cuenta, puedes repetir este tutorial cuando quieras.' },
];

let state = null;

const store = {
  get() { try { const v = JSON.parse(sessionStorage.getItem(KEY) || 'null'); return v && Number.isInteger(v.i) ? v : null; } catch { return null; } },
  set(i) { try { sessionStorage.setItem(KEY, JSON.stringify({ i, at: Date.now() })); } catch { /* private mode */ } },
  clear() { try { sessionStorage.removeItem(KEY); } catch { /* ignore */ } },
};
const valid = (i) => Number.isInteger(i) && i >= 0 && i < STEPS.length;

function visible(el) {
  if (!el) return false;
  const r = el.getBoundingClientRect();
  return r.width > 0 && r.height > 0 && getComputedStyle(el).visibility !== 'hidden';
}

function findTarget(step) {
  for (const sel of [step.target, step.fallback]) {
    if (!sel) continue;
    const el = [...document.querySelectorAll(sel)].find(visible);
    if (el) return el;
  }
  return null;
}

/** The page has finished loading (images and regions included). */
function loaded() {
  return document.readyState === 'complete' ? Promise.resolve() : new Promise((r) => window.addEventListener('load', r, { once: true }));
}

/** Waits (up to ~4 s after the page loads) for the step's control. */
async function waitTarget(step) {
  await loaded();
  if (!step.target) return null;
  const t0 = Date.now();
  return new Promise((resolve) => {
    const look = () => {
      const el = findTarget(step);
      if (el || Date.now() - t0 > 4000) resolve(el);
      else setTimeout(look, 100);
    };
    look();
  });
}

function onPage(page) {
  if (!page) return true;
  const want = new URL(appUrl(page), location.href);
  const here = new URL(location.href);
  return want.pathname === here.pathname && (want.searchParams.get('r') || '') === (here.searchParams.get('r') || '');
}

function build() {
  const root = document.createElement('div');
  root.className = 'tour';
  root.setAttribute('data-tour-root', '');
  root.innerHTML = `
    <div class="tour-block" aria-hidden="true"></div>
    <div class="tour-spot" aria-hidden="true"></div>
    <div class="tour-card" role="dialog" aria-modal="true" aria-labelledby="tour-title" aria-describedby="tour-text">
      <div class="tour-step muted small" aria-live="polite"></div>
      <div class="tour-dots" aria-hidden="true"></div>
      <h2 id="tour-title"></h2>
      <p id="tour-text"></p>
      <div class="tour-demo-slot"></div>
      <div class="tour-actions">
        <button type="button" class="btn btn--ghost btn--sm" data-tour-skip>Omitir</button>
        <button type="button" class="btn btn--ghost btn--sm" data-tour-restart hidden>Reiniciar</button>
        <span class="tour-grow"></span>
        <button type="button" class="btn btn--sm" data-tour-prev>Anterior</button>
        <button type="button" class="btn btn--primary btn--sm" data-tour-next>Siguiente</button>
      </div>
    </div>`;
  document.body.appendChild(root);
  root.addEventListener('click', (e) => {
    if (e.target.closest('[data-tour-next]')) go(1);
    else if (e.target.closest('[data-tour-prev]')) go(-1);
    else if (e.target.closest('[data-tour-skip]')) end('skipped');
    else if (e.target.closest('[data-tour-restart]')) goTo(0);
    else if (e.target.closest('[data-tour-continue]')) navigate(state.i);
  });
  // The layer under the card swallows clicks: the tutorial never changes real information.
  root.querySelector('.tour-block').addEventListener('pointerdown', (e) => { e.preventDefault(); e.stopPropagation(); });
  window.addEventListener('resize', place);
  window.addEventListener('scroll', place, true);
  window.addEventListener('keydown', onKey, true);
  return root;
}

function paintCard(step, i) {
  const { root } = state;
  root.querySelector('.tour-step').textContent = `Paso ${i + 1} de ${STEPS.length} · ${step.section}`;
  const sections = [...new Set(STEPS.map((s) => s.section))];
  root.querySelector('.tour-dots').innerHTML = sections.map((s) => `<span class="${s === step.section ? 'is-on' : ''}">${s}</span>`).join('');
  root.querySelector('#tour-title').textContent = step.title;
  root.querySelector('#tour-text').textContent = step.text;
  root.querySelector('[data-tour-prev]').disabled = i === 0;
  root.querySelector('[data-tour-restart]').hidden = i === 0;
  root.querySelector('[data-tour-next]').textContent = i === STEPS.length - 1 ? 'Finalizar' : 'Siguiente';
  html.dataset.tourAt = String(i);
}

function place() {
  if (!state) return;
  const { root, el } = state;
  const spot = root.querySelector('.tour-spot');
  const card = root.querySelector('.tour-card');
  const narrow = window.innerWidth < 720;
  root.classList.toggle('is-center', !el);
  root.classList.toggle('is-sheet', narrow);
  if (el && el.isConnected) {
    const r = el.getBoundingClientRect();
    const pad = 6;
    Object.assign(spot.style, { display: 'block', top: `${r.top - pad}px`, left: `${r.left - pad}px`, width: `${r.width + pad * 2}px`, height: `${r.height + pad * 2}px` });
    if (!narrow) {
      const cw = Math.min(380, window.innerWidth - 24);
      const ch = card.offsetHeight || 200;
      let top = r.bottom + 12;
      if (top + ch > window.innerHeight - 12) top = Math.max(12, r.top - ch - 12);
      let left = r.left;
      if (r.right + cw + 16 < window.innerWidth && r.left < 280) { left = r.right + 14; top = Math.max(12, Math.min(r.top, window.innerHeight - ch - 12)); }
      left = Math.max(12, Math.min(left, window.innerWidth - cw - 12));
      Object.assign(card.style, { top: `${top}px`, left: `${left}px` });
    } else Object.assign(card.style, { top: '', left: '' });
  } else {
    spot.style.display = 'none';
    Object.assign(card.style, { top: '', left: '' });
  }
}

async function show(i) {
  const step = STEPS[i];
  state.i = i;
  state.el = null;
  store.set(i);
  paintCard(step, i);
  const slot = state.root.querySelector('.tour-demo-slot');
  slot.innerHTML = '';
  state.root.classList.add('is-loading');
  place();
  const token = (state.token = Symbol('step'));
  const el = await waitTarget(step);
  if (!state || state.token !== token) return;
  state.root.classList.remove('is-loading');
  state.el = el;
  if (!el && step.demo) slot.innerHTML = step.demo; // static example: nothing to click, nothing saved
  if (el) el.scrollIntoView({ block: 'center', inline: 'nearest' });
  place();
  html.dataset.tourReady = String(i);
  state.root.querySelector('[data-tour-next]').focus({ preventScroll: true });
}

function saveStep(i) {
  return api('/api/profile/prefs', { method: 'PATCH', body: { tour_step: i }, keepalive: true }).catch(() => {});
}

/** Opens the page of step i, carrying the step in the address. */
function navigate(i) {
  store.set(i);
  saveStep(i);
  state?.root.classList.add('is-loading');
  location.href = appUrl(STEPS[i].page, { tour: i });
}

function end(result) {
  store.clear();
  if (!state) return;
  state.root.remove();
  window.removeEventListener('resize', place);
  window.removeEventListener('scroll', place, true);
  window.removeEventListener('keydown', onKey, true);
  state = null;
  ['tourAuto', 'tourStep', 'tourAt', 'tourReady'].forEach((k) => delete html.dataset[k]);
  api('/api/profile/prefs', { method: 'PATCH', body: { tour: result }, keepalive: true }).catch(() => {});
}

function onKey(e) {
  if (!state) return;
  if (e.key === 'Escape') { e.preventDefault(); end('skipped'); }
  else if (e.key === 'ArrowRight') { e.preventDefault(); go(1); }
  else if (e.key === 'ArrowLeft') { e.preventDefault(); go(-1); }
  else if (e.key === 'Tab' && !e.target.closest?.('.tour-card')) { e.preventDefault(); state.root.querySelector('[data-tour-next]').focus(); }
}

function goTo(n) {
  if (!state || !valid(n)) return;
  if (!onPage(STEPS[n].page)) { navigate(n); return; }
  saveStep(n);
  show(n);
}

function go(d) {
  if (!state) return;
  const n = state.i + d;
  if (n >= STEPS.length) { end('done'); return; }
  goTo(n);
}

/** Opens the tutorial at step i. If that step lives on another page, offers to continue there. */
function open(i, { offerContinue = false } = {}) {
  if (!state) state = { root: build(), i };
  const step = STEPS[i];
  if (onPage(step.page)) { show(i); return; }
  if (!offerContinue) { navigate(i); return; }
  // resumed (another day) on a page the person opened themselves: do not drag them away, ask first
  state.i = i;
  state.el = null;
  paintCard(step, i);
  state.root.querySelector('.tour-demo-slot').innerHTML = `<p class="small">Seguimos donde lo dejaste, en ${step.section}. <button type="button" class="btn btn--primary btn--sm" data-tour-continue>Ir a esa página</button></p>`;
  place();
}

export async function startTour() {
  document.querySelectorAll('.menu.open').forEach((m) => m.classList.remove('open'));
  if (state) { state.root.remove(); state = null; }
  store.set(0);
  await api('/api/profile/prefs', { method: 'PATCH', body: { tour: 'again' } }).catch(() => {});
  open(0);
}

document.addEventListener('click', (e) => {
  if (!e.target.closest('[data-tour-start]')) return;
  e.preventDefault();
  startTour();
});

// Resume: the address (?tour=N) first, then this tab, then the person's saved preference.
(function resume() {
  const params = new URLSearchParams(location.search);
  const fromUrl = params.has('tour') ? Number(params.get('tour')) : NaN;
  if (params.has('tour')) { // clean address: a reload or a copied link does not restart the tutorial
    params.delete('tour');
    const q = params.toString();
    history.replaceState(history.state, '', location.pathname + (q ? `?${q}` : '') + location.hash);
  }
  if (document.querySelector('dialog[open]') || params.has('drawer')) return;
  if (valid(fromUrl)) { open(fromUrl); return; }
  const tab = store.get();
  if (tab && valid(tab.i) && Date.now() - tab.at < 6 * 3600 * 1000) { open(tab.i, { offerContinue: true }); return; }
  if (html.hasAttribute('data-tour-auto')) {
    const saved = Number(html.dataset.tourStep);
    setTimeout(() => open(valid(saved) ? saved : 0, { offerContinue: true }), 400);
  }
})();
