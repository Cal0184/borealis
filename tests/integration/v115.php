<?php
declare(strict_types=1);

/*
 * Borealis 1.15 — refinamiento funcional: notices while a page stays open, the Master Log (sign-ins,
 * refused sign-ins, kinds, grouping, JSON, pages by id, the chain with results and after archiving),
 * favorites and recents, the grouped sidebar, saved views and the task views, followers and
 * decisions, automations (templates, no repeats, only notices, permissions) and the 1.14 → 1.15
 * upgrade. Included by run.php after v114.php (same globals).
 */

$root = dirname(__DIR__, 2);
$cnt = fn(string $sql, array $p = []) => (int)$q($sql, $p)->fetchColumn();
$yesterday = date('Y-m-d', strtotime($todayL . ' -1 day'));

// ================================================================ migration
group('1.15 · Migración 017');
$tables = $cnt("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('user_favorites','user_recent','saved_views','task_watchers','automation_rules','automation_runs')");
$cols = $cnt("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = 'activity' AND COLUMN_NAME = 'result') OR (TABLE_NAME = 'audit_chain' AND COLUMN_NAME IN ('base_id','base_hash')) OR (TABLE_NAME = 'comments' AND COLUMN_NAME = 'is_decision'))");
check('Migración 017: seis tablas nuevas y cuatro columnas', $tables === 6 && $cols === 4, "$tables / $cols");
$errors = [];
foreach (preg_split('/^-- @@\s*$/m', (string)file_get_contents($root . '/database/migrations/017_v115.sql')) as $stmt) {
    $sql = trim(preg_replace('/^--.*$/m', '', $stmt));
    if ($sql === '') continue;
    try { $q($sql); } catch (PDOException $e) { if (!in_array((int)($e->errorInfo[1] ?? 0), [1060, 1061, 1050], true)) $errors[] = $e->getMessage(); }
}
check('La migración 017 se puede repetir sin errores', !$errors, implode(' | ', $errors));
check('Versión 1.15.0', str_contains((string)file_get_contents($root . '/app/bootstrap.php'), "'1.15.0'"));

// ================================================================ notices while a page is open
group('1.15 · Avisos con la página abierta');
$admin->request('GET', '/api/notifications/status', null, ['Accept: application/json', 'X-Borealis-Background: 1']);
$st0 = $admin->json();
check('/api/notifications/status da los no leídos y el último aviso, sin lista la primera vez', $admin->status === 200 && isset($st0['unread'], $st0['latest']) && $st0['items'] === [], snippet($admin));
$tW = $task($pA, 'Aviso en vivo', ['assignee_id' => $juan]);
$juanC->request('GET', '/api/notifications/status?after=' . (int)$st0['latest'], null, ['Accept: application/json', 'X-Borealis-Background: 1']);
$stJ = $juanC->json();
check('…y lo nuevo desde «after» (para el aviso dentro de la página), con enlace que marca como leído', $juanC->status === 200 && (int)$stJ['unread'] >= 1 && count($stJ['items']) >= 1 && str_contains((string)$stJ['items'][0]['url'], '/notifications/') && str_contains((string)$stJ['items'][0]['body'], 'Aviso en vivo'), snippet($juanC));
$anon = new Client($BASE);
$anon->request('GET', '/api/notifications/status', null, ['Accept: application/json']);
check('Sin sesión responde 401 (la página muestra un aviso y no abandona el formulario)', $anon->status === 401);
$js = (string)file_get_contents($root . '/public/assets/js/notify-live.js') . (string)file_get_contents($root . '/public/sw.js') . (string)file_get_contents($root . '/public/assets/js/core.js');
check('Ninguna confirmación usa window.confirm (bloquea la página); el service worker pregunta por cambios sin guardar antes de seguir un aviso',
    !preg_match('/^(?!\s*(\/\/|\*)).*(window\.confirm\(|[^.\w]confirm\(\s*[\'"`])/m', (string)file_get_contents($root . '/public/assets/js/ui.js') . (string)file_get_contents($root . '/public/assets/js/drawer.js') . (string)file_get_contents($root . '/public/assets/js/core.js'))
    && str_contains($js, 'borealis-dirty?') && str_contains($js, 'X-Borealis-Background') && str_contains($js, 'session-ended'));
$sess = (string)file_get_contents($root . '/app/Core/Session.php');
check('La consulta en segundo plano no alarga la sesión', str_contains($sess, 'X-Borealis-Background') || str_contains($sess, 'HTTP_X_BOREALIS_BACKGROUND'));

// ================================================================ Master Log
group('1.15 · Registro maestro');
[$ines, $inesC] = $mkUser('Inés Acceso', 'ines.acceso@example.test', 'member');
$login = $q("SELECT * FROM activity WHERE workspace_id = ? AND action = 'login' AND user_id = ? ORDER BY id DESC LIMIT 1", [$ws1, $ines])->fetch(PDO::FETCH_ASSOC);
check('El inicio de sesión queda registrado (categoría seguridad, red abreviada, sin contraseña)', $login && $login['category'] === 'security' && str_contains((string)$login['after_data'], '.x') && !preg_match('/pass/i', (string)$login['after_data']), json_encode($login));
$bad = new Client($BASE);
$bad->login('ines.acceso@example.test', 'contraseña-equivocada');
$deny = $q("SELECT * FROM activity WHERE workspace_id = ? AND action = 'login_failed' AND user_id = ? ORDER BY id DESC LIMIT 1", [$ws1, $ines])->fetch(PDO::FETCH_ASSOC);
check('Un intento rechazado queda como «Rechazado», sin lo que se escribió', $deny && $deny['result'] === 'denied' && !str_contains((string)json_encode($deny), 'equivocada'), json_encode($deny));
$bad->login('nadie-existe@example.test', 'x');
check('Un correo que no existe no se registra (no hay a quién atribuirlo)', $cnt("SELECT COUNT(*) FROM activity WHERE summary LIKE '%nadie-existe%' OR data LIKE '%nadie-existe%'") === 0);
$inesC->form('/logout', []);
check('El cierre de sesión también', $cnt("SELECT COUNT(*) FROM activity WHERE workspace_id = ? AND action = 'logout' AND user_id = ?", [$ws1, $ines]) >= 1);

$admin->get('/admin/audit?kind=error');
check('Filtro por tipo: «Errores y rechazos» muestra el intento rechazado', $admin->status === 200 && str_contains($admin->body, 'Intento de inicio de sesión rechazado') && str_contains($admin->body, 'kind-error') && !str_contains($admin->body, 'data-kind="normal"'), snippet($admin));
$admin->get('/admin/audit?kind=admin&category=security');
check('…«Administración» muestra los accesos, con su color', $admin->status === 200 && str_contains($admin->body, 'Inició sesión') && str_contains($admin->body, 'data-kind="admin"'), snippet($admin));
$admin->get('/admin/audit?group=week');
check('Agrupado por semana, con encabezados', $admin->status === 200 && str_contains($admin->body, 'data-audit-group="week"') && str_contains($admin->body, 'Semana del'), snippet($admin));
$admin->get('/admin/audit?group=month');
check('…y por mes', $admin->status === 200 && preg_match('/data-audit-heading>[A-Z][a-zé]+ de 20\d\d</u', $admin->body) === 1, snippet($admin));
$admin->get('/admin/audit');
preg_match_all('/data-audit-row="(\d+)"/', $admin->body, $m1);
$next = preg_match('/href="[^"]*before=(\d+)/', $admin->body, $mn) ? (int)$mn[1] : 0;
$admin->get('/admin/audit?before=' . $next);
preg_match_all('/data-audit-row="(\d+)"/', $admin->body, $m2);
check('Páginas de 100 por número de registro («Más antiguos»), sin repetir ninguno', count($m1[1]) === 100 && $next > 0 && $m2[1] && max(array_map('intval', $m2[1])) < $next && !array_intersect($m1[1], $m2[1]) && str_contains($admin->body, 'Más recientes'), count($m1[1]) . ' ' . $next);
$admin->get('/admin/audit?task_id=' . $tW);
check('El historial de una tarea sale del mismo registro (con enlace a la tarea)', $admin->status === 200 && str_contains($admin->body, 'data-audit-row') && str_contains($admin->body, '/t/CAPN-'), snippet($admin));
$admin->request('GET', '/admin/audit.json?category=security');
$j = json_decode($admin->body, true) ?: [];
$r0 = $j['registros'][0] ?? [];
check('Exportación JSON con los mismos filtros, con tipo, resultado, enlace y huellas', $admin->status === 200 && str_contains((string)($admin->headers['content-type'][0] ?? ''), 'application/json') && $r0 && isset($r0['huella'], $r0['tipo'], $r0['resultado'], $r0['elemento'])
    && !array_filter($j['registros'], fn($x) => $x['categoria'] !== 'security'), mb_substr($admin->body, 0, 300));
$admin->request('GET', '/admin/audit.csv?kind=error');
check('El CSV agrega n.º, tipo, resultado y enlace', $admin->status === 200 && str_contains($admin->body, 'Resultado') && str_contains($admin->body, 'Rechazado'), mb_substr($admin->body, 0, 300));
$juanC->request('GET', '/admin/audit.json');
check('Sin permiso de auditoría no se exporta en JSON', in_array($juanC->status, [403, 404], true));
$admin->get('/admin/audit?verify=1');
check('La cadena sigue íntegra con resultados nuevos (la 1.14 no cambia de huella)', str_contains($admin->body, 'data-audit-verify="ok"'), snippet($admin));
$q("UPDATE activity SET result = NULL WHERE id = ?", [$deny['id']]);
$admin->get('/admin/audit?verify=1');
$brokenRes = str_contains($admin->body, 'data-audit-verify="broken"');
$q("UPDATE activity SET result = 'denied' WHERE id = ?", [$deny['id']]);
check('Borrar el «Rechazado» de un registro fuera de Borealis se detecta', $brokenRes);
// a server error goes to the log once (without trace)
$errLog = (string)file_get_contents($root . '/app/Services/Activity.php');
check('Los errores del servidor entran al registro sin traza ni rutas, uno por hora', str_contains($errLog, 'function systemError') && str_contains((string)file_get_contents($root . '/app/Core/ErrorHandler.php'), 'Activity::systemError'));
// retention
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/audit-archive.php') . ' 2>&1', $ao, $ac);
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/audit-archive.php') . ' --months=2 2>&1', $ao2, $ac2);
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/audit-archive.php') . ' --months=24 --dry-run 2>&1', $ao3, $ac3);
check('Conservación: sin política no archiva nada; el mínimo es 6 meses; --dry-run no toca nada', $ac === 0 && str_contains(implode(' ', $ao), 'no se archiva') && $ac2 === 2 && $ac3 === 0, implode(' | ', array_merge($ao, $ao2, $ao3)));
$routes = (string)file_get_contents($root . '/app/routes.php');
check('Sigue sin haber forma de editar o borrar el registro desde Borealis', !preg_match('~\$r->(patch|delete|post)\(\'/api/(admin/)?(audit|activity)~', $routes));

// ================================================================ sidebar, favorites, recents
group('1.15 · Barra lateral, favoritos y recientes');
$admin->get('/');
check('La barra lateral se agrupa por áreas (Mi espacio, Organización, Seguimiento, Administración)', str_contains($admin->body, 'data-nav-group="mine"') && str_contains($admin->body, 'data-nav-group="org"') && str_contains($admin->body, 'data-nav-group="watch"') && str_contains($admin->body, 'data-nav-group="admin"') && str_contains($admin->body, 'Registro maestro') && str_contains($admin->body, 'Bandeja de entrada'), snippet($admin));
$juanC->get('/');
check('Quien no administra no ve el grupo Administración', $juanC->status === 200 && str_contains($juanC->body, 'data-nav-group="mine"') && !str_contains($juanC->body, 'data-nav-group="admin"'));
check('En el móvil hay una barra inferior con lo frecuente', str_contains($juanC->body, 'class="mobile-tabs"') && str_contains($juanC->body, 'data-dialog="dlg-new-task"'));
$juanC->api('PATCH', '/api/profile/prefs', ['nav_closed' => ['watch', 'otra-cosa'], 'nav_compact' => true]);
$jp = json_decode((string)$q('SELECT prefs FROM users WHERE id = ?', [$juan])->fetchColumn(), true);
$juanC->get('/');
check('Los grupos plegados y la barra compacta se recuerdan en el perfil (solo valores válidos)', $jp['nav_closed'] === ['watch'] && $jp['nav_compact'] === true && preg_match('/data-nav-group="watch"\s*>/', $juanC->body) === 1 && str_contains($juanC->body, 'data-nav="compact"'), json_encode($jp));
$juanC->get('/monitoring');
check('…pero el grupo de la página actual siempre se muestra abierto', preg_match('/data-nav-group="watch" open/', $juanC->body) === 1);
$juanC->api('PATCH', '/api/profile/prefs', ['nav_closed' => [], 'nav_compact' => false]);
$juanC->api('POST', '/api/favorites', ['type' => 'project', 'id' => $pA]);
$f1 = $juanC->json();
$juanC->get('/');
check('Marcar un proyecto como favorito lo muestra arriba en la barra lateral', $f1['favorite'] === true && str_contains($juanC->body, 'id="sidebar-favorites"') && str_contains($juanC->body, 'data-fav="project:' . $pA . '"'), snippet($juanC));
$juanC->api('POST', '/api/favorites', ['type' => 'project', 'id' => $pA]);
check('…y volver a pulsar lo quita', $juanC->json()['favorite'] === false);
$pX = (int)$q("SELECT id FROM projects WHERE workspace_id = ? AND id NOT IN (SELECT project_id FROM project_members WHERE user_id = ?) AND (team_id IS NULL) ORDER BY id LIMIT 1", [$ws1, $juan])->fetchColumn();
$juanC->api('POST', '/api/favorites', ['type' => 'project', 'id' => $pX]);
check('No se puede marcar un proyecto al que no se tiene acceso', $pX === 0 || in_array($juanC->status, [403, 404], true), (string)$juanC->status);
$keyW = (string)$q("SELECT CONCAT(p.project_key, '-', t.number) FROM tasks t JOIN projects p ON p.id = t.project_id WHERE t.id = ?", [$tW])->fetchColumn();
$juanC->get('/t/' . $keyW);
$juanC->request('GET', '/api/nav/recent', null, ['Accept: application/json']);
$rec = $juanC->json()['recent'] ?? [];
check('La búsqueda rápida muestra lo abierto hace poco (primero lo último)', ($rec[0]['key'] ?? '') === $keyW, json_encode(array_slice($rec, 0, 3)));
$q('DELETE FROM project_members WHERE project_id = ? AND user_id = ?', [$pA, $juan]);
$juanC->request('GET', '/api/nav/recent', null, ['Accept: application/json']);
$rec2 = array_column($juanC->json()['recent'] ?? [], 'key');
$q('INSERT INTO project_members (project_id, user_id, role_id, added_at) VALUES (?,?,?,UTC_TIMESTAMP())', [$pA, $juan, $role($ws1, 'project', 'contributor')]);
check('…y deja de mostrar lo que ya no se puede ver', !in_array($keyW, $rec2, true), json_encode($rec2));

// ================================================================ task views and saved filters
group('1.15 · Vistas de tareas y filtros guardados');
$tB1 = $task($pA, 'Vista tablero', ['assignee_id' => $juan, 'due_date' => $yesterday]);
foreach (['table' => '<table class="table mine-table"', 'list' => 'class="task-compact"', 'board' => 'class="task-board"'] as $v => $needle) {
    $admin->get("/tasks?tab=team&view=$v&group=assignee&project_id=$pA");
    $okV[$v] = $admin->status === 200 && str_contains($admin->body, $needle) && str_contains($admin->body, 'Juan Capacidad');
}
check('«Equipo» se ve como tabla, lista compacta o tablero, agrupado por responsable', !in_array(false, $okV, true), json_encode($okV));
$ap = json_decode((string)$q('SELECT prefs FROM users WHERE id = ?', [$adminId])->fetchColumn(), true);
$admin->get('/tasks?tab=team');
check('La vista y la agrupación elegidas se recuerdan', ($ap['task_view'] ?? '') === 'board' && ($ap['task_group'] ?? '') === 'assignee' && str_contains($admin->body, 'data-view="board"'), json_encode([$ap['task_view'] ?? null, $ap['task_group'] ?? null]));
$admin->get("/tasks?tab=team&view=table&group=due&project_id=$pA&due=overdue");
check('Filtro «Vencidas» y agrupación por fecha', $admin->status === 200 && str_contains($admin->body, 'Vista tablero') && str_contains($admin->body, 'Vencidas') && !str_contains($admin->body, 'Aviso en vivo'), snippet($admin));
$admin->api('POST', '/api/views', ['name' => 'Vencidas de Capacidad Norte', 'query' => "project_id=$pA&due=overdue&secreto=1", 'shared' => true]);
$vid = (int)($admin->json()['id'] ?? 0);
$sv = $q('SELECT * FROM saved_views WHERE id = ?', [$vid])->fetch(PDO::FETCH_ASSOC);
check('Guardar una vista: solo se guardan los filtros conocidos', $vid && $sv['query'] === "project_id=$pA&due=overdue" && (int)$sv['shared'] === 1, json_encode($sv));
$juanC->get('/tasks?tab=team');
check('Una vista compartida aparece para el resto (cada quien ve solo lo suyo)', str_contains($juanC->body, 'Vencidas de Capacidad Norte'));
$juanC->api('DELETE', "/api/views/$vid");
check('…pero solo quien la creó puede borrarla', $juanC->status === 403);
[$gina, $ginaC] = $mkUser('Gina Invitada', 'gina.invitada@example.test', 'guest');
$ginaC->api('POST', '/api/views', ['name' => 'Mía', 'query' => 'assignee=me', 'shared' => true]);
$mine = $q('SELECT shared FROM saved_views WHERE id = ?', [(int)($ginaC->json()['id'] ?? 0)])->fetchColumn();
check('Quien no puede crear proyectos guarda vistas solo para sí', $mine !== false && (int)$mine === 0);
$admin->api('DELETE', "/api/views/$vid");
check('Borrar la propia vista', $admin->status === 200 && $cnt('SELECT COUNT(*) FROM saved_views WHERE id = ?', [$vid]) === 0);

// ================================================================ task detail, followers, decisions
group('1.15 · Detalle de tarea, seguidores y decisiones');
$admin->get('/t/' . $keyW . '?drawer=1');
$b = $admin->body;
check('El detalle tiene cabecera con Seguir y Favorito, y en propiedades las horas reales y el progreso', str_contains($b, 'data-watch') && str_contains($b, 'data-favorite="task:' . $tW . '"') && str_contains($b, 'data-real-hours') && str_contains($b, 'data-progress'));
$posD = strpos($b, 'Descripción'); $posS = strpos($b, 'Dependencias y relaciones'); $posC = strpos($b, '> Comentarios'); $posH = strpos($b, '> Historial');
check('…y el contenido en orden: descripción, dependencias, comentarios, historial', $posD && $posS && $posC && $posH && $posD < $posS && $posS < $posC && $posC < $posH, json_encode([$posD, $posS, $posC, $posH]));
$luciaC->api('POST', "/api/tasks/$tW/watch");
check('Seguir una tarea sin ser responsable', $luciaC->status === 200 && $luciaC->json()['watching'] === true);
$n0 = $cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'watch'", [$lucia]);
$admin->api('POST', "/api/tasks/$tW/comments", ['body' => 'Revisado el alcance con el cliente.']);
check('Quien sigue la tarea recibe el comentario (una vez)', $cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'watch'", [$lucia]) === $n0 + 1);
check('Quien comenta pasa a seguir la tarea', $cnt('SELECT COUNT(*) FROM task_watchers WHERE task_id = ? AND user_id = ?', [$tW, $adminId]) === 1);
$jn = $cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'watch'", [$juan]);
$admin->api('POST', "/api/tasks/$tW/comments", ['body' => 'Decidimos entregar en dos partes.', 'decision' => '1']);
$dec = $q('SELECT * FROM comments WHERE task_id = ? ORDER BY id DESC LIMIT 1', [$tW])->fetch(PDO::FETCH_ASSOC);
check('Un comentario puede marcarse como decisión, y queda en el registro con su texto', (int)$dec['is_decision'] === 1 && $cnt("SELECT COUNT(*) FROM activity WHERE action = 'decision' AND task_id = ? AND after_data LIKE '%dos partes%'", [$tW]) === 1);
check('…la responsable no recibe además el aviso de seguidora (no se duplica)', $cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'watch'", [$juan]) === $jn);
$admin->get("/projects/$pA");
check('El resumen del proyecto lista sus decisiones', str_contains($admin->body, 'data-decisions') && str_contains($admin->body, 'Decidimos entregar en dos partes'), snippet($admin));
$luciaC->api('POST', "/api/tasks/$tW/watch");
check('Dejar de seguir', $luciaC->json()['watching'] === false && $cnt('SELECT COUNT(*) FROM task_watchers WHERE task_id = ? AND user_id = ?', [$tW, $lucia]) === 0);

// ================================================================ automations
group('1.15 · Automatizaciones');
$juanC->api('POST', '/api/automations', ['template' => 'overdue']);
check('Solo la administración crea automatizaciones', $juanC->status === 403, (string)$juanC->status);
$admin->api('POST', '/api/automations', ['template' => 'overdue', 'project_id' => $pA]);
$ruleO = (int)($admin->json()['id'] ?? 0);
$admin->api('POST', '/api/automations', ['template' => 'overdue', 'project_id' => $pA]);
check('Activar una plantilla (vencidas en un proyecto) y no duplicarla', $ruleO > 0 && $admin->status === 422, snippet($admin));
$admin->get('/admin/automations');
check('La página muestra la regla con su «cuándo, si, entonces»', $admin->status === 200 && str_contains($admin->body, 'data-rule="overdue"') && str_contains($admin->body, 'Avisa a quien es responsable'), snippet($admin));
$before = $cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'automation' AND task_id = ?", [$juan, $tB1]);
[$c1] = $cron();
$after1 = $cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'automation' AND task_id = ?", [$juan, $tB1]);
[$c2] = $cron();
$after2 = $cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'automation' AND task_id = ?", [$juan, $tB1]);
check('La tarea programada avisa de la tarea vencida a su responsable, una sola vez aunque corra de nuevo', $c1 === 0 && $after1 === $before + 1 && $after2 === $after1, "$before $after1 $after2");
$logged = $q("SELECT * FROM activity WHERE action = 'auto_run' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$ruleO])->fetch(PDO::FETCH_ASSOC);
check('Lo que hizo queda en el registro maestro como automático', $logged && $logged['source'] === 'automation' && $logged['category'] === 'automation' && $logged['user_id'] === null, json_encode($logged));
check('La regla no cambió la tarea (solo avisa)', $q('SELECT due_date FROM tasks WHERE id = ?', [$tB1])->fetchColumn() === $yesterday);
$admin->api('PATCH', "/api/automations/$ruleO", ['enabled' => false]);
$tB2 = $task($pA, 'Vencida con regla en pausa', ['assignee_id' => $juan, 'due_date' => $yesterday]);
$cron();
check('Una regla en pausa no actúa', $cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'automation' AND task_id = ?", [$juan, $tB2]) === 0);
// blocker finished → the dependent task's responsible is told
$admin->api('POST', '/api/automations', ['template' => 'blocker_done']);
$ruleB = (int)($admin->json()['id'] ?? 0);
$blk = $task($pA, 'Conseguir permisos', []);
$dep = $task($pA, 'Construir el puente', ['assignee_id' => $lucia]);
$depKey = (string)$q("SELECT CONCAT(p.project_key, '-', t.number) FROM tasks t JOIN projects p ON p.id = t.project_id WHERE t.id = ?", [$dep])->fetchColumn();
$admin->api('POST', "/api/tasks/$blk/links", ['target' => $depKey, 'link_type' => 'blocks']);
$admin->api('POST', "/api/tasks/$blk/done", ['done' => true]);
$nb = $q("SELECT message FROM notifications WHERE user_id = ? AND type = 'automation' AND task_id = ? ORDER BY id DESC LIMIT 1", [$lucia, $dep])->fetchColumn();
check('«Bloqueo resuelto»: al finalizar la tarea que bloqueaba, avisa a quien puede avanzar', $ruleB > 0 && $nb && str_contains((string)$nb, 'ya no espera'), (string)$nb);
$admin->api('POST', "/api/tasks/$blk/done", ['done' => false]);
$admin->api('POST', "/api/tasks/$blk/done", ['done' => true]);
check('…y no lo repite si se reabre y se vuelve a finalizar', $cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'automation' AND task_id = ?", [$lucia, $dep]) === 1);
// hours over the estimate and a long block → the project lead is asked
$admin->api('POST', '/api/automations', ['template' => 'hours_over', 'project_id' => $pA, 'pct' => 20]);
$admin->api('POST', '/api/automations', ['template' => 'blocked_review', 'project_id' => $pA, 'days' => 3]);
$admin->api('PATCH', "/api/projects/$pA", ['lead_user_id' => $marta]);
$tH = $task($pA, 'Se pasó de horas', ['estimate_hours' => 1, 'assignee_id' => $juan]);
$juanC->api('POST', "/api/tasks/$tH/time", ['hours' => '2', 'work_date' => $todayL]);
$tK = $task($pA, 'Bloqueada hace días', ['assignee_id' => $juan]);
$q("UPDATE tasks SET is_blocked = 1, blocked_reason = 'Falta el permiso municipal', blocked_at = UTC_TIMESTAMP() - INTERVAL 5 DAY WHERE id = ?", [$tK]);
$lead = (int)$q('SELECT COALESCE(lead_user_id, created_by) FROM projects WHERE id = ?', [$pA])->fetchColumn();
$cron();
$mH = $q("SELECT message FROM notifications WHERE user_id = ? AND type = 'automation' AND task_id = ?", [$lead, $tH])->fetchColumn();
$mK = $q("SELECT message FROM notifications WHERE user_id = ? AND type = 'automation' AND task_id = ?", [$lead, $tK])->fetchColumn();
check('«Superó las horas acordadas» y «Bloqueo prolongado» avisan a quien lleva el proyecto', $lead > 0 && $mH && str_contains((string)$mH, '+20 %') && $mK && str_contains((string)$mK, 'más de 3 días bloqueada'), json_encode([$lead, $mH, $mK]));
$admin->api('DELETE', "/api/automations/$ruleB");
check('Eliminar una automatización queda registrado', $admin->status === 200 && $cnt("SELECT COUNT(*) FROM activity WHERE action = 'auto_deleted' AND entity_id = ?", [$ruleB]) === 1);
$lp = json_decode((string)$q('SELECT prefs FROM users WHERE id = ?', [$lucia])->fetchColumn(), true) ?: [];
$ncode = (string)file_get_contents($root . '/app/Services/Notifications.php');
check('Cada persona puede apagar los avisos de automatizaciones y de tareas que sigue en su perfil (categorías propias)', str_contains($ncode, "'automation' => ['Automatizaciones'") && str_contains($ncode, "'watching' => ['Tareas que sigues'"));

// ================================================================ upgrade
group('1.15 · Actualización desde la 1.14');
$beforeRows = $cnt('SELECT COUNT(*) FROM activity WHERE workspace_id = ?', [$ws1]);
$pdo->exec("DELETE FROM schema_migrations WHERE version = '017_v115'");
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/migrate.php') . ' --no-backup 2>&1', $mout, $mcode);
check('Al actualizar se aplica la 017 y no se pierde nada del registro', $mcode === 0 && str_contains(implode("\n", $mout), 'Aplicada: 017_v115') && $cnt('SELECT COUNT(*) FROM activity WHERE workspace_id = ?', [$ws1]) >= $beforeRows, implode("\n", $mout));
$admin->get('/admin/audit?verify=1');
check('…y la integridad sigue comprobándose bien', str_contains($admin->body, 'data-audit-verify="ok"'), snippet($admin));
// after archiving, the chain continues from the last archived row
$firstRows = $q('SELECT id, row_hash FROM activity WHERE workspace_id = ? AND row_hash IS NOT NULL ORDER BY id LIMIT 5', [$ws1])->fetchAll(PDO::FETCH_ASSOC);
$lastArch = end($firstRows);
$q('DELETE FROM activity WHERE workspace_id = ? AND id <= ?', [$ws1, $lastArch['id']]);
$admin->get('/admin/audit?verify=1');
$brokenNoBase = str_contains($admin->body, 'data-audit-verify="broken"');
$q('UPDATE audit_chain SET base_id = ?, base_hash = ? WHERE workspace_id = ?', [$lastArch['id'], $lastArch['row_hash'], $ws1]);
$admin->get('/admin/audit?verify=1');
check('Si se borran registros antiguos sin archivarlos se detecta; archivados (con el punto de partida), la cadena sigue válida', $brokenNoBase && str_contains($admin->body, 'data-audit-verify="ok"'), snippet($admin));
