<?php
declare(strict_types=1);

/*
 * Borealis 1.13 — «Análisis»: history with explicit denominators, estimate suggestions, insufficient
 * data, future overload, rule-based risk, scenarios (simulate, apply, revert), permissions,
 * consistency after a change, performance, migration 015 and the formulas (tests/unit/analysis.php).
 * Covers the ten mandatory scenarios (A–J) of the Fase C brief.
 * Included by run.php after v112.php (same globals: $admin, $q, $role, $ws1, $mkUser, check(), group(), snippet()).
 */

group('1.13 · Análisis y apoyo a decisiones');
$root = dirname(__DIR__, 2);
$cnt = fn(string $sql, array $p = []) => (int)$q($sql, $p)->fetchColumn();
$tz = new DateTimeZone('America/Mexico_City');
$day = fn(int $d) => (new DateTimeImmutable('now', $tz))->modify("$d day")->format('Y-m-d');
$nextMon = (new DateTimeImmutable('now', $tz))->modify('monday next week');
$nm = $nextMon->format('Y-m-d');
$nf = $nextMon->modify('+4 days')->format('Y-m-d');

// ---- Formulas (no server)
$aout = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tests/unit/analysis.php'), $aout, $acode);
$unit = json_decode(implode('', $aout), true) ?: ['ok' => false, 'checks' => []];
foreach ($unit['checks'] as $uc) check('Fórmulas: ' . $uc['name'], $uc['ok'], $uc['detail']);
if (!$unit['checks']) check('Fórmulas: pruebas unitarias ejecutadas', false, implode("\n", $aout));

// ---- Migration 015
$tables = $cnt("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('analysis_cache','scenario_applications')");
$idx = $cnt("SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME IN ('ix_task_completed','ix_tdc_changed','ix_act_entity')");
check('Migración 015: dos tablas nuevas y tres índices', $tables === 2 && $idx === 3, "$tables / $idx");
$errors = [];
foreach (preg_split('/^-- @@\s*$/m', (string)file_get_contents($root . '/database/migrations/015_v113.sql')) as $stmt) {
    $sql = trim(preg_replace('/^--.*$/m', '', $stmt));
    if ($sql === '') continue;
    try { $q($sql); } catch (PDOException $e) { if (!in_array((int)($e->errorInfo[1] ?? 0), [1060, 1061], true)) $errors[] = $e->getMessage(); }
}
check('La migración 015 se puede repetir sin errores', !$errors, implode(' | ', $errors));

// ---- People and projects
[$gema, $gemaC] = $mkUser('Gema Gestora', 'gema.gestora@example.test', 'member');
[$beto, $betoC] = $mkUser('Beto Bloque', 'beto.bloque@example.test', 'member');
[$ana, $anaC] = $mkUser('Ana Cuarenta', 'ana.cuarenta@example.test', 'member');
[$otto, $ottoC] = $mkUser('Otto Ajeno', 'otto.ajeno@example.test', 'member');
$mkP = function (string $name, string $key) use ($admin, $role, $ws1, $gema, $beto, $ana): int {
    $admin->api('POST', '/api/projects', ['name' => $name, 'project_key' => $key]);
    $id = (int)($admin->json()['id'] ?? 0);
    $admin->api('POST', "/api/projects/$id/modules", ['module' => 'sprints', 'enabled' => true]);
    $admin->api('POST', "/api/projects/$id/members", ['user_id' => $gema, 'role_id' => $role($ws1, 'project', 'manager')]);
    foreach ([$beto, $ana] as $u) $admin->api('POST', "/api/projects/$id/members", ['user_id' => $u, 'role_id' => $role($ws1, 'project', 'contributor')]);
    return $id;
};
$ph = $mkP('Análisis Histórico', 'ANH');
$mk = function (int $pid, array $in) use ($admin): array { $admin->api('POST', "/api/projects/$pid/tasks", $in); return $admin->json()['task'] ?? []; };
$done = function (array $t) use ($admin): void { $admin->api('POST', '/api/tasks/' . (int)$t['id'] . '/done', ['done' => true]); };

// ---- A) History: 15 on time, 5 late, 2 without date, 3 pending
for ($i = 1; $i <= 15; $i++) $done($mk($ph, ['title' => "A tiempo $i", 'assignee_id' => $beto, 'due_date' => $day(5)]));
for ($i = 1; $i <= 5; $i++) $done($mk($ph, ['title' => "Tarde $i", 'assignee_id' => $beto, 'due_date' => $day(-3)]));
for ($i = 1; $i <= 2; $i++) $done($mk($ph, ['title' => "Sin fecha $i", 'assignee_id' => $beto]));
$pend = [];
for ($i = 1; $i <= 3; $i++) $pend[] = $mk($ph, ['title' => "Pendiente $i", 'assignee_id' => $beto, 'due_date' => $day($i === 1 ? -2 : 9)]);
$gemaC->get("/analysis?tab=trends&project_id=$ph");
$row = preg_match('/<tr data-ind="ontime">(.*?)<\/tr>/s', $gemaC->body, $m) ? $m[1] : '';
check('Prueba A: 15 a tiempo y 5 fuera de plazo → 75 % de cumplimiento entre las 20 finalizadas con fecha válida', $gemaC->status === 200 && str_contains($row, '<strong>75 %</strong>') && str_contains($row, '15 de 20'), snippet($gemaC));
check('…con el denominador explícito y las 2 finalizadas sin fecha listadas como excluidas (las 3 pendientes no se mezclan)', str_contains($row, '2 sin fecha límite registrada') && str_contains($row, '15 a tiempo ÷ 20 con fecha válida'), $row);
$late = preg_match('/<tr data-ind="late">(.*?)<\/tr>/s', $gemaC->body, $m) ? $m[1] : '';
check('…y 25 % finalizadas fuera de plazo (5 de 20)', str_contains($late, '<strong>25 %</strong>') && str_contains($late, '5 de 20'), $late);
check('Cada indicador explica su fórmula, lo que se cuenta y lo que queda fuera («¿Cómo se calcula?»)', substr_count($gemaC->body, '¿Cómo se calcula?') >= 5 && str_contains($gemaC->body, 'finalizadas a tiempo ÷ finalizadas con fecha límite válida × 100'));

// ---- B) Estimates: five comparable tasks, each about 2 h over its estimate
$admin->api('POST', "/api/projects/$ph/labels", ['name' => 'Informe', 'color' => '#0EA5E9']);
$lab = (int)($admin->json()['id'] ?? 0);
foreach ([[4, 6], [5, 7], [3, 5], [6, 8], [4, 6]] as $k => [$est, $real]) {
    $t = $mk($ph, ['title' => 'Informe ' . ($k + 1), 'assignee_id' => $beto, 'estimate_hours' => $est, 'due_date' => $day(4), 'label_ids' => [$lab]]);
    $betoC->api('POST', '/api/tasks/' . (int)$t['id'] . '/time', ['hours' => (string)$real, 'work_date' => $day(0)]);
    $done($t);
    $estTasks[] = $t;
}
check('Preparación: cinco tareas «Informe» con etiqueta, horas estimadas y registradas', $cnt('SELECT COUNT(*) FROM task_labels WHERE label_id = ?', [$lab]) === 5 && $cnt("SELECT COUNT(*) FROM tasks t JOIN statuses s ON s.id = t.status_id WHERE t.project_id = ? AND s.category = 'done' AND t.title LIKE 'Informe %'", [$ph]) === 5);
$betoC->get("/api/analysis/estimate?project_id=$ph&label_ids=$lab&estimate=4");
$s = $betoC->json()['suggestion'] ?? [];
check('Prueba B: con 5 tareas comparables Borealis sugiere a partir del historial y muestra la muestra usada', $betoC->status === 200 && ($s['ok'] ?? false) && (int)$s['n'] === 5 && count($s['tasks'] ?? []) === 5, json_encode($s));
check('…«desviación habitual de aproximadamente 2 h sobre su estimación inicial», con origen y período', str_contains((string)($s['message'] ?? ''), 'aproximadamente 2 h sobre su estimación inicial') && (float)$s['median_hours'] === 2.0 && str_contains((string)$s['source'], 'Análisis Histórico') && str_contains((string)$s['source'], 'Informe'), json_encode($s));
check('…y para una estimación de 4 h sugiere 6 h (mediana de registrado ÷ estimado = 1,5)', (float)($s['suggested'] ?? 0) === 6.0 && (float)$s['median_ratio'] === 1.5, json_encode($s));
check('Consultar la sugerencia no modifica ninguna estimación', $cnt("SELECT COUNT(*) FROM tasks WHERE project_id = ? AND title LIKE 'Informe %' AND estimate_hours IN (4,5,3,6)", [$ph]) === 5);
$betoC->get("/api/analysis/estimate?project_id=$ph&estimate=4");
$s2 = $betoC->json()['suggestion'] ?? [];
check('Las categorías no se mezclan: sin etiqueta no hay tareas comparables (las «Informe» no cuentan)', ($s2['ok'] ?? true) === false && (int)$s2['excluded']['other_category'] >= 5, json_encode($s2));
$admin->get('/t/' . ($pend[1]['key'] ?? ''));
check('La ficha de la tarea ofrece «Sugerencia según el historial» junto al estimado (opcional)', str_contains($admin->body, 'data-estimate-suggest') && str_contains($admin->body, 'Sugerencia según el historial'), snippet($admin));
$admin->get('/');
check('El diálogo de nueva tarea ofrece consultar el historial', str_contains($admin->body, 'data-estimate-suggest data-project-from="#nt-project"'));

// ---- C) Insufficient data
$pc = $mkP('Poco Historial', 'POC');
$done($mk($pc, ['title' => 'Única 1', 'assignee_id' => $beto, 'due_date' => $day(3), 'estimate_hours' => 2]));
$done($mk($pc, ['title' => 'Única 2', 'assignee_id' => $beto, 'due_date' => $day(-1), 'estimate_hours' => 2]));
$gemaC->get("/analysis?tab=trends&project_id=$pc");
check('Prueba C: con 2 tareas finalizadas Borealis dice «No hay datos suficientes para identificar una tendencia confiable.»', $gemaC->status === 200 && substr_count($gemaC->body, 'No hay datos suficientes para identificar una tendencia confiable.') >= 2 && str_contains($gemaC->body, 'data-pattern="none"'), snippet($gemaC));
check('…y no inventa patrones ni tendencias (ningún «Mejora» ni «Empeora»)', !preg_match('/data-trend="(up|down|stable)"/', $gemaC->body) && !str_contains($gemaC->body, 'data-pattern="estimate"'));
$gemaC->get("/analysis?project_id=$pc");
check('El resumen tampoco genera recomendaciones estadísticas y avisa en «Calidad de los datos»', $gemaC->status === 200 && !str_contains($gemaC->body, 'data-rec="estimate"') && str_contains($gemaC->body, 'no hay historial suficiente'), snippet($gemaC));
$gemaC->get("/api/analysis/estimate?project_id=$pc&estimate=2");
check('…ni sugiere estimaciones: «No hay suficientes tareas comparables» con cuántas hay', ($gemaC->json()['suggestion']['ok'] ?? true) === false && str_contains((string)($gemaC->json()['suggestion']['message'] ?? ''), '(0 de 5 necesarias)'), json_encode($gemaC->json()));

// ---- D) Future overload: 58 h planned next week, 40 h capacity
$pd = $mkP('Planificación Futura', 'PLF');
$admin->api('PATCH', "/api/members/$ana", ['weekly_hours' => 40]);
$mk($pd, ['title' => 'Diseño grande', 'assignee_id' => $ana, 'estimate_hours' => 30, 'start_date' => $nm, 'due_date' => $nf]);
$mk($pd, ['title' => 'Integración grande', 'assignee_id' => $ana, 'estimate_hours' => 28, 'start_date' => $nm, 'due_date' => $nf]);
// the team «Equipo Ana»: Ana (40 h) and Gema (no weekly hours, listed apart)
$admin->api('POST', '/api/teams', ['name' => 'Equipo Ana']);
$team = (int)($admin->json()['id'] ?? 0);
$admin->api('POST', "/api/teams/$team/members", ['user_id' => $ana]);
$admin->api('POST', "/api/teams/$team/members", ['user_id' => $gema, 'team_role' => 'coordinator']);
$creator = (int)$q('SELECT user_id FROM team_members WHERE team_id = ? AND user_id NOT IN (?, ?)', [$team, $ana, $gema])->fetchColumn();
$admin->api('DELETE', "/api/teams/$team/members/$creator");
check('Preparación: equipo con Ana (40 h semanales) y Gema (sin horas configuradas)', $cnt('SELECT COUNT(*) FROM team_members WHERE team_id = ?', [$team]) === 2, (string)$cnt('SELECT COUNT(*) FROM team_members WHERE team_id = ?', [$team]));
$gemaC->get("/analysis?tab=planning&team_id=$team");
$wk = preg_match('/<tr data-week="' . $nm . '">(.*?)<\/tr>\s*<tr class="an-subrow">(.*?)<\/tr>/s', $gemaC->body, $m) ? $m[0] : '';
check('Prueba D: la semana próxima muestra 40 h de capacidad, 58 h planificadas y 18 h de diferencia', $gemaC->status === 200 && str_contains($wk, '>40 h<') && str_contains($wk, '>58 h<') && str_contains($wk, 'data-gap="18"'), $wk ?: snippet($gemaC));
check('…con «La carga planificada supera la capacidad disponible en 18 horas…» y alternativas de reorganización', str_contains($wk, 'La carga planificada supera la capacidad disponible en 18 horas. Se recomienda revisar fechas, prioridades o distribución del trabajo.')
    && str_contains($wk, 'Dividir tareas grandes.') && str_contains($wk, 'No se propone ampliar la jornada'), $wk);
check('…y quien no tiene horas configuradas se lista aparte, sin inventar su capacidad', str_contains($wk, 'Sin horas semanales configuradas (no cuentan): Gema Gestora'), $wk);
$gemaC->get("/analysis?team_id=$team");
check('…y el resumen recomienda revisar esa semana, con evidencia y confianza', str_contains($gemaC->body, 'tiene más trabajo planificado que capacidad') && str_contains($gemaC->body, 'Diferencia: 18 h por encima.') && str_contains($gemaC->body, 'Confianza'), snippet($gemaC));

// ---- E) Sprint risk: 22 h pending, 12 h capacity, one blocked task
$pe = $mkP('Sprint en Riesgo', 'SPR');
$admin->api('POST', "/api/projects/$pe/sprints", ['name' => 'Sprint Riesgo', 'start_date' => $day(0), 'end_date' => $day(13)]);
$sp = (int)($admin->json()['id'] ?? 0);
$admin->api('POST', "/api/projects/$pe/sprints", ['name' => 'Sprint Siguiente', 'start_date' => $day(14), 'end_date' => $day(27)]);
$sp2 = (int)($admin->json()['id'] ?? 0);
$e1 = $mk($pe, ['title' => 'Pieza diez', 'assignee_id' => $beto, 'estimate_hours' => 10, 'sprint_id' => $sp, 'priority_id' => null]);
$e2 = $mk($pe, ['title' => 'Pieza doce', 'assignee_id' => $beto, 'estimate_hours' => 12, 'sprint_id' => $sp]);
$admin->api('POST', "/api/sprints/$sp/start");
$gemaC->api('PATCH', "/api/sprints/$sp/capacity", ['user_id' => $beto, 'hours' => 12]);
$admin->api('PATCH', '/api/tasks/' . (int)$e2['id'], ['is_blocked' => 1, 'blocked_reason' => 'Esperando al proveedor']);
check('Preparación: sprint activo con 22 h, 12 h disponibles para Beto y una tarea bloqueada', $q('SELECT state FROM sprints WHERE id = ?', [$sp])->fetchColumn() === 'active' && (float)$q('SELECT hours FROM sprint_capacity WHERE sprint_id = ? AND user_id = ?', [$sp, $beto])->fetchColumn() === 12.0);
$gemaC->get("/analysis?tab=planning&project_id=$pe");
$item = preg_match('/<li class="an-risk" data-level="(\w+)"><div class="an-risk-head"><span class="an-risk-title"><a[^>]*>Sprint en Riesgo · Sprint Riesgo<\/a>.*?<\/details>/s', $gemaC->body, $m) ? $m : ['', ''];
check('Prueba E: el sprint aparece con «Riesgo elevado»', $item[1] === 'high', $item[0] ?: snippet($gemaC));
check('…explicado: «mantiene 22 h estimadas de trabajo pendiente y dispone de 12 h de capacidad planificada» y «una tarea bloqueada»', str_contains($item[0], 'riesgo elevado porque mantiene 22 h estimadas de trabajo pendiente y dispone de 12 h de capacidad planificada') && str_contains($item[0], 'una tarea bloqueada'), $item[0]);
check('…como clasificación por reglas, sin porcentaje de probabilidad', str_contains($item[0], 'Clasificación por reglas, no una probabilidad') && !preg_match('/\d+\s*%\s*de probabilidad/u', $gemaC->body));

// ---- F) Scenario: moving the 12 h task changes the simulated indicators, not the database
$before = $q('SELECT id, sprint_id, estimate_hours, due_date, is_blocked, updated_at FROM tasks WHERE project_id = ? ORDER BY id', [$pe])->fetchAll(PDO::FETCH_ASSOC);
$gemaC->api('POST', "/api/analysis/sprints/$sp/simulate", ['changes' => [['type' => 'move', 'task_id' => (int)$e2['id'], 'to_sprint_id' => $sp2]]]);
$res = $gemaC->json()['result'] ?? [];
check('Prueba F: simular el traslado de la tarea de 12 h deja 10 h pendientes y 2 h de margen en el escenario', $gemaC->status === 200 && (float)($res['scenario']['pending'] ?? -1) === 10.0 && (float)$res['scenario']['margin'] === 2.0 && (float)$res['current']['pending'] === 22.0, json_encode($res['scenario'] ?? $gemaC->json()));
check('…compara «Estado actual» con «Escenario simulado» (riesgo elevado → bajo)', $res['current']['level'] === 'high' && $res['scenario']['level'] === 'low' && str_contains((string)($gemaC->json()['html'] ?? ''), 'Escenario simulado'), json_encode([$res['current']['level'] ?? null, $res['scenario']['level'] ?? null]));
$after = $q('SELECT id, sprint_id, estimate_hours, due_date, is_blocked, updated_at FROM tasks WHERE project_id = ? ORDER BY id', [$pe])->fetchAll(PDO::FETCH_ASSOC);
check('…sin modificar la base de datos real', $before === $after && $cnt('SELECT COUNT(*) FROM scenario_applications') === 0);
$gemaC->api('POST', "/api/analysis/sprints/$sp/simulate", ['changes' => [['type' => 'unblock', 'task_id' => (int)$e2['id']], ['type' => 'capacity', 'user_id' => $beto, 'hours' => 30]]]);
$r2 = $gemaC->json()['result'] ?? [];
check('Resolver el bloqueo y subir la capacidad se simulan, y el bloqueo queda marcado como hipotético', $gemaC->status === 200 && (int)$r2['scenario']['blocked'] === 0 && str_contains(implode(' ', $r2['notes'] ?? []), 'no se aplicará') && (int)$q('SELECT is_blocked FROM tasks WHERE id = ?', [$e2['id']])->fetchColumn() === 1, json_encode($r2['notes'] ?? $gemaC->json()));
$gemaC->get("/analysis?tab=scenarios&sprint_id=$sp&move=" . (int)$e2['id']);
check('La pestaña Escenarios abre el simulador con la tarea propuesta ya trasladada', $gemaC->status === 200 && str_contains($gemaC->body, 'id="an-sim"') && str_contains($gemaC->body, 'data-sim-level="low"'), snippet($gemaC));

// ---- G) Authorised application, audited and reversible
$fp = (string)($res['fingerprint'] ?? '');
$changes = [['type' => 'capacity', 'user_id' => $beto, 'hours' => 30], ['type' => 'move', 'task_id' => (int)$e2['id'], 'to_sprint_id' => $sp2]];
$gemaC->api('POST', "/api/analysis/sprints/$sp/simulate", ['changes' => $changes]);
$fp = (string)($gemaC->json()['result']['fingerprint'] ?? '');
$betoC->api('POST', "/api/analysis/sprints/$sp/apply", ['changes' => $changes, 'fingerprint' => $fp, 'confirm' => true]);
check('Un colaborador no puede aplicar escenarios (403) y nada cambia', $betoC->status === 403 && (int)$q('SELECT sprint_id FROM tasks WHERE id = ?', [$e2['id']])->fetchColumn() === $sp);
$gemaC->api('POST', "/api/analysis/sprints/$sp/apply", ['changes' => $changes, 'fingerprint' => $fp]);
check('Sin confirmación explícita no se aplica (422)', $gemaC->status === 422 && (int)$q('SELECT sprint_id FROM tasks WHERE id = ?', [$e2['id']])->fetchColumn() === $sp);
$gemaC->api('POST', "/api/analysis/sprints/$sp/apply", ['changes' => $changes, 'fingerprint' => str_repeat('0', 64), 'confirm' => true]);
check('Si los datos cambiaron desde la simulación (huella distinta) se rechaza con 409 y nada cambia', $gemaC->status === 409 && str_contains((string)($gemaC->json()['error'] ?? ''), 'cambiaron desde la simulación') && (int)$q('SELECT sprint_id FROM tasks WHERE id = ?', [$e2['id']])->fetchColumn() === $sp);
$gemaC->api('POST', "/api/analysis/sprints/$sp/apply", ['changes' => $changes, 'fingerprint' => $fp, 'confirm' => true]);
$scen = (int)($gemaC->json()['scenario']['id'] ?? 0);
check('Prueba G: quien gestiona el proyecto confirma y los cambios se aplican (tarea al sprint siguiente, 30 h disponibles)', $gemaC->status === 200 && (int)$q('SELECT sprint_id FROM tasks WHERE id = ?', [$e2['id']])->fetchColumn() === $sp2
    && (float)$q('SELECT hours FROM sprint_capacity WHERE sprint_id = ? AND user_id = ?', [$sp, $beto])->fetchColumn() === 30.0, snippet($gemaC));
$audit = $q('SELECT * FROM scenario_applications WHERE id = ?', [$scen])->fetch(PDO::FETCH_ASSOC);
check('…y quedan auditados: quién, cuándo, cada valor antes y después, y en la actividad', $audit && (int)$audit['user_id'] === $gema && $audit['state'] === 'applied' && str_contains($audit['changes'], '"before":12') && str_contains($audit['changes'], '"after":30')
    && $cnt("SELECT COUNT(*) FROM activity WHERE action = 'scenario_applied' AND entity_id = ?", [$sp]) === 1, json_encode($audit));
$gemaC->api('POST', "/api/analysis/scenarios/$scen/revert");
check('El escenario se puede revertir: la tarea vuelve al sprint y la capacidad a 12 h', $gemaC->status === 200 && (int)$q('SELECT sprint_id FROM tasks WHERE id = ?', [$e2['id']])->fetchColumn() === $sp
    && (float)$q('SELECT hours FROM sprint_capacity WHERE sprint_id = ? AND user_id = ?', [$sp, $beto])->fetchColumn() === 12.0 && $q('SELECT state FROM scenario_applications WHERE id = ?', [$scen])->fetchColumn() === 'reverted', snippet($gemaC));
$gemaC->api('POST', "/api/analysis/scenarios/$scen/revert");
check('…una sola vez', $gemaC->status === 409);
$ch2 = [['type' => 'due', 'task_id' => (int)$e1['id'], 'date' => $day(10)]];
$gemaC->api('POST', "/api/analysis/sprints/$sp/simulate", ['changes' => $ch2]);
$fp2 = (string)($gemaC->json()['result']['fingerprint'] ?? '');
$gemaC->api('POST', "/api/analysis/sprints/$sp/apply", ['changes' => $ch2, 'fingerprint' => $fp2, 'confirm' => true]);
$scen2 = (int)($gemaC->json()['scenario']['id'] ?? 0);
$applied2 = $q('SELECT due_date FROM tasks WHERE id = ?', [$e1['id']])->fetchColumn() === $day(10);
$admin->api('PATCH', '/api/tasks/' . (int)$e1['id'], ['due_date' => $day(11)]);
$gemaC->api('POST', "/api/analysis/scenarios/$scen2/revert");
check('Si alguien cambió la tarea después, revertir se rechaza y explica qué cambió (no pisa su trabajo)', $applied2 && $gemaC->status === 409 && str_contains((string)($gemaC->json()['error'] ?? ''), 'SPR-') && $q('SELECT due_date FROM tasks WHERE id = ?', [$e1['id']])->fetchColumn() === $day(11), json_encode($gemaC->json()));
$gemaC->api('POST', "/api/analysis/sprints/$sp/simulate", ['changes' => [['type' => 'move', 'task_id' => 999999]]]);
check('Un cambio sobre una tarea que no está en el sprint se rechaza', $gemaC->status === 422);

// ---- H) Permissions
$ottoC->get("/analysis?project_id=$ph");
check('Prueba H: alguien ajeno no puede consultar el análisis de otro proyecto (404 del servidor)', $ottoC->status === 404, snippet($ottoC));
$ottoC->get("/api/analysis/estimate?project_id=$ph&estimate=4");
$s404 = $ottoC->status;
$ottoC->get("/analysis/report.csv?project_id=$ph");
$c404 = $ottoC->status;
$ottoC->get("/analysis/report/print?project_id=$ph");
$p404 = $ottoC->status;
$ottoC->api('POST', "/api/analysis/sprints/$sp/simulate", ['changes' => []]);
check('…ni por la API, el CSV, la vista imprimible ni el simulador', $s404 === 404 && $c404 === 404 && $p404 === 404 && $ottoC->status === 404, "$s404 $c404 $p404 {$ottoC->status}");
$ottoC->get("/analysis?tab=trends&sprint_id=$sp");
check('…ni con un sprint ajeno en la URL', $ottoC->status === 404);
$ottoC->get('/analysis');
check('Su propio Análisis carga y no menciona proyectos ajenos', $ottoC->status === 200 && !str_contains($ottoC->body, 'Análisis Histórico') && !str_contains($ottoC->body, 'Sprint en Riesgo'), snippet($ottoC));
$betoC->api('POST', "/api/analysis/sprints/$sp/simulate", ['changes' => []]);
check('Un colaborador del proyecto no puede usar el simulador (403)', $betoC->status === 403);
$betoC->api('PATCH', '/api/analysis/settings', ['min_sample' => 3]);
check('Solo la administración cambia los parámetros globales del análisis', $betoC->status === 403);
$admin->api('PATCH', '/api/analysis/settings', ['min_sample' => 4, 'risk_moderate' => 2, 'risk_elevated' => 4]);
check('…y el cambio queda registrado con el valor anterior y el nuevo', $admin->status === 200 && (bool)$q("SELECT 1 FROM activity WHERE action = 'settings' AND summary LIKE '%parámetros de análisis%Registros mínimos para una conclusión 5 → 4%'")->fetchColumn(), snippet($admin));
$admin->api('PATCH', '/api/analysis/settings', ['risk_moderate' => 5, 'risk_elevated' => 3]);
check('Umbrales incoherentes se rechazan (elevado ≤ moderado)', $admin->status === 422);
$admin->api('PATCH', '/api/analysis/settings', ['min_sample' => 5]);

// ---- I) Consistency: a change in estimate or date updates the indicators (no stale cache)
$gemaC->get("/analysis?tab=estimates&project_id=$ph");
$m1 = preg_match('/data-dev-median>([^<]+)</', $gemaC->body, $m) ? $m[1] : '';
$gemaC->get("/analysis?tab=estimates&project_id=$ph");
$cachedRows = $cnt('SELECT COUNT(*) FROM analysis_cache WHERE workspace_id = ?', [$ws1]);
$admin->api('PATCH', '/api/tasks/' . (int)$estTasks[0]['id'], ['estimate_hours' => 6]);
$gemaC->get("/analysis?tab=estimates&project_id=$ph");
$m2 = preg_match('/data-dev-median>([^<]+)</', $gemaC->body, $m) ? $m[1] : '';
check('Prueba I: al cambiar una estimación la desviación mediana se recalcula (el resultado guardado se invalida)', $cachedRows > 0 && $m1 !== '' && $m2 !== '' && $m1 !== $m2, "$m1 → $m2 ($cachedRows en caché)");
$gemaC->get("/analysis?tab=planning&project_id=$ph");
$hadOverdue = str_contains($gemaC->body, '2 tareas vencidas');
$admin->api('PATCH', '/api/tasks/' . (int)$pend[1]['id'], ['due_date' => $day(-1)]);
$gemaC->get("/analysis?tab=planning&project_id=$ph");
check('…y al cambiar una fecha límite el riesgo del proyecto cuenta la nueva tarea vencida', !$hadOverdue && str_contains($gemaC->body, '2 tareas vencidas'), snippet($gemaC));
$gemaC->get("/analysis?project_id=$ph");
$gemaC->get("/analysis?project_id=$ph");
check('Una segunda visita sin cambios reutiliza el resultado guardado', str_contains($gemaC->body, 'Resultado guardado; se recalcula en cuanto cambian los datos.'));

// ---- J) Performance: 1 500 tasks and 3 000 time entries
$pj = $mkP('Volumen Grande', 'VOL');
$st = $q("SELECT id, category FROM statuses WHERE project_id = ? ORDER BY position", [$pj])->fetchAll(PDO::FETCH_ASSOC);
$doneS = (int)array_values(array_filter($st, fn($s) => $s['category'] === 'done'))[0]['id'];
$todoS = (int)$st[0]['id'];
$rows = [];
for ($i = 1; $i <= 1500; $i++) {
    $doneT = $i % 3 !== 0;
    $created = date('Y-m-d H:i:s', strtotime('-' . (170 - intdiv($i, 10)) . ' days'));
    $comp = $doneT ? date('Y-m-d H:i:s', strtotime($created . ' +' . ($i % 9 + 1) . ' days')) : null;
    $due = date('Y-m-d', strtotime($created . ' +' . ($i % 7 + 2) . ' days'));
    $rows[] = sprintf("(%d,%d,'Carga %d',%d,%d,%s,'%s',%s,%s,'%s','%s')", $pj, $i, $i, $doneT ? $doneS : $todoS, $ana, $i % 5 + 1, $due, $comp ? "'$comp'" : 'NULL', $comp ? "'$due'" : 'NULL', $created, $created);
    if (count($rows) === 500) { $q('INSERT INTO tasks (project_id, number, title, status_id, assignee_id, estimate_hours, due_date, completed_at, completed_due_date, created_at, updated_at) VALUES ' . implode(',', $rows)); $rows = []; }
}
$q("INSERT INTO time_entries (project_id, task_id, user_id, minutes, work_date, created_at) SELECT t.project_id, t.id, ?, 60 * ((t.number % 6) + 1), DATE(t.created_at), t.created_at FROM tasks t WHERE t.project_id = ?", [$ana, $pj]);
$q("INSERT INTO time_entries (project_id, task_id, user_id, minutes, work_date, created_at) SELECT t.project_id, t.id, ?, 30, DATE(t.created_at), t.created_at FROM tasks t WHERE t.project_id = ?", [$ana, $pj]);
check('Preparación: 1 500 tareas y 3 000 registros de horas en un proyecto', $cnt('SELECT COUNT(*) FROM tasks WHERE project_id = ?', [$pj]) === 1500 && $cnt('SELECT COUNT(*) FROM time_entries WHERE project_id = ?', [$pj]) === 3000);
$times = [];
foreach (['summary', 'trends', 'estimates', 'planning', 'report'] as $tb) {
    $t0 = microtime(true);
    $admin->get("/analysis?tab=$tb&project_id=$pj");
    $times[$tb] = [round(microtime(true) - $t0, 2), $admin->status];
}
$slow = array_filter($times, fn($x) => $x[0] > 4.0 || $x[1] !== 200);
check('Prueba J: con ese volumen cada pestaña responde en menos de 4 s la primera vez', !$slow, json_encode($times));
$t0 = microtime(true);
$admin->get("/analysis?tab=summary&project_id=$pj");
$warm = round(microtime(true) - $t0, 2);
check('…y en menos de 1 s cuando los datos no cambiaron (resultado guardado)', $warm < 1.0 && str_contains($admin->body, 'Resultado guardado'), (string)$warm);
$plan = $q("EXPLAIN SELECT id FROM tasks WHERE project_id = ? AND completed_at BETWEEN '2026-01-01' AND '2026-12-31'", [$pj])->fetch(PDO::FETCH_ASSOC);
check('Las tareas finalizadas por período se buscan con el índice nuevo', in_array($plan['key'] ?? '', ['ix_task_completed'], true) || str_contains((string)($plan['possible_keys'] ?? ''), 'ix_task_completed'), json_encode($plan));

// ---- Every view, every role; the menu; report and CSV
$ok = true; $bad = [];
foreach (['admin' => $admin, 'gestión' => $gemaC, 'colaboración' => $betoC, 'ajeno' => $ottoC] as $who => $cl) {
    foreach (['summary', 'trends', 'estimates', 'planning', 'scenarios', 'report'] as $tb) {
        $cl->get("/analysis?tab=$tb");
        if ($cl->status !== 200) { $ok = false; $bad[] = "$who/$tb " . $cl->status; }
    }
}
check('Las seis pestañas de Análisis cargan para administración, gestión, colaboración y alguien ajeno', $ok, implode(', ', $bad));
check('El menú principal tiene «Análisis» junto a Monitoreo', str_contains($admin->body, '> Análisis</a>') && str_contains($admin->body, 'Monitoreo</a>'));
$gemaC->get("/analysis?tab=report&project_id=$pe");
check('Informes: resumen, cumplimiento, evolución, carga, desviaciones, bloqueos, riesgos, recomendaciones y decisiones pendientes', $gemaC->status === 200 && str_contains($gemaC->body, '1. Resumen general') && str_contains($gemaC->body, '9. Decisiones pendientes') && str_contains($gemaC->body, 'Esperando al proveedor'), snippet($gemaC));
$gemaC->get("/analysis/report/print?project_id=$pe");
check('Vista imprimible con las mismas secciones', $gemaC->status === 200 && str_contains($gemaC->body, 'Informe de análisis') && str_contains($gemaC->body, '7. Riesgos'));
$gemaC->get("/analysis/report.csv?project_id=$ph");
check('CSV con cada indicador, su valor, numerador, denominador y excluidos', $gemaC->status === 200 && str_contains($gemaC->body, 'Cumplimiento de plazos') && str_contains($gemaC->body, '"Cumplimiento de plazos",80,%,20,25,2,'), mb_substr($gemaC->body, 0, 400));
$gemaC->get("/analysis?tab=trends&project_id=$ph&mine=1");
check('«Solo lo mío» limita los indicadores a las tareas propias (Gema no tiene ninguna)', $gemaC->status === 200 && str_contains($gemaC->body, '0 de 0'), snippet($gemaC));
$betoC->get("/analysis?tab=planning&project_id=$pd");
$capSec = preg_match('/<section class="card mb" data-capacity>(.*?)<\/section>/s', $betoC->body, $m) ? $m[1] : '';
check('Un integrante ve la capacidad sin el desglose de otras personas', $betoC->status === 200 && $capSec !== '' && !str_contains($capSec, 'Ana Cuarenta') && !str_contains($capSec, 'Por persona'), $capSec);
$admin->get('/admin');
check('Administración › Ajustes tiene «Parámetros de análisis» con valores por defecto', str_contains($admin->body, 'Parámetros de análisis') && str_contains($admin->body, 'name="risk_elevated" value="4"'));
check('Ninguna vista de Análisis ordena ni puntúa personas (sin rankings)', !preg_match('/ranking|puntuación de productividad/iu', $gemaC->body . $admin->body));
