<?php
declare(strict_types=1);

/*
 * Borealis 1.8 — integration checks. Included by run.php (same globals: $admin, $q, $pdo, $role,
 * $ws1, $mkUser, $mkProject, $BASE, check(), group(), snippet()).
 * Outside services (push service, Discord, GitHub) are local stand-ins: tests/fake-services/server.php.
 */

$root = dirname(__DIR__, 2);
$today = date('Y-m-d');
$plus = fn(int $d) => date('Y-m-d', strtotime("$today $d day"));
$cnt = fn(string $sql, array $p = []) => (int)$q($sql, $p)->fetchColumn();
$notes = fn(int $uid, string $type) => $cnt('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = ?', [$uid, $type]);

// ---------------------------------------------------------------- outside services (stand-ins)
$fsPort = (int)(getenv('BOREALIS_FAKE_SERVICES_PORT') ?: 8098);
$fs = "http://127.0.0.1:$fsPort";
$fsLog = sys_get_temp_dir() . '/borealis-fake-services-' . getmypid() . '.jsonl';
@unlink($fsLog);
$fsProc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$fsPort", __DIR__ . '/../fake-services/server.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $fsPipes, null, ['FAKE_SERVICES_LOG' => $fsLog, 'PATH' => getenv('PATH')]);
usleep(400000);
$fsGet = function () use ($fs): array {
    $r = @file_get_contents("$fs/_log");
    return is_string($r) ? (json_decode($r, true) ?: []) : [];
};
$fsReset = function () use ($fs): void { $ch = curl_init("$fs/_reset"); curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true]); curl_exec($ch); curl_close($ch); };
$fsWait = function (callable $pred, float $secs = 3.0) use ($fsGet): array {
    $end = microtime(true) + $secs;
    do { $rows = array_values(array_filter($fsGet(), $pred)); if ($rows) return $rows; usleep(100000); } while (microtime(true) < $end);
    return [];
};
$cfgPath18 = $root . '/config/config.php';
$cfgOrig18 = (string)file_get_contents($cfgPath18);
$cfg18 = require $cfgPath18;
$cfg18['push'] = ['test_hosts' => "127.0.0.1:$fsPort", 'subject' => 'mailto:pruebas@example.test'];
$cfg18['integrations'] = ['discord_test_prefix' => "$fs/discord/api/webhooks/", 'github_api' => "$fs/gh"];
file_put_contents($cfgPath18, "<?php\nreturn " . var_export($cfg18, true) . ";\n");
usleep(3200000); // opcache revalidation

// ---------------------------------------------------------------- people and project
group('1.8 · Preparación');
[$dani, $daniC] = $mkUser('Dani Personal', 'dani.personal@example.test', 'member');
[$eli, $eliC] = $mkUser('Eli Equipo', 'eli.equipo@example.test', 'member');
[$fer, $ferC] = $mkUser('Fer Fuera', 'fer.fuera@example.test', 'member');
$admin->api('POST', '/api/projects', ['name' => 'Proyecto Dieciocho', 'project_key' => 'DIE']);
$p18 = (int)($admin->json()['id'] ?? 0);
$admin->api('POST', "/api/projects/$p18/modules", ['module' => 'sprints', 'enabled' => true]);
foreach ([$dani, $eli] as $uid) $admin->api('POST', "/api/projects/$p18/members", ['user_id' => $uid, 'role_id' => $role($ws1, 'project', 'contributor')]);
$admin->api('POST', '/api/teams', ['name' => 'Equipo Dieciocho']);
$team18 = (int)($admin->json()['id'] ?? 0);
foreach ([$dani, $eli] as $uid) $admin->api('POST', "/api/teams/$team18/members", ['user_id' => $uid, 'team_role' => 'member']);
check('Tres personas, un proyecto con sprints (Dani y Eli) y un equipo (Dani y Eli); Fer queda fuera', $dani && $eli && $fer && $p18 && $team18
    && $cnt('SELECT COUNT(*) FROM team_members WHERE team_id = ? AND user_id IN (?, ?)', [$team18, $dani, $eli]) === 2 && !$cnt('SELECT COUNT(*) FROM team_members WHERE team_id = ? AND user_id = ?', [$team18, $fer]));
$daniC->get('/calendar');
$eliC->get('/calendar');
$ferC->get('/calendar');
$calDani = $cnt("SELECT id FROM calendars WHERE owner_id = ? AND kind = 'personal' AND source = 'internal' ORDER BY id LIMIT 1", [$dani]);

// ================================================================ calendars
group('1.8 · Calendario personal y de equipo');
check('Cada persona tiene su calendario personal', $calDani > 0);
$daniC->api('POST', '/api/events', ['calendar_id' => $calDani, 'kind' => 'event', 'title' => 'Cita médica privada', 'start_date' => $today, 'start_time' => '09:00', 'end_time' => '09:30']);
$evPriv = (int)($daniC->json()['id'] ?? 0);
$daniC->api('POST', '/api/calendars', ['name' => 'Calendario de otro equipo', 'kind' => 'shared', 'team_id' => $cnt('SELECT id FROM teams WHERE workspace_id = ? AND id <> ? ORDER BY id LIMIT 1', [$ws1, $team18])]);
check('No se puede compartir un calendario con un equipo del que no formas parte', $daniC->status === 422 && isset($daniC->json()['errors']['team_id']), snippet($daniC));
$daniC->api('POST', '/api/calendars', ['name' => 'Calendario Dieciocho', 'kind' => 'shared', 'default_access' => 'edit', 'team_id' => $team18]);
$calTeam18 = (int)($daniC->json()['id'] ?? 0);
check('Un calendario de equipo queda ligado a su equipo', $daniC->status === 200 && $cnt('SELECT team_id FROM calendars WHERE id = ?', [$calTeam18]) === $team18, snippet($daniC));
$daniC->api('POST', '/api/calendars', ['name' => 'Personal con equipo', 'kind' => 'personal', 'team_id' => $team18]);
$calOdd = (int)($daniC->json()['id'] ?? 0);
check('Un calendario personal nunca queda ligado a un equipo (el dato se ignora)', $calOdd && $q('SELECT team_id FROM calendars WHERE id = ?', [$calOdd])->fetchColumn() === null);
$daniC->api('DELETE', "/api/calendars/$calOdd");
$daniC->api('POST', '/api/events', ['calendar_id' => $calTeam18, 'kind' => 'meeting', 'title' => 'Reunión del equipo dieciocho', 'start_date' => $today, 'start_time' => '11:00', 'end_time' => '12:00']);
$evTeam = (int)($daniC->json()['id'] ?? 0);
$eliC->get('/calendar?scope=both&date=' . $today . '&view=week');
$eliSees = str_contains($eliC->body, 'Reunión del equipo dieciocho');
check('El equipo ve la reunión del calendario de equipo', $evTeam && $eliSees, snippet($eliC));
check('…pero no la cita del calendario personal de Dani', !str_contains($eliC->body, 'Cita médica privada'));
$eliC->api('GET', "/api/events/$evPriv");
$s1 = $eliC->status;
$eliC->api('PATCH', "/api/events/$evPriv", ['title' => 'Cambiada']);
check('Ni abriéndola ni cambiándola por la API', in_array($s1, [403, 404], true) && in_array($eliC->status, [403, 404], true) && $q('SELECT title FROM events WHERE id = ?', [$evPriv])->fetchColumn() === 'Cita médica privada', "$s1 {$eliC->status}");
$ferC->get('/calendar?scope=both&date=' . $today . '&view=week');
$ferBody = $ferC->body;
$ferC->api('GET', "/api/events/$evTeam");
check('Quien no es del equipo no ve el calendario de equipo ni su reunión', !str_contains($ferBody, 'Calendario Dieciocho') && !str_contains($ferBody, 'Reunión del equipo dieciocho') && in_array($ferC->status, [403, 404], true), (string)$ferC->status);
$ferC->api('POST', '/api/events', ['calendar_id' => $calTeam18, 'title' => 'Intruso', 'start_date' => $today, 'start_time' => '13:00', 'end_time' => '14:00']);
check('…ni puede crear eventos en él', in_array($ferC->status, [403, 404, 422], true) && !$cnt("SELECT COUNT(*) FROM events WHERE title = 'Intruso'"), (string)$ferC->status);
$daniC->get('/calendar?scope=personal&date=' . $today . '&view=week');
$pBody = $daniC->body;
check('Vista «Personal»: solo los calendarios personales (con candado) y sus eventos', str_contains($pBody, 'Cita médica privada') && !str_contains($pBody, 'Reunión del equipo dieciocho') && str_contains($pBody, 'cal-group--personal') && !str_contains($pBody, 'cal-group--team') && str_contains($pBody, 'is-personal'), snippet($daniC));
$daniC->get('/calendar?scope=team&date=' . $today . '&view=week');
check('Vista «Equipo»: solo los de equipo', str_contains($daniC->body, 'Reunión del equipo dieciocho') && !str_contains($daniC->body, 'Cita médica privada') && str_contains($daniC->body, 'cal-group--team') && !str_contains($daniC->body, 'cal-group--personal'), snippet($daniC));
$daniC->get('/calendar?date=' . $today . '&view=week');
check('La elección se recuerda al volver al calendario', str_contains($daniC->body, 'Reunión del equipo dieciocho') && !str_contains($daniC->body, 'Cita médica privada'));
$daniC->get('/calendar?scope=both&date=' . $today . '&view=week');
check('Vista «Ambos»: los dos a la vez, cada uno con su color', str_contains($daniC->body, 'Reunión del equipo dieciocho') && str_contains($daniC->body, 'Cita médica privada') && str_contains($daniC->body, 'cal-group--personal') && str_contains($daniC->body, 'cal-group--team') && str_contains($daniC->body, 'data-tour="cal-scope"'));
check('Al crear un evento se elige el calendario, agrupado en «Personal» y «De equipo»', str_contains($daniC->body, '<optgroup label="Personal (solo tú)">') && str_contains($daniC->body, '<optgroup label="De equipo">'));
$admin->api('PATCH', "/api/projects/$p18", ['start_date' => $today, 'due_date' => $plus(3)]);
$daniC->get('/calendar?scope=both&view=month&date=' . $plus(3));
check('Los hitos del proyecto (inicio y entrega) aparecen en el calendario de equipo', str_contains($daniC->body, 'Entrega: Proyecto Dieciocho'), snippet($daniC));
$daniC->api('POST', '/api/calendar-layers', ['layer' => 'milestones', 'shown' => false]);
$daniC->get('/calendar?scope=both&view=month&date=' . $plus(3));
$hid = !str_contains($daniC->body, 'Entrega: Proyecto Dieciocho');
$daniC->api('POST', '/api/calendar-layers', ['layer' => 'milestones', 'shown' => true]);
check('…y se pueden ocultar como capa', $hid);
$ferC->get('/calendar?scope=both&view=month&date=' . $plus(3));
check('Quien no participa en el proyecto no ve sus hitos', !str_contains($ferC->body, 'Proyecto Dieciocho'));

// ================================================================ notifications
group('1.8 · Centro de notificaciones y preferencias');
$daniC->get('/profile');
check('Perfil › Notificaciones: categorías, anticipación y móvil', str_contains($daniC->body, 'id="notificaciones"') && str_contains($daniC->body, 'name="before[]"') && str_contains($daniC->body, 'data-push-box'), snippet($daniC));
$daniC->api('PATCH', '/api/profile/notifications', ['in_app' => ['due'], 'lead' => 9]);
check('Una anticipación inventada se rechaza', $daniC->status === 422 && isset($daniC->json()['errors']['lead']));
$allBut = fn(array $off) => array_values(array_diff(array_keys(['assigned' => 1, 'due' => 1, 'overdue' => 1, 'date' => 1, 'calendar' => 1, 'sprint' => 1, 'project' => 1, 'comments' => 1, 'capacity' => 1]), $off));
$daniC->api('PATCH', '/api/profile/notifications', ['in_app' => $allBut(['assigned']), 'lead' => 3, 'push_cats' => $allBut([])]);
$admin->api('POST', "/api/projects/$p18/tasks", ['title' => 'Asignación silenciada', 'assignee_id' => $dani]);
check('Si apaga «Nuevas asignaciones», no recibe ese aviso', $daniC->status === 200 && $notes($dani, 'assigned') === 0, snippet($daniC));
$daniC->api('PATCH', '/api/profile/notifications', ['in_app' => $allBut([]), 'lead' => 3, 'push_cats' => $allBut([])]);
$admin->api('POST', "/api/projects/$p18/tasks", ['title' => 'Asignación avisada', 'assignee_id' => $dani]);
check('Al encenderla vuelve a recibirlo', $notes($dani, 'assigned') === 1);
$task18 = function (string $title, array $extra = []) use ($admin, $p18): array {
    $admin->api('POST', "/api/projects/$p18/tasks", ['title' => $title] + $extra);
    return $admin->json()['task'] ?? [];
};
$tSoon = $task18('Vence en tres días', ['assignee_id' => $dani, 'due_date' => $plus(3)]);
$tFar = $task18('Vence en cinco días', ['assignee_id' => $dani, 'due_date' => $plus(5)]);
$tLate = $task18('Ya vencida', ['assignee_id' => $dani, 'due_date' => $plus(-2)]);
$tDoneLate = $task18('Vencida pero terminada', ['assignee_id' => $dani, 'due_date' => $plus(-2)]);
$admin->api('POST', '/api/tasks/' . ($tDoneLate['id'] ?? 0) . '/done', ['done' => true]);
$daniC->get('/');
$daniC->get('/');
$dueOf = fn(array $t, string $type) => $cnt('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = ? AND task_id = ?', [$dani, $type, (int)($t['id'] ?? 0)]);
check('«Tareas por vencer» respeta la anticipación elegida (3 días: sí; 5 días: todavía no)', $dueOf($tSoon, 'due') === 1 && $dueOf($tFar, 'due') === 0);
check('Una tarea vencida avisa una sola vez, aunque se abra Borealis muchas veces', $dueOf($tLate, 'overdue') === 1);
check('Una tarea completada no avisa', $dueOf($tDoneLate, 'overdue') === 0 && $dueOf($tDoneLate, 'due') === 0);
$admin->api('PATCH', '/api/tasks/' . ($tFar['id'] ?? 0), ['due_date' => $plus(6)]);
$admin->api('PATCH', '/api/tasks/' . ($tFar['id'] ?? 0), ['title' => 'Vence en seis días']);
check('Cambiar la fecha de entrega avisa al responsable (una vez por cambio)', $dueOf($tFar, 'date') === 1 && str_contains((string)$q("SELECT message FROM notifications WHERE user_id = ? AND type = 'date' ORDER BY id DESC LIMIT 1", [$dani])->fetchColumn(), 'pasó del'));
$admin->api('PATCH', '/api/tasks/' . ($tDoneLate['id'] ?? 0), ['due_date' => $plus(1)]);
check('…pero no si la tarea ya está completada', $dueOf($tDoneLate, 'date') === 0);
$admin->api('PATCH', "/api/projects/$p18", ['due_date' => $plus(4)]);
check('Un cambio importante del proyecto (su entrega) avisa a quienes participan, una vez por cambio', $cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'project' AND project_id = ? AND message LIKE '%entrega es ahora%'", [$eli, $p18]) === 2, (string)$q("SELECT GROUP_CONCAT(message) FROM notifications WHERE user_id = ? AND project_id = ?", [$eli, $p18])->fetchColumn());
$daniC->get('/notifications?cat=date');
check('El centro de notificaciones filtra por tipo', str_contains($daniC->body, 'pasó del') && !str_contains($daniC->body, 'Asignación avisada') && str_contains($daniC->body, 'aria-current="page">Cambios de fecha de entrega'), snippet($daniC));
$daniC->get('/');
check('La campana muestra cuántas hay sin leer', (bool)preg_match('/data-tour="notifications"/', $daniC->body) && Notifications18Unread($q, $dani) > 0);
$daniC->api('POST', '/api/notifications/read-all');
check('Marcar todo como leído las deja en cero', $cnt('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$dani]) === 0);

// ================================================================ sprints
group('1.8 · Sprints en dos etapas, calendario y avisos');
$admin->api('POST', "/api/projects/$p18/sprints", ['name' => 'Sprint Diecio', 'goal' => 'Primera versión usable', 'start_date' => $plus(1), 'end_date' => $plus(10)]);
$sp18 = (int)($admin->json()['id'] ?? 0);
check('Etapa 1: basta con nombre, objetivo y fechas estimadas', $admin->status === 200 && $sp18 > 0, snippet($admin));
$admin->get('/sprints');
check('«Sprints» reúne los sprints de los proyectos, con lo que falta planificar', $admin->status === 200 && str_contains($admin->body, 'Sprint Diecio') && str_contains($admin->body, 'Por planificar'), snippet($admin));
$ferC->get('/sprints');
check('…y solo de los proyectos donde participas', !str_contains($ferC->body, 'Sprint Diecio'));
$admin->get("/projects/$p18/sprints?sprint=$sp18");
check('Etapa 2: planificación con tareas, responsables, estimación y prioridad', str_contains($admin->body, 'id="sprint-plan"'), snippet($admin));
$admin->api('POST', "/api/projects/$p18/tasks", ['title' => 'Diseñar pantalla de inicio', 'sprint_id' => $sp18, 'assignee_id' => $eli, 'estimate_hours' => '6', 'priority' => 'medium']);
$tPlan = $admin->json()['task'] ?? [];
$admin->api('PATCH', '/api/tasks/' . ($tSoon['id'] ?? 0), ['sprint_id' => $sp18]);
$admin->get("/projects/$p18/sprints?sprint=$sp18");
check('Las tareas añadidas aparecen en la planificación con sus horas', str_contains($admin->body, 'Diseñar pantalla de inicio') && str_contains($admin->body, 'Vence en tres días') && $q('SELECT estimate_hours FROM tasks WHERE id = ?', [(int)($tPlan['id'] ?? 0)])->fetchColumn() == 6);
$daniC->get('/calendar?scope=both&view=month&date=' . $plus(1));
check('El inicio previsto del sprint está en el calendario', str_contains($daniC->body, 'Inicio previsto: Sprint Diecio'), snippet($daniC));
$daniC->get('/');
check('Un sprint que empieza mañana avisa a quienes participan', $cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'sprint' AND dedupe_key LIKE ?", [$dani, "sp-soon:$sp18:%"]) === 1);

// Discord, configured before the sprint starts.
$admin->api('POST', "/api/projects/$p18/integrations/discord", ['webhook' => 'https://example.com/hook', 'events' => ['sprint_start']]);
check('Discord: una dirección que no es un webhook se rechaza', $admin->status === 422 && isset($admin->json()['errors']['webhook']));
$hook = "$fs/discord/api/webhooks/1234567/token-secreto-abc";
$daniC->api('POST', "/api/projects/$p18/integrations/discord", ['webhook' => $hook, 'events' => ['sprint_start']]);
check('Discord: solo quien administra el proyecto lo configura', $daniC->status === 403, (string)$daniC->status);
$admin->api('POST', "/api/projects/$p18/integrations/discord", ['webhook' => $hook, 'events' => ['sprint_start', 'sprint_close', 'sprint_overdue', 'milestone', 'task_due']]);
$secret = (string)$q("SELECT secret FROM project_integrations WHERE project_id = ? AND provider = 'discord'", [$p18])->fetchColumn();
check('Discord: el webhook se guarda cifrado, nunca en claro', $admin->status === 200 && $secret !== '' && !str_contains($secret, 'token-secreto'), snippet($admin));
$fsReset();
$admin->api('POST', "/api/projects/$p18/integrations/discord/test");
$got = $fsWait(fn($r) => str_starts_with($r['path'], '/discord/'));
check('Discord: «Enviar prueba» publica en el canal', $admin->status === 200 && $got && str_contains(base64_decode($got[0]['body']), 'Proyecto Dieciocho'), snippet($admin));
$admin->get('/integrations');
check('«Todo a mano» muestra el estado de Discord sin enseñar el webhook', $admin->status === 200 && str_contains($admin->body, 'Webhook …4567') && !str_contains($admin->body, 'token-secreto'), snippet($admin));

$fsReset();
$admin->api('POST', "/api/sprints/$sp18/start");
$startNote = $cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'sprint' AND dedupe_key LIKE ?", [$eli, "sp-start:%"]);
$disc = $fsWait(fn($r) => str_starts_with($r['path'], '/discord/'));
$discBody = $disc ? (json_decode(base64_decode($disc[0]['body']), true) ?: []) : [];
check('Iniciar el sprint avisa al equipo del proyecto', $admin->status === 200 && $startNote === 1, snippet($admin));
check('…y lo publica una vez en Discord, sin menciones masivas', count($disc) === 1 && str_contains((string)($discBody['content'] ?? ''), 'Sprint Diecio') && ($discBody['allowed_mentions']['parse'] ?? null) === [], json_encode($discBody));
$q('UPDATE sprints SET end_date = ? WHERE id = ?', [$plus(-1), $sp18]);
$eliC->get('/');
$eliC->get('/');
check('Al llegar la fecha de fin, el sprint NO se cierra solo', $q('SELECT state FROM sprints WHERE id = ?', [$sp18])->fetchColumn() === 'active' && $q('SELECT completed_at FROM sprints WHERE id = ?', [$sp18])->fetchColumn() === null);
check('…se avisa una vez que falta revisarlo y cerrarlo', $cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND dedupe_key LIKE ?", [$eli, "sp-over:$sp18:%"]) === 1);
$eliC->get('/sprints');
$pc = str_contains($eliC->body, 'Pendiente de cierre');
$eliC->get('/calendar?scope=both&view=month&date=' . $plus(-1));
check('…y se ve como «pendiente de cierre» en Sprints y en el calendario', $pc && str_contains($eliC->body, 'Fin (sin cerrar): Sprint Diecio'), snippet($eliC));
$admin->get("/projects/$p18/sprints?sprint=$sp18");
check('La página del sprint explica que no se cierra solo', str_contains($admin->body, 'No se cierra solo'));
$fsReset();
$admin->api('POST', '/api/tasks/' . ($tPlan['id'] ?? 0) . '/done', ['done' => true]);
$admin->api('POST', "/api/sprints/$sp18/complete", ['move_to' => '', 'result_notes' => 'Cerrado a mano']);
$closeMsg = (string)$q("SELECT message FROM notifications WHERE user_id = ? AND dedupe_key LIKE 'sp-close:%' ORDER BY id DESC LIMIT 1", [$eli])->fetchColumn();
check('Cerrarlo es una acción de una persona: avisa con el resumen de completadas y pendientes', $admin->status === 200 && $q('SELECT state FROM sprints WHERE id = ?', [$sp18])->fetchColumn() === 'completed' && (bool)preg_match('/1 de 2/', $closeMsg), $closeMsg . ' ' . snippet($admin));
check('…y se comparte en Discord', (bool)$fsWait(fn($r) => str_starts_with($r['path'], '/discord/') && str_contains(base64_decode($r['body']), 'Sprint Diecio')));
$eliC->get('/calendar?scope=both&view=month&date=' . $today);
check('El día del cierre real queda marcado en el calendario', str_contains($eliC->body, 'Cerrado: Sprint Diecio'), snippet($eliC));

// Discord failing never stops Borealis.
$admin->api('POST', "/api/projects/$p18/integrations/discord", ['webhook' => "$fs/discord/api/webhooks/1234567/broken", 'events' => ['sprint_start', 'milestone']]);
$admin->api('POST', "/api/projects/$p18/integrations/discord/test");
check('Discord: si el webhook ya no existe se explica cómo arreglarlo', $admin->status === 422 && str_contains((string)json_encode($admin->json(), JSON_UNESCAPED_UNICODE), 'no reconoce el webhook'), snippet($admin));
$admin->api('PATCH', "/api/projects/$p18", ['due_date' => $plus(8)]);
check('…y un fallo de Discord no impide trabajar (el cambio se guarda)', $admin->status === 200 && $q('SELECT due_date FROM projects WHERE id = ?', [$p18])->fetchColumn() === $plus(8), snippet($admin));

// ================================================================ «Todo a mano»
group('1.8 · «Todo a mano»: enlaces e integraciones');
$admin->api('POST', "/api/projects/$p18/links", ['title' => 'Carpeta del proyecto', 'url' => 'https://drive.google.com/drive/folders/abc123']);
$link18 = (int)($admin->json()['id'] ?? 0);
check('Un enlace de Drive se reconoce como Drive', $link18 && $q('SELECT kind FROM project_links WHERE id = ?', [$link18])->fetchColumn() === 'drive', snippet($admin));
$admin->api('POST', "/api/projects/$p18/links", ['title' => 'Malo', 'url' => 'javascript:alert(1)']);
check('Solo se aceptan direcciones web (https://)', $admin->status === 422);
$eliC->api('POST', "/api/projects/$p18/links", ['title' => 'Mío', 'url' => 'https://example.com']);
check('Quien solo colabora no cambia los enlaces del proyecto', $eliC->status === 403, (string)$eliC->status);
$eliC->get('/integrations');
check('…pero sí los ve, marcados como «Enlace directo», distintos de las integraciones', $eliC->status === 200 && str_contains($eliC->body, 'Carpeta del proyecto') && str_contains($eliC->body, 'Enlace directo') && str_contains($eliC->body, 'Integración'), snippet($eliC));
$ferC->get('/integrations');
$ferC->api('POST', "/api/projects/$p18/links", ['title' => 'Intruso', 'url' => 'https://example.com']);
check('Quien no participa no ve los enlaces del proyecto ni puede añadirlos', !str_contains($ferC->body, 'Carpeta del proyecto') && in_array($ferC->status, [403, 404], true), (string)$ferC->status);
$admin->api('POST', "/api/projects/$p18/integrations/github", ['repo' => 'https://github.com/acme/web']);
$gh = $admin->json()['github'] ?? [];
check('GitHub: un repositorio público muestra issues, pull requests y commits recientes', $admin->status === 200 && ($gh['name'] ?? '') === 'acme/web' && count($gh['issues'] ?? []) === 1 && ($gh['pulls'][0]['number'] ?? 0) === 13 && ($gh['commits'][0]['sha'] ?? '') === 'abcdef1', json_encode($gh));
$admin->api('POST', "/api/projects/$p18/integrations/github", ['repo' => 'acme/secret']);
check('GitHub: un repositorio privado sin token explica que falta un token', str_contains((string)($admin->json()['github']['error'] ?? ''), 'token'), snippet($admin));
$admin->api('POST', "/api/projects/$p18/integrations/github", ['repo' => 'acme/secret', 'token' => 'good-token']);
$ghSecret = (string)$q("SELECT secret FROM project_integrations WHERE project_id = ? AND provider = 'github'", [$p18])->fetchColumn();
check('…con un token de solo lectura funciona, y el token se guarda cifrado', ($admin->json()['github']['private'] ?? false) === true && empty($admin->json()['github']['error']) && $ghSecret !== '' && !str_contains($ghSecret, 'good-token'), snippet($admin));
$admin->api('POST', "/api/projects/$p18/integrations/github", ['repo' => 'no es un repo']);
check('…un nombre de repositorio inválido se rechaza', $admin->status === 422);
$cfg18b = $cfg18; $cfg18b['integrations']['github_api'] = 'http://127.0.0.1:9';
file_put_contents($cfgPath18, "<?php\nreturn " . var_export($cfg18b, true) . ";\n");
usleep(3200000);
$admin->api('POST', "/api/projects/$p18/integrations/github/refresh");
$offErr = (string)($admin->json()['github']['error'] ?? '');
$pages = [];
foreach (['/', '/calendar', '/tasks', '/sprints', '/integrations', "/projects/$p18", "/projects/$p18/sprints"] as $pg) { $admin->get($pg); $pages[$pg] = $admin->status; }
check('Sin conexión con GitHub ni Discord, Borealis sigue funcionando y lo dice', str_contains($offErr, 'Sin conexión') && count(array_filter($pages, fn($s) => $s === 200)) === count($pages), json_encode($pages) . ' ' . $offErr);
file_put_contents($cfgPath18, "<?php\nreturn " . var_export($cfg18, true) . ";\n");
usleep(3200000);
$admin->api('DELETE', "/api/projects/$p18/integrations/github");
$admin->api('DELETE', "/api/projects/$p18/integrations/discord");
$admin->api('DELETE', "/api/links/$link18");
$admin->get('/integrations');
check('Desconectar GitHub y Discord y quitar el enlace los borra (también los secretos)', !$cnt('SELECT COUNT(*) FROM project_integrations WHERE project_id = ?', [$p18]) && !$cnt('SELECT COUNT(*) FROM project_links WHERE id = ?', [$link18]) && $admin->status === 200);

// ================================================================ push
group('1.8 · Notificaciones en el móvil (Web Push)');
$daniC->api('GET', '/api/push/key');
$vapidPub = (string)($daniC->json()['key'] ?? '');
$b64d = fn(string $s) => (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
$b64 = fn(string $b) => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
check('El servidor tiene su clave pública VAPID (P-256)', strlen($b64d($vapidPub)) === 65 && $b64d($vapidPub)[0] === "\x04", $vapidPub);
check('La clave privada no se guarda en claro', !str_contains((string)@file_get_contents($root . '/storage/vapid.json'), 'PRIVATE KEY'));
// A «browser»: its own P-256 keys and auth secret, as PushManager.subscribe() creates them.
$ua = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
$uad = openssl_pkey_get_details($ua)['ec'];
$uaPub = "\x04" . str_pad($uad['x'], 32, "\0", STR_PAD_LEFT) . str_pad($uad['y'], 32, "\0", STR_PAD_LEFT);
$uaAuth = random_bytes(16);
$pem = fn(string $raw) => "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw), 64, "\n") . "-----END PUBLIC KEY-----\n";
$decrypt = function (string $body) use ($ua, $uaPub, $uaAuth, $pem): ?string {
    if (strlen($body) < 86) return null;
    $salt = substr($body, 0, 16); $idlen = ord($body[20]); $asPub = substr($body, 21, $idlen); $ct = substr($body, 21 + $idlen);
    $shared = openssl_pkey_derive($pem($asPub), $ua, 32);
    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPub . $asPub, $uaAuth);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $plain = openssl_decrypt(substr($ct, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($ct, -16));
    return $plain === false ? null : rtrim($plain, "\0\x02");
};
$daniC->api('POST', '/api/push/subscription', ['endpoint' => 'http://127.0.0.1:9/push/x', 'p256dh' => $b64($uaPub), 'auth' => $b64($uaAuth)]);
check('Una suscripción a un servicio que no es seguro (http) se rechaza', $daniC->status === 422);
$daniC->api('POST', '/api/push/subscription', ['endpoint' => "$fs/push/dani-movil", 'p256dh' => $b64($uaPub), 'auth' => 'corta']);
check('Unas claves incompletas se rechazan', $daniC->status === 422);
$daniC->api('POST', '/api/push/subscription', ['endpoint' => "$fs/push/dani-movil", 'p256dh' => $b64($uaPub), 'auth' => $b64($uaAuth), 'device' => 'Android · Chrome']);
check('Activar en el móvil guarda el dispositivo', $daniC->status === 200 && $cnt('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?', [$dani]) === 1, snippet($daniC));
$daniC->api('POST', '/api/push/subscription', ['endpoint' => "$fs/push/dani-movil", 'p256dh' => $b64($uaPub), 'auth' => $b64($uaAuth), 'device' => 'Android · Chrome']);
check('Activarlo otra vez no duplica el dispositivo', $cnt('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?', [$dani]) === 1);
$fsReset();
$daniC->api('POST', '/api/push/test');
$pm = $fsWait(fn($r) => $r['path'] === '/push/dani-movil');
$plain = $pm ? $decrypt(base64_decode($pm[0]['body'])) : null;
check('«Enviar una prueba» llega cifrada (aes128gcm) y se puede descifrar con las claves del navegador', $daniC->status === 200 && $pm && ($pm[0]['headers']['content-encoding'] ?? '') === 'aes128gcm' && str_contains((string)$plain, 'Las notificaciones funcionan'), snippet($daniC) . ' ' . (string)$plain);
$auth = $pm[0]['headers']['authorization'] ?? '';
$okSig = false; $claims = [];
if (preg_match('/^vapid t=([\w-]+)\.([\w-]+)\.([\w-]+), k=([\w-]+)$/', $auth, $am)) {
    $claims = json_decode($b64d($am[2]), true) ?: [];
    $raw = $b64d($am[3]);
    $int = fn(string $i) => (($i = ltrim($i, "\0")) === '' ? "\0" : (ord($i[0]) & 0x80 ? "\0" . $i : $i));
    $r = $int(substr($raw, 0, 32)); $s = $int(substr($raw, 32));
    $seq = "\x02" . chr(strlen($r)) . $r . "\x02" . chr(strlen($s)) . $s;
    $der = "\x30" . chr(strlen($seq)) . $seq;
    $okSig = $am[4] === $vapidPub && openssl_verify("$am[1].$am[2]", $der, $pem($b64d($am[4])), OPENSSL_ALGO_SHA256) === 1;
}
check('El mensaje va firmado con VAPID (ES256) para ese servicio, con caducidad', $okSig && ($claims['aud'] ?? '') === $fs && ($claims['exp'] ?? 0) > time() && ($claims['exp'] ?? 0) <= time() + 86400 && ($claims['sub'] ?? '') === 'mailto:pruebas@example.test', $auth);
$daniC->api('PATCH', '/api/profile/notifications', ['in_app' => $allBut([]), 'lead' => 1, 'push' => '1', 'push_cats' => $allBut(['comments'])]);
$fsReset();
$admin->api('POST', "/api/projects/$p18/tasks", ['title' => 'Revisar textos del móvil', 'assignee_id' => $dani]);
$pm = $fsWait(fn($r) => $r['path'] === '/push/dani-movil');
$plain = $pm ? $decrypt(base64_decode($pm[0]['body'])) : null;
$pj = json_decode((string)$plain, true) ?: [];
check('Una nueva asignación llega al móvil al momento, con su enlace', count($pm) === 1 && str_contains((string)($pj['body'] ?? ''), 'Revisar textos del móvil') && str_contains((string)($pj['url'] ?? ''), '/t/DIE-'), (string)$plain);
usleep(500000);
check('Al activar un dispositivo no le llega de golpe lo anterior, y cada aviso se envía una sola vez', count($fsGet()) === 1 && $cnt('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND pushed_at IS NULL', [$dani]) === 0);
$tPush = $task18('Comentada', ['assignee_id' => $eli]);
$fsReset();
$daniC->api('PATCH', '/api/profile/notifications', ['in_app' => $allBut([]), 'lead' => 1, 'push' => '1', 'push_cats' => $allBut(['assigned'])]);
$admin->api('POST', "/api/projects/$p18/tasks", ['title' => 'Solo en la app', 'assignee_id' => $dani]);
usleep(800000);
check('Una categoría apagada para el móvil no llega al móvil, pero sí a la campana', !$fsGet() && $cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND message LIKE '%Solo en la app%'", [$dani]) === 1);
// Reminder about a task that was completed before it could be sent.
$daniC->api('PATCH', '/api/profile/notifications', ['in_app' => $allBut([]), 'lead' => 1, 'push' => '1', 'push_cats' => $allBut([])]);
$tGone = $task18('Terminada antes del aviso', ['assignee_id' => $dani, 'due_date' => $plus(1)]);
$admin->api('POST', '/api/tasks/' . ($tGone['id'] ?? 0) . '/done', ['done' => true]);
$q('INSERT INTO notifications (user_id, workspace_id, type, message, url, task_id, dedupe_key, created_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())', [$dani, $ws1, 'due', 'DIE: Terminada antes del aviso vence mañana', '/t/x', (int)($tGone['id'] ?? 0), 'due:test-gone']);
$fsReset();
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/notify.php') . ' 2>&1', $nout, $ncode);
usleep(300000);
$sentGone = array_filter($fsGet(), fn($r) => str_contains((string)$decrypt(base64_decode($r['body'])), 'Terminada antes del aviso'));
check('Una tarea completada no se notifica al móvil', $ncode === 0 && !$sentGone, implode("\n", $nout));
// Someone who has not opened Borealis: the cron job creates their reminders.
$tCron = $task18('Vence mañana sin abrir Borealis', ['assignee_id' => $eli, 'due_date' => $plus(1)]);
$before = $cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND task_id = ? AND type = 'due'", [$eli, (int)($tCron['id'] ?? 0)]);
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/notify.php') . ' 2>&1', $nout2, $ncode2);
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/notify.php') . ' 2>&1', $nout3, $ncode3);
check('El cron (bin/notify.php) crea los recordatorios de quien no tiene Borealis abierto, sin repetirlos', $ncode2 === 0 && $ncode3 === 0 && $before === 0 && $cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND task_id = ? AND type = 'due'", [$eli, (int)($tCron['id'] ?? 0)]) === 1, implode("\n", array_merge($nout2, $nout3)));
$daniC->api('GET', '/api/push/pending');
check('Si un aviso llega sin contenido, el móvil puede pedir los últimos', $daniC->status === 200 && is_array($daniC->json()['items'] ?? null));
$daniC->api('POST', '/api/push/subscription', ['endpoint' => "$fs/push/gone", 'p256dh' => $b64($uaPub), 'auth' => $b64($uaAuth)]);
$daniC->api('POST', '/api/push/test');
check('Un dispositivo que se dio de baja (410) se quita solo', !$cnt('SELECT COUNT(*) FROM push_subscriptions WHERE endpoint LIKE ?', ['%/push/gone']) && $cnt('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?', [$dani]) === 1);
$daniC->api('POST', '/api/push/unsubscribe', ['endpoint' => "$fs/push/dani-movil"]);
check('Desactivar en el móvil quita el dispositivo', !$cnt('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?', [$dani]));
$anon18 = new Client($BASE);
$anon18->get('/sw.js');
$swType = (string)($anon18->headers['content-type'][0] ?? '');
$swOk = $anon18->status === 200 && str_contains($swType, 'javascript') && str_contains($anon18->body, "addEventListener('push'");
$anon18->get('/manifest.webmanifest');
check('Service worker y manifiesto (para instalar en iPhone/iPad y Android) se sirven', $swOk && $anon18->status === 200 && (json_decode($anon18->body, true)['display'] ?? '') === 'standalone', $swType);

// ================================================================ update
group('1.8 · Actualización');
$pdo->exec("DELETE FROM schema_migrations WHERE version = '010_v18'");
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/migrate.php') . ' --no-backup 2>&1', $m10, $m10c);
check('La migración 010 se puede repetir sin errores ni pérdida de datos', $m10c === 0 && str_contains(implode("\n", $m10), 'Aplicada: 010_v18') && $cnt('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$dani]) > 0, implode("\n", $m10));

file_put_contents($cfgPath18, $cfgOrig18);
usleep(3200000);
proc_terminate($fsProc);
@unlink($fsLog);

function Notifications18Unread(callable $q, int $uid): int
{
    return (int)$q('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$uid])->fetchColumn();
}
