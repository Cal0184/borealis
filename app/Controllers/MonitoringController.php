<?php
declare(strict_types=1);

namespace Borealis\Controllers;

use Borealis\Core\Request;
use Borealis\Core\Response;
use Borealis\Core\View;
use Borealis\Security\Policy;
use Borealis\Services\Monitoring;
use Borealis\Services\Reports;

/** «Monitoreo» (1.12): one menu entry, five tabs plus the report. Every number links to its records. */
final class MonitoringController
{
    public const TABS = [
        'summary' => 'Resumen', 'compliance' => 'Cumplimiento', 'workload' => 'Carga de trabajo', 'times' => 'Tiempos', 'risks' => 'Riesgos y alertas', 'report' => 'Informes',
    ];

    public function index(Request $r): Response
    {
        $tab = isset(self::TABS[(string)$r->q('tab')]) ? (string)$r->q('tab') : 'summary';
        Monitoring::scanIfStale();
        $pid = Monitoring::projectFilter($r->q('project_id'));
        $sc = Monitoring::scope();
        $sprintId = null;
        if ($r->q('sprint_id') && ctype_digit((string)$r->q('sprint_id'))) {
            $sp = \Borealis\Core\Database::get()->one('SELECT * FROM sprints WHERE id = ?', [(int)$r->q('sprint_id')]);
            if (!$sp || !in_array((int)$sp['project_id'], $sc['projects'], true)) throw \Borealis\Core\HttpException::notFound('El sprint no existe o no tienes acceso.');
            $sprintId = (int)$sp['id'];
        }
        [$from, $to] = Reports::range($r->query);
        $mine = $r->q('mine') === '1';
        $f = ['project_id' => $pid, 'sprint_id' => $sprintId, 'from' => $from, 'to' => $to, 'mine' => $mine];
        $data = ['tab' => $tab, 'filters' => $f, 'cfg' => Monitoring::settings(), 'scope' => $sc,
            'projects' => array_values($sc['rows']), 'run' => \Borealis\Core\Database::get()->one('SELECT * FROM monitor_runs WHERE workspace_id = ?', [Policy::wsId()])];
        $data['sprintOptions'] = $sc['projects'] ? \Borealis\Core\Database::get()->all('SELECT s.id, s.name, s.state, s.project_id FROM sprints s WHERE s.project_id IN (' . \Borealis\Core\Database::in($sc['projects']) . ') ORDER BY s.state = \'completed\', s.start_date DESC LIMIT 200', $sc['projects']) : [];
        switch ($tab) {
            case 'summary':
                $data['s'] = Monitoring::summary($pid);
                $data['wl'] = Monitoring::workload(Monitoring::week(), 1, $pid);
                break;
            case 'compliance':
                $class = (string)$r->q('class', 'late');
                $data['c'] = Monitoring::compliance($f + ['class' => in_array($class, array_merge(array_keys(Monitoring::CLASSES), ['open', 'done', 'blocked']), true) ? $class : 'late']);
                $data['deps'] = Monitoring::dependencyRisks($pid ? [$pid] : $sc['projects']);
                break;
            case 'workload':
                $week = $r->q('week') && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$r->q('week')) ? Monitoring::week((string)$r->q('week')) : Monitoring::week();
                $data['week'] = $week;
                $data['view'] = in_array($r->q('view'), ['person', 'project', 'sprint'], true) ? (string)$r->q('view') : 'person';
                $data['wl'] = Monitoring::workload($week, 4, $pid, $sprintId);
                $data['focus'] = $r->q('user') && ctype_digit((string)$r->q('user')) ? (int)$r->q('user') : null;
                if ($data['view'] === 'sprint') $data['sprints'] = Monitoring::sprints($pid);
                break;
            case 'times':
                $data['t'] = Monitoring::times($f);
                break;
            case 'risks':
                $data['alerts'] = Monitoring::alerts(['project_id' => $pid, 'rule' => $r->q('rule'), 'severity' => $r->q('severity'), 'state' => $r->q('state') === 'resolved' ? 'resolved' : 'active']);
                $data['state'] = $r->q('state') === 'resolved' ? 'resolved' : 'active';
                $data['capacityAlerts'] = count(\Borealis\Services\CapacityAlerts::visible('open', $pid)); // the ones this person may decide on
                $data['ownCapacity'] = (bool)\Borealis\Core\Database::get()->value("SELECT 1 FROM capacity_alerts WHERE workspace_id = ? AND user_id = ? AND status = 'open'", [Policy::wsId(), \Borealis\Core\Auth::id()]);
                break;
            case 'report':
                $from ??= date('Y-m-d', strtotime(today() . ' -30 days'));
                $to ??= today();
                $f['from'] = $from; $f['to'] = $to;
                $data['filters'] = $f;
                $data['c'] = Monitoring::compliance($f + ['class' => 'late']);
                $data['done'] = Monitoring::compliance($f + ['class' => 'done']);
                $data['t'] = Monitoring::times($f);
                $data['closed'] = Monitoring::closedSprints($pid, $from, $to);
                $data['alerts'] = Monitoring::alerts(['project_id' => $pid]);
                $data['wl'] = Monitoring::workload(Monitoring::week(), 1, $pid, $sprintId);
                break;
        }
        return View::page('monitoring/index', ['title' => 'Monitoreo', 'nav' => 'monitoring'] + $data);
    }

    public function reportCsv(Request $r): Response
    {
        $pid = Monitoring::projectFilter($r->q('project_id'));
        [$from, $to] = Reports::range($r->query);
        $sid = $r->q('sprint_id') && ctype_digit((string)$r->q('sprint_id')) ? (int)$r->q('sprint_id') : null;
        if ($sid) {
            $sp = \Borealis\Core\Database::get()->one('SELECT project_id FROM sprints WHERE id = ?', [$sid]);
            if (!$sp || !in_array((int)$sp['project_id'], Monitoring::scope()['projects'], true)) throw \Borealis\Core\HttpException::notFound();
        }
        $csv = Monitoring::reportCsv(['project_id' => $pid, 'sprint_id' => $sid, 'from' => $from, 'to' => $to]);
        return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="monitoreo-' . today() . '.csv"', 'Cache-Control' => 'no-store']);
    }

    public function ack(Request $r): Response
    {
        $a = Monitoring::acknowledge($r->intParam('id'), $r->input('note') !== null ? (string)$r->input('note') : null);
        return Response::ok(['alert' => ['id' => (int)$a['id'], 'state' => $a['state']]], 'Alerta marcada como revisada: no se escalará más');
    }

    public function scan(Request $r): Response
    {
        Monitoring::scanIfStale(30);
        return Response::ok([], 'Revisión hecha');
    }

    public function saveSettings(Request $r): Response
    {
        Monitoring::saveSettings($r->input());
        return Response::ok([], 'Umbrales de monitoreo guardados');
    }
}
