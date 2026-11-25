<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Background;
use Borealis\Core\Database;

/**
 * 1.15 · Internal notification engine: the scheduled job without cron and without visits.
 *
 * Reported problem: on a server without a working cron job, reminders were only created while
 * somebody had Borealis open (the «visit» fallback). This engine keeps the job running by itself:
 *
 *   ensure()  any page (after the answer was sent) checks a small file; if the engine is not
 *             beating, it starts a «chain» by calling its own address POST /cron/tick.
 *   tick()    answers 202 at once, then runs the job, waits one interval (60 s) and calls
 *             /cron/tick again. The chain keeps going with nobody signed in and no tabs open.
 *
 * Only one chain lives at a time (storage/heartbeat.json holds its id; a tick of an old chain
 * stops). When a real cron job runs (php bin/notify.php or /cron/run), the chain stops by
 * itself, because cron is more robust (it survives a server restart without any visit).
 * Limits, documented in CONFIGURACION-1.16: the server must be able to reach its own address;
 * after a restart of the server (or if the hosting stops long requests) it starts again with the
 * next request of any kind: a visit, a page left open, or a device that received a notice
 * (service worker → /cron/ping). It holds one PHP worker sleeping between beats.
 * 1.16: works with PHP-FPM, LiteSpeed and Apache (Core/Background.php), not only PHP-FPM.
 * 'cron.heartbeat' => false turns it off; 'always' allows it without PHP-FPM (tests).
 */
final class Heartbeat
{
    /** Without a beat for this long the chain is considered dead and the next visit starts another. */
    public const ALIVE_SECONDS = 180;
    /** A real cron run this recent means the engine is not needed. */
    public const CRON_COVERS_MINUTES = 10;

    public static function interval(): int
    {
        return max(1, min(300, (int)config('cron.heartbeat_seconds', 60)));
    }

    public static function enabled(): bool
    {
        $mode = config('cron.heartbeat', true);
        if ($mode === false || $mode === 'off') return false;
        if ($mode !== 'always' && !self::serverCan()) return false;
        return Scheduler::ready();
    }

    /**
     * 1.16 · the web server can keep working after answering: PHP-FPM, LiteSpeed, Apache. Not PHP's
     * built-in test server (it serves one request at a time: a sleeping beat would block it).
     */
    public static function serverCan(): bool
    {
        $m = Background::mode();
        return $m === 'fpm' || $m === 'litespeed' || ($m === 'compat' && PHP_SAPI !== 'cli-server');
    }

    private static function file(): string
    {
        return storage_path('heartbeat.json');
    }

    /** ['chain' => ?string, 'beat' => ?int, 'started' => ?int, 'ticks' => int, 'error' => ?string, 'stopped' => ?string, 'attempt' => ?int] */
    public static function state(): array
    {
        $j = is_file(self::file()) ? json_decode((string)@file_get_contents(self::file()), true) : null;
        return (is_array($j) ? $j : []) + ['chain' => null, 'beat' => null, 'started' => null, 'ticks' => 0, 'error' => null, 'stopped' => null, 'attempt' => null];
    }

    private static function save(array $s): void
    {
        $f = self::file();
        $tmp = $f . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, json_encode($s)) !== false) { @chmod($tmp, 0600); @rename($tmp, $f); }
    }

    public static function alive(?array $s = null): bool
    {
        $s ??= self::state();
        return !empty($s['chain']) && empty($s['stopped']) && (int)$s['beat'] >= time() - self::ALIVE_SECONDS;
    }

    /** True when a real cron job (command or address) ran recently. */
    public static function cronCovers(): bool
    {
        try {
            $row = Database::get()->one('SELECT last_started_at, last_source, cli_ok_at FROM system_jobs WHERE name = ?', [Scheduler::JOB]);
        } catch (\Throwable) { return false; }
        if (!$row) return false;
        $recent = fn(?string $at) => $at && strtotime($at . ' UTC') >= time() - self::CRON_COVERS_MINUTES * 60;
        return $recent($row['cli_ok_at']) || (in_array($row['last_source'], ['cli', 'http'], true) && $recent($row['last_started_at']));
    }

    /** Called after every page and every background poll: starts the chain when it is not beating. */
    public static function ensure(): void
    {
        try {
            if (!self::enabled()) return;
            $s = self::state();
            if (self::alive($s)) return;
            if (self::cronCovers()) return;
            if ((int)$s['attempt'] > time() - 60) return; // one start attempt a minute at most
            $chain = bin2hex(random_bytes(8));
            self::save(['chain' => $chain, 'beat' => time(), 'started' => time(), 'ticks' => 0, 'error' => null, 'stopped' => null, 'attempt' => time()]);
            self::fire($chain);
        } catch (\Throwable $e) {
            error_log('[Borealis] motor de avisos: ' . $e->getMessage());
        }
    }

    /** Address of the next beat. 'cron.heartbeat_url' overrides it (a server that reaches itself by another name). */
    public static function tickUrl(): string
    {
        $u = (string)config('cron.heartbeat_url', '');
        return $u !== '' ? $u : absolute_url('/cron/tick');
    }

    /**
     * Calls /cron/tick and waits only for the 202 (the tick answers before working). If the public
     * address cannot be reached from the server itself, tries once more through 127.0.0.1 with the
     * same name (curl --resolve), which keeps the HTTPS certificate valid.
     */
    public static function fire(string $chain, bool $markStopped = true): bool
    {
        $url = self::tickUrl();
        $err = self::post($url, $chain, false);
        if ($err !== null && str_starts_with($err, 'conexión')) {
            $err2 = self::post($url, $chain, true);
            if ($err2 === null) $err = null; else $err .= ' · por 127.0.0.1: ' . $err2;
        }
        if ($err !== null) {
            $s = self::state();
            if (($s['chain'] ?? null) === $chain) { $s['error'] = mb_substr($err, 0, 300); if ($markStopped) $s['stopped'] = 'error'; self::save($s); }
            error_log('[Borealis] motor de avisos: ' . $err);
            return false;
        }
        return true;
    }

    /** Returns null on success (HTTP 202) or the reason. */
    private static function post(string $url, string $chain, bool $loopback): ?string
    {
        if (!function_exists('curl_init')) return 'falta la extensión curl de PHP';
        $ch = curl_init($url);
        $opts = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['X-Borealis-Token: ' . Scheduler::token(), 'X-Borealis-Chain: ' . $chain, 'User-Agent: Borealis-heartbeat'],
        ];
        // 1.16: the status line is enough. A server that holds the rest of the answer (Apache with
        // compression) still accepted the beat when it said 202.
        $code = 0;
        $opts[CURLOPT_HEADERFUNCTION] = function ($h, string $line) use (&$code) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) $code = (int)$m[1];
            if ($code === 202 && trim($line) === '') return 0; // headers complete and accepted: stop waiting
            return strlen($line);
        };
        if ($loopback) {
            $p = parse_url($url);
            $port = $p['port'] ?? (($p['scheme'] ?? 'http') === 'https' ? 443 : 80);
            $opts[CURLOPT_RESOLVE] = [($p['host'] ?? '') . ':' . $port . ':127.0.0.1'];
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = $code ?: (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($code === 202) return null;
        if ($errno) return ($errno === CURLE_COULDNT_CONNECT || $errno === CURLE_COULDNT_RESOLVE_HOST || $errno === CURLE_OPERATION_TIMEDOUT ? 'conexión: ' : '') . $error;
        if ($code === 202) return null;
        return 'respuesta ' . $code . ($body ? ' (' . mb_substr(trim((string)$body), 0, 80) . ')' : '');
    }

    /**
     * POST /cron/tick. The caller has already received 202 when this runs (see CronController).
     * One beat: run the job, wait one interval, call the next beat. Stops when another chain took
     * over, when a real cron job is running, or when the engine was turned off.
     */
    public static function beat(string $chain): void
    {
        $t0 = time();
        $s = self::state();
        if (($s['chain'] ?? null) !== $chain || !empty($s['stopped'])) return;
        $s['beat'] = $t0;
        $s['ticks'] = (int)$s['ticks'] + 1;
        $s['error'] = null;
        self::save($s);

        $last = Database::get()->value('SELECT last_started_at FROM system_jobs WHERE name = ?', [Scheduler::JOB]);
        if (!$last || strtotime($last . ' UTC') <= time() - max(1, self::interval() - 5)) {
            try { Scheduler::run('engine'); } catch (\Throwable $e) { error_log('[Borealis] motor de avisos: ' . $e->getMessage()); }
        }

        // wait the rest of the interval, checking every few seconds that this chain is still the one
        while (time() < $t0 + self::interval()) {
            sleep(min(5, max(1, $t0 + self::interval() - time())));
            $cur = self::state();
            if (($cur['chain'] ?? null) !== $chain || !empty($cur['stopped'])) return;
        }
        if (!self::enabled()) return;
        if (self::cronCovers()) {
            $cur = self::state();
            if (($cur['chain'] ?? null) === $chain) { $cur['stopped'] = 'cron'; self::save($cur); }
            return;
        }
        // 1.16: a passing failure (the server busy for a moment) gets a second try before the chain stops
        if (!self::fire($chain, false)) {
            sleep(10);
            $cur = self::state();
            if (($cur['chain'] ?? null) === $chain && empty($cur['stopped'])) self::fire($chain);
        }
    }

    /** For Administración: running / stopped and why. */
    public static function status(): array
    {
        $s = self::state();
        return [
            'enabled' => self::enabled(),
            'alive' => self::alive($s),
            'cron' => self::cronCovers(),
            'seconds' => $s['beat'] ? time() - (int)$s['beat'] : null,
            'since' => $s['started'] ? (int)$s['started'] : null,
            'ticks' => (int)$s['ticks'],
            'error' => $s['error'],
            'stopped' => $s['stopped'],
            'fpm' => self::serverCan(),
            'server' => Background::label(),
        ];
    }
}
