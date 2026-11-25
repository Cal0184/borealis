<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Validator;
use Borealis\Security\Policy;

/**
 * Monitoreo (1.12): compliance, workload, time and risks, calculated from real records.
 *
 * Everything here is derived: no task gets a new status, no person gets a score. Indicators are
 * computed on demand with set-based queries; only the rule-based alerts are stored (monitor_alerts),
 * once per rule and subject, so they can be acknowledged, escalated and resolved.
 *
 * Who sees what (always checked here, in PHP):
 *  - whoever has «projects.manage_all» (administración): every project of the workspace;
 *  - everyone else: the projects they can view (membership or team). People's workload is shown
 *    only for themselves and for the people of the projects they manage, and hours in projects the
 *    viewer cannot see are added up as «otros proyectos», never listed.
 */
final class Monitoring
{
    public const REASONS = [
        'technical' => 'Problema técnico', 'external' => 'Dependencia externa', 'scope' => 'Cambio de requisitos',
        'estimate' => 'Estimación insuficiente', 'availability' => 'Falta de disponibilidad', 'blocked' => 'Bloqueo por otra tarea', 'other' => 'Otro',
    ];

    /** Derived punctuality of a task (never stored as a status). */
    public const CLASSES = [
        'ontime' => 'Dentro del plazo', 'soon' => 'Próxima a vencer', 'late' => 'Atrasada',
        'done_ontime' => 'Completada dentro del plazo', 'done_late' => 'Completada fuera del plazo', 'nodate' => 'Sin fecha límite',
    ];

    public const HEALTH = [
        'ok' => ['En curso', 'success'], 'attention' => ['Atención', 'warn'], 'critical' => ['Crítico', 'danger'], 'nodata' => ['Sin datos suficientes', ''],
    ];

    public const LOAD = [
        'available' => ['Capacidad disponible', 'info'], 'balanced' => ['Carga equilibrada', 'success'], 'high' => ['Carga elevada', 'warn'],
        'over' => ['Sobrecarga', 'danger'], 'unknown' => ['Sin información suficiente', ''],
    ];

    public const SEVERITY = ['info' => ['Informativa', 'info'], 'warning' => ['Advertencia', 'warn'], 'critical' => ['Crítica', 'danger']];

    public const RULES = [
        'overdue' => 'Tarea vencida', 'due_soon' => 'Tarea importante próxima a vencer', 'sprint_pending' => 'Sprint con compromisos pendientes',
        'overload' => 'Trabajo planificado superior a la capacidad', 'deviation' => 'Desviación entre horas estimadas y registradas',
        'no_estimate' => 'Tarea sin estimación en un sprint activo', 'blocked' => 'Tarea bloqueada', 'dependency' => 'Dependencia en riesgo',
        'over_agreed' => 'Tarea que superó el tiempo acordado', 'estimate_increase' => 'Ampliación importante de la estimación',
    ];

    /** Defaults of the configurable thresholds (Administración › Configuración › Monitoreo). */
    public const DEFAULTS = [
        'soon_days' => 2,               // «próxima a vencer»: due within this many days
        'critical_priority' => 30,      // priority weight from which an overdue task is critical (Alta = 30, Urgente = 40)
        'critical_overdue_days' => 7,   // days late from which an overdue task is critical
        'deviation_pct' => 50,          // logged above the estimate by this % → deviation alert
        'deviation_min_hours' => 1,     // ignore estimates smaller than this
        'load_available' => 60,         // planned / capacity below this % → capacidad disponible
        'load_high' => 85,              // up to this % → equilibrada; above → elevada
        'load_over' => 100,             // above this % → sobrecarga
        'blocked_critical_days' => 3,   // blocked for this many days (and important) → critical
        'escalate_l2_days' => 2,        // an alert not acknowledged after these days reaches the project managers
        'escalate_l3_days' => 3,        // a critical alert not acknowledged after these days reaches the administration
        'escalate_admin' => 1,          // 0 = never notify the administration
        'estimate_increase_pct' => 25,  // 1.14: the worker's estimate is this % above the agreed one → review alert
    ];
    private const LIMITS = [
        'soon_days' => [0, 30], 'critical_priority' => [0, 100], 'critical_overdue_days' => [1, 365], 'deviation_pct' => [5, 1000], 'deviation_min_hours' => [0, 100],
        'load_available' => [1, 99], 'load_high' => [10, 300], 'load_over' => [50, 500], 'blocked_critical_days' => [0, 90],
        'escalate_l2_days' => [0, 60], 'escalate_l3_days' => [0, 90], 'escalate_admin' => [0, 1], 'estimate_increase_pct' => [5, 500],
    ];

    // ------------------------------------------------------------------ settings

    public static function settings(?array $ws = null): array
    {
        $ws ??= Policy::workspace();
        $saved = (array)(($ws['settings']['monitoring'] ?? null) ?: []);
        $out = self::DEFAULTS;
        foreach ($saved as $k => $v) if (isset(self::DEFAULTS[$k]) && is_numeric($v)) $out[$k] = (int)$v;
        return $out;
    }

    public static function saveSettings(array $input): array
    {
        Policy::authorize('workspace.manage');
        $new = self::settings();
        $old = $new;
        $errors = [];
        foreach (self::LIMITS as $k => [$min, $max]) {
            if (!array_key_exists($k, $input)) continue;
            $v = $input[$k];
            if ($k === 'escalate_admin') { $new[$k] = filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 1 : 0; continue; }
            if (!is_numeric($v) || (int)$v != $v || (int)$v < $min || (int)$v > $max) { $errors[$k] = "Escribe un número entero entre $min y $max."; continue; }
            $new[$k] = (int)$v;
        }
        if (!$errors && !($new['load_available'] < $new['load_high'] && $new['load_high'] <= $new['load_over'])) {
            $errors['load_high'] = 'Los umbrales de carga deben ir de menor a mayor: disponible < equilibrada ≤ sobrecarga.';
        }
        if ($errors) throw HttpException::invalid($errors);
        $db = Database::get();
        $settings = json_decode((string)$db->value('SELECT settings FROM workspaces WHERE id = ?', [Policy::wsId()]), true) ?: [];
        $settings['monitoring'] = $new;
        $db->update('workspaces', ['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE), 'updated_at' => now()], 'id = ?', [Policy::wsId()]);
        $changed = array_keys(array_filter($new, fn($v, $k) => ($old[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));
        Activity::log('settings', 'workspace', Policy::wsId(), 'Cambió los umbrales de monitoreo', null, null, [], null,
            ['before' => array_intersect_key($old, array_flip($changed)), 'after' => array_intersect_key($new, array_flip($changed)), 'category' => 'settings']);
        Policy::reset();
        return $new;
    }

    // ------------------------------------------------------------------ scope

    /** [isAdmin, visible project ids, managed project ids] for the current person. */
    public static function scope(): array
    {
        static $cache = [];
        $key = Auth::id() . ':' . Policy::wsId();
        if (isset($cache[$key])) return $cache[$key];
        $db = Database::get();
        [$vis, $vp] = Policy::visibleProjectsSql('p');
        $rows = $db->all("SELECT p.* FROM projects p WHERE p.archived_at IS NULL AND $vis", $vp);
        $admin = Policy::can('projects.manage_all');
        $managed = [];
        foreach ($rows as $p) if (Policy::canProject($p, 'project.manage')) $managed[] = (int)$p['id'];
        return $cache[$key] = ['admin' => $admin, 'projects' => array_map(fn($p) => (int)$p['id'], $rows), 'managed' => $managed, 'rows' => array_column($rows, null, 'id')];
    }

    /** People whose workload the viewer may see: themselves; managers also the people of their projects; the administration everyone. */
    public static function visiblePeople(): array
    {
        $sc = self::scope();
        $db = Database::get();
        if ($sc['admin']) {
            return array_map('intval', $db->column("SELECT user_id FROM workspace_members WHERE workspace_id = ? AND status = 'active'", [Policy::wsId()]));
        }
        $ids = [Auth::id()];
        foreach ($sc['managed'] as $pid) {
            $p = $sc['rows'][$pid];
            foreach (Projects::members($p) as $m) $ids[] = (int)$m['id'];
        }
        return array_values(array_unique($ids));
    }

    /** A project id the viewer can see, or null; anything else is «not found» (no hint that it exists). */
    public static function projectFilter(mixed $raw): ?int
    {
        if ($raw === null || $raw === '' || $raw === '0') return null;
        $id = (int)$raw;
        if (!in_array($id, self::scope()['projects'], true)) throw HttpException::notFound('El proyecto no existe o no tienes acceso.');
        return $id;
    }

    // ------------------------------------------------------------------ punctuality

    public static function classify(array $t, ?array $cfg = null): string
    {
        $cfg ??= self::settings();
        if (!empty($t['completed_at'])) {
            $l = lateness($t);
            if (!$l) return 'nodate';
            return $l['kind'] === 'done-late' ? 'done_late' : 'done_ontime';
        }
        if (empty($t['due_date'])) return 'nodate';
        if ($t['due_date'] < today()) return 'late';
        if ($t['due_date'] <= date('Y-m-d', strtotime(today() . ' +' . (int)$cfg['soon_days'] . ' days'))) return 'soon';
        return 'ontime';
    }

    /** Estimate vs logged: null when there is no valid estimate (never divides by zero). */
    public static function deviation(?float $estimateHours, int $loggedMinutes): ?array
    {
        if ($estimateHours === null || $estimateHours <= 0) return null;
        $logged = round($loggedMinutes / 60, 2);
        $diff = round($logged - $estimateHours, 2);
        return ['estimate' => $estimateHours, 'logged' => $logged, 'hours' => $diff, 'pct' => (int)round($diff / $estimateHours * 100)];
    }

    public static function fmtHours(float $h, bool $sign = false): string
    {
        $s = rtrim(rtrim(number_format(abs($h), 1, ',', '.'), '0'), ',');
        return ($sign ? ($h > 0 ? '+' : ($h < 0 ? '−' : '')) : ($h < 0 ? '−' : '')) . $s . ' h';
    }

    // ------------------------------------------------------------------ explanations and history

    public static function explain(int $taskId, array $input): int
    {
        $t = Tasks::find($taskId);
        Policy::authorizeProject($t['project'], 'task.edit');
        $v = Validator::check($input, ['kind' => 'nullable,in:delay|deviation', 'reason' => 'required,in:' . implode('|', array_keys(self::REASONS)),
            'comment' => 'nullable,string,max:1000', 'corrective_action' => 'nullable,string,max:1000']);
        $kind = $v['kind'] ?: 'delay';
        $id = Database::get()->insert('task_explanations', [
            'task_id' => $taskId, 'kind' => $kind, 'reason' => $v['reason'], 'comment' => $v['comment'] ?: null, 'corrective_action' => $v['corrective_action'] ?: null,
            'due_date' => $t['due_date'] ?: null, 'created_by' => Auth::id(), 'created_at' => now(),
        ]);
        Activity::log('explained', 'task', $taskId, ($kind === 'delay' ? 'Explicó el retraso de ' : 'Explicó la desviación de horas de ') . $t['task_key'] . ': ' . self::REASONS[$v['reason']]
            . ($v['corrective_action'] ? ' · acción: ' . mb_substr($v['corrective_action'], 0, 120) : ''), (int)$t['project_id'], $t['parent_id'] ? (int)$t['parent_id'] : $taskId);
        return $id;
    }

    public static function explanations(int $taskId): array
    {
        return Database::get()->all('SELECT x.*, u.name AS user_name FROM task_explanations x LEFT JOIN users u ON u.id = x.created_by WHERE x.task_id = ? ORDER BY x.created_at DESC, x.id DESC', [$taskId]);
    }

    public static function dueChanges(int $taskId): array
    {
        return Database::get()->all('SELECT d.*, u.name AS user_name FROM task_due_changes d LEFT JOIN users u ON u.id = d.changed_by WHERE d.task_id = ? ORDER BY d.changed_at DESC, d.id DESC', [$taskId]);
    }

    /** Called when a task enters a sprint: once the sprint is running, it is work added after the start. */
    public static function trackSprintTask(int $sprintId, array $t): void
    {
        $db = Database::get();
        if ($db->value("SELECT state FROM sprints WHERE id = ?", [$sprintId]) !== 'active') return;
        $db->run('INSERT IGNORE INTO sprint_commitments (sprint_id, task_id, planned, estimate_hours, added_at) VALUES (?,?,0,?,?)', [$sprintId, (int)$t['id'], $t['estimate_hours'] ?? null, now()]);
    }

    // ------------------------------------------------------------------ project health

    /**
     * Operational status of each visible project (it never replaces the administrative state).
     * Rules, in order: no tasks or no dates → «Sin datos suficientes»; any critical reason → «Crítico»;
     * any attention reason → «Atención»; otherwise «En curso». Every reason is shown.
     */
    public static function projectHealth(?int $onlyProject = null): array
    {
        $sc = self::scope();
        $pids = $onlyProject ? [$onlyProject] : $sc['projects'];
        if (!$pids) return [];
        $cfg = self::settings();
        $db = Database::get();
        $in = Database::in($pids);
        $today = today();
        $soon = date('Y-m-d', strtotime($today . ' +' . (int)$cfg['soon_days'] . ' days'));
        $agg = array_column($db->all("SELECT t.project_id, COUNT(*) AS total, SUM(s.category <> 'done') AS open, SUM(s.category <> 'done' AND t.due_date IS NOT NULL) AS dated,
            SUM(s.category <> 'done' AND t.due_date < ?) AS overdue, SUM(s.category <> 'done' AND t.due_date < ? AND COALESCE(pr.weight, 0) >= ?) AS overdue_high,
            SUM(s.category <> 'done' AND t.due_date <= ?) AS overdue_long, SUM(s.category <> 'done' AND t.is_blocked = 1) AS blocked,
            SUM(s.category <> 'done' AND t.due_date BETWEEN ? AND ? AND COALESCE(pr.weight, 0) >= ?) AS soon_high
            FROM tasks t JOIN statuses s ON s.id = t.status_id LEFT JOIN priorities pr ON pr.id = t.priority_id
            WHERE t.project_id IN ($in) AND t.deleted_at IS NULL AND t.parent_id IS NULL GROUP BY t.project_id",
            array_merge([$today, $today, $cfg['critical_priority'], date('Y-m-d', strtotime($today . ' -' . (int)$cfg['critical_overdue_days'] . ' days')), $today, $soon, $cfg['critical_priority']], $pids)), null, 'project_id');
        $blocking = array_column($db->all("SELECT a.project_id, COUNT(DISTINCT a.id) AS n FROM task_links l JOIN tasks a ON a.id = l.source_task_id JOIN statuses sa ON sa.id = a.status_id
            JOIN tasks b ON b.id = l.target_task_id JOIN statuses sb ON sb.id = b.status_id
            WHERE l.link_type = 'blocks' AND a.project_id IN ($in) AND a.deleted_at IS NULL AND b.deleted_at IS NULL AND sa.category <> 'done' AND sb.category <> 'done' AND (a.is_blocked = 1 OR a.due_date < ?)
            GROUP BY a.project_id", array_merge($pids, [$today])), 'n', 'project_id');
        $sprintLate = array_column($db->all("SELECT project_id, COUNT(*) AS n FROM sprints WHERE project_id IN ($in) AND state = 'active' AND end_date < ? GROUP BY project_id", array_merge($pids, [$today])), 'n', 'project_id');
        $alerts = array_column($db->all("SELECT project_id, COUNT(*) AS n FROM monitor_alerts WHERE project_id IN ($in) AND state <> 'resolved' AND severity <> 'info' GROUP BY project_id", $pids), 'n', 'project_id');
        $out = [];
        foreach ($pids as $pid) {
            $p = $sc['rows'][$pid] ?? $db->one('SELECT * FROM projects WHERE id = ?', [$pid]);
            if (!$p) continue;
            $a = array_map('intval', $agg[$pid] ?? ['total' => 0, 'open' => 0, 'dated' => 0, 'overdue' => 0, 'overdue_high' => 0, 'overdue_long' => 0, 'blocked' => 0, 'soon_high' => 0]);
            $crit = $warn = [];
            $n = fn(int $x, string $one, string $many) => $x === 1 ? $one : str_replace('%d', (string)$x, $many);
            if ($a['total'] === 0) {
                $status = 'nodata'; $why = ['Aún no tiene tareas.'];
            } elseif ($a['open'] > 0 && $a['dated'] === 0 && !$p['due_date']) {
                $status = 'nodata'; $why = [$n($a['open'], 'La tarea abierta no tiene fecha límite', 'Ninguna de sus %d tareas abiertas tiene fecha límite') . ' y el proyecto no tiene entrega: no se puede evaluar el cumplimiento.'];
            } else {
                if ($p['due_date'] && $p['due_date'] < $today && $a['open'] > 0 && $p['state'] !== 'completed') $crit[] = 'La entrega del proyecto (' . fmt_date($p['due_date'], true) . ') ya pasó y ' . $n($a['open'], 'queda 1 tarea abierta', 'quedan %d tareas abiertas') . '.';
                if ($a['overdue_high']) $crit[] = $n($a['overdue_high'], '1 tarea de prioridad alta o urgente vencida', '%d tareas de prioridad alta o urgente vencidas') . '.';
                if ($a['overdue_long']) $crit[] = $n($a['overdue_long'], '1 tarea con', '%d tareas con') . ' ' . (int)$cfg['critical_overdue_days'] . ' días o más de retraso.';
                if (($blocking[$pid] ?? 0) > 0) $crit[] = $n((int)$blocking[$pid], '1 tarea bloqueada o vencida frena a otras', '%d tareas bloqueadas o vencidas frenan a otras') . ' (dependencias).';
                if ($a['open'] >= 4 && $a['overdue'] * 100 >= $a['open'] * 25) $crit[] = "{$a['overdue']} de {$a['open']} tareas abiertas están vencidas (25 % o más).";
                if ($a['overdue'] && !$crit) $warn[] = $n($a['overdue'], '1 tarea vencida', '%d tareas vencidas') . '.';
                if ($a['blocked']) $warn[] = $n($a['blocked'], '1 tarea bloqueada', '%d tareas bloqueadas') . '.';
                if ($a['soon_high']) $warn[] = $n($a['soon_high'], '1 tarea importante vence', '%d tareas importantes vencen') . ' en los próximos ' . (int)$cfg['soon_days'] . ' días.';
                if (($sprintLate[$pid] ?? 0) > 0) $warn[] = 'El sprint activo pasó su fecha de fin sin cerrarse.';
                if (($alerts[$pid] ?? 0) > 0 && !$crit && !$warn) $warn[] = $n((int)$alerts[$pid], '1 alerta abierta', '%d alertas abiertas') . ' en Riesgos.';
                $status = $crit ? 'critical' : ($warn ? 'attention' : 'ok');
                $why = $crit ? array_merge($crit, $warn) : ($warn ?: [$a['open'] ? $n($a['open'], '1 tarea abierta', '%d tareas abiertas') . ', ninguna vencida ni bloqueada.' : 'Sin tareas abiertas.']);
            }
            $out[] = ['project' => $p, 'status' => $status, 'why' => $why, 'counts' => $a];
        }
        $order = ['critical' => 0, 'attention' => 1, 'nodata' => 2, 'ok' => 3];
        usort($out, fn($x, $y) => [$order[$x['status']], $x['project']['name']] <=> [$order[$y['status']], $y['project']['name']]);
        return $out;
    }

    // ------------------------------------------------------------------ compliance

    /** Tasks of the visible projects with their punctuality class (top-level and subtasks with their own date). */
    public static function compliance(array $f): array
    {
        $cfg = self::settings();
        $q = ['with_subtasks' => 1, 'limit' => 3000, 'sort' => 'due'];
        if (!empty($f['project_id'])) $q['project_id'] = (int)$f['project_id'];
        if (!empty($f['sprint_id'])) $q['sprint_id'] = (string)(int)$f['sprint_id'];
        if (!empty($f['mine'])) $q['assignee'] = 'me';
        $rows = Tasks::query($q);
        $from = $f['from'] ?? null; $to = $f['to'] ?? null;
        $counts = array_fill_keys(array_keys(self::CLASSES), 0) + ['blocked' => 0, 'open' => 0, 'done' => 0];
        $list = [];
        $want = $f['class'] ?? 'late';
        foreach ($rows as $t) {
            if ($t['parent_id'] && !$t['due_date']) continue; // a subtask counts only when it has its own date
            $c = self::classify($t, $cfg);
            $done = !empty($t['completed_at']);
            if ($from || $to) {
                // period: finished tasks by completion date, open ones by due date
                $d = $done ? local_date('Y-m-d', (string)$t['completed_at']) : $t['due_date'];
                if (!$d || ($from && $d < $from) || ($to && $d > $to)) { if ($done || $c !== 'late') continue; }
            }
            $counts[$c]++;
            $counts[$done ? 'done' : 'open']++;
            if (!$done && $t['is_blocked']) $counts['blocked']++;
            $t['class'] = $c;
            $match = $want === 'open' ? !$done : ($want === 'done' ? $done : ($want === 'blocked' ? (!$done && $t['is_blocked']) : $c === $want));
            if ($match) $list[] = $t;
        }
        if ($list) {
            $ids = array_map(fn($t) => (int)$t['id'], $list);
            $in = Database::in($ids);
            $ex = Database::get()->all("SELECT x.task_id, x.reason, x.corrective_action, x.created_at FROM task_explanations x WHERE x.task_id IN ($in) ORDER BY x.created_at DESC, x.id DESC", $ids);
            $byTask = [];
            foreach ($ex as $e) $byTask[(int)$e['task_id']] ??= $e;
            $dc = array_column(Database::get()->all("SELECT task_id, COUNT(*) AS n FROM task_due_changes WHERE task_id IN ($in) GROUP BY task_id", $ids), 'n', 'task_id');
            foreach ($list as &$t) { $t['last_explanation'] = $byTask[(int)$t['id']] ?? null; $t['due_changes'] = (int)($dc[$t['id']] ?? 0); }
            unset($t);
            if ($want === 'late') usort($list, fn($a, $b) => strcmp((string)$a['due_date'], (string)$b['due_date']));
        }
        return ['counts' => $counts, 'tasks' => $list, 'class' => $want];
    }

    // ------------------------------------------------------------------ time

    public static function times(array $f): array
    {
        $cfg = self::settings();
        $q = ['with_subtasks' => 0, 'limit' => 3000, 'sort' => 'updated'];
        if (!empty($f['project_id'])) $q['project_id'] = (int)$f['project_id'];
        if (!empty($f['sprint_id'])) $q['sprint_id'] = (string)(int)$f['sprint_id'];
        if (!empty($f['mine'])) $q['assignee'] = 'me';
        $rows = [];
        $donePcts = [];
        $sum = ['estimate' => 0.0, 'logged' => 0.0, 'compared' => 0, 'over' => 0, 'no_estimate' => 0, 'incomplete' => 0];
        foreach (Tasks::query($q) as $t) {
            $done = !empty($t['completed_at']);
            if ($done && (!empty($f['from']) || !empty($f['to']))) {
                $d = local_date('Y-m-d', (string)$t['completed_at']);
                if ((!empty($f['from']) && $d < $f['from']) || (!empty($f['to']) && $d > $f['to'])) continue;
            }
            $est = $t['estimate_hours'] !== null ? (float)$t['estimate_hours'] : null;
            $min = (int)$t['logged_minutes'];
            if ($est === null && $min === 0) continue;
            $dev = self::deviation($est, $min);
            $flag = null;
            if ($dev === null) { $flag = 'no_estimate'; $sum['no_estimate']++; }
            elseif ($done && $min === 0) { $flag = 'incomplete'; $sum['incomplete']++; }
            if ($dev && $flag === null) {
                $sum['compared']++;
                if ($done) { $sum['estimate'] += $est; $sum['logged'] += $dev['logged']; $donePcts[] = $dev['pct']; }
                if ($est >= $cfg['deviation_min_hours'] && $dev['pct'] >= $cfg['deviation_pct']) $sum['over']++;
            }
            $rows[] = $t + ['dev' => $dev, 'flag' => $flag, 'done' => $done];
        }
        // The median, not the mean: one extreme task does not distort the indicator.
        sort($donePcts);
        $n = count($donePcts);
        $median = $n ? ($n % 2 ? $donePcts[intdiv($n, 2)] : (int)round(($donePcts[$n / 2 - 1] + $donePcts[$n / 2]) / 2)) : null;
        usort($rows, fn($a, $b) => (($b['dev']['pct'] ?? -10000) <=> ($a['dev']['pct'] ?? -10000)));
        return ['rows' => $rows, 'summary' => $sum + ['median_pct' => $median, 'done_compared' => $n]];
    }

    // ------------------------------------------------------------------ workload

    /** Monday of the week of $date. */
    public static function week(?string $date = null): string
    {
        return CapacityAlerts::weekStart($date);
    }

    /**
     * Planned load per person and week: the remaining estimated hours of each open task (remaining
     * hours if written, otherwise estimate − logged), shared among its responsible people, spread
     * evenly over the working days between its start (or today) and its due date. A task already
     * overdue counts in the current week. Tasks without a date or an estimate are listed apart:
     * they are never given invented hours, which is why a week can be «sin información suficiente».
     */
    public static function workload(string $from, int $weeks = 4, ?int $projectId = null, ?int $sprintId = null, ?array $onlyPeople = null, bool $global = false): array
    {
        $cfg = self::settings();
        $db = Database::get();
        $ws = Policy::wsId();
        $sc = $global ? ['projects' => []] : self::scope();
        $people = $onlyPeople ?? self::visiblePeople();
        if (!$people) return ['weeks' => [], 'people' => [], 'projects' => []];
        $weekList = [];
        for ($i = 0; $i < $weeks; $i++) $weekList[] = date('Y-m-d', strtotime($from . ' 12:00:00 +' . ($i * 7) . ' days'));
        $lastDay = date('Y-m-d', strtotime(end($weekList) . ' 12:00:00 +6 days'));
        $inP = Database::in($people);
        $members = $db->all("SELECT u.id, u.name, u.avatar_color, wm.weekly_hours FROM workspace_members wm JOIN users u ON u.id = wm.user_id
            WHERE wm.workspace_id = ? AND wm.status = 'active' AND u.id IN ($inP) ORDER BY u.name", array_merge([$ws], $people));
        $extras = [];
        foreach ($db->all("SELECT user_id, week_start, SUM(minutes) AS m FROM capacity_extras WHERE workspace_id = ? AND user_id IN ($inP) AND week_start BETWEEN ? AND ? GROUP BY user_id, week_start",
            array_merge([$ws], $people, [$weekList[0], end($weekList)])) as $e) $extras[(int)$e['user_id']][$e['week_start']] = (int)$e['m'] / 60;
        $where = "p.workspace_id = ? AND p.archived_at IS NULL AND t.deleted_at IS NULL AND t.parent_id IS NULL AND s.category <> 'done'";
        $params = [$ws];
        if ($projectId) { $where .= ' AND t.project_id = ?'; $params[] = $projectId; }
        if ($sprintId) { $where .= ' AND t.sprint_id = ?'; $params[] = $sprintId; }
        $tasks = $db->all("SELECT t.id, t.project_id, t.number, t.title, t.assignee_id, t.estimate_hours, t.remaining_hours, t.start_date, t.due_date, t.sprint_id, p.project_key, p.name AS project_name,
            sp.start_date AS sprint_start, sp.end_date AS sprint_end,
            (SELECT COALESCE(SUM(te.minutes), 0) FROM time_entries te WHERE te.task_id = t.id AND te.deleted_at IS NULL) AS logged_minutes
            FROM tasks t JOIN projects p ON p.id = t.project_id JOIN statuses s ON s.id = t.status_id LEFT JOIN sprints sp ON sp.id = t.sprint_id
            WHERE $where AND (t.assignee_id IN ($inP) OR EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id = t.id AND ta.user_id IN ($inP)))",
            array_merge($params, $people, $people));
        $co = [];
        if ($tasks) {
            $tids = array_map(fn($t) => (int)$t['id'], $tasks);
            foreach ($db->all('SELECT task_id, user_id FROM task_assignees WHERE task_id IN (' . Database::in($tids) . ')', $tids) as $r) $co[(int)$r['task_id']][] = (int)$r['user_id'];
        }
        $today = today();
        $curWeek = self::week($today);
        $load = $byProject = $notes = $taskLoad = [];
        foreach ($tasks as $t) {
            $resp = array_values(array_unique(array_filter(array_merge([(int)$t['assignee_id']], $co[(int)$t['id']] ?? []))));
            $mine = array_values(array_intersect($resp, $people));
            if (!$mine) continue;
            $key = $t['project_key'] . '-' . $t['number'];
            if ($t['remaining_hours'] !== null) $hours = (float)$t['remaining_hours'];
            elseif ($t['estimate_hours'] !== null) $hours = max(0.0, (float)$t['estimate_hours'] - (int)$t['logged_minutes'] / 60);
            else { foreach ($mine as $u) $notes[$u]['no_estimate'][] = $key; continue; }
            $end = $t['due_date'] ?: $t['sprint_end'];
            if (!$end) { foreach ($mine as $u) { $notes[$u]['no_date'][] = $key; $notes[$u]['no_date_hours'] = ($notes[$u]['no_date_hours'] ?? 0) + $hours / count($resp); } continue; }
            if ($hours <= 0) continue;
            $start = max($today, (string)($t['start_date'] ?: ($t['sprint_start'] ?: $today)));
            $perDay = [];
            if ($end < $today) { $perDay[$curWeek] = $hours; foreach ($mine as $u) $notes[$u]['overdue'][] = $key; }
            else {
                if ($start > $end) $start = $end;
                $days = [];
                for ($d = $start, $i = 0; $d <= $end && $i < 400; $d = date('Y-m-d', strtotime($d . ' 12:00:00 +1 day')), $i++) if ((int)date('N', strtotime($d)) <= 5) $days[] = $d;
                if (!$days) $days = [$end];
                foreach ($days as $d) { $w = self::week($d); $perDay[$w] = ($perDay[$w] ?? 0) + $hours / count($days); }
            }
            $share = 1 / count($resp);
            foreach ($perDay as $w => $h) {
                if (!in_array($w, $weekList, true)) continue;
                foreach ($mine as $u) {
                    $load[$u][$w] = ($load[$u][$w] ?? 0) + $h * $share;
                    $pk = ($global || in_array((int)$t['project_id'], $sc['projects'], true)) ? (int)$t['project_id'] : 0;
                    $byProject[$u][$w][$pk] = ($byProject[$u][$w][$pk] ?? 0) + $h * $share;
                    $taskLoad[$u][$w][] = ['key' => $key, 'title' => $pk ? $t['title'] : null, 'project_id' => $pk, 'hours' => round($h * $share, 2)];
                }
            }
        }
        $rows = [];
        foreach ($members as $m) {
            $uid = (int)$m['id'];
            $cells = [];
            foreach ($weekList as $w) {
                $cap = $m['weekly_hours'] !== null && (float)$m['weekly_hours'] > 0 ? (float)$m['weekly_hours'] + ($extras[$uid][$w] ?? 0) : null;
                $planned = round($load[$uid][$w] ?? 0, 2);
                $cls = self::loadClass($planned, $cap, !empty($notes[$uid]['no_estimate']) && $w === $curWeek, $cfg); // unestimated work weighs on the current week only
                $cells[$w] = ['capacity' => $cap, 'planned' => $planned, 'class' => $cls, 'over' => $cap !== null ? round($planned - $cap, 2) : null,
                    'pct' => $cap ? (int)round($planned / $cap * 100) : null, 'projects' => $byProject[$uid][$w] ?? [], 'tasks' => $taskLoad[$uid][$w] ?? []];
            }
            $rows[] = ['user' => $m, 'weeks' => $cells, 'notes' => $notes[$uid] ?? []];
        }
        return ['weeks' => $weekList, 'people' => $rows, 'last_day' => $lastDay];
    }

    public static function loadClass(float $planned, ?float $cap, bool $unestimated, array $cfg): string
    {
        if ($cap === null) return 'unknown';
        if ($planned <= 0 && $unestimated) return 'unknown';
        $pct = $planned / $cap * 100;
        if ($pct > $cfg['load_over']) return 'over';
        if ($pct > $cfg['load_high']) return 'high';
        if ($pct >= $cfg['load_available']) return 'balanced';
        return 'available';
    }

    /** Planned hours per visible project and week (sum of the people's shares). */
    public static function workloadByProject(array $wl): array
    {
        $sc = self::scope();
        $out = [];
        foreach ($wl['people'] as $r) foreach ($r['weeks'] as $w => $c) foreach ($c['projects'] as $pid => $h) {
            if (!$pid) continue;
            $out[$pid]['project'] = $sc['rows'][$pid];
            $out[$pid]['weeks'][$w] = round(($out[$pid]['weeks'][$w] ?? 0) + $h, 2);
        }
        return array_values($out);
    }

    // ------------------------------------------------------------------ sprints

    /** Active sprints of the visible projects with their commitment, progress, hours and risks. */
    public static function sprints(?int $projectId = null): array
    {
        $sc = self::scope();
        $pids = $projectId ? [$projectId] : $sc['projects'];
        if (!$pids) return [];
        $db = Database::get();
        $in = Database::in($pids);
        $out = [];
        foreach ($db->all("SELECT * FROM sprints WHERE project_id IN ($in) AND state = 'active' ORDER BY end_date", $pids) as $s) {
            $out[] = self::sprintSummary($s);
        }
        return $out;
    }

    public static function sprintSummary(array $s): array
    {
        $db = Database::get();
        $sid = (int)$s['id'];
        $active = $s['state'] === 'active';
        $sc = $db->all("SELECT sc.*, t.number, t.title, t.is_blocked, t.estimate_hours AS est_now, t.sprint_id AS cur_sprint, st.category,
            (SELECT COALESCE(SUM(te.minutes), 0) FROM time_entries te WHERE te.task_id = t.id AND te.deleted_at IS NULL) AS logged_minutes
            FROM sprint_commitments sc JOIN tasks t ON t.id = sc.task_id JOIN statuses st ON st.id = t.status_id WHERE sc.sprint_id = ? AND t.deleted_at IS NULL", [$sid]);
        $r = ['planned' => 0, 'added' => 0, 'unknown' => 0, 'done' => 0, 'pending' => 0, 'moved' => 0, 'backlog' => 0, 'removed' => 0, 'blocked' => 0, 'estimate' => 0.0, 'logged' => 0.0, 'over' => []];
        foreach ($sc as $c) {
            $r[$c['planned'] === null ? 'unknown' : ((int)$c['planned'] ? 'planned' : 'added')]++;
            $in = $active ? (int)$c['cur_sprint'] === $sid : true;
            if ($active && !$in) { $r['removed']++; continue; }
            if ($active) { if ($c['category'] === 'done') $r['done']++; else { $r['pending']++; if ($c['is_blocked']) $r['blocked']++; } }
            else {
                if ($c['outcome'] === 'done') $r['done']++; elseif ($c['outcome'] === 'moved') $r['moved']++; elseif ($c['outcome'] === 'pending') $r['backlog']++; else $r['removed']++;
                if ($c['outcome'] !== 'done' && $c['is_blocked']) $r['blocked']++;
            }
            $est = $c['est_now'] !== null ? (float)$c['est_now'] : ($c['estimate_hours'] !== null ? (float)$c['estimate_hours'] : null);
            if ($est !== null) $r['estimate'] += $est;
            $r['logged'] += (int)$c['logged_minutes'] / 60;
            $dev = self::deviation($est, (int)$c['logged_minutes']);
            if ($dev && $dev['pct'] >= self::settings()['deviation_pct'] && $est >= self::settings()['deviation_min_hours']) $r['over'][] = ['task_id' => (int)$c['task_id'], 'title' => $c['title'], 'dev' => $dev];
        }
        $p = Projects::find((int)$s['project_id']);
        $risks = [];
        if ($active) {
            if ($s['end_date'] && $s['end_date'] < today() && $r['pending']) $risks[] = 'La fecha de fin (' . fmt_date($s['end_date'], true) . ') ya pasó con ' . $r['pending'] . ' pendientes.';
            if ($r['blocked']) $risks[] = $r['blocked'] . ($r['blocked'] === 1 ? ' tarea bloqueada.' : ' tareas bloqueadas.');
            if ($s['end_date'] && $s['end_date'] >= today()) {
                $left = (int)((strtotime($s['end_date']) - strtotime(today())) / 86400);
                $total = $r['done'] + $r['pending'];
                if ($left <= 2 && $total && $r['pending'] * 2 > $total) $risks[] = "Quedan $left días y más de la mitad del trabajo está pendiente.";
            }
            if (array_filter(Capacity::people($s, $p), fn($x) => $x['over'])) $risks[] = 'Hay personas con más horas asignadas que disponibles en el sprint.';
        }
        return ['sprint' => $s, 'project' => $p, 'counts' => $r, 'risks' => $risks];
    }

    /** Sprints closed in a period (for the report). */
    public static function closedSprints(?int $projectId, ?string $from, ?string $to): array
    {
        $sc = self::scope();
        $pids = $projectId ? [$projectId] : $sc['projects'];
        if (!$pids) return [];
        $params = $pids;
        $w = 'project_id IN (' . Database::in($pids) . ") AND state = 'completed'";
        if ($from) { $w .= ' AND completed_at >= ?'; $params[] = $from . ' 00:00:00'; }
        if ($to) { $w .= ' AND completed_at <= ?'; $params[] = $to . ' 23:59:59'; }
        return array_map([self::class, 'sprintSummary'], Database::get()->all("SELECT * FROM sprints WHERE $w ORDER BY completed_at DESC LIMIT 30", $params));
    }

    // ------------------------------------------------------------------ dependencies

    /** Open tasks B that depend on a task A («A bloquea a B») that is blocked, overdue or due after B. */
    public static function dependencyRisks(?array $pids = null, ?int $wsId = null): array
    {
        $db = Database::get();
        $w = '';
        $params = [];
        if ($pids !== null) { if (!$pids) return []; $w = ' AND b.project_id IN (' . Database::in($pids) . ')'; $params = $pids; }
        if ($wsId !== null) { $w .= ' AND pb.workspace_id = ?'; $params[] = $wsId; }
        $rows = $db->all("SELECT b.id AS b_id, b.project_id AS b_project, b.title AS b_title, b.due_date AS b_due, CONCAT(pb.project_key, '-', b.number) AS b_key, b.assignee_id AS b_assignee,
            a.id AS a_id, a.title AS a_title, a.due_date AS a_due, a.is_blocked AS a_blocked, a.blocked_reason AS a_reason, CONCAT(pa.project_key, '-', a.number) AS a_key
            FROM task_links l JOIN tasks a ON a.id = l.source_task_id JOIN tasks b ON b.id = l.target_task_id JOIN projects pa ON pa.id = a.project_id JOIN projects pb ON pb.id = b.project_id
            JOIN statuses sa ON sa.id = a.status_id JOIN statuses sb ON sb.id = b.status_id
            WHERE l.link_type = 'blocks' AND a.deleted_at IS NULL AND b.deleted_at IS NULL AND sa.category <> 'done' AND sb.category <> 'done' AND pb.archived_at IS NULL $w LIMIT 2000", $params);
        $out = [];
        $today = today();
        foreach ($rows as $r) {
            $why = null;
            if ($r['a_blocked']) $why = "{$r['a_key']} (de la que depende) está bloqueada" . ($r['a_reason'] ? ": {$r['a_reason']}" : '') . '.';
            elseif ($r['a_due'] && $r['a_due'] < $today) $why = "{$r['a_key']} (de la que depende) está vencida desde el " . fmt_date($r['a_due'], true) . '.';
            elseif ($r['a_due'] && $r['b_due'] && $r['a_due'] > $r['b_due']) $why = "{$r['a_key']} (de la que depende) vence el " . fmt_date($r['a_due'], true) . ', después que esta (' . fmt_date($r['b_due'], true) . ').';
            if ($why) $out[] = $r + ['why' => $why];
        }
        return $out;
    }

    // ------------------------------------------------------------------ alerts (rule engine)

    /** Runs the rules if the last run is older than $maxAge seconds (page visits; cron always runs them). */
    public static function scanIfStale(int $maxAge = 600): void
    {
        $last = Database::get()->value('SELECT ran_at FROM monitor_runs WHERE workspace_id = ?', [Policy::wsId()]);
        if ($last && strtotime($last . ' UTC') > time() - $maxAge) return;
        try { self::scan(Policy::wsId()); } catch (\Throwable $e) { error_log('[borealis] monitoring: ' . $e->getMessage()); }
    }

    /**
     * Evaluates every rule for a workspace. Each condition has a stable key, so running it again
     * (cron every 5 minutes, two visits at once) updates the same alert instead of creating another.
     * Alerts whose condition disappeared are resolved; notifications go out once per alert and level.
     */
    public static function scan(int $wsId): array
    {
        $lockFile = storage_path('monitor-') . $wsId . '.lock';
        $lock = @fopen($lockFile, 'c');
        if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) return ['skipped' => true];
        $db = Database::get();
        $run = now();
        $stats = ['seen' => 0, 'new' => 0, 'resolved' => 0, 'notified' => 0];
        try {
            $ws = $db->one('SELECT id, name, settings FROM workspaces WHERE id = ?', [$wsId]);
            $ws['settings'] = json_decode((string)$ws['settings'], true) ?: [];
            $cfg = self::settings($ws);
            $found = self::conditions($wsId, $cfg);
            $outbox = [];
            foreach ($found as $c) {
                $stats['seen']++;
                $row = $db->one('SELECT * FROM monitor_alerts WHERE workspace_id = ? AND dedupe_key = ?', [$wsId, $c['key']]);
                $fields = ['severity' => $c['severity'], 'project_id' => $c['project_id'] ?? null, 'task_id' => $c['task_id'] ?? null, 'sprint_id' => $c['sprint_id'] ?? null,
                    'user_id' => $c['user_id'] ?? null, 'message' => mb_substr($c['message'], 0, 255), 'explanation' => mb_substr($c['explanation'], 0, 1000), 'last_seen' => $run];
                if (!$row) {
                    $id = $db->insert('monitor_alerts', $fields + ['workspace_id' => $wsId, 'rule' => $c['rule'], 'dedupe_key' => $c['key'], 'level' => $c['level'], 'state' => 'open', 'first_seen' => $run]);
                    $row = $db->one('SELECT * FROM monitor_alerts WHERE id = ?', [$id]);
                    $stats['new']++;
                } else {
                    if ($row['state'] === 'resolved') $fields += ['state' => 'open', 'level' => $c['level'], 'first_seen' => $run, 'resolved_at' => null, 'escalated_at' => null, 'ack_by' => null, 'ack_at' => null, 'ack_note' => null];
                    $db->update('monitor_alerts', $fields, 'id = ?', [$row['id']]);
                    $row = $db->one('SELECT * FROM monitor_alerts WHERE id = ?', [$row['id']]);
                }
                // escalation (only while nobody acknowledged it)
                if ($row['state'] === 'open') {
                    $age = (time() - strtotime($row['first_seen'] . ' UTC')) / 86400;
                    $level = (int)$row['level'];
                    $target = $level;
                    if ($level < 2 && ($row['severity'] === 'critical' || $age >= $cfg['escalate_l2_days']) && $row['project_id']) $target = 2;
                    if ($row['severity'] === 'critical' && $cfg['escalate_admin'] && $age >= $cfg['escalate_l3_days']) $target = 3;
                    if ($target > $level) $db->update('monitor_alerts', ['level' => $target, 'escalated_at' => $run], 'id = ?', [$row['id']]);
                    if ($row['severity'] !== 'info') {
                        for ($l = 1; $l <= $target; $l++) {
                            if ($l === 1 && !empty($c['l1_by_existing'])) continue; // already announced by the existing notices (vencida)
                            foreach (self::recipients($l, $c, $row, $wsId) as $uid) $outbox[$uid][] = [$row, $l];
                        }
                    }
                }
            }
            // resolved: what was not found in this run
            // (by id, not by time: two runs can fall in the same second)
            $seen = [];
            foreach ($found as $c) $seen[$c['key']] = true;
            $stale = [];
            foreach ($db->all("SELECT id, dedupe_key FROM monitor_alerts WHERE workspace_id = ? AND state <> 'resolved'", [$wsId]) as $x) if (!isset($seen[$x['dedupe_key']])) $stale[] = (int)$x['id'];
            foreach (array_chunk($stale, 500) as $ids) {
                $stats['resolved'] += $db->run("UPDATE monitor_alerts SET state = 'resolved', resolved_at = ? WHERE id IN (" . Database::in($ids) . ')', array_merge([$run], $ids))->rowCount();
            }
            $stats['notified'] = self::deliver($outbox, $wsId);
            $db->run('INSERT INTO monitor_runs (workspace_id, ran_at, ok, error) VALUES (?,?,1,NULL) ON DUPLICATE KEY UPDATE ran_at = VALUES(ran_at), ok = 1, error = NULL', [$wsId, $run]);
        } catch (\Throwable $e) {
            $db->run('INSERT INTO monitor_runs (workspace_id, ran_at, ok, error) VALUES (?,?,0,?) ON DUPLICATE KEY UPDATE ran_at = VALUES(ran_at), ok = 0, error = VALUES(error)', [$wsId, $run, mb_substr($e->getMessage(), 0, 500)]);
            throw $e;
        } finally {
            if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
        }
        return $stats;
    }

    /** Every rule, as explicit conditions: key, rule, severity, level, subject, message and why. */
    private static function conditions(int $wsId, array $cfg): array
    {
        $db = Database::get();
        $today = today();
        $out = [];
        $base = "FROM tasks t JOIN projects p ON p.id = t.project_id JOIN statuses s ON s.id = t.status_id LEFT JOIN priorities pr ON pr.id = t.priority_id
            WHERE p.workspace_id = ? AND p.archived_at IS NULL AND t.deleted_at IS NULL AND s.category <> 'done'";
        $blocksOthers = array_flip(array_map('intval', $db->column("SELECT DISTINCT l.source_task_id FROM task_links l JOIN tasks b ON b.id = l.target_task_id JOIN statuses sb ON sb.id = b.status_id
            JOIN projects p ON p.id = b.project_id WHERE l.link_type = 'blocks' AND b.deleted_at IS NULL AND sb.category <> 'done' AND p.workspace_id = ?", [$wsId])));
        $key = fn($t) => $t['project_key'] . '-' . $t['number'];

        // Cumplimiento: vencidas (warning; critical when important, very late or blocking others)
        foreach ($db->all("SELECT t.id, t.number, t.title, t.due_date, t.project_id, t.assignee_id, p.project_key, COALESCE(pr.weight, 0) AS w, pr.name AS prio $base AND t.due_date < ? LIMIT 2000", [$wsId, $today]) as $t) {
            $days = (int)((strtotime($today) - strtotime($t['due_date'])) / 86400);
            $why = [];
            if ($t['w'] >= $cfg['critical_priority']) $why[] = "prioridad {$t['prio']}";
            if ($days >= $cfg['critical_overdue_days']) $why[] = "$days días de retraso (umbral: {$cfg['critical_overdue_days']})";
            if (isset($blocksOthers[(int)$t['id']])) $why[] = 'otras tareas dependen de ella';
            $out[] = ['key' => 'overdue:' . $t['id'], 'rule' => 'overdue', 'severity' => $why ? 'critical' : 'warning', 'level' => 1, 'l1_by_existing' => true,
                'project_id' => (int)$t['project_id'], 'task_id' => (int)$t['id'],
                'message' => $key($t) . " «{$t['title']}» lleva " . ($days === 1 ? '1 día' : "$days días") . ' de retraso',
                'explanation' => 'Venció el ' . fmt_date($t['due_date'], true) . ' sin completarse. ' . ($why ? 'Es crítica por: ' . implode(', ', $why) . '.' : 'Advertencia: no es de prioridad alta, no supera el umbral de días ni frena a otras tareas.')];
        }
        // Cumplimiento: importante próxima a vencer (informativa)
        $soon = date('Y-m-d', strtotime($today . ' +' . (int)$cfg['soon_days'] . ' days'));
        foreach ($db->all("SELECT t.id, t.number, t.title, t.due_date, t.project_id, p.project_key, pr.name AS prio $base AND t.due_date BETWEEN ? AND ? AND COALESCE(pr.weight, 0) >= ? LIMIT 2000", [$wsId, $today, $soon, $cfg['critical_priority']]) as $t) {
            $out[] = ['key' => 'due_soon:' . $t['id'] . ':' . $t['due_date'], 'rule' => 'due_soon', 'severity' => 'info', 'level' => 1, 'project_id' => (int)$t['project_id'], 'task_id' => (int)$t['id'],
                'message' => $key($t) . " «{$t['title']}» (prioridad {$t['prio']}) vence el " . fmt_date($t['due_date'], true),
                'explanation' => 'Tarea de prioridad alta o urgente que vence en los próximos ' . (int)$cfg['soon_days'] . ' días. Los recordatorios de siempre ya avisan a quien es responsable.'];
        }
        // Cumplimiento: sprint con la fecha de fin pasada y compromisos pendientes
        foreach ($db->all("SELECT sp.*, p.name AS project_name, (SELECT COUNT(*) FROM tasks t JOIN statuses s ON s.id = t.status_id WHERE t.sprint_id = sp.id AND t.deleted_at IS NULL AND t.parent_id IS NULL AND s.category <> 'done') AS pending
            FROM sprints sp JOIN projects p ON p.id = sp.project_id WHERE p.workspace_id = ? AND p.archived_at IS NULL AND sp.state = 'active' AND sp.end_date < ?", [$wsId, $today]) as $s) {
            if (!(int)$s['pending']) continue;
            $out[] = ['key' => 'sprint_pending:' . $s['id'], 'rule' => 'sprint_pending', 'severity' => 'warning', 'level' => 2, 'project_id' => (int)$s['project_id'], 'sprint_id' => (int)$s['id'],
                'message' => "«{$s['name']}» ({$s['project_name']}) pasó su fecha de fin con {$s['pending']} " . ((int)$s['pending'] === 1 ? 'tarea pendiente' : 'tareas pendientes'),
                'explanation' => 'El sprint terminaba el ' . fmt_date($s['end_date'], true) . ' y sigue abierto. Ciérralo y decide qué pasa con lo pendiente (otro sprint o backlog): nada se completa solo.'];
        }
        // Bloqueos (warning; critical when blocked for days and important or blocking others)
        foreach ($db->all("SELECT t.id, t.number, t.title, t.blocked_reason, t.blocked_at, t.project_id, p.project_key, COALESCE(pr.weight, 0) AS w $base AND t.is_blocked = 1 LIMIT 2000", [$wsId]) as $t) {
            $days = $t['blocked_at'] ? (int)floor((time() - strtotime($t['blocked_at'] . ' UTC')) / 86400) : null;
            $important = $t['w'] >= $cfg['critical_priority'] || isset($blocksOthers[(int)$t['id']]);
            $crit = $important && $days !== null && $days >= $cfg['blocked_critical_days'];
            $out[] = ['key' => 'blocked:' . $t['id'], 'rule' => 'blocked', 'severity' => $crit ? 'critical' : 'warning', 'level' => 2, 'project_id' => (int)$t['project_id'], 'task_id' => (int)$t['id'],
                'message' => $key($t) . " «{$t['title']}» está bloqueada" . ($t['blocked_reason'] ? ": {$t['blocked_reason']}" : ''),
                'explanation' => ($days !== null ? 'Bloqueada desde hace ' . ($days === 1 ? '1 día' : "$days días") . '. ' : 'Bloqueada antes de 1.12 (sin fecha registrada). ')
                    . ($crit ? 'Crítica: es importante o frena a otras tareas y lleva ' . (int)$cfg['blocked_critical_days'] . ' días o más.' : 'Advertencia: se avisa a la responsable del proyecto para que ayude a resolverlo.')];
        }
        // Dependencias en riesgo
        foreach (self::dependencyRisks(null, $wsId) as $r) {
            $out[] = ['key' => 'dependency:' . $r['b_id'] . ':' . $r['a_id'], 'rule' => 'dependency', 'severity' => 'warning', 'level' => 1, 'project_id' => (int)$r['b_project'], 'task_id' => (int)$r['b_id'],
                'message' => "{$r['b_key']} «{$r['b_title']}» podría verse afectada por {$r['a_key']}",
                'explanation' => $r['why'] . ' Borealis no cambia ninguna fecha por su cuenta: revisa la planificación de ambas.'];
        }
        // Tiempos: desviación importante (informativa; advertencia desde el doble del umbral)
        foreach ($db->all("SELECT t.id, t.number, t.title, t.project_id, t.estimate_hours, p.project_key, s.category,
            (SELECT COALESCE(SUM(te.minutes), 0) FROM time_entries te WHERE te.task_id = t.id AND te.deleted_at IS NULL) AS m
            FROM tasks t JOIN projects p ON p.id = t.project_id JOIN statuses s ON s.id = t.status_id
            WHERE p.workspace_id = ? AND p.archived_at IS NULL AND t.deleted_at IS NULL AND t.estimate_hours >= ? AND t.estimate_hours > 0 AND (s.category <> 'done' OR t.completed_at >= ?)
            HAVING m > 0 LIMIT 3000", [$wsId, $cfg['deviation_min_hours'], gmdate('Y-m-d H:i:s', time() - 14 * 86400)]) as $t) {
            $dev = self::deviation((float)$t['estimate_hours'], (int)$t['m']);
            if (!$dev || $dev['pct'] < $cfg['deviation_pct']) continue;
            $out[] = ['key' => 'deviation:' . $t['id'], 'rule' => 'deviation', 'severity' => $dev['pct'] >= 2 * $cfg['deviation_pct'] ? 'warning' : 'info', 'level' => 1, 'project_id' => (int)$t['project_id'], 'task_id' => (int)$t['id'],
                'message' => $key($t) . " «{$t['title']}»: " . self::fmtHours($dev['logged']) . ' registradas de ' . self::fmtHours($dev['estimate']) . ' estimadas (' . self::fmtHours($dev['hours'], true) . ", +{$dev['pct']} %)",
                'explanation' => 'Las horas registradas superan la estimación en un ' . $dev['pct'] . ' % (umbral: ' . $cfg['deviation_pct'] . ' %). Una desviación no es un incumplimiento: sirve para revisar la estimación o el alcance' . ($t['category'] === 'done' ? '.' : ' del trabajo que queda.')];
        }
        // 1.14 · Tiempos: una tarea abierta ya consumió más horas que las acordadas (a quien gestiona el proyecto)
        foreach ($db->all("SELECT t.id, t.number, t.title, t.project_id, t.estimate_hours, p.project_key,
            (SELECT COALESCE(SUM(te.minutes), 0) FROM time_entries te WHERE te.task_id = t.id AND te.deleted_at IS NULL) AS m
            $base AND t.estimate_hours > 0 HAVING m > t.estimate_hours * 60 LIMIT 2000", [$wsId]) as $t) {
            $over = round((int)$t['m'] / 60 - (float)$t['estimate_hours'], 2);
            $out[] = ['key' => 'over_agreed:' . $t['id'] . ':' . (float)$t['estimate_hours'], 'rule' => 'over_agreed', 'severity' => 'warning', 'level' => 2, 'project_id' => (int)$t['project_id'], 'task_id' => (int)$t['id'],
                'message' => $key($t) . " «{$t['title']}» lleva " . self::fmtHours((int)$t['m'] / 60) . ' de ' . self::fmtHours((float)$t['estimate_hours']) . ' acordadas (' . self::fmtHours($over, true) . ') y sigue abierta',
                'explanation' => 'Superó el tiempo acordado. No es una medida de rendimiento: puede ser una estimación corta, un bloqueo o trabajo nuevo. Revisa con su responsable si hay que acordar más horas, dividir la tarea o reprogramar.'];
        }
        // 1.14 · Tiempos: el trabajador pide bastante más tiempo del acordado (pendiente de revisión)
        $inc = (int)($cfg['estimate_increase_pct'] ?? 25);
        foreach ($db->all("SELECT t.id, t.number, t.title, t.project_id, t.estimate_hours, t.worker_estimate_hours, p.project_key $base AND t.estimate_review = 'pending'
            AND t.estimate_hours > 0 AND t.worker_estimate_hours >= t.estimate_hours * ? LIMIT 2000", [$wsId, 1 + $inc / 100]) as $t) {
            $pct = (int)round(((float)$t['worker_estimate_hours'] / (float)$t['estimate_hours'] - 1) * 100);
            $out[] = ['key' => 'estimate_increase:' . $t['id'] . ':' . (float)$t['worker_estimate_hours'], 'rule' => 'estimate_increase', 'severity' => $pct >= 2 * $inc ? 'warning' : 'info', 'level' => 2,
                'project_id' => (int)$t['project_id'], 'task_id' => (int)$t['id'],
                'message' => $key($t) . " «{$t['title']}»: su responsable estima " . self::fmtHours((float)$t['worker_estimate_hours']) . ' y lo acordado es ' . self::fmtHours((float)$t['estimate_hours']) . " (+$pct %)",
                'explanation' => "La nueva estimación supera lo acordado en un $pct % (umbral: $inc %). Ábrela para leer el motivo y acordar las horas: la carga y la capacidad siguen usando lo acordado hasta entonces."];
        }
        // Tiempos: sin estimación en un sprint activo (informativa)
        foreach ($db->all("SELECT t.id, t.number, t.title, t.project_id, t.sprint_id, p.project_key $base AND t.parent_id IS NULL AND t.estimate_hours IS NULL
            AND EXISTS (SELECT 1 FROM sprints sp WHERE sp.id = t.sprint_id AND sp.state = 'active') LIMIT 2000", [$wsId]) as $t) {
            $out[] = ['key' => 'no_estimate:' . $t['id'], 'rule' => 'no_estimate', 'severity' => 'info', 'level' => 1, 'project_id' => (int)$t['project_id'], 'task_id' => (int)$t['id'], 'sprint_id' => (int)$t['sprint_id'],
                'message' => $key($t) . " «{$t['title']}» no tiene horas estimadas y está en el sprint activo",
                'explanation' => 'Sin estimación no se puede calcular la carga ni la capacidad del sprint para esta tarea.'];
        }
        // Carga: semana actual y siguiente, solo de quien tiene horas semanales definidas
        if (Policy::wsId() !== $wsId) Policy::useWorkspace($wsId); // cron: one workspace after another (web requests are already in it)
        $people = array_map('intval', $db->column("SELECT user_id FROM workspace_members WHERE workspace_id = ? AND status = 'active' AND weekly_hours > 0", [$wsId]));
        if ($people) {
            $wl = self::workload(self::week(), 2, null, null, $people, true);
            foreach ($wl['people'] as $r) foreach ($r['weeks'] as $w => $c) {
                if ($c['class'] !== 'over') continue;
                $pids = array_values(array_filter(array_keys($c['projects'])));
                $out[] = ['key' => 'overload:' . $r['user']['id'] . ':' . $w, 'rule' => 'overload', 'severity' => 'warning', 'level' => 1, 'user_id' => (int)$r['user']['id'],
                    'project_id' => count($pids) === 1 ? $pids[0] : null, 'load_projects' => $pids,
                    'message' => "{$r['user']['name']}: " . self::fmtHours($c['planned']) . ' planificadas para la semana del ' . fmt_date($w) . ' con ' . self::fmtHours((float)$c['capacity']) . ' de capacidad (' . self::fmtHours((float)$c['over'], true) . ')',
                    'explanation' => 'Horas pendientes estimadas repartidas entre el inicio y la fecha límite de cada tarea (días laborables). La capacidad es una referencia de planificación, no un límite ni una medida de rendimiento: sirve para redistribuir, reprogramar o ajustar estimaciones.'];
            }
        }
        return $out;
    }

    /** Who hears about an alert at each level (always within what they can see). */
    private static function recipients(int $level, array $c, array $row, int $wsId): array
    {
        $db = Database::get();
        if ($level === 1) {
            if ($c['rule'] === 'overload') return [(int)$c['user_id']];
            if (!empty($c['task_id'])) {
                return array_map('intval', array_merge($db->column('SELECT assignee_id FROM tasks WHERE id = ? AND assignee_id IS NOT NULL', [$c['task_id']]),
                    $db->column('SELECT user_id FROM task_assignees WHERE task_id = ?', [$c['task_id']])));
            }
            return [];
        }
        if ($level === 2) {
            $pids = $c['rule'] === 'overload' ? ($c['load_projects'] ?? []) : array_filter([(int)($c['project_id'] ?? 0)]);
            $ids = [];
            foreach ($pids as $pid) $ids = array_merge($ids, self::managers((int)$pid));
            return array_values(array_unique($ids));
        }
        // level 3: the administration (people who can see every project of the workspace)
        return array_map('intval', $db->column("SELECT wm.user_id FROM workspace_members wm JOIN role_permissions rp ON rp.role_id = wm.role_id AND rp.permission = 'projects.manage_all'
            JOIN users u ON u.id = wm.user_id AND u.is_active = 1 WHERE wm.workspace_id = ? AND wm.status = 'active'", [$wsId]));
    }

    /** People who manage a project: its lead and its members with «project.manage». */
    public static function managers(int $projectId): array
    {
        $db = Database::get();
        return array_values(array_unique(array_map('intval', array_merge(
            $db->column('SELECT lead_user_id FROM projects WHERE id = ? AND lead_user_id IS NOT NULL', [$projectId]),
            $db->column("SELECT pm.user_id FROM project_members pm JOIN role_permissions rp ON rp.role_id = pm.role_id AND rp.permission = 'project.manage' WHERE pm.project_id = ?", [$projectId]),
        ))));
    }

    /** Notices: once per alert and level (dedupe); more than three for one person in a run become one. */
    private static function deliver(array $outbox, int $wsId): int
    {
        $sent = 0;
        foreach ($outbox as $uid => $items) {
            $fresh = [];
            foreach ($items as [$row, $level]) {
                $dk = 'mon:' . $row['id'] . ':' . $level . ':' . substr(md5((string)$row['first_seen']), 0, 6);
                if (Database::get()->value('SELECT 1 FROM notifications WHERE user_id = ? AND dedupe_key = ?', [$uid, $dk])) continue;
                $fresh[$dk] = [$row, $level];
            }
            if (!$fresh) continue;
            if (count($fresh) > 3) {
                // grouped: one notice, and the individual keys are reserved so they do not come later one by one
                if (Notifications::store((int)$uid, $wsId, 'monitor', null, count($fresh) . ' alertas nuevas de monitoreo', url('/monitoring', ['tab' => 'risks']), null, null, 'mon-group:' . md5(implode(',', array_keys($fresh))))) $sent++;
                foreach ($fresh as $dk => [$row, $level]) Database::get()->run('INSERT IGNORE INTO notifications (user_id, workspace_id, type, project_id, task_id, message, url, dedupe_key, read_at, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [$uid, $wsId, 'monitor', $row['project_id'], $row['task_id'], mb_substr(self::levelPrefix($level) . $row['message'], 0, 255), url('/monitoring', ['tab' => 'risks']), $dk, now(), now()]);
                continue;
            }
            foreach ($fresh as $dk => [$row, $level]) {
                if (Notifications::store((int)$uid, $wsId, 'monitor', null, self::levelPrefix($level) . $row['message'], $row['task_id'] ? self::taskUrl((int)$row['task_id']) : url('/monitoring', ['tab' => 'risks']), $row['project_id'] ? (int)$row['project_id'] : null, $row['task_id'] ? (int)$row['task_id'] : null, $dk)) $sent++;
            }
        }
        return $sent;
    }

    private static function levelPrefix(int $level): string
    {
        return match ($level) { 2 => 'Para responsables: ', 3 => 'Escalada a la administración: ', default => '' };
    }

    private static function taskUrl(int $taskId): string
    {
        $k = Database::get()->one('SELECT p.project_key, t.number FROM tasks t JOIN projects p ON p.id = t.project_id WHERE t.id = ?', [$taskId]);
        return $k ? url('/t/' . $k['project_key'] . '-' . $k['number']) : url('/monitoring');
    }

    /** Alerts the current person may see, newest and most severe first. */
    public static function alerts(array $f = []): array
    {
        $sc = self::scope();
        $db = Database::get();
        $w = ['a.workspace_id = ?'];
        $p = [Policy::wsId()];
        $w[] = ($f['state'] ?? 'active') === 'resolved' ? "a.state = 'resolved'" : "a.state <> 'resolved'";
        if (!$sc['admin']) {
            $vis = $sc['projects'] ? 'a.project_id IN (' . Database::in($sc['projects']) . ')' : '0';
            $people = self::visiblePeople();
            $w[] = "(($vis) OR (a.rule = 'overload' AND a.user_id IN (" . Database::in($people) . ')))';
            $p = array_merge($p, $sc['projects'], $people);
        }
        if (!empty($f['project_id'])) { $w[] = 'a.project_id = ?'; $p[] = (int)$f['project_id']; }
        if (!empty($f['rule']) && isset(self::RULES[$f['rule']])) { $w[] = 'a.rule = ?'; $p[] = $f['rule']; }
        if (!empty($f['severity']) && isset(self::SEVERITY[$f['severity']])) { $w[] = 'a.severity = ?'; $p[] = $f['severity']; }
        return $db->all("SELECT a.*, pj.name AS project_name, pj.color AS project_color, CONCAT(pj.project_key, '-', t.number) AS task_key, u.name AS user_name, ab.name AS ack_name
            FROM monitor_alerts a LEFT JOIN projects pj ON pj.id = a.project_id LEFT JOIN tasks t ON t.id = a.task_id LEFT JOIN users u ON u.id = a.user_id LEFT JOIN users ab ON ab.id = a.ack_by
            WHERE " . implode(' AND ', $w) . " ORDER BY FIELD(a.severity, 'critical', 'warning', 'info'), a.state = 'ack', a.first_seen DESC LIMIT 300", $p);
    }

    /** Acknowledge («Revisada»): stops escalation; it is resolved only when the condition disappears. */
    public static function acknowledge(int $id, ?string $note): array
    {
        $db = Database::get();
        $a = null;
        foreach (self::alerts() as $x) if ((int)$x['id'] === $id) { $a = $x; break; }
        if (!$a) throw HttpException::notFound('La alerta no existe o no tienes acceso.');
        $sc = self::scope();
        $can = $sc['admin'] || ($a['project_id'] && in_array((int)$a['project_id'], $sc['managed'], true)) || ((int)$a['user_id'] === Auth::id())
            || ($a['task_id'] && in_array(Auth::id(), array_map('intval', array_merge($db->column('SELECT assignee_id FROM tasks WHERE id = ?', [$a['task_id']]), $db->column('SELECT user_id FROM task_assignees WHERE task_id = ?', [$a['task_id']]))), true));
        if (!$can) throw HttpException::forbidden('Solo quien es responsable de la tarea o del proyecto, o la administración, puede marcarla como revisada.');
        $note = $note !== null ? mb_substr(trim($note), 0, 500) : null;
        $db->update('monitor_alerts', ['state' => 'ack', 'ack_by' => Auth::id(), 'ack_at' => now(), 'ack_note' => $note ?: null], 'id = ?', [$id]);
        Activity::log('alert_ack', 'alert', $id, 'Marcó como revisada la alerta «' . mb_substr($a['message'], 0, 120) . '»' . ($note ? ": $note" : ''), $a['project_id'] ? (int)$a['project_id'] : null, $a['task_id'] ? (int)$a['task_id'] : null);
        return $db->one('SELECT * FROM monitor_alerts WHERE id = ?', [$id]);
    }

    // ------------------------------------------------------------------ summary and report

    public static function summary(?int $projectId): array
    {
        $sc = self::scope();
        $pids = $projectId ? [$projectId] : $sc['projects'];
        $cmp = self::compliance(['project_id' => $projectId, 'class' => 'none']);
        $db = Database::get();
        $active = $pids ? (int)$db->value("SELECT COUNT(*) FROM projects WHERE id IN (" . Database::in($pids) . ") AND state = 'active' AND archived_at IS NULL", $pids) : 0;
        $sprints = self::sprints($projectId);
        $alerts = self::alerts(['project_id' => $projectId]);
        $risk = count(array_filter($alerts, fn($a) => $a['severity'] !== 'info'));
        $upcoming = Tasks::query(['category' => ['todo', 'in_progress'], 'due_from' => today(), 'due_to' => date('Y-m-d', strtotime(today() . ' +7 days')), 'has_date' => 1, 'sort' => 'due', 'limit' => 8] + ($projectId ? ['project_id' => $projectId] : []));
        return ['counts' => $cmp['counts'], 'active_projects' => $active, 'sprints' => $sprints, 'alerts' => $alerts, 'at_risk' => $risk, 'upcoming' => $upcoming,
            'health' => self::projectHealth($projectId)];
    }

    /** Rows of the CSV report (same data as the «Informes» tab). */
    public static function reportCsv(array $f): string
    {
        $rows = [];
        $q = ['with_subtasks' => 0, 'limit' => 3000, 'sort' => 'due'];
        if (!empty($f['project_id'])) $q['project_id'] = (int)$f['project_id'];
        if (!empty($f['sprint_id'])) $q['sprint_id'] = (string)(int)$f['sprint_id'];
        $cfg = self::settings();
        $ids = [];
        $tasks = [];
        foreach (Tasks::query($q) as $t) {
            $done = !empty($t['completed_at']);
            $d = $done ? local_date('Y-m-d', (string)$t['completed_at']) : $t['due_date'];
            if ((!empty($f['from']) || !empty($f['to'])) && (!$d || (!empty($f['from']) && $d < $f['from']) || (!empty($f['to']) && $d > $f['to']))) {
                if ($done || self::classify($t, $cfg) !== 'late') continue;
            }
            $tasks[] = $t; $ids[] = (int)$t['id'];
        }
        $reasons = [];
        if ($ids) foreach (Database::get()->all('SELECT task_id, reason FROM task_explanations WHERE task_id IN (' . Database::in($ids) . ') ORDER BY created_at', $ids) as $x) $reasons[(int)$x['task_id']][] = self::REASONS[$x['reason']] ?? $x['reason'];
        foreach ($tasks as $t) {
            $l = lateness($t);
            $dev = self::deviation($t['estimate_hours'] !== null ? (float)$t['estimate_hours'] : null, (int)$t['logged_minutes']);
            $rows[] = [$t['task_key'], $t['title'], $t['project_name'], $t['sprint_name'] ?? '', $t['status_name'], self::CLASSES[self::classify($t, $cfg)], fmt_dmy($t['due_date']),
                $t['completed_at'] ? fmt_dmy(local_date('Y-m-d', (string)$t['completed_at'])) : '', $l && $l['kind'] !== 'ontime' ? $l['days'] : '',
                $t['estimate_hours'] !== null ? str_replace('.', ',', (string)(float)$t['estimate_hours']) : '', str_replace('.', ',', (string)round((int)$t['logged_minutes'] / 60, 2)),
                $dev ? str_replace('.', ',', (string)$dev['hours']) : '', $dev ? $dev['pct'] : '', $t['is_blocked'] ? 'Sí' : 'No', implode('; ', $reasons[(int)$t['id']] ?? [])];
        }
        return Reports::csv(['Clave', 'Tarea', 'Proyecto', 'Sprint', 'Estado', 'Cumplimiento', 'Fecha límite', 'Finalizada', 'Días de retraso', 'Horas estimadas', 'Horas registradas', 'Desviación (h)', 'Desviación (%)', 'Bloqueada', 'Motivos registrados'], $rows);
    }
}
