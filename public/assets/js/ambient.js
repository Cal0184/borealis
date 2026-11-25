// Perfil › Apariencia: one click applies the background at once and saves it for this person only.
import { api, toast, saveState } from './core.js?v=1.16.3';

document.addEventListener('click', async (e) => {
  const btn = e.target.closest('[data-ambient-pick]');
  if (!btn) return;
  const key = btn.dataset.ambientPick;
  const html = document.documentElement;
  const before = html.dataset.ambient || 'default';
  html.dataset.ambient = key; // immediately, before the server answers
  document.querySelectorAll('[data-ambient-pick]').forEach((b) => b.setAttribute('aria-pressed', String(b === btn)));
  saveState('saving');
  try {
    await api('/api/profile/prefs', { method: 'PATCH', body: { ambient: key } });
    saveState('saved');
  } catch (err) {
    html.dataset.ambient = before;
    document.querySelectorAll('[data-ambient-pick]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.ambientPick === before)));
    saveState('error');
    if (err.status !== 401) toast(err.message, { type: 'error' });
  }
});
