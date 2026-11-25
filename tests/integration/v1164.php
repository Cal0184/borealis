<?php
declare(strict_types=1);

/*
 * Borealis 1.16.4 — fixes of the external audit (docs/AUDITORIA-EXTERNA.md): «¿Olvidaste tu contraseña?»,
 * copia completa (.zip), copias con nombre aleatorio y rotación, seguridad de la instalación,
 * avisos push solo a servicios conocidos. Included by run.php after v1163.php (same globals).
 */

$root = dirname(__DIR__, 2);

group('1.16.4 · Recuperar contraseña por correo');
$anonF = new Client($BASE);
$anonF->get('/login');
check('El inicio de sesión enlaza «¿Olvidaste tu contraseña?»', str_contains($anonF->body, 'href="/forgot"'), snippet($anonF));
$anonF->get('/forgot');
check('Sin correo configurado lo dice y remite a la administración', $anonF->status === 200 && str_contains($anonF->body, 'todavía no tiene el correo configurado'), snippet($anonF));

// a working SMTP stand-in, as in v116.php
$cert = sys_get_temp_dir() . '/borealis-smtp164-' . getmypid() . '.pem';
$pk = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
$crt = openssl_csr_sign(openssl_csr_new(['commonName' => 'localhost'], $pk), null, $pk, 2);
openssl_x509_export($crt, $crtPem); openssl_pkey_export($pk, $keyPem);
file_put_contents($cert, $crtPem . $keyPem);
file_put_contents($cert . '.crt', $crtPem);
$smtpPort = (int)(getenv('BOREALIS_FAKE_SMTP164_PORT') ?: 2537);
$smtpLog = sys_get_temp_dir() . '/borealis-smtp164-' . getmypid() . '.jsonl';
@unlink($smtpLog);
$smtp = proc_open([PHP_BINARY, $root . '/tests/fake-services/smtp.php', (string)$smtpPort], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $sp164, null,
    ['FAKE_SMTP_LOG' => $smtpLog, 'FAKE_SMTP_CERT' => $cert, 'FAKE_SMTP_USER' => 'avisos@example.test', 'FAKE_SMTP_PASS' => 'clave-smtp-164', 'PATH' => getenv('PATH')]);
$mails = fn() => array_values(array_filter(array_map(fn($l) => json_decode($l, true), is_file($smtpLog) ? file($smtpLog, FILE_IGNORE_NEW_LINES) : [])));
usleep(500000);
$admin->api('POST', '/api/mail/settings', ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 465, 'username' => 'avisos@example.test', 'password' => 'clave-smtp-164', 'from_address' => 'avisos@example.test', 'from_name' => 'Borealis']);
$mj = json_decode((string)@file_get_contents($root . '/storage/mail.json'), true) ?: [];
$mj['host'] = 'localhost'; $mj['port'] = $smtpPort; $mj['encryption'] = 'tls'; $mj['cafile'] = $cert . '.crt';
file_put_contents($root . '/storage/mail.json', json_encode($mj));
sleep(1);

$rosaPass = pw();
$admin->api('POST', '/api/members', ['name' => 'Rosa Olvido', 'email' => 'rosa@example.test', 'password' => $rosaPass, 'role_id' => $role($ws1, 'workspace', 'member')]);
$rosa = (int)($admin->json()['id'] ?? 0);
check('Se crea una persona para la prueba', $rosa > 0, snippet($admin));

$anonF->get('/forgot');
check('Con correo configurado pide la dirección', str_contains($anonF->body, 'name="email"') && str_contains($anonF->body, 'Enviar enlace'), snippet($anonF));
$anonF->form('/forgot', ['email' => 'nadie@example.test']);
$noOne = $anonF->body;
$anonF->get('/forgot');
$anonF->form('/forgot', ['email' => 'rosa@example.test']);
check('Misma respuesta exista o no la cuenta (no revela quién tiene cuenta)', $anonF->status === 200 && str_contains($anonF->body, 'en unos minutos llegará un correo') && str_contains($noOne, 'en unos minutos llegará un correo'), snippet($anonF));
$deadline = time() + 10; $toRosa = [];
while (time() < $deadline && !$toRosa) { usleep(300000); $toRosa = array_values(array_filter($mails(), fn($m) => in_array('rosa@example.test', (array)($m['to'] ?? []), true) || str_contains((string)json_encode($m['to'] ?? ''), 'rosa@example.test'))); }
check('Llega un correo con el enlace a la persona (y a nadie más)', count($toRosa) === 1 && count($mails()) === 1, json_encode(array_map(fn($m) => [$m['to'], $m['subject']], $mails()), JSON_UNESCAPED_UNICODE));
preg_match('~(https?://\S+/reset\?t=([0-9a-f.]+))~', (string)($toRosa[0]['text'] ?? ''), $lm);
$token = $lm[2] ?? '';
check('El enlace es firmado (id.vencimiento.firma)', preg_match('/^\d+\.\d{10}\.[a-f0-9]{40}$/', $token) === 1, (string)($toRosa[0]['text'] ?? ''));

$anonR = new Client($BASE);
$anonR->get('/reset?t=' . rawurlencode(substr($token, 0, -1) . (substr($token, -1) === 'a' ? 'b' : 'a')));
check('Un enlace alterado no sirve (410)', $anonR->status === 410 && str_contains($anonR->body, 'no es válido'), snippet($anonR));
[$tid, $texp] = explode('.', $token . '..');
$anonR->get('/reset?t=' . rawurlencode($tid . '.' . (time() - 10) . '.' . substr($token, -40)));
check('Un enlace vencido no sirve', $anonR->status === 410, snippet($anonR));
$anonR->get('/reset?t=' . rawurlencode($token));
check('El enlace correcto abre el formulario de contraseña nueva', $anonR->status === 200 && str_contains($anonR->body, 'name="password"'), snippet($anonR));
$anonR->form('/reset', ['t' => $token, 'password' => 'corta']);
check('Valida el mínimo de 8 caracteres', $anonR->status === 422, snippet($anonR));
$rosaC = new Client($BASE); $rosaC->login('rosa@example.test', $rosaPass); $rosaC->get('/');
check('Rosa tenía una sesión abierta con la contraseña antigua', $rosaC->status === 200 && str_contains($rosaC->body, 'Rosa'), snippet($rosaC));
$newPass = pw();
$anonR->get('/reset?t=' . rawurlencode($token));
$anonR->form('/reset', ['t' => $token, 'password' => $newPass]);
check('Guarda la nueva contraseña y vuelve al inicio de sesión', in_array($anonR->status, [302, 303], true) && str_contains($anonR->location(), '/login'), snippet($anonR));
$rosaNew = new Client($BASE); $rosaNew->login('rosa@example.test', $newPass); $rosaNew->get('/');
check('Entra con la contraseña nueva', $rosaNew->status === 200 && str_contains($rosaNew->body, 'Rosa'), snippet($rosaNew));
$rosaC->get('/');
check('La sesión abierta con la contraseña antigua se cierra', $rosaC->status !== 200 || !str_contains($rosaC->body, 'Rosa Olvido'), (string)$rosaC->status);
$anonR->get('/reset?t=' . rawurlencode($token));
check('El mismo enlace no sirve dos veces', $anonR->status === 410, snippet($anonR));
foreach ([1, 2, 3] as $i) { $anonF->get('/forgot'); $anonF->form('/forgot', ['email' => 'rosa@example.test']); }
check('Límite: el cuarto enlace en una hora se rechaza (429)', $anonF->status === 429, snippet($anonF));
$q("DELETE FROM login_attempts WHERE success = 2");
check('Los pedidos de enlace no cuentan como intentos fallidos de inicio de sesión', (int)$q("SELECT COUNT(*) FROM login_attempts WHERE email LIKE 'reset:%' AND success = 0")->fetchColumn() === 0);
$admin->api('POST', '/api/mail/settings', ['transport' => 'none']);
proc_terminate($smtp);
@unlink($smtpLog); @unlink($cert); @unlink($cert . '.crt'); @unlink($root . '/storage/mail-status.json'); @unlink($root . '/storage/mail.json');

group('1.16.4 · Copias de seguridad');
$admin->api('POST', '/api/backups');
$bk = (string)($admin->json()['file'] ?? '');
check('Las copias llevan una parte aleatoria en el nombre (no se pueden adivinar)', preg_match('/^borealis-\d{8}-\d{6}-manual-[a-f0-9]{12}\.sql$/', $bk) === 1, $bk);
check('…terminan con la marca de copia completa', str_ends_with(trim((string)@file_get_contents($root . '/storage/backups/' . $bk)), '-- fin de la copia'));
$admin->get('/admin/backups/' . rawurlencode($bk));
check('…y se descargan', $admin->status === 200 && str_contains($admin->body, 'CREATE TABLE'), (string)$admin->status);
check('No queda ningún archivo .part', !glob($root . '/storage/backups/*.part'));
// rotation: 25 old automatic copies → only the newest 20 stay; manual and pre-update copies stay
for ($i = 0; $i < 25; $i++) { $f = $root . sprintf('/storage/backups/borealis-20200101-%06d-antes-de-eliminar-usuario-%012x.sql', $i, $i); file_put_contents($f, '-- vieja'); touch($f, 1577836800 + $i); }
$oldManual = $root . '/storage/backups/borealis-20200101-000000-manual.sql'; file_put_contents($oldManual, '-- manual'); touch($oldManual, 1577836000);
$oldPre = $root . '/storage/backups/borealis-20200101-000001-pre-update.sql'; file_put_contents($oldPre, '-- previa'); touch($oldPre, 1577836001);
$admin->api('POST', '/api/members/' . $rosa . '/delete', ['confirm' => 1]);
$autos = array_filter(glob($root . '/storage/backups/*.sql') ?: [], fn($f) => !preg_match('/-(manual|pre-update)(-[a-f0-9]{12})?\.sql$/', $f));
check('Se guardan solo las 20 copias automáticas más recientes', $admin->status === 200 && count($autos) === 20 && !is_file($root . '/storage/backups/borealis-20200101-000000-antes-de-eliminar-usuario-000000000000.sql'), count($autos) . ' · ' . snippet($admin));
check('Las copias hechas a mano y las previas a una actualización no se borran', is_file($oldManual) && is_file($oldPre));
foreach (glob($root . '/storage/backups/borealis-20200101-*') ?: [] as $f) @unlink($f);

$admin->get('/admin/data');
check('Administración › Datos ofrece la copia completa (.zip) para guardar fuera del servidor', str_contains($admin->body, 'action="/admin/copia-completa"') && str_contains($admin->body, 'Descargar copia completa'), snippet($admin));
$bruno->form('/admin/copia-completa', []);
check('Sin ser administración de la instalación no se descarga (403)', $bruno->status === 403, snippet($bruno));
$admin->get('/admin/data');
$admin->form('/admin/copia-completa', []);
$zipTmp = tempnam(sys_get_temp_dir(), 'bz');
file_put_contents($zipTmp, $admin->body);
$zip = new ZipArchive();
$names = [];
if ($admin->status === 200 && $zip->open($zipTmp) === true) { for ($i = 0; $i < $zip->numFiles; $i++) $names[] = $zip->getNameIndex($i); $cfgInZip = (string)$zip->getFromName('config/config.php'); $zip->close(); }
$hasSql = (bool)array_filter($names, fn($n) => preg_match('~^base-de-datos/borealis-.*-completa-[a-f0-9]{12}\.sql$~', $n));
check('La copia completa trae base de datos, config/config.php, storage/ y un LEEME', $hasSql && in_array('config/config.php', $names, true) && in_array('LEEME.txt', $names, true) && (bool)array_filter($names, fn($n) => str_starts_with($n, 'storage/uploads/')), implode(', ', array_slice($names, 0, 12)));
check('…sin las copias viejas ni archivos temporales', !array_filter($names, fn($n) => str_starts_with($n, 'storage/backups/') || str_ends_with($n, '.lock')));
check('…y el ZIP temporal se borra del servidor después de enviarlo', !glob($root . '/storage/backups/descarga-*'));
@unlink($zipTmp);

group('1.16.4 · Seguridad de la instalación');
$admin->get('/admin/data');
check('Administración › Datos muestra «Seguridad de la instalación»', str_contains($admin->body, 'id="seguridad"') && str_contains($admin->body, 'Seguridad de la instalación'), snippet($admin));
check('…y avisa si hay una sola cuenta con administración de la instalación', str_contains($admin->body, 'Solo hay <strong>una</strong> cuenta'), snippet($admin));
$ex = json_decode((string)@file_get_contents($root . '/storage/exposure.json'), true) ?: [];
check('La comprobación queda guardada y no da «expuesto» con la raíz en public/', in_array($ex['status'] ?? '', ['safe', 'unknown'], true) && !is_file($root . '/storage/exposure-probe.txt'), json_encode($ex, JSON_UNESCAPED_UNICODE));
// simulated exposure: the red alert must appear on every page for the installation's administration
file_put_contents($root . '/storage/exposure.json', json_encode(['status' => 'exposed', 'checked_at' => time(), 'urls' => ['x'], 'detail' => 'prueba']));
$admin->get('/my-work');
check('Si storage/ se puede descargar, la administración ve una alerta roja en todas las páginas', str_contains($admin->body, 'se puede descargar desde internet'), snippet($admin));
$bruno->get('/my-work');
check('…que el resto de las personas no ve', !str_contains($bruno->body, 'se puede descargar desde internet'));
@unlink($root . '/storage/exposure.json');
$bruno->api('POST', '/api/admin/exposure-check');
check('Solo la administración de la instalación puede lanzar la comprobación', $bruno->status === 403, snippet($bruno));

group('1.16.4 · Avisos push solo a servicios conocidos');
$admin->api('POST', '/api/push/subscription', ['endpoint' => 'https://intranet.example.test/hook', 'p256dh' => rtrim(strtr(base64_encode("\x04" . random_bytes(64)), '+/', '-_'), '='), 'auth' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=')]);
check('Una dirección que no es de un servicio push se rechaza (422)', $admin->status === 422, snippet($admin));
check('…y no se guarda', (int)$q("SELECT COUNT(*) FROM push_subscriptions WHERE endpoint LIKE 'https://intranet.example.test%'")->fetchColumn() === 0);
