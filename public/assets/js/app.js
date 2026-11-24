// Entry point for the authenticated application.
import { runInit } from './core.js?v=1.14.0';
import './email-check.js?v=1.14.0';
import './date-field.js?v=1.14.0';
import './ui.js?v=1.14.0';
import './sortable.js?v=1.14.0';
import './people-drag.js?v=1.14.0';
import './calendar.js?v=1.14.0';
import './calendar-page.js?v=1.14.0';
import './mentions.js?v=1.14.0';
import './colors.js?v=1.14.0';
import './ambient.js?v=1.14.0';
import './tour.js?v=1.14.0';
import './photo.js?v=1.14.0';
import './push.js?v=1.14.0';
import './analysis.js?v=1.14.0';
import './workload.js?v=1.14.0';
import './gantt.js?v=1.14.0';
import './notify-live.js?v=1.14.0';
import { openFromUrl } from './drawer.js?v=1.14.0';

// The greeting follows the person's own clock (the server only knows the installation's time zone).
function greet() {
  const el = document.querySelector('[data-greeting]');
  if (!el) return;
  const h = new Date().getHours();
  const word = h < 5 ? 'Buenas noches' : h < 12 ? 'Buenos días' : h < 20 ? 'Buenas tardes' : 'Buenas noches';
  el.textContent = el.dataset.name ? `${word}, ${el.dataset.name}` : word;
}
greet();
runInit(document);
openFromUrl();
