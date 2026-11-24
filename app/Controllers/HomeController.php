<?php
declare(strict_types=1);

namespace Borealis\Controllers;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Request;
use Borealis\Core\Response;
use Borealis\Core\View;
use Borealis\Security\Policy;
use Borealis\Services\Activity;
use Borealis\Services\Notifications;
use Borealis\Services\Projects;
use Borealis\Services\Search;
use Borealis\Services\Tasks;
use Borealis\Services\Teams;
use Borealis\Services\Workspaces;

final class HomeController
{
    public function home(Request $r): Response
    {
        Notifications::generateDueReminders();
        $projects = Projects::list(['sort' => 'recent', 'standard' => 1]);
        $active = array_values(array_filter($projects, fn($p) => $p['state'] !== 'completed'));
        $mine = Tasks::query(['assignee' => 'me', 'category' => ['todo', 'in_progress'], 'sort' => 'due', 'with_subtasks' => 1, 'limit' => 200]);
        $soon = array_values(array_filter($mine, fn($t) => $t['due_date'] && $t['due_date'] <= date('Y-m-d', strtotime(today() . ' +14 days'))));
        $weekAgo = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
        $doneWeek = Tasks::query(['category' => ['done'], 'completed_since' => $weekAgo, 'limit' => 2000]);
        $totals = ['total' => array_sum(array_column($active, 'total')), 'done' => array_sum(array_column($active, 'done'))];
        return View::page('home', [
            'title' => 'Inicio', 'nav' => 'home',
            'projects' => array_slice($active, 0, 6),
            'project_count' => count($active),
            'mine' => $mine,
            'mine_progress' => count(array_filter($mine, fn($t) => $t['status_category'] === 'in_progress')),
            'soon' => array_slice($soon, 0, 7),
            'overdue' => count(array_filter($mine, fn($t) => $t['due_date'] && $t['due_date'] < today())),
            'done_week' => count($doneWeek),
            'totals' => $totals,
            'teams' => Teams::forUser(Auth::id()),
            'activity' => Activity::recent(8),
        ]);
    }

    public const MY_TABS = [
        'today' => 'Hoy', 'open' => 'Abiertas', 'todo' => 'Pendientes', 'progress' => 'En proceso', 'overdue' => 'Vencidas', 'upcoming' => 'Próximas', 'done' => 'Finalizadas',
    ];

    public function myWork(Request $r): Response
    {
        $tab = array_key_exists((string)$r->q('tab'), self::MY_TABS) ? (string)$r->q('tab') : 'open';
        $f = ['assignee' => 'me', 'with_subtasks' => 1, 'sort' => in_array($r->q('sort'), ['due', 'priority', 'updated', 'key'], true) ? $r->q('sort') : 'due'];
        $f += match ($tab) {
            'todo' => ['category' => ['todo']],
            'progress' => ['category' => ['in_progress']],
            'overdue' => ['overdue' => 1],
            // 1.11: «Hoy» = open tasks due today; «Próximas» starts tomorrow so no task is in both
            'today' => ['category' => ['todo', 'in_progress'], 'due_on' => today()],
            'upcoming' => ['category' => ['todo', 'in_progress'], 'due_from' => date('Y-m-d', strtotime(today() . ' +1 day')), 'due_to' => date('Y-m-d', strtotime(today() . ' +14 days')), 'has_date' => 1],
            'done' => ['category' => ['done'], 'sort' => 'updated', 'limit' => 100],
            default => ['category' => ['todo', 'in_progress']],
        };
        if ($r->q('q')) $f['q'] = (string)$r->q('q');
        if ($r->q('project_id')) $f['project_id'] = (int)$r->q('project_id');
        if ($r->q('priority_id')) $f['priority_id'] = (int)$r->q('priority_id');
        $tasks = Tasks::query($f);
        $counts = [];
        foreach (self::MY_TABS as $k => $_) {
            $counts[$k] = match ($k) {
                'open' => count(Tasks::query(['assignee' => 'me', 'with_subtasks' => 1, 'category' => ['todo', 'in_progress']])),
                'todo' => count(Tasks::query(['assignee' => 'me', 'with_subtasks' => 1, 'category' => ['todo']])),
                'progress' => count(Tasks::query(['assignee' => 'me', 'with_subtasks' => 1, 'category' => ['in_progress']])),
                'overdue' => count(Tasks::query(['assignee' => 'me', 'with_subtasks' => 1, 'overdue' => 1])),
                'today' => count(Tasks::query(['assignee' => 'me', 'with_subtasks' => 1, 'category' => ['todo', 'in_progress'], 'due_on' => today()])),
                default => null,
            };
        }
        $statuses = $editable = [];
        foreach (array_unique(array_column($tasks, 'project_id')) as $pid) {
            $statuses[$pid] = Projects::statuses((int)$pid);
            $proj = Projects::find((int)$pid);
            $editable[$pid] = !$proj['archived_at'] && Policy::canProject($proj, 'task.edit');
        }
        return View::page('my-work', [
            'title' => 'Mi trabajo', 'nav' => 'my-work', 'tab' => $tab, 'tasks' => $tasks, 'counts' => $counts, 'statuses' => $statuses, 'editable' => $editable,
            'projects' => Projects::list(), 'priorities' => Workspaces::priorities(), 'filters' => $r->query,
        ]);
    }

    public function search(Request $r): Response
    {
        $q = trim((string)$r->q('q', ''));
        return View::page('search', ['title' => 'Buscar', 'nav' => '', 'q' => $q, 'results' => Search::run($q)]);
    }

    public function notifications(Request $r): Response
    {
        $unread = $r->q('filter') === 'unread';
        $cat = isset(Notifications::CATEGORIES[(string)$r->q('cat')]) ? (string)$r->q('cat') : null;
        \Borealis\Services\Events::generateReminders(); // 1.6: event reminders are due now, not in two minutes
        Notifications::generateDueReminders(); // 1.8: due, overdue and sprint notices too
        return View::page('notifications', ['title' => 'Bandeja de entrada', 'nav' => 'notifications', 'items' => Notifications::list(100, $unread, $cat), 'unreadOnly' => $unread, 'cat' => $cat]);
    }

    public function notificationsMenu(Request $r): Response
    {
        \Borealis\Services\Events::generateReminders();
        return Response::html(View::render('partials/notification-menu', ['items' => Notifications::list(8)]));
    }

    public function openNotification(Request $r): Response
    {
        $n = Database::get()->one('SELECT * FROM notifications WHERE id = ? AND user_id = ?', [$r->intParam('id'), Auth::id()]) ?? throw HttpException::notFound();
        Notifications::markRead((int)$n['id']);
        $to = (string)$n['url'];
        $base = rtrim((string)config('app.base_path', ''), '/');
        if ($to === '' || $to[0] !== '/' || str_starts_with($to, '//') || ($base !== '' && !str_starts_with($to, $base))) $to = url('/notifications');
        return Response::redirect($to);
    }

    /** 1.7: a profile photo. Its address changes with every new photo, so it can be cached. */
    public function avatar(Request $r): Response
    {
        $f = \Borealis\Services\Avatars::file($r->intParam('id'), $r->param('token'), $r->intParam('size'));
        return new Response('', 200, [
            'Content-Type' => 'image/webp', 'Content-Length' => (string)filesize($f),
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], $f);
    }

    public function profile(Request $r): Response
    {
        return View::page('profile', ['title' => 'Mi perfil', 'nav' => '', 'me' => Auth::user(), 'teams' => Teams::forUser(Auth::id()), 'membership' => Policy::workspace()]);
    }
}
