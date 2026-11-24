<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Validator;
use Borealis\Security\Policy;

/**
 * 1.15 · Automations: «when this happens (event), if this is true (condition), do this (action)».
 *
 * Instead of a free rule builder (powerful, but hard to understand and easy to get wrong), Borealis
 * offers ready templates with one or two settings each. The administration turns them on for the
 * whole workspace or for one project.
 *
 * Safety:
 *  - actions only send notices (and, at most, write in the Master Log). No rule changes tasks, so a
 *    rule can never trigger another rule or itself: there are no loops;
 *  - each thing a rule does has a key (rule + task + date…) stored in automation_runs with a unique
 *    index: the same event never makes it act twice, even if the scheduled task runs every minute;
 *  - notices go only to people who are members of the workspace and can see the project;
 *  - at most 100 notices per rule and run; only «workspace.manage» can create or change rules;
 *  - every run that did something, and every error, goes to the Master Log (source «Automatización»).
 */
final class Automations
{
    /** template => [name, when (event), condition, action, default params] */
    public const TEMPLATES = [
        'overdue' => ['Tarea vencida', 'Cada día, en la tarea programada', 'La tarea pasó su fecha límite y no está finalizada', 'Avisa a quien es responsable y a quien lleva el proyecto', []],
        'blocker_done' => ['Bloqueo resuelto', 'Cuando una tarea se finaliza', 'Otras tareas dependían de ella («bloquea a»)', 'Avisa a responsables de las tareas que ya pueden avanzar', []],
        'hours_over' => ['Superó las horas acordadas', 'Cada día, en la tarea programada', 'Las horas registradas superan la estimación en más del porcentaje indicado', 'Avisa a quien lleva el proyecto', ['pct' => 20]],
        'project_done' => ['Proyecto terminado', 'Cuando se finaliza la última tarea abierta de un proyecto', 'No quedan tareas abiertas', 'Envía un resumen (tareas, horas, a tiempo) a quien lleva el proyecto', []],
        'week_priorities' => ['Prioridades de la semana', 'Los lunes, en la primera ejecución desde las 8:00', 'La persona tiene tareas abiertas', 'Le envía sus 3 pendientes más importantes, por prioridad y fecha', []],
        'sprint_report' => ['Informe al cerrar el sprint', 'Cuando un sprint termina (se cierra o pasa su fecha de fin)', 'El sprint tenía tareas', 'Envía el cumplimiento del sprint a quien lleva el proyecto', []],
        'blocked_review' => ['Bloqueo prolongado', 'Cada día, en la tarea programada', 'La tarea lleva bloqueada más de los días indicados', 'Pide revisión a quien lleva el proyecto', ['days' => 3]],
    ];
    private const MAX_ACTIONS = 100;
    private static int $depth = 0;
    private static array $sent = [];

    public static function ready(): bool
    {
        return Activity::v115();
    }

    // ---------------------------------------------------------------- rules

    public static function rules(?int $wsId = null): array
    {
        if (!self::ready()) return [];
        return Database::get()->all('SELECT r.*, p.name AS project_name, u.name AS created_by_name FROM automation_rules r LEFT JOIN projects p ON p.id = r.project_id LEFT JOIN users u ON u.id = r.created_by
            WHERE r.workspace_id = ? ORDER BY r.created_at', [$wsId ?? Policy::wsId()]);
    }

    public static function create(array $in): int
    {
        Policy::authorize('workspace.manage');
        $v = Validator::check($in, ['template' => 'required,in:' . implode('|', array_keys(self::TEMPLATES)), 'project_id' => 'nullable,int', 'name' => 'sometimes,nullable,string,max:120']);
        $tpl = self::TEMPLATES[$v['template']];
        $pid = !empty($v['project_id']) ? (int)Projects::find((int)$v['project_id'])['id'] : null;
        $params = self::params((string)$v['template'], $in);
        $db = Database::get();
        if ($db->value('SELECT 1 FROM automation_rules WHERE workspace_id = ? AND template = ? AND project_id <=> ?', [Policy::wsId(), $v['template'], $pid])) {
            throw HttpException::invalid(['template' => 'Esa automatización ya existe para ' . ($pid ? 'ese proyecto' : 'todo el espacio') . '. Puedes cambiarla o pausarla.']);
        }
        $name = trim((string)($v['name'] ?? '')) ?: $tpl[0];
        $id = $db->insert('automation_rules', ['workspace_id' => Policy::wsId(), 'project_id' => $pid, 'template' => $v['template'], 'name' => mb_substr($name, 0, 120),
            'params' => $params ? json_encode($params) : null, 'enabled' => 1, 'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now()]);
        Activity::log('auto_created', 'automation', $id, "Creó la automatización «{$name}»", $pid, null, [], null, ['category' => 'automation', 'after' => ['plantilla' => $tpl[0], 'alcance' => $pid ? 'proyecto' : 'todo el espacio'] + $params]);
        return $id;
    }

    public static function update(int $id, array $in): void
    {
        Policy::authorize('workspace.manage');
        $db = Database::get();
        $r = $db->one('SELECT * FROM automation_rules WHERE id = ? AND workspace_id = ?', [$id, Policy::wsId()]) ?? throw HttpException::notFound();
        $before = ['activa' => (bool)$r['enabled']] + (json_decode((string)$r['params'], true) ?: []);
        $data = ['updated_at' => now()];
        if (array_key_exists('enabled', $in)) $data['enabled'] = in_array($in['enabled'], [true, 1, '1', 'true', 'on'], true) ? 1 : 0;
        $params = self::params((string)$r['template'], $in + (json_decode((string)$r['params'], true) ?: []));
        $data['params'] = $params ? json_encode($params) : null;
        $db->update('automation_rules', $data, 'id = ?', [$id]);
        Activity::log('auto_updated', 'automation', $id, (isset($data['enabled']) && !$data['enabled'] ? 'Pausó' : 'Cambió') . " la automatización «{$r['name']}»", $r['project_id'] ? (int)$r['project_id'] : null, null, [], null,
            ['category' => 'automation', 'before' => $before, 'after' => ['activa' => (bool)($data['enabled'] ?? $r['enabled'])] + $params]);
    }

    public static function delete(int $id): void
    {
        Policy::authorize('workspace.manage');
        $db = Database::get();
        $r = $db->one('SELECT * FROM automation_rules WHERE id = ? AND workspace_id = ?', [$id, Policy::wsId()]) ?? throw HttpException::notFound();
        $db->run('DELETE FROM automation_rules WHERE id = ?', [$id]);
        Activity::log('auto_deleted', 'automation', $id, "Eliminó la automatización «{$r['name']}»", $r['project_id'] ? (int)$r['project_id'] : null, null, [], null, ['category' => 'automation']);
    }

    private static function params(string $tpl, array $in): array
    {
        $def = self::TEMPLATES[$tpl][4];
        $out = [];
        if (isset($def['pct'])) $out['pct'] = max(5, min(500, (int)($in['pct'] ?? $def['pct'])));
        if (isset($def['days'])) $out['days'] = max(1, min(60, (int)($in['days'] ?? $def['days'])));
        return $out;
    }

    // ---------------------------------------------------------------- running

    /** Events from the application (a task changed state). Never throws: a rule must not break the change. */
    public static function onEvent(string $event, array $ctx): void
    {
        if (!self::ready() || self::$depth > 0) return;
        self::$depth++;
        try {
            $t = $ctx['task'];
            if ($event !== 'task_status' || ($ctx['status']['category'] ?? '') !== 'done' || ($t['status_category'] ?? '') === 'done') return;
            $ws = (int)$t['project']['workspace_id'];
            foreach (self::active($ws, ['blocker_done', 'project_done'], (int)$t['project_id']) as $rule) {
                self::guarded($rule, fn() => $rule['template'] === 'blocker_done' ? self::blockerDone($rule, $t) : self::projectDone($rule, $t));
            }
        } catch (\Throwable $e) {
            \Borealis\Core\ErrorHandler::log($e);
        } finally { self::$depth--; }
    }

    /** From the scheduled task: the time-based templates of every workspace. Returns notices sent. */
    public static function runScheduled(?int $ts = null): int
    {
        if (!self::ready()) return 0;
        $ts ??= time();
        $total = 0;
        foreach (Database::get()->all('SELECT * FROM automation_rules WHERE enabled = 1 AND template IN (\'overdue\',\'hours_over\',\'week_priorities\',\'sprint_report\',\'blocked_review\') ORDER BY workspace_id, id') as $rule) {
            $total += self::guarded($rule, fn() => match ($rule['template']) {
                'overdue' => self::overdue($rule),
                'hours_over' => self::hoursOver($rule),
                'week_priorities' => self::weekPriorities($rule, $ts),
                'sprint_report' => self::sprintReport($rule),
                'blocked_review' => self::blockedReview($rule),
            });
        }
        return $total;
    }

    /** Runs one rule, records what it did (or its error) and returns how many notices it sent. */
    private static function guarded(array $rule, callable $fn): int
    {
        self::$sent = [];
        $db = Database::get();
        try {
            $fn();
            $n = count(self::$sent);
            $db->run('UPDATE automation_rules SET last_run_at = ?, runs = runs + 1 WHERE id = ?', [now(), $rule['id']]);
            if ($n) {
                Activity::log('auto_run', 'automation', (int)$rule['id'], "Automatización «{$rule['name']}»: " . ($n === 1 ? '1 aviso' : "$n avisos"), $rule['project_id'] ? (int)$rule['project_id'] : null, null, [], (int)$rule['workspace_id'],
                    ['category' => 'automation', 'source' => 'automation', 'user_id' => null, 'after' => ['avisos' => $n, 'a' => implode(', ', array_slice(array_unique(self::$sent), 0, 10))]]);
            }
            return $n;
        } catch (\Throwable $e) {
            \Borealis\Core\ErrorHandler::log($e);
            try {
                Activity::log('auto_error', 'automation', (int)$rule['id'], mb_substr("La automatización «{$rule['name']}» falló: " . $e->getMessage(), 0, 255), $rule['project_id'] ? (int)$rule['project_id'] : null, null, [], (int)$rule['workspace_id'],
                    ['category' => 'automation', 'source' => 'automation', 'user_id' => null, 'result' => 'error']);
            } catch (\Throwable) {}
            return 0;
        }
    }

    private static function active(int $ws, array $templates, ?int $projectId): array
    {
        return Database::get()->all('SELECT * FROM automation_rules WHERE workspace_id = ? AND enabled = 1 AND template IN (' . Database::in($templates) . ') AND (project_id IS NULL OR project_id = ?)',
            array_merge([$ws], $templates, [$projectId]));
    }

    /** Claims the action once (rule + key); false when it was already done. */
    private static function once(array $rule, string $key, string $detail = ''): bool
    {
        if (count(self::$sent) >= self::MAX_ACTIONS) return false;
        return Database::get()->run('INSERT IGNORE INTO automation_runs (rule_id, dedupe_key, result, detail, created_at) VALUES (?,?,?,?,?)',
            [$rule['id'], mb_substr($key, 0, 120), 'ok', $detail !== '' ? mb_substr($detail, 0, 255) : null, now()])->rowCount() === 1;
    }

    /** Sends a notice to someone who belongs to the workspace and can see the project. */
    private static function notify(array $rule, int $userId, string $message, ?string $url, ?int $projectId, ?int $taskId): void
    {
        if ($userId <= 0) return;
        $db = Database::get();
        $ws = (int)$rule['workspace_id'];
        if (!$db->value("SELECT 1 FROM workspace_members wm JOIN users u ON u.id = wm.user_id AND u.is_active = 1 WHERE wm.user_id = ? AND wm.workspace_id = ? AND wm.status = 'active'", [$userId, $ws])) return;
        if ($projectId && !self::canSee($userId, $projectId)) return;
        if (Notifications::store($userId, $ws, 'automation', null, $message, $url, $projectId, $taskId, 'auto:' . $rule['id'] . ':' . md5($message . $userId))) {
            self::$sent[] = (string)$db->value('SELECT name FROM users WHERE id = ?', [$userId]);
        }
    }

    /** Can the person see the project? (member, through its teams, or a role that sees every project) */
    private static function canSee(int $userId, int $projectId): bool
    {
        return (bool)Database::get()->value("SELECT 1 FROM projects p WHERE p.id = ? AND (
              EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = p.id AND pm.user_id = ?)
           OR EXISTS (SELECT 1 FROM team_members tm WHERE tm.team_id = p.team_id AND tm.user_id = ?)
           OR EXISTS (SELECT 1 FROM project_teams pt JOIN team_members tm ON tm.team_id = pt.team_id WHERE pt.project_id = p.id AND tm.user_id = ?)
           OR EXISTS (SELECT 1 FROM workspace_members wm JOIN role_permissions rp ON rp.role_id = wm.role_id AND rp.permission = 'projects.manage_all' WHERE wm.workspace_id = p.workspace_id AND wm.user_id = ? AND wm.status = 'active'))",
            [$projectId, $userId, $userId, $userId, $userId]);
    }

    private static function lead(int $projectId): int
    {
        $db = Database::get();
        $p = $db->one('SELECT lead_user_id, created_by FROM projects WHERE id = ?', [$projectId]);
        return (int)($p['lead_user_id'] ?: $p['created_by'] ?? 0);
    }

    private static function scope(array $rule): array
    {
        return $rule['project_id'] ? ['AND p.id = ?', [(int)$rule['project_id']]] : ['', []];
    }

    private static function overdue(array $rule): void
    {
        [$ps, $pa] = self::scope($rule);
        $rows = Database::get()->all("SELECT t.id, t.number, t.title, t.due_date, t.assignee_id, t.project_id, p.project_key FROM tasks t JOIN projects p ON p.id = t.project_id JOIN statuses s ON s.id = t.status_id
            WHERE p.workspace_id = ? AND p.archived_at IS NULL AND t.deleted_at IS NULL AND s.category <> 'done' AND t.due_date < ? $ps ORDER BY t.due_date LIMIT 500", array_merge([(int)$rule['workspace_id'], today()], $pa));
        foreach ($rows as $t) {
            $key = "{$t['project_key']}-{$t['number']}";
            if (!self::once($rule, "overdue:{$t['id']}:{$t['due_date']}", $key)) continue;
            $msg = "$key está vencida desde el " . date('d/m/Y', strtotime($t['due_date'])) . ": {$t['title']}";
            $to = array_unique(array_filter([(int)$t['assignee_id'], self::lead((int)$t['project_id'])]));
            foreach ($to as $uid) self::notify($rule, $uid, $msg, url('/t/' . $key), (int)$t['project_id'], (int)$t['id']);
        }
    }

    private static function blockerDone(array $rule, array $t): void
    {
        $rows = Database::get()->all("SELECT d.id, d.number, d.title, d.assignee_id, d.project_id, p.project_key FROM task_links l JOIN tasks d ON d.id = l.target_task_id AND d.deleted_at IS NULL
            JOIN projects p ON p.id = d.project_id JOIN statuses s ON s.id = d.status_id WHERE l.source_task_id = ? AND l.link_type = 'blocks' AND s.category <> 'done'", [(int)$t['id']]);
        foreach ($rows as $d) {
            $key = "{$d['project_key']}-{$d['number']}";
            if (!$d['assignee_id'] || !self::once($rule, "unblocked:{$t['id']}:{$d['id']}", $key)) continue;
            self::notify($rule, (int)$d['assignee_id'], "{$t['task_key']} se finalizó: $key ya no espera por ella ({$d['title']})", url('/t/' . $key), (int)$d['project_id'], (int)$d['id']);
        }
    }

    private static function projectDone(array $rule, array $t): void
    {
        $db = Database::get();
        $pid = (int)$t['project_id'];
        // the change of state is being saved: count the others
        $open = (int)$db->value("SELECT COUNT(*) FROM tasks t JOIN statuses s ON s.id = t.status_id WHERE t.project_id = ? AND t.deleted_at IS NULL AND t.parent_id IS NULL AND s.category <> 'done' AND t.id <> ?", [$pid, (int)$t['id']]);
        if ($open > 0 || !self::once($rule, "project_done:$pid")) return;
        $s = $db->one("SELECT COUNT(*) AS n, SUM(t.completed_due_date IS NOT NULL AND DATE(t.completed_at) > t.completed_due_date) AS late FROM tasks t WHERE t.project_id = ? AND t.deleted_at IS NULL AND t.parent_id IS NULL", [$pid]);
        $min = (int)$db->value('SELECT COALESCE(SUM(minutes), 0) FROM time_entries WHERE project_id = ?', [$pid]);
        $name = (string)$db->value('SELECT name FROM projects WHERE id = ?', [$pid]);
        $n = (int)$s['n']; $late = (int)$s['late'];
        self::notify($rule, self::lead($pid), "«{$name}» terminó: $n tareas, " . TimeEntries::fmt($min) . ' registradas, ' . ($n ? (int)round(($n - $late) / $n * 100) : 100) . ' % a tiempo', url('/projects/' . $pid), $pid, null);
    }

    private static function hoursOver(array $rule): void
    {
        $pct = (int)(json_decode((string)$rule['params'], true)['pct'] ?? 20);
        [$ps, $pa] = self::scope($rule);
        $rows = Database::get()->all("SELECT t.id, t.number, t.title, t.estimate_hours, t.project_id, p.project_key, SUM(te.minutes) AS mins FROM tasks t JOIN projects p ON p.id = t.project_id
            JOIN time_entries te ON te.task_id = t.id WHERE p.workspace_id = ? AND t.deleted_at IS NULL AND t.estimate_hours > 0 $ps GROUP BY t.id HAVING mins > t.estimate_hours * 60 * ? LIMIT 300",
            array_merge([(int)$rule['workspace_id']], $pa, [1 + $pct / 100]));
        foreach ($rows as $t) {
            $key = "{$t['project_key']}-{$t['number']}";
            if (!self::once($rule, "hours:{$t['id']}:{$pct}", $key)) continue;
            self::notify($rule, self::lead((int)$t['project_id']), "$key lleva " . TimeEntries::fmt((int)$t['mins']) . ' de ' . rtrim(rtrim((string)$t['estimate_hours'], '0'), '.') . " h estimadas (más de +$pct %): {$t['title']}", url('/t/' . $key), (int)$t['project_id'], (int)$t['id']);
        }
    }

    private static function blockedReview(array $rule): void
    {
        $days = (int)(json_decode((string)$rule['params'], true)['days'] ?? 3);
        [$ps, $pa] = self::scope($rule);
        $rows = Database::get()->all("SELECT t.id, t.number, t.title, t.blocked_at, t.blocked_reason, t.project_id, p.project_key FROM tasks t JOIN projects p ON p.id = t.project_id JOIN statuses s ON s.id = t.status_id
            WHERE p.workspace_id = ? AND t.deleted_at IS NULL AND t.is_blocked = 1 AND s.category <> 'done' AND t.blocked_at IS NOT NULL AND t.blocked_at < ? $ps LIMIT 300",
            array_merge([(int)$rule['workspace_id'], date('Y-m-d H:i:s', time() - $days * 86400)], $pa));
        foreach ($rows as $t) {
            $key = "{$t['project_key']}-{$t['number']}";
            if (!self::once($rule, "blocked:{$t['id']}:{$t['blocked_at']}", $key)) continue;
            self::notify($rule, self::lead((int)$t['project_id']), "$key lleva más de $days días bloqueada" . ($t['blocked_reason'] ? " ({$t['blocked_reason']})" : '') . '. ¿Puedes revisarla?', url('/t/' . $key), (int)$t['project_id'], (int)$t['id']);
        }
    }

    private static function sprintReport(array $rule): void
    {
        [$ps, $pa] = self::scope($rule);
        $db = Database::get();
        $rows = $db->all("SELECT sp.*, p.name AS project_name FROM sprints sp JOIN projects p ON p.id = sp.project_id WHERE p.workspace_id = ? AND p.archived_at IS NULL
            AND (sp.state = 'completed' OR (sp.end_date IS NOT NULL AND sp.end_date < ?)) AND COALESCE(sp.completed_at, sp.end_date) >= ? $ps",
            array_merge([(int)$rule['workspace_id'], today(), date('Y-m-d', strtotime(today() . ' -14 days'))], $pa));
        foreach ($rows as $sp) {
            $c = $db->one("SELECT COUNT(*) AS n, SUM(s.category = 'done') AS done, SUM(s.category <> 'done' AND t.due_date < ?) AS late FROM tasks t JOIN statuses s ON s.id = t.status_id WHERE t.sprint_id = ? AND t.deleted_at IS NULL", [today(), $sp['id']]);
            if (!(int)$c['n'] || !self::once($rule, "sprint:{$sp['id']}")) continue;
            $pct = (int)round((int)$c['done'] / (int)$c['n'] * 100);
            self::notify($rule, self::lead((int)$sp['project_id']), "Sprint «{$sp['name']}» de {$sp['project_name']}: {$c['done']} de {$c['n']} tareas finalizadas ($pct %)" . ((int)$c['late'] ? ", {$c['late']} vencidas sin terminar" : ''),
                url('/projects/' . $sp['project_id'] . '/sprints'), (int)$sp['project_id'], null);
        }
    }

    private static function weekPriorities(array $rule, int $ts): void
    {
        $db = Database::get();
        foreach ($db->all("SELECT u.id FROM users u JOIN workspace_members wm ON wm.user_id = u.id AND wm.workspace_id = ? AND wm.status = 'active' WHERE u.is_active = 1", [(int)$rule['workspace_id']]) as $u) {
            // the person's own Monday, from 8:00
            $now = UserTime::now((int)$u['id'], $ts);
            if ($now->format('N') !== '1' || (int)$now->format('G') < 8) continue;
            [$ps, $pa] = self::scope($rule);
            $top = $db->all("SELECT t.id, t.number, t.title, t.due_date, p.project_key, pr.name AS prio FROM tasks t JOIN projects p ON p.id = t.project_id JOIN statuses s ON s.id = t.status_id LEFT JOIN priorities pr ON pr.id = t.priority_id
                WHERE p.workspace_id = ? AND p.archived_at IS NULL AND t.deleted_at IS NULL AND s.category <> 'done' AND t.assignee_id = ? $ps
                ORDER BY pr.weight IS NULL, pr.weight DESC, t.due_date IS NULL, t.due_date LIMIT 3", array_merge([(int)$rule['workspace_id'], (int)$u['id']], $pa));
            if (!$top || !self::once($rule, 'week:' . $u['id'] . ':' . $now->format('o-W'))) continue;
            $list = implode(' · ', array_map(fn($t) => "{$t['project_key']}-{$t['number']} {$t['title']}" . ($t['prio'] ? " ({$t['prio']})" : ''), $top));
            self::notify($rule, (int)$u['id'], 'Para esta semana, por prioridad: ' . $list, url('/my-work'), null, null);
        }
    }
}
