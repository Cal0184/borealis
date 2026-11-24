// Calendar: drag a task to another day to reschedule it (start date shifts with it).
// 1.6: events of the general calendar move the same way (time and length are kept).
import { api, toast, refresh } from './core.js?v=1.15.0';

const parse = (s) => (s ? Date.UTC(+s.slice(0, 4), +s.slice(5, 7) - 1, +s.slice(8, 10)) : null);
const fmt = (ms) => new Date(ms).toISOString().slice(0, 10);

let drag = null;
let suppress = false;

document.addEventListener('pointerdown', (e) => {
  if (e.button !== 0 || e.pointerType === 'touch') return;
  const el = e.target.closest('.cal-task[data-cal-drag], [data-event-drag]');
  if (el) drag = { el, x: e.clientX, y: e.clientY, started: false };
});

document.addEventListener('pointermove', (e) => {
  if (!drag) return;
  if (!drag.started) {
    if (Math.hypot(e.clientX - drag.x, e.clientY - drag.y) < 6) return;
    drag.started = true;
    drag.ghost = drag.el.cloneNode(true);
    drag.ghost.classList.add('drag-ghost');
    drag.ghost.style.width = drag.el.offsetWidth + 'px';
    document.body.appendChild(drag.ghost);
    document.body.classList.add('is-dragging');
  }
  e.preventDefault();
  drag.ghost.style.left = e.clientX - 10 + 'px';
  drag.ghost.style.top = e.clientY - 10 + 'px';
  document.querySelectorAll('.day.drop-target').forEach((d) => d.classList.remove('drop-target'));
  drag.day = document.elementFromPoint(e.clientX, e.clientY)?.closest('[data-calendar] .day[data-day]') || null;
  drag.day?.classList.add('drop-target');
});

async function finish(cancel) {
  const d = drag;
  drag = null;
  if (!d?.started) return;
  suppress = true;
  setTimeout(() => { suppress = false; }, 0);
  d.ghost.remove();
  document.body.classList.remove('is-dragging');
  document.querySelectorAll('.day.drop-target').forEach((x) => x.classList.remove('drop-target'));
  if (cancel || !d.day) return;
  const target = d.day.dataset.day;
  if (d.el.dataset.eventDrag !== undefined) {
    if (d.el.dataset.eventDay === target) return;
    d.day.appendChild(d.el);
    try {
      const data = await api(`/api/events/${d.el.dataset.event}/move`, { body: { date: target } });
      toast(data.message || 'Fecha actualizada', { timeout: 1800 });
    } catch (err) {
      if (err.status !== 401) toast(err.message, { type: 'error' });
    }
    await refresh();
    return;
  }
  const { start, due, taskId } = d.el.dataset;
  const body = {};
  if (due) {
    if (due === target) return;
    body.due_date = target;
    if (start) body.start_date = fmt(parse(start) + (parse(target) - parse(due)));
  } else {
    if (start === target) return;
    body.start_date = target;
  }
  d.day.appendChild(d.el);
  try {
    const data = await api(`/api/tasks/${taskId}`, { method: 'PATCH', body });
    toast(data.message || 'Fecha actualizada', { timeout: 1800 });
  } catch (err) {
    if (err.status !== 401) toast(err.message, { type: 'error' });
  }
  await refresh();
}

document.addEventListener('pointerup', () => finish(false));
document.addEventListener('pointercancel', () => finish(true));
document.addEventListener('click', (e) => { if (suppress) { e.preventDefault(); e.stopPropagation(); } }, true);
