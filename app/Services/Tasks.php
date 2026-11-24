<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Validator;
use Borealis\Security\Policy;

/**
 * Single source of truth for tasks: list, board, backlog, calendar, timeline,
 * my-work and reports all read through query() and write through this class.
 */
final class Tasks
{
    private const SELECT = "SELECT t.*, CONCAT(p.project_key, '-', t.number) AS task_key, p.name AS project_name, p.project_key, p.color AS project_color,
        s.name AS status_name, s.category AS status_category, s.color AS status_color,
        pr.name AS priority_name, pr.color AS priority_color, pr.weight AS priority_weight,
        a.name AS assignee_name, a.avatar_color AS assignee_color, tteam.name AS team_name, tteam.color AS team_color, e.name AS epic_name, e.color AS epic_color, sp.name AS sprint_name,
        (SELECT COUNT(*) FROM tasks st WHERE st.parent_id = t.id AND st.deleted_at IS NULL) AS subtask_count,
        (SELECT COUNT(*) FROM tasks st JOIN statuses ss ON ss.id = st.status_id WHERE st.parent_id = t.id AND st.deleted_at IS NULL AND ss.category = 'done') AS subtask_done,
        (SELECT COUNT(*) FROM comments c WHERE c.task_id = t.id AND c.deleted_at IS NULL) AS comment_count,
        (SELECT COUNT(*) FROM files f WHERE f.task_id = t.id AND f.deleted_at IS NULL) AS file_count,
        (SELECT COALESCE(SUM(te.minutes), 0) FROM time_entries te WHERE te.task_id = t.id AND te.deleted_at IS NULL) AS logged_minutes
        FROM tasks t JOIN projects p ON p.id = t.project_id JOIN statuses s ON s.id = t.status_id
        LEFT JOIN priorities pr ON pr.id = t.priority_id LEFT JOIN users a ON a.id = t.assignee_id LEFT JOIN teams tteam ON tteam.id = t.team_id
        LEFT JOIN epics e ON e.id = t.epic_id LEFT JOIN sprints sp ON sp.id = t.sprint_id";

    public const SORTS = [
        'position' => 's.position, t.position, t.id',
        'backlog' => 't.backlog_position, t.id',
        'priority' => 'pr.weight IS NULL, pr.weight DESC, t.due_date IS NULL, t.due_date, t.id',
        'due' => 't.due_date IS NULL, t.due_date, pr.weight DESC, t.id',
        'created' => 't.id DESC',
        'oldest' => 't.id',
        'updated' => 't.updated_at DESC',
        'title' => 't.title',
        'status' => 's.position, t.position',
        'assignee' => 'a.name IS NULL, a.name, t.id',
        'key' => 'p.project_key, t.number',
    ];

    /** Query tasks visible to the current user. */
    public static function query(array $f = []): array
    {
        [$vis, $params] = Policy::visibleProjectsSql('p');
        $w = [$vis, 't.deleted_at IS NULL'];
        if (empty($f['include_archived'])) $w[] = 'p.archived_at IS NULL';
        if (!empty($f['project_id'])) { $w[] = 't.project_id = ?'; $params[] = (int)$f['project_id']; }
        if (!empty($f['project_ids'])) { $ids = array_map('intval', $f['project_ids']); $w[] = 't.project_id IN (' . Database::in($ids) . ')'; array_push($params, ...$ids); }
        if (!array_key_exists('parent_id', $f) && empty($f['with_subtasks'])) $w[] = 't.parent_id IS NULL';
        if (!empty($f['parent_id'])) { $w[] = 't.parent_id = ?'; $params[] = (int)$f['parent_id']; }
        if (isset($f['assignee']) && $f['assignee'] !== '') {
            $a = (string)$f['assignee'];
            if ($a === 'none') $w[] = 't.assignee_id IS NULL AND t.team_id IS NULL';
            elseif (str_starts_with($a, 'team:')) { $w[] = 't.team_id = ?'; $params[] = (int)substr($a, 5); }
            else {
                // A person is responsible as main assignee, as co-assignee, or through the task's team.
                $uid = $a === 'me' ? Auth::id() : (int)$a;
                $w[] = '(t.assignee_id = ? OR EXISTS (SELECT 1 FROM task_assignees tax WHERE tax.task_id = t.id AND tax.user_id = ?)'
                    . ' OR (t.team_id IS NOT NULL AND EXISTS (SELECT 1 FROM team_members tmx WHERE tmx.team_id = t.team_id AND tmx.user_id = ?)))';
                array_push($params, $uid, $uid, $uid);
            }
        }
        if (!empty($f['status_id'])) { $w[] = 't.status_id = ?'; $params[] = (int)$f['status_id']; }
        if (!empty($f['category'])) {
            $cats = (array)$f['category'];
            $w[] = 's.category IN (' . Database::in($cats) . ')';
            array_push($params, ...$cats);
        }
        if (!empty($f['priority_id'])) { $w[] = 't.priority_id = ?'; $params[] = (int)$f['priority_id']; }
        if (!empty($f['label_id'])) { $w[] = 'EXISTS (SELECT 1 FROM task_labels tl WHERE tl.task_id = t.id AND tl.label_id = ?)'; $params[] = (int)$f['label_id']; }
        if (!empty($f['epic_id'])) { $w[] = 't.epic_id = ?'; $params[] = (int)$f['epic_id']; }
        if (isset($f['sprint_id']) && $f['sprint_id'] !== '') {
            if ($f['sprint_id'] === 'none') $w[] = 't.sprint_id IS NULL';
            else { $w[] = 't.sprint_id = ?'; $params[] = (int)$f['sprint_id']; }
        }
        if (!empty($f['blocked'])) $w[] = 't.is_blocked = 1';
        if (!empty($f['overdue'])) { $w[] = "t.due_date < ? AND s.category <> 'done'"; $params[] = today(); }
        if (!empty($f['due_on'])) { $w[] = 't.due_date = ?'; $params[] = $f['due_on']; } // 1.11: «Hoy» in Mi trabajo
        if (!empty($f['due_from'])) { $w[] = 'COALESCE(t.due_date, t.start_date) >= ?'; $params[] = $f['due_from']; }
        if (!empty($f['due_to'])) { $w[] = 'COALESCE(t.start_date, t.due_date) <= ?'; $params[] = $f['due_to']; }
        if (!empty($f['has_date'])) $w[] = '(t.due_date IS NOT NULL OR t.start_date IS NOT NULL)';
        if (!empty($f['completed_since'])) { $w[] = 't.completed_at >= ?'; $params[] = $f['completed_since']; }
        if (!empty($f['q'])) {
            $q = trim((string)$f['q']);
            if (preg_match('/^([A-Za-z][A-Za-z0-9]{1,5})-(\d+)$/', $q, $m)) {
                $w[] = '(p.project_key = ? AND t.number = ?)'; $params[] = strtoupper($m[1]); $params[] = (int)$m[2];
            } else {
                $w[] = '(t.title LIKE ? OR t.description LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%";
            }
        }
        $order = self::SORTS[$f['sort'] ?? 'position'] ?? self::SORTS['position'];
        if (($f['dir'] ?? '') === 'desc') $order = preg_replace('/,.*$/', '', $order) . ' DESC';
        $limit = min((int)($f['limit'] ?? 1000), 2000);
        $rows = Database::get()->all(self::SELECT . ' WHERE ' . implode(' AND ', $w) . " ORDER BY $order LIMIT $limit", $params);
        return self::attachLabels($rows);
    }

    /** Adds labels and co-assignees (people responsible besides the main assignee). */
    public static function attachLabels(array $rows): array
    {
        if (!$rows) return $rows;
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        $in = Database::in($ids);
        $labels = Database::get()->all("SELECT tl.task_id, l.id, l.name, l.color FROM task_labels tl JOIN labels l ON l.id = tl.label_id WHERE tl.task_id IN ($in) ORDER BY l.name", $ids);
        $by = [];
        foreach ($labels as $l) $by[$l['task_id']][] = $l;
        $co = Database::get()->all("SELECT ta.task_id, u.id, u.name, u.avatar_color FROM task_assignees ta JOIN users u ON u.id = ta.user_id WHERE ta.task_id IN ($in) ORDER BY ta.added_at, u.name", $ids);
        $byCo = [];
        foreach ($co as $c) $byCo[$c['task_id']][] = $c;
        foreach ($rows as &$r) {
            $r['labels'] = $by[$r['id']] ?? [];
            $r['co_assignees'] = $byCo[$r['id']] ?? [];
        }
        return $rows;
    }

    /** Everyone responsible for a task, for display: main assignee, co-assignees and team. */
    public static function responsibleLabel(array $t): string
    {
        $names = array_filter(array_merge([$t['assignee_name'] ?? null], array_column($t['co_assignees'] ?? [], 'name')));
        if (!empty($t['team_name'])) $names[] = 'equipo ' . $t['team_name'];
        return implode(', ', $names);
    }

    /** Ids of every person responsible (main, co-assignees and the team's members). */
    public static function responsibleIds(array $t): array
    {
        $ids = array_map('intval', array_merge([$t['assignee_id'] ?? 0], array_column($t['co_assignees'] ?? [], 'id')));
        if (!empty($t['team_id'])) $ids = array_merge($ids, array_map('intval', Database::get()->column('SELECT user_id FROM team_members WHERE team_id = ?', [$t['team_id']])));
        return array_values(array_unique(array_filter($ids)));
    }

    /** Load one task with project access check. */
    public static function find(int $id): array
    {
        $t = Database::get()->one(self::SELECT . ' WHERE t.id = ? AND p.workspace_id = ? AND t.deleted_at IS NULL', [$id, Policy::wsId()]);
        if (!$t) throw HttpException::notFound('La tarea no existe o fue eliminada.');
        $p = Projects::find((int)$t['project_id']);
        $t['project'] = $p;
        return self::attachLabels([$t])[0];
    }

    public static function findByKey(string $key): array
    {
        if (!preg_match('/^([A-Za-z][A-Za-z0-9]{1,5})-(\d+)$/', $key, $m)) throw HttpException::notFound('La tarea no existe.');
        $id = Database::get()->value('SELECT t.id FROM tasks t JOIN projects p ON p.id = t.project_id WHERE p.workspace_id = ? AND p.project_key = ? AND t.number = ?', [Policy::wsId(), strtoupper($m[1]), (int)$m[2]]);
        // Initials renamed later: the old key still resolves to the same task.
        $id = $id ?: Database::get()->value('SELECT t.id FROM tasks t JOIN project_key_aliases a ON a.project_id = t.project_id WHERE a.workspace_id = ? AND a.old_key = ? AND t.number = ?', [Policy::wsId(), strtoupper($m[1]), (int)$m[2]]);
        if (!$id) throw HttpException::notFound('La tarea no existe o fue eliminada.');
        return self::find((int)$id);
    }

    /** 1.14 · kinds of quick work (and of unplanned work in any project). */
    public const WORK_TYPES = ['request' => 'Solicitud imprevista', 'urgent' => 'Urgente', 'admin' => 'Administrativa', 'weekly' => 'De la semana', 'short' => 'Corta', 'other' => 'Otra'];

    private static function rules(bool $creating): array
    {
        $r = [
            'title' => 'required,string,max:255', 'description' => 'nullable,string,max:60000', 'status_id' => 'nullable,int', 'priority_id' => 'nullable,int',
            'assignee_id' => 'nullable,int', 'team_id' => 'nullable,int', 'due_date' => 'nullable,date', 'due_time' => 'nullable,time', 'start_date' => 'nullable,date', 'epic_id' => 'nullable,int', 'sprint_id' => 'nullable,int',
            'estimate' => 'nullable,number', 'estimate_hours' => 'nullable,number', 'remaining_hours' => 'nullable,number', 'is_blocked' => 'nullable,bool', 'blocked_reason' => 'nullable,string,max:255', 'parent_id' => 'nullable,int',
            'drive_url' => 'nullable,string,max:500', 'github_url' => 'nullable,string,max:500',
            'is_milestone' => 'nullable,bool', 'unplanned' => 'nullable,bool', 'work_type' => 'nullable,in:' . implode('|', array_keys(self::WORK_TYPES)),
        ];
        if (!$creating) {
            unset($r['parent_id']);
            $r = array_map(fn($x) => 'sometimes,' . $x, $r);
        }
        return $r;
    }

    /** Validate references against the task's project; never trust ids from the client. */
    private static function checkRefs(array $p, array &$v): void
    {
        $db = Database::get();
        $pid = (int)$p['id'];
        if (!empty($v['status_id'])) Projects::status($pid, (int)$v['status_id']);
        if (!empty($v['priority_id']) && !$db->value('SELECT 1 FROM priorities WHERE id = ? AND workspace_id = ?', [$v['priority_id'], Policy::wsId()])) throw HttpException::invalid(['priority_id' => 'Prioridad no válida.']);
        if (!empty($v['epic_id']) && !$db->value('SELECT 1 FROM epics WHERE id = ? AND project_id = ?', [$v['epic_id'], $pid])) throw HttpException::invalid(['epic_id' => 'Épica no válida.']);
        if (!empty($v['sprint_id']) && !$db->value("SELECT 1 FROM sprints WHERE id = ? AND project_id = ? AND state <> 'completed'", [$v['sprint_id'], $pid])) throw HttpException::invalid(['sprint_id' => 'Sprint no válido o ya finalizado.']);
        if (!empty($v['assignee_id'])) self::checkAssignable($p, (int)$v['assignee_id'], 'assignee_id');
        if (!empty($v['team_id'])) {
            $tid = (int)$v['team_id'];
            $team = $db->one('SELECT id, name, archived_at FROM teams WHERE id = ? AND workspace_id = ?', [$tid, Policy::wsId()]);
            if (!$team || $team['archived_at']) throw HttpException::invalid(['team_id' => 'Equipo no válido.']);
            if (!in_array($tid, array_map('intval', array_column(Projects::accessTeams($p), 'id')), true)) {
                if (!Policy::canProject($p, 'project.manage')) throw HttpException::invalid(['team_id' => 'Ese equipo no participa en el proyecto. Pide a un responsable que lo añada.']);
                // A manager giving work to a new team adds the team to the project as contributor.
                $db->run('INSERT INTO project_teams (project_id, team_id, role_id, added_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE role_id = role_id', [$pid, $tid, Policy::projectRoleId('contributor'), now()]);
                Activity::log('team_added', 'project', $pid, "Añadió el equipo «{$team['name']}» al proyecto al asignarle una tarea", $pid);
            }
        }
        foreach (['estimate' => 'La estimación', 'estimate_hours' => 'Las horas estimadas'] as $k => $label) {
            if (isset($v[$k]) && $v[$k] !== null && $v[$k] !== '' && ((float)$v[$k] < 0 || (float)$v[$k] > 9999)) throw HttpException::invalid([$k => "$label debe estar entre 0 y 9999."]);
        }
        $start = $v['start_date'] ?? null;
        $due = $v['due_date'] ?? null;
        if (!empty($v['due_time']) && !$due) throw HttpException::invalid(['due_time' => 'Pon primero la fecha de entrega.']);
        if ($start && $due && $due < $start) throw HttpException::invalid(['due_date' => 'El vencimiento no puede ser anterior al inicio.']);
    }

    /** A person can be responsible if they can edit tasks in the project; a manager may add them. */
    private static function checkAssignable(array $p, int $uid, string $field): void
    {
        $pid = (int)$p['id'];
        $assignable = array_map('intval', array_column(Projects::assignableUsers($p), 'id'));
        if (in_array($uid, $assignable, true)) return;
        if (!Workspaces::isActiveMember($uid)) throw HttpException::invalid([$field => 'La persona no pertenece al espacio.']);
        if (!Policy::canProject($p, 'project.manage')) throw HttpException::invalid([$field => 'Esa persona no participa en el proyecto. Pide a un responsable que la añada.']);
        // A manager assigning someone new adds them to the project as contributor.
        Database::get()->run('INSERT INTO project_members (project_id, user_id, role_id, added_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE role_id = role_id', [$pid, $uid, Policy::projectRoleId('contributor'), now()]);
        Activity::log('member_added', 'project', $pid, 'Añadió a ' . (Users::find($uid)['name'] ?? '') . ' al proyecto al asignarle una tarea', $pid);
    }

    /**
     * Replaces everyone responsible for a task. The first id becomes the main assignee
     * (shown on cards and used by older screens); the rest are co-assignees.
     */
    public static function setAssignees(int $id, array $userIds): array
    {
        $t = self::find($id);
        Policy::authorizeProject($t['project'], 'task.edit');
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (count($userIds) > 20) throw HttpException::invalid(['assignee_ids' => 'Máximo 20 responsables por tarea.']);
        foreach ($userIds as $uid) self::checkAssignable($t['project'], $uid, 'assignee_ids');
        $before = self::responsibleIdsDirect($t);
        $main = $userIds[0] ?? null;
        $db = Database::get();
        $db->transaction(function (Database $db) use ($t, $id, $userIds, $main) {
            Capacity::guard($t, $t['sprint_id'] ? (int)$t['sprint_id'] : null, $t['estimate_hours'] !== null ? (float)$t['estimate_hours'] : null, $userIds);
            $db->update('tasks', ['assignee_id' => $main, 'updated_at' => now()], 'id = ?', [$id]);
            $db->run('DELETE FROM task_assignees WHERE task_id = ?', [$id]);
            foreach (array_slice($userIds, 1) as $uid) $db->insert('task_assignees', ['task_id' => $id, 'user_id' => $uid, 'added_at' => now()]);
        });
        $added = array_diff($userIds, $before);
        $pid = (int)$t['project_id'];
        $logTask = $t['parent_id'] ? (int)$t['parent_id'] : $id;
        $names = array_map(fn($u) => Users::find($u)['name'] ?? '', $userIds);
        Activity::log('assigned', 'task', $id, $names ? "Asignó {$t['task_key']} a " . implode(', ', $names) : "Quitó los responsables de {$t['task_key']}", $pid, $logTask);
        foreach ($added as $uid) {
            if ($uid !== Auth::id()) Notifications::send($uid, 'assigned', "Te asignaron {$t['task_key']}: {$t['title']}", url('/t/' . $t['task_key']), $pid, $id);
        }
        foreach (array_diff($before, $userIds) as $uid) self::notifyUnassigned($t, (int)$uid);
        GoogleExport::touchTask($id);
        return self::find($id);
    }

    /** 1.11: «Ya no eres responsable de …», once per change; not for finished tasks, not to whoever did it. */
    private static function notifyUnassigned(array $t, int $uid): void
    {
        if (($t['status_category'] ?? '') === 'done') return;
        Notifications::send($uid, 'assigned', "Ya no eres responsable de {$t['task_key']}: {$t['title']}", url('/t/' . $t['task_key']), (int)$t['project_id'], (int)$t['id']);
    }

    public static function responsibleIdsDirect(array $t): array
    {
        return array_values(array_filter(array_map('intval', array_merge([$t['assignee_id'] ?? 0], array_column($t['co_assignees'] ?? [], 'id')))));
    }

    /** Adds one person to the task's responsibles (used when a person is dropped on a card). */
    public static function addAssignee(int $id, int $userId): array
    {
        $t = self::find($id);
        $ids = self::responsibleIdsDirect($t);
        if (in_array($userId, $ids, true)) return $t;
        $ids[] = $userId;
        return self::setAssignees($id, $ids);
    }

    /**
     * People can be dragged between cards on the board only when the project is marked as
     * polivalente, or the person belongs to a polivalente team that takes part in the project.
     */
    public static function canMovePerson(array $p, int $userId): bool
    {
        if ((int)($p['is_flexible'] ?? 0)) return true;
        $flexTeams = array_map('intval', array_column(array_filter(Projects::accessTeams($p), fn($t) => (int)$t['is_flexible']), 'id'));
        if (!$flexTeams) return false;
        return (bool)Database::get()->value('SELECT 1 FROM team_members WHERE user_id = ? AND team_id IN (' . Database::in($flexTeams) . ')', array_merge([$userId], $flexTeams));
    }

    /** Board drag and drop: a person dropped on a card joins its responsibles; from another card, they move. */
    public static function movePerson(int $toTaskId, int $userId, ?int $fromTaskId): array
    {
        $to = self::find($toTaskId);
        Policy::authorizeProject($to['project'], 'task.edit');
        if (!self::canMovePerson($to['project'], $userId)) {
            throw HttpException::conflict('Para mover personas en el tablero, marca el proyecto o el equipo de esta persona como polivalente (Ajustes del proyecto o del equipo).');
        }
        // Both halves of a move happen together: if the person has no hours for the new task,
        // they stay where they were.
        return Database::get()->transaction(function () use ($to, $toTaskId, $userId, $fromTaskId) {
            if ($fromTaskId && $fromTaskId !== $toTaskId) {
                $from = self::find($fromTaskId);
                if ((int)$from['project_id'] !== (int)$to['project_id']) throw HttpException::invalid(['from_task_id' => 'Solo se puede mover entre tareas del mismo proyecto.']);
                $ids = self::responsibleIdsDirect($from);
                if (in_array($userId, $ids, true)) self::setAssignees($fromTaskId, array_values(array_diff($ids, [$userId])));
            }
            return self::addAssignee($toTaskId, $userId);
        });
    }

    /** Board drag and drop: a person dragged out of a card stops being responsible for it. */
    public static function removePerson(int $taskId, int $userId): array
    {
        $t = self::find($taskId);
        Policy::authorizeProject($t['project'], 'task.edit');
        $ids = self::responsibleIdsDirect($t);
        if (!in_array($userId, $ids, true)) return $t;
        return self::setAssignees($taskId, array_values(array_diff($ids, [$userId])));
    }

    public static function create(int $projectId, array $input): array
    {
        $p = Projects::find($projectId);
        Policy::authorizeProject($p, 'task.create');
        $v = Validator::check($input, self::rules(true));
        self::normalizeLinks($v);
        $db = Database::get();
        if (!empty($v['parent_id'])) {
            $parent = $db->one('SELECT id, project_id, parent_id, sprint_id, epic_id FROM tasks WHERE id = ? AND deleted_at IS NULL', [$v['parent_id']]);
            if (!$parent || (int)$parent['project_id'] !== $projectId) throw HttpException::invalid(['parent_id' => 'Tarea principal no válida.']);
            if ($parent['parent_id']) throw HttpException::invalid(['parent_id' => 'Las subtareas no pueden tener subtareas.']);
        }
        self::checkRefs($p, $v);
        $statusId = !empty($v['status_id']) ? (int)$v['status_id'] : (int)(Projects::firstStatus($projectId, 'todo') ?? Projects::statuses($projectId)[0])['id'];
        $status = Projects::status($projectId, $statusId);

        $id = $db->transaction(function (Database $db) use ($p, $v, $statusId, $status, $projectId, $input) {
            $people = !empty($input['assignee_ids']) && is_array($input['assignee_ids']) ? array_map('intval', $input['assignee_ids']) : [];
            if (!empty($v['assignee_id'])) array_unshift($people, (int)$v['assignee_id']);
            Capacity::guard(['id' => 0, 'parent_id' => $v['parent_id'] ?? null], !empty($v['sprint_id']) ? (int)$v['sprint_id'] : null, isset($v['estimate_hours']) && $v['estimate_hours'] !== null ? (float)$v['estimate_hours'] : null, $people);
            $seq = (int)$db->value('SELECT task_seq FROM projects WHERE id = ? FOR UPDATE', [$projectId]) + 1;
            $db->update('projects', ['task_seq' => $seq, 'updated_at' => now()], 'id = ?', [$projectId]);
            $pos = (float)$db->value('SELECT COALESCE(MAX(position), 0) FROM tasks WHERE status_id = ?', [$statusId]) + 1024;
            $bpos = (float)$db->value('SELECT COALESCE(MAX(backlog_position), 0) FROM tasks WHERE project_id = ?', [$projectId]) + 1024;
            return $db->insert('tasks', [
                'project_id' => $projectId, 'number' => $seq, 'parent_id' => $v['parent_id'] ?? null, 'title' => $v['title'], 'description' => $v['description'],
                'status_id' => $statusId, 'priority_id' => $v['priority_id'] ?: null, 'assignee_id' => $v['assignee_id'] ?: null, 'team_id' => ($v['team_id'] ?? null) ?: null, 'reporter_id' => Auth::id(),
                'epic_id' => $v['epic_id'] ?: null, 'sprint_id' => $v['sprint_id'] ?: null, 'estimate' => $v['estimate'], 'estimate_hours' => $v['estimate_hours'] ?? null, 'start_date' => $v['start_date'], 'due_date' => $v['due_date'], 'due_time' => ($v['due_date'] ?? null) ? (($v['due_time'] ?? null) ?: null) : null,
                'position' => $pos, 'backlog_position' => $bpos, 'is_blocked' => !empty($v['is_blocked']) ? 1 : 0, 'blocked_reason' => $v['blocked_reason'],
                'drive_url' => $v['drive_url'] ?? null, 'github_url' => $v['github_url'] ?? null,
                // 1.14: original plan, who estimated, milestone and unplanned work
                'baseline_start' => $v['start_date'] ?: null, 'baseline_due' => $v['due_date'] ?: null,
                'admin_estimate_hours' => isset($v['estimate_hours']) && $v['estimate_hours'] !== null && $v['estimate_hours'] !== '' && TaskEstimates::canManage(['project' => $p]) ? $v['estimate_hours'] : null,
                'worker_estimate_hours' => isset($v['estimate_hours']) && $v['estimate_hours'] !== null && $v['estimate_hours'] !== '' && !TaskEstimates::canManage(['project' => $p]) ? $v['estimate_hours'] : null,
                'is_milestone' => !empty($v['is_milestone']) ? 1 : 0, 'unplanned' => (!empty($v['unplanned']) || in_array($v['work_type'] ?? null, ['urgent', 'request'], true)) ? 1 : 0,
                'work_type' => !empty($v['work_type']) ? $v['work_type'] : null,
                'started_at' => $status['category'] !== 'todo' ? now() : null, 'blocked_at' => !empty($v['is_blocked']) ? now() : null, 'blocked_by' => !empty($v['is_blocked']) ? Auth::id() : null,
                'remaining_hours' => isset($v['remaining_hours']) && $v['remaining_hours'] !== '' ? $v['remaining_hours'] : null,
                'completed_at' => $status['category'] === 'done' ? now() : null, 'completed_due_date' => $status['category'] === 'done' ? ($v['due_date'] ?? null) : null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        $t = self::find($id);
        if ($t['sprint_id'] && !$t['parent_id']) Monitoring::trackSprintTask((int)$t['sprint_id'], $t);
        if (!empty($input['label_ids']) && is_array($input['label_ids'])) self::setLabels($id, $input['label_ids']);
        // 1.14: the first estimate opens the estimate history (administration or worker, and the agreed one)
        if (isset($v['estimate_hours']) && $v['estimate_hours'] !== null && $v['estimate_hours'] !== '') {
            TaskEstimates::record($id, TaskEstimates::canManage(['project' => $p]) ? 'admin' : 'worker', null, (float)$v['estimate_hours'], null);
            TaskEstimates::record($id, 'agreed', null, (float)$v['estimate_hours'], 'Estimación al crear la tarea');
        }
        Activity::log('created', 'task', $id, ($t['parent_id'] ? 'Creó la subtarea ' : 'Creó la tarea ') . "{$t['task_key']} «{$t['title']}»", $projectId, $t['parent_id'] ? (int)$t['parent_id'] : $id);
        GoogleExport::touchTask($id);
        if ($t['assignee_id']) {
            Notifications::send((int)$t['assignee_id'], 'assigned', "Te asignaron {$t['task_key']}: {$t['title']}", url('/t/' . $t['task_key']), $projectId, $id);
        }
        if (!empty($input['assignee_ids']) && is_array($input['assignee_ids'])) {
            $ids = array_map('intval', $input['assignee_ids']);
            if ($t['assignee_id'] && !in_array((int)$t['assignee_id'], $ids, true)) array_unshift($ids, (int)$t['assignee_id']);
            self::setAssignees($id, $ids);
        }
        if (!empty($t['team_id'])) self::notifyTeam($t, (int)$t['team_id']);
        return self::find($id);
    }

    private const FIELD_LABELS = [
        'title' => 'el título', 'description' => 'la descripción', 'status_id' => 'el estado', 'priority_id' => 'la prioridad', 'assignee_id' => 'el responsable', 'team_id' => 'el equipo responsable',
        'due_date' => 'el vencimiento', 'due_time' => 'la hora de entrega', 'start_date' => 'el inicio', 'epic_id' => 'la épica', 'sprint_id' => 'el sprint', 'estimate' => 'la estimación', 'estimate_hours' => 'las horas estimadas', 'remaining_hours' => 'las horas restantes',
        'is_blocked' => 'el bloqueo', 'blocked_reason' => 'el motivo del bloqueo', 'is_milestone' => 'el hito', 'unplanned' => 'si es imprevista', 'work_type' => 'el tipo de trabajo', 'drive_url' => 'la carpeta de Drive', 'github_url' => 'el repositorio de GitHub',
    ];

    public static function update(int $id, array $input): array
    {
        $t = self::find($id);
        $p = $t['project'];
        Policy::authorizeProject($p, 'task.edit');
        $v = Validator::check($input, self::rules(false));
        self::normalizeLinks($v);
        // 1.14: optional reason of the change, kept in the audit log (never in the task itself)
        self::$reason = isset($input['change_reason']) && is_string($input['change_reason']) ? mb_substr(trim($input['change_reason']), 0, 500) : null;
        if (!$v && !array_key_exists('label_ids', $input) && !array_key_exists('assignee_ids', $input)) return $t;
        // 1.9: removing the date removes the hour too; an hour alone needs a date
        if (array_key_exists('due_date', $v) && !$v['due_date']) $v['due_time'] = null;
        if (array_key_exists('due_time', $v)) $v['due_time'] = $v['due_time'] ? substr((string)$v['due_time'], 0, 5) . ':00' : null;
        $merged = $v + ['start_date' => $t['start_date'], 'due_date' => $t['due_date'], 'due_time' => $t['due_time'] ?? null];
        foreach (['status_id', 'priority_id', 'epic_id', 'sprint_id', 'assignee_id', 'team_id'] as $k) if (!array_key_exists($k, $merged)) $merged[$k] = null;
        self::checkRefs($p, $merged);
        $data = [];
        foreach ($v as $k => $val) {
            if ($k === 'is_blocked' || $k === 'is_milestone' || $k === 'unplanned') $val = $val ? 1 : 0;
            if (in_array($k, ['priority_id', 'assignee_id', 'team_id', 'epic_id', 'sprint_id'], true)) $val = $val ?: null;
            if ($k === 'status_id' && !$val) continue;
            if ((string)$val !== (string)($t[$k] ?? '')) $data[$k] = $val;
        }
        if (isset($data['is_blocked']) && !$data['is_blocked']) $data['blocked_reason'] = null;
        if (array_key_exists('remaining_hours', $data) && $data['remaining_hours'] !== null && $data['remaining_hours'] !== '' && ((float)$data['remaining_hours'] < 0 || (float)$data['remaining_hours'] > 9999)) throw HttpException::invalid(['remaining_hours' => 'Indica las horas restantes entre 0 y 9999.']);
        if (array_key_exists('remaining_hours', $data) && $data['remaining_hours'] === '') $data['remaining_hours'] = null;
        // 1.12: when and by whom it was blocked (cleared when unblocked)
        if (isset($data['is_blocked'])) { $data['blocked_at'] = $data['is_blocked'] ? now() : null; $data['blocked_by'] = $data['is_blocked'] ? Auth::id() : null; }
        if (isset($data['status_id'])) {
            $s = Projects::status((int)$p['id'], (int)$data['status_id']);
            $data['completed_at'] = $s['category'] === 'done' ? ($t['completed_at'] ?: now()) : null;
            // 1.11: the due date the task had when it was finished stays with it (on time / late)
            $data['completed_due_date'] = $s['category'] === 'done' ? ($t['completed_at'] ? ($t['completed_due_date'] ?? null) : (array_key_exists('due_date', $data) ? $data['due_date'] : $t['due_date'])) : null;
            $data['position'] = (float)Database::get()->value('SELECT COALESCE(MAX(position), 0) FROM tasks WHERE status_id = ?', [$s['id']]) + 1024;
            if ($s['category'] !== 'todo' && empty($t['started_at'])) $data['started_at'] = now(); // 1.12: real start
        }
        Database::get()->transaction(function () use ($t, $id, $data, $input) {
            // Sprint hours: check the task as it will be after every part of this change.
            if (array_intersect(['sprint_id', 'estimate_hours', 'assignee_id'], array_keys($data)) || array_key_exists('assignee_ids', $input)) {
                if (array_key_exists('assignee_ids', $input)) $people = array_map('intval', (array)($input['assignee_ids'] ?? []));
                else {
                    $people = self::responsibleIdsDirect($t);
                    if (array_key_exists('assignee_id', $data)) {
                        $co = array_map('intval', array_column($t['co_assignees'] ?? [], 'id'));
                        $people = array_merge($data['assignee_id'] ? [(int)$data['assignee_id']] : [], array_diff($co, [(int)$data['assignee_id']]));
                    }
                }
                $sprint = array_key_exists('sprint_id', $data) ? $data['sprint_id'] : $t['sprint_id'];
                $hours = array_key_exists('estimate_hours', $data) ? $data['estimate_hours'] : $t['estimate_hours'];
                Capacity::guard($t, $sprint ? (int)$sprint : null, $hours !== null && $hours !== '' ? (float)$hours : null, $people);
            }
            if ($data) {
                // 1.14: the first dates a task gets are its original plan (baseline for the Gantt)
                if (array_key_exists('due_date', $data) && $data['due_date'] && empty($t['baseline_due'])) $data['baseline_due'] = $data['due_date'];
                if (array_key_exists('start_date', $data) && $data['start_date'] && empty($t['baseline_start'])) $data['baseline_start'] = $data['start_date'];
                Database::get()->update('tasks', $data + ['updated_at' => now()], 'id = ?', [$id]);
                if (array_key_exists('estimate_hours', $data)) TaskEstimates::editedInline($t, $data['estimate_hours'] !== null && $data['estimate_hours'] !== '' ? (float)$data['estimate_hours'] : null);
                self::afterChange($t, $data);
            }
            if (array_key_exists('label_ids', $input)) self::setLabels($id, (array)$input['label_ids']);
            if (array_key_exists('assignee_ids', $input)) {
                self::setAssignees($id, (array)($input['assignee_ids'] ?? []));
            } elseif (array_key_exists('assignee_id', $data) && $data['assignee_id']) {
                // Changing the main assignee: never leave them also listed as co-assignee.
                Database::get()->run('DELETE FROM task_assignees WHERE task_id = ? AND user_id = ?', [$id, $data['assignee_id']]);
            }
        });
        return self::find($id);
    }

    /** 1.10: the two work links of a task, checked and written in one canonical form. */
    private const LINK_KINDS = [
        'drive_url' => ['hosts' => ['drive.google.com', 'docs.google.com'], 'msg' => 'Pega un enlace de Google Drive (https://drive.google.com/…).'],
        'github_url' => ['hosts' => ['github.com'], 'msg' => 'Pega el enlace de un repositorio de GitHub (https://github.com/organización/repositorio).'],
    ];

    private static function normalizeLinks(array &$v): void
    {
        foreach (array_keys(self::LINK_KINDS) as $k) {
            if (!array_key_exists($k, $v)) continue;
            $url = self::normalizeLink($k, (string)($v[$k] ?? ''));
            if ($url === false) throw HttpException::invalid([$k => self::LINK_KINDS[$k]['msg']]);
            $v[$k] = $url;
        }
    }

    /**
     * Returns the link as https://…, null when empty, or false when it is not a link of that kind.
     * Accepts what people usually paste: without https://, with http://, www.github.com, and the
     * git@github.com:org/repo.git form of «Code › SSH».
     */
    public static function normalizeLink(string $kind, string $raw): string|null|false
    {
        $u = trim($raw);
        if ($u === '') return null;
        if (!isset(self::LINK_KINDS[$kind]) || mb_strlen($u) > 500 || preg_match('/[\s<>"\'`\\\\]/u', $u)) return false;
        if ($kind === 'github_url' && preg_match('#^git@github\.com:([^/]+/[^/]+?)(?:\.git)?/?$#i', $u, $m)) $u = 'https://github.com/' . $m[1];
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $u)) $u = 'https://' . $u;
        $p = parse_url($u);
        if (!$p || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || isset($p['user']) || isset($p['pass']) || isset($p['port'])) return false;
        $host = strtolower($p['host']);
        if (str_starts_with($host, 'www.')) $host = substr($host, 4);
        if (!in_array($host, self::LINK_KINDS[$kind]['hosts'], true)) return false;
        $path = $p['path'] ?? '';
        if ($kind === 'github_url') {
            // At least organization/repository; deeper paths (a branch, a folder, an issue) are kept.
            if (!preg_match('#^/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+#', $path)) return false;
            $path = preg_replace('#\.git$#', '', rtrim($path, '/'));
        } elseif ($path === '' || $path === '/') return false;
        $out = 'https://' . $host . $path . (isset($p['query']) ? '?' . $p['query'] : '') . (isset($p['fragment']) ? '#' . $p['fragment'] : '');
        return mb_strlen($out) > 500 ? false : $out;
    }

    /** Lets the members of a team know the team was given a task. */
    private static function notifyTeam(array $t, int $teamId): void
    {
        foreach (Database::get()->column('SELECT user_id FROM team_members WHERE team_id = ?', [$teamId]) as $uid) {
            if ((int)$uid === Auth::id()) continue;
            Notifications::send((int)$uid, 'assigned', "Asignaron a tu equipo {$t['task_key']}: {$t['title']}", url('/t/' . $t['task_key']), (int)$t['project_id'], (int)$t['id']);
        }
    }

    /** 1.14: reason given for the change being saved (audit). */
    private static ?string $reason = null;

    /** Activity + notifications for a set of changed columns. */
    private static function afterChange(array $t, array $data): void
    {
        $why = ['reason' => self::$reason];
        $pid = (int)$t['project_id'];
        $logTask = $t['parent_id'] ? (int)$t['parent_id'] : (int)$t['id'];
        $fields = array_values(array_intersect(array_keys(self::FIELD_LABELS), array_keys($data)));
        if (isset($data['status_id'])) {
            $s = Projects::status($pid, (int)$data['status_id']);
            Activity::log('status_changed', 'task', (int)$t['id'], "Movió {$t['task_key']} a «{$s['name']}»", $pid, $logTask, ['from' => $t['status_name'], 'to' => $s['name']],
                null, $why + ['before' => ['estado' => $t['status_name']], 'after' => ['estado' => $s['name']]]);
            $fields = array_values(array_diff($fields, ['status_id']));
            if ($s['category'] === 'done' && $t['status_category'] !== 'done' && $t['reporter_id'] && (int)$t['reporter_id'] !== (int)$t['assignee_id']) {
                Notifications::send((int)$t['reporter_id'], 'status', "{$t['task_key']} finalizada: {$t['title']}", url('/t/' . $t['task_key']), $pid, (int)$t['id']);
            }
        }
        if (array_key_exists('assignee_id', $data)) {
            $name = $data['assignee_id'] ? (Users::find((int)$data['assignee_id'])['name'] ?? '') : null;
            Activity::log('assigned', 'task', (int)$t['id'], $name ? "Asignó {$t['task_key']} a $name" : "Quitó el responsable de {$t['task_key']}", $pid, $logTask, [],
                null, $why + ['before' => ['responsable' => $t['assignee_name'] ?? null], 'after' => ['responsable' => $name]]);
            $fields = array_values(array_diff($fields, ['assignee_id']));
            if ($data['assignee_id']) {
                Notifications::send((int)$data['assignee_id'], 'assigned', "Te asignaron {$t['task_key']}: " . ($data['title'] ?? $t['title']), url('/t/' . $t['task_key']), $pid, (int)$t['id']);
            }
            // 1.11: whoever stops being the main responsible person is told too (unless they stay as co-assignee)
            $co = array_map('intval', array_column($t['co_assignees'] ?? [], 'id'));
            if ($t['assignee_id'] && (int)$t['assignee_id'] !== (int)$data['assignee_id'] && !in_array((int)$t['assignee_id'], $co, true)) self::notifyUnassigned($t, (int)$t['assignee_id']);
        }
        if (array_key_exists('team_id', $data)) {
            $tn = $data['team_id'] ? (string)Database::get()->value('SELECT name FROM teams WHERE id = ?', [$data['team_id']]) : null;
            Activity::log('assigned', 'task', (int)$t['id'], $tn ? "Asignó {$t['task_key']} al equipo $tn" : "Quitó el equipo responsable de {$t['task_key']}", $pid, $logTask);
            $fields = array_values(array_diff($fields, ['team_id']));
            if ($data['team_id']) self::notifyTeam($t, (int)$data['team_id']);
        }
        if ((array_key_exists('due_date', $data) || array_key_exists('due_time', $data)) && ($t['status_category'] ?? '') !== 'done') {
            // 1.8: the responsible people hear about a new delivery date (not for finished tasks); 1.9: also its hour
            Notifications::dueChanged($t, $t['due_date'] ?: null, array_key_exists('due_date', $data) ? ($data['due_date'] ?: null) : ($t['due_date'] ?: null),
                $t['due_time'] ?? null, array_key_exists('due_time', $data) ? $data['due_time'] : ($t['due_time'] ?? null));
        }
        if (array_key_exists('due_date', $data)) {
            // 1.12: reprogramming history (every change, also on finished tasks)
            Database::get()->insert('task_due_changes', ['task_id' => (int)$t['id'], 'old_due' => $t['due_date'] ?: null, 'new_due' => $data['due_date'] ?: null, 'changed_by' => Auth::id() ?: null, 'changed_at' => now()]);
        }
        if (!empty($data['sprint_id']) && !$t['parent_id']) Monitoring::trackSprintTask((int)$data['sprint_id'], $t);
        GoogleExport::touchTask((int)$t['id']);
        if (isset($data['is_blocked'])) {
            Activity::log($data['is_blocked'] ? 'blocked' : 'unblocked', 'task', (int)$t['id'], ($data['is_blocked'] ? 'Marcó como bloqueada ' : 'Desbloqueó ') . $t['task_key'], $pid, $logTask);
            $fields = array_values(array_diff($fields, ['is_blocked', 'blocked_reason']));
        }
        // 1.14: date changes are part of the calendar history (category «calendar»), with both values
        $dateFields = array_values(array_intersect($fields, ['start_date', 'due_date', 'due_time']));
        if ($dateFields) {
            $b = []; $a = [];
            foreach ($dateFields as $f) { $b[$f] = $t[$f] ?? null; $a[$f] = $data[$f] ?: null; }
            $fmt = fn($d) => $d ? fmt_date((string)$d) : 'sin fecha';
            $txt = isset($a['due_date']) || array_key_exists('due_date', $a)
                ? (($b['due_date'] ?? null) && ($a['due_date'] ?? null) ? "Reprogramó {$t['task_key']}: del " . $fmt($b['due_date']) . ' al ' . $fmt($a['due_date']) : "Cambió el vencimiento de {$t['task_key']}: " . $fmt($b['due_date'] ?? null) . ' → ' . $fmt($a['due_date'] ?? null))
                : 'Cambió ' . implode(', ', array_map(fn($f) => self::FIELD_LABELS[$f], $dateFields)) . " de {$t['task_key']}";
            Activity::log('due_changed', 'task', (int)$t['id'], $txt, $pid, $logTask, ['fields' => $dateFields], null, $why + ['before' => $b, 'after' => $a, 'category' => 'calendar']);
            $fields = array_values(array_diff($fields, $dateFields));
        }
        if ($fields) {
            $b = []; $a = [];
            foreach ($fields as $f) if (!in_array($f, ['description'], true)) { $b[$f] = $t[$f] ?? null; $a[$f] = $data[$f] ?? null; }
            Activity::log('updated', 'task', (int)$t['id'], "Cambió " . implode(', ', array_map(fn($f) => self::FIELD_LABELS[$f], $fields)) . " de {$t['task_key']}", $pid, $logTask, ['fields' => $fields], null, $why + ['before' => $b, 'after' => $a]);
        }
        self::$reason = null;
    }

    /** Move on the board: into $statusId, placed right after $afterId (null = top). */
    public static function move(int $id, int $statusId, ?int $afterId): array
    {
        $t = self::find($id);
        Policy::authorizeProject($t['project'], 'task.edit');
        $s = Projects::status((int)$t['project_id'], $statusId);
        $db = Database::get();
        $db->transaction(function (Database $db) use ($t, $s, $afterId, $id) {
            $pos = self::positionAfter('position', 'status_id = ?', [$s['id']], $id, $afterId);
            $data = ['status_id' => $s['id'], 'position' => $pos, 'updated_at' => now()];
            if ((int)$s['id'] !== (int)$t['status_id']) {
                $data['completed_at'] = $s['category'] === 'done' ? ($t['completed_at'] ?: now()) : null;
                $data['completed_due_date'] = $s['category'] === 'done' ? ($t['completed_at'] ? ($t['completed_due_date'] ?? null) : $t['due_date']) : null;
                if ($s['category'] !== 'todo' && empty($t['started_at'])) $data['started_at'] = now();
            }
            $db->update('tasks', $data, 'id = ?', [$id]);
        });
        if ((int)$s['id'] !== (int)$t['status_id']) self::afterChange($t, ['status_id' => $s['id']]);
        return self::find($id);
    }

    /** Reorder in the backlog: placed right after $afterId (null = top). */
    public static function reorderBacklog(int $id, ?int $afterId): void
    {
        $t = self::find($id);
        Policy::authorizeProject($t['project'], 'task.edit');
        $pos = self::positionAfter('backlog_position', 'project_id = ? AND parent_id IS NULL', [$t['project_id']], $id, $afterId);
        Database::get()->update('tasks', ['backlog_position' => $pos, 'updated_at' => now()], 'id = ?', [$id]);
    }

    /** Fractional positioning with automatic renormalisation when the gap gets too small. */
    private static function positionAfter(string $col, string $scope, array $params, int $selfId, ?int $afterId): float
    {
        $db = Database::get();
        $rows = $db->all("SELECT id, $col AS pos FROM tasks WHERE $scope AND deleted_at IS NULL AND id <> ? ORDER BY $col, id", array_merge($params, [$selfId]));
        $idx = -1;
        if ($afterId) {
            foreach ($rows as $i => $r) if ((int)$r['id'] === $afterId) { $idx = $i; break; }
            if ($idx === -1) throw HttpException::invalid(['after_id' => 'La tarjeta de referencia no está en esa columna.']);
        }
        $prev = $idx >= 0 ? (float)$rows[$idx]['pos'] : null;
        $next = isset($rows[$idx + 1]) ? (float)$rows[$idx + 1]['pos'] : null;
        if ($prev === null && $next === null) return 1024;
        if ($prev === null) return $next - 1024;
        if ($next === null) return $prev + 1024;
        if ($next - $prev < 0.0001) {
            foreach ($rows as $i => $r) $db->update('tasks', [$col => ($i + 1) * 1024], 'id = ?', [$r['id']]);
            return ($idx + 1) * 1024 + 512;
        }
        return ($prev + $next) / 2;
    }

    /** Toggle done from My Work / subtasks: first done status, or back to first todo. */
    public static function setDone(int $id, bool $done): array
    {
        $t = self::find($id);
        $s = Projects::firstStatus((int)$t['project_id'], $done ? 'done' : 'todo') ?? Projects::statuses((int)$t['project_id'])[0];
        return self::update($id, ['status_id' => $s['id']]);
    }

    public static function duplicate(int $id): array
    {
        $t = self::find($id);
        Policy::authorizeProject($t['project'], 'task.create');
        $copy = self::create((int)$t['project_id'], [
            'title' => mb_substr('Copia de ' . $t['title'], 0, 255), 'description' => $t['description'], 'status_id' => $t['status_id'],
            'priority_id' => $t['priority_id'], 'assignee_id' => $t['assignee_id'], 'team_id' => $t['team_id'], 'due_date' => $t['due_date'], 'start_date' => $t['start_date'],
            'epic_id' => $t['epic_id'], 'estimate' => $t['estimate'], 'parent_id' => $t['parent_id'],
            'drive_url' => $t['drive_url'] ?? null, 'github_url' => $t['github_url'] ?? null,
            'assignee_ids' => $t['co_assignees'] ? array_merge([(int)$t['assignee_id']], array_map('intval', array_column($t['co_assignees'], 'id'))) : [],
            'label_ids' => array_column($t['labels'], 'id'),
        ]);
        foreach (self::query(['parent_id' => $id, 'project_id' => $t['project_id'], 'sort' => 'oldest']) as $sub) {
            self::create((int)$t['project_id'], ['title' => $sub['title'], 'parent_id' => $copy['id'], 'status_id' => $sub['status_id'], 'assignee_id' => $sub['assignee_id'], 'priority_id' => $sub['priority_id']]);
        }
        return self::find((int)$copy['id']);
    }

    /** Soft delete (recoverable from the database); subtasks go with their parent. */
    public static function delete(int $id): void
    {
        $t = self::find($id);
        Policy::authorizeProject($t['project'], 'task.delete');
        $db = Database::get();
        $db->run('UPDATE tasks SET deleted_at = ? WHERE (id = ? OR parent_id = ?) AND deleted_at IS NULL', [now(), $id, $id]);
        Activity::log('deleted', 'task', $id, "Eliminó {$t['task_key']} «{$t['title']}»", (int)$t['project_id'], $t['parent_id'] ? (int)$t['parent_id'] : null);
        GoogleExport::touchTask($id);
    }

    public static function setLabels(int $id, array $labelIds): void
    {
        $t = self::find($id);
        Policy::authorizeProject($t['project'], 'task.edit');
        $labelIds = array_values(array_unique(array_filter(array_map('intval', $labelIds))));
        $db = Database::get();
        $valid = $labelIds ? array_map('intval', $db->column('SELECT id FROM labels WHERE project_id = ? AND id IN (' . Database::in($labelIds) . ')', array_merge([$t['project_id']], $labelIds))) : [];
        $db->transaction(function (Database $db) use ($id, $valid) {
            $db->run('DELETE FROM task_labels WHERE task_id = ?', [$id]);
            foreach ($valid as $l) $db->insert('task_labels', ['task_id' => $id, 'label_id' => $l]);
        });
    }

    /** Bulk actions from list/backlog. Each task is checked individually. */
    public static function bulk(array $ids, string $action, mixed $value): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) throw HttpException::invalid(['ids' => 'Selecciona al menos una tarea.']);
        if (count($ids) > 500) throw HttpException::invalid(['ids' => 'Máximo 500 tareas por operación.']);
        $field = match ($action) {
            'status' => 'status_id', 'assignee' => 'assignee_id', 'team' => 'team_id', 'priority' => 'priority_id', 'sprint' => 'sprint_id', 'epic' => 'epic_id', 'delete' => null,
            default => throw HttpException::invalid(['action' => 'Acción no válida.']),
        };
        return Database::get()->transaction(function () use ($ids, $field, $value) {
            foreach ($ids as $id) {
                if ($field === null) self::delete($id);
                else self::update($id, [$field => $value === '' || $value === 'none' ? null : $value]);
            }
            return count($ids);
        });
    }

    // ---- Links ----------------------------------------------------------

    public const LINK_TYPES = ['relates' => 'Relacionada con', 'blocks' => 'Bloquea a', 'duplicates' => 'Duplica a'];

    public static function links(int $taskId): array
    {
        return Database::get()->all("SELECT l.id, l.link_type, 'out' AS dir, t.id AS task_id, CONCAT(p.project_key,'-',t.number) AS task_key, t.title, s.category AS status_category
            FROM task_links l JOIN tasks t ON t.id = l.target_task_id JOIN projects p ON p.id = t.project_id JOIN statuses s ON s.id = t.status_id WHERE l.source_task_id = ? AND t.deleted_at IS NULL
            UNION ALL
            SELECT l.id, l.link_type, 'in' AS dir, t.id, CONCAT(p.project_key,'-',t.number), t.title, s.category
            FROM task_links l JOIN tasks t ON t.id = l.source_task_id JOIN projects p ON p.id = t.project_id JOIN statuses s ON s.id = t.status_id WHERE l.target_task_id = ? AND t.deleted_at IS NULL", [$taskId, $taskId]);
    }

    public static function addLink(int $taskId, string $targetKey, string $type): void
    {
        $t = self::find($taskId);
        Policy::authorizeProject($t['project'], 'task.edit');
        if (!isset(self::LINK_TYPES[$type])) throw HttpException::invalid(['link_type' => 'Tipo de relación no válido.']);
        try {
            $target = self::findByKey(trim($targetKey));
        } catch (HttpException) {
            throw HttpException::invalid(['target' => 'No encontramos una tarea con esa clave.']);
        }
        if ((int)$target['id'] === $taskId) throw HttpException::invalid(['target' => 'Una tarea no puede relacionarse consigo misma.']);
        // 1.12: the other task must be one this person can work on (not only see), the same pair is
        // never related twice in either direction, and «Bloquea a» can never form a circle.
        if (!Policy::canProject($target['project'], 'task.edit')) throw HttpException::invalid(['target' => 'No puedes relacionar tareas de un proyecto en el que no puedes editar.']);
        $db = Database::get();
        if ($type === 'blocks' && self::blocksPath((int)$target['id'], $taskId)) {
            throw HttpException::invalid(['target' => "{$target['task_key']} ya depende (directa o indirectamente) de {$t['task_key']}: la relación formaría un círculo."]);
        }
        if ($db->value('SELECT 1 FROM task_links WHERE link_type = ? AND ((source_task_id = ? AND target_task_id = ?) OR (source_task_id = ? AND target_task_id = ?))', [$type, $taskId, $target['id'], $target['id'], $taskId])) {
            throw HttpException::invalid(['target' => 'Esas dos tareas ya tienen esa relación.']);
        }
        Database::get()->run('INSERT IGNORE INTO task_links (source_task_id, target_task_id, link_type, created_by, created_at) VALUES (?,?,?,?,?)', [$taskId, $target['id'], $type, Auth::id(), now()]);
        Activity::log('linked', 'task', $taskId, "Relacionó {$t['task_key']} con {$target['task_key']}", (int)$t['project_id'], $taskId);
    }

    /** 1.12: true when $from already blocks $to through a chain of «Bloquea a» links. */
    public static function blocksPath(int $from, int $to): bool
    {
        $db = Database::get();
        $seen = [$from => true];
        $queue = [$from];
        for ($i = 0; $queue && $i < 500; $i++) {
            $cur = array_shift($queue);
            foreach ($db->column("SELECT target_task_id FROM task_links WHERE source_task_id = ? AND link_type = 'blocks'", [$cur]) as $next) {
                $next = (int)$next;
                if ($next === $to) return true;
                if (!isset($seen[$next])) { $seen[$next] = true; $queue[] = $next; }
            }
        }
        return false;
    }

    public static function removeLink(int $taskId, int $linkId): void
    {
        $t = self::find($taskId);
        Policy::authorizeProject($t['project'], 'task.edit');
        $n = Database::get()->run('DELETE FROM task_links WHERE id = ? AND (source_task_id = ? OR target_task_id = ?)', [$linkId, $taskId, $taskId])->rowCount();
        if (!$n) throw HttpException::notFound();
    }
}
