// Drag and drop for the board and the backlog, plus keyboard/menu alternatives.
// The DOM moves first for responsiveness; the server decides, and on rejection the view is
// re-rendered from the database so nothing shows a state that was not saved.
import { api, toast, refresh, afterSuccess } from './core.js?v=1.16.0';
import { openTask } from './drawer.js?v=1.16.0';

const THRESHOLD = 6;
const INTERACTIVE = 'button, a, input, select, textarea, label, .menu';

let drag = null;
let suppressClick = false;

function setups() {
  return [
    {
      item: '.task-card[data-draggable]',
      list: '[data-column-body]',
      id: (el) => el.dataset.taskId,
      async save(el, list) {
        const prev = prevItem(el, this.item);
        const data = await api(`/api/tasks/${el.dataset.taskId}/move`, { body: { status_id: Number(list.dataset.columnBody), after_id: prev ? Number(prev.dataset.taskId) : null } });
        return data;
      },
      highlight: (list) => list.closest('.column'),
    },
    {
      item: '[data-backlog-item][data-draggable]',
      list: '[data-backlog-list]',
      id: (el) => el.dataset.backlogItem,
      async save(el, list, fromList) {
        const prev = prevItem(el, this.item);
        const body = { after_id: prev ? Number(prev.dataset.backlogItem) : null };
        if (list !== fromList) body.sprint_id = Number(list.dataset.backlogList) || null;
        return api(`/api/tasks/${el.dataset.backlogItem}/backlog-move`, { body });
      },
      highlight: (list) => list.closest('[data-backlog-group]'),
    },
    {
      // 1.6 «Mis tareas»: reorder tasks (or the subtasks of one task) inside their own list
      item: '[data-ptask][data-draggable]',
      list: '[data-ptask-list]',
      direct: true,
      id: (el) => el.dataset.ptask,
      async save(el, list, fromList) {
        if (list !== fromList) throw Object.assign(new Error('Para cambiar una subtarea de tarea, edítala y elige otra.'), { status: 0 });
        const ids = [...list.children].filter((x) => x.matches('[data-ptask]')).map((x) => Number(x.dataset.ptask));
        return api(`/api/my-lists/${list.dataset.ptaskList}/reorder`, { body: { ids, parent_id: Number(list.dataset.parent) || null } });
      },
      highlight: (list) => list,
    },
  ];
}

function prevItem(el, sel) {
  let p = el.previousElementSibling;
  while (p && !p.matches(sel)) p = p.previousElementSibling;
  return p;
}
function nextItem(el, sel) {
  let n = el.nextElementSibling;
  while (n && !n.matches(sel)) n = n.nextElementSibling;
  return n;
}

async function commit(setup, el, list, fromList) {
  try {
    const data = await setup.save(el, list, fromList);
    toast(data.message || 'Guardado', { timeout: 1800 });
    afterSuccess(data);
  } catch (err) {
    if (err.status !== 401) toast(err.message, { type: 'error' });
  }
  await refresh(); // counts, WIP limits and order always come from the server
}

// ---------- Pointer dragging (mouse and pen; touch uses the menu) ----------
document.addEventListener('pointerdown', (e) => {
  if (e.button !== 0 || e.pointerType === 'touch') return;
  if (e.target.closest(INTERACTIVE)) return;
  for (const setup of setups()) {
    const el = e.target.closest(setup.item);
    if (el) { drag = { setup, el, x: e.clientX, y: e.clientY, started: false }; return; }
  }
});

function startDrag(e) {
  const { el } = drag;
  const r = el.getBoundingClientRect();
  drag.started = true;
  drag.fromList = el.closest(drag.setup.list);
  drag.dx = e.clientX - r.left;
  drag.dy = e.clientY - r.top;
  const ghost = el.cloneNode(true);
  ghost.classList.add('drag-ghost');
  ghost.style.width = r.width + 'px';
  ghost.removeAttribute('id');
  document.body.appendChild(ghost);
  drag.ghost = ghost;
  const ph = document.createElement(el.tagName === 'LI' ? 'li' : 'div');
  ph.className = 'drop-placeholder';
  ph.style.height = r.height + 'px';
  el.after(ph);
  drag.ph = ph;
  el.classList.add('dragging');
  el.hidden = true;
  document.body.classList.add('is-dragging');
}

function moveGhost(e) {
  drag.ghost.style.left = e.clientX - drag.dx + 'px';
  drag.ghost.style.top = e.clientY - drag.dy + 'px';
  const under = document.elementFromPoint(e.clientX, e.clientY);
  const list = under?.closest(drag.setup.list);
  document.querySelectorAll('.drop-target').forEach((x) => x.classList.remove('drop-target'));
  if (!list) return;
  drag.setup.highlight(list)?.classList.add('drop-target');
  const items = (drag.setup.direct ? [...list.children].filter((x) => x.matches(drag.setup.item)) : [...list.querySelectorAll(drag.setup.item)]).filter((x) => x !== drag.el);
  const before = items.find((x) => { const r = x.getBoundingClientRect(); return e.clientY < r.top + r.height / 2; });
  if (before) before.before(drag.ph);
  else {
    const tail = list.querySelector('.column-add, [data-empty]');
    if (tail) tail.before(drag.ph); else list.appendChild(drag.ph);
  }
  // Auto-scroll the board horizontally and the page vertically.
  const board = document.getElementById('board-scroll');
  if (board && board.contains(list)) {
    const br = board.getBoundingClientRect();
    if (e.clientX > br.right - 60) board.scrollLeft += 18;
    else if (e.clientX < br.left + 60) board.scrollLeft -= 18;
  }
  if (e.clientY > window.innerHeight - 50) window.scrollBy(0, 16);
  else if (e.clientY < 70) window.scrollBy(0, -16);
}

document.addEventListener('pointermove', (e) => {
  if (!drag) return;
  if (!drag.started) {
    if (Math.hypot(e.clientX - drag.x, e.clientY - drag.y) < THRESHOLD) return;
    startDrag(e);
  }
  e.preventDefault();
  moveGhost(e);
});

function endDrag(cancel) {
  const d = drag;
  drag = null;
  if (!d?.started) return;
  suppressClick = true;
  setTimeout(() => { suppressClick = false; }, 0);
  d.ghost.remove();
  document.body.classList.remove('is-dragging');
  document.querySelectorAll('.drop-target').forEach((x) => x.classList.remove('drop-target'));
  const list = d.ph.closest(d.setup.list);
  d.el.hidden = false;
  d.el.classList.remove('dragging');
  if (cancel || !list) { d.ph.remove(); return; }
  const moved = !(d.ph.previousElementSibling === d.el || d.ph.nextElementSibling === d.el) || list !== d.fromList;
  d.ph.replaceWith(d.el);
  if (moved) commit(d.setup, d.el, list, d.fromList);
}

document.addEventListener('pointerup', () => endDrag(false));
document.addEventListener('pointercancel', () => endDrag(true));
document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && drag?.started) endDrag(true); });

// ---------- Click / keyboard on board cards opens the task ----------
document.addEventListener('click', (e) => {
  if (suppressClick) { e.preventDefault(); e.stopPropagation(); return; }
  const card = e.target.closest('.task-card');
  if (!card || e.target.closest(INTERACTIVE)) return;
  openTask(card.dataset.taskKey, card);
}, true);

document.addEventListener('keydown', (e) => {
  const el = e.target;
  if (e.key === 'Enter' && el.matches?.('.task-card')) { e.preventDefault(); openTask(el.dataset.taskKey, el); return; }
  if (e.key === 'Enter' && el.matches?.('[data-backlog-item]')) { e.preventDefault(); el.querySelector('[data-open-task]')?.click(); return; }
  // Alt + arrows move the focused card or backlog item.
  if (!e.altKey || !['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key)) return;
  if (el.matches?.('.task-card[data-draggable]')) {
    e.preventDefault();
    boardMove(el, { ArrowUp: 'up', ArrowDown: 'down', ArrowLeft: 'prev', ArrowRight: 'next' }[e.key]);
  } else if (el.matches?.('[data-backlog-item][data-draggable]') && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
    e.preventDefault();
    backlogMove(el, e.key === 'ArrowUp' ? 'up' : 'down');
  }
});

async function boardMove(card, dir) {
  const setup = setups()[0];
  const list = card.closest(setup.list);
  const sel = setup.item;
  let target = list;
  if (dir === 'up') {
    const p = prevItem(card, sel);
    if (!p) return toast('Ya está arriba del todo.');
    p.before(card);
  } else if (dir === 'down') {
    const n = nextItem(card, sel);
    if (!n) return toast('Ya está abajo del todo.');
    n.after(card);
  } else {
    const cols = [...document.querySelectorAll(setup.list)];
    const i = cols.indexOf(list) + (dir === 'next' ? 1 : -1);
    if (i < 0 || i >= cols.length) return toast(dir === 'next' ? 'Ya está en la última columna.' : 'Ya está en la primera columna.');
    target = cols[i];
    const first = target.querySelector(sel);
    if (first) first.before(card); else (target.querySelector('.column-add')?.before(card) ?? target.appendChild(card));
  }
  const key = card.dataset.taskKey;
  await commit(setup, card, target, list);
  const again = document.querySelector(`.task-card[data-task-key="${CSS.escape(key)}"]`);
  again?.focus();
}

async function backlogMove(li, dir) {
  const setup = setups()[1];
  const list = li.closest(setup.list);
  const sel = setup.item;
  if (dir === 'up') { const p = prevItem(li, sel); if (!p) return toast('Ya está arriba del todo.'); p.before(li); }
  else if (dir === 'down') { const n = nextItem(li, sel); if (!n) return toast('Ya está abajo del todo.'); n.after(li); }
  else { const f = list.querySelector(sel); if (f === li) return toast('Ya está arriba del todo.'); f.before(li); }
  const id = li.dataset.backlogItem;
  await commit(setup, li, list, list);
  document.querySelector(`[data-backlog-item="${CSS.escape(id)}"]`)?.focus();
}

async function ptaskMove(li, dir) {
  const setup = setups()[2];
  const list = li.closest(setup.list);
  const sel = setup.item;
  if (dir === 'up') { const p = prevItem(li, sel); if (!p) return toast('Ya está arriba del todo.'); p.before(li); }
  else { const n = nextItem(li, sel); if (!n) return toast('Ya está abajo del todo.'); n.after(li); }
  const id = li.dataset.ptask;
  await commit(setup, li, list, list);
  document.querySelector(`[data-ptask="${CSS.escape(id)}"] [data-ptask-move="${dir}"]`)?.focus();
}

document.addEventListener('click', (e) => {
  const pm = e.target.closest('[data-ptask-move]');
  if (pm) { const li = pm.closest('[data-ptask]'); if (li) ptaskMove(li, pm.dataset.ptaskMove); return; }
  const mv = e.target.closest('[data-move]');
  if (mv) {
    const card = mv.closest('.task-card');
    mv.closest('.menu')?.classList.remove('open');
    if (card) boardMove(card, mv.dataset.move);
    return;
  }
  const bm = e.target.closest('[data-backlog-move]');
  if (bm) {
    const li = bm.closest('[data-backlog-item]');
    bm.closest('.menu')?.classList.remove('open');
    if (li) backlogMove(li, bm.dataset.backlogMove);
  }
});
