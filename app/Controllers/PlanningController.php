<?php
declare(strict_types=1);

namespace Borealis\Controllers;

use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Request;
use Borealis\Core\Response;
use Borealis\Core\Validator;
use Borealis\Core\View;
use Borealis\Security\Policy;
use Borealis\Services\Projects;
use Borealis\Services\Reports;
use Borealis\Services\Sprints;
use Borealis\Services\Tasks;
use Borealis\Services\Workspaces;

final class PlanningController
{
    public function backlog(Request $r): Response
    {
        $ctx = ProjectContext::load($r, 'backlog');
        $pid = (int)$ctx['project']['id'];
        $f = ProjectContext::filters($r);
        unset($f['sprint_id']);
        $base = ['project_id' => $pid, 'sort' => 'backlog', 'category' => ['todo', 'in_progress']] + $f;
        $groups = [];
        if ($ctx['modules']['sprints']) {
            foreach ($ctx['sprints'] as $s) {
                if ($s['state'] === 'completed') continue;
                $groups[] = ['sprint' => $s, 'tasks' => Tasks::query(['sprint_id' => (string)$s['id']] + $base + ['category' => ['todo', 'in_progress', 'done']])];
            }
            $groups[] = ['sprint' => null, 'tasks' => Tasks::query(['sprint_id' => 'none'] + $base)];
        } else {
            $groups[] = ['sprint' => null, 'tasks' => Tasks::query($base)];
        }
        return View::page('planning/backlog', $ctx + ['tab' => 'backlog', 'groups' => $groups, 'filters' => $r->query]);
    }

    public function sprints(Request $r): Response
    {
        $ctx = ProjectContext::load($r, 'sprints');
        $selected = null;
        if ($r->q('sprint')) {
            $selected = Sprints::find((int)$r->q('sprint'));
            if ((int)$selected['project_id'] !== (int)$ctx['project']['id']) $selected = null;
        }
        $selected ??= Sprints::active((int)$ctx['project']['id']);
        // No active sprint: show the next planned one, otherwise the last finished one.
        if (!$selected) foreach (['planned', 'completed'] as $st) {
            $pick = array_values(array_filter($ctx['sprints'], fn($x) => $x['state'] === $st));
            if ($pick) { $selected = $st === 'planned' ? $pick[0] : end($pick); break; }
        }
        $tasks = $selected ? Tasks::query(['project_id' => $ctx['project']['id'], 'sprint_id' => (string)$selected['id'], 'sort' => 'status']) : [];
        $selectedStats = null;
        foreach ($ctx['sprints'] as $s) if ($selected && (int)$s['id'] === (int)$selected['id']) $selectedStats = $s;
        $control = $selected ? Sprints::control($selected, $ctx['project'], Tasks::query(['project_id' => $ctx['project']['id'], 'sprint_id' => (string)$selected['id'], 'with_subtasks' => 1])) : null;
        $withSubs = $selected ? Tasks::query(['project_id' => $ctx['project']['id'], 'sprint_id' => (string)$selected['id'], 'with_subtasks' => 1]) : [];
        return View::page('planning/sprints', $ctx + ['tab' => 'sprints', 'selected' => $selectedStats, 'sprintTasks' => $tasks, 'control' => $control,
            'planning' => $selected ? Sprints::planning($selected, $withSubs) : null, 'priorities' => Workspaces::priorities(), 'assignable' => Projects::assignableUsers($ctx['project'])]);
    }

    /** Month or week grid. $project limits to one project; otherwise all projects with the calendar module. */
    private function calendarData(Request $r, ?array $project): array
    {
        $view = $r->q('view') === 'week' ? 'week' : 'month';
        $anchor = (string)$r->q('date', today());
        if (!Validator::isDate($anchor)) $anchor = today();
        if ($view === 'month') {
            $first = date('Y-m-01', strtotime($anchor));
            $start = date('Y-m-d', strtotime('monday this week', strtotime($first)));
            $last = date('Y-m-t', strtotime($anchor));
            $end = date('Y-m-d', strtotime('sunday this week', strtotime($last)));
            $prev = date('Y-m-d', strtotime($first . ' -1 month'));
            $next = date('Y-m-d', strtotime($first . ' +1 month'));
        } else {
            $start = date('Y-m-d', strtotime('monday this week', strtotime($anchor)));
            $end = date('Y-m-d', strtotime($start . ' +6 days'));
            $prev = date('Y-m-d', strtotime($start . ' -7 days'));
            $next = date('Y-m-d', strtotime($start . ' +7 days'));
        }
        $f = ['due_from' => $start, 'due_to' => $end, 'has_date' => 1, 'with_subtasks' => 1, 'sort' => 'priority'];
        if ($r->q('assignee')) $f['assignee'] = (string)$r->q('assignee');
        if ($r->q('hide_done') === '1') $f['category'] = ['todo', 'in_progress'];
        [$vis, $vp] = Policy::visibleProjectsSql('p');
        $calendarProjects = Projects::withProgress(Database::get()->all("SELECT p.* FROM projects p JOIN project_modules m ON m.project_id = p.id AND m.module_key = 'calendar' AND m.enabled = 1 WHERE p.archived_at IS NULL AND $vis ORDER BY p.name", $vp));
        if ($project) {
            $f['project_id'] = (int)$project['id'];
        } else {
            $ids = array_map(fn($p) => (int)$p['id'], $calendarProjects);
            if ($r->q('project_id') && in_array((int)$r->q('project_id'), $ids, true)) $ids = [(int)$r->q('project_id')];
            $f['project_ids'] = $ids ?: [0];
        }
        $byDay = [];
        foreach (Tasks::query($f) as $t) {
            $day = $t['due_date'] ?: $t['start_date'];
            if ($day >= $start && $day <= $end) $byDay[$day][] = $t;
        }
        return compact('view', 'anchor', 'start', 'end', 'prev', 'next', 'byDay', 'calendarProjects') + ['people' => Workspaces::members(false), 'filters' => $r->query];
    }

    public function calendar(Request $r): Response
    {
        return View::page('planning/calendar', ['title' => 'Calendario', 'nav' => 'calendar', 'project' => null, 'cal' => $this->calendarData($r, null)]);
    }

    public function projectCalendar(Request $r): Response
    {
        $ctx = ProjectContext::load($r, 'calendar');
        return View::page('planning/calendar', $ctx + ['tab' => 'calendar', 'cal' => $this->calendarData($r, $ctx['project'])]);
    }

    public function timeline(Request $r): Response
    {
        $ctx = ProjectContext::load($r, 'timeline');
        // 1.14: Gantt chart built from the project's data (days, weeks or months)
        $scale = isset(\Borealis\Services\Gantt::SCALES[(string)$r->q('scale')]) ? (string)$r->q('scale') : 'week';
        $from = (string)$r->q('from', '');
        $to = (string)$r->q('to', '');
        $g = \Borealis\Services\Gantt::build($ctx['project'], $scale, Validator::isDate($from) ? $from : null, Validator::isDate($to) ? $to : null, (string)$r->q('subtasks', '1') !== '0');
        return View::page('planning/timeline', $ctx + ['tab' => 'timeline', 'g' => $g, 'subtasks' => (string)$r->q('subtasks', '1') !== '0']);
    }

    public function epics(Request $r): Response
    {
        $ctx = ProjectContext::load($r, 'epics');
        $byEpic = [];
        foreach (Tasks::query(['project_id' => $ctx['project']['id'], 'sort' => 'status']) as $t) {
            if ($t['epic_id']) $byEpic[$t['epic_id']][] = $t;
        }
        return View::page('planning/epics', $ctx + ['tab' => 'epics', 'byEpic' => $byEpic]);
    }

    public function projectReports(Request $r): Response
    {
        $ctx = ProjectContext::load($r, 'reports');
        return View::page('planning/reports', $ctx + ['tab' => 'reports', 'report' => Reports::project($ctx['project'])]);
    }

    public function workspaceReports(Request $r): Response
    {
        return View::page('planning/workspace-reports', ['title' => 'Reportes', 'nav' => 'reports', 'report' => Reports::workspace()]);
    }

    public function exportCsv(Request $r): Response
    {
        $p = Projects::find($r->intParam('id'));
        return self::download(Reports::tasksCsv(['project_id' => (int)$p['id'], 'include_archived' => 1]), $p['project_key'] . '-tareas-' . today() . '.csv');
    }

    /** Whole project as a Borealis file, to import it in this or another installation. */
    public function exportProject(Request $r): Response
    {
        $p = Projects::find($r->intParam('id'));
        $data = \Borealis\Services\ProjectTransfer::export((int)$p['id'], (string)$r->q('files') === '1');
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return new Response((string)$json, 200, ['Content-Type' => 'application/json; charset=utf-8', 'Content-Disposition' => 'attachment; filename="borealis-proyecto-' . $p['project_key'] . '-' . today() . '.json"', 'Cache-Control' => 'no-store']);
    }

    public function exportHours(Request $r): Response
    {
        $p = Projects::find($r->intParam('id'));
        [$from, $to] = Reports::range($r->query);
        return self::download(Reports::hoursCsv((int)$p['id'], $from, $to), $p['project_key'] . '-horas-' . today() . '.csv');
    }

    public function printProject(Request $r): Response
    {
        $ctx = ProjectContext::load($r, 'reports');
        return View::page('planning/print-project', ['title' => 'Informe · ' . $ctx['project']['name'], 'layout' => 'print', 'back' => url('/projects/' . $ctx['project']['id'] . '/reports'), 'project' => $ctx['project'], 'report' => Reports::project($ctx['project'])]);
    }

    public function exportWorkspace(Request $r): Response
    {
        $kind = (string)$r->param('kind');
        [$from, $to] = Reports::range($r->query);
        $csv = match ($kind) {
            'projects' => Reports::projectsCsv(),
            'tasks' => Reports::tasksCsv([]),
            'hours' => Reports::hoursCsv(null, $from, $to),
            default => throw HttpException::notFound(),
        };
        return self::download($csv, 'borealis-' . $kind . '-' . today() . '.csv');
    }

    public function printWorkspace(Request $r): Response
    {
        return View::page('planning/print-workspace', ['title' => 'Informe del espacio', 'layout' => 'print', 'report' => Reports::workspace()]);
    }

    private static function download(string $csv, string $name): Response
    {
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
        return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="' . $name . '"', 'Cache-Control' => 'no-store']);
    }
}
