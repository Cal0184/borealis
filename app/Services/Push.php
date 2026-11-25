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
        $known = (bool)Database::get()->value('SELECT 1 FROM push_subscriptions WHERE user_id = ? AND endpoint_hash = ?', [Auth::id(), hash('sha256', $v['endpoint'])]);
        Database::get()->run('INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth, device, created_at) VALUES (?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth), device = VALUES(device), failures = 0, last_error = NULL',
            [Auth::id(), $v['endpoint'], hash('sha256', $v['endpoint']), $v['p256dh'], $v['auth'], $v['device'] ? mb_substr($v['device'], 0, 120) : null, now()]);
        // 1.14: activating a device turns on the person's «notifications on devices» switch (server side,
        // so it no longer depends on the profile form being saved after the browser's permission).
        $prefs = Auth::user()['prefs'] ?? [];
        if (empty($prefs['notify']['push']) && array_key_exists('notify', $prefs)) {
            $prefs['notify']['push'] = true;
            Database::get()->update('users', ['prefs' => json_encode($prefs), 'updated_at' => now()], 'id = ?', [Auth::id()]);
            Auth::reset();
            Notifications::forgetPrefs((int)Auth::id());
        }
        // A new device receives what happens from now on, not a burst of the day's earlier notices.
        // (1.14: the daily check of a device that was already known changes nothing here.)
        if (!$known) Database::get()->run('UPDATE notifications SET pushed_at = ? WHERE user_id = ? AND pushed_at IS NULL', [now(), Auth::id()]);
    }

    /**
     * 1.14 · the browser replaced its subscription by itself (service worker «pushsubscriptionchange»),
     * possibly with nobody signed in. Proof that it is the same device: the old endpoint and its old
     * «auth» secret, which only that browser and Borealis know. Returns false when they do not match.
     */
    public static function renew(array $input): bool
    {
        $v = Validator::check($input, ['old_endpoint' => 'required,string,max:1000', 'old_auth' => 'required,string,max:100', 'endpoint' => 'required,string,max:1000', 'p256dh' => 'required,string,max:200', 'auth' => 'required,string,max:100']);
        $db = Database::get();
        $row = $db->one('SELECT * FROM push_subscriptions WHERE endpoint_hash = ?', [hash('sha256', $v['old_endpoint'])]);
        if (!$row || !hash_equals((string)$row['auth'], $v['old_auth'])) return false;
        $u = parse_url($v['endpoint']);
        if ((($u['scheme'] ?? '') !== 'https' && !self::testEndpoint($v['endpoint'])) || strlen(self::b64d($v['p256dh'])) !== 65 || strlen(self::b64d($v['auth'])) !== 16) return false;
        $db->run('DELETE FROM push_subscriptions WHERE endpoint_hash = ? AND id <> ?', [hash('sha256', $v['endpoint']), $row['id']]);
        $db->run('UPDATE push_subscriptions SET endpoint = ?, endpoint_hash = ?, p256dh = ?, auth = ?, failures = 0, last_error = NULL WHERE id = ?',
            [$v['endpoint'], hash('sha256', $v['endpoint']), $v['p256dh'], $v['auth'], $row['id']]);
        return true;
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
            \Borealis\Core\Background::detach(); // 1.16: PHP-FPM, LiteSpeed or Apache
            try { foreach (array_keys(self::$pending) as $uid) self::flush((int)$uid); } catch (\Throwable $e) { error_log('[Borealis] push: ' . $e->getMessage()); }
        });
    }

    /** Minutes to wait before each retry of a message the push service did not accept for a passing reason. */
    public const RETRY_MINUTES = [5, 15, 60, 180];

    /** Result of the last deliver(): ['code' => int, 'error' => ?string, 'kind' => ok|gone|retry|failed]. */
    public static array $last = [];

    /**
     * Offers each pending notice (last 24 h, not yet offered) to every device of the person, if they
     * want that category on their phone. Returns how many messages were delivered to a push service.
     * 1.9: during the person's quiet hours nothing is sent (the notices wait, unclaimed, and go out
     * when the quiet hours end); notices whose task was completed or whose date changed are dropped;
     * more than three at once become a single «N avisos nuevos» message.
     * 1.14: every message to every device is written to notification_deliveries (one row per notice
     * and device, UNIQUE, so it is never sent twice), with its error; a passing failure (no network,
     * 429, 5xx) is retried by the scheduled job (retryDue) instead of being lost.
     */
    public static function flush(?int $userId = null): int
    {
        $db = Database::get();
        $log = Scheduler::ready();
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
                $last = $out[count($out) - 1];
                $payload = self::groupPayload(count($out), $last);
                foreach ($subs as $sub) if (self::send($sub, $last, $payload, 'normal', $log)) $sent++;
                continue;
            }
            foreach ($out as $n) {
                $payload = self::payload($n);
                foreach ($subs as $sub) if (self::send($sub, $n, $payload, self::urgency($n), $log)) $sent++;
            }
        }
        return $sent;
    }

    private static function urgency(array $n): string
    {
        return in_array($n['type'], ['overdue', 'due', 'assigned'], true) ? 'high' : 'normal';
    }

    public static function payload(array $n): string
    {
        return json_encode(['title' => Notifications::TYPES[$n['type']] ?? 'Borealis', 'body' => $n['message'], 'url' => $n['url'] ?: url('/notifications'),
            'tag' => 'borealis-' . ($n['dedupe_key'] ? substr(md5($n['dedupe_key']), 0, 12) : $n['id']), 'id' => (int)$n['id']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function groupPayload(int $count, array $last): string
    {
        return json_encode(['title' => 'Borealis', 'body' => $count . ' avisos nuevos: ' . mb_substr($last['message'], 0, 120) . '…', 'url' => url('/notifications'),
            'tag' => 'borealis-grupo', 'id' => (int)$last['id']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** One notice to one device, recorded once in notification_deliveries (when the 1.14 table exists). */
    private static function send(array $sub, array $n, string $payload, string $urgency, bool $log): bool
    {
        $db = Database::get();
        if ($log) {
            $ins = $db->run('INSERT IGNORE INTO notification_deliveries (notification_id, subscription_id, user_id, state, attempts, created_at, updated_at) VALUES (?,?,?,?,0,?,?)',
                [$n['id'], $sub['id'], $sub['user_id'], 'sending', now(), now()])->rowCount();
            if ($ins !== 1) return false; // already offered to this device
        }
        $ok = self::deliver($sub, $payload, $urgency);
        if ($log) self::record((int)$n['id'], (int)$sub['id'], 1);
        return $ok;
    }

    /** Writes the outcome of the last deliver() on the delivery row and plans the next attempt. */
    private static function record(int $notificationId, int $subId, int $attempt): void
    {
        $l = self::$last;
        $state = $l['kind'];
        $next = null;
        if ($state === 'retry') {
            if ($attempt > count(self::RETRY_MINUTES)) $state = 'failed';
            else $next = gmdate('Y-m-d H:i:s', time() + 60 * self::RETRY_MINUTES[$attempt - 1]);
        }
        Database::get()->run('UPDATE notification_deliveries SET state = ?, attempts = ?, last_code = ?, last_error = ?, next_attempt_at = ?, updated_at = ? WHERE notification_id = ? AND subscription_id = ?',
            [$state === 'ok' ? 'sent' : $state, $attempt, $l['code'] ?: null, $l['error'] ? mb_substr((string)$l['error'], 0, 255) : null, $next, now(), $notificationId, $subId]);
        // 1.15: a message that will not be delivered goes to the Master Log, with the reason
        if ($state === 'failed') {
            try {
                $n = Database::get()->one('SELECT n.workspace_id, n.user_id, n.project_id, n.task_id, u.name FROM notifications n JOIN users u ON u.id = n.user_id WHERE n.id = ?', [$notificationId]);
                if ($n) Activity::log('notify_failed', 'notification', $notificationId, 'No se pudo entregar un aviso al dispositivo de ' . $n['name'], $n['project_id'] ? (int)$n['project_id'] : null, $n['task_id'] ? (int)$n['task_id'] : null, [], (int)$n['workspace_id'],
                    ['category' => 'notification', 'result' => 'error', 'source' => 'system', 'user_id' => null, 'after' => ['código' => $l['code'] ?: null, 'motivo' => $l['error'] ? mb_substr((string)$l['error'], 0, 200) : null, 'intentos' => $attempt]]);
            } catch (\Throwable $e) { \Borealis\Core\ErrorHandler::log($e); }
        }
    }

    /**
     * 1.14: sends again the messages whose last attempt failed for a passing reason and whose next
     * attempt is due. Not when the notice was read meanwhile, its task is done, the date changed, or
     * the person is in quiet hours (it waits). Returns how many were accepted this time.
     */
    public static function retryDue(): int
    {
        $db = Database::get();
        $rows = $db->all("SELECT d.notification_id, d.subscription_id, d.attempts, n.* FROM notification_deliveries d JOIN notifications n ON n.id = d.notification_id
            WHERE d.state = 'retry' AND d.next_attempt_at <= ? ORDER BY d.next_attempt_at LIMIT 200", [now()]);
        $ok = 0;
        foreach ($rows as $r) {
            $uid = (int)$r['user_id'];
            if (UserTime::isQuiet($uid)) continue;
            $sub = $db->one('SELECT * FROM push_subscriptions WHERE id = ?', [$r['subscription_id']]);
            if (!$sub || $r['read_at'] || !Notifications::stillRelevant($r) || strtotime($r['created_at'] . ' UTC') < time() - 86400) {
                $db->run("UPDATE notification_deliveries SET state = 'failed', next_attempt_at = NULL, last_error = ?, updated_at = ? WHERE notification_id = ? AND subscription_id = ?",
                    [$sub ? 'Ya no hacía falta: el aviso se leyó, la tarea cambió o pasaron 24 horas.' : 'El dispositivo ya no tiene notificaciones activadas.', now(), $r['notification_id'], $r['subscription_id']]);
                continue;
            }
            // claim it, so two runs never retry the same message
            if ($db->run("UPDATE notification_deliveries SET state = 'sending', updated_at = ? WHERE notification_id = ? AND subscription_id = ? AND state = 'retry'", [now(), $r['notification_id'], $r['subscription_id']])->rowCount() !== 1) continue;
            $n = $r;
            $n['id'] = $r['notification_id'];
            if (self::deliver($sub, self::payload($n), self::urgency($n))) $ok++;
            self::record((int)$r['notification_id'], (int)$r['subscription_id'], (int)$r['attempts'] + 1);
        }
        return $ok;
    }

    /** Deliveries of the last days for Administración › Notificaciones (counts and last errors). */
    public static function deliveryStats(int $days = 7): array
    {
        $db = Database::get();
        $since = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $counts = array_column($db->all('SELECT state, COUNT(*) AS n FROM notification_deliveries WHERE created_at >= ? GROUP BY state', [$since]), 'n', 'state');
        $errors = $db->all("SELECT d.state, d.attempts, d.last_code, d.last_error, d.updated_at, d.next_attempt_at, u.name AS user_name, s.device FROM notification_deliveries d
            JOIN users u ON u.id = d.user_id LEFT JOIN push_subscriptions s ON s.id = d.subscription_id
            WHERE d.created_at >= ? AND d.state IN ('retry','failed') ORDER BY d.updated_at DESC LIMIT 10", [$since]);
        return ['counts' => array_map('intval', $counts), 'errors' => $errors];
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
            self::$last = ['code' => $code, 'error' => null, 'kind' => 'ok'];
            return true;
        }
        if ($code === 404 || $code === 410) { // the device unsubscribed or the browser was reset
            $db->run('DELETE FROM push_subscriptions WHERE id = ?', [$sub['id']]);
            self::$last = ['code' => $code, 'error' => 'El navegador ya no tiene esta suscripción (se quitó el dispositivo).', 'kind' => 'gone'];
            return false;
        }
        $msg = $code ? "El servicio de notificaciones respondió $code" . (is_string($res) && $res !== '' ? ': ' . mb_substr(strip_tags($res), 0, 120) : '') : 'Sin conexión con el servicio de notificaciones: ' . $err;
        // 401/403: the VAPID keys changed (storage/vapid.json replaced) — the device has to be activated again.
        if ($code === 401 || $code === 403) $msg .= ' (las claves del servidor cambiaron: vuelve a activar las notificaciones en ese dispositivo)';
        $db->run('UPDATE push_subscriptions SET last_error = ?, failures = failures + 1 WHERE id = ?', [mb_substr($msg, 0, 255), $sub['id']]);
        $db->run('DELETE FROM push_subscriptions WHERE id = ? AND failures >= 20', [$sub['id']]);
        self::$last = ['code' => $code, 'error' => $msg, 'kind' => ($code === 0 || $code === 408 || $code === 429 || $code >= 500) ? 'retry' : 'failed'];
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
