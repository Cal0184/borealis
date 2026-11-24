<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Session;
use Borealis\Security\Secrets;

/**
 * Google connection of a person (1.6): OAuth 2.0 «authorization code» flow with PKCE against the
 * official endpoints, and a small REST client for Google Calendar API v3 and Google Tasks API v1.
 *
 * Nothing works until the installation's administration creates an OAuth client in Google Cloud and
 * writes its id and secret in config/config.php ('google' => [...], see README «Google»). Tokens are
 * kept encrypted on the server (Secrets) and never reach the browser. Endpoints can be overridden in
 * config only so the test-suite can point them at a local fake server.
 */
final class Google
{
    /** Minimal scopes: identify the account, list calendars, read/write events, read/write tasks. */
    public const SCOPES = [
        'openid', 'email',
        'https://www.googleapis.com/auth/calendar.calendarlist.readonly',
        'https://www.googleapis.com/auth/calendar.events',
        'https://www.googleapis.com/auth/tasks',
    ];

    public static function configured(): bool
    {
        return trim((string)config('google.client_id', '')) !== '' && trim((string)config('google.client_secret', '')) !== '';
    }

    public static function redirectUri(): string
    {
        return rtrim((string)config('google.redirect_uri', ''), '/') ?: absolute_url('/google/callback');
    }

    private static function ep(string $k): string
    {
        $def = ['auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token_url' => 'https://oauth2.googleapis.com/token',
            'revoke_url' => 'https://oauth2.googleapis.com/revoke', 'userinfo_url' => 'https://openidconnect.googleapis.com/v1/userinfo', 'api_base' => 'https://www.googleapis.com'];
        return rtrim((string)config('google.' . $k, $def[$k]), '/');
    }

    /** The person's connection, without tokens. */
    public static function account(?int $uid = null): ?array
    {
        $a = Database::get()->one('SELECT id, user_id, google_email, scopes, expires_at, status, last_error, last_sync_at, export_calendar_id, export_calendar_name, export_options, export_state, export_error, export_synced_at, export_failures, created_at FROM google_accounts WHERE user_id = ?', [$uid ?? Auth::id()]);
        return $a ?: null;
    }

    // ---- OAuth ---------------------------------------------------------------------

    public static function authUrl(): string
    {
        if (!self::configured()) throw HttpException::conflict('La conexión con Google no está configurada en esta instalación. La administración debe seguir los pasos de README › Google.');
        $state = bin2hex(random_bytes(16));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        Session::set('google_oauth', ['state' => $state, 'verifier' => $verifier, 'uid' => Auth::id(), 'at' => time()]);
        return self::ep('auth_url') . '?' . http_build_query([
            'client_id' => config('google.client_id'), 'redirect_uri' => self::redirectUri(), 'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES), 'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true',
            'state' => $state, 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
        ]);
    }

    /** Callback: checks state, exchanges the code, stores the encrypted tokens. Returns a message for the person. */
    public static function finish(array $q): string
    {
        $s = Session::get('google_oauth');
        Session::forget('google_oauth');
        if (!empty($q['error'])) return $q['error'] === 'access_denied' ? 'No se conectó Google: cancelaste el permiso.' : 'Google rechazó la conexión (' . mb_substr((string)$q['error'], 0, 80) . ').';
        if (!$s || !hash_equals((string)$s['state'], (string)($q['state'] ?? '')) || (int)$s['uid'] !== Auth::id() || time() - (int)$s['at'] > 900) {
            throw HttpException::forbidden('La solicitud de conexión con Google caducó o no es válida. Vuelve a intentarlo desde tu perfil.');
        }
        $tok = self::post(self::ep('token_url'), ['code' => (string)($q['code'] ?? ''), 'client_id' => config('google.client_id'), 'client_secret' => config('google.client_secret'),
            'redirect_uri' => self::redirectUri(), 'grant_type' => 'authorization_code', 'code_verifier' => $s['verifier']]);
        if (empty($tok['access_token'])) throw new GoogleError('Google no entregó un token de acceso.');
        $granted = explode(' ', (string)($tok['scope'] ?? implode(' ', self::SCOPES)));
        $info = self::http('GET', self::ep('userinfo_url'), null, (string)$tok['access_token']);
        $db = Database::get();
        $old = $db->one('SELECT * FROM google_accounts WHERE user_id = ?', [Auth::id()]);
        $row = ['google_email' => mb_substr((string)($info['email'] ?? ''), 0, 190) ?: null, 'scopes' => implode(' ', $granted),
            'access_token' => Secrets::encrypt((string)$tok['access_token']), 'expires_at' => gmdate('Y-m-d H:i:s', time() + (int)($tok['expires_in'] ?? 3600) - 60),
            'status' => 'active', 'last_error' => null, 'updated_at' => now()];
        if (!empty($tok['refresh_token'])) $row['refresh_token'] = Secrets::encrypt((string)$tok['refresh_token']);
        if ($old) {
            if ($old['google_email'] && $row['google_email'] && strcasecmp($old['google_email'], $row['google_email']) !== 0) {
                // another Google account: forget the links of the previous one (data in Borealis stays, unlinked)
                self::unlinkData((int)$old['id']);
            }
            $db->update('google_accounts', $row, 'id = ?', [$old['id']]);
        } else {
            $db->insert('google_accounts', $row + ['user_id' => Auth::id(), 'created_at' => now()]);
        }
        $missing = array_diff(['https://www.googleapis.com/auth/calendar.events', 'https://www.googleapis.com/auth/tasks'], $granted);
        Activity::log('google_connected', 'user', Auth::id(), 'Conectó su cuenta de Google' . ($row['google_email'] ? ' ' . $row['google_email'] : ''));
        return $missing ? 'Google conectado, pero no diste todos los permisos: ' . (in_array('https://www.googleapis.com/auth/tasks', $missing, true) ? 'Google Tasks no se sincronizará. ' : '') . (in_array('https://www.googleapis.com/auth/calendar.events', $missing, true) ? 'Google Calendar no se sincronizará.' : '') : 'Google conectado' . ($row['google_email'] ? ' (' . $row['google_email'] . ')' : '') . '. Elige qué calendarios mostrar.';
    }

    public static function hasScope(string $scope): bool
    {
        $a = self::account();
        return $a && $a['status'] === 'active' && in_array($scope, explode(' ', (string)$a['scopes']), true);
    }

    /** Disconnect: revokes the token at Google (best effort), deletes tokens; Borealis copies stay unlinked. Nothing is deleted in Google. */
    public static function disconnect(): void
    {
        $db = Database::get();
        $a = $db->one('SELECT * FROM google_accounts WHERE user_id = ?', [Auth::id()]);
        if (!$a) return;
        $token = Secrets::decrypt($a['refresh_token']) ?: Secrets::decrypt($a['access_token']);
        if ($token) { try { self::post(self::ep('revoke_url'), ['token' => $token]); } catch (\Throwable) { /* already revoked or Google unreachable: tokens are deleted anyway */ } }
        $db->transaction(function (Database $db) use ($a) {
            self::unlinkData((int)$a['id']);
            $db->run('DELETE FROM google_accounts WHERE id = ?', [$a['id']]);
        });
        Activity::log('google_disconnected', 'user', Auth::id(), 'Desconectó su cuenta de Google');
    }

    /**
     * Unlinks what came from an account: Google calendars disappear from Borealis (they are only mirrors;
     * the events stay in Google), Google task lists become normal personal lists (the tasks stay in both places).
     */
    private static function unlinkData(int $accountId): void
    {
        $db = Database::get();
        $db->run("UPDATE calendars SET deleted_at = ?, updated_at = ? WHERE google_account_id = ? AND source = 'google' AND deleted_at IS NULL", [now(), now(), $accountId]);
        $lists = array_map('intval', $db->column('SELECT id FROM personal_lists WHERE google_account_id = ?', [$accountId]));
        if ($lists) {
            $db->run('UPDATE personal_tasks SET google_task_id = NULL, google_etag = NULL, google_updated = NULL, pushed_at = NULL WHERE list_id IN (' . Database::in($lists) . ')', $lists);
            $db->run('UPDATE personal_lists SET google_account_id = NULL, google_list_id = NULL, google_etag = NULL, synced_at = NULL WHERE id IN (' . Database::in($lists) . ')', $lists);
        }
    }

    // ---- REST ----------------------------------------------------------------------

    /** A valid access token for the person (refreshing it if needed). Marks the account on failure. */
    public static function token(?int $uid = null): string
    {
        $db = Database::get();
        $a = $db->one('SELECT * FROM google_accounts WHERE user_id = ?', [$uid ?? Auth::id()]);
        if (!$a) throw new GoogleError('No tienes una cuenta de Google conectada.');
        if ($a['status'] === 'revoked') throw new GoogleError('Google retiró el permiso de Borealis. Vuelve a conectar tu cuenta desde tu perfil.');
        $access = Secrets::decrypt($a['access_token']);
        if ($access && $a['expires_at'] && strtotime($a['expires_at'] . ' UTC') > time()) return $access;
        $refresh = Secrets::decrypt($a['refresh_token']);
        if (!$refresh) { self::fail((int)$a['id'], 'revoked', 'La conexión caducó. Vuelve a conectar tu cuenta de Google.'); throw new GoogleError('La conexión con Google caducó. Vuelve a conectarla desde tu perfil.'); }
        try {
            $tok = self::post(self::ep('token_url'), ['client_id' => config('google.client_id'), 'client_secret' => config('google.client_secret'), 'refresh_token' => $refresh, 'grant_type' => 'refresh_token']);
        } catch (GoogleError $e) {
            if ($e->reason === 'invalid_grant') { self::fail((int)$a['id'], 'revoked', 'Google retiró el permiso (contraseña cambiada, acceso revocado o caducado). Vuelve a conectar tu cuenta.'); throw new GoogleError('Google retiró el permiso de Borealis. Vuelve a conectar tu cuenta desde tu perfil.'); }
            throw $e;
        }
        $db->update('google_accounts', ['access_token' => Secrets::encrypt((string)$tok['access_token']), 'expires_at' => gmdate('Y-m-d H:i:s', time() + (int)($tok['expires_in'] ?? 3600) - 60), 'updated_at' => now()]
            + (!empty($tok['refresh_token']) ? ['refresh_token' => Secrets::encrypt((string)$tok['refresh_token'])] : []), 'id = ?', [$a['id']]);
        return (string)$tok['access_token'];
    }

    public static function fail(int $accountId, string $status, string $msg): void
    {
        Database::get()->update('google_accounts', ['status' => $status, 'last_error' => mb_substr($msg, 0, 500), 'updated_at' => now()], 'id = ?', [$accountId]);
    }

    /** Authenticated call to the Google APIs. $path is relative to api_base ('/calendar/v3/...'). */
    public static function api(string $method, string $path, ?array $body = null, array $headers = [], ?int $uid = null): array
    {
        $uid ??= Auth::id();
        $tok = self::token($uid);
        try {
            return self::http($method, self::ep('api_base') . $path, $body, $tok, $headers);
        } catch (GoogleError $e) {
            if ($e->googleStatus === 401) { // token rejected before its expiry: refresh once
                Database::get()->run('UPDATE google_accounts SET expires_at = NULL WHERE user_id = ?', [$uid]);
                return self::http($method, self::ep('api_base') . $path, $body, self::token($uid), $headers);
            }
            throw $e;
        }
    }

    private static function post(string $url, array $form): array
    {
        return self::http('POST', $url, $form, null, [], true);
    }

    private static function http(string $method, string $url, ?array $body, ?string $token, array $headers = [], bool $form = false): array
    {
        $ch = curl_init($url);
        $h = ['Accept: application/json'];
        if ($token) $h[] = 'Authorization: Bearer ' . $token;
        foreach ($headers as $k => $v) $h[] = "$k: $v";
        $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false];
        if ($body !== null) {
            if ($form) { $opts[CURLOPT_POSTFIELDS] = http_build_query($body); $h[] = 'Content-Type: application/x-www-form-urlencoded'; }
            else { $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE); $h[] = 'Content-Type: application/json'; }
        }
        $opts[CURLOPT_HTTPHEADER] = $h;
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new GoogleError('No se pudo contactar con Google (' . $err . '). Tu calendario de Borealis sigue funcionando; inténtalo más tarde.', 0, 'network');
        $data = $raw === '' ? [] : json_decode((string)$raw, true);
        if (!is_array($data)) $data = [];
        if ($status >= 400) {
            $reason = is_string($data['error'] ?? null) ? $data['error'] : (string)($data['error']['errors'][0]['reason'] ?? $data['error']['status'] ?? '');
            $msg = is_array($data['error'] ?? null) ? (string)($data['error']['message'] ?? '') : (string)($data['error_description'] ?? $reason);
            throw new GoogleError(match (true) {
                $status === 403 && str_contains($reason, 'insufficient') => 'Google no dio permiso para esto. Vuelve a conectar tu cuenta y acepta todos los permisos.',
                $status === 403 && str_contains(strtolower($reason), 'ratelimit') => 'Google pidió esperar un poco (límite de uso). Inténtalo en unos minutos.',
                $status === 429 => 'Google pidió esperar un poco (límite de uso). Inténtalo en unos minutos.',
                $status === 404 => 'Ese elemento ya no existe en Google.',
                $status === 409 => 'Ese elemento ya existe en Google.',
                $status === 410 => 'La sincronización con Google se reinició.',
                $status === 412 => 'Alguien cambió esto en Google mientras tanto.',
                $status >= 500 => 'Google no responde ahora mismo. Tu calendario de Borealis sigue funcionando.',
                default => 'Google respondió con un error' . ($msg ? ': ' . mb_substr($msg, 0, 160) : '') . '.',
            }, $status, $reason);
        }
        return $data;
    }
}
