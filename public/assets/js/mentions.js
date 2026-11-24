// @mentions: suggests project members while typing in a comment.
import { appUrl } from './core.js?v=1.15.1';

const cache = new Map();
let pop = null;
let state = null;

async function members(src) {
  if (!cache.has(src)) {
    cache.set(src, fetch(appUrl(src), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then((r) => (r.ok ? r.json() : { members: [] }))
      .then((d) => d.members || [])
      .catch(() => []));
  }
  return cache.get(src);
}

function close() { pop?.remove(); pop = null; state = null; }

function choose(m) {
  const { ta, at } = state;
  const caret = ta.selectionStart;
  const insert = '@' + m.handle + ' ';
  ta.value = ta.value.slice(0, at) + insert + ta.value.slice(caret);
  const pos = at + insert.length;
  ta.setSelectionRange(pos, pos);
  ta.dispatchEvent(new Event('input', { bubbles: true }));
  close();
  ta.focus();
}

function render(list) {
  if (!pop) {
    pop = document.createElement('div');
    pop.className = 'mention-pop';
    pop.setAttribute('role', 'listbox');
    pop.setAttribute('aria-label', 'Mencionar a');
    document.body.appendChild(pop);
  }
  pop.innerHTML = '';
  list.forEach((m, i) => {
    const b = document.createElement('button');
    b.type = 'button';
    b.setAttribute('role', 'option');
    b.setAttribute('aria-selected', String(i === state.index));
    const name = document.createElement('span');
    name.textContent = m.name;
    const h = document.createElement('span');
    h.className = 'muted small';
    h.textContent = '@' + m.handle;
    b.append(name, h);
    b.addEventListener('mousedown', (e) => { e.preventDefault(); choose(m); });
    pop.appendChild(b);
  });
  const r = state.ta.getBoundingClientRect();
  pop.style.left = window.scrollX + r.left + 'px';
  pop.style.top = window.scrollY + r.bottom + 4 + 'px';
}

document.addEventListener('input', async (e) => {
  const ta = e.target;
  if (!ta.matches?.('textarea[data-mentions]')) return;
  const before = ta.value.slice(0, ta.selectionStart);
  const m = /(^|\s)@([\p{L}\p{N}._-]{0,30})$/u.exec(before);
  if (!m) { close(); return; }
  const q = m[2].toLowerCase();
  const all = await members(ta.dataset.mentions);
  const list = all.filter((u) => u.handle.toLowerCase().startsWith(q) || u.name.toLowerCase().includes(q)).slice(0, 8);
  if (!list.length) { close(); return; }
  state = { ta, at: before.length - m[2].length - 1, list, index: 0 };
  render(list);
});

document.addEventListener('keydown', (e) => {
  if (!state || e.target !== state.ta) return;
  if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
    e.preventDefault();
    state.index = (state.index + (e.key === 'ArrowDown' ? 1 : -1) + state.list.length) % state.list.length;
    render(state.list);
  } else if (e.key === 'Enter' || e.key === 'Tab') {
    e.preventDefault();
    e.stopImmediatePropagation();
    choose(state.list[state.index]);
  } else if (e.key === 'Escape') {
    e.preventDefault();
    e.stopImmediatePropagation();
    close();
  }
}, true);

document.addEventListener('focusout', (e) => { if (state && e.target === state.ta) setTimeout(close, 150); });
