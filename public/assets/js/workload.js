// 1.14 · weekly load of each person, shown where work is assigned: «Ana · 12 de 15 h esta semana».
// Loaded once per page (and again after a minute) from /api/workload; nothing is shown when the
// person cannot see others' load.
import { api, onInit } from './core.js?v=1.16.5';

let cache = null;
let at = 0;
async function load() {
  if (cache && Date.now() - at < 60000) return cache;
  try { cache = (await api('/api/workload', { method: 'GET' })).people || {}; at = Date.now(); } catch { cache = {}; }
  return cache;
}

async function decorate(select) {
  const people = await load();
  for (const opt of select.options) {
    const w = people[opt.value];
    if (!w) continue;
    opt.dataset.label ??= opt.textContent;
    opt.textContent = `${opt.dataset.label} · ${w.text}`;
    opt.dataset.load = w.state;
  }
  hint(select, people);
}

function hint(select, people) {
  const slot = select.parentElement?.querySelector('[data-workload-hint]');
  if (!slot) return;
  const w = people[select.value];
  slot.textContent = !w ? '' : w.state === 'over' ? `Ya supera sus horas de la semana (${w.text}). Considera otra persona u otra fecha.`
    : w.state === 'full' ? `Le queda poco tiempo esta semana (${w.text}).` : w.capacity == null ? `${w.text} (sin horas semanales configuradas).` : `Disponible: ${String(w.free).replace('.', ',')} h esta semana.`;
  slot.dataset.load = w?.state || '';
}

onInit((root) => {
  root.querySelectorAll?.('[data-workload-select]').forEach((sel) => {
    if (sel.dataset.wlReady) return;
    sel.dataset.wlReady = '1';
    decorate(sel);
    sel.addEventListener('change', () => load().then((p) => hint(sel, p)));
  });
});
// the «Nueva tarea» dialog: decorate when it opens (its people depend on the chosen project)
document.addEventListener('click', (e) => {
  if (!e.target.closest?.('[data-dialog="dlg-new-task"]')) return;
  const sel = document.getElementById('nt-assignee');
  if (sel) { cache = null; decorate(sel); }
});
