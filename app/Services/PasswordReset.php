<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Validator;
use Borealis\Security\Secrets;

/**
 * 1.16.4 · «¿Olvidaste tu contraseña?» by e-mail.
 *
 * No table: the link carries the account id, an expiry time and a signature made with the
 * installation key over both plus a fingerprint of the current password. Once the password
 * changes (with this link or any other way), every link sent before stops working. Valid 60 minutes.
 * The answer is always the same, whether the address exists or not. Limits: 3 links per address
 * and 10 per IP per hour (rows in login_attempts with success = 2, never counted as failed sign-ins).
 */
final class PasswordReset
{
    public const MINUTES = 60;

    public static function available(): bool
    {
        return Mailer::configured();
    }

    public static function request(string $email, string $ip): void
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw HttpException::invalid(['email' => 'Escribe un correo válido.']);
        $db = Database::get();
        $since = gmdate('Y-m-d H:i:s', time() - 3600);
        $byEmail = (int)$db->value('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND success = 2 AND created_at > ?', ['reset:' . $email, $since]);
        $byIp = (int)$db->value("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 2 AND created_at > ?", [$ip, $since]);
        if ($byEmail >= 3 || $byIp >= 10) throw new HttpException(429, 'Ya se enviaron varios enlaces. Revisa tu correo (también la carpeta de spam) o espera una hora.');
        $db->insert('login_attempts', ['email' => mb_substr('reset:' . $email, 0, 190), 'ip' => $ip, 'success' => 2, 'created_at' => now()]);

        $u = $db->one('SELECT * FROM users WHERE email = ? AND is_active = 1', [$email]);
        if (!$u || (int)($u['is_ghost'] ?? 0) === 1) return; // same answer for unknown addresses
        $link = absolute_url('/reset', ['t' => self::token($u)]);
        $name = (string)$u['name'];
        $text = "Hola, $name:\n\nAlguien (seguramente tú) pidió cambiar la contraseña de tu cuenta de Borealis.\n"
            . "Para elegir una nueva, abre este enlace en los próximos " . self::MINUTES . " minutos:\n\n$link\n\n"
            . "Si no lo pediste, ignora este correo: tu contraseña no cambia.\n";
        $html = '<p>Hola, ' . e($name) . ':</p><p>Alguien (seguramente tú) pidió cambiar la contraseña de tu cuenta de Borealis.</p>'
            . '<p><a href="' . e($link) . '" style="display:inline-block;padding:10px 16px;background:#0F766E;color:#fff;border-radius:6px;text-decoration:none">Elegir una contraseña nueva</a></p>'
            . '<p style="color:#64748B;font-size:13px">El enlace vale ' . self::MINUTES . ' minutos. Si no lo pediste, ignora este correo: tu contraseña no cambia.</p>';
        try {
            Mailer::send($email, 'Cambiar tu contraseña de Borealis', $text, $html);
            Activity::security('password_reset_requested', (int)$u['id'], 'Pidió un enlace para cambiar la contraseña', $ip);
        } catch (\Throwable $e) {
            error_log('[Borealis] correo de contraseña: ' . $e->getMessage());
        }
    }

    public static function token(array $u, ?int $expires = null): string
    {
        $expires ??= time() + self::MINUTES * 60;
        $payload = (int)$u['id'] . '.' . $expires;
        return $payload . '.' . substr(Secrets::hmac('reset|' . $payload . '|' . Auth::passwordVersion($u)), 0, 40);
    }

    /** The account the link belongs to, or null when it is wrong, expired or already used. */
    public static function verify(string $token): ?array
    {
        if (!preg_match('/^(\d{1,10})\.(\d{10})\.([a-f0-9]{40})$/', $token, $m)) return null;
        if ((int)$m[2] < time()) return null;
        $u = Database::get()->one('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int)$m[1]]);
        if (!$u || (int)($u['is_ghost'] ?? 0) === 1) return null;
        return hash_equals(self::token($u, (int)$m[2]), $token) ? $u : null;
    }

    public static function reset(string $token, string $password, string $ip): array
    {
        $u = self::verify($token) ?? throw HttpException::invalid(['_' => 'El enlace no es válido o ya venció. Pide otro.'], 'El enlace no es válido o ya venció. Pide otro.');
        Validator::check(['password' => $password], ['password' => 'required,string,min:8,max:200']);
        Database::get()->update('users', ['password_hash' => Auth::hash($password), 'updated_at' => now()], 'id = ?', [$u['id']]);
        Activity::security('password_reset', (int)$u['id'], 'Cambió su contraseña con el enlace enviado por correo', $ip);
        return $u;
    }
}
