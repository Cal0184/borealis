<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\HttpException;
use Borealis\Security\Secrets;

/**
 * 1.16.2 · E-mail settings saved from Administración, for installations managed only by FTP.
 *
 * Before 1.16.2 e-mail could only be turned on by editing config/config.php (section «mail»), which
 * on a hosting without SSH means downloading, editing and uploading that file by FTP. Now the
 * installation's administration fills a form; the values go to storage/mail.json with the password
 * encrypted with the installation key (storage/app.key). A «mail» section in config/config.php still
 * wins, so existing installations change nothing.
 * storage/mail-status.json keeps the last success and the last error, shown in Administración.
 */
final class MailSettings
{
    /** Ready-made values for the usual providers (host, port, encryption). */
    public const PRESETS = [
        'hosting' => ['Buzón de tu dominio (cPanel, Hostinger, etc.)', '', 465, 'ssl'],
        'gmail' => ['Gmail / Google Workspace', 'smtp.gmail.com', 587, 'tls'],
        'outlook' => ['Outlook / Microsoft 365', 'smtp.office365.com', 587, 'tls'],
        'zoho' => ['Zoho Mail', 'smtp.zoho.com', 465, 'ssl'],
        'brevo' => ['Brevo (servicio gratuito de envío)', 'smtp-relay.brevo.com', 587, 'tls'],
    ];

    private static ?array $saved = null;

    private static function file(): string
    {
        return storage_path('mail.json');
    }

    private static function statusFile(): string
    {
        return storage_path('mail-status.json');
    }

    /** 'config' (config/config.php), 'admin' (saved here) or 'none'. */
    public static function source(): string
    {
        if (trim((string)config('mail.transport', '')) !== '') return 'config';
        return self::saved() ? 'admin' : 'none';
    }

    private static function saved(): array
    {
        if (self::$saved === null) {
            $j = is_file(self::file()) ? json_decode((string)@file_get_contents(self::file()), true) : null;
            self::$saved = is_array($j) && in_array($j['transport'] ?? '', ['smtp', 'sendmail'], true) ? $j : [];
        }
        return self::$saved;
    }

    /** One mail setting: config/config.php first, then the values saved in Administración. */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::source() === 'config') return config('mail.' . $key, $default);
        $s = self::saved();
        if ($key === 'password') return isset($s['password']) ? (Secrets::decrypt((string)$s['password']) ?? '') : $default;
        return array_key_exists($key, $s) && $s[$key] !== '' && $s[$key] !== null ? $s[$key] : $default;
    }

    /** Saves the form of Administración › Datos › Correo. An empty password keeps the saved one. */
    public static function save(array $in): void
    {
        if (self::source() === 'config') throw HttpException::conflict('El correo está definido en config/config.php: cámbialo allí o quita esa sección para configurarlo desde aquí.');
        $t = (string)($in['transport'] ?? 'smtp');
        if ($t === 'none') { @unlink(self::file()); self::$saved = null; return; }
        if (!in_array($t, ['smtp', 'sendmail'], true)) throw HttpException::invalid(['transport' => 'Elige cómo enviar.']);
        $from = trim((string)($in['from_address'] ?? ''));
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) throw HttpException::invalid(['from_address' => 'Escribe el correo remitente (por ejemplo, avisos@tu-dominio.cl).']);
        $data = ['transport' => $t, 'from_address' => $from, 'from_name' => mb_substr(trim((string)($in['from_name'] ?? '')) ?: 'Borealis', 0, 80), 'saved' => gmdate('c')];
        if ($t === 'smtp') {
            $host = trim((string)($in['host'] ?? ''));
            if ($host === '' || !preg_match('/^[a-z0-9.-]+$/i', $host)) throw HttpException::invalid(['host' => 'Escribe el servidor SMTP (por ejemplo, mail.tu-dominio.cl).']);
            $port = (int)($in['port'] ?? 0);
            if (!in_array($port, [25, 465, 587, 2525], true)) throw HttpException::invalid(['port' => 'Usa el puerto 465 (SSL) o 587 (STARTTLS).']);
            $enc = (string)($in['encryption'] ?? ($port === 465 ? 'ssl' : 'tls'));
            if (!in_array($enc, ['ssl', 'tls'], true)) $enc = $port === 465 ? 'ssl' : 'tls';
            $user = trim((string)($in['username'] ?? ''));
            $pass = (string)($in['password'] ?? '');
            $old = self::saved();
            $data += ['host' => $host, 'port' => $port, 'encryption' => $enc, 'username' => mb_substr($user, 0, 190),
                'password' => $pass !== '' ? Secrets::encrypt($pass) : ($old['password'] ?? null)];
        }
        $f = self::file();
        $tmp = $f . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE)) === false || !@rename($tmp, $f)) {
            throw HttpException::conflict('No se pudo guardar storage/mail.json: revisa los permisos de la carpeta storage/ en el FTP.');
        }
        @chmod($f, 0600);
        self::$saved = null;
    }

    /** What the form shows (never the password). */
    public static function form(): array
    {
        $s = self::source() === 'admin' ? self::saved() : [];
        return ['transport' => $s['transport'] ?? 'smtp', 'host' => $s['host'] ?? '', 'port' => (int)($s['port'] ?? 465), 'encryption' => $s['encryption'] ?? 'ssl',
            'username' => $s['username'] ?? '', 'has_password' => !empty($s['password']), 'from_address' => $s['from_address'] ?? '', 'from_name' => $s['from_name'] ?? 'Borealis'];
    }

    // ------------------------------------------------------------------ last result

    public static function record(?string $error): void
    {
        $st = self::status();
        if ($error === null) $st['ok'] = gmdate('Y-m-d H:i:s'); else { $st['error'] = mb_substr($error, 0, 300); $st['error_at'] = gmdate('Y-m-d H:i:s'); }
        @file_put_contents(self::statusFile(), json_encode($st, JSON_UNESCAPED_UNICODE));
    }

    /** ['ok' => ?datetime, 'error' => ?string, 'error_at' => ?datetime] (UTC). */
    public static function status(): array
    {
        $j = is_file(self::statusFile()) ? json_decode((string)@file_get_contents(self::statusFile()), true) : null;
        return (is_array($j) ? $j : []) + ['ok' => null, 'error' => null, 'error_at' => null];
    }
}
