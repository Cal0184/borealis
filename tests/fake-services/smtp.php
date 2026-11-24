<?php
declare(strict_types=1);

/*
 * A local SMTP server, ONLY for the test-suite (1.9). It speaks enough SMTP to check Borealis' side:
 * EHLO, STARTTLS (with a certificate made by the test), AUTH PLAIN / LOGIN against one user and
 * password, MAIL FROM, RCPT TO, DATA. Every accepted message is appended to a JSON-lines file.
 * It proves what Borealis sends and that it refuses to send a password without TLS; it does not
 * prove that a real mail server (or the recipient's provider) accepts it.
 *
 *   FAKE_SMTP_LOG=/tmp/m.jsonl FAKE_SMTP_CERT=/tmp/c.pem FAKE_SMTP_USER=u FAKE_SMTP_PASS=p \
 *     php tests/fake-services/smtp.php 2525
 * FAKE_SMTP_CERT empty: no STARTTLS offered. FAKE_SMTP_DOWN_FILE: while that file exists, answers 421.
 */

$port = (int)($argv[1] ?? 2525);
$log = getenv('FAKE_SMTP_LOG') ?: sys_get_temp_dir() . '/fake-smtp.jsonl';
$cert = getenv('FAKE_SMTP_CERT') ?: '';
$user = getenv('FAKE_SMTP_USER') ?: 'borealis@example.test';
$pass = getenv('FAKE_SMTP_PASS') ?: 'secreto';
$down = getenv('FAKE_SMTP_DOWN_FILE') ?: '';
$ctx = stream_context_create($cert ? ['ssl' => ['local_cert' => $cert, 'allow_self_signed' => true, 'verify_peer' => false]] : []);
$srv = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if (!$srv) { fwrite(STDERR, "$errstr\n"); exit(1); }

while ($c = @stream_socket_accept($srv, -1)) {
    stream_set_timeout($c, 10);
    $say = fn(string $l) => fwrite($c, $l . "\r\n");
    clearstatcache();
    if ($down && is_file($down)) { $say('421 Servicio no disponible'); fclose($c); continue; }
    $say('220 fake-smtp listo');
    $tls = false; $authed = false; $from = null; $to = []; $authLog = [];
    while (($line = fgets($c)) !== false) {
        $l = rtrim($line, "\r\n");
        $cmd = strtoupper(strtok($l, ' ') ?: '');
        if ($cmd === 'EHLO' || $cmd === 'HELO') {
            $caps = ['250-fake-smtp'];
            if ($cert && !$tls) $caps[] = '250-STARTTLS';
            $caps[] = '250-AUTH PLAIN LOGIN';
            $caps[] = '250 8BITMIME';
            fwrite($c, implode("\r\n", $caps) . "\r\n");
        } elseif ($cmd === 'STARTTLS' && $cert) {
            $say('220 adelante');
            if (!stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) break;
            $tls = true;
        } elseif ($cmd === 'AUTH') {
            $parts = explode(' ', $l);
            if (strtoupper($parts[1] ?? '') === 'PLAIN') {
                $raw = base64_decode($parts[2] ?? '');
                [, $u, $p] = array_pad(explode("\0", (string)$raw), 3, '');
            } else {
                $say('334 VXNlcm5hbWU6'); $u = base64_decode(trim((string)fgets($c)));
                $say('334 UGFzc3dvcmQ6'); $p = base64_decode(trim((string)fgets($c)));
            }
            $authLog = ['user' => $u, 'tls' => $tls];
            if ($u === $user && $p === $pass) { $authed = true; $say('235 ok'); } else $say('535 5.7.8 credenciales no válidas');
        } elseif ($cmd === 'MAIL') {
            if (!$authed) { $say('530 autenticación requerida'); continue; }
            $from = preg_match('/<([^>]*)>/', $l, $m) ? $m[1] : null; $to = []; $say('250 ok');
        } elseif ($cmd === 'RCPT') {
            $to[] = preg_match('/<([^>]*)>/', $l, $m) ? $m[1] : null; $say('250 ok');
        } elseif ($cmd === 'DATA') {
            $say('354 termina con .');
            $data = '';
            while (($d = fgets($c)) !== false) { if (rtrim($d, "\r\n") === '.') break; $data .= $d; }
            [$h, $body] = array_pad(explode("\r\n\r\n", $data, 2), 2, '');
            $subject = preg_match('/^Subject: (.*)$/mi', $h, $m) ? trim($m[1]) : '';
            if (preg_match('/^=\?UTF-8\?B\?(.*)\?=$/', $subject, $m)) $subject = base64_decode($m[1]);
            // decode the text part (base64) so the tests can read it
            $text = preg_match('/Content-Type: text\/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n([A-Za-z0-9+\/=\r\n]+)/', $data, $m) ? base64_decode(str_replace("\r\n", '', $m[1])) : '';
            file_put_contents($log, json_encode(['from' => $from, 'to' => $to, 'subject' => $subject, 'text' => $text, 'tls' => $tls, 'auth' => $authLog, 'headers' => $h, 'at' => gmdate('c')], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
            $say('250 aceptado');
        } elseif ($cmd === 'QUIT') {
            $say('221 adiós'); break;
        } else {
            $say('502 no implementado');
        }
    }
    @fclose($c);
}
