<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Validator;
use Borealis\Security\Policy;

final class Teams
{
    public static function list(bool $archived = false): array
    {
        $db = Database::get();
        $teams = $db->all('SELECT t.*, (SELECT COUNT(*) FROM team_members tm WHERE tm.team_id = t.id) AS member_count
            FROM teams t WHERE t.workspace_id = ? AND t.archived_at IS ' . ($archived ? 'NOT ' : '') . 'NULL ORDER BY t.name', [Policy::wsId()]);
        [$vis, $vp] = Policy::visibleProjectsSql('p');
        foreach ($teams as &$t) {
            $t['members'] = $db->all('SELECT u.id, u.name, u.avatar_color, tm.team_role FROM team_members tm JOIN users u ON u.id = tm.user_id WHERE tm.team_id = ? ORDER BY tm.team_role, u.name LIMIT 6', [$t['id']]);
            $t['project_count'] = (int)$db->value("SELECT COUNT(*) FROM projects p WHERE p.team_id = ? AND p.archived_at IS NULL AND $vis", array_merge([$t['id']], $vp));
            $t['open_tasks'] = (int)$db->value("SELECT COUNT(*) FROM tasks k JOIN projects p ON p.id = k.project_id JOIN statuses s ON s.id = k.status_id
                WHERE p.team_id = ? AND p.archived_at IS NULL AND k.deleted_at IS NULL AND s.category <> 'done' AND $vis", array_merge([$t['id']], $vp));
        }
        return $teams;
    }

    public static function find(int $id): array
    {
        $t = Database::get()->one('SELECT * FROM teams WHERE id = ? AND workspace_id = ?', [$id, Policy::wsId()]);
        if (!$t) throw HttpException::notFound('El equipo no existe.');
        return $t;
    }

    public static function isCoordinator(int $teamId, ?int $userId = null): bool
    {
        return (bool)Database::get()->value("SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ? AND team_role = 'coordinator'", [$teamId, $userId ?? Auth::id()]);
    }

    public static function canManage(array $team): bool
    {
        return Policy::can('teams.manage') || self::isCoordinator((int)$team['id']);
    }

    private static function authorizeManage(array $team): void
    {
        if (!self::canManage($team)) throw HttpException::forbidden('Solo coordinadores o administradores pueden modificar este equipo.');
    }

    public static function create(array $input): int
    {
        Policy::authorize('teams.create');
        $v = Validator::check($input, ['name' => 'required,string,max:120', 'description' => 'nullable,string,max:2000', 'color' => 'nullable,color']);
        $db = Database::get();
        return $db->transaction(function () use ($db, $v) {
            $id = $db->insert('teams', ['workspace_id' => Policy::wsId(), 'name' => $v['name'], 'description' => $v['description'], 'color' => $v['color'] ?? '#10B981', 'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now()]);
            $db->insert('team_members', ['team_id' => $id, 'user_id' => Auth::id(), 'team_role' => 'coordinator', 'joined_at' => now()]);
            Activity::log('created', 'team', $id, "Creó el equipo «{$v['name']}»");
            return $id;
        });
    }

    public static function update(int $id, array $input): void
    {
        $team = self::find($id);
        self::authorizeManage($team);
        $v = Validator::check($input, ['name' => 'sometimes,required,string,max:120', 'description' => 'sometimes,nullable,string,max:2000', 'color' => 'sometimes,color', 'is_flexible' => 'sometimes,bool']);
        if (array_key_exists('is_flexible', $v)) $v['is_flexible'] = $v['is_flexible'] ? 1 : 0;
        if (!$v) return;
        Database::get()->update('teams', $v + ['updated_at' => now()], 'id = ?', [$id]);
        Activity::log('updated', 'team', $id, 'Actualizó el equipo «' . ($v['name'] ?? $team['name']) . '»');
    }

    public static function setArchived(int $id, bool $archived): void
    {
        $team = self::find($id);
        self::authorizeManage($team);
        Database::get()->update('teams', ['archived_at' => $archived ? now() : null, 'updated_at' => now()], 'id = ?', [$id]);
        Activity::log($archived ? 'archived' : 'restored', 'team', $id, ($archived ? 'Archivó' : 'Restauró') . " el equipo «{$team['name']}»");
    }

    /** Permanently delete: projects keep existing and simply lose their team link. */
    public static function delete(int $id): void
    {
        $team = self::find($id);
        if (!Policy::can('teams.manage')) throw HttpException::forbidden('Solo administradores pueden eliminar equipos.');
        Database::get()->run('DELETE FROM teams WHERE id = ?', [$id]);
        Activity::log('deleted', 'team', $id, "Eliminó el equipo «{$team['name']}»");
    }

    public static function members(int $teamId): array
    {
        return Database::get()->all("SELECT u.id, u.name, u.email, u.title, u.avatar_color, u.is_ghost, tm.team_role, tm.responsibility, tm.joined_at,
                (SELECT wm.weekly_hours FROM workspace_members wm JOIN teams tt ON tt.id = tm.team_id WHERE wm.user_id = u.id AND wm.workspace_id = tt.workspace_id) AS weekly_hours,
                (SELECT COUNT(*) FROM tasks k JOIN projects p ON p.id = k.project_id JOIN statuses s ON s.id = k.status_id
                  WHERE k.assignee_id = u.id AND p.team_id = tm.team_id AND k.deleted_at IS NULL AND p.archived_at IS NULL AND s.category <> 'done') AS open_tasks
            FROM team_members tm JOIN users u ON u.id = tm.user_id WHERE tm.team_id = ? ORDER BY tm.team_role = 'coordinator' DESC, u.name", [$teamId]);
    }

    public static function addMember(int $teamId, int $userId, string $role = 'member', ?string $responsibility = null): void
    {
        $team = self::find($teamId);
        self::authorizeManage($team);
        if (!Workspaces::isActiveMember($userId)) throw HttpException::invalid(['user_id' => 'La persona no pertenece al espacio.']);
        $db = Database::get();
        if ($db->value('SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ?', [$teamId, $userId])) {
            throw HttpException::invalid(['user_id' => 'Ya forma parte del equipo.']);
        }
        $db->insert('team_members', ['team_id' => $teamId, 'user_id' => $userId, 'team_role' => $role === 'coordinator' ? 'coordinator' : 'member', 'responsibility' => $responsibility ? mb_substr($responsibility, 0, 160) : null, 'joined_at' => now()]);
        $u = Users::find($userId);
        Activity::log('member_added', 'team', $teamId, "Añadió a {$u['name']} al equipo «{$team['name']}»");
        Notifications::send($userId, 'team', "Te añadieron al equipo {$team['name']}", url('/teams/' . $teamId));
    }

    public static function updateMember(int $teamId, int $userId, array $input): void
    {
        $team = self::find($teamId);
        self::authorizeManage($team);
        $v = Validator::check($input, ['team_role' => 'sometimes,in:coordinator|member', 'responsibility' => 'sometimes,nullable,string,max:160']);
        $db = Database::get();
        if (!$db->value('SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ?', [$teamId, $userId])) throw HttpException::notFound();
        if (($v['team_role'] ?? null) === 'member') self::assertCoordinatorRemains($teamId, $userId);
        if ($v) $db->update('team_members', $v, 'team_id = ? AND user_id = ?', [$teamId, $userId]);
        Activity::log('member_updated', 'team', $teamId, 'Actualizó a ' . (Users::find($userId)['name'] ?? '') . " en «{$team['name']}»");
    }

    public static function removeMember(int $teamId, int $userId): void
    {
        $team = self::find($teamId);
        if ($userId !== Auth::id()) self::authorizeManage($team);
        self::assertCoordinatorRemains($teamId, $userId);
        $n = Database::get()->run('DELETE FROM team_members WHERE team_id = ? AND user_id = ?', [$teamId, $userId])->rowCount();
        if (!$n) throw HttpException::notFound();
        Activity::log('member_removed', 'team', $teamId, 'Retiró a ' . (Users::find($userId)['name'] ?? '') . " del equipo «{$team['name']}»");
    }

    private static function assertCoordinatorRemains(int $teamId, int $userId): void
    {
        $db = Database::get();
        $isCoord = $db->value("SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ? AND team_role = 'coordinator'", [$teamId, $userId]);
        $others = (int)$db->value("SELECT COUNT(*) FROM team_members WHERE team_id = ? AND user_id <> ? AND team_role = 'coordinator'", [$teamId, $userId]);
        if ($isCoord && $others === 0 && !Policy::can('teams.manage')) {
            throw HttpException::conflict('El equipo necesita al menos un coordinador. Designa otro antes.');
        }
    }

    public static function projects(int $teamId): array
    {
        [$vis, $vp] = Policy::visibleProjectsSql('p');
        return Projects::withProgress(Database::get()->all("SELECT p.* FROM projects p WHERE (p.team_id = ? OR EXISTS (SELECT 1 FROM project_teams pt WHERE pt.project_id = p.id AND pt.team_id = ?)) AND $vis ORDER BY p.archived_at IS NOT NULL, p.name", array_merge([$teamId, $teamId], $vp)));
    }

    /** Teams the given user belongs to. */
    public static function forUser(int $userId): array
    {
        return Database::get()->all('SELECT t.id, t.name, t.color, tm.team_role, tm.responsibility FROM team_members tm JOIN teams t ON t.id = tm.team_id
            WHERE tm.user_id = ? AND t.workspace_id = ? AND t.archived_at IS NULL ORDER BY t.name', [$userId, Policy::wsId()]);
    }
}
