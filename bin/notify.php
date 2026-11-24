<?php
declare(strict_types=1);

/*
 * Borealis — reminders, mobile notifications, e-mail and Google Calendar while nobody has Borealis
 * open (1.8, extended in 1.9).
 *
 *   php bin/notify.php            (every 5 minutes from cron; 10 also works)
 *
 * HestiaCP: Cron Jobs › Add Cron Job, command
 *   cd /home/USUARIO/web/DOMINIO/public_html/borealis && php bin/notify.php >/dev/null 2>&1
 * every 5 minutes (the «30 minutos antes» reminder then arrives between 25 and 30 minutes before). Without this job Borealis still creates the reminders when each person opens
 * it, and sends notices of assignments at the moment they happen; what is lost is the phone
 * reminder of a task about to be due while the person is not using Borealis.
 *
 * For each active person: tasks about to be due and overdue (with their own lead time), sprints
 * starting or ending, and event reminders. Then it sends what is pending to their devices and
 * announces in Discord the tasks due tomorrow of the projects that turned that on.
 * Every notice is created once (dedupe), so running it often never repeats anything.
 * 1.12: it also runs the «Monitoreo» rules once per workspace (lock per workspace in storage/monitor-N.lock;
 * errors go to stderr and to monitor_runs, visible in Monitoreo as «última revisión»).
 * 1.14: the work itself is Borealis\Services\Scheduler (the same code runs from /cron/run and, as a
 * fallback, after a visit). Each run is recorded in system_jobs and shown in Administración ›
 * Notificaciones, so a cron job that does not run (or runs with another PHP) is no longer silent.
 * Errors are printed: do not send the output to /dev/null while you set it up.
 */

if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/app/bootstrap.php';

use Borealis\Core\Config;
use Borealis\Core\Database;
use Borealis\Services\Scheduler;

Config::load();
if (!Config::isInstalled()) { fwrite(STDERR, "Borealis no está instalado.\n"); exit(1); }
$db = Database::get();
if (!$db->value("SELECT 1 FROM schema_migrations WHERE version = '011_v19'")) { fwrite(STDERR, "Falta aplicar la migración 011_v19 (abre Borealis como administración o ejecuta php bin/migrate.php).\n"); exit(1); }
// Tests only: pin the clock (BOREALIS_NOW=2026-11-16T08:05:00Z). Never set this on a real server.
$ts = getenv('BOREALIS_NOW') ? (strtotime((string)getenv('BOREALIS_NOW')) ?: null) : null;
$missing = array_values(array_filter(['openssl', 'curl', 'pdo_mysql', 'mbstring'], fn($e) => !extension_loaded($e)));
if ($missing) fwrite(STDERR, 'Este PHP de consola (' . PHP_VERSION . ', ' . PHP_BINARY . ') no tiene: ' . implode(', ', $missing) . ". Usa en el cron la misma versión de PHP del sitio (por ejemplo /usr/bin/php8.2).\n");

$r = Scheduler::run('cli', $ts);
if (!empty($r['skipped'])) { echo "Ya hay otra ejecución en curso.\n"; exit(0); }
foreach ($r['errors'] as $e) fwrite(STDERR, $e . "\n");
echo $r['summary'] . "\n";
