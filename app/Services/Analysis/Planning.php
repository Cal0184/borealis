<?php
declare(strict_types=1);

namespace Borealis\Services\Analysis;

use Borealis\Core\Database;
use Borealis\Services\Capacity;
use Borealis\Services\Monitoring;
use Borealis\Services\Projects;

/**
 * Análisis (1.13): what may happen and what can be done about it.
 *  - future capacity per week (reuses Monitoreo › Carga, the same load model);
 *  - rule-based risk of sprints and projects (points with their reasons, never a probability);
 *  - recommendations, each with problem, evidence, action, expected impact, confidence and link.
 * Nothing here writes data: actions go through the scenario simulator or the normal screens.
 */
final class Planning
{
    // ------------------------------------------------------------------ future capacity

    /** People whose capacity counts: the visible people, narrowed to the project's or the team's people. */
    public static function people(?int $projectId, ?int $teamId, bool $mine): array
    {
        $vis = Monitoring::visiblePeople();
        if ($mine) return array_values(array_intersect($vis, [\Borealis\Core\Auth::id()]));
        if ($projectId) {
            $p = Projects::find($projectId);
            $ids = array_map(fn($m) => (int)$m['id'], Projects::assignableUsers($p));
            return array_values(array_intersect($vis, $ids));
        }
        if ($teamId) {
            $ids = array_map('intval', Database::get()->column('SELECT user_id FROM team_members WHERE team_id = ?', [$teamId]));
            return array_values(array_intersect($vis, $ids));
        }
        return $vis;
    }

    /**
     * Planned load against capacity for the next weeks. Load counts every project of each person
     * (a person working on two projects has one capacity), hours of projects the viewer cannot see
     * appear only as «otros proyectos». People without weekly hours are listed apart, not guessed.
     */
    public static function capacity(?int $projectId, ?int $teamId, bool $mine, ?string $from = null): array
    {
        $cfg = Analysis::settings();
        $people = self::people($projectId, $teamId, $mine);
        $week = Monitoring::week($from);
        $wl = $people ? Monitoring::workload($week, (int)$cfg['horizon_weeks'], null, null, $people) : ['weeks' => [], 'people' => []];
        $weeks = [];
        foreach ($wl['weeks'] as $w) $weeks[$w] = ['week' => $w, 'capacity' => 0.0, 'planned' => 0.0, 'planned_unknown' => 0.0, 'people' => 0, 'no_capacity' => [], 'tasks' => []];
        $noEstimate = $noDate = [];
        foreach ($wl['people'] as $r) {
            foreach ($r['weeks'] as $w => $c) {
                if ($c['capacity'] === null) { $weeks[$w]['no_capacity'][] = $r['user']['name']; $weeks[$w]['planned_unknown'] += $c['planned']; continue; }
                $weeks[$w]['capacity'] += $c['capacity'];
                $weeks[$w]['planned'] += $c['planned'];
                $weeks[$w]['people']++;
                foreach ($c['tasks'] as $t) if ($t['project_id']) { $k = $t['key']; $weeks[$w]['tasks'][$k] = ['key' => $k, 'title' => $t['title'], 'hours' => round(($weeks[$w]['tasks'][$k]['hours'] ?? 0) + $t['hours'], 2), 'project_id' => $t['project_id']]; }
            }
            foreach ($r['notes']['no_estimate'] ?? [] as $k) $noEstimate[$k] = true;
            foreach ($r['notes']['no_date'] ?? [] as $k) $noDate[$k] = true;
        }
        foreach ($weeks as $w => &$x) {
            $x['capacity'] = round($x['capacity'], 2);
            $x['planned'] = round($x['planned'], 2);
            $x['gap'] = $x['people'] ? round($x['planned'] - $x['capacity'], 2) : null;
            $x['pct'] = $x['capacity'] > 0 ? (int)round($x['planned'] / $x['capacity'] * 100) : null;
            $x['class'] = $x['people'] ? Monitoring::loadClass($x['planned'], $x['capacity'] ?: null, false, Monitoring::settings()) : 'unknown';
            $x['message'] = $x['gap'] !== null && $x['gap'] > 0.05 ? self::overloadMessage($x['gap']) : null;
            $x['tasks'] = array_values($x['tasks']);
            usort($x['tasks'], fn($a, $b) => $b['hours'] <=> $a['hours']);
            $x['tasks'] = array_slice($x['tasks'], 0, 12);
        }
        unset($x);
        return ['weeks' => array_values($weeks), 'people' => $wl['people'], 'no_estimate' => array_keys($noEstimate), 'no_date' => array_keys($noDate), 'count' => count($people)];
    }

    /** «La carga planificada supera la capacidad disponible en 18 horas…» */
    public static function overloadMessage(float $gap): string
    {
        $n = rtrim(rtrim(number_format($gap, 1, ',', '.'), '0'), ',');
        return "La carga planificada supera la capacidad disponible en $n " . ($n === '1' ? 'hora' : 'horas') . '. Se recomienda revisar fechas, prioridades o distribución del trabajo.';
    }

    /** The reorganisation alternatives offered for an overload (no one is told to work longer hours). */
    public const ALTERNATIVES = [
        'Reprogramar tareas cuya fecha permita moverse.', 'Distribuir trabajo entre integrantes autorizados con horas libres.', 'Modificar prioridades.',
        'Dividir tareas grandes.', 'Revisar estimaciones.', 'Reducir el alcance.', 'Ajustar la planificación de los próximos sprints.',
    ];

    // ------------------------------------------------------------------ sprint snapshot (shared with the simulator)

    /** Open top-level tasks of a sprint with remaining hours, people, priority and dependencies; capacity per person. */
    public static function snapshot(array $sprint): array
    {
        $db = Database::get();
        $sid = (int)$sprint['id'];
        $tasks = $db->all("SELECT t.id, t.number, t.title, t.estimate_hours, t.remaining_hours, t.due_date, t.is_blocked, t.assignee_id, t.priority_id, t.updated_at, t.sprint_id,
                p.project_key, COALESCE(pr.weight, 0) AS weight, pr.name AS priority_name, s.category,
                COALESCE((SELECT SUM(te.minutes) FROM time_entries te WHERE te.task_id = t.id AND te.deleted_at IS NULL), 0) AS logged_minutes,
                (SELECT COUNT(*) FROM task_due_changes d WHERE d.task_id = t.id AND d.old_due IS NOT NULL) AS due_changes
            FROM tasks t JOIN projects p ON p.id = t.project_id JOIN statuses s ON s.id = t.status_id LEFT JOIN priorities pr ON pr.id = t.priority_id
            WHERE t.sprint_id = ? AND t.parent_id IS NULL AND t.deleted_at IS NULL ORDER BY t.id", [$sid]);
        $ids = array_map(fn($t) => (int)$t['id'], $tasks);
        $co = $deps = [];
        if ($ids) {
            $in = Database::in($ids);
            foreach ($db->all("SELECT task_id, user_id FROM task_assignees WHERE task_id IN ($in)", $ids) as $r) $co[(int)$r['task_id']][] = (int)$r['user_id'];
            // «A bloquea a B»: B in the sprint depends on an open A (in or out of the sprint)
            foreach ($db->all("SELECT l.target_task_id AS b, a.id AS a, CONCAT(pa.project_key, '-', a.number) AS a_key, a.is_blocked AS a_blocked, a.due_date AS a_due, a.sprint_id AS a_sprint
                FROM task_links l JOIN tasks a ON a.id = l.source_task_id JOIN projects pa ON pa.id = a.project_id JOIN statuses sa ON sa.id = a.status_id
                WHERE l.link_type = 'blocks' AND l.target_task_id IN ($in) AND a.deleted_at IS NULL AND sa.category <> 'done'", $ids) as $d) $deps[(int)$d['b']][] = $d;
        }
        $out = [];
        $people = [];
        foreach ($tasks as $t) {
            $resp = array_values(array_unique(array_filter(array_merge([(int)$t['assignee_id']], $co[(int)$t['id']] ?? []))));
            foreach ($resp as $u) $people[$u] = true;
            $out[(int)$t['id']] = ['id' => (int)$t['id'], 'key' => $t['project_key'] . '-' . $t['number'], 'title' => $t['title'],
                'estimate' => $t['estimate_hours'] !== null ? (float)$t['estimate_hours'] : null, 'remaining_set' => $t['remaining_hours'] !== null ? (float)$t['remaining_hours'] : null,
                'logged' => round((int)$t['logged_minutes'] / 60, 2), 'due' => $t['due_date'], 'blocked' => (bool)$t['is_blocked'], 'people' => $resp,
                'priority_id' => $t['priority_id'] ? (int)$t['priority_id'] : null, 'weight' => (int)$t['weight'], 'priority' => $t['priority_name'],
                'done' => $t['category'] === 'done', 'in' => true, 'updated_at' => $t['updated_at'], 'due_changes' => (int)$t['due_changes'], 'deps' => $deps[(int)$t['id']] ?? []];
        }
        $project = Projects::find((int)$sprint['project_id']);
        $members = Projects::assignableUsers($project);
        $memberIds = array_map(fn($m) => (int)$m['id'], $members);
        $avail = Capacity::availability($sprint, $memberIds);
        $cap = [];
        foreach ($members as $m) $cap[(int)$m['id']] = ['name' => $m['name'], 'hours' => $avail[(int)$m['id']]['hours'] ?? null, 'set' => $avail[(int)$m['id']]['set'] ?? false];
        $today = today();
        $total = $sprint['start_date'] && $sprint['end_date'] ? Metrics::workdays($sprint['start_date'], $sprint['end_date']) : 0;
        $left = $sprint['end_date'] ? ($sprint['state'] === 'active' ? Metrics::workdays(max($today, (string)$sprint['start_date']), $sprint['end_date']) : $total) : 0;
        return ['sprint' => ['id' => $sid, 'name' => $sprint['name'], 'state' => $sprint['state'], 'start' => $sprint['start_date'], 'end' => $sprint['end_date'], 'project_id' => (int)$sprint['project_id']],
            'tasks' => $out, 'capacity' => $cap, 'days_total' => $total, 'days_left' => $left, 'today' => $today];
    }

    /**
     * Indicators of a sprint snapshot (real or simulated). Pure: no database. Used by the risk
     * engine and by the simulator, so both always agree.
     * Capacity = available hours of the people responsible for its open tasks, × working days left ÷ working days.
     */
    public static function evaluate(array $m, array $cfg, ?float $histDevPct = null): array
    {
        $pending = 0.0; $open = 0; $unest = 0; $blocked = 0; $depRisk = 0; $reprog = 0; $lateDue = 0; $done = 0;
        $load = [];
        $resp = [];
        foreach ($m['tasks'] as $t) {
            if (!$t['in']) continue;
            if ($t['done']) { $done++; continue; }
            $open++;
            $rem = Metrics::remaining($t['estimate'], $t['remaining_set'], $t['logged']);
            if ($rem === null) $unest++; else $pending += $rem;
            if ($t['blocked']) $blocked++;
            foreach ($t['deps'] as $d) if (!empty($d['a_blocked']) || ($d['a_due'] && $d['a_due'] < $m['today'])) { $depRisk++; break; }
            if ($t['due_changes'] >= $cfg['reprogram_threshold']) $reprog++;
            if ($t['due'] && $m['sprint']['end'] && $t['due'] > $m['sprint']['end']) $lateDue++;
            $n = count($t['people']);
            foreach ($t['people'] as $u) { $resp[$u] = true; if ($rem !== null) $load[$u] = ($load[$u] ?? 0) + $rem / $n; }
        }
        $capTotal = 0.0; $capKnown = false; $capMissing = [];
        foreach (array_keys($resp) as $u) {
            $h = $m['capacity'][$u]['hours'] ?? null;
            if ($h === null) { $capMissing[] = $m['capacity'][$u]['name'] ?? ('#' . $u); continue; }
            $capKnown = true;
            $capTotal += $h;
        }
        $capLeft = $capKnown ? Metrics::capacityLeft($capTotal, $m['days_total'], $m['days_left']) : null;
        $pending = round($pending, 2);
        $people = [];
        foreach ($load as $u => $h) {
            $c = $m['capacity'][$u]['hours'] ?? null;
            $cl = $c !== null ? Metrics::capacityLeft($c, $m['days_total'], $m['days_left']) : null;
            $people[$u] = ['name' => $m['capacity'][$u]['name'] ?? ('#' . $u), 'load' => round($h, 2), 'capacity' => $cl, 'over' => $cl !== null && $h > $cl + 0.001];
        }
        // ---- risk factors (points are documented in docs/ANALISIS-1.13.md)
        $f = [];
        $hh = fn(float $x) => Metrics::h($x);
        $ended = $m['sprint']['end'] && $m['sprint']['end'] < $m['today'] && $m['sprint']['state'] === 'active';
        if ($ended && $open) $f[] = ['points' => 3, 'kind' => 'ended', 'text' => 'La fecha de fin (' . fmt_date($m['sprint']['end'], true) . ") ya pasó con $open " . ($open === 1 ? 'tarea pendiente' : 'tareas pendientes') . '.'];
        if ($capLeft !== null && $pending > $capLeft + 0.001) {
            $ratio = $capLeft > 0 ? $pending / $capLeft * 100 : INF;
            $f[] = ['points' => $ratio >= $cfg['overload_high_pct'] ? 3 : 2, 'kind' => 'overload', 'text' => 'Mantiene ' . $hh($pending) . ' estimadas de trabajo pendiente y dispone de ' . $hh($capLeft) . ' de capacidad planificada (' . Metrics::h(round($pending - $capLeft, 2)) . ' por encima).'];
        }
        if ($blocked) $f[] = ['points' => $blocked >= 2 ? 2 : 1, 'kind' => 'blocked', 'text' => 'Contiene ' . ($blocked === 1 ? 'una tarea bloqueada' : "$blocked tareas bloqueadas") . '.'];
        if ($depRisk) $f[] = ['points' => 1, 'kind' => 'deps', 'text' => ($depRisk === 1 ? 'Una tarea depende' : "$depRisk tareas dependen") . ' de otra bloqueada o vencida.'];
        if (!$ended && $m['days_total'] && $m['days_left'] <= $cfg['soon_days'] && $open && $open * 2 > $open + $done) $f[] = ['points' => 1, 'kind' => 'soon', 'text' => "Quedan {$m['days_left']} días laborables y más de la mitad de las tareas sigue pendiente."];
        if ($reprog) $f[] = ['points' => 1, 'kind' => 'reprog', 'text' => ($reprog === 1 ? 'Una tarea ha cambiado' : "$reprog tareas han cambiado") . " de fecha {$cfg['reprogram_threshold']} veces o más."];
        $projected = null;
        if ($histDevPct !== null && $histDevPct >= $cfg['pattern_deviation_pct'] && $pending > 0 && $capLeft !== null) {
            $projected = round($pending * (1 + $histDevPct / 100), 2);
            if ($projected > $capLeft + 0.001 && $pending <= $capLeft + 0.001) $f[] = ['points' => 1, 'kind' => 'history', 'estimate' => true,
                'text' => 'Estimación: con la desviación habitual del proyecto (' . Metrics::pct($histDevPct, true) . '), lo pendiente pasaría a unas ' . $hh($projected) . '.'];
        }
        $missing = [];
        if ($open && !$capKnown) $missing[] = 'Nadie responsable de las tareas abiertas tiene horas disponibles configuradas.';
        if ($open && $unest * 2 >= $open) $missing[] = "$unest de $open tareas abiertas no tienen estimación.";
        if (!$m['sprint']['end']) $missing[] = 'El sprint no tiene fecha de fin.';
        $level = $open ? Metrics::riskLevel($f, !$missing, (int)$cfg['risk_moderate'], (int)$cfg['risk_elevated']) : 'low';
        return ['open' => $open, 'done' => $done, 'pending' => $pending, 'unestimated' => $unest, 'blocked' => $blocked, 'dep_risk' => $depRisk, 'reprogrammed' => $reprog, 'due_after_end' => $lateDue,
            'capacity' => $capKnown ? round($capTotal, 2) : null, 'capacity_left' => $capLeft, 'margin' => $capLeft !== null ? round($capLeft - $pending, 2) : null, 'cap_missing' => $capMissing,
            'people' => $people, 'factors' => $f, 'points' => array_sum(array_column($f, 'points')), 'missing' => $missing, 'level' => $level, 'projected' => $projected,
            'sentence' => self::sentence($level, $f, $missing, $open, 'Este sprint')];
    }

    public static function sentence(string $level, array $factors, array $missing, int $open, string $subject): string
    {
        if (!$open) return "$subject no tiene trabajo pendiente.";
        if ($level === 'nodata') return "$subject no se puede evaluar con fiabilidad: " . implode(' ', $missing);
        $facts = array_values(array_filter($factors, fn($x) => empty($x['estimate'])));
        if (!$facts) return "$subject presenta " . mb_strtolower(Analysis::RISK[$level][0]) . ': ninguna de las reglas de riesgo se cumple.';
        $parts = array_map(fn($x) => rtrim(lcfirst($x['text']), '.'), $facts);
        $last = array_pop($parts);
        return "$subject presenta " . mb_strtolower(Analysis::RISK[$level][0]) . ' porque ' . ($parts ? implode(', ', $parts) . ' y ' : '') . $last . '.';
    }

    // ------------------------------------------------------------------ risks

    /** Active and planned sprints of the projects, each with its rule-based evaluation. */
    public static function sprintRisks(array $pids): array
    {
        if (!$pids) return [];
        $cfg = Analysis::settings();
        $db = Database::get();
        $sprints = $db->all("SELECT s.*, p.name AS project_name FROM sprints s JOIN projects p ON p.id = s.project_id WHERE s.project_id IN (" . Database::in($pids) . ") AND s.state IN ('active','planned') AND p.archived_at IS NULL
            ORDER BY s.state = 'active' DESC, s.start_date IS NULL, s.start_date LIMIT 40", $pids);
        $dev = [];
        [$from, $to] = Analysis::period(null, null);
        $out = [];
        foreach ($sprints as $s) {
            $pid = (int)$s['project_id'];
            if (!array_key_exists($pid, $dev)) { $i = History::indicators([$pid], $from, $to); $dev[$pid] = $i['deviation']['enough'] ? $i['deviation']['median_pct'] : null; }
            $snap = self::snapshot($s);
            $ev = self::evaluate($snap, $cfg, $dev[$pid]);
            $out[] = ['sprint' => $snap['sprint'] + ['project_name' => $s['project_name']], 'eval' => $ev, 'snapshot' => $snap];
        }
        return $out;
    }

    /**
     * Rule-based risk of each project: overdue, blocked and dependency counts, the delivery date
     * against the capacity of the people responsible, and (with enough history) late finishes.
     */
    public static function projectRisks(array $pids): array
    {
        if (!$pids) return [];
        $cfg = Analysis::settings();
        $db = Database::get();
        $in = Database::in($pids);
        $today = today();
        [$from, $to] = Analysis::period(null, null);
        $tasks = $db->all("SELECT t.id, t.project_id, t.estimate_hours, t.remaining_hours, t.due_date, t.is_blocked, t.assignee_id,
                COALESCE((SELECT SUM(te.minutes) FROM time_entries te WHERE te.task_id = t.id AND te.deleted_at IS NULL), 0) AS logged_minutes
            FROM tasks t JOIN statuses s ON s.id = t.status_id WHERE t.project_id IN ($in) AND t.deleted_at IS NULL AND t.parent_id IS NULL AND s.category <> 'done'", $pids);
        $co = [];
        if ($tasks) { $ids = array_map(fn($t) => (int)$t['id'], $tasks); foreach ($db->all('SELECT task_id, user_id FROM task_assignees WHERE task_id IN (' . Database::in($ids) . ')', $ids) as $r) $co[(int)$r['task_id']][] = (int)$r['user_id']; }
        $by = [];
        foreach ($tasks as $t) $by[(int)$t['project_id']][] = $t;
        $deps = [];
        foreach (Monitoring::dependencyRisks($pids) as $d) $deps[(int)$d['b_project']] = ($deps[(int)$d['b_project']] ?? 0) + 1;
        $weekly = array_column($db->all("SELECT user_id, weekly_hours FROM workspace_members WHERE workspace_id = ? AND status = 'active'", [\Borealis\Security\Policy::wsId()]), 'weekly_hours', 'user_id');
        $out = [];
        foreach ($pids as $pid) {
            $p = Analysis::scope()['rows'][$pid] ?? null;
            if (!$p) continue;
            $list = $by[$pid] ?? [];
            $open = count($list);
            $pending = 0.0; $unest = 0; $overdue = 0; $blocked = 0; $people = []; $dated = 0;
            foreach ($list as $t) {
                $rem = Metrics::remaining($t['estimate_hours'] !== null ? (float)$t['estimate_hours'] : null, $t['remaining_hours'] !== null ? (float)$t['remaining_hours'] : null, (int)$t['logged_minutes'] / 60);
                if ($rem === null) $unest++; else $pending += $rem;
                if ($t['due_date']) { $dated++; if ($t['due_date'] < $today) $overdue++; }
                if ($t['is_blocked']) $blocked++;
                foreach (array_filter(array_merge([(int)$t['assignee_id']], $co[(int)$t['id']] ?? [])) as $u) $people[$u] = true;
            }
            $f = [];
            $missing = [];
            $n = fn(int $x, string $one, string $many) => $x === 1 ? $one : str_replace('%d', (string)$x, $many);
            if ($p['due_date'] && $p['due_date'] < $today && $open && $p['state'] !== 'completed') $f[] = ['points' => 3, 'kind' => 'ended', 'text' => 'La entrega del proyecto (' . fmt_date($p['due_date'], true) . ') ya pasó con ' . $n($open, '1 tarea abierta', '%d tareas abiertas') . '.'];
            elseif ($p['due_date'] && $open && $p['due_date'] >= $today) {
                $cap = 0.0; $known = false;
                $days = Metrics::workdays($today, $p['due_date']);
                foreach (array_keys($people) as $u) if (isset($weekly[$u]) && $weekly[$u] !== null) { $known = true; $cap += (float)$weekly[$u] * $days / 5; }
                if ($known && $pending > $cap + 0.001) {
                    $f[] = ['points' => ($cap > 0 && $pending / $cap * 100 >= $cfg['overload_high_pct']) ? 3 : 2, 'kind' => 'overload',
                        'text' => 'Quedan ' . Metrics::h($pending) . ' estimadas hasta la entrega (' . fmt_date($p['due_date'], true) . ') y sus responsables suman como máximo ' . Metrics::h(round($cap, 1)) . ' de capacidad en ese plazo (sin descontar otros proyectos).'];
                }
                if (!$known) $missing[] = 'Sus responsables no tienen horas semanales configuradas.';
            }
            if ($overdue) $f[] = ['points' => ($open >= 4 && $overdue * 4 >= $open) ? 2 : 1, 'kind' => 'overdue', 'text' => $n($overdue, '1 tarea vencida', '%d tareas vencidas') . " de $open abiertas."];
            if ($blocked) $f[] = ['points' => 1, 'kind' => 'blocked', 'text' => $n($blocked, '1 tarea bloqueada', '%d tareas bloqueadas') . '.'];
            if ($deps[$pid] ?? 0) $f[] = ['points' => 1, 'kind' => 'deps', 'text' => $n($deps[$pid], '1 tarea depende', '%d tareas dependen') . ' de otra bloqueada, vencida o que vence después.'];
            $hist = History::indicators([$pid], $from, $to);
            if ($hist['enough'] && $hist['late']['value'] !== null && $hist['late']['value'] >= 50) $f[] = ['points' => 1, 'kind' => 'history', 'text' => 'En el período analizado, el ' . Metrics::pct($hist['late']['value']) . " de las tareas con fecha finalizó fuera de plazo ({$hist['late']['num']} de {$hist['late']['den']})."];
            if ($open && !$dated && !$p['due_date']) $missing[] = 'Ninguna tarea abierta tiene fecha límite y el proyecto no tiene entrega.';
            if ($open && $unest * 2 >= $open && $p['due_date']) $missing[] = "$unest de $open tareas abiertas no tienen estimación.";
            $level = $open ? Metrics::riskLevel($f, !$missing || (bool)array_filter($f, fn($x) => $x['points'] >= 2), (int)$cfg['risk_moderate'], (int)$cfg['risk_elevated']) : 'low';
            $out[] = ['project' => ['id' => (int)$p['id'], 'name' => $p['name'], 'due_date' => $p['due_date'], 'state' => $p['state']], 'open' => $open, 'pending' => round($pending, 2),
                'unestimated' => $unest, 'factors' => $f, 'missing' => $missing, 'level' => $level, 'points' => array_sum(array_column($f, 'points')),
                'sentence' => self::sentence($level, $f, $missing, $open, 'El proyecto')];
        }
        $order = ['high' => 0, 'moderate' => 1, 'nodata' => 2, 'low' => 3];
        usort($out, fn($a, $b) => [$order[$a['level']], -$a['points'], $a['project']['name']] <=> [$order[$b['level']], -$b['points'], $b['project']['name']]);
        return $out;
    }

    // ------------------------------------------------------------------ recommendations

    /**
     * Operational recommendations from explicit rules. Each one: problem, evidence, action, impact
     * (when it can be estimated), confidence and a link. None of them changes anything.
     */
    public static function recommendations(array $pids, array $sprintRisks, array $capacity, array $patterns, array $repeated): array
    {
        $cfg = Analysis::settings();
        $out = [];
        $add = function (array $r) use (&$out) { $out[$r['key']] ??= $r; };
        foreach ($sprintRisks as $sr) {
            $ev = $sr['eval']; $sp = $sr['sprint'];
            $canSim = Analysis::canManage($sp['project_id']);
            if ($ev['margin'] !== null && $ev['margin'] < -0.001 && $ev['level'] !== 'nodata') {
                $gap = -$ev['margin'];
                $cands = array_values(array_filter($sr['snapshot']['tasks'], fn($t) => $t['in'] && !$t['done'] && Metrics::remaining($t['estimate'], $t['remaining_set'], $t['logged']) > 0));
                usort($cands, fn($a, $b) => [$a['weight'], -(Metrics::remaining($a['estimate'], $a['remaining_set'], $a['logged']))] <=> [$b['weight'], -(Metrics::remaining($b['estimate'], $b['remaining_set'], $b['logged']))]);
                [$pick, $sum] = Metrics::coverGap(array_map(fn($t) => $t + ['hours' => Metrics::remaining($t['estimate'], $t['remaining_set'], $t['logged'])], $cands), $gap);
                $add(['key' => 'overload:' . $sp['id'], 'kind' => 'overload', 'severity' => $ev['level'] === 'high' ? 'danger' : 'warn',
                    'problem' => "«{$sp['name']}» ({$sp['project_name']}) tiene más horas planificadas que capacidad disponible.",
                    'evidence' => ['Trabajo pendiente estimado: ' . Metrics::h($ev['pending']) . '.', 'Capacidad planificada restante: ' . Metrics::h($ev['capacity_left']) . '.', 'Diferencia: ' . Metrics::h($gap) . ' por encima.'],
                    'action' => 'Considera trasladar tareas de menor prioridad al siguiente sprint' . ($pick ? ': ' . implode(', ', array_map(fn($t) => $t['key'] . ' (' . Metrics::h($t['hours']) . ($t['priority'] ? ', ' . mb_strtolower($t['priority']) : '') . ')', $pick)) : '') . '. También puedes redistribuir trabajo, dividir tareas o revisar estimaciones.',
                    'impact' => $pick ? 'Trasladar ' . (count($pick) === 1 ? 'esa tarea' : 'esas tareas') . ' (' . Metrics::h($sum) . ') dejaría un margen de ' . Metrics::h(round($sum - $gap, 2)) . '.' : null,
                    'confidence' => $ev['unestimated'] ? 'Media: ' . $ev['unestimated'] . ' tareas sin estimar no cuentan' : 'Alta: datos actuales del sprint',
                    'link' => $canSim ? url('/analysis', ['tab' => 'scenarios', 'sprint_id' => $sp['id'], 'move' => implode(',', array_map(fn($t) => $t['id'], $pick))]) : null, 'link_label' => 'Probar en Escenarios', 'project_id' => $sp['project_id']]);
            }
            if ($ev['unestimated'] && $ev['level'] !== 'low') {
                $keys = array_values(array_map(fn($t) => $t['key'], array_filter($sr['snapshot']['tasks'], fn($t) => $t['in'] && !$t['done'] && Metrics::remaining($t['estimate'], $t['remaining_set'], $t['logged']) === null)));
                $add(['key' => 'unest:' . $sp['id'], 'kind' => 'quality', 'severity' => 'info', 'problem' => "«{$sp['name']}» tiene tareas sin estimación.",
                    'evidence' => [count($keys) . ' de ' . $ev['open'] . ' tareas abiertas no tienen horas estimadas: ' . implode(', ', array_slice($keys, 0, 6)) . (count($keys) > 6 ? '…' : '') . '.'],
                    'action' => 'Estímalas para que la carga y el riesgo del sprint reflejen todo el trabajo.', 'impact' => null, 'confidence' => 'Alta: dato registrado',
                    'link' => url('/projects/' . $sp['project_id'] . '/sprints'), 'link_label' => 'Abrir el sprint', 'task_key' => $keys[0] ?? null, 'project_id' => $sp['project_id']]);
            }
        }
        foreach ($capacity['weeks'] ?? [] as $w) {
            if ($w['gap'] === null || $w['gap'] <= 0.05) continue;
            $add(['key' => 'week:' . $w['week'], 'kind' => 'overload', 'severity' => 'warn', 'problem' => 'La semana del ' . fmt_date($w['week'], true) . ' tiene más trabajo planificado que capacidad.',
                'evidence' => ['Carga planificada: ' . Metrics::h($w['planned']) . ' de ' . $w['people'] . ($w['people'] === 1 ? ' persona' : ' personas') . ' con horas configuradas.', 'Capacidad: ' . Metrics::h($w['capacity']) . '.', 'Diferencia: ' . Metrics::h($w['gap']) . ' por encima.'],
                'action' => 'Revisa fechas, prioridades o distribución del trabajo de esa semana. Ampliar la jornada no se propone como solución.', 'impact' => 'Liberar ' . Metrics::h($w['gap']) . ' de esa semana la dejaría dentro de la capacidad.',
                'confidence' => $w['no_capacity'] ? 'Media: ' . count($w['no_capacity']) . ' personas sin horas configuradas no cuentan' : 'Alta: datos actuales', 'link' => url('/analysis', ['tab' => 'planning']), 'link_label' => 'Ver la planificación']);
        }
        if ($pids) {
            $db = Database::get();
            $in = Database::in($pids);
            $rows = $db->all("SELECT a.id, CONCAT(pa.project_key, '-', a.number) AS a_key, a.title, a.is_blocked, a.due_date, a.project_id, COUNT(DISTINCT b.id) AS n
                FROM task_links l JOIN tasks a ON a.id = l.source_task_id JOIN projects pa ON pa.id = a.project_id JOIN statuses sa ON sa.id = a.status_id
                JOIN tasks b ON b.id = l.target_task_id JOIN statuses sb ON sb.id = b.status_id
                WHERE l.link_type = 'blocks' AND a.project_id IN ($in) AND a.deleted_at IS NULL AND b.deleted_at IS NULL AND sa.category <> 'done' AND sb.category <> 'done' AND (a.is_blocked = 1 OR a.due_date < ?)
                GROUP BY a.id, pa.project_key, a.number, a.title, a.is_blocked, a.due_date, a.project_id HAVING n >= 2 ORDER BY n DESC LIMIT 10", array_merge($pids, [today()]));
            foreach ($rows as $r) {
                $n = (int)$r['n'];
                $add(['key' => 'blocker:' . $r['id'], 'kind' => 'blocked', 'severity' => 'danger', 'problem' => "{$r['a_key']} " . ($r['is_blocked'] ? 'está bloqueada' : 'está vencida') . " y frena a $n tareas.",
                    'evidence' => ["{$r['a_key']} «{$r['title']}» bloquea a $n tareas abiertas.", $r['is_blocked'] ? 'Está marcada como bloqueada.' : 'Venció el ' . fmt_date($r['due_date'], true) . '.'],
                    'action' => "Revisa prioritariamente el bloqueo de esta tarea, ya que afecta $n actividades dependientes.", 'impact' => "Resolverla liberaría $n tareas dependientes.",
                    'confidence' => 'Alta: dependencias registradas', 'task_key' => $r['a_key'], 'project_id' => (int)$r['project_id']]);
            }
        }
        foreach ($patterns as $pt) {
            if ($pt['kind'] !== 'estimate') continue;
            $add(['key' => 'pattern:' . $pt['key'], 'kind' => 'estimate', 'severity' => 'info', 'problem' => $pt['title'] . '.', 'evidence' => [$pt['text']],
                'action' => 'Revisa las estimaciones futuras de esta categoría utilizando los resultados históricos (la sugerencia aparece al estimar una tarea con esa etiqueta).', 'impact' => null,
                'confidence' => $pt['confidence'] . ": $pt[n] tareas comparables", 'link' => url('/analysis', ['tab' => 'estimates', 'project_id' => $pt['project_id']]), 'link_label' => 'Ver estimaciones', 'project_id' => $pt['project_id']]);
        }
        foreach ($repeated as $r) {
            if (!$r['open']) continue;
            $add(['key' => 'reprog:' . $r['task_id'], 'kind' => 'reprogram', 'severity' => 'warn', 'problem' => "{$r['key']} ha cambiado de fecha {$r['n']} veces.",
                'evidence' => ["«{$r['title']}»: {$r['n']} cambios de su fecha límite en el período (umbral: {$cfg['reprogram_threshold']})."],
                'action' => 'Revisa el alcance, los bloqueos y las dependencias antes de establecer un nuevo compromiso.', 'impact' => null, 'confidence' => 'Alta: historial de fechas',
                'task_key' => $r['key'], 'project_id' => $r['project_id']]);
        }
        $rank = ['danger' => 0, 'warn' => 1, 'info' => 2];
        $out = array_values($out);
        usort($out, fn($a, $b) => $rank[$a['severity']] <=> $rank[$b['severity']]);
        return $out;
    }
}
