<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Validator;
use Borealis\Security\Policy;

/**
 * «Capacidad agotada — Trabajo pendiente» (1.5).
 *
 * A person's week (Monday to Sunday) is at risk when, in this workspace:
 *  - the hours they really logged that week (time entries, every project counted once) reached
 *    their weekly hours (Administración › Usuarios) plus any extra hours authorised for that week, and
 *  - they still have open work committed for that week: tasks (not subtasks) where they are directly
 *    responsible, not finished, and either due that week or in an active sprint that ends that week.
 *
 * Estimated or assigned hours are never counted as consumed. Tasks without an estimate are listed
 * as «sin estimar», never given invented hours. Overdue tasks from earlier weeks are shown apart and
 * do not raise the alert by themselves. Nothing is completed, moved or authorised automatically.
 *
 * Alerts are stored once per person and week. Looking at them changes nothing; they become
 * «resuelta» only through a decision (resolve()) or when the problem disappears (the work is done,
 * moved or reassigned, or hours become available), and every step is kept in capacity_alert_events.
 */
final class CapacityAlerts
{
    public const ACTIONS = [
        'reschedule' => 'Reprogramar', 'reassign' => 'Reasignar', 'estimate' => 'Revisar estimación',
        'extra' => 'Autorizar horas adicionales', 'keep' => 'Mantener pendiente con justificación',
    ];
    public const RESOLUTIONS = self::ACTIONS + ['cleared' => 'Ya no hay trabajo pendiente o hay horas disponibles'];

    // ---- weeks -------------------------------------------------------------

    public static function weekStart(?string $date = null): string
    {
        $ts = strtotime(($date ?: today()) . ' 12:00:00');
        return date('Y-m-d', $ts - ((int)date('N', $ts) - 1) * 86400);
    }

    public static function weekEnd(string $weekStart): string
    {
        return date('Y-m-d', strtotime($weekStart . ' 12:00:00') + 6 * 86400);
    }

    // ---- measuring -----------------------------------------------------------

    /**
     * The facts for one person and week in the current workspace:
     * capacity (null = no weekly hours defined), logged and extra minutes, committed open tasks and overdue ones.
     */
    public static function measure(int $userId, string $weekStart): array
    {
        $db = Database::get();
        $ws = Policy::wsId();
        $we = self::weekEnd($weekStart);
        $weekly = $db->value('SELECT weekly_hours FROM workspace_members WHERE workspace_id = ? AND user_id = ?', [$ws, $userId]);
        $extra = (int)$db->value('SELECT COALESCE(SUM(minutes), 0) FROM capacity_extras WHERE workspace_id = ? AND user_id = ? AND week_start = ?', [$ws, $userId, $weekStart]);
        $logged = (int)$db->value('SELECT COALESCE(SUM(te.minutes), 0) FROM time_entries te JOIN projects p ON p.id = te.project_id
            WHERE te.user_id = ? AND p.workspace_id = ? AND te.deleted_at IS NULL AND te.work_date BETWEEN ? AND ?', [$userId, $ws, $weekStart, $we]);
        $mine = '(t.assignee_id = ? OR EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id = t.id AND ta.user_id = ?))';
        $base = "SELECT t.id, t.project_id, t.title, t.number, t.due_date, t.sprint_id, t.estimate_hours, t.assignee_id, p.project_key, p.name AS project_name,
                sp.name AS sprint_name, sp.end_date AS sprint_end,
                (1 + (SELECT COUNT(*) FROM task_assignees x WHERE x.task_id = t.id AND x.user_id <> COALESCE(t.assignee_id, 0))) - (t.assignee_id IS NULL) AS people
            FROM tasks t JOIN projects p ON p.id = t.project_id JOIN statuses s ON s.id = t.status_id LEFT JOIN sprints sp ON sp.id = t.sprint_id
            WHERE p.workspace_id = ? AND p.archived_at IS NULL AND t.deleted_at IS NULL AND t.parent_id IS NULL AND s.category <> 'done' AND $mine";
        $committed = $db->all($base . " AND ((t.due_date BETWEEN ? AND ?) OR (sp.state = 'active' AND sp.end_date BETWEEN ? AND ? AND (sp.start_date IS NULL OR sp.start_date <= ?)))
            ORDER BY t.due_date IS NULL, t.due_date, t.id", [$ws, $userId, $userId, $weekStart, $we, $weekStart, $we, $we]);
        $ids = array_map(fn($t) => (int)$t['id'], $committed);
        $overdue = $db->all($base . ' AND t.due_date < ?' . ($ids ? ' AND t.id NOT IN (' . Database::in($ids) . ')' : '') . ' ORDER BY t.due_date, t.id',
            array_merge([$ws, $userId, $userId, $weekStart], $ids));
        $pendingHours = 0.0;
        $unestimated = 0;
        foreach ($committed as &$t) {
            $t['task_key'] = $t['project_key'] . '-' . $t['number'];
            $t['share'] = Capacity::share($t['estimate_hours'] !== null ? (float)$t['estimate_hours'] : null, max(1, (int)$t['people']));
            if ($t['share'] === null) $unestimated++; else $pendingHours += $t['share'];
        }
        unset($t);
        foreach ($overdue as &$t) $t['task_key'] = $t['project_key'] . '-' . $t['number'];
        unset($t);
        $capacity = ($weekly !== null && (float)$weekly > 0) ? (int)round((float)$weekly * 60) + $extra : null;
        return [
            'user_id' => $userId, 'week_start' => $weekStart, 'week_end' => $we,
            'weekly_minutes' => $weekly !== null ? (int)round((float)$weekly * 60) : null, 'extra_minutes' => $extra,
            'capacity_minutes' => $capacity, 'logged_minutes' => $logged,
            'tasks' => $committed, 'overdue' => $overdue, 'pending_hours' => round($pendingHours, 2), 'unestimated' => $unestimated,
            'at_risk' => $capacity !== null && $logged >= $capacity && count($committed) > 0,
        ];
    }

    // ---- detection -------------------------------------------------------------

    /**
     * Brings one person's alert for that week in line with the facts. Creates it (and notifies once),
     * keeps its numbers current, reopens it when the problem comes back after a decision that no
     * longer covers it, or closes it as «cleared» when the problem is gone.
     */
    public static function evaluate(int $userId, ?string $weekStart = null): ?array
    {
        $weekStart ??= self::weekStart();
        $db = Database::get();
        $ws = Policy::wsId();
        $m = self::measure($userId, $weekStart);
        $a = $db->one('SELECT * FROM capacity_alerts WHERE workspace_id = ? AND user_id = ? AND week_start = ?', [$ws, $userId, $weekStart]);
        $numbers = ['capacity_minutes' => (int)($m['capacity_minutes'] ?? 0), 'logged_minutes' => $m['logged_minutes'], 'pending_tasks' => count($m['tasks']), 'updated_at' => now()];
        if ($m['at_risk']) {
            if (!$a) {
                $db->run('INSERT IGNORE INTO capacity_alerts (workspace_id, user_id, week_start, status, capacity_minutes, logged_minutes, pending_tasks, detected_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)',
                    [$ws, $userId, $weekStart, 'open', $numbers['capacity_minutes'], $numbers['logged_minutes'], $numbers['pending_tasks'], now(), now()]);
                $a = $db->one('SELECT * FROM capacity_alerts WHERE workspace_id = ? AND user_id = ? AND week_start = ?', [$ws, $userId, $weekStart]);
                self::event((int)$a['id'], 'detected', self::sentence($m, Users::find($userId)['name'] ?? ''), null);
                self::notify($a, $m, 'cap-' . $a['id']);
            } elseif ($a['status'] === 'open') {
                $db->update('capacity_alerts', $numbers, 'id = ?', [$a['id']]);
            } elseif (self::comesBack($a, $m)) {
                $db->update('capacity_alerts', $numbers + ['status' => 'open', 'resolved_at' => null, 'resolved_by' => null, 'resolution' => null, 'resolution_note' => null], 'id = ?', [$a['id']]);
                $n = (int)$db->value('SELECT COUNT(*) FROM capacity_alert_events WHERE alert_id = ?', [$a['id']]);
                self::event((int)$a['id'], 'reopened', 'Volvió a ocurrir: ' . self::sentence($m, Users::find($userId)['name'] ?? ''), null);
                self::notify($a, $m, 'cap-' . $a['id'] . '-' . $n);
            }
        } elseif ($a && $a['status'] === 'open') {
            $db->update('capacity_alerts', $numbers + ['status' => 'resolved', 'resolved_at' => now(), 'resolved_by' => null, 'resolution' => 'cleared'], 'id = ?', [$a['id']]);
            self::event((int)$a['id'], 'cleared', $m['tasks'] ? 'Hay horas disponibles otra vez.' : 'Ya no queda trabajo pendiente comprometido para esa semana.', null);
        }
        return $a ? $db->one('SELECT * FROM capacity_alerts WHERE id = ?', [$a['id']]) : null;
    }

    /** A resolved alert reopens only when the decision taken no longer covers the situation. */
    private static function comesBack(array $a, array $m): bool
    {
        if ($a['resolution'] === 'extra' || $a['resolution'] === 'cleared') return true; // the extra hours are used up too, or the problem is new
        return count($m['tasks']) > (int)$a['pending_tasks']; // more work committed than when it was decided
    }

    /** Re-checks after hours are logged or removed. Never lets an alert problem break the time entry itself. */
    public static function check(int $userId, string $workDate): void
    {
        try {
            self::evaluate($userId, self::weekStart($workDate));
        } catch (\Throwable $e) {
            error_log('Borealis capacity alert check: ' . $e->getMessage());
        }
    }

    private static bool $refreshed = false;

    /**
     * Re-checks, once per request, the current week of everyone with weekly hours who logged time
     * this week or already has an alert, plus every alert still open from earlier weeks. This is how
     * changes of responsible person, dates, sprints, estimates or status are picked up.
     */
    public static function refresh(bool $force = false): void
    {
        if (self::$refreshed && !$force) return;
        self::$refreshed = true;
        $db = Database::get();
        $ws = Policy::wsId();
        $week = self::weekStart();
        $uids = $db->column("SELECT wm.user_id FROM workspace_members wm WHERE wm.workspace_id = ? AND wm.status = 'active' AND wm.weekly_hours > 0
            AND (EXISTS (SELECT 1 FROM time_entries te JOIN projects p ON p.id = te.project_id WHERE te.user_id = wm.user_id AND p.workspace_id = wm.workspace_id AND te.deleted_at IS NULL AND te.work_date >= ?)
              OR EXISTS (SELECT 1 FROM capacity_alerts ca WHERE ca.workspace_id = wm.workspace_id AND ca.user_id = wm.user_id AND ca.week_start = ?))", [$ws, $week, $week]);
        foreach ($uids as $uid) self::evaluate((int)$uid, $week);
        foreach ($db->all("SELECT user_id, week_start FROM capacity_alerts WHERE workspace_id = ? AND status = 'open' AND week_start <> ?", [$ws, $week]) as $old) {
            self::evaluate((int)$old['user_id'], (string)$old['week_start']);
        }
    }

    /** What the alert panels show: open alerts visible to the current person, optionally for one project or person. */
    public static function forView(?int $projectId = null, ?int $userId = null): array
    {
        if (!self::canSeeAny()) return [];
        self::refresh();
        $rows = self::visible('open', $projectId);
        if ($userId !== null) $rows = array_values(array_filter($rows, fn($a) => (int)$a['user_id'] === $userId));
        return $rows;
    }

    // ---- who sees what -----------------------------------------------------------

    /** Alerts the current person may see (and act on): workspace administration sees all; project managers, those touching their projects. */
    public static function visible(string $status = 'open', ?int $projectId = null): array
    {
        $db = Database::get();
        $rows = $db->all("SELECT ca.*, u.name AS user_name, u.avatar_color, rb.name AS resolved_by_name FROM capacity_alerts ca JOIN users u ON u.id = ca.user_id LEFT JOIN users rb ON rb.id = ca.resolved_by
            WHERE ca.workspace_id = ? AND ca.status = ? ORDER BY " . ($status === 'open' ? 'ca.week_start DESC, ca.detected_at' : 'ca.resolved_at DESC') . ' LIMIT 200', [Policy::wsId(), $status]);
        $out = [];
        $admin = Policy::can('members.manage');
        foreach ($rows as $a) {
            $m = self::measure((int)$a['user_id'], (string)$a['week_start']);
            $tasks = $m['tasks'];
            $projects = array_values(array_unique(array_map(fn($t) => (int)$t['project_id'], $tasks)));
            $manageable = array_values(array_filter($projects, fn($pid) => self::canManageProject($pid)));
            if ($projectId !== null && !in_array($projectId, $projects, true) && !($status !== 'open' && self::eventsMention((int)$a['id'], $projectId))) continue;
            if (!$admin && !$manageable && (int)($a['resolved_by'] ?? 0) !== Auth::id()) continue;
            foreach ($m['tasks'] as &$t) {
                $t['manageable'] = $admin || in_array((int)$t['project_id'], $manageable, true);
                if ($status === 'open' && $t['manageable']) { $t['candidates'] = self::candidates($t, (string)$a['week_start']); $t['sprints'] = self::sprintsFor($t); }
            }
            unset($t);
            $a['measure'] = $m;
            $a['can_act'] = $admin || (bool)$manageable;
            $a['events'] = $db->all('SELECT e.*, u.name AS user_name FROM capacity_alert_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.alert_id = ? ORDER BY e.id', [$a['id']]);
            $a['current_week'] = $a['week_start'] === self::weekStart();
            $out[] = $a;
        }
        return $out;
    }

    private static function eventsMention(int $alertId, int $projectId): bool
    {
        $key = (string)Database::get()->value('SELECT project_key FROM projects WHERE id = ?', [$projectId]);
        return $key !== '' && (bool)Database::get()->value('SELECT 1 FROM capacity_alert_events WHERE alert_id = ? AND detail LIKE ?', [$alertId, '%' . $key . '-%']);
    }

    /** May the current person see the alerts panel at all? */
    public static function canSeeAny(): bool
    {
        if (Policy::can('members.manage')) return true;
        [$vis, $vp] = Policy::visibleProjectsSql('p');
        foreach (Database::get()->all("SELECT p.* FROM projects p WHERE $vis AND p.archived_at IS NULL", $vp) as $p) {
            if (Policy::canProject($p, 'project.manage')) return true;
        }
        return false;
    }

    private static function canManageProject(int $projectId): bool
    {
        $p = Database::get()->one('SELECT * FROM projects WHERE id = ? AND workspace_id = ?', [$projectId, Policy::wsId()]);
        return $p && Policy::canProject($p, 'project.manage');
    }

    public static function find(int $id): array
    {
        $a = Database::get()->one('SELECT ca.*, u.name AS user_name FROM capacity_alerts ca JOIN users u ON u.id = ca.user_id WHERE ca.id = ? AND ca.workspace_id = ?', [$id, Policy::wsId()]);
        if (!$a) throw HttpException::notFound('La alerta no existe.');
        return $a;
    }

    /** People who could take a task: participants of its project with free hours this week (or no limit set). */
    public static function candidates(array $task, string $weekStart): array
    {
        $p = Projects::find((int)$task['project_id']);
        $out = [];
        foreach (Projects::assignableUsers($p) as $u) {
            $uid = (int)$u['id'];
            if ($uid === (int)($task['assignee_id'] ?? 0)) continue;
            $m = self::measure($uid, $weekStart);
            if ($m['capacity_minutes'] !== null && $m['logged_minutes'] >= $m['capacity_minutes']) continue;
            $out[] = ['id' => $uid, 'name' => $u['name'], 'free_minutes' => $m['capacity_minutes'] !== null ? $m['capacity_minutes'] - $m['logged_minutes'] : null];
        }
        usort($out, fn($a, $b) => ($b['free_minutes'] ?? -1) <=> ($a['free_minutes'] ?? -1));
        return $out;
    }

    /** Sprints a task can be moved to (planned or active, not the one it is in). */
    public static function sprintsFor(array $task): array
    {
        return Database::get()->all("SELECT id, name, start_date, end_date, state FROM sprints WHERE project_id = ? AND state IN ('planned','active') AND id <> ? ORDER BY start_date IS NULL, start_date, id",
            [(int)$task['project_id'], (int)($task['sprint_id'] ?? 0)]);
    }

    // ---- decisions -----------------------------------------------------------------

    /**
     * Applies a decision from the alert itself. Task changes go through Tasks (same permissions
     * and sprint-capacity checks as anywhere else). Returns the alert as it is afterwards.
     */
    public static function resolve(int $alertId, array $input): array
    {
        $a = self::find($alertId);
        $m = self::measure((int)$a['user_id'], (string)$a['week_start']);
        $action = (string)($input['action'] ?? '');
        if (!isset(self::ACTIONS[$action])) throw HttpException::invalid(['action' => 'Elige qué hacer con la alerta.']);
        $taskIds = array_map(fn($t) => (int)$t['id'], $m['tasks']);
        $projects = array_values(array_unique(array_map(fn($t) => (int)$t['project_id'], $m['tasks'])));
        $admin = Policy::can('members.manage');
        if (!$admin && !array_filter($projects, fn($pid) => self::canManageProject($pid))) throw HttpException::forbidden('Solo la administración del espacio o la responsable del proyecto pueden resolver esta alerta.');
        $name = $a['user_name'];
        $db = Database::get();

        $task = null;
        if (in_array($action, ['reschedule', 'reassign', 'estimate'], true)) {
            $tid = (int)($input['task_id'] ?? 0);
            if (!in_array($tid, $taskIds, true)) throw HttpException::invalid(['task_id' => 'Elige una de las tareas pendientes de la alerta.']);
            $task = Tasks::find($tid);
            if (!$admin && !Policy::canProject($task['project'], 'project.manage')) throw HttpException::forbidden('Solo la responsable de ese proyecto puede cambiar esta tarea desde la alerta.');
        }
        $note = trim((string)($input['note'] ?? ''));
        if (mb_strlen($note) > 500) throw HttpException::invalid(['note' => 'Máximo 500 caracteres.']);

        switch ($action) {
            case 'reschedule':
                if (!empty($input['sprint_id'])) {
                    $sid = (int)$input['sprint_id'];
                    if (!in_array($sid, array_map(fn($s) => (int)$s['id'], self::sprintsFor($task)), true)) throw HttpException::invalid(['sprint_id' => 'Elige un sprint planificado o activo del proyecto.']);
                    $sp = $db->one('SELECT name FROM sprints WHERE id = ?', [$sid]);
                    Tasks::update((int)$task['id'], ['sprint_id' => $sid] + (($task['due_date'] && $task['due_date'] <= $m['week_end']) ? ['due_date' => null] : []));
                    $detail = "Reprogramó {$task['task_key']} al sprint «{$sp['name']}».";
                } else {
                    $v = Validator::check($input, ['due_date' => 'required,date']);
                    if ($v['due_date'] <= $m['week_end']) throw HttpException::invalid(['due_date' => 'Elige una fecha posterior a esta semana.']);
                    $change = ['due_date' => $v['due_date']];
                    if ($task['sprint_id'] && in_array((int)$task['sprint_id'], array_map(fn($t) => (int)$t['sprint_id'], $m['tasks']), true)) {
                        $sEnd = (string)$db->value('SELECT end_date FROM sprints WHERE id = ?', [(int)$task['sprint_id']]);
                        if ($sEnd !== '' && $sEnd <= $m['week_end']) $change['sprint_id'] = null; // out of the sprint that ends this week
                    }
                    Tasks::update((int)$task['id'], $change);
                    $detail = "Reprogramó {$task['task_key']} para el " . fmt_date($v['due_date'], true) . (array_key_exists('sprint_id', $change) ? ' (fuera del sprint que termina esta semana)' : '') . '.';
                }
                break;
            case 'reassign':
                $to = (int)($input['user_id'] ?? 0);
                if (!in_array($to, array_column(self::candidates($task, (string)$a['week_start']), 'id'), true)) throw HttpException::invalid(['user_id' => 'Elige a alguien del proyecto con horas disponibles.']);
                $ids = array_map('intval', array_merge([$task['assignee_id'] ?? 0], array_column($task['co_assignees'] ?? [], 'id')));
                $ids = array_values(array_unique(array_filter(array_map(fn($x) => $x === (int)$a['user_id'] ? $to : $x, $ids))));
                Tasks::setAssignees((int)$task['id'], $ids);
                $detail = "Reasignó {$task['task_key']} de $name a " . (Users::find($to)['name'] ?? '') . '.';
                break;
            case 'estimate':
                $v = Validator::check($input, ['estimate_hours' => 'required,number']);
                $h = round((float)$v['estimate_hours'], 2);
                if ($h < 0 || $h > 999) throw HttpException::invalid(['estimate_hours' => 'Indica entre 0 y 999 horas.']);
                $before = $task['estimate_hours'] !== null ? Capacity::num((float)$task['estimate_hours']) . ' h' : 'sin estimar';
                Tasks::update((int)$task['id'], ['estimate_hours' => $h]);
                $detail = "Cambió la estimación de {$task['task_key']} de $before a " . Capacity::num($h) . ' h' . ($note !== '' ? ": $note" : '.');
                break;
            case 'extra':
                $minutes = TimeEntries::parseHours($input['hours'] ?? '');
                if ($minutes < 15 || $minutes > 40 * 60) throw HttpException::invalid(['hours' => 'Autoriza entre 15 minutos y 40 horas.']);
                $db->insert('capacity_extras', ['workspace_id' => Policy::wsId(), 'user_id' => (int)$a['user_id'], 'week_start' => $a['week_start'], 'minutes' => $minutes,
                    'reason' => $note ?: null, 'authorized_by' => Auth::id(), 'alert_id' => (int)$a['id'], 'created_at' => now()]);
                $detail = 'Autorizó ' . TimeEntries::fmt($minutes) . " adicionales a $name para esa semana" . ($note !== '' ? ": $note" : '.');
                break;
            default: // keep
                if (mb_strlen($note) < 5) throw HttpException::invalid(['note' => 'Escribe por qué se mantiene pendiente (al menos 5 caracteres).']);
                $detail = "Mantuvo el trabajo pendiente: $note";
        }

        self::event((int)$a['id'], $action, $detail, Auth::id());
        Activity::log('capacity_alert', 'user', (int)$a['user_id'], mb_substr("Alerta de capacidad de $name: $detail", 0, 255));
        $after = self::measure((int)$a['user_id'], (string)$a['week_start']);
        // A decision about the whole week settles it; a change to one task settles it once nothing is left.
        $settles = in_array($action, ['keep', 'extra', 'estimate'], true) || !$after['at_risk'];
        if ($settles) {
            $db->update('capacity_alerts', ['status' => 'resolved', 'resolved_at' => now(), 'resolved_by' => Auth::id(), 'resolution' => $action, 'resolution_note' => mb_substr($detail, 0, 500),
                'capacity_minutes' => (int)($after['capacity_minutes'] ?? 0), 'logged_minutes' => $after['logged_minutes'], 'pending_tasks' => count($after['tasks']), 'updated_at' => now()], 'id = ?', [$a['id']]);
        } else {
            $db->update('capacity_alerts', ['pending_tasks' => count($after['tasks']), 'logged_minutes' => $after['logged_minutes'], 'updated_at' => now()], 'id = ?', [$a['id']]);
        }
        return self::find((int)$a['id']) + ['still_open' => !$settles, 'left' => count($after['tasks'])];
    }

    // ---- helpers ---------------------------------------------------------------------

    public static function sentence(array $m, string $name): string
    {
        $n = count($m['tasks']);
        $cap = $m['capacity_minutes'] ?? 0;
        return "$name ha utilizado " . TimeEntries::fmt($m['logged_minutes']) . ' de sus ' . TimeEntries::fmt($cap) . ' disponibles esta semana, pero todavía tiene '
            . ($n === 1 ? 'una tarea pendiente' : "$n tareas pendientes") . '.';
    }

    private static function event(int $alertId, string $action, string $detail, ?int $userId): void
    {
        Database::get()->insert('capacity_alert_events', ['alert_id' => $alertId, 'action' => $action, 'user_id' => $userId, 'detail' => mb_substr($detail, 0, 500), 'created_at' => now()]);
    }

    /** One notification per alert (and per reopening) to the workspace administration and the managers of the projects involved. */
    private static function notify(array $a, array $m, string $dedupe): void
    {
        $db = Database::get();
        $ws = Policy::wsId();
        $name = (string)(Users::find((int)$a['user_id'])['name'] ?? '');
        $to = array_map('intval', $db->column("SELECT wm.user_id FROM workspace_members wm JOIN role_permissions rp ON rp.role_id = wm.role_id AND rp.permission = 'members.manage' WHERE wm.workspace_id = ? AND wm.status = 'active'", [$ws]));
        foreach (array_unique(array_map(fn($t) => (int)$t['project_id'], $m['tasks'])) as $pid) {
            $p = $db->one('SELECT * FROM projects WHERE id = ?', [$pid]);
            foreach (Projects::members($p) as $u) {
                if ($db->value("SELECT 1 FROM project_members pm JOIN role_permissions rp ON rp.role_id = pm.role_id AND rp.permission = 'project.manage' WHERE pm.project_id = ? AND pm.user_id = ?", [$pid, (int)$u['id']])) $to[] = (int)$u['id'];
            }
        }
        foreach (array_unique($to) as $uid) {
            if ($uid === (int)$a['user_id']) continue; // a planning aid for whoever decides, not a note to the person
            // 1.9: worded as a planning discrepancy, not as an evaluation of the person; stored directly so
            // whoever triggered the check (or the cron acting for someone) also gets it if they manage.
            $n = count($m['tasks']);
            Notifications::store($uid, $ws, 'capacity', null, "$name completó sus " . TimeEntries::fmt((int)($m['capacity_minutes'] ?? 0)) . ' semanales, pero mantiene '
                . ($n === 1 ? 'una tarea planificada pendiente' : "$n tareas planificadas pendientes") . ' (discrepancia de planificación)', url('/capacity'), null, null, $dedupe);
        }
    }
}
