<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1); // 1.16.5: never runs when requested from the web

/*
 * Borealis 1.16 — notifications with Borealis closed on a hosting managed only by FTP: work after
 * the answer on any server (PHP-FPM, LiteSpeed, Apache), /cron/ping from the devices, the service
 * worker keeping the job alive, defaults on, sessions that end, notices above open dialogs.
 * Included by run.php after v115.php (same globals).
 */

$root = dirname(__DIR__, 2);
$cnt = fn(string $sql, array $p = []) => (int)$q($sql, $p)->fetchColumn();
$read = fn(string $f) => (string)file_get_contents($root . '/' . $f);

group('1.16 · Avisos con Borealis cerrado en cualquier alojamiento');
check('Versión 1.16.x', str_contains($read('app/bootstrap.php'), "'1.16."));
$bg = $read('app/Core/Background.php');
check('El trabajo tras responder funciona con PHP-FPM, LiteSpeed y Apache (no solo PHP-FPM)', str_contains($bg, 'fastcgi_finish_request') && str_contains($bg, 'litespeed_finish_request') && str_contains($bg, 'ignore_user_abort') && str_contains($read('app/Services/Push.php'), 'Background::detach()') && str_contains($read('app/Services/Scheduler.php'), 'Background::detach()') && !str_contains($read('app/Services/Scheduler.php'), "!function_exists('fastcgi_finish_request')"));
$anon = new Client($BASE);
$anon->get('/login');
$len = (int)($anon->headers['content-length'][0] ?? -1);
check('En modo compatible (Apache mod_php, aquí php -S) la página dice dónde termina, así el navegador no espera el trabajo posterior', $anon->status === 200 && $len === strlen($anon->body) && strtolower((string)($anon->headers['connection'][0] ?? '')) === 'close', json_encode([$len, strlen($anon->body), $anon->headers['connection'] ?? null]));

// /cron/ping
$ping = new Client($BASE);
$ping->request('POST', '/cron/ping?src=push');
check('/cron/ping responde 204 vacío, sin sesión ni cookies', $ping->status === 204 && $ping->body === '' && empty($ping->headers['set-cookie']), snippet($ping));
$ping->request('GET', '/cron/ping');
check('…también por GET (servicios externos que solo visitan direcciones)', $ping->status === 204 && $ping->body === '');
$cfgPath16 = $root . '/config/config.php';
$cfgOrig16 = (string)file_get_contents($cfgPath16);
$cfg16 = require $cfgPath16;
$cfg16['cron'] = ['web_fallback' => 'always'];
file_put_contents($cfgPath16, "<?php\nreturn " . var_export($cfg16, true) . ";\n");
usleep(3200000);
$q("UPDATE system_jobs SET last_started_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE) WHERE name = 'notify'");
$runs16 = $cnt("SELECT runs FROM system_jobs WHERE name = 'notify'");
(new Client($BASE))->request('POST', '/cron/ping?src=push');
usleep(900000);
$src16 = (string)$q("SELECT last_source FROM system_jobs WHERE name = 'notify'")->fetchColumn();
check('Un dispositivo que recibe un aviso (service worker → /cron/ping) pone en marcha la tarea programada sin sesión', $src16 === 'device' && $cnt("SELECT runs FROM system_jobs WHERE name = 'notify'") === $runs16 + 1, $src16);
(new Client($BASE))->request('POST', '/cron/ping?src=push');
(new Client($BASE))->request('GET', '/cron/ping');
usleep(500000);
check('…como mucho cada dos minutos, aunque lleguen muchos avisos (nunca dos ejecuciones seguidas)', $cnt("SELECT runs FROM system_jobs WHERE name = 'notify'") === $runs16 + 1);
file_put_contents($cfgPath16, $cfgOrig16);
usleep(3200000);

// defaults
group('1.16 · Preferencias activadas por defecto');
[$val, $valC] = $mkUser('Valentina Defecto', 'valentina.defecto@example.test', 'member');
$valC->get('/profile');
preg_match_all('~name="push_cats\[\]" value="([a-z]+)"( checked)?~', $valC->body, $pm, PREG_SET_ORDER);
$on = array_column(array_filter($pm, fn($m) => !empty($m[2])), 1);
$offCats = array_column(array_filter($pm, fn($m) => empty($m[2])), 1);
check('Para una persona nueva todos los avisos van al dispositivo (asignaciones, vencimientos, retrasos, cambios, comentarios, alertas)', count($pm) >= 12 && $offCats === ['weekly'] && in_array('project', $on, true) && in_array('comments', $on, true) && in_array('monitor', $on, true), json_encode($offCats));
check('…y sin horario de silencio hasta que la persona lo elija', $valC->status === 200 && !preg_match('~name="quiet_on" value="1" checked~', $valC->body));
$oldPrefs = ['notify' => ['off' => ['weekly'], 'push' => true, 'push_off' => ['project', 'comments', 'capacity', 'weekly', 'watching'], 'before' => [1440, 30], 'quiet' => ['on' => false, 'from' => '22:00', 'to' => '07:00']]];
$q('UPDATE users SET prefs = ? WHERE id = ?', [json_encode($oldPrefs), $val]);
$valC->get('/profile');
check('Quien guardó sus preferencias antes de 1.16 sin cambiar nada pasa a recibirlo todo', str_contains($valC->body, 'name="push_cats[]" value="project" checked') && str_contains($valC->body, 'name="push_cats[]" value="comments" checked'));
$oldPrefs['notify']['push_off'] = ['project'];
$q('UPDATE users SET prefs = ? WHERE id = ?', [json_encode($oldPrefs), $val]);
$valC->get('/profile');
check('…pero lo que una persona apagó a propósito sigue apagado', str_contains($valC->body, 'name="push_cats[]" value="project">') && str_contains($valC->body, 'name="push_cats[]" value="comments" checked'));
$q('UPDATE users SET prefs = NULL WHERE id = ?', [$val]);

// a session that ends does not stop the notices
group('1.16 · La sesión caducada no interrumpe los avisos');
$fsPort16 = (int)(getenv('BOREALIS_FAKE_SERVICES_PORT16') ?: 8097);
$fs16 = "http://127.0.0.1:$fsPort16";
$fsLog16 = sys_get_temp_dir() . '/borealis-fake-services16-' . getmypid() . '.jsonl';
@unlink($fsLog16);
$fsProc16 = proc_open([PHP_BINARY, '-S', "127.0.0.1:$fsPort16", $root . '/tests/fake-services/server.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $fsPipes16, null, ['FAKE_SERVICES_LOG' => $fsLog16, 'PATH' => getenv('PATH')]);
usleep(400000);
$fsGet16 = function () use ($fs16): array { $r = @file_get_contents("$fs16/_log"); return is_string($r) ? (json_decode($r, true) ?: []) : []; };
$cfg16 = require $cfgPath16;
$cfg16['push'] = ['test_hosts' => "127.0.0.1:$fsPort16", 'subject' => 'mailto:pruebas@example.test'];
file_put_contents($cfgPath16, "<?php\nreturn " . var_export($cfg16, true) . ";\n");
usleep(3200000);
$admin->api('POST', "/api/projects/$pS/members", ['user_id' => $val, 'role_id' => $role($ws1, 'project', 'contributor')]);
$valC->api('POST', '/api/push/subscription', ['endpoint' => "$fs16/push/valentina", 'p256dh' => $b64($uaPub), 'auth' => $b64($uaAuth), 'device' => 'Chrome · Windows']);
check('El dispositivo queda activado', $valC->status === 200, snippet($valC));
$valC->form('/logout', []); // the session ends (same as expiring): the device stays linked
$valC->request('GET', '/api/notifications/status', null, ['Accept: application/json', 'X-Borealis-Background: 1']);
$ended = $valC->status === 401;
$admin->api('POST', "/api/projects/$pS/tasks", ['title' => 'Asignada con la sesión caducada', 'assignee_id' => $val]);
$tV = (int)($admin->json()['task']['id'] ?? 0);
usleep(900000);
$got = array_values(array_filter($fsGet16(), fn($r) => $r['path'] === '/push/valentina'));
$plain16 = $got ? $decrypt(base64_decode($got[0]['body'])) : null;
check('Con la sesión terminada (401), la asignación llega al dispositivo al instante y cifrada (el teléfono no necesita sesión para mostrarla)', $ended && $tV > 0 && $got && str_contains((string)$plain16, 'Asignada con la sesión caducada'), json_encode([$ended, count($got), $plain16]));
$admin->api('PATCH', "/api/tasks/$tV", ['due_date' => $day(5)]);
usleep(900000);
$got2 = array_filter($fsGet16(), fn($r) => $r['path'] === '/push/valentina' && str_contains((string)$decrypt(base64_decode($r['body'])), 'fecha'));
check('…y también una modificación (cambio de fecha)', count($got2) >= 1, (string)count($fsGet16()));
$before = count(array_filter($fsGet16(), fn($r) => $r['path'] === '/push/valentina'));
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/notify.php') . ' 2>&1');
(new Client($BASE))->request('POST', '/cron/ping?src=push');
usleep(500000);
check('Nada se envía dos veces: otra ejecución de la tarea o de un dispositivo no repite avisos', count(array_filter($fsGet16(), fn($r) => $r['path'] === '/push/valentina')) === $before);

// 1.16.1 · sound, notices that stay on screen, test with Borealis closed, list of devices
group('1.16.1 · Sonido, avisos que se quedan y prueba con Borealis cerrado');
$pj16 = json_decode((string)$plain16, true) ?: [];
check('Una asignación va con sonido y se queda en pantalla hasta cerrarla (por defecto)', ($pj16['silent'] ?? null) === false && ($pj16['sticky'] ?? null) === true, (string)$plain16);
$admin->api('POST', '/api/push/subscription', ['endpoint' => "$fs16/push/admin-pc", 'p256dh' => $b64($uaPub), 'auth' => $b64($uaAuth), 'device' => 'Edge · Windows']);
$t0 = microtime(true);
$admin->api('POST', '/api/push/test', ['delay' => 2]);
$took = microtime(true) - $t0;
$early = array_filter($fsGet16(), fn($r) => $r['path'] === '/push/admin-pc');
check('«Probar con Borealis cerrado» responde enseguida y pide cerrar Borealis', $admin->status === 200 && str_contains((string)($admin->json()['message'] ?? ''), 'Cierra Borealis') && !$early && $took < 1.8, snippet($admin) . " $took");
usleep(3500000);
$late = array_values(array_filter($fsGet16(), fn($r) => $r['path'] === '/push/admin-pc'));
$plainT = $late ? (string)$decrypt(base64_decode($late[0]['body'])) : '';
check('…y la prueba llega después, sin ninguna página esperando', str_contains($plainT, 'Borealis cerrado'), $plainT);
$admin->api('PATCH', '/api/profile/notifications', ['in_app' => ['assigned', 'due', 'overdue'], 'push' => '1', 'push_cats' => ['assigned'], 'has_sound' => '1']);
$admin->get('/profile');
check('Se puede quitar el sonido y que no se queden en pantalla (se guarda)', !preg_match('~name="sound" value="1" checked~', $admin->body) && !preg_match('~name="sticky" value="1" checked~', $admin->body) && str_contains($admin->body, 'name="borealis-sound" content="0"'), snippet($admin));
check('El perfil lista cada dispositivo con su última entrega', str_contains($admin->body, 'data-devices') && str_contains($admin->body, 'Edge · Windows') && str_contains($admin->body, 'última entrega'), snippet($admin));
$admin->api('POST', '/api/push/test');
usleep(500000);
$quiet = array_values(array_filter($fsGet16(), fn($r) => $r['path'] === '/push/admin-pc'));
$pq = json_decode((string)$decrypt(base64_decode(end($quiet)['body'])), true) ?: [];
check('…y entonces los avisos llegan sin sonido', ($pq['silent'] ?? null) === true && ($pq['sticky'] ?? null) === false, json_encode($pq));
$admin->api('PATCH', '/api/profile/notifications', ['in_app' => ['assigned', 'due', 'overdue'], 'push' => '1', 'push_cats' => ['assigned', 'due', 'overdue'], 'has_sound' => '1', 'sound' => '1', 'sticky' => '1']);
$admin->api('POST', '/api/push/unsubscribe', ['endpoint' => "$fs16/push/admin-pc"]);
$sw16 = $read('public/sw.js');
check('El service worker muestra el aviso con sonido, «quedarse en pantalla» y vibración, y si el navegador rechaza una opción lo muestra igual', str_contains($sw16, 'requireInteraction: !!n.sticky') && str_contains($sw16, 'silent: !!n.silent') && str_contains($sw16, 'renotify: true') && str_contains($sw16, 'vibrate') && str_contains($sw16, 'try { await show(n); } catch'));
check('Con Borealis abierto suena un tono corto (si la persona no quitó el sonido)', str_contains($read('public/assets/js/notify-live.js'), 'function chime()'));

file_put_contents($cfgPath16, $cfgOrig16);
usleep(3200000);
proc_terminate($fsProc16);
@unlink($fsLog16);

// client side
group('1.16 · Service worker, permisos y diálogos');
$sw = $read('public/sw.js');
$pj = $read('public/assets/js/push.js');
check('El service worker avisa a Borealis cada vez que recibe un aviso y cuando el navegador lo despierta (sincronización periódica)', str_contains($sw, "ping('push')") && str_contains($sw, "addEventListener('periodicsync'") && str_contains($sw, 'cron/ping') && str_contains($sw, "credentials: 'omit'"));
check('Con el permiso del navegador dado, el dispositivo se vuelve a vincular solo (salvo que la persona lo desactivara aquí)', str_contains($pj, "store.get('borealis-push') !== '0'") && str_contains($pj, 'periodicSync.register'));
check('«Ahora no» oculta la invitación una semana, no para siempre', str_contains($pj, 'borealis-push-nudge-until') && !str_contains($pj, "'borealis-push-nudge', 'no'"));
$admin->get('/my-work');
check('La invitación a activar las notificaciones aparece en cualquier página, no solo en Inicio', $admin->status === 200 && str_contains($admin->body, 'data-push-nudge'));
check('Los avisos dentro de la página se ven encima de una confirmación abierta (capa superior del navegador)', str_contains($admin->body, 'id="toasts" role="status" aria-live="polite" popover="manual"') && str_contains($read('public/assets/js/core.js'), 'raiseToasts(box)') && str_contains($read('public/assets/css/borealis.css'), '.toasts[popover]:not(:popover-open)'));
$admin->get('/admin/notifications');
check('Administración explica qué funciona sin acceso al servidor y cómo usar un servicio externo gratuito con la dirección secreta', $admin->status === 200 && str_contains($admin->body, 'data-layers') && str_contains($admin->body, 'cron-job.org') && str_contains($admin->body, 'data-server-mode') && preg_match('~data-cron-url>[^<]+/cron/run\?token=~', $admin->body), snippet($admin));

// 1.16.2 · e-mail configured from Administración (no config.php by FTP), sent at once, defaults on
group('1.16.2 · Correo configurado desde Administración');
$admin->get('/admin/data');
check('Administración › Datos › Correo tiene un formulario (sin editar config/config.php por FTP)', $admin->status === 200 && str_contains($admin->body, 'data-api="/api/mail/settings"') && str_contains($admin->body, 'data-mail-preset') && str_contains($admin->body, 'smtp.gmail.com'), snippet($admin));
$anonMail = new Client($BASE);
$anonMail->api('POST', '/api/mail/settings', ['transport' => 'smtp']);
$valMail = new Client($BASE);
check('Sin sesión no se puede tocar (401/419)', in_array($anonMail->status, [401, 403, 419], true), (string)$anonMail->status);
$admin->api('POST', '/api/mail/settings', ['transport' => 'smtp', 'host' => '', 'port' => 465, 'from_address' => 'no-es-correo']);
check('Valida servidor y remitente', $admin->status === 422 && isset($admin->json()['errors']['from_address']), snippet($admin));
$admin->api('POST', '/api/mail/settings', ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 465, 'username' => 'avisos@example.test', 'password' => 'clave-smtp-de-prueba', 'from_address' => 'avisos@example.test', 'from_name' => 'Borealis Avisos']);
$mj = json_decode((string)@file_get_contents($root . '/storage/mail.json'), true) ?: [];
check('Guarda y prueba al instante: si el servidor no responde lo dice con el motivo', $admin->status === 409 && str_contains((string)($admin->json()['error'] ?? $admin->body), 'no salió'), snippet($admin));
check('La contraseña queda cifrada en storage/mail.json (nunca en claro)', ($mj['host'] ?? '') === '127.0.0.1' && !str_contains((string)json_encode($mj), 'clave-smtp-de-prueba') && !empty($mj['password']), json_encode(array_keys($mj)));
$admin->get('/admin/data');
check('…el error queda a la vista en Administración y la contraseña no se muestra', str_contains($admin->body, 'Último error') && !str_contains($admin->body, 'clave-smtp-de-prueba'), snippet($admin));

// a working SMTP (the stand-in of the test-suite, with its own certificate)
$cert16 = sys_get_temp_dir() . '/borealis-smtp16-' . getmypid() . '.pem';
$pk16 = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
$crt16 = openssl_csr_sign(openssl_csr_new(['commonName' => 'localhost'], $pk16), null, $pk16, 2);
openssl_x509_export($crt16, $crtPem16); openssl_pkey_export($pk16, $keyPem16);
file_put_contents($cert16, $crtPem16 . $keyPem16);
file_put_contents($cert16 . '.crt', $crtPem16);
$smtp16Port = (int)(getenv('BOREALIS_FAKE_SMTP16_PORT') ?: 2536);
$smtp16Log = sys_get_temp_dir() . '/borealis-smtp16-' . getmypid() . '.jsonl';
@unlink($smtp16Log);
$smtp16 = proc_open([PHP_BINARY, $root . '/tests/fake-services/smtp.php', (string)$smtp16Port], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $sp16, null,
    ['FAKE_SMTP_LOG' => $smtp16Log, 'FAKE_SMTP_CERT' => $cert16, 'FAKE_SMTP_USER' => 'avisos@example.test', 'FAKE_SMTP_PASS' => 'clave-smtp-de-prueba', 'FAKE_SMTP_DOWN_FILE' => $smtp16Log . '.down', 'PATH' => getenv('PATH')]);
$mails16 = fn() => array_values(array_filter(array_map(fn($l) => json_decode($l, true), is_file($smtp16Log) ? file($smtp16Log, FILE_IGNORE_NEW_LINES) : [])));
usleep(500000);
// the stand-in listens on a test port and signs with its own certificate: only the tests set these two
$mj['host'] = 'localhost'; $mj['port'] = $smtp16Port; $mj['encryption'] = 'tls'; $mj['cafile'] = $cert16 . '.crt';
file_put_contents($root . '/storage/mail.json', json_encode($mj));
$admin->api('POST', '/api/mail/test');
$m1 = $mails16();
check('Con un servidor que funciona, el correo de prueba sale con la contraseña guardada', $admin->status === 200 && count($m1) === 1 && str_contains((string)($m1[0]['text'] ?? json_encode($m1[0])), 'correo de prueba'), snippet($admin) . ' ' . count($m1));
$admin->api('POST', "/api/projects/$pS/tasks", ['title' => 'Asignada con correo al instante', 'assignee_id' => $val]);
usleep(1500000);
$toVal = array_filter($mails16(), fn($m) => str_contains((string)json_encode($m, JSON_UNESCAPED_UNICODE), 'Asignada con correo al instante'));
check('La asignación llega por correo al instante, sin esperar la tarea programada', count($toVal) === 1, (string)count($mails16()));
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/notify.php') . ' 2>&1');
check('…y la tarea programada no la repite', count(array_filter($mails16(), fn($m) => str_contains((string)json_encode($m, JSON_UNESCAPED_UNICODE), 'Asignada con correo al instante'))) === 1);
$admin->get('/admin/data');
check('Administración muestra el último envío correcto', str_contains($admin->body, 'Último envío correcto'), snippet($admin));
$q("UPDATE users SET prefs = ? WHERE id = ?", [json_encode(['notify' => ['v' => 116, 'email' => true, 'email_off' => ['automation', 'calendar', 'comments', 'date', 'monitor', 'project', 'watching']]]), $val]);
$admin->api('PATCH', "/api/tasks/" . (int)$q("SELECT id FROM tasks WHERE title = 'Asignada con correo al instante'")->fetchColumn(), ['due_date' => $day(6)]);
usleep(1500000);
check('Las categorías por correo vienen activadas: un cambio de fecha también llega (también a quien tenía las de antes sin cambiar)', count(array_filter($mails16(), fn($m) => str_contains((string)json_encode($m, JSON_UNESCAPED_UNICODE), 'fecha de entrega'))) >= 1, (string)count($mails16()));
$admin->api('POST', '/api/mail/settings', ['transport' => 'none']);
check('Se puede desactivar el correo desde la misma pantalla', $admin->status === 200 && !is_file($root . '/storage/mail.json'), snippet($admin));
proc_terminate($smtp16);
@unlink($smtp16Log); @unlink($cert16); @unlink($cert16 . '.crt'); @unlink($root . '/storage/mail-status.json');
