<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Background;
use Borealis\Core\Database;
use Borealis\Security\Policy;
use Borealis\Security\Secrets;

/**
 * The scheduled job of Borealis (1.14): reminders, monitoring, capacity, mobile notifications,
 * e-mail and Google Calendar, for everybody, without anybody having Borealis open.
 *
 * Before 1.14 this lived in bin/notify.php and the only other place that created time-based
 * reminders was the person's own visit (HomeController). On a server without a working cron job
 * every reminder therefore appeared «when the person comes back», which is the problem reported for
 * 1.13. The job is now the same code whichever way it is started, and each run is recorded in
 * system_jobs, so Administración says plainly whether the cron job runs:
 *
 *   - «cli»:   php bin/notify.php from cron (recommended, HestiaCP › Cron Jobs every 5 minutes).
 *   - «http»:  GET/POST /cron/run?token=… for hosts where the cron job can only call a URL
 *              (HestiaCP can do it with curl or wget; also external cron services).
 *   - «visit»: fallback. After answering any page (PHP-FPM, response already sent) Borealis starts
 *              the job if the last run is older than 5 minutes. It keeps reminders going for the
 *              whole team as long as anybody uses Borealis, but it is not a replacement for cron.
 *
 * Runs never overlap (file lock), and every notice is created and delivered once (dedupe keys and
 * notification_deliveries), so starting the job from several places never duplicates anything.
 */
final class Scheduler
{
    public const JOB = 'notify';
    /** A run older than this means the cron job is not running (the job is set every 5 minutes). */
    public const STALE_MINUTES = 15;
    private static bool $running = false;
    private static bool $hooked = false;

    public static function running(): bool
    {
        return self::$running;
    }

    public static function ready(): bool
    {
        try {
            return (bool)Database::get()->value("SELECT 1 FROM schema_migrations WHERE version = '016_v114'");
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * One full run. Returns a summary; ['skipped' => true] when another run holds the lock.
     * $ts pins the clock (tests only).
     */
    public static function run(string $source = 'cli', ?int $ts = null): array
    {
        $lock = @fopen(BASE_PATH . '/storage/notify.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return ['skipped' => true];
        $db = Database::get();
        $ready = self::ready();
        $started = now();
        if ($ready) {
            $db->run('INSERT INTO system_jobs (name, last_started_at, last_source, runs) VALUES (?,?,?,1)
                ON DUPLICATE KEY UPDATE last_started_at = VALUES(last_started_at), last_source = VALUES(last_source), runs = runs + 1', [self::JOB, $started, $source]);
        }
        $prevUser = Auth::user();
        self::$running = true;
        $errors = [];
        $out = ['created' => 0, 'sent' => 0, 'retried' => 0, 'emails' => 0, 'mail_notices' => 0, 'mail_errors' => 0, 'gcal' => 0];
        try {
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
                    $errors[] = "Persona {$u['id']}: " . $e->getMessage();
                }
            }
            $hasMonitor = (bool)$db->value("SELECT 1 FROM schema_migrations WHERE version = '014_v112'");
            foreach ($db->all("SELECT wm.workspace_id, MIN(wm.user_id) AS uid FROM workspace_members wm JOIN users u ON u.id = wm.user_id AND u.is_active = 1 WHERE wm.status = 'active' GROUP BY wm.workspace_id") as $w) {
                $ws = (int)$w['workspace_id'];
                try {
                    Auth::actAs($db->one('SELECT * FROM users WHERE id = ?', [$w['uid']]));
                    Policy::useWorkspace($ws);
                    CapacityAlerts::refresh(true);
                } catch (\Throwable $e) { $errors[] = "Capacidad (espacio $ws): " . $e->getMessage(); }
                if ($hasMonitor) {
                    try {
                        $st = Monitoring::scan($ws);
                        if (!empty($st['error'])) $errors[] = "Monitoreo (espacio $ws): {$st['error']}";
                    } catch (\Throwable $e) { $errors[] = "Monitoreo (espacio $ws): " . $e->getMessage(); }
                }
            }
            $out['created'] = (int)$db->value('SELECT COUNT(*) FROM notifications') - $n0;

            // Discord: tasks due tomorrow, only key and title, for projects that turned it on.
            $tomorrow = date('Y-m-d', strtotime(today() . ' +1 day'));
            foreach ($db->all("SELECT i.project_id FROM project_integrations i WHERE i.provider = 'discord' AND i.enabled = 1 AND FIND_IN_SET('task_due', i.events)") as $i) {
                try {
                    $rows = $db->all("SELECT t.id, t.number, t.title, p.project_key, p.name FROM tasks t JOIN projects p ON p.id = t.project_id JOIN statuses s ON s.id = t.status_id
                        WHERE t.project_id = ? AND t.deleted_at IS NULL AND s.category <> 'done' AND t.due_date = ? ORDER BY t.number LIMIT 20", [$i['project_id'], $tomorrow]);
                    if (!$rows) continue;
                    $list = implode("\n", array_map(fn($t) => "• {$t['project_key']}-{$t['number']} {$t['title']}", $rows));
                    Integrations::announce((int)$i['project_id'], 'task_due', "Vencen mañana en «{$rows[0]['name']}»:\n$list", '/projects/' . $i['project_id'] . '/list', 'tdue:' . $i['project_id'] . ':' . $tomorrow);
                } catch (\Throwable $e) { $errors[] = 'Discord: ' . $e->getMessage(); }
            }

            if (Push::available()) {
                try {
                    $out['retried'] = $ready ? Push::retryDue() : 0;
                    $out['sent'] = Push::flush();
                } catch (\Throwable $e) { $errors[] = 'Notificaciones: ' . $e->getMessage(); }
            }
            try {
                $mail = NotificationMail::flush();
                $out['emails'] = $mail['emails']; $out['mail_notices'] = $mail['notices']; $out['mail_errors'] = $mail['errors'];
            } catch (\Throwable $e) { $errors[] = 'Correo: ' . $e->getMessage(); }
            try { $out['gcal'] = GoogleExport::runAll(); } catch (\Throwable $e) { $errors[] = 'Google Calendar: ' . $e->getMessage(); }
            if (class_exists(Automations::class)) {
                try { $out['automations'] = Automations::runScheduled(); } catch (\Throwable $e) { $errors[] = 'Automatizaciones: ' . $e->getMessage(); }
            }
            try { self::dailyDigest(); } catch (\Throwable $e) { $errors[] = 'Registro maestro: ' . $e->getMessage(); }
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        } finally {
            self::$running = false;
            Auth::reset();
            if ($prevUser && PHP_SAPI === 'cli') Auth::actAs($prevUser);
            $out['errors'] = $errors;
            $out['summary'] = self::summary($out);
            if ($ready) {
                $db->run('UPDATE system_jobs SET last_finished_at = ?, last_ok_at = IF(? = 1, ?, last_ok_at), last_summary = ?, last_error = ?, cli_ok_at = IF(? = 1 AND ? = \'cli\', ?, cli_ok_at) WHERE name = ?', [
                    now(), $errors ? 0 : 1, now(), mb_substr($out['summary'], 0, 255), $errors ? mb_substr(implode(' · ', $errors), 0, 500) : null, $errors ? 0 : 1, $source, now(), self::JOB]);
            }
            try { self::logRunErrors($errors, $source); } catch (\Throwable) {}
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $out;
    }

    /**
     * 1.15 · once a day, one Master Log entry per workspace with the previous day's notices: created,
     * delivered to devices and failed. (Each failed delivery also has its own entry, with the reason.)
     * A run that fails writes one entry an hour at most, so a broken setting is visible but not noisy.
     */
    private static function dailyDigest(): void
    {
        if (!Activity::v115()) return;
        $db = Database::get();
        $day = date('Y-m-d', strtotime(today() . ' -1 day'));
        [$from, $to] = [$day . ' 00:00:00', $day . ' 23:59:59'];
        foreach ($db->column("SELECT DISTINCT workspace_id FROM workspace_members WHERE status = 'active'") as $ws) {
            $ws = (int)$ws;
            $key = 'Avisos del ' . date('d/m/Y', strtotime($day));
            if ($db->value("SELECT 1 FROM activity WHERE workspace_id = ? AND action = 'notify_daily' AND summary LIKE ? LIMIT 1", [$ws, $key . '%'])) continue;
            $created = (int)$db->value('SELECT COUNT(*) FROM notifications WHERE workspace_id = ? AND created_at BETWEEN ? AND ?', [$ws, $from, $to]);
            $d = $db->one("SELECT SUM(d.state = 'sent') AS sent, SUM(d.state = 'failed') AS failed FROM notification_deliveries d JOIN notifications n ON n.id = d.notification_id
                WHERE n.workspace_id = ? AND d.created_at BETWEEN ? AND ?", [$ws, $from, $to]) ?: [];
            $sent = (int)($d['sent'] ?? 0);
            $failed = (int)($d['failed'] ?? 0);
            Activity::log('notify_daily', 'notification', null, "$key: $created creados, $sent entregados a dispositivos" . ($failed ? ", $failed sin entregar" : ''), null, null, [], $ws,
                ['category' => 'notification', 'source' => 'system', 'user_id' => null, 'after' => ['creados' => $created, 'entregados' => $sent, 'sin entregar' => $failed]]);
        }
    }

    /** 1.15 · a failed run, in each workspace's Master Log (once an hour for the same message). */
    private static function logRunErrors(array $errors, string $source): void
    {
        if (!$errors || !Activity::v115()) return;
        $db = Database::get();
        $msg = mb_substr('La tarea programada terminó con errores: ' . implode(' · ', $errors), 0, 255);
        $since = date('Y-m-d H:i:s', time() - 3600);
        foreach ($db->column("SELECT DISTINCT workspace_id FROM workspace_members WHERE status = 'active'") as $ws) {
            if ($db->value("SELECT 1 FROM activity WHERE workspace_id = ? AND action = 'notify_run_error' AND created_at >= ? LIMIT 1", [$ws, $since])) continue;
            Activity::log('notify_run_error', 'system', null, $msg, null, null, [], (int)$ws, ['category' => 'error', 'result' => 'error', 'source' => 'system', 'user_id' => null, 'after' => ['origen' => $source]]);
        }
    }

    public static function summary(array $o): string
    {
        return "Avisos nuevos: {$o['created']} · enviados a dispositivos: {$o['sent']}" . ($o['retried'] ? " (reintentos: {$o['retried']})" : '')
            . " · correos: {$o['emails']} ({$o['mail_notices']} avisos" . ($o['mail_errors'] ? ", {$o['mail_errors']} con error" : '') . ") · Google Calendar: {$o['gcal']} personas";
    }

    /** Last run, and whether it is recent enough: ['row' => …, 'state' => ok|stale|never|error, 'minutes' => …]. */
    public static function status(): array
    {
        $row = self::ready() ? Database::get()->one('SELECT * FROM system_jobs WHERE name = ?', [self::JOB]) : null;
        if (!$row || !$row['last_started_at']) return ['row' => $row, 'state' => 'never', 'minutes' => null, 'cli' => false];
        $min = (int)floor((time() - strtotime($row['last_started_at'] . ' UTC')) / 60);
        $cliMin = $row['cli_ok_at'] ? (int)floor((time() - strtotime($row['cli_ok_at'] . ' UTC')) / 60) : null;
        $state = $row['last_error'] ? 'error' : ($min > self::STALE_MINUTES ? 'stale' : 'ok');
        return ['row' => $row, 'state' => $state, 'minutes' => $min, 'cli' => $cliMin !== null && $cliMin <= self::STALE_MINUTES];
    }

    // ------------------------------------------------------------------ /cron/run

    private static function tokenFile(): string
    {
        return BASE_PATH . '/storage/cron.json';
    }

    /** Secret of the /cron/run address: config('cron.token') or one created once in storage/cron.json (encrypted). */
    public static function token(): string
    {
        $cfg = (string)config('cron.token', '');
        if (strlen($cfg) >= 24) return $cfg;
        $f = self::tokenFile();
        if (is_file($f)) {
            $j = json_decode((string)file_get_contents($f), true);
            $t = is_array($j) ? Secrets::decrypt((string)($j['token'] ?? '')) : null;
            if ($t) return $t;
        }
        return self::rotateToken();
    }

    public static function rotateToken(): string
    {
        $t = bin2hex(random_bytes(24));
        $f = self::tokenFile();
        $tmp = $f . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, json_encode(['token' => Secrets::encrypt($t), 'created' => gmdate('c')])) === false || !@rename($tmp, $f)) {
            throw new \RuntimeException('No se pudo guardar storage/cron.json: revisa los permisos de storage/.');
        }
        @chmod($f, 0600);
        return $t;
    }

    public static function checkToken(string $given): bool
    {
        return $given !== '' && hash_equals(self::token(), $given);
    }

    // ------------------------------------------------------------------ fallback after a visit

    /**
     * Called by the front controller for every page: when the last run is older than 5 minutes,
     * runs the job after the response was sent. Only where PHP can finish the response first
     * (PHP-FPM, as on HestiaCP) so nobody waits for it; 'cron.web_fallback' => false turns it off.
     */
    public static function maybeAfterResponse(): void
    {
        // 1.15: keep the internal engine beating (cheap: reads one small file); it starts its chain
        // after the answer was sent, so the page never waits for it.
        if (Heartbeat::enabled()) {
            $s = Heartbeat::state();
            if (!Heartbeat::alive($s) && (int)$s['attempt'] <= time() - 60) register_shutdown_function(function () {
                Background::detach();
                Heartbeat::ensure();
            });
        }
        self::afterResponse('visit');
    }

    /** Seconds between two runs started by requests (visits, pages left open, devices). */
    public const REQUEST_EVERY = 120;

    /**
     * 1.16 · runs the job after the answer, when the last run is older than REQUEST_EVERY. Used by
     * every page («visit») and by /cron/ping («device»: a service worker that received a notice or
     * woke up by itself). PHP-FPM, LiteSpeed and Apache (Core/Background.php).
     */
    public static function afterResponse(string $source): void
    {
        $mode = config('cron.web_fallback', true);
        if ($mode === false || self::$hooked) return;
        if ($mode !== 'always' && !Heartbeat::serverCan()) return;
        try {
            if (!self::ready()) return;
            $last = Database::get()->value('SELECT last_started_at FROM system_jobs WHERE name = ?', [self::JOB]);
            if ($last && strtotime($last . ' UTC') > time() - self::REQUEST_EVERY) return;
        } catch (\Throwable) { return; }
        self::$hooked = true;
        register_shutdown_function(function () use ($source) {
            Background::detach();
            @set_time_limit(120);
            try { self::run($source); } catch (\Throwable $e) { error_log('[Borealis] tarea programada: ' . $e->getMessage()); }
        });
    }
}
