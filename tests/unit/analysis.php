<?php
declare(strict_types=1);

/*
 * Análisis (1.13): the formulas and rules, without a server or database.
 *   php tests/unit/analysis.php      → prints one JSON line {"ok":bool,"checks":[...]}
 * Included in tests/integration/v113.php.
 */
putenv('BOREALIS_CONFIG=' . sys_get_temp_dir() . '/borealis-no-config-' . getmypid() . '.php');
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use Borealis\Services\Analysis\Metrics;
use Borealis\Services\Analysis\Planning;

$checks = [];
$c = function (string $name, bool $ok, string $detail = '') use (&$checks) { $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $ok ? '' : $detail]; };

// Prueba A (fórmula): 15 a tiempo de 20 con fecha válida = 75 %, denominador explícito
$r = Metrics::rate(15, 20);
$c('Cumplimiento: 15 de 20 = 75 % con numerador y denominador', $r['value'] === 75.0 && $r['num'] === 15 && $r['den'] === 20, json_encode($r));
$c('Sin denominador no hay porcentaje (no divide por cero)', Metrics::rate(0, 0)['value'] === null);

// Mediana, percentiles y valores extremos
$c('Mediana impar y par', Metrics::median([3, 1, 2]) === 2.0 && Metrics::median([1, 2, 3, 4]) === 2.5 && Metrics::median([]) === null);
$c('Percentil 25 y 75 con interpolación', abs(Metrics::percentile([1, 2, 3, 4, 5], 25) - 2.0) < 1e-9 && abs(Metrics::percentile([1, 2, 3, 4, 5], 75) - 4.0) < 1e-9);
[$keep, $out] = Metrics::outliers(['a' => 1.5, 'b' => 1.4, 'c' => 1.67, 'd' => 1.33, 'e' => 1.5]);
$c('Desviaciones parecidas (prompt: 4→6, 5→7, 3→5, 6→8, 4→6) no se descartan', count($keep) === 5 && !$out, json_encode($out));
[$keep, $out] = Metrics::outliers(['a' => 1.5, 'b' => 1.4, 'c' => 1.6, 'd' => 1.5, 'e' => 1.45, 'x' => 10.0]);
$c('Un valor extremo (10× lo estimado) se aparta y se cuenta', $out === ['x'] && count($keep) === 5, json_encode($out));
[$keep, $out] = Metrics::outliers(['a' => 1.0, 'b' => 9.0, 'c' => 1.1]);
$c('Con menos de 5 valores no se descarta nada', count($keep) === 3 && !$out);

// Tendencias: solo con 3 períodos con muestra suficiente
$t = Metrics::trend([['value' => 70.0, 'n' => 10], ['value' => 82.0, 'n' => 10], ['value' => 88.0, 'n' => 10]], 5);
$c('Tendencia de mejora 70 → 82 → 88 (+18 puntos)', $t['dir'] === 'up' && $t['change'] === 18.0, json_encode($t));
$t = Metrics::trend([['value' => 70.0, 'n' => 10], ['value' => 90.0, 'n' => 2], ['value' => 88.0, 'n' => 10]], 5);
$c('Prueba C: con solo 2 períodos suficientes → «No hay datos suficientes para identificar una tendencia confiable.»', $t['dir'] === 'nodata' && $t['text'] === 'No hay datos suficientes para identificar una tendencia confiable.', json_encode($t));
$c('Cambios pequeños se informan como «estable»', Metrics::trend([['value' => 80.0, 'n' => 9], ['value' => 82.0, 'n' => 9], ['value' => 81.0, 'n' => 9]], 5)['dir'] === 'stable');

// Restante, capacidad proporcional, días laborables
$c('Restante: horas anotadas > estimación − registradas, nunca negativo', Metrics::remaining(10.0, 3.0, 2.0) === 3.0 && Metrics::remaining(10.0, null, 4.0) === 6.0 && Metrics::remaining(2.0, null, 5.0) === 0.0 && Metrics::remaining(null, null, 1.0) === null);
$c('Capacidad restante proporcional a los días laborables', Metrics::capacityLeft(40, 10, 5) === 20.0 && Metrics::capacityLeft(40, 0, 0) === 0.0);
$c('Días laborables entre dos fechas (lunes 2026-11-16 a domingo 22)', Metrics::workdays('2026-11-16', '2026-11-22') === 5);

// Riesgo por reglas, no probabilidad
$f = [['points' => 3, 'text' => 'x'], ['points' => 1, 'text' => 'y']];
$c('Riesgo: 4 puntos = elevado, 2 = moderado, 1 = bajo, sin datos = «Sin datos suficientes»', Metrics::riskLevel($f, true, 2, 4) === 'high' && Metrics::riskLevel([['points' => 2]], true, 2, 4) === 'moderate'
    && Metrics::riskLevel([['points' => 1]], true, 2, 4) === 'low' && Metrics::riskLevel($f, false, 2, 4) === 'nodata');

// Cubrir una sobrecarga con las tareas de menor prioridad
[$pick, $sum] = Metrics::coverGap([['key' => 'A', 'hours' => 5.0], ['key' => 'B', 'hours' => 8.0], ['key' => 'C', 'hours' => 10.0]], 12.0);
$c('Para 12 h de sobrecarga se proponen A y B (13 h)', array_column($pick, 'key') === ['A', 'B'] && $sum === 13.0, json_encode($pick));

// Prueba E (reglas): 22 h pendientes, 12 h de capacidad y una tarea bloqueada → riesgo elevado explicado, sin porcentaje de probabilidad
$cfg = ['reprogram_threshold' => 3, 'overload_high_pct' => 130, 'pattern_deviation_pct' => 25, 'risk_moderate' => 2, 'risk_elevated' => 4, 'soon_days' => 2];
$task = fn(int $id, float $h, bool $blocked = false) => ['id' => $id, 'key' => "E-$id", 'title' => "T$id", 'estimate' => $h, 'remaining_set' => null, 'logged' => 0.0, 'due' => null, 'blocked' => $blocked,
    'people' => [7], 'priority_id' => null, 'weight' => 0, 'priority' => null, 'done' => false, 'in' => true, 'updated_at' => '', 'due_changes' => 0, 'deps' => []];
$m = ['sprint' => ['id' => 1, 'name' => 'S', 'state' => 'active', 'start' => '2026-11-16', 'end' => '2026-11-27', 'project_id' => 1],
    'tasks' => [1 => $task(1, 10.0), 2 => $task(2, 12.0, true)], 'capacity' => [7 => ['name' => 'Ana', 'hours' => 12.0, 'set' => true]], 'days_total' => 10, 'days_left' => 10, 'today' => '2026-11-16'];
$ev = Planning::evaluate($m, $cfg);
$c('Prueba E: 22 h pendientes con 12 h de capacidad y una bloqueada → riesgo elevado (3 + 1 puntos)', $ev['level'] === 'high' && $ev['points'] === 4 && $ev['pending'] === 22.0 && $ev['capacity_left'] === 12.0, json_encode($ev));
$c('…explicado en una frase con las cifras y sin probabilidad', str_contains($ev['sentence'], 'riesgo elevado porque mantiene 22 h estimadas de trabajo pendiente y dispone de 12 h de capacidad planificada') && str_contains($ev['sentence'], 'una tarea bloqueada') && !str_contains($ev['sentence'], 'probabilidad'), $ev['sentence']);

// Prueba F (cálculo): trasladar la tarea de 12 h → margen positivo y riesgo menor, en memoria
$m2 = $m; $m2['tasks'][2]['in'] = false;
$ev2 = Planning::evaluate($m2, $cfg);
$c('Prueba F: sin la tarea de 12 h quedan 10 h pendientes, 2 h de margen y riesgo bajo', $ev2['pending'] === 10.0 && $ev2['margin'] === 2.0 && $ev2['level'] === 'low', json_encode($ev2));

// Sin capacidad configurada o con la mitad sin estimar → «Sin datos suficientes»
$m3 = $m; $m3['capacity'][7]['hours'] = null;
$c('Sin horas disponibles configuradas el sprint queda «Sin datos suficientes»', Planning::evaluate($m3, $cfg)['level'] === 'nodata');
$m4 = $m; $m4['tasks'][1]['estimate'] = null;
$c('Con la mitad de las tareas sin estimar también', Planning::evaluate($m4, $cfg)['level'] === 'nodata');

// Mensaje de sobrecarga del prompt
$c('Prueba D (texto): «supera la capacidad disponible en 18 horas»', Planning::overloadMessage(18.0) === 'La carga planificada supera la capacidad disponible en 18 horas. Se recomienda revisar fechas, prioridades o distribución del trabajo.');

$ok = !array_filter($checks, fn($x) => !$x['ok']);
echo json_encode(['ok' => $ok, 'checks' => $checks], JSON_UNESCAPED_UNICODE), "\n";
exit($ok ? 0 : 1);
