<?php
declare(strict_types=1);

namespace Borealis\Controllers;

use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Request;
use Borealis\Core\Response;
use Borealis\Core\View;
use Borealis\Security\Policy;
use Borealis\Services\Analysis\Analysis;
use Borealis\Services\Analysis\Estimates;
use Borealis\Services\Analysis\History;
use Borealis\Services\Analysis\Metrics;
use Borealis\Services\Analysis\Planning;
use Borealis\Services\Analysis\Scenarios;
use Borealis\Services\CapacityAlerts;
use Borealis\Services\Monitoring;
use Borealis\Services\Reports;

/**
 * «Análisis» (1.13): one menu entry, six tabs. Every number explains how it was calculated, with
 * which records and which were left out. Permissions are those of Monitoreo, checked here and in
 * the services on every request (pages, API, print view and CSV alike).
 */
final class AnalysisController
{
    public const TABS = ['summary' => 'Resumen', 'trends' => 'Tendencias', 'estimates' => 'Estimaciones', 'planning' => 'Planificación', 'scenarios' => 'Escenarios', 'report' => 'Informes'];

    /** Filters from the query string, each one checked against what the viewer may see. */
    private static function filters(Request $r): array
    {
        $pid = Monitoring::projectFilter($r->q('project_id'));
        $team = Analysis::team($r->q('team_id'));
        $sid = null;
        if ($r->q('sprint_id') && ctype_digit((string)$r->q('sprint_id'))) {
            $sp = Analysis::sprint((int)$r->q('sprint_id'));
            $sid = (int)$sp['id'];
            $pid ??= (int)$sp['project_id'];
            if ((int)$sp['project_id'] !== $pid) throw HttpException::notFound('El sprint no existe o no tienes acceso.');
        }
        $from = $r->q('from') && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$r->q('from')) ? (string)$r->q('from') : null;
        $to = $r->q('to') && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$r->q('to')) ? (string)$r->q('to') : null;
        [$from, $to] = Analysis::period($from, $to);
        $state = in_array($r->q('state'), ['open', 'done'], true) ? (string)$r->q('state') : 'all';
        return ['project_id' => $pid, 'team_id' => $team, 'sprint_id' => $sid, 'from' => $from, 'to' => $to, 'mine' => $r->q('mine') === '1', 'state' => $state];
    }

    public function index(Request $r): Response
    {
        $tab = isset(self::TABS[(string)$r->q('tab')]) ? (string)$r->q('tab') : 'summary';
        $f = self::filters($r);
        $sc = Analysis::scope();
        $pids = Analysis::pids($f['project_id']);
        $tf = ['mine' => $f['mine'], 'team_id' => $f['team_id'], 'sprint_id' => $f['sprint_id']];
        $data = ['tab' => $tab, 'filters' => $f, 'cfg' => Analysis::settings(), 'scope' => $sc, 'projects' => array_values($sc['rows']),
            'teams' => self::teams(), 'canManageAny' => $sc['admin'] || (bool)$sc['managed']];
        $data['sprintOptions'] = $sc['projects'] ? Database::get()->all("SELECT s.id, s.name, s.state, s.project_id FROM sprints s WHERE s.project_id IN (" . Database::in($sc['projects']) . ") ORDER BY s.state = 'completed', s.start_date DESC LIMIT 200", $sc['projects']) : [];
        $key = $tab . '|' . json_encode($f);
        switch ($tab) {
            case 'summary':
                $data['d'] = Analysis::remember($key, $pids, function () use ($pids, $f, $tf) {
                    $ind = History::indicators($pids, $f['from'], $f['to'], $tf);
                    $sprints = Planning::sprintRisks($pids);
                    $cap = Planning::capacity($f['project_id'], $f['team_id'], $f['mine']);
                    $patterns = History::patterns($pids, $f['from'], $f['to'], $tf);
                    return ['ind' => $ind, 'projects' => Planning::projectRisks($pids), 'sprints' => self::lean($sprints), 'cap' => self::leanCap($cap),
                        'trend' => History::trends($pids, $f['from'], $f['to'], $tf)['trend'], 'patterns' => $patterns,
                        'recs' => Planning::recommendations($pids, $sprints, $cap, $patterns, $ind['reprogram']['repeated']),
                        'quality' => History::quality($pids, $f['from'], $f['to'])];
                });
                break;
            case 'trends':
                $data['d'] = Analysis::remember($key, $pids, fn() => ['ind' => History::indicators($pids, $f['from'], $f['to'], $tf), 'trends' => History::trends($pids, $f['from'], $f['to'], $tf),
                    'patterns' => History::patterns($pids, $f['from'], $f['to'], $tf), 'sprints' => History::sprintComparison($pids, $f['from'], $f['to'], $f['project_id'])]);
                break;
            case 'estimates':
                $data['d'] = Analysis::remember($key, $pids, fn() => ['ind' => History::indicators($pids, $f['from'], $f['to'], $tf), 'cats' => Estimates::byCategory($pids, $f['from'], $f['to'], $tf)]);
                $data['labels'] = $f['project_id'] ? Database::get()->all('SELECT id, name FROM labels WHERE project_id = ? ORDER BY name', [$f['project_id']]) : [];
                break;
            case 'planning':
                $data['d'] = Analysis::remember($key, $pids, function () use ($pids, $f) {
                    $sprints = Planning::sprintRisks($pids);
                    return ['cap' => self::leanCap(Planning::capacity($f['project_id'], $f['team_id'], $f['mine'])), 'sprints' => self::lean($sprints), 'projects' => Planning::projectRisks($pids)];
                });
                break;
            case 'scenarios':
                $managed = array_values(array_filter($sc['projects'], fn($p) => Analysis::canManage($p)));
                $data['simSprints'] = $managed ? Database::get()->all("SELECT s.id, s.name, s.state, s.project_id, p.name AS project_name FROM sprints s JOIN projects p ON p.id = s.project_id
                    WHERE s.project_id IN (" . Database::in($managed) . ") AND s.state IN ('active','planned') AND p.archived_at IS NULL ORDER BY p.name, s.state = 'active' DESC, s.start_date", $managed) : [];
                $sid = $f['sprint_id'] ?: ((int)($data['simSprints'][0]['id'] ?? 0) ?: null);
                if ($f['project_id'] && !$f['sprint_id']) { foreach ($data['simSprints'] as $s) if ((int)$s['project_id'] === $f['project_id']) { $sid = (int)$s['id']; break; } }
                if ($sid && in_array($sid, array_map(fn($s) => (int)$s['id'], $data['simSprints']), true)) {
                    [$sp, $snap] = Scenarios::load($sid);
                    [$targets, $prios] = Scenarios::references($sp);
                    $pre = array_values(array_filter(array_map('intval', explode(',', (string)$r->q('move', ''))), fn($id) => isset($snap['tasks'][$id])));
                    $data['sim'] = ['sprint' => $sp, 'snap' => $snap, 'targets' => array_values($targets), 'priorities' => array_values($prios), 'premove' => $pre,
                        'result' => Scenarios::simulate($sid, array_map(fn($id) => ['type' => 'move', 'task_id' => $id, 'to_sprint_id' => array_key_first($targets)], $pre))];
                }
                $data['history'] = Scenarios::history($pids);
                break;
            case 'report':
                $data['d'] = self::report($f, $pids, $tf, $key);
                break;
        }
        return View::page('analysis/index', ['title' => 'Análisis', 'nav' => 'analysis'] + $data);
    }

    /** The executive report (shared by the tab, the print view and the CSV: same data, same permissions). */
    private static function report(array $f, array $pids, array $tf, string $key): array
    {
        return Analysis::remember('report|' . $key, $pids, function () use ($f, $pids, $tf) {
            $ind = History::indicators($pids, $f['from'], $f['to'], $tf);
            $sprints = Planning::sprintRisks($f['sprint_id'] ? [] : $pids);
            if ($f['sprint_id']) $sprints = array_values(array_filter(Planning::sprintRisks($pids), fn($s) => $s['sprint']['id'] === $f['sprint_id']));
            $cap = Planning::capacity($f['project_id'], $f['team_id'], $f['mine']);
            $patterns = History::patterns($pids, $f['from'], $f['to'], $tf);
            $recs = Planning::recommendations($pids, $sprints, $cap, $patterns, $ind['reprogram']['repeated']);
            $deviations = [];
            foreach (History::finished($pids, $f['from'], $f['to'], $tf) as $t) {
                if (!($d = History::deviation($t, (float)Analysis::settings()['deviation_min_hours']))) continue;
                $deviations[] = ['key' => $t['key'], 'title' => $t['title'], 'dev' => $d, 'project' => $t['project_name']];
            }
            usort($deviations, fn($a, $b) => abs($b['dev']['hours']) <=> abs($a['dev']['hours']));
            $pending = [];
            foreach (CapacityAlerts::visible('open', $f['project_id']) as $a) $pending[] = ['kind' => 'capacity', 'text' => 'Alerta de capacidad abierta: ' . ($a['user_name'] ?? '') . ' (semana del ' . fmt_date((string)$a['week_start'], true) . ')', 'link' => url('/monitoring', ['tab' => 'risks'])];
            foreach (Monitoring::alerts(['project_id' => $f['project_id']]) as $a) if ($a['severity'] === 'critical' && $a['state'] === 'open') $pending[] = ['kind' => 'alert', 'text' => 'Alerta crítica sin revisar: ' . $a['message'], 'task_key' => $a['task_key'] ?? null];
            return ['ind' => $ind, 'projects' => Planning::projectRisks($pids), 'sprints' => self::lean($sprints), 'cap' => self::leanCap($cap), 'patterns' => $patterns, 'recs' => $recs,
                'trend' => History::trends($pids, $f['from'], $f['to'], $tf)['trend'], 'deviations' => array_slice($deviations, 0, 10), 'pending' => array_slice($pending, 0, 15),
                'quality' => History::quality($pids, $f['from'], $f['to'])];
        });
    }

    private static function lean(array $sprints): array
    {
        return array_map(fn($s) => ['sprint' => $s['sprint'], 'eval' => $s['eval'], 'canSim' => Analysis::canManage($s['sprint']['project_id'])], $sprints);
    }

    /** Capacity per week; per-person rows only for the people the viewer may already see in Monitoreo. */
    private static function leanCap(array $cap): array
    {
        $cap['people'] = array_map(fn($p) => ['user' => ['id' => (int)$p['user']['id'], 'name' => $p['user']['name']], 'weeks' => array_map(fn($c) => ['capacity' => $c['capacity'], 'planned' => $c['planned'], 'class' => $c['class'], 'over' => $c['over']], $p['weeks'])], $cap['people']);
        return $cap;
    }

    private static function teams(): array
    {
        $vis = Policy::visibleTeamIds();
        $rows = Database::get()->all('SELECT id, name FROM teams WHERE workspace_id = ? AND archived_at IS NULL ORDER BY name', [Policy::wsId()]);
        return $vis === null ? $rows : array_values(array_filter($rows, fn($t) => in_array((int)$t['id'], $vis, true)));
    }

    public function printReport(Request $r): Response
    {
        $f = self::filters($r);
        $pids = Analysis::pids($f['project_id']);
        $tf = ['mine' => $f['mine'], 'team_id' => $f['team_id'], 'sprint_id' => $f['sprint_id']];
        $d = self::report($f, $pids, $tf, 'report|' . json_encode($f));
        return View::page('analysis/print', ['title' => 'Informe de análisis', 'layout' => 'print', 'd' => $d, 'filters' => $f, 'scope' => Analysis::scope(),
            'back' => url('/analysis', array_filter(['tab' => 'report', 'project_id' => $f['project_id'], 'from' => $f['from'], 'to' => $f['to']]))]);
    }

    /** CSV: one row per indicator (value, numerator, denominator, excluded) plus risks and recommendations. */
    public function reportCsv(Request $r): Response
    {
        $f = self::filters($r);
        $pids = Analysis::pids($f['project_id']);
        $tf = ['mine' => $f['mine'], 'team_id' => $f['team_id'], 'sprint_id' => $f['sprint_id']];
        $d = self::report($f, $pids, $tf, 'report|' . json_encode($f));
        $i = $d['ind'];
        $num = fn($x) => $x === null ? '' : str_replace('.', ',', (string)$x);
        $period = fmt_dmy($f['from']) . ' – ' . fmt_dmy($f['to']);
        $rows = [
            ['Indicador', Metrics::DEFINITIONS['ontime'][0], $num($i['ontime']['value']), '%', $i['ontime']['num'], $i['ontime']['den'], $i['ontime']['excluded'], $period, Metrics::DEFINITIONS['ontime'][1]],
            ['Indicador', Metrics::DEFINITIONS['late'][0], $num($i['late']['value']), '%', $i['late']['num'], $i['late']['den'], $i['late']['excluded'], $period, Metrics::DEFINITIONS['late'][1]],
            ['Indicador', Metrics::DEFINITIONS['deviation'][0], $num($i['deviation']['median_pct']), '% (mediana)', '', $i['deviation']['n'], array_sum($i['deviation']['excluded']), $period, Metrics::DEFINITIONS['deviation'][1]],
            ['Indicador', Metrics::DEFINITIONS['reprogram'][0], $num($i['reprogram']['rate']), 'cambios por tarea', $i['reprogram']['changes'], $i['reprogram']['tasks'], '', $period, Metrics::DEFINITIONS['reprogram'][1]],
            ['Indicador', Metrics::DEFINITIONS['blocked'][0], $num($i['blocks']['median_days']), 'días (mediana)', '', $i['blocks']['closed'], $i['blocks']['unknown_start'], $period, Metrics::DEFINITIONS['blocked'][1]],
        ];
        foreach ($i['sprints'] as $s) $rows[] = ['Sprint', $s['project_name'] . ' · ' . $s['name'], $num($s['rate']['value']), '% de lo comprometido', $s['rate']['num'], $s['rate']['den'], $s['c']['added'], fmt_dmy($s['start']) . ' – ' . fmt_dmy($s['end']), $s['source'] === 'commitment' ? 'Compromiso al iniciar' : ($s['source'] === 'close' ? 'Recuento del cierre (aproximado)' : 'Sin dato')];
        foreach ($d['cap']['weeks'] as $w) $rows[] = ['Capacidad', 'Semana del ' . fmt_dmy($w['week']), $num($w['gap']), 'h sobre la capacidad', $num($w['planned']), $num($w['capacity']), count($w['no_capacity']), '', 'carga planificada − capacidad'];
        foreach ($d['projects'] as $p) $rows[] = ['Riesgo', $p['project']['name'], Analysis::RISK[$p['level']][0], $p['points'] . ' puntos', '', '', '', '', $p['sentence']];
        foreach ($d['sprints'] as $s) $rows[] = ['Riesgo', $s['sprint']['project_name'] . ' · ' . $s['sprint']['name'], Analysis::RISK[$s['eval']['level']][0], $s['eval']['points'] . ' puntos', $num($s['eval']['pending']), $num($s['eval']['capacity_left']), $s['eval']['unestimated'], '', $s['eval']['sentence']];
        foreach ($d['recs'] as $rc) $rows[] = ['Recomendación', $rc['problem'], '', '', '', '', '', '', $rc['action'] . ($rc['impact'] ? ' ' . $rc['impact'] : '') . ' (Confianza: ' . $rc['confidence'] . ')'];
        $csv = Reports::csv(['Tipo', 'Elemento', 'Valor', 'Unidad', 'Numerador', 'Denominador', 'Excluidos', 'Período', 'Definición / explicación'], $rows);
        return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="analisis-' . today() . '.csv"', 'Cache-Control' => 'no-store']);
    }

    // ------------------------------------------------------------------ API

    public function estimate(Request $r): Response
    {
        $pid = (int)$r->q('project_id');
        $labels = array_filter(explode(',', (string)$r->q('label_ids', '')), 'ctype_digit');
        $est = is_numeric($r->q('estimate')) ? (float)$r->q('estimate') : null;
        $task = $r->q('task_id') && ctype_digit((string)$r->q('task_id')) ? (int)$r->q('task_id') : null;
        if ($task) {
            $t = \Borealis\Services\Tasks::find($task);
            $pid = (int)$t['project_id'];
            if ($r->q('label_ids') === null) $labels = array_map(fn($l) => (string)$l['id'], $t['labels'] ?? []);
        }
        return Response::json(['ok' => true, 'suggestion' => Estimates::suggest($pid, $labels, $est, $task)]);
    }

    public function simulate(Request $r): Response
    {
        $res = Scenarios::simulate($r->intParam('id'), (array)$r->input('changes', []));
        return Response::json(['ok' => true, 'result' => $res, 'html' => partial('analysis-compare', ['res' => $res])]);
    }

    public function apply(Request $r): Response
    {
        $res = Scenarios::apply($r->intParam('id'), (array)$r->input('changes', []), (string)$r->input('fingerprint', ''), filter_var($r->input('confirm'), FILTER_VALIDATE_BOOLEAN));
        return Response::ok(['scenario' => $res], 'Escenario aplicado: ' . count($res['changes']) . (count($res['changes']) === 1 ? ' cambio registrado' : ' cambios registrados') . ' en el historial');
    }

    public function revert(Request $r): Response
    {
        $res = Scenarios::revert($r->intParam('id'));
        return Response::ok(['scenario' => $res], 'Escenario revertido');
    }

    public function saveSettings(Request $r): Response
    {
        Analysis::saveSettings($r->input());
        return Response::ok([], 'Parámetros de análisis guardados');
    }
}
