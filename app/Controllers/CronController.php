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
}
