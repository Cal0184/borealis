<?php
declare(strict_types=1);

namespace Borealis\Services\Analysis;

use Borealis\Core\Database;
use Borealis\Services\Capacity;
use Borealis\Services\Monitoring;

/**
 * Análisis (1.13): what happened. Historical indicators, trends, recurring patterns, sprint
 * comparison and data quality, read from the records Borealis already keeps (tasks, hours,
 * due-date history, activity log, sprint commitments). Nothing is reconstructed without evidence:
 * a record that is missing is counted as excluded and said so.
 *
 * Every function takes the project ids already checked by the caller (Analysis::pids) and the
 * filters [from, to, mine, team_id]. Queries are set-based (no query per task).
 */
final class History
{
    // ------------------------------------------------------------------ data

    /** Tasks finished in the period (top-level, and subtasks with their own date), with their logged hours and labels. */
    public static function finished(array $pids, string $from, string $to, array $f = []): array
    {
        if (!$pids) return [];
        $in = Database::in($pids);
        [$pw, $pp] = Analysis::peopleFilter($f);
        $rows = Database::get()->all("SELECT t.id, t.project_id, t.parent_id, t.number, t.title, t.estimate_hours, t.completed_at, t.completed_due_date, t.due_date, t.created_at, t.started_at,
                t.epic_id, t.sprint_id, p.project_key, p.name AS project_name, COALESCE(te.m, 0) AS logged_minutes
            FROM tasks t JOIN projects p ON p.id = t.project_id JOIN statuses s ON s.id = t.status_id
            LEFT JOIN (SELECT task_id, SUM(minutes) AS m FROM time_entries WHERE deleted_at IS NULL AND project_id IN ($in) GROUP BY task_id) te ON te.task_id = t.id
            WHERE t.project_id IN ($in) AND t.deleted_at IS NULL AND s.category = 'done' AND t.completed_at BETWEEN ? AND ?
              AND (t.parent_id IS NULL OR t.due_date IS NOT NULL OR t.completed_due_date IS NOT NULL) $pw",
            array_merge($pids, $pids, [date('Y-m-d', strtotime($from . ' -1 day')) . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 23:59:59'], $pp));
        $out = [];
        foreach ($rows as $r) {
            $d = local_date('Y-m-d', (string)$r['completed_at']); // the installation's day, as everywhere else
            if ($d < $from || $d > $to) continue;
            $r['done_day'] = $d;
            $r['key'] = $r['project_key'] . '-' . $r['number'];
            $out[(int)$r['id']] = $r;
        }
        if ($out) {
            $ids = array_keys($out);
            foreach (Database::get()->all('SELECT tl.task_id, l.id, l.name FROM task_labels tl JOIN labels l ON l.id = tl.label_id WHERE tl.task_id IN (' . Database::in($ids) . ')', $ids) as $l) {
                $out[(int)$l['task_id']]['labels'][(int)$l['id']] = $l['name'];
            }
        }
        return array_values($out);
    }

    /** Punctuality of a finished task from its recorded due date: 'ontime' | 'late' | null (excluded). */
    public static function punctuality(array $t): ?string
    {
        $due = $t['completed_due_date'] ?? null;
        if (!$due) return null;
        return $t['done_day'] <= $due ? 'ontime' : 'late';
    }

    /** Deviation of a finished task: null when it cannot be compared (and why, in $why). */
    public static function deviation(array $t, float $minHours, ?string &$why = null): ?array
    {
        $why = null;
        if ($t['parent_id']) { $why = 'subtask'; return null; }
        $est = $t['estimate_hours'] !== null ? (float)$t['estimate_hours'] : null;
        if ($est === null || $est <= 0) { $why = 'no_estimate'; return null; }
        if ($est < $minHours) { $why = 'small'; return null; }
        if ((int)$t['logged_minutes'] <= 0) { $why = 'no_logged'; return null; }
        return Monitoring::deviation($est, (int)$t['logged_minutes']);
    }

    // ------------------------------------------------------------------ indicators

    public static function indicators(array $pids, string $from, string $to, array $f = []): array
    {
        $cfg = Analysis::settings();
        $rows = self::finished($pids, $from, $to, $f);
        $on = $late = $noDate = 0;
        $devPct = $devH = [];
        $ex = ['no_estimate' => 0, 'no_logged' => 0, 'small' => 0];
        foreach ($rows as $t) {
            $p = self::punctuality($t);
            if ($p === 'ontime') $on++; elseif ($p === 'late') $late++; else $noDate++;
            $d = self::deviation($t, (float)$cfg['deviation_min_hours'], $why);
            if ($d) { $devPct[] = $d['pct']; $devH[] = $d['hours']; } elseif ($why && $why !== 'subtask') $ex[$why]++;
        }
        $top = count(array_filter($rows, fn($t) => !$t['parent_id']));
        return [
            'period' => [$from, $to], 'finished' => count($rows),
            'ontime' => Metrics::rate($on, $on + $late) + ['excluded' => $noDate],
            'late' => Metrics::rate($late, $on + $late) + ['excluded' => $noDate],
            'deviation' => ['median_pct' => Metrics::median($devPct), 'median_hours' => Metrics::median($devH), 'p25' => Metrics::percentile($devPct, 25), 'p75' => Metrics::percentile($devPct, 75),
                'n' => count($devPct), 'top' => $top, 'excluded' => $ex, 'enough' => count($devPct) >= $cfg['min_sample']],
            'reprogram' => self::reprogramming($pids, $from, $to, $f),
            'blocks' => self::blocks($pids, $from, $to, $f),
            'sprints' => self::sprints($pids, $from, $to),
            'backlog' => self::backlog($pids, $from, $to, $f),
            'enough' => ($on + $late) >= $cfg['min_sample'],
            'min_sample' => $cfg['min_sample'],
        ];
    }

    /** Due-date changes of an existing date in the period ÷ tasks with a due date that were open during the period. */
    public static function reprogramming(array $pids, string $from, string $to, array $f = []): array
    {
        if (!$pids) return ['changes' => 0, 'tasks' => 0, 'reprogrammed' => 0, 'rate' => null, 'repeated' => []];
        $db = Database::get();
        $in = Database::in($pids);
        [$pw, $pp] = Analysis::peopleFilter($f);
        $range = [$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'];
        $byTask = $db->all("SELECT d.task_id, COUNT(*) AS n, t.title, t.number, p.project_key, t.project_id, t.completed_at
            FROM task_due_changes d JOIN tasks t ON t.id = d.task_id JOIN projects p ON p.id = t.project_id
            WHERE t.project_id IN ($in) AND t.deleted_at IS NULL AND d.old_due IS NOT NULL AND (d.new_due IS NULL OR d.new_due <> d.old_due) AND d.changed_at >= ? AND d.changed_at < ? $pw
            GROUP BY d.task_id, t.title, t.number, p.project_key, t.project_id, t.completed_at ORDER BY n DESC", array_merge($pids, $range, $pp));
        $tasks = (int)$db->value("SELECT COUNT(*) FROM tasks t WHERE t.project_id IN ($in) AND t.deleted_at IS NULL AND t.due_date IS NOT NULL AND t.created_at < ? AND (t.completed_at IS NULL OR t.completed_at >= ?) $pw",
            array_merge($pids, [$range[1], $range[0]], $pp));
        $changes = array_sum(array_map(fn($r) => (int)$r['n'], $byTask));
        $thr = Analysis::settings()['reprogram_threshold'];
        $repeated = array_values(array_filter(array_map(fn($r) => ['task_id' => (int)$r['task_id'], 'key' => $r['project_key'] . '-' . $r['number'], 'title' => $r['title'], 'n' => (int)$r['n'], 'project_id' => (int)$r['project_id'], 'open' => !$r['completed_at']], $byTask), fn($r) => $r['n'] >= $thr));
        return ['changes' => $changes, 'tasks' => $tasks, 'reprogrammed' => count($byTask), 'rate' => $tasks ? round($changes / $tasks, 2) : null,
            'pct_tasks' => Metrics::rate(count($byTask), $tasks), 'repeated' => $repeated];
    }

    /** Block periods from the activity log («Marcó como bloqueada» → «Desbloqueó»). */
    public static function blocks(array $pids, string $from, string $to, array $f = []): array
    {
        if (!$pids) return ['closed' => 0, 'median_days' => null, 'open' => [], 'unknown_start' => 0];
        $db = Database::get();
        $in = Database::in($pids);
        [$pw, $pp] = Analysis::peopleFilter($f);
        $ev = $db->all("SELECT a.entity_id AS task_id, a.action, a.created_at FROM activity a JOIN tasks t ON t.id = a.entity_id
            WHERE a.project_id IN ($in) AND a.entity_type = 'task' AND a.action IN ('blocked','unblocked') AND a.created_at < ? AND t.deleted_at IS NULL $pw ORDER BY a.entity_id, a.id",
            array_merge($pids, [date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'], $pp));
        $start = [];
        $durations = [];
        $unknown = 0;
        foreach ($ev as $e) {
            $tid = (int)$e['task_id'];
            if ($e['action'] === 'blocked') { $start[$tid] = $e['created_at']; continue; }
            if (!isset($start[$tid])) { if ($e['created_at'] >= $from) $unknown++; continue; }
            if ($e['created_at'] >= $from . ' 00:00:00') $durations[] = round((strtotime($e['created_at']) - strtotime($start[$tid])) / 86400, 1);
            unset($start[$tid]);
        }
        $open = $db->all("SELECT t.id, t.title, t.number, p.project_key, t.blocked_at, t.blocked_reason, t.project_id FROM tasks t JOIN projects p ON p.id = t.project_id JOIN statuses s ON s.id = t.status_id
            WHERE t.project_id IN ($in) AND t.deleted_at IS NULL AND t.is_blocked = 1 AND s.category <> 'done' $pw ORDER BY t.blocked_at", array_merge($pids, $pp));
        return ['closed' => count($durations), 'median_days' => Metrics::median($durations), 'open' => array_map(fn($o) => $o + ['key' => $o['project_key'] . '-' . $o['number'],
            'days' => $o['blocked_at'] ? (int)floor((time() - strtotime($o['blocked_at'] . ' UTC')) / 86400) : null], $open), 'unknown_start' => $unknown];
    }

    /**
     * Closed sprints of the period with what they committed, added, completed and moved.
     * Since 1.12 from sprint_commitments; older sprints from the counts saved when they closed
     * (marked «aproximado»: those counts include work added later).
     */
    public static function sprints(array $pids, string $from, string $to, int $limit = 24): array
    {
        if (!$pids) return [];
        $db = Database::get();
        $in = Database::in($pids);
        $sprints = $db->all("SELECT s.*, p.name AS project_name FROM sprints s JOIN projects p ON p.id = s.project_id WHERE s.project_id IN ($in) AND s.state = 'completed' AND s.completed_at BETWEEN ? AND ?
            ORDER BY s.completed_at DESC LIMIT $limit", array_merge($pids, [$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 23:59:59']));
        if (!$sprints) return [];
        $sids = array_map(fn($s) => (int)$s['id'], $sprints);
        $agg = [];
        foreach ($db->all('SELECT sc.sprint_id, sc.planned, sc.outcome, COUNT(*) AS n, SUM(COALESCE(sc.estimate_hours, 0)) AS h, SUM(sc.estimate_hours IS NULL) AS unest,
                SUM(COALESCE((SELECT SUM(te.minutes) FROM time_entries te WHERE te.task_id = sc.task_id AND te.deleted_at IS NULL), 0)) AS logged
            FROM sprint_commitments sc JOIN tasks t ON t.id = sc.task_id WHERE sc.sprint_id IN (' . Database::in($sids) . ') AND t.deleted_at IS NULL GROUP BY sc.sprint_id, sc.planned, sc.outcome', $sids) as $r) {
            $agg[(int)$r['sprint_id']][] = $r;
        }
        $out = [];
        foreach ($sprints as $s) {
            $sid = (int)$s['id'];
            $c = ['committed' => 0, 'committed_hours' => 0.0, 'committed_done' => 0, 'committed_done_hours' => 0.0, 'added' => 0, 'added_done' => 0, 'added_hours' => 0.0,
                'moved' => 0, 'backlog' => 0, 'unknown' => 0, 'unestimated' => 0, 'logged' => 0.0];
            foreach ($agg[$sid] ?? [] as $r) {
                $n = (int)$r['n']; $h = (float)$r['h'];
                $c['logged'] += (int)$r['logged'] / 60;
                if ($r['planned'] === null) { $c['unknown'] += $n; continue; }
                if ((int)$r['planned'] === 1) {
                    $c['committed'] += $n; $c['committed_hours'] += $h; $c['unestimated'] += (int)$r['unest'];
                    if ($r['outcome'] === 'done') { $c['committed_done'] += $n; $c['committed_done_hours'] += $h; }
                } else {
                    $c['added'] += $n; $c['added_hours'] += $h;
                    if ($r['outcome'] === 'done') $c['added_done'] += $n;
                }
                if ($r['outcome'] === 'moved') $c['moved'] += $n;
                if ($r['outcome'] === 'pending') $c['backlog'] += $n;
            }
            if ($c['committed'] > 0) { $source = 'commitment'; $rate = Metrics::rate($c['committed_done'], $c['committed']); }
            elseif ($s['committed_count'] !== null && (int)$s['committed_count'] > 0 && $s['completed_count'] !== null) { $source = 'close'; $rate = Metrics::rate(min((int)$s['completed_count'], (int)$s['committed_count']), (int)$s['committed_count']); }
            else { $source = 'none'; $rate = Metrics::rate(0, 0); }
            $out[] = ['id' => $sid, 'name' => $s['name'], 'project_id' => (int)$s['project_id'], 'project_name' => $s['project_name'], 'start' => $s['start_date'], 'end' => $s['end_date'],
                'closed' => local_date('Y-m-d', (string)$s['completed_at']), 'goal' => $s['goal'], 'source' => $source, 'rate' => $rate, 'c' => $c,
                'committed_count' => $s['committed_count'] !== null ? (int)$s['committed_count'] : null, 'completed_count' => $s['completed_count'] !== null ? (int)$s['completed_count'] : null];
        }
        return $out;
    }

    /** Pending work at the end of each week of the period (top-level tasks). */
    public static function backlog(array $pids, string $from, string $to, array $f = []): array
    {
        if (!$pids) return [];
        $in = Database::in($pids);
        [$pw, $pp] = Analysis::peopleFilter($f);
        $rows = Database::get()->all("SELECT t.created_at, t.completed_at FROM tasks t WHERE t.project_id IN ($in) AND t.deleted_at IS NULL AND t.parent_id IS NULL AND t.created_at < ? $pw",
            array_merge($pids, [date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'], $pp));
        $created = $done = [];
        foreach ($rows as $r) { $created[] = local_date('Y-m-d', (string)$r['created_at']); if ($r['completed_at']) $done[] = local_date('Y-m-d', (string)$r['completed_at']); }
        sort($created); sort($done);
        $points = [];
        $cur = date('Y-m-d', strtotime(Monitoring::week($from) . ' 12:00:00 +6 days'));
        for ($i = 0; $i < 160 && $cur <= date('Y-m-d', strtotime(Monitoring::week($to) . ' 12:00:00 +6 days')); $i++) {
            $day = min($cur, $to);
            $c = self::countUpTo($created, $day);
            $d = self::countUpTo($done, $day);
            $wkStart = date('Y-m-d', strtotime($cur . ' 12:00:00 -6 days'));
            $points[] = ['week' => $wkStart, 'day' => $day, 'pending' => $c - $d, 'created' => $c - self::countUpTo($created, date('Y-m-d', strtotime($wkStart . ' -1 day'))),
                'done' => $d - self::countUpTo($done, date('Y-m-d', strtotime($wkStart . ' -1 day')))];
            $cur = date('Y-m-d', strtotime($cur . ' 12:00:00 +7 days'));
        }
        return $points;
    }

    private static function countUpTo(array $sorted, string $day): int
    {
        $lo = 0; $hi = count($sorted);
        while ($lo < $hi) { $mid = intdiv($lo + $hi, 2); if ($sorted[$mid] <= $day) $lo = $mid + 1; else $hi = $mid; }
        return $lo;
    }

    // ------------------------------------------------------------------ trends

    /** Monthly (or weekly, for periods of 10 weeks or less) series of punctuality, deviation and reprogramming, with their trend. */
    public static function trends(array $pids, string $from, string $to, array $f = []): array
    {
        $cfg = Analysis::settings();
        $weekly = (strtotime($to) - strtotime($from)) <= 70 * 86400;
        $bucket = fn(string $d) => $weekly ? Monitoring::week($d) : substr($d, 0, 7);
        $b = [];
        for ($d = $weekly ? Monitoring::week($from) : substr($from, 0, 7) . '-01', $i = 0; $d <= $to && $i < 200; $i++) {
            $b[$bucket($d)] = ['label' => $weekly ? 'Semana del ' . fmt_date($d) : self::month($d), 'on' => 0, 'late' => 0, 'dev' => [], 'reprog' => 0];
            $d = date('Y-m-d', strtotime($d . ($weekly ? ' 12:00:00 +7 days' : ' 12:00:00 +1 month')));
        }
        foreach (self::finished($pids, $from, $to, $f) as $t) {
            $k = $bucket($t['done_day']);
            if (!isset($b[$k])) continue;
            $p = self::punctuality($t);
            if ($p === 'ontime') $b[$k]['on']++; elseif ($p === 'late') $b[$k]['late']++;
            if ($d = self::deviation($t, (float)$cfg['deviation_min_hours'])) $b[$k]['dev'][] = $d['pct'];
        }
        if ($pids) {
            $in = Database::in($pids);
            [$pw, $pp] = Analysis::peopleFilter($f);
            foreach (Database::get()->all("SELECT d.changed_at FROM task_due_changes d JOIN tasks t ON t.id = d.task_id WHERE t.project_id IN ($in) AND t.deleted_at IS NULL AND d.old_due IS NOT NULL
                AND d.changed_at >= ? AND d.changed_at < ? $pw", array_merge($pids, [$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'], $pp)) as $r) {
                $k = $bucket(local_date('Y-m-d', (string)$r['changed_at']));
                if (isset($b[$k])) $b[$k]['reprog']++;
            }
        }
        $series = ['ontime' => [], 'deviation' => [], 'reprogram' => []];
        foreach ($b as $k => $x) {
            $den = $x['on'] + $x['late'];
            $series['ontime'][] = ['key' => $k, 'label' => $x['label'], 'value' => $den ? round($x['on'] / $den * 100, 1) : null, 'n' => $den];
            $series['deviation'][] = ['key' => $k, 'label' => $x['label'], 'value' => $x['dev'] ? Metrics::median($x['dev']) : null, 'n' => count($x['dev'])];
            $series['reprogram'][] = ['key' => $k, 'label' => $x['label'], 'value' => (float)$x['reprog'], 'n' => $x['reprog']];
        }
        $min = (int)$cfg['min_sample'];
        return ['weekly' => $weekly, 'series' => $series,
            'trend' => ['ontime' => Metrics::trend($series['ontime'], $min, 3, (float)$cfg['trend_min_change']),
                'deviation' => Metrics::trend($series['deviation'], $min, 3, (float)$cfg['trend_min_change'])]];
    }

    private static function month(string $d): string
    {
        $m = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        return ucfirst($m[(int)substr($d, 5, 2)]) . ' ' . substr($d, 0, 4);
    }

    // ------------------------------------------------------------------ patterns

    /**
     * Situations that repeat, each with its period, number of records and limits. Only reported
     * with at least «min_sample» records; worded as an association, never as a cause, and never
     * about a person.
     */
    public static function patterns(array $pids, string $from, string $to, array $f = []): array
    {
        $cfg = Analysis::settings();
        $min = (int)$cfg['min_sample'];
        $out = [];
        $rows = self::finished($pids, $from, $to, $f);
        // 1) categories (labels) that usually exceed their estimate
        $byLabel = [];
        $all = [];
        foreach ($rows as $t) {
            if (!($d = self::deviation($t, (float)$cfg['deviation_min_hours']))) continue;
            $all[] = $d['pct'];
            foreach ($t['labels'] ?? [] as $lid => $name) { $byLabel[$lid]['name'] = $name; $byLabel[$lid]['project'] = $t['project_name']; $byLabel[$lid]['pid'] = (int)$t['project_id']; $byLabel[$lid]['pct'][] = $d['pct']; $byLabel[$lid]['h'][] = $d['hours']; }
        }
        foreach ($byLabel as $lid => $l) {
            $n = count($l['pct']);
            if ($n < $min) continue;
            $med = Metrics::median($l['pct']);
            if ($med < $cfg['pattern_deviation_pct']) continue;
            $out[] = ['kind' => 'estimate', 'key' => 'label:' . $lid, 'project_id' => $l['pid'], 'label_id' => $lid,
                'title' => "Las tareas con la etiqueta «{$l['name']}» suelen superar su estimación",
                'text' => 'Mediana de ' . Metrics::pct($med, true) . ' (' . Metrics::h(Metrics::median($l['h']), true) . " por tarea) en $n tareas finalizadas de {$l['project']}"
                    . ($all ? '; en el conjunto analizado la mediana es ' . Metrics::pct(Metrics::median($all), true) . '.' : '.'),
                'n' => $n, 'confidence' => Metrics::confidence($n, $min), 'caveat' => 'Compara horas registradas con horas estimadas. No indica por qué ocurre.'];
        }
        // 2) stages (epics) with more reprogramming than the rest
        if ($pids) {
            $in = Database::in($pids);
            [$pw, $pp] = Analysis::peopleFilter($f);
            $ep = Database::get()->all("SELECT e.id, e.name, e.project_id, COUNT(DISTINCT t.id) AS tasks,
                    (SELECT COUNT(*) FROM task_due_changes d JOIN tasks t2 ON t2.id = d.task_id WHERE t2.epic_id = e.id AND t2.deleted_at IS NULL AND d.old_due IS NOT NULL AND d.changed_at >= ? AND d.changed_at < ?) AS changes
                FROM epics e JOIN tasks t ON t.epic_id = e.id AND t.deleted_at IS NULL AND t.due_date IS NOT NULL WHERE e.project_id IN ($in) $pw GROUP BY e.id, e.name, e.project_id",
                array_merge([$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'], $pids, $pp));
            $totT = array_sum(array_column($ep, 'tasks'));
            $totC = array_sum(array_column($ep, 'changes'));
            foreach ($ep as $e) {
                $n = (int)$e['tasks']; $c = (int)$e['changes'];
                $restT = $totT - $n; $restC = $totC - $c;
                if ($n < $min || $c < 3 || $restT < $min) continue;
                $rate = $c / $n; $rest = $restC / $restT;
                if ($rate < max(0.5, $rest * 2)) continue;
                $out[] = ['kind' => 'reprogram', 'key' => 'epic:' . $e['id'], 'project_id' => (int)$e['project_id'],
                    'title' => "La etapa «{$e['name']}» acumula más reprogramaciones",
                    'text' => "$c cambios de fecha en $n tareas (" . number_format($rate, 1, ',', '') . ' por tarea), frente a ' . number_format($rest, 1, ',', '') . " por tarea en las demás etapas ($restT tareas).",
                    'n' => $n, 'confidence' => Metrics::confidence($n, $min), 'caveat' => 'Una etapa puede tener más cambios por su naturaleza; revisa el contexto antes de concluir.'];
            }
        }
        // 3) sprints: committed work left unfinished, planned above capacity, work added after the start
        $byProject = [];
        foreach (self::sprints($pids, $from, $to) as $s) if ($s['source'] === 'commitment') $byProject[$s['project_id']][] = $s;
        foreach ($byProject as $pid => $list) {
            $n = count($list);
            if ($n < 3) continue;
            $pname = $list[0]['project_name'];
            $short = array_filter($list, fn($s) => $s['rate']['value'] !== null && $s['rate']['value'] < 80);
            if (count($short) / $n >= 0.6) {
                $out[] = ['kind' => 'sprint', 'key' => 'sprint-short:' . $pid, 'project_id' => $pid,
                    'title' => "Los sprints de {$pname} suelen cerrar con trabajo comprometido pendiente",
                    'text' => count($short) . " de $n sprints cerrados completaron menos del 80 % de lo comprometido al iniciar (mediana " . Metrics::pct(Metrics::median(array_map(fn($s) => $s['rate']['value'], $list))) . ').',
                    'n' => $n, 'confidence' => Metrics::confidence($n, 3), 'caveat' => 'Los sprints pueden diferir en alcance y complejidad: no mide la productividad de nadie.'];
            }
            $with = array_filter($list, fn($s) => $s['c']['added'] > 0);
            $without = array_filter($list, fn($s) => $s['c']['added'] === 0);
            if (count($with) >= 2 && count($without) >= 2) {
                $mw = Metrics::median(array_map(fn($s) => $s['rate']['value'], $with));
                $mo = Metrics::median(array_map(fn($s) => $s['rate']['value'], $without));
                if ($mo - $mw >= $cfg['trend_min_change']) $out[] = ['kind' => 'scope', 'key' => 'scope:' . $pid, 'project_id' => $pid,
                    'title' => "En {$pname}, los cambios de alcance coinciden con menos cumplimiento",
                    'text' => 'Los ' . count($with) . ' sprints con trabajo añadido después de iniciar completaron de mediana ' . Metrics::pct($mw) . ' de lo comprometido; los ' . count($without) . ' sin cambios, ' . Metrics::pct($mo) . '.',
                    'n' => $n, 'confidence' => Metrics::confidence($n, 3), 'caveat' => 'Es una coincidencia observada, no demuestra que el trabajo añadido sea la causa.'];
            }
            $over = $known = 0;
            foreach (array_slice($list, 0, 12) as $s) {
                $sp = Database::get()->one('SELECT * FROM sprints WHERE id = ?', [$s['id']]);
                $members = Database::get()->column('SELECT DISTINCT u FROM (SELECT t.assignee_id AS u FROM sprint_commitments sc JOIN tasks t ON t.id = sc.task_id WHERE sc.sprint_id = ? AND sc.planned = 1 AND t.assignee_id IS NOT NULL
                    UNION SELECT ta.user_id FROM sprint_commitments sc JOIN task_assignees ta ON ta.task_id = sc.task_id WHERE sc.sprint_id = ? AND sc.planned = 1) x', [$s['id'], $s['id']]);
                $cap = 0.0; $ok = (bool)$members;
                foreach (Capacity::availability($sp, array_map('intval', $members)) as $a) { if ($a['hours'] === null) { $ok = false; break; } $cap += $a['hours']; }
                if (!$ok || $s['c']['committed_hours'] <= 0) continue;
                $known++;
                if ($s['c']['committed_hours'] > $cap + 0.001) $over++;
            }
            if ($known >= 3 && $over / $known >= 0.6) $out[] = ['kind' => 'capacity', 'key' => 'overplan:' . $pid, 'project_id' => $pid,
                'title' => "En {$pname}, lo comprometido suele superar la capacidad del sprint",
                'text' => "En $over de $known sprints cerrados (con capacidad conocida) las horas comprometidas al iniciar superaban las horas disponibles de sus responsables.",
                'n' => $known, 'confidence' => Metrics::confidence($known, 3), 'caveat' => 'Usa la capacidad registrada de cada sprint; no incluye ausencias que no se anotaron.'];
        }
        return $out;
    }

    /** Sprint comparison for one project (or all visible): rows plus a trend only when the sprints are comparable (same project, commitment data). */
    public static function sprintComparison(array $pids, string $from, string $to, ?int $projectId): array
    {
        $rows = array_reverse(self::sprints($pids, $from, $to));
        $trend = null;
        $reference = null;
        if ($projectId) {
            $cmp = array_values(array_filter($rows, fn($s) => $s['source'] === 'commitment'));
            $trend = Metrics::trend(array_map(fn($s) => ['value' => $s['rate']['value'], 'n' => 1], $cmp), 1, 3, (float)Analysis::settings()['trend_min_change']);
            $last = array_slice($cmp, -3);
            if (count($last) >= 2) $reference = ['n' => count($last), 'done_hours' => Metrics::median(array_map(fn($s) => $s['c']['committed_done_hours'], $last)),
                'committed_hours' => Metrics::median(array_map(fn($s) => $s['c']['committed_hours'], $last)), 'rate' => Metrics::median(array_map(fn($s) => $s['rate']['value'], $last))];
        }
        return ['rows' => $rows, 'trend' => $trend, 'reference' => $reference];
    }

    // ------------------------------------------------------------------ data quality

    /** What is missing or inconsistent, with examples to correct (each one opens the task). */
    public static function quality(array $pids, string $from, string $to): array
    {
        if (!$pids) return [];
        $db = Database::get();
        $in = Database::in($pids);
        $cfg = Analysis::settings();
        $sample = fn(string $where, array $p) => $db->all("SELECT CONCAT(p.project_key, '-', t.number) AS task_key, t.title FROM tasks t JOIN projects p ON p.id = t.project_id JOIN statuses s ON s.id = t.status_id
            WHERE t.project_id IN ($in) AND t.deleted_at IS NULL AND $where ORDER BY t.id DESC LIMIT 6", array_merge($pids, $p));
        $count = fn(string $where, array $p) => (int)$db->value("SELECT COUNT(*) FROM tasks t JOIN statuses s ON s.id = t.status_id WHERE t.project_id IN ($in) AND t.deleted_at IS NULL AND $where", array_merge($pids, $p));
        $range = [$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'];
        $checks = [
            'no_estimate' => ['Tareas abiertas sin estimación', "t.parent_id IS NULL AND s.category <> 'done' AND t.estimate_hours IS NULL", [], 'No cuentan en la carga ni en el riesgo. Estímalas para que la planificación sea completa.'],
            'no_estimate_sprint' => ['…de ellas, en un sprint activo', "t.parent_id IS NULL AND s.category <> 'done' AND t.estimate_hours IS NULL AND t.sprint_id IN (SELECT id FROM sprints WHERE state = 'active')", [], 'El riesgo del sprint se marca «Sin datos suficientes» si faltan la mitad o más.'],
            'no_logged' => ['Finalizadas con estimación pero sin horas registradas', "t.parent_id IS NULL AND s.category = 'done' AND t.estimate_hours > 0 AND t.completed_at >= ? AND t.completed_at < ? AND NOT EXISTS (SELECT 1 FROM time_entries te WHERE te.task_id = t.id AND te.deleted_at IS NULL)", $range, 'Quedan fuera de la desviación de estimaciones y del motor de sugerencias.'],
            'no_due_done' => ['Finalizadas sin fecha límite registrada', "s.category = 'done' AND t.completed_due_date IS NULL AND t.completed_at >= ? AND t.completed_at < ? AND (t.parent_id IS NULL OR t.due_date IS NOT NULL)", $range, 'No cuentan en el cumplimiento de plazos (no hay contra qué compararlas).'],
            'dates' => ['Fechas inconsistentes (inicio posterior al vencimiento)', "s.category <> 'done' AND t.start_date IS NOT NULL AND t.due_date IS NOT NULL AND t.start_date > t.due_date", [], 'La carga se reparte como si empezaran el día de su vencimiento.'],
            'done_before_created' => ['Finalizadas antes de su creación', "t.completed_at IS NOT NULL AND t.completed_at < t.created_at", [], 'Registro inválido: se excluye de los tiempos de ciclo.'],
            'duplicates' => ['Posibles duplicados (mismo título, ambas abiertas)', "s.category <> 'done' AND t.parent_id IS NULL AND EXISTS (SELECT 1 FROM tasks t2 JOIN statuses s2 ON s2.id = t2.status_id WHERE t2.project_id = t.project_id AND t2.id <> t.id AND t2.deleted_at IS NULL AND t2.parent_id IS NULL AND s2.category <> 'done' AND LOWER(TRIM(t2.title)) = LOWER(TRIM(t.title)))", [], 'Si es la misma tarea, cuenta dos veces en la carga.'],
        ];
        $out = [];
        foreach ($checks as $k => [$label, $where, $p, $why]) {
            $n = $count($where, $p);
            $out[] = ['key' => $k, 'label' => $label, 'n' => $n, 'why' => $why, 'examples' => $n ? $sample($where, $p) : []];
        }
        $future = (int)$db->value("SELECT COUNT(*) FROM time_entries WHERE project_id IN ($in) AND deleted_at IS NULL AND work_date > ?", array_merge($pids, [today()]));
        $out[] = ['key' => 'future_time', 'label' => 'Horas registradas en días futuros', 'n' => $future, 'why' => 'Registro inválido: no debería ocurrir; revisa el registro de horas.', 'examples' => []];
        $nodates = (int)$db->value("SELECT COUNT(*) FROM sprints WHERE project_id IN ($in) AND state <> 'completed' AND (start_date IS NULL OR end_date IS NULL)", $pids);
        $out[] = ['key' => 'sprint_dates', 'label' => 'Sprints sin fechas', 'n' => $nodates, 'why' => 'Sin fechas no se puede calcular su capacidad ni su riesgo.', 'examples' => []];
        $old = (int)$db->value("SELECT COUNT(*) FROM sprints s WHERE s.project_id IN ($in) AND s.state = 'completed' AND NOT EXISTS (SELECT 1 FROM sprint_commitments sc WHERE sc.sprint_id = s.id AND sc.planned = 1)", $pids);
        $out[] = ['key' => 'sprint_history', 'label' => 'Sprints cerrados sin el compromiso inicial guardado', 'n' => $old, 'why' => 'Cerrados antes de 1.12: se usa el recuento del cierre, marcado como aproximado.', 'examples' => []];
        $fin = count(self::finished($pids, $from, $to));
        $out[] = ['key' => 'history', 'label' => 'Tareas finalizadas en el período', 'n' => $fin, 'info' => true,
            'why' => $fin < $cfg['min_sample'] ? 'Menos de ' . $cfg['min_sample'] . ': no hay historial suficiente para tendencias ni sugerencias.' : 'Historial suficiente para los indicadores básicos.', 'examples' => [], 'insufficient' => $fin < $cfg['min_sample']];
        return $out;
    }
}
