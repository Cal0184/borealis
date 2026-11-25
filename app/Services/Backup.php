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
        $d = storage_path('backups');
        if (!is_dir($d)) mkdir($d, 0750, true);
        return $d;
    }

    /** 1.16.4: automatic copies kept in storage/backups (the oldest beyond this number are deleted). */
    public const KEEP = 20;
    /** 1.16.4: file names (random part so that nobody can guess them; old names still listed). */
    public const NAME_RE = '/^borealis-[0-9]{8}-[0-9]{6}-[a-z0-9-]+\.sql$/';

    public static function create(?\PDO $pdo = null, string $label = 'manual'): string
    {
        $pdo ??= Database::get()->pdo;
        @set_time_limit(600);
        $label = preg_replace('/[^a-z0-9-]/', '', $label) ?: 'copia';
        // 1.16.4: a random part in the name (a copy is never guessable even if storage/ were served) and
        // written to a «.part» file first: a copy cut in the middle (time limit) never looks complete.
        $file = self::dir() . '/borealis-' . gmdate('Ymd-His') . '-' . $label . '-' . bin2hex(random_bytes(6)) . '.sql';
        $tmp = $file . '.part';
        $fh = @fopen($tmp, 'w');
        if ($fh === false) throw new \RuntimeException('No se pudo escribir la copia en storage/backups: revisa los permisos de esa carpeta.');
        try {
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
            if (fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n-- fin de la copia\n") === false) throw new \RuntimeException('No se pudo terminar la copia (¿disco lleno?).');
        } catch (\Throwable $e) {
            fclose($fh);
            @unlink($tmp);
            throw $e;
        }
        fclose($fh);
        if (!@rename($tmp, $file)) { @unlink($tmp); throw new \RuntimeException('No se pudo guardar la copia en storage/backups.'); }
        @chmod($file, 0640);
        self::prune($file);
        return $file;
    }

    /**
     * 1.16.4: keeps the newest KEEP automatic copies (before deleting a person, importing, «completa», cron…)
     * and never the one just made. Copies made by hand («manual») and before an update are never deleted.
     * Also drops leftovers of cut copies.
     */
    public static function prune(?string $keep = null): int
    {
        $files = array_filter(glob(self::dir() . '/borealis-*.sql') ?: [], fn($f) => !preg_match('/-[0-9]{6}-(manual|pre-update)(-[a-f0-9]{12})?\.sql$/', basename($f)));
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a) ?: strcmp($b, $a));
        $gone = 0;
        foreach (array_slice($files, self::KEEP) as $f) {
            if ($keep !== null && realpath($f) === realpath($keep)) continue;
            if (@unlink($f)) $gone++;
        }
        foreach (glob(self::dir() . '/*.part') ?: [] as $f) if (filemtime($f) < time() - 3600) @unlink($f);
        return $gone;
    }

    public static function list(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*.sql') ?: [] as $f) {
            $out[] = ['name' => basename($f), 'size' => filesize($f), 'time' => gmdate('Y-m-d H:i:s', filemtime($f))];
        }
        usort($out, fn($a, $b) => strcmp($b['time'] . $b['name'], $a['time'] . $a['name']));
        return $out;
    }

    /**
     * 1.16.4 · «Descargar todo»: one ZIP with everything needed to rebuild the installation on another
     * server: a fresh SQL copy, config/config.php and storage/ (key, push keys, mail, uploaded files).
     * Only for the installation's administration. Written to a temporary file that is deleted after sending.
     */
    public static function fullArchive(): string
    {
        if (!class_exists(\ZipArchive::class)) throw new \RuntimeException('Este servidor no tiene la extensión zip de PHP: descarga la copia SQL y copia storage/ por FTP.');
        @set_time_limit(0);
        $sql = self::create(null, 'completa');
        $zipPath = self::dir() . '/descarga-' . bin2hex(random_bytes(8)) . '.zip.part';
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('No se pudo crear el ZIP en storage/backups.');
        $zip->addFile($sql, 'base-de-datos/' . basename($sql));
        $cfg = BASE_PATH . '/config/config.php';
        if (is_file($cfg)) $zip->addFile($cfg, 'config/config.php');
        $storage = storage_path();
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($storage, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $rel = substr($f->getPathname(), strlen($storage) + 1);
            $rel = str_replace('\\', '/', $rel);
            // copies (they are inside the SQL above), locks and temporary files are not needed
            if (str_starts_with($rel, 'backups/') || str_ends_with($rel, '.lock') || str_ends_with($rel, '.part')) continue;
            $zip->addFile($f->getPathname(), 'storage/' . $rel);
        }
        $zip->addFromString('LEEME.txt', "Copia completa de Borealis " . BOREALIS_VERSION . " (" . gmdate('Y-m-d H:i') . " UTC)\r\n\r\n"
            . "1. Sube los archivos de Borealis al servidor nuevo.\r\n"
            . "2. Copia config/config.php y la carpeta storage/ de este ZIP encima (cambia en config.php el usuario,\r\n"
            . "   la contraseña y el nombre de la base de datos si son otros, y 'url' si cambia el dominio).\r\n"
            . "   No copies config/storage.php del servidor anterior. Si el servidor nuevo ignora los .htaccess (Nginx),\r\n"
            . "   pulsa después Administración › Datos › «Proteger ahora».\r\n"
            . "3. Importa base-de-datos/*.sql en la base de datos vacía (phpMyAdmin › Importar).\r\n\r\n"
            . "Este ZIP contiene la llave de cifrado y los datos de todas las personas: guárdalo en un lugar seguro.\r\n");
        if (!$zip->close()) throw new \RuntimeException('No se pudo terminar el ZIP (¿disco lleno?).');
        return $zipPath;
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
