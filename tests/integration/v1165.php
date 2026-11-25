<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1); // 1.16.5: never runs when requested from the web

/*
 * Borealis 1.16.5 — «Proteger ahora» (Administración › Datos): where the data folder is, the key renewed
 * with everything encrypted again, and the exposure check that knows the new place. The move itself is
 * tested in tests/unit/storage-1165.php (here the web root is public/, so storage/ is already outside).
 * Included by run.php after v1164.php (same globals).
 */

$root = dirname(__DIR__, 2);

group('1.16.5 · Proteger los datos privados');
$u1165 = json_decode(shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tests/unit/storage-1165.php')) ?: '', true);
foreach (($u1165['checks'] ?? [['name' => 'Las pruebas de tests/unit/storage-1165.php se ejecutan', 'ok' => false, 'detail' => '']]) as $uc) check($uc['name'], (bool)$uc['ok'], (string)$uc['detail']);

$admin->get('/admin/data');
check('Administración › Datos dice dónde están los datos privados', str_contains($admin->body, 'Dónde están los datos privados') && str_contains($admin->body, 'data-storage-place="outside"'), snippet($admin));
check('…y con la raíz en public/ no ofrece mover nada', !str_contains($admin->body, 'data-confirm-label="Proteger ahora"'), snippet($admin));
check('Con la llave escrita en config/config.php (instalaciones desde 1.16.4) no ofrece renovarla aquí', !str_contains($admin->body, 'Renovar la llave de cifrado'), snippet($admin));

// installations before 1.16.4 keep the key in storage/app.key: the case of a folder that was downloadable
$cfgFile = $root . '/config/config.php';
$cfgText = (string)file_get_contents($cfgFile);
if (preg_match("~'key' => 'base64:([^']+)',\\n~", $cfgText, $km)) {
    file_put_contents($root . '/storage/app.key', $km[1]);
    file_put_contents($cfgFile, str_replace($km[0], '', $cfgText));
    sleep(3); // the web server may keep the old config.php compiled for 2 s (opcache.revalidate_freq)
}
$admin->get('/admin/data');
check('Con la llave en storage/app.key ofrece «Renovar la llave de cifrado»', str_contains($admin->body, 'Renovar la llave de cifrado'), snippet($admin));

// secrets encrypted with the current key: mail password, /cron/run token, push key, a Discord webhook
$admin->api('POST', '/api/mail/settings', ['transport' => 'smtp', 'host' => 'smtp.example.test', 'port' => 587, 'username' => 'avisos@example.test', 'password' => 'clave-1165', 'from_address' => 'avisos@example.test', 'from_name' => 'Borealis']);
check('Se guarda un correo con contraseña', in_array($admin->status, [200, 409], true) && is_file($root . '/storage/mail.json'), snippet($admin));
$admin->get('/admin/notifications');
preg_match('~/cron/run\?token=([a-f0-9]{48})~', $admin->body, $tm);
$cronToken = $tm[1] ?? '';
check('Hay una dirección /cron/run con su clave', $cronToken !== '', snippet($admin));
$keyBefore = trim((string)@file_get_contents($root . '/storage/app.key'));
$vapidBefore = json_decode((string)@file_get_contents($root . '/storage/vapid.json'), true)['public'] ?? '';
$mailBefore = json_decode((string)@file_get_contents($root . '/storage/mail.json'), true)['password'] ?? '';

$bruno->api('POST', '/api/admin/protect-storage');
check('Solo la administración de la instalación puede usar «Proteger ahora»', $bruno->status === 403, snippet($bruno));

$admin->api('POST', '/api/admin/protect-storage');
$pj = $admin->json();
check('«Proteger ahora» renueva la llave y vuelve a comprobar', $admin->status === 200 && ($pj['rotated'] ?? false) === true && str_contains((string)($pj['message'] ?? json_encode($pj)), 'llave de cifrado es nueva'), snippet($admin));
$keyAfter = trim((string)@file_get_contents($root . '/storage/app.key'));
check('storage/app.key cambió', $keyBefore !== '' && $keyAfter !== '' && $keyAfter !== $keyBefore);
check('La llave anterior queda solo para los ids de Google Calendar (storage/ids.key)', trim((string)@file_get_contents($root . '/storage/ids.key')) === $keyBefore);
check('Antes se hizo una copia de la base de datos', (bool)glob($root . '/storage/backups/borealis-*-pre-llave-*.sql'));
$mailAfter = json_decode((string)@file_get_contents($root . '/storage/mail.json'), true)['password'] ?? '';
check('La contraseña del correo se cifró de nuevo', $mailAfter !== '' && $mailAfter !== $mailBefore);
$admin->get('/admin/data');
check('…y Borealis la sigue leyendo (el formulario sabe que hay una guardada)', $admin->status === 200 && str_contains($admin->body, 'smtp.example.test'), snippet($admin));
$admin->get('/admin/notifications');
check('La dirección /cron/run no cambia (cron-job.org sigue funcionando)', $cronToken !== '' && str_contains($admin->body, $cronToken), snippet($admin));
$anonCron = new Client($BASE);
$anonCron->get('/cron/run?token=' . $cronToken);
check('…y responde con la llave nueva', $anonCron->status === 200, snippet($anonCron));
check('La clave pública de los avisos push no cambia (los dispositivos siguen activos)', $vapidBefore !== '' && (json_decode((string)@file_get_contents($root . '/storage/vapid.json'), true)['public'] ?? '') === $vapidBefore);
$admin->get('/my-work');
check('Las páginas siguen funcionando con la llave nueva', $admin->status === 200, snippet($admin));
$ex = json_decode((string)@file_get_contents($root . '/storage/exposure.json'), true) ?: [];
check('La comprobación sabe que los datos están fuera de la web', ($ex['placement'] ?? '') === 'outside' && ($ex['status'] ?? '') !== 'exposed', json_encode($ex, JSON_UNESCAPED_UNICODE));

$admin->api('POST', '/api/mail/settings', ['transport' => 'none']);
foreach (glob($root . '/storage/backups/borealis-*-pre-llave-*.sql') ?: [] as $f) @unlink($f);
