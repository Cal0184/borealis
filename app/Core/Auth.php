<?php
declare(strict_types=1);

namespace Borealis\Core;

final class Auth
{
    private static ?array $user = null;
    private static bool $resolved = false;

    public static function user(): ?array
    {
        if (!self::$resolved) {
            self::$resolved = true;
            $id = Session::get('uid');
            if (is_int($id)) {
                $u = Database::get()->one('SELECT * FROM users WHERE id = ? AND is_active = 1', [$id]);
                if ($u && hash_equals((string)Session::get('pwv'), self::passwordVersion($u))) {
                    $u['prefs'] = json_decode((string)$u['prefs'], true) ?: [];
                    self::$user = $u;
                } else {
                    Session::forget('uid');
                }
            }
        }
        return self::$user;
    }

    public static function id(): int
    {
        return (int)(self::user()['id'] ?? 0);
    }

    public static function requireUser(): array
    {
        $u = self::user();
        if (!$u) throw HttpException::unauthorized();
        return $u;
    }

    /** Fingerprint of the password hash: changing the password invalidates other sessions. */
    public static function passwordVersion(array $u): string
    {
        return substr(hash('sha256', (string)$u['password_hash']), 0, 16);
    }

    public static function login(array $u): void
    {
        Session::regenerate();
        Session::set('uid', (int)$u['id']);
        Session::set('pwv', self::passwordVersion($u));
        self::$resolved = false;
        self::$user = null;
    }

    public static function logout(): void
    {
        Session::destroy();
        self::$user = null;
        self::$resolved = true;
    }

    /** Command line only (bin/notify.php): acts as one person to prepare their reminders. */
    public static function actAs(array $u): void
    {
        if (PHP_SAPI !== 'cli') throw new \LogicException('Auth::actAs solo desde la consola.');
        $u['prefs'] = is_array($u['prefs'] ?? null) ? $u['prefs'] : (json_decode((string)($u['prefs'] ?? ''), true) ?: []);
        self::$user = $u;
        self::$resolved = true;
    }

    public static function reset(): void
    {
        self::$user = null;
        self::$resolved = false;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
    }
}
