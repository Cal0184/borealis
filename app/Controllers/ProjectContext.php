<?php
declare(strict_types=1);

namespace Borealis\Controllers;

use Borealis\Core\Request;
use Borealis\Security\Policy;
use Borealis\Services\Epics;
use Borealis\Services\Modules;
use Borealis\Services\Projects;
use Borealis\Services\Sprints;
use Borealis\Services\Workspaces;

/** Loads what every project screen needs: one place, consistent names everywhere. */
final class ProjectContext
{
    public static function load(Request $r, ?string $module = null): array
    {
        $p = Projects::withProgress([Projects::find($r->intParam('id'))])[0];
        if ($module) Modules::require($p, $module);
        $modules = Modules::enabled((int)$p['id']);
        $perms = Policy::projectPerms($p);
        $editable = !$p['archived_at'];
        return [
            'project' => $p,
            'modules' => $modules,
            'can' => fn(string $perm) => in_array($perm, $perms, true) && ($editable || in_array($perm, ['project.view', 'project.manage', 'project.archive'], true)),
            'statuses' => Projects::statuses((int)$p['id']),
            'priorities' => Workspaces::priorities(),
            'labels' => Projects::labels((int)$p['id']),
            'members' => Projects::members($p),
            'assignable' => Projects::assignableUsers($p),
            'epics' => $modules['epics'] ? Epics::list((int)$p['id']) : [],
            'sprints' => $modules['sprints'] ? Sprints::list((int)$p['id']) : [],
            'title' => $p['name'],
            'nav' => 'projects',
        ];
    }

    /** Task filters accepted from the query string by list/board/backlog/calendar. */
    public static function filters(Request $r): array
    {
        $f = [];
        foreach (['assignee', 'priority_id', 'label_id', 'q', 'sprint_id', 'epic_id', 'status_id'] as $k) {
            $v = $r->q($k);
            if (is_string($v) && $v !== '') $f[$k] = $v;
        }
        if ($r->q('blocked') === '1') $f['blocked'] = 1;
        return $f;
    }
}
