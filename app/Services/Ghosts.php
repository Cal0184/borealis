<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Session;
use Borealis\Core\Validator;
use Borealis\Security\Policy;

/**
 * Practice accounts ("fantasmas") for trying Borealis out: they cannot sign in with a password,
 * only the installation administrator can "enter as" them, and they can all be removed at once.
 * Entering as a real person is deliberately not possible.
 */
final class Ghosts
{
    public static function guard(): void
    {
        if (!(int)(Auth::user()['is_instance_admin'] ?? 0) || self::impersonating()) {
            throw HttpException::forbidden('Solo la administración de la instalación puede usar cuentas fantasma.');
        }
    }

    public static function create(array $input): int
    {
        self::guard();
        $v = Validator::check($input, ['name' => 'required,string,max:120', 'role_id' => 'required,int', 'team_id' => 'nullable,int', 'weekly_hours' => 'nullable,number']);
        $role = Workspaces::role((int)$v['role_id'], 'workspace');
        if ($role['role_key'] === 'owner') throw HttpException::invalid(['role_id' => 'Una cuenta fantasma no puede ser propietaria del espacio.']);
        $db = Database::get();
        return $db->transaction(function () use ($db, $v, $role) {
            $email = 'fantasma-' . bin2hex(random_bytes(5)) . '@borealis.invalid';
            $uid = $db->insert('users', [
                'email' => $email, 'name' => $v['name'], 'handle' => Users::uniqueHandle($v['name'], $email),
                // Random hash nobody knows; login is also refused for ghosts.
                'password_hash' => Auth::hash(bin2hex(random_bytes(24))),
                'avatar_color' => ['#8B5CF6', '#64748B', '#A78BFA', '#94A3B8'][random_int(0, 3)],
                'is_ghost' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $db->insert('workspace_members', ['workspace_id' => Policy::wsId(), 'user_id' => $uid, 'role_id' => $role['id'], 'joined_at' => now(),
                'weekly_hours' => ($v['weekly_hours'] ?? '') === '' || $v['weekly_hours'] === null ? null : max(0, min(168, (float)$v['weekly_hours']))]);
            if (!empty($v['team_id'])) Teams::addMember((int)$v['team_id'], $uid);
            Activity::log('created', 'user', $uid, "Creó la cuenta fantasma {$v['name']}");
            return $uid;
        });
    }

    /** Ghost accounts that belong to the current workspace. */
    public static function list(): array
    {
        return Database::get()->all("SELECT u.id, u.name, u.avatar_color, r.name AS role_name FROM users u
            JOIN workspace_members wm ON wm.user_id = u.id AND wm.workspace_id = ? JOIN roles r ON r.id = wm.role_id
            WHERE u.is_ghost = 1 ORDER BY u.name", [Policy::wsId()]);
    }

    public static function enter(int $ghostId): array
    {
        self::guard();
        $g = Database::get()->one('SELECT u.* FROM users u JOIN workspace_members wm ON wm.user_id = u.id AND wm.workspace_id = ? WHERE u.id = ? AND u.is_ghost = 1 AND u.is_active = 1', [Policy::wsId(), $ghostId]);
        if (!$g) throw HttpException::notFound('Esa cuenta fantasma no existe en este espacio.');
        $admin = Auth::user();
        $ws = Policy::wsId();
        Activity::log('impersonated', 'user', $ghostId, "Entró como la cuenta fantasma {$g['name']}");
        Auth::login($g);
        Session::set('imp_uid', (int)$admin['id']);
        Session::set('imp_pwv', Auth::passwordVersion($admin));
        Session::set('wsid', $ws);
        return $g;
    }

    public static function impersonating(): bool
    {
        return is_int(Session::get('imp_uid'));
    }

    public static function leave(): void
    {
        $adminId = Session::get('imp_uid');
        if (!is_int($adminId)) throw HttpException::invalid([], 'No estás usando una cuenta fantasma.');
        $pwv = (string)Session::get('imp_pwv');
        $ws = Session::get('wsid');
        $admin = Database::get()->one('SELECT * FROM users WHERE id = ? AND is_active = 1', [$adminId]);
        if (!$admin || !hash_equals($pwv, Auth::passwordVersion($admin))) {
            Auth::logout();
            throw HttpException::unauthorized();
        }
        Auth::login($admin);
        Session::forget('imp_uid');
        Session::forget('imp_pwv');
        if ($ws !== null) Session::set('wsid', $ws);
    }

    /**
     * Removes every ghost of this workspace after taking a full backup. Their tasks, comments and
     * history stay, credited to "Usuario eliminado"; their hours are hidden (soft-deleted) unless kept.
     */
    public static function deleteAll(bool $keepHours): array
    {
        self::guard();
        $ids = array_map('intval', array_column(self::list(), 'id'));
        if (!$ids) return ['deleted' => 0, 'backup' => null];
        $backup = basename(Backup::create(null, 'antes-de-borrar-fantasmas'));
        $db = Database::get();
        $db->transaction(function () use ($db, $ids, $keepHours) {
            $in = Database::in($ids);
            foreach (['team_members', 'project_members', 'task_assignees', 'notifications'] as $t) $db->run("DELETE FROM $t WHERE user_id IN ($in)", $ids);
            $db->run("UPDATE tasks SET assignee_id = NULL WHERE assignee_id IN ($in)", $ids);
            if (!$keepHours) $db->run("UPDATE time_entries SET deleted_at = ? WHERE user_id IN ($in) AND deleted_at IS NULL", [now(), ...$ids]);
            // Ghosts only ever belong to the workspace they were created in.
            $db->run("DELETE FROM workspace_members WHERE user_id IN ($in)", $ids);
            foreach ($ids as $id) {
                $db->update('users', ['name' => 'Usuario eliminado', 'handle' => 'eliminado-' . $id, 'email' => 'eliminado-' . $id . '-' . bin2hex(random_bytes(3)) . '@borealis.invalid',
                    'title' => null, 'is_active' => 0, 'is_ghost' => 2, 'password_hash' => Auth::hash(bin2hex(random_bytes(24))), 'updated_at' => now()], 'id = ?', [$id]);
            }
        });
        Activity::log('deleted', 'user', null, 'Borró ' . count($ids) . (count($ids) === 1 ? ' cuenta fantasma' : ' cuentas fantasma'));
        return ['deleted' => count($ids), 'backup' => $backup];
    }
}
