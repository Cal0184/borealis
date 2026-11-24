<?php
declare(strict_types=1);

namespace Borealis\Core;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || str_starts_with((string)Config::get('app.url', ''), 'https://');
        session_name((string)Config::get('session.name', 'borealis_session'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => rtrim((string)Config::get('app.base_path', ''), '/') . '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();
        $idle = (int)Config::get('session.idle_minutes', 240) * 60;
        if (isset($_SESSION['_last']) && time() - (int)$_SESSION['_last'] > $idle) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['_last'] = time();
    }

    public static function get(string $k, mixed $d = null): mixed { return $_SESSION[$k] ?? $d; }
    public static function set(string $k, mixed $v): void { $_SESSION[$k] = $v; }
    public static function forget(string $k): void { unset($_SESSION[$k]); }

    public static function flash(string $k, mixed $v = null): mixed
    {
        if ($v !== null) { $_SESSION['_flash'][$k] = $v; return null; }
        $val = $_SESSION['_flash'][$k] ?? null;
        unset($_SESSION['_flash'][$k]);
        return $val;
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
            session_destroy();
        }
    }
}
