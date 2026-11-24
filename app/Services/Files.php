<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Validator;
use Borealis\Security\Policy;

/**
 * Uploads live outside the web root (storage/uploads) under random names with
 * no extension, so they can never be executed. Downloads go through a
 * permission-checked controller.
 */
final class Files
{
    private const BLOCKED = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'phps', 'cgi', 'pl', 'py', 'sh', 'exe', 'bat', 'cmd', 'js', 'mjs', 'html', 'htm', 'svg', 'xhtml', 'shtml', 'htaccess', 'jsp', 'asp', 'aspx'];
    private const SIGNATURES = [
        'png' => ['image/png'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'gif' => ['image/gif'],
        'webp' => ['image/webp'], 'pdf' => ['application/pdf'], 'zip' => ['application/zip', 'application/x-zip-compressed'],
    ];
    private const INLINE_IMAGES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public static function dir(): string
    {
        return rtrim((string)config('uploads.path', BASE_PATH . '/storage/uploads'), '/');
    }

    public static function upload(int $projectId, array $file, ?int $taskId = null, ?int $folderId = null): array
    {
        $p = Projects::find($projectId);
        Policy::authorizeProject($p, 'file.upload');
        $db = Database::get();
        if ($taskId) {
            $t = Tasks::find($taskId);
            if ((int)$t['project_id'] !== $projectId) throw HttpException::invalid(['task_id' => 'Tarea no válida.']);
        }
        if ($folderId && !$db->value('SELECT 1 FROM folders WHERE id = ? AND project_id = ?', [$folderId, $projectId])) throw HttpException::invalid(['folder_id' => 'Carpeta no válida.']);

        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw HttpException::invalid(['file' => 'El archivo supera el tamaño máximo permitido.']);
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) throw HttpException::invalid(['file' => 'No se recibió ningún archivo.']);
        ['name' => $name, 'mime' => $mime, 'size' => $size] = self::validate((string)$file['name'], (string)$file['tmp_name']);

        $stored = bin2hex(random_bytes(20));
        $dir = self::dir();
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        $dest = $dir . '/' . $stored;
        $moved = move_uploaded_file($file['tmp_name'], $dest);
        if (!$moved) throw new \RuntimeException('No se pudo guardar el archivo en storage/uploads.');
        @chmod($dest, 0640);

        $id = $db->insert('files', [
            'workspace_id' => Policy::wsId(), 'project_id' => $projectId, 'task_id' => $taskId, 'folder_id' => $folderId, 'uploader_id' => Auth::id(),
            'original_name' => $name, 'stored_name' => $stored, 'mime' => $mime, 'size_bytes' => $size, 'sha256' => hash_file('sha256', $dest), 'created_at' => now(),
        ]);
        Activity::log('file_uploaded', 'file', $id, "Subió «{$name}»" . ($taskId ? ' a ' . $t['task_key'] : ''), $projectId, $taskId);
        return self::find($id);
    }

    /**
     * The single set of checks for any content that becomes a project file (upload, project import).
     * Returns the clean visible name, the detected MIME type and the size; throws 422 otherwise.
     */
    public static function validate(string $originalName, string $path): array
    {
        $max = (int)config('uploads.max_bytes', 10 * 1024 * 1024);
        $size = (int)@filesize($path);
        if ($size <= 0) throw HttpException::invalid(['file' => 'El archivo está vacío.']);
        if ($size > $max) throw HttpException::invalid(['file' => 'El archivo supera el máximo de ' . round($max / 1048576, 1) . ' MB.']);

        $name = trim(str_replace(["\0", '/', '\\'], '', $originalName));
        $name = mb_substr($name !== '' ? $name : 'archivo', 0, 200);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = (array)config('uploads.allowed_extensions', []);
        $parts = array_map('strtolower', explode('.', $name));
        foreach (array_slice($parts, 1) as $part) {
            if (in_array($part, self::BLOCKED, true)) throw HttpException::invalid(['file' => 'Este tipo de archivo no está permitido.']);
        }
        if ($ext === '' || !in_array($ext, $allowed, true)) throw HttpException::invalid(['file' => 'Tipo de archivo no permitido. Formatos aceptados: ' . implode(', ', $allowed) . '.']);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        if (str_contains($mime, 'php') || str_contains($mime, 'html') || str_contains($mime, 'javascript') || $mime === 'image/svg+xml') {
            throw HttpException::invalid(['file' => 'El contenido del archivo no está permitido.']);
        }
        // Formats with a recognisable signature must really be what their extension says.
        $expected = self::SIGNATURES[$ext] ?? null;
        if ($expected && !in_array($mime, $expected, true)) {
            throw HttpException::invalid(['file' => 'El contenido no corresponde a un archivo .' . $ext . '.']);
        }
        return ['name' => $name, 'mime' => $mime, 'size' => $size];
    }

    public static function find(int $id): array
    {
        $f = Database::get()->one('SELECT f.*, u.name AS uploader_name FROM files f LEFT JOIN users u ON u.id = f.uploader_id WHERE f.id = ? AND f.workspace_id = ? AND f.deleted_at IS NULL', [$id, Policy::wsId()]);
        if (!$f) throw HttpException::notFound('El archivo no existe.');
        Projects::find((int)$f['project_id']);
        return $f;
    }

    public static function path(array $f): string
    {
        $path = self::dir() . '/' . basename((string)$f['stored_name']);
        if (!is_file($path)) throw HttpException::notFound('El archivo no se encuentra en el almacenamiento.');
        return $path;
    }

    public static function isInlineImage(array $f): bool
    {
        return in_array($f['mime'], self::INLINE_IMAGES, true);
    }

    public static function list(int $projectId, array $f = []): array
    {
        $where = 'f.project_id = ? AND f.deleted_at IS NULL';
        $params = [$projectId];
        if (!empty($f['task_id'])) { $where .= ' AND f.task_id = ?'; $params[] = (int)$f['task_id']; }
        if (array_key_exists('folder_id', $f) && empty($f['q'])) {
            if ($f['folder_id']) { $where .= ' AND f.folder_id = ?'; $params[] = (int)$f['folder_id']; }
            else $where .= ' AND f.folder_id IS NULL';
        }
        if (!empty($f['q'])) { $where .= ' AND f.original_name LIKE ?'; $params[] = '%' . $f['q'] . '%'; }
        return Database::get()->all("SELECT f.*, u.name AS uploader_name, CONCAT(p.project_key,'-',t.number) AS task_key
            FROM files f LEFT JOIN users u ON u.id = f.uploader_id LEFT JOIN tasks t ON t.id = f.task_id JOIN projects p ON p.id = f.project_id
            WHERE $where ORDER BY f.created_at DESC", $params);
    }

    public static function delete(int $id): void
    {
        $f = self::find($id);
        $p = Projects::find((int)$f['project_id']);
        $own = (int)$f['uploader_id'] === Auth::id() && Policy::canProject($p, 'file.upload');
        if (!$own) Policy::authorizeProject($p, 'file.delete');
        // Soft delete keeps the stored bytes until an administrator purges them.
        Database::get()->update('files', ['deleted_at' => now()], 'id = ?', [$id]);
        Activity::log('file_deleted', 'file', $id, "Eliminó «{$f['original_name']}»", (int)$f['project_id'], $f['task_id'] ? (int)$f['task_id'] : null);
    }

    /** Changes the name people see; the stored file and its extension stay the same. */
    public static function rename(int $id, string $name): array
    {
        $f = self::find($id);
        $p = Projects::find((int)$f['project_id']);
        $own = (int)$f['uploader_id'] === Auth::id() && Policy::canProject($p, 'file.upload');
        if (!$own) Policy::authorizeProject($p, 'file.delete');
        $name = trim(str_replace(["\0", '/', '\\'], '', $name));
        if ($name === '') throw HttpException::invalid(['name' => 'Escribe un nombre.']);
        $ext = strtolower(pathinfo((string)$f['original_name'], PATHINFO_EXTENSION));
        if ($ext !== '' && strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== $ext) $name .= '.' . $ext;
        foreach (array_slice(array_map('strtolower', explode('.', $name)), 1) as $part) {
            if (in_array($part, self::BLOCKED, true)) throw HttpException::invalid(['name' => 'Ese nombre no está permitido.']);
        }
        $name = mb_substr($name, 0, 200);
        Database::get()->update('files', ['original_name' => $name], 'id = ?', [$id]);
        Activity::log('file_renamed', 'file', $id, "Renombró «{$f['original_name']}» a «{$name}»", (int)$f['project_id'], $f['task_id'] ? (int)$f['task_id'] : null);
        return self::find($id);
    }

    public static function move(int $id, ?int $folderId): void
    {
        $f = self::find($id);
        $p = Projects::find((int)$f['project_id']);
        Policy::authorizeProject($p, 'file.upload');
        if ($folderId && !Database::get()->value('SELECT 1 FROM folders WHERE id = ? AND project_id = ?', [$folderId, $p['id']])) throw HttpException::invalid(['folder_id' => 'Carpeta no válida.']);
        Database::get()->update('files', ['folder_id' => $folderId], 'id = ?', [$id]);
    }

    // ---- Folders --------------------------------------------------------

    public static function folders(int $projectId, ?int $parentId): array
    {
        return Database::get()->all('SELECT fo.*, (SELECT COUNT(*) FROM files f WHERE f.folder_id = fo.id AND f.deleted_at IS NULL) AS file_count FROM folders fo WHERE fo.project_id = ? AND ' . ($parentId ? 'fo.parent_id = ?' : 'fo.parent_id IS NULL') . ' ORDER BY fo.name', $parentId ? [$projectId, $parentId] : [$projectId]);
    }

    public static function folder(int $projectId, int $id): array
    {
        return Database::get()->one('SELECT * FROM folders WHERE id = ? AND project_id = ?', [$id, $projectId]) ?? throw HttpException::notFound('La carpeta no existe.');
    }

    public static function breadcrumbs(int $projectId, ?int $folderId): array
    {
        $out = [];
        $guard = 0;
        while ($folderId && $guard++ < 20) {
            $f = self::folder($projectId, $folderId);
            array_unshift($out, $f);
            $folderId = $f['parent_id'] ? (int)$f['parent_id'] : null;
        }
        return $out;
    }

    public static function createFolder(int $projectId, string $name, ?int $parentId): int
    {
        $p = Projects::find($projectId);
        Policy::authorizeProject($p, 'file.upload');
        $v = Validator::check(['name' => $name], ['name' => 'required,string,max:120']);
        if ($parentId) self::folder($projectId, $parentId);
        $id = Database::get()->insert('folders', ['project_id' => $projectId, 'parent_id' => $parentId, 'name' => $v['name'], 'created_by' => Auth::id(), 'created_at' => now()]);
        Activity::log('folder_created', 'folder', $id, "Creó la carpeta «{$v['name']}»", $projectId);
        return $id;
    }

    public static function renameFolder(int $projectId, int $id, string $name): void
    {
        $p = Projects::find($projectId);
        Policy::authorizeProject($p, 'file.upload');
        self::folder($projectId, $id);
        $v = Validator::check(['name' => $name], ['name' => 'required,string,max:120']);
        Database::get()->update('folders', ['name' => $v['name']], 'id = ?', [$id]);
    }

    /** Delete an empty folder only: files are never removed implicitly. */
    public static function deleteFolder(int $projectId, int $id): void
    {
        $p = Projects::find($projectId);
        Policy::authorizeProject($p, 'file.delete');
        self::folder($projectId, $id);
        $db = Database::get();
        if ($db->value('SELECT 1 FROM files WHERE folder_id = ? AND deleted_at IS NULL', [$id]) || $db->value('SELECT 1 FROM folders WHERE parent_id = ?', [$id])) {
            throw HttpException::conflict('La carpeta no está vacía. Mueve o elimina su contenido primero.');
        }
        $db->run('DELETE FROM folders WHERE id = ?', [$id]);
    }

    public static function humanSize(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024) . ' KB';
        return round($bytes / 1048576, 1) . ' MB';
    }
}
