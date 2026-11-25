<?php
declare(strict_types=1);

namespace Borealis\Services;

/**
 * E-mail (1.9), without external libraries.
 *
 * Transports (config/config.php › 'mail', or since 1.16.2 Administración › Datos › Correo: MailSettings):
 *  - 'smtp': authenticated SMTP with STARTTLS (port 587) or implicit TLS (port 465). The certificate
 *    is verified. The password can also come from the BOREALIS_SMTP_PASSWORD environment variable.
 *  - 'sendmail': PHP mail(), i.e. the server's own mail system (HestiaCP: Exim). Only if the
 *    administration prefers it; SMTP with the domain's mailbox is more reliable (SPF/DKIM).
 *  - none (default): Borealis does not send e-mail and says so in the profile.
 * Messages are plain text plus a simple HTML version. Nothing secret is ever written to the logs.
 */
final class Mailer
{
    /** For tests: the last SMTP conversation (without the password). */
    public static array $trace = [];

    public static function transport(): string
    {
        $t = (string)MailSettings::get('transport', '');
        return in_array($t, ['smtp', 'sendmail'], true) ? $t : 'none';
    }

    public static function configured(): bool
    {
        return match (self::transport()) {
            'smtp' => trim((string)MailSettings::get('host', '')) !== '' && filter_var(self::from(), FILTER_VALIDATE_EMAIL) !== false,
            'sendmail' => function_exists('mail') && filter_var(self::from(), FILTER_VALIDATE_EMAIL) !== false,
            default => false,
        };
    }

    /** Public description for the administration page (never the password). */
    public static function describe(): array
    {
        return ['transport' => self::transport(), 'configured' => self::configured(), 'host' => (string)MailSettings::get('host', ''), 'port' => (int)MailSettings::get('port', 587),
            'encryption' => self::encryption(), 'username' => (string)MailSettings::get('username', ''), 'from' => self::from(), 'from_name' => self::fromName()];
    }

    private static function from(): string
    {
        return trim((string)MailSettings::get('from_address', ''));
    }

    private static function fromName(): string
    {
        return trim((string)MailSettings::get('from_name', 'Borealis')) ?: 'Borealis';
    }

    private static function encryption(): string
    {
        $e = strtolower((string)MailSettings::get('encryption', ''));
        if (in_array($e, ['tls', 'ssl', 'none'], true)) return $e;
        return (int)MailSettings::get('port', 587) === 465 ? 'ssl' : 'tls';
    }

    /** Sends one message. Throws \RuntimeException with a reason the administration can act on. */
    public static function send(string $to, string $subject, string $text, ?string $html = null): void
    {
        if (!self::configured()) throw new \RuntimeException('El correo no está configurado en esta instalación.');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to)) throw new \RuntimeException('Dirección de correo no válida.');
        $msg = self::build($to, $subject, $text, $html);
        // 1.16.2: the last success and the last error are kept for Administración
        try {
            if (self::transport() === 'sendmail') {
                [$headers, $body] = explode("\r\n\r\n", $msg, 2);
                $headers = preg_replace('/^(To|Subject): .*\r\n/m', '', $headers . "\r\n");
                if (!@mail($to, self::encodeHeader($subject), $body, rtrim($headers), '-f' . self::from())) throw new \RuntimeException('El sistema de correo del servidor no aceptó el mensaje.');
            } else {
                self::smtp($to, $msg);
            }
        } catch (\RuntimeException $e) {
            MailSettings::record($e->getMessage());
            throw $e;
        }
        MailSettings::record(null);
    }

    private static function encodeHeader(string $s): string
    {
        $s = str_replace(["\r", "\n"], ' ', $s);
        return preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }

    private static function build(string $to, string $subject, string $text, ?string $html): string
    {
        $host = parse_url((string)config('app.url', ''), PHP_URL_HOST) ?: 'borealis.local';
        $h = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'From: ' . self::encodeHeader(self::fromName()) . ' <' . self::from() . '>',
            'To: <' . $to . '>',
            'Subject: ' . self::encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $host . '>',
            'MIME-Version: 1.0',
            'Auto-Submitted: auto-generated',
            'X-Auto-Response-Suppress: All',
        ];
        $txt = chunk_split(base64_encode($text), 76, "\r\n");
        if ($html === null) {
            $h[] = 'Content-Type: text/plain; charset=UTF-8';
            $h[] = 'Content-Transfer-Encoding: base64';
            return implode("\r\n", $h) . "\r\n\r\n" . $txt;
        }
        $b = 'b' . bin2hex(random_bytes(10));
        $h[] = 'Content-Type: multipart/alternative; boundary="' . $b . '"';
        return implode("\r\n", $h) . "\r\n\r\n"
            . "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n$txt"
            . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html), 76, "\r\n")
            . "--$b--\r\n";
    }

    // ------------------------------------------------------------------ SMTP

    private static function smtp(string $to, string $msg): void
    {
        self::$trace = [];
        $host = (string)MailSettings::get('host');
        $port = (int)MailSettings::get('port', 587);
        $enc = self::encryption();
        $timeout = (int)MailSettings::get('timeout', 15);
        $ctx = stream_context_create(['ssl' => array_filter([
            'verify_peer' => (bool)MailSettings::get('verify_peer', true), 'verify_peer_name' => (bool)MailSettings::get('verify_peer', true),
            'cafile' => MailSettings::get('cafile') ?: null, 'peer_name' => $host, 'SNI_enabled' => true,
        ], fn($v) => $v !== null)]);
        $fp = @stream_socket_client(($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) throw new \RuntimeException("No se pudo conectar con el servidor de correo $host:$port ($errstr).");
        stream_set_timeout($fp, $timeout);
        try {
            self::expect($fp, [220]);
            $ehlo = parse_url((string)config('app.url', ''), PHP_URL_HOST) ?: 'localhost';
            $caps = self::cmd($fp, "EHLO $ehlo", [250]);
            if ($enc === 'tls') {
                if (!preg_match('/^250[ -]STARTTLS/mi', $caps)) throw new \RuntimeException('El servidor de correo no ofrece STARTTLS; no se envía la contraseña sin cifrar.');
                self::cmd($fp, 'STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new \RuntimeException('No se pudo establecer la conexión cifrada (TLS) con el servidor de correo: revisa el certificado o el puerto.');
                }
                $caps = self::cmd($fp, "EHLO $ehlo", [250]);
            }
            $user = (string)MailSettings::get('username', '');
            $pass = (string)(getenv('BOREALIS_SMTP_PASSWORD') ?: MailSettings::get('password', ''));
            if ($user !== '') {
                if ($enc === 'none' && !self::localHost($host)) throw new \RuntimeException('Sin cifrado solo se permite un servidor de correo local; usa el puerto 587 (STARTTLS) o 465 (SSL).');
                if (preg_match('/^250[ -]AUTH[ =].*PLAIN/mi', $caps)) {
                    self::cmd($fp, 'AUTH PLAIN ' . base64_encode("\0$user\0$pass"), [235], 'AUTH PLAIN ***');
                } else {
                    self::cmd($fp, 'AUTH LOGIN', [334]);
                    self::cmd($fp, base64_encode($user), [334], '***');
                    self::cmd($fp, base64_encode($pass), [235], '***');
                }
            }
            self::cmd($fp, 'MAIL FROM:<' . self::from() . '>', [250]);
            self::cmd($fp, "RCPT TO:<$to>", [250, 251]);
            self::cmd($fp, 'DATA', [354]);
            $data = preg_replace('/^\./m', '..', str_replace("\r\n", "\n", $msg));
            self::cmd($fp, str_replace("\n", "\r\n", rtrim($data, "\n")) . "\r\n.", [250], '[mensaje]');
            @fwrite($fp, "QUIT\r\n");
        } finally {
            @fclose($fp);
        }
    }

    private static function localHost(string $h): bool
    {
        return in_array($h, ['localhost', '127.0.0.1', '::1'], true);
    }

    private static function cmd($fp, string $line, array $ok, ?string $shown = null): string
    {
        self::$trace[] = 'C: ' . ($shown ?? $line);
        if (@fwrite($fp, $line . "\r\n") === false) throw new \RuntimeException('Se cortó la conexión con el servidor de correo.');
        return self::expect($fp, $ok);
    }

    private static function expect($fp, array $ok): string
    {
        $all = '';
        while (($l = fgets($fp, 2048)) !== false) {
            $all .= $l;
            if (strlen($l) < 4 || $l[3] !== '-') break;
        }
        self::$trace[] = 'S: ' . trim($all);
        $code = (int)substr($all, 0, 3);
        if (!in_array($code, $ok, true)) {
            if ($all === '') throw new \RuntimeException('El servidor de correo no respondió a tiempo.');
            $why = trim(substr(preg_replace('/\s+/', ' ', $all), 0, 160));
            throw new \RuntimeException(match (true) {
                $code === 535 || $code === 534 => 'El servidor de correo rechazó el usuario o la contraseña.',
                $code >= 500 => "El servidor de correo rechazó el envío: $why",
                default => "Respuesta inesperada del servidor de correo: $why",
            });
        }
        return $all;
    }
}
