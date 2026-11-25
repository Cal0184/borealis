// 1.14 · Carta Gantt: draws the «blocks» dependencies as arrows between bars (red when the
// successor is planned to start before its predecessor ends). Everything else is plain HTML/CSS.
import { onInit } from './core.js?v=1.16.6';

function draw(root) {
  const svg = root.querySelector('[data-gantt-links]');
  const scroll = root.querySelector('.gantt-scroll');
  let deps = [];
  try { deps = JSON.parse(root.querySelector('[data-gantt-deps]')?.textContent || '[]'); } catch { deps = []; }
  if (!svg || !scroll) return;
  const grid = root.querySelector('.gantt-grid');
  svg.setAttribute('width', grid.scrollWidth);
  svg.setAttribute('height', grid.scrollHeight);
  svg.innerHTML = '<defs><marker id="gantt-arrow" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0,0 L8,4 L0,8 z" fill="currentColor"/></marker></defs>';
  const base = grid.getBoundingClientRect();
  const box = (id) => root.querySelector(`[data-gantt-bar="${id}"]`)?.getBoundingClientRect();
  for (const d of deps) {
    const a = box(d.from), b = box(d.to);
    if (!a || !b) continue;
    const x1 = a.right - base.left, y1 = a.top + a.height / 2 - base.top;
    const x2 = b.left - base.left, y2 = b.top + b.height / 2 - base.top;
    const mid = Math.max(x1 + 8, Math.min(x2 - 8, x1 + 16));
    const p = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    p.setAttribute('d', `M${x1},${y1} H${mid} V${y2} H${x2 - 2}`);
    p.setAttribute('class', d.conflict ? 'gantt-link is-conflict' : 'gantt-link');
    p.setAttribute('marker-end', 'url(#gantt-arrow)');
    const t = document.createElementNS('http://www.w3.org/2000/svg', 'title');
    t.textContent = `${d.from_key} bloquea a ${d.to_key}${d.conflict ? ' · empieza antes de que termine la anterior' : ''}`;
    p.append(t);
    svg.append(p);
  }
  // start scrolled to today on wide charts
  const today = root.querySelector('.gantt-today');
  if (today && !scroll.dataset.scrolled && scroll.scrollWidth > scroll.clientWidth) {
    scroll.dataset.scrolled = '1';
    scroll.scrollLeft = Math.max(0, today.getBoundingClientRect().left - base.left - scroll.clientWidth / 2);
  }
}

onInit((root) => {
  root.querySelectorAll?.('[data-gantt]').forEach((g) => {
    draw(g);
    if (g.dataset.ganttReady) return;
    g.dataset.ganttReady = '1';
    let tm = null;
    window.addEventListener('resize', () => { clearTimeout(tm); tm = setTimeout(() => draw(g), 120); });
  });
});
