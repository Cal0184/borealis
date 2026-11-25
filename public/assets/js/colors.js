// Friendlier colour picker: a swatch that opens a curated palette (plus "custom").
// Enhances every input.color-input; the real input keeps the value, so forms and
// inline saves work exactly as before.
import { onInit } from './core.js?v=1.16.4';

const PALETTE = [
  ['#10B981', 'Aurora'], ['#059669', 'Bosque'], ['#14B8A6', 'Laguna'], ['#0EA5E9', 'Cielo'],
  ['#6366F1', 'Índigo'], ['#8B5CF6', 'Violeta'], ['#EC4899', 'Flor'], ['#F43F5E', 'Fruto'],
  ['#F97316', 'Otoño'], ['#F59E0B', 'Ámbar'], ['#84CC16', 'Brote'], ['#64748B', 'Piedra'],
];
let open = null;

function close() { open?.pop.remove(); open?.btn.setAttribute('aria-expanded', 'false'); open = null; }

function setValue(input, value) {
  input.value = value;
  input.dispatchEvent(new Event('input', { bubbles: true }));
  input.dispatchEvent(new Event('change', { bubbles: true }));
}

function enhance(input) {
  if (input.dataset.enhanced) return;
  input.dataset.enhanced = '1';
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'swatch-btn';
  btn.setAttribute('aria-haspopup', 'true');
  btn.setAttribute('aria-expanded', 'false');
  const label = input.labels?.[0]?.textContent?.trim() || input.getAttribute('aria-label') || 'Color';
  btn.setAttribute('aria-label', `${label}: elegir color`);
  const paint = () => { btn.style.setProperty('--c', input.value); btn.title = input.value.toUpperCase(); };
  paint();
  input.addEventListener('input', paint);
  input.classList.add('color-native');
  input.after(btn);
  if (input.disabled) btn.disabled = true;
  btn.addEventListener('click', (e) => {
    e.stopPropagation();
    if (open?.input === input) { close(); return; }
    close();
    const pop = document.createElement('div');
    pop.className = 'swatch-pop';
    pop.setAttribute('role', 'listbox');
    pop.setAttribute('aria-label', label);
    for (const [hex, name] of PALETTE) {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'swatch-opt';
      b.style.setProperty('--c', hex);
      b.title = name;
      b.setAttribute('role', 'option');
      b.setAttribute('aria-label', name);
      b.setAttribute('aria-selected', String(hex.toLowerCase() === input.value.toLowerCase()));
      b.addEventListener('click', () => { setValue(input, hex); close(); btn.focus(); });
      pop.appendChild(b);
    }
    const custom = document.createElement('button');
    custom.type = 'button';
    custom.className = 'swatch-custom';
    custom.textContent = 'Otro color…';
    custom.addEventListener('click', () => { close(); input.showPicker ? input.showPicker() : input.click(); });
    pop.appendChild(custom);
    document.body.appendChild(pop);
    const r = btn.getBoundingClientRect();
    const left = Math.min(window.scrollX + r.left, window.scrollX + window.innerWidth - pop.offsetWidth - 8);
    const below = r.bottom + pop.offsetHeight + 8 < window.innerHeight;
    pop.style.left = Math.max(8, left) + 'px';
    pop.style.top = (below ? window.scrollY + r.bottom + 6 : window.scrollY + r.top - pop.offsetHeight - 6) + 'px';
    open = { pop, btn, input };
    btn.setAttribute('aria-expanded', 'true');
    pop.querySelector('[aria-selected="true"], .swatch-opt')?.focus();
  });
}

document.addEventListener('click', (e) => { if (open && !e.target.closest('.swatch-pop')) close(); });
document.addEventListener('keydown', (e) => {
  if (!open) return;
  if (e.key === 'Escape') { e.stopPropagation(); const b = open.btn; close(); b.focus(); return; }
  const opts = [...open.pop.querySelectorAll('button')];
  const i = opts.indexOf(document.activeElement);
  const step = { ArrowRight: 1, ArrowLeft: -1, ArrowDown: 4, ArrowUp: -4 }[e.key];
  if (step && i >= 0) { e.preventDefault(); opts[Math.max(0, Math.min(opts.length - 1, i + step))].focus(); }
}, true);

onInit((root) => (root || document).querySelectorAll('input.color-input').forEach(enhance));
