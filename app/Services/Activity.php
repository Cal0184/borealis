<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Security\Policy;

/**
 * Activity log and audit (1.14 consolidates it: one log for the whole platform, also used by the
 * calendar history). Each row: who, what, which element, when, the values before and after, the
 * reason, the project and the origin (web, system, google, import, cli).
 *
 * Protection: there is no screen or API that edits or deletes a row. Each new row is chained to the
 * previous one of its workspace (prev_hash → row_hash, SHA-256 over every stored field), so a row
 * changed or removed directly in the database is detected by verify() (Administración › Historial ›
 * «Comprobar integridad»). Passwords, tokens, secrets, webhook addresses and device keys are never
 * stored: redact() drops them from data/before/after whatever the caller passes.
 */
final class Activity
{
    /** Origin of the rows written from now on in this request (google during a sync, import…). */
    public static ?string $source = null;

    public const CATEGORIES = [
        'project' => 'Proyectos', 'task' => 'Tareas', 'assignment' => 'Asignaciones', 'estimate' => 'Estimaciones', 'time' => 'Horas',
        'calendar' => 'Calendario', 'sprint' => 'Sprints', 'team' => 'Usuarios y equipos', 'permission' => 'Permisos', 'settings' => 'Configuración',
        'integration' => 'Integraciones', 'deletion' => 'Eliminaciones', 'system' => 'Acciones automáticas', 'other' => 'Otros',
        // 1.15
        'security' => 'Accesos y seguridad', 'notification' => 'Notificaciones', 'automation' => 'Automatizaciones', 'error' => 'Errores del sistema',
    ];
    public const SOURCES = ['web' => 'Borealis', 'system' => 'Automático', 'google' => 'Google', 'import' => 'Importación', 'cli' => 'Consola', 'discord' => 'Discord', 'github' => 'GitHub', 'automation' => 'Automatización'];
    /** 1.15 · how an entry is shown in the Master Log (colour and filter). */
    public const KINDS = ['normal' => 'Trabajo', 'admin' => 'Administración', 'auto' => 'Automático', 'error' => 'Errores y rechazos'];
    public const RESULTS = ['error' => 'Error', 'denied' => 'Rechazado'];
    private const ADMIN_CATS = ['permission', 'settings', 'team', 'security'];
    private const AUTO_SOURCES = ['system', 'google', 'github', 'discord', 'import', 'automation'];

    private static ?bool $ready = null;
    private static ?bool $v115 = null;

    /** 1.15 columns (result, retention base) exist */
    public static function v115(): bool
    {
        if (self::$v115 === null) {
            try { self::$v115 = (bool)Database::get()->value("SELECT 1 FROM schema_migrations WHERE version = '017_v115'"); } catch (\Throwable) { self::$v115 = false; }
        }
        return self::$v115;
    }

    public static function ready(): bool
    {
        if (self::$ready === null) {
            try { self::$ready = (bool)Database::get()->value("SELECT 1 FROM schema_migrations WHERE version = '016_v114'"); } catch (\Throwable) { self::$ready = false; }
        }
        return self::$ready;
    }

    /**
     * $audit (1.14, optional): before, after (arrays), reason (string), category, source.
     * 1.15: result ('error' | 'denied'; done when left out) and user_id (who, when there is no session:
     * a failed sign-in, or null for «Sistema»).
     */
    public static function log(string $action, string $entityType, ?int $entityId, string $summary, ?int $projectId = null, ?int $taskId = null, array $data = [], ?int $workspaceId = null, array $audit = []): void
    {
        $db = Database::get();
        $row = [
            'workspace_id' => $workspaceId ?? Policy::wsId(),
            'project_id' => $projectId,
            'task_id' => $taskId,
            'user_id' => array_key_exists('user_id', $audit) ? ($audit['user_id'] ? (int)$audit['user_id'] : null) : (Auth::id() ?: null),
            'action' => mb_substr($action, 0, 40),
            'entity_type' => mb_substr($entityType, 0, 30),
            'entity_id' => $entityId,
            'summary' => mb_substr($summary, 0, 255),
            'data' => $data ? json_encode(self::redact($data), JSON_UNESCAPED_UNICODE) : null,
            'created_at' => now(),
        ];
        if (!self::ready()) { $db->insert('activity', $row); return; }
        $row += [
            'category' => $audit['category'] ?? self::categoryOf($action, $entityType),
            'before_data' => isset($audit['before']) && $audit['before'] !== [] ? mb_substr(json_encode(self::redact((array)$audit['before']), JSON_UNESCAPED_UNICODE), 0, 60000) : null,
            'after_data' => isset($audit['after']) && $audit['after'] !== [] ? mb_substr(json_encode(self::redact((array)$audit['after']), JSON_UNESCAPED_UNICODE), 0, 60000) : null,
            'reason' => isset($audit['reason']) && trim((string)$audit['reason']) !== '' ? mb_substr(trim((string)$audit['reason']), 0, 500) : null,
            'source' => $audit['source'] ?? self::$source ?? (Scheduler::running() ? 'system' : (PHP_SAPI === 'cli' ? 'cli' : 'web')),
        ];
        if (self::v115()) $row['result'] = isset($audit['result'], self::RESULTS[$audit['result']]) ? $audit['result'] : null;
        $db->transaction(function (Database $db) use ($row) {
            $ws = (int)$row['workspace_id'];
            $db->run('INSERT IGNORE INTO audit_chain (workspace_id, head, last_id) VALUES (?,?,0)', [$ws, str_repeat('0', 64)]);
            $prev = (string)$db->value('SELECT head FROM audit_chain WHERE workspace_id = ? FOR UPDATE', [$ws]);
            $id = $db->insert('activity', $row + ['prev_hash' => $prev]);
            $hash = self::hash($row + ['id' => $id, 'prev_hash' => $prev]);
            $db->run('UPDATE activity SET row_hash = ? WHERE id = ?', [$hash, $id]);
            $db->run('UPDATE audit_chain SET head = ?, last_id = ? WHERE workspace_id = ?', [$hash, $id, $ws]);
        });
    }

    /** SHA-256 over every stored field of a row, in a fixed order, with the previous row's hash. */
    public static function hash(array $r): string
    {
        $f = ['id', 'workspace_id', 'project_id', 'task_id', 'user_id', 'action', 'entity_type', 'entity_id', 'category', 'summary', 'data', 'before_data', 'after_data', 'reason', 'source', 'created_at', 'prev_hash'];
        $base = implode("\x1f", array_map(fn($k) => ($r[$k] ?? null) === null ? "\x00" : (string)$r[$k], $f));
        // 1.15: the result joins the hash only when there is one, so every row written before keeps its hash
        if (($r['result'] ?? null) !== null) $base .= "\x1fresult=" . $r['result'];
        return hash('sha256', $base);
    }

    /**
     * Walks the chain of the current workspace. Returns ['ok' => bool, 'checked' => n, 'broken' => first bad id|null, 'legacy' => rows before 1.14].
     */
    public static function verify(?int $wsId = null): array
    {
        $db = Database::get();
        $ws = $wsId ?? Policy::wsId();
        $legacy = (int)$db->value('SELECT COUNT(*) FROM activity WHERE workspace_id = ? AND row_hash IS NULL', [$ws]);
        $head = $db->one('SELECT * FROM audit_chain WHERE workspace_id = ?', [$ws]);
        // 1.15: after archiving, the chain continues from the last archived row
        $prev = ($head['base_hash'] ?? null) ?: str_repeat('0', 64);
        $checked = 0;
        $last = (int)($head['base_id'] ?? 0);
        $broken = null;
        do {
            $rows = $db->all('SELECT * FROM activity WHERE workspace_id = ? AND row_hash IS NOT NULL AND id > ? ORDER BY id LIMIT 2000', [$ws, $last]);
            foreach ($rows as $r) {
                $last = (int)$r['id'];
                $checked++;
                if ($r['prev_hash'] !== $prev || !hash_equals((string)$r['row_hash'], self::hash($r))) { $broken = $last; break 2; }
                $prev = (string)$r['row_hash'];
            }
        } while (count($rows) === 2000);
        // the newest rows were deleted: the chain ends before the head says it should
        if ($broken === null && $head && $checked > 0 && ($head['head'] !== $prev || (int)$head['last_id'] !== $last)) $broken = (int)$head['last_id'];
        if ($broken === null && $head && $checked === 0 && (int)$head['last_id'] > (int)($head['base_id'] ?? 0)) $broken = (int)$head['last_id'];
        // a row whose hash was blanked to pass as «before 1.14»: no unchained row may come after the first chained one
        if ($broken === null && $checked > 0) {
            $first = (int)$db->value('SELECT MIN(id) FROM activity WHERE workspace_id = ? AND row_hash IS NOT NULL', [$ws]);
            $gap = $db->value('SELECT MIN(id) FROM activity WHERE workspace_id = ? AND row_hash IS NULL AND id > ?', [$ws, $first]);
            if ($gap) $broken = (int)$gap;
        }
        return ['ok' => $broken === null, 'checked' => $checked, 'broken' => $broken, 'legacy' => $legacy];
    }

    /** Removes anything secret from what is about to be stored. */
    public static function redact(array $a): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            if (is_string($k) && preg_match('/(pass(word)?|token|secret|api_?key|webhook|^auth$|p256dh|endpoint|cookie|session|_hash$|^pem$|credential)/i', $k)) { $out[$k] = '[oculto]'; continue; }
            $out[$k] = is_array($v) ? self::redact($v) : (is_string($v) ? mb_substr($v, 0, 2000) : $v);
        }
        return $out;
    }

    public static function categoryOf(string $action, string $entityType): string
    {
        if (in_array($action, ['login', 'logout', 'login_failed', 'password_changed', 'password_reset', 'denied'], true)) return 'security';
        if (str_starts_with($action, 'auto_') || $entityType === 'automation') return 'automation';
        if (str_starts_with($action, 'notify_') || $entityType === 'notification') return 'notification';
        if ($action === 'system_error') return 'error';
        if (str_contains($action, 'deleted') || str_contains($action, 'removed') || $action === 'delete') return 'deletion';
        if (str_starts_with($action, 'estimate')) return 'estimate';
        if (str_starts_with($action, 'time_')) return 'time';
        if ($action === 'integration' || $action === 'link_added' || str_starts_with($action, 'google')) return 'integration';
        if (in_array($action, ['role_changed', 'role_saved', 'suspended', 'reactivated', 'visibility', 'member_role'], true)) return 'permission';
        if (in_array($action, ['settings', 'monitor_settings', 'analysis_settings'], true)) return 'settings';
        if ($action === 'assigned' || str_starts_with($action, 'assign')) return 'assignment';
        if (str_starts_with($action, 'due_') || str_starts_with($action, 'event_') || $entityType === 'event' || $entityType === 'calendar') return 'calendar';
        return match ($entityType) {
            'project', 'epic', 'document', 'file', 'folder' => 'project',
            'task', 'comment' => 'task',
            'time', 'time_entry' => 'time',
            'sprint' => 'sprint',
            'team', 'user', 'member', 'invitation', 'ghost' => 'team',
            'role' => 'permission',
            'workspace', 'settings' => 'settings',
            'integration', 'google' => 'integration',
            'capacity', 'monitor', 'scenario' => 'system',
            default => 'other',
        };
    }

    /** Recent activity limited to projects the user can see (plus workspace-level events). */
    public static function recent(int $limit = 15, ?int $projectId = null, ?int $taskId = null): array
    {
        $db = Database::get();
        [$vis, $params] = Policy::visibleProjectsSql('p');
        $where = "a.workspace_id = ? AND (a.project_id IS NULL OR EXISTS (SELECT 1 FROM projects p WHERE p.id = a.project_id AND $vis))";
        $args = array_merge([Policy::wsId()], $params);
        if ($projectId) { $where .= ' AND a.project_id = ?'; $args[] = $projectId; }
        if ($taskId) { $where .= ' AND a.task_id = ?'; $args[] = $taskId; }
        $args[] = $limit;
        return $db->all("SELECT a.*, u.name AS user_name, u.avatar_color, pr.name AS project_name, pr.project_key, t.number AS task_number
            FROM activity a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN projects pr ON pr.id = a.project_id LEFT JOIN tasks t ON t.id = a.task_id
            WHERE $where ORDER BY a.id DESC LIMIT ?", $args);
    }

    /**
     * 1.14 · the audit search (Administración › Historial and the project's «Historial de planificación»).
     * $f: user_id, project_id, category, action, from, to (dates), q (text), task_id.
     */
    public static function search(array $f, int $limit = 50, int $offset = 0, ?array $onlyProjects = null): array
    {
        [$where, $args] = self::where($f, $onlyProjects);
        $args[] = $limit;
        $args[] = $offset;
        return Database::get()->all("SELECT a.*, u.name AS user_name, u.avatar_color, p.name AS project_name, p.project_key, t.number AS task_number FROM activity a
            LEFT JOIN users u ON u.id = a.user_id LEFT JOIN projects p ON p.id = a.project_id LEFT JOIN tasks t ON t.id = a.task_id WHERE $where ORDER BY a.id DESC LIMIT ? OFFSET ?", $args);
    }

    public static function where(array $f, ?array $onlyProjects = null): array
    {
        $where = 'a.workspace_id = ?';
        $args = [Policy::wsId()];
        if ($onlyProjects !== null) {
            $ids = array_map('intval', $onlyProjects);
            $where .= ' AND a.project_id IN (' . Database::in($ids) . ')';
            $args = array_merge($args, $ids);
        }
        if (!empty($f['user_id'])) { $where .= ' AND a.user_id = ?'; $args[] = (int)$f['user_id']; }
        if (!empty($f['project_id'])) { $where .= ' AND a.project_id = ?'; $args[] = (int)$f['project_id']; }
        if (!empty($f['task_id'])) { $where .= ' AND (a.task_id = ? OR (a.entity_type = \'task\' AND a.entity_id = ?))'; $args[] = (int)$f['task_id']; $args[] = (int)$f['task_id']; }
        if (!empty($f['category']) && isset(self::CATEGORIES[$f['category']])) {
            // rows from before 1.14 have no category: classify them the same way on the fly
            $where .= ' AND (a.category = ? OR (a.category IS NULL AND ' . self::legacyCategorySql((string)$f['category']) . '))';
            $args[] = (string)$f['category'];
        }
        if (!empty($f['type'])) { $where .= ' AND a.entity_type = ?'; $args[] = (string)$f['type']; }
        if (!empty($f['action'])) { $where .= ' AND a.action = ?'; $args[] = mb_substr((string)$f['action'], 0, 40); }
        if (!empty($f['source']) && isset(self::SOURCES[$f['source']])) { $where .= ' AND a.source = ?'; $args[] = (string)$f['source']; }
        if (!empty($f['from']) && preg_match('/^\d{4}-\d\d-\d\d$/', (string)$f['from'])) { $where .= ' AND a.created_at >= ?'; $args[] = $f['from'] . ' 00:00:00'; }
        if (!empty($f['to']) && preg_match('/^\d{4}-\d\d-\d\d$/', (string)$f['to'])) { $where .= ' AND a.created_at <= ?'; $args[] = $f['to'] . ' 23:59:59'; }
        if (!empty($f['q'])) { $where .= ' AND a.summary LIKE ?'; $args[] = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_substr((string)$f['q'], 0, 80)) . '%'; }
        // 1.15
        if (!empty($f['kind']) && isset(self::KINDS[$f['kind']])) $where .= ' AND ' . self::kindSql((string)$f['kind']);
        if (!empty($f['result']) && self::v115() && isset(self::RESULTS[$f['result']])) { $where .= ' AND a.result = ?'; $args[] = (string)$f['result']; }
        if (!empty($f['before']) && ctype_digit((string)$f['before'])) { $where .= ' AND a.id < ?'; $args[] = (int)$f['before']; }
        return [$where, $args];
    }

    private static function legacyCategorySql(string $cat): string
    {
        return match ($cat) {
            'calendar' => "(a.entity_type = 'event' OR a.action LIKE 'event\\_%')",
            'deletion' => "(a.action LIKE '%deleted%' OR a.action LIKE '%removed%')",
            'assignment' => "a.action = 'assigned'",
            'project' => "a.entity_type IN ('project','epic','document','file','folder') AND a.action NOT LIKE '%deleted%'",
            'task' => "a.entity_type IN ('task','comment') AND a.action NOT IN ('assigned') AND a.action NOT LIKE '%deleted%'",
            'sprint' => "a.entity_type = 'sprint'",
            'team' => "a.entity_type IN ('team','user','member','invitation','ghost')",
            'permission' => "a.entity_type = 'role'",
            'settings' => "a.entity_type = 'workspace'",
            'time' => "a.entity_type IN ('time','time_entry')",
            default => '0',
        };
    }

    /**
     * 1.15 · the kind of an entry: «error» (failed or refused), «auto» (done by Borealis or an
     * integration), «admin» (people, permissions, settings, access) or «normal» (everyday work).
     */
    public static function kind(array $a): string
    {
        $cat = $a['category'] ?? null ?: self::categoryOf((string)$a['action'], (string)$a['entity_type']);
        if (!empty($a['result']) || $cat === 'error') return 'error';
        if (in_array($a['source'] ?? '', self::AUTO_SOURCES, true) || in_array($cat, ['system', 'automation'], true)) return 'auto';
        if (in_array($cat, self::ADMIN_CATS, true)) return 'admin';
        return 'normal';
    }

    private static function kindSql(string $kind): string
    {
        $err = self::v115() ? "(a.result IS NOT NULL OR a.category = 'error')" : "a.category = 'error'";
        $auto = "(a.source IN ('" . implode("','", self::AUTO_SOURCES) . "') OR a.category IN ('system','automation'))";
        $admin = "a.category IN ('" . implode("','", self::ADMIN_CATS) . "')";
        return match ($kind) {
            'error' => $err,
            'auto' => "(NOT $err AND $auto)",
            'admin' => "(NOT $err AND NOT $auto AND $admin)",
            default => "(NOT $err AND NOT $auto AND (a.category IS NULL OR NOT $admin))",
        };
    }

    /** 1.15 · where the element of an entry lives (null when it has no page of its own or was deleted). */
    public static function link(array $a): ?string
    {
        if (!empty($a['task_id']) && !empty($a['project_key']) && isset($a['task_number'])) return url('/t/' . $a['project_key'] . '-' . $a['task_number']);
        return match ($a['entity_type'] ?? '') {
            'project' => !empty($a['entity_id']) && !str_contains((string)$a['action'], 'deleted') ? url('/projects/' . (int)$a['entity_id']) : null,
            'sprint' => !empty($a['project_id']) ? url('/projects/' . (int)$a['project_id'] . '/sprints') : null,
            'team' => !empty($a['entity_id']) ? url('/teams/' . (int)$a['entity_id']) : null,
            'user', 'member' => !empty($a['entity_id']) ? url('/people/' . (int)$a['entity_id']) : null,
            'automation' => url('/admin/automations'),
            default => !empty($a['project_id']) ? url('/projects/' . (int)$a['project_id']) : null,
        };
    }

    /**
     * 1.15 · a server error also goes to the Master Log (without the trace, paths or query values),
     * at most once an hour for the same error, so the administration can see it without the server.
     */
    public static function systemError(\Throwable $e): void
    {
        static $busy = false;
        if ($busy || !self::v115()) return;
        $busy = true;
        try {
            $ws = Policy::wsIdOrNull();
            if (!$ws) return;
            $msg = preg_replace('/(SQLSTATE\[\w+\]).*/s', '$1', $e->getMessage());
            $msg = mb_substr(get_class($e) . ': ' . preg_replace('~/[\w./-]+~', '…', (string)$msg), 0, 200);
            $since = date('Y-m-d H:i:s', time() - 3600);
            if (Database::get()->value("SELECT 1 FROM activity WHERE workspace_id = ? AND action = 'system_error' AND summary = ? AND created_at >= ? LIMIT 1", [$ws, $msg, $since])) return;
            self::log('system_error', 'system', null, $msg, null, null, [], $ws, ['category' => 'error', 'result' => 'error', 'source' => 'system',
                'after' => ['archivo' => basename($e->getFile()) . ':' . $e->getLine()]]);
        } catch (\Throwable) {
            // the error log on disk still has it
        } finally { $busy = false; }
    }

    /**
     * 1.15 · sign-in, sign-out and refused sign-in, in the person's workspace. Only the shortened
     * address is kept (the last part is hidden) and never what was typed in the password field.
     */
    public static function security(string $action, int $userId, string $summary, string $ip = '', ?string $result = null): void
    {
        if (!self::ready()) return;
        try {
            $ws = Policy::wsIdOrNull() ?? Policy::homeWorkspace($userId);
            if (!$ws) return;
            self::log($action, 'user', $userId, $summary, null, null, [], $ws, ['category' => 'security', 'user_id' => $userId, 'result' => $result,
                'after' => $ip !== '' ? ['red' => self::maskIp($ip)] : []]);
        } catch (\Throwable $e) {
            \Borealis\Core\ErrorHandler::log($e);
        }
    }

    public static function maskIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return preg_replace('/\.\d+$/', '.x', $ip);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) return implode(':', array_slice(explode(':', $ip), 0, 3)) . ':…';
        return '';
    }

    /** Actions present in the workspace (for the filter). */
    public static function actions(): array
    {
        return Database::get()->column('SELECT DISTINCT action FROM activity WHERE workspace_id = ? ORDER BY action LIMIT 200', [Policy::wsId()]);
    }
}
