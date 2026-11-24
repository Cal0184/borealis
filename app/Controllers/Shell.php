<?php
declare(strict_types=1);

namespace Borealis\Controllers;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Security\Policy;
use Borealis\Services\Notifications;
use Borealis\Services\Projects;
use Borealis\Services\TimeEntries;
use Borealis\Services\Workspaces;

/** Data shared by every page of the application layout (sidebar, top bar). */
final class Shell
{
    private static ?array $data = null;

    public static function data(): array
    {
        if (self::$data !== null) return self::$data;
        // 1.6: in-app reminders of events (no cron), at most every two minutes per session
        if (time() - (int)\Borealis\Core\Session::get('ev_rem_at', 0) > 120) {
            \Borealis\Core\Session::set('ev_rem_at', time());
            try { \Borealis\Services\Events::generateReminders(); } catch (\Throwable $e) { error_log('Borealis event reminders: ' . $e->getMessage()); }
        }
        $ws = Policy::workspace();
        [$vis, $vp] = Policy::visibleProjectsSql('p');
        $modProjects = fn(string $mod) => (bool)Database::get()->value("SELECT 1 FROM projects p JOIN project_modules m ON m.project_id = p.id AND m.module_key = ? AND m.enabled = 1 WHERE p.archived_at IS NULL AND $vis LIMIT 1", array_merge([$mod], $vp));
        $db = Database::get();
        $mine = $db->one("SELECT COUNT(*) AS open, SUM(t.due_date < ?) AS overdue FROM tasks t JOIN projects p ON p.id = t.project_id JOIN statuses s ON s.id = t.status_id
            WHERE (t.assignee_id = ? OR EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id = t.id AND ta.user_id = ?) OR (t.team_id IS NOT NULL AND EXISTS (SELECT 1 FROM team_members tmx WHERE tmx.team_id = t.team_id AND tmx.user_id = ?)))
              AND t.deleted_at IS NULL AND p.archived_at IS NULL AND s.category <> 'done' AND $vis", array_merge([today(), Auth::id(), Auth::id(), Auth::id()], $vp));
        $weekly = $db->value('SELECT weekly_hours FROM workspace_members WHERE workspace_id = ? AND user_id = ?', [Policy::wsId(), Auth::id()]);
        $teams = $db->all('SELECT t.id, t.name, t.color FROM teams t JOIN team_members tm ON tm.team_id = t.id AND tm.user_id = ? WHERE t.workspace_id = ? AND t.archived_at IS NULL ORDER BY t.name LIMIT 5', [Auth::id(), Policy::wsId()]);
        return self::$data = [
            'my_open' => (int)($mine['open'] ?? 0),
            'my_overdue' => (int)($mine['overdue'] ?? 0),
            'week_minutes' => TimeEntries::myWeek(),
            'weekly_hours' => $weekly !== null ? (float)$weekly : null,
            'teams' => $teams,
            'user' => Auth::user(),
            'workspace' => $ws,
            'workspaces' => Workspaces::forUser(),
            'projects' => array_slice(Projects::list(['sort' => 'recent', 'standard' => 1]), 0, 6),
            'unread' => Notifications::unreadCount(),
            'show_calendar' => $modProjects('calendar'),
            'show_sprints' => $modProjects('sprints') || Policy::can('projects.create'),
            'show_reports' => true,
            'is_admin' => Policy::can('workspace.manage') || Policy::can('members.manage') || Policy::can('roles.manage'),
            'can_create_project' => Policy::can('projects.create'),
            'motion' => Auth::user()['prefs']['motion'] ?? 'full',
            'ambient' => Auth::user()['prefs']['ambient'] ?? 'default',
            'tour' => Auth::user()['prefs']['tour'] ?? null,
            'tour_step' => isset(Auth::user()['prefs']['tour_step']) ? (int)Auth::user()['prefs']['tour_step'] : null,
            // the tutorial shows «Sprints» inside a real project the person can see, preferably one with sprints
            'tour_project' => (int)($db->value("SELECT p.id FROM projects p LEFT JOIN project_modules pmo ON pmo.project_id = p.id AND pmo.module_key = 'sprints' AND pmo.enabled = 1 WHERE p.archived_at IS NULL AND $vis ORDER BY pmo.project_id IS NULL, p.updated_at DESC LIMIT 1", $vp) ?: 0),
        ];
    }

    public static function reset(): void
    {
        self::$data = null;
    }
}
