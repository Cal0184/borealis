<?php
declare(strict_types=1);

namespace Borealis\Security;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Session;

/**
 * Server-side authorization. Every service resolves the current workspace and
 * project permissions through this class; nothing trusts client-provided ids.
 */
final class Policy
{
    private static ?array $workspace = null;
    private static array $projectCache = [];

    public static function reset(): void
    {
        self::$workspace = null;
        self::$projectCache = [];
    }

    /** Current workspace membership: ['id','name','role_key','role_name','perms'=>[...]] */
    public static function workspace(): array
    {
        if (self::$workspace !== null) return self::$workspace;
        $uid = Auth::id();
        if (!$uid) throw HttpException::unauthorized();
        $db = Database::get();
        $sql = 'SELECT w.id, w.name, w.settings, r.id AS role_id, r.role_key, r.name AS role_name
                FROM workspace_members wm JOIN workspaces w ON w.id = wm.workspace_id JOIN roles r ON r.id = wm.role_id
                WHERE wm.user_id = ? AND wm.status = \'active\'';
        $wanted = (int)(Session::get('wsid') ?: Auth::user()['last_workspace_id'] ?? 0);
        $row = $wanted ? $db->one($sql . ' AND w.id = ?', [$uid, $wanted]) : null;
        $row ??= $db->one($sql . ' ORDER BY wm.joined_at LIMIT 1', [$uid]);
        if (!$row) throw HttpException::forbidden('Tu cuenta no pertenece a ningún espacio de trabajo activo.');
        Session::set('wsid', (int)$row['id']);
        $row['id'] = (int)$row['id'];
        $row['settings'] = json_decode((string)$row['settings'], true) ?: [];
        $row['perms'] = $db->column('SELECT permission FROM role_permissions WHERE role_id = ?', [$row['role_id']]);
        return self::$workspace = $row;
    }

    /** Command line only: the workspace of the person set with Auth::actAs. */
    public static function useWorkspace(int $wsId): void
    {
        if (PHP_SAPI !== 'cli') throw new \LogicException('Policy::useWorkspace solo desde la consola.');
        self::$workspace = null;
        self::$projectCache = [];
        $db = Database::get();
        $row = $db->one("SELECT w.id, w.name, w.settings, r.id AS role_id, r.role_key, r.name AS role_name FROM workspace_members wm JOIN workspaces w ON w.id = wm.workspace_id JOIN roles r ON r.id = wm.role_id
            WHERE wm.user_id = ? AND wm.workspace_id = ? AND wm.status = 'active'", [Auth::id(), $wsId]) ?? throw HttpException::forbidden();
        $row['id'] = (int)$row['id'];
        $row['settings'] = json_decode((string)$row['settings'], true) ?: [];
        $row['perms'] = $db->column('SELECT permission FROM role_permissions WHERE role_id = ?', [$row['role_id']]);
        self::$workspace = $row;
    }

    public static function wsId(): int
    {
        return self::workspace()['id'];
    }

    public static function can(string $perm): bool
    {
        return in_array($perm, self::workspace()['perms'], true);
    }

    public static function authorize(string $perm): void
    {
        if (!self::can($perm)) throw HttpException::forbidden();
    }

    /** Permissions of the current user on a project row (must belong to the current workspace). */
    public static function projectPerms(array $project): array
    {
        $pid = (int)$project['id'];
        if (isset(self::$projectCache[$pid])) return self::$projectCache[$pid];
        if ((int)$project['workspace_id'] !== self::wsId()) return self::$projectCache[$pid] = [];
        if (self::can('projects.manage_all')) return self::$projectCache[$pid] = array_keys(Permissions::PROJECT);

        $db = Database::get();
        $uid = Auth::id();
        $roleIds = $db->column('SELECT role_id FROM project_members WHERE project_id = ? AND user_id = ?', [$pid, $uid]);
        if (!empty($project['team_id'])) {
            $tm = $db->one('SELECT tm.team_role FROM team_members tm JOIN teams t ON t.id = tm.team_id WHERE tm.team_id = ? AND tm.user_id = ? AND t.archived_at IS NULL', [$project['team_id'], $uid]);
            if ($tm) {
                $roleIds[] = $tm['team_role'] === 'coordinator'
                    ? self::projectRoleId('manager')
                    : ((int)($project['team_role_id'] ?? 0) ?: self::projectRoleId('contributor'));
            }
        }
        // Teams added as participants (1.2): coordinators manage, members get the team's role.
        foreach ($db->all('SELECT tm.team_role, pt.role_id FROM project_teams pt JOIN team_members tm ON tm.team_id = pt.team_id JOIN teams t ON t.id = pt.team_id
                WHERE pt.project_id = ? AND tm.user_id = ? AND t.archived_at IS NULL', [$pid, $uid]) as $tm) {
            $roleIds[] = $tm['team_role'] === 'coordinator' ? self::projectRoleId('manager') : (int)$tm['role_id'];
        }
        // 1.7: no access for simply being in the workspace (the old «visible to the workspace»
        // option): only members, their teams and whoever has projects.manage_all see a project.
        $roleIds = array_values(array_unique(array_filter(array_map('intval', $roleIds))));
        $perms = $roleIds ? $db->column('SELECT DISTINCT permission FROM role_permissions WHERE role_id IN (' . Database::in($roleIds) . ')', $roleIds) : [];
        // project.view is implied by any project role.
        if ($perms && !in_array('project.view', $perms, true)) $perms[] = 'project.view';
        return self::$projectCache[$pid] = $perms;
    }

    public static function canProject(array $project, string $perm): bool
    {
        return in_array($perm, self::projectPerms($project), true);
    }

    public static function authorizeProject(array $project, string $perm): void
    {
        if (!self::canProject($project, 'project.view')) throw HttpException::notFound('El proyecto no existe o no tienes acceso.');
        if (!self::canProject($project, $perm)) throw HttpException::forbidden();
        if ($project['archived_at'] && !in_array($perm, ['project.view', 'project.archive', 'project.manage'], true)) {
            throw HttpException::forbidden('El proyecto está archivado. Restáuralo para hacer cambios.');
        }
    }

    public static function isGuest(): bool
    {
        return self::workspace()['role_key'] === 'guest';
    }

    /**
     * 1.4.1: people a guest may see (search, Equipos, profiles): themselves, the people of the projects
     * they can see and of their own teams. Null = no restriction (everyone who is not a guest).
     */
    public static function visibleUserIds(): ?array
    {
        if (!self::isGuest()) return null;
        $db = Database::get();
        $uid = Auth::id();
        [$vis, $vp] = self::visibleProjectsSql('p');
        $pids = array_map('intval', $db->column("SELECT p.id FROM projects p WHERE $vis", $vp));
        $teams = self::visibleTeamIds();
        $ids = [$uid];
        if ($pids) {
            $in = Database::in($pids);
            $ids = array_merge($ids, $db->column("SELECT user_id FROM project_members WHERE project_id IN ($in)", $pids));
        }
        if ($teams) {
            $tin = Database::in($teams);
            $ids = array_merge($ids, $db->column("SELECT user_id FROM team_members WHERE team_id IN ($tin)", $teams));
        }
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** 1.4.1: teams a guest may see: their own and those working on projects they can see. Null = all. */
    public static function visibleTeamIds(): ?array
    {
        if (!self::isGuest()) return null;
        $db = Database::get();
        [$vis, $vp] = self::visibleProjectsSql('p');
        $ids = array_merge(
            $db->column('SELECT tm.team_id FROM team_members tm JOIN teams t ON t.id = tm.team_id WHERE tm.user_id = ? AND t.workspace_id = ?', [Auth::id(), self::wsId()]),
            $db->column("SELECT p.team_id FROM projects p WHERE p.team_id IS NOT NULL AND $vis", $vp),
            $db->column("SELECT pt.team_id FROM project_teams pt JOIN projects p ON p.id = pt.project_id WHERE $vis", $vp),
        );
        return array_values(array_unique(array_map('intval', $ids)));
    }

    public static function projectRoleId(string $key): int
    {
        static $cache = [];
        $ws = self::wsId();
        return $cache["$ws.$key"] ??= (int)Database::get()->value("SELECT id FROM roles WHERE workspace_id = ? AND scope = 'project' AND role_key = ?", [$ws, $key]);
    }

    /**
     * SQL fragment restricting `projects p` to those the current user can view.
     * Mirrors projectPerms(): explicit membership or team membership (1.7: no workspace-wide visibility).
     */
    public static function visibleProjectsSql(string $alias = 'p', bool $onlyMine = false): array
    {
        $ws = self::wsId();
        if (!$onlyMine && self::can('projects.manage_all')) {
            return ["$alias.workspace_id = ?", [$ws]];
        }
        $uid = Auth::id();
        $sql = "$alias.workspace_id = ? AND ("
            . "EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = $alias.id AND pm.user_id = ?)"
            . " OR EXISTS (SELECT 1 FROM team_members tm JOIN teams tt ON tt.id = tm.team_id AND tt.archived_at IS NULL WHERE tm.team_id = $alias.team_id AND tm.user_id = ?)"
            . " OR EXISTS (SELECT 1 FROM project_teams ptv JOIN team_members tmv ON tmv.team_id = ptv.team_id JOIN teams ttv ON ttv.id = ptv.team_id AND ttv.archived_at IS NULL WHERE ptv.project_id = $alias.id AND tmv.user_id = ?))";
        return [$sql, [$ws, $uid, $uid, $uid]];
    }
}
