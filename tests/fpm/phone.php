<?php
declare(strict_types=1);
/*
 * 1.15 · A «phone» for tests/fpm/engine.sh: creates browser push keys and subscribes Ana's account
 * (setup), and later decrypts what the fake push service received (read), as the browser would.
 *   php phone.php setup <db> <endpoint>          → prints the keys (JSON)
 *   php phone.php read <keys.json> <fake-base> <name> → prints the decrypted messages
 */
$b64 = fn(string $b) => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
$b64d = fn(string $s) => (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
if (($argv[1] ?? '') === 'setup') {
    [, , $db, $endpoint] = $argv;
    $ua = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $d = openssl_pkey_get_details($ua)['ec'];
    $pub = "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
    $auth = random_bytes(16);
    openssl_pkey_export($ua, $pem);
    $pdo = new PDO("mysql:unix_socket=" . (getenv("MYSQL_SOCKET") ?: "/run/mysqld/mysqld.sock") . ";dbname=$db;charset=utf8mb4", "root", "", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $u = $pdo->query("SELECT id, prefs FROM users WHERE email = 'ana@example.test'")->fetch(PDO::FETCH_ASSOC);
    $prefs = json_decode((string)$u['prefs'], true) ?: [];
    $prefs['notify'] = array_merge($prefs['notify'] ?? [], ['push' => true, 'push_off' => [], 'off' => [], 'quiet' => ['on' => false, 'from' => '22:00', 'to' => '07:00']]);
    $pdo->prepare('UPDATE users SET prefs = ? WHERE id = ?')->execute([json_encode($prefs), $u['id']]);
    $pdo->prepare('DELETE FROM push_subscriptions WHERE user_id = ?')->execute([$u['id']]);
    $pdo->prepare('INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth, device, created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())')
        ->execute([$u['id'], $endpoint, hash('sha256', $endpoint), $b64($pub), $b64($auth), 'Teléfono de prueba']);
    echo json_encode(['pem' => $pem, 'pub' => $b64($pub), 'auth' => $b64($auth)]);
    exit;
}
[, , $keysFile, $base, $name] = $argv;
$k = json_decode((string)file_get_contents($keysFile), true);
$ua = openssl_pkey_get_private($k['pem']);
$uaPub = $b64d($k['pub']); $uaAuth = $b64d($k['auth']);
$pemPub = fn(string $raw) => "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw), 64, "\n") . "-----END PUBLIC KEY-----\n";
$log = json_decode((string)file_get_contents("$base/_log"), true) ?: [];
foreach ($log as $r) {
    if (!str_contains($r['path'] ?? '', $name)) continue;
    $body = base64_decode($r['body']);
    $salt = substr($body, 0, 16); $idlen = ord($body[20]); $asPub = substr($body, 21, $idlen); $ct = substr($body, 21 + $idlen);
    $shared = openssl_pkey_derive($pemPub($asPub), $ua, 32);
    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPub . $asPub, $uaAuth);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $plain = openssl_decrypt(substr($ct, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($ct, -16));
    echo ($plain === false ? '(no se pudo descifrar)' : rtrim($plain, "\0\x02")), "\n";
}
