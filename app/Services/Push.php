<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Validator;
use Borealis\Security\Secrets;

/**
 * Mobile and desktop notifications with the Web Push standard (1.8), without external libraries:
 *  - VAPID (RFC 8292): the server signs each request with its own P-256 key (ES256 JWT). The key
 *    pair is created once and kept in storage/vapid.json (private key encrypted with storage/app.key).
 *  - Payload encryption «aes128gcm» (RFC 8188 / RFC 8291): only the person's browser can read it.
 * The browser vendor's push service (Google FCM, Mozilla, Apple) delivers it to the device even
 * when Borealis is closed. iPhone/iPad: only with Borealis added to the Home Screen (iOS 16.4+).
 * Notices are sent right after the response (assignments) and by bin/notify.php from cron
 * (reminders while nobody has Borealis open). Each notice is offered once (notifications.pushed_at).
 */
final class Push
{
    private const DER_P256_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';
    private static array $pending = [];
    private static bool $hooked = false;
    /** For tests: where requests went (endpoint => headers/body), when BOREALIS_PUSH_LOG is set. */
    public static array $sent = [];

    public static function available(): bool
    {
        return extension_loaded('openssl') && function_exists('openssl_pkey_derive') && in_array('aes-128-gcm', openssl_get_cipher_methods(), true) && function_exists('curl_init');
    }

    // ------------------------------------------------------------------ keys

    private static function keyFile(): string
    {
        return BASE_PATH . '/storage/vapid.json';
    }

    /** Server key pair: ['public' => raw 65 bytes, 'pem' => private key PEM]. Created on first use. */
    public static function keys(): array
    {
        static $k = null;
        if ($k) return $k;
        $f = self::keyFile();
        if (is_file($f)) {
            $j = json_decode((string)file_get_contents($f), true);
            $pem = is_array($j) ? Secrets::decrypt((string)($j['private'] ?? '')) : null;
            if ($pem) return $k = ['public' => self::b64d((string)$j['public']), 'pem' => $pem];
        }
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (!$key) throw new \RuntimeException('No se pudo crear la clave de notificaciones (OpenSSL sin curvas EC).');
        openssl_pkey_export($key, $pem);
        $d = openssl_pkey_get_details($key)['ec'];
        $pub = "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
        $tmp = $f . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, json_encode(['public' => self::b64($pub), 'private' => Secrets::encrypt($pem), 'created' => gmdate('c')])) === false || !@rename($tmp, $f)) {
            throw new \RuntimeException('No se pudo guardar storage/vapid.json: revisa los permisos de storage/.');
        }
        @chmod($f, 0600);
        return $k = ['public' => $pub, 'pem' => $pem];
    }

    public static function publicKey(): string
    {
        return self::b64(self::keys()['public']);
    }

    // ------------------------------------------------------------------ subscriptions

    public static function subscribe(array $input): void
    {
        $v = Validator::check($input, ['endpoint' => 'required,string,max:1000', 'p256dh' => 'required,string,max:200', 'auth' => 'required,string,max:100', 'device' => 'nullable,string,max:120']);
        $u = parse_url($v['endpoint']);
        if (($u['scheme'] ?? '') !== 'https' && !self::testEndpoint($v['endpoint'])) throw HttpException::invalid(['endpoint' => 'La suscripción no es válida.']);
        if (strlen(self::b64d($v['p256dh'])) !== 65 || strlen(self::b64d($v['auth'])) !== 16) throw HttpException::invalid(['p256dh' => 'Las claves de la suscripción no son válidas.']);
        Database::get()->run('INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth, device, created_at) VALUES (?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth), device = VALUES(device), failures = 0, last_error = NULL',
            [Auth::id(), $v['endpoint'], hash('sha256', $v['endpoint']), $v['p256dh'], $v['auth'], $v['device'] ? mb_substr($v['device'], 0, 120) : null, now()]);
        // A new device receives what happens from now on, not a burst of the day's earlier notices.
        Database::get()->run('UPDATE notifications SET pushed_at = ? WHERE user_id = ? AND pushed_at IS NULL', [now(), Auth::id()]);
    }

    public static function unsubscribe(?string $endpoint): int
    {
        $db = Database::get();
        if ($endpoint) return $db->run('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint_hash = ?', [Auth::id(), hash('sha256', $endpoint)])->rowCount();
        return $db->run('DELETE FROM push_subscriptions WHERE user_id = ?', [Auth::id()])->rowCount();
    }

    public static function devices(int $userId): array
    {
        return Database::get()->all('SELECT id, device, created_at, last_ok_at, last_error FROM push_subscriptions WHERE user_id = ? ORDER BY created_at DESC', [$userId]);
    }

    /** Local endpoints are accepted only by the test suite (BOREALIS_PUSH_TEST_HOSTS). */
    private static function testEndpoint(string $url): bool
    {
        $hosts = array_filter(explode(',', (string)(getenv('BOREALIS_PUSH_TEST_HOSTS') ?: config('push.test_hosts', ''))));
        return $hosts && in_array(parse_url($url, PHP_URL_HOST) . ':' . parse_url($url, PHP_URL_PORT), $hosts, true);
    }

    // ------------------------------------------------------------------ sending

    /** Sends the person's new notices after the response, so the page does not wait for it. */
    public static function later(int $userId): void
    {
        if (!self::$pending && !Database::get()->value('SELECT 1 FROM push_subscriptions WHERE user_id = ? LIMIT 1', [$userId])) return;
        self::$pending[$userId] = true;
        if (self::$hooked || PHP_SAPI === 'cli') return;
        self::$hooked = true;
        register_shutdown_function(function () {
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            try { foreach (array_keys(self::$pending) as $uid) self::flush((int)$uid); } catch (\Throwable $e) { error_log('[Borealis] push: ' . $e->getMessage()); }
        });
    }

    /**
     * Offers each pending notice (last 24 h, not yet offered) to every device of the person, if they
     * want that category on their phone. Returns how many messages were delivered to a push service.
     * 1.9: during the person's quiet hours nothing is sent (the notices wait, unclaimed, and go out
     * when the quiet hours end); notices whose task was completed or whose date changed are dropped;
     * more than three at once become a single «N avisos nuevos» message.
     */
    public static function flush(?int $userId = null): int
    {
        $db = Database::get();
        $sql = 'SELECT n.* FROM notifications n WHERE n.pushed_at IS NULL AND n.read_at IS NULL AND n.created_at >= ?' . ($userId ? ' AND n.user_id = ?' : '') . ' ORDER BY n.id LIMIT 500';
        $rows = $db->all($sql, $userId ? [gmdate('Y-m-d H:i:s', time() - 86400), $userId] : [gmdate('Y-m-d H:i:s', time() - 86400)]);
        $byUser = [];
        foreach ($rows as $n) $byUser[(int)$n['user_id']][] = $n;
        $sent = 0;
        foreach ($byUser as $uid => $list) {
            if (UserTime::isQuiet($uid)) continue;
            $out = [];
            foreach ($list as $n) {
                // claim it: two processes never send the same notice
                if ($db->run('UPDATE notifications SET pushed_at = ? WHERE id = ? AND pushed_at IS NULL', [now(), $n['id']])->rowCount() !== 1) continue;
                if (!Notifications::wantsPush($uid, (string)$n['type'])) continue;
                if (!Notifications::stillRelevant($n)) continue;
                $out[] = $n;
            }
            if (!$out) continue;
            $subs = $db->all('SELECT * FROM push_subscriptions WHERE user_id = ?', [$uid]);
            if (count($out) > 3) {
                $payload = json_encode(['title' => 'Borealis', 'body' => count($out) . ' avisos nuevos: ' . mb_substr($out[count($out) - 1]['message'], 0, 120) . '…', 'url' => url('/notifications'),
                    'tag' => 'borealis-grupo', 'id' => (int)$out[count($out) - 1]['id']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                foreach ($subs as $sub) if (self::deliver($sub, $payload, 'normal')) $sent++;
                continue;
            }
            foreach ($out as $n) {
                $payload = json_encode(['title' => Notifications::TYPES[$n['type']] ?? 'Borealis', 'body' => $n['message'], 'url' => $n['url'] ?: url('/notifications'),
                    'tag' => 'borealis-' . ($n['dedupe_key'] ? substr(md5($n['dedupe_key']), 0, 12) : $n['id']), 'id' => (int)$n['id']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                foreach ($subs as $sub) {
                    if (self::deliver($sub, $payload, in_array($n['type'], ['overdue', 'due', 'assigned'], true) ? 'high' : 'normal')) $sent++;
                }
            }
        }
        return $sent;
    }

    /** One encrypted message to one device. Removes subscriptions the push service says are gone. */
    public static function deliver(array $sub, string $payload, string $urgency = 'normal'): bool
    {
        $endpoint = (string)$sub['endpoint'];
        $body = self::encrypt($payload, self::b64d((string)$sub['p256dh']), self::b64d((string)$sub['auth']));
        $headers = ['Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm', 'TTL: 86400', 'Urgency: ' . $urgency,
            'Authorization: ' . self::vapidHeader($endpoint), 'Content-Length: ' . strlen($body)];
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4]);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $db = Database::get();
        if ($code >= 200 && $code < 300) {
            $db->run('UPDATE push_subscriptions SET last_ok_at = ?, last_error = NULL, failures = 0 WHERE id = ?', [now(), $sub['id']]);
            return true;
        }
        if ($code === 404 || $code === 410) { // the device unsubscribed or the browser was reset
            $db->run('DELETE FROM push_subscriptions WHERE id = ?', [$sub['id']]);
            return false;
        }
        $msg = $code ? "El servicio de notificaciones respondió $code" . (is_string($res) && $res !== '' ? ': ' . mb_substr(strip_tags($res), 0, 120) : '') : 'Sin conexión con el servicio de notificaciones: ' . $err;
        $db->run('UPDATE push_subscriptions SET last_error = ?, failures = failures + 1 WHERE id = ?', [mb_substr($msg, 0, 255), $sub['id']]);
        $db->run('DELETE FROM push_subscriptions WHERE id = ? AND failures >= 20', [$sub['id']]);
        return false;
    }

    // ------------------------------------------------------------------ crypto (RFC 8291 + RFC 8292)

    /** «aes128gcm» body for a subscription (ua_public: 65 bytes, auth: 16 bytes). */
    public static function encrypt(string $plain, string $uaPublic, string $authSecret, ?array $fixed = null): string
    {
        $local = $fixed['key'] ?? openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $d = openssl_pkey_get_details($local)['ec'];
        $asPublic = "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
        $shared = openssl_pkey_derive(self::publicPem($uaPublic), $local, 32);
        if ($shared === false) throw new \RuntimeException('No se pudo derivar la clave de la suscripción.');
        $salt = $fixed['salt'] ?? random_bytes(16);
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $tag = '';
        $cipher = openssl_encrypt($plain . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
    }

    /** «vapid t=<JWT>, k=<public key>» for the push service of that endpoint. */
    public static function vapidHeader(string $endpoint): string
    {
        $u = parse_url($endpoint);
        $aud = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
        $sub = (string)config('push.subject', '') ?: 'mailto:' . (string)(config('mail.from') ?: 'admin@' . (parse_url((string)config('app.url', ''), PHP_URL_HOST) ?: 'borealis.invalid'));
        $h = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $c = self::b64(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $sub], JSON_UNESCAPED_SLASHES));
        openssl_sign("$h.$c", $der, self::keys()['pem'], OPENSSL_ALGO_SHA256);
        return 'vapid t=' . "$h.$c." . self::b64(self::derToRaw($der)) . ', k=' . self::publicKey();
    }

    public static function publicPem(string $raw): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin(self::DER_P256_PREFIX) . $raw), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** ECDSA signature: DER SEQUENCE(r, s) → 64 raw bytes, as JWT ES256 requires. */
    public static function derToRaw(string $der): string
    {
        $pos = 2;
        if (ord($der[1]) & 0x80) $pos += ord($der[1]) & 0x7f;
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$pos + 1]);
            $int = substr($der, $pos + 2, $len);
            $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
            $pos += 2 + $len;
        }
        return $out;
    }

    public static function b64(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64d(string $s): string
    {
        return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }
}
