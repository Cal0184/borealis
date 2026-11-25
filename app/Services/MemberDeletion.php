<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Security\AccountPolicy;
use Borealis\Security\Policy;

/**
 * 1.16.3 · Administración › Usuarios › «Eliminar usuario».
 *
 * Takes a person out of the current workspace for good, after a full backup:
 *  - their open tasks, the projects they lead and their place on open tasks' assignees go to the
 *    person chosen ($reassignTo) or stay without anyone responsible;
 *  - memberships of this workspace's projects, teams, calendars, events and sprints are removed,
 *    as are their follows, notifications, favourites, recents and saved views here;
 *  - their history stays: comments, hours, finished tasks and the Master Log keep pointing at them.
 * If the account belongs to no other workspace, it is also closed for good: it can no longer sign
 * in, its name becomes «Usuario eliminado», its e-mail is freed, and its devices, Google link and
 * personal lists are removed. An account still used in other workspaces only leaves this one.
 * Rows are never hard-deleted from users, so every foreign key and the audit chain stay valid.
 */
final class MemberDeletion
{
    /** What would be affected, for the confirmation dialog: [user_id => [open_tasks, led_projects]]. */
    public static function impact(): array
    {
        $db = Database::get();
        $ws = Policy::wsId();
        $out = [];
        foreach ($db->all('SELECT x.user_id, COUNT(DISTINCT x.task_id) AS n FROM (
                SELECT t.id AS task_id, t.assignee_id AS user_id FROM tasks t JOIN projects p ON p.id = t.project_id WHERE p.workspace_id = ? AND t.completed_at IS NULL AND t.deleted_at IS NULL AND t.assignee_id IS NOT NULL
                UNION SELECT t.id, ta.user_id FROM task_assignees ta JOIN tasks t ON t.id = ta.task_id JOIN projects p ON p.id = t.project_id WHERE p.workspace_id = ? AND t.completed_at IS NULL AND t.deleted_at IS NULL
            ) x GROUP BY x.user_id', [$ws, $ws]) as $r) $out[(int)$r['user_id']]['tasks'] = (int)$r['n'];
        foreach ($db->all('SELECT lead_user_id AS user_id, COUNT(*) AS n FROM projects WHERE workspace_id = ? AND lead_user_id IS NOT NULL AND archived_at IS NULL GROUP BY lead_user_id', [$ws]) as $r) $out[(int)$r['user_id']]['projects'] = (int)$r['n'];
        return $out;
    }

    /**
     * @return array{name:string, account_closed:bool, other_workspaces:int, tasks:int, projects:int, reassigned_to:?string, backup:string}
     */
    public static function delete(int $userId, ?int $reassignTo = null, string $reason = ''): array
    {
        Policy::authorize('members.manage');
        if ($userId === Auth::id()) throw HttpException::conflict('No puedes eliminar tu propia cuenta.');
        $m = Workspaces::member($userId) ?? throw HttpException::notFound('Esa persona no pertenece a este espacio.');
        AccountPolicy::authorizeOver($m, 'la cuenta');
        $db = Database::get();
        $target = $db->one('SELECT is_instance_admin FROM users WHERE id = ?', [$userId]);
        if ((int)($target['is_instance_admin'] ?? 0) && !AccountPolicy::isInstanceAdmin()) {
            throw HttpException::forbidden('Solo la administración de la instalación puede eliminar a esta persona.');
        }
        if ($m['role_key'] === 'owner') {
            $others = (int)$db->value("SELECT COUNT(*) FROM workspace_members wm JOIN roles r ON r.id = wm.role_id WHERE wm.workspace_id = ? AND r.role_key = 'owner' AND wm.status = 'active' AND wm.user_id <> ?", [Policy::wsId(), $userId]);
            if ($others === 0) throw HttpException::conflict('El espacio debe conservar al menos un propietario activo.');
        }
        $heir = null;
        if ($reassignTo) {
            if ($reassignTo === $userId) throw HttpException::invalid(['reassign_to' => 'Elige a otra persona.']);
            $heir = Workspaces::member($reassignTo);
            if (!$heir || $heir['status'] !== 'active') throw HttpException::invalid(['reassign_to' => 'Esa persona no está activa en este espacio.']);
        }
        $reason = trim($reason);

        $backup = basename(Backup::create(null, 'antes-de-eliminar-usuario'));
        $ws = Policy::wsId();
        $others = AccountPolicy::otherWorkspaces($userId);
        $closeAccount = $others === 0;
        $impact = self::impact()[$userId] ?? [];

        $db->transaction(function () use ($db, $ws, $userId, $heir, $closeAccount) {
            $wsTasks = 'SELECT t.id FROM tasks t JOIN projects p ON p.id = t.project_id WHERE p.workspace_id = ?';
            $openTasks = $wsTasks . ' AND t.completed_at IS NULL';
            $hid = $heir ? (int)$heir['id'] : null;
            // Open work changes hands (or stays without anyone); finished tasks keep who did them.
            if ($hid) {
                $db->run("INSERT IGNORE INTO task_assignees (task_id, user_id) SELECT ta.task_id, ? FROM task_assignees ta WHERE ta.user_id = ? AND ta.task_id IN (SELECT id FROM ($openTasks) o)", [$hid, $userId, $ws]);
                $db->run("INSERT IGNORE INTO project_members (project_id, user_id, role_id) SELECT pm.project_id, ?, pm.role_id FROM project_members pm JOIN projects p ON p.id = pm.project_id
                    WHERE pm.user_id = ? AND p.workspace_id = ? AND p.archived_at IS NULL", [$hid, $userId, $ws]);
            }
            $db->run("DELETE FROM task_assignees WHERE user_id = ? AND task_id IN (SELECT id FROM ($openTasks) o)", [$userId, $ws]);
            $db->run("UPDATE tasks SET assignee_id = ? WHERE assignee_id = ? AND completed_at IS NULL AND project_id IN (SELECT id FROM projects WHERE workspace_id = ?)", [$hid, $userId, $ws]);
            $db->run('UPDATE projects SET lead_user_id = ? WHERE lead_user_id = ? AND workspace_id = ?', [$hid, $userId, $ws]);
            // Memberships and personal things inside this workspace.
            $db->run("DELETE FROM task_watchers WHERE user_id = ? AND task_id IN (SELECT id FROM ($wsTasks) w)", [$userId, $ws]);
            $db->run('DELETE FROM project_members WHERE user_id = ? AND project_id IN (SELECT id FROM projects WHERE workspace_id = ?)', [$userId, $ws]);
            $db->run('DELETE FROM team_members WHERE user_id = ? AND team_id IN (SELECT id FROM teams WHERE workspace_id = ?)', [$userId, $ws]);
            $db->run('DELETE FROM calendar_members WHERE user_id = ? AND calendar_id IN (SELECT id FROM calendars WHERE workspace_id = ?)', [$userId, $ws]);
            $db->run('DELETE FROM event_participants WHERE user_id = ? AND event_id IN (SELECT e.id FROM events e JOIN calendars c ON c.id = e.calendar_id WHERE c.workspace_id = ?)', [$userId, $ws]);
            $db->run('DELETE FROM sprint_capacity WHERE user_id = ? AND sprint_id IN (SELECT s.id FROM sprints s JOIN projects p ON p.id = s.project_id WHERE p.workspace_id = ?)', [$userId, $ws]);
            foreach (['notifications', 'user_favorites', 'user_recent', 'saved_views'] as $t) $db->run("DELETE FROM $t WHERE user_id = ? AND workspace_id = ?", [$userId, $ws]);
            $email = (string)$db->value('SELECT email FROM users WHERE id = ?', [$userId]);
            $db->run('DELETE FROM invitations WHERE workspace_id = ? AND email = ? AND accepted_at IS NULL AND revoked_at IS NULL', [$ws, $email]);
            $db->run('DELETE FROM workspace_members WHERE workspace_id = ? AND user_id = ?', [$ws, $userId]);

            if ($closeAccount) {
                foreach (['push_subscriptions', 'personal_tasks', 'personal_lists', 'google_exports', 'google_accounts', 'notifications', 'user_favorites', 'user_recent', 'saved_views'] as $t) {
                    if (self::tableExists($t)) $db->run("DELETE FROM $t WHERE user_id = ?", [$userId]);
                }
                $db->update('users', ['name' => 'Usuario eliminado', 'handle' => 'eliminado-' . $userId,
                    'email' => 'eliminado-' . $userId . '-' . bin2hex(random_bytes(3)) . '@borealis.invalid', 'title' => null, 'is_active' => 0,
                    'password_hash' => Auth::hash(bin2hex(random_bytes(24))), 'updated_at' => now()], 'id = ?', [$userId]);
                self::clearPhoto($userId);
            }
        });

        $summary = "Eliminó a {$m['name']} ({$m['email']}) del espacio" . ($closeAccount ? ' y cerró su cuenta' : '');
        Activity::log('deleted', 'user', $userId, $summary, null, null, [], null, [
            'category' => 'deletion', 'reason' => $reason,
            'before' => ['nombre' => $m['name'], 'correo' => $m['email'], 'rol' => $m['role_name'], 'estado' => $m['status']],
            'after' => ['tareas_abiertas' => $impact['tasks'] ?? 0, 'proyectos_que_lideraba' => $impact['projects'] ?? 0,
                'pasan_a' => $heir['name'] ?? 'sin responsable', 'cuenta_cerrada' => $closeAccount, 'otros_espacios' => $others, 'copia' => $backup],
        ]);
        Policy::reset();
        return ['name' => $m['name'], 'account_closed' => $closeAccount, 'other_workspaces' => $others, 'tasks' => $impact['tasks'] ?? 0,
            'projects' => $impact['projects'] ?? 0, 'reassigned_to' => $heir['name'] ?? null, 'backup' => $backup];
    }

    private static function tableExists(string $t): bool
    {
        static $known = null;
        $known ??= array_flip(Database::get()->column('SHOW TABLES'));
        return isset($known[$t]);
    }

    private static function clearPhoto(int $userId): void
    {
        try {
            $db = Database::get();
            $old = (string)$db->value('SELECT avatar_photo FROM users WHERE id = ?', [$userId]);
            if ($old === '') return;
            $db->update('users', ['avatar_photo' => null], 'id = ?', [$userId]);
            foreach (glob(Avatars::dir() . '/' . $userId . '-' . $old . '-*.webp') ?: [] as $f) @unlink($f);
        } catch (\Throwable) {
            // the photo is cosmetic: never fail the deletion over it
        }
    }
}
