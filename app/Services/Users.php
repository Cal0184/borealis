<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Validator;

final class Users
{
    public const COLORS = ['#10B981', '#0EA5E9', '#F59E0B', '#8B5CF6', '#EC4899', '#14B8A6', '#6366F1', '#84CC16', '#F97316'];

    public static function create(string $name, string $email, string $password): int
    {
        $v = Validator::check(compact('name', 'email', 'password'), ['name' => 'required,string,max:120', 'email' => 'required,email,max:190', 'password' => 'required,string,min:8,max:200']);
        $db = Database::get();
        $email = mb_strtolower($v['email']);
        if ($db->value('SELECT 1 FROM users WHERE email = ?', [$email])) {
            throw HttpException::invalid(['email' => 'Ya existe una cuenta con este correo.']);
        }
        return $db->insert('users', [
            'email' => $email,
            'name' => $v['name'],
            'handle' => self::uniqueHandle($v['name'], $email),
            'password_hash' => Auth::hash($v['password']),
            'avatar_color' => self::COLORS[random_int(0, count(self::COLORS) - 1)],
            'prefs' => json_encode(['motion' => 'full']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function uniqueHandle(string $name, string $email): string
    {
        $base = strtolower((string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name));
        $base = trim((string)preg_replace('/[^a-z0-9]+/', '.', $base), '.');
        if (strlen($base) < 2) $base = preg_replace('/[^a-z0-9]+/', '.', strtolower(strstr($email, '@', true) ?: 'usuario'));
        $base = substr($base, 0, 40);
        $handle = $base;
        $i = 2;
        while (Database::get()->value('SELECT 1 FROM users WHERE handle = ?', [$handle])) {
            $handle = $base . $i++;
        }
        return $handle;
    }

    public static function find(int $id): ?array
    {
        return Database::get()->one('SELECT id, name, email, handle, title, avatar_color, is_active FROM users WHERE id = ?', [$id]);
    }

    public static function updateProfile(array $input): void
    {
        $v = Validator::check($input, ['name' => 'required,string,max:120', 'title' => 'nullable,string,max:120', 'avatar_color' => 'sometimes,color', 'email' => 'required,email,max:190']);
        $db = Database::get();
        $email = mb_strtolower($v['email']);
        if ($db->value('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, Auth::id()])) {
            throw HttpException::invalid(['email' => 'Ese correo ya está en uso.']);
        }
        $data = ['name' => $v['name'], 'title' => $v['title'], 'email' => $email, 'updated_at' => now()];
        if (!empty($v['avatar_color'])) $data['avatar_color'] = $v['avatar_color'];
        $db->update('users', $data, 'id = ?', [Auth::id()]);
    }

    public static function changePassword(string $current, string $new): void
    {
        $u = Database::get()->one('SELECT * FROM users WHERE id = ?', [Auth::id()]);
        if (!$u || !password_verify($current, $u['password_hash'])) {
            throw HttpException::invalid(['current_password' => 'La contraseña actual no es correcta.']);
        }
        Validator::check(['password' => $new], ['password' => 'required,string,min:8,max:200']);
        Database::get()->update('users', ['password_hash' => Auth::hash($new), 'updated_at' => now()], 'id = ?', [Auth::id()]);
        $u['password_hash'] = Database::get()->value('SELECT password_hash FROM users WHERE id = ?', [Auth::id()]);
        Auth::login($u); // keep this session valid, invalidate others
    }

    public static function savePrefs(array $input): array
    {
        $v = Validator::check($input, ['motion' => 'sometimes,in:full|reduced|off', 'density' => 'sometimes,in:comfortable|compact',
            'ambient' => 'sometimes,in:' . implode('|', array_keys(self::AMBIENTS)), 'tour' => 'sometimes,in:done|skipped|again',
            'tour_step' => 'sometimes,nullable,int']);
        // 1.6: the step the multi-page tutorial was on, so it resumes after moving to another page or reloading
        if (array_key_exists('tour_step', $v)) $v['tour_step'] = $v['tour_step'] === null || $v['tour_step'] === '' ? null : max(0, min(99, (int)$v['tour_step']));
        if (($v['tour'] ?? null) === 'again') $v['tour'] = null; // «Ver el tutorial otra vez»: it starts on the next page
        $prefs = array_merge(Auth::user()['prefs'] ?? [], array_filter($v, fn($x) => $x !== null));
        if (array_key_exists('tour', $v) && $v['tour'] === null) unset($prefs['tour'], $prefs['tour_step']);
        if (array_key_exists('tour_step', $v) && $v['tour_step'] === null) unset($prefs['tour_step']);
        if (in_array($v['tour'] ?? null, ['done', 'skipped'], true)) unset($prefs['tour_step']);
        Database::get()->update('users', ['prefs' => json_encode($prefs), 'updated_at' => now()], 'id = ?', [Auth::id()]);
        return $prefs;
    }

    /** Generic placeholders that are not a real name (e.g. an account created as «Usuario»). */
    private const GENERIC_NAMES = ['usuario', 'usuaria', 'user', 'admin', 'administrador', 'administradora', 'administrator', 'cuenta', 'nombre', 'test', 'prueba'];

    /**
     * The name to greet someone with (1.6): the first word of their name, or, when the name is
     * empty or a generic placeholder, their @handle or the part of the e-mail before the @.
     */
    public static function greetingName(array $u): string
    {
        $first = trim((string)strtok(trim((string)($u['name'] ?? '')), " \t"));
        if ($first !== '' && !in_array(mb_strtolower($first), self::GENERIC_NAMES, true)) return $first;
        foreach ([(string)($u['handle'] ?? ''), strstr((string)($u['email'] ?? ''), '@', true) ?: ''] as $alt) {
            $alt = trim($alt);
            if ($alt !== '' && !in_array(mb_strtolower($alt), self::GENERIC_NAMES, true)) return $alt;
        }
        return '';
    }

    /** True when the account still has a placeholder name, so the home page can suggest setting a real one. */
    public static function hasGenericName(array $u): bool
    {
        $first = trim((string)strtok(trim((string)($u['name'] ?? '')), " \t"));
        return $first === '' || in_array(mb_strtolower($first), self::GENERIC_NAMES, true);
    }

    /** Personal backgrounds (Perfil › Apariencia). Only the background changes, never the tools. */
    public const AMBIENTS = [
        'default' => ['Borealis', 'El estilo predeterminado'], 'medieval' => ['Medieval', 'Castillos y piedra'], 'agua' => ['Agua', 'Océanos y lagos'],
        'fuego' => ['Fuego', 'Brasas y volcanes'], 'tierra' => ['Tierra', 'Montañas y desiertos'], 'cielo' => ['Cielo', 'Nubes y horizontes'], 'bosque' => ['Bosques', 'Árboles y naturaleza'],
    ];

    /** Failed attempts allowed per e-mail + IP before waiting; then the wait doubles each time (max 15 min). */
    public const PAIR_FREE_FAILURES = 5;
    public const PAIR_LOCK_FAILURES = 8;
    /** Failures per IP (any account) in 15 minutes before that IP waits 15 minutes. */
    public const IP_LOCK_FAILURES = 30;
    /** Failures on one account from many IPs in 15 minutes before unknown IPs must wait a little. */
    public const ACCOUNT_SLOW_FAILURES = 50;

    /**
     * 1.4.1 throttling. Before, 8 failures on an e-mail from ANY IP locked that account for 15 minutes,
     * so anyone could lock someone else out. Now:
     * - per e-mail + IP: 5 free failures, then a wait that doubles (30 s, 1, 2, 4… min) and, from the
     *   8th failure, 15 minutes. A successful sign-in from that IP starts the count again.
     * - per IP: 30 failures in 15 minutes on any accounts → that IP waits 15 minutes.
     * - per account from many IPs (distributed guessing): from 50 failures in 15 minutes, IPs that never
     *   signed in to that account wait 60 s between attempts; IPs that did sign in before are not slowed.
     * Rows older than a day are purged on every successful sign-in and now and then on failures.
     */
    private static function throttle(string $email, string $ip): void
    {
        $db = Database::get();
        $now = time();
        if (random_int(1, 50) === 1) self::purgeAttempts();
        $since = gmdate('Y-m-d H:i:s', $now - 900);
        $deny = fn() => throw new HttpException(429, 'Demasiados intentos fallidos. Espera unos minutos e inténtalo de nuevo.');

        $byIp = (int)$db->value('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at > ?', [$ip, $since]);
        if ($byIp >= self::IP_LOCK_FAILURES) $deny();

        $lastOk = (string)($db->value('SELECT MAX(created_at) FROM login_attempts WHERE email = ? AND ip = ? AND success = 1', [$email, $ip]) ?? '');
        $from = $lastOk !== '' && $lastOk > $since ? $lastOk : $since;
        $pair = $db->one('SELECT COUNT(*) AS n, MAX(created_at) AS last FROM login_attempts WHERE email = ? AND ip = ? AND success = 0 AND created_at > ?', [$email, $ip, $from]);
        $n = (int)($pair['n'] ?? 0);
        if ($n >= self::PAIR_LOCK_FAILURES) $deny();
        if ($n >= self::PAIR_FREE_FAILURES) {
            $wait = min(900, 30 * (2 ** ($n - self::PAIR_FREE_FAILURES)));
            if ($now - (int)strtotime($pair['last'] . ' UTC') < $wait) $deny();
        }

        $byAccount = (int)$db->value('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND success = 0 AND created_at > ?', [$email, $since]);
        if ($byAccount >= self::ACCOUNT_SLOW_FAILURES && $lastOk === '') {
            $last = (string)$db->value('SELECT MAX(created_at) FROM login_attempts WHERE email = ? AND ip = ? AND success = 0', [$email, $ip]);
            if ($last !== '' && $now - (int)strtotime($last . ' UTC') < 60) $deny();
        }
    }

    /** A fixed hash made with the same algorithm as real passwords, so unknown e-mails take as long. */
    public static function purgeAttempts(): void
    {
        Database::get()->run('DELETE FROM login_attempts WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
    }

    private static function dummyHash(): string
    {
        return defined('PASSWORD_ARGON2ID')
            ? '$argon2id$v=19$m=65536,t=4,p=1$alFxWVdDdzRFZ3ZQQ3BwcA$+gSQm90X0XoU60spbWvNlcDXV28AJ7C0Uk6JO0o462E'
            : '$2y$10$cdDA0NJY.2thwGifZ6xB0uWzb6FVCA7nGAK53nmCASZt2f848oere';
    }

    public static function attempt(string $email, string $password, string $ip): array
    {
        $db = Database::get();
        $email = mb_strtolower(trim($email));
        self::throttle($email, $ip);
        $u = $db->one('SELECT * FROM users WHERE email = ?', [$email]);
        $ok = $u && password_verify($password, $u['password_hash']) && (int)$u['is_active'] === 1 && (int)($u['is_ghost'] ?? 0) === 0;
        if (!$u) password_verify($password, self::dummyHash()); // equalize timing with the real algorithm
        $db->insert('login_attempts', ['email' => $email, 'ip' => $ip, 'success' => $ok ? 1 : 0, 'created_at' => now()]);
        if (!$ok) {
            // 1.15: a refused sign-in of an existing account goes to its Master Log (unknown addresses do not)
            if ($u) Activity::security('login_failed', (int)$u['id'], 'Intento de inicio de sesión rechazado', $ip, 'denied');
            throw HttpException::invalid(['email' => 'Correo o contraseña incorrectos.'], 'Correo o contraseña incorrectos.');
        }
        if (password_needs_rehash($u['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT)) {
            $u['password_hash'] = Auth::hash($password);
            $db->update('users', ['password_hash' => $u['password_hash']], 'id = ?', [$u['id']]);
        }
        $db->update('users', ['last_login_at' => now()], 'id = ?', [$u['id']]);
        self::purgeAttempts();
        return $u;
    }
}
