// Board: drag people to give or take work.
//  - From the people strip onto a card: the person joins the card's responsibles.
//  - From one card onto another: the person moves.
//  - From a card back onto the people strip: the person is taken off that card.
// When the board shows a sprint, a card where the person has no room left is marked as not
// valid while dragging and the drop is refused with the reason. The server checks the same
// rules (polivalente, available hours) and is the one that decides.
import { api, toast, refresh, saveState } from './core.js?v=1.16.6';

let dragging = null;

const hrs = (x) => (Math.round(x * 10) / 10).toString().replace('.', ',');

function cardFor(id) { return document.querySelector(`.task-card[data-task-id="${id}"]`); }
function cardHasPerson(card, user) { return !!card.querySelector(`[data-card-people] [data-person="${user}"]`); }

/** null when the drop is fine, otherwise the reason it is not. */
function refusal(card) {
  const { user, free, name, from } = dragging;
  if (cardHasPerson(card, user)) return `${name} ya es responsable de esta tarea.`;
  if (free === null) return null;
  const hours = card.dataset.hours === '' ? null : Number(card.dataset.hours);
  if (!hours) return null;
  const share = hours / (Number(card.dataset.people || 0) + 1);
  let room = free;
  if (from) { // moving: the hours of the card they leave become free again
    const src = cardFor(from);
    const h = src && src.dataset.hours !== '' ? Number(src.dataset.hours) : 0;
    const n = src ? Number(src.dataset.people || 1) : 1;
    room += h / Math.max(1, n);
  }
  if (room + 0.001 >= share) return null;
  return room <= 0.001 ? `${name} no tiene horas disponibles en este sprint.` : `${name} solo tiene ${hrs(room)} h disponibles y esta tarea le suma ${hrs(share)} h.`;
}

function clearMarks() {
  document.querySelectorAll('.task-card.person-over, .task-card.person-bad').forEach((c) => c.classList.remove('person-over', 'person-bad'));
  document.querySelector('#people-strip')?.classList.remove('drop-over');
}

document.addEventListener('dragstart', (e) => {
  const el = e.target.closest?.('.person-drag[data-person]');
  if (!el) return;
  const fromTask = el.dataset.fromTask ? Number(el.dataset.fromTask) : null;
  const pill = document.querySelector(`#people-strip .person-pill[data-person="${el.dataset.person}"]`);
  const freeAttr = pill ? pill.dataset.free : '';
  dragging = {
    user: Number(el.dataset.person), from: fromTask,
    free: freeAttr === undefined || freeAttr === '' ? null : Number(freeAttr),
    name: pill?.dataset.name || el.getAttribute('aria-label')?.split(':')[0] || 'Esta persona',
  };
  e.dataTransfer.effectAllowed = fromTask ? 'move' : 'copy';
  e.dataTransfer.setData('text/plain', `borealis-person:${dragging.user}`);
  document.body.classList.add('dragging-person');
  if (fromTask) document.body.classList.add('dragging-from-card');
});

document.addEventListener('dragend', () => {
  dragging = null;
  document.body.classList.remove('dragging-person', 'dragging-from-card');
  clearMarks();
});

document.addEventListener('dragover', (e) => {
  if (!dragging) return;
  const strip = e.target.closest?.('#people-strip');
  if (strip && dragging.from) { // back to the strip: take them off the card
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    strip.classList.add('drop-over');
    return;
  }
  const card = e.target.closest?.('.task-card[data-task-id]');
  if (!card) return;
  e.preventDefault();
  document.querySelectorAll('.task-card.person-over, .task-card.person-bad').forEach((c) => { if (c !== card) c.classList.remove('person-over', 'person-bad'); });
  const bad = Number(card.dataset.taskId) !== dragging.from && refusal(card);
  // A card where the person does not fit stays a drop target (marked in red) so the drop can say why.
  e.dataTransfer.dropEffect = dragging.from ? 'move' : 'copy';
  card.classList.toggle('person-bad', !!bad);
  card.classList.toggle('person-over', !bad);
});

document.addEventListener('dragleave', (e) => {
  const card = e.target.closest?.('.task-card');
  if (card && !card.contains(e.relatedTarget)) card.classList.remove('person-over', 'person-bad');
  const strip = e.target.closest?.('#people-strip');
  if (strip && !strip.contains(e.relatedTarget)) strip.classList.remove('drop-over');
});

async function send(path, opts, card) {
  card?.classList.add('is-saving');
  saveState('saving');
  try {
    const data = await api(path, opts);
    saveState('saved');
    toast(data.message || 'Guardado', { timeout: 2200 });
  } catch (err) {
    saveState('error');
    if (err.status !== 401) toast(err.message, { type: 'error' });
  }
  await refresh();
  card?.classList.remove('is-saving');
}

document.addEventListener('drop', async (e) => {
  if (!dragging) return;
  const d = dragging;
  const strip = e.target.closest?.('#people-strip');
  if (strip && d.from) {
    e.preventDefault();
    dragging = null;
    clearMarks();
    await send(`/api/tasks/${d.from}/people/${d.user}`, { method: 'DELETE' }, cardFor(d.from));
    return;
  }
  const card = e.target.closest?.('.task-card[data-task-id]');
  if (!card) return;
  e.preventDefault();
  const to = Number(card.dataset.taskId);
  const reason = to !== d.from && refusal(card);
  dragging = null;
  clearMarks();
  if (to === d.from) return;
  if (reason) { toast(reason, { type: 'error' }); return; }
  await send(`/api/tasks/${to}/people`, { body: { user_id: d.user, from_task_id: d.from } }, card);
});
