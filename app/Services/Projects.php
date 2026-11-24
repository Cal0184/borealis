<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Validator;
use Borealis\Security\Policy;

final class Projects
{
    public const DEFAULT_STATUSES = [
        ['Por hacer', 'todo', '#94A3B8'],
        ['En progreso', 'in_progress', '#0EA5E9'],
        ['En revisión', 'in_progress', '#F59E0B'],
        ['Finalizado', 'done', '#10B981'],
    ];

    /** 1.11: «planned» added (migration 013). Archived is not a state: it is archived_at, and it keeps the state it had. */
    public const STATES = ['planned' => 'Planificado', 'active' => 'Activo', 'on_hold' => 'En pausa', 'completed' => 'Completado'];

    /** Load a project in the current workspace that the user can view. */
    public static function find(int $id): array
    {
        $p = Database::get()->one('SELECT p.*, t.name AS team_name FROM projects p LEFT JOIN teams t ON t.id = p.team_id WHERE p.id = ? AND p.workspace_id = ?', [$id, Policy::wsId()]);
        if (!$p || !Policy::canProject($p, 'project.view')) throw HttpException::notFound('El proyecto no existe o no tienes acceso.');
        return $p;
    }

    public static function findByKey(string $key): array
    {
        $id = Database::get()->value('SELECT id FROM projects WHERE project_key = ? AND workspace_id = ?', [strtoupper($key), Policy::wsId()])
            ?: Database::get()->value('SELECT project_id FROM project_key_aliases WHERE old_key = ? AND workspace_id = ?', [strtoupper($key), Policy::wsId()]);
        if (!$id) throw HttpException::notFound('El proyecto no existe o no tienes acceso.');
        return self::find((int)$id);
    }

    /** Projects visible to the user, with filters q, state, team_id, archived (0|1|all). */
    public static function list(array $f = []): array
    {
        // 1.7: the administration sees every project; «Solo donde participo» narrows it to their own.
        [$vis, $params] = Policy::visibleProjectsSql('p', ($f['mine'] ?? '') === '1');
        $where = [$vis];
        $archived = (string)($f['archived'] ?? '0');
        if ($archived === '1') $where[] = 'p.archived_at IS NOT NULL';
        elseif ($archived !== 'all') $where[] = 'p.archived_at IS NULL';
        if (!empty($f['q'])) { $where[] = '(p.name LIKE ? OR p.project_key LIKE ?)'; $params[] = '%' . $f['q'] . '%'; $params[] = '%' . $f['q'] . '%'; }
        if (!empty($f['state']) && isset(self::STATES[$f['state']])) { $where[] = 'p.state = ?'; $params[] = $f['state']; }
        if (!empty($f['team_id'])) { $where[] = 'p.team_id = ?'; $params[] = (int)$f['team_id']; }
        // 1.14: the «Trabajos rápidos» project is not a project people manage: out of the project lists
        if (!empty($f['standard'])) $where[] = "p.kind = 'standard'";
        $order = match ($f['sort'] ?? '') { 'due' => 'p.due_date IS NULL, p.due_date', 'recent' => 'p.updated_at DESC', default => 'p.name' };
        $rows = Database::get()->all('SELECT p.*, t.name AS team_name, u.name AS lead_name, u.avatar_color AS lead_color FROM projects p
            LEFT JOIN teams t ON t.id = p.team_id LEFT JOIN users u ON u.id = p.lead_user_id WHERE ' . implode(' AND ', $where) . " ORDER BY $order", $params);
        return self::withProgress($rows);
    }

    /** Attach task totals (top-level tasks only) to project rows. */
    public static function withProgress(array $rows): array
    {
        if (!$rows) return $rows;
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        $stats = Database::get()->all("SELECT t.project_id, COUNT(*) AS total, SUM(s.category = 'done') AS done, SUM(t.is_blocked = 1 AND s.category <> 'done') AS blocked,
                SUM(t.due_date < ? AND s.category <> 'done') AS overdue
            FROM tasks t JOIN statuses s ON s.id = t.status_id WHERE t.deleted_at IS NULL AND t.parent_id IS NULL AND t.project_id IN (" . Database::in($ids) . ') GROUP BY t.project_id', array_merge([today()], $ids));
        $by = array_column($stats, null, 'project_id');
        foreach ($rows as &$r) {
            $s = $by[$r['id']] ?? ['total' => 0, 'done' => 0, 'blocked' => 0, 'overdue' => 0];
            $r['total'] = (int)$s['total'];
            $r['done'] = (int)$s['done'];
            $r['blocked'] = (int)$s['blocked'];
            $r['overdue'] = (int)$s['overdue'];
            $r['progress'] = pct($r['done'], $r['total']);
        }
        return $rows;
    }

    public const COVERS = ['forest' => 'Bosque', 'aurora' => 'Aurora', 'lake' => 'Lago', 'moss' => 'Musgo', 'dusk' => 'Atardecer', 'night' => 'Noche', 'color' => 'Color del proyecto'];

    public static function suggestKey(string $name): string
    {
        $ascii = strtoupper((string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name));
        $words = array_values(array_filter(preg_split('/[^A-Z0-9]+/', $ascii) ?: []));
        if (!$words) $base = 'PRY';
        elseif (count($words) === 1) $base = substr($words[0], 0, 3);
        else $base = implode('', array_map(fn($w) => $w[0], array_slice($words, 0, 4)));
        if (strlen($base) < 2) $base = str_pad($base, 2, 'X');
        if (ctype_digit($base[0])) $base = 'P' . $base;
        $base = substr($base, 0, 6);
        $key = $base;
        $i = 2;
        while (Database::get()->value('SELECT 1 FROM projects WHERE workspace_id = ? AND project_key = ? UNION SELECT 1 FROM project_key_aliases WHERE workspace_id = ? AND old_key = ?', [Policy::wsId(), $key, Policy::wsId(), $key])) {
            $key = substr($base, 0, 5) . $i++;
        }
        return $key;
    }

    /** Only a name is required. */
    public static function create(array $input): int
    {
        Policy::authorize('projects.create');
        $v = Validator::check($input, [
            'name' => 'required,string,max:160', 'description' => 'nullable,string,max:5000', 'project_key' => 'nullable,string,max:6',
            'team_id' => 'nullable,int', 'visibility' => 'nullable,in:workspace|private', 'start_date' => 'nullable,date', 'due_date' => 'nullable,date',
            'lead_user_id' => 'nullable,int', 'color' => 'nullable,color', 'state' => 'nullable,in:planned|active',
        ]);
        $db = Database::get();
        if (!empty($v['project_key'])) {
            $key = strtoupper($v['project_key']);
            if (!preg_match('/^[A-Z][A-Z0-9]{1,5}$/', $key)) throw HttpException::invalid(['project_key' => 'Usa de 2 a 6 letras o números, empezando por letra.']);
            if ($db->value('SELECT 1 FROM projects WHERE workspace_id = ? AND project_key = ? UNION SELECT 1 FROM project_key_aliases WHERE workspace_id = ? AND old_key = ?', [Policy::wsId(), $key, Policy::wsId(), $key])) throw HttpException::invalid(['project_key' => 'Esas siglas ya están en uso.']);
        } else {
            $key = self::suggestKey($v['name']);
        }
        if (!empty($v['team_id'])) Teams::find((int)$v['team_id']);
        if (!empty($v['lead_user_id']) && !Workspaces::isActiveMember((int)$v['lead_user_id'])) throw HttpException::invalid(['lead_user_id' => 'Responsable no válido.']);
        self::assertDates($v['start_date'] ?? null, $v['due_date'] ?? null);
        $members = array_values(array_unique(array_filter(array_map('intval', is_array($input['member_ids'] ?? null) ? $input['member_ids'] : []))));
        foreach ($members as $uid) if (!Workspaces::isActiveMember($uid)) throw HttpException::invalid(['member_ids' => 'Una de las personas no pertenece al espacio.']);

        $pid = $db->transaction(function () use ($db, $v, $key, $members) {
            $id = $db->insert('projects', [
                'workspace_id' => Policy::wsId(), 'team_id' => $v['team_id'] ?: null, 'project_key' => $key, 'name' => $v['name'],
                'description' => $v['description'], 'color' => $v['color'] ?? '#10B981', 'visibility' => 'private', // 1.7: access only through membership
                'lead_user_id' => $v['lead_user_id'] ?: Auth::id(), 'state' => ($v['state'] ?? null) ?: 'active', 'start_date' => $v['start_date'], 'due_date' => $v['due_date'],
                'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (self::DEFAULT_STATUSES as $i => [$n, $cat, $color]) {
                $db->insert('statuses', ['project_id' => $id, 'name' => $n, 'category' => $cat, 'color' => $color, 'position' => $i]);
            }
            $db->insert('project_members', ['project_id' => $id, 'user_id' => Auth::id(), 'role_id' => Policy::projectRoleId('manager'), 'added_at' => now()]);
            // 1.14: the lead (if it is someone else) manages it too, and the people chosen in the dialog join as contributors
            $lead = (int)($v['lead_user_id'] ?: Auth::id());
            if ($lead !== (int)Auth::id()) $db->run('INSERT IGNORE INTO project_members (project_id, user_id, role_id, added_at) VALUES (?,?,?,?)', [$id, $lead, Policy::projectRoleId('manager'), now()]);
            foreach ($members as $uid) if ($uid !== (int)Auth::id() && $uid !== $lead) $db->run('INSERT IGNORE INTO project_members (project_id, user_id, role_id, added_at) VALUES (?,?,?,?)', [$id, $uid, Policy::projectRoleId('contributor'), now()]);
            Activity::log('created', 'project', $id, "Creó el proyecto «{$v['name']}»", $id, null, [], null,
                ['after' => ['nombre' => $v['name'], 'siglas' => $key, 'inicio' => $v['start_date'], 'entrega' => $v['due_date'], 'estado' => ($v['state'] ?? null) ?: 'active', 'integrantes' => count($members) + 1]]);
            return $id;
        });
        foreach (array_unique(array_merge($members, [(int)($v['lead_user_id'] ?: 0)])) as $uid) {
            if ($uid > 0 && $uid !== (int)Auth::id()) Notifications::send($uid, 'project', "Te añadieron al proyecto {$v['name']}", url('/projects/' . $pid), $pid);
        }
        return $pid;
    }

    private static function assertDates(?string $start, ?string $due): void
    {
        if ($start && $due && $due < $start) throw HttpException::invalid(['due_date' => 'La fecha de entrega no puede ser anterior al inicio.']);
    }

    /** Partial update. The internal id and key never change; the name is the single source shown everywhere. */
    public static function update(int $id, array $input): array
    {
        $p = self::find($id);
        Policy::authorizeProject($p, 'project.manage');
        $v = Validator::check($input, [
            'name' => 'sometimes,required,string,max:160', 'description' => 'sometimes,nullable,string,max:5000', 'team_id' => 'sometimes,nullable,int',
            'team_role_id' => 'sometimes,nullable,int', 'visibility' => 'sometimes,in:workspace|private', 'state' => 'sometimes,in:planned|active|on_hold|completed',
            'start_date' => 'sometimes,nullable,date', 'due_date' => 'sometimes,nullable,date', 'lead_user_id' => 'sometimes,nullable,int', 'color' => 'sometimes,color',
            'cover' => 'sometimes,in:' . implode('|', array_keys(self::COVERS)), 'project_key' => 'sometimes,required,string,max:6',
            'is_flexible' => 'sometimes,bool',
        ]);
        if (array_key_exists('is_flexible', $v)) $v['is_flexible'] = $v['is_flexible'] ? 1 : 0;
        $db = Database::get();
        $oldKey = null;
        if (isset($v['project_key'])) {
            $key = strtoupper($v['project_key']);
            if (!preg_match('/^[A-Z][A-Z0-9]{1,5}$/', $key)) throw HttpException::invalid(['project_key' => 'Usa de 2 a 6 letras o números, empezando por letra.']);
            if ($key === $p['project_key']) {
                unset($v['project_key']);
            } else {
                $taken = $db->value('SELECT 1 FROM projects WHERE workspace_id = ? AND project_key = ? AND id <> ?', [$p['workspace_id'], $key, $id])
                    || $db->value('SELECT 1 FROM project_key_aliases WHERE workspace_id = ? AND old_key = ? AND project_id <> ?', [$p['workspace_id'], $key, $id]);
                if ($taken) throw HttpException::invalid(['project_key' => 'Esas siglas ya las usa (o las usó) otro proyecto.']);
                $v['project_key'] = $key;
                $oldKey = $p['project_key'];
            }
        }
        if (array_key_exists('team_id', $v) && $v['team_id']) Teams::find((int)$v['team_id']);
        if (!empty($v['team_role_id'])) Workspaces::role((int)$v['team_role_id'], 'project');
        if (!empty($v['lead_user_id']) && !Workspaces::isActiveMember((int)$v['lead_user_id'])) throw HttpException::invalid(['lead_user_id' => 'Responsable no válido.']);
        self::assertDates(array_key_exists('start_date', $v) ? $v['start_date'] : $p['start_date'], array_key_exists('due_date', $v) ? $v['due_date'] : $p['due_date']);
        if (isset($v['state'])) {
            $v['completed_at'] = $v['state'] === 'completed' ? ($p['completed_at'] ?: now()) : null;
        }
        if (!$v) return $p;
        $db->transaction(function () use ($db, $v, $id, $p, $oldKey) {
            $db->update('projects', $v + ['updated_at' => now()], 'id = ?', [$id]);
            if ($oldKey) {
                // Old links (BOR-101) keep working: the previous initials stay reserved for this project.
                $db->run('DELETE FROM project_key_aliases WHERE workspace_id = ? AND old_key = ?', [$p['workspace_id'], $v['project_key']]);
                $db->run('INSERT IGNORE INTO project_key_aliases (workspace_id, old_key, project_id, created_at) VALUES (?, ?, ?, ?)', [$p['workspace_id'], $oldKey, $id, now()]);
            }
        });
        $changes = [];
        if ($oldKey) $changes[] = "cambió las siglas de {$oldKey} a {$v['project_key']}";
        if (isset($v['name']) && $v['name'] !== $p['name']) $changes[] = "renombró «{$p['name']}» a «{$v['name']}»";
        if (isset($v['state']) && $v['state'] !== $p['state']) $changes[] = 'cambió el estado a ' . self::STATES[$v['state']];
        $chg = array_keys(array_filter($v, fn($val, $k) => !in_array($k, ['completed_at', 'description'], true) && (string)$val !== (string)($p[$k] ?? ''), ARRAY_FILTER_USE_BOTH));
        $isDates = $chg && !array_diff($chg, ['start_date', 'due_date']);
        Activity::log($isDates ? 'due_changed' : 'updated', 'project', $id, $changes ? ucfirst(implode(', ', $changes)) : ($isDates ? 'Cambió las fechas del proyecto «' . $p['name'] . '»' : 'Actualizó el proyecto «' . ($v['name'] ?? $p['name']) . '»'), $id, null, ['fields' => array_keys($v)], null,
            ['before' => array_intersect_key($p, array_flip($chg)), 'after' => array_intersect_key($v, array_flip($chg)), 'reason' => is_string($input['change_reason'] ?? null) ? $input['change_reason'] : null, 'category' => $isDates ? 'calendar' : 'project']);
        if (($v['state'] ?? null) === 'completed' && $p['state'] !== 'completed') {
            foreach (self::memberIds($id) as $uid) Notifications::send($uid, 'project', "Proyecto completado: " . ($v['name'] ?? $p['name']), url('/projects/' . $id), $id);
        }
        // 1.8: important changes reach the members (a new delivery date, or the project on hold)
        $pname = $v['name'] ?? $p['name'];
        if (($v['state'] ?? null) === 'completed' && $p['state'] !== 'completed') Integrations::announce($id, 'milestone', "Proyecto completado: «{$pname}»", url('/projects/' . $id), 'pdone:' . $id . ':' . today());
        if (array_key_exists('due_date', $v) && (string)$v['due_date'] !== (string)$p['due_date'] && $v['due_date']) Integrations::announce($id, 'milestone', "«{$pname}»: nueva fecha de entrega, " . fmt_date($v['due_date'], true), url('/projects/' . $id), 'pdue:' . $id . ':' . $v['due_date']);
        if (array_key_exists('due_date', $v) && (string)$v['due_date'] !== (string)$p['due_date']) {
            foreach (self::memberIds($id) as $uid) Notifications::send($uid, 'project', "«{$pname}»: la entrega " . ($v['due_date'] ? 'es ahora el ' . fmt_date($v['due_date']) : 'quedó sin fecha'), url('/projects/' . $id), $id, null, 'pdue:' . $id . ':' . ($v['due_date'] ?: 'none'));
        }
        if (($v['state'] ?? null) === 'on_hold' && $p['state'] !== 'on_hold') {
            foreach (self::memberIds($id) as $uid) Notifications::send($uid, 'project', "«{$pname}» quedó en pausa", url('/projects/' . $id), $id);
        }
        return self::find($id);
    }

    public static function setArchived(int $id, bool $archived): void
    {
        $p = self::find($id);
        Policy::authorizeProject($p, 'project.archive');
        Database::get()->update('projects', ['archived_at' => $archived ? now() : null, 'updated_at' => now()], 'id = ?', [$id]);
        Activity::log($archived ? 'archived' : 'restored', 'project', $id, ($archived ? 'Archivó' : 'Restauró') . " el proyecto «{$p['name']}»", $id);
    }

    // ---- Members --------------------------------------------------------

    /**
     * Explicit members plus people who get access through a team: the project's main team and
     * every team added as a participant (1.2). Team access is live: whoever joins the team later
     * also gets access, and leaving the team removes it.
     */
    public static function members(array $p): array
    {
        $db = Database::get();
        $rows = $db->all("SELECT u.id, u.name, u.email, u.handle, u.avatar_color, u.title, r.id AS role_id, r.name AS role_name, 'direct' AS source
            FROM project_members pm JOIN users u ON u.id = pm.user_id JOIN roles r ON r.id = pm.role_id
            JOIN workspace_members wm ON wm.user_id = u.id AND wm.workspace_id = ? AND wm.status = 'active'
            WHERE pm.project_id = ? ORDER BY u.name", [Policy::wsId(), $p['id']]);
        $seen = array_map('intval', array_column($rows, 'id'));
        foreach (self::accessTeams($p) as $team) {
            $people = $db->all("SELECT u.id, u.name, u.email, u.handle, u.avatar_color, u.title, tm.team_role FROM team_members tm JOIN users u ON u.id = tm.user_id
                JOIN workspace_members wm ON wm.user_id = u.id AND wm.workspace_id = ? AND wm.status = 'active' WHERE tm.team_id = ? ORDER BY u.name", [Policy::wsId(), $team['id']]);
            $teamRole = $team['role_id'] ? Workspaces::role((int)$team['role_id']) : null;
            foreach ($people as $t) {
                if (in_array((int)$t['id'], $seen, true)) continue;
                $seen[] = (int)$t['id'];
                $t['role_id'] = $t['team_role'] === 'coordinator' ? Policy::projectRoleId('manager') : ((int)$team['role_id'] ?: Policy::projectRoleId('contributor'));
                $t['role_name'] = $t['team_role'] === 'coordinator' ? 'Responsable' : ($teamRole['name'] ?? 'Colaborador');
                $t['source'] = 'team';
                $t['team_name'] = $team['name'];
                $rows[] = $t;
            }
        }
        return $rows;
    }

    /** Teams that give access to the project: main team first, then participant teams. */
    public static function accessTeams(array $p): array
    {
        $db = Database::get();
        $out = [];
        if (!empty($p['team_id'])) {
            $t = $db->one('SELECT id, name, color, is_flexible FROM teams WHERE id = ? AND archived_at IS NULL', [$p['team_id']]);
            if ($t) $out[] = $t + ['role_id' => $p['team_role_id'] ?? null, 'main' => true];
        }
        foreach ($db->all('SELECT t.id, t.name, t.color, t.is_flexible, pt.role_id FROM project_teams pt JOIN teams t ON t.id = pt.team_id
                WHERE pt.project_id = ? AND t.archived_at IS NULL ORDER BY t.name', [$p['id']]) as $t) {
            if (!empty($p['team_id']) && (int)$t['id'] === (int)$p['team_id']) continue;
            $out[] = $t + ['main' => false];
        }
        return $out;
    }

    /** Adds a whole team as participant of the project with a project role for its members. */
    public static function addTeam(int $id, int $teamId, int $roleId): void
    {
        $p = self::find($id);
        Policy::authorizeProject($p, 'project.manage');
        Workspaces::role($roleId, 'project');
        $team = Teams::find($teamId);
        if ($team['archived_at']) throw HttpException::invalid(['team_id' => 'El equipo está archivado.']);
        if ((int)$p['team_id'] === $teamId) throw HttpException::invalid(['team_id' => 'Es el equipo principal del proyecto: sus integrantes ya participan.']);
        Database::get()->run('INSERT INTO project_teams (project_id, team_id, role_id, added_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE role_id = VALUES(role_id)', [$id, $teamId, $roleId, now()]);
        Activity::log('team_added', 'project', $id, "Añadió el equipo «{$team['name']}» al proyecto", $id);
        foreach (Teams::members($teamId) as $m) {
            if ((int)$m['id'] !== Auth::id()) Notifications::send((int)$m['id'], 'project', "Tu equipo {$team['name']} participa en el proyecto {$p['name']}", url('/projects/' . $id), $id);
        }
    }

    public static function removeTeam(int $id, int $teamId): void
    {
        $p = self::find($id);
        Policy::authorizeProject($p, 'project.manage');
        $n = Database::get()->run('DELETE FROM project_teams WHERE project_id = ? AND team_id = ?', [$id, $teamId])->rowCount();
        if (!$n) throw HttpException::notFound();
        Activity::log('team_removed', 'project', $id, 'Retiró al equipo «' . (Database::get()->value('SELECT name FROM teams WHERE id = ?', [$teamId]) ?? '') . '» del proyecto', $id);
    }

    /** People who can be assigned work: members with task.edit or better. Used by selectors. */
    public static function assignableUsers(array $p): array
    {
        $editRoles = array_map('intval', Database::get()->column("SELECT rp.role_id FROM role_permissions rp JOIN roles r ON r.id = rp.role_id WHERE r.workspace_id = ? AND rp.permission = 'task.edit'", [Policy::wsId()]));
        return array_values(array_filter(self::members($p), fn($m) => in_array((int)$m['role_id'], $editRoles, true)));
    }

    public static function memberIds(int $projectId): array
    {
        $p = Database::get()->one('SELECT * FROM projects WHERE id = ?', [$projectId]);
        return $p ? array_map('intval', array_column(self::members($p), 'id')) : [];
    }

    public static function addMember(int $id, int $userId, int $roleId): void
    {
        $p = self::find($id);
        Policy::authorizeProject($p, 'project.manage');
        Workspaces::role($roleId, 'project');
        if (!Workspaces::isActiveMember($userId)) throw HttpException::invalid(['user_id' => 'La persona no pertenece al espacio.']);
        Database::get()->run('INSERT INTO project_members (project_id, user_id, role_id, added_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE role_id = VALUES(role_id)', [$id, $userId, $roleId, now()]);
        $u = Users::find($userId);
        Activity::log('member_added', 'project', $id, "Añadió a {$u['name']} al proyecto", $id);
        Notifications::send($userId, 'project', "Te añadieron al proyecto {$p['name']}", url('/projects/' . $id), $id);
    }

    public static function removeMember(int $id, int $userId): void
    {
        $p = self::find($id);
        Policy::authorizeProject($p, 'project.manage');
        $db = Database::get();
        $managerRole = Policy::projectRoleId('manager');
        $isManager = $db->value('SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ? AND role_id = ?', [$id, $userId, $managerRole]);
        if ($isManager && (int)$db->value('SELECT COUNT(*) FROM project_members WHERE project_id = ? AND role_id = ? AND user_id <> ?', [$id, $managerRole, $userId]) === 0 && !Policy::can('projects.manage_all')) {
            throw HttpException::conflict('El proyecto necesita al menos un responsable.');
        }
        $n = $db->run('DELETE FROM project_members WHERE project_id = ? AND user_id = ?', [$id, $userId])->rowCount();
        if (!$n) throw HttpException::notFound();
        Activity::log('member_removed', 'project', $id, 'Retiró a ' . (Users::find($userId)['name'] ?? '') . ' del proyecto', $id);
    }

    // ---- Statuses -------------------------------------------------------

    public static function statuses(int $projectId): array
    {
        return Database::get()->all('SELECT * FROM statuses WHERE project_id = ? ORDER BY position, id', [$projectId]);
    }

    public static function status(int $projectId, int $statusId): array
    {
        $s = Database::get()->one('SELECT * FROM statuses WHERE id = ? AND project_id = ?', [$statusId, $projectId]);
        if (!$s) throw HttpException::invalid(['status_id' => 'Estado no válido para este proyecto.']);
        return $s;
    }

    public static function firstStatus(int $projectId, string $category): ?array
    {
        return Database::get()->one('SELECT * FROM statuses WHERE project_id = ? AND category = ? ORDER BY position, id LIMIT 1', [$projectId, $category]);
    }

    public static function saveStatus(int $projectId, ?int $statusId, array $input): int
    {
        $p = self::find($projectId);
        Policy::authorizeProject($p, 'project.manage');
        $rules = ['name' => 'required,string,max:60', 'category' => 'required,in:todo|in_progress|done', 'color' => 'nullable,color', 'wip_limit' => 'nullable,int'];
        if ($statusId) $rules = array_map(fn($r) => 'sometimes,' . $r, $rules);
        $v = Validator::check($input, $rules);
        $db = Database::get();
        if ($statusId) {
            $old = self::status($projectId, $statusId);
            if (isset($v['category']) && $old['category'] === 'done' && $v['category'] !== 'done' && (int)$db->value("SELECT COUNT(*) FROM statuses WHERE project_id = ? AND category = 'done'", [$projectId]) === 1) {
                throw HttpException::conflict('El proyecto necesita al menos un estado de tipo «Finalizado».');
            }
            if ($v) $db->update('statuses', $v, 'id = ?', [$statusId]);
            if (isset($v['category']) && $v['category'] !== $old['category']) self::syncCompletion($statusId, $v['category']);
            Activity::log('status_updated', 'status', $statusId, 'Actualizó la columna «' . ($v['name'] ?? $old['name']) . '»', $projectId);
            return $statusId;
        }
        $pos = (int)$db->value('SELECT COALESCE(MAX(position), -1) + 1 FROM statuses WHERE project_id = ?', [$projectId]);
        $id = $db->insert('statuses', ['project_id' => $projectId, 'name' => $v['name'], 'category' => $v['category'], 'color' => $v['color'] ?? '#94A3B8', 'position' => $pos, 'wip_limit' => $v['wip_limit'] ?: null]);
        Activity::log('status_created', 'status', $id, "Creó la columna «{$v['name']}»", $projectId);
        return $id;
    }

    private static function syncCompletion(int $statusId, string $category): void
    {
        $db = Database::get();
        if ($category === 'done') $db->run('UPDATE tasks SET completed_due_date = IF(completed_at IS NULL, due_date, completed_due_date), completed_at = COALESCE(completed_at, ?) WHERE status_id = ?', [now(), $statusId]);
        else $db->run('UPDATE tasks SET completed_at = NULL, completed_due_date = NULL WHERE status_id = ?', [$statusId]);
    }

    /** Move a status one slot left (-1) or right (+1), or set full order via $order ids. */
    public static function reorderStatuses(int $projectId, array $order): void
    {
        $p = self::find($projectId);
        Policy::authorizeProject($p, 'project.manage');
        $ids = array_map('intval', array_column(self::statuses($projectId), 'id'));
        $order = array_map('intval', $order);
        sort($ids);
        $check = $order;
        sort($check);
        if ($ids !== $check) throw HttpException::invalid(['order' => 'El orden enviado no coincide con las columnas del proyecto.']);
        Database::get()->transaction(function (Database $db) use ($order) {
            foreach ($order as $i => $sid) $db->update('statuses', ['position' => $i], 'id = ?', [$sid]);
        });
        Activity::log('status_reordered', 'project', $projectId, 'Reordenó las columnas', $projectId);
    }

    public static function moveStatus(int $projectId, int $statusId, int $dir): void
    {
        $ids = array_map('intval', array_column(self::statuses($projectId), 'id'));
        $i = array_search($statusId, $ids, true);
        if ($i === false) throw HttpException::notFound();
        $j = $i + ($dir < 0 ? -1 : 1);
        if ($j < 0 || $j >= count($ids)) return;
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        self::reorderStatuses($projectId, $ids);
    }

    /** Delete a column; its tasks move to $targetId so no task is lost. */
    public static function deleteStatus(int $projectId, int $statusId, ?int $targetId): void
    {
        $p = self::find($projectId);
        Policy::authorizeProject($p, 'project.manage');
        $s = self::status($projectId, $statusId);
        $db = Database::get();
        $all = self::statuses($projectId);
        if (count($all) <= 1) throw HttpException::conflict('El proyecto necesita al menos una columna.');
        if ($s['category'] === 'done' && count(array_filter($all, fn($x) => $x['category'] === 'done')) === 1) {
            throw HttpException::conflict('No puedes eliminar el único estado de tipo «Finalizado».');
        }
        $count = (int)$db->value('SELECT COUNT(*) FROM tasks WHERE status_id = ?', [$statusId]);
        $t = null;
        if ($count > 0) {
            if (!$targetId || $targetId === $statusId) throw HttpException::invalid(['target_id' => 'Elige a qué columna se moverán sus tareas.']);
            $t = self::status($projectId, $targetId);
        }
        $db->transaction(function (Database $db) use ($statusId, $targetId, $count, $t) {
            if ($count > 0) {
                $db->run('UPDATE tasks SET status_id = ?, updated_at = ? WHERE status_id = ?', [$targetId, now(), $statusId]);
                self::syncCompletion($targetId, $t['category']);
            }
            $db->run('DELETE FROM statuses WHERE id = ?', [$statusId]);
        });
        Activity::log('status_deleted', 'status', $statusId, "Eliminó la columna «{$s['name']}»" . ($count ? " y movió $count tareas a «{$t['name']}»" : ''), $projectId);
    }

    // ---- Labels ---------------------------------------------------------

    public static function labels(int $projectId): array
    {
        return Database::get()->all('SELECT * FROM labels WHERE project_id = ? ORDER BY name', [$projectId]);
    }

    public static function saveLabel(int $projectId, ?int $labelId, array $input): int
    {
        $p = self::find($projectId);
        Policy::authorizeProject($p, $labelId ? 'project.manage' : 'task.edit');
        $v = Validator::check($input, ['name' => 'required,string,max:40', 'color' => 'nullable,color']);
        $db = Database::get();
        $dupe = $db->value('SELECT id FROM labels WHERE project_id = ? AND name = ?', [$projectId, $v['name']]);
        if ($dupe && (int)$dupe !== $labelId) {
            if (!$labelId) return (int)$dupe;
            throw HttpException::invalid(['name' => 'Ya existe una etiqueta con ese nombre.']);
        }
        if ($labelId) {
            if (!$db->value('SELECT 1 FROM labels WHERE id = ? AND project_id = ?', [$labelId, $projectId])) throw HttpException::notFound();
            $db->update('labels', ['name' => $v['name'], 'color' => $v['color'] ?? '#0EA5E9'], 'id = ?', [$labelId]);
            return $labelId;
        }
        return $db->insert('labels', ['project_id' => $projectId, 'name' => $v['name'], 'color' => $v['color'] ?? '#0EA5E9']);
    }

    public static function deleteLabel(int $projectId, int $labelId): void
    {
        $p = self::find($projectId);
        Policy::authorizeProject($p, 'project.manage');
        if (!Database::get()->run('DELETE FROM labels WHERE id = ? AND project_id = ?', [$labelId, $projectId])->rowCount()) throw HttpException::notFound();
    }

    // ---- Modules --------------------------------------------------------

    public static function setModule(int $projectId, string $key, bool $enabled): array
    {
        $p = self::find($projectId);
        Policy::authorizeProject($p, 'project.manage');
        $changes = Modules::set($projectId, $key, $enabled);
        foreach ($changes as $k => $on) {
            Activity::log($on ? 'module_enabled' : 'module_disabled', 'project', $projectId, ($on ? 'Activó' : 'Desactivó') . ' el módulo ' . Modules::OPTIONAL[$k][0], $projectId);
        }
        return $changes;
    }
}
