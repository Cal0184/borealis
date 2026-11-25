<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1); // 1.16.5: never runs when requested from the web

/*
 * Time-zone and repetition rules of events (1.6), without a server or database:
 *   php tests/unit/events-time.php      → prints one JSON line {"ok":bool,"checks":[...]}
 * A weekly meeting on Mondays at 10:00 must stay at 10:00 local time across a daylight-saving change.
 */
putenv('BOREALIS_CONFIG=' . sys_get_temp_dir() . '/borealis-no-config-' . getmypid() . '.php');
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use Borealis\Core\Config;
use Borealis\Services\Events;

$checks = [];
$c = function (string $name, bool $ok, string $detail = '') use (&$checks) { $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $ok ? '' : $detail]; };

Config::set('app.timezone', 'America/New_York'); // DST ends on 2026-11-01
$start = Events::toUtc('2026-10-26 10:00');
$c('10:00 en Nueva York antes del cambio de horario se guarda como 14:00 UTC', $start === '2026-10-26 14:00:00', $start);
$c('10:00 en Nueva York después del cambio se guarda como 15:00 UTC', Events::toUtc('2026-11-02 10:00') === '2026-11-02 15:00:00', Events::toUtc('2026-11-02 10:00'));
$ev = ['starts_at' => $start, 'ends_at' => Events::toUtc('2026-10-26 11:00'), 'all_day' => 0, 'recurrence' => 'weekly', 'recurrence_until' => null];
$occ = Events::occurrences($ev, '2026-10-26', '2026-11-16');
$c('Reunión semanal de los lunes: 4 repeticiones en 4 semanas', count($occ) === 4, json_encode($occ));
$c('Siempre a las 10:00 hora local, también tras el cambio de horario', array_unique(array_map(fn($o) => substr($o[0], 11), $occ)) === ['10:00'] && array_map(fn($o) => substr($o[0], 0, 10), $occ) === ['2026-10-26', '2026-11-02', '2026-11-09', '2026-11-16'], json_encode($occ));
$c('Todas caen en lunes', array_unique(array_map(fn($o) => date('N', strtotime($o[0])), $occ)) === ['1'], json_encode($occ));
$ev['recurrence_until'] = '2026-11-03';
$c('«Hasta» corta la repetición', count(Events::occurrences($ev, '2026-10-26', '2026-11-30')) === 2);
$m = ['starts_at' => Events::toUtc('2026-01-31 09:00'), 'ends_at' => Events::toUtc('2026-01-31 09:30'), 'all_day' => 0, 'recurrence' => 'monthly', 'recurrence_until' => null];
$mo = array_map(fn($o) => substr($o[0], 0, 10), Events::occurrences($m, '2026-01-01', '2026-05-31'));
$c('Mensual el día 31: salta los meses que no lo tienen', $mo === ['2026-01-31', '2026-03-31', '2026-05-31'], json_encode($mo));
$ad = ['starts_at' => '2026-11-05 00:00:00', 'ends_at' => '2026-11-07 00:00:00', 'all_day' => 1, 'recurrence' => 'none', 'recurrence_until' => null];
$ao = Events::occurrences($ad, '2026-11-01', '2026-11-30');
$c('Evento de todo el día: fechas sin desplazamiento por zona horaria', $ao === [['2026-11-05 00:00', '2026-11-07 00:00']], json_encode($ao));
Config::set('app.timezone', 'Asia/Tokyo');
$c('El mismo evento de todo el día no cambia de fecha en otra zona', Events::occurrences($ad, '2026-11-01', '2026-11-30') === [['2026-11-05 00:00', '2026-11-07 00:00']]);
$c('Un evento que termina justo al empezar el rango no aparece en él', Events::occurrences(['starts_at' => Events::toUtc('2026-11-04 22:00'), 'ends_at' => Events::toUtc('2026-11-05 00:00'), 'all_day' => 0, 'recurrence' => 'none', 'recurrence_until' => null], '2026-11-05', '2026-11-05') === []);

echo json_encode(['ok' => !in_array(false, array_column($checks, 'ok'), true), 'checks' => $checks], JSON_UNESCAPED_UNICODE), "\n";
