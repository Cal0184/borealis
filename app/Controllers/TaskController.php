<?php
declare(strict_types=1);

namespace Borealis\Controllers;

use Borealis\Core\Request;
use Borealis\Core\Response;
use Borealis\Core\View;
use Borealis\Security\Policy;
use Borealis\Services\Activity;
use Borealis\Services\Comments;
use Borealis\Services\Epics;
use Borealis\Services\Files;
use Borealis\Services\Modules;
use Borealis\Services\Projects;
use Borealis\Services\Sprints;
use Borealis\Services\Tasks;
use Borealis\Services\Workspaces;

final class TaskController
{
    public static function detail(array $t): array
    {
        $p = $t['project'];
        $pid = (int)$p['id'];
        $modules = Modules::enabled($pid);
        $perms = Policy::projectPerms($p);
        $editable = !$p['archived_at'];
        return [
            'task' => $t,
            'project' => $p,
            'modules' => $modules,
            'can' => fn(string $perm) => $editable && in_array($perm, $perms, true),
            'statuses' => Projects::statuses($pid),
            'priorities' => Workspaces::priorities(),
            'labels' => Projects::labels($pid),
            'assignable' => Projects::assignableUsers($p),
            'taskTeams' => Policy::canProject($p, 'project.manage') ? \Borealis\Services\Teams::list() : Projects::accessTeams($p),
            'epics' => $modules['epics'] ? Epics::list($pid) : [],
            'sprints' => $modules['sprints'] ? array_values(array_filter(Sprints::list($pid), fn($s) => $s['state'] !== 'completed' || (int)$s['id'] === (int)$t['sprint_id'])) : [],
            'subtasks' => $t['parent_id'] ? [] : Tasks::query(['parent_id' => (int)$t['id'], 'project_id' => $pid, 'sort' => 'oldest']),
            'parent' => $t['parent_id'] ? Tasks::find((int)$t['parent_id']) : null,
            'comments' => Comments::forTask((int)$t['id']),
            'files' => Files::list($pid, ['task_id' => (int)$t['id']]),
            'links' => Tasks::links((int)$t['id']),
            'time' => \Borealis\Services\TimeEntries::forTask((int)$t['id']),
            'history' => Activity::recent(30, $pid, (int)$t['id']),
        ];
    }

    public function show(Request $r): Response
    {
        $t = Tasks::findByKey($r->param('key'));
        if (strtoupper((string)$r->param('key')) !== $t['task_key']) {
            return Response::redirect(url('/t/' . $t['task_key'], $r->q('drawer') === '1' ? ['drawer' => 1] : []));
        }
        $data = self::detail($t);
        if ($r->q('drawer') === '1') {
            return Response::html(View::render('partials/task-detail', $data + ['inDrawer' => true]));
        }
        return View::page('task', $data + ['title' => $t['task_key'] . ' · ' . $t['title'], 'nav' => 'projects', 'inDrawer' => false]);
    }
}
