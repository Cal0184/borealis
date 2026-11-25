<?php
declare(strict_types=1);

/*
 * Borealis 1.9 — integration checks. Included by run.php after v18.php (same globals: $admin, $q,
 * $pdo, $role, $ws1, $mkUser, $BASE, $googleConnect, check(), group(), snippet()).
 * Google is the local stand-in tests/fake-google/server.php and e-mail goes to the local SMTP server
 * tests/fake-services/smtp.php (with STARTTLS and a certificate made here). They prove Borealis'
 * side; real Google and a real mail server still have to be tried on the real installation.
 */

$root = dirname(__DIR__, 2);
$cnt = fn(string $sql, array $p = []) => (int)$q($sql, $p)->fetchColumn();
$installTz = new DateTimeZone('America/Mexico_City'); // the installer of run.php uses this zone
$localDay = fn(int $d) => (new DateTimeImmutable('now', $installTz))->modify("$d day")->format('Y-m-d');
$cron = function (?string $nowUtc = null) use ($root): array {
    $env = $nowUtc ? 'BOREALIS_NOW=' . escapeshellarg($nowUtc) . ' ' : '';
    exec($env . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/notify.php') . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
};

// ---------------------------------------------------------------- stand-ins: Google and SMTP
$g9Port = (int)(getenv('BOREALIS_FAKE_GOOGLE19_PORT') ?: 8097);
$g9 = "http://127.0.0.1:$g9Port";
$g9State = sys_get_temp_dir() . '/borealis-fake-google19-' . getmypid() . '.json';
@unlink($g9State);
$g9Proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$g9Port", $root . '/tests/fake-google/server.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $g9Pipes, null, ['FAKE_GOOGLE_STATE' => $g9State, 'PATH' => getenv('PATH')]);
$g9Call = function (array $b) use ($g9): array {
    $ch = curl_init("$g9/_control");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($b)]);
    $r = (string)curl_exec($ch); curl_close($ch);
    return json_decode($r, true) ?: [];
};
$gEvents = function (string $cal = 'primary@test') use ($g9Call): array {
    return array_filter($g9Call(['action' => 'state'])['calendars'][$cal]['events'] ?? [], fn($e) => ($e['status'] ?? '') !== 'cancelled' && empty($e['purged']));
};
$gLog = fn() => $g9Call(['action' => 'state'])['log'] ?? [];
$byRef = fn(array $evs, string $ref) => array_values(array_filter($evs, fn($e) => ($e['extendedProperties']['private']['borealis_ref'] ?? '') === $ref));

// self-signed certificate for «localhost» (STARTTLS of the stand-in)
$certFile = sys_get_temp_dir() . '/borealis-smtp-' . getmypid() . '.pem';
$pk = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
$crt = openssl_csr_sign(openssl_csr_new(['commonName' => 'localhost'], $pk), null, $pk, 2);
openssl_x509_export($crt, $crtPem); openssl_pkey_export($pk, $keyPem);
file_put_contents($certFile, $crtPem . $keyPem);
file_put_contents($certFile . '.crt', $crtPem);
$smtpPort = (int)(getenv('BOREALIS_FAKE_SMTP_PORT') ?: 2526);
$smtpPlainPort = $smtpPort + 1;
$smtpLog = sys_get_temp_dir() . '/borealis-smtp-' . getmypid() . '.jsonl';
$smtpDown = sys_get_temp_dir() . '/borealis-smtp-down-' . getmypid();
@unlink($smtpLog); @unlink($smtpDown);
$smtpEnv = ['FAKE_SMTP_LOG' => $smtpLog, 'FAKE_SMTP_CERT' => $certFile, 'FAKE_SMTP_USER' => 'avisos@example.test', 'FAKE_SMTP_PASS' => 'clave-smtp-de-prueba', 'FAKE_SMTP_DOWN_FILE' => $smtpDown, 'PATH' => getenv('PATH')];
$smtpProc = proc_open([PHP_BINARY, $root . '/tests/fake-services/smtp.php', (string)$smtpPort], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $sp1, null, $smtpEnv);
$smtpPlainProc = proc_open([PHP_BINARY, $root . '/tests/fake-services/smtp.php', (string)$smtpPlainPort], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $sp2, null, ['FAKE_SMTP_CERT' => ''] + $smtpEnv);
$mails = fn() => array_values(array_filter(array_map(fn($l) => json_decode($l, true), is_file($smtpLog) ? file($smtpLog, FILE_IGNORE_NEW_LINES) : [])));
usleep(500000);

$cfgPath19 = $root . '/config/config.php';
$cfgOrig19 = (string)file_get_contents($cfgPath19);
$cfg19 = require $cfgPath19;
$cfg19['google'] = ['client_id' => 'test-client.apps', 'client_secret' => 'test-secret', 'auth_url' => "$g9/o/oauth2/v2/auth", 'token_url' => "$g9/token", 'revoke_url' => "$g9/revoke", 'userinfo_url' => "$g9/userinfo", 'api_base' => $g9];
$mailCfg = ['transport' => 'smtp', 'host' => 'localhost', 'port' => $smtpPort, 'encryption' => 'tls', 'username' => 'avisos@example.test', 'password' => 'clave-smtp-de-prueba',
    'from_address' => 'avisos@example.test', 'from_name' => 'Borealis Pruebas', 'cafile' => $certFile . '.crt', 'delay_minutes' => 0];
$writeCfg = function (array $extra) use ($cfg19, $cfgPath19) {
    file_put_contents($cfgPath19, "<?php\nreturn " . var_export(array_replace($cfg19, $extra), true) . ";\n");
    usleep(3200000); // opcache revalidation of the web server
};
$writeCfg([]);

// ---------------------------------------------------------------- people and project
group('1.9 · Preparación');
[$gabi, $gabiC] = $mkUser('Gabi Google', 'gabi.google@example.test', 'member');
[$hugo, $hugoC] = $mkUser('Hugo Hora', 'hugo.hora@example.test', 'member');
$admin->api('POST', '/api/projects', ['name' => 'Proyecto Diecinueve', 'project_key' => 'NUE', 'description' => 'Pruebas 1.9']);
$p19 = (int)($admin->json()['id'] ?? 0);
$admin->api('POST', "/api/projects/$p19/modules", ['module' => 'sprints', 'enabled' => true]);
foreach ([$gabi, $hugo] as $uid) $admin->api('POST', "/api/projects/$p19/members", ['user_id' => $uid, 'role_id' => $role($ws1, 'project', 'contributor')]);
$t19 = function (string $title, array $extra = []) use ($admin, $p19): array {
    $admin->api('POST', "/api/projects/$p19/tasks", ['title' => $title] + $extra);
    return $admin->json()['task'] ?? [];
};
check('Dos personas en un proyecto nuevo', $gabi && $hugo && $p19);

// ================================================================ hour of delivery
group('1.9 · Hora de entrega');
$tH = $t19('Entregar informe con hora', ['assignee_id' => $gabi, 'due_date' => $localDay(2), 'due_time' => '17:30', 'estimate_hours' => 2, 'description' => "Revisar **cifras** y enviar.\n\nDetalle largo que no debería pasar de trescientos caracteres."]);
check('Una tarea puede tener hora de entrega (opcional)', $admin->status === 200 && (string)$q('SELECT due_time FROM tasks WHERE id = ?', [(int)($tH['id'] ?? 0)])->fetchColumn() === '17:30:00', snippet($admin));
$admin->api('POST', "/api/projects/$p19/tasks", ['title' => 'Hora sin fecha', 'due_time' => '10:00']);
check('Una hora sin fecha se rechaza con el motivo', $admin->status === 422 && isset($admin->json()['errors']['due_time']), snippet($admin));
$admin->api('PATCH', '/api/tasks/' . (int)($tH['id'] ?? 0), ['due_time' => '25:00']);
check('Una hora inventada (25:00) se rechaza', $admin->status === 422 && isset($admin->json()['errors']['due_time']), snippet($admin));
$tTmp = $t19('Quitar fecha quita la hora', ['due_date' => $localDay(3), 'due_time' => '09:00']);
$admin->api('PATCH', '/api/tasks/' . (int)($tTmp['id'] ?? 0), ['due_date' => '']);
check('Quitar la fecha quita también la hora', $q('SELECT due_time FROM tasks WHERE id = ?', [(int)($tTmp['id'] ?? 0)])->fetchColumn() === null);
$admin->api('PATCH', '/api/tasks/' . (int)($tH['id'] ?? 0), ['due_time' => '18:00']);
check('Cambiar la hora avisa a quien es responsable, con la hora', $cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'date' AND task_id = ? AND message LIKE '%a las 18:00%'", [$gabi, (int)($tH['id'] ?? 0)]) === 1);
$admin->api('PATCH', '/api/tasks/' . (int)($tH['id'] ?? 0), ['due_time' => '17:30']);
$gabiC->get('/projects/' . $p19 . '/board');
check('El tablero muestra la hora junto a la fecha', str_contains($gabiC->body, ' · 17:30'), snippet($gabiC));
$gabiC->get('/t/' . ($tH['key'] ?? 'NUE-1'));
check('La ficha de la tarea tiene el campo de hora', str_contains($gabiC->body, 'name="due_time"') && str_contains($gabiC->body, 'value="17:30"'), snippet($gabiC));

// ================================================================ Google Calendar, Borealis → Google
group('1.9 · Google Calendar: conectar y elegir calendario');
$googleConnect($gabiC);
check('Gabi conecta su cuenta de Google (OAuth con PKCE, sin contraseña)', $q('SELECT status FROM google_accounts WHERE user_id = ?', [$gabi])->fetchColumn() === 'active');
$gabiC->get('/profile');
check('Perfil › Google Calendar: estado «Conectado» y el apartado para enviar el trabajo', str_contains($gabiC->body, '>Conectado<') && str_contains($gabiC->body, 'Enviar mi trabajo a Google Calendar') && str_contains($gabiC->body, 'data-google-export'), snippet($gabiC));
$g9Call(['action' => 'add_calendar', 'id' => 'trabajo@test', 'summary' => 'Trabajo', 'accessRole' => 'writer']);
$g9Call(['action' => 'add_event', 'id' => 'externo1', 'summary' => 'Evento personal de Gabi', 'start' => ['dateTime' => $localDay(2) . 'T09:00:00-06:00'], 'end' => ['dateTime' => $localDay(2) . 'T10:00:00-06:00']]);
$gabiC->api('GET', '/api/google/export');
$wcals = array_column($gabiC->json()['calendars'] ?? [], 'id');
check('Solo se ofrecen los calendarios donde puede crear eventos (no los de solo lectura)', $gabiC->status === 200 && in_array('primary@test', $wcals, true) && in_array('trabajo@test', $wcals, true) && !in_array('festivos@test', $wcals, true), snippet($gabiC));
check('Antes de elegir, el estado es «apagado»', ($gabiC->json()['status']['state'] ?? '') === 'off');
$gabiC->api('POST', '/api/google/export', ['calendar_id' => 'festivos@test', 'mine' => '1']);
check('Elegir un calendario de solo lectura se rechaza', $gabiC->status === 422 && isset($gabiC->json()['errors']['calendar_id']), snippet($gabiC));
$gabiC->api('POST', '/api/google/export', ['calendar_id' => 'primary@test']);
check('Hay que elegir al menos qué enviar', $gabiC->status === 422 && isset($gabiC->json()['errors']['mine']), snippet($gabiC));

group('1.9 · Google Calendar: tareas en el calendario, sin duplicados');
$tD = $t19('Revisar contrato', ['assignee_id' => $gabi, 'due_date' => $localDay(4)]);
$tNoDate = $t19('Sin fecha', ['assignee_id' => $gabi]);
$tHugo = $t19('Tarea de Hugo', ['assignee_id' => $hugo, 'due_date' => $localDay(4)]);
$tCo = $t19('Tarea compartida', ['assignee_id' => $hugo, 'due_date' => $localDay(5)]);
$admin->api('PATCH', '/api/tasks/' . (int)($tCo['id'] ?? 0), ['assignee_ids' => [$hugo, $gabi]]);
$gabiC->api('POST', '/api/google/export', ['calendar_id' => 'primary@test', 'mine' => '1', 'details' => 'full', 'done' => 'remove']);
$evs = $gEvents();
$eH = $byRef($evs, 'task:' . ($tH['id'] ?? 0))[0] ?? [];
$eD = $byRef($evs, 'task:' . ($tD['id'] ?? 0))[0] ?? [];
check('Al elegir el calendario se envían sus tareas con fecha y se confirma', $gabiC->status === 200 && str_contains((string)($gabiC->json()['message'] ?? ''), 'nuevos') && $eH && $eD, snippet($gabiC));
check('El evento lleva la clave y el nombre de la tarea', ($eH['summary'] ?? '') === '[' . ($tH['key'] ?? '') . '] Entregar informe con hora', json_encode($eH['summary'] ?? null));
$desc = (string)($eH['description'] ?? '');
check('…el proyecto, el tiempo estimado, una descripción breve y el enlace directo a Borealis', str_contains($desc, 'Proyecto: Proyecto Diecinueve') && str_contains($desc, 'Tiempo estimado: 2 h') && str_contains($desc, 'Revisar cifras y enviar')
    && str_contains($desc, $BASE . '/t/' . ($tH['key'] ?? '')) && !str_contains($desc, '**'), $desc);
check('Con hora: un bloque que termina a la hora de entrega y dura lo estimado (15:30–17:30, hora de la instalación)', str_starts_with((string)($eH['start']['dateTime'] ?? ''), $localDay(2) . 'T15:30:00') && str_starts_with((string)($eH['end']['dateTime'] ?? ''), $localDay(2) . 'T17:30:00'), json_encode([$eH['start'] ?? null, $eH['end'] ?? null]));
check('Solo fecha: evento de todo el día', ($eD['start']['date'] ?? '') === $localDay(4) && ($eD['end']['date'] ?? '') === $localDay(5), json_encode([$eD['start'] ?? null, $eD['end'] ?? null]));
check('Marcado como de Borealis, sin ocupar la agenda y sin recordatorios repetidos de Google', ($eH['extendedProperties']['private']['borealis'] ?? '') === '1' && ($eH['transparency'] ?? '') === 'transparent' && ($eH['reminders']['useDefault'] ?? null) === false && str_starts_with((string)($eH['id'] ?? ''), 'brl'));
check('También las tareas en que figura como corresponsable', (bool)$byRef($evs, 'task:' . ($tCo['id'] ?? 0)));
check('No se envían tareas sin fecha ni tareas de otras personas', !$byRef($evs, 'task:' . ($tNoDate['id'] ?? 0)) && !$byRef($evs, 'task:' . ($tHugo['id'] ?? 0)));
check('Los eventos propios de Gabi siguen ahí, intactos', isset($evs['externo1']) && $evs['externo1']['summary'] === 'Evento personal de Gabi');
$nBorealis = fn() => count(array_filter($gEvents(), fn($e) => ($e['extendedProperties']['private']['borealis'] ?? '') === '1'));
$n0 = $nBorealis();
$admin->api('PATCH', '/api/tasks/' . (int)($tH['id'] ?? 0), ['title' => 'Entregar informe final']);
$eH2 = $byRef($gEvents(), 'task:' . ($tH['id'] ?? 0));
check('Al cambiar la tarea en Borealis se actualiza el mismo evento, al momento y sin duplicarlo', count($eH2) === 1 && ($eH2[0]['id'] ?? '') === ($eH['id'] ?? '-') && str_contains((string)$eH2[0]['summary'], 'Entregar informe final') && $nBorealis() === $n0);
$logBefore = count($gLog());
$gabiC->api('POST', '/api/google/export/sync');
$gabiC->api('POST', '/api/google/export/sync');
$writes = array_filter(array_slice($gLog(), $logBefore), fn($l) => preg_match('/^(POST|PATCH|DELETE) \/calendar/', $l));
check('Sincronizar sin cambios no vuelve a escribir en Google', $gabiC->status === 200 && !$writes && $nBorealis() === $n0, json_encode(array_values($writes)));
$admin->api('PATCH', '/api/tasks/' . (int)($tD['id'] ?? 0), ['due_date' => $localDay(6)]);
$eD2 = $byRef($gEvents(), 'task:' . ($tD['id'] ?? 0));
check('Cambiar la fecha mueve el evento', count($eD2) === 1 && ($eD2[0]['start']['date'] ?? '') === $localDay(6));
$admin->api('POST', '/api/tasks/' . (int)($tD['id'] ?? 0) . '/done', ['done' => true]);
check('Al completarla se quita del calendario (opción por defecto) y nada más se toca', !$byRef($gEvents(), 'task:' . ($tD['id'] ?? 0)) && isset($gEvents()['externo1']));
$admin->api('PATCH', '/api/tasks/' . (int)($tCo['id'] ?? 0), ['assignee_ids' => [$hugo]]);
check('Si deja de ser responsable, el evento se quita de su calendario', !$byRef($gEvents(), 'task:' . ($tCo['id'] ?? 0)));
$gabiC->api('POST', '/api/google/export', ['calendar_id' => 'primary@test', 'mine' => '1', 'done' => 'keep']);
$eDone = $byRef($gEvents(), 'task:' . ($tD['id'] ?? 0))[0] ?? [];
check('Con «Dejarla, marcada con ✓», la completada queda en el calendario con ✓', str_starts_with((string)($eDone['summary'] ?? ''), '✓ ['), json_encode($eDone['summary'] ?? null));
// Lost track (e.g. a timeout after Google created the event): deterministic ids avoid a duplicate.
$q('DELETE FROM google_exports WHERE user_id = ?', [$gabi]);
$n1 = $nBorealis();
$gabiC->api('POST', '/api/google/export/sync');
check('Si Borealis pierde el registro de un evento ya creado, Google responde «ya existe» y se actualiza sin duplicar', $gabiC->status === 200 && $nBorealis() === $n1 && $cnt('SELECT COUNT(*) FROM google_exports WHERE user_id = ?', [$gabi]) === $n1, snippet($gabiC) . " $n1 vs " . $nBorealis());
// Deleted for good in Google: created again (once) on the next change.
$g9Call(['action' => 'purge_event', 'id' => (string)($eH['id'] ?? '')]);
$admin->api('PATCH', '/api/tasks/' . (int)($tH['id'] ?? 0), ['title' => 'Entregar informe (otra vez)']);
check('Si el evento se borró del todo en Google, el siguiente cambio lo vuelve a crear una sola vez', count($byRef($gEvents(), 'task:' . ($tH['id'] ?? 0))) === 1);

group('1.9 · Google Calendar: opciones, privacidad y errores');
$admin->api('POST', "/api/projects/$p19/sprints", ['name' => 'Sprint Diecinueve', 'start_date' => $localDay(1), 'end_date' => $localDay(10)]);
$admin->api('PATCH', "/api/projects/$p19", ['due_date' => $localDay(20)]);
$gabiC->api('POST', '/api/google/export', ['calendar_id' => 'primary@test', 'mine' => '1', 'sprints' => '1', 'details' => 'basic']);
$evs = $gEvents();
$spr = array_values(array_filter($evs, fn($e) => str_starts_with((string)($e['extendedProperties']['private']['borealis_ref'] ?? ''), 'sprint:')));
$prj = $byRef($evs, 'project:' . $p19);
check('«Sprints y entregas»: el sprint como evento de varios días y la entrega del proyecto', $spr && ($spr[0]['start']['date'] ?? '') === $localDay(1) && ($spr[0]['end']['date'] ?? '') === $localDay(11) && str_contains((string)$spr[0]['summary'], 'Sprint Diecinueve') && $prj && ($prj[0]['start']['date'] ?? '') === $localDay(20), json_encode($spr[0] ?? null));
$eB = $byRef($evs, 'task:' . ($tH['id'] ?? 0))[0] ?? [];
check('«Solo título, proyecto y enlace»: no viaja la descripción ni el estado', str_contains((string)($eB['description'] ?? ''), 'Proyecto: Proyecto Diecinueve') && !str_contains((string)($eB['description'] ?? ''), 'Revisar cifras') && !str_contains((string)($eB['description'] ?? ''), 'Estado:'), (string)($eB['description'] ?? ''));
$gabiC->api('POST', '/api/google/export', ['calendar_id' => 'trabajo@test', 'mine' => '1', 'sprints' => '1']);
check('Cambiar de calendario mueve los eventos de Borealis (salen del anterior, entran en el nuevo)', $gabiC->status === 200 && !array_filter($gEvents(), fn($e) => ($e['extendedProperties']['private']['borealis'] ?? '') === '1') && count($gEvents('trabajo@test')) >= 3 && isset($gEvents()['externo1']), snippet($gabiC));
$g9Call(['action' => 'quota', 'times' => 1]);
$tQ = $t19('Tarea durante el límite de Google', ['assignee_id' => $gabi, 'due_date' => $localDay(7)]);
$acc = $q('SELECT export_state, export_error, export_retry_at FROM google_accounts WHERE user_id = ?', [$gabi])->fetch();
check('Si Google pide esperar (cuota), Borealis sigue funcionando, guarda el motivo y programa un reintento', $admin->status === 200 && $acc['export_state'] === 'error' && str_contains((string)$acc['export_error'], 'esperar') && $acc['export_retry_at'] !== null, json_encode($acc));
$gabiC->get('/profile');
check('…y el perfil lo muestra', str_contains($gabiC->body, 'Se reintentará solo'), snippet($gabiC));
[$c1, $o1] = $cron();
check('El cron respeta la pausa del reintento (no insiste antes de tiempo)', $c1 === 0 && !$byRef($gEvents('trabajo@test'), 'task:' . ($tQ['id'] ?? 0)), $o1);
$q('UPDATE google_accounts SET export_retry_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE user_id = ?', [$gabi]);
[$c2, $o2] = $cron();
check('Pasada la pausa, el cron la envía (sin tener Borealis abierto) y vuelve a «Funcionando»', $c2 === 0 && count($byRef($gEvents('trabajo@test'), 'task:' . ($tQ['id'] ?? 0))) === 1 && $q('SELECT export_state FROM google_accounts WHERE user_id = ?', [$gabi])->fetchColumn() === 'ok', $o2);
$hugoC->api('GET', '/api/google/export');
check('Otra persona no ve nada de la conexión de Gabi', $hugoC->status === 200 && ($hugoC->json()['status']['state'] ?? '') === 'disconnected' && !isset($hugoC->json()['status']['calendar_id']), snippet($hugoC));
$hugoC->api('POST', '/api/google/export/sync');
check('…ni puede sincronizar sin cuenta propia', $hugoC->status === 409);
$anon = new Client($BASE);
$anon->api('GET', '/api/google/export', null, false);
check('Sin sesión no hay acceso', $anon->status === 401);
$gabiC->get('/profile');
check('El navegador nunca recibe los tokens de Google', !preg_match('/\b(at|rt)-[0-9a-f]{12}\b/', $gabiC->body));
$errLog = $root . '/storage/logs/error.log';
check('Los registros de errores no contienen tokens', !is_file($errLog) || !preg_match('/\b(at|rt)-[0-9a-f]{12}\b/', (string)file_get_contents($errLog)));
$gabiC->api('POST', '/api/google/export/stop', ['remove' => true]);
check('«Dejar de enviar» quita solo los eventos de Borealis del calendario', $gabiC->status === 200 && !count($gEvents('trabajo@test')) && isset($gEvents()['externo1']) && !$cnt('SELECT COUNT(*) FROM google_exports WHERE user_id = ?', [$gabi]), snippet($gabiC));

group('1.9 · Google Calendar: desconectar y reconectar');
$gabiC->api('POST', '/api/google/export', ['calendar_id' => 'primary@test', 'mine' => '1']);
$nKeep = $nBorealis();
$gabiC->api('POST', '/api/google/disconnect');
check('Desconectar sin quitar: los tokens se borran y los eventos se quedan en Google', $gabiC->status === 200 && !$cnt('SELECT COUNT(*) FROM google_accounts WHERE user_id = ?', [$gabi]) && $nBorealis() === $nKeep && $nKeep > 0);
$googleConnect($gabiC);
$gabiC->api('POST', '/api/google/export', ['calendar_id' => 'primary@test', 'mine' => '1']);
check('Reconectar y volver a elegir el mismo calendario no duplica los eventos', $gabiC->status === 200 && $nBorealis() === $nKeep, snippet($gabiC) . " $nKeep vs " . $nBorealis());
$g9Call(['action' => 'revoke']);
$admin->api('PATCH', '/api/tasks/' . (int)($tH['id'] ?? 0), ['title' => 'Cambio con permiso retirado']);
$gabiC->get('/profile');
check('Si Google retira el permiso: «Error de autorización» y «Volver a conectar»; Borealis sigue funcionando', $admin->status === 200 && $q('SELECT status FROM google_accounts WHERE user_id = ?', [$gabi])->fetchColumn() === 'revoked' && str_contains($gabiC->body, 'Error de autorización') && str_contains($gabiC->body, 'Volver a conectar'), snippet($gabiC));
$googleConnect($gabiC);
$gabiC->api('POST', '/api/google/export/sync');
check('Al reconectar se pone al día solo lo que cambió', $gabiC->status === 200 && str_contains((string)($byRef($gEvents(), 'task:' . ($tH['id'] ?? 0))[0]['summary'] ?? ''), 'Cambio con permiso retirado'), snippet($gabiC));
$gabiC->api('POST', '/api/google/disconnect', ['remove_events' => true]);
check('«Desconectar y quitar sus eventos»: se quitan solo los de Borealis y se desconecta', $gabiC->status === 200 && $nBorealis() === 0 && isset($gEvents()['externo1']) && !$cnt('SELECT COUNT(*) FROM google_accounts WHERE user_id = ?', [$gabi]), snippet($gabiC));

// ================================================================ notifications 1.9
group('1.9 · Preferencias de notificaciones');
$hugoC->get('/profile');
check('Perfil: avisos antes de la entrega, zona horaria, horario de silencio y correo', str_contains($hugoC->body, 'name="before[]"') && str_contains($hugoC->body, 'name="timezone"') && str_contains($hugoC->body, 'name="quiet_on"') && str_contains($hugoC->body, 'name="email"') && str_contains($hugoC->body, 'data-pwa-box'), snippet($hugoC));
$def = fn($k) => str_contains($hugoC->body, 'name="before[]" value="' . $k . '" checked');
check('Por defecto: 24 horas y 30 minutos antes; resumen semanal apagado; correo encendido', $def(1440) && $def(30) && !$def(120) && !str_contains($hugoC->body, 'name="in_app[]" value="weekly" checked') && str_contains($hugoC->body, 'name="email" value="1" checked')
    && str_contains($hugoC->body, 'name="in_app[]" value="assigned" checked') && str_contains($hugoC->body, 'name="in_app[]" value="due" checked') && str_contains($hugoC->body, 'name="in_app[]" value="overdue" checked') && str_contains($hugoC->body, 'name="in_app[]" value="sprint" checked'));
$cats = ['assigned', 'due', 'overdue', 'date', 'calendar', 'sprint', 'project', 'comments', 'capacity'];
$hugoC->api('PATCH', '/api/profile/notifications', ['in_app' => $cats, 'timezone' => 'Marte/Olimpo']);
check('Una zona horaria inventada se rechaza', $hugoC->status === 422 && isset($hugoC->json()['errors']['timezone']));
$hugoC->api('PATCH', '/api/profile/notifications', ['in_app' => $cats, 'quiet_on' => '1', 'quiet_from' => '24:00', 'quiet_to' => '07:00']);
check('Una hora de silencio inventada se rechaza', $hugoC->status === 422 && isset($hugoC->json()['errors']['quiet_from']));
$hugoC->api('PATCH', '/api/profile/notifications', ['in_app' => array_merge($cats, ['weekly']), 'before' => [1440, 30], 'timezone' => 'America/Santiago', 'quiet_on' => '', 'email' => '1', 'email_cats' => ['assigned', 'due', 'overdue', 'sprint', 'weekly']]);
$hp = json_decode((string)$q('SELECT prefs FROM users WHERE id = ?', [$hugo])->fetchColumn(), true) ?: [];
check('Las preferencias se guardan por persona (zona, silencio, correo, resumen semanal)', $hugoC->status === 200 && ($hp['timezone'] ?? '') === 'America/Santiago' && ($hp['notify']['before'] ?? []) === [1440, 30] && ($hp['notify']['quiet']['on'] ?? true) === false && !empty($hp['notify']['weekly']) && !empty($hp['notify']['email']), json_encode($hp));
$gp = json_decode((string)$q('SELECT prefs FROM users WHERE id = ?', [$gabi])->fetchColumn(), true) ?: [];
check('…y no cambian las de otra persona', !isset($gp['timezone']) && !isset($gp['notify']['before']));

group('1.9 · Recordatorios: 24 h y 30 min, sin Borealis abierto');
// A task due in 4 days at 10:00 (installation's hour). The cron runs with a pinned clock.
$dueDay = $localDay(4);
$tR = $t19('Presentar avance', ['assignee_id' => $hugo, 'due_date' => $dueDay, 'due_time' => '10:00']);
$dueUtc = (new DateTimeImmutable("$dueDay 10:00", $installTz))->setTimezone(new DateTimeZone('UTC'));
$at = fn(string $mod) => $dueUtc->modify($mod)->format('Y-m-d\TH:i:s\Z');
$dueN = fn(?string $like = null) => $cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'due' AND task_id = ?" . ($like ? ' AND message LIKE ?' : ''), $like ? [$hugo, (int)($tR['id'] ?? 0), $like] : [$hugo, (int)($tR['id'] ?? 0)]);
[$rc0] = $cron($at('-30 hours'));
check('30 horas antes: todavía nada', $rc0 === 0 && $dueN() === 0);
[$rc1, $ro1] = $cron($at('-23 hours'));
[$rc1b] = $cron($at('-22 hours'));
check('24 horas antes: un aviso, en la hora de su zona (Santiago: 13:00), una sola vez', $rc1 === 0 && $dueN() === 1 && $dueN('%vence mañana a las 13:00%') === 1, $ro1 . ' ' . json_encode($q("SELECT message FROM notifications WHERE user_id = ? AND task_id = ?", [$hugo, (int)($tR['id'] ?? 0)])->fetchAll(PDO::FETCH_COLUMN)));
[$rc2] = $cron($at('-25 minutes'));
[$rc2b] = $cron($at('-20 minutes'));
check('30 minutos antes: el segundo aviso («vence en 25 minutos»), también una sola vez', $rc2 === 0 && $dueN() === 2 && $dueN('%vence en 25 minutos%') === 1);
[$rc3] = $cron($at('+5 minutes'));
[$rc3b] = $cron($at('+50 minutes'));
check('Pasada la hora: «vencida», una sola vez', $rc3 === 0 && $cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'overdue' AND task_id = ?", [$hugo, (int)($tR['id'] ?? 0)]) === 1);
$tLate = $t19('Creada tarde', ['assignee_id' => $hugo, 'due_date' => $dueDay, 'due_time' => '12:00']);
$cron($dueUtc->modify('+100 minutes')->format('Y-m-d\TH:i:s\Z'));
check('Creada 20 minutos antes de vencer: llega solo el aviso de 30 minutos, no un aluvión', $cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'due' AND task_id = ?", [$hugo, (int)($tLate['id'] ?? 0)]) === 1
    && $cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'due' AND task_id = ? AND dedupe_key LIKE '%:30'", [$hugo, (int)($tLate['id'] ?? 0)]) === 1);
$tMove = $t19('Cambia de hora', ['assignee_id' => $hugo, 'due_date' => $dueDay, 'due_time' => '18:00']);
$cron($dueUtc->modify('+8 hours -20 minutes')->format('Y-m-d\TH:i:s\Z'));
$admin->api('PATCH', '/api/tasks/' . (int)($tMove['id'] ?? 0), ['due_time' => '20:00']);
$cron($dueUtc->modify('+8 hours -15 minutes')->format('Y-m-d\TH:i:s\Z'));
$oldN = $q("SELECT * FROM notifications WHERE user_id = ? AND type = 'due' AND task_id = ? ORDER BY id LIMIT 1", [$hugo, (int)($tMove['id'] ?? 0)])->fetch();
$newN = $q("SELECT * FROM notifications WHERE user_id = ? AND type = 'due' AND task_id = ? ORDER BY id DESC LIMIT 1", [$hugo, (int)($tMove['id'] ?? 0)])->fetch();
check('Si cambia la hora, el recordatorio anterior queda con la hora vieja (ya no se envía, ver «Correo») y llega uno con la nueva', $oldN && str_contains((string)$oldN['dedupe_key'], 'T18:00')
    && $newN && (int)$newN['id'] !== (int)$oldN['id'] && str_contains((string)$newN['dedupe_key'], 'T20:00') && str_contains((string)$newN['message'], 'a las 23:00'), json_encode([$oldN, $newN]));
$tFin = $t19('Terminada a tiempo', ['assignee_id' => $hugo, 'due_date' => $dueDay, 'due_time' => '23:00']);
$admin->api('POST', '/api/tasks/' . (int)($tFin['id'] ?? 0) . '/done', ['done' => true]);
$cron($dueUtc->modify('+13 hours -10 minutes')->format('Y-m-d\TH:i:s\Z'));
check('Una tarea completada no genera recordatorios', !$cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND task_id = ? AND type IN ('due','overdue')", [$hugo, (int)($tFin['id'] ?? 0)]));
// weekly summary: next Monday 09:00 in Santiago
$mon = (new DateTimeImmutable('next monday 09:00', new DateTimeZone('America/Santiago')))->setTimezone(new DateTimeZone('UTC'));
$cron($mon->format('Y-m-d\TH:i:s\Z'));
$cron($mon->modify('+1 hour')->format('Y-m-d\TH:i:s\Z'));
check('Resumen semanal: el lunes por la mañana, una vez, solo a quien lo activó', $cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'weekly'", [$hugo]) === 1 && !$cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'weekly'", [$gabi]));
check('Discrepancia de planificación para la administración: horas completas con tareas pendientes', $cnt("SELECT COUNT(*) FROM notifications WHERE type = 'capacity' AND message LIKE '%completó sus 15 h%' AND message LIKE '%planificada%pendiente%' AND message LIKE '%discrepancia de planificación%'") >= 1,
    json_encode($q("SELECT message FROM notifications WHERE type = 'capacity' LIMIT 3")->fetchAll(PDO::FETCH_COLUMN)));

group('1.9 · Correo');
[$cm0, $om0] = $cron();
$hugoC->get('/profile');
check('Sin «mail» en la configuración: no se envía nada y el perfil lo dice', $cm0 === 0 && !$mails() && str_contains($hugoC->body, 'todavía no puede enviar correos'), $om0);
$writeCfg(['mail' => $mailCfg]);
$q("UPDATE notifications SET read_at = UTC_TIMESTAMP() WHERE user_id = ? AND type <> 'due'", [$hugo]);
$readOne = (int)$q("SELECT id FROM notifications WHERE user_id = ? AND type = 'due' ORDER BY id LIMIT 1", [$hugo])->fetchColumn();
$q('UPDATE notifications SET read_at = UTC_TIMESTAMP() WHERE id = ?', [$readOne]);
$q("UPDATE notifications SET emailed_at = NULL, created_at = UTC_TIMESTAMP() WHERE user_id = ?", [$hugo]);
$admin->api('POST', "/api/projects/$p19/tasks", ['title' => 'Nueva para el correo', 'assignee_id' => $hugo]);
// 1.16.2: the e-mail now goes out right after that request (not only with the cron): wait for it
for ($w = 0; $w < 80 && !array_filter($mails(), fn($x) => in_array('hugo.hora@example.test', $x['to'], true)); $w++) usleep(500000);
[$cm1, $om1] = $cron();
$m = $mails();
$mh = array_values(array_filter($m, fn($x) => in_array('hugo.hora@example.test', $x['to'], true)));
check('Los avisos salen por SMTP autenticado y cifrado (STARTTLS), con certificado verificado (1.16.2: al instante, sin esperar el cron)', $cm1 === 0 && $mh && $mh[0]['tls'] === true && ($mh[0]['auth']['user'] ?? '') === 'avisos@example.test' && ($mh[0]['auth']['tls'] ?? false) === true, $om1 . ' ' . json_encode($m));
check('Varios avisos juntos llegan en un solo correo, cada uno con su enlace directo', count($mh) === 1 && preg_match('/\d+ avisos nuevos/', (string)($mh[0]['subject'] ?? '')) && str_contains((string)$mh[0]['text'], 'Nueva para el correo') && str_contains((string)$mh[0]['text'], $BASE . '/t/NUE-'), json_encode($mh[0] ?? null));
check('Un recordatorio de una hora que cambió ya no se envía por correo (sí el de la hora nueva)', $oldN && $newN && !str_contains((string)($mh[0]['text'] ?? ''), (string)$oldN['message']) && str_contains((string)($mh[0]['text'] ?? ''), (string)$newN['message']), (string)($mh[0]['text'] ?? ''));
check('Lo ya leído en Borealis no se envía por correo', !str_contains((string)($mh[0]['text'] ?? ''), (string)$q('SELECT message FROM notifications WHERE id = ?', [$readOne])->fetchColumn()));
check('El correo no incluye la contraseña SMTP en ninguna parte', !str_contains(json_encode($m), 'clave-smtp-de-prueba'));
[$cm2] = $cron();
check('Cada aviso se envía una sola vez', $cm2 === 0 && count(array_filter($mails(), fn($x) => in_array('hugo.hora@example.test', $x['to'], true))) === 1);
check('Gabi no recibe correos de los avisos de Hugo', !array_filter($mails(), fn($x) => in_array('gabi.google@example.test', $x['to'], true) && str_contains((string)$x['text'], 'Nueva para el correo')));
// quiet hours: a window around «now» in Santiago
$nowS = new DateTimeImmutable('now', new DateTimeZone('America/Santiago'));
$hugoC->api('PATCH', '/api/profile/notifications', ['in_app' => array_merge($cats, ['weekly']), 'before' => [1440, 30], 'timezone' => 'America/Santiago', 'quiet_on' => '1', 'quiet_from' => $nowS->modify('-1 hour')->format('H:i'), 'quiet_to' => $nowS->modify('+1 hour')->format('H:i'), 'email' => '1', 'email_cats' => ['assigned']]);
$admin->api('POST', "/api/projects/$p19/tasks", ['title' => 'Asignada en silencio', 'assignee_id' => $hugo]);
$cron();
$quietSent = array_filter($mails(), fn($x) => str_contains((string)$x['text'], 'Asignada en silencio'));
check('Horario de silencio: el aviso queda en la campana y el correo espera', !$quietSent && $cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND message LIKE '%Asignada en silencio%' AND emailed_at IS NULL", [$hugo]) === 1);
$hugoC->api('PATCH', '/api/profile/notifications', ['in_app' => array_merge($cats, ['weekly']), 'before' => [1440, 30], 'timezone' => 'America/Santiago', 'quiet_on' => '', 'email' => '1', 'email_cats' => ['assigned']]);
$cron();
check('…y sale al terminar el silencio', (bool)array_filter($mails(), fn($x) => str_contains((string)$x['text'], 'Asignada en silencio')));
$hugoC->api('PATCH', '/api/profile/notifications', ['in_app' => array_merge($cats, ['weekly']), 'before' => [1440, 30], 'timezone' => 'America/Santiago', 'quiet_on' => '', 'email' => '', 'email_cats' => ['assigned']]);
$admin->api('POST', "/api/projects/$p19/tasks", ['title' => 'Sin correo por elección', 'assignee_id' => $hugo]);
$cron();
check('Con «Por correo» apagado no se envía nada', !array_filter($mails(), fn($x) => str_contains((string)$x['text'], 'Sin correo por elección')));
$hugoC->api('PATCH', '/api/profile/notifications', ['in_app' => array_merge($cats, ['weekly']), 'before' => [1440, 30], 'timezone' => 'America/Santiago', 'quiet_on' => '', 'email' => '1', 'email_cats' => ['assigned']]);
touch($smtpDown);
$admin->api('POST', "/api/projects/$p19/tasks", ['title' => 'Servidor de correo caído', 'assignee_id' => $hugo]);
[$cm3, $om3] = $cron();
check('Si el servidor de correo no responde, el aviso no se pierde: queda para el siguiente intento', $cm3 === 0 && str_contains($om3, 'con error') && $cnt("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND message LIKE '%Servidor de correo caído%' AND emailed_at IS NULL", [$hugo]) === 1, $om3);
@unlink($smtpDown);
$cron();
check('…y se envía cuando vuelve', (bool)array_filter($mails(), fn($x) => str_contains((string)$x['text'], 'Servidor de correo caído')));
$hugoC->api('POST', '/api/mail/test');
check('«Enviar correo de prueba» es solo para la administración de la instalación', $hugoC->status === 403);
$admin->api('POST', '/api/mail/test');
check('La administración puede enviar un correo de prueba', $admin->status === 200 && (bool)array_filter($mails(), fn($x) => str_contains((string)$x['subject'], 'correo de prueba')), snippet($admin));
$writeCfg(['mail' => ['password' => 'mala'] + $mailCfg]);
$admin->api('POST', '/api/mail/test');
check('Contraseña SMTP incorrecta: se informa el motivo sin mostrar la contraseña', $admin->status === 409 && str_contains($admin->body, 'rechazó el usuario o la contraseña') && !str_contains($admin->body, 'mala'), snippet($admin));
$writeCfg(['mail' => ['port' => $smtpPlainPort] + $mailCfg]);
$admin->api('POST', '/api/mail/test');
check('Si el servidor no ofrece cifrado, Borealis no envía la contraseña sin cifrar', $admin->status === 409 && str_contains($admin->body, 'STARTTLS'), snippet($admin));
$writeCfg(['mail' => $mailCfg]);

// ================================================================ PWA
group('1.9 · Aplicación instalable (PWA)');
$anon->get('/manifest.webmanifest');
$mf = json_decode($anon->body, true) ?: [];
check('Manifiesto con id, nombre, icono propio (también «maskable») y modo aplicación', ($mf['id'] ?? '') === './' && ($mf['display'] ?? '') === 'standalone' && in_array('maskable', array_column($mf['icons'] ?? [], 'purpose'), true) && count($mf['shortcuts'] ?? []) === 3);
$anon->get('/sw.js');
$sw = $anon->body;
check('El service worker solo guarda la página «Sin conexión» (nada de proyectos, sesiones ni personas)', str_contains($sw, "addEventListener('fetch'") && str_contains($sw, "r.mode !== 'navigate'") && substr_count($sw, 'c.add(') === 1 && str_contains($sw, "'offline.html'") && !str_contains($sw, 'cache.put') && !str_contains($sw, '.put('));
$anon->get('/offline.html');
check('La página «Sin conexión» existe y no tiene datos de nadie', $anon->status === 200 && str_contains($anon->body, 'Sin conexión') && !preg_match('/[\w.+-]+@[\w-]+\.[a-z]{2,}/i', $anon->body) && !str_contains($anon->body, 'csrf'));
$anon->get('/assets/img/app-512.png');
check('Icono de 512 px para instalar', $anon->status === 200);

// ================================================================ update
group('1.9 · Actualización');
$pdo->exec("DELETE FROM schema_migrations WHERE version = '011_v19'");
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/migrate.php') . ' --no-backup 2>&1', $m11, $m11c);
check('La migración 011 se puede repetir sin errores ni pérdida de datos', $m11c === 0 && str_contains(implode("\n", $m11), 'Aplicada: 011_v19') && (string)$q('SELECT due_time FROM tasks WHERE id = ?', [(int)($tR['id'] ?? 0)])->fetchColumn() === '10:00:00', implode("\n", $m11));
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tests/unit/reminders.php'), $u19, $u19c);
$u19j = json_decode(implode('', $u19), true) ?: [];
foreach ($u19j['checks'] ?? [] as $uc) check('Reglas · ' . $uc['name'], $uc['ok'], $uc['detail']);
check('Las reglas de recordatorios se comprobaron sin servidor', $u19c === 0 && !empty($u19j['ok']), implode("\n", $u19));

file_put_contents($cfgPath19, $cfgOrig19);
usleep(3200000);
proc_terminate($g9Proc); @unlink($g9State); @unlink($g9State . '.lock');
proc_terminate($smtpProc); proc_terminate($smtpPlainProc);
@unlink($smtpLog); @unlink($certFile); @unlink($certFile . '.crt');
