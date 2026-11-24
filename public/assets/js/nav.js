// 1.15 · Sidebar groups, compact sidebar, favorites and the quick search's «recent» list.
// Preferences are saved to the person's profile (they follow them to other devices).
import { api, appUrl, toast } from './core.js?v=1.15.1';

let saveTimer = null;
function savePrefs(body) {
  clearTimeout(saveTimer);
  saveTimer = setTimeout(() => { api('/api/profile/prefs', { method: 'PATCH', body }).catch(() => {}); }, 400);
}

// a folded group is remembered; the group of the current page is always shown open by the server
document.addEventListener('toggle', (e) => {
  const g = e.target;
  if (!(g instanceof HTMLDetailsElement) || !g.matches('[data-nav-group]')) return;
  const closed = [...document.querySelectorAll('[data-nav-group]')].filter((d) => !d.open).map((d) => d.dataset.navGroup);
  savePrefs({ nav_closed: closed });
}, true);

document.addEventListener('click', async (e) => {
  const t = e.target instanceof Element ? e.target : null;
  if (!t) return;

  const compact = t.closest('[data-nav-compact]');
  if (compact) {
    const on = document.documentElement.dataset.nav !== 'compact';
    if (on) document.documentElement.dataset.nav = 'compact'; else delete document.documentElement.dataset.nav;
    compact.setAttribute('aria-pressed', on ? 'true' : 'false');
    savePrefs({ nav_compact: on });
    return;
  }

  const fav = t.closest('[data-favorite]');
  if (fav) {
    e.preventDefault();
    const [type, id] = fav.dataset.favorite.split(':');
    try {
      const d = await api('/api/favorites', { body: { type, id: Number(id) } });
      fav.setAttribute('aria-pressed', d.favorite ? 'true' : 'false');
      fav.title = d.favorite ? 'Quitar de favoritos' : 'Agregar a favoritos';
      toast(d.favorite ? 'Agregado a favoritos: aparece arriba en la barra lateral' : 'Quitado de favoritos');
    } catch (err) { toast(err.message || 'No se pudo cambiar el favorito', { type: 'error' }); }
  }
});

// Quick search: on focus, before typing, show favorites and what was opened lately.
const input = document.getElementById('global-search');
if (input) {
  const box = document.createElement('div');
  box.className = 'search-recent';
  box.id = 'search-recent';
  box.hidden = true;
  box.setAttribute('role', 'listbox');
  box.setAttribute('aria-label', 'Favoritos y recientes');
  input.closest('form')?.append(box);
  input.setAttribute('aria-controls', 'search-recent');
  let loaded = false;
  const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const row = (i) => `<a role="option" href="${esc(i.url)}"><span class="dot" style="--dot:${esc(i.color || '#10B981')}"></span><span class="truncate">${esc(i.label)}</span><span class="muted small">${esc(i.sub)}</span></a>`;
  const show = async () => {
    if (input.value.trim()) { box.hidden = true; return; }
    if (!loaded) {
      try {
        const d = await fetch(appUrl('/api/nav/recent'), { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Borealis-Background': '1' } }).then((r) => r.json());
        const parts = [];
        if (d.favorites?.length) parts.push('<div class="search-recent-head">Favoritos</div>' + d.favorites.slice(0, 5).map(row).join(''));
        if (d.recent?.length) parts.push('<div class="search-recent-head">Abiertos hace poco</div>' + d.recent.map(row).join(''));
        box.innerHTML = parts.join('') || '<div class="search-recent-head">Escribe para buscar tareas, proyectos y personas</div>';
        loaded = true;
      } catch { return; }
    }
    box.hidden = false;
  };
  input.addEventListener('focus', show);
  input.addEventListener('input', show);
  input.addEventListener('keydown', (e) => { if (e.key === 'Escape') box.hidden = true; });
  document.addEventListener('click', (e) => { if (!input.closest('form').contains(e.target)) box.hidden = true; });
}

// 1.15 · profile: the section index follows the scroll (and stays in view on the phone)
const pnav = document.querySelector('[data-profile-nav]');
if (pnav && 'IntersectionObserver' in window) {
  const links = [...pnav.querySelectorAll('a[href^="#"]')];
  const targets = links.map((a) => document.getElementById(a.hash.slice(1))).filter(Boolean);
  const mark = (id) => links.forEach((a) => {
    const on = a.hash === '#' + id;
    if (on) { a.setAttribute('aria-current', 'true'); if (pnav.scrollWidth > pnav.clientWidth) pnav.scrollTo({ left: a.offsetLeft - 16, behavior: 'smooth' }); } else a.removeAttribute('aria-current');
  });
  const seen = new Map();
  const io = new IntersectionObserver((entries) => {
    entries.forEach((en) => seen.set(en.target.id, en.isIntersecting ? en.intersectionRatio : 0));
    const best = targets.find((t) => (seen.get(t.id) || 0) > 0);
    if (best) mark(best.id);
  }, { rootMargin: '-70px 0px -55% 0px', threshold: [0, 0.01] });
  targets.forEach((t) => io.observe(t));
  mark((location.hash || '#datos').slice(1));
}
