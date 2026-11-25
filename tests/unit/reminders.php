<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1); // 1.16.5: never runs when requested from the web

/*
 * Reminder rules of 1.9, without a server or database:
 *   php tests/unit/reminders.php      → prints one JSON line {"ok":bool,"checks":[...]}
 * Which reminder applies at a given moment (24 h and 30 min by default), how it is worded, and
 * that the Google Calendar event ids are valid for Google (base32hex) and stable.
 */
putenv('BOREALIS_CONFIG=' . sys_get_temp_dir() . '/borealis-no-config-' . getmypid() . '.php');
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use Borealis\Core\Config;
use Borealis\Services\Notifications as N;

$checks = [];
$c = function (string $name, bool $ok, string $detail = '') use (&$checks) { $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $ok ? '' : $detail]; };
Config::set('app.timezone', 'America/Santiago');
$tz = new DateTimeZone('America/Santiago');
$at = fn(string $s) => new DateTimeImmutable($s, $tz);
$due = $at('2026-11-20 17:00');
$def = N::BEFORE_DEFAULT;

$c('Por defecto: 24 horas y 30 minutos antes', $def === [1440, 30]);
$c('Dos días antes: todavía ningún aviso', N::dueLead($def, $at('2026-11-18 17:00'), '2026-11-20', $due) === null);
$c('23 h 59 min antes: el aviso de 24 horas', N::dueLead($def, $at('2026-11-19 17:01'), '2026-11-20', $due) === 1440);
$c('29 minutos antes: el de 30 minutos', N::dueLead($def, $at('2026-11-20 16:31'), '2026-11-20', $due) === 30);
$c('Si ya pasaron ambos momentos (tarea creada tarde), solo el más cercano', N::dueLead($def, $at('2026-11-20 16:50'), '2026-11-20', $due) === 30);
$c('Sin momentos elegidos: nunca avisa', N::dueLead([], $at('2026-11-20 16:50'), '2026-11-20', $due) === null);
$c('Texto: «vence mañana a las 17:00»', N::dueText($at('2026-11-19 17:05'), '2026-11-20', $due) === 'vence mañana a las 17:00', N::dueText($at('2026-11-19 17:05'), '2026-11-20', $due));
$c('Texto: «vence en 25 minutos (a las 17:00)»', N::dueText($at('2026-11-20 16:35'), '2026-11-20', $due) === 'vence en 25 minutos (a las 17:00)', N::dueText($at('2026-11-20 16:35'), '2026-11-20', $due));
$c('Texto: «vence hoy a las 17:00» con más de hora y media', N::dueText($at('2026-11-20 09:00'), '2026-11-20', $due) === 'vence hoy a las 17:00');
// date only
$c('Solo fecha: la víspera, «24 horas antes» = «vence mañana»', N::dueLead($def, $at('2026-11-19 09:00'), '2026-11-20', null) === 1440);
$c('Solo fecha: el mismo día, los avisos de horas se juntan en uno («hoy»)', N::dueLead($def, $at('2026-11-20 09:00'), '2026-11-20', null) === 0 && N::dueText($at('2026-11-20 09:00'), '2026-11-20', null) === 'vence hoy');
$c('Solo fecha: dos días antes, nada (con los valores por defecto)', N::dueLead($def, $at('2026-11-18 09:00'), '2026-11-20', null) === null);
$c('Solo fecha con «3 días antes»: avisa desde tres días antes', N::dueLead([4320, 30], $at('2026-11-17 09:00'), '2026-11-20', null) === 4320);
$c('Una semana antes, con «1 semana»', N::dueLead([10080], $at('2026-11-13 18:00'), '2026-11-20', $due) === 10080);
// Google event ids
Config::set('app.key', base64_encode(str_repeat('k', 32)));
$id = \Borealis\Services\GoogleExport::eventId(7, 'task:42', 'primary@test');
$c('Id de evento válido para Google (base32hex, 5 a 1024 caracteres)', (bool)preg_match('/^[a-v0-9]{5,1024}$/', $id), $id);
$c('El mismo elemento da siempre el mismo id (no se duplica al repetir)', $id === \Borealis\Services\GoogleExport::eventId(7, 'task:42', 'primary@test'));
$c('Otra persona, otra tarea u otro calendario dan otro id', count(array_unique([$id, \Borealis\Services\GoogleExport::eventId(8, 'task:42', 'primary@test'), \Borealis\Services\GoogleExport::eventId(7, 'task:43', 'primary@test'), \Borealis\Services\GoogleExport::eventId(7, 'task:42', 'otro@test')])) === 4);

echo json_encode(['ok' => !in_array(false, array_column($checks, 'ok'), true), 'checks' => $checks], JSON_UNESCAPED_UNICODE), "\n";
