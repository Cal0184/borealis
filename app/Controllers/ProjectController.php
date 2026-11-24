<?php
declare(strict_types=1);

namespace Borealis\Controllers;

use Borealis\Core\Request;
use Borealis\Core\Response;
use Borealis\Core\View;
use Borealis\Security\Policy;
use Borealis\Services\Activity;
use Borealis\Services\Modules;
use Borealis\Services\Projects;
use Borealis\Services\Sprints;
use Borealis\Services\Tasks;
use Borealis\Services\Teams;
use Borealis\Services\Workspaces;

final class ProjectController
{
    public function index(Request $r): Response
    {
        $f = array_intersect_key($r->query, array_flip(['q', 'state', 'team_id', 'archived', 'sort', 'mine']));
        return View::page('projects/index', [
            'title' => 'Proyectos', 'nav' => 'projects', 'projects' => Projects::list($f), 'filters' => $f,
            'teams' => Teams::list(), 'canCreate' => Policy::can('projects.create'), 'people' => Workspaces::members(false),
        ]);
    }

    public function summary(Request $r): Response
    {
        $ctx = ProjectContext::load($r);
        $pid = (int)$ctx['project']['id'];
        return View::page('projects/summary', $ctx + [
            'tab' => 'summary',
            'activity' => Activity::recent(10, $pid),
            'upcoming' => Tasks::query(['project_id' => $pid, 'category' => ['todo', 'in_progress'], 'has_date' => 1, 'sort' => 'due', 'limit' => 6]),
            'blocked' => Tasks::query(['project_id' => $pid, 'blocked' => 1, 'category' => ['todo', 'in_progress'], 'limit' => 6]),
            'activeSprint' => $ctx['modules']['sprints'] ? Sprints::active($pid) : null,
            'byStatus' => \Borealis\Core\Database::get()->all('SELECT s.id, s.name, s.color, s.category, COUNT(t.id) AS n FROM statuses s LEFT JOIN tasks t ON t.status_id = s.id AND t.deleted_at IS NULL AND t.parent_id IS NULL WHERE s.project_id = ? GROUP BY s.id ORDER BY s.position', [$pid]),
        ]);
    }

    public function list(Request $r): Response
    {
        $ctx = ProjectContext::load($r);
        $f = ProjectContext::filters($r);
        $sort = array_key_exists((string)$r->q('sort'), Tasks::SORTS) ? (string)$r->q('sort') : 'status';
        $show = (string)$r->q('show', 'open');
        if ($show === 'open') $f['category'] = ['todo', 'in_progress'];
        elseif ($show === 'done') $f['category'] = ['done'];
        $tasks = Tasks::query(['project_id' => $ctx['project']['id'], 'sort' => $sort, 'dir' => $r->q('dir') === 'desc' ? 'desc' : 'asc'] + $f);
        return View::page('projects/list', $ctx + ['tab' => 'list', 'tasks' => $tasks, 'filters' => $r->query, 'sort' => $sort, 'show' => $show]);
    }

    public function board(Request $r): Response
    {
        $ctx = ProjectContext::load($r);
        $f = ProjectContext::filters($r);
        if ($ctx['modules']['sprints'] && !isset($f['sprint_id']) && $r->q('sprint_id') === null) {
            $active = Sprints::active((int)$ctx['project']['id']);
            if ($active) $f['sprint_id'] = (string)$active['id'];
        }
        $tasks = Tasks::query(['project_id' => $ctx['project']['id'], 'sort' => 'position'] + $f);
        $columns = [];
        foreach ($ctx['statuses'] as $s) $columns[$s['id']] = ['status' => $s, 'tasks' => []];
        foreach ($tasks as $t) $columns[$t['status_id']]['tasks'][] = $t;
        $boardPeople = array_values(array_filter(\Borealis\Services\Projects::assignableUsers($ctx['project']), fn($m) => Tasks::canMovePerson($ctx['project'], (int)$m['id'])));
        $movableIds = array_map('intval', array_column($boardPeople, 'id'));
        // Board showing one sprint: everyone's available hours in it, for the people strip.
        $boardSprint = null;
        $sprintPeople = [];
        if ($ctx['modules']['sprints'] && !empty($f['sprint_id']) && ctype_digit((string)$f['sprint_id'])) {
            $boardSprint = \Borealis\Core\Database::get()->one('SELECT * FROM sprints WHERE id = ? AND project_id = ?', [(int)$f['sprint_id'], (int)$ctx['project']['id']]);
            if ($boardSprint) $sprintPeople = \Borealis\Services\Capacity::people($boardSprint, $ctx['project']);
        }
        return View::page('projects/board', $ctx + ['boardPeople' => $boardPeople, 'movableIds' => $movableIds, 'boardSprint' => $boardSprint, 'sprintPeople' => $sprintPeople, 'tab' => 'board', 'columns' => $columns, 'filters' => $r->query + (isset($f['sprint_id']) ? ['sprint_id' => $f['sprint_id']] : []), 'taskCount' => count($tasks)]);
    }

    public function settings(Request $r): Response
    {
        $ctx = ProjectContext::load($r);
        $p = $ctx['project'];
        if (!Policy::canProject($p, 'project.manage')) throw \Borealis\Core\HttpException::forbidden('Solo responsables del proyecto pueden cambiar sus ajustes.');
        $memberIds = array_map('intval', array_column(array_filter($ctx['members'], fn($m) => $m['source'] === 'direct'), 'id'));
        return View::page('projects/settings', $ctx + [
            'tab' => 'settings', 'section' => (string)$r->q('section', 'general'),
            'teams' => Teams::list(), 'people' => Workspaces::members(false), 'memberIds' => $memberIds,
            'projectRoles' => Workspaces::roles('project'), 'catalog' => Modules::OPTIONAL,
            'accessTeams' => \Borealis\Services\Projects::accessTeams($p),
        ]);
    }
}
