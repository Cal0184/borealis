// Análisis (1.13): estimate suggestions next to «Tiempo estimado» and the scenario simulator.
// Nothing here saves data on its own: «Usar» only fills the field (the person saves it), and a
// scenario is applied only after an explicit confirmation.
import { api, toast, onInit, refresh } from './core.js?v=1.16.2';

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const hours = (x) => (x === null || x === undefined ? '—' : `${String(Math.round(x * 10) / 10).replace('.', ',')} h`);
const pct = (x) => (x === null || x === undefined ? '—' : `${x > 0 ? '+' : x < 0 ? '−' : ''}${String(Math.abs(Math.round(x * 10) / 10)).replace('.', ',')} %`);

// ---------- Estimate suggestion ----------
function renderSuggestion(out, s, input) {
  const ex = s.excluded || {};
  const excl = [
    ex.no_logged ? `${ex.no_logged} sin horas registradas` : '', ex.no_estimate ? `${ex.no_estimate} sin estimación` : '',
    ex.outliers ? `${ex.outliers} con valores extremos` : '', ex.other_category ? `${ex.other_category} de otra categoría` : '', ex.size ? `${ex.size} de otro tamaño` : '',
  ].filter(Boolean).join(', ');
  let html = `<div class="an-suggest" data-suggest="${s.ok ? 'ok' : 'none'}" role="status">`;
  if (!s.ok) {
    html += `<p class="small">${esc(s.message)}</p>`;
  } else {
    html += `<p class="small"><span class="an-tag an-tag--estimate">Estimación</span> ${esc(s.message)}</p>`;
    html += `<p class="small muted">Mediana ${esc(pct(s.median_pct))} (${esc(hours(s.median_hours))}); la mitad central de las tareas, entre ${esc(pct(s.p25))} y ${esc(pct(s.p75))}. Confianza: ${esc(s.confidence)} (${s.n} tareas).</p>`;
    if (s.calendar_days !== null) html += `<p class="small muted">Duración en el calendario (inicio → fin), aparte del trabajo registrado: mediana ${String(s.calendar_days).replace('.', ',')} días.</p>`;
    if (s.suggested !== null && input) html += `<div class="row"><button class="btn btn--sm btn--primary" type="button" data-suggest-use="${s.suggested}">Usar ${esc(hours(s.suggested))}</button><button class="btn btn--sm btn--ghost" type="button" data-suggest-close>Ignorar</button></div>`;
    else if (input) html += '<p class="small muted">Escribe tu estimación para ver la sugerencia en horas.</p>';
  }
  html += `<details class="an-why"><summary>Origen de la sugerencia</summary><p class="small">${esc(s.source)}</p>${excl ? `<p class="small muted">No se usaron: ${esc(excl)}.</p>` : ''}`;
  if (s.tasks?.length) html += `<ul class="small">${s.tasks.map((t) => `<li>${esc(t.key)} · ${esc(t.title)}: ${esc(hours(t.estimate))} estimadas, ${esc(hours(t.logged))} registradas</li>`).join('')}</ul>`;
  html += '<p class="small muted">Sugerencia opcional: no cambia ninguna estimación por sí sola.</p></details></div>';
  out.innerHTML = html;
  out.querySelector('[data-suggest-use]')?.addEventListener('click', (e) => {
    input.value = e.currentTarget.dataset.suggestUse;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
    out.innerHTML = '';
  });
  out.querySelector('[data-suggest-close]')?.addEventListener('click', () => { out.innerHTML = ''; });
}

document.addEventListener('click', async (e) => {
  const btn = e.target.closest('[data-estimate-suggest]');
  if (!btn) return;
  e.preventDefault();
  const scope = btn.closest('form, .card, .td-side, dialog, section') || document;
  const input = btn.dataset.for ? scope.querySelector(btn.dataset.for) || document.querySelector(btn.dataset.for) : null;
  const out = btn.parentElement.querySelector('[data-estimate-out]') || scope.querySelector('[data-estimate-out]');
  const params = new URLSearchParams();
  if (btn.dataset.task) params.set('task_id', btn.dataset.task);
  let project = btn.dataset.project;
  if (btn.dataset.projectFrom) project = (scope.querySelector(btn.dataset.projectFrom) || document.querySelector(btn.dataset.projectFrom))?.value;
  if (project) params.set('project_id', project);
  if (btn.dataset.labelsFrom) {
    const sel = document.querySelector(btn.dataset.labelsFrom);
    params.set('label_ids', sel ? [...sel.selectedOptions].map((o) => o.value).join(',') : '');
  }
  if (input && input.value !== '') params.set('estimate', input.value);
  if (!params.get('project_id') && !params.get('task_id')) { toast('Elige primero un proyecto.', { type: 'error' }); return; }
  btn.setAttribute('aria-busy', 'true');
  try {
    const data = await api(`/api/analysis/estimate?${params}`, { method: 'GET' });
    if (out) renderSuggestion(out, data.suggestion, input);
  } catch (err) {
    toast(err.message, { type: 'error' });
  } finally {
    btn.removeAttribute('aria-busy');
  }
});

// ---------- Scenario simulator ----------
function collect(root) {
  const changes = [];
  root.querySelectorAll('tr[data-task]').forEach((tr) => {
    const id = Number(tr.dataset.task);
    const move = tr.querySelector('[data-sim="move"]')?.value;
    if (move) changes.push({ type: 'move', task_id: id, to_sprint_id: move === 'backlog' ? null : Number(move) });
    const est = tr.querySelector('[data-sim="estimate"]');
    if (est && est.value !== '' && Number(est.value) !== Number(tr.dataset.origEst || NaN)) changes.push({ type: 'estimate', task_id: id, hours: Number(est.value) });
    const due = tr.querySelector('[data-sim="due"]');
    if (due && due.value !== (tr.dataset.origDue || '')) changes.push({ type: 'due', task_id: id, date: due.value || null });
    const who = tr.querySelector('[data-sim="assign"]');
    if (who && Number(who.value) && Number(who.value) !== Number(tr.dataset.origUser)) changes.push({ type: 'assign', task_id: id, user_id: Number(who.value) });
    const pr = tr.querySelector('[data-sim="priority"]');
    if (pr && Number(pr.value) !== Number(tr.dataset.origPrio)) changes.push({ type: 'priority', task_id: id, priority_id: Number(pr.value) || null });
    if (tr.querySelector('[data-sim="unblock"]')?.checked) changes.push({ type: 'unblock', task_id: id });
  });
  root.querySelectorAll('[data-user]').forEach((l) => {
    const v = l.querySelector('[data-sim="capacity"]').value;
    if (v !== '' && Number(v) !== Number(l.dataset.orig === '' ? NaN : l.dataset.orig)) changes.push({ type: 'capacity', user_id: Number(l.dataset.user), hours: Number(v) });
  });
  return changes;
}

function wireApply(root) {
  const box = root.querySelector('[data-sim-result]');
  const confirm = box.querySelector('[data-sim-confirm]');
  const btn = box.querySelector('[data-sim-apply-btn]');
  if (!confirm || !btn) return;
  confirm.addEventListener('change', () => { btn.disabled = !confirm.checked; });
  btn.addEventListener('click', async () => {
    if (!confirm.checked) return;
    btn.disabled = true;
    btn.setAttribute('aria-busy', 'true');
    try {
      const data = await api(`/api/analysis/sprints/${root.dataset.sprint}/apply`, { body: { changes: collect(root), fingerprint: root.dataset.fingerprint, confirm: true } });
      toast(data.message || 'Escenario aplicado');
      if (/[?&]move=/.test(location.search)) history.replaceState(null, '', location.pathname + location.search.replace(/([?&])move=[^&]*&?/, '$1').replace(/[?&]$/, ''));
      await refresh();
    } catch (err) {
      toast(err.message, { type: 'error' });
      btn.disabled = false;
    } finally {
      btn.removeAttribute('aria-busy');
    }
  });
}

async function simulate(root) {
  const run = root.querySelector('[data-sim-run]');
  run.setAttribute('aria-busy', 'true');
  try {
    const data = await api(`/api/analysis/sprints/${root.dataset.sprint}/simulate`, { body: { changes: collect(root) } });
    root.querySelector('[data-sim-result]').innerHTML = data.html;
    root.dataset.fingerprint = data.result.fingerprint;
    wireApply(root);
  } catch (err) {
    toast(err.message, { type: 'error' });
  } finally {
    run.removeAttribute('aria-busy');
  }
}

function initSim() {
  const root = document.getElementById('an-sim');
  if (!root || root.dataset.wired) return;
  root.dataset.wired = '1';
  root.querySelector('[data-sim-run]').addEventListener('click', () => simulate(root));
  root.querySelector('[data-sim-reset]').addEventListener('click', () => {
    root.querySelectorAll('tr[data-task]').forEach((tr) => {
      tr.querySelector('[data-sim="move"]').value = '';
      tr.querySelector('[data-sim="estimate"]').value = tr.dataset.origEst;
      tr.querySelector('[data-sim="due"]').value = tr.dataset.origDue;
      const who = tr.querySelector('[data-sim="assign"]'); if (who) who.value = tr.dataset.origUser;
      const pr = tr.querySelector('[data-sim="priority"]'); if (pr) pr.value = tr.dataset.origPrio;
      const ub = tr.querySelector('[data-sim="unblock"]'); if (ub) ub.checked = false;
    });
    root.querySelectorAll('[data-user]').forEach((l) => { l.querySelector('[data-sim="capacity"]').value = l.dataset.orig; });
    simulate(root);
  });
  wireApply(root);
}

initSim();
onInit(() => initSim());
