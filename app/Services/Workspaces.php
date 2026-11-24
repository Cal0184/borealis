<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Session;
use Borealis\Core\Validator;
use Borealis\Security\AccountPolicy;
use Borealis\Security\Permissions;
use Borealis\Security\Policy;

final class Workspaces
{
    public const DEFAULT_PRIORITIES = [
        ['Urgente', '#F43F5E', 40, 0],
        ['Alta', '#F59E0B', 30, 0],
        ['Media', '#0EA5E9', 20, 1],
        ['Baja', '#94A3B8', 10, 0],
    ];

    /** Create a workspace with its default roles and priorities; $ownerId becomes owner. */
    public static function create(string $name, int $ownerId): int
    {
        $db = Database::get();
        return $db->transaction(function (Database $db) use ($name, $ownerId) {
            $ws = $db->insert('workspaces', ['name' => $name, 'settings' => '{}', 'created_by' => $ownerId, 'created_at' => now(), 'updated_at' => now()]);
            foreach (['workspace' => Permissions::defaultWorkspaceRoles(), 'project' => Permissions::defaultProjectRoles()] as $scope => $roles) {
                foreach ($roles as $key => [$rname, $desc, $perms, $rank]) {
                    $rid = $db->insert('roles', ['workspace_id' => $ws, 'scope' => $scope, 'role_key' => $key, 'name' => $rname, 'description' => $desc, 'is_system' => 1, 'rank_order' => $rank, 'created_at' => now()]);
                    foreach ($perms as $p) $db->insert('role_permissions', ['role_id' => $rid, 'permission' => $p]);
                }
            }
            foreach (self::DEFAULT_PRIORITIES as [$pname, $color, $weight, $def]) {
                $db->insert('priorities', ['workspace_id' => $ws, 'name' => $pname, 'color' => $color, 'weight' => $weight, 'is_default' => $def]);
            }
            $owner = (int)$db->value("SELECT id FROM roles WHERE workspace_id = ? AND scope = 'workspace' AND role_key = 'owner'", [$ws]);
            $db->insert('workspace_members', ['workspace_id' => $ws, 'user_id' => $ownerId, 'role_id' => $owner, 'joined_at' => now()]);
            $db->update('users', ['last_workspace_id' => $ws], 'id = ?', [$ownerId]);
            Activity::log('created', 'workspace', $ws, "Creó el espacio «{$name}»", null, null, [], $ws);
            return $ws;
        });
    }

    public static function forUser(): array
    {
        return Database::get()->all("SELECT w.id, w.name, r.name AS role_name FROM workspace_members wm JOIN workspaces w ON w.id = wm.workspace_id JOIN roles r ON r.id = wm.role_id
            WHERE wm.user_id = ? AND wm.status = 'active' ORDER BY w.name", [Auth::id()]);
    }

    public static function switchTo(int $id): void
    {
        $ok = Database::get()->value("SELECT 1 FROM workspace_members WHERE workspace_id = ? AND user_id = ? AND status = 'active'", [$id, Auth::id()]);
        if (!$ok) throw HttpException::notFound();
        Session::set('wsid', $id);
        Database::get()->update('users', ['last_workspace_id' => $id], 'id = ?', [Auth::id()]);
        Policy::reset();
    }

    public static function rename(string $name): void
    {
        Policy::authorize('workspace.manage');
        $v = Validator::check(['name' => $name], ['name' => 'required,string,max:120']);
        Database::get()->update('workspaces', ['name' => $v['name'], 'updated_at' => now()], 'id = ?', [Policy::wsId()]);
        Activity::log('updated', 'workspace', Policy::wsId(), "Renombró el espacio a «{$v['name']}»");
    }

    public static function roles(string $scope): array
    {
        $roles = Database::get()->all('SELECT * FROM roles WHERE workspace_id = ? AND scope = ? ORDER BY rank_order, id', [Policy::wsId(), $scope]);
        foreach ($roles as &$r) {
            $r['perms'] = Database::get()->column('SELECT permission FROM role_permissions WHERE role_id = ?', [$r['id']]);
        }
        return $roles;
    }

    public static function role(int $id, ?string $scope = null): array
    {
        $r = Database::get()->one('SELECT * FROM roles WHERE id = ? AND workspace_id = ?' . ($scope ? ' AND scope = ?' : ''), array_merge([$id, Policy::wsId()], $scope ? [$scope] : []));
        if (!$r) throw HttpException::invalid(['role_id' => 'Rol no válido.']);
        return $r;
    }

    public static function members(bool $includeSuspended = true): array
    {
        return Database::get()->all("SELECT u.id, u.name, u.email, u.handle, u.title, u.avatar_color, u.last_login_at, u.is_active, u.is_ghost, wm.status, wm.joined_at, wm.weekly_hours, r.id AS role_id, r.role_key, r.name AS role_name
            FROM workspace_members wm JOIN users u ON u.id = wm.user_id JOIN roles r ON r.id = wm.role_id
            WHERE wm.workspace_id = ?" . ($includeSuspended ? '' : " AND wm.status = 'active' AND u.is_active = 1") . ' ORDER BY u.name', [Policy::wsId()]);
    }

    public static function member(int $userId): ?array
    {
        return Database::get()->one("SELECT u.id, u.name, u.email, u.handle, u.title, u.avatar_color, u.is_ghost, wm.status, wm.weekly_hours, r.role_key, r.name AS role_name, r.id AS role_id
            FROM workspace_members wm JOIN users u ON u.id = wm.user_id JOIN roles r ON r.id = wm.role_id WHERE wm.workspace_id = ? AND u.id = ?", [Policy::wsId(), $userId]);
    }

    /** Hours per week a person has available in this workspace (null = not defined). */
    /** «Horas semanales disponibles»: empty = not set (no limit), otherwise 0–168. */
    public static function weeklyInput(mixed $hours): ?float
    {
        $h = ($hours === null || $hours === '') ? null : (is_numeric($hours) ? round((float)$hours, 2) : -1);
        if ($h !== null && ($h < 0 || $h > 168)) throw HttpException::invalid(['weekly_hours' => 'Indica entre 0 y 168 horas por semana.']);
        return $h;
    }

    public static function setWeeklyHours(int $userId, mixed $hours): void
    {
        if ($userId !== Auth::id()) Policy::authorize('members.manage');
        $m = self::member($userId) ?? throw HttpException::notFound();
        $h = self::weeklyInput($hours);
        $db = Database::get();
        $db->transaction(function () use ($db, $m, $h, $userId) {
            // 1.7: the weekly hours are the usual availability; a sprint's capacity is what it had.
            // Sprints that already ended keep the hours computed with the old value.
            $old = $m['weekly_hours'] !== null ? (float)$m['weekly_hours'] : null;
            if ($old !== null && $old !== $h) Capacity::keepHistory($userId, $old);
            $db->run('UPDATE workspace_members SET weekly_hours = ? WHERE workspace_id = ? AND user_id = ?', [$h, Policy::wsId(), $userId]);
        });
        $old = $m['weekly_hours'] !== null ? (float)$m['weekly_hours'] : null;
        if ($old !== $h) Activity::log('weekly_hours', 'member', $userId, "Cambió las horas semanales de {$m['name']}", null, null, [], null, ['before' => ['horas semanales' => $old], 'after' => ['horas semanales' => $h], 'category' => 'team']);
    }

    public static function isActiveMember(int $userId): bool
    {
        return (bool)Database::get()->value("SELECT 1 FROM workspace_members wm JOIN users u ON u.id = wm.user_id WHERE wm.workspace_id = ? AND wm.user_id = ? AND wm.status = 'active' AND u.is_active = 1", [Policy::wsId(), $userId]);
    }

    public static function changeMemberRole(int $userId, int $roleId): void
    {
        $role = self::role($roleId, 'workspace');
        // 1.4.1: members.manage + roles.manage, never one's own role, never above the actor.
        AccountPolicy::authorizeRoleChange($userId, $role);
        $m = self::member($userId) ?? throw HttpException::notFound();
        if ($m['role_key'] === 'owner' && $role['role_key'] !== 'owner') {
            if (Policy::workspace()['role_key'] !== 'owner') throw HttpException::forbidden('Solo un propietario puede cambiar el rol de otro propietario.');
            self::assertAnotherOwner($userId);
        }
        Database::get()->update('workspace_members', ['role_id' => $roleId], 'workspace_id = ? AND user_id = ?', [Policy::wsId(), $userId]);
        Activity::log('role_changed', 'user', $userId, "Cambió el rol de {$m['name']} a {$role['name']}", null, null, [], null, ['before' => ['rol' => $m['role_name'] ?? null], 'after' => ['rol' => $role['name']], 'category' => 'permission']);
    }

    public static function setMemberStatus(int $userId, string $status): void
    {
        Policy::authorize('members.manage');
        $m = self::member($userId) ?? throw HttpException::notFound();
        if ($userId === Auth::id()) throw HttpException::conflict('No puedes suspender tu propia cuenta.');
        AccountPolicy::authorizeOver($m, 'el acceso');
        if ($m['role_key'] === 'owner') self::assertAnotherOwner($userId);
        Database::get()->update('workspace_members', ['status' => $status === 'suspended' ? 'suspended' : 'active'], 'workspace_id = ? AND user_id = ?', [Policy::wsId(), $userId]);
        Activity::log($status === 'suspended' ? 'suspended' : 'reactivated', 'user', $userId, ($status === 'suspended' ? 'Suspendió a ' : 'Reactivó a ') . $m['name']);
    }

    private static function assertAnotherOwner(int $exceptUserId): void
    {
        $others = (int)Database::get()->value("SELECT COUNT(*) FROM workspace_members wm JOIN roles r ON r.id = wm.role_id WHERE wm.workspace_id = ? AND r.role_key = 'owner' AND wm.status = 'active' AND wm.user_id <> ?", [Policy::wsId(), $exceptUserId]);
        if ($others === 0) throw HttpException::conflict('El espacio debe conservar al menos un propietario activo.');
    }

    /**
     * Admin creates an account directly (useful without e-mail).
     * 1.4.1: an e-mail that already has an account is never attached directly: an invitation is
     * created instead, and the person joins only after accepting it with their own password.
     * Returns ['id' => new user id] or ['invitation' => invite()'s result].
     */
    public static function createUser(array $input): array
    {
        Policy::authorize('members.manage');
        $v = Validator::check($input, ['name' => 'required,string,max:120', 'email' => 'required,email,max:190', 'password' => 'required,string,min:8,max:200', 'role_id' => 'required,int', 'team_id' => 'nullable,int']);
        $weekly = self::weeklyInput($input['weekly_hours'] ?? null);
        $role = self::role($v['role_id'], 'workspace');
        AccountPolicy::authorizeAssignableRole($role);
        $db = Database::get();
        $existing = $db->one('SELECT id FROM users WHERE email = ?', [mb_strtolower($v['email'])]);
        if ($existing) {
            if ($db->value('SELECT 1 FROM workspace_members WHERE workspace_id = ? AND user_id = ?', [Policy::wsId(), (int)$existing['id']])) {
                throw HttpException::invalid(['email' => 'Esta persona ya pertenece al espacio.']);
            }
            return ['invitation' => self::invite(['email' => $v['email'], 'role_id' => $v['role_id'], 'team_id' => $v['team_id'] ?? null, 'weekly_hours' => $weekly])];
        }
        return ['id' => $db->transaction(function () use ($db, $v, $role, $weekly) {
            $uid = Users::create($v['name'], $v['email'], $v['password']);
            $db->insert('workspace_members', ['workspace_id' => Policy::wsId(), 'user_id' => $uid, 'role_id' => $role['id'], 'weekly_hours' => $weekly, 'joined_at' => now()]);
            if (!empty($v['team_id'])) Teams::addMember((int)$v['team_id'], $uid);
            Activity::log('created', 'user', $uid, "Incorporó a {$v['name']} al espacio");
            return $uid;
        })];
    }

    /**
     * Administration edits another person's account: name, e-mail and job title.
     * Accounts are shared between workspaces, so an e-mail change on someone who also belongs
     * to another workspace needs the installation's administration.
     */
    public static function updateAccount(int $userId, array $input): void
    {
        // 1.4.1: the same rules as every other change to a global account (AccountPolicy).
        AccountPolicy::authorizeAccountChange($userId, 'la cuenta');
        $m = self::member($userId) ?? throw HttpException::notFound();
        $db = Database::get();
        $actorIsInstanceAdmin = AccountPolicy::isInstanceAdmin();
        $v = Validator::check($input, ['name' => 'sometimes,required,string,max:120', 'email' => 'sometimes,required,email,max:190', 'title' => 'sometimes,nullable,string,max:120']);
        $data = [];
        if (array_key_exists('name', $v) && $v['name'] !== $m['name']) $data['name'] = $v['name'];
        if (array_key_exists('title', $v) && (string)$v['title'] !== (string)$m['title']) $data['title'] = $v['title'];
        if (array_key_exists('email', $v)) {
            $email = mb_strtolower($v['email']);
            if ($email !== mb_strtolower((string)$m['email'])) {
                if ($m['is_ghost']) throw HttpException::invalid(['email' => 'Las cuentas fantasma no usan correo.']);
                if ($db->value('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $userId])) throw HttpException::invalid(['email' => 'Ese correo ya está en uso por otra cuenta.']);
                $elsewhere = (int)$db->value('SELECT COUNT(*) FROM workspace_members WHERE user_id = ? AND workspace_id <> ?', [$userId, Policy::wsId()]);
                if ($elsewhere && !$actorIsInstanceAdmin) throw HttpException::forbidden('Esta persona también usa otros espacios: solo la administración de la instalación puede cambiar su correo.');
                $data['email'] = $email;
            }
        }
        if (!$data) return;
        $db->update('users', $data + ['updated_at' => now()], 'id = ?', [$userId]);
        $labels = ['name' => 'el nombre', 'email' => 'el correo', 'title' => 'el cargo'];
        Activity::log('updated', 'user', $userId, 'Editó ' . implode(', ', array_map(fn($k) => $labels[$k], array_keys($data))) . ' de la cuenta de ' . $m['name'], null, null, [], null,
            ['before' => array_intersect_key($m, $data), 'after' => $data, 'category' => 'team']);
    }

    public static function resetPassword(int $userId, string $password): void
    {
        // 1.4.1: never on accounts shared with other workspaces, the installation's administration
        // or anyone above the actor, unless the installation's administration does it.
        if ($userId === Auth::id()) throw HttpException::forbidden('Cambia tu propia contraseña desde tu perfil.');
        AccountPolicy::authorizeAccountChange($userId, 'la contraseña');
        $m = self::member($userId) ?? throw HttpException::notFound();
        Validator::check(['password' => $password], ['password' => 'required,string,min:8,max:200']);
        Database::get()->update('users', ['password_hash' => Auth::hash($password), 'updated_at' => now()], 'id = ?', [$userId]);
        Activity::log('password_reset', 'user', $userId, "Restableció la contraseña de {$m['name']}");
    }

    // ---- Invitations ------------------------------------------------------

    public static function invite(array $input): array
    {
        Policy::authorize('members.manage');
        $v = Validator::check($input, ['email' => 'required,email,max:190', 'role_id' => 'required,int', 'team_id' => 'nullable,int']);
        $weekly = self::weeklyInput($input['weekly_hours'] ?? null);
        $role = self::role($v['role_id'], 'workspace');
        if ($role['role_key'] === 'owner') throw HttpException::invalid(['role_id' => 'No se puede invitar directamente como propietario.']);
        AccountPolicy::authorizeAssignableRole($role);
        $email = mb_strtolower($v['email']);
        $db = Database::get();
        if ($db->value('SELECT 1 FROM workspace_members wm JOIN users u ON u.id = wm.user_id WHERE wm.workspace_id = ? AND u.email = ?', [Policy::wsId(), $email])) {
            throw HttpException::invalid(['email' => 'Esta persona ya pertenece al espacio.']);
        }
        if ($v['team_id']) Teams::find((int)$v['team_id']);
        $token = bin2hex(random_bytes(24));
        $id = $db->insert('invitations', [
            'workspace_id' => Policy::wsId(), 'email' => $email, 'role_id' => $role['id'], 'team_id' => $v['team_id'], 'weekly_hours' => $weekly,
            'token_hash' => hash('sha256', $token), 'invited_by' => Auth::id(), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 14 * 86400), 'created_at' => now(),
        ]);
        Activity::log('invited', 'invitation', $id, "Invitó a $email");
        return ['id' => $id, 'link' => absolute_url('/invite/' . $token)];
    }

    /** A pending invitation's link is shown only once (only its hash is stored): issue a new one. */
    public static function renewInvitation(int $id): array
    {
        Policy::authorize('members.manage');
        $db = Database::get();
        $inv = $db->one('SELECT * FROM invitations WHERE id = ? AND workspace_id = ? AND accepted_at IS NULL AND revoked_at IS NULL', [$id, Policy::wsId()]);
        if (!$inv) throw HttpException::notFound('La invitación ya no está pendiente.');
        $token = bin2hex(random_bytes(24));
        $db->update('invitations', ['token_hash' => hash('sha256', $token), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 14 * 86400)], 'id = ?', [$id]);
        Activity::log('invited', 'invitation', $id, "Generó un enlace nuevo para {$inv['email']}");
        return ['id' => $id, 'email' => $inv['email'], 'link' => absolute_url('/invite/' . $token)];
    }

    public static function pendingInvitations(): array
    {
        return Database::get()->all('SELECT i.*, r.name AS role_name, t.name AS team_name FROM invitations i JOIN roles r ON r.id = i.role_id LEFT JOIN teams t ON t.id = i.team_id
            WHERE i.workspace_id = ? AND i.accepted_at IS NULL AND i.revoked_at IS NULL AND i.expires_at > ? ORDER BY i.id DESC', [Policy::wsId(), now()]);
    }

    public static function revokeInvitation(int $id): void
    {
        Policy::authorize('members.manage');
        $n = Database::get()->update('invitations', ['revoked_at' => now()], 'id = ? AND workspace_id = ? AND accepted_at IS NULL', [$id, Policy::wsId()]);
        if (!$n) throw HttpException::notFound();
    }

    public static function findInvitation(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
        return Database::get()->one('SELECT i.*, w.name AS workspace_name FROM invitations i JOIN workspaces w ON w.id = i.workspace_id
            WHERE i.token_hash = ? AND i.accepted_at IS NULL AND i.revoked_at IS NULL AND i.expires_at > ?', [hash('sha256', $token), now()]);
    }

    /** Accept an invitation for a given (existing or newly created) user id. */
    public static function acceptInvitation(array $inv, int $userId): void
    {
        $db = Database::get();
        $db->transaction(function () use ($db, $inv, $userId) {
            $db->run('INSERT IGNORE INTO workspace_members (workspace_id, user_id, role_id, weekly_hours, joined_at) VALUES (?,?,?,?,?)', [$inv['workspace_id'], $userId, $inv['role_id'], $inv['weekly_hours'] ?? null, now()]);
            if ($inv['team_id']) {
                $db->run('INSERT IGNORE INTO team_members (team_id, user_id, team_role, joined_at) VALUES (?,?,?,?)', [$inv['team_id'], $userId, 'member', now()]);
            }
            $db->update('invitations', ['accepted_at' => now()], 'id = ?', [$inv['id']]);
            $db->update('users', ['last_workspace_id' => $inv['workspace_id']], 'id = ?', [$userId]);
            Activity::log('joined', 'user', $userId, 'Se unió al espacio por invitación', null, null, [], (int)$inv['workspace_id']);
        });
    }

    // ---- Priorities ------------------------------------------------------

    public static function priorities(): array
    {
        return Database::get()->all('SELECT * FROM priorities WHERE workspace_id = ? ORDER BY weight DESC, id', [Policy::wsId()]);
    }

    public static function savePriority(?int $id, array $input): int
    {
        Policy::authorize('workspace.manage');
        $v = Validator::check($input, ['name' => 'required,string,max:40', 'color' => 'required,color', 'weight' => 'required,int']);
        $db = Database::get();
        if ($id) {
            if (!$db->update('priorities', $v, 'id = ? AND workspace_id = ?', [$id, Policy::wsId()]) && !$db->value('SELECT 1 FROM priorities WHERE id = ? AND workspace_id = ?', [$id, Policy::wsId()])) {
                throw HttpException::notFound();
            }
            return $id;
        }
        return $db->insert('priorities', $v + ['workspace_id' => Policy::wsId(), 'is_default' => 0]);
    }

    public static function deletePriority(int $id): void
    {
        Policy::authorize('workspace.manage');
        $n = Database::get()->run('DELETE FROM priorities WHERE id = ? AND workspace_id = ?', [$id, Policy::wsId()])->rowCount();
        if (!$n) throw HttpException::notFound();
    }

    public static function defaultPriorityId(): ?int
    {
        $id = Database::get()->value('SELECT id FROM priorities WHERE workspace_id = ? AND is_default = 1 LIMIT 1', [Policy::wsId()]);
        return $id ? (int)$id : null;
    }

    // ---- Roles -----------------------------------------------------------

    public static function saveRole(?int $id, array $input): int
    {
        Policy::authorize('roles.manage');
        $v = Validator::check($input, ['name' => 'required,string,max:80', 'description' => 'nullable,string,max:255', 'scope' => 'sometimes,in:workspace|project']);
        $perms = array_values(array_filter((array)($input['perms'] ?? []), 'is_string'));
        $db = Database::get();
        return $db->transaction(function () use ($db, $id, $v, $perms) {
            if ($id) {
                $role = self::role($id);
                if ($role['role_key'] === 'owner') throw HttpException::forbidden('Los permisos del propietario no se pueden reducir.');
                $scope = $role['scope'];
                $db->update('roles', ['name' => $v['name'], 'description' => $v['description']], 'id = ?', [$id]);
            } else {
                $scope = $v['scope'] ?? 'project';
                $id = $db->insert('roles', ['workspace_id' => Policy::wsId(), 'scope' => $scope, 'role_key' => 'custom_' . bin2hex(random_bytes(4)), 'name' => $v['name'], 'description' => $v['description'], 'is_system' => 0, 'rank_order' => 50, 'created_at' => now()]);
            }
            $catalog = $scope === 'workspace' ? Permissions::WORKSPACE : Permissions::PROJECT;
            $perms = array_values(array_intersect($perms, array_keys($catalog)));
            AccountPolicy::authorizeRoleDefinition(isset($role) ? $role : null, $scope, $perms);
            if ($scope === 'project' && !in_array('project.view', $perms, true)) $perms[] = 'project.view';
            $db->run('DELETE FROM role_permissions WHERE role_id = ?', [$id]);
            foreach ($perms as $p) $db->insert('role_permissions', ['role_id' => $id, 'permission' => $p]);
            Activity::log('role_saved', 'role', $id, "Actualizó el rol «{$v['name']}»");
            return $id;
        });
    }

    public static function deleteRole(int $id): void
    {
        Policy::authorize('roles.manage');
        $role = self::role($id);
        if ($role['is_system']) throw HttpException::conflict('Los roles del sistema no se pueden eliminar.');
        $db = Database::get();
        $inUse = (int)$db->value('SELECT COUNT(*) FROM workspace_members WHERE role_id = ?', [$id]) + (int)$db->value('SELECT COUNT(*) FROM project_members WHERE role_id = ?', [$id]) + (int)$db->value('SELECT COUNT(*) FROM projects WHERE team_role_id = ?', [$id]);
        if ($inUse) throw HttpException::conflict('Este rol está asignado. Cambia esas asignaciones antes de eliminarlo.');
        $db->run('DELETE FROM roles WHERE id = ?', [$id]);
    }
}
