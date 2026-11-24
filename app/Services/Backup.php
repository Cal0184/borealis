<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Database;
use Borealis\Security\Policy;

/** Portable SQL dump written with PDO (no mysqldump binary required). */
final class Backup
{
    public static function dir(): string
    {
        $d = BASE_PATH . '/storage/backups';
        if (!is_dir($d)) mkdir($d, 0750, true);
        return $d;
    }

    public static function create(?\PDO $pdo = null, string $label = 'manual'): string
    {
        $pdo ??= Database::get()->pdo;
        $file = self::dir() . '/borealis-' . gmdate('Ymd-His') . '-' . preg_replace('/[^a-z0-9-]/', '', $label) . '.sql';
        $fh = fopen($file, 'w');
        fwrite($fh, "-- Borealis backup " . gmdate('c') . " (v" . BOREALIS_VERSION . ")\nSET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n");
        foreach ($pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(\PDO::FETCH_NUM) as [$table]) {
            $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(\PDO::FETCH_NUM)[1];
            fwrite($fh, "DROP TABLE IF EXISTS `$table`;\n$create;\n\n");
            $stmt = $pdo->query("SELECT * FROM `$table`");
            $batch = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $batch[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string)$v : $pdo->quote((string)$v)), $row)) . ')';
                if (count($batch) >= 200) {
                    fwrite($fh, "INSERT INTO `$table` VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch) fwrite($fh, "INSERT INTO `$table` VALUES\n" . implode(",\n", $batch) . ";\n");
            fwrite($fh, "\n");
        }
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);
        @chmod($file, 0640);
        return $file;
    }

    public static function list(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*.sql') ?: [] as $f) {
            $out[] = ['name' => basename($f), 'size' => filesize($f), 'time' => gmdate('Y-m-d H:i:s', filemtime($f))];
        }
        usort($out, fn($a, $b) => strcmp($b['name'], $a['name']));
        return $out;
    }

    /** JSON export of everything in the current workspace. */
    public static function exportWorkspace(): string
    {
        Policy::authorize('workspace.manage');
        $db = Database::get();
        $ws = Policy::wsId();
        $pids = array_map('intval', $db->column('SELECT id FROM projects WHERE workspace_id = ?', [$ws]));
        $in = Database::in($pids);
        $tids = $pids ? array_map('intval', $db->column("SELECT id FROM tasks WHERE project_id IN ($in)", $pids)) : [];
        $tin = Database::in($tids);
        $data = [
            'borealis_version' => BOREALIS_VERSION,
            'exported_at' => gmdate('c'),
            'workspace' => $db->one('SELECT id, name, settings, created_at FROM workspaces WHERE id = ?', [$ws]),
            'members' => $db->all('SELECT u.id, u.name, u.email, u.handle, u.title, r.role_key, wm.status, wm.joined_at FROM workspace_members wm JOIN users u ON u.id = wm.user_id JOIN roles r ON r.id = wm.role_id WHERE wm.workspace_id = ?', [$ws]),
            'roles' => $db->all('SELECT r.*, GROUP_CONCAT(rp.permission) AS permissions FROM roles r LEFT JOIN role_permissions rp ON rp.role_id = r.id WHERE r.workspace_id = ? GROUP BY r.id', [$ws]),
            'priorities' => $db->all('SELECT * FROM priorities WHERE workspace_id = ?', [$ws]),
            'teams' => $db->all('SELECT * FROM teams WHERE workspace_id = ?', [$ws]),
            'team_members' => $db->all('SELECT tm.* FROM team_members tm JOIN teams t ON t.id = tm.team_id WHERE t.workspace_id = ?', [$ws]),
            'projects' => $db->all('SELECT * FROM projects WHERE workspace_id = ?', [$ws]),
        ];
        foreach (['project_members', 'project_teams', 'project_modules', 'statuses', 'labels', 'epics', 'sprints', 'documents', 'folders'] as $t) {
            $data[$t] = $pids ? $db->all("SELECT * FROM `$t` WHERE project_id IN ($in)", $pids) : [];
        }
        $data['tasks'] = $pids ? $db->all("SELECT * FROM tasks WHERE project_id IN ($in)", $pids) : [];
        $data['task_assignees'] = $tids ? $db->all("SELECT * FROM task_assignees WHERE task_id IN ($tin)", $tids) : [];
        $data['task_labels'] = $tids ? $db->all("SELECT * FROM task_labels WHERE task_id IN ($tin)", $tids) : [];
        $data['task_links'] = $tids ? $db->all("SELECT * FROM task_links WHERE source_task_id IN ($tin)", $tids) : [];
        $data['comments'] = $tids ? $db->all("SELECT * FROM comments WHERE task_id IN ($tin)", $tids) : [];
        $data['files'] = $db->all('SELECT id, project_id, task_id, folder_id, uploader_id, original_name, mime, size_bytes, sha256, deleted_at, created_at FROM files WHERE workspace_id = ?', [$ws]);
        $data['activity'] = $db->all('SELECT * FROM activity WHERE workspace_id = ? ORDER BY id', [$ws]);
        Activity::log('exported', 'workspace', $ws, 'Exportó la información del espacio');
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
