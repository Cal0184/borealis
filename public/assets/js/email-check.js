// Stricter e-mail check in the browser (mirrors Validator::emailError on the server).
// The browser's own type=email check only asks for "x@y"; this also requires a real-looking
// domain with a top-level part of 2+ letters and suggests fixes for typos such as gmail.con.
const TYPOS = {
  'gmail.con': 'gmail.com',
  'gmail.co': 'gmail.com',
  'gmail.cm': 'gmail.com',
  'gmail.om': 'gmail.com',
  'gmail.comm': 'gmail.com',
  'gmail.es': 'gmail.com',
  'gmial.com': 'gmail.com',
  'gmal.com': 'gmail.com',
  'gmaill.com': 'gmail.com',
  'gamil.com': 'gmail.com',
  'gnail.com': 'gmail.com',
  'gmai.com': 'gmail.com',
  'gimail.com': 'gmail.com',
  'gmail.cpm': 'gmail.com',
  'gmail.vom': 'gmail.com',
  'gmail.xom': 'gmail.com',
  'hotmail.con': 'hotmail.com',
  'hotmal.com': 'hotmail.com',
  'hotmial.com': 'hotmail.com',
  'hotmai.com': 'hotmail.com',
  'hotmil.com': 'hotmail.com',
  'homail.com': 'hotmail.com',
  'hotmail.co': 'hotmail.com',
  'hotmail.cm': 'hotmail.com',
  'hormail.com': 'hotmail.com',
  'outlok.com': 'outlook.com',
  'outlook.con': 'outlook.com',
  'outloo.com': 'outlook.com',
  'outlook.co': 'outlook.com',
  'otulook.com': 'outlook.com',
  'yaho.com': 'yahoo.com',
  'yahoo.con': 'yahoo.com',
  'yahooo.com': 'yahoo.com',
  'yahoo.co': 'yahoo.com',
  'yhoo.com': 'yahoo.com',
  'icloud.con': 'icloud.com',
  'iclod.com': 'icloud.com',
  'icloud.co': 'icloud.com',
  'live.con': 'live.com',
};

export function emailError(value) {
  const v = String(value || '').trim();
  if (v === '') return '';
  const bad = 'Correo electrónico no válido. Revisa que tenga la forma nombre@dominio.com.';
  const parts = v.split('@');
  if (parts.length !== 2 || v.length > 190) return bad;
  const [local, rawDomain] = parts;
  if (!local || !rawDomain || local.length > 64 || /\s/.test(v)) return bad;
  if (local.startsWith('.') || local.endsWith('.') || local.includes('..')) return bad;
  if (!/^[A-Za-z0-9.!#$%&'*+/=?^_`{|}~-]+$/.test(local)) return bad;
  const domain = rawDomain.toLowerCase();
  const labels = domain.split('.');
  if (labels.length < 2) return bad;
  if (!labels.every((l) => /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/.test(l))) return bad;
  if (!/^[a-z]{2,24}$/.test(labels[labels.length - 1])) return 'El dominio del correo no parece válido (después del último punto debe haber al menos dos letras, como .com o .mx).';
  if (TYPOS[domain]) return `¿Quisiste decir ${local}@${TYPOS[domain]}? Revisa el dominio del correo.`;
  return '';
}

function check(input) {
  if (!input.matches?.('input[type=email]') || input.hasAttribute('data-no-email-check')) return;
  input.setCustomValidity(emailError(input.value));
}

// Validate as the person types (silently) and show the message when they leave the field.
document.addEventListener('input', (e) => check(e.target), true);
document.addEventListener('change', (e) => { check(e.target); if (e.target.validationMessage && e.target.matches?.('input[type=email]')) e.target.reportValidity(); }, true);
document.addEventListener('submit', (e) => { e.target.querySelectorAll?.('input[type=email]').forEach(check); }, true);
