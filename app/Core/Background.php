<?php
declare(strict_types=1);

namespace Borealis\Core;

/**
 * 1.16 · Work after the answer was sent, on any kind of shared hosting.
 *
 * Up to 1.15 the notification engine and the «after a visit» fallback only worked with PHP-FPM
 * (fastcgi_finish_request). Most hostings managed only by FTP run PHP with LiteSpeed (LSAPI) or
 * Apache, where that function does not exist, so on those servers nothing ran with Borealis
 * closed and reminders appeared only when somebody came back. Now:
 *
 *   fpm        PHP-FPM (Nginx, Apache + FPM, HestiaCP, cPanel with FPM) · fastcgi_finish_request()
 *   litespeed  LiteSpeed / OpenLiteSpeed (LSAPI)                         · litespeed_finish_request()
 *   compat     anything else (Apache mod_php, CGI): the answer goes out with Content-Length and
 *              «Connection: close», output buffers are flushed and PHP keeps working with
 *              ignore_user_abort(). The browser has the whole page; on a server that buffers the
 *              output (compression) the work still happens, the page only finishes loading later.
 *   cli        command line (bin/notify.php, tests): nothing to detach.
 */
final class Background
{
    private static bool $detached = false;

    public static function mode(): string
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') return 'cli';
        if (function_exists('fastcgi_finish_request')) return 'fpm';
        if (function_exists('litespeed_finish_request')) return 'litespeed';
        return 'compat';
    }

    /** True when the answer can be closed for certain before working (PHP-FPM or LiteSpeed). */
    public static function native(): bool
    {
        return in_array(self::mode(), ['fpm', 'litespeed'], true);
    }

    /** Human name for Administración. */
    public static function label(): string
    {
        return match (self::mode()) {
            'fpm' => 'PHP-FPM',
            'litespeed' => 'LiteSpeed',
            'compat' => 'Apache u otro (' . PHP_SAPI . ', modo compatible)',
            default => 'línea de comandos',
        };
    }

    /** Headers that let the browser finish with the page in «compat» mode (called before the body). */
    public static function compatHeaders(int $length): void
    {
        if (self::mode() !== 'compat' || headers_sent()) return;
        if (!ini_get('zlib.output_compression')) header('Content-Length: ' . $length);
        header('Connection: close');
    }

    /** Ends the answer to the caller and keeps PHP running. Safe to call more than once. */
    public static function detach(): void
    {
        if (self::$detached || PHP_SAPI === 'cli') return;
        self::$detached = true;
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        ignore_user_abort(true);
        @set_time_limit(0);
        switch (self::mode()) {
            case 'fpm': @fastcgi_finish_request(); break;
            case 'litespeed': @litespeed_finish_request(); break;
            default:
                while (ob_get_level() > 0) @ob_end_flush();
                @flush();
        }
    }
}
