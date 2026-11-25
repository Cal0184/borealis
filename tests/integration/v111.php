<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1); // 1.16.5: never runs when requested from the web

/*
 * Borealis 1.11 — integration checks: «Planificado» projects, late tasks (days of delay without a
 * new status), tasks finished after their due date, «Hoy» in Mi trabajo, the notice to whoever stops
 * being responsible, and migration 013 run twice.
 * Included by run.php after v110.php (same globals: $admin, $q, $role, $ws1, $mkUser, check(), group(), snippet()).
 */

group('1.11 · Proyectos planificados, tareas atrasadas y fuera de plazo');
$d = fn(int $days) => date('Y-m-d', strtotime(($days >= 0 ? '+' : '') . $days . ' days'));
[$ana, $anaC] = $mkUser('Ana Atrasos', 'ana.atrasos@example.test', 'member');
[$beto, $betoC] = $mkUser('Beto Relevo', 'beto.relevo@example.test', 'member');
[$ciro, $ciroC] = $mkUser('Ciro Ajeno', 'ciro.ajeno@example.test', 'member');

// ---- Migration 013
$enum = (string)$q("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'state'")->fetchColumn();
$col = (int)$q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks' AND COLUMN_NAME = 'completed_due_date'")->fetchColumn();
check('Migración 013: el estado «planned» existe y tasks.completed_due_date también', str_contains($enum, "'planned'") && str_contains($enum, "'on_hold'") && $col === 1, $enum);
$errors = [];
foreach (preg_split('/^-- @@\s*$/m', (string)file_get_contents(dirname(__DIR__, 2) . '/database/migrations/013_v111.sql')) as $stmt) {
    $sql = trim(preg_replace('/^--.*$/m', '', $stmt));
    if ($sql === '') continue;
    try { $q($sql); } catch (PDOException $e) { if ((int)($e->errorInfo[1] ?? 0) !== 1060) $errors[] = $e->getMessage(); }
}
check('La migración 013 se puede repetir sin errores ni cambios', !$errors, implode(' | ', $errors));

// ---- Projects: «Planificado»
$admin->api('POST', '/api/projects', ['name' => 'Proyecto Planificado', 'project_key' => 'PLN', 'state' => 'planned', 'start_date' => $d(10)]);
$pp = (int)($admin->json()['id'] ?? 0);
check('Se crea un proyecto en estado «Planificado»', $pp > 0 && $q('SELECT state FROM projects WHERE id = ?', [$pp])->fetchColumn() === 'planned', snippet($admin));
$admin->get('/projects?state=planned');
check('El listado filtra por «Planificado» y lo muestra con su estado', $admin->status === 200 && str_contains($admin->body, 'Proyecto Planificado') && str_contains($admin->body, '>Planificado<'), snippet($admin));
$admin->get("/projects/$pp/settings");
check('Los ajustes del proyecto ofrecen los cuatro estados', str_contains($admin->body, 'value="planned"') && str_contains($admin->body, 'value="on_hold"') && str_contains($admin->body, 'value="completed"'));
$admin->api('PATCH', "/api/projects/$pp", ['state' => 'active']);
check('Pasa de «Planificado» a «Activo» y queda en el historial', $admin->status === 200 && $q('SELECT state FROM projects WHERE id = ?', [$pp])->fetchColumn() === 'active'
    && (bool)$q("SELECT 1 FROM activity WHERE project_id = ? AND summary LIKE '%estado a Activo%'", [$pp])->fetchColumn(), snippet($admin));
$admin->api('PATCH', "/api/projects/$pp", ['state' => 'archived']);
check('Un estado que no existe se rechaza (archivar sigue siendo aparte)', $admin->status === 422, snippet($admin));
$admin->api('POST', '/api/projects', ['name' => 'Proyecto raro', 'state' => 'completed']);
check('Al crear solo se admite «Activo» o «Planificado»', $admin->status === 422, snippet($admin));
$ciroC->api('PATCH', "/api/projects/$pp", ['state' => 'on_hold']);
check('Quien no participa no puede cambiar el estado manipulando la petición', in_array($ciroC->status, [403, 404], true) && $q('SELECT state FROM projects WHERE id = ?', [$pp])->fetchColumn() === 'active', snippet($ciroC));

// ---- Late tasks
$admin->api('POST', "/api/projects/$pp/members", ['user_id' => $ana, 'role_id' => $role($ws1, 'project', 'contributor')]);
$admin->api('POST', "/api/projects/$pp/members", ['user_id' => $beto, 'role_id' => $role($ws1, 'project', 'contributor')]);
$stProg = (int)$q("SELECT id FROM statuses WHERE project_id = ? AND name = 'En progreso'", [$pp])->fetchColumn();
$admin->api('POST', "/api/projects/$pp/tasks", ['title' => 'Informe atrasado', 'assignee_id' => $ana, 'due_date' => $d(-3), 'status_id' => $stProg]);
$late = $admin->json()['task'] ?? [];
$lateId = (int)($late['id'] ?? 0);
$anaC->get('/my-work?tab=overdue');
check('Una tarea vencida aparece en «Vencidas» con los días de retraso', $anaC->status === 200 && str_contains($anaC->body, 'Informe atrasado') && str_contains($anaC->body, '3 días de retraso'), snippet($anaC));
check('…y conserva su estado de trabajo («En progreso» + atrasada, sin estado nuevo)', (int)$q('SELECT status_id FROM tasks WHERE id = ?', [$lateId])->fetchColumn() === $stProg);
$admin->get("/projects/$pp/board");
check('La tarjeta del tablero muestra «3 días de retraso»', str_contains($admin->body, 'class="late-tag late"') && str_contains($admin->body, '3 días de retraso'));
$anaC->get('/t/' . ($late['key'] ?? ''));
check('La ficha de la tarea muestra el retraso junto a la fecha', $anaC->status === 200 && str_contains($anaC->body, '3 días de retraso') && str_contains($anaC->body, 'vencía el'), snippet($anaC));

// ---- Finished after the due date
$anaC->api('POST', "/api/tasks/$lateId/done", ['done' => true]);
$row = $q('SELECT completed_at, completed_due_date, due_date, status_id FROM tasks WHERE id = ?', [$lateId])->fetch(PDO::FETCH_ASSOC);
check('Al finalizarla se guardan la fecha real y la fecha límite que tenía', $anaC->status === 200 && $row['completed_at'] && $row['completed_due_date'] === $d(-3), json_encode($row));
$admin->api('PATCH', "/api/tasks/$lateId", ['due_date' => $d(5)]);
check('Cambiar la fecha después no borra la fecha límite original', $q('SELECT completed_due_date FROM tasks WHERE id = ?', [$lateId])->fetchColumn() === $d(-3));
$anaC->get('/my-work?tab=done');
check('«Finalizadas» la marca «Fuera de plazo · 3 días» y ya no está entre las pendientes', str_contains($anaC->body, 'Fuera de plazo · 3 días'), snippet($anaC));
$anaC->get('/my-work?tab=overdue');
check('…y deja de estar en «Vencidas»', !str_contains($anaC->body, 'Informe atrasado'));
$anaC->get('/t/' . ($late['key'] ?? ''));
check('La ficha dice «fuera de plazo (fecha límite original: …)»', str_contains($anaC->body, 'fuera de plazo (fecha límite original'), snippet($anaC));
$anaC->api('POST', "/api/tasks/$lateId/done", ['done' => false]);
$row = $q('SELECT completed_at, completed_due_date FROM tasks WHERE id = ?', [$lateId])->fetch(PDO::FETCH_ASSOC);
check('Reabrirla la deja pendiente otra vez (sin fecha de finalización)', $anaC->status === 200 && $row['completed_at'] === null && $row['completed_due_date'] === null, json_encode($row));

$admin->api('POST', "/api/projects/$pp/tasks", ['title' => 'Entrega puntual', 'assignee_id' => $ana, 'due_date' => $d(2)]);
$okId = (int)($admin->json()['task']['id'] ?? 0);
$anaC->api('POST', "/api/tasks/$okId/done", ['done' => true]);
$anaC->get('/my-work?tab=done');
check('Una tarea finalizada antes de su fecha dice «A tiempo»', str_contains($anaC->body, 'A tiempo'), snippet($anaC));

$stDone = (int)$q("SELECT id FROM statuses WHERE project_id = ? AND category = 'done' ORDER BY position LIMIT 1", [$pp])->fetchColumn();
$admin->api('POST', "/api/projects/$pp/tasks", ['title' => 'Movida en el tablero', 'due_date' => $d(-1)]);
$mvId = (int)($admin->json()['task']['id'] ?? 0);
$admin->api('POST', "/api/tasks/$mvId/move", ['status_id' => $stDone, 'after_id' => null]);
check('Mover la tarjeta a la columna final también guarda la fecha límite que tenía', $q('SELECT completed_due_date FROM tasks WHERE id = ?', [$mvId])->fetchColumn() === $d(-1), snippet($admin));

// ---- «Hoy» in Mi trabajo
$admin->api('POST', "/api/projects/$pp/tasks", ['title' => 'Vence hoy mismo', 'assignee_id' => $ana, 'due_date' => $d(0)]);
$admin->api('POST', "/api/projects/$pp/tasks", ['title' => 'Vence mañana', 'assignee_id' => $ana, 'due_date' => $d(1)]);
$anaC->get('/my-work?tab=today');
check('«Hoy» muestra lo que vence hoy, y no lo de mañana ni lo vencido', $anaC->status === 200 && str_contains($anaC->body, 'Vence hoy mismo') && !str_contains($anaC->body, 'Vence mañana') && !str_contains($anaC->body, 'Informe atrasado'), snippet($anaC));
$anaC->get('/my-work?tab=upcoming');
check('«Próximas» empieza mañana: la de hoy no aparece en las dos', str_contains($anaC->body, 'Vence mañana') && !str_contains($anaC->body, 'Vence hoy mismo'), snippet($anaC));

// ---- Responsibility changes
$admin->api('POST', "/api/projects/$pp/tasks", ['title' => 'Cambio de manos', 'assignee_id' => $ana]);
$hand = $admin->json()['task'] ?? [];
$admin->api('PATCH', '/api/tasks/' . (int)($hand['id'] ?? 0), ['assignee_id' => $beto]);
$msgAna = (string)$q("SELECT GROUP_CONCAT(message SEPARATOR ' | ') FROM notifications WHERE user_id = ? AND task_id = ?", [$ana, (int)($hand['id'] ?? 0)])->fetchColumn();
$msgBeto = (string)$q("SELECT GROUP_CONCAT(message SEPARATOR ' | ') FROM notifications WHERE user_id = ? AND task_id = ?", [$beto, (int)($hand['id'] ?? 0)])->fetchColumn();
check('Quien deja de ser responsable recibe «Ya no eres responsable de …»; quien la recibe, «Te asignaron …»', str_contains($msgAna, 'Ya no eres responsable de') && str_contains($msgBeto, 'Te asignaron'), $msgAna . ' / ' . $msgBeto);
$admin->api('PATCH', '/api/tasks/' . (int)($hand['id'] ?? 0), ['assignee_ids' => [$ana]]);
$n = (int)$q("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND task_id = ? AND message LIKE 'Ya no eres responsable%'", [$beto, (int)($hand['id'] ?? 0)])->fetchColumn();
check('Con varios responsables también: quien sale de la lista recibe un solo aviso', $n === 1, snippet($admin));
$overdueAdmins = (int)$q("SELECT COUNT(*) FROM notifications n JOIN tasks t ON t.id = n.task_id WHERE t.project_id = ? AND n.type = 'overdue' AND n.user_id NOT IN (?, ?)", [$pp, $ana, $beto])->fetchColumn();
check('Los retrasos no se avisan a la administración (solo a la persona responsable)', $overdueAdmins === 0);

// ---- Export / import keep the original due date of finished tasks
$admin->api('POST', "/api/tasks/$lateId/done", ['done' => true]);
$admin->get("/projects/$pp/export.json");
$exp = json_decode($admin->body, true) ?: [];
$found = array_values(array_filter($exp['tasks'] ?? [], fn($x) => ($x['title'] ?? '') === 'Informe atrasado'));
check('Exportar el proyecto incluye la fecha límite con la que se finalizó', ($found[0]['completed_due_date'] ?? null) === $d(5), json_encode($found[0] ?? []));
