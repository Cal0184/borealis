<?php
declare(strict_types=1);

namespace Borealis\Security;

use Borealis\Core\Auth;
use Borealis\Core\Config;
use Borealis\Core\Database;
use Borealis\Core\HttpException;

/**
 * One place that decides who may act on accounts, memberships and role assignments (1.4.1).
 *
 * Main rule: permissions inside a workspace never give control over a person's global identity.
 * An account is shared by every workspace it belongs to, so its password, name, e-mail and title
 * can be changed by a workspace's administration only while the account belongs to that workspace
 * alone. Anything wider needs the installation's administration (users.is_instance_admin).
 *
 * Role rule: nobody hands out, or takes away, more power than they hold themselves.
 */
final class AccountPolicy
{
    public static function isInstanceAdmin(): bool
    {
        return (bool)(int)(Auth::user()['is_instance_admin'] ?? 0);
    }

    // ---- Workspaces ----------------------------------------------------------

    /** Off by default: only the installation's administration creates workspaces (config app.allow_workspace_creation). */
    public static function canCreateWorkspace(): bool
    {
        return self::isInstanceAdmin() || Config::get('app.allow_workspace_creation', false) === true;
    }

    public static function authorizeCreateWorkspace(): void
    {
        if (!self::canCreateWorkspace()) {
            throw HttpException::forbidden('Solo la administración de la instalación puede crear espacios de trabajo.');
        }
    }

    // ---- Accounts ------------------------------------------------------------

    /** Number of workspaces other than the current one where the account has a membership (any status). */
    public static function otherWorkspaces(int $userId): int
    {
        return (int)Database::get()->value('SELECT COUNT(*) FROM workspace_members WHERE user_id = ? AND workspace_id <> ?', [$userId, Policy::wsId()]);
    }

    /**
     * Authorizes a change to someone's global account (password, name, e-mail, title) from this workspace.
     * Returns the membership row. $what is used in the error messages ("la contraseña", "la cuenta").
     */
    public static function authorizeAccountChange(int $userId, string $what = 'la cuenta'): array
    {
        Policy::authorize('members.manage');
        $m = self::member($userId);
        if ($userId === Auth::id()) return $m; // one's own account (the profile asks for the current password)
        if (self::isInstanceAdmin()) return $m; // global authority
        $target = Database::get()->one('SELECT is_instance_admin FROM users WHERE id = ?', [$userId]);
        if ((int)($target['is_instance_admin'] ?? 0)) {
            throw HttpException::forbidden("Solo la administración de la instalación puede cambiar $what de esta persona.");
        }
        if (self::otherWorkspaces($userId) > 0) {
            throw HttpException::forbidden("Esta persona también usa otros espacios de trabajo: solo la administración de la instalación puede cambiar $what.");
        }
        if ($m['role_key'] === 'owner' && Policy::workspace()['role_key'] !== 'owner') {
            throw HttpException::forbidden("Solo un propietario puede cambiar $what de otro propietario.");
        }
        if (!self::holdsAll(self::rolePerms((int)$m['role_id']))) {
            throw HttpException::forbidden("No puedes cambiar $what de alguien con más permisos que tú.");
        }
        return $m;
    }

    // ---- Memberships and roles ----------------------------------------------

    /** May the actor hand out this workspace role (on invitations, new accounts and role changes)? */
    public static function authorizeAssignableRole(array $role): void
    {
        if ($role['role_key'] === 'owner' && Policy::workspace()['role_key'] !== 'owner') {
            throw HttpException::forbidden('Solo un propietario puede asignar el rol de propietario.');
        }
        if (!self::isInstanceAdmin() && !self::holdsAll(self::rolePerms((int)$role['id']))) {
            throw HttpException::forbidden('No puedes asignar un rol con permisos que tú no tienes.');
        }
    }

    /**
     * Changing an existing member's role: needs members.manage and roles.manage, never on oneself,
     * never on someone holding more than the actor, never to a role above the actor.
     */
    public static function authorizeRoleChange(int $userId, array $newRole): array
    {
        Policy::authorize('members.manage');
        if (!Policy::can('roles.manage')) throw HttpException::forbidden('Cambiar el rol de una persona requiere el permiso de administrar roles.');
        if ($userId === Auth::id()) throw HttpException::forbidden('No puedes cambiar tu propio rol.');
        $m = self::member($userId);
        self::authorizeAssignableRole($newRole);
        self::authorizeOver($m, 'el rol');
        return $m;
    }

    /** Membership-level actions on someone (suspend, reactivate): never on someone above the actor. */
    public static function authorizeOver(array $m, string $what): void
    {
        if (self::isInstanceAdmin()) return;
        if ($m['role_key'] === 'owner' && Policy::workspace()['role_key'] !== 'owner') {
            throw HttpException::forbidden("Solo un propietario puede cambiar $what de otro propietario.");
        }
        if (!self::holdsAll(self::rolePerms((int)$m['role_id']))) {
            throw HttpException::forbidden("No puedes cambiar $what de alguien con más permisos que tú.");
        }
    }

    /**
     * Editing a workspace role's permissions: only roles the actor fully holds, and only granting
     * permissions the actor holds. Owners and the installation's administration are not limited.
     */
    public static function authorizeRoleDefinition(?array $existing, string $scope, array $perms): void
    {
        if ($scope !== 'workspace' || self::isInstanceAdmin() || Policy::workspace()['role_key'] === 'owner') return;
        if ($existing && !self::holdsAll(self::rolePerms((int)$existing['id']))) {
            throw HttpException::forbidden('No puedes modificar un rol con permisos que tú no tienes.');
        }
        if (!self::holdsAll($perms)) {
            throw HttpException::forbidden('No puedes conceder permisos que tú no tienes.');
        }
    }

    // ---- helpers -------------------------------------------------------------

    private static function member(int $userId): array
    {
        $m = Database::get()->one("SELECT u.id, u.name, u.email, u.is_ghost, r.id AS role_id, r.role_key
            FROM workspace_members wm JOIN users u ON u.id = wm.user_id JOIN roles r ON r.id = wm.role_id WHERE wm.workspace_id = ? AND u.id = ?", [Policy::wsId(), $userId]);
        if (!$m) throw HttpException::notFound('Esa persona no pertenece a este espacio.');
        return $m;
    }

    private static function rolePerms(int $roleId): array
    {
        return Database::get()->column('SELECT permission FROM role_permissions WHERE role_id = ?', [$roleId]);
    }

    /** Does the actor hold every one of these workspace permissions? Owners hold everything. */
    private static function holdsAll(array $perms): bool
    {
        if (Policy::workspace()['role_key'] === 'owner') return true;
        return !array_diff($perms, Policy::workspace()['perms']);
    }
}
