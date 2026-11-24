<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Security\AccountPolicy;
use Borealis\Security\Policy;

/**
 * Imports data from Borealis v0.2.x (storage/borealis.json + storage/uploads) into the current workspace.
 * Additive only: nothing existing is modified or deleted. The whole import runs in one transaction,
 * and the same source cannot be imported twice into the same workspace.
 */
final class LegacyImport
{
    private const MAX_JSON_BYTES = 20 * 1024 * 1024;
    private const PRIORITIES = ['urgent' => 'urgente', 'highest' => 'urgente', 'high' => 'alta', 'medium' => 'media', 'low' => 'baja', 'lowest' => 'baja'];
    private const TYPES = ['feature' => 'Funcionalidad', 'issue' => 'Incidencia', 'bug' => 'Incidencia', 'improvement' => 'Mejora'];
    private const UPLOAD_EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'txt', 'md', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip'];

    /**
     * Reads a v0.2.x export: either the borealis.json file or a ZIP of the old installation.
     * @return array{0: array, 1: callable(string): ?string, 2: string} data, upload reader, hash
     */
    public static function open(string $path, string $originalName = ''): array
    {
        $name = strtolower($originalName ?: $path);
        if (str_ends_with($name, '.zip')) {
            if (!class_exists(\ZipArchive::class)) throw HttpException::invalid(['file' => 'El servidor no tiene la extensión zip de PHP. Sube solo el archivo storage/borealis.json.']);
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::RDONLY) !== true) throw HttpException::invalid(['file' => 'No se pudo abrir el ZIP.']);
            // Entries are only read into memory, never extracted to disk by their own path.
            $jsonIndex = null;
            $uploads = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string)$zip->getNameIndex($i);
                if (preg_match('~(^|/)storage/borealis\.json$~', $entry)) $jsonIndex ??= $i;
                if (preg_match('~(^|/)storage/uploads/([a-f0-9]{16,64}\.bin)$~', $entry, $m)) $uploads[$m[2]] = $i;
            }
            if ($jsonIndex === null) throw HttpException::invalid(['file' => 'El ZIP no contiene storage/borealis.json de Borealis v0.2.']);
            $stat = $zip->statIndex($jsonIndex);
            if (($stat['size'] ?? 0) > self::MAX_JSON_BYTES) throw HttpException::invalid(['file' => 'Los datos son demasiado grandes para importarlos desde el navegador.']);
            $raw = (string)$zip->getFromIndex($jsonIndex);
            $reader = function (string $storage) use ($zip, $uploads): ?string {
                if (!isset($uploads[$storage])) return null;
                $s = $zip->statIndex($uploads[$storage]);
                if (($s['size'] ?? 0) > 50 * 1024 * 1024) return null;
                $b = $zip->getFromIndex($uploads[$storage]);
                return $b === false ? null : $b;
            };
        } else {
            if (filesize($path) > self::MAX_JSON_BYTES) throw HttpException::invalid(['file' => 'El archivo es demasiado grande.']);
            $raw = (string)file_get_contents($path);
            $dir = dirname($path) . '/uploads';
            $reader = function (string $storage) use ($dir): ?string {
                if (!preg_match('/^[a-f0-9]{16,64}\.bin$/', $storage)) return null;
                $f = $dir . '/' . $storage;
                return is_file($f) ? (string)file_get_contents($f) : null;
            };
        }
        try {
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw HttpException::invalid(['file' => 'El archivo no es un JSON válido de Borealis.']);
        }
        if (!is_array($data) || !isset($data['projects'], $data['tasks']) || !is_array($data['projects']) || !is_array($data['tasks'])) {
            throw HttpException::invalid(['file' => 'El archivo no tiene el formato de datos de Borealis v0.2.']);
        }
        return [$data, $reader, hash('sha256', $raw)];
    }

    /** Counts what would be imported, without writing anything. */
    public static function preview(array $data): array
    {
        $tasks = self::list($data, 'tasks');
        return [
            'members' => count(self::list($data, 'members')), 'teams' => count(self::list($data, 'teams')),
            'projects' => count(self::list($data, 'projects')), 'tasks' => count($tasks),
            'subtasks' => array_sum(array_map(fn($t) => count((array)($t['checklist'] ?? [])), $tasks)),
            'comments' => array_sum(array_map(fn($t) => count((array)($t['comments'] ?? [])), $tasks)),
            'sprints' => count(self::list($data, 'sprints')), 'files' => count(self::list($data, 'documents')),
        ];
    }

    public static function assertNotImported(string $hash): void
    {
        if (Database::get()->value('SELECT 1 FROM legacy_imports WHERE workspace_id = ? AND source_hash = ?', [Policy::wsId(), $hash])) {
            throw HttpException::conflict('Estos datos ya se importaron en este espacio de trabajo.');
        }
    }

    /** @param callable(string): ?string $readUpload */
    public static function run(array $data, callable $readUpload, string $hash): array
    {
        Policy::authorize('workspace.manage');
        Policy::authorize('projects.create');
        $db = Database::get();
        $ws = Policy::wsId();
        self::assertNotImported($hash);
        $report = ['members' => 0, 'members_existing' => 0, 'teams' => 0, 'projects' => 0, 'tasks' => 0, 'subtasks' => 0, 'comments' => 0, 'sprints' => 0, 'files' => 0, 'warnings' => []];
        $written = [];

        try {
            $db->transaction(function () use ($db, $ws, $data, $readUpload, $hash, &$report, &$written) {
                $now = now();
                $memberRole = (int)$db->value("SELECT id FROM roles WHERE workspace_id = ? AND scope = 'workspace' AND role_key = 'member'", [$ws]);
                $managerRole = Policy::projectRoleId('manager');
                $contribRole = Policy::projectRoleId('contributor');

                // ---- People: one account per e-mail; imported accounts get an unusable random password.
                $users = [];
                foreach (self::list($data, 'members') as $m) {
                    $lid = (string)($m['id'] ?? '');
                    $name = self::str($m['name'] ?? '', 120) ?: 'Persona importada';
                    $email = mb_strtolower(self::str($m['email'] ?? '', 190));
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $email = 'importado-' . preg_replace('/[^a-z0-9]/', '', strtolower($lid ?: bin2hex(random_bytes(3)))) . '-' . substr($hash, 0, 6) . '@borealis.invalid';
                        $report['warnings'][] = "«{$name}» no tenía un correo válido; se le asignó {$email}.";
                    }
                    $uid = (int)$db->value('SELECT id FROM users WHERE email = ?', [$email]);
                    if ($uid && !$db->value('SELECT 1 FROM workspace_members WHERE workspace_id = ? AND user_id = ?', [$ws, $uid]) && !AccountPolicy::isInstanceAdmin()) {
                        // 1.4.1: an account from another workspace is never attached without its consent.
                        $report['warnings'][] = "«{$name}» ya tiene una cuenta de Borealis en otro espacio: no se incorporó. Invítala desde Administración › Usuarios; sus tareas quedan sin responsable.";
                        continue;
                    }
                    if ($uid) {
                        $report['members_existing']++;
                    } else {
                        $uid = $db->insert('users', [
                            'email' => $email, 'name' => $name, 'handle' => Users::uniqueHandle($name, $email),
                            'password_hash' => Auth::hash(bin2hex(random_bytes(24))), 'title' => self::str($m['title'] ?? '', 120) ?: null,
                            'avatar_color' => self::color($m['color'] ?? null, '#10B981'), 'prefs' => json_encode(['motion' => 'full']),
                            'created_at' => $now, 'updated_at' => $now,
                        ]);
                        $report['members']++;
                    }
                    if (!$db->value('SELECT 1 FROM workspace_members WHERE workspace_id = ? AND user_id = ?', [$ws, $uid])) {
                        $db->insert('workspace_members', ['workspace_id' => $ws, 'user_id' => $uid, 'role_id' => $memberRole, 'joined_at' => $now]);
                    }
                    if ($lid !== '') $users[$lid] = $uid;
                }
                $user = fn($lid) => $lid !== null && isset($users[(string)$lid]) ? $users[(string)$lid] : null;

                // ---- Teams
                $teams = [];
                foreach (self::list($data, 'teams') as $t) {
                    $tid = $db->insert('teams', [
                        'workspace_id' => $ws, 'name' => self::str($t['name'] ?? '', 120) ?: 'Equipo importado', 'description' => self::str($t['description'] ?? '', 5000) ?: null,
                        'created_by' => Auth::id(), 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $lead = $user($t['leadId'] ?? null);
                    $ids = array_unique(array_filter(array_map($user, array_merge((array)($t['memberIds'] ?? []), [$t['leadId'] ?? null]))));
                    if (!$lead) $db->insert('team_members', ['team_id' => $tid, 'user_id' => Auth::id(), 'team_role' => 'coordinator', 'joined_at' => $now]);
                    foreach ($ids as $uid) {
                        if (!$lead && $uid === Auth::id()) continue;
                        $db->insert('team_members', ['team_id' => $tid, 'user_id' => $uid, 'team_role' => $uid === $lead ? 'coordinator' : 'member', 'joined_at' => $now]);
                    }
                    $teams[(string)($t['id'] ?? '')] = $tid;
                    $report['teams']++;
                }

                // ---- Projects with their own statuses, labels, sprints and tasks
                $priorities = [];
                foreach (Workspaces::priorities() as $p) $priorities[mb_strtolower($p['name'])] = (int)$p['id'];
                $allStatuses = self::list($data, 'statuses');
                $allSprints = self::list($data, 'sprints');
                $allTasks = self::list($data, 'tasks');
                $projects = [];
                $taskMap = [];
                foreach (self::list($data, 'projects') as $p) {
                    $lpid = (string)($p['id'] ?? '');
                    $name = self::str($p['name'] ?? '', 160) ?: 'Proyecto importado';
                    $key = self::projectKey((string)($p['key'] ?? ''), $name, $ws, $report);
                    $state = ['active' => 'active', 'on_hold' => 'on_hold', 'paused' => 'on_hold', 'completed' => 'completed', 'done' => 'completed'][(string)($p['status'] ?? 'active')] ?? 'active';
                    [$start, $due] = [self::date($p['startDate'] ?? null), self::date($p['dueDate'] ?? null)];
                    if ($start && $due && $due < $start) $due = null;
                    $pid = $db->insert('projects', [
                        'workspace_id' => $ws, 'team_id' => $teams[(string)($p['teamId'] ?? '')] ?? null, 'project_key' => $key, 'name' => $name,
                        'description' => self::str($p['description'] ?? '', 5000) ?: null, 'visibility' => 'workspace', 'state' => $state,
                        'lead_user_id' => Auth::id(), 'start_date' => $start, 'due_date' => $due, 'completed_at' => $state === 'completed' ? $now : null,
                        'created_by' => Auth::id(), 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $db->insert('project_members', ['project_id' => $pid, 'user_id' => Auth::id(), 'role_id' => $managerRole, 'added_at' => $now]);
                    $projects[$lpid] = $pid;
                    $report['projects']++;

                    // Statuses (fall back to the defaults when the old project had none)
                    $sts = array_values(array_filter($allStatuses, fn($s) => (string)($s['projectId'] ?? '') === $lpid));
                    usort($sts, fn($a, $b) => (int)($a['position'] ?? 0) <=> (int)($b['position'] ?? 0));
                    $statusMap = [];
                    if (!$sts) {
                        foreach (Projects::DEFAULT_STATUSES as $i => [$n, $cat, $color]) $statusMap['__' . $i] = $db->insert('statuses', ['project_id' => $pid, 'name' => $n, 'category' => $cat, 'color' => $color, 'position' => $i]);
                    }
                    foreach ($sts as $i => $s) {
                        $cat = !empty($s['isDone']) ? 'done' : ($i === 0 ? 'todo' : 'in_progress');
                        $statusMap[(string)($s['id'] ?? $i)] = $db->insert('statuses', ['project_id' => $pid, 'name' => self::str($s['name'] ?? '', 60) ?: 'Estado', 'category' => $cat, 'color' => self::color($s['color'] ?? null, '#94A3B8'), 'position' => $i]);
                    }
                    $firstStatus = (int)reset($statusMap);
                    $doneStatus = (int)$db->value("SELECT id FROM statuses WHERE project_id = ? AND category = 'done' ORDER BY position LIMIT 1", [$pid]) ?: $firstStatus;
                    if (!$db->value("SELECT 1 FROM statuses WHERE project_id = ? AND category = 'done'", [$pid])) {
                        $doneStatus = $db->insert('statuses', ['project_id' => $pid, 'name' => 'Finalizado', 'category' => 'done', 'color' => '#10B981', 'position' => count($statusMap)]);
                    }

                    // Sprints (only one may be active)
                    $sprintMap = [];
                    $hasActive = false;
                    foreach (array_filter($allSprints, fn($s) => (string)($s['projectId'] ?? '') === $lpid) as $s) {
                        $st = ['active' => 'active', 'completed' => 'completed', 'done' => 'completed', 'closed' => 'completed'][(string)($s['status'] ?? '')] ?? 'planned';
                        if ($st === 'active' && $hasActive) $st = 'planned';
                        $hasActive = $hasActive || $st === 'active';
                        $sprintMap[(string)($s['id'] ?? '')] = $db->insert('sprints', [
                            'project_id' => $pid, 'name' => self::str($s['name'] ?? '', 120) ?: 'Sprint', 'goal' => self::str($s['goal'] ?? '', 5000) ?: null,
                            'start_date' => self::date($s['startDate'] ?? null), 'end_date' => self::date($s['endDate'] ?? null), 'state' => $st,
                            'started_at' => $st !== 'planned' ? $now : null, 'completed_at' => $st === 'completed' ? $now : null, 'created_at' => $now, 'updated_at' => $now,
                        ]);
                        $report['sprints']++;
                    }

                    // Tasks
                    $ptasks = array_values(array_filter($allTasks, fn($t) => (string)($t['projectId'] ?? '') === $lpid));
                    $labelIds = [];
                    $label = function (string $n) use ($db, $pid, &$labelIds): int {
                        $n = mb_substr(trim($n), 0, 40);
                        return $labelIds[mb_strtolower($n)] ??= $db->insert('labels', ['project_id' => $pid, 'name' => $n, 'color' => '#0EA5E9']);
                    };
                    $used = [];
                    $numbers = [];
                    foreach ($ptasks as $t) {
                        $n = preg_match('/-(\d{1,9})$/', (string)($t['code'] ?? ''), $m) ? (int)$m[1] : 0;
                        $numbers[] = ($n > 0 && !isset($used[$n])) ? $n : 0;
                        if ($n > 0) $used[$n] = true;
                    }
                    $seq = $used ? max(array_keys($used)) : 0;
                    $hasPoints = $hasDates = false;
                    foreach ($ptasks as $i => $t) {
                        $number = $numbers[$i] ?: ++$seq;
                        $statusId = $statusMap[(string)($t['statusId'] ?? '')] ?? $firstStatus;
                        $isDone = (int)$db->value("SELECT category = 'done' FROM statuses WHERE id = ?", [$statusId]) === 1;
                        $points = isset($t['points']) && is_numeric($t['points']) && (float)$t['points'] > 0 ? min(9999, (float)$t['points']) : null;
                        [$start, $due] = [self::date($t['startDate'] ?? null), self::date($t['dueDate'] ?? null)];
                        if ($start && $due && $due < $start) $start = null;
                        $hasPoints = $hasPoints || $points !== null;
                        $hasDates = $hasDates || $start || $due;
                        $created = self::datetime($t['createdAt'] ?? null) ?? $now;
                        $updated = self::datetime($t['updatedAt'] ?? null) ?? $created;
                        $assignee = $user($t['assigneeId'] ?? null);
                        $taskId = $db->insert('tasks', [
                            'project_id' => $pid, 'number' => $number, 'title' => self::str($t['title'] ?? '', 255) ?: 'Tarea sin título',
                            'description' => self::str($t['description'] ?? '', 100000) ?: null, 'status_id' => $statusId,
                            'priority_id' => $priorities[self::PRIORITIES[strtolower((string)($t['priority'] ?? ''))] ?? ''] ?? null,
                            'assignee_id' => $assignee, 'reporter_id' => $user($t['reporterId'] ?? null), 'sprint_id' => $sprintMap[(string)($t['sprintId'] ?? '')] ?? null,
                            'estimate' => $points, 'start_date' => $start, 'due_date' => $due, 'position' => ($i + 1) * 1000, 'backlog_position' => ($i + 1) * 1000,
                            'completed_at' => $isDone ? $updated : null, 'created_at' => $created, 'updated_at' => $updated,
                        ]);
                        $taskMap[(string)($t['id'] ?? '')] = $taskId;
                        $report['tasks']++;
                        if ($assignee && !$db->value('SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ?', [$pid, $assignee])) {
                            $db->insert('project_members', ['project_id' => $pid, 'user_id' => $assignee, 'role_id' => $contribRole, 'added_at' => $now]);
                        }
                        $names = array_map('strval', array_filter((array)($t['labels'] ?? []), 'is_scalar'));
                        if (isset(self::TYPES[(string)($t['type'] ?? '')])) $names[] = self::TYPES[(string)$t['type']];
                        foreach (array_unique(array_filter(array_map('trim', $names))) as $ln) {
                            $db->run('INSERT IGNORE INTO task_labels (task_id, label_id) VALUES (?, ?)', [$taskId, $label($ln)]);
                        }
                        // Checklist items become subtasks
                        foreach ((array)($t['checklist'] ?? []) as $j => $c) {
                            if (!is_array($c) || self::str($c['text'] ?? '', 255) === '') continue;
                            $done = !empty($c['done']);
                            $db->insert('tasks', [
                                'project_id' => $pid, 'number' => ++$seq, 'parent_id' => $taskId, 'title' => self::str($c['text'], 255),
                                'status_id' => $done ? $doneStatus : $firstStatus, 'reporter_id' => $user($t['reporterId'] ?? null),
                                'position' => ($j + 1) * 1000, 'backlog_position' => ($j + 1) * 1000, 'completed_at' => $done ? $updated : null,
                                'created_at' => $created, 'updated_at' => $updated,
                            ]);
                            $report['subtasks']++;
                        }
                        foreach ((array)($t['comments'] ?? []) as $c) {
                            if (!is_array($c) || self::str($c['text'] ?? '', 10000) === '') continue;
                            $db->insert('comments', ['task_id' => $taskId, 'user_id' => $user($c['actorId'] ?? null), 'body' => self::str($c['text'], 10000), 'created_at' => self::datetime($c['createdAt'] ?? null) ?? $now]);
                            $report['comments']++;
                        }
                    }
                    $db->update('projects', ['task_seq' => $seq], 'id = ?', [$pid]);

                    // Modules: turn on what the old data actually uses.
                    $mods = ['docs' => true, 'reports' => true];
                    if ($sprintMap) $mods += ['sprints' => true, 'backlog' => true];
                    if ($hasPoints) $mods['estimates'] = true;
                    if ($hasDates) $mods += ['calendar' => true, 'timeline' => true];
                    foreach ($mods as $k => $_) $db->insert('project_modules', ['project_id' => $pid, 'module_key' => $k, 'enabled' => 1, 'updated_at' => $now]);
                    Activity::log('imported', 'project', $pid, "Importó «{$name}» desde Borealis v0.2", $pid);
                }

                // ---- Files (documents): copied into storage/uploads with new random names.
                $dir = Files::dir();
                if (!is_dir($dir)) mkdir($dir, 0750, true);
                $fallbackProject = $projects ? reset($projects) : null;
                foreach (self::list($data, 'documents') as $d) {
                    $fname = self::str(basename(str_replace('\\', '/', (string)($d['name'] ?? ''))), 200) ?: 'archivo';
                    $pid = $projects[(string)($d['projectId'] ?? '')] ?? $fallbackProject;
                    $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                    $blocked = array_intersect(array_slice(array_map('strtolower', explode('.', $fname)), 1), ['php', 'phtml', 'phar', 'html', 'htm', 'svg', 'js', 'sh', 'exe']);
                    if (!$pid || !in_array($ext, self::UPLOAD_EXTENSIONS, true) || $blocked) { $report['warnings'][] = "Archivo «{$fname}» no importado (tipo no permitido o sin proyecto)."; continue; }
                    $bytes = $readUpload((string)($d['storageName'] ?? ''));
                    if ($bytes === null || $bytes === '') { $report['warnings'][] = "Archivo «{$fname}» no encontrado en las cargas de la versión anterior."; continue; }
                    $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
                    if (preg_match('/php|html|javascript|svg/', $mime)) { $report['warnings'][] = "Archivo «{$fname}» no importado (contenido no permitido)."; continue; }
                    $stored = bin2hex(random_bytes(20));
                    if (file_put_contents($dir . '/' . $stored, $bytes) === false) throw new \RuntimeException('No se pudo escribir en storage/uploads.');
                    @chmod($dir . '/' . $stored, 0640);
                    $written[] = $dir . '/' . $stored;
                    $db->insert('files', ['workspace_id' => $ws, 'project_id' => $pid, 'uploader_id' => Auth::id(), 'original_name' => $fname, 'stored_name' => $stored, 'mime' => $mime, 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'created_at' => self::datetime($d['addedAt'] ?? null) ?? $now]);
                    $report['files']++;
                }

                $db->insert('legacy_imports', ['workspace_id' => $ws, 'source_hash' => $hash, 'source_version' => (string)($data['version'] ?? '1'), 'summary' => json_encode($report, JSON_UNESCAPED_UNICODE), 'imported_by' => Auth::id(), 'created_at' => $now]);
                Activity::log('imported', 'workspace', $ws, "Importó datos de Borealis v0.2: {$report['projects']} proyectos y {$report['tasks']} tareas");
            });
        } catch (\Throwable $e) {
            foreach ($written as $f) @unlink($f); // the database rolled back; remove copied files too
            throw $e;
        }
        return $report;
    }

    private static function list(array $data, string $key): array
    {
        return array_values(array_filter((array)($data[$key] ?? []), 'is_array'));
    }

    private static function str(mixed $v, int $max): string
    {
        return is_scalar($v) ? mb_substr(trim(str_replace("\0", '', (string)$v)), 0, $max) : '';
    }

    private static function color(mixed $v, string $default): string
    {
        return is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtoupper($v) : $default;
    }

    private static function date(mixed $v): ?string
    {
        if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
        return "$m[1]-$m[2]-$m[3]";
    }

    private static function datetime(mixed $v): ?string
    {
        if (!is_string($v) || $v === '') return null;
        try {
            return (new \DateTimeImmutable($v))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    private static function projectKey(string $key, string $name, int $ws, array &$report): string
    {
        $db = Database::get();
        $key = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $key));
        if (!preg_match('/^[A-Z][A-Z0-9]{1,5}$/', $key)) $key = Projects::suggestKey($name);
        $base = substr($key, 0, 5);
        $try = $key;
        for ($i = 2; $db->value('SELECT 1 FROM projects WHERE workspace_id = ? AND project_key = ?', [$ws, $try]); $i++) {
            $try = substr($base, 0, 6 - strlen((string)$i)) . $i;
        }
        if ($try !== $key) $report['warnings'][] = "La clave {$key} ya existía; «{$name}» usa {$try}.";
        return $try;
    }
}
