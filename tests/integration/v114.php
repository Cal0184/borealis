<?php
declare(strict_types=1);

/*
 * Borealis 1.14 — gestión, monitoreo y trazabilidad: the scheduled job (CLI, /cron/run, heartbeat),
 * push deliveries with log and retries, renewal of a subscription, push on by default, the audit
 * log (hash chain, tampering, secrets never stored, filters, CSV, calendar history), estimates of
 * the worker (propose, review, agree) and the new monitoring rules, quick work and the load shown
 * while assigning, simpler project creation, the Gantt chart and its baseline, the period analysis
 * and the 1.13 → 1.14 upgrade.
 * Included by run.php after v113.php (same globals: $admin, $q, $role, $ws1, $mkUser, check(), group(), snippet(),
 * and the «browser» keys of v18.php: $uaPub, $uaAuth, $b64, $decrypt).
 */

$root = dirname(__DIR__, 2);
$cnt = fn(string $sql, array $p = []) => (int)$q($sql, $p)->fetchColumn();
$tz = new DateTimeZone('America/Mexico_City');
$day = fn(int $d) => (new DateTimeImmutable('now', $tz))->modify("$d day")->format('Y-m-d');
$cron = function (array $env = []) use ($root): array {
    $pre = '';
    foreach ($env as $k => $v) $pre .= $k . '=' . escapeshellarg($v) . ' ';
    exec($pre . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/notify.php') . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
};
$alert = fn(string $key) => $q('SELECT * FROM monitor_alerts WHERE workspace_id = ? AND dedupe_key = ?', [$ws1, $key])->fetch(PDO::FETCH_ASSOC) ?: null;
$adminId = (int)$q('SELECT id FROM users WHERE email = ?', [$adminEmail])->fetchColumn();

// ================================================================ migration
group('1.14 · Migración 016');
$tables = $cnt("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('system_jobs','notification_deliveries','task_estimates','audit_chain')");
$cols = $cnt("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = 'tasks' AND COLUMN_NAME IN ('admin_estimate_hours','worker_estimate_hours','estimate_review','is_milestone','baseline_start','baseline_due','unplanned','work_type'))
    OR (TABLE_NAME = 'projects' AND COLUMN_NAME = 'kind') OR (TABLE_NAME = 'activity' AND COLUMN_NAME IN ('category','before_data','after_data','reason','source','prev_hash','row_hash')))");
check('Migración 016: cuatro tablas nuevas y 16 columnas', $tables === 4 && $cols === 16, "$tables / $cols");
$errors = [];
foreach (preg_split('/^-- @@\s*$/m', (string)file_get_contents($root . '/database/migrations/016_v114.sql')) as $stmt) {
    $sql = trim(preg_replace('/^--.*$/m', '', $stmt));
    if ($sql === '') continue;
    try { $q($sql); } catch (PDOException $e) { if (!in_array((int)($e->errorInfo[1] ?? 0), [1060, 1061, 1050], true)) $errors[] = $e->getMessage(); }
}
check('La migración 016 se puede repetir sin errores', !$errors, implode(' | ', $errors));

// ================================================================ scheduled job
group('1.14 · Tarea programada (notificaciones con Borealis cerrado)');
$runs0 = $cnt("SELECT IFNULL(MAX(runs),0) FROM system_jobs WHERE name = 'notify'");
[$c1, $o1] = $cron();
$job = $q("SELECT * FROM system_jobs WHERE name = 'notify'")->fetch(PDO::FETCH_ASSOC) ?: [];
check('bin/notify.php deja constancia de cada ejecución (inicio, fin, resultado, origen «cli»)', $c1 === 0 && (int)($job['runs'] ?? 0) === $runs0 + 1 && $job['last_source'] === 'cli' && $job['last_ok_at'] && $job['cli_ok_at'] && !$job['last_error'], $o1 . ' ' . json_encode($job));
check('…y sigue imprimiendo su resumen como antes', str_contains($o1, 'recordatorios') || str_contains($o1, 'avisos') || $o1 !== '', $o1);
$admin->get('/admin/notifications');
check('Administración › Notificaciones dice que la tarea programada funciona y cómo configurarla en HestiaCP', $admin->status === 200 && str_contains($admin->body, 'data-job-state="ok"') && str_contains($admin->body, 'bin/notify.php') && str_contains($admin->body, 'Cron Jobs'), snippet($admin));
$cronUrl = preg_match('~curl -fsS &quot;([^&]+(?:&amp;[^&]+)*)&quot;~', $admin->body, $m) ? html_entity_decode($m[1]) : '';
$token = (string)(parse_url($cronUrl, PHP_URL_QUERY) ? (parse_str((string)parse_url($cronUrl, PHP_URL_QUERY), $qs) ?: ($qs['token'] ?? '')) : '');
check('…y a la administración de la instalación le da la dirección /cron/run con su clave', strlen($token) >= 24 && str_contains($cronUrl, '/cron/run?token='), $cronUrl);
check('La clave no se guarda en claro (storage/cron.json va cifrado)', $token !== '' && !str_contains((string)@file_get_contents($root . '/storage/cron.json'), $token));
$anon = new Client($BASE);
$anon->get('/cron/run');
$s1 = $anon->status;
$anon->get('/cron/run?token=' . str_repeat('a', 48));
check('/cron/run sin clave o con una clave equivocada responde 403 y no ejecuta nada', $s1 === 403 && $anon->status === 403 && (int)$q("SELECT runs FROM system_jobs WHERE name = 'notify'")->fetchColumn() === $runs0 + 1, $anon->body);
$anon->get('/cron/run?token=' . $token);
check('…y si la última ejecución fue hace menos de un minuto no la repite', $anon->status === 200 && str_contains($anon->body, 'menos de un minuto'), $anon->body);
$q("UPDATE system_jobs SET last_started_at = DATE_SUB(last_started_at, INTERVAL 3 MINUTE) WHERE name = 'notify'");
$anon->request('GET', '/cron/run', null, ['X-Borealis-Token: ' . $token]);
check('Con la clave (en la dirección o en la cabecera X-Borealis-Token) ejecuta la tarea y queda registrada como «http»', $anon->status === 200 && $q("SELECT last_source FROM system_jobs WHERE name = 'notify'")->fetchColumn() === 'http'
    && (int)$q("SELECT runs FROM system_jobs WHERE name = 'notify'")->fetchColumn() === $runs0 + 2 && ($anon->headers['cache-control'][0] ?? '') === 'no-store', $anon->status . ' ' . $anon->body);
check('…sin abrir sesión ni dejar cookies', !isset($anon->headers['set-cookie']));
[$mid, $memC] = $mkUser('Rita Rutina', 'rita.rutina@example.test', 'member');
$memC->api('POST', '/api/admin/cron-token');
check('Solo la administración de la instalación puede cambiar la clave', $memC->status === 403, snippet($memC));
$memC->get('/admin/notifications');
check('…y alguien sin permisos no ve la página', in_array($memC->status, [403, 404], true));
$admin->api('POST', '/api/admin/cron-token');
$q("UPDATE system_jobs SET last_started_at = DATE_SUB(last_started_at, INTERVAL 3 MINUTE) WHERE name = 'notify'");
$anon->get('/cron/run?token=' . $token);
check('Al generar otra clave, la anterior deja de funcionar', $admin->status === 200 && $anon->status === 403, $anon->body);
$q("UPDATE system_jobs SET last_started_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 40 MINUTE), last_finished_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 40 MINUTE) WHERE name = 'notify'");
$admin->get('/admin/notifications');
$stale = str_contains($admin->body, 'data-job-state="stale"') && str_contains($admin->body, 'no se ejecuta desde hace');
$admin->get('/profile');
check('Si la tarea programada deja de ejecutarse, Administración y el perfil lo avisan', $stale && str_contains($admin->body, 'data-push-job'), snippet($admin));
[$c2, $o2] = $cron();
$admin->get('/admin/notifications');
check('…y al volver a ejecutarse el aviso desaparece', $c2 === 0 && str_contains($admin->body, 'data-job-state="ok"'), $o2);
// the reminders of someone who never opens Borealis are created by the job alone
$pS = (function () use ($admin, $mid, $role, $ws1): int {
    $admin->api('POST', '/api/projects', ['name' => 'Rutina Cerrada', 'project_key' => 'RUC', 'member_ids' => [$mid]]);
    return (int)($admin->json()['id'] ?? 0);
})();
$admin->api('POST', "/api/projects/$pS/tasks", ['title' => 'Vence mañana sin entrar', 'assignee_id' => $mid, 'due_date' => $day(1)]);
$tS = (int)($admin->json()['task']['id'] ?? 0);
$q("UPDATE system_jobs SET last_started_at = DATE_SUB(last_started_at, INTERVAL 3 MINUTE) WHERE name = 'notify'");
$anon->get('/cron/run?token=' . Scheduler14Token($root));
check('La dirección /cron/run crea los recordatorios de quien no tiene Borealis abierto', $anon->status === 200 && $cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND task_id = ? AND type = 'due'", [$mid, $tS]) === 1, $anon->body);
$cron(); $cron();
check('…sin repetirlos al ejecutar también el cron por línea de comandos', $cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND task_id = ? AND type = 'due'", [$mid, $tS]) === 1);

/** Reads the current /cron/run key the way the admin page shows it. */
function Scheduler14Token(string $root): string
{
    global $admin;
    $admin->get('/admin/notifications');
    if (!preg_match('~/cron/run\?token=([a-f0-9]{24,})~', html_entity_decode($admin->body), $m)) return '';
    return $m[1];
}

// ================================================================ push deliveries
group('1.14 · Envíos a dispositivos: registro, reintentos y renovación');
$fsPort = (int)(getenv('BOREALIS_FAKE_SERVICES_PORT') ?: 8098);
$fs = "http://127.0.0.1:$fsPort";
$fsLog = sys_get_temp_dir() . '/borealis-fake-services14-' . getmypid() . '.jsonl';
@unlink($fsLog); @unlink($fsLog . '.down');
$fsProc14 = proc_open([PHP_BINARY, '-S', "127.0.0.1:$fsPort", $root . '/tests/fake-services/server.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $fsPipes14, null, ['FAKE_SERVICES_LOG' => $fsLog, 'PATH' => getenv('PATH')]);
usleep(400000);
$fsPost = function (string $p) use ($fs): void { $ch = curl_init($fs . $p); curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true]); curl_exec($ch); curl_close($ch); };
$fsGet = function () use ($fs): array { $r = @file_get_contents("$fs/_log"); return is_string($r) ? (json_decode($r, true) ?: []) : []; };
$cfgPath14 = $root . '/config/config.php';
$cfgOrig14 = (string)file_get_contents($cfgPath14);
$cfg14 = require $cfgPath14;
$cfg14['push'] = ['test_hosts' => "127.0.0.1:$fsPort", 'subject' => 'mailto:pruebas@example.test'];
file_put_contents($cfgPath14, "<?php\nreturn " . var_export($cfg14, true) . ";\n");
usleep(3200000);

[$pia, $piaC] = $mkUser('Pía Push', 'pia.push@example.test', 'member');
$admin->api('POST', "/api/projects/$pS/members", ['user_id' => $pia, 'role_id' => $role($ws1, 'project', 'contributor')]);
$prefs = json_decode((string)$q('SELECT prefs FROM users WHERE id = ?', [$pia])->fetchColumn(), true) ?: [];
check('Para una persona nueva las notificaciones al dispositivo vienen activadas (solo falta el permiso del navegador)', !isset($prefs['notify']['push']) || $prefs['notify']['push'] === true, json_encode($prefs));
$piaC->get('/');
check('El inicio invita a activar las notificaciones en este dispositivo', str_contains($piaC->body, 'data-push-nudge'), snippet($piaC));
$fsPost('/_down');
$piaC->api('POST', '/api/push/subscription', ['endpoint' => "$fs/push/flaky", 'p256dh' => $b64($uaPub), 'auth' => $b64($uaAuth), 'device' => 'Android · Chrome']);
check('Activar el dispositivo guarda la suscripción', $piaC->status === 200 && $cnt('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?', [$pia]) === 1, snippet($piaC));
$fsPost('/_reset');
$admin->api('POST', "/api/projects/$pS/tasks", ['title' => 'Aviso con el servicio caído', 'assignee_id' => $pia]);
$tF = (int)($admin->json()['task']['id'] ?? 0);
usleep(800000);
$nF = (int)$q("SELECT id FROM notifications WHERE user_id = ? AND task_id = ? AND type = 'assigned'", [$pia, $tF])->fetchColumn();
$d = $q('SELECT * FROM notification_deliveries WHERE notification_id = ?', [$nF])->fetch(PDO::FETCH_ASSOC) ?: [];
check('Si el servicio de notificaciones falla de forma pasajera (503) el envío queda registrado para reintentar, con su error', ($d['state'] ?? '') === 'retry' && (int)$d['attempts'] === 1 && (int)$d['last_code'] === 503 && $d['next_attempt_at'] && count($fsGet()) === 1, json_encode($d));
[$c3] = $cron();
check('…el reintento espera a su hora (no reintenta antes)', ($q('SELECT state FROM notification_deliveries WHERE notification_id = ?', [$nF])->fetchColumn()) === 'retry' && count($fsGet()) === 1);
$fsPost('/_up');
$q('UPDATE notification_deliveries SET next_attempt_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE notification_id = ?', [$nF]);
[$c4, $o4] = $cron();
$d = $q('SELECT * FROM notification_deliveries WHERE notification_id = ?', [$nF])->fetch(PDO::FETCH_ASSOC) ?: [];
$got = array_values(array_filter($fsGet(), fn($r) => $r['path'] === '/push/flaky'));
$plain = $got ? $decrypt(base64_decode($got[count($got) - 1]['body'])) : null;
check('La tarea programada lo reintenta y llega (2.º intento), con el contenido del aviso', $c4 === 0 && ($d['state'] ?? '') === 'sent' && (int)$d['attempts'] === 2 && count($got) === 2 && str_contains((string)$plain, 'Aviso con el servicio caído'), $o4 . ' ' . json_encode($d));
$cron(); $cron();
check('…y nunca se envía dos veces', count(array_filter($fsGet(), fn($r) => $r['path'] === '/push/flaky')) === 2 && $cnt('SELECT COUNT(*) FROM notification_deliveries WHERE notification_id = ?', [$nF]) === 1);
// a notice read meanwhile is not retried
$fsPost('/_down');
$admin->api('POST', "/api/projects/$pS/tasks", ['title' => 'Leído antes del reintento', 'assignee_id' => $pia]);
$tR = (int)($admin->json()['task']['id'] ?? 0);
usleep(600000);
$nR = (int)$q("SELECT id FROM notifications WHERE user_id = ? AND task_id = ? AND type = 'assigned'", [$pia, $tR])->fetchColumn();
$piaC->api('POST', "/api/notifications/$nR/read");
$fsPost('/_up');
$fsPost('/_reset');
$q('UPDATE notification_deliveries SET next_attempt_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE notification_id = ?', [$nR]);
$cron();
check('Un aviso que se leyó mientras esperaba el reintento ya no se envía', !$fsGet() && $q('SELECT state FROM notification_deliveries WHERE notification_id = ?', [$nR])->fetchColumn() === 'failed', (string)$q('SELECT last_error FROM notification_deliveries WHERE notification_id = ?', [$nR])->fetchColumn());
// permanent error: logged with a clear message, not retried
$piaC->api('POST', '/api/push/subscription', ['endpoint' => "$fs/push/badauth", 'p256dh' => $b64($uaPub), 'auth' => $b64($uaAuth), 'device' => 'Escritorio']);
$admin->api('POST', "/api/projects/$pS/tasks", ['title' => 'Dos dispositivos', 'assignee_id' => $pia]);
$tB = (int)($admin->json()['task']['id'] ?? 0);
usleep(800000);
$nB = (int)$q("SELECT id FROM notifications WHERE user_id = ? AND task_id = ? AND type = 'assigned'", [$pia, $tB])->fetchColumn();
$rows = $q('SELECT d.state, d.last_code, d.last_error, s.endpoint FROM notification_deliveries d JOIN push_subscriptions s ON s.id = d.subscription_id WHERE d.notification_id = ? ORDER BY s.id', [$nB])->fetchAll(PDO::FETCH_ASSOC);
$byEp = array_column($rows, null, 'endpoint');
check('Con dos dispositivos se registra un envío por dispositivo: uno llega y el otro falla con un error permanente que no se reintenta', count($rows) === 2 && ($byEp["$fs/push/flaky"]['state'] ?? '') === 'sent'
    && ($byEp["$fs/push/badauth"]['state'] ?? '') === 'failed' && (int)$byEp["$fs/push/badauth"]['last_code'] === 403 && str_contains((string)$byEp["$fs/push/badauth"]['last_error'], 'VAPID'), json_encode($rows));
$admin->get('/admin/notifications');
check('Administración › Notificaciones muestra los envíos fallidos con su motivo (sin direcciones de dispositivos)', str_contains($admin->body, 'VAPID') && !str_contains($admin->body, '/push/badauth'), snippet($admin));
// the browser replaced the subscription (pushsubscriptionchange): renewal proven with the old keys
$ua2 = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
$ua2d = openssl_pkey_get_details($ua2)['ec'];
$ua2Pub = "\x04" . str_pad($ua2d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ua2d['y'], 32, "\0", STR_PAD_LEFT);
$anon->api('POST', '/api/push/renew', ['old_endpoint' => "$fs/push/flaky", 'old_auth' => $b64(random_bytes(16)), 'endpoint' => "$fs/push/flaky-nueva", 'p256dh' => $b64($ua2Pub), 'auth' => $b64(random_bytes(16))], false);
check('Renovar una suscripción sin probar la anterior (clave auth) se rechaza', $anon->status === 403 && $cnt('SELECT COUNT(*) FROM push_subscriptions WHERE endpoint = ?', ["$fs/push/flaky-nueva"]) === 0, snippet($anon));
$anon->api('POST', '/api/push/renew', ['old_endpoint' => "$fs/push/flaky", 'old_auth' => $b64($uaAuth), 'endpoint' => "$fs/push/flaky-nueva", 'p256dh' => $b64($ua2Pub), 'auth' => $b64(random_bytes(16))], false);
check('Cuando el navegador cambia la suscripción, el service worker la renueva sola sin sesión y sin duplicar el dispositivo', $anon->status === 200 && $cnt('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ? AND endpoint = ?', [$pia, "$fs/push/flaky-nueva"]) === 1
    && $cnt('SELECT COUNT(*) FROM push_subscriptions WHERE endpoint = ?', ["$fs/push/flaky"]) === 0, snippet($anon));
$anon->get('/sw.js');
check('El service worker atiende «pushsubscriptionchange»', str_contains($anon->body, 'pushsubscriptionchange') && str_contains($anon->body, 'api/push/renew'));
// someone who had turned push off on purpose keeps it off; activating a device turns it on
$piaC->api('PATCH', '/api/profile/notifications', ['push' => '0']);
$prefOff = json_decode((string)$q('SELECT prefs FROM users WHERE id = ?', [$pia])->fetchColumn(), true)['notify']['push'] ?? null;
$fsPost('/_reset');
$admin->api('POST', "/api/projects/$pS/tasks", ['title' => 'Con el móvil apagado a propósito', 'assignee_id' => $pia]);
usleep(700000);
check('Quien apagó las notificaciones del dispositivo no las recibe (se respeta su preferencia)', $prefOff === false && !array_filter($fsGet(), fn($r) => str_starts_with($r['path'], '/push/flaky')), json_encode($prefOff));
$piaC->api('POST', '/api/push/subscription', ['endpoint' => "$fs/push/flaky-nueva", 'p256dh' => $b64($ua2Pub), 'auth' => $b64(random_bytes(16))]);
$prefOn = json_decode((string)$q('SELECT prefs FROM users WHERE id = ?', [$pia])->fetchColumn(), true)['notify']['push'] ?? null;
check('…y al activar de nuevo un dispositivo se encienden', $prefOn === true, json_encode($prefOn));
// fallback for hosts without cron: a visit starts the job after its answer when the last run is older than 5 minutes
$cfgV = require $cfgPath14;
$cfgV['cron'] = ['web_fallback' => 'always'];
file_put_contents($cfgPath14, "<?php\nreturn " . var_export($cfgV, true) . ";\n");
usleep(3200000);
$q("UPDATE system_jobs SET last_started_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE) WHERE name = 'notify'");
$runsV = $cnt("SELECT runs FROM system_jobs WHERE name = 'notify'");
(new Client($BASE))->get('/login');
usleep(500000);
$srcV = (string)$q("SELECT last_source FROM system_jobs WHERE name = 'notify'")->fetchColumn();
(new Client($BASE))->get('/login');
usleep(300000);
check('Sin cron, una visita cualquiera ejecuta la tarea tras responder (como mucho cada 5 minutos)', $srcV === 'visit' && $cnt("SELECT runs FROM system_jobs WHERE name = 'notify'") === $runsV + 1, $srcV);
file_put_contents($cfgPath14, $cfgOrig14);
usleep(3200000);
proc_terminate($fsProc14);
@unlink($fsLog); @unlink($fsLog . '.down');

// ================================================================ audit
group('1.14 · Registro de actividad y auditoría');
$admin->api('POST', "/api/projects/$pS/tasks", ['title' => 'Cambio de fecha con motivo', 'assignee_id' => $mid, 'due_date' => $day(3)]);
$tA = $admin->json()['task'] ?? [];
$admin->api('PATCH', '/api/tasks/' . (int)$tA['id'], ['due_date' => $day(6), 'change_reason' => 'El cliente pidió más tiempo']);
$row = $q("SELECT * FROM activity WHERE action = 'due_changed' AND entity_type = 'task' AND entity_id = ? ORDER BY id DESC LIMIT 1", [(int)$tA['id']])->fetch(PDO::FETCH_ASSOC) ?: [];
check('Cambiar una fecha guarda quién, cuándo, antes, después y el motivo, en la categoría Calendario', ($row['category'] ?? '') === 'calendar' && (int)$row['user_id'] === $adminId && str_contains((string)$row['before_data'], $day(3)) && str_contains((string)$row['after_data'], $day(6)) && $row['reason'] === 'El cliente pidió más tiempo' && $row['row_hash'], json_encode($row));
$admin->api('POST', '/api/events', ['calendar_id' => $calEvents, 'kind' => 'event', 'title' => 'Revisión trimestral', 'start_date' => $day(8), 'start_time' => '10:00', 'end_time' => '11:00']);
$ev = (int)($admin->json()['id'] ?? 0);
$admin->api('PATCH', "/api/events/$ev", ['start_date' => $day(9), 'start_time' => '12:00', 'end_time' => '13:00']);
$admin->api('POST', "/api/events/$ev/cancel");
$evRows = $q("SELECT action, category, before_data, after_data, source FROM activity WHERE entity_type = 'event' AND entity_id = ? ORDER BY id", [$ev])->fetchAll(PDO::FETCH_ASSOC);
$acts = array_column($evRows, 'action');
$resch = array_values(array_filter($evRows, fn($r) => $r['action'] === 'event_rescheduled'))[0] ?? [];
check('Historial del calendario: creación, reprogramación (antes y después) y cancelación de un evento', in_array('event_rescheduled', $acts, true) && count($evRows) >= 3 && str_contains((string)$resch['before_data'], $day(8)) && str_contains((string)$resch['after_data'], $day(9))
    && count(array_filter($evRows, fn($r) => $r['category'] === 'calendar')) === count($evRows) && $resch['source'] === 'web', json_encode($evRows));
$admin->get('/admin/audit?category=calendar&project_id=' . $pS);
check('El registro se filtra por categoría y proyecto, y muestra el antes, el después y el motivo', $admin->status === 200 && str_contains($admin->body, 'El cliente pidió más tiempo') && str_contains($admin->body, 'data-audit-row') && !str_contains($admin->body, 'Revisión trimestral'), snippet($admin));
$admin->get('/admin/audit?user_id=' . $mid);
$onlyMid = $admin->status === 200;
$admin->get('/admin/audit?period=week&action=due_changed');
$byAction = $admin->status === 200 && str_contains($admin->body, 'El cliente pidió más tiempo') && !str_contains($admin->body, 'Revisión trimestral');
$admin->get('/admin/audit?period=year&category=calendar');
check('…también por persona, acción y período', $onlyMid && $byAction && $admin->status === 200 && str_contains($admin->body, 'El cliente pidió más tiempo') && str_contains($admin->body, 'value="year" selected'), snippet($admin));
$admin->request('GET', '/admin/audit.csv?category=calendar&project_id=' . $pS);
$ctype = (string)($admin->headers['content-type'][0] ?? '');
check('Se exporta a CSV (UTF-8, separado por «;») con los mismos filtros', $admin->status === 200 && str_contains($ctype, 'text/csv') && str_starts_with($admin->body, "\xEF\xBB\xBF") && str_contains($admin->body, 'El cliente pidió más tiempo') && !str_contains($admin->body, 'Revisión trimestral'), $ctype . ' ' . mb_substr($admin->body, 0, 200));
$memC->get('/admin/audit');
$m1 = $memC->status;
$memC->get('/admin/audit.csv');
check('Quien no tiene permiso de auditoría no ve ni exporta el registro', in_array($m1, [403, 404], true) && in_array($memC->status, [403, 404], true));
$admin->get('/admin/audit?verify=1');
check('La integridad del registro se comprueba (cadena de huellas)', str_contains($admin->body, 'data-audit-verify="ok"'), snippet($admin));
$victim = $q("SELECT id, summary FROM activity WHERE workspace_id = ? AND row_hash IS NOT NULL ORDER BY id DESC LIMIT 1 OFFSET 3", [$ws1])->fetch(PDO::FETCH_ASSOC);
$q('UPDATE activity SET summary = ? WHERE id = ?', ['Modificado directamente en la base de datos', $victim['id']]);
$admin->get('/admin/audit?verify=1');
$broken = str_contains($admin->body, 'data-audit-verify="broken"') && str_contains($admin->body, 'n.º ' . $victim['id']);
$q('UPDATE activity SET summary = ? WHERE id = ?', [$victim['summary'], $victim['id']]);
$del = $q("SELECT id FROM activity WHERE workspace_id = ? AND row_hash IS NOT NULL ORDER BY id DESC LIMIT 1 OFFSET 5", [$ws1])->fetchColumn();
$admin->get('/admin/audit?verify=1');
$restored = str_contains($admin->body, 'data-audit-verify="ok"');
check('Una modificación hecha fuera de Borealis se detecta e indica desde qué registro', $broken && $restored, snippet($admin));
$bk = $q('SELECT * FROM activity WHERE id = ?', [$del])->fetch(PDO::FETCH_ASSOC);
$q('DELETE FROM activity WHERE id = ?', [$del]);
$admin->get('/admin/audit?verify=1');
$delDetected = str_contains($admin->body, 'data-audit-verify="broken"');
$q('INSERT INTO activity (' . implode(',', array_map(fn($k) => "`$k`", array_keys($bk))) . ') VALUES (' . implode(',', array_fill(0, count($bk), '?')) . ')', array_values($bk));
check('…también una eliminación', $delDetected, snippet($admin));
$hid = $q("SELECT id, prev_hash, row_hash FROM activity WHERE workspace_id = ? AND row_hash IS NOT NULL ORDER BY id DESC LIMIT 1 OFFSET 4", [$ws1])->fetch(PDO::FETCH_ASSOC);
$q('UPDATE activity SET summary = CONCAT(summary, ?), prev_hash = NULL, row_hash = NULL WHERE id = ?', [' (alterado)', $hid['id']]);
$admin->get('/admin/audit?verify=1');
$hidDetected = str_contains($admin->body, 'data-audit-verify="broken"');
$q('UPDATE activity SET summary = REPLACE(summary, ?, ?), prev_hash = ?, row_hash = ? WHERE id = ?', [' (alterado)', '', $hid['prev_hash'], $hid['row_hash'], $hid['id']]);
$admin->get('/admin/audit?verify=1');
check('…y un registro al que se le quitó la huella para que pase por anterior a la 1.14', $hidDetected && str_contains($admin->body, 'data-audit-verify="ok"'), snippet($admin));
check('Borealis no ofrece ninguna forma de editar o borrar el registro', !preg_match('~\$r->(patch|delete)\(\'/api/(admin/)?(audit|activity)~', (string)file_get_contents($root . '/app/routes.php')));
// secrets never end up in the log
$admin->api('PATCH', "/api/members/$mid", ['weekly_hours' => 32]);
$admin->api('POST', "/api/projects/$pS/integrations/github", ['repo' => 'acme/secret', 'token' => 'ghp_tokensecreto123']);
$leak = $cnt("SELECT COUNT(*) FROM activity WHERE CONCAT(IFNULL(summary,''), IFNULL(before_data,''), IFNULL(after_data,''), IFNULL(reason,'')) REGEXP ?", ['ghp_tokensecreto|\\$2y\\$|\\$argon2|BEGIN [A-Z ]*PRIVATE KEY|p256dh']);
$wh = $q("SELECT before_data, after_data FROM activity WHERE entity_type IN ('member','user') AND after_data LIKE '%32%' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
check('Cambiar las horas semanales de alguien queda con antes y después', str_contains((string)($wh['after_data'] ?? ''), '32'), json_encode($wh));
check('El registro nunca guarda contraseñas, tokens, claves ni datos de dispositivos', $leak === 0, (string)$leak);
$admin->api('DELETE', "/api/projects/$pS/integrations/github");

// ================================================================ estimates
group('1.14 · Estimación de horas del trabajador');
[$gil, $gilC] = $mkUser('Gil Gestor', 'gil.gestor@example.test', 'member');
[$tea, $teaC] = $mkUser('Tea Trabajadora', 'tea.trabajadora@example.test', 'member');
[$uli, $uliC] = $mkUser('Uli Ajeno', 'uli.ajeno@example.test', 'member');
$admin->api('POST', '/api/projects', ['name' => 'Estimaciones', 'project_key' => 'EST', 'lead_user_id' => $gil, 'member_ids' => [$tea, $uli]]);
$pE = (int)($admin->json()['id'] ?? 0);
$gilC->api('POST', "/api/projects/$pE/tasks", ['title' => 'Informe de cierre', 'assignee_id' => $tea, 'estimate_hours' => 10, 'due_date' => $day(10)]);
$tE = $gilC->json()['task'] ?? [];
$te = fn() => $q('SELECT estimate_hours, admin_estimate_hours, worker_estimate_hours, estimate_review FROM tasks WHERE id = ?', [(int)$tE['id']])->fetch(PDO::FETCH_ASSOC);
check('La estimación de quien gestiona el proyecto es la de administración y, a la vez, la acordada', (float)$te()['admin_estimate_hours'] === 10.0 && (float)$te()['estimate_hours'] === 10.0, json_encode($te()));
$teaC->api('POST', '/api/tasks/' . (int)$tE['id'] . '/estimate', ['hours' => 14]);
check('La trabajadora no puede cambiar lo acordado sin un motivo', $teaC->status === 422 && isset($teaC->json()['errors']['reason']), snippet($teaC));
$teaC->api('POST', '/api/tasks/' . (int)$tE['id'] . '/estimate', ['hours' => 14, 'reason' => 'Faltan los datos de dos sucursales']);
check('Con un motivo, su estimación (14 h) queda por revisar y lo acordado no cambia solo', $teaC->status === 200 && (float)$te()['worker_estimate_hours'] === 14.0 && (float)$te()['estimate_hours'] === 10.0 && $te()['estimate_review'] === 'pending', json_encode($te()));
check('…y quien gestiona el proyecto recibe el aviso con el motivo', $cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND task_id = ? AND message LIKE '%Faltan los datos%'", [$gil, (int)$tE['id']]) === 1);
$uliC->api('POST', '/api/tasks/' . (int)$tE['id'] . '/estimate', ['hours' => 2, 'reason' => 'Lo hago yo']);
check('Quien no es responsable de la tarea no puede estimarla', $uliC->status === 403, snippet($uliC));
$teaC->api('POST', '/api/tasks/' . (int)$tE['id'] . '/estimate/agree', ['hours' => 14]);
check('La trabajadora no puede aprobar su propia ampliación', $teaC->status === 403);
$cron();
$ai = $alert('estimate_increase:' . (int)$tE['id'] . ':14');
check('Monitoreo levanta «ampliación importante de la estimación» (40 % sobre lo acordado) para quien gestiona', $ai && (int)$ai['level'] === 2 && $ai['state'] === 'open', json_encode($ai));
$gilC->get('/t/' . $tE['key']);
check('La ficha de la tarea muestra administración, trabajador, acordado, real y diferencia, con el historial', $gilC->status === 200 && str_contains($gilC->body, 'data-estimates="pending"') && str_contains($gilC->body, 'data-est-worker') && str_contains($gilC->body, 'Faltan los datos de dos sucursales'), snippet($gilC));
$gilC->api('POST', '/api/tasks/' . (int)$tE['id'] . '/estimate/agree', ['hours' => 13, 'reason' => 'Una sucursal ya envió los datos']);
check('Quien gestiona acuerda el valor (13 h): pasa a ser el acordado y la revisión se cierra', $gilC->status === 200 && (float)$te()['estimate_hours'] === 13.0 && $te()['estimate_review'] === 'agreed', json_encode($te()));
check('Cada paso queda en el historial de estimaciones (quién, tipo, antes, después, motivo)', $cnt('SELECT COUNT(*) FROM task_estimates WHERE task_id = ?', [(int)$tE['id']]) >= 3
    && $cnt("SELECT COUNT(*) FROM task_estimates WHERE task_id = ? AND kind = 'agreed' AND old_hours = 10 AND new_hours = 13 AND reason = 'Una sucursal ya envió los datos' AND user_id = ?", [(int)$tE['id'], $gil]) === 1);
check('…y en el registro de auditoría con antes, después y motivo', $cnt("SELECT COUNT(*) FROM activity WHERE action = 'estimate_agreed' AND entity_id = ? AND reason = 'Una sucursal ya envió los datos' AND after_data LIKE '%13%'", [(int)$tE['id']]) === 1);
check('…y la trabajadora recibe el acuerdo', $cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND task_id = ? AND message LIKE '%se acordaron 13 h%'", [$tea, (int)$tE['id']]) === 1);
$cron();
check('La alerta de ampliación se cierra sola cuando ya no aplica', ($alert('estimate_increase:' . (int)$tE['id'] . ':14')['state'] ?? '') !== 'open');
$teaC->api('POST', '/api/tasks/' . (int)$tE['id'] . '/time', ['hours' => '15', 'work_date' => $day(0)]);
$cron();
$ao = $alert('over_agreed:' . (int)$tE['id'] . ':13');
check('Superar el tiempo acordado (15 de 13 h) levanta una alerta para quien gestiona', $ao && $ao['state'] === 'open' && (int)$ao['level'] === 2, json_encode($ao));
check('…que da las cifras y explica la situación sin calificarla como bajo rendimiento', $ao && !preg_match('/bajo rendimiento|culpa|lento|incumpli/i', $ao['message'] . ' ' . $ao['explanation']) && str_contains((string)$ao['message'], 'lleva 15 h de 13 h acordadas'), ($ao['message'] ?? '') . ' / ' . ($ao['explanation'] ?? ''));
$gilC->api('POST', "/api/projects/$pE/tasks", ['title' => 'Sin estimar', 'assignee_id' => $tea]);
$tN = $gilC->json()['task'] ?? [];
$teaC->api('POST', '/api/tasks/' . (int)$tN['id'] . '/estimate', ['hours' => 6]);
check('Sin horas acordadas, la primera estimación de la trabajadora se vuelve el plan sin pedir motivo', $teaC->status === 200 && (float)$q('SELECT estimate_hours FROM tasks WHERE id = ?', [(int)$tN['id']])->fetchColumn() === 6.0);

// ================================================================ quick work
group('1.14 · Trabajos rápidos y carga al asignar');
$teaC->api('POST', '/api/quick', ['title' => 'Llamar al proveedor', 'estimate_hours' => 1, 'due_date' => $day(0), 'work_type' => 'request']);
$qk = $teaC->json()['task'] ?? [];
$qp = $q("SELECT * FROM projects WHERE workspace_id = ? AND kind = 'quick'", [$ws1])->fetchAll(PDO::FETCH_ASSOC);
check('Un trabajo rápido se crea sin configurar ningún proyecto', $teaC->status === 200 && count($qp) === 1 && $cnt('SELECT COUNT(*) FROM tasks WHERE id = ? AND project_id = ?', [(int)($qk['id'] ?? 0), (int)$qp[0]['id']]) === 1, snippet($teaC));
check('…con su responsable, horas, fecha y tipo (la solicitud imprevista cuenta como fuera de la planificación)', (bool)$q("SELECT 1 FROM tasks WHERE id = ? AND assignee_id = ? AND estimate_hours = 1 AND work_type = 'request' AND unplanned = 1", [(int)($qk['id'] ?? 0), $tea])->fetchColumn());
$teaC->api('POST', '/api/quick', ['title' => 'Otra seguida', 'work_type' => 'admin']);
check('Se pueden crear varios seguidos y se reutiliza el mismo espacio', $teaC->status === 200 && $cnt("SELECT COUNT(*) FROM projects WHERE workspace_id = ? AND kind = 'quick'", [$ws1]) === 1);
[$gus, $gusC] = $mkUser('Gus Invitado', 'gus.invitado@example.test', 'guest');
$gusC->api('POST', '/api/quick', ['title' => 'Para otra persona', 'assignee_id' => $tea]);
check('Quien no puede asignar trabajo no crea trabajos rápidos para otras personas', $gusC->status === 403, snippet($gusC));
$gilC->api('POST', '/api/quick', ['title' => 'Urgencia del cliente', 'assignee_id' => $tea, 'estimate_hours' => 3, 'due_date' => $day(1), 'work_type' => 'urgent']);
$qu = $gilC->json()['task'] ?? [];
check('Quien coordina asigna un trabajo rápido a otra persona y ella recibe el aviso', $gilC->status === 200 && $cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND task_id = ? AND type = 'assigned'", [$tea, (int)($qu['id'] ?? 0)]) === 1, snippet($gilC));
$teaC->api('POST', '/api/tasks/' . (int)$qk['id'] . '/time', ['hours' => '1.5', 'work_date' => $day(0)]);
check('En un trabajo rápido se registran horas', $teaC->status === 200 && $cnt('SELECT SUM(minutes) FROM time_entries WHERE task_id = ? AND deleted_at IS NULL', [(int)$qk['id']]) === 90, snippet($teaC));
$admin->get('/projects');
$listed = str_contains(substr($admin->body, (int)strpos($admin->body, 'id="page"')), QuickName14()); // 1.15: only the page, not the sidebar link
$teaC->get('/quick');
check('El espacio de trabajos rápidos no aparece entre los proyectos, pero tiene su propia página semanal', !$listed && $teaC->status === 200 && str_contains($teaC->body, 'Llamar al proveedor') && str_contains($teaC->body, 'Urgencia del cliente') && str_contains($teaC->body, 'data-quick-form'), snippet($teaC));
$teaC->get('/');
check('…y aparecen en Mi trabajo', str_contains($teaC->body, 'Urgencia del cliente'));
$admin->api('PATCH', "/api/members/$tea", ['weekly_hours' => 20]);
$gilC->api('GET', '/api/workload');
$wl = $gilC->json()['people'][$tea] ?? [];
check('Al asignar se ve la carga de la semana de cada persona, con los trabajos rápidos incluidos', $gilC->status === 200 && (float)($wl['capacity'] ?? 0) === 20.0 && (float)$wl['logged'] >= 16.5 && str_contains((string)$wl['text'], 'de 20 h esta semana'), json_encode($wl));
$teaC->api('GET', '/api/workload');
check('Quien no asigna trabajo solo ve su propia carga', $teaC->status === 200 && array_keys($teaC->json()['people'] ?? []) === [$tea], json_encode(array_keys($teaC->json()['people'] ?? [])));
$admin->get('/');
check('El diálogo de nueva tarea ofrece «Trabajos rápidos», la carga del responsable y «Crear y añadir otra»', str_contains($admin->body, 'value="quick"') && str_contains($admin->body, 'data-workload-select') && str_contains($admin->body, 'data-stay'));
$admin->api('POST', '/api/projects/quick/tasks', ['title' => 'Desde el diálogo general', 'assignee_id' => $tea]);
check('…y una tarea creada con «Trabajos rápidos» desde ese diálogo va al espacio de trabajos rápidos', $admin->status === 200 && $cnt('SELECT COUNT(*) FROM tasks WHERE title = ? AND project_id = ?', ['Desde el diálogo general', (int)$qp[0]['id']]) === 1, snippet($admin));
// capacity used up with work pending (the 15 h example)
$admin->api('PATCH', "/api/members/$uli", ['weekly_hours' => 15]);
$uliC->api('POST', '/api/quick', ['title' => 'Semana completa', 'estimate_hours' => 15, 'due_date' => $day(0)]);
$uw = $uliC->json()['task'] ?? [];
$uliC->api('POST', '/api/quick', ['title' => 'Todavía pendiente', 'estimate_hours' => 2, 'due_date' => $day(0)]);
$uliC->api('POST', '/api/tasks/' . (int)$uw['id'] . '/time', ['hours' => '15', 'work_date' => $day(0)]);
check('Con 15 de 15 h usadas y trabajo pendiente se avisa (también con trabajos rápidos)', (bool)$q("SELECT 1 FROM capacity_alerts WHERE workspace_id = ? AND user_id = ? AND status = 'open'", [$ws1, $uli])->fetchColumn());

function QuickName14(): string { return '>Trabajos rápidos<'; }

// ================================================================ projects
group('1.14 · Crear proyectos más simple');
$gilC->api('POST', '/api/projects', ['name' => 'Solo con nombre']);
$pMin = (int)($gilC->json()['id'] ?? 0);
check('Basta el nombre: la clave se sugiere sola', $gilC->status === 200 && $pMin && (string)$q('SELECT project_key FROM projects WHERE id = ?', [$pMin])->fetchColumn() !== '', snippet($gilC));
$admin->api('POST', '/api/projects', ['name' => 'Lanzamiento web', 'lead_user_id' => $gil, 'member_ids' => [$tea, $uli], 'start_date' => $day(0), 'due_date' => $day(30), 'description' => 'Publicar el sitio nuevo']);
$pL = (int)($admin->json()['id'] ?? 0);
$mem = array_column($q('SELECT pm.user_id, r.role_key FROM project_members pm JOIN roles r ON r.id = pm.role_id WHERE pm.project_id = ?', [$pL])->fetchAll(PDO::FETCH_ASSOC), 'role_key', 'user_id');
check('Al crear se eligen responsable (gestiona), integrantes, fechas y objetivo en un paso', $admin->status === 200 && ($mem[$gil] ?? '') === 'manager' && ($mem[$tea] ?? '') === 'contributor' && ($mem[$uli] ?? '') === 'contributor'
    && (bool)$q('SELECT 1 FROM projects WHERE id = ? AND lead_user_id = ? AND due_date = ? AND description = ?', [$pL, $gil, $day(30), 'Publicar el sitio nuevo'])->fetchColumn(), json_encode($mem));
check('…y cada integrante recibe el aviso', $cnt("SELECT COUNT(*) FROM notifications WHERE project_id = ? AND user_id IN (?, ?)", [$pL, $tea, $uli]) >= 2);
$admin->api('POST', '/api/projects', ['name' => 'Con alguien de fuera', 'member_ids' => [999999]]);
check('Una persona que no es del espacio se rechaza', $admin->status === 422);
$admin->get('/');
check('El diálogo de nuevo proyecto separa lo esencial de las opciones avanzadas', str_contains($admin->body, 'name="member_ids[]"') && str_contains($admin->body, 'data-fill-dates') && substr_count($admin->body, 'Más opciones') >= 2);

// ================================================================ Gantt
group('1.14 · Carta Gantt');
$mkT = function (array $in) use ($gilC, $pL): array { $gilC->api('POST', "/api/projects/$pL/tasks", $in); return $gilC->json()['task'] ?? []; };
$g1 = $mkT(['title' => 'Diseño', 'assignee_id' => $tea, 'start_date' => $day(0), 'due_date' => $day(5), 'estimate_hours' => 8]);
$g2 = $mkT(['title' => 'Desarrollo', 'assignee_id' => $uli, 'start_date' => $day(3), 'due_date' => $day(12)]);
$g3 = $mkT(['title' => 'Publicación', 'due_date' => $day(20), 'is_milestone' => 1]);
$g4 = $mkT(['title' => 'Atrasada', 'assignee_id' => $tea, 'start_date' => $day(-6), 'due_date' => $day(-2)]);
$gs = $mkT(['title' => 'Maquetas', 'parent_id' => (int)$g1['id'], 'start_date' => $day(1), 'due_date' => $day(2)]);
$gilC->api('POST', '/api/tasks/' . (int)$g1['id'] . '/links', ['target' => $g2['key'], 'link_type' => 'blocks']);
$gilC->api('PATCH', '/api/tasks/' . (int)$g2['id'], ['due_date' => $day(15), 'change_reason' => 'Depende de un proveedor']);
check('Preparación: tareas con fechas, subtarea, hito, dependencia y una fecha cambiada', $g1 && $g2 && $g3 && $g4 && $gs && $cnt("SELECT COUNT(*) FROM task_links WHERE link_type = 'blocks' AND source_task_id = ?", [(int)$g1['id']]) === 1);
$gilC->get("/projects/$pL/timeline?scale=week");
$b = $gilC->body;
check('La carta Gantt se arma sola con tareas, subtareas, responsables y avance', $gilC->status === 200 && str_contains($b, 'data-gantt-bar="' . $g1['id'] . '"') && str_contains($b, 'data-gantt-row="' . $gs['id'] . '"') && str_contains($b, 'Tea Trabajadora'), snippet($gilC));
check('…con hitos, dependencias y retrasos', str_contains($b, 'gantt-milestone') && preg_match('/"from":' . (int)$g1['id'] . ',"to":' . (int)$g2['id'] . '/', $b) && str_contains($b, '1 con retraso'));
check('…y compara la planificación original con la actual', str_contains($b, 'data-gantt-base') && str_contains($b, 'fuera de su plan original'));
$scales = [];
foreach (['day', 'week', 'month'] as $sc) { $gilC->get("/projects/$pL/timeline?scale=$sc"); $scales[$sc] = $gilC->status === 200 && str_contains($gilC->body, 'data-gantt-scale="' . $sc . '" ') === false ? $gilC->status : $gilC->status; }
$gilC->get("/projects/$pL/timeline?scale=day");
$dayOk = $gilC->status === 200 && str_contains($gilC->body, 'aria-current="page" data-gantt-scale="day"');
$gilC->get("/projects/$pL/timeline?scale=month");
check('Escalas por días, semanas y meses', $dayOk && $gilC->status === 200 && str_contains($gilC->body, 'aria-current="page" data-gantt-scale="month"'), json_encode($scales));
$gilC->api('PATCH', '/api/tasks/' . (int)$g3['id'], ['due_date' => $day(22)]);
$gilC->get("/projects/$pL/timeline?scale=week");
check('Se actualiza sola al cambiar una fecha (sin rehacer nada)', substr_count($gilC->body, 'data-gantt-base') === 2, (string)substr_count($gilC->body, 'data-gantt-base'));
$teaC->api('POST', "/api/projects/$pL/baseline");
check('Solo quien gestiona el proyecto fija una nueva planificación original', in_array($teaC->status, [403, 404], true));
$gilC->api('POST', "/api/projects/$pL/baseline");
$nb = (int)($gilC->json()['changed'] ?? 0);
$gilC->get("/projects/$pL/timeline?scale=week");
check('Al fijarla, la comparación vuelve a cero y el cambio queda en el registro', $nb >= 2 && !str_contains($gilC->body, 'data-gantt-base') && $cnt("SELECT COUNT(*) FROM activity WHERE action = 'baseline' AND entity_id = ? AND category = 'calendar'", [$pL]) === 1, (string)$nb);
$teaC->get("/projects/$pL/timeline");
check('Quien colabora también ve la carta Gantt', $teaC->status === 200 && str_contains($teaC->body, 'data-gantt'));
$uliC->get("/projects/$pE/timeline");
$gusC->get("/projects/$pL/timeline");
check('Quien no pertenece al proyecto no la ve', in_array($gusC->status, [403, 404], true));

// ================================================================ analysis by period
group('1.14 · Análisis histórico por período');
$okP = [];
foreach (['week', 'month', 'quarter', 'year'] as $p) { $admin->get("/analysis?period=$p"); $okP[$p] = $admin->status === 200 && str_contains($admin->body, 'data-movement'); }
check('Análisis por semana, mes, trimestre y año', !in_array(false, $okP, true), json_encode($okP));
$admin->get("/analysis?period=week&project_id=$pL");
$mvT = preg_match('/data-mv="tasks">([^<]*)</', $admin->body, $m) ? $m[1] : '';
check('«¿Qué ocurrió en el período?» cuenta los trabajos creados y finalizados del período (las subtareas cuentan con su tarea)', str_contains($mvT, '4 creados'), $mvT);
$admin->get('/analysis?period=week&user_id=' . $tea);
$mvH = preg_match('/data-mv="hours">([^<]*)</', $admin->body, $m) ? $m[1] : '';
check('…filtrado por persona, con las horas registradas e imprevistos aparte', $admin->status === 200 && str_contains($admin->body, 'name="user_id"') && preg_match('/^1[0-9][,.]?\d* h$/', trim($mvH)) === 1 && str_contains($admin->body, 'en imprevistos'), $mvH);
$teaC->get('/analysis?period=week&user_id=' . $uli);
check('Quien no administra no puede filtrar por otra persona', $teaC->status === 200 && !str_contains($teaC->body, 'name="user_id"'), snippet($teaC));

// ================================================================ upgrade
group('1.14 · Actualización desde la 1.13');
$admin->api('POST', "/api/projects/$pL/tasks", ['title' => 'Venía de la 1.13', 'estimate_hours' => 7, 'start_date' => $day(2), 'due_date' => $day(9)]);
$tu = (int)($admin->json()['task']['id'] ?? 0);
$q('INSERT INTO task_due_changes (task_id, changed_by, old_due, new_due, changed_at) VALUES (?,?,?,?,UTC_TIMESTAMP())', [$tu, $adminId, $day(4), $day(9)]);
$q('UPDATE tasks SET admin_estimate_hours = NULL, baseline_start = NULL, baseline_due = NULL WHERE id = ?', [$tu]);
$q("UPDATE users SET prefs = JSON_MERGE_PATCH(COALESCE(prefs, '{}'), '{\"notify\":{\"push\":false}}') WHERE id = ?", [$tea]);
$q('INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth, device, created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())', [$tea, 'https://push.example.test/tea', hash('sha256', 'https://push.example.test/tea'), $b64($uaPub), $b64($uaAuth), 'Prueba']);
$q("UPDATE users SET prefs = JSON_MERGE_PATCH(COALESCE(prefs, '{}'), '{\"notify\":{\"push\":false}}') WHERE id = ?", [$gus]);
$pdo->exec("DELETE FROM schema_migrations WHERE version = '016_v114'");
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/migrate.php') . ' --no-backup 2>&1', $mout, $mcode);
$up = $q('SELECT admin_estimate_hours, baseline_start, baseline_due FROM tasks WHERE id = ?', [$tu])->fetch(PDO::FETCH_ASSOC);
check('Al actualizar se conserva todo: la estimación existente pasa a ser la de administración', $mcode === 0 && str_contains(implode("\n", $mout), 'Aplicada: 016_v114') && (float)$up['admin_estimate_hours'] === 7.0, implode("\n", $mout));
check('…la planificación original sale de la primera fecha registrada (antes de cualquier cambio)', $up['baseline_due'] === $day(4) && $up['baseline_start'] === $day(2), json_encode($up));
$pt = json_decode((string)$q('SELECT prefs FROM users WHERE id = ?', [$tea])->fetchColumn(), true)['notify']['push'] ?? null;
$pg = json_decode((string)$q('SELECT prefs FROM users WHERE id = ?', [$gus])->fetchColumn(), true)['notify']['push'] ?? null;
check('…quien tenía un dispositivo activado pero el aviso apagado por el error de la 1.13 lo recupera; quien no tenía dispositivo, no se toca', $pt === true && $pg === false, json_encode([$pt, $pg]));
check('…y las tareas, horas y avisos existentes siguen ahí', $cnt('SELECT COUNT(*) FROM tasks WHERE id IN (?, ?, ?)', [(int)$tE['id'], (int)$qk['id'], $tu]) === 3 && $cnt('SELECT COUNT(*) FROM time_entries WHERE task_id = ?', [(int)$tE['id']]) === 1);
$q('DELETE FROM push_subscriptions WHERE endpoint = ?', ['https://push.example.test/tea']);
