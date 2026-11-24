<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Security\Policy;

/**
 * Export one project to a self-contained JSON file and import it as a NEW project
 * (in the same Borealis or another one). People are matched by e-mail, teams by name and
 * priorities by name; anything without a match is kept as text so no information is lost.
 * Nothing existing is ever overwritten by an import.
 */
final class ProjectTransfer
{
    public const FORMAT = 'borealis-project';
    public const VERSION = 1;
    /** Attachments are embedded only up to this total, to keep the file uploadable. */
    public const MAX_EMBED_BYTES = 25 * 1024 * 1024;

    public static function export(int $projectId, bool $withFiles): array
    {
        $p = Projects::find($projectId);
        Policy::authorizeProject($p, 'project.manage');
        $db = Database::get();
        $pid = (int)$p['id'];
        $emails = [];
        $email = function ($uid) use ($db, &$emails) {
            if (!$uid) return null;
            return $emails[$uid] ??= $db->value('SELECT email FROM users WHERE id = ?', [$uid]) ?: null;
        };
        $tasks = $db->all('SELECT t.*, pr.name AS priority_name FROM tasks t LEFT JOIN priorities pr ON pr.id = t.priority_id WHERE t.project_id = ? AND t.deleted_at IS NULL ORDER BY t.parent_id IS NOT NULL, t.number', [$pid]);
        $tids = array_map('intval', array_column($tasks, 'id'));
        $tin = Database::in($tids);
        $co = $tids ? $db->all("SELECT task_id, user_id FROM task_assignees WHERE task_id IN ($tin)", $tids) : [];
        $coBy = [];
        foreach ($co as $c) $coBy[$c['task_id']][] = $email($c['user_id']);
        $tl = $tids ? $db->all("SELECT task_id, label_id FROM task_labels WHERE task_id IN ($tin)", $tids) : [];
        $tlBy = [];
        foreach ($tl as $l) $tlBy[$l['task_id']][] = (int)$l['label_id'];
        $teamName = fn($id) => $id ? $db->value('SELECT name FROM teams WHERE id = ?', [$id]) : null;

        $files = [];
        $embedded = 0;
        $skipped = 0;
        foreach ($db->all('SELECT * FROM files WHERE project_id = ? AND deleted_at IS NULL ORDER BY id', [$pid]) as $f) {
            $row = ['ref' => (int)$f['id'], 'task_ref' => $f['task_id'] ? (int)$f['task_id'] : null, 'folder_ref' => $f['folder_id'] ? (int)$f['folder_id'] : null,
                'name' => $f['original_name'], 'mime' => $f['mime'], 'size' => (int)$f['size_bytes'], 'sha256' => $f['sha256'], 'uploader' => $email($f['uploader_id']), 'created_at' => $f['created_at']];
            $path = Files::dir() . '/' . basename((string)$f['stored_name']);
            if ($withFiles && is_file($path) && $embedded + (int)$f['size_bytes'] <= self::MAX_EMBED_BYTES) {
                $row['content'] = base64_encode((string)file_get_contents($path));
                $embedded += (int)$f['size_bytes'];
            } elseif ($withFiles) {
                $skipped++;
            }
            $files[] = $row;
        }

        $data = [
            'format' => self::FORMAT, 'version' => self::VERSION, 'borealis_version' => BOREALIS_VERSION, 'exported_at' => gmdate('c'),
            'exported_by' => Auth::user()['name'] ?? null,
            'project' => [
                'name' => $p['name'], 'project_key' => $p['project_key'], 'description' => $p['description'], 'color' => $p['color'], 'cover' => $p['cover'] ?? 'forest',
                'visibility' => $p['visibility'], 'state' => $p['state'], 'start_date' => $p['start_date'], 'due_date' => $p['due_date'], 'is_flexible' => (int)($p['is_flexible'] ?? 0),
                'lead' => $email($p['lead_user_id']), 'team' => $teamName($p['team_id']), 'created_at' => $p['created_at'], 'completed_at' => $p['completed_at'],
            ],
            'modules' => $db->all('SELECT module_key, enabled FROM project_modules WHERE project_id = ?', [$pid]),
            'statuses' => array_map(fn($s) => ['ref' => (int)$s['id'], 'name' => $s['name'], 'category' => $s['category'], 'color' => $s['color'], 'position' => (int)$s['position'], 'wip_limit' => $s['wip_limit'] !== null ? (int)$s['wip_limit'] : null],
                $db->all('SELECT * FROM statuses WHERE project_id = ? ORDER BY position, id', [$pid])),
            'labels' => array_map(fn($l) => ['ref' => (int)$l['id'], 'name' => $l['name'], 'color' => $l['color']], $db->all('SELECT * FROM labels WHERE project_id = ?', [$pid])),
            'epics' => array_map(fn($e) => ['ref' => (int)$e['id'], 'name' => $e['name'], 'description' => $e['description'], 'color' => $e['color'], 'is_closed' => (int)$e['is_closed'], 'created_at' => $e['created_at']],
                $db->all('SELECT * FROM epics WHERE project_id = ?', [$pid])),
            'sprints' => array_map(function ($s) {
                $s['ref'] = (int)$s['id'];
                unset($s['id'], $s['project_id']);
                return $s;
            }, $db->all('SELECT * FROM sprints WHERE project_id = ? ORDER BY id', [$pid])),
            'members' => array_map(fn($m) => ['email' => $m['email'], 'name' => $m['name'], 'role_key' => $m['role_key']],
                $db->all('SELECT u.email, u.name, r.role_key FROM project_members pm JOIN users u ON u.id = pm.user_id JOIN roles r ON r.id = pm.role_id WHERE pm.project_id = ? AND u.is_ghost = 0', [$pid])),
            'teams' => array_map(fn($t) => ['name' => $t['name'], 'role_key' => $t['role_key']],
                $db->all('SELECT t.name, r.role_key FROM project_teams pt JOIN teams t ON t.id = pt.team_id JOIN roles r ON r.id = pt.role_id WHERE pt.project_id = ?', [$pid])),
            'tasks' => array_map(fn($t) => [
                'ref' => (int)$t['id'], 'number' => (int)$t['number'], 'parent_ref' => $t['parent_id'] ? (int)$t['parent_id'] : null, 'title' => $t['title'], 'description' => $t['description'],
                'status_ref' => (int)$t['status_id'], 'priority' => $t['priority_name'], 'assignee' => $email($t['assignee_id']), 'co_assignees' => array_values(array_filter($coBy[$t['id']] ?? [])),
                'team' => $teamName($t['team_id']), 'reporter' => $email($t['reporter_id']), 'epic_ref' => $t['epic_id'] ? (int)$t['epic_id'] : null, 'sprint_ref' => $t['sprint_id'] ? (int)$t['sprint_id'] : null,
                'estimate' => $t['estimate'], 'estimate_hours' => $t['estimate_hours'], 'start_date' => $t['start_date'], 'due_date' => $t['due_date'],
                'position' => (float)$t['position'], 'backlog_position' => (float)$t['backlog_position'], 'is_blocked' => (int)$t['is_blocked'], 'blocked_reason' => $t['blocked_reason'], 'drive_url' => $t['drive_url'] ?? null, 'github_url' => $t['github_url'] ?? null,
                'completed_at' => $t['completed_at'], 'completed_due_date' => $t['completed_due_date'] ?? null, 'created_at' => $t['created_at'], 'updated_at' => $t['updated_at'], 'labels' => $tlBy[$t['id']] ?? [],
            ], $tasks),
            'links' => $tids ? array_map(fn($l) => ['source_ref' => (int)$l['source_task_id'], 'target_ref' => (int)$l['target_task_id'], 'type' => $l['link_type']],
                $db->all("SELECT * FROM task_links WHERE source_task_id IN ($tin) AND target_task_id IN ($tin)", array_merge($tids, $tids))) : [],
            'comments' => $tids ? array_map(fn($c) => ['task_ref' => (int)$c['task_id'], 'author' => $email($c['user_id']), 'author_name' => $c['author_name'], 'body' => $c['body'], 'created_at' => $c['created_at'], 'edited_at' => $c['edited_at']],
                $db->all("SELECT c.*, u.name AS author_name FROM comments c LEFT JOIN users u ON u.id = c.user_id WHERE c.task_id IN ($tin) AND c.deleted_at IS NULL ORDER BY c.id", $tids)) : [],
            'time_entries' => array_map(fn($te) => ['task_ref' => (int)$te['task_id'], 'user' => $te['email'], 'user_name' => $te['name'], 'minutes' => (int)$te['minutes'], 'work_date' => $te['work_date'], 'note' => $te['note'], 'created_at' => $te['created_at']],
                $db->all('SELECT te.*, u.email, u.name FROM time_entries te JOIN users u ON u.id = te.user_id JOIN tasks t ON t.id = te.task_id WHERE te.project_id = ? AND te.deleted_at IS NULL AND t.deleted_at IS NULL ORDER BY te.id', [$pid])),
            'documents' => array_map(fn($d) => ['title' => $d['title'], 'body' => $d['body'], 'created_at' => $d['created_at'], 'updated_at' => $d['updated_at']],
                $db->all('SELECT * FROM documents WHERE project_id = ? ORDER BY id', [$pid])),
            'folders' => array_map(fn($f) => ['ref' => (int)$f['id'], 'parent_ref' => $f['parent_id'] ? (int)$f['parent_id'] : null, 'name' => $f['name']],
                $db->all('SELECT * FROM folders WHERE project_id = ? ORDER BY parent_id IS NOT NULL, id', [$pid])),
            'files' => $files,
            'files_skipped' => $skipped,
        ];
        Activity::log('exported', 'project', $pid, "Exportó el proyecto «{$p['name']}»" . ($withFiles ? ' con archivos' : ''), $pid);
        return $data;
    }

    public static function decode(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT) {
            throw HttpException::invalid(['file' => 'El archivo no es una exportación de proyecto de Borealis (.json).']);
        }
        if ((int)($data['version'] ?? 0) > self::VERSION) throw HttpException::invalid(['file' => 'El archivo viene de una versión más nueva de Borealis. Actualiza esta instalación primero.']);
        if (empty($data['project']['name']) || !is_array($data['statuses'] ?? null) || !$data['statuses']) throw HttpException::invalid(['file' => 'Al archivo le faltan datos del proyecto (nombre o columnas).']);
        return $data;
    }

    /** Creates a new project from an export. Returns the new project id and a short report. */
    public static function import(array $data, ?string $newName = null): array
    {
        Policy::authorize('projects.create');
        $db = Database::get();
        $ws = Policy::wsId();
        $src = $data['project'];
        $str = fn($v, int $max) => $v === null ? null : mb_substr((string)$v, 0, $max);
        $date = fn($v) => is_string($v) && \Borealis\Core\Validator::isDate($v) ? $v : null;
        $dt = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v) ? $v : null;
        $color = fn($v, $d) => is_string($v) && preg_match('/^#[0-9A-Fa-f]{6}$/', $v) ? strtoupper($v) : $d;

        // People of this workspace by e-mail; teams and priorities by name.
        $people = [];
        foreach ($db->all('SELECT u.id, u.email FROM workspace_members wm JOIN users u ON u.id = wm.user_id WHERE wm.workspace_id = ? AND wm.status = \'active\'', [$ws]) as $u) $people[mb_strtolower($u['email'])] = (int)$u['id'];
        $user = fn($email) => $email ? ($people[mb_strtolower((string)$email)] ?? null) : null;
        $teams = [];
        foreach ($db->all('SELECT id, name FROM teams WHERE workspace_id = ? AND archived_at IS NULL', [$ws]) as $t) $teams[mb_strtolower($t['name'])] = (int)$t['id'];
        $team = fn($name) => $name ? ($teams[mb_strtolower((string)$name)] ?? null) : null;
        $prios = [];
        foreach (Workspaces::priorities() as $pr) $prios[mb_strtolower($pr['name'])] = (int)$pr['id'];
        $projectRoles = [];
        foreach (Workspaces::roles('project') as $r) $projectRoles[$r['role_key']] = (int)$r['id'];
        $role = fn($key) => $projectRoles[$key] ?? $projectRoles['contributor'] ?? Policy::projectRoleId('contributor');

        $name = $str(trim((string)($newName ?: $src['name'])), 160);
        if ($name === '') throw HttpException::invalid(['name' => 'El proyecto necesita un nombre.']);
        $key = strtoupper((string)($src['project_key'] ?? ''));
        $taken = fn($k) => (bool)$db->value('SELECT 1 FROM projects WHERE workspace_id = ? AND project_key = ? UNION SELECT 1 FROM project_key_aliases WHERE workspace_id = ? AND old_key = ?', [$ws, $k, $ws, $k]);
        if (!preg_match('/^[A-Z][A-Z0-9]{1,5}$/', $key) || $taken($key)) $key = Projects::suggestKey($name);

        $unknown = [];
        $report = ['tasks' => 0, 'comments' => 0, 'hours' => 0, 'files' => 0, 'files_rejected' => [], 'documents' => 0];
        $written = []; // stored files, removed again if the import fails (no orphans)
        try {
        $id = $db->transaction(function (Database $db) use ($data, $src, $name, $key, $user, $team, $prios, $role, $str, $date, $dt, $color, $ws, &$unknown, &$report, &$written) {
            $note = function ($email) use (&$unknown) { if ($email) $unknown[mb_strtolower((string)$email)] = true; };
            $pid = $db->insert('projects', [
                'workspace_id' => $ws, 'team_id' => $team($src['team'] ?? null), 'project_key' => $key, 'name' => $name, 'description' => $str($src['description'] ?? null, 5000),
                'color' => $color($src['color'] ?? null, '#10B981'), 'cover' => in_array($src['cover'] ?? '', array_keys(Projects::COVERS), true) ? $src['cover'] : 'forest',
                'visibility' => ($src['visibility'] ?? '') === 'private' ? 'private' : 'workspace', 'state' => in_array($src['state'] ?? '', ['planned', 'active', 'on_hold', 'completed'], true) ? $src['state'] : 'active',
                'is_flexible' => (int)!empty($src['is_flexible']), 'lead_user_id' => $user($src['lead'] ?? null) ?? Auth::id(), 'start_date' => $date($src['start_date'] ?? null), 'due_date' => $date($src['due_date'] ?? null),
                'completed_at' => $dt($src['completed_at'] ?? null), 'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            // The importer manages the new project; listed members join with their role when they exist here.
            $db->insert('project_members', ['project_id' => $pid, 'user_id' => Auth::id(), 'role_id' => Policy::projectRoleId('manager'), 'added_at' => now()]);
            foreach ((array)($data['members'] ?? []) as $m) {
                $uid = $user($m['email'] ?? null);
                if (!$uid) { $note($m['email'] ?? null); continue; }
                if ($uid === Auth::id()) continue;
                $db->run('INSERT IGNORE INTO project_members (project_id, user_id, role_id, added_at) VALUES (?,?,?,?)', [$pid, $uid, $role($m['role_key'] ?? 'contributor'), now()]);
            }
            foreach ((array)($data['teams'] ?? []) as $t) {
                $tid = $team($t['name'] ?? null);
                if ($tid) $db->run('INSERT IGNORE INTO project_teams (project_id, team_id, role_id, added_at) VALUES (?,?,?,?)', [$pid, $tid, $role($t['role_key'] ?? 'contributor'), now()]);
            }
            foreach ((array)($data['modules'] ?? []) as $m) {
                if (isset(Modules::OPTIONAL[$m['module_key'] ?? ''])) $db->run('INSERT INTO project_modules (project_id, module_key, enabled, updated_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)', [$pid, $m['module_key'], (int)!empty($m['enabled']), now()]);
            }
            $map = ['status' => [], 'label' => [], 'epic' => [], 'sprint' => [], 'task' => [], 'folder' => []];
            $hasDone = false;
            foreach ($data['statuses'] as $i => $s) {
                $cat = in_array($s['category'] ?? '', ['todo', 'in_progress', 'done'], true) ? $s['category'] : 'todo';
                $hasDone = $hasDone || $cat === 'done';
                $map['status'][(int)($s['ref'] ?? $i)] = $db->insert('statuses', ['project_id' => $pid, 'name' => $str($s['name'] ?? 'Columna', 60) ?: 'Columna', 'category' => $cat,
                    'color' => $color($s['color'] ?? null, '#94A3B8'), 'position' => (int)($s['position'] ?? $i), 'wip_limit' => isset($s['wip_limit']) && $s['wip_limit'] !== null ? max(0, (int)$s['wip_limit']) : null]);
            }
            if (!$hasDone) $db->insert('statuses', ['project_id' => $pid, 'name' => 'Finalizado', 'category' => 'done', 'color' => '#10B981', 'position' => count($data['statuses'])]);
            $firstStatus = (int)reset($map['status']);
            foreach ((array)($data['labels'] ?? []) as $l) {
                $ln = $str($l['name'] ?? '', 40);
                if ($ln === '' || isset($seenLabels[$ln])) continue;
                $seenLabels[$ln] = true;
                $map['label'][(int)$l['ref']] = $db->insert('labels', ['project_id' => $pid, 'name' => $ln, 'color' => $color($l['color'] ?? null, '#0EA5E9')]);
            }
            foreach ((array)($data['epics'] ?? []) as $e) {
                $map['epic'][(int)$e['ref']] = $db->insert('epics', ['project_id' => $pid, 'name' => $str($e['name'] ?? 'Épica', 160), 'description' => $str($e['description'] ?? null, 60000), 'color' => $color($e['color'] ?? null, '#8B5CF6'),
                    'is_closed' => (int)!empty($e['is_closed']), 'created_by' => Auth::id(), 'created_at' => $dt($e['created_at'] ?? null) ?? now(), 'updated_at' => now()]);
            }
            $activeSeen = false;
            foreach ((array)($data['sprints'] ?? []) as $s) {
                $state = in_array($s['state'] ?? '', ['planned', 'active', 'completed'], true) ? $s['state'] : 'planned';
                if ($state === 'active') { if ($activeSeen) $state = 'planned'; $activeSeen = true; }
                $num = fn($v) => $v === null || $v === '' || !is_numeric($v) ? null : $v;
                $map['sprint'][(int)$s['ref']] = $db->insert('sprints', ['project_id' => $pid, 'name' => $str($s['name'] ?? 'Sprint', 120), 'goal' => $str($s['goal'] ?? null, 5000),
                    'start_date' => $date($s['start_date'] ?? null), 'end_date' => $date($s['end_date'] ?? null), 'capacity_hours' => $num($s['capacity_hours'] ?? null), 'state' => $state,
                    'started_at' => $dt($s['started_at'] ?? null), 'completed_at' => $dt($s['completed_at'] ?? null), 'committed_count' => $num($s['committed_count'] ?? null), 'completed_count' => $num($s['completed_count'] ?? null),
                    'committed_points' => $num($s['committed_points'] ?? null), 'completed_points' => $num($s['completed_points'] ?? null), 'result_notes' => $str($s['result_notes'] ?? null, 5000),
                    'created_at' => $dt($s['created_at'] ?? null) ?? now(), 'updated_at' => now()]);
            }
            // Tasks keep their numbers (so PROY-12 stays 12); parents first.
            $tasks = (array)($data['tasks'] ?? []);
            usort($tasks, fn($a, $b) => [(int)!empty($a['parent_ref']), (int)($a['number'] ?? 0)] <=> [(int)!empty($b['parent_ref']), (int)($b['number'] ?? 0)]);
            $maxNumber = 0;
            $usedNumbers = [];
            foreach ($tasks as $t) {
                $n = (int)($t['number'] ?? 0);
                if ($n < 1 || isset($usedNumbers[$n])) $n = max(array_keys($usedNumbers) ?: [0]) + 1;
                $usedNumbers[$n] = true;
                $maxNumber = max($maxNumber, $n);
                $sid = $map['status'][(int)($t['status_ref'] ?? 0)] ?? $firstStatus;
                $cat = $db->value('SELECT category FROM statuses WHERE id = ?', [$sid]);
                $parent = !empty($t['parent_ref']) ? ($map['task'][(int)$t['parent_ref']] ?? null) : null;
                $assignee = $user($t['assignee'] ?? null);
                if (!$assignee) $note($t['assignee'] ?? null);
                $num = fn($v) => $v === null || $v === '' || !is_numeric($v) ? null : max(0, min(9999, (float)$v));
                $newId = $db->insert('tasks', [
                    'project_id' => $pid, 'number' => $n, 'parent_id' => $parent, 'title' => $str($t['title'] ?? 'Sin título', 255) ?: 'Sin título', 'description' => $str($t['description'] ?? null, 60000),
                    'status_id' => $sid, 'priority_id' => $prios[mb_strtolower((string)($t['priority'] ?? ''))] ?? null, 'assignee_id' => $assignee, 'team_id' => $team($t['team'] ?? null),
                    'reporter_id' => $user($t['reporter'] ?? null) ?? Auth::id(), 'epic_id' => $map['epic'][(int)($t['epic_ref'] ?? 0)] ?? null,
                    'sprint_id' => $parent ? null : ($map['sprint'][(int)($t['sprint_ref'] ?? 0)] ?? null), 'estimate' => $num($t['estimate'] ?? null), 'estimate_hours' => $num($t['estimate_hours'] ?? null),
                    'start_date' => $date($t['start_date'] ?? null), 'due_date' => $date($t['due_date'] ?? null), 'position' => (float)($t['position'] ?? $n * 1024), 'backlog_position' => (float)($t['backlog_position'] ?? $n * 1024),
                    'is_blocked' => (int)!empty($t['is_blocked']), 'blocked_reason' => $str($t['blocked_reason'] ?? null, 255),
                    'drive_url' => Tasks::normalizeLink('drive_url', (string)($t['drive_url'] ?? '')) ?: null, 'github_url' => Tasks::normalizeLink('github_url', (string)($t['github_url'] ?? '')) ?: null,
                    'completed_at' => $cat === 'done' ? ($dt($t['completed_at'] ?? null) ?? now()) : null,
                    'completed_due_date' => $cat === 'done' ? ($date($t['completed_due_date'] ?? null) ?? $date($t['due_date'] ?? null)) : null, 'created_at' => $dt($t['created_at'] ?? null) ?? now(), 'updated_at' => now(),
                ]);
                $map['task'][(int)($t['ref'] ?? 0)] = $newId;
                foreach ((array)($t['co_assignees'] ?? []) as $ce) {
                    $cu = $user($ce);
                    if (!$cu) { $note($ce); continue; }
                    if ($cu !== $assignee) $db->run('INSERT IGNORE INTO task_assignees (task_id, user_id, added_at) VALUES (?,?,?)', [$newId, $cu, now()]);
                }
                foreach ((array)($t['labels'] ?? []) as $lr) {
                    if (isset($map['label'][(int)$lr])) $db->run('INSERT IGNORE INTO task_labels (task_id, label_id) VALUES (?,?)', [$newId, $map['label'][(int)$lr]]);
                }
                $report['tasks']++;
            }
            $db->update('projects', ['task_seq' => $maxNumber], 'id = ?', [$pid]);
            foreach ((array)($data['links'] ?? []) as $l) {
                $a = $map['task'][(int)($l['source_ref'] ?? 0)] ?? null;
                $b = $map['task'][(int)($l['target_ref'] ?? 0)] ?? null;
                if ($a && $b && isset(Tasks::LINK_TYPES[$l['type'] ?? ''])) $db->run('INSERT IGNORE INTO task_links (source_task_id, target_task_id, link_type, created_by, created_at) VALUES (?,?,?,?,?)', [$a, $b, $l['type'], Auth::id(), now()]);
            }
            foreach ((array)($data['comments'] ?? []) as $c) {
                $tid = $map['task'][(int)($c['task_ref'] ?? 0)] ?? null;
                if (!$tid || trim((string)($c['body'] ?? '')) === '') continue;
                $author = $user($c['author'] ?? null);
                $body = (string)$c['body'];
                // Author not in this workspace: keep who wrote it inside the text.
                if (!$author) { $note($c['author'] ?? null); $body = '«' . ($c['author_name'] ?? $c['author'] ?? 'Persona de otra instalación') . '» escribió:' . "\n\n" . $body; }
                $db->insert('comments', ['task_id' => $tid, 'user_id' => $author, 'body' => mb_substr($body, 0, 20000), 'edited_at' => $dt($c['edited_at'] ?? null), 'created_at' => $dt($c['created_at'] ?? null) ?? now()]);
                $report['comments']++;
            }
            foreach ((array)($data['time_entries'] ?? []) as $te) {
                $tid = $map['task'][(int)($te['task_ref'] ?? 0)] ?? null;
                $uid = $user($te['user'] ?? null);
                $min = (int)($te['minutes'] ?? 0);
                if (!$uid) { $note($te['user'] ?? null); }
                if (!$tid || !$uid || $min <= 0 || !$date($te['work_date'] ?? null)) continue;
                $db->insert('time_entries', ['project_id' => $pid, 'task_id' => $tid, 'user_id' => $uid, 'minutes' => min($min, 24 * 60), 'work_date' => $te['work_date'], 'note' => $str($te['note'] ?? null, 255), 'created_by' => Auth::id(), 'created_at' => $dt($te['created_at'] ?? null) ?? now()]);
                $report['hours'] += $min;
            }
            foreach ((array)($data['documents'] ?? []) as $d) {
                $db->insert('documents', ['project_id' => $pid, 'title' => $str($d['title'] ?? 'Documento', 200) ?: 'Documento', 'body' => $str($d['body'] ?? null, 1000000), 'created_by' => Auth::id(), 'updated_by' => Auth::id(),
                    'created_at' => $dt($d['created_at'] ?? null) ?? now(), 'updated_at' => $dt($d['updated_at'] ?? null) ?? now()]);
                $report['documents']++;
            }
            foreach ((array)($data['folders'] ?? []) as $f) {
                $map['folder'][(int)$f['ref']] = $db->insert('folders', ['project_id' => $pid, 'parent_id' => !empty($f['parent_ref']) ? ($map['folder'][(int)$f['parent_ref']] ?? null) : null, 'name' => $str($f['name'] ?? 'Carpeta', 120) ?: 'Carpeta', 'created_by' => Auth::id(), 'created_at' => now()]);
            }
            // 1.4.1: the same checks as a normal upload (Files::validate); rejected files are reported.
            $dir = Files::dir();
            foreach ((array)($data['files'] ?? []) as $f) {
                if (empty($f['content'])) continue;
                $fname = (string)($f['name'] ?? '');
                $bin = base64_decode((string)$f['content'], true);
                if ($bin === false || $bin === '') { $report['files_rejected'][] = $fname; continue; }
                $stored = bin2hex(random_bytes(20));
                if (!is_dir($dir)) mkdir($dir, 0750, true);
                if (file_put_contents($dir . '/' . $stored, $bin) === false) throw new \RuntimeException('No se pudo escribir en storage/uploads.');
                $written[] = $dir . '/' . $stored;
                @chmod($dir . '/' . $stored, 0640);
                try {
                    $ok = Files::validate($fname, $dir . '/' . $stored);
                } catch (HttpException) {
                    @unlink($dir . '/' . $stored);
                    array_pop($written);
                    $report['files_rejected'][] = $fname;
                    continue;
                }
                $db->insert('files', ['workspace_id' => $ws, 'project_id' => $pid, 'task_id' => $map['task'][(int)($f['task_ref'] ?? 0)] ?? null, 'folder_id' => $map['folder'][(int)($f['folder_ref'] ?? 0)] ?? null,
                    'uploader_id' => $user($f['uploader'] ?? null) ?? Auth::id(), 'original_name' => $ok['name'], 'stored_name' => $stored, 'mime' => $ok['mime'],
                    'size_bytes' => strlen($bin), 'sha256' => hash('sha256', $bin), 'created_at' => $dt($f['created_at'] ?? null) ?? now()]);
                $report['files']++;
            }
            Activity::log('imported', 'project', $pid, "Importó el proyecto «{$name}» desde un archivo de Borealis", $pid);
            return $pid;
        });
        } catch (\Throwable $e) {
            foreach ($written as $w) @unlink($w);
            throw $e;
        }
        $report['unknown_people'] = array_keys($unknown);
        $report['key'] = $key;
        return ['id' => $id, 'report' => $report];
    }
}
