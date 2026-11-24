<?php
declare(strict_types=1);

/*
 * Borealis 1.12 — «Monitoreo»: compliance, delays with reasons, due-date history, estimate vs logged,
 * planned load against capacity, the rule-based alerts (dedupe, escalation, acknowledge, auto-resolve),
 * dependencies, sprint commitments, reports and permissions. Covers the ten mandatory scenarios of
 * the Fase B brief, plus migration 014 run twice.
 * Included by run.php after v111.php (same globals: $admin, $q, $role, $ws1, $mkUser, check(), group(), snippet()).
 */

group('1.12 · Monitoreo de cumplimiento y carga de trabajo');
$root = dirname(__DIR__, 2);
$cnt = fn(string $sql, array $p = []) => (int)$q($sql, $p)->fetchColumn();
$tz = new DateTimeZone('America/Mexico_City'); // the installer of run.php uses this zone
$day = fn(int $d) => (new DateTimeImmutable('now', $tz))->modify("$d day")->format('Y-m-d');
$cron = function () use ($root): array {
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/notify.php') . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
};
$alert = fn(string $key) => $q('SELECT * FROM monitor_alerts WHERE workspace_id = ? AND dedupe_key = ?', [$ws1, $key])->fetch(PDO::FETCH_ASSOC) ?: null;

// ---- Migration 014
$tables = $cnt("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('task_due_changes','task_explanations','sprint_commitments','monitor_alerts','monitor_runs')");
$cols = $cnt("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks' AND COLUMN_NAME IN ('started_at','blocked_at','blocked_by','remaining_hours')");
check('Migración 014: cinco tablas nuevas y cuatro columnas en tareas', $tables === 5 && $cols === 4, "$tables / $cols");
$errors = [];
foreach (preg_split('/^-- @@\s*$/m', (string)file_get_contents($root . '/database/migrations/014_v112.sql')) as $stmt) {
    $sql = trim(preg_replace('/^--.*$/m', '', $stmt));
    if ($sql === '') continue;
    try { $q($sql); } catch (PDOException $e) { if (!in_array((int)($e->errorInfo[1] ?? 0), [1060, 1061], true)) $errors[] = $e->getMessage(); }
}
check('La migración 014 se puede repetir sin errores', !$errors, implode(' | ', $errors));

// ---- People and project
[$mara, $maraC] = $mkUser('Mara Gestora', 'mara.gestora@example.test', 'member');
[$tito, $titoC] = $mkUser('Tito Tareas', 'tito.tareas@example.test', 'member');
[$dora, $doraC] = $mkUser('Dora Carga', 'dora.carga@example.test', 'member');
[$fede, $fedeC] = $mkUser('Fede Quince', 'fede.quince@example.test', 'member');
[$lalo, $laloC] = $mkUser('Lalo Lejano', 'lalo.lejano@example.test', 'member');
$admin->api('POST', '/api/projects', ['name' => 'Proyecto Monitoreo', 'project_key' => 'MON']);
$pm = (int)($admin->json()['id'] ?? 0);
$admin->api('POST', "/api/projects/$pm/modules", ['module' => 'sprints', 'enabled' => true]);
$admin->api('POST', "/api/projects/$pm/members", ['user_id' => $mara, 'role_id' => $role($ws1, 'project', 'manager')]);
foreach ([$tito, $dora, $fede] as $u) $admin->api('POST', "/api/projects/$pm/members", ['user_id' => $u, 'role_id' => $role($ws1, 'project', 'contributor')]);
$q('UPDATE workspace_members SET weekly_hours = 15 WHERE workspace_id = ? AND user_id IN (?, ?)', [$ws1, $dora, $fede]);
$mk = function (array $in) use ($admin, $pm): array {
    $admin->api('POST', "/api/projects/$pm/tasks", $in);
    return $admin->json()['task'] ?? [];
};

// 1) Overdue task visible in Monitoreo
$late = $mk(['title' => 'Entrega vencida', 'assignee_id' => $tito, 'due_date' => $day(-3)]);
$lateId = (int)($late['id'] ?? 0);
$maraC->get("/monitoring?tab=compliance&class=late&project_id=$pm");
check('Escenario 1: una tarea vencida aparece en Monitoreo › Cumplimiento › Atrasadas con sus días de retraso', $maraC->status === 200 && str_contains($maraC->body, 'Entrega vencida') && str_contains($maraC->body, '3 días de retraso'), snippet($maraC));
$maraC->get("/monitoring?project_id=$pm");
check('…y cuenta en el indicador «Tareas atrasadas» del resumen, que enlaza a la lista', $maraC->status === 200 && (bool)preg_match('/data-kpi="late"[^>]*>.*?<span class="value">1<\/span>/s', $maraC->body), snippet($maraC));
check('El resumen muestra el estado del proyecto con su motivo (sin cambiar el estado del proyecto)', str_contains($maraC->body, 'Proyecto Monitoreo') && (str_contains($maraC->body, '>Atención<') || str_contains($maraC->body, '>Crítico<')) && $q('SELECT state FROM projects WHERE id = ?', [$pm])->fetchColumn() === 'active');
$titoC->get('/t/' . ($late['key'] ?? ''));
check('La ficha de la tarea muestra «Seguimiento» con la puntualidad «Atrasada» y ofrece registrar el motivo', str_contains($titoC->body, 'data-punctuality="late"') && str_contains($titoC->body, 'Registrar el motivo'), snippet($titoC));

// 9) Rescheduling keeps the history; reason with comment, author and date
$titoC->api('POST', "/api/tasks/$lateId/explanations", ['kind' => 'delay', 'reason' => 'external', 'comment' => 'El proveedor no envió los datos', 'corrective_action' => 'Pedirlos por escrito']);
check('Se registra el motivo del retraso con comentario, acción correctiva, autor y fecha', $titoC->status === 200
    && (bool)$q("SELECT 1 FROM task_explanations WHERE task_id = ? AND reason = 'external' AND created_by = ? AND corrective_action = 'Pedirlos por escrito' AND created_at IS NOT NULL", [$lateId, $tito])->fetchColumn(), snippet($titoC));
$titoC->api('POST', "/api/tasks/$lateId/explanations", ['reason' => 'inventado']);
check('Un motivo fuera de la lista se rechaza', $titoC->status === 422);
$titoC->api('PATCH', "/api/tasks/$lateId", ['due_date' => $day(2)]);
$titoC->api('PATCH', "/api/tasks/$lateId", ['due_date' => $day(4)]);
$hist = $q('SELECT old_due, new_due, changed_by FROM task_due_changes WHERE task_id = ? ORDER BY id', [$lateId])->fetchAll(PDO::FETCH_ASSOC);
check('Escenario 9: reprogramar dos veces deja las dos fechas anteriores en el historial', count($hist) === 2 && $hist[0]['old_due'] === $day(-3) && $hist[1]['old_due'] === $day(2) && $hist[1]['new_due'] === $day(4) && (int)$hist[0]['changed_by'] === $tito, json_encode($hist));
check('…y el motivo registrado se conserva con la fecha límite que tenía entonces', $q('SELECT due_date FROM task_explanations WHERE task_id = ?', [$lateId])->fetchColumn() === $day(-3));
$titoC->get('/t/' . ($late['key'] ?? ''));
check('La ficha muestra «La fecha límite cambió 2 veces» y el motivo con su autor', str_contains($titoC->body, 'La fecha límite cambió 2 veces') && str_contains($titoC->body, 'Dependencia externa') && str_contains($titoC->body, 'Tito Tareas'));
$titoC->api('PATCH', "/api/tasks/$lateId", ['due_date' => $day(-3)]); // back to overdue for the alert scenarios

// 2) Done late keeps its history
$late2 = $mk(['title' => 'Cierre tardío', 'assignee_id' => $tito, 'due_date' => $day(-2)]);
$titoC->api('POST', '/api/tasks/' . (int)$late2['id'] . '/done', ['done' => true]);
$maraC->get("/monitoring?tab=compliance&class=done_late&project_id=$pm");
check('Escenario 2: una tarea completada fuera del plazo aparece en «Completadas fuera del plazo» con sus días', str_contains($maraC->body, 'Cierre tardío') && str_contains($maraC->body, 'Fuera de plazo · 2 días')
    && $q('SELECT completed_due_date FROM tasks WHERE id = ?', [$late2['id']])->fetchColumn() === $day(-2), snippet($maraC));
$maraC->get("/monitoring?tab=compliance&class=late&project_id=$pm");
check('…y ya no figura entre las atrasadas', !str_contains($maraC->body, 'Cierre tardío'));

// 3) 5 h estimated, 12 h logged → +7 h, +140 %
$dev = $mk(['title' => 'Estimación corta', 'assignee_id' => $tito, 'estimate_hours' => 5, 'due_date' => $day(5)]);
$devId = (int)$dev['id'];
$titoC->api('POST', "/api/tasks/$devId/time", ['hours' => '8', 'work_date' => $day(0)]);
$titoC->api('POST', "/api/tasks/$devId/time", ['hours' => '4', 'work_date' => $day(0)]);
$titoC->api('POST', "/api/tasks/$devId/done", ['done' => true]);
$d = $titoC->json()['deviation'] ?? null;
check('Escenario 3: al finalizar con 12 h de 5 h estimadas, la respuesta indica +7 h y +140 %', $d && (float)$d['hours'] === 7.0 && (int)$d['pct'] === 140 && str_contains((string)($titoC->json()['message'] ?? ''), '+140 %'), json_encode($titoC->json()));
$maraC->get("/monitoring?tab=times&project_id=$pm");
check('…y Monitoreo › Tiempos lo muestra como «+7 h · +140 %»', str_contains($maraC->body, 'Estimación corta') && str_contains($maraC->body, '+7 h · +140 %'), snippet($maraC));
$zero = $mk(['title' => 'Sin estimar con horas', 'assignee_id' => $tito]);
$titoC->api('POST', '/api/tasks/' . (int)$zero['id'] . '/time', ['hours' => '2', 'work_date' => $day(0)]);
$maraC->get("/monitoring?tab=times&project_id=$pm");
check('Una tarea sin estimación no divide por cero: se marca «Sin estimación» y no entra en los totales', $maraC->status === 200 && str_contains($maraC->body, 'Sin estimar con horas') && str_contains($maraC->body, 'Sin estimación'), snippet($maraC));

// Manual time: dedupe and audited correction
$n0 = $cnt('SELECT COUNT(*) FROM time_entries WHERE task_id = ? AND deleted_at IS NULL', [$zero['id']]);
$titoC->api('POST', '/api/tasks/' . (int)$zero['id'] . '/time', ['hours' => '2', 'work_date' => $day(0)]);
check('El mismo registro de horas enviado dos veces seguidas no se duplica', $titoC->status === 200 && $cnt('SELECT COUNT(*) FROM time_entries WHERE task_id = ? AND deleted_at IS NULL', [$zero['id']]) === $n0);
$teId = $cnt('SELECT id FROM time_entries WHERE task_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1', [$zero['id']]);
$titoC->api('PATCH', "/api/time/$teId", ['hours' => '1:30', 'note' => 'Corregido: eran 90 minutos']);
check('Corregir un registro de horas cambia los minutos y deja el antes y el después en el historial', $titoC->status === 200 && $cnt('SELECT minutes FROM time_entries WHERE id = ?', [$teId]) === 90
    && (bool)$q("SELECT 1 FROM activity WHERE action = 'time_updated' AND data LIKE ? AND data LIKE '%\"minutes\":120%' AND data LIKE '%\"minutes\":90%'", ['%"entry":' . $teId . ',%'])->fetchColumn(), snippet($titoC));
$laloC->api('PATCH', "/api/time/$teId", ['hours' => '9']);
check('Alguien ajeno al proyecto no puede corregir horas de otros', in_array($laloC->status, [403, 404], true) && $cnt('SELECT minutes FROM time_entries WHERE id = ?', [$teId]) === 90);
$titoC->api('PATCH', "/api/time/$teId", ['hours' => '30']);
check('Una corrección fuera de rango (más de 24 h) se rechaza', $titoC->status === 422 && $cnt('SELECT minutes FROM time_entries WHERE id = ?', [$teId]) === 90);

// 4) 15 h capacity, 22 h planned → 7 h over
$big = $mk(['title' => 'Carga grande', 'assignee_id' => $dora, 'estimate_hours' => 22, 'due_date' => $day(0)]);
$maraC->get("/monitoring?tab=workload&user=$dora");
check('Escenario 4: 22 h planificadas con 15 h de capacidad se ven como «Sobrecarga» con 7 h por encima', str_contains($maraC->body, 'Dora Carga') && str_contains($maraC->body, 'Sobrecarga') && str_contains($maraC->body, '7 h por encima') && str_contains($maraC->body, 'Carga grande'), snippet($maraC));
[$c1, $o1] = $cron();
$ov = $q("SELECT * FROM monitor_alerts WHERE workspace_id = ? AND rule = 'overload' AND user_id = ? AND state <> 'resolved'", [$ws1, $dora])->fetch(PDO::FETCH_ASSOC);
check('…y el motor de reglas crea la alerta de sobrecarga explicada (+7 h) y avisa a la persona', $c1 === 0 && $ov && str_contains($ov['message'], '(+7 h)') && str_contains($ov['explanation'], 'no un límite ni una medida de rendimiento')
    && $cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'monitor'", [$dora]) >= 1, $o1 . ' ' . json_encode($ov));
check('La ejecución queda registrada (última revisión correcta)', (int)$q('SELECT ok FROM monitor_runs WHERE workspace_id = ?', [$ws1])->fetchColumn() === 1);

// 7) Running it again creates no duplicates
$a0 = $cnt('SELECT COUNT(*) FROM monitor_alerts WHERE workspace_id = ?', [$ws1]);
$nn0 = $cnt("SELECT COUNT(*) FROM notifications WHERE type = 'monitor'");
[$c2] = $cron();
[$c3] = $cron();
check('Escenario 7: ejecutar la revisión dos veces más no crea alertas ni avisos repetidos', $c2 === 0 && $c3 === 0 && $cnt('SELECT COUNT(*) FROM monitor_alerts WHERE workspace_id = ?', [$ws1]) === $a0 && $cnt("SELECT COUNT(*) FROM notifications WHERE type = 'monitor'") === $nn0, "$a0 / $nn0");
$ovA = $alert('overdue:' . $lateId);
check('La tarea vencida tiene su alerta (advertencia, nivel 1) con el porqué', $ovA && $ovA['severity'] === 'warning' && (int)$ovA['level'] === 1 && str_contains($ovA['explanation'], 'Venció el'), json_encode($ovA));

// Escalation: two days without review → whoever manages the project
$q('UPDATE monitor_alerts SET first_seen = DATE_SUB(first_seen, INTERVAL 3 DAY) WHERE id = ?', [$ovA['id']]);
$cron();
$ovA = $alert('overdue:' . $lateId);
check('Sin revisar en 2 días, la alerta sube a nivel 2 y avisa a quien gestiona el proyecto', (int)$ovA['level'] === 2 && $cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'monitor'", [$mara]) >= 1, json_encode($ovA));
$nm = $cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'monitor'", [$mara]);
$cron();
check('…sin repetir el aviso en la siguiente ejecución', $cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'monitor'", [$mara]) === $nm);
$laloC->api('POST', '/api/monitoring/alerts/' . $ovA['id'] . '/ack');
check('Alguien ajeno no puede marcarla como revisada', in_array($laloC->status, [403, 404], true) && $alert('overdue:' . $lateId)['state'] === 'open');
$maraC->api('POST', '/api/monitoring/alerts/' . $ovA['id'] . '/ack', ['note' => 'Hablado con Tito']);
check('Quien gestiona el proyecto la marca como revisada (con nota y en el historial)', $maraC->status === 200 && $alert('overdue:' . $lateId)['state'] === 'ack'
    && (bool)$q("SELECT 1 FROM activity WHERE action = 'alert_ack' AND entity_id = ?", [$ovA['id']])->fetchColumn(), snippet($maraC));
$q('UPDATE monitor_alerts SET first_seen = DATE_SUB(first_seen, INTERVAL 10 DAY) WHERE id = ?', [$ovA['id']]);
$cron();
check('Una alerta revisada ya no escala', $alert('overdue:' . $lateId)['state'] === 'ack' && (int)$alert('overdue:' . $lateId)['level'] === 2);

// 5) 15 of 15 hours used with committed work pending (existing capacity alert, shown from Monitoreo)
$doneF = $mk(['title' => 'Trabajo de la semana', 'assignee_id' => $fede, 'estimate_hours' => 15, 'due_date' => $day(0)]);
$mk(['title' => 'Pendiente comprometido', 'assignee_id' => $fede, 'estimate_hours' => 3, 'due_date' => $day(0)]);
$fedeC->api('POST', '/api/tasks/' . (int)$doneF['id'] . '/time', ['hours' => '15', 'work_date' => $day(0)]);
check('Escenario 5: con 15 de 15 h registradas y trabajo comprometido pendiente se abre la alerta de capacidad', (bool)$q("SELECT 1 FROM capacity_alerts WHERE workspace_id = ? AND user_id = ? AND status = 'open'", [$ws1, $fede])->fetchColumn());
$fedeC->get('/monitoring?tab=risks');
check('…y Monitoreo › Riesgos y alertas la enlaza para decidir qué hacer', $fedeC->status === 200 && str_contains($fedeC->body, 'capacidad agotada con trabajo pendiente'), snippet($fedeC));

// 6) Dependency on a blocked task, without changing any date
$a = $mk(['title' => 'Base de datos', 'assignee_id' => $tito, 'due_date' => $day(6)]);
$b = $mk(['title' => 'Pantalla de pagos', 'assignee_id' => $dora, 'due_date' => $day(8)]);
$admin->api('POST', '/api/tasks/' . (int)$a['id'] . '/links', ['target' => $b['key'], 'link_type' => 'blocks']);
check('Se registra «Base de datos bloquea a Pantalla de pagos»', $admin->status === 200, snippet($admin));
$titoC->api('PATCH', '/api/tasks/' . (int)$a['id'], ['is_blocked' => true, 'blocked_reason' => 'Falta el acceso al servidor']);
$row = $q('SELECT blocked_at, blocked_by FROM tasks WHERE id = ?', [$a['id']])->fetch(PDO::FETCH_ASSOC);
check('El bloqueo guarda desde cuándo y quién lo marcó', $row['blocked_at'] !== null && (int)$row['blocked_by'] === $tito, json_encode($row));
$q('UPDATE monitor_runs SET ran_at = DATE_SUB(ran_at, INTERVAL 1 HOUR) WHERE workspace_id = ?', [$ws1]);
$maraC->get("/monitoring?tab=compliance&project_id=$pm");
check('Escenario 6: «Pantalla de pagos» aparece en «Dependencias en riesgo» porque la tarea de la que depende está bloqueada', str_contains($maraC->body, 'Dependencias en riesgo') && str_contains($maraC->body, 'Pantalla de pagos'), snippet($maraC));
check('…y ninguna fecha cambió', $q('SELECT due_date FROM tasks WHERE id = ?', [$b['id']])->fetchColumn() === $day(8) && $q('SELECT due_date FROM tasks WHERE id = ?', [$a['id']])->fetchColumn() === $day(6));
check('Hay alerta de bloqueo (para quien gestiona) y de dependencia (para la responsable de la tarea afectada)', $alert('blocked:' . $a['id']) && $alert('dependency:' . $b['id'] . ':' . $a['id']));

// Dependencies: circle, self, duplicate, authorization
$admin->api('POST', '/api/tasks/' . (int)$b['id'] . '/links', ['target' => $a['key'], 'link_type' => 'blocks']);
check('Una dependencia circular directa se rechaza', $admin->status === 422 && str_contains((string)($admin->json()['errors']['target'] ?? ''), 'círculo'), snippet($admin));
$c = $mk(['title' => 'Despliegue']);
$admin->api('POST', '/api/tasks/' . (int)$b['id'] . '/links', ['target' => $c['key'], 'link_type' => 'blocks']);
$admin->api('POST', '/api/tasks/' . (int)$c['id'] . '/links', ['target' => $a['key'], 'link_type' => 'blocks']);
check('…y también una indirecta (A → B → C → A)', $admin->status === 422 && str_contains((string)($admin->json()['errors']['target'] ?? ''), 'círculo'), snippet($admin));
$admin->api('POST', '/api/tasks/' . (int)$a['id'] . '/links', ['target' => $a['key'], 'link_type' => 'blocks']);
check('Una tarea no puede depender de sí misma', $admin->status === 422);
$admin->api('POST', '/api/tasks/' . (int)$a['id'] . '/links', ['target' => $b['key'], 'link_type' => 'blocks']);
check('La misma relación no se duplica', $admin->status === 422 && $cnt("SELECT COUNT(*) FROM task_links WHERE source_task_id = ? AND target_task_id = ? AND link_type = 'blocks'", [$a['id'], $b['id']]) === 1);
$admin->api('POST', '/api/projects', ['name' => 'Proyecto Ajeno', 'project_key' => 'AJE']);
$pa = (int)($admin->json()['id'] ?? 0);
$admin->api('POST', "/api/projects/$pa/members", ['user_id' => $lalo, 'role_id' => $role($ws1, 'project', 'contributor')]);
$admin->api('POST', "/api/projects/$pa/tasks", ['title' => 'Tarea ajena', 'assignee_id' => $lalo, 'due_date' => $day(-1)]);
$ajena = $admin->json()['task'] ?? [];
$titoC->api('POST', '/api/tasks/' . (int)$a['id'] . '/links', ['target' => $ajena['key'], 'link_type' => 'blocks']);
check('No se puede crear una dependencia con una tarea de un proyecto donde no se puede editar', in_array($titoC->status, [403, 404, 422], true) && !$cnt('SELECT COUNT(*) FROM task_links WHERE target_task_id = ?', [$ajena['id']]), snippet($titoC));

// Auto-resolve: when the cause disappears
$titoC->api('PATCH', '/api/tasks/' . (int)$a['id'], ['is_blocked' => false]);
$q('UPDATE monitor_runs SET ran_at = DATE_SUB(ran_at, INTERVAL 1 HOUR) WHERE workspace_id = ?', [$ws1]);
$cron();
check('Al desbloquear, las alertas de bloqueo y de dependencia se resuelven solas', $alert('blocked:' . $a['id'])['state'] === 'resolved' && $alert('dependency:' . $b['id'] . ':' . $a['id'])['state'] === 'resolved');

// 8) Permissions: URL and API manipulation
$laloC->get("/monitoring?project_id=$pm");
check('Escenario 8: quien no participa recibe 404 al pedir el monitoreo de ese proyecto por URL', $laloC->status === 404);
$laloC->get("/monitoring/report.csv?project_id=$pm");
check('…también en el informe CSV', $laloC->status === 404);
$laloC->get('/monitoring?tab=risks');
check('…y sus alertas solo muestran lo suyo (nada del proyecto ajeno)', $laloC->status === 200 && !str_contains($laloC->body, 'Entrega vencida') && !str_contains($laloC->body, 'Dora Carga'), snippet($laloC));
$laloC->get('/monitoring?tab=workload');
check('…ni la carga de personas con las que no comparte proyecto', !str_contains($laloC->body, 'Dora Carga'));
$laloC->api('POST', "/api/tasks/$lateId/explanations", ['reason' => 'other']);
check('…ni puede registrar motivos en sus tareas', in_array($laloC->status, [403, 404], true));
$titoC->api('PATCH', '/api/monitoring/settings', ['soon_days' => 5]);
check('Solo la administración cambia los umbrales', $titoC->status === 403);
$admin->api('PATCH', '/api/monitoring/settings', ['load_available' => 90, 'load_high' => 80]);
check('Los umbrales de carga deben ir de menor a mayor', $admin->status === 422);
$admin->api('PATCH', '/api/monitoring/settings', ['soon_days' => 3, 'escalate_admin' => '0']);
$set = json_decode((string)$q('SELECT settings FROM workspaces WHERE id = ?', [$ws1])->fetchColumn(), true)['monitoring'] ?? [];
check('La administración guarda los umbrales (solo en el espacio, sin tocar config/)', $admin->status === 200 && (int)($set['soon_days'] ?? 0) === 3 && (int)($set['escalate_admin'] ?? 1) === 0, json_encode($set));
$admin->get('/admin');
check('Administración › Ajustes muestra el formulario de umbrales', str_contains($admin->body, 'Umbrales de monitoreo') && str_contains($admin->body, 'name="load_over"'));
$titoC->get('/monitoring');
check('Sin ranking de personas: ninguna vista ordena ni puntúa por productividad', !preg_match('/ranking|productividad|puntuaci/i', strip_tags($titoC->body)));

// 10) Sprint closed with work pending: original commitment kept
$admin->api('POST', "/api/projects/$pm/sprints", ['name' => 'Sprint Monitoreo', 'goal' => 'Pagos en línea', 'start_date' => $day(-7), 'end_date' => $day(-1)]);
$spId = (int)($admin->json()['id'] ?? 0);
$s1 = $mk(['title' => 'Sprint tarea 1', 'assignee_id' => $tito, 'estimate_hours' => 2, 'sprint_id' => $spId]);
$s2 = $mk(['title' => 'Sprint tarea 2', 'assignee_id' => $tito, 'estimate_hours' => 3, 'sprint_id' => $spId]);
$s3 = $mk(['title' => 'Sprint tarea 3', 'assignee_id' => $dora, 'estimate_hours' => 4, 'sprint_id' => $spId]);
$admin->api('POST', "/api/sprints/$spId/start");
check('Al iniciar el sprint se guarda el compromiso original (3 tareas planificadas)', $admin->status === 200 && $cnt('SELECT COUNT(*) FROM sprint_commitments WHERE sprint_id = ? AND planned = 1', [$spId]) === 3, snippet($admin));
$s4 = $mk(['title' => 'Sprint tarea añadida', 'assignee_id' => $tito, 'sprint_id' => $spId]);
check('Lo que entra después del inicio queda como «añadida»', $cnt('SELECT planned FROM sprint_commitments WHERE sprint_id = ? AND task_id = ?', [$spId, $s4['id']]) === 0 && $q('SELECT planned FROM sprint_commitments WHERE sprint_id = ? AND task_id = ?', [$spId, $s4['id']])->fetchColumn() !== false);
$titoC->api('POST', '/api/tasks/' . (int)$s1['id'] . '/done', ['done' => true]);
$q('UPDATE monitor_runs SET ran_at = DATE_SUB(ran_at, INTERVAL 1 HOUR) WHERE workspace_id = ?', [$ws1]);
$cron();
check('Un sprint con la fecha de fin pasada y pendientes genera su alerta (nunca se cierra solo)', (bool)$alert('sprint_pending:' . $spId) && $q('SELECT state FROM sprints WHERE id = ?', [$spId])->fetchColumn() === 'active');
$maraC->get("/monitoring?project_id=$pm");
check('El resumen muestra el sprint activo con planificadas, añadidas y su riesgo', str_contains($maraC->body, 'Sprint Monitoreo') && str_contains($maraC->body, 'planificadas al inicio') && str_contains($maraC->body, 'ya pasó con 3 pendientes'), snippet($maraC));
$admin->api('POST', "/api/sprints/$spId/complete", []);
$out = $q('SELECT task_id, outcome FROM sprint_commitments WHERE sprint_id = ? ORDER BY task_id', [$spId])->fetchAll(PDO::FETCH_KEY_PAIR);
check('Escenario 10: al cerrarlo incompleto se guarda qué se completó y qué volvió al backlog', $admin->status === 200 && ($out[$s1['id']] ?? '') === 'done' && ($out[$s2['id']] ?? '') === 'pending' && ($out[$s3['id']] ?? '') === 'pending' && ($out[$s4['id']] ?? '') === 'pending', json_encode($out) . ' ' . snippet($admin));
check('…sin perder el compromiso original (3 planificadas + 1 añadida)', $cnt('SELECT COUNT(*) FROM sprint_commitments WHERE sprint_id = ? AND planned = 1', [$spId]) === 3 && $cnt('SELECT COUNT(*) FROM sprint_commitments WHERE sprint_id = ? AND planned = 0', [$spId]) === 1);
$maraC->get("/monitoring?tab=report&project_id=$pm");
check('El informe del período muestra el resumen del sprint cerrado: 3 planificadas, 1 añadida, 1 completada, 3 al backlog', $maraC->status === 200 && str_contains($maraC->body, 'Sprint Monitoreo')
    && (bool)preg_match('/<b>3<\/b> planificadas al inicio.*<b>1<\/b> añadidas después.*<b>1<\/b> completadas.*<b>3<\/b> devueltas al backlog/s', $maraC->body), snippet($maraC));
$q('UPDATE monitor_runs SET ran_at = DATE_SUB(ran_at, INTERVAL 1 HOUR) WHERE workspace_id = ?', [$ws1]);
$cron();
check('Al cerrar el sprint su alerta se resuelve sola', $alert('sprint_pending:' . $spId)['state'] === 'resolved');

// Report CSV
$maraC->get("/monitoring/report.csv?project_id=$pm");
check('El informe CSV se descarga con retrasos, motivos y desviaciones del proyecto', $maraC->status === 200 && str_contains($maraC->headers['content-type'][0] ?? '', 'text/csv')
    && str_contains($maraC->body, 'Entrega vencida') && str_contains($maraC->body, 'Dependencia externa') && str_contains($maraC->body, 'Estimación corta'), mb_substr($maraC->body, 0, 300));
check('…y no incluye proyectos que esa persona no ve', !str_contains($maraC->body, 'Tarea ajena'));

// Pages render for every tab and role
$okTabs = true; $bad = '';
foreach (['summary', 'compliance', 'workload', 'times', 'risks', 'report'] as $tab) foreach ([$admin, $maraC, $titoC, $laloC] as $cl) {
    $cl->get("/monitoring?tab=$tab");
    if ($cl->status !== 200 || str_contains($cl->body, 'Warning:') || str_contains($cl->body, 'Notice:')) { $okTabs = false; $bad = "$tab " . snippet($cl); }
}
foreach (['project', 'sprint'] as $v) { $maraC->get("/monitoring?tab=workload&view=$v"); if ($maraC->status !== 200) { $okTabs = false; $bad = "view $v " . snippet($maraC); } }
check('Las seis vistas de Monitoreo cargan para administración, gestión, colaboración y alguien ajeno', $okTabs, $bad);
$admin->get('/');
check('El menú principal tiene «Monitoreo» (y Reportes sigue disponible dentro)', str_contains($admin->body, '/monitoring') && str_contains($admin->body, 'Monitoreo'));
