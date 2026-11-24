<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Validator;
use Borealis\Security\Policy;

final class Docs
{
    public static function list(int $projectId, ?string $q = null): array
    {
        $sql = 'SELECT d.id, d.title, d.updated_at, u.name AS updated_by_name FROM documents d LEFT JOIN users u ON u.id = d.updated_by WHERE d.project_id = ?';
        $params = [$projectId];
        if ($q) { $sql .= ' AND (d.title LIKE ? OR d.body LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }
        return Database::get()->all($sql . ' ORDER BY d.title', $params);
    }

    public static function find(int $projectId, int $id): array
    {
        return Database::get()->one('SELECT d.*, u.name AS updated_by_name FROM documents d LEFT JOIN users u ON u.id = d.updated_by WHERE d.id = ? AND d.project_id = ?', [$id, $projectId])
            ?? throw HttpException::notFound('El documento no existe.');
    }

    public static function save(int $projectId, ?int $id, array $input): int
    {
        $p = Projects::find($projectId);
        Modules::require($p, 'docs');
        Policy::authorizeProject($p, 'doc.edit');
        $v = Validator::check($input, ['title' => 'required,string,max:200', 'body' => 'nullable,string,max:200000']);
        $db = Database::get();
        if ($id) {
            self::find($projectId, $id);
            $db->update('documents', ['title' => $v['title'], 'body' => $v['body'], 'updated_by' => Auth::id(), 'updated_at' => now()], 'id = ?', [$id]);
            Activity::log('doc_updated', 'document', $id, "Editó el documento «{$v['title']}»", $projectId);
            return $id;
        }
        $id = $db->insert('documents', ['project_id' => $projectId, 'title' => $v['title'], 'body' => $v['body'], 'created_by' => Auth::id(), 'updated_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now()]);
        Activity::log('doc_created', 'document', $id, "Creó el documento «{$v['title']}»", $projectId);
        return $id;
    }

    public static function delete(int $projectId, int $id): void
    {
        $p = Projects::find($projectId);
        Modules::require($p, 'docs');
        Policy::authorizeProject($p, 'project.manage');
        $d = self::find($projectId, $id);
        Database::get()->run('DELETE FROM documents WHERE id = ?', [$id]);
        Activity::log('doc_deleted', 'document', $id, "Eliminó el documento «{$d['title']}»", $projectId, null, ['body' => mb_substr((string)$d['body'], 0, 20000)]);
    }
}
