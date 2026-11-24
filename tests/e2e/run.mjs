// Borealis browser tests (Playwright + Chromium).
// Requires a running server with an EMPTY database; the suite installs Borealis through the UI.
//   BOREALIS_URL=http://127.0.0.1:8080 BOREALIS_TEST_DB_NAME=... BOREALIS_TEST_DB_USER=... BOREALIS_TEST_DB_PASS=... \
//   node tests/e2e/run.mjs [--shots=dir] [--json=results.json]
import { createRequire } from 'node:module';
import { mkdirSync, writeFileSync } from 'node:fs';
import { randomBytes } from 'node:crypto';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const BASE = (process.env.BOREALIS_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const arg = (n) => process.argv.find((a) => a.startsWith(`--${n}=`))?.split('=').slice(1).join('=');
const SHOTS = arg('shots') || 'tests/e2e/screenshots';
const JSON_OUT = arg('json');
mkdirSync(SHOTS, { recursive: true });

const results = [];
let group = '';
const section = (g) => { group = g; console.log(`\n== ${g}`); };
function check(name, ok, detail = '') {
  results.push({ group, name, ok: !!ok, detail: ok ? '' : String(detail) });
  console.log(`${ok ? '  ✔' : '  ✘'} ${name}${ok || !detail ? '' : `  → ${detail}`}`);
}
async function attempt(name, fn) {
  try { await fn(); } catch (e) { check(name, false, e.message.split('\n')[0]); }
}

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'es-MX' });
const page = await ctx.newPage();
const consoleErrors = [];
page.on('pageerror', (e) => consoleErrors.push(e.message));
page.on('console', (m) => { if (m.type() === 'error' && !/favicon|40[134]|422|419/.test(m.text())) consoleErrors.push(m.text()); });

const pass = 'E2e-' + randomBytes(6).toString('hex');
const email = 'ana@example.test';
const toastText = () => page.locator('#toasts .toast').last().textContent();
const waitToast = async (re) => { await page.locator('#toasts .toast', { hasText: re }).first().waitFor({ timeout: 6000 }); };

// ------------------------------------------------------------------ install through the UI
section('Instalación desde el navegador');
await attempt('Instalador completo', async () => {
  await page.goto(BASE + '/');
  await page.fill('#db_host', process.env.BOREALIS_TEST_DB_HOST || '127.0.0.1');
  await page.fill('#db_name', process.env.BOREALIS_TEST_DB_NAME);
  await page.fill('#db_user', process.env.BOREALIS_TEST_DB_USER);
  await page.fill('#db_pass', process.env.BOREALIS_TEST_DB_PASS || '');
  await page.fill('#app_url', BASE);
  await page.fill('#workspace_name', 'Bosque Norte');
  await page.fill('#admin_name', 'Ana Rivera');
  await page.fill('#admin_email', email);
  await page.fill('#admin_password', pass);
  await page.screenshot({ path: `${SHOTS}/00-instalador.png`, fullPage: true });
  await Promise.all([page.waitForURL((u) => !u.pathname.includes('install'), { timeout: 15000 }), page.click('button[type=submit]')]);
  await page.waitForSelector('#sidebar');
  check('Instalación termina en el inicio con sesión iniciada', await page.locator('#sidebar').isVisible());
});

await attempt('Tutorial la primera vez', async () => {
  await page.locator('.tour-card').waitFor({ timeout: 5000 });
  check('El tutorial empieza solo en el primer ingreso', await page.locator('.tour-card').isVisible() && /bienvenida/i.test(await page.locator('#tour-title').textContent()));
  const stepText = () => page.locator('.tour-step').textContent();
  const next = async () => { const nav = page.waitForNavigation({ timeout: 4000 }).catch(() => null); await page.click('[data-tour-next]'); await nav; await page.locator('.tour-card').waitFor({ timeout: 5000 }).catch(() => {}); await page.waitForTimeout(450); };
  await next();
  const spot = await page.locator('.tour-spot').boundingBox();
  const target = await page.locator('[data-tour="new-task"]').boundingBox();
  check('Cada paso señala un control real de la pantalla', spot && target && Math.abs(spot.y + 6 - target.y) < 12, JSON.stringify([spot, target]));
  await next(); await next();
  check('El tutorial pasa de una página a otra y sigue (Inicio → Proyectos)', new URL(page.url()).pathname === '/projects' && (await stepText()).includes('Paso 4 de') && await page.locator('.tour-spot').isVisible(), page.url());
  const nav = page.waitForNavigation({ timeout: 4000 }).catch(() => null);
  await page.click('[data-tour-prev]');
  await nav; await page.locator('.tour-card').waitFor({ timeout: 5000 }); await page.waitForTimeout(450);
  check('«Anterior» vuelve al paso previo, también en otra página', new URL(page.url()).pathname === '/' && (await stepText()).includes('Paso 3 de'));
  await page.reload();
  await page.locator('.tour-card').waitFor({ timeout: 5000 });
  check('Tras una interrupción (recarga) continúa en el mismo paso', (await stepText()).includes('Paso 3 de'));
  await page.waitForTimeout(350); // let the spotlight finish moving
  await page.screenshot({ path: `${SHOTS}/14-tutorial.png` });
  const skipped = page.waitForResponse((r) => r.url().includes('/api/profile/prefs') && r.request().postData()?.includes('skipped'));
  await page.click('[data-tour-skip]');
  await skipped;
  await page.reload();
  await page.waitForTimeout(900);
  check('Omitido, no vuelve a aparecer al recargar', (await page.locator('.tour-card').count()) === 0);
  await page.locator('[data-tour="account"]').click();
  await page.locator('.menu.open [data-tour-start]').click();
  await page.locator('.tour-card').waitFor({ timeout: 5000 });
  check('Se puede repetir desde el menú de la cuenta (empieza en el paso 1)', (await stepText()).includes('Paso 1 de'));
  const seen = [];
  for (let i = 0; i < 20 && (await page.locator('.tour-card').count()); i++) {
    seen.push([new URL(page.url()).pathname, (await page.locator('#tour-title').textContent()), await page.locator('.tour-spot').isVisible(), await page.locator('.tour-demo').count()]);
    if ((await page.locator('[data-tour-next]').textContent()).trim() === 'Finalizar') break;
    await next();
  }
  const pages = [...new Set(seen.map((x) => x[0]))];
  check('Recorre Inicio, Proyectos, Tareas, Sprints, Calendario y Configuración', ['/', '/projects', '/tasks', '/calendar', '/profile'].every((p) => pages.includes(p)) && ['Sprints', 'Calendario', 'Personal y de equipo', 'Configuración'].every((t) => seen.some((x) => x[1] === t)), JSON.stringify(seen));
  check('Sin proyectos todavía, «Sprints» muestra un ejemplo sin tocar datos', seen.filter((x) => ['Sprints'].includes(x[1])).every((x) => x[3] === 1), JSON.stringify(seen));
  check('El último paso ofrece «Finalizar»', (await page.locator('[data-tour-next]').textContent()).trim() === 'Finalizar');
  const saved = page.waitForResponse((r) => r.url().includes('/api/profile/prefs') && r.request().postData()?.includes('done'));
  await page.click('[data-tour-next]');
  await saved;
  check('Al terminar se cierra', (await page.locator('.tour-card').count()) === 0);
  await page.reload();
  await page.waitForTimeout(900);
  check('Terminado, no vuelve a aparecer', (await page.locator('.tour-card').count()) === 0);
  await page.goto(BASE + '/');
});

// ------------------------------------------------------------------ projects
section('Proyectos en la interfaz');
let projectUrl = '';
await attempt('Crear proyecto', async () => {
  await page.click('[data-dialog="dlg-new-project"]');
  const dlg = page.locator('#dlg-new-project');
  await dlg.waitFor({ state: 'visible' });
  await page.locator('#dlg-new-project button[type=submit]').click();
  check('Sin nombre, el formulario no se envía (validación nativa)', await dlg.isVisible());
  await page.fill('#np-name', 'Sendero Alpino');
  // Clicking outside must not close a modal with typed data.
  await page.mouse.click(10, 10);
  check('Clic fuera del modal no lo cierra ni borra lo escrito', (await dlg.isVisible()) && (await page.inputValue('#np-name')) === 'Sendero Alpino');
  const urlBefore = page.url();
  await page.click('#dlg-new-project button[type=submit]');
  await waitToast(/Proyecto creado/);
  check('Al crear: aviso con enlace y se permanece en la misma pantalla', page.url() === urlBefore && !(await dlg.isVisible()));
  await page.locator('#sidebar-projects a', { hasText: 'Sendero Alpino' }).first().waitFor({ timeout: 4000 });
  check('El proyecto aparece en la barra lateral sin recargar', true);
  projectUrl = await page.locator('#sidebar-projects a', { hasText: 'Sendero Alpino' }).first().getAttribute('href');
});

await attempt('Renombrar proyecto', async () => {
  await page.goto(BASE + projectUrl + '/settings');
  await page.evaluate(() => window.scrollTo(0, 200));
  await page.fill('#ps-name', 'Sendero Boreal');
  await page.click('#project-form button[type=submit]');
  await waitToast(/./);
  await page.waitForFunction(() => document.querySelector('.project-hero, h1')?.textContent.includes('Sendero Boreal'));
  const side = await page.locator('#sidebar-projects').textContent();
  check('Renombrado: título y barra lateral cambian sin recargar', side.includes('Sendero Boreal') && !side.includes('Sendero Alpino'));
  check('Se permanece en Ajustes tras guardar', page.url().includes('/settings'));
  await page.reload();
  check('El nombre persiste al recargar', (await page.inputValue('#ps-name')) === 'Sendero Boreal');
  await page.fill('#ps-name', '');
  await page.click('#project-form button[type=submit]');
  await page.waitForTimeout(400);
  const toasts = await page.locator('#toasts').textContent();
  check('Con nombre vacío no se muestra «Guardado»', !/Guardado|actualizado/i.test(toasts));
});

// ------------------------------------------------------------------ board
section('Tablero y tareas');
await attempt('Crear tareas en el tablero', async () => {
  await page.goto(BASE + projectUrl + '/board');
  const firstCol = page.locator('[data-column]').first();
  await firstCol.locator('button', { hasText: 'Añadir tarea' }).click();
  const ta = firstCol.locator('textarea[name=title]');
  for (const t of ['Marcar el sendero', 'Revisar puentes', 'Comprar pintura']) {
    await ta.fill(t);
    await ta.press('Enter');
    await firstCol.locator('.task-card', { hasText: t }).waitFor({ timeout: 5000 });
  }
  check('Tres tareas creadas con Enter; el campo queda listo para la siguiente', (await firstCol.locator('.task-card').count()) === 3 && (await ta.isVisible()));
  await page.screenshot({ path: `${SHOTS}/01-tablero.png` });
});

await attempt('Arrastrar tarjeta a otra columna', async () => {
  const card = page.locator('.task-card', { hasText: 'Marcar el sendero' });
  const target = page.locator('[data-column-body]').nth(1);
  const cb = await card.boundingBox();
  const tb = await target.boundingBox();
  await page.mouse.move(cb.x + 40, cb.y + 30);
  await page.mouse.down();
  await page.mouse.move(cb.x + 60, cb.y + 40, { steps: 3 });
  await page.mouse.move(tb.x + tb.width / 2, tb.y + 30, { steps: 12 });
  await page.mouse.up();
  await waitToast(/movida/);
  await page.waitForTimeout(500);
  await page.reload();
  const inSecond = await page.locator('[data-column]').nth(1).locator('.task-card', { hasText: 'Marcar el sendero' }).count();
  check('La tarjeta queda en la nueva columna tras recargar', inSecond === 1);
});

await attempt('Mover con el menú (alternativa sin arrastre)', async () => {
  const card = page.locator('.task-card', { hasText: 'Revisar puentes' });
  await card.locator('[data-menu]').click();
  await card.locator('[data-move="next"]').click();
  await waitToast(/movida/);
  await page.waitForTimeout(300);
  check('Menú «Columna siguiente» mueve la tarjeta', (await page.locator('[data-column]').nth(1).locator('.task-card', { hasText: 'Revisar puentes' }).count()) === 1);
});

await attempt('Panel de detalle de tarea', async () => {
  const before = await page.evaluate(() => document.getElementById('board-scroll').scrollLeft);
  await page.locator('.task-card', { hasText: 'Comprar pintura' }).locator('.task-card-title').click();
  const drawer = page.locator('#drawer.open');
  await drawer.locator('.title-input').waitFor({ timeout: 5000 });
  check('Clic en la tarjeta abre el panel lateral', true);
  check('La URL refleja la tarea abierta (enlace compartible)', page.url().includes('task='));
  const title = drawer.locator('.title-input');
  await title.fill('Comprar pintura verde');
  await title.press('Enter');
  await waitToast(/./);
  await page.locator('.task-card', { hasText: 'Comprar pintura verde' }).waitFor({ timeout: 4000 });
  check('Editar el título en el panel actualiza la tarjeta del tablero', true);
  await title.fill('');
  await title.press('Enter');
  await page.waitForTimeout(300);
  check('Título vacío: error visible y se restaura el valor guardado', (await title.inputValue()) === 'Comprar pintura verde' && /vacío/.test(await page.locator('#toasts').textContent()));
  const cm = drawer.locator('textarea[name=body]');
  await cm.fill('Hay que elegir el tono');
  await cm.locator('xpath=ancestor::form').locator('button[type=submit]').click();
  await drawer.locator('.comment, [id^=comment]', { hasText: 'Hay que elegir el tono' }).first().waitFor({ timeout: 5000 });
  check('Comentario publicado aparece en el panel', true);
  const sub = drawer.locator('input[name=title][placeholder*="subtarea"]');
  await sub.fill('Pedir presupuesto');
  await sub.press('Enter');
  await drawer.getByText('Pedir presupuesto').first().waitFor({ timeout: 5000 });
  check('Subtarea añadida en el panel', true);
  await page.screenshot({ path: `${SHOTS}/02-detalle-tarea.png` });
  await page.keyboard.press('Escape');
  await page.locator('#drawer.open').waitFor({ state: 'detached', timeout: 3000 }).catch(() => {});
  check('Escape cierra el panel', !(await page.locator('#drawer').evaluate((d) => d.classList.contains('open'))));
  const after = await page.evaluate(() => document.getElementById('board-scroll').scrollLeft);
  check('El tablero conserva su desplazamiento', after === before);
});

await attempt('Finalizar la última tarea', async () => {
  for (const t of ['Marcar el sendero', 'Revisar puentes', 'Comprar pintura verde']) {
    const card = page.locator('.task-card', { hasText: t });
    const last = page.locator('[data-column]').last().locator('[data-column-body]');
    const cb = await card.boundingBox();
    const tb = await last.boundingBox();
    await page.mouse.move(cb.x + 40, cb.y + 30); await page.mouse.down();
    await page.mouse.move(cb.x + 50, cb.y + 40, { steps: 2 });
    await page.mouse.move(tb.x + tb.width / 2, tb.y + 20, { steps: 10 }); await page.mouse.up();
    // 1.1: finishing a task offers a closing comment and the hours it took.
    const wrap = page.locator('#dlg-wrapup[open]');
    const offered = await wrap.waitFor({ timeout: 4000 }).then(() => true, () => false);
    if (t === 'Marcar el sendero') {
      check('Al finalizar una tarea se ofrece comentario de cierre y horas', offered);
      if (offered) {
        await page.screenshot({ path: `${SHOTS}/03a-cierre-tarea.png` });
        await wrap.locator('textarea[name=comment]').fill('Quedó marcado hasta el mirador');
        await wrap.locator('input[name=hours]').fill('1:30');
        await wrap.locator('button[type=submit]').click();
        await waitToast(/Comentario y horas guardados/);
        check('El comentario y las horas de cierre se guardan', true);
      }
    } else if (offered) {
      await wrap.locator('button[data-close-dialog]', { hasText: 'Omitir' }).click();
    }
    await page.locator('#dlg-wrapup[open]').waitFor({ state: 'detached', timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(400);
  }
  // Subtask still open → project not complete yet; the summary dialog appears only when all top-level tasks are done.
  const shown = await page.locator('#dlg-summary[open]').waitFor({ timeout: 4000 }).then(() => 1, () => 0);
  check('Al finalizar todas las tareas se ofrece el resumen del proyecto', shown === 1);
  if (shown) {
    await page.screenshot({ path: `${SHOTS}/03-resumen-proyecto.png` });
    await page.click('#dlg-summary [data-close-dialog], #dlg-summary button:not([data-summary-complete])');
  }
});

await attempt('Horas en el detalle de la tarea', async () => {
  await page.goto(BASE + projectUrl + '/board');
  await page.locator('.task-card', { hasText: 'Marcar el sendero' }).locator('.card-open').click();
  const drawer = page.locator('#drawer.open');
  await drawer.waitFor({ timeout: 5000 });
  await drawer.locator('#td-time', { hasText: '1 h 30 min' }).waitFor({ timeout: 5000 });
  check('El panel de la tarea muestra las horas del cierre', true);
  await drawer.locator('.time-form input[name=hours]').fill('45m');
  await drawer.locator('.time-form button[type=submit]').click();
  await drawer.locator('#td-time', { hasText: '2 h 15 min' }).waitFor({ timeout: 5000 });
  check('Registrar más horas actualiza el total sin recargar', true);
  await page.screenshot({ path: `${SHOTS}/03b-horas-tarea.png` });
  await page.keyboard.press('Escape');
});

await attempt('Ajustes: siglas, color y fondo', async () => {
  await page.goto(BASE + projectUrl + '/settings');
  const sw = page.locator('.swatch-btn').first();
  check('El color del proyecto usa la paleta nueva', await sw.isVisible());
  await sw.click();
  const pop = page.locator('.swatch-pop');
  await pop.waitFor({ timeout: 3000 });
  await pop.locator('.swatch-opt').nth(4).click();
  await page.locator('input[name=cover][value=aurora]').check({ force: true });
  await page.screenshot({ path: `${SHOTS}/03c-ajustes-apariencia.png`, fullPage: true });
  await page.locator('input[name=cover][value=aurora]').locator('xpath=ancestor::form').locator('button[type=submit]').click();
  await page.waitForTimeout(800);
  await page.goto(BASE + projectUrl);
  check('El encabezado del proyecto usa el fondo elegido', await page.locator('.project-hero.cover-aurora, .cover-aurora').first().isVisible());
  const ink = await page.locator('.tabs--slider .tab-ink').boundingBox();
  const cur = await page.locator('.tabs--slider [aria-current="page"]').boundingBox();
  check('La pestaña activa tiene el indicador deslizante debajo', ink && cur && Math.abs(ink.x - cur.x) < 4 && Math.abs(ink.width - cur.width) < 4, JSON.stringify({ ink, cur }));
});

await attempt('Reportes con botones de exportación', async () => {
  await page.goto(BASE + '/reports');
  const [popup] = await Promise.all([page.waitForEvent('popup'), page.getByRole('link', { name: /Informe \(imprimir o PDF\)/ }).click()]);
  await popup.waitForLoadState();
  check('El informe imprimible se abre con su botón de imprimir', await popup.locator('[data-print]').isVisible());
  await popup.screenshot({ path: `${SHOTS}/03d-informe.png`, fullPage: true });
  await popup.close();
  const [dl] = await Promise.all([page.waitForEvent('download'), page.getByRole('link', { name: 'Horas (CSV)' }).click()]);
  check('El botón «Horas (CSV)» descarga un archivo CSV', /\.csv$/.test(dl.suggestedFilename()), dl.suggestedFilename());
});

// ------------------------------------------------------------------ list
section('Lista y filtros');
await attempt('Lista con filtros y selección', async () => {
  await page.goto(BASE + projectUrl + '/list');
  const quick = page.locator('form.quick-add input[name=title]').first();
  await quick.fill('Tarea desde la lista');
  await quick.press('Enter');
  await page.locator('tbody .task-title-open', { hasText: 'Tarea desde la lista' }).waitFor({ timeout: 5000 }); // 1.4: the name opens the task
  check('Alta rápida en la lista', true);
  await page.fill('[data-filter-form] input[name=q]', 'lista');
  await page.locator('[data-filter-form] input[name=q]').press('Enter');
  await page.waitForURL(/q=lista/);
  const rows = await page.locator('[data-select]').count();
  check('El filtro de búsqueda reduce la lista', rows === 1, rows);
  await page.locator('[data-select]').first().check();
  check('Seleccionar muestra la barra de acciones masivas', await page.locator('[data-bulk-bar].show').isVisible());
  await page.screenshot({ path: `${SHOTS}/04-lista.png` });
});

// ------------------------------------------------------------------ preferences
section('Preferencias');
await attempt('Desactivar animaciones', async () => {
  await page.goto(BASE + '/profile');
  const sel = page.locator('select[name=motion], input[name=motion][value=off]').first();
  if (await sel.evaluate((e) => e.tagName) === 'SELECT') await sel.selectOption('off'); else await sel.check();
  const form = sel.locator('xpath=ancestor::form');
  if (await form.locator('button[type=submit]').count()) await form.locator('button[type=submit]').click();
  await page.waitForFunction(() => document.documentElement.dataset.motion === 'off', null, { timeout: 5000 });
  check('La preferencia de movimiento se aplica', true);
});

// ------------------------------------------------------------------ import
section('Importación desde v0.2');
await attempt('Importar desde la pantalla de Datos', async () => {
  await page.goto(BASE + '/admin/data');
  await page.setInputFiles('#li-file', new URL('../fixtures/v022.zip', import.meta.url).pathname);
  await page.click('#legacy-import button[type=submit]');
  await waitToast(/Importados 3 proyectos/);
  await page.locator('#legacy-import', { hasText: '4 tareas' }).waitFor({ timeout: 5000 });
  check('La importación informa el resultado y permanece en la pantalla', page.url().includes('/admin/data'));
  await page.screenshot({ path: `${SHOTS}/05-importacion.png`, fullPage: true });
  await page.locator('#sidebar-projects a', { hasText: 'Proyecto legado' }).first().waitFor({ timeout: 4000 }).catch(() => {});
  await page.goto(BASE + '/projects');
  check('Los proyectos importados aparecen en Proyectos', await page.getByText('Proyecto legado').first().isVisible());
});

// ------------------------------------------------------------------ 1.2
section('Novedades 1.2');
const call = (method, path, body) => page.evaluate(async ([m, p, b]) => {
  const r = await fetch(p, { method: m, headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'fetch', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content }, body: b ? JSON.stringify(b) : undefined });
  return { status: r.status, json: await r.json().catch(() => ({})) };
}, [method, path, body]);
let memberRole = 0;
let pid12 = 0;
let people12 = {};

await attempt('Invitar por enlace muestra el mensaje con el enlace', async () => {
  await page.goto(BASE + '/admin/members');
  memberRole = Number(await page.locator('#iv-r option', { hasText: 'Integrante' }).first().getAttribute('value'));
  await page.click('[data-dialog="dlg-invite"]');
  await page.fill('#iv-e', 'invitada@example.test');
  await page.click('#dlg-invite button[type=submit]');
  const box = page.locator('#dlg-invite [data-invite-link] textarea');
  await box.waitFor({ timeout: 5000 });
  const v = await box.inputValue();
  check('El cuadro de la invitación trae el enlace (no queda vacío)', /\/invite\/[a-f0-9]{48}/.test(v), v);
  await page.screenshot({ path: `${SHOTS}/12-invitar-enlace.png` });
  await page.click('#dlg-invite [data-close-dialog]');
  await page.reload();
  await page.locator('button', { hasText: 'Nuevo enlace' }).first().click();
  const share = page.locator('#dlg-share[open] textarea');
  await share.waitFor({ timeout: 5000 });
  check('«Nuevo enlace» en invitaciones pendientes muestra un enlace para copiar', /\/invite\/[a-f0-9]{48}/.test(await share.inputValue()));
  await page.click('#dlg-share [data-close-dialog]');
});

await attempt('Correo mal escrito: aviso en el navegador', async () => {
  await page.goto(BASE + '/admin/members');
  await page.click('[data-dialog="dlg-create-user"]');
  await page.fill('#cu-n', 'Prueba Correo');
  await page.fill('#cu-e', 'prueba@gmail.con');
  const msg = await page.locator('#cu-e').evaluate((el) => { el.dispatchEvent(new Event('input', { bubbles: true })); return el.validationMessage; });
  check('El navegador sugiere gmail.com antes de enviar', msg.includes('gmail.com'), msg);
  await page.fill('#cu-e', 'prueba@dominio');
  const msg2 = await page.locator('#cu-e').evaluate((el) => { el.dispatchEvent(new Event('input', { bubbles: true })); return el.validationMessage; });
  check('Un correo sin dominio completo no pasa', msg2.length > 0, msg2);
  await page.click('#dlg-create-user [data-close-dialog]');
});

await attempt('Editar la cuenta de otra persona', async () => {
  await page.goto(BASE + '/admin/members');
  for (const [k, name] of [['eli', 'Eli Montes'], ['fer', 'Fer Lagos']]) {
    const r = await call('POST', '/api/members', { name, email: `${k}@example.test`, password: 'Clave-' + randomBytes(5).toString('hex'), role_id: memberRole });
    people12[k] = r.json.id;
  }
  await page.reload();
  const uid = people12.eli;
  await page.locator(`[data-dialog="dlg-edit-${uid}"]`).evaluate((b) => b.click());
  await page.fill(`#en-${uid}`, 'Elisa Montes');
  await page.fill(`#et-${uid}`, 'Cartógrafa');
  await page.screenshot({ path: `${SHOTS}/12-editar-cuenta.png` });
  await page.click(`#dlg-edit-${uid} button[type=submit]`);
  await waitToast(/Cuenta actualizada/);
  await page.reload();
  check('El nombre editado aparece en la lista de usuarios', await page.getByText('Elisa Montes').first().isVisible());
});

await attempt('Equipo en proyecto, varios responsables y perfil con horas', async () => {
  const team = await call('POST', '/api/teams', { name: 'Cuadrilla Sur' });
  await call('POST', `/api/teams/${team.json.id}/members`, { user_id: people12.eli, team_role: 'member' });
  await call('POST', `/api/teams/${team.json.id}/members`, { user_id: people12.fer, team_role: 'member' });
  const proj = await call('POST', '/api/projects', { name: 'Mirador 12' });
  pid12 = proj.json.id;
  people12.team = team.json.id;
  await page.goto(`${BASE}/projects/${pid12}/settings?section=members`);
  await page.selectOption('#pt-add', String(team.json.id));
  await page.click('form[data-api$="/teams"] button[type=submit]');
  await waitToast(/Equipo añadido/);
  await page.waitForTimeout(400);
  check('El equipo aparece como participante del proyecto', await page.getByText('Equipos participantes').isVisible() && await page.locator('.list', { hasText: 'Fer Lagos' }).first().isVisible());
  await page.screenshot({ path: `${SHOTS}/12-equipo-en-proyecto.png`, fullPage: true });
  const t1 = await call('POST', `/api/projects/${pid12}/tasks`, { title: 'Marcar mirador', assignee_id: people12.eli });
  await call('POST', `/api/projects/${pid12}/tasks`, { title: 'Limpiar barandal' });
  people12.task1 = t1.json.task;
  await page.goto(BASE + '/t/' + t1.json.task.key);
  await page.locator('details.multi-pick summary').click();
  await page.locator('details.multi-pick label', { hasText: 'Fer Lagos' }).locator('input').check();
  await page.locator('.ts [data-save-state]', { hasText: 'Guardado' }).waitFor({ timeout: 6000 });
  await page.waitForTimeout(500);
  await page.selectOption(`#td-team-${t1.json.task.id}`, String(team.json.id));
  await page.waitForTimeout(600);
  const fresh = await call('GET', `/api/tasks/${t1.json.task.id}`);
  check('Desde la tarea se suman responsables y un equipo', JSON.stringify(fresh.json.task.assignee_ids) === JSON.stringify([people12.eli, people12.fer]) && fresh.json.task.team_id === team.json.id, JSON.stringify(fresh.json.task));
  await page.screenshot({ path: `${SHOTS}/12-varios-responsables.png` });
});

await attempt('Mover personas en el tablero', async () => {
  await page.goto(`${BASE}/projects/${pid12}/board`);
  check('Sin polivalente no aparece la fila de personas', (await page.locator('.people-strip').count()) === 0);
  await call('PATCH', `/api/teams/${people12.team}`, { is_flexible: true });
  await page.reload();
  check('Con un equipo polivalente aparece la fila de personas', await page.locator('.people-strip').isVisible());
  const target = page.locator('.task-card', { hasText: 'Limpiar barandal' });
  await page.locator('.task-card', { hasText: 'Marcar mirador' }).locator(`.person-drag[data-person="${people12.fer}"]`).dragTo(target);
  await waitToast(/Responsables de/);
  await page.waitForTimeout(500);
  const a = await call('GET', `/api/tasks/${people12.task1.id}`);
  const tasks = await call('GET', `/api/projects/${pid12}/tasks`);
  const other = tasks.json.tasks.find((t) => t.title === 'Limpiar barandal');
  check('Arrastrar a una persona de una tarjeta a otra la mueve', !a.json.task.assignee_ids.includes(people12.fer) && other.assignee_ids.includes(people12.fer), JSON.stringify([a.json.task.assignee_ids, other.assignee_ids]));
  await page.locator('.people-strip .person-drag[data-person="' + people12.eli + '"]').dragTo(page.locator('.task-card', { hasText: 'Limpiar barandal' }));
  await waitToast(/Responsables de/);
  const other2 = (await call('GET', `/api/tasks/${other.id}`)).json.task;
  check('Arrastrar desde la fila de personas la suma como responsable', other2.assignee_ids.includes(people12.eli) && other2.assignee_ids.includes(people12.fer), JSON.stringify(other2.assignee_ids));
  await page.screenshot({ path: `${SHOTS}/12-tablero-personas.png` });
});

await attempt('Perfil con horas', async () => {
  await call('POST', `/api/tasks/${people12.task1.id}/time`, { hours: '2', user_id: people12.eli });
  await page.goto(`${BASE}/people/${people12.eli}`);
  check('El perfil de una persona muestra sus horas', (await page.locator('[data-hours-week]').textContent()).includes('2 h'));
  await page.screenshot({ path: `${SHOTS}/12-perfil-horas.png`, fullPage: true });
});

await attempt('Exportar e importar un proyecto', async () => {
  await page.goto(`${BASE}/projects/${pid12}/settings`);
  const [dl] = await Promise.all([page.waitForEvent('download'), page.locator('a', { hasText: 'Exportar sin archivos' }).click()]);
  const file = `${SHOTS}/${dl.suggestedFilename()}`;
  await dl.saveAs(file);
  check('Exportar descarga un archivo .json', dl.suggestedFilename().endsWith('.json'), dl.suggestedFilename());
  await page.goto(BASE + '/projects');
  await page.click('[data-dialog="dlg-import-project"]');
  await page.setInputFiles('#ip-file', file);
  await page.fill('#ip-name', 'Mirador importado');
  await page.click('#dlg-import-project button[type=submit]');
  await waitToast(/Proyecto importado/);
  await page.waitForTimeout(500);
  await page.reload();
  check('El proyecto importado aparece en Proyectos', await page.getByText('Mirador importado').first().isVisible());
  await page.screenshot({ path: `${SHOTS}/12-importar.png` });
});

// ------------------------------------------------------------------ 1.3
section('Novedades 1.3');
const c13 = {};
const until = async (fn, ms = 8000) => { const t0 = Date.now(); while (Date.now() - t0 < ms) { if (await fn()) return true; await page.waitForTimeout(200); } return false; };
const ids13 = async (id) => (await call('GET', `/api/tasks/${id}`)).json.task.assignee_ids;
await attempt('Varios responsables seguidos sin que se cierre el panel', async () => {
  const proj = await call('POST', '/api/projects', { name: 'Travesía 13' });
  c13.pid = proj.json.id;
  await call('POST', `/api/projects/${c13.pid}/modules`, { module: 'sprints', enabled: true });
  await call('PATCH', `/api/projects/${c13.pid}`, { is_flexible: true });
  const contrib = Number(await (async () => { await page.goto(`${BASE}/projects/${c13.pid}/settings?section=members`); return page.locator('select[name=role_id] option', { hasText: /Colabora|Contribu/ }).first().getAttribute('value'); })());
  c13.people = [];
  for (const [k, name] of [['gil', 'Gil Andrade'], ['hana', 'Hana Ruiz'], ['ivo', 'Ivo Paredes'], ['jade', 'Jade Soto']]) {
    const r = await call('POST', '/api/members', { name, email: `${k}13@example.test`, password: 'Clave-' + randomBytes(5).toString('hex'), role_id: memberRole });
    c13[k] = r.json.id;
    c13.people.push(r.json.id);
    await call('POST', `/api/projects/${c13.pid}/members`, { user_id: r.json.id, role_id: contrib });
  }
  const today = new Date().toISOString().slice(0, 10);
  const end = new Date(Date.now() + 13 * 86400000).toISOString().slice(0, 10);
  c13.sprint = (await call('POST', `/api/projects/${c13.pid}/sprints`, { name: 'Sprint Cumbre', start_date: today, end_date: end })).json.id;
  const t = (await call('POST', `/api/projects/${c13.pid}/tasks`, { title: 'Preparar cuerdas' })).json.task;
  c13.task = t;
  // A slow connection, so several clicks happen while a save is still on its way.
  await page.route('**/api/**', async (r) => { await new Promise((x) => setTimeout(x, 700)); await r.continue(); });
  await page.goto(BASE + '/t/' + t.key);
  await page.locator('details.ts-pick summary').click();
  const box = (name) => page.locator('details.ts-pick label', { hasText: name }).locator('input');
  for (const n of ['Gil Andrade', 'Hana Ruiz', 'Ivo Paredes', 'Jade Soto']) { await box(n).check(); await page.waitForTimeout(250); }
  const midOpen = await page.locator('details.ts-pick').evaluate((d) => d.open);
  const midState = await page.locator('.ts [data-save-state]').textContent();
  check('Indicador «Guardando…» mientras se guarda', /Guardando/.test(midState), midState + ' | ' + await page.locator('#toasts').textContent());
  await box('Hana Ruiz').uncheck();
  await page.waitForTimeout(250);
  await page.locator('.ts [data-save-state]', { hasText: 'Guardado' }).waitFor({ timeout: 10000 });
  await page.waitForTimeout(1200);
  const open = await page.locator('details.ts-pick').evaluate((d) => d.open);
  const ids = (await call('GET', `/api/tasks/${t.id}`)).json.task.assignee_ids;
  check('El selector sigue abierto durante y después de guardar', midOpen && open);
  check('Se guardan todos los clics, en orden (4 marcadas y 1 desmarcada)', JSON.stringify(ids) === JSON.stringify([c13.gil, c13.ivo, c13.jade]), JSON.stringify(ids));
  await page.unroute('**/api/**');
  await page.reload();
  await page.locator('details.ts-pick summary').click();
  const checked = await page.locator('details.ts-pick input:checked').count();
  check('Al recargar, los responsables siguen ahí', checked === 3, String(checked));
  await page.screenshot({ path: `${SHOTS}/13-responsables-seguidos.png` });
});

await attempt('Clics justo al ritmo del guardado (regresión)', async () => {
  const bad = [];
  for (const gap of [150, 250, 300]) {
    await call('PATCH', `/api/tasks/${c13.task.id}`, { assignee_ids: [] });
    await page.goto(BASE + '/t/' + c13.task.key);
    await page.route('**/api/**', async (r) => { await new Promise((x) => setTimeout(x, 700)); await r.continue(); });
    await page.locator('details.ts-pick summary').click();
    for (const n of ['Gil Andrade', 'Hana Ruiz', 'Ivo Paredes', 'Jade Soto']) { await page.locator('details.ts-pick label', { hasText: n }).locator('input').check(); await page.waitForTimeout(gap); }
    await page.locator('.ts [data-save-state]', { hasText: 'Guardado' }).waitFor({ timeout: 10000 });
    await page.unroute('**/api/**');
    const ids = (await call('GET', `/api/tasks/${c13.task.id}`)).json.task.assignee_ids;
    const shown = await page.locator('details.ts-pick input:checked').count();
    if (ids.length !== 4 || shown !== 4) bad.push(`${gap} ms: ${JSON.stringify(ids)} / ${shown}`);
  }
  check('Con clics cada 150, 250 y 300 ms se guardan los 4 y se siguen viendo marcados', bad.length === 0, bad.join('; '));
});

await attempt('Arrastrar para asignar, retirar y reasignar con horas por sprint', async () => {
  await call('PATCH', `/api/tasks/${c13.task.id}`, { assignee_ids: [] });
  for (const uid of c13.people) await call('PATCH', `/api/sprints/${c13.sprint}/capacity`, { user_id: uid, hours: 40 });
  const mk = async (title, h) => (await call('POST', `/api/projects/${c13.pid}/tasks`, { title, sprint_id: c13.sprint, estimate_hours: h })).json.task;
  c13.a = await mk('Ruta norte', 30); c13.b = await mk('Ruta sur', 10); c13.c = await mk('Campamento', 8); c13.d = await mk('Señalizar', 5);
  await call('PATCH', `/api/tasks/${c13.c.id}`, { assignee_ids: [c13.hana] });
  await page.setViewportSize({ width: 1440, height: 1400 }); // every card visible: no scrolling while dragging
  await page.goto(`${BASE}/projects/${c13.pid}/board?sprint_id=${c13.sprint}`);
  const card = (title) => page.locator('.task-card', { hasText: title });
  const pill = (uid) => page.locator(`#people-strip .person-pill[data-person="${uid}"]`);
  check('La fila muestra horas usadas y capacidad de cada persona', (await pill(c13.gil).textContent()).includes('/ 40'));
  await pill(c13.gil).dragTo(card('Ruta norte'));
  await until(async () => (await ids13(c13.a.id)).includes(c13.gil));
  await page.locator('.save-state[data-state="saved"], .save-state:empty').first().waitFor();
  await page.waitForTimeout(600);
  await pill(c13.gil).dragTo(card('Ruta sur'));
  check('Asignar arrastrando hasta el límite exacto (30 + 10 = 40 h)', await until(async () => (await ids13(c13.b.id)).includes(c13.gil)));
  await page.waitForTimeout(800);
  check('Al llegar al límite pasa a «Sin disponibilidad» y no se puede arrastrar', await page.locator(`#people-strip .people-group.is-full .person-pill[data-person="${c13.gil}"]`).isVisible() && (await pill(c13.gil).getAttribute('draggable')) === null);
  await page.screenshot({ path: `${SHOTS}/13-tablero-sin-disponibilidad.png` });
  await page.locator(`.task-card[data-task-id="${c13.b.id}"] .person-drag[data-person="${c13.gil}"]`).dragTo(page.locator('#people-strip'));
  check('Retirar arrastrando a la fila de personas', await until(async () => !(await ids13(c13.b.id)).includes(c13.gil)));
  await page.waitForTimeout(800);
  check('Recupera las horas y vuelve a «Disponibles»', await page.locator(`#people-strip .people-group:not(.is-full) .person-pill[data-person="${c13.gil}"]`).isVisible() && (await pill(c13.gil).getAttribute('data-free')) === '10');
  await page.locator(`.task-card[data-task-id="${c13.c.id}"] .person-drag[data-person="${c13.hana}"]`).dragTo(card('Señalizar'));
  await until(async () => (await ids13(c13.d.id)).includes(c13.hana));
  await page.waitForTimeout(800);
  const c = (await call('GET', `/api/tasks/${c13.c.id}`)).json.task.assignee_ids;
  const d = (await call('GET', `/api/tasks/${c13.d.id}`)).json.task.assignee_ids;
  check('Reasignar arrastrando de una tarjeta a otra', !c.includes(c13.hana) && d.includes(c13.hana), JSON.stringify([c, d, c13.hana, await page.locator('#toasts').textContent(), await page.locator(`.task-card[data-task-id="${c13.c.id}"]`).innerHTML()]).slice(0, 300));
  await call('PATCH', `/api/sprints/${c13.sprint}/capacity`, { user_id: c13.ivo, hours: 5 });
  await page.reload();
  await pill(c13.ivo).dragTo(card('Campamento'));
  await page.locator('#toasts .toast', { hasText: 'solo tiene 5 h disponibles' }).first().waitFor({ timeout: 6000 });
  check('Una tarea de 8 h con 5 h libres se rechaza con el motivo y sin cambios', (await call('GET', `/api/tasks/${c13.c.id}`)).json.task.assignee_ids.length === 0);
  await page.reload();
  check('Al recargar, el tablero muestra lo mismo que la base de datos', (await pill(c13.gil).getAttribute('data-free')) === '10' && await card('Señalizar').locator(`[data-person="${c13.hana}"]`).count() === 1);
  await page.screenshot({ path: `${SHOTS}/13-tablero-horas.png` });
  await page.goto(`${BASE}/projects/${c13.pid}/sprints?sprint=${c13.sprint}`);
  check('El sprint muestra horas disponibles editables por persona', await page.locator(`#cap-h-${c13.gil}`).isVisible());
  await page.screenshot({ path: `${SHOTS}/13-sprint-horas.png`, fullPage: true });
  await page.setViewportSize({ width: 1440, height: 900 });
});

// ------------------------------------------------------------------ 1.4
section('Novedades 1.4');
await attempt('Lista: un clic abre la tarea y el nombre se edita dentro', async () => {
  await page.goto(`${BASE}/projects/${c13.pid}/list`);
  check('En la lista el nombre no es un campo editable', (await page.locator('tbody input[name="title"]').count()) === 0);
  await page.locator('tbody tr', { hasText: 'Ruta norte' }).locator('.task-title-open').click();
  await page.locator('#drawer.open .title-input').waitFor();
  await page.fill('#drawer.open .title-input', 'Ruta norte por la cresta');
  await page.locator('#drawer.open .title-input').press('Tab');
  await page.locator('.ts [data-save-state]', { hasText: 'Guardado' }).waitFor({ timeout: 6000 }).catch(() => {});
  await page.waitForTimeout(800);
  check('Renombrar dentro guarda sin cerrar el panel', await page.locator('#drawer.open').isVisible() && (await call('GET', `/api/tasks/${c13.a.id}`)).json.task.title === 'Ruta norte por la cresta');
  await page.keyboard.press('Escape');
  await page.waitForTimeout(400);
  check('Al cerrar, la lista muestra el nombre nuevo', await page.locator('tbody .task-title-open', { hasText: 'Ruta norte por la cresta' }).isVisible());
});

await attempt('Sprints por estado', async () => {
  await call('POST', `/api/projects/${c13.pid}/sprints`, { name: 'Sprint Descenso' });
  await page.goto(`${BASE}/projects/${c13.pid}/sprints`);
  check('Se ven los grupos Activo, Planificados y Finalizados', (await page.locator('.sprint-group-title').count()) === 3);
  await page.locator('.sprint-link', { hasText: 'Sprint Descenso' }).click();
  await page.waitForURL(/sprint=/);
  check('Elegir un sprint muestra su detalle', (await page.locator('.card-head h2').first().textContent()).includes('Sprint Descenso'));
  await page.goto(`${BASE}/projects/${c13.pid}/sprints?sprint=${c13.sprint}`);
  await page.screenshot({ path: `${SHOTS}/14-sprints.png`, fullPage: true });
});

await attempt('Archivos del proyecto', async () => {
  await page.goto(`${BASE}/projects/${c13.pid}/files`);
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');
  await page.setInputFiles('.drop-zone input[type=file]', [{ name: 'mapa.png', mimeType: 'image/png', buffer: png }, { name: 'lista.txt', mimeType: 'text/plain', buffer: Buffer.from('cuerdas\n') }]);
  await page.locator('.file-name', { hasText: 'mapa.png' }).first().waitFor({ timeout: 8000 });
  await page.goto(`${BASE}/projects/${c13.pid}/files?view=grid`);
  check('Cuadrícula con miniatura para la imagen', await page.locator('.file-tile img').count() === 1 && await page.locator('.file-tile', { hasText: 'lista.txt' }).locator('.file-ext').isVisible());
  await page.screenshot({ path: `${SHOTS}/14-archivos-cuadricula.png` });
  const tile = page.locator('.file-tile', { hasText: 'mapa.png' });
  await tile.locator('[data-menu]').click();
  await tile.locator('input[name="name"]').fill('Mapa de la ruta');
  await tile.locator('form.file-rename button[type=submit]').click();
  await page.locator('.file-name', { hasText: 'Mapa de la ruta.png' }).waitFor({ timeout: 6000 });
  check('Cambiar el nombre visible', true);
  await page.goto(`${BASE}/projects/${c13.pid}/files?view=list`);
  check('Vista de lista con formato y tamaño', await page.locator('tr', { hasText: 'lista.txt' }).locator('td', { hasText: /^\s*TXT\s*$/ }).first().isVisible());
  await page.screenshot({ path: `${SHOTS}/14-archivos-lista.png` });
  const row = page.locator('tr', { hasText: 'lista.txt' });
  await row.locator('[data-menu]').click();
  await row.locator('[data-call][data-method="DELETE"]').click();
  await page.locator('#dlg-confirm [data-confirm-ok]').click();
  await page.waitForTimeout(800);
  check('Eliminar pide confirmación y quita el archivo', (await page.locator('tr', { hasText: 'lista.txt' }).count()) === 0);
});

await attempt('Apariencia por persona', async () => {
  await page.goto(`${BASE}/profile`);
  await page.locator('[data-ambient-pick="medieval"]').click();
  check('Elegir un fondo lo aplica al instante', (await page.evaluate(() => document.documentElement.dataset.ambient)) === 'medieval');
  await page.locator('#apariencia [data-save-state]', { hasText: 'Guardado' }).waitFor({ timeout: 6000 });
  await page.goto(`${BASE}/projects/${c13.pid}/board?sprint_id=${c13.sprint}`);
  check('Se mantiene en otras pantallas y al volver', (await page.evaluate(() => document.documentElement.dataset.ambient)) === 'medieval');
  await page.screenshot({ path: `${SHOTS}/14-fondo-medieval.png` });
  await page.goto(`${BASE}/profile`);
  await page.screenshot({ path: `${SHOTS}/14-apariencia.png`, fullPage: true });
  await page.locator('[data-ambient-pick="default"]').click();
  await page.locator('#apariencia [data-save-state]', { hasText: 'Guardado' }).waitFor({ timeout: 6000 });
  await page.reload();
  check('Volver al predeterminado', (await page.evaluate(() => document.documentElement.dataset.ambient)) === 'default');
});

// ------------------------------------------------------------------ 1.5
section('Novedades 1.5');
await attempt('Temas completos: fondo, barra lateral, símbolo y navegación', async () => {
  const themes = ['medieval', 'agua', 'fuego', 'tierra', 'cielo', 'bosque'];
  await page.goto(`${BASE}/profile`);
  await page.evaluate(() => { window.__sinRecarga = true; });
  const look = () => page.evaluate(() => {
    const sb = document.querySelector('.sidebar'); const bm = document.querySelector('.brand-mark');
    const nav = document.querySelector('.nav a.nav-item'); const cs = getComputedStyle(sb);
    const rgb = (c) => (c.match(/[\d.]+/g) || []).slice(0, 3).map(Number);
    const L = ([r, g, b]) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); };
    const solid = getComputedStyle(document.documentElement).getPropertyValue('--sb-solid').trim();
    const tmp = document.createElement('span'); tmp.style.color = solid || cs.backgroundColor; document.body.appendChild(tmp); const bg = rgb(getComputedStyle(tmp).color); tmp.remove();
    const fg = rgb(getComputedStyle(nav).color);
    const [a, b] = [L(fg), L(bg)].sort((x, y) => y - x);
    return { sidebar: cs.backgroundImage + '|' + cs.backgroundColor, mark: getComputedStyle(bm).backgroundImage + '|' + getComputedStyle(bm).clipPath + '|' + getComputedStyle(bm).borderRadius,
      art: getComputedStyle(document.querySelector('.sidebar-forest')).backgroundImage.slice(0, 60), contrast: (a + 0.05) / (b + 0.05), transition: cs.transitionDuration, sinRecarga: window.__sinRecarga === true };
  });
  const base = await look();
  const seen = new Set([base.sidebar]);
  const marks = new Set([base.mark]);
  let allContrast = true; let detail = '';
  for (const t of themes) {
    await page.locator(`[data-ambient-pick="${t}"]`).click();
    await page.locator('#apariencia [data-save-state]', { hasText: 'Guardado' }).waitFor({ timeout: 6000 });
    const l = await look();
    seen.add(l.sidebar); marks.add(l.mark);
    if (l.contrast < 4.5) { allContrast = false; detail += `${t}: ${l.contrast.toFixed(2)} `; }
    if (!l.sinRecarga) detail += `${t}: recargó `;
    await page.locator('.sidebar').screenshot({ path: `${SHOTS}/15-tema-${t}-barra.png` });
  }
  const last = await look();
  const imgs = await page.evaluate(async () => Promise.all(['medieval-barra', 'agua-barra', 'fuego-barra', 'cielo-barra', 'bosque-barra', 'tierra-fondo'].map(async (n) => (await fetch(`/assets/img/themes/${n}.webp`)).status)));
  check('Las ilustraciones de los temas se sirven (6 imágenes WebP)', imgs.every((x) => x === 200), imgs.join(','));
  check('Cada tema cambia la barra lateral (6 temas + original distintos)', seen.size === 7, [...seen].join(' / ').slice(0, 300));
  check('Cada tema tiene su variante del símbolo de Borealis', marks.size === 7 && await page.locator('.brand-mark svg use[href$="#i-tree"]').count() === 1, [...marks].join(' / ').slice(0, 300));
  check('Texto de la barra lateral con contraste AA (≥ 4,5:1) en todos los temas', allContrast, detail);
  check('Cambiar de tema no recarga la página', last.sinRecarga && !detail.includes('recargó'), detail);
  await page.goto(`${BASE}/teams`);
  const cur = await page.evaluate(() => { const a = document.querySelector('.nav a.nav-item[aria-current="page"]'); return a ? getComputedStyle(a).boxShadow : ''; });
  check('El elemento activo del menú usa el color del tema', /inset/.test(cur) && cur.includes('110, 231, 160'), cur);
  await page.screenshot({ path: `${SHOTS}/15-tema-bosque-equipos.png` });
  // Persists for this person across sessions.
  const ctx2 = await browser.newContext({ viewport: { width: 1280, height: 800 }, locale: 'es-MX', reducedMotion: 'reduce' });
  const p2 = await ctx2.newPage();
  await p2.goto(`${BASE}/login`);
  await p2.fill('input[name=email]', email);
  await p2.fill('input[name=password]', pass);
  await Promise.all([p2.waitForURL((u) => !u.pathname.includes('login'), { timeout: 15000 }), p2.click('button[type=submit]')]);
  check('El tema se conserva al iniciar sesión de nuevo', (await p2.evaluate(() => document.documentElement.dataset.ambient)) === 'bosque');
  check('Con «reducir movimiento» la barra cambia sin transición', (await p2.evaluate(() => getComputedStyle(document.querySelector('.sidebar')).transitionDuration)).split(',').every((d) => parseFloat(d) === 0));
  await ctx2.close();
  await page.goto(`${BASE}/profile`);
  await page.locator('[data-ambient-pick="default"]').click();
  await page.locator('#apariencia [data-save-state]', { hasText: 'Guardado' }).waitFor({ timeout: 6000 });
  const back = await look();
  check('El tema original queda exactamente como antes', back.sidebar === base.sidebar && back.mark === base.mark, back.sidebar + ' ≠ ' + base.sidebar);
});

await attempt('Alerta «Capacidad agotada» y su resolución desde el panel', async () => {
  const gil = c13.gil;
  await call('PATCH', `/api/members/${gil}`, { weekly_hours: 1 });
  const today = new Date().toISOString().slice(0, 10);
  const t = (await call('POST', `/api/projects/${c13.pid}/tasks`, { title: 'Informe de cumbre', assignee_id: gil, due_date: today, estimate_hours: 2 })).json.task;
  const r = await call('POST', `/api/tasks/${t.id}/time`, { hours: '1', user_id: gil });
  check('Preparación: Gil registra 1 h de 1 h con una tarea pendiente', r.status === 200, JSON.stringify(r.json));
  await page.goto(BASE + '/');
  const panel = page.locator('[data-capacity-alerts]');
  check('El inicio muestra la alerta con el mensaje claro', await panel.isVisible() && /Gil Andrade ha utilizado 1 h de sus 1 h disponibles/.test(await panel.textContent()), (await panel.textContent().catch(() => '')).slice(0, 200));
  await page.screenshot({ path: `${SHOTS}/15-alerta-capacidad.png`, fullPage: true });
  await panel.locator('button', { hasText: 'Resolver' }).click();
  const dlg = page.locator('dialog[open]');
  await dlg.locator('summary', { hasText: 'Mantener pendiente' }).click();
  await page.screenshot({ path: `${SHOTS}/15-alerta-resolver.png` });
  await dlg.locator('textarea[name=note]').fill('Se entrega el lunes, sin horas extra');
  await dlg.locator('button', { hasText: 'Mantener pendiente' }).click();
  await waitToast(/Alerta resuelta/);
  check('Al resolver, la alerta desaparece del panel sin recargar', await until(async () => (await page.locator('[data-capacity-alerts]').count()) === 0));
  await page.goto(BASE + '/capacity');
  check('El historial muestra la decisión y quién la tomó', await page.getByText('Mantuvo el trabajo pendiente: Se entrega el lunes').first().isVisible() && await page.getByText('por Ana Rivera').first().isVisible());
  await page.screenshot({ path: `${SHOTS}/15-alerta-historial.png`, fullPage: true });
  await call('PATCH', `/api/members/${gil}`, { weekly_hours: '' });
});

// ------------------------------------------------------------------ responsive
section('Novedades 1.6');
const ymd = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const cdmx = () => new Date(new Date().toLocaleString('en-US', { timeZone: 'America/Mexico_City' }));
const dmy = (iso) => iso.split('-').reverse().join('/');
const plusDays = (n) => { const d = cdmx(); d.setDate(d.getDate() + n); return ymd(d); };

await attempt('Saludo correcto según la hora local de la persona', async () => {
  const want = (h) => (h < 5 ? 'Buenas noches' : h < 12 ? 'Buenos días' : h < 20 ? 'Buenas tardes' : 'Buenas noches');
  const results = [];
  for (const tz of ['Pacific/Kiritimati', 'America/Mexico_City', 'Pacific/Pago_Pago']) {
    const c = await browser.newContext({ timezoneId: tz, locale: 'es-MX' });
    const p = await c.newPage();
    await p.goto(BASE + '/login');
    await p.fill('#email', email); await p.fill('#password', pass);
    await Promise.all([p.waitForURL((u) => !u.pathname.includes('login')), p.click('button[type=submit]')]);
    const h = Number(new Intl.DateTimeFormat('en-GB', { hour: 'numeric', hourCycle: 'h23', timeZone: tz }).format(new Date()));
    const txt = (await p.locator('[data-greeting]').textContent()).trim();
    results.push([tz, h, txt]);
    await c.close();
  }
  check('El saludo usa el nombre real y la hora local (3 zonas horarias)', results.every(([, h, t]) => t === `${want(h)}, Ana`), JSON.stringify(results));
  check('Ninguna zona muestra «Usuario»', results.every(([, , t]) => !t.includes('Usuario')));
});

await attempt('Barra lateral sin barra de desplazamiento visible (1.7)', async () => {
  await page.setViewportSize({ width: 1280, height: 560 });
  await page.goto(BASE + '/');
  const sb = await page.evaluate(() => {
    const el = [...document.querySelectorAll('.sidebar, .sidebar *')].find((x) => x.scrollHeight > x.clientHeight + 4 && /(auto|scroll)/.test(getComputedStyle(x).overflowY));
    if (!el) return null;
    return { width: getComputedStyle(el).scrollbarWidth, gutter: el.offsetWidth - el.clientWidth };
  });
  check('Con poca altura, la barra lateral no dibuja ninguna barra de desplazamiento ni deja hueco', sb && sb.width === 'none' && sb.gutter === 0, JSON.stringify(sb));
  await page.locator('.sidebar').screenshot({ path: `${SHOTS}/16-barra-lateral.png` });
  await page.mouse.move(120, 300);
  await page.mouse.wheel(0, 400);
  await page.waitForTimeout(200);
  const moved = await page.evaluate(() => [...document.querySelectorAll('.sidebar, .sidebar *')].some((x) => x.scrollTop > 0));
  check('Sigue desplazándose con la rueda del ratón o el trackpad', moved);
  await page.evaluate(() => { document.querySelectorAll('.sidebar, .sidebar *').forEach((x) => { x.scrollTop = 0; }); });
  const last = page.locator('.sidebar .me-card');
  await last.focus();
  const inView = await last.evaluate((el) => { const r = el.getBoundingClientRect(); return r.top >= 0 && r.bottom <= innerHeight + 1; });
  check('Con el teclado (Tab) la última opción entra en la vista', inView);
  await page.setViewportSize({ width: 1440, height: 900 });
});

await attempt('Los seis temas cambian también el fondo principal', async () => {
  await page.goto(BASE + '/profile');
  const mainLook = () => page.evaluate(() => { const m = getComputedStyle(document.querySelector('.main')); const h = document.querySelector('.hero'); return m.backgroundImage + '|' + m.backgroundColor + '|' + (h ? getComputedStyle(h).backgroundImage.slice(0, 80) : ''); });
  const looks = new Set([await mainLook()]);
  const bad = [];
  for (const t of ['medieval', 'agua', 'fuego', 'tierra', 'cielo', 'bosque']) {
    await page.locator(`[data-ambient-pick="${t}"]`).click();
    await page.locator('#apariencia [data-save-state]', { hasText: 'Guardado' }).waitFor({ timeout: 6000 });
    const l = await mainLook();
    if (!/fondo\.webp|gradient/.test(l)) bad.push(t);
    looks.add(l);
    await page.goto(BASE + '/');
    await page.screenshot({ path: `${SHOTS}/16-tema-${t}-inicio.png` });
    const hero = await page.evaluate(() => getComputedStyle(document.querySelector('.hero')).backgroundImage);
    if (!hero.includes('cabecera.webp')) bad.push(t + ':cabecera');
    await page.goto(BASE + '/profile');
  }
  check('Cada tema cambia el fondo del área principal y la cabecera (no solo la barra lateral)', looks.size === 7 && bad.length === 0, bad.join(',') + ' / ' + looks.size);
  const fl = await page.evaluate(async () => (await fetch(location.href)).headers.get('cache-control'));
  check('Sin parpadeo: el tema llega en el HTML (no se aplica después con JavaScript)', (await page.evaluate(() => document.documentElement.outerHTML.slice(0, 300))).includes('data-ambient="bosque"') && /no-store/.test(fl || ''), fl);
  await page.locator('[data-ambient-pick="default"]').click();
  await page.locator('#apariencia [data-save-state]', { hasText: 'Guardado' }).waitFor({ timeout: 6000 });
});

let calPid = 0;
await attempt('Tareas: Mis tareas, subtareas, orden y paneles que no se cierran al guardar', async () => {
  await page.goto(BASE + '/tasks');
  for (const t of ['Revisar presupuesto', 'Llamar al proveedor', 'Enviar factura']) {
    await page.fill('#pt-new', t);
    await page.keyboard.press('Enter');
    await page.locator('.ptask-title', { hasText: '' }).last().waitFor();
    await page.waitForFunction((x) => [...document.querySelectorAll('.ptask-title')].some((i) => i.value === x), t, { timeout: 5000 });
  }
  check('Añadir tareas personales con Enter', (await page.locator('.ptask:not(.ptask--sub)').count()) === 3);
  const first = page.locator('.ptask:not(.ptask--sub)').first();
  const id = await first.getAttribute('data-ptask');
  await page.locator(`#ptask-more-${id} > summary`).click();
  await page.fill(`#pt-notes-${id}`, 'Comparar con el del año pasado');
  await page.locator(`#pt-notes-${id}`).blur();
  await waitToast(/Guardado/);
  await page.waitForTimeout(600);
  check('El panel de notas sigue abierto después de guardarse solo', await page.locator(`#ptask-more-${id}`).evaluate((d) => d.open));
  await page.fill(`#pt-sub-${id}`, 'Pedir la versión final');
  await page.keyboard.press('Enter');
  await page.locator(`#ptask-more-${id} .ptask--sub`).waitFor({ timeout: 5000 });
  check('Añadir una subtarea sin que se cierre el panel', await page.locator(`#ptask-more-${id}`).evaluate((d) => d.open) && (await page.locator(`#ptask-more-${id} .ptask--sub`).count()) === 1);
  await page.selectOption(`#pt-prio-${id}`, 'high');
  await waitToast(/Guardado/);
  await page.fill(`#pt-due-${id}`, plusDays(2));
  await page.locator(`#pt-due-${id}`).blur();
  await waitToast(/Guardado/);
  check('Prioridad y fecha límite se guardan solas', (await page.locator(`#pt-prio-${id}`).inputValue()) === 'high' && (await page.locator(`#pt-due-${id}-iso`).inputValue()) === plusDays(2));
  await page.locator(`#ptask-more-${id} > summary`).click(); // close the panel so the whole list fits on screen for the drag
  await page.waitForLoadState('networkidle'); // the autosaves above refresh the list; drag once it has settled
  await page.waitForTimeout(400);
  const before = await page.locator('.ptask:not(.ptask--sub)').evaluateAll((els) => els.map((e) => e.dataset.ptask));
  const src = await page.locator(`.ptask[data-ptask="${before[2]}"] .ptask-grip`).boundingBox();
  const dst = await page.locator(`.ptask[data-ptask="${before[0]}"] > .ptask-row`).boundingBox();
  await page.mouse.move(src.x + 4, src.y + 4); await page.mouse.down();
  await page.mouse.move(src.x + 4, src.y - 20, { steps: 4 });
  await page.mouse.move(dst.x + 20, dst.y + 4, { steps: 8 });
  await page.mouse.up();
  await waitToast(/Orden guardado/);
  await page.reload();
  const after = await page.locator('.ptask:not(.ptask--sub)').evaluateAll((els) => els.map((e) => e.dataset.ptask));
  check('Arrastrar para reordenar y el orden se conserva', after[0] === before[2], JSON.stringify([before, after]));
  await page.screenshot({ path: `${SHOTS}/17-mis-tareas.png`, fullPage: true });
  await page.locator(`.ptask[data-ptask="${before[1]}"] .subtask-check`).first().click();
  await waitToast(/Tarea completada/);
  await page.goto(BASE + '/tasks?tab=done');
  check('Completadas: aparece la tarea terminada', await page.locator('.ptask-list--done', { hasText: 'Llamar al proveedor' }).count() === 1);
  for (const tab of ['assigned', 'team']) { await page.goto(BASE + '/tasks?tab=' + tab); check(`Vista «${tab === 'assigned' ? 'Asignadas a mí' : 'Equipo'}» disponible`, (await page.locator('nav[aria-label="Vistas de tareas"] a[aria-current="page"]').count()) === 1); }
});

await attempt('Calendario: vistas, crear, editar, mostrar y ocultar', async () => {
  const pr = await call('POST', '/api/projects', { name: 'Calendario E2E', project_key: 'CALE' });
  calPid = pr.json.id;
  await call('POST', `/api/projects/${calPid}/tasks`, { title: 'Entregar maqueta', due_date: plusDays(1) });
  await page.goto(BASE + '/calendar');
  check('Vista mes con leyenda que distingue reunión, evento, tarea y vencimiento', await page.locator('.cal-month').isVisible() && (await page.locator('.cal-legend-i').count()) === 7);
  const taskChip = page.locator('.cal-item--task', { hasText: 'Entregar maqueta' });
  check('El vencimiento de la tarea aparece en el calendario', (await taskChip.count()) === 1);
  // click on a free spot of a day → dialog with that date
  const target = plusDays(3);
  const day = page.locator(`.cal-month .day[data-day="${target}"]`);
  if (await day.count()) {
    const box = await day.boundingBox();
    await page.mouse.click(box.x + box.width / 2, box.y + box.height - 12);
  } else {
    await page.click('[data-new-event][data-kind=event]');
    await page.fill('#ev-sd', target);
  }
  await page.locator('#dlg-event[open]').waitFor({ timeout: 4000 });
  check('Hacer clic en un día abre «Nuevo evento» con esa fecha', (await page.inputValue('#ev-sd-iso')) === target && (await page.inputValue('#ev-sd')) === dmy(target));
  await page.fill('#ev-title', 'Taller de diseño');
  await page.fill('#ev-st', '15:00'); await page.fill('#ev-et', '16:30');
  await page.click('#dlg-event [data-event-save]');
  await waitToast(/Evento creado/);
  await page.goto(BASE + `/calendar?view=week&date=${target}`);
  const ev = page.locator('.tg-event', { hasText: 'Taller de diseño' });
  check('Vista semana: el evento está en su hora', (await ev.count()) === 1 && /15:00–16:30/.test(await ev.textContent()));
  await page.screenshot({ path: `${SHOTS}/18-calendario-semana.png` });
  // click an hour slot → time prefilled
  const col = page.locator(`.tg-col[data-day="${target}"]`);
  const cb = await col.boundingBox();
  await page.locator('[data-tg-scroll]').evaluate((el) => { el.scrollTop = 8 * 44; });
  const cb2 = await col.boundingBox();
  await page.mouse.click(cb2.x + 10, cb2.y + (11 * 44) + 6);
  await page.locator('#dlg-event[open]').waitFor({ timeout: 4000 });
  check('Hacer clic en una hora libre propone esa hora', (await page.inputValue('#ev-st')) === '11:00', await page.inputValue('#ev-st'));
  await page.click('#dlg-event [data-close-dialog]');
  await ev.click();
  await page.locator('#dlg-event[open]').waitFor({ timeout: 4000 });
  check('Abrir un evento muestra su detalle editable', (await page.inputValue('#ev-title')) === 'Taller de diseño' && await page.locator('[data-event-delete]').isVisible());
  await page.fill('#ev-title', 'Taller de diseño (sala 2)');
  await page.waitForTimeout(700);
  check('Editar no cierra el panel por sí solo', await page.locator('#dlg-event[open]').count() === 1);
  await page.click('#dlg-event [data-event-save]');
  await waitToast(/Cambios guardados/);
  await page.locator('.tg-event', { hasText: 'sala 2' }).waitFor({ timeout: 5000 }).catch(() => {});
  check('Guardar cierra el diálogo y aplica el cambio', (await page.locator('.tg-event', { hasText: 'sala 2' }).count()) === 1 && (await page.locator('#dlg-event[open]').count()) === 0);
  await page.goto(BASE + `/calendar?view=day&date=${target}`);
  check('Vista día', (await page.locator('.tgrid[style*="--days:1"] .tg-event', { hasText: 'sala 2' }).count()) === 1);
  // hide / show a calendar
  const eqId = await page.locator('.cal-list label', { hasText: 'Equipo' }).locator('input').getAttribute('data-cal-toggle');
  const calOfEvent = await page.evaluate(async () => { const id = document.querySelector('.tg-event').dataset.event; return (await (await fetch(`/api/events/${id}`, { headers: { Accept: 'application/json' } })).json()).event.calendar_id; });
  const box = page.locator(`[data-cal-toggle="${calOfEvent}"]`);
  await box.uncheck();
  await page.waitForFunction(() => !document.querySelector('.tg-event'), null, { timeout: 5000 });
  check('Ocultar un calendario quita sus eventos de la vista', (await page.locator('.tg-event').count()) === 0);
  await page.reload();
  check('…y se recuerda al recargar', !(await page.locator(`[data-cal-toggle="${calOfEvent}"]`).isChecked()));
  await page.locator(`[data-cal-toggle="${calOfEvent}"]`).check();
  await page.locator('.tg-event').first().waitFor({ timeout: 5000 });
  check('Mostrarlo de nuevo lo superpone con los demás', (await page.locator('.tg-event').count()) >= 1 && !!eqId);
});

await attempt('Reunión semanal con participantes y enlace', async () => {
  await page.goto(BASE + '/calendar');
  await page.click('[data-new-event][data-kind=meeting]');
  await page.locator('#dlg-event[open]').waitFor();
  const calName = await page.locator('#ev-cal option:checked').textContent();
  check('«Nueva reunión» propone el calendario Reuniones', calName.includes('Reuniones'), calName);
  const monday = (() => { const d = cdmx(); d.setDate(d.getDate() + ((8 - d.getDay()) % 7 || 7)); return ymd(d); })();
  await page.fill('#ev-title', 'Planificación semanal');
  await page.fill('#ev-sd', dmy(monday)); await page.fill('#ev-ed', dmy(monday)); // typed as dd/mm/aaaa
  await page.fill('#ev-st', '10:00'); await page.fill('#ev-et', '10:45');
  await page.selectOption('#ev-rec', 'weekly');
  check('Elegir «Cada semana» muestra «Hasta»', await page.locator('#dlg-event [data-until]').isVisible());
  await page.fill('#ev-url', 'https://meet.example.test/plan');
  await page.locator('#dlg-event .cal-people input[type=checkbox]').nth(1).check();
  await page.click('#dlg-event [data-event-save]');
  await waitToast(/Reunión creada/);
  // the month of the Monday after: its grid (weeks start on Monday) always holds both Mondays, even at the end of a month
  const mondayAfter = (() => { const d = new Date(monday + 'T12:00:00'); d.setDate(d.getDate() + 7); return ymd(d); })();
  await page.goto(BASE + `/calendar?view=month&date=${mondayAfter}`);
  const n = await page.locator('.cal-item--meeting', { hasText: 'Planificación semanal' }).count();
  check('Se repite cada lunes (y se distingue como reunión)', n >= 2, String(n));
  await page.locator('.cal-item--meeting', { hasText: 'Planificación semanal' }).first().click();
  await page.locator('#dlg-event[open]').waitFor();
  check('El detalle ofrece «Unirse» con el enlace de videollamada', (await page.locator('[data-event-video]').getAttribute('href')) === 'https://meet.example.test/plan' && await page.locator('[data-event-video]').isVisible());
  await page.screenshot({ path: `${SHOTS}/19-reunion.png` });
  await page.click('#dlg-event [data-close-dialog]');
});

await attempt('Cambiar la fecha de una tarea actualiza el calendario', async () => {
  await page.goto(BASE + `/calendar?date=${plusDays(1)}`);
  const chip = page.locator('.cal-item--task', { hasText: 'Entregar maqueta' });
  const to = plusDays(2);
  const dest = page.locator(`.cal-month .day[data-day="${to}"]`);
  if (!(await dest.count())) { check('Arrastrar una tarea a otro día', true); return; }
  const a = await chip.boundingBox(); const b = await dest.boundingBox();
  await page.mouse.move(a.x + 10, a.y + 5); await page.mouse.down();
  await page.mouse.move(b.x + 30, b.y + 40, { steps: 10 }); await page.mouse.up();
  await waitToast(/Fecha|actualiz|Guardado/i);
  await page.reload();
  check('Arrastrar la tarea a otro día cambia su vencimiento y el calendario lo refleja', (await page.locator(`.day[data-day="${to}"] .cal-item--task`, { hasText: 'Entregar maqueta' }).count()) === 1);
  await chip.first().click();
  await page.locator('#drawer.open').waitFor({ timeout: 5000 });
  check('Hacer clic en la tarea abre su detalle', await page.locator('#drawer.open').isVisible());
  await page.keyboard.press('Escape');
});

await attempt('Calendarios: crear con color y eliminar con confirmación', async () => {
  await page.goto(BASE + '/calendar');
  await page.click('[data-new-calendar]');
  await page.locator('#dlg-calendar[open]').waitFor();
  await page.fill('#cal-name', 'Formación');
  await page.locator('#dlg-calendar .swatch').nth(3).click();
  await page.click('#dlg-calendar button[type=submit]');
  await waitToast(/Calendario creado/);
  const row = page.locator('.cal-list li', { hasText: 'Formación' });
  await row.waitFor({ timeout: 5000 }).catch(() => {});
  check('El calendario nuevo aparece con su color', (await row.count()) === 1);
  await row.locator('[data-edit-calendar]').click();
  await page.locator('#dlg-calendar[open]').waitFor();
  await page.click('[data-cal-delete]');
  await page.locator('#dlg-confirm[open]').waitFor();
  check('Eliminar pide confirmación', await page.locator('#dlg-confirm [data-confirm-text]').textContent().then((t) => t.includes('Formación')));
  await page.click('#dlg-confirm [data-confirm-ok]');
  await waitToast(/Calendario eliminado/);
  await page.waitForFunction(() => ![...document.querySelectorAll('.cal-list li')].some((l) => l.textContent.includes('Formación')), null, { timeout: 5000 }).catch(() => {});
  check('Se elimina', (await page.locator('.cal-list li', { hasText: 'Formación' }).count()) === 0);
});

await attempt('Colores legibles en todos los temas y en el móvil', async () => {
  const contrast = () => page.evaluate(() => {
    const rgb = (c) => { const m = c.match(/[\d.]+/g).map(Number); return c.startsWith('color(') ? m.slice(0, 3).map((v) => v * 255) : m.slice(0, 3); };
    const L = ([r, g, b]) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); };
    const bg = (el) => { for (let n = el; n; n = n.parentElement) { const c = getComputedStyle(n).backgroundColor; const a = c.match(/[\d.]+/g).map(Number)[3]; if (a === undefined || a >= 0.95) return c; } return 'rgb(255, 255, 255)'; }; // transparent: the colour seen is the one behind
    return [...document.querySelectorAll('.cal-item, .tg-event')].slice(0, 30).map((el) => { const cs = getComputedStyle(el); const [a, b] = [L(rgb(cs.color)), L(rgb(bg(el)))].sort((x, y) => y - x); return (a + 0.05) / (b + 0.05); });
  });
  let worst = 99;
  for (const t of ['default', 'medieval', 'fuego', 'tierra']) {
    await call('PATCH', '/api/profile/prefs', { ambient: t });
    await page.goto(BASE + '/calendar');
    const cs = await contrast();
    worst = Math.min(worst, ...cs.filter((x) => x > 0));
    await page.screenshot({ path: `${SHOTS}/20-calendario-${t}.png` });
  }
  await call('PATCH', '/api/profile/prefs', { ambient: 'default' });
  check('Los elementos del calendario mantienen contraste AA (≥ 4,5:1) en los temas', worst >= 4.5, worst.toFixed(2));
  await page.setViewportSize({ width: 390, height: 844 });
  for (const path of ['/calendar', '/calendar?view=week', '/calendar?view=day', '/tasks']) {
    await page.goto(BASE + path);
    const over = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    check(`Móvil: ${path} sin desplazamiento horizontal de la página`, over <= 1, String(over));
    await page.screenshot({ path: `${SHOTS}/21-movil-${path.replace(/\W+/g, '-')}.png`, fullPage: false });
  }
  await page.goto(BASE + '/calendar');
  await page.click('[data-new-event][data-kind=event]');
  await page.locator('#dlg-event[open]').waitFor();
  const dl = await page.locator('#dlg-event').boundingBox();
  check('Móvil: el diálogo de evento cabe en la pantalla', dl.width <= 390 && dl.x >= 0);
  await page.click('#dlg-event [data-close-dialog]');
  await page.locator('[data-tour="account"]').click().catch(() => {});
  await page.goto(BASE + '/profile');
  await page.locator('#ayuda [data-tour-start]').click();
  await page.locator('.tour-card').waitFor({ timeout: 6000 });
  check('Móvil: el tutorial se muestra como hoja inferior legible', await page.locator('.tour.is-sheet .tour-card').isVisible());
  await page.click('[data-tour-skip]');
  await page.setViewportSize({ width: 1440, height: 900 });
});

await attempt('Google: sin configuración no se finge la conexión', async () => {
  await page.goto(BASE + '/profile#google');
  check('El perfil explica que Google es opcional y no está configurado', await page.locator('#google', { hasText: 'no está configurada' }).isVisible() && (await page.locator('#google a[href*="google/connect"]').count()) === 0);
  await page.locator('#google').screenshot({ path: `${SHOTS}/22-google-perfil.png` });
});

section('Novedades 1.7');
await attempt('Fotografía de perfil con recorte', async () => {
  await page.goto(BASE + '/profile');
  const png = await page.evaluate(async () => {
    const c = document.createElement('canvas'); c.width = 900; c.height = 600;
    const x = c.getContext('2d'); x.fillStyle = '#1d6b52'; x.fillRect(0, 0, 900, 600); x.fillStyle = '#f2c94c'; x.beginPath(); x.arc(600, 300, 160, 0, Math.PI * 2); x.fill();
    return c.toDataURL('image/png').split(',')[1];
  });
  check('Sin fotografía se ven las iniciales', (await page.locator('#topbar-me-photo .avatar.has-photo').count()) === 0 && /AR/.test(await page.locator('#topbar-me-photo').textContent()));
  await page.setInputFiles('[data-photo-input]', { name: 'retrato.png', mimeType: 'image/png', buffer: Buffer.from(png, 'base64') });
  await page.locator('#dlg-photo[open]').waitFor({ timeout: 5000 });
  check('Al elegir la imagen se abre la herramienta de recorte', await page.locator('#dlg-photo [data-crop-canvas]').isVisible());
  const stage = await page.locator('[data-crop-stage]').boundingBox();
  await page.mouse.move(stage.x + stage.width / 2, stage.y + stage.height / 2);
  await page.mouse.down();
  await page.mouse.move(stage.x + stage.width / 2 - 60, stage.y + stage.height / 2, { steps: 5 });
  await page.mouse.up();
  await page.locator('[data-crop-zoom]').fill('1.6');
  await page.locator('#dlg-photo').screenshot({ path: `${SHOTS}/23-recorte-foto.png` });
  await page.click('[data-crop-save]');
  await waitToast(/Fotografía guardada/);
  await page.locator('#topbar-me-photo .avatar.has-photo img').waitFor({ timeout: 5000 });
  const img = await page.locator('#topbar-me-photo img').evaluate((i) => new Promise((res) => { if (i.complete) res([i.naturalWidth, i.naturalHeight, i.currentSrc]); else i.onload = () => res([i.naturalWidth, i.naturalHeight, i.currentSrc]); }));
  check('Se guarda y aparece en el menú de la cuenta sin recargar', img[0] === 96 && img[1] === 96, JSON.stringify(img));
  const center = await page.locator('#foto .avatar img').evaluate(async (i) => {
    await (i.complete ? null : new Promise((r) => { i.onload = r; }));
    const r = await fetch(i.currentSrc.replace(/\/96$/, '/256')); const b = await createImageBitmap(await r.blob());
    const c = document.createElement('canvas'); c.width = b.width; c.height = b.height; const x = c.getContext('2d'); x.drawImage(b, 0, 0);
    return [...x.getImageData(128, 128, 1, 1).data].slice(0, 3);
  });
  check('El recorte elegido es el que se guarda (el círculo amarillo queda al centro)', center[0] > 200 && center[1] > 160 && center[2] < 140, JSON.stringify(center));
  await page.screenshot({ path: `${SHOTS}/24-perfil-con-foto.png` });
  await page.locator('#foto [data-call="/api/profile/photo"]').click();
  await page.locator('#dlg-confirm[open]').waitFor();
  await page.click('#dlg-confirm [data-confirm-ok]');
  await waitToast(/Fotografía eliminada/);
  await page.waitForFunction(() => !document.querySelector('#topbar-me-photo .has-photo'), null, { timeout: 5000 });
  check('Eliminarla vuelve a las iniciales', /AR/.test(await page.locator('#topbar-me-photo').textContent()));
});

await attempt('Crear cuenta con horas semanales y enlace correcto', async () => {
  await page.goto(BASE + '/admin/members');
  await page.click('[data-dialog="dlg-create-user"]');
  await page.locator('#dlg-create-user[open]').waitFor();
  check('El formulario pide «Horas semanales disponibles»', await page.locator('#dlg-create-user label[for="cu-h"]', { hasText: 'Horas semanales disponibles' }).isVisible());
  await page.fill('#cu-n', 'Olivia Quince');
  await page.fill('#cu-e', 'olivia.quince@example.test');
  await page.fill('#cu-h', '15');
  await page.click('#dlg-create-user button[type="submit"]');
  const box = page.locator('#dlg-create-user [data-invite-link] textarea, #dlg-create-user [data-invite-link] .share-text, #dlg-create-user [data-invite-link]');
  await page.locator('#dlg-create-user [data-invite-link]:not([hidden])').waitFor({ timeout: 6000 });
  const text = await page.locator('#dlg-create-user [data-invite-link]').evaluate((el) => (el.querySelector('textarea')?.value || el.textContent));
  const link = (text.match(/https?:\/\/\S+\/login/) || [''])[0];
  check('El mensaje para copiar trae el enlace de inicio de sesión de esta instalación', link === new URL('login', BASE.replace(/\/?$/, '/')).href, text);
  await page.locator('#dlg-create-user [data-close-dialog]').first().click();
  await page.goto(BASE + '/admin/members');
  const row = page.locator('tr', { hasText: 'Olivia Quince' });
  check('La lista muestra las 15 h y se pueden cambiar ahí mismo', (await row.locator('input[name="weekly_hours"]').inputValue()) === '15');
});

await attempt('«Calendario» en el menú lateral', async () => {
  await page.goto(BASE + '/calendar');
  const item = page.locator('.sidebar a.nav-item[href$="/calendar"]');
  check('Escritorio: «Calendario» con su icono, marcado al estar en la sección', (await item.getAttribute('aria-current')) === 'page' && (await item.locator('svg use').getAttribute('href')).includes('calendar') && (await item.textContent()).trim() === 'Calendario');
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(BASE + '/');
  await page.locator('[data-sidebar-toggle], .sidebar-toggle, button[aria-controls="sidebar"]').first().click();
  await page.locator('.sidebar a.nav-item[href$="/calendar"]').click();
  await page.waitForURL(/\/calendar/);
  check('Móvil: se abre desde el menú y lleva al calendario', (await page.locator('h1', { hasText: 'Calendario' }).count()) === 1);
  await page.setViewportSize({ width: 1440, height: 900 });
});

section('Novedades 1.7.1');
const apiCall = (path, method = 'GET', body) => page.evaluate(async ([path, method, body]) => {
  const r = await fetch(path, { method, headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content, Accept: 'application/json', 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined });
  return { status: r.status, data: await r.json().catch(() => null) };
}, [path, method, body]);
await attempt('Tutorial: navegación real entre páginas', async () => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(BASE + '/');
  const modules = [];
  const onReq = (r) => { if (/\/assets\/js\/[a-z-]+\.js/.test(r.url())) modules.push(r.url()); };
  page.on('request', onReq);
  await page.reload();
  await page.waitForLoadState('load');
  page.off('request', onReq);
  check('Cada módulo de JavaScript se pide con la versión (la caché del servidor no puede servir el tutorial antiguo)', modules.length > 5 && modules.every((u) => /\?v=/.test(u)), modules.filter((u) => !/\?v=/.test(u)).join(', '));
  // The server cannot save the step (as if a proxy rejected the request): the tutorial must still move on.
  const errorsBefore = consoleErrors.length;
  await page.route('**/api/profile/prefs', (r) => r.abort());
  await page.locator('[data-tour="account"]').click();
  await page.locator('.menu.open [data-tour-start]').click();
  await page.locator('.tour-card').waitFor({ timeout: 5000 });
  const step = () => page.locator('.tour-step').textContent();
  const nextNav = async () => { const nav = page.waitForNavigation({ timeout: 5000 }).catch(() => null); await page.click('[data-tour-next]'); await nav; await page.locator('.tour-card').waitFor({ timeout: 6000 }); await page.waitForFunction(() => document.documentElement.dataset.tourReady !== undefined, null, { timeout: 6000 }).catch(() => {}); };
  for (let i = 0; i < 3; i++) await nextNav();
  check('Sin poder guardar en el servidor, «Siguiente» abre Proyectos y el tutorial continúa', new URL(page.url()).pathname === '/projects' && (await step()).includes('Paso 4 de') && await page.locator('.tour-spot').isVisible(), page.url() + ' ' + (await step()));
  check('La dirección queda limpia (sin ?tour=) tras abrir la página', !new URL(page.url()).searchParams.has('tour'), page.url());
  const link = await page.locator('#sidebar a[href$="/calendar"]').first().boundingBox();
  await page.mouse.click(link.x + 10, link.y + link.height / 2);
  await page.waitForTimeout(700);
  check('Mientras dura, la página no responde a clics (no puede cambiar información real)', new URL(page.url()).pathname === '/projects');
  await nextNav();
  check('Proyectos → Tareas', new URL(page.url()).pathname === '/tasks' && (await step()).includes('Paso 5 de'));
  await page.reload();
  await page.locator('.tour-card').waitFor({ timeout: 6000 });
  check('Recargar a mitad conserva el paso aunque el servidor no lo guardara', (await step()).includes('Paso 5 de'), await step());
  await page.click('[data-tour-next]'); await page.waitForTimeout(400);
  await nextNav();
  check('Tareas → Sprints (en un proyecto real)', /\/projects\/\d+\/sprints$/.test(new URL(page.url()).pathname) && (await step()).includes('Sprints'), page.url());
  await nextNav();
  check('Sprints → Calendario', new URL(page.url()).pathname === '/calendar' && (await step()).includes('Calendario'));
  const nav = page.waitForNavigation({ timeout: 5000 }).catch(() => null);
  await page.click('[data-tour-prev]'); await nav; await page.locator('.tour-card').waitFor({ timeout: 6000 });
  check('«Anterior» vuelve a la página del paso previo', /\/sprints$/.test(new URL(page.url()).pathname), page.url());
  const nav2 = page.waitForNavigation({ timeout: 5000 }).catch(() => null);
  await page.click('[data-tour-restart]'); await nav2; await page.locator('.tour-card').waitFor({ timeout: 6000 });
  check('«Reiniciar» vuelve al paso 1 en Inicio', new URL(page.url()).pathname === '/' && (await step()).includes('Paso 1 de'));
  await page.screenshot({ path: `${SHOTS}/25-tutorial-reiniciar.png` });
  await page.unroute('**/api/profile/prefs');
  // the requests blocked on purpose show up as «Failed to load resource»: they are not app errors
  consoleErrors.splice(errorsBefore, consoleErrors.length - errorsBefore, ...consoleErrors.slice(errorsBefore).filter((m) => !/ERR_FAILED/.test(m)));
  await page.click('[data-tour-skip]');
  await page.waitForTimeout(500);
  await page.reload(); await page.waitForTimeout(900);
  check('Omitido, no reaparece', (await page.locator('.tour-card').count()) === 0);
});

let estKey = '';
await attempt('«+ Nueva tarea» con tiempo estimado', async () => {
  await page.goto(BASE + '/');
  await page.click('[data-tour="new-task"]');
  await page.fill('#nt-title', 'Diseñar pantalla de inicio');
  const me = await page.$$eval('#nt-assignee option:not([hidden])', (os) => os.find((o) => /\(yo\)/.test(o.textContent))?.value || '');
  if (me) await page.selectOption('#nt-assignee', me);
  check('El formulario «Nueva tarea» tiene «Tiempo estimado» y «Sprint» a la vista', await page.locator('#nt-hours').isVisible() && await page.locator('#nt-sprint').isVisible());
  await page.fill('#nt-hours', '6');
  await page.screenshot({ path: `${SHOTS}/26-nueva-tarea-horas.png` });
  const resp = page.waitForResponse((r) => /\/api\/projects\/\d+\/tasks$/.test(r.url()) && r.request().method() === 'POST');
  await page.click('#dlg-new-task button[type=submit]');
  const data = await (await resp).json();
  estKey = data.task?.key || '';
  const id = data.task?.id || data.id;
  const t = await apiCall(`/api/tasks/${id}`);
  const task = t.data?.task || t.data;
  check('La tarea se guarda con 6 horas estimadas', Number(task?.estimate_hours) === 6, JSON.stringify(t.data).slice(0, 200));
  estKey = task?.key || estKey;
  await page.goto(BASE + '/t/' + estKey);
  await page.waitForTimeout(800);
  const val = await page.locator(`#td-eh-${id}`).inputValue().catch(() => '');
  check('Al abrir la tarea, «Estimado» muestra las 6 h', /^6([.,]0+)?$/.test(val), val);
  await page.goto(BASE + '/');
  await page.click('[data-tour="new-task"]');
  await page.fill('#nt-title', 'Horas negativas');
  await page.fill('#nt-hours', '-3');
  await page.evaluate(() => document.getElementById('nt-hours').removeAttribute('min'));
  await page.click('#dlg-new-task button[type=submit]');
  await page.waitForTimeout(900);
  check('Horas no válidas: no se crea y se explica en el campo', await page.locator('#dlg-new-task[open]').count() === 1 && ((await page.locator('#dlg-new-task [data-error-for="estimate_hours"]').textContent()) + (await page.locator('#dlg-new-task [data-form-error]').textContent())).trim().length > 0);
  await page.keyboard.press('Escape');
});

await attempt('«Mis tareas» muestra las tareas asignadas y las personales', async () => {
  await page.goto(BASE + '/tasks');
  const html = await page.locator('main').innerText();
  check('«Mis tareas» lista las tareas de proyectos asignadas a la persona', html.includes('Asignadas en proyectos') && html.includes('Diseñar pantalla de inicio'), html.slice(0, 300));
  check('Y, por separado, las personales (privadas)', await page.locator('#mine-personal-h').isVisible() && await page.locator('[data-tour="tasks-add"]').isVisible());
  await page.goto(BASE + '/tasks?tab=assigned');
  check('El enlace antiguo «Asignadas a mí» abre Mis tareas filtrado por proyectos', await page.locator('.chip.is-on').textContent().then((t) => t.includes('De proyectos')) && (await page.locator('#mine-personal-h').count()) === 0);
  await page.goto(BASE + '/tasks?show=personal');
  check('Filtro «Personales» muestra solo las privadas', (await page.locator('#mine-projects-h').count()) === 0 && await page.locator('#mine-personal-h').isVisible());
  await page.goto(BASE + '/tasks');
  await page.screenshot({ path: `${SHOTS}/27-mis-tareas.png`, fullPage: true });
});

await attempt('«Completadas» sin errores visuales', async () => {
  await page.goto(BASE + '/tasks?tab=done');
  const cards = await page.locator('.done-grid > .card').evaluateAll((els) => els.map((e) => { const r = e.getBoundingClientRect(); return [r.left, r.top, r.width, r.height]; }));
  check('Escritorio: dos columnas con separación, sin superponerse', cards.length === 2 && Math.abs(cards[0][1] - cards[1][1]) < 2 && cards[1][0] - (cards[0][0] + cards[0][2]) >= 12, JSON.stringify(cards));
  const pad = await page.evaluate(() => { const li = document.querySelector('.ptask-list--done .ptask'); const card = li?.closest('.card'); if (!li) return null; return li.getBoundingClientRect().left - card.getBoundingClientRect().left; });
  check('Las filas no tocan el borde de la tarjeta', pad === null || pad >= 12, String(pad));
  await page.screenshot({ path: `${SHOTS}/28-completadas.png` });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.reload();
  const m = await page.locator('.done-grid > .card').evaluateAll((els) => els.map((e) => { const r = e.getBoundingClientRect(); return [r.left, r.top, r.width, r.height]; }));
  const scrollX = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  check('Móvil: una columna, una debajo de la otra con separación y sin desbordar', m.length === 2 && m[1][1] >= m[0][1] + m[0][3] + 12 && scrollX <= 0, JSON.stringify(m) + ' ' + scrollX);
  await page.screenshot({ path: `${SHOTS}/28-completadas-movil.png`, fullPage: true });
  await page.goto(BASE + '/tasks');
  const sx = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  check('Móvil: «Mis tareas» sin desplazamiento horizontal', sx <= 0, String(sx));
  await page.screenshot({ path: `${SHOTS}/27-mis-tareas-movil.png`, fullPage: true });
  await page.setViewportSize({ width: 1440, height: 900 });
});

section('Novedades 1.8');
const noOverflow = () => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
await attempt('Calendario personal y de equipo', async () => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(BASE + '/calendar?scope=both');
  check('El calendario ofrece «Ambos / Personal / Equipo»', await page.locator('[data-tour="cal-scope"] a').count() === 3 && await page.locator('.cal-group--personal').isVisible() && await page.locator('.cal-group--team').isVisible());
  await page.screenshot({ path: `${SHOTS}/30-calendario-ambos.png` });
  await page.click('[data-tour="cal-scope"] a:has-text("Personal")');
  await page.waitForLoadState('load');
  check('«Personal» muestra solo los calendarios personales', new URL(page.url()).searchParams.get('scope') === 'personal' && await page.locator('.cal-group--team').count() === 0 && await page.locator('.cal-group--personal').isVisible());
  await page.screenshot({ path: `${SHOTS}/30-calendario-personal.png` });
  await page.click('[data-tour="cal-scope"] a:has-text("Equipo")');
  await page.waitForLoadState('load');
  check('«Equipo» muestra solo los de equipo', await page.locator('.cal-group--personal').count() === 0 && await page.locator('.cal-group--team').isVisible());
  await page.click('[data-new-calendar]');
  await page.locator('#dlg-calendar[open]').waitFor({ timeout: 4000 });
  const teamShown = await page.locator('#dlg-calendar [data-cal-team]').isVisible();
  await page.check('#dlg-calendar input[name=kind][value=personal]');
  const teamHidden = !(await page.locator('#dlg-calendar [data-cal-team]').isVisible());
  check('Nuevo calendario: «De equipo» pide el equipo; «Personal» no lo ofrece', teamShown && teamHidden, `${teamShown} ${teamHidden}`);
  await page.check('#dlg-calendar input[name=kind][value=shared]');
  await page.screenshot({ path: `${SHOTS}/30-nuevo-calendario.png` });
  await page.locator('#dlg-calendar [data-close-dialog]').first().click().catch(() => page.keyboard.press('Escape'));
  await page.goto(BASE + '/calendar?scope=both');
  await page.setViewportSize({ width: 390, height: 844 });
  await page.reload();
  check('Móvil: el calendario con el selector no desborda', await noOverflow() <= 1, String(await noOverflow()));
  await page.screenshot({ path: `${SHOTS}/30-calendario-movil.png`, fullPage: true });
  await page.setViewportSize({ width: 1440, height: 900 });
});

await attempt('Notificaciones configurables', async () => {
  await page.goto(BASE + '/profile#notificaciones');
  const form = page.locator('[data-notify-form]');
  check('Perfil › Notificaciones: categorías, anticipación y móvil', await form.isVisible() && await form.locator('input[name="in_app[]"]').count() === 11 && await page.locator('[data-push-box]').isVisible());
  await form.locator('input[name="in_app[]"][value="capacity"]').uncheck();
  await form.locator('input[name="before[]"][value="2880"], input[name="before[]"][value="4320"]').first().check();
  const resp = page.waitForResponse((r) => r.url().includes('/api/profile/notifications'));
  await form.locator('button[type=submit]').click();
  const r = await resp;
  const saved = (await r.json()).notify || {};
  check('Guardar desde la página aplica la elección (capacidad apagada, 3 días, 24 h y 30 min antes)', r.status() === 200 && JSON.stringify(saved.before) === '[4320,1440,30]' && (saved.off || []).includes('capacity'), JSON.stringify(saved));
  await page.waitForTimeout(600);
  await page.locator('#notificaciones').screenshot({ path: `${SHOTS}/31-preferencias-notificaciones.png` });
  await page.reload();
  check('Al volver, la elección sigue ahí', !(await form.locator('input[name="in_app[]"][value="capacity"]').isChecked()) && await form.locator('input[name="before[]"][value="4320"]').isChecked());
  await page.goto(BASE + '/notifications');
  check('El centro de notificaciones filtra por tipo', await page.locator('.notif-cats .chip').count() === 12);
  await page.screenshot({ path: `${SHOTS}/31-centro-notificaciones.png` });
  check('La campana tiene contador o queda sin número cuando no hay pendientes', await page.locator('[data-tour="notifications"]').isVisible());
});

await attempt('Sprints en dos etapas', async () => {
  await page.goto(BASE + '/sprints');
  check('Página «Sprints» con sus tarjetas', await page.locator('h1', { hasText: 'Sprints' }).isVisible());
  await page.click('[data-dialog="dlg-quick-sprint"]');
  await page.locator('#dlg-quick-sprint[open]').waitFor({ timeout: 4000 });
  await page.fill('#qs-n', 'Sprint de prueba 1.8');
  await page.fill('#qs-g', 'Probar la creación en dos etapas');
  const today = new Date(); const iso = (d) => d.toISOString().slice(0, 10);
  await page.fill('#qs-s', iso(today)); await page.fill('#qs-e', iso(new Date(today.getTime() + 13 * 864e5)));
  await page.screenshot({ path: `${SHOTS}/32-sprint-etapa-1.png` });
  const resp = page.waitForResponse((r) => /\/api\/projects\/\d+\/sprints$/.test(r.url()) && r.request().method() === 'POST');
  await page.click('#dlg-quick-sprint button[type=submit]');
  const r = await resp;
  const created = await r.json();
  check('Etapa 1: con nombre, proyecto, objetivo y fechas se crea', r.status() === 200 && created.id > 0, JSON.stringify(created).slice(0, 200));
  await page.goto(BASE + '/sprints');
  check('Aparece en «Sprints» con lo que falta planificar', (await page.locator('.sprint-card', { hasText: 'Sprint de prueba 1.8' }).count()) === 1 && (await page.locator('.sprint-card', { hasText: 'Sprint de prueba 1.8' }).innerText()).includes('Por planificar'));
  await page.screenshot({ path: `${SHOTS}/32-sprints.png`, fullPage: true });
  await page.locator('.sprint-card', { hasText: 'Sprint de prueba 1.8' }).click();
  await page.waitForLoadState('load');
  check('Etapa 2: la planificación del sprint está a la vista', await page.locator('#sprint-plan').isVisible(), page.url());
  await page.locator('#sprint-plan').screenshot({ path: `${SHOTS}/32-sprint-etapa-2.png` });
});

await attempt('«Todo a mano»', async () => {
  await page.goto(BASE + '/integrations');
  const legend = await page.locator('.tools-legend').innerText();
  check('Distingue «Enlace directo» de «Integración»', legend.includes('Enlace directo') && legend.includes('Integración'), legend);
  const det = page.locator('details.tools-config').first();
  await det.locator('summary').click();
  await det.locator('input[name=url]').first().fill('https://drive.google.com/drive/folders/prueba18');
  await det.locator('input[name=title]').first().fill('Carpeta de Drive');
  const resp = page.waitForResponse((r) => /\/links$/.test(r.url()));
  await det.locator('form[data-api$="/links"] button[type=submit]').click();
  check('Añadir un enlace de Drive desde la página', (await resp).status() === 200);
  await page.reload();
  check('El enlace aparece como acceso directo', (await page.locator('a', { hasText: 'Carpeta de Drive' }).count()) > 0);
  await page.screenshot({ path: `${SHOTS}/33-todo-a-mano.png`, fullPage: true });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.reload();
  check('Móvil: «Todo a mano» no desborda', await noOverflow() <= 1, String(await noOverflow()));
  await page.screenshot({ path: `${SHOTS}/33-todo-a-mano-movil.png`, fullPage: true });
  await page.setViewportSize({ width: 1440, height: 900 });
});

section('Fechas dd/mm/aaaa (1.8.1)');
await attempt('Fechas en día/mes/año aunque el navegador esté en inglés', async () => {
  const en = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-US', storageState: await ctx.storageState() });
  const p2 = await en.newPage();
  p2.on('pageerror', (e) => consoleErrors.push(e.message));
  await p2.goto(BASE + '/tasks');
  await p2.fill('#pt-new', 'Revisar fechas');
  await p2.keyboard.press('Enter');
  const row = p2.locator('.ptask:not(.ptask--sub)', { has: p2.locator('.ptask-title[value="Revisar fechas"]') }).first();
  await p2.waitForFunction(() => [...document.querySelectorAll('.ptask-title')].some((i) => i.value === 'Revisar fechas'), null, { timeout: 5000 });
  const id = await p2.locator('.ptask:not(.ptask--sub)').evaluateAll((els) => els.map((e) => [e.dataset.ptask, e.querySelector('.ptask-title').value]).find((x) => x[1] === 'Revisar fechas')[0]);
  await p2.locator(`#ptask-more-${id} > summary`).click();
  const field = p2.locator(`#pt-due-${id}`);
  check('Con el navegador en inglés, el campo de fecha pide dd/mm/aaaa (no mm/dd/aaaa)', (await field.getAttribute('placeholder')) === 'dd/mm/aaaa' && (await field.getAttribute('type')) === 'text');
  check('Junto al campo hay un botón para elegir en el calendario', await p2.locator(`#pt-due-${id} ~ .date-pick`).isVisible());
  await field.click();
  await p2.keyboard.type('09112026');
  check('Al escribir los números se añaden las barras (09/11/2026)', (await field.inputValue()) === '09/11/2026', await field.inputValue());
  await p2.keyboard.press('Enter');
  await p2.locator('#toasts .toast', { hasText: /Guardado/ }).first().waitFor({ timeout: 6000 });
  await p2.reload();
  await p2.locator(`#ptask-more-${id} > summary`).click();
  check('Se guarda como 9 de noviembre y se vuelve a mostrar 09/11/2026', (await p2.inputValue(`#pt-due-${id}-iso`)) === '2026-11-09' && (await p2.inputValue(`#pt-due-${id}`)) === '09/11/2026');
  await p2.fill(`#pt-due-${id}`, '31/02/2026');
  await p2.locator(`#pt-due-${id}`).blur();
  await p2.waitForTimeout(400);
  const msg = await p2.locator(`#pt-due-${id}`).evaluate((i) => i.validationMessage);
  check('Una fecha que no existe (31/02/2026) se rechaza con el motivo y no se guarda', msg.includes('dd/mm/aaaa') && (await p2.inputValue(`#pt-due-${id}-iso`)) === '2026-11-09', msg);
  await p2.screenshot({ path: `${SHOTS}/31-fecha-dd-mm.png`, clip: { x: 0, y: 0, width: 1440, height: 700 } });
  await p2.goto(BASE + '/calendar');
  await p2.click('[data-new-event][data-kind=event]');
  await p2.locator('#dlg-event[open]').waitFor();
  check('El diálogo de evento también muestra la fecha como dd/mm/aaaa', /^\d{2}\/\d{2}\/\d{4}$/.test(await p2.inputValue('#ev-sd')), await p2.inputValue('#ev-sd'));
  await en.close();
});

section('Novedades 1.9');
await attempt('Hora de entrega en la tarea', async () => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(BASE + '/');
  await page.click('[data-tour="new-task"]');
  await page.fill('#nt-title', 'Enviar propuesta al cliente');
  const me = await page.$$eval('#nt-assignee option:not([hidden])', (os) => os.find((o) => /\(yo\)/.test(o.textContent))?.value || '');
  if (me) await page.selectOption('#nt-assignee', me);
  await page.locator('#dlg-new-task details.more-options summary').first().click();
  const d = new Date(Date.now() + 2 * 86400000);
  const dmy = `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()}`;
  await page.fill('#nt-due', dmy);
  await page.fill('#nt-dt', '16:30');
  check('«Nueva tarea» ofrece la hora de entrega (opcional) junto a la fecha', await page.locator('#nt-dt').isVisible());
  await page.screenshot({ path: `${SHOTS}/34-nueva-tarea-hora.png` });
  const resp = page.waitForResponse((r) => /\/api\/projects\/\d+\/tasks$/.test(r.url()) && r.request().method() === 'POST');
  await page.click('#dlg-new-task button[type=submit]');
  const data = await (await resp).json();
  const key = data.task?.key;
  const t = await apiCall(`/api/tasks/${data.task?.id}`);
  check('La tarea se guarda con su hora', String((t.data?.task || t.data)?.due_time || '').startsWith('16:30'), JSON.stringify(t.data).slice(0, 200));
  await page.goto(BASE + '/t/' + key);
  await page.waitForTimeout(600);
  check('La ficha muestra la hora y permite cambiarla', await page.locator('input[name="due_time"]').first().inputValue() === '16:30');
  const tf = page.locator('input[name="due_time"]').first();
  check('La hora se escribe en 24 horas (hh:mm), sin AM/PM (Chromium en es-MX mostraba 04:30 p. m.)', await tf.getAttribute('type') === 'text' && await tf.getAttribute('placeholder') === 'hh:mm');
  await tf.fill('915');
  await tf.press('Tab');
  await page.waitForTimeout(900);
  const t2 = await apiCall(`/api/tasks/${data.task?.id}`);
  check('Escribir «915» guarda 09:15', (await tf.inputValue()) === '09:15' && String((t2.data?.task || t2.data)?.due_time || '').startsWith('09:15'), JSON.stringify((t2.data?.task || t2.data)?.due_time));
  await tf.fill('25:00');
  await tf.press('Tab');
  await page.waitForTimeout(600);
  const t3 = await apiCall(`/api/tasks/${data.task?.id}`);
  check('Una hora inexistente (25:00) se rechaza sin guardar', await tf.evaluate((el) => !el.checkValidity()) && String((t3.data?.task || t3.data)?.due_time || '').startsWith('09:15'));
  await tf.fill('16:30');
  await tf.press('Tab');
  await page.waitForTimeout(900);
  await page.locator('.task-sheet, main').first().screenshot({ path: `${SHOTS}/34-tarea-con-hora.png` }).catch(() => page.screenshot({ path: `${SHOTS}/34-tarea-con-hora.png` }));
});

await attempt('Google Calendar: conectar, elegir calendario y enviar', async () => {
  const { spawn, execFileSync } = await import('node:child_process');
  const { existsSync, unlinkSync, readFileSync } = await import('node:fs');
  const port = Number(process.env.BOREALIS_FAKE_GOOGLE_E2E_PORT || 8096);
  const fg = `http://127.0.0.1:${port}`;
  const state = `/tmp/borealis-e2e-google-${process.pid}.json`;
  if (existsSync(state)) unlinkSync(state);
  const proc = spawn(process.execPath.includes('node') ? 'php' : 'php', ['-S', `127.0.0.1:${port}`, 'tests/fake-google/server.php'], { env: { ...process.env, FAKE_GOOGLE_STATE: state }, stdio: 'ignore' });
  const cfg = 'config/config.php';
  const orig = readFileSync(cfg, 'utf8');
  const setCfg = (php) => execFileSync('php', ['-r', `$c = require '${cfg}'; ${php} file_put_contents('${cfg}', "<?php\nreturn " . var_export($c, true) . ";\n");`]);
  try {
    await page.waitForTimeout(500);
    setCfg(`$c['google'] = ['client_id' => 'e2e.apps', 'client_secret' => 'e2e-secret', 'auth_url' => '${fg}/o/oauth2/v2/auth', 'token_url' => '${fg}/token', 'revoke_url' => '${fg}/revoke', 'userinfo_url' => '${fg}/userinfo', 'api_base' => '${fg}'];`);
    await page.waitForTimeout(3200);
    await page.goto(BASE + '/profile#google');
    await page.locator('#google').screenshot({ path: `${SHOTS}/35-google-sin-conectar.png` });
    check('Perfil › Google Calendar: pasos claros y botón para conectar', await page.locator('#google .gx-steps').isVisible() && await page.locator('#google a', { hasText: 'Conectar con Google' }).isVisible());
    await Promise.all([page.waitForURL((u) => u.pathname.endsWith('/profile'), { timeout: 10000 }), page.locator('#google a', { hasText: 'Conectar con Google' }).click()]);
    await page.waitForSelector('[data-google-export]');
    check('Tras autorizar en Google vuelve al perfil, conectado', (await page.locator('[data-google-state]').textContent()).trim() === 'Conectado');
    await page.waitForFunction(() => [...document.querySelectorAll('#gx-cal option')].some((o) => o.value === 'primary@test'), null, { timeout: 8000 });
    check('El selector ofrece solo calendarios donde puede escribir', !(await page.locator('#gx-cal option[value="festivos@test"]').count()));
    await page.locator('#google').screenshot({ path: `${SHOTS}/35-google-elegir.png` });
    const r = page.waitForResponse((x) => x.url().endsWith('/api/google/export') && x.request().method() === 'POST');
    await page.click('[data-google-export] button[type=submit]');
    const resp = await r;
    check('«Empezar a enviar» confirma que funciona', resp.status() === 200 && /Google Calendar/.test((await resp.json()).message || ''));
    await page.waitForSelector('[data-gx-status]', { timeout: 8000 });
    check('El estado muestra «Funcionando» y cuántos eventos hay', /Funcionando/.test(await page.locator('[data-gx-status]').textContent()));
    await page.locator('#google').screenshot({ path: `${SHOTS}/35-google-funcionando.png` });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(BASE + '/profile#google');
    await page.waitForTimeout(500);
    check('Móvil: el apartado de Google Calendar no desborda', await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth) <= 1);
    await page.locator('#google').screenshot({ path: `${SHOTS}/35-google-movil.png` });
    await page.setViewportSize({ width: 1440, height: 900 });
    // disconnect while the fake Google still answers, so later pages do not try to reach it
    const dc = await apiCall('/api/google/disconnect', 'POST', { remove_events: true });
    check('«Desconectar y quitar sus eventos» desde el perfil', dc.status === 200, JSON.stringify(dc.data).slice(0, 200));
  } finally {
    writeFileSync(cfg, orig);
    proc.kill();
    await page.waitForTimeout(3200);
  }
});

await attempt('Correo, silencio y zona horaria en el perfil', async () => {
  await page.goto(BASE + '/profile#notificaciones');
  check('Horario de silencio y zona horaria visibles', await page.locator('#nf-tz').isVisible() && await page.locator('input[name="quiet_on"]').isVisible());
  check('Sin correo configurado, se dice claramente', /todavía no puede enviar correos/.test(await page.locator('[data-mail-status]').textContent()));
  await page.locator('[data-mail-box]').screenshot({ path: `${SHOTS}/36-correo.png` });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.reload();
  check('Móvil: las preferencias de notificación no desbordan', await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth) <= 1);
  await page.locator('#notificaciones').screenshot({ path: `${SHOTS}/36-notificaciones-movil.png` });
  await page.setViewportSize({ width: 1440, height: 900 });
});

await attempt('Borealis como aplicación (PWA)', async () => {
  await page.goto(BASE + '/profile#aplicacion');
  check('Perfil › «Borealis como aplicación» explica cómo instalarlo', await page.locator('[data-pwa-box]').isVisible() && (await page.locator('[data-pwa-box] p:not([hidden])').count()) >= 1);
  await page.locator('[data-pwa-box]').screenshot({ path: `${SHOTS}/37-instalar.png` });
  const off = await page.evaluate(async () => (await fetch('/offline.html')).status);
  check('La página «Sin conexión» se sirve', off === 200);
});

section('Novedades 1.10');
await attempt('Enlaces de Drive y GitHub en la tarea', async () => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await ctx.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: BASE });
  await page.goto(BASE + projectUrl + '/board');
  const pid = Number((projectUrl.match(/\/projects\/(\d+)/) || [])[1]);
  const made = await apiCall(`/api/projects/${pid}/tasks`, 'POST', { title: 'Maquetar la página de precios', description: 'Diseño en la carpeta compartida; código en el repositorio del sitio.' });
  const key = made.data?.task?.key;
  const id = made.data?.task?.id;
  await page.goto(BASE + '/t/' + key);
  const sec = page.locator('.task-section', { hasText: 'Enlaces de trabajo' });
  check('La ficha de la tarea tiene la sección «Enlaces de trabajo»', await sec.isVisible());
  const drive = sec.locator('input[name="drive_url"]');
  await drive.fill('drive.google.com/drive/folders/1Precios2026?usp=sharing');
  await drive.press('Tab');
  await page.waitForTimeout(900);
  await sec.locator('input[name="github_url"]').fill('git@github.com:Gep/sitio-web.git');
  await sec.locator('input[name="github_url"]').press('Tab');
  await page.waitForTimeout(900);
  const t = (await apiCall(`/api/tasks/${id}`)).data?.task || {};
  check('Pegar los enlaces los guarda al salir del campo (y los deja en https)', t.drive_url === 'https://drive.google.com/drive/folders/1Precios2026?usp=sharing' && t.github_url === 'https://github.com/Gep/sitio-web', JSON.stringify(t).slice(0, 200));
  check('Tras guardar, el campo muestra el enlace completo', await sec.locator('input[name="github_url"]').inputValue() === 'https://github.com/Gep/sitio-web');
  await sec.locator('[data-work-link="github"] button[data-copy]').click();
  await page.waitForTimeout(300);
  check('«Copiar» pone el enlace del repositorio en el portapapeles', await page.evaluate(() => navigator.clipboard.readText()) === 'https://github.com/Gep/sitio-web');
  check('…y lo confirma con un aviso', await page.locator('.toast', { hasText: 'repositorio de GitHub copiado' }).count() >= 1);
  await sec.locator('[data-work-link="drive"] button[data-copy]').click();
  await page.waitForTimeout(300);
  check('«Copiar» de Drive copia el enlace de la carpeta', await page.evaluate(() => navigator.clipboard.readText()) === 'https://drive.google.com/drive/folders/1Precios2026?usp=sharing');
  check('Cada enlace se puede abrir en otra pestaña', await sec.locator('a[href="https://github.com/Gep/sitio-web"][target="_blank"]').count() === 1 && await sec.locator('a[href^="https://drive.google.com/"][target="_blank"]').count() === 1);
  await page.waitForTimeout(2500);
  await sec.screenshot({ path: `${SHOTS}/38-enlaces-tarea.png` });
  await page.screenshot({ path: `${SHOTS}/38-tarea-con-enlaces.png` });
  await sec.locator('input[name="github_url"]').fill('https://gitlab.com/Gep/sitio-web');
  await sec.locator('input[name="github_url"]').press('Tab');
  await page.waitForTimeout(900);
  const t2 = (await apiCall(`/api/tasks/${id}`)).data?.task || {};
  check('Un enlace que no es de GitHub se rechaza con el motivo y vuelve el anterior', t2.github_url === 'https://github.com/Gep/sitio-web' && await sec.locator('input[name="github_url"]').inputValue() === 'https://github.com/Gep/sitio-web'
    && await page.locator('.toast.error', { hasText: 'GitHub' }).count() >= 1);
  await page.goto(BASE + projectUrl + '/board');
  await page.locator(`[data-open-task="${key}"]`).first().click();
  const dsec = page.locator('#drawer.open .task-section', { hasText: 'Enlaces de trabajo' });
  await dsec.waitFor({ timeout: 5000 });
  await dsec.locator('[data-work-link="github"] button[data-copy]').click();
  await page.waitForTimeout(300);
  check('También desde el panel lateral del tablero se copian', await page.evaluate(() => navigator.clipboard.readText()) === 'https://github.com/Gep/sitio-web');
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(BASE + '/t/' + key);
  const msec = page.locator('.task-section', { hasText: 'Enlaces de trabajo' });
  await page.evaluate(() => document.querySelector('#td-worklinks')?.closest('.task-section')?.scrollIntoView({ block: 'center' }));
  const ov = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  check('En el móvil la sección cabe sin desplazamiento horizontal', ov <= 1, `+${ov}px`);
  await page.waitForTimeout(500);
  await page.screenshot({ path: `${SHOTS}/38-enlaces-movil.png` });
  await page.setViewportSize({ width: 1440, height: 900 });
});

section('Novedades 1.11');
await attempt('Tareas atrasadas, fuera de plazo, «Hoy» y proyecto planificado', async () => {
  await page.setViewportSize({ width: 1440, height: 900 });
  const pid = Number((projectUrl.match(/\/projects\/(\d+)/) || [])[1]);
  await page.goto(BASE + projectUrl + '/board');
  const me = await page.evaluate(async (pid) => {
    const html = await (await fetch(`/projects/${pid}/settings`)).text();
    const doc = new DOMParser().parseFromString(html, 'text/html');
    return Number(doc.querySelector('#ps-lead option[selected]')?.value || 0);
  }, pid);
  const ymd = (n) => { const d = new Date(); d.setDate(d.getDate() + n); return d.toLocaleDateString('sv-SE', { timeZone: 'America/Mexico_City' }); };
  const late = (await apiCall(`/api/projects/${pid}/tasks`, 'POST', { title: 'Enviar la cotización al cliente', assignee_id: me, due_date: ymd(-4) })).data?.task;
  await apiCall(`/api/projects/${pid}/tasks`, 'POST', { title: 'Revisar los textos de la portada', assignee_id: me, due_date: ymd(0) });
  const fin = (await apiCall(`/api/projects/${pid}/tasks`, 'POST', { title: 'Preparar la reunión de avance', assignee_id: me, due_date: ymd(-2) })).data?.task;
  await apiCall(`/api/tasks/${fin.id}/done`, 'POST', { done: true });
  const api = (await apiCall(`/api/tasks/${late.id}`)).data?.task || {};
  check('La API informa los días de retraso sin cambiar el estado', api.punctuality?.kind === 'late' && api.punctuality?.days === 4 && api.category !== 'done', JSON.stringify(api.punctuality));
  await page.goto(BASE + projectUrl + '/board');
  const card = page.locator(`.task-card[data-task-id="${late.id}"]`);
  check('La tarjeta del tablero muestra «4 días de retraso»', (await card.locator('.late-tag').innerText()).includes('4 días de retraso'));
  await card.scrollIntoViewIfNeeded();
  await card.screenshot({ path: `${SHOTS}/39-tarjeta-atrasada.png` });
  await page.goto(BASE + '/my-work?tab=today');
  check('Mi trabajo › «Hoy» es la primera pestaña y muestra lo que vence hoy', (await page.locator('nav.segmented a').first().innerText()).startsWith('Hoy') && await page.getByText('Revisar los textos de la portada').isVisible()
    && await page.getByText('Enviar la cotización al cliente').count() === 0);
  await page.screenshot({ path: `${SHOTS}/39-mi-trabajo-hoy.png` });
  await page.goto(BASE + '/my-work?tab=overdue');
  check('«Vencidas» muestra el retraso junto a la fecha', await page.locator('tr', { hasText: 'Enviar la cotización al cliente' }).locator('.late-tag').isVisible());
  await page.screenshot({ path: `${SHOTS}/39-mi-trabajo-vencidas.png` });
  await page.goto(BASE + '/my-work?tab=done');
  check('«Finalizadas» señala la terminada fuera de plazo', (await page.locator('tr', { hasText: 'Preparar la reunión de avance' }).locator('.late-tag').innerText()).includes('Fuera de plazo · 2 días'));
  await page.screenshot({ path: `${SHOTS}/39-finalizadas-fuera-de-plazo.png` });
  await page.goto(BASE + '/t/' + late.key);
  check('La ficha muestra el retraso junto a «Vence»', await page.locator('.ts-late .late-tag').isVisible());
  await page.screenshot({ path: `${SHOTS}/39-ficha-atrasada.png` });
  await page.goto(BASE + '/projects');
  await page.getByRole('button', { name: /Nuevo proyecto/ }).first().click();
  const dlg = page.locator('dialog[open]');
  await dlg.locator('#np-name').fill('Rediseño 2027');
  await dlg.locator('details.more-options summary').click();
  await dlg.locator('#np-state').selectOption('planned');
  await dlg.screenshot({ path: `${SHOTS}/39-nuevo-proyecto-planificado.png` });
  await dlg.locator('button[type=submit]').click();
  await page.waitForTimeout(800);
  await page.goto(BASE + '/projects?state=planned');
  check('Un proyecto creado como «Planificado» aparece con ese estado', await page.locator('.badge', { hasText: 'Planificado' }).count() >= 1 && await page.getByText('Rediseño 2027').count() >= 1);
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(BASE + '/my-work?tab=overdue');
  const ov = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  check('En el móvil, Mi trabajo con el retraso cabe sin desplazamiento horizontal', ov <= 1, `+${ov}px`);
  await page.screenshot({ path: `${SHOTS}/39-movil-vencidas.png` });
  await page.setViewportSize({ width: 1440, height: 900 });
});

section('Novedades 1.12');
await attempt('Monitoreo: resumen, cumplimiento, carga, tiempos, alertas e informe', async () => {
  await page.setViewportSize({ width: 1440, height: 900 });
  const pid = Number((projectUrl.match(/\/projects\/(\d+)/) || [])[1]);
  await page.goto(BASE + projectUrl + '/board');
  const me = await page.evaluate(async (pid) => {
    const html = await (await fetch(`/projects/${pid}/settings`)).text();
    const doc = new DOMParser().parseFromString(html, 'text/html');
    return Number(doc.querySelector('#ps-lead option[selected]')?.value || 0);
  }, pid);
  const ymd = (n) => { const d = new Date(); d.setDate(d.getDate() + n); return d.toLocaleDateString('sv-SE', { timeZone: 'America/Mexico_City' }); };
  await apiCall(`/api/members/${me}`, 'PATCH', { weekly_hours: 15 });
  await apiCall(`/api/projects/${pid}/tasks`, 'POST', { title: 'Migrar la base de clientes', assignee_id: me, estimate_hours: 22, due_date: ymd(0) });
  const dev = (await apiCall(`/api/projects/${pid}/tasks`, 'POST', { title: 'Ajustar el formulario de contacto', assignee_id: me, estimate_hours: 5, due_date: ymd(3) })).data?.task;
  await apiCall(`/api/tasks/${dev.id}/time`, 'POST', { hours: '12', work_date: ymd(0) });
  const done = await apiCall(`/api/tasks/${dev.id}/done`, 'POST', { done: true });
  check('Al finalizar con 12 h de 5 h estimadas se informa +7 h (+140 %)', done.data?.deviation?.pct === 140, JSON.stringify(done.data?.deviation));
  await apiCall('/api/monitoring/scan', 'POST', {});
  await page.goto(BASE + '/monitoring');
  check('El menú lleva a «Monitoreo»', await page.locator('.nav-item[aria-current="page"]', { hasText: 'Monitoreo' }).isVisible());
  check('El resumen muestra los indicadores enlazados y el estado de cada proyecto', await page.locator('.mon-kpis a.stat').count() === 8 && await page.locator('.mon-health li').count() >= 1);
  await page.screenshot({ path: `${SHOTS}/40-monitoreo-resumen.png`, fullPage: true });
  await page.locator('.mon-kpis a[data-kpi="late"]').click();
  await page.waitForURL(/tab=compliance/);
  check('Pulsar «Tareas atrasadas» abre la lista de atrasadas', await page.locator('.segmented a[aria-current="page"]', { hasText: 'Atrasadas' }).isVisible());
  await page.screenshot({ path: `${SHOTS}/40-monitoreo-cumplimiento.png`, fullPage: true });
  await page.goto(BASE + `/monitoring?tab=workload&user=${me}`);
  check('La carga muestra la sobrecarga con las horas por encima', await page.locator('.mon-cell .badge', { hasText: 'Sobrecarga' }).first().isVisible() && await page.getByText(/h por encima/).first().isVisible());
  await page.screenshot({ path: `${SHOTS}/40-monitoreo-carga.png`, fullPage: true });
  await page.goto(BASE + '/monitoring?tab=times');
  check('Tiempos compara estimado y registrado (+7 h · +140 %)', await page.locator('tr', { hasText: 'Ajustar el formulario de contacto' }).getByText('+7 h · +140 %').isVisible());
  await page.screenshot({ path: `${SHOTS}/40-monitoreo-tiempos.png`, fullPage: true });
  await page.goto(BASE + '/monitoring?tab=risks');
  check('Riesgos y alertas explica cada alerta y permite marcarla como revisada', await page.locator('.mon-alerts li').count() >= 1);
  await page.screenshot({ path: `${SHOTS}/40-monitoreo-alertas.png`, fullPage: true });
  const ack = page.locator('.mon-alerts li button', { hasText: 'Marcar revisada' }).first();
  if (await ack.count()) {
    await ack.click();
    await page.waitForTimeout(900);
    check('Marcar una alerta como revisada la deja con quién la revisó', await page.locator('.mon-alerts .badge', { hasText: 'Revisada por' }).count() >= 1);
  }
  await page.goto(BASE + '/monitoring?tab=report');
  const [dl] = await Promise.all([page.waitForEvent('download'), page.getByRole('link', { name: /Descargar CSV/ }).click()]);
  check('El informe se descarga en CSV', /\.csv$/.test(dl.suggestedFilename()), dl.suggestedFilename());
  await page.screenshot({ path: `${SHOTS}/40-monitoreo-informe.png`, fullPage: true });
  await page.goto(BASE + '/t/' + dev.key);
  check('La ficha de la tarea tiene «Seguimiento» con la desviación de horas', await page.locator('[data-deviation] .dev-over').isVisible());
  const why = page.locator('#td-tracking details', { has: page.locator('select[name=reason]') }); // 1.14: the estimates box has its own form
  check('Con una desviación importante el formulario del motivo aparece abierto (opcional)', await why.evaluate((d) => d.open));
  await page.locator('#td-tracking select[name=reason]').selectOption('estimate');
  await page.locator('#td-tracking textarea[name=comment]').fill('El formulario necesitaba validaciones nuevas');
  await page.locator('#td-tracking input[name=corrective_action]').fill('Estimar con el equipo las tareas de formularios');
  await page.locator('#td-tracking form', { has: page.locator('select[name=reason]') }).locator('button[type=submit]').click(); // 1.14: the estimates box has its own form
  await page.locator('[data-explanations]').waitFor({ timeout: 5000 });
  check('El motivo queda registrado con autor y fecha', (await page.locator('[data-explanations]').innerText()).includes('Estimación insuficiente'));
  await page.locator('#td-tracking-sec').screenshot({ path: `${SHOTS}/40-ficha-seguimiento.png` });
  await page.goto(BASE + '/admin');
  await page.locator('#monitoreo').scrollIntoViewIfNeeded();
  await page.locator('#monitoreo').screenshot({ path: `${SHOTS}/40-umbrales.png` });
  await page.setViewportSize({ width: 390, height: 844 });
  for (const tab of ['summary', 'compliance', 'workload', 'times', 'risks', 'report']) {
    await page.goto(BASE + '/monitoring?tab=' + tab);
    const ov = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    check(`En el móvil, Monitoreo › ${tab} cabe sin desplazamiento horizontal`, ov <= 1, `+${ov}px`);
    if (tab === 'summary' || tab === 'workload') await page.screenshot({ path: `${SHOTS}/40-movil-${tab}.png`, fullPage: true });
  }
  await page.setViewportSize({ width: 1440, height: 900 });
  await apiCall(`/api/members/${me}`, 'PATCH', { weekly_hours: '' });
});

section('Novedades 1.13');
await attempt('Análisis: resumen, tendencias, estimaciones, planificación, escenarios e informe', async () => {
  await page.setViewportSize({ width: 1440, height: 900 });
  const ymd = (n) => { const d = new Date(); d.setDate(d.getDate() + n); return d.toLocaleDateString('sv-SE', { timeZone: 'America/Mexico_City' }); };
  const nextMon = (() => { const d = new Date(new Date().toLocaleString('en-US', { timeZone: 'America/Mexico_City' })); d.setDate(d.getDate() + ((8 - d.getDay()) % 7 || 7)); return d; })();
  const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  const nm = iso(nextMon); const nf = iso(new Date(nextMon.getTime() + 4 * 86400000));
  const p = await apiCall('/api/projects', 'POST', { name: 'Portal de Clientes', project_key: 'PCL' });
  const pid = p.data.id;
  await apiCall(`/api/projects/${pid}/modules`, 'POST', { module: 'sprints', enabled: true });
  await page.goto(BASE + `/projects/${pid}/board`);
  const me = await page.$$eval('#nt-assignee option', (os) => os.find((o) => /\(yo\)/.test(o.textContent))?.value || '');
  await apiCall(`/api/members/${me}`, 'PATCH', { weekly_hours: 40 });
  const lab = (await apiCall(`/api/projects/${pid}/labels`, 'POST', { name: 'Informe', color: '#0EA5E9' })).data.id;
  const mk = async (body) => (await apiCall(`/api/projects/${pid}/tasks`, 'POST', body)).data.task;
  for (const [i, [est, real, due]] of [[4, 6, 3], [5, 7, 3], [3, 5, -2], [6, 8, 3], [4, 6, -1], [2, 2, 3]].entries()) {
    const t = await mk({ title: `Informe mensual ${i + 1}`, assignee_id: me, estimate_hours: est, due_date: ymd(due), label_ids: [lab] });
    await apiCall(`/api/tasks/${t.id}/time`, 'POST', { hours: String(real), work_date: ymd(0) });
    await apiCall(`/api/tasks/${t.id}/done`, 'POST', { done: true });
  }
  await mk({ title: 'Rediseño del portal', assignee_id: me, estimate_hours: 30, start_date: nm, due_date: nf });
  await mk({ title: 'Migración de cuentas', assignee_id: me, estimate_hours: 28, start_date: nm, due_date: nf });
  const sp = (await apiCall(`/api/projects/${pid}/sprints`, 'POST', { name: 'Sprint Portal 1', start_date: ymd(0), end_date: ymd(13) })).data.id;
  await apiCall(`/api/projects/${pid}/sprints`, 'POST', { name: 'Sprint Portal 2', start_date: ymd(14), end_date: ymd(27) });
  const s1 = await mk({ title: 'Formulario de alta', assignee_id: me, estimate_hours: 10, sprint_id: sp });
  const s2 = await mk({ title: 'Pasarela de pago', assignee_id: me, estimate_hours: 12, sprint_id: sp });
  await apiCall(`/api/sprints/${sp}/start`, 'POST', {});
  await apiCall(`/api/sprints/${sp}/capacity`, 'PATCH', { user_id: Number(me), hours: 12 });
  await apiCall(`/api/tasks/${s2.id}`, 'PATCH', { is_blocked: 1, blocked_reason: 'Esperando las credenciales del banco' });

  await page.goto(BASE + '/analysis');
  check('El menú lleva a «Análisis»', await page.locator('.nav-item[aria-current="page"]', { hasText: 'Análisis' }).isVisible());
  check('Arriba: estado, cumplimiento, capacidad proyectada y riesgos', await page.locator('.an-kpis a.stat').count() === 4);
  check('El sprint aparece con riesgo elevado y su porqué', await page.locator('.an-risk[data-level="high"]', { hasText: 'Sprint Portal 1' }).isVisible());
  await page.locator('.an-risk', { hasText: 'Sprint Portal 1' }).locator('summary', { hasText: 'Por qué' }).click();
  check('«Por qué» muestra cada factor como hecho, con sus puntos', (await page.locator('.an-risk', { hasText: 'Sprint Portal 1' }).locator('.an-factors').innerText()).includes('(+3)'));
  check('Recomendaciones con problema, evidencia, acción, impacto y confianza', await page.locator('.an-rec').count() >= 1 && (await page.locator('.an-rec').first().innerText()).includes('Confianza'));
  await page.screenshot({ path: `${SHOTS}/41-analisis-resumen.png`, fullPage: true });

  await page.goto(BASE + `/analysis?tab=trends&project_id=${pid}`);
  await page.locator('tr[data-ind="ontime"] summary').click();
  check('Tendencias: cumplimiento con numerador y denominador (4 de 6)', (await page.locator('tr[data-ind="ontime"]').innerText()).includes('4 de 6'));
  check('Con pocos meses de historial no inventa tendencias', await page.locator('[data-trend="nodata"]').count() >= 1);
  await page.screenshot({ path: `${SHOTS}/41-analisis-tendencias.png`, fullPage: true });

  await page.goto(BASE + `/analysis?tab=estimates&project_id=${pid}`);
  await page.selectOption('#an-try-l', String(lab));
  await page.fill('#an-try-h', '4');
  await page.click('[data-estimate-try] [data-estimate-suggest]');
  await page.locator('[data-suggest="ok"]').waitFor({ timeout: 6000 });
  check('Estimaciones: sugerencia a partir de tareas comparables, con su origen', (await page.locator('[data-suggest="ok"]').innerText()).includes('desviación habitual'));
  await page.locator('[data-suggest="ok"] summary').click();
  await page.screenshot({ path: `${SHOTS}/41-analisis-estimaciones.png`, fullPage: true });

  const nt = await mk({ title: 'Informe trimestral', assignee_id: me, estimate_hours: 4, label_ids: [lab] });
  await page.goto(BASE + '/t/' + nt.key);
  await page.click('[data-estimate-suggest][data-task]');
  await page.locator('[data-suggest="ok"]').waitFor({ timeout: 6000 });
  await page.locator('.ts-suggest').screenshot({ path: `${SHOTS}/41-ficha-sugerencia.png` });
  const use = page.locator('[data-suggest-use]');
  const val = await use.getAttribute('data-suggest-use');
  await use.click();
  await page.waitForTimeout(800);
  const after = await apiCall(`/api/tasks/${nt.id}`);
  check('«Usar» rellena la estimación y se guarda solo porque la persona lo pidió', Number(after.data?.task?.estimate_hours) === Number(val), `${val} / ${after.data?.task?.estimate_hours}`);

  await page.goto(BASE + `/analysis?tab=planning&project_id=${pid}`);
  check('Planificación: la semana próxima avisa de la sobrecarga con alternativas', await page.locator('[data-overload]').count() >= 1);
  await page.screenshot({ path: `${SHOTS}/41-analisis-planificacion.png`, fullPage: true });

  await page.goto(BASE + `/analysis?tab=scenarios&sprint_id=${sp}`);
  await page.locator(`tr[data-task="${s2.id}"] [data-sim="move"]`).selectOption({ label: 'Sprint Portal 2' });
  await page.locator(`[data-user="${me}"] [data-sim="capacity"]`).fill('30');
  await page.click('[data-sim-run]');
  await page.locator('[data-sim-level="low"]').waitFor({ timeout: 6000 });
  check('Escenarios: trasladar la tarea de 12 h baja el riesgo en el escenario simulado', await page.locator('[data-compare]').isVisible());
  await page.screenshot({ path: `${SHOTS}/41-analisis-escenarios.png`, fullPage: true });
  const stillIn = await apiCall(`/api/tasks/${s2.id}`);
  check('…sin tocar la tarea real', stillIn.data?.task?.sprint_id === sp || Number(stillIn.data?.task?.sprint_id) === Number(sp));
  check('Aplicar exige marcar la confirmación', await page.locator('[data-sim-apply-btn]').isDisabled());
  await page.check('[data-sim-confirm]');
  await page.click('[data-sim-apply-btn]');
  await waitToast(/Escenario aplicado/);
  await page.waitForLoadState('networkidle');
  const moved = await apiCall(`/api/tasks/${s2.id}`);
  check('Al aplicarlo, la tarea pasa al sprint siguiente', Number(moved.data?.task?.sprint_id) !== Number(sp));
  await page.locator('[data-sim-history]').waitFor();
  await page.locator('[data-sim-history]').screenshot({ path: `${SHOTS}/41-escenario-aplicado.png` });
  await page.locator('[data-sim-history] [data-call*="/revert"]').first().click();
  await page.locator('#dlg-confirm[open]').waitFor();
  await page.click('#dlg-confirm [data-confirm-ok]');
  await waitToast(/Escenario revertido/);
  const back = await apiCall(`/api/tasks/${s2.id}`);
  check('Revertir devuelve la tarea al sprint original', Number(back.data?.task?.sprint_id) === Number(sp));

  await page.goto(BASE + `/analysis?tab=report&project_id=${pid}`);
  check('Informes: nueve secciones interpretadas', await page.locator('.an-report h2').count() === 9);
  await page.screenshot({ path: `${SHOTS}/41-analisis-informe.png`, fullPage: true });
  await page.goto(BASE + `/analysis/report/print?project_id=${pid}`);
  await page.screenshot({ path: `${SHOTS}/41-informe-imprimible.png`, fullPage: true });
  await page.goto(BASE + '/admin');
  await page.locator('#analisis').scrollIntoViewIfNeeded();
  await page.locator('#analisis').screenshot({ path: `${SHOTS}/41-parametros.png` });
  await page.setViewportSize({ width: 390, height: 844 });
  for (const tab of ['summary', 'trends', 'estimates', 'planning', 'scenarios', 'report']) {
    await page.goto(BASE + `/analysis?tab=${tab}&project_id=${pid}` + (tab === 'scenarios' ? `&sprint_id=${sp}` : ''));
    const ov = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    check(`En el móvil, Análisis › ${tab} cabe sin desplazamiento horizontal`, ov <= 1, `+${ov}px`);
    if (['summary', 'scenarios', 'planning'].includes(tab)) await page.screenshot({ path: `${SHOTS}/41-movil-${tab}.png`, fullPage: true });
  }
  await page.setViewportSize({ width: 1440, height: 900 });
  await apiCall(`/api/members/${me}`, 'PATCH', { weekly_hours: '' });
});

section('Diseño adaptable');
// ------------------------------------------------------------------ 1.14
section('Novedades 1.14');
await attempt('1.14 · «Crear y añadir otra», trabajos rápidos y carga al asignar', async () => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(BASE + '/');
  await page.click('[data-tour="new-task"]');
  await page.selectOption('#nt-project', 'quick');
  await page.fill('#nt-title', 'Primera de varias');
  const me = await page.$$eval('#nt-assignee option:not([hidden])', (os) => os.find((o) => /\(yo\)/.test(o.textContent))?.value || '');
  if (me) await page.selectOption('#nt-assignee', me);
  await page.waitForTimeout(600);
  const hint = ((await page.locator('#dlg-new-task [data-workload-hint]').textContent()) || '').trim();
  check('Al elegir responsable se ve su carga de la semana', /esta semana/.test(hint), hint);
  const r1 = page.waitForResponse((r) => /\/api\/projects\/quick\/tasks$/.test(r.url()) && r.request().method() === 'POST');
  await page.click('#dlg-new-task [data-stay]');
  const d1 = await (await r1).json();
  await page.waitForTimeout(400);
  check('«Crear y añadir otra» guarda y deja el diálogo abierto, vacío y con el mismo proyecto y responsable', d1.ok !== false && await page.locator('#dlg-new-task[open]').count() === 1
    && (await page.inputValue('#nt-title')) === '' && (await page.inputValue('#nt-project')) === 'quick' && (!me || (await page.inputValue('#nt-assignee')) === me), JSON.stringify(d1).slice(0, 200));
  await page.fill('#nt-title', 'Segunda de varias');
  const r2 = page.waitForResponse((r) => /\/api\/projects\/quick\/tasks$/.test(r.url()) && r.request().method() === 'POST');
  await page.click('#dlg-new-task button[type=submit].btn--primary');
  await r2;
  await page.waitForTimeout(500);
  check('«Crear tarea» guarda y cierra', await page.locator('#dlg-new-task[open]').count() === 0);
  await page.goto(BASE + '/quick');
  check('Las dos aparecen en «Trabajos rápidos»', await page.locator('[data-quick-row]', { hasText: 'Primera de varias' }).count() === 1 && await page.locator('[data-quick-row]', { hasText: 'Segunda de varias' }).count() === 1);
  await page.fill('#qw-title', 'Llamar al proveedor de papel');
  await page.fill('#qw-h', '1');
  await page.selectOption('#qw-type', 'urgent');
  const r3 = page.waitForResponse((r) => /\/api\/quick$/.test(r.url()) && r.request().method() === 'POST');
  await page.click('[data-quick-form] button[type=submit]');
  await r3;
  await page.waitForTimeout(700);
  check('El formulario de trabajos rápidos queda listo para el siguiente (título vacío, tipo conservado)', (await page.inputValue('#qw-title')) === '' && (await page.inputValue('#qw-type')) === 'urgent');
  await page.reload();
  check('…y el trabajo urgente aparece marcado como imprevisto', await page.locator('[data-quick-row]', { hasText: 'Llamar al proveedor de papel' }).locator('.badge.warn').count() === 1);
  await page.screenshot({ path: `${SHOTS}/50-trabajos-rapidos.png`, fullPage: true });
  await page.goto(BASE + '/projects');
  check('El espacio de trabajos rápidos no aparece entre los proyectos', await page.locator('main', { hasText: 'Trabajos rápidos' }).locator('a[href*="/projects/"]', { hasText: /^Trabajos rápidos$/ }).count() === 0);
});

await attempt('1.14 · Nuevo proyecto con responsable, fechas e integrantes', async () => {
  await page.goto(BASE + '/projects');
  await page.locator('main [data-dialog="dlg-new-project"]').first().click();
  await page.fill('#np-name', 'Campaña de invierno');
  await page.locator('#dlg-new-project [data-fill-dates]', { hasText: 'Esta semana' }).click();
  const due = await page.inputValue('#np-due');
  check('«Esta semana» rellena la entrega', /^\d{4}-\d{2}-\d{2}$/.test(due) || /^\d{2}\/\d{2}\/\d{4}$/.test(due), due);
  const chips = page.locator('#dlg-new-project input[name="member_ids[]"]');
  const nChips = await chips.count();
  if (nChips) await chips.first().check();
  await page.screenshot({ path: `${SHOTS}/51-nuevo-proyecto.png` });
  const r = page.waitForResponse((x) => /\/api\/projects$/.test(x.url()) && x.request().method() === 'POST');
  await page.click('#dlg-new-project button[type=submit]');
  const d = await (await r).json();
  check('El proyecto se crea en un paso, con integrantes', d.ok !== false && !!d.id && nChips > 0, JSON.stringify(d).slice(0, 200));
});

await attempt('1.14 · Estimación del trabajador en la ficha', async () => {
  if (!estKey) { check('Hay una tarea con estimación para probar', false); return; }
  await page.goto(BASE + '/t/' + estKey);
  const box = page.locator('[data-estimates]');
  check('La ficha muestra administración, trabajador, acordado y real', await box.isVisible() && await box.locator('[data-est-agreed]').isVisible() && await box.locator('[data-est-real]').isVisible());
  const form = box.locator('[data-estimate-form]');
  if (!(await form.isVisible())) await box.locator('details summary').first().click();
  await form.locator('input[name=hours]').fill('9');
  await form.locator('button[type=submit]').click();
  await page.waitForTimeout(700);
  check('Cambiar lo acordado sin motivo se explica en el campo', ((await form.locator('[data-error-for="reason"]').textContent()) || '').trim().length > 0);
  await form.locator('input[name=hours]').fill('9');
  await form.locator('input[name=reason]').fill('Hay que adaptar también la versión móvil');
  const r = page.waitForResponse((x) => /\/estimate$/.test(x.url()) && x.request().method() === 'POST');
  await form.locator('button[type=submit]').click();
  await r;
  await page.waitForTimeout(800);
  await page.goto(BASE + '/t/' + estKey);
  check('Con un motivo queda «por revisar»', (await page.locator('[data-estimates]').getAttribute('data-estimates')) === 'pending');
  const agree = page.locator('[data-estimate-agree]');
  check('Quien gestiona ve «Acordar» con la propuesta', await agree.isVisible() && (await agree.locator('input[name=hours]').inputValue()).startsWith('9'));
  await page.screenshot({ path: `${SHOTS}/52-estimaciones.png` });
  const r2 = page.waitForResponse((x) => /\/estimate\/agree$/.test(x.url()));
  await agree.locator('button[type=submit]').click();
  await r2;
  await page.waitForTimeout(800);
  await page.goto(BASE + '/t/' + estKey);
  check('Al acordar se cierra la revisión y el acordado pasa a 9 h', (await page.locator('[data-estimates]').getAttribute('data-estimates')) === 'agreed' && /9/.test(await page.locator('[data-est-agreed]').textContent()));
});

await attempt('1.14 · Carta Gantt', async () => {
  const pidG = (projectUrl.match(/\/projects\/(\d+)/) || [])[1];
  await page.goto(BASE + projectUrl);
  if (pidG) await apiCall(`/api/projects/${pidG}/tasks`, 'POST', { title: 'Hito de lanzamiento', due_date: plusDays(9), is_milestone: 1 });
  if (pidG) await apiCall(`/api/projects/${pidG}/tasks`, 'POST', { title: 'Preparar contenidos', start_date: plusDays(1), due_date: plusDays(6) });
  await page.goto(BASE + projectUrl + '/timeline');
  check('La carta Gantt se muestra en el proyecto', await page.locator('[data-gantt]').isVisible(), page.url());
  const bars = await page.locator('[data-gantt-bar]').count();
  check('…con barras para las tareas con fechas y el hito', bars > 0 && await page.locator('.gantt-milestone').count() >= 1, String(bars));
  await page.locator('[data-gantt-scale="month"]').click();
  await page.waitForLoadState('domcontentloaded');
  check('Cambia a escala de meses', /scale=month/.test(page.url()) && await page.locator('[data-gantt-scale="month"][aria-current="page"]').count() === 1);
  await page.locator('[data-gantt-scale="week"]').click();
  await page.waitForLoadState('domcontentloaded');
  await page.screenshot({ path: `${SHOTS}/53-gantt.png`, fullPage: true });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.reload();
  check('En el móvil la carta se desplaza dentro de su marco, sin desbordar la página', (await noOverflow()) <= 1, String(await noOverflow()));
  await page.screenshot({ path: `${SHOTS}/53-gantt-movil.png` });
  await page.setViewportSize({ width: 1440, height: 900 });
});

await attempt('1.14 · Auditoría, notificaciones y análisis por período', async () => {
  await page.goto(BASE + '/admin/audit');
  check('El registro de actividad lista los cambios', await page.locator('[data-audit-row]').count() > 0);
  await page.getByRole('link', { name: /Comprobar integridad/ }).click();
  await page.waitForLoadState('domcontentloaded');
  check('«Comprobar integridad» confirma la cadena', await page.locator('[data-audit-verify="ok"]').isVisible());
  await page.screenshot({ path: `${SHOTS}/54-auditoria.png`, fullPage: true });
  await page.goto(BASE + '/admin/notifications');
  check('Administración › Notificaciones dice el estado de la tarea programada', await page.locator('[data-job-state]').isVisible());
  await page.screenshot({ path: `${SHOTS}/55-admin-notificaciones.png`, fullPage: true });
  await page.goto(BASE + '/analysis?period=quarter');
  check('Análisis por trimestre con «¿Qué ocurrió en el período?»', await page.locator('[data-movement]').isVisible());
  await page.screenshot({ path: `${SHOTS}/56-analisis-periodo.png`, fullPage: true });
});

const viewports = { escritorio: [1440, 900], portatil: [1280, 800], tableta: [820, 1180], movil: [390, 844] };
const pagesToShoot = { inicio: '/', proyectos: '/projects', tablero: projectUrl + '/board', lista: projectUrl + '/list', resumen: projectUrl, 'mi-trabajo': '/my-work', calendario: '/calendar', monitoreo: '/monitoring', analisis: '/analysis', equipos: '/teams', sprints: '/sprints', 'todo-a-mano': '/integrations', notificaciones: '/notifications' };
for (const [vname, [w, h]] of Object.entries(viewports)) {
  await page.setViewportSize({ width: w, height: h });
  const overflow = [];
  for (const [pname, path] of Object.entries(pagesToShoot)) {
    await page.goto(BASE + path);
    await page.waitForLoadState('networkidle');
    const ov = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    if (ov > 1) overflow.push(`${pname} (+${ov}px)`);
    await page.screenshot({ path: `${SHOTS}/${vname}-${pname}.png` });
  }
  check(`${vname} ${w}×${h}: sin desplazamiento horizontal de página`, overflow.length === 0, overflow.join(', '));
  if (w < 900) {
    await page.goto(BASE + '/');
    const toggle = page.locator('[data-sidebar-toggle]');
    check(`${vname}: menú lateral accesible con botón`, await toggle.isVisible());
    await toggle.click();
    await page.waitForTimeout(300);
    check(`${vname}: el menú lateral se abre`, await page.locator('#sidebar.open').isVisible());
    await page.screenshot({ path: `${SHOTS}/${vname}-menu-abierto.png` });
  }
}

await attempt('Teclado: foco visible y atajo de búsqueda', async () => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(BASE + '/');
  await page.keyboard.press('/');
  check('La tecla / enfoca la búsqueda', await page.evaluate(() => document.activeElement?.id === 'global-search'));
  await page.keyboard.press('Escape');
  await page.keyboard.press('Tab');
  const outline = await page.evaluate(() => getComputedStyle(document.activeElement).outlineStyle + ' ' + getComputedStyle(document.activeElement).boxShadow);
  check('El elemento enfocado muestra un indicador visible', !/^none none$/.test(outline), outline);
});

section('Errores de JavaScript');
check('Sin errores de JavaScript en la consola durante todo el recorrido', consoleErrors.length === 0, consoleErrors.slice(0, 5).join(' | '));

await browser.close();
const ok = results.filter((r) => r.ok).length;
console.log(`\nResultado: ${ok} correctas, ${results.length - ok} fallidas, ${results.length} en total. Capturas en ${SHOTS}/`);
if (JSON_OUT) writeFileSync(JSON_OUT, JSON.stringify({ date: new Date().toISOString(), base: BASE, pass: ok, fail: results.length - ok, results }, null, 2));
process.exit(results.length - ok ? 1 : 0);
