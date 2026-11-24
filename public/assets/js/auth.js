// Login, registration, invitation and installer pages: block double submission.
import './email-check.js?v=1.14.0';
document.addEventListener('submit', (e) => {
  const form = e.target;
  if (form.dataset.sent) { e.preventDefault(); return; }
  if (!form.checkValidity()) return;
  form.dataset.sent = '1';
  form.querySelectorAll('button[type=submit], button:not([type])').forEach((b) => { b.setAttribute('aria-busy', 'true'); b.disabled = true; });
});
// Back/forward cache: re-enable the form if the page is restored.
window.addEventListener('pageshow', () => {
  document.querySelectorAll('form[data-sent]').forEach((f) => {
    delete f.dataset.sent;
    f.querySelectorAll('button').forEach((b) => { b.disabled = false; b.removeAttribute('aria-busy'); });
  });
});
