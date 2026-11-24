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
 */

if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/app/bootstrap.php';

use Borealis\Core\Auth;
use Borealis\Core\Config;
use Borealis\Core\Database;
use Borealis\Security\Policy;
use Borealis\Services\CapacityAlerts;
use Borealis\Services\Events;
use Borealis\Services\GoogleExport;
use Borealis\Services\Integrations;
use Borealis\Services\Monitoring;
use Borealis\Services\NotificationMail;
use Borealis\Services\Notifications;
use Borealis\Services\Push;

Config::load();
if (!Config::isInstalled()) { fwrite(STDERR, "Borealis no está instalado.\n"); exit(1); }
$lock = fopen(BASE_PATH . '/storage/notify.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { echo "Ya hay otra ejecución en curso.\n"; exit(0); }
$db = Database::get();
if (!$db->value("SELECT 1 FROM schema_migrations WHERE version = '011_v19'")) { fwrite(STDERR, "Falta aplicar la migración 011_v19 (abre Borealis como administración o ejecuta php bin/migrate.php).\n"); exit(1); }
// Tests only: pin the clock (BOREALIS_NOW=2026-11-16T08:05:00Z). Never set this on a real server.
$ts = getenv('BOREALIS_NOW') ? (strtotime((string)getenv('BOREALIS_NOW')) ?: null) : null;

$people = $db->all("SELECT u.*, wm.workspace_id FROM users u JOIN workspace_members wm ON wm.user_id = u.id AND wm.status = 'active' WHERE u.is_active = 1 ORDER BY u.id");
$n0 = (int)$db->value('SELECT COUNT(*) FROM notifications');
foreach ($people as $u) {
    try {
        $ws = (int)$u['workspace_id'];
        unset($u['workspace_id']);
        Auth::actAs($u);
        Policy::useWorkspace($ws);
        Notifications::generateFor((int)$u['id'], $ws, $ts);
        Events::generateReminders();
    } catch (\Throwable $e) {
        fwrite(STDERR, "Persona {$u['id']}: " . $e->getMessage() . "\n");
    }
}
$hasMonitor = (bool)$db->value("SELECT 1 FROM schema_migrations WHERE version = '014_v112'");
// Planning discrepancies (hours used up with planned work pending), once per workspace.
foreach ($db->all("SELECT wm.workspace_id, MIN(wm.user_id) AS uid FROM workspace_members wm JOIN users u ON u.id = wm.user_id AND u.is_active = 1 WHERE wm.status = 'active' GROUP BY wm.workspace_id") as $w) {
    $ws = (int)$w['workspace_id'];
    try {
        Auth::actAs($db->one('SELECT * FROM users WHERE id = ?', [$w['uid']]));
        Policy::useWorkspace($ws);
        CapacityAlerts::refresh(true);
    } catch (\Throwable $e) { fwrite(STDERR, "Capacidad (espacio $ws): " . $e->getMessage() . "\n"); }
    // 1.12: monitoring rules (overdue, overload, deviations, blocked work…) with dedupe, auto-resolve and escalation.
    if ($hasMonitor) {
        try {
            $st = Monitoring::scan($ws);
            if (!empty($st['error'])) fwrite(STDERR, "Monitoreo (espacio $ws): {$st['error']}\n");
        } catch (\Throwable $e) { fwrite(STDERR, "Monitoreo (espacio $ws): " . $e->getMessage() . "\n"); }
    }
}
$created = (int)$db->value('SELECT COUNT(*) FROM notifications') - $n0;

// Discord: tasks due tomorrow, only key and title, for projects that turned it on.
$tomorrow = date('Y-m-d', strtotime(today() . ' +1 day'));
foreach ($db->all("SELECT i.project_id FROM project_integrations i WHERE i.provider = 'discord' AND i.enabled = 1 AND FIND_IN_SET('task_due', i.events)") as $i) {
    $rows = $db->all("SELECT t.id, t.number, t.title, p.project_key, p.name FROM tasks t JOIN projects p ON p.id = t.project_id JOIN statuses s ON s.id = t.status_id
        WHERE t.project_id = ? AND t.deleted_at IS NULL AND s.category <> 'done' AND t.due_date = ? ORDER BY t.number LIMIT 20", [$i['project_id'], $tomorrow]);
    if (!$rows) continue;
    $list = implode("\n", array_map(fn($t) => "• {$t['project_key']}-{$t['number']} {$t['title']}", $rows));
    Integrations::announce((int)$i['project_id'], 'task_due', "Vencen mañana en «{$rows[0]['name']}»:\n$list", '/projects/' . $i['project_id'] . '/list', 'tdue:' . $i['project_id'] . ':' . $tomorrow);
}

$sent = Push::available() ? Push::flush() : 0;
$mail = NotificationMail::flush();
$gcal = GoogleExport::runAll();
echo "Avisos nuevos: $created · enviados a dispositivos: $sent · correos: {$mail['emails']} ({$mail['notices']} avisos" . ($mail['errors'] ? ", {$mail['errors']} con error" : '') . ") · Google Calendar: $gcal personas\n";
