<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Validator;
use Borealis\Security\Policy;

/**
 * Calendars of a workspace (1.6).
 *
 * Access levels: none < read < edit < manage.
 *  - personal calendar: only its owner (manage). Nobody else, administration included.
 *  - shared calendar: its creator and the workspace administration (workspace.manage) manage it;
 *    explicit calendar_members get their level; everyone else in the workspace gets default_access.
 *  - Google calendars are personal mirrors of the owner's Google account (see GoogleCalendar).
 */
final class Calendars
{
    public const LEVELS = ['none' => 0, 'read' => 1, 'edit' => 2, 'manage' => 3];
    public const PURPOSES = ['personal' => 'Personal', 'team' => 'Equipo', 'projects' => 'Proyectos', 'meetings' => 'Reuniones', 'events' => 'Eventos', 'other' => 'Otro'];
    public const COLORS = ['#10B981', '#0EA5E9', '#6366F1', '#F59E0B', '#EF4444', '#8B5CF6', '#14B8A6', '#EC4899', '#64748B', '#84CC16'];

    /** First visit to the calendar in a workspace: the example calendars exist; each person gets a personal one. */
    public static function ensureDefaults(): void
    {
        $db = Database::get();
        $ws = Policy::wsId();
        if (!$db->value("SELECT 1 FROM calendars WHERE workspace_id = ? AND kind = 'shared' AND purpose IS NOT NULL LIMIT 1", [$ws])) {
            foreach ([['Equipo', 'team', '#0EA5E9', 'edit'], ['Proyectos', 'projects', '#6366F1', 'edit'], ['Reuniones', 'meetings', '#F59E0B', 'edit'], ['Eventos', 'events', '#EC4899', 'read']] as [$n, $p, $c, $acc]) {
                $db->insert('calendars', ['workspace_id' => $ws, 'name' => $n, 'color' => $c, 'kind' => 'shared', 'default_access' => $acc, 'purpose' => $p, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        if (!$db->value("SELECT 1 FROM calendars WHERE workspace_id = ? AND kind = 'personal' AND owner_id = ? AND source = 'internal' AND deleted_at IS NULL LIMIT 1", [$ws, Auth::id()])) {
            $db->insert('calendars', ['workspace_id' => $ws, 'owner_id' => Auth::id(), 'name' => 'Personal', 'color' => '#10B981', 'kind' => 'personal', 'default_access' => 'none',
                'purpose' => 'personal', 'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Access level of the current person to a calendar row. */
    public static function level(array $c): string
    {
        if ($c['kind'] === 'personal') return (int)$c['owner_id'] === Auth::id() ? 'manage' : 'none';
        if (Policy::can('workspace.manage') || (int)($c['created_by'] ?? 0) === Auth::id()) return 'manage';
        $own = Database::get()->value('SELECT access FROM calendar_members WHERE calendar_id = ? AND user_id = ?', [$c['id'], Auth::id()]);
        $def = (string)$c['default_access'];
        // 1.8: a team calendar is shared with that team only, a project calendar with the people who see the
        // project. Anyone else needs to be added by name.
        if (!$own && !empty($c['team_id']) && !Database::get()->value('SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ?', [$c['team_id'], Auth::id()])) return 'none';
        if (!$own && !empty($c['project_id'])) {
            [$vis, $vp] = Policy::visibleProjectsSql('p');
            if (!Database::get()->value("SELECT 1 FROM projects p WHERE p.id = ? AND $vis", array_merge([(int)$c['project_id']], $vp))) return 'none';
        }
        if (Policy::workspace()['role_key'] === 'guest' && !$own) return 'none'; // guests only see calendars they were added to
        if ($own && self::LEVELS[$own] > self::LEVELS[$def]) return (string)$own;
        return $def;
    }

    /** Calendars the person can see, with their access level and visibility preference. */
    public static function visible(): array
    {
        self::ensureDefaults();
        $rows = Database::get()->all('SELECT c.*, p.name AS project_name, tm.name AS team_name FROM calendars c LEFT JOIN projects p ON p.id = c.project_id LEFT JOIN teams tm ON tm.id = c.team_id
            WHERE c.workspace_id = ? AND c.deleted_at IS NULL ORDER BY c.kind = \'shared\', c.source, c.name', [Policy::wsId()]);
        $hidden = array_map('intval', Auth::user()['prefs']['cal_hidden'] ?? []);
        $out = [];
        foreach ($rows as $c) {
            $lvl = self::level($c);
            if ($lvl === 'none') continue;
            $c['access'] = $lvl;
            $c['shown'] = !in_array((int)$c['id'], $hidden, true);
            $out[] = $c;
        }
        return $out;
    }

    public static function find(int $id, string $need = 'read'): array
    {
        $c = Database::get()->one('SELECT * FROM calendars WHERE id = ? AND workspace_id = ? AND deleted_at IS NULL', [$id, Policy::wsId()]);
        if (!$c || self::level($c) === 'none') throw HttpException::notFound('Ese calendario no existe o no tienes acceso.');
        $c['access'] = self::level($c);
        if (self::LEVELS[$c['access']] < self::LEVELS[$need]) {
            throw HttpException::forbidden($need === 'manage' ? 'Solo quien administra este calendario puede cambiarlo.' : 'No puedes modificar los eventos de este calendario.');
        }
        return $c;
    }

    private static function check(array $input, bool $creating): array
    {
        $r = ['name' => 'required,string,max:120', 'color' => 'nullable,color', 'kind' => 'nullable,in:personal|shared', 'default_access' => 'nullable,in:none|read|edit',
            'purpose' => 'nullable,in:' . implode('|', array_keys(self::PURPOSES)), 'project_id' => 'nullable,int', 'team_id' => 'nullable,int'];
        $v = Validator::check($input, $creating ? $r : array_map(fn($x) => 'sometimes,' . $x, $r));
        if (!empty($v['project_id'])) Projects::find((int)$v['project_id']);
        if (!empty($v['team_id']) && !Database::get()->value('SELECT 1 FROM teams WHERE id = ? AND workspace_id = ?', [(int)$v['team_id'], Policy::wsId()])) {
            throw HttpException::invalid(['team_id' => 'Ese equipo no existe.']);
        }
        return $v;
    }

    private static function checkTeamMember(mixed $teamId): void
    {
        if (!$teamId || Policy::can('teams.manage')) return;
        if (!Database::get()->value('SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ?', [(int)$teamId, Auth::id()])) {
            throw HttpException::invalid(['team_id' => 'Solo puedes compartir un calendario con un equipo del que formas parte.']);
        }
    }

    public static function create(array $input): int
    {
        $v = self::check($input, true);
        $kind = $v['kind'] ?: 'shared';
        if ($kind === 'shared' && Policy::workspace()['role_key'] === 'guest') throw HttpException::forbidden('Las personas invitadas solo pueden crear calendarios personales.');
        // 1.8: a personal calendar never belongs to a team; a team calendar only to a team you are in.
        if ($kind === 'personal') $v['team_id'] = $v['project_id'] = null;
        self::checkTeamMember($v['team_id'] ?? null);
        return Database::get()->insert('calendars', [
            'workspace_id' => Policy::wsId(), 'owner_id' => $kind === 'personal' ? Auth::id() : null, 'name' => trim($v['name']), 'color' => $v['color'] ?: self::COLORS[0],
            'kind' => $kind, 'default_access' => $kind === 'personal' ? 'none' : ($v['default_access'] ?: 'read'), 'purpose' => $v['purpose'] ?: 'other',
            'project_id' => $v['project_id'] ?: null, 'team_id' => $v['team_id'] ?: null, 'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public static function update(int $id, array $input): void
    {
        $c = self::find($id, 'manage');
        $v = self::check($input, false);
        $data = [];
        foreach (['name', 'color', 'default_access', 'purpose', 'project_id', 'team_id'] as $k) {
            if (!array_key_exists($k, $v)) continue;
            if ($k === 'default_access' && $c['kind'] === 'personal') continue;
            $data[$k] = $v[$k] === '' ? null : ($k === 'name' ? trim($v[$k]) : $v[$k]);
        }
        if ($c['kind'] === 'personal') unset($data['team_id'], $data['project_id']);
        if (!empty($data['team_id'])) self::checkTeamMember($data['team_id']);
        if ($data) Database::get()->update('calendars', $data + ['updated_at' => now()], 'id = ?', [$id]);
    }

    /** Gives (or removes, with access «none») a person's explicit access to a shared calendar. */
    public static function setMember(int $id, int $userId, string $access): void
    {
        $c = self::find($id, 'manage');
        if ($c['kind'] !== 'shared') throw HttpException::invalid(['user_id' => 'Los calendarios personales no se comparten.']);
        if (!Workspaces::isActiveMember($userId)) throw HttpException::invalid(['user_id' => 'Esa persona no pertenece al espacio.']);
        $db = Database::get();
        if ($access === 'none') { $db->run('DELETE FROM calendar_members WHERE calendar_id = ? AND user_id = ?', [$id, $userId]); return; }
        if (!isset(self::LEVELS[$access])) throw HttpException::invalid(['access' => 'Acceso no válido.']);
        $db->run('INSERT INTO calendar_members (calendar_id, user_id, access) VALUES (?,?,?) ON DUPLICATE KEY UPDATE access = VALUES(access)', [$id, $userId, $access]);
    }

    /**
     * Deleting a calendar asks where its events go: $moveTo (another calendar the person can edit)
     * or null to delete them too (recoverable: they are only hidden). Google mirrors are just unlinked.
     */
    public static function delete(int $id, ?int $moveTo): array
    {
        $c = self::find($id, 'manage');
        $db = Database::get();
        $n = (int)$db->value('SELECT COUNT(*) FROM events WHERE calendar_id = ? AND deleted_at IS NULL', [$id]);
        if ($moveTo) {
            if ($moveTo === $id) throw HttpException::invalid(['move_to' => 'Elige otro calendario.']);
            $dest = self::find($moveTo, 'edit');
            if ($dest['source'] === 'google' || $c['source'] === 'google') throw HttpException::invalid(['move_to' => 'Los eventos de Google no se mueven a otros calendarios.']);
        }
        $db->transaction(function (Database $db) use ($id, $moveTo) {
            if ($moveTo) $db->run('UPDATE events SET calendar_id = ?, updated_at = ? WHERE calendar_id = ? AND deleted_at IS NULL', [$moveTo, now(), $id]);
            else $db->run('UPDATE events SET deleted_at = ?, updated_at = ? WHERE calendar_id = ? AND deleted_at IS NULL', [now(), now(), $id]);
            $db->update('calendars', ['deleted_at' => now(), 'updated_at' => now()], 'id = ?', [$id]);
        });
        Activity::log('calendar_deleted', 'calendar', $id, "Eliminó el calendario «{$c['name']}»" . ($n ? ($moveTo ? " y movió sus $n eventos" : " y sus $n eventos") : ''));
        return ['events' => $n, 'moved' => (bool)$moveTo];
    }

    /** Show or hide a calendar in the person's own view (saved in their preferences). */
    public static function setShown(int $id, bool $shown): void
    {
        self::find($id);
        $prefs = Auth::user()['prefs'] ?? [];
        $hidden = array_values(array_diff(array_map('intval', $prefs['cal_hidden'] ?? []), [$id]));
        if (!$shown) $hidden[] = $id;
        $prefs['cal_hidden'] = $hidden;
        Database::get()->update('users', ['prefs' => json_encode($prefs), 'updated_at' => now()], 'id = ?', [Auth::id()]);
    }

    /** Show or hide the built-in layers (project tasks, personal tasks, sprints). */
    public static function setLayer(string $layer, bool $shown): void
    {
        if (!in_array($layer, ['tasks', 'personal', 'sprints', 'milestones'], true)) throw HttpException::invalid(['layer' => 'Capa no válida.']);
        $prefs = Auth::user()['prefs'] ?? [];
        $hidden = array_values(array_diff($prefs['cal_layers_hidden'] ?? [], [$layer]));
        if (!$shown) $hidden[] = $layer;
        $prefs['cal_layers_hidden'] = $hidden;
        Database::get()->update('users', ['prefs' => json_encode($prefs), 'updated_at' => now()], 'id = ?', [Auth::id()]);
    }

    public const SCOPES = ['both' => 'Ambos', 'personal' => 'Personal', 'team' => 'Equipo'];

    /** 1.8: which calendars the page shows (personal, team or both), remembered per person. */
    public static function scope(?string $requested): string
    {
        $prefs = Auth::user()['prefs'] ?? [];
        $cur = isset(self::SCOPES[$prefs['cal_scope'] ?? '']) ? $prefs['cal_scope'] : 'both';
        if ($requested !== null && isset(self::SCOPES[$requested]) && $requested !== $cur) {
            $prefs['cal_scope'] = $requested;
            Database::get()->update('users', ['prefs' => json_encode($prefs), 'updated_at' => now()], 'id = ?', [Auth::id()]);
            return $requested;
        }
        return $cur;
    }

    /** A calendar belongs to the personal side (private, only its owner) or to the team side (shared). */
    public static function inScope(array $c, string $scope): bool
    {
        return $scope === 'both' || ($scope === 'personal' ? $c['kind'] === 'personal' : $c['kind'] === 'shared');
    }

    public static function layerShown(string $layer): bool
    {
        return !in_array($layer, Auth::user()['prefs']['cal_layers_hidden'] ?? [], true);
    }
}
