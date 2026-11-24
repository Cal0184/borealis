<?php
declare(strict_types=1);

namespace Borealis\Controllers;

use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Request;
use Borealis\Core\Response;
use Borealis\Core\View;
use Borealis\Security\Policy;
use Borealis\Services\CapacityAlerts;
use Borealis\Services\Projects;
use Borealis\Services\Tasks;
use Borealis\Services\Teams;
use Borealis\Services\Workspaces;

final class TeamController
{
    public function index(Request $r): Response
    {
        $archived = $r->q('archived') === '1';
        $teams = Teams::list($archived);
        $people = Workspaces::members(false);
        // 1.4.1: guests see only the teams and people within their reach.
        if (($tv = Policy::visibleTeamIds()) !== null) $teams = array_values(array_filter($teams, fn($t) => in_array((int)$t['id'], $tv, true)));
        if (($uv = Policy::visibleUserIds()) !== null) $people = array_values(array_filter($people, fn($m) => in_array((int)$m['id'], $uv, true)));
        return View::page('teams/index', [
            'title' => 'Equipos', 'nav' => 'teams', 'teams' => $teams, 'archived' => $archived,
            'canCreate' => Policy::can('teams.create'), 'people' => $people,
        ]);
    }

    public function show(Request $r): Response
    {
        $team = Teams::find($r->intParam('id'));
        if (($tv = Policy::visibleTeamIds()) !== null && !in_array((int)$team['id'], $tv, true)) throw HttpException::notFound('El equipo no existe o no tienes acceso.');
        $members = Teams::members((int)$team['id']);
        $memberIds = array_map('intval', array_column($members, 'id'));
        $candidates = array_values(array_filter(Workspaces::members(false), fn($m) => !in_array((int)$m['id'], $memberIds, true)));
        $projects = Teams::projects((int)$team['id']);
        $linkable = array_values(array_filter(Projects::list(), fn($p) => (int)$p['team_id'] !== (int)$team['id'] && Policy::canProject($p, 'project.manage')));
        return View::page('teams/show', [
            'title' => $team['name'], 'nav' => 'teams', 'team' => $team, 'members' => $members, 'candidates' => $candidates,
            'projects' => $projects, 'linkable' => $linkable, 'canManage' => Teams::canManage($team), 'canDelete' => Policy::can('teams.manage'),
            'roles' => Workspaces::roles('workspace'), 'canInvite' => Policy::can('members.manage'),
        ]);
    }

    /** 1.5: open «Capacidad agotada» alerts and the history of decisions. */
    public function capacity(Request $r): Response
    {
        if (!CapacityAlerts::canSeeAny()) throw HttpException::forbidden('Solo la administración del espacio y la responsable de cada proyecto ven las alertas de capacidad.');
        return View::page('teams/capacity', [
            'title' => 'Alertas de capacidad', 'nav' => 'teams',
            'alerts' => CapacityAlerts::forView(), 'history' => CapacityAlerts::visible('resolved'),
        ]);
    }

    public function person(Request $r): Response
    {
        $person = Workspaces::member($r->intParam('id')) ?? throw HttpException::notFound('Esa persona no pertenece a este espacio.');
        if (($uv = Policy::visibleUserIds()) !== null && !in_array((int)$person['id'], $uv, true)) throw HttpException::notFound('Esa persona no pertenece a este espacio.');
        $uid = (int)$person['id'];
        [$vis, $vp] = Policy::visibleProjectsSql('p');
        $projects = Projects::withProgress(Database::get()->all("SELECT DISTINCT p.* FROM projects p
            LEFT JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ?
            LEFT JOIN team_members tm ON tm.team_id = p.team_id AND tm.user_id = ?
            WHERE p.archived_at IS NULL AND (pm.user_id IS NOT NULL OR tm.user_id IS NOT NULL
              OR EXISTS (SELECT 1 FROM project_teams pt JOIN team_members tm2 ON tm2.team_id = pt.team_id WHERE pt.project_id = p.id AND tm2.user_id = ?)) AND $vis ORDER BY p.name", array_merge([$uid, $uid, $uid], $vp)));
        $periods = ['semana' => ['Esta semana', date('Y-m-d', strtotime('monday this week', strtotime(today())))], 'mes' => ['Este mes', substr(today(), 0, 8) . '01'],
            'trimestre' => ['Últimos 90 días', date('Y-m-d', strtotime(today() . ' -89 days'))], 'todo' => ['Todo', null]];
        $period = isset($periods[(string)$r->q('periodo')]) ? (string)$r->q('periodo') : 'mes';
        return View::page('teams/person', [
            'hours' => \Borealis\Services\TimeEntries::forPerson($uid, $periods[$period][1]), 'periods' => $periods, 'period' => $period,
            'canManage' => Policy::can('members.manage'),
            'title' => $person['name'], 'nav' => 'teams', 'person' => $person, 'teams' => Teams::forUser($uid), 'projects' => $projects,
            'tasks' => Tasks::query(['assignee' => (string)$uid, 'category' => ['todo', 'in_progress'], 'with_subtasks' => 1, 'sort' => 'due', 'limit' => 100]),
        ]);
    }
}
