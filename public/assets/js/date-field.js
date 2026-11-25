// Dates as dd/mm/aaaa everywhere (1.7.1). A browser's own date field shows the format of the
// browser's language (mm/dd/aaaa in English), so each <input type="date"> gets a text field in
// front that reads and writes dd/mm/aaaa, plus a button that opens the browser's calendar.
// The original field stays in the form with its name and keeps the ISO value (aaaa-mm-dd) that
// the server receives, so saving, autosave (data-patch) and validation work exactly as before.
import { onInit } from './core.js?v=1.16.5';

const proto = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
const iso = (el) => proto.get.call(el);

export function toText(v) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v || '');
  return m ? `${m[3]}/${m[2]}/${m[1]}` : '';
}

/** dd/mm/aaaa (also d/m/aa, with - or .), or aaaa-mm-dd → aaaa-mm-dd. '' when empty, null when invalid. */
export function parse(text) {
  const t = (text || '').trim();
  if (t === '') return '';
  let d; let m; let y;
  let r = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(t);
  if (r) [, y, m, d] = r;
  else {
    r = /^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2}|\d{4})$/.exec(t) || /^(\d{2})(\d{2})(\d{4})$/.exec(t);
    if (!r) return null;
    [, d, m, y] = r;
    if (y.length === 2) y = `20${y}`;
  }
  const dt = new Date(Date.UTC(+y, +m - 1, +d));
  if (dt.getUTCFullYear() !== +y || dt.getUTCMonth() !== +m - 1 || dt.getUTCDate() !== +d) return null;
  return `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
}

function commit(field) {
  const { orig, txt } = field;
  const v = parse(txt.value);
  let msg = '';
  if (v === null) msg = 'Escribe la fecha como dd/mm/aaaa, por ejemplo 09/11/2026.';
  else if (v === '' && field.required) msg = 'Indica una fecha.';
  else if (v && orig.min && v < orig.min) msg = `La fecha no puede ser anterior al ${toText(orig.min)}.`;
  else if (v && orig.max && v > orig.max) msg = `La fecha no puede ser posterior al ${toText(orig.max)}.`;
  txt.setCustomValidity(msg);
  if (msg) return false;
  txt.value = toText(v);
  if (v !== iso(orig)) {
    if (orig.matches('[data-patch]') && orig.dataset.prev === undefined) orig.dataset.prev = iso(orig);
    proto.set.call(orig, v);
    orig.dispatchEvent(new Event('input', { bubbles: true }));
    orig.dispatchEvent(new Event('change', { bubbles: true }));
  }
  return true;
}

function enhance(orig) {
  if (orig.dataset.dateField) return;
  orig.dataset.dateField = '1';
  const wrap = document.createElement('span');
  wrap.className = 'date-field';
  const txt = document.createElement('input');
  txt.type = 'text';
  txt.className = `${orig.className} date-text`;
  txt.inputMode = 'numeric';
  txt.autocomplete = 'off';
  txt.placeholder = 'dd/mm/aaaa';
  txt.maxLength = 10;
  txt.title = 'Fecha en formato dd/mm/aaaa';
  // The visible field takes the id, so every <label for> and test keeps pointing at what people use.
  if (orig.id) { txt.id = orig.id; orig.id = `${orig.id}-iso`; }
  for (const a of ['aria-label', 'aria-describedby', 'aria-labelledby']) if (orig.hasAttribute(a)) txt.setAttribute(a, orig.getAttribute(a));
  txt.disabled = orig.disabled;
  txt.readOnly = orig.readOnly;
  const field = { orig, txt, required: orig.required };
  // In a normal form the browser checks «required» on the visible field. Autosaved fields
  // (data-patch) keep it on the original, which is what the autosave code reads.
  if (orig.required && !orig.matches('[data-patch]')) { txt.required = true; orig.required = false; }
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'date-pick';
  btn.setAttribute('aria-label', 'Elegir en el calendario');
  btn.title = 'Elegir en el calendario';
  btn.disabled = orig.disabled;
  btn.innerHTML = '<svg class="icon sm" aria-hidden="true" focusable="false"><use href="' + (document.querySelector('use[href*="icons.svg"]')?.getAttribute('href')?.split('#')[0] || '') + '#i-calendar"></use></svg>';
  orig.classList.add('date-native');
  orig.tabIndex = -1;
  orig.setAttribute('aria-hidden', 'true');
  orig.before(wrap);
  wrap.append(txt, btn, orig);
  txt.value = toText(iso(orig));
  txt.defaultValue = txt.value;

  // Code that sets the value (filling a dialog, resetting a form) updates what is shown.
  Object.defineProperty(orig, 'value', {
    configurable: true,
    get() { return iso(this); },
    set(v) { proto.set.call(this, v); txt.value = toText(iso(this)); txt.setCustomValidity(''); },
  });
  orig.addEventListener('change', () => { txt.value = toText(iso(orig)); txt.setCustomValidity(''); });
  orig.form?.addEventListener('reset', () => setTimeout(() => { txt.value = toText(iso(orig)); txt.setCustomValidity(''); }));
  txt.addEventListener('input', (e) => {
    txt.setCustomValidity('');
    // Typing 0911 2026 adds the slashes: 09/11/2026.
    if (e.inputType === 'insertText' && /^\d{2}$|^\d{2}\/\d{2}$/.test(txt.value)) txt.value += '/';
  });
  txt.addEventListener('change', () => { if (!commit(field)) txt.reportValidity(); });
  txt.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return;
    if (!commit(field)) { e.preventDefault(); txt.reportValidity(); return; }
    if (orig.matches('[data-patch]')) { e.preventDefault(); txt.blur(); }
  });
  btn.addEventListener('click', () => {
    try { orig.showPicker(); } catch { txt.focus(); }
  });
  wrap.dateField = field;
}

// Before any form is sent, what is written in the visible fields becomes the value sent.
document.addEventListener('submit', (e) => {
  for (const w of e.target.querySelectorAll?.('.date-field') || []) {
    if (w.dateField && !commit(w.dateField)) {
      e.preventDefault();
      e.stopImmediatePropagation();
      w.dateField.txt.reportValidity();
      return;
    }
  }
}, true);

onInit((root) => root.querySelectorAll?.('input[type="date"]').forEach(enhance));

// Hours as hh:mm (24 h) whatever the browser's language (1.9). A browser's own time field shows
// 04:30 PM in English, so each <input type="time"> becomes a text field with the same id and name
// that accepts 16:30, 1630, 930 or 9 and stores hh:mm. The server validates it again.
/** hh:mm, hh:mm:ss, h:mm, hhmm, hmm or h → hh:mm. '' when empty, null when invalid. */
export function parseTime(text) {
  const t = (text || '').trim();
  if (t === '') return '';
  const r = /^(\d{1,2})(?:[:.h]?(\d{2}))?(?::\d{2})?$/.exec(t);
  if (!r) return null;
  const h = +r[1]; const m = +(r[2] || 0);
  if (h > 23 || m > 59) return null;
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
}

const TIME_MSG = 'Escribe la hora como hh:mm, por ejemplo 16:30.';
function commitTime(el) {
  const v = parseTime(el.value);
  el.setCustomValidity(v === null ? TIME_MSG : '');
  if (v === null) return false;
  if (el.value !== v) el.value = v;
  return true;
}

function enhanceTime(el) {
  if (el.dataset.timeField) return;
  el.dataset.timeField = '1';
  const v = el.value;
  el.type = 'text';
  el.value = (v || '').slice(0, 5);
  el.defaultValue = el.value;
  el.inputMode = 'numeric';
  el.autocomplete = 'off';
  el.placeholder = 'hh:mm';
  el.maxLength = 5;
  if (!el.title) el.title = 'Hora en formato de 24 horas (hh:mm)';
  el.classList.add('time-text');
  el.addEventListener('input', (e) => {
    el.setCustomValidity('');
    if (e.inputType === 'insertText' && /^\d{2}$/.test(el.value) && +el.value <= 23) el.value += ':';
  });
}

// Runs before any other change handler (autosave included), so they only ever see hh:mm.
document.addEventListener('change', (e) => {
  const el = e.target;
  if (!el?.dataset?.timeField || commitTime(el)) return;
  e.stopImmediatePropagation();
  el.reportValidity();
}, true);
document.addEventListener('submit', (e) => {
  for (const el of e.target.querySelectorAll?.('[data-time-field]') || []) {
    if (!commitTime(el)) { e.preventDefault(); e.stopImmediatePropagation(); el.reportValidity(); return; }
  }
}, true);

onInit((root) => root.querySelectorAll?.('input[type="time"]').forEach(enhanceTime));
