<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Validator;
use Borealis\Security\Policy;

/**
 * Hours each person has available in a sprint, and how many are already committed.
 *
 * - Availability: the hours set for that person in that sprint, otherwise their weekly hours
 *   × the sprint's length in weeks (working days / 5). Without either, there is no limit.
 * - Load: the estimated hours of the sprint's tasks (not subtasks) where the person is
 *   responsible. A task with several responsible people is split equally among them, so the
 *   task's hours are counted once in total. A task without an estimate counts as «pendiente»,
 *   never as zero. A whole team responsible without named people does not use anyone's hours.
 *
 * Every change that can add load (people, estimate, sprint) goes through guard(), inside the
 * same transaction as the write and with the sprint row locked, so two people assigning at the
 * same time cannot both use the last free hours.
 */
final class Capacity
{
    private const EPS = 0.001;

    public static function weeks(array $sprint): ?float
    {
        $s = $sprint['start_date'] ?? null;
        $e = $sprint['end_date'] ?? null;
        if (!$s || !$e || $e < $s) return null;
        $n = 0;
        for ($d = strtotime($s), $end = strtotime($e); $d <= $end; $d += 86400) if ((int)date('N', $d) < 6) $n++;
        return $n / 5;
    }

    /** Availability per person in the sprint: uid => ['hours' => ?float, 'set' => bool]. */
    public static function availability(array $sprint, array $userIds): array
    {
        $db = Database::get();
        $out = [];
        if (!$userIds) return $out;
        $set = [];
        $src = [];
        foreach ($db->all('SELECT user_id, hours, source FROM sprint_capacity WHERE sprint_id = ?', [(int)$sprint['id']]) as $r) { $set[(int)$r['user_id']] = (float)$r['hours']; $src[(int)$r['user_id']] = $r['source'] ?? 'manual'; }
        $wsId = (int)$db->value('SELECT workspace_id FROM projects WHERE id = ?', [(int)$sprint['project_id']]);
        $weekly = [];
        foreach ($db->all('SELECT user_id, weekly_hours FROM workspace_members WHERE workspace_id = ? AND user_id IN (' . Database::in($userIds) . ')', array_merge([$wsId], $userIds)) as $r) {
            if ($r['weekly_hours'] !== null) $weekly[(int)$r['user_id']] = (float)$r['weekly_hours'];
        }
        $weeks = self::weeks($sprint);
        foreach ($userIds as $uid) {
            $uid = (int)$uid;
            if (isset($set[$uid])) $out[$uid] = ['hours' => $set[$uid], 'set' => true, 'source' => $src[$uid]];
            else $out[$uid] = ['hours' => isset($weekly[$uid]) && $weeks !== null ? round($weekly[$uid] * $weeks, 2) : null, 'set' => false, 'source' => 'weekly'];
        }
        return $out;
    }

    /**
     * Load per person in a sprint: uid => ['hours' => float, 'pending' => int, 'tasks' => int].
     * $override replaces one task's state for a "what if": [taskId, inSprint, ?hours, ids].
     */
    public static function loads(int $sprintId, ?array $override = null): array
    {
        $db = Database::get();
        $tasks = [];
        foreach ($db->all('SELECT id, estimate_hours, assignee_id FROM tasks WHERE sprint_id = ? AND parent_id IS NULL AND deleted_at IS NULL', [$sprintId]) as $r) {
            $tasks[(int)$r['id']] = ['hours' => $r['estimate_hours'] !== null ? (float)$r['estimate_hours'] : null, 'ids' => $r['assignee_id'] ? [(int)$r['assignee_id']] : []];
        }
        if ($tasks) {
            $ids = array_keys($tasks);
            foreach ($db->all('SELECT task_id, user_id FROM task_assignees WHERE task_id IN (' . Database::in($ids) . ')', $ids) as $r) {
                $tasks[(int)$r['task_id']]['ids'][] = (int)$r['user_id'];
            }
        }
        if ($override) {
            [$tid, $in, $hours, $people] = $override;
            unset($tasks[$tid]);
            if ($in) $tasks[$tid] = ['hours' => $hours, 'ids' => $people];
        }
        $out = [];
        foreach ($tasks as $t) {
            $people = array_values(array_unique(array_filter($t['ids'])));
            $n = count($people);
            foreach ($people as $uid) {
                $out[$uid] ??= ['hours' => 0.0, 'pending' => 0, 'tasks' => 0];
                $out[$uid]['tasks']++;
                if ($t['hours'] === null) $out[$uid]['pending']++;
                else $out[$uid]['hours'] += $t['hours'] / $n;
            }
        }
        return $out;
    }

    /** One task's share for one person if they were among $n responsible people. */
    public static function share(?float $hours, int $n): ?float
    {
        return $hours === null ? null : ($n > 0 ? $hours / $n : $hours);
    }

    /**
     * Rejects a change that would give someone more sprint hours than they have available.
     * Call it inside the transaction that writes the change. A person already over their limit
     * (because their availability was lowered) may still lose or keep work, never gain it.
     *
     * @param array $t       the task as it is now (id 0 for a task being created)
     * @param ?int  $sprint  the task's sprint after the change
     * @param ?float $hours  the task's estimated hours after the change
     * @param int[] $people  the task's responsible people after the change
     */
    public static function guard(array $t, ?int $sprint, ?float $hours, array $people): void
    {
        if (!$sprint || !empty($t['parent_id']) || $hours === null || $hours <= 0) return;
        $db = Database::get();
        $sp = $db->one('SELECT * FROM sprints WHERE id = ? FOR UPDATE', [$sprint]);
        if (!$sp) return;
        $people = array_values(array_unique(array_filter(array_map('intval', $people))));
        if (!$people) return;
        $tid = (int)($t['id'] ?? 0);
        $before = self::loads($sprint);
        $after = self::loads($sprint, [$tid, true, $hours, $people]);
        $avail = self::availability($sp, $people);
        $share = $hours / count($people);
        foreach ($people as $uid) {
            $cap = $avail[$uid]['hours'] ?? null;
            if ($cap === null) continue;
            $a = $after[$uid]['hours'] ?? 0.0;
            $b = $before[$uid]['hours'] ?? 0.0;
            if ($a <= $cap + self::EPS || $a <= $b + self::EPS) continue;
            $free = max(0.0, $cap - ($a - $share));
            $name = (string)($db->value('SELECT name FROM users WHERE id = ?', [$uid]) ?: 'Esta persona');
            $msg = $free <= self::EPS
                ? "$name no tiene horas disponibles en «{$sp['name']}» (" . self::num($cap) . ' h ocupadas).'
                : "$name solo tiene " . self::num($free) . " h disponibles en «{$sp['name']}» y esta tarea le suma " . self::num($share) . ' h.';
            throw HttpException::conflict($msg);
        }
    }

    /**
     * Everyone who can work in the project, with availability and load in the sprint.
     * Sorted: people with hours left first, then those without availability.
     */
    public static function people(array $sprint, array $project): array
    {
        $members = Projects::assignableUsers($project);
        $ids = array_map(fn($m) => (int)$m['id'], $members);
        $loads = self::loads((int)$sprint['id']);
        $avail = self::availability($sprint, $ids);
        $out = [];
        foreach ($members as $m) {
            $uid = (int)$m['id'];
            $cap = $avail[$uid]['hours'] ?? null;
            $load = round($loads[$uid]['hours'] ?? 0.0, 2);
            $free = $cap !== null ? round($cap - $load, 2) : null;
            $out[] = ['id' => $uid] + $m + [
                'capacity' => $cap, 'set' => $avail[$uid]['set'] ?? false, 'cap_source' => $avail[$uid]['source'] ?? 'weekly', 'load' => $load, 'free' => $free,
                'pending' => $loads[$uid]['pending'] ?? 0, 'tasks' => $loads[$uid]['tasks'] ?? 0,
                'full' => $cap !== null && $free <= self::EPS, 'over' => $cap !== null && $free < -self::EPS,
            ];
        }
        return $out;
    }

    /**
     * 1.7: before someone's weekly hours change, sprints that already ended (finished, or past
     * their end date) keep the hours they had, so history is not recalculated. Sprints with hours
     * set by hand for that person are left as they are.
     */
    public static function keepHistory(int $userId, float $oldWeekly): void
    {
        $db = Database::get();
        $rows = $db->all("SELECT s.* FROM sprints s JOIN projects p ON p.id = s.project_id
            WHERE p.workspace_id = ? AND s.start_date IS NOT NULL AND s.end_date IS NOT NULL AND (s.state = 'completed' OR s.end_date < ?)
              AND NOT EXISTS (SELECT 1 FROM sprint_capacity sc WHERE sc.sprint_id = s.id AND sc.user_id = ?)
              AND (EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = p.id AND pm.user_id = ?)
                OR EXISTS (SELECT 1 FROM team_members tm WHERE tm.team_id = p.team_id AND tm.user_id = ?)
                OR EXISTS (SELECT 1 FROM project_teams pt JOIN team_members tm2 ON tm2.team_id = pt.team_id WHERE pt.project_id = p.id AND tm2.user_id = ?)
                OR EXISTS (SELECT 1 FROM tasks t LEFT JOIN task_assignees ta ON ta.task_id = t.id WHERE t.sprint_id = s.id AND (t.assignee_id = ? OR ta.user_id = ?)))",
            [Policy::wsId(), today(), $userId, $userId, $userId, $userId, $userId, $userId]);
        foreach ($rows as $s) {
            $w = self::weeks($s);
            if ($w === null) continue;
            $db->run("INSERT IGNORE INTO sprint_capacity (sprint_id, user_id, hours, source, updated_at) VALUES (?, ?, ?, 'history', ?)", [(int)$s['id'], $userId, round($oldWeekly * $w, 2), now()]);
        }
    }

    /** Sets (or clears, with null) one person's available hours in a sprint. Returns a warning if they end up over. */
    public static function set(int $sprintId, int $userId, mixed $hours): ?string
    {
        $s = Sprints::find($sprintId);
        Policy::authorizeProject($s['project'], 'sprint.manage');
        if (!in_array($userId, array_map(fn($m) => (int)$m['id'], Projects::assignableUsers($s['project'])), true)) throw HttpException::invalid(['user_id' => 'Esa persona no participa en el proyecto.']);
        $v = Validator::check(['hours' => $hours === '' ? null : $hours], ['hours' => 'nullable,number']);
        $db = Database::get();
        if ($v['hours'] === null) {
            $db->run('DELETE FROM sprint_capacity WHERE sprint_id = ? AND user_id = ?', [$sprintId, $userId]);
        } else {
            $h = (float)$v['hours'];
            if ($h < 0 || $h > 999) throw HttpException::invalid(['hours' => 'Las horas disponibles deben estar entre 0 y 999.']);
            $db->run("INSERT INTO sprint_capacity (sprint_id, user_id, hours, source, updated_at) VALUES (?,?,?,'manual',?) ON DUPLICATE KEY UPDATE hours = VALUES(hours), source = 'manual', updated_at = VALUES(updated_at)", [$sprintId, $userId, $h, now()]);
        }
        $name = (string)$db->value('SELECT name FROM users WHERE id = ?', [$userId]);
        Activity::log('sprint_updated', 'sprint', $sprintId, "Cambió las horas disponibles de $name en «{$s['name']}»", (int)$s['project_id']);
        foreach (self::people($s, $s['project']) as $p) {
            if ($p['id'] === $userId && $p['over']) return "Atención: $name tiene " . self::num($p['load']) . ' h asignadas y solo ' . self::num((float)$p['capacity']) . ' h disponibles. Sus tareas se mantienen; revísalas para corregirlo.';
        }
        return null;
    }

    public static function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');
    }
}
