<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Security\Policy;

/**
 * Gantt chart of a project (1.14), built on every visit from the project's own data — there is no
 * second plan to maintain: tasks and subtasks (start → due), responsible people, «blocks»
 * dependencies, milestones, sprints, progress, delays, date changes and the original plan
 * (baseline_start / baseline_due, set the first time a task gets dates, or fixed again by a manager).
 *
 * Nothing is invented: a task with only a due date is drawn as a short bar ending that day and
 * marked «solo fecha límite»; a task without dates is counted apart.
 */
final class Gantt
{
    public const SCALES = ['day' => 'Días', 'week' => 'Semanas', 'month' => 'Meses'];

    public static function build(array $project, string $scale, ?string $from, ?string $to, bool $subtasks = true): array
    {
        $pid = (int)$project['id'];
        $db = Database::get();
        $all = Tasks::query(['project_id' => $pid, 'sort' => 'due', 'with_subtasks' => 1, 'limit' => 2000]);
        $ids = array_map(fn($t) => (int)$t['id'], $all);
        $logged = $ids ? array_column($db->all('SELECT task_id, SUM(minutes) AS m FROM time_entries WHERE deleted_at IS NULL AND task_id IN (' . Database::in($ids) . ') GROUP BY task_id', $ids), 'm', 'task_id') : [];
        $changes = $ids ? array_column($db->all('SELECT task_id, COUNT(*) AS n FROM task_due_changes WHERE old_due IS NOT NULL AND task_id IN (' . Database::in($ids) . ') GROUP BY task_id', $ids), 'n', 'task_id') : [];
        $links = $ids ? $db->all("SELECT l.source_task_id AS a, l.target_task_id AS b FROM task_links l WHERE l.link_type = 'blocks' AND l.source_task_id IN (" . Database::in($ids) . ') AND l.target_task_id IN (' . Database::in($ids) . ')', array_merge($ids, $ids)) : [];
        $today = today();
        $rows = [];
        $undated = 0;
        $minD = $project['start_date'] ?: null;
        $maxD = $project['due_date'] ?: null;
        foreach ($all as $t) {
            if (!$subtasks && $t['parent_id']) continue;
            $start = $t['start_date'] ?: null;
            $due = $t['due_date'] ?: null;
            if (!$start && !$due) { $undated++; continue; }
            $s = $start ?: $due;
            $e = $due ?: $start;
            if ($e < $s) $e = $s;
            $done = $t['status_category'] === 'done';
            $agreed = $t['estimate_hours'] !== null ? (float)$t['estimate_hours'] : null;
            $min = (int)($logged[$t['id']] ?? 0);
            // progress: finished = 100; with subtasks = share finished; else hours logged of the agreed ones (never 100 until finished)
            $progress = $done ? 100 : ((int)$t['subtask_count'] > 0 ? (int)round(100 * (int)$t['subtask_done'] / (int)$t['subtask_count'])
                : ($agreed ? min(95, (int)round(100 * $min / 60 / $agreed)) : ($t['status_category'] === 'in_progress' ? null : 0)));
            $lateDays = !$done && $due && $due < $today ? (int)((strtotime($today) - strtotime($due)) / 86400)
                : ($done && $t['completed_at'] && $due && substr((string)local_date('Y-m-d', $t['completed_at']), 0, 10) > $due ? (int)((strtotime(local_date('Y-m-d', $t['completed_at'])) - strtotime($due)) / 86400) : 0);
            $bs = $t['baseline_start'] ?? null;
            $bd = $t['baseline_due'] ?? null;
            $moved = ($bd && $due && $bd !== $due) || ($bs && $start && $bs !== $start);
            $rows[] = [
                'id' => (int)$t['id'], 'key' => $t['task_key'], 'title' => $t['title'], 'parent_id' => $t['parent_id'] ? (int)$t['parent_id'] : null,
                'start' => $s, 'end' => $e, 'only_due' => !$start, 'done' => $done, 'status' => $t['status_name'], 'color' => $t['status_color'],
                'who' => $t['assignee_name'] ?? ($t['team_name'] ?? null), 'milestone' => !empty($t['is_milestone']), 'progress' => $progress,
                'late' => $lateDays, 'late_open' => !$done && $lateDays > 0, 'base_start' => $bs ?: ($bd && !$start ? $bd : null), 'base_end' => $bd ?: $bs, 'moved' => $moved,
                'changes' => (int)($changes[$t['id']] ?? 0), 'sprint_id' => $t['sprint_id'] ? (int)$t['sprint_id'] : null, 'blocked' => !empty($t['is_blocked']) && !$done,
                'agreed' => $agreed, 'logged_h' => round($min / 60, 1), 'unplanned' => !empty($t['unplanned']),
            ];
            $minD = $minD ? min($minD, $s, $bs ?: $s) : min($s, $bs ?: $s);
            $maxD = $maxD ? max($maxD, $e, $bd ?: $e) : max($e, $bd ?: $e);
        }
        // order: parents by start, each followed by its subtasks
        $children = [];
        $top = [];
        foreach ($rows as $r) { if ($r['parent_id']) $children[$r['parent_id']][] = $r; else $top[] = $r; }
        usort($top, fn($a, $b) => [$a['start'], $a['end'], $a['key']] <=> [$b['start'], $b['end'], $b['key']]);
        $ordered = [];
        $seen = [];
        foreach ($top as $r) {
            $ordered[] = $r; $seen[$r['id']] = true;
            foreach ($children[$r['id']] ?? [] as $c) { $ordered[] = $c + ['child' => true]; $seen[$c['id']] = true; }
        }
        foreach ($rows as $r) if (!isset($seen[$r['id']])) $ordered[] = $r + ['child' => true]; // subtasks whose parent has no dates

        $sprints = $db->all("SELECT id, name, state, start_date, end_date FROM sprints WHERE project_id = ? AND start_date IS NOT NULL AND end_date IS NOT NULL ORDER BY start_date", [$pid]);
        foreach ($sprints as $sp) { $minD = $minD ? min($minD, $sp['start_date']) : $sp['start_date']; $maxD = $maxD ? max($maxD, $sp['end_date']) : $sp['end_date']; }

        // dependencies: is the successor planned to start before its predecessor ends?
        $byId = array_column($ordered, null, 'id');
        $deps = [];
        foreach ($links as $l) {
            $a = $byId[(int)$l['a']] ?? null; $b = $byId[(int)$l['b']] ?? null;
            if (!$a || !$b) continue;
            $deps[] = ['from' => $a['id'], 'to' => $b['id'], 'conflict' => !$a['done'] && $a['end'] >= $b['start'], 'from_key' => $a['key'], 'to_key' => $b['key']];
        }

        // range: what the filters say, else the whole project with a little margin
        $minD ??= $today; $maxD ??= $today;
        $pad = $scale === 'month' ? 14 : ($scale === 'week' ? 7 : 2);
        $f = $from && strtotime($from) ? $from : date('Y-m-d', strtotime($minD . " -$pad days"));
        $t2 = $to && strtotime($to) ? $to : date('Y-m-d', strtotime($maxD . " +$pad days"));
        if ($t2 < $f) [$f, $t2] = [$t2, $f];
        // never an unreadable chart: at most ~ 2 years
        if ((strtotime($t2) - strtotime($f)) / 86400 > 760) $t2 = date('Y-m-d', strtotime($f . ' +760 days'));
        if ($scale === 'week') { $f = date('Y-m-d', strtotime('monday this week', strtotime($f))); }
        if ($scale === 'month') { $f = date('Y-m-01', strtotime($f)); $t2 = date('Y-m-t', strtotime($t2)); }
        $days = (int)((strtotime($t2) - strtotime($f)) / 86400) + 1;
        return [
            'rows' => $ordered, 'deps' => $deps, 'sprints' => $sprints, 'undated' => $undated, 'from' => $f, 'to' => $t2, 'days' => $days,
            'columns' => self::columns($f, $t2, $scale), 'scale' => $scale, 'today' => $today,
            'moved' => count(array_filter($ordered, fn($r) => $r['moved'])), 'late' => count(array_filter($ordered, fn($r) => $r['late_open'])),
            'conflicts' => count(array_filter($deps, fn($d) => $d['conflict'])), 'milestones' => count(array_filter($ordered, fn($r) => $r['milestone'])),
        ];
    }

    /** Header columns for the scale: [label, start date, number of days]. */
    public static function columns(string $from, string $to, string $scale): array
    {
        $out = [];
        $d = $from;
        $months = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
        while ($d <= $to) {
            $ts = strtotime($d . ' 12:00:00');
            if ($scale === 'day') { $next = date('Y-m-d', $ts + 86400); $label = date('j', $ts); $sub = ['L', 'M', 'X', 'J', 'V', 'S', 'D'][(int)date('N', $ts) - 1]; }
            elseif ($scale === 'week') { $next = date('Y-m-d', $ts + 7 * 86400); $label = date('j', $ts) . ' ' . $months[(int)date('n', $ts) - 1]; $sub = 'S' . (int)date('W', $ts); }
            else { $next = date('Y-m-01', strtotime(date('Y-m-01', $ts) . ' +1 month')); $label = $months[(int)date('n', $ts) - 1]; $sub = date('Y', $ts); }
            $end = min($next, date('Y-m-d', strtotime($to . ' +1 day')));
            $out[] = ['label' => $label, 'sub' => $sub, 'start' => $d, 'days' => (int)((strtotime($end) - strtotime($d)) / 86400), 'weekend' => $scale === 'day' && (int)date('N', $ts) >= 6];
            $d = $next;
        }
        return $out;
    }

    /** A manager fixes the current dates as the new original plan (the change is in the audit log). */
    public static function rebaseline(int $projectId, ?string $reason): int
    {
        $p = Projects::find($projectId);
        Policy::authorizeProject($p, 'project.manage');
        $db = Database::get();
        $n = $db->run('UPDATE tasks SET baseline_start = start_date, baseline_due = due_date WHERE project_id = ? AND deleted_at IS NULL AND (start_date IS NOT NULL OR due_date IS NOT NULL)
            AND (NOT (baseline_start <=> start_date) OR NOT (baseline_due <=> due_date))', [$projectId])->rowCount();
        Activity::log('baseline', 'project', $projectId, "Fijó las fechas actuales como planificación original ($n tareas cambiaron)", $projectId, null, [], null, ['reason' => $reason, 'category' => 'calendar', 'after' => ['tareas' => $n]]);
        return $n;
    }
}
