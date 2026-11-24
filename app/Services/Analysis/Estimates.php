<?php
declare(strict_types=1);

namespace Borealis\Services\Analysis;

use Borealis\Core\Database;
use Borealis\Security\Policy;
use Borealis\Services\Projects;

/**
 * Análisis (1.13): estimate suggestions learnt from the project's own history.
 *
 * No external AI: a robust statistic over comparable tasks, fully shown to the person.
 *  1. Comparable = finished top-level tasks of the same project, in the analysed period, with an
 *     estimate and logged hours. If the new task has labels, only tasks sharing one of them; without
 *     labels, only tasks without labels (categories are never mixed).
 *  2. With an estimate written, tasks of a similar size (½× to 2×) are preferred when there are enough.
 *  3. Extreme ratios (Tukey's fences on registered ÷ estimated) are set aside and counted.
 *  4. Fewer than «min_sample» left → no suggestion, and the reason.
 * Result: the usual deviation in hours and %, the suggested hours (estimate × median ratio, rounded
 * to ½ h) and the tasks used. It never changes a task: the person decides whether to use it.
 */
final class Estimates
{
    public static function suggest(int $projectId, array $labelIds, ?float $estimate, ?int $excludeTaskId = null): array
    {
        $p = Projects::find($projectId);
        Policy::authorizeProject($p, 'project.view');
        $cfg = Analysis::settings();
        $min = (int)$cfg['min_sample'];
        [$from, $to] = Analysis::period(null, null);
        $labelIds = array_values(array_unique(array_filter(array_map('intval', $labelIds))));
        if ($labelIds) {
            $valid = Database::get()->column('SELECT id FROM labels WHERE project_id = ? AND id IN (' . Database::in($labelIds) . ')', array_merge([$projectId], $labelIds));
            $labelIds = array_map('intval', $valid);
        }
        $rows = History::finished([$projectId], $from, $to);
        $cands = $ex = [];
        $ex = ['no_estimate' => 0, 'no_logged' => 0, 'small' => 0, 'other_category' => 0, 'outliers' => 0, 'size' => 0];
        foreach ($rows as $t) {
            if ($t['parent_id'] || (int)$t['id'] === $excludeTaskId) continue;
            $tl = array_keys($t['labels'] ?? []);
            $same = $labelIds ? (bool)array_intersect($labelIds, $tl) : !$tl;
            if (!$same) { $ex['other_category']++; continue; }
            $d = History::deviation($t, (float)$cfg['deviation_min_hours'], $why);
            if (!$d) { if (isset($ex[$why])) $ex[$why]++; continue; }
            $cands[(int)$t['id']] = $t + ['dev' => $d, 'ratio' => $d['logged'] / $d['estimate']];
        }
        if ($estimate !== null && $estimate > 0) {
            $similar = array_filter($cands, fn($c) => $c['dev']['estimate'] >= $estimate / 2 && $c['dev']['estimate'] <= $estimate * 2);
            if (count($similar) >= $min) { $ex['size'] = count($cands) - count($similar); $cands = $similar; }
        }
        [$keep, $out] = Metrics::outliers(array_map(fn($c) => $c['ratio'], $cands));
        $ex['outliers'] = count($out);
        $used = array_map(fn($k) => $cands[$k], $keep);
        $scope = $labelIds ? 'con ' . (count($labelIds) === 1 ? 'la etiqueta «' . self::labelNames($labelIds) . '»' : 'alguna de las etiquetas «' . self::labelNames($labelIds) . '»') : 'sin etiqueta';
        $base = ['project_id' => $projectId, 'period' => [$from, $to], 'scope' => $scope, 'n' => count($used), 'min' => $min, 'excluded' => $ex,
            'source' => 'Tareas finalizadas de ' . $p['name'] . " $scope entre el " . fmt_date($from, true) . ' y el ' . fmt_date($to, true) . ', con horas estimadas y registradas.'];
        if (count($used) < $min) {
            return $base + ['ok' => false, 'message' => 'No hay suficientes tareas comparables para sugerir una estimación (' . count($used) . ' de ' . $min . ' necesarias).'];
        }
        $ratios = array_map(fn($c) => $c['ratio'], $used);
        $hours = array_map(fn($c) => $c['dev']['hours'], $used);
        $pcts = array_map(fn($c) => $c['dev']['pct'], $used);
        $medRatio = Metrics::median($ratios);
        $medH = round(Metrics::median($hours), 1);
        $medPct = Metrics::median($pcts);
        $suggested = $estimate !== null && $estimate > 0 ? max(0.5, round($estimate * $medRatio * 2) / 2) : null;
        $abs = Metrics::h(abs($medH));
        $msg = abs($medH) < 0.25 ? 'Las tareas similares finalizaron, de forma habitual, cerca de su estimación inicial.'
            : 'Las tareas similares finalizaron con una desviación habitual de aproximadamente ' . $abs . ($medH > 0 ? ' sobre' : ' por debajo de') . ' su estimación inicial.';
        // Calendar duration (start → finish) is shown apart: it is not worked time.
        $cal = [];
        foreach ($used as $c) if ($c['started_at']) $cal[] = max(0, (strtotime((string)$c['completed_at']) - strtotime((string)$c['started_at'])) / 86400);
        usort($used, fn($a, $b) => strcmp((string)$b['completed_at'], (string)$a['completed_at']));
        return $base + ['ok' => true, 'message' => $msg, 'median_hours' => $medH, 'median_pct' => $medPct, 'median_ratio' => round($medRatio, 2),
            'p25' => Metrics::percentile($pcts, 25), 'p75' => Metrics::percentile($pcts, 75), 'estimate' => $estimate, 'suggested' => $suggested,
            'confidence' => Metrics::confidence(count($used), $min), 'calendar_days' => $cal ? round(Metrics::median($cal), 1) : null, 'calendar_n' => count($cal),
            'tasks' => array_map(fn($c) => ['key' => $c['key'], 'title' => $c['title'], 'estimate' => $c['dev']['estimate'], 'logged' => $c['dev']['logged']], array_slice($used, 0, 12))];
    }

    private static function labelNames(array $ids): string
    {
        return implode('», «', Database::get()->column('SELECT name FROM labels WHERE id IN (' . Database::in($ids) . ') ORDER BY name', $ids));
    }

    /** Per category (label) of a project: how finished tasks compared with their estimate (for the «Estimaciones» tab). */
    public static function byCategory(array $pids, string $from, string $to, array $f = []): array
    {
        $cfg = Analysis::settings();
        $groups = [];
        foreach (History::finished($pids, $from, $to, $f) as $t) {
            if (!($d = History::deviation($t, (float)$cfg['deviation_min_hours']))) continue;
            $labels = $t['labels'] ?? [0 => 'Sin etiqueta'];
            foreach ($labels as $lid => $name) {
                $k = $t['project_id'] . ':' . $lid;
                $groups[$k] ??= ['project_id' => (int)$t['project_id'], 'project' => $t['project_name'], 'label_id' => (int)$lid, 'label' => $name, 'pct' => [], 'h' => [], 'est' => 0.0, 'logged' => 0.0];
                $groups[$k]['pct'][] = $d['pct']; $groups[$k]['h'][] = $d['hours']; $groups[$k]['est'] += $d['estimate']; $groups[$k]['logged'] += $d['logged'];
            }
        }
        $out = [];
        foreach ($groups as $g) {
            $n = count($g['pct']);
            $out[] = ['project_id' => $g['project_id'], 'project' => $g['project'], 'label_id' => $g['label_id'], 'label' => $g['label'], 'n' => $n,
                'median_pct' => Metrics::median($g['pct']), 'median_hours' => Metrics::median($g['h']), 'estimate' => round($g['est'], 1), 'logged' => round($g['logged'], 1),
                'enough' => $n >= $cfg['min_sample'], 'flag' => $n >= $cfg['min_sample'] && Metrics::median($g['pct']) >= $cfg['pattern_deviation_pct']];
        }
        usort($out, fn($a, $b) => [$b['enough'], $b['median_pct'] ?? -1e9] <=> [$a['enough'], $a['median_pct'] ?? -1e9]);
        return $out;
    }
}
