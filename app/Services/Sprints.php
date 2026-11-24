<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Validator;
use Borealis\Security\Policy;

final class Sprints
{
    public const STATES = ['planned' => 'Planificado', 'active' => 'Activo', 'completed' => 'Finalizado'];

    public static function list(int $projectId, ?string $state = null): array
    {
        $sql = "SELECT sp.*,
            (SELECT COUNT(*) FROM tasks t WHERE t.sprint_id = sp.id AND t.deleted_at IS NULL AND t.parent_id IS NULL) AS task_count,
            (SELECT COUNT(*) FROM tasks t JOIN statuses s ON s.id = t.status_id WHERE t.sprint_id = sp.id AND t.deleted_at IS NULL AND t.parent_id IS NULL AND s.category = 'done') AS done_count,
            (SELECT COALESCE(SUM(t.estimate),0) FROM tasks t WHERE t.sprint_id = sp.id AND t.deleted_at IS NULL AND t.parent_id IS NULL) AS points,
            (SELECT COALESCE(SUM(t.estimate),0) FROM tasks t JOIN statuses s ON s.id = t.status_id WHERE t.sprint_id = sp.id AND t.deleted_at IS NULL AND t.parent_id IS NULL AND s.category = 'done') AS done_points
            FROM sprints sp WHERE sp.project_id = ?" . ($state ? ' AND sp.state = ?' : '') . " ORDER BY FIELD(sp.state,'active','planned','completed'), COALESCE(sp.start_date, sp.created_at), sp.id";
        return Database::get()->all($sql, $state ? [$projectId, $state] : [$projectId]);
    }

    public static function find(int $id): array
    {
        $s = Database::get()->one('SELECT * FROM sprints WHERE id = ?', [$id]) ?? throw HttpException::notFound('El sprint no existe.');
        $p = Projects::find((int)$s['project_id']);
        Modules::require($p, 'sprints');
        $s['project'] = $p;
        return $s;
    }

    /** Working days (Mon–Fri) between two dates, inclusive. */
    private static function workdays(string $from, string $to): int
    {
        $n = 0;
        for ($d = strtotime($from), $end = strtotime($to); $d <= $end; $d += 86400) if ((int)date('N', $d) < 6) $n++;
        return $n;
    }

    /**
     * Sprint control: capacity (weekly hours × sprint length) against planned hours, hours logged,
     * a burndown series and alerts. Works with hours, points or task counts, whichever the team uses.
     */
    public static function control(array $sprint, array $project, array $tasks): array
    {
        $db = Database::get();
        $start = $sprint['start_date'];
        $end = $sprint['end_date'];
        $hasDates = $start && $end && $end >= $start;
        $weeks = $hasDates ? self::workdays($start, $end) / 5 : null;
        $top = array_values(array_filter($tasks, fn($t) => !$t['parent_id']));
        $members = Projects::members($project);
        $hours = $db->all('SELECT user_id, weekly_hours FROM workspace_members WHERE workspace_id = ?', [(int)$project['workspace_id']]);
        $weekly = array_column($hours, 'weekly_hours', 'user_id');
        $taskIds = array_map(fn($t) => (int)$t['id'], $tasks);
        $loggedBy = [];
        if ($taskIds) {
            $w = 'task_id IN (' . Database::in($taskIds) . ') AND deleted_at IS NULL';
            $params = $taskIds;
            if ($hasDates) { $w .= ' AND work_date BETWEEN ? AND ?'; $params[] = $start; $params[] = $end; }
            foreach ($db->all("SELECT user_id, SUM(minutes) AS m FROM time_entries WHERE $w GROUP BY user_id", $params) as $r) $loggedBy[(int)$r['user_id']] = (int)$r['m'];
        }
        // People: availability and load come from Capacity, the same numbers the board and the
        // assignment checks use (several responsibles split a task's hours equally).
        $people = [];
        $missing = 0;
        foreach (Capacity::people($sprint, $project) as $c) {
            $uid = (int)$c['id'];
            $mine = array_filter($top, fn($t) => in_array($uid, array_merge([(int)$t['assignee_id']], array_map('intval', array_column($t['co_assignees'] ?? [], 'id'))), true));
            $wh = isset($weekly[$uid]) && $weekly[$uid] !== null ? (float)$weekly[$uid] : null;
            if ($wh === null && !$c['set'] && $mine) $missing++;
            $people[] = $c + [
                'weekly' => $wh, 'planned' => $c['load'], 'logged' => round(($loggedBy[$uid] ?? 0) / 60, 1),
                'open' => count(array_filter($mine, fn($t) => $t['status_category'] !== 'done')),
            ];
        }
        $autoCap = round(array_sum(array_map(fn($p) => (float)$p['capacity'], $people)), 2);
        $capacity = $sprint['capacity_hours'] !== null ? (float)$sprint['capacity_hours'] : ($autoCap ?: null);
        $planned = array_sum(array_map(fn($t) => (float)$t['estimate_hours'], $top));
        $logged = round(array_sum($loggedBy) / 60, 1);
        $unestimated = count(array_filter($top, fn($t) => $t['estimate_hours'] === null));

        // Burndown: remaining work per day, in hours if estimated, else points, else tasks.
        $unit = $planned > 0 ? 'hours' : (array_sum(array_map(fn($t) => (float)$t['estimate'], $top)) > 0 ? 'points' : 'tasks');
        $weight = fn($t) => $unit === 'hours' ? (float)$t['estimate_hours'] : ($unit === 'points' ? (float)$t['estimate'] : 1.0);
        $total = array_sum(array_map($weight, $top));
        $series = [];
        if ($hasDates) {
            $last = min($end, today());
            for ($d = strtotime($start); $d <= strtotime($end); $d += 86400) {
                $day = date('Y-m-d', $d);
                $done = 0.0;
                foreach ($top as $t) {
                    if ($t['status_category'] === 'done' && $t['completed_at'] && local_date('Y-m-d', $t['completed_at']) <= $day) $done += $weight($t);
                }
                $series[] = ['day' => $day, 'remaining' => $day <= $last ? round($total - $done, 1) : null];
            }
        }
        $openCount = count(array_filter($top, fn($t) => $t['status_category'] !== 'done'));
        $alerts = [];
        if ($capacity !== null && $planned > $capacity) $alerts[] = ['danger', 'Sobrecarga: hay ' . self::num($planned) . ' h planificadas para ' . self::num($capacity) . ' h de capacidad.'];
        foreach ($people as $p) if ($p['over']) $alerts[] = ['warn', "{$p['name']} tiene " . self::num($p['planned']) . ' h asignadas y solo ' . self::num((float)$p['capacity']) . ' h disponibles en el sprint. Sus tareas se mantienen: quita o reparte alguna, o sube sus horas disponibles.'];
        if ($missing) $alerts[] = ['info', $missing === 1 ? '1 persona del sprint no tiene horas semanales definidas.' : "$missing personas del sprint no tienen horas semanales definidas."];
        if ($unestimated && $top) $alerts[] = ['info', $unestimated === 1 ? '1 tarea no tiene horas estimadas.' : "$unestimated tareas no tienen horas estimadas."];
        if ($sprint['state'] === 'active' && $hasDates) {
            $left = $end >= today() ? self::workdays(today(), $end) : 0;
            if ($end < today() && $openCount) $alerts[] = ['danger', "El sprint terminó el " . fmt_date($end, true) . " y quedan $openCount tareas abiertas."];
            elseif ($left <= 2 && $openCount && $total > 0 && $series) {
                $known = array_values(array_filter($series, fn($x) => $x['remaining'] !== null));
                $rem = $known ? $known[count($known) - 1]['remaining'] : $total;
                if ($rem / $total > .3) $alerts[] = ['warn', "Quedan $left días hábiles y falta el " . (int)round($rem / $total * 100) . '% del trabajo.'];
            }
        }
        if (!$hasDates) $alerts[] = ['info', 'Define las fechas del sprint para calcular la capacidad y el gráfico de avance.'];
        return compact('capacity', 'autoCap', 'planned', 'logged', 'people', 'series', 'unit', 'total', 'alerts', 'weeks', 'unestimated') + ['override' => $sprint['capacity_hours'] !== null];
    }

    private static function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');
    }

    public static function active(int $projectId): ?array
    {
        return Database::get()->one("SELECT * FROM sprints WHERE project_id = ? AND state = 'active' LIMIT 1", [$projectId]);
    }

    public static function create(int $projectId, array $input): int
    {
        $p = Projects::find($projectId);
        Modules::require($p, 'sprints');
        Policy::authorizeProject($p, 'sprint.manage');
        $v = Validator::check($input, ['name' => 'nullable,string,max:120', 'goal' => 'nullable,string,max:2000', 'start_date' => 'nullable,date', 'end_date' => 'nullable,date']);
        if ($v['start_date'] && $v['end_date'] && $v['end_date'] < $v['start_date']) throw HttpException::invalid(['end_date' => 'La fecha final no puede ser anterior al inicio.']);
        $db = Database::get();
        $n = (int)$db->value('SELECT COUNT(*) FROM sprints WHERE project_id = ?', [$projectId]) + 1;
        $name = $v['name'] ?: "Sprint $n";
        $id = $db->insert('sprints', ['project_id' => $projectId, 'name' => $name, 'goal' => $v['goal'], 'start_date' => $v['start_date'], 'end_date' => $v['end_date'], 'created_at' => now(), 'updated_at' => now()]);
        Activity::log('sprint_created', 'sprint', $id, "Creó «{$name}»", $projectId);
        return $id;
    }

    public static function update(int $id, array $input): void
    {
        $s = self::find($id);
        Policy::authorizeProject($s['project'], 'sprint.manage');
        $v = Validator::check($input, ['name' => 'sometimes,required,string,max:120', 'goal' => 'sometimes,nullable,string,max:2000', 'start_date' => 'sometimes,nullable,date', 'end_date' => 'sometimes,nullable,date', 'result_notes' => 'sometimes,nullable,string,max:5000', 'capacity_hours' => 'sometimes,nullable,number']);
        if (isset($v['capacity_hours']) && $v['capacity_hours'] !== null && ((float)$v['capacity_hours'] < 0 || (float)$v['capacity_hours'] > 99999)) throw HttpException::invalid(['capacity_hours' => 'Capacidad no válida.']);
        if (array_key_exists('capacity_hours', $v) && $v['capacity_hours'] === '') $v['capacity_hours'] = null;
        $start = array_key_exists('start_date', $v) ? $v['start_date'] : $s['start_date'];
        $end = array_key_exists('end_date', $v) ? $v['end_date'] : $s['end_date'];
        if ($start && $end && $end < $start) throw HttpException::invalid(['end_date' => 'La fecha final no puede ser anterior al inicio.']);
        if ($v) Database::get()->update('sprints', $v + ['updated_at' => now()], 'id = ?', [$id]);
        Activity::log('sprint_updated', 'sprint', $id, 'Actualizó «' . ($v['name'] ?? $s['name']) . '»', (int)$s['project_id']);
    }

    public static function start(int $id): void
    {
        $s = self::find($id);
        Policy::authorizeProject($s['project'], 'sprint.manage');
        if ($s['state'] !== 'planned') throw HttpException::conflict('Solo se puede iniciar un sprint planificado.');
        if (self::active((int)$s['project_id'])) throw HttpException::conflict('Ya hay un sprint activo. Finalízalo antes de iniciar otro.');
        $db = Database::get();
        $count = (int)$db->value('SELECT COUNT(*) FROM tasks WHERE sprint_id = ? AND deleted_at IS NULL AND parent_id IS NULL', [$id]);
        if ($count === 0) throw HttpException::conflict('Añade al menos una tarea al sprint antes de iniciarlo.');
        $points = (float)$db->value('SELECT COALESCE(SUM(estimate),0) FROM tasks WHERE sprint_id = ? AND deleted_at IS NULL AND parent_id IS NULL', [$id]);
        $db->update('sprints', [
            'state' => 'active', 'started_at' => now(), 'start_date' => $s['start_date'] ?: today(),
            'end_date' => $s['end_date'] ?: date('Y-m-d', strtotime(today() . ' +14 days')), 'committed_count' => $count, 'committed_points' => $points, 'updated_at' => now(),
        ], 'id = ?', [$id]);
        // 1.12: the original commitment, to tell planned work from work added later
        $db->run('INSERT INTO sprint_commitments (sprint_id, task_id, planned, estimate_hours, added_at)
            SELECT ?, t.id, 1, t.estimate_hours, ? FROM tasks t WHERE t.sprint_id = ? AND t.deleted_at IS NULL AND t.parent_id IS NULL
            ON DUPLICATE KEY UPDATE planned = 1, estimate_hours = VALUES(estimate_hours), outcome = NULL', [$id, now(), $id]);
        Activity::log('sprint_started', 'sprint', $id, "Inició «{$s['name']}» con $count tareas", (int)$s['project_id']);
        $end = $s['end_date'] ?: date('Y-m-d', strtotime(today() . ' +14 days'));
        self::announce($s, 'start', "Comenzó «{$s['name']}» en {$s['project']['name']}: $count tareas, hasta el " . fmt_date($end), 'sp-start:' . $id);
    }

    /** Close a sprint, record results and move unfinished tasks to the backlog or another sprint. */
    public static function complete(int $id, ?int $moveTo, ?string $notes): array
    {
        $s = self::find($id);
        Policy::authorizeProject($s['project'], 'sprint.manage');
        if ($s['state'] !== 'active') throw HttpException::conflict('Solo se puede finalizar el sprint activo.');
        $db = Database::get();
        if ($moveTo) {
            $target = $db->one("SELECT * FROM sprints WHERE id = ? AND project_id = ? AND state = 'planned'", [$moveTo, $s['project_id']]);
            if (!$target) throw HttpException::invalid(['move_to' => 'Elige un sprint planificado del mismo proyecto.']);
        }
        $res = $db->transaction(function (Database $db) use ($s, $id, $moveTo, $notes) {
            $done = (int)$db->value("SELECT COUNT(*) FROM tasks t JOIN statuses st ON st.id = t.status_id WHERE t.sprint_id = ? AND t.deleted_at IS NULL AND t.parent_id IS NULL AND st.category = 'done'", [$id]);
            $donePts = (float)$db->value("SELECT COALESCE(SUM(t.estimate),0) FROM tasks t JOIN statuses st ON st.id = t.status_id WHERE t.sprint_id = ? AND t.deleted_at IS NULL AND t.parent_id IS NULL AND st.category = 'done'", [$id]);
            // 1.12: outcome of each task that was in the sprint (done / moved to another sprint / back to the backlog)
            $db->run("INSERT INTO sprint_commitments (sprint_id, task_id, planned, estimate_hours, added_at)
                SELECT ?, t.id, NULL, t.estimate_hours, ? FROM tasks t WHERE t.sprint_id = ? AND t.deleted_at IS NULL AND t.parent_id IS NULL
                ON DUPLICATE KEY UPDATE added_at = sprint_commitments.added_at", [$id, now(), $id]);
            $db->run("UPDATE sprint_commitments sc JOIN tasks t ON t.id = sc.task_id JOIN statuses st ON st.id = t.status_id
                SET sc.outcome = IF(st.category = 'done', 'done', ?) WHERE sc.sprint_id = ? AND t.sprint_id = ? AND t.deleted_at IS NULL", [$moveTo ? 'moved' : 'pending', $id, $id]);
            $moved = $db->run("UPDATE tasks t JOIN statuses st ON st.id = t.status_id SET t.sprint_id = ?, t.updated_at = ? WHERE t.sprint_id = ? AND t.deleted_at IS NULL AND st.category <> 'done'", [$moveTo, now(), $id])->rowCount();
            $db->update('sprints', ['state' => 'completed', 'completed_at' => now(), 'completed_count' => $done, 'completed_points' => $donePts, 'result_notes' => $notes ? mb_substr($notes, 0, 5000) : null, 'updated_at' => now()], 'id = ?', [$id]);
            Activity::log('sprint_completed', 'sprint', $id, "Finalizó «{$s['name']}»: $done completadas, $moved trasladadas", (int)$s['project_id']);
            return ['done' => $done, 'moved' => $moved, 'committed' => (int)$s['committed_count']];
        });
        $dest = $moveTo ? '«' . Database::get()->value('SELECT name FROM sprints WHERE id = ?', [$moveTo]) . '»' : 'el backlog';
        $total = (int)$res['done'] + (int)$res['moved'];
        self::announce($s, 'close', "Se cerró «{$s['name']}» en {$s['project']['name']}: {$res['done']} de $total " . ($total === 1 ? 'tarea completada' : 'tareas completadas') . ($res['moved'] ? ($res['moved'] === 1 ? '; la pendiente pasa a ' : "; las {$res['moved']} pendientes pasan a ") . $dest : ', sin pendientes'), 'sp-close:' . $id);
        return $res;
    }

    public static function delete(int $id): void
    {
        $s = self::find($id);
        Policy::authorizeProject($s['project'], 'sprint.manage');
        if ($s['state'] !== 'planned') throw HttpException::conflict('Solo se pueden eliminar sprints planificados; los demás forman parte del historial.');
        Database::get()->run('DELETE FROM sprints WHERE id = ?', [$id]); // tasks return to backlog via ON DELETE SET NULL
        Activity::log('sprint_deleted', 'sprint', $id, "Eliminó «{$s['name']}»; sus tareas volvieron al backlog", (int)$s['project_id']);
    }

    // ------------------------------------------------------------------ 1.8: sprints in the plan

    /** Members of a project (directly or through its teams), for sprint notices. */
    private static function memberSql(): string
    {
        return "(EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = p.id AND pm.user_id = ?)
            OR EXISTS (SELECT 1 FROM team_members tm JOIN teams tt ON tt.id = tm.team_id AND tt.archived_at IS NULL WHERE tm.team_id = p.team_id AND tm.user_id = ?)
            OR EXISTS (SELECT 1 FROM project_teams ptv JOIN team_members tmv ON tmv.team_id = ptv.team_id WHERE ptv.project_id = p.id AND tmv.user_id = ?))";
    }

    /** Notice to the project's members (not to whoever did it) and, if enabled, to its Discord channel. */
    private static function announce(array $s, string $event, string $message, string $dedupe): void
    {
        $pid = (int)$s['project_id'];
        $link = url('/projects/' . $pid . '/sprints', ['sprint' => $s['id']]);
        foreach (Projects::memberIds($pid) as $uid) Notifications::send($uid, 'sprint', $message, $link, $pid, null, $dedupe . ':' . $uid);
        Integrations::announce($pid, 'sprint_' . $event, $message, $link, $dedupe);
    }

    /**
     * Time-based sprint notices for one person: a planned sprint starts today or tomorrow, an active one
     * ends within two days, or reached its end date and is still open. Reaching the date never closes
     * a sprint: the people in charge review it and close it themselves.
     */
    public static function remindersFor(int $uid, int $wsId): void
    {
        $db = Database::get();
        $today = today();
        $tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
        $soon = date('Y-m-d', strtotime($today . ' +2 days'));
        $rows = $db->all("SELECT s.*, p.name AS project_name FROM sprints s JOIN projects p ON p.id = s.project_id
            JOIN project_modules mo ON mo.project_id = p.id AND mo.module_key = 'sprints' AND mo.enabled = 1
            WHERE p.workspace_id = ? AND p.archived_at IS NULL AND " . self::memberSql() . "
              AND ((s.state = 'planned' AND s.start_date BETWEEN ? AND ?) OR (s.state = 'active' AND s.end_date IS NOT NULL AND s.end_date <= ?)) LIMIT 50",
            [$wsId, $uid, $uid, $uid, $today, $tomorrow, $soon]);
        foreach ($rows as $s) {
            $link = url('/projects/' . $s['project_id'] . '/sprints', ['sprint' => $s['id']]);
            if ($s['state'] === 'planned') {
                $msg = "«{$s['name']}» ({$s['project_name']}) empieza " . ($s['start_date'] === $today ? 'hoy' : 'mañana') . '. Revisa sus tareas antes de iniciarlo.';
                $key = 'sp-soon:' . $s['id'] . ':' . $s['start_date'];
                $event = 'sprint_soon';
            } elseif ($s['end_date'] < $today) {
                $msg = "«{$s['name']}» ({$s['project_name']}) llegó a su fecha de fin y sigue abierto: falta revisarlo y cerrarlo.";
                $key = 'sp-over:' . $s['id'] . ':' . $s['end_date'];
                $event = 'sprint_overdue';
            } else {
                $msg = "«{$s['name']}» ({$s['project_name']}) termina " . ($s['end_date'] === $today ? 'hoy' : ($s['end_date'] === $tomorrow ? 'mañana' : 'el ' . fmt_date($s['end_date']))) . '.';
                $key = 'sp-end:' . $s['id'] . ':' . $s['end_date'];
                $event = 'sprint_ending';
            }
            Notifications::store($uid, $wsId, 'sprint', null, $msg, $link, (int)$s['project_id'], null, $key);
            Integrations::announce((int)$s['project_id'], $event, $msg, $link, $key);
        }
    }

    /**
     * Sprint dates for the calendar: start (planned or real), planned end, and the day it was really
     * closed. An active sprint past its end date is shown as «pending closure», not as finished.
     */
    public static function calendarItems(string $from, string $to): array
    {
        [$vis, $vp] = Policy::visibleProjectsSql('p');
        $rows = Database::get()->all("SELECT s.*, p.name AS project_name, p.color AS project_color,
                (SELECT COUNT(*) FROM tasks t WHERE t.sprint_id = s.id AND t.deleted_at IS NULL AND t.parent_id IS NULL) AS task_count,
                (SELECT COUNT(*) FROM tasks t JOIN statuses st ON st.id = t.status_id WHERE t.sprint_id = s.id AND t.deleted_at IS NULL AND t.parent_id IS NULL AND st.category = 'done') AS done_count
            FROM sprints s JOIN projects p ON p.id = s.project_id
            JOIN project_modules mo ON mo.project_id = p.id AND mo.module_key = 'sprints' AND mo.enabled = 1
            WHERE p.archived_at IS NULL AND $vis AND ((s.start_date BETWEEN ? AND ?) OR (s.end_date BETWEEN ? AND ?) OR (s.completed_at BETWEEN ? AND ?))",
            array_merge($vp, [$from, $to, $from, $to, \Borealis\Services\Events::toUtc($from . ' 00:00'), \Borealis\Services\Events::toUtc($to . ' 23:59')]));
        $out = [];
        $today = today();
        foreach ($rows as $s) {
            $in = fn(?string $d) => $d && $d >= $from && $d <= $to;
            if ($in($s['start_date'])) $out[] = ['type' => 'sprint', 'edge' => 'start', 'day' => $s['start_date']] + $s;
            $closedDay = $s['completed_at'] ? local_date('Y-m-d', $s['completed_at']) : null;
            if ($in($s['end_date']) && $closedDay !== $s['end_date']) $out[] = ['type' => 'sprint', 'edge' => $s['state'] === 'active' && $s['end_date'] < $today ? 'overdue' : 'end', 'day' => $s['end_date']] + $s;
            if ($in($closedDay)) $out[] = ['type' => 'sprint', 'edge' => 'closed', 'day' => $closedDay] + $s;
        }
        return $out;
    }

    /** Sprints of every project the person sees, for «Sprints» (1.8), newest plans first. */
    public static function overview(): array
    {
        [$vis, $vp] = Policy::visibleProjectsSql('p');
        return Database::get()->all("SELECT sp.*, p.name AS project_name, p.color AS project_color, p.project_key,
            (SELECT COUNT(*) FROM tasks t WHERE t.sprint_id = sp.id AND t.deleted_at IS NULL AND t.parent_id IS NULL) AS task_count,
            (SELECT COUNT(*) FROM tasks t JOIN statuses s ON s.id = t.status_id WHERE t.sprint_id = sp.id AND t.deleted_at IS NULL AND t.parent_id IS NULL AND s.category = 'done') AS done_count,
            (SELECT COUNT(*) FROM tasks t WHERE t.sprint_id = sp.id AND t.deleted_at IS NULL AND t.parent_id IS NULL AND t.assignee_id IS NULL AND t.team_id IS NULL AND NOT EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id = t.id)) AS unassigned,
            (SELECT COUNT(*) FROM tasks t WHERE t.sprint_id = sp.id AND t.deleted_at IS NULL AND t.parent_id IS NULL AND t.estimate_hours IS NULL) AS unestimated,
            (SELECT COALESCE(SUM(t.estimate_hours),0) FROM tasks t WHERE t.sprint_id = sp.id AND t.deleted_at IS NULL AND t.parent_id IS NULL) AS hours
            FROM sprints sp JOIN projects p ON p.id = sp.project_id
            JOIN project_modules mo ON mo.project_id = p.id AND mo.module_key = 'sprints' AND mo.enabled = 1
            WHERE p.archived_at IS NULL AND $vis AND (sp.state <> 'completed' OR sp.completed_at >= ?)
            ORDER BY FIELD(sp.state,'active','planned','completed'), sp.start_date IS NULL, sp.start_date, sp.id", array_merge($vp, [gmdate('Y-m-d H:i:s', time() - 60 * 86400)]));
    }

    /**
     * Second stage of a sprint: what is still missing to plan it (tasks, people, hours, priority).
     * Nothing here is mandatory; it only shows what remains.
     */
    public static function planning(array $sprint, array $tasks): array
    {
        $top = array_values(array_filter($tasks, fn($t) => !$t['parent_id']));
        $noOne = array_filter($top, fn($t) => !$t['assignee_id'] && empty($t['team_id']) && empty($t['co_assignees']));
        return [
            'tasks' => count($top),
            'unassigned' => count($noOne),
            'unestimated' => count(array_filter($top, fn($t) => $t['estimate_hours'] === null)),
            'unprioritized' => count(array_filter($top, fn($t) => empty($t['priority_id']))),
            'hours' => array_sum(array_map(fn($t) => (float)($t['estimate_hours'] ?? 0), $top)),
            'dates' => (bool)($sprint['start_date'] && $sprint['end_date']),
            'goal' => trim((string)$sprint['goal']) !== '',
        ];
    }
}
