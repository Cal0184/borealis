<?php
declare(strict_types=1);

namespace Borealis\Controllers;

use Borealis\Core\Database;
use Borealis\Core\Request;
use Borealis\Core\Response;
use Borealis\Core\Session;
use Borealis\Core\Validator;
use Borealis\Core\View;
use Borealis\Security\Policy;
use Borealis\Services\Calendars;
use Borealis\Services\Events;
use Borealis\Services\Google;
use Borealis\Services\GoogleError;
use Borealis\Services\PersonalTasks;
use Borealis\Services\Projects;
use Borealis\Services\Tasks;
use Borealis\Services\Teams;
use Borealis\Services\Workspaces;

/** 1.6: general calendar (day / week / month), «Tareas» page and the Google connection pages. */
final class CalendarController
{
    /** 1.7.1: «Asignadas a mí» joined «Mis tareas» (?tab=assigned still works and opens it filtered). */
    public const TASK_TABS = ['mine' => 'Mis tareas', 'team' => 'Equipo', 'done' => 'Completadas'];
    public const MINE_SHOW = ['all' => 'Todas', 'projects' => 'De proyectos', 'personal' => 'Personales'];

    public function calendar(Request $r): Response
    {
        $view = in_array($r->q('view'), ['day', 'week', 'month'], true) ? (string)$r->q('view') : 'month';
        $anchor = (string)$r->q('date', today());
        if (!Validator::isDate($anchor)) $anchor = today();
        [$start, $end, $prev, $next] = match ($view) {
            'day' => [$anchor, $anchor, date('Y-m-d', strtotime($anchor . ' -1 day')), date('Y-m-d', strtotime($anchor . ' +1 day'))],
            'week' => (function () use ($anchor) { $s = date('Y-m-d', strtotime('monday this week', strtotime($anchor))); return [$s, date('Y-m-d', strtotime($s . ' +6 days')), date('Y-m-d', strtotime($s . ' -7 days')), date('Y-m-d', strtotime($s . ' +7 days'))]; })(),
            default => (function () use ($anchor) {
                $first = date('Y-m-01', strtotime($anchor));
                return [date('Y-m-d', strtotime('monday this week', strtotime($first))), date('Y-m-d', strtotime('sunday this week', strtotime(date('Y-m-t', strtotime($anchor))))),
                    date('Y-m-d', strtotime($first . ' -1 month')), date('Y-m-d', strtotime($first . ' +1 month'))];
            })(),
        };
        $calendars = Calendars::visible();
        $scope = Calendars::scope($r->q('scope') !== null ? (string)$r->q('scope') : null);
        $shownIds = array_map(fn($c) => (int)$c['id'], array_filter($calendars, fn($c) => $c['shown'] && Calendars::inScope($c, $scope)));
        $items = []; // day => list of items (events are also in $timed for the time grid)
        foreach (Events::range($start, $end, $shownIds) as $e) $items[] = ['type' => $e['kind'], 'day' => substr($e['occ_start'], 0, 10)] + $e;
        $layers = ['tasks' => Calendars::layerShown('tasks'), 'personal' => Calendars::layerShown('personal'), 'sprints' => Calendars::layerShown('sprints'), 'milestones' => Calendars::layerShown('milestones')];
        // Personal side: your own tasks (personal ones and those assigned to you). Team side: the
        // projects' tasks, sprints and milestones. Both: everything the person turned on.
        $on = $layers;
        if ($scope === 'personal') { $on['sprints'] = $on['milestones'] = false; }
        if ($scope === 'team') { $on['personal'] = false; }
        $editable = [];
        if ($on['tasks']) {
            $f = ['due_from' => $start, 'due_to' => $end, 'has_date' => 1, 'with_subtasks' => 1, 'sort' => 'priority', 'limit' => 400];
            if ($r->q('tasks') === 'mine' || $scope === 'personal') $f['assignee'] = 'me';
            foreach (Tasks::query($f) as $t) {
                if (!$t['due_date'] || $t['due_date'] < $start || $t['due_date'] > $end) continue;
                if (!isset($editable[$t['project_id']])) { $p = Projects::find((int)$t['project_id']); $editable[$t['project_id']] = !$p['archived_at'] && Policy::canProject($p, 'task.edit'); }
                $items[] = ['type' => 'task', 'day' => $t['due_date'], 'editable' => $editable[$t['project_id']]] + $t;
            }
        }
        if ($on['personal']) foreach (PersonalTasks::dueBetween($start, $end) as $t) $items[] = ['type' => 'personal', 'day' => $t['due_date']] + $t;
        if ($on['sprints']) foreach (\Borealis\Services\Sprints::calendarItems($start, $end) as $it) $items[] = $it;
        if ($on['milestones']) {
            [$vis, $vp] = Policy::visibleProjectsSql('p');
            foreach (Database::get()->all("SELECT p.id, p.name, p.color, p.start_date, p.due_date, p.state FROM projects p WHERE p.archived_at IS NULL AND $vis AND ((p.start_date BETWEEN ? AND ?) OR (p.due_date BETWEEN ? AND ?))", array_merge($vp, [$start, $end, $start, $end])) as $p) {
                if ($p['start_date'] && $p['start_date'] >= $start && $p['start_date'] <= $end) $items[] = ['type' => 'milestone', 'edge' => 'start', 'day' => $p['start_date']] + $p;
                if ($p['due_date'] && $p['due_date'] >= $start && $p['due_date'] <= $end) $items[] = ['type' => 'milestone', 'edge' => 'due', 'day' => $p['due_date']] + $p;
            }
        }
        $byDay = [];
        foreach ($items as $it) {
            if (in_array($it['type'], ['event', 'meeting'], true)) {
                // an event spanning several days appears on each of them
                $d0 = substr($it['occ_start'], 0, 10);
                $d1 = $it['all_day'] ? date('Y-m-d', strtotime(substr($it['occ_end'], 0, 10) . ' -1 day')) : substr($it['occ_end'], 0, 10);
                if (!$it['all_day'] && substr($it['occ_end'], 11, 5) === '00:00' && $d1 > $d0) $d1 = date('Y-m-d', strtotime($d1 . ' -1 day'));
                for ($d = max($d0, $start), $i = 0; $d <= min($d1, $end) && $i < 62; $d = date('Y-m-d', strtotime($d . ' +1 day')), $i++) $byDay[$d][] = $it + ['cont' => $d !== $d0];
            } else {
                $byDay[$it['day']][] = $it;
            }
        }
        $editableCals = array_values(array_filter($calendars, fn($c) => Calendars::LEVELS[$c['access']] >= Calendars::LEVELS['edit'] && ($c['source'] !== 'google' || in_array($c['google_access'], ['owner', 'writer'], true))));
        $google = Google::account();
        return View::page('calendar/index', [
            'title' => 'Calendario', 'nav' => 'calendar', 'view' => $view, 'anchor' => $anchor, 'start' => $start, 'end' => $end, 'prev' => $prev, 'next' => $next,
            'calendars' => $calendars, 'editableCals' => $editableCals, 'byDay' => $byDay, 'layers' => $layers, 'scope' => $scope, 'tasksFilter' => $r->q('tasks') === 'mine' ? 'mine' : 'all',
            'people' => Workspaces::members(false), 'projects' => Projects::list(), 'teams' => Teams::list(), 'google' => $google,
            'googleStale' => $google && $google['status'] === 'active' && (!$google['last_sync_at'] || strtotime($google['last_sync_at']) < time() - 600),
            'openEvent' => (int)$r->q('event'),
        ]);
    }

    public function tasks(Request $r): Response
    {
        $q = (string)$r->q('tab');
        $show = array_key_exists((string)$r->q('show'), self::MINE_SHOW) ? (string)$r->q('show') : 'all';
        if ($q === 'assigned') { $q = 'mine'; $show = 'projects'; } // links from 1.6/1.7
        $tab = array_key_exists($q, self::TASK_TABS) ? $q : 'mine';
        $data = ['title' => 'Tareas', 'nav' => 'tasks', 'tab' => $tab, 'show' => $show, 'filters' => $r->query, 'errors' => [], 'google' => null];
        // Each block loads on its own: if one fails, the others are still shown, with the reason.
        $safe = function (string $block, callable $fn) use (&$data) {
            try { $data += $fn(); }
            catch (\Borealis\Core\HttpException $e) { throw $e; }
            catch (\Throwable $e) {
                error_log('[Borealis] /tasks ' . $block . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                $data['errors'][$block] = true;
            }
        };
        $safe('google', fn() => ['google' => Google::account()]);
        if ($tab === 'mine') {
            if ($show !== 'personal') $safe('projects', fn() => self::assignedToMe($r));
            if ($show !== 'projects') $safe('personal', function () use ($r) {
                $lists = PersonalTasks::lists();
                $listId = (int)$r->q('list');
                $current = null;
                foreach ($lists as $l) if ((int)$l['id'] === $listId) $current = $l;
                $current ??= $lists[0];
                return ['lists' => $lists, 'list' => $current, 'items' => PersonalTasks::tasks((int)$current['id'], 'open')];
            });
        } elseif ($tab === 'done') {
            $safe('personal', function () {
                $done = [];
                foreach (PersonalTasks::lists() as $l) foreach (PersonalTasks::tasks((int)$l['id'], 'done') as $t) $done[] = $t + ['list_name' => $l['name']];
                usort($done, fn($a, $b) => strcmp((string)$b['completed_at'], (string)$a['completed_at']));
                return ['personalDone' => array_slice($done, 0, 100)];
            });
            $safe('projects', fn() => ['teamDone' => Tasks::query(['assignee' => 'me', 'with_subtasks' => 1, 'category' => ['done'], 'sort' => 'updated', 'limit' => 100])]);
        } else {
            $safe('projects', fn() => self::teamTasks($r));
        }
        return View::page('tasks/index', $data);
    }

    public const VIEWS = ['table' => 'Tabla', 'list' => 'Lista compacta', 'board' => 'Tablero'];
    public const GROUPS = ['none' => 'Sin agrupar', 'project' => 'Proyecto', 'assignee' => 'Responsable', 'status' => 'Estado', 'due' => 'Fecha'];
    private const PAGE = 100;

    /**
     * 1.15 · «Tareas › Equipo»: the open tasks of every project the person can see, as a table, a
     * compact list or a board, grouped by project, person, state or date, with filters that can be
     * saved. The chosen view and grouping are remembered in the profile. Results come in pages of 100
     * so a large workspace never loads everything at once.
     */
    private static function teamTasks(Request $r): array
    {
        $prefs = \Borealis\Core\Auth::user()['prefs'] ?? [];
        $view = array_key_exists((string)$r->q('view'), self::VIEWS) ? (string)$r->q('view') : ($prefs['task_view'] ?? 'table');
        $group = array_key_exists((string)$r->q('group'), self::GROUPS) ? (string)$r->q('group') : ($prefs['task_group'] ?? 'none');
        if (!isset(self::VIEWS[$view])) $view = 'table';
        if (!isset(self::GROUPS[$group])) $group = 'none';
        if (($r->q('view') && $view !== ($prefs['task_view'] ?? null)) || ($r->q('group') && $group !== ($prefs['task_group'] ?? null))) {
            try { \Borealis\Services\Users::savePrefs(['task_view' => $view, 'task_group' => $group]); } catch (\Throwable) {}
        }
        $f = ['with_subtasks' => 1, 'category' => ['todo', 'in_progress'], 'sort' => 'due'];
        if ($r->q('project_id')) $f['project_id'] = (int)$r->q('project_id');
        if (is_string($r->q('assignee')) && preg_match('/^(me|none|\d+)$/', (string)$r->q('assignee'))) $f['assignee'] = (string)$r->q('assignee');
        if ($r->q('priority_id')) $f['priority_id'] = (int)$r->q('priority_id');
        if (is_string($r->q('q')) && trim((string)$r->q('q')) !== '') $f['q'] = mb_substr(trim((string)$r->q('q')), 0, 80);
        if ($r->q('blocked') === '1') $f['blocked'] = 1;
        $due = (string)$r->q('due');
        if ($due === 'overdue') $f['overdue'] = 1;
        elseif ($due === 'week') { $f['due_from'] = today(); $f['due_to'] = date('Y-m-d', strtotime(today() . ' +7 days')); }
        elseif ($due === 'none') $f['no_date'] = 1;
        $page = max(1, (int)$r->q('page', 1));
        $rows = Tasks::query($f + ['limit' => self::PAGE * $page + 1]);
        $more = count($rows) > self::PAGE * $page;
        $rows = array_slice($rows, 0, self::PAGE * $page);
        $out = self::withEditing($rows) + ['projects' => Projects::list(), 'view' => $view, 'group' => $group, 'page' => $page, 'hasMore' => $more,
            'people' => \Borealis\Services\Workspaces::members(false), 'priorities' => \Borealis\Services\Workspaces::priorities(), 'savedViews' => \Borealis\Services\SavedViews::list()];
        $out['grouped'] = self::groupTasks($rows, $group);
        return $out;
    }

    /** [label => tasks] in a stable, meaningful order. */
    public static function groupTasks(array $rows, string $group): array
    {
        if ($group === 'none') return ['' => $rows];
        $today = today();
        $week = date('Y-m-d', strtotime($today . ' +7 days'));
        $out = [];
        foreach ($rows as $t) {
            $k = match ($group) {
                'project' => $t['project_name'],
                'assignee' => $t['assignee_name'] ?: ($t['team_name'] ?? null ?: 'Sin responsable'),
                'status' => $t['status_name'],
                'due' => !$t['due_date'] ? '5 Sin fecha' : ($t['due_date'] < $today ? '1 Vencidas' : ($t['due_date'] === $today ? '2 Hoy' : ($t['due_date'] <= $week ? '3 Próximos 7 días' : '4 Más adelante'))),
            };
            $out[$k][] = $t;
        }
        if ($group === 'due') { ksort($out); $out = array_combine(array_map(fn($k) => substr($k, 2), array_keys($out)), array_values($out)); }
        elseif ($group !== 'status') uksort($out, fn($a, $b) => (in_array($a, ['Sin responsable'], true) <=> in_array($b, ['Sin responsable'], true)) ?: strcoll($a, $b));
        return $out;
    }

    /** Open project tasks where the person is responsible (main, co-assignee or through a team), by due date. */
    private static function assignedToMe(Request $r): array
    {
        $f = ['assignee' => 'me', 'with_subtasks' => 1, 'category' => ['todo', 'in_progress'], 'sort' => 'due', 'limit' => 300];
        if ($r->q('project_id')) $f['project_id'] = (int)$r->q('project_id');
        $out = self::withEditing(Tasks::query($f));
        $groups = ['overdue' => [], 'today' => [], 'week' => [], 'later' => [], 'none' => []];
        $today = today();
        $week = date('Y-m-d', strtotime($today . ' +7 days'));
        foreach ($out['tasks'] as $t) {
            $d = $t['due_date'];
            $g = $d === null ? 'none' : ($d < $today ? 'overdue' : ($d === $today ? 'today' : ($d <= $week ? 'week' : 'later')));
            $groups[$g][] = $t;
        }
        return ['assigned' => $groups, 'assignedCount' => count($out['tasks']), 'statuses' => $out['statuses'], 'editable' => $out['editable'], 'projects' => Projects::list()];
    }

    /** Statuses of each project in the list and whether the person may edit its tasks. */
    private static function withEditing(array $tasks): array
    {
        $statuses = $editable = [];
        foreach (array_unique(array_column($tasks, 'project_id')) as $pid) {
            $statuses[$pid] = Projects::statuses((int)$pid);
            $p = Projects::find((int)$pid);
            $editable[$pid] = !$p['archived_at'] && Policy::canProject($p, 'task.edit');
        }
        return ['tasks' => $tasks, 'statuses' => $statuses, 'editable' => $editable];
    }

    /** «Conectar con Google»: sends the person to Google's consent screen. */
    public function googleConnect(Request $r): Response
    {
        try {
            return Response::redirect(Google::authUrl());
        } catch (\Borealis\Core\HttpException $e) {
            Session::flash('google_msg', ['error', $e->getMessage()]);
            return Response::redirect(url('/profile') . '#google');
        }
    }

    public function googleCallback(Request $r): Response
    {
        try {
            $msg = Google::finish($r->query);
            Session::flash('google_msg', [str_starts_with($msg, 'No se') || str_starts_with($msg, 'Google rechazó') ? 'error' : 'ok', $msg]);
        } catch (GoogleError | \Borealis\Core\HttpException $e) {
            Session::flash('google_msg', ['error', $e->getMessage()]);
        }
        return Response::redirect(url('/profile') . '#google');
    }
}
