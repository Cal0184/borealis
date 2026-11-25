<?php
declare(strict_types=1);

namespace Borealis\Controllers;

use Borealis\Core\Database;
use Borealis\Core\Migrator;
use Borealis\Core\Request;
use Borealis\Core\Response;
use Borealis\Services\Scheduler;

/**
 * 1.14 · /cron/run?token=… — the scheduled job started by a URL, for hosts where the cron job can
 * only call an address (HestiaCP: «curl -fsS "https://dominio/borealis/cron/run?token=…"»).
 * No session, no cookies. The token is compared in constant time; without it nothing runs and the
 * answer says nothing about the installation. Calls closer than 60 seconds do not run twice.
 */
final class CronController
{
    public static function handle(Request $r): Response
    {
        $token = (string)($r->q('token') ?? $r->header('X-Borealis-Token') ?? '');
        $text = fn(string $s, int $code) => new Response($s . "\n", $code, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']);
        if (!Scheduler::ready() || (new Migrator(Database::get()->pdo))->pending()) return $text('Borealis necesita actualizar la base de datos: entra como administración.', 503);
        if (!Scheduler::checkToken($token)) return $text('No autorizado.', 403);
        $last = Database::get()->value('SELECT last_started_at FROM system_jobs WHERE name = ?', [Scheduler::JOB]);
        if ($last && strtotime($last . ' UTC') > time() - 60) return $text('Ya se ejecutó hace menos de un minuto.', 200);
        ignore_user_abort(true);
        @set_time_limit(120);
        $res = Scheduler::run('http');
        if (!empty($res['skipped'])) return $text('Ya hay otra ejecución en curso.', 200);
        return $text($res['summary'] . ($res['errors'] ? "\nErrores: " . implode(' · ', $res['errors']) : ''), $res['errors'] ? 500 : 200);
    }

    /**
     * 1.15 · POST /cron/tick: one beat of the internal engine. Answers 202 at once (the caller only
     * waits for that), then works with the connection closed. Token and chain id come in headers.
     */
    public static function tick(Request $r): void
    {
        $text = function (string $s, int $code): void {
            (new Response($s . "\n", $code, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']))->send();
        };
        if ($r->method !== 'POST') { $text('Método no permitido.', 405); return; }
        if (!Scheduler::ready() || (new Migrator(Database::get()->pdo))->pending()) { $text('Borealis necesita actualizar la base de datos.', 503); return; }
        if (!Scheduler::checkToken((string)($r->header('X-Borealis-Token') ?? ''))) { $text('No autorizado.', 403); return; }
        $chain = (string)($r->header('X-Borealis-Chain') ?? '');
        if (!preg_match('/^[a-f0-9]{16}$/', $chain) || !\Borealis\Services\Heartbeat::enabled()) { $text('Sin efecto.', 409); return; }
        ignore_user_abort(true);
        @set_time_limit(0);
        $text('Latido recibido.', 202);
        \Borealis\Core\Background::detach(); // 1.16: PHP-FPM, LiteSpeed or Apache
        \Borealis\Services\Heartbeat::beat($chain);
    }

    /**
     * 1.16 · /cron/ping: «somebody is around», without token, session or cookies. The service worker
     * calls it when a device receives a notice and when the browser wakes it up by itself (periodic
     * background sync of an installed Borealis), so every device helps to keep the job and the
     * engine running even when no page of Borealis is open. It answers 204 with nothing in it and
     * can start at most what a visit starts: the engine (once a minute at most) and the job (once
     * every two minutes at most, never twice at a time).
     */
    public static function ping(Request $r): void
    {
        (new Response('', 204, ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']))->send();
        try {
            if (!Scheduler::ready() || (new Migrator(Database::get()->pdo))->pending()) return;
        } catch (\Throwable) { return; }
        register_shutdown_function(function () {
            \Borealis\Core\Background::detach();
            \Borealis\Services\Heartbeat::ensure();
        });
        Scheduler::afterResponse('device');
    }
}
