// Declarative behaviours: data-api forms, data-call buttons, data-patch inline edits,
// menus, dialogs, toggles, selection and bulk actions.
import { api, ApiError, appUrl, toast, refresh, afterSuccess, saveState, fillShare, openDialog, closeDialog, confirmAction, markDirty, clearDirty, onInit } from './core.js?v=1.16.4';
import { openTask, closeDrawer } from './drawer.js?v=1.16.4';

// ---------- Form serialisation ----------
function serialize(form) {
  const out = {};
  const fd = new FormData(form);
  for (const [name, value] of fd.entries()) {
    if (value instanceof File) continue;
    if (name === '_csrf') continue;
    if (name.endsWith('[]')) {
      const key = name.slice(0, -2);
      (out[key] ??= []);
      if (value !== '') out[key].push(value);
    } else {
      out[name] = value; // later fields win (hidden default + checkbox)
    }
  }
  // Unchecked single checkboxes with a value mean "off" for patch semantics.
  form.querySelectorAll('input[type=checkbox]:not([name$="[]"])').forEach((cb) => {
    if (!cb.checked && !(cb.name in out) && cb.name) out[cb.name] = '';
  });
  return out;
}

function fillUrl(template, body) {
  return template.replace(/\{(\w+)\}/g, (_, k) => {
    const v = body[k];
    delete body[k];
    return encodeURIComponent(v ?? '');
  });
}

function showErrors(form, err) {
  form.querySelectorAll('[aria-invalid]').forEach((el) => el.removeAttribute('aria-invalid'));
  form.querySelectorAll('[data-error-for]').forEach((el) => { el.textContent = ''; });
  const box = form.querySelector('[data-form-error]');
  const fieldErrors = err.errors || {};
  let shownInline = false;
  for (const [field, msg] of Object.entries(fieldErrors)) {
    const input = form.querySelector(`[name="${CSS.escape(field)}"], [name="${CSS.escape(field)}[]"]`);
    if (input) input.setAttribute('aria-invalid', 'true');
    const slot = form.querySelector(`[data-error-for="${CSS.escape(field)}"]`);
    if (slot) { slot.textContent = msg; shownInline = true; }
  }
  const general = shownInline ? err.message : (Object.values(fieldErrors)[0] || err.message);
  if (box) { box.textContent = general; } else { toast(general, { type: 'error' }); }
  form.querySelector('[aria-invalid="true"]')?.focus();
}

function clearErrors(form) {
  form.querySelectorAll('[aria-invalid]').forEach((el) => el.removeAttribute('aria-invalid'));
  form.querySelectorAll('[data-error-for]').forEach((el) => { el.textContent = ''; });
  const box = form.querySelector('[data-form-error]');
  if (box) box.textContent = '';
}

async function submitApiForm(form, submitter) {
  if (form.dataset.busy) return; // no duplicate submissions
  if (!form.reportValidity()) return;
  const buttons = [...form.querySelectorAll('button[type=submit], button:not([type])')];
  form.dataset.busy = '1';
  buttons.forEach((b) => { b.disabled = true; });
  if (submitter) submitter.setAttribute('aria-busy', 'true');
  clearErrors(form);
  const method = (form.dataset.method || 'POST').toUpperCase();
  try {
    let data;
    if (form.hasAttribute('data-multipart')) {
      const fd = new FormData(form);
      data = await api(fillUrl(form.dataset.api, {}), { method, formData: fd });
    } else {
      const body = serialize(form);
      const path = fillUrl(form.dataset.api, body);
      data = await api(path, { method, body });
    }
    clearDirty(form);
    const dlg = form.closest('dialog');
    // 1.14: «Crear y añadir otra» (a submit button with data-stay) keeps the dialog open
    const stay = !!submitter?.hasAttribute('data-stay');
    if (form.hasAttribute('data-close') && dlg && !stay) closeDialog(dlg, true);
    if (form.hasAttribute('data-show-link') && (data.share_text || data.link)) {
      const slot = form.querySelector('[data-invite-link]');
      // fillShare sets defaultValue as well: the form is reset right after, and a reset used to
      // empty the box (that was why «Invitar por enlace» seemed to generate nothing).
      if (slot) fillShare(slot, data.share_text || data.link, data.share_title);
    }
    if (form.hasAttribute('data-reset')) {
      // 1.14: fields marked data-keep-value (project, person, date…) survive the reset
      const kept = [...form.querySelectorAll('[data-keep-value]')].map((el) => [el, el.value]);
      form.reset();
      kept.forEach(([el, v]) => { el.value = v; el.dispatchEvent(new Event('change', { bubbles: true })); });
    }
    const link = form.dataset.toastLink === 'task' && data.task ? { href: data.task.url, label: 'Abrir', task: data.task.key }
      : form.dataset.toastLink === 'project' && data.url ? { href: data.url, label: 'Abrir proyecto' }
      : form.dataset.toastLink === 'url' && data.url ? { href: data.url, label: form.dataset.toastLabel || 'Abrir' } : null;
    if (!form.hasAttribute('data-silent')) toast(form.dataset.toast || data.message || 'Guardado', { link });
    afterSuccess(data);
    if (form.dataset.redirectField && data[form.dataset.redirectField]) { location.href = data[form.dataset.redirectField]; return; }
    if (data.redirect) { location.href = data.redirect; return; }
    if (form.dataset.redirect) { location.href = form.dataset.redirect; return; }
    if (form.dataset.onSuccess === 'reload') { location.reload(); return; }
    const keepFocus = form.hasAttribute('data-keep-open') || stay ? form.querySelector('[name=title]')?.id : null;
    await refresh();
    if (keepFocus) {
      const el = document.getElementById(keepFocus);
      el?.closest('.column-add')?.classList.add('open');
      el?.focus();
    }
  } catch (err) {
    if (err instanceof ApiError && err.status !== 401) showErrors(form, err);
  } finally {
    delete form.dataset.busy;
    buttons.forEach((b) => { b.disabled = false; });
    if (submitter) submitter.removeAttribute('aria-busy');
  }
}

// ---------- Buttons that call the API ----------
async function runCall(btn) {
  if (btn.dataset.busy) return;
  if (btn.dataset.confirm) {
    const ok = await confirmAction(btn.dataset.confirm, { label: btn.dataset.confirmLabel || 'Confirmar', style: btn.dataset.confirmStyle || 'danger' });
    if (!ok) return;
  }
  btn.dataset.busy = '1';
  btn.setAttribute('aria-busy', 'true');
  btn.disabled = true;
  try {
    const body = btn.dataset.body ? JSON.parse(btn.dataset.body) : {};
    const data = await api(btn.dataset.call, { method: (btn.dataset.method || 'POST').toUpperCase(), body });
    if (!btn.hasAttribute('data-silent')) toast(data.message || 'Guardado');
    afterSuccess(data);
    btn.closest('.menu')?.classList.remove('open');
    if (btn.dataset.after === 'close-drawer') {
      const inDrawer = !!btn.closest('#drawer');
      closeDrawer(true);
      if (!inDrawer) return; // full task page navigates back to the project
    }
    if (data.redirect) { location.href = data.redirect; return; }
    await refresh();
    if (btn.hasAttribute('data-open-result') && data.task) openTask(data.task.key);
  } catch (err) {
    if (err.status !== 401) toast(err.message, { type: 'error' });
  } finally {
    delete btn.dataset.busy;
    if (btn.isConnected) { btn.removeAttribute('aria-busy'); btn.disabled = false; }
  }
}

// ---------- Inline edits ----------
function fieldValue(el) {
  if (el.type === 'checkbox') return el.checked;
  return el.value === '' ? null : el.value;
}

/** Clipboard with a fallback for pages served over plain http, where navigator.clipboard is missing. */
function copyText(text) {
  if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
  return new Promise((resolve, reject) => {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.cssText = 'position:fixed;top:-1000px;opacity:0';
    document.body.appendChild(ta);
    ta.select();
    let ok = false;
    try { ok = document.execCommand('copy'); } catch { ok = false; }
    ta.remove();
    if (ok) resolve(); else reject(new Error('copy'));
  });
}

async function runPatch(el) {
  if (el.dataset.busy) return;
  const prev = el.dataset.prev;
  const value = fieldValue(el);
  if (el.required && (value === null || value === '')) {
    toast('Este campo no puede quedar vacío.', { type: 'error' });
    if (prev !== undefined) el.value = prev;
    delete el.dataset.dirty;
    return;
  }
  const body = { [el.name]: value };
  (el.dataset.patchWith || '').split(',').filter(Boolean).forEach((pair) => {
    const [name, sel] = pair.split(':');
    const other = document.querySelector(sel);
    if (other) body[name] = fieldValue(other);
  });
  el.dataset.busy = '1';
  el.setAttribute('aria-busy', 'true');
  const quiet = !!el.closest('.ts'); // the task panel has its own «Guardado» indicator
  saveState('saving');
  try {
    const data = await api(el.dataset.patch, { method: 'PATCH', body });
    delete el.dataset.dirty;
    el.dataset.prev = el.type === 'checkbox' ? String(el.checked) : el.value;
    saveState('saved');
    if (!quiet || data.warning) toast(data.warning || data.message || 'Guardado', { timeout: data.warning ? 6000 : 1800, type: data.warning ? 'warn' : undefined });
    afterSuccess(data);
    await refresh();
  } catch (err) {
    saveState('error');
    // 1.10: say what is wrong with this field («Pega un enlace de GitHub…»), not only «Revisa los datos».
    if (err.status !== 401) toast(err.errors?.[el.name] || err.message, { type: 'error', timeout: err.errors?.[el.name] ? 6000 : 3800 });
    if (prev !== undefined) {
      if (el.type === 'checkbox') el.checked = prev === 'true'; else el.value = prev;
    }
    delete el.dataset.dirty;
  } finally {
    delete el.dataset.busy;
    el.removeAttribute('aria-busy');
  }
}

// Several responsible people. Each click is applied on screen at once; the saves run one at a
// time in the background and always send the latest choice, so quick consecutive clicks are
// never lost and an older answer can never undo a newer click. The picker stays open (see
// [data-live] in core.js) and only refreshes once nothing is left to send.
function multiIds(box) {
  const main = Number(box.dataset.main) || null;
  const picked = [...box.querySelectorAll('input[type=checkbox]:checked')].map((c) => Number(c.value));
  return main && picked.includes(main) ? [main, ...picked.filter((x) => x !== main)] : picked;
}
function runMulti(box) {
  if (!box.dataset.pending) saveState('saving');
  box.dataset.pending = '1';
  clearTimeout(box._multiTimer);
  box._multiTimer = setTimeout(() => { box._multiTimer = null; flushMulti(box); }, 300);
}
async function flushMulti(box) {
  if (box._multiBusy) return; // the save on its way checks for newer clicks when it returns
  box._multiBusy = true;
  let ok = true;
  let sent = null;
  try {
    // Keep sending until what the server has is exactly what is on screen.
    while (true) {
      sent = multiIds(box);
      const data = await api(box.dataset.patchMulti, { method: 'PATCH', body: { assignee_ids: sent } });
      if (data.task) box.dataset.main = data.task.assignee_id || '';
      box.querySelectorAll('input[type=checkbox]').forEach((c) => { c.defaultChecked = sent.includes(Number(c.value)); });
      if (JSON.stringify(multiIds(box)) === JSON.stringify(sent)) break;
    }
  } catch (err) {
    ok = false;
    // Back to what the server has; the message says why (e.g. not enough hours).
    box.querySelectorAll('input[type=checkbox]').forEach((c) => { c.checked = c.defaultChecked; });
    if (err.status !== 401) toast(err.message, { type: 'error' });
  } finally {
    box._multiBusy = false;
  }
  if (box._multiTimer) return; // another click is about to be sent; stay «Guardando…»
  delete box.dataset.pending;
  saveState(ok ? 'saved' : 'error');
  await refresh();
}

// ---------- Menus ----------
function closeMenus(except = null) {
  document.querySelectorAll('.menu.open').forEach((m) => {
    if (m === except) return;
    m.classList.remove('open', 'floating');
    m.style.top = m.style.left = '';
    m.previousElementSibling?.setAttribute('aria-expanded', 'false');
  });
}

// Menus inside scrolling areas (board columns, tables) would be clipped: float them over the page.
function insideScroller(el) {
  for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
    const o = getComputedStyle(p);
    if (/(auto|scroll|hidden)/.test(o.overflowX + o.overflowY)) return true;
  }
  return false;
}
function placeFloating(btn, menu) {
  const r = btn.getBoundingClientRect();
  menu.classList.add('floating');
  const w = menu.offsetWidth;
  const h = menu.offsetHeight;
  const left = Math.max(8, Math.min(r.right - w, window.innerWidth - w - 8));
  const top = r.bottom + 6 + h > window.innerHeight && r.top - 6 - h > 0 ? r.top - 6 - h : r.bottom + 6;
  menu.style.left = left + 'px';
  menu.style.top = top + 'px';
}
window.addEventListener('scroll', () => { if (document.querySelector('.menu.floating')) closeMenus(); }, true);
window.addEventListener('resize', () => closeMenus());

async function toggleMenu(btn) {
  const menu = btn.nextElementSibling;
  if (!menu?.classList.contains('menu')) return;
  const open = !menu.classList.contains('open');
  closeMenus(menu);
  menu.classList.toggle('open', open);
  btn.setAttribute('aria-expanded', String(open));
  if (open) {
    const r = btn.getBoundingClientRect();
    menu.classList.toggle('up', r.bottom > window.innerHeight - 260 && r.top > 300);
    if (btn.dataset.menuSrc) {
      const res = await fetch(btn.dataset.menuSrc, { credentials: 'same-origin' });
      menu.innerHTML = res.ok ? await res.text() : '<p class="muted" style="padding:12px">No se pudo cargar.</p>';
    }
    if (insideScroller(btn)) { menu.classList.remove('up'); placeFloating(btn, menu); }
    menu.querySelector('button:not([disabled]), a')?.focus();
  }
}

// ---------- Selection & bulk actions ----------
function selectedIds() {
  return [...document.querySelectorAll('[data-select]:checked')].map((c) => Number(c.dataset.select));
}
function updateBulk() {
  const bar = document.querySelector('[data-bulk-bar]');
  if (!bar) return;
  const ids = selectedIds();
  bar.classList.toggle('show', ids.length > 0);
  bar.querySelector('[data-bulk-count]').textContent = ids.length === 1 ? '1 seleccionada' : `${ids.length} seleccionadas`;
  document.querySelectorAll('[data-select]').forEach((c) => c.closest('tr, li')?.classList.toggle('is-selected', c.checked));
}
async function bulk(action, value) {
  const ids = selectedIds();
  if (!ids.length) return;
  if (action === 'delete') {
    const ok = await confirmAction(`¿Eliminar ${ids.length} ${ids.length === 1 ? 'tarea' : 'tareas'} y sus subtareas?`, { label: 'Eliminar' });
    if (!ok) return;
  }
  try {
    const data = await api('/api/tasks/bulk', { body: { ids, action, value } });
    toast(data.message);
    await refresh();
    updateBulk();
  } catch (err) { toast(err.message, { type: 'error' }); }
}

// ---------- Event wiring (delegated, so swapped regions keep working) ----------
document.addEventListener('submit', (e) => {
  const form = e.target;
  if (form.matches('form[data-api]')) {
    e.preventDefault();
    submitApiForm(form, e.submitter);
  } else if (form.matches('form[method=post]')) {
    // Plain forms (login, logout…): block double submission.
    if (form.dataset.sent) { e.preventDefault(); return; }
    form.dataset.sent = '1';
    e.submitter?.setAttribute('aria-busy', 'true');
  }
});

document.addEventListener('click', (e) => {
  const t = e.target;
  const call = t.closest('[data-call]');
  if (call && !call.disabled) { e.preventDefault(); runCall(call); return; }

  const opener = t.closest('[data-open-task]');
  if (opener && !e.ctrlKey && !e.metaKey && !e.shiftKey) {
    e.preventDefault();
    closeMenus();
    openTask(opener.dataset.openTask, opener);
    return;
  }

  // A click anywhere on a list row that is not a control opens the task (the name is read-only there).
  const row = t.closest('tr[data-row-open]');
  if (row && !t.closest('a, button, input, select, textarea, label, .menu-wrap') && !window.getSelection()?.toString()) {
    openTask(row.dataset.rowOpen, row);
    return;
  }

  const dlgBtn = t.closest('[data-dialog]');
  if (dlgBtn) { e.preventDefault(); closeMenus(); openDialog(document.getElementById(dlgBtn.dataset.dialog)); return; }

  const closeBtn = t.closest('[data-close-dialog]');
  if (closeBtn) { e.preventDefault(); closeDialog(closeBtn.closest('dialog')); return; }

  const menuBtn = t.closest('[data-menu]');
  if (menuBtn) { e.preventDefault(); e.stopPropagation(); toggleMenu(menuBtn); return; }
  if (!t.closest('.menu')) closeMenus();

  const toggler = t.closest('[data-toggle]');
  if (toggler) {
    e.preventDefault();
    toggler.dataset.toggle.split(',').forEach((sel) => {
      const el = document.querySelector(sel.trim());
      if (!el) return;
      el.hidden = !el.hidden;
      if (toggler.hasAttribute('data-reset-form') && el.tagName === 'FORM' && el.hidden) { el.reset(); clearDirty(el); }
      if (!el.hidden) el.querySelector('textarea, input:not([type=hidden])')?.focus();
    });
    return;
  }

  const classToggler = t.closest('[data-toggle-class]');
  if (classToggler) {
    const el = document.querySelector(classToggler.dataset.toggleClass);
    el?.classList.toggle(classToggler.dataset.class || 'open');
    if (classToggler.dataset.focus && el?.classList.contains(classToggler.dataset.class || 'open')) document.querySelector(classToggler.dataset.focus)?.focus();
    return;
  }

  // 1.14: «Esta semana» / «Este mes» fill a pair of date fields
  const fill = t.closest('[data-fill-dates]');
  if (fill) {
    e.preventDefault();
    try {
      for (const [sel, v] of Object.entries(JSON.parse(fill.dataset.fillDates))) {
        const el = document.querySelector(sel);
        if (el) { el.value = v; el.dispatchEvent(new Event('change', { bubbles: true })); }
      }
      fill.closest('form')?.querySelector('details.more-options')?.setAttribute('open', '');
    } catch { /* malformed */ }
    return;
  }

  const copy = t.closest('[data-copy]');
  if (copy) {
    // 1.10: a copy button next to a field copies what the field holds right now (even before it is saved).
    const from = copy.dataset.copyFrom ? document.querySelector(copy.dataset.copyFrom) : null;
    const text = ((from ? from.value : '') || copy.dataset.copy || '').trim();
    closeMenus();
    if (!text) { toast('Todavía no hay un enlace que copiar.', { type: 'warn' }); return; }
    copyText(text).then(() => toast(copy.dataset.copyMsg || 'Enlace copiado'), () => toast('No se pudo copiar. Selecciónalo y cópialo a mano: ' + text, { type: 'warn', timeout: 8000 }));
    return;
  }

  if (t.closest('[data-sidebar-toggle]')) { document.getElementById('sidebar')?.classList.toggle('open'); document.querySelector('.sidebar-scrim')?.classList.toggle('open'); return; }
  if (t.closest('[data-sidebar-close]')) { document.getElementById('sidebar')?.classList.remove('open'); document.querySelector('.sidebar-scrim')?.classList.remove('open'); return; }
  if (t.closest('[data-drawer-close]')) { closeDrawer(); return; }

  if (t.closest('[data-bulk-clear]')) { document.querySelectorAll('[data-select]:checked, [data-select-all]').forEach((c) => { c.checked = false; }); updateBulk(); return; }
  if (t.closest('[data-bulk-delete]')) { bulk('delete', null); return; }

  const leave = t.closest('[data-confirm-leave]');
  if (leave && document.querySelector('form[data-dirty]') && leave.href) {
    e.preventDefault();
    confirmAction('Tienes cambios sin guardar. ¿Salir sin guardar?', { label: 'Salir sin guardar' }).then((yes) => {
      if (!yes) return;
      document.querySelectorAll('form[data-dirty]').forEach((f) => f.removeAttribute('data-dirty'));
      location.href = leave.href;
    });
  }
});

document.addEventListener('change', (e) => {
  const el = e.target;
  if (el.matches('[data-patch]')) { runPatch(el); return; }
  if (el.matches('[data-select]')) { updateBulk(); return; }
  if (el.matches('[data-select-all]')) {
    el.closest('[data-selectable]')?.querySelectorAll('[data-select]').forEach((c) => { c.checked = el.checked; });
    updateBulk();
    return;
  }
  if (el.matches('[data-bulk]')) {
    if (el.value !== '') bulk(el.dataset.bulk, el.value);
    el.value = '';
    return;
  }
  if (el.matches('[data-module-toggle]')) {
    const on = el.checked;
    el.disabled = true;
    api(`/api/projects/${el.dataset.project}/modules`, { body: { module: el.dataset.moduleToggle, enabled: on } })
      .then((d) => { toast(d.message); return refresh(); })
      .catch((err) => { el.checked = !on; toast(err.message, { type: 'error' }); })
      .finally(() => { el.disabled = false; });
    return;
  }
  const multi = el.closest('[data-patch-multi]');
  if (multi && el.type === 'checkbox') { runMulti(multi); return; }
  if (el.matches('[data-switch-workspace]')) {
    api('/api/workspace/switch', { body: { workspace_id: el.value } }).then((d) => { location.href = d.redirect; }).catch((err) => toast(err.message, { type: 'error' }));
    return;
  }
  if (el.matches('[data-autosubmit]')) {
    const form = el.form;
    if (form) form.requestSubmit();
    return;
  }
  if (el.closest('form[data-api]') && !el.matches('[data-patch]')) markDirty(el);
});

document.addEventListener('input', (e) => {
  const el = e.target;
  if (el.matches('[data-patch]')) { el.dataset.dirty = '1'; return; }
  if (el.closest('form[data-api]') && el.type !== 'hidden' && el.type !== 'file') markDirty(el);
});

document.addEventListener('focusin', (e) => {
  const el = e.target;
  if (el.matches?.('[data-patch]') && el.dataset.prev === undefined) {
    el.dataset.prev = el.type === 'checkbox' ? String(el.checked) : el.value;
  }
});

document.addEventListener('keydown', (e) => {
  const el = e.target;
  if (e.key === 'Escape') {
    if (document.querySelector('.menu.open')) { closeMenus(); return; }
  }
  if (e.key === 'Enter' && el.matches?.('input[data-patch]')) { e.preventDefault(); el.blur(); return; }
  if (e.key === 'Enter' && !e.shiftKey && el.matches?.('textarea[data-enter-submit]')) { e.preventDefault(); el.form?.requestSubmit(); return; }
  if (e.key === 'Enter' && (e.ctrlKey || e.metaKey) && el.tagName === 'TEXTAREA' && el.form) { e.preventDefault(); el.form.requestSubmit(); return; }
  if (e.key === 'Enter' && el.matches?.('.description-view[data-toggle]')) { e.preventDefault(); el.click(); return; }
  if (e.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName) && !el.isContentEditable) {
    const s = document.getElementById('global-search');
    if (s && s.offsetParent) { e.preventDefault(); s.focus(); }
  }
});

// Esc on a modal: never lose typed data silently.
document.addEventListener('cancel', (e) => {
  const dlg = e.target;
  if (dlg.id === 'dlg-confirm') return;
  if (dlg.tagName === 'DIALOG') { e.preventDefault(); closeDialog(dlg); }
}, true);

window.addEventListener('beforeunload', (e) => {
  if (document.querySelector('dialog[open] [data-dirty], #drawer.open [data-dirty], form[data-api][data-dirty]:not([hidden])')) {
    e.preventDefault();
    e.returnValue = '';
  }
});

// ---------- Drop zones ----------
document.addEventListener('dragover', (e) => {
  const zone = e.target.closest?.('[data-drop-zone]');
  if (zone && e.dataTransfer?.types.includes('Files')) { e.preventDefault(); zone.classList.add('over'); }
});
document.addEventListener('dragleave', (e) => { e.target.closest?.('[data-drop-zone]')?.classList.remove('over'); });
document.addEventListener('drop', (e) => {
  const zone = e.target.closest?.('[data-drop-zone]');
  if (!zone || !e.dataTransfer?.files.length) return;
  e.preventDefault();
  zone.classList.remove('over');
  const input = zone.querySelector('input[type=file]');
  input.files = e.dataTransfer.files;
  input.form.requestSubmit();
});
document.addEventListener('change', (e) => {
  if (e.target.matches('input[type=file][data-autosubmit]') && e.target.files.length) e.target.form.requestSubmit();
});

// Options that only apply to some values of another field (e.g. responsables per project).
function applyOptionFilter(sel) {
  const src = document.querySelector(sel.dataset.filterBy);
  if (!src) return;
  sel.querySelectorAll('option[data-projects]').forEach((o) => {
    const ok = o.dataset.projects.split(',').includes(src.value);
    o.hidden = !ok;
    o.disabled = !ok;
  });
  if (sel.selectedOptions[0]?.disabled) sel.value = '';
}
document.addEventListener('change', (e) => {
  document.querySelectorAll('select[data-filter-by]').forEach((sel) => { if (e.target.matches(sel.dataset.filterBy)) applyOptionFilter(sel); });
});
document.addEventListener('click', (e) => {
  if (e.target.closest('[data-dialog]')) setTimeout(() => document.querySelectorAll('select[data-filter-by]').forEach(applyOptionFilter), 0);
});

// Project initials: proposed from the name until the person types their own.
function suggestKey(name) {
  const words = name.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toUpperCase().split(/[^A-Z0-9]+/).filter(Boolean);
  let base = !words.length ? 'PRY' : words.length === 1 ? words[0].slice(0, 3) : words.slice(0, 4).map((w) => w[0]).join('');
  if (base.length < 2) base = base.padEnd(2, 'X');
  if (/^\d/.test(base)) base = 'P' + base;
  return base.slice(0, 6);
}
function showKey(input) {
  const v = (input.value || input.placeholder || 'WEB').toUpperCase();
  input.closest('form')?.querySelectorAll('[data-key-preview], [data-key-preview-2]').forEach((el) => { el.textContent = v; });
}
document.addEventListener('input', (e) => {
  const el = e.target;
  if (el.matches?.('[data-key-source]')) {
    const key = document.querySelector(el.dataset.keySource);
    if (key) { key.placeholder = suggestKey(el.value) || 'WEB'; if (!key.dataset.touched) key.value = ''; showKey(key); }
  } else if (el.matches?.('.key-input')) {
    el.dataset.touched = el.value ? '1' : '';
    el.value = el.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
    showKey(el);
  }
});

// Sliding indicator under the current section tab.
function placeInk(nav, link) {
  const ink = nav.querySelector('.tab-ink');
  if (!ink || !link) { if (ink) ink.style.width = '0'; return; }
  ink.style.width = link.offsetWidth + 'px';
  ink.style.transform = `translateX(${link.offsetLeft}px)`;
}
function initSliders(root) {
  (root || document).querySelectorAll('[data-slider]').forEach((nav) => {
    if (!nav.querySelector('.tab-ink')) nav.prepend(Object.assign(document.createElement('span'), { className: 'tab-ink' }));
    placeInk(nav, nav.querySelector('[aria-current="page"]'));
    nav.querySelector('[aria-current="page"]')?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    requestAnimationFrame(() => nav.classList.add('ready'));
  });
}
document.addEventListener('click', (e) => {
  const a = e.target.closest('[data-slider] a');
  if (a && !e.ctrlKey && !e.metaKey) placeInk(a.closest('[data-slider]'), a);
});
window.addEventListener('resize', () => document.querySelectorAll('[data-slider]').forEach((n) => placeInk(n, n.querySelector('[aria-current="page"]'))));

// "Add member" dialog: switch between its three ways.
document.addEventListener('click', (e) => {
  const tab = e.target.closest('[data-am-tab]');
  if (!tab) return;
  e.preventDefault();
  const dlg = tab.closest('dialog');
  dlg.querySelectorAll('[data-am-tab]').forEach((t) => { if (t !== tab) t.removeAttribute('aria-current'); else t.setAttribute('aria-current', 'page'); });
  dlg.querySelectorAll('[data-am-panel]').forEach((p) => { p.hidden = p.id !== tab.dataset.amTab; });
  dlg.querySelector(`#${tab.dataset.amTab} input:not([type=hidden]), #${tab.dataset.amTab} select`)?.focus();
});
// Temporary passwords: proposed automatically, still editable.
function genPassword() {
  const abc = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
  const a = new Uint32Array(12);
  crypto.getRandomValues(a);
  return [...a].map((n) => abc[n % abc.length]).join('');
}
document.addEventListener('click', (e) => {
  if (!e.target.closest('[data-dialog]')) return;
  setTimeout(() => document.querySelectorAll('dialog[open] input[data-generate-password]').forEach((i) => { if (!i.value) i.value = genPassword(); }), 0);
});
document.addEventListener('reset', (e) => {
  e.target.querySelectorAll?.('input[data-generate-password]').forEach((i) => setTimeout(() => { i.value = genPassword(); }, 0));
});

onInit((root) => { initSliders(root); updateBulk(); document.querySelectorAll('select[data-filter-by]').forEach(applyOptionFilter); });
export { appUrl };
