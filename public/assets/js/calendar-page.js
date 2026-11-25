// 1.6 general calendar: show/hide calendars and layers, create by clicking a day or an hour,
// open an event to see or edit it, manage calendars, and the optional Google sync.
import { api, toast, refresh, openDialog, confirmAction, clearDirty, onInit } from './core.js?v=1.16.2';

const $ = (sel, root = document) => root.querySelector(sel);
const pad = (n) => String(n).padStart(2, '0');
const addHour = (t) => { const [h, m] = t.split(':').map(Number); return `${pad(Math.min(23, h + 1))}:${pad(h >= 23 ? 59 : m)}`; };

function eventDialog() { return document.getElementById('dlg-event'); }

function setMode(dlg, { editing, canEdit, cancelled, source, video }) {
  const form = $('form', dlg);
  $('[data-event-fields]', dlg).disabled = !canEdit;
  $('[data-event-save]', dlg).hidden = !canEdit;
  const actions = $('[data-event-actions]', dlg);
  actions.hidden = !editing;
  $('[data-event-cancel]', dlg).hidden = !canEdit || cancelled || source === 'google';
  $('[data-event-restore]', dlg).hidden = !canEdit || !cancelled || source === 'google';
  $('[data-event-delete]', dlg).hidden = !canEdit;
  const v = $('[data-event-video]', dlg);
  v.hidden = !video; if (video) v.href = video;
  form.dataset.api = editing ? '/api/events/{id}' : '/api/events';
  form.dataset.method = editing ? 'PATCH' : 'POST';
}

function syncFields(dlg) {
  const allDay = $('[data-allday]', dlg).checked;
  dlg.querySelectorAll('[data-time]').forEach((f) => { f.hidden = allDay; });
  $('[data-until]', dlg).hidden = $('[data-recurrence]', dlg).value === 'none';
  const meeting = $('input[name=kind][value=meeting]', dlg).checked;
  $('[data-participants]', dlg).classList.toggle('is-meeting', meeting);
}

function newEvent({ date, time, kind = 'event' } = {}) {
  const dlg = eventDialog();
  if (!dlg) return;
  const form = $('form', dlg);
  form.reset();
  clearDirty(form);
  form.querySelectorAll('.error').forEach((e) => { e.textContent = ''; });
  $('[data-form-error]', dlg).textContent = '';
  const d = date || document.getElementById('cal-region')?.dataset.calAnchor || new Date().toISOString().slice(0, 10);
  form.elements.id.value = '';
  form.start_date.value = d;
  form.end_date.value = d;
  const t = time || '09:00';
  form.start_time.value = t;
  form.end_time.value = addHour(t);
  $(`input[name=kind][value=${kind}]`, dlg).checked = true;
  // a meeting goes to «Reuniones» and an event to «Eventos» when the person can write there
  const pref = [...form.calendar_id.options].find((o) => o.dataset.purpose === (kind === 'meeting' ? 'meetings' : 'events') && !o.disabled);
  if (pref) form.calendar_id.value = pref.value;
  $('[data-event-heading]', dlg).textContent = kind === 'meeting' ? 'Nueva reunión' : 'Nuevo evento';
  $('[data-event-note]', dlg).hidden = true;
  $('[data-event-meta]', dlg).textContent = '';
  setMode(dlg, { editing: false, canEdit: true });
  syncFields(dlg);
  openDialog(dlg);
  form.title.focus();
}

async function openEvent(id) {
  const dlg = eventDialog();
  if (!dlg) return;
  let ev;
  try { ev = (await api(`/api/events/${id}`, { method: 'GET' })).event; } catch (e) { toast(e.message, { type: 'error' }); return; }
  const form = $('form', dlg);
  form.reset();
  form.querySelectorAll('.error').forEach((e) => { e.textContent = ''; });
  $('[data-form-error]', dlg).textContent = '';
  for (const k of ['id', 'title', 'description', 'location', 'video_url', 'start_date', 'start_time', 'end_date', 'end_time', 'recurrence', 'recurrence_until', 'reminder_minutes', 'project_id', 'team_id']) {
    if (form[k]) form[k].value = ev[k] ?? '';
  }
  const calSel = form.calendar_id;
  if (![...calSel.options].some((o) => Number(o.value) === ev.calendar_id)) {
    const o = new Option(ev.calendar_name, ev.calendar_id); o.dataset.extra = '1'; calSel.add(o);
  }
  calSel.value = String(ev.calendar_id);
  form.all_day.checked = ev.all_day;
  $(`input[name=kind][value=${ev.kind}]`, dlg).checked = true;
  form.querySelectorAll('input[name="participant_ids[]"][type=checkbox]').forEach((cb) => { cb.checked = ev.participant_ids.includes(Number(cb.value)); });
  const kind = ev.kind === 'meeting' ? 'Reunión' : 'Evento';
  $('[data-event-heading]', dlg).textContent = ev.status === 'cancelled' ? `${kind} cancelado` : ev.can_edit ? `Editar ${kind.toLowerCase()}` : kind;
  const note = $('[data-event-note]', dlg);
  const notes = [];
  if (ev.status === 'cancelled') notes.push('Este evento está cancelado.');
  if (!ev.can_edit) notes.push(ev.source === 'google' ? 'Este calendario de Google es de solo lectura para ti.' : 'Puedes ver este evento, pero no cambiarlo.');
  if (ev.source === 'google') notes.push('Viene de Google Calendar: los cambios se guardan también allí.');
  if (ev.google_recurring) notes.push('Es una repetición de un evento de Google: el cambio afecta solo a este día.');
  note.textContent = notes.join(' ');
  note.hidden = !notes.length;
  $('[data-event-meta]', dlg).textContent = `${ev.calendar_name} · ${ev.when}${ev.participants.length ? ' · Participan: ' + ev.participants.join(', ') : ''}`;
  setMode(dlg, { editing: true, canEdit: ev.can_edit, cancelled: ev.status === 'cancelled', source: ev.source, video: ev.video_url });
  syncFields(dlg);
  clearDirty(form);
  openDialog(dlg);
}

function editCalendar(data) {
  const dlg = document.getElementById('dlg-calendar');
  const form = $('form', dlg);
  form.reset();
  clearDirty(form);
  const editing = !!data;
  form.dataset.api = editing ? '/api/calendars/{id}' : '/api/calendars';
  form.dataset.method = editing ? 'PATCH' : 'POST';
  form.elements.id.value = data?.id ?? '';
  form.name.value = data?.name ?? '';
  const c = form.querySelector(`input[name=color][value="${data?.color ?? ''}"]`) || form.querySelector('input[name=color]');
  c.checked = true;
  if (data) {
    form.querySelector(`input[name=kind][value=${data.kind}]`).checked = true;
    form.default_access.value = data.default_access || 'read';
    form.purpose.value = data.purpose || 'other';
    form.team_id.value = data.team_id ?? '';
  }
  $('[data-cal-kind]', dlg).hidden = editing;
  $('[data-cal-access]', dlg).hidden = editing && data.kind === 'personal';
  $('[data-cal-team]', dlg).hidden = form.querySelector('input[name=kind]:checked')?.value === 'personal';
  $('[data-calendar-heading]', dlg).textContent = editing ? 'Ajustes del calendario' : 'Nuevo calendario';
  const del = $('[data-cal-delete-box]', dlg);
  del.hidden = !editing;
  del.querySelectorAll('[data-cal-move] option').forEach((o) => { o.hidden = o.value === String(data?.id); });
  $('[data-cal-move]', dlg).value = '';
  openDialog(dlg);
}

async function call(path, opts, ok) {
  try {
    const data = await api(path, opts);
    if (ok !== false) toast(data.message || 'Guardado', { timeout: 2200 });
    await refresh();
    return data;
  } catch (e) {
    if (e.status !== 401) toast(e.message, { type: 'error', timeout: 7000 });
    return null;
  }
}

document.addEventListener('change', (e) => {
  const t = e.target;
  if (t.matches('[data-cal-toggle]')) call(`/api/calendars/${t.dataset.calToggle}/shown`, { body: { shown: t.checked } }, false);
  else if (t.matches('[data-cal-layer]')) call('/api/calendar-layers', { body: { layer: t.dataset.calLayer, shown: t.checked } }, false);
  else if (t.closest('#dlg-event') && (t.matches('[data-allday], [data-recurrence], input[name=kind]'))) syncFields(eventDialog());
  else if (t.closest('#dlg-calendar') && t.name === 'kind') {
    $('#dlg-calendar [data-cal-access]').hidden = t.value === 'personal';
    $('#dlg-calendar [data-cal-team]').hidden = t.value === 'personal';
    if (t.value === 'personal') $('#dlg-calendar select[name=team_id]').value = '';
  }
});

document.addEventListener('click', async (e) => {
  const t = e.target;
  const nb = t.closest('[data-new-event]');
  if (nb) { e.preventDefault(); newEvent({ date: nb.dataset.date, kind: nb.dataset.kind }); return; }
  const ev = t.closest('[data-event]');
  if (ev && ev.closest('[data-calendar]')) { e.preventDefault(); openEvent(ev.dataset.event); return; }
  const ec = t.closest('[data-edit-calendar]');
  if (ec) { editCalendar(JSON.parse(ec.dataset.editCalendar)); return; }
  if (t.closest('[data-new-calendar]')) { setTimeout(() => editCalendar(null), 0); return; }
  if (t.closest('[data-cal-delete]')) {
    const dlg = document.getElementById('dlg-calendar');
    const form = $('form', dlg);
    const move = $('[data-cal-move]', dlg);
    const text = move.value ? `¿Eliminar «${form.name.value}»? Sus eventos pasarán a otro calendario.` : `¿Eliminar «${form.name.value}» y todos sus eventos?`;
    if (!(await confirmAction(text, { label: 'Eliminar calendario' }))) return;
    if (await call(`/api/calendars/${form.elements.id.value}`, { method: 'DELETE', body: { move_to: Number(move.value) || null } })) { clearDirty(form); dlg.close(); }
    return;
  }
  const dlg = t.closest('#dlg-event');
  if (dlg) {
    const id = $('form', dlg).id.value;
    const act = t.closest('[data-event-cancel], [data-event-restore], [data-event-delete]');
    if (!act || !id) return;
    if (act.matches('[data-event-cancel]')) {
      if (!(await confirmAction('¿Cancelar este evento? Seguirá visible como cancelado y se avisará a los participantes.', { label: 'Cancelar evento' }))) return;
      if (await call(`/api/events/${id}/cancel`, {})) { clearDirty($('form', dlg)); dlg.close(); }
    } else if (act.matches('[data-event-restore]')) {
      if (await call(`/api/events/${id}/restore`, {})) { clearDirty($('form', dlg)); dlg.close(); }
    } else {
      const google = $('[data-event-note]', dlg).textContent.includes('Google');
      if (!(await confirmAction(google ? '¿Eliminar este evento? También se eliminará de Google Calendar.' : '¿Eliminar este evento?', { label: 'Eliminar' }))) return;
      if (await call(`/api/events/${id}`, { method: 'DELETE' })) { clearDirty($('form', dlg)); dlg.close(); }
    }
    return;
  }
  // a click on free space of a day (month) or an hour (week/day) creates an event there
  const free = t.closest('[data-new-on-click]');
  if (!free || t.closest('button, a, input, label, select')) return;
  if (!document.querySelector('[data-new-event]')) return; // nothing the person can edit
  const day = t.closest('[data-day]');
  if (!day) return;
  let time;
  if (day.classList.contains('tg-col')) {
    const r = day.getBoundingClientRect();
    const hour = parseFloat(getComputedStyle(day.closest('.tgrid')).getPropertyValue('--hour')) || 44;
    const mins = Math.max(0, Math.min(23 * 60 + 30, Math.floor(((e.clientY - r.top) / hour) * 2) * 30));
    time = `${pad(Math.floor(mins / 60))}:${pad(mins % 60)}`;
  }
  newEvent({ date: day.dataset.day, time });
});

// The kept dialog has the calendars of the last render; drop the temporary option when it closes.
document.addEventListener('close', (e) => {
  if (e.target.id === 'dlg-event') e.target.querySelectorAll('option[data-extra]').forEach((o) => o.remove());
}, true);

let synced = false;
onInit((root) => {
  const region = root.id === 'cal-region' ? root : root.querySelector?.('#cal-region');
  if (!region) return;
  const scroller = region.querySelector('[data-tg-scroll]');
  if (scroller && !scroller.dataset.scrolled) {
    scroller.dataset.scrolled = '1';
    const now = region.querySelector('[data-tg-now]');
    const hour = parseFloat(getComputedStyle(region.querySelector('.tgrid')).getPropertyValue('--hour')) || 44;
    const d = new Date();
    if (now) now.style.top = `${(d.getHours() + d.getMinutes() / 60) * hour}px`;
    scroller.scrollTop = 7 * hour;
  }
  if (region.dataset.openEvent && !region.dataset.opened) { region.dataset.opened = '1'; openEvent(region.dataset.openEvent); }
  if (region.hasAttribute('data-google-autosync') && !synced) {
    synced = true;
    api('/api/google/sync', { body: {} }).then(() => refresh()).catch((err) => { if (err.status !== 401) { toast(err.message, { type: 'error', timeout: 7000 }); refresh(); } });
  }
});

// Profile › Google: list the person's Google calendars to choose which ones Borealis shows.
onInit((root) => {
  const form = root.matches?.('[data-google-calendars]') ? root : root.querySelector?.('[data-google-calendars]');
  if (!form || form.dataset.loaded) return;
  form.dataset.loaded = '1';
  const box = form.querySelector('[data-google-list]');
  api('/api/google/calendars', { method: 'GET' }).then(({ calendars }) => {
    box.innerHTML = '';
    if (!calendars.length) { box.textContent = 'Tu cuenta de Google no tiene calendarios.'; return; }
    for (const c of calendars) {
      const label = document.createElement('label');
      label.className = 'check';
      const cb = document.createElement('input');
      cb.type = 'checkbox'; cb.name = 'calendars[]'; cb.value = c.id; cb.checked = c.linked;
      const sw = document.createElement('span');
      sw.className = 'cal-swatch'; sw.style.setProperty('--c', c.color);
      const txt = document.createElement('span');
      txt.textContent = `${c.name}${c.primary ? ' (principal)' : ''}${['owner', 'writer'].includes(c.access) ? '' : ' · solo lectura'}`;
      label.append(cb, sw, txt);
      box.append(label);
    }
  }).catch((err) => { box.textContent = err.message; box.setAttribute('role', 'alert'); });
});

// 1.9 · Profile › Google Calendar › «Enviar mi trabajo»: the calendars where the person can write.
onInit((root) => {
  const form = root.matches?.('[data-google-export]') ? root : root.querySelector?.('[data-google-export]');
  if (!form || form.dataset.loaded) return;
  form.dataset.loaded = '1';
  const sel = form.querySelector('[data-gx-calendars]');
  const current = sel.dataset.current;
  api('/api/google/export', { method: 'GET' }).then(({ calendars, status }) => {
    if (status?.calendars_error) throw new Error(status.calendars_error);
    sel.innerHTML = '';
    if (!calendars.length) {
      sel.append(new Option('No tienes calendarios de Google en los que puedas escribir', ''));
      return;
    }
    if (!current) sel.append(new Option('Elige un calendario…', ''));
    for (const c of calendars) sel.append(new Option(`${c.name}${c.primary ? ' (principal)' : ''}`, c.id, false, current ? c.id === current : false));
    if (!current && calendars.some((c) => c.primary)) sel.value = calendars.find((c) => c.primary).id;
  }).catch((err) => {
    const hint = form.querySelector('[data-error-for="calendar_id"]');
    if (hint) { hint.textContent = err.message; hint.setAttribute('role', 'alert'); }
  });
});
