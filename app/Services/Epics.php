<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Validator;
use Borealis\Security\Policy;

final class Epics
{
    public static function list(int $projectId): array
    {
        return Database::get()->all("SELECT e.*,
            (SELECT COUNT(*) FROM tasks t WHERE t.epic_id = e.id AND t.deleted_at IS NULL) AS task_count,
            (SELECT COUNT(*) FROM tasks t JOIN statuses s ON s.id = t.status_id WHERE t.epic_id = e.id AND t.deleted_at IS NULL AND s.category = 'done') AS done_count
            FROM epics e WHERE e.project_id = ? ORDER BY e.is_closed, e.name", [$projectId]);
    }

    private static function load(int $projectId, int $id): array
    {
        return Database::get()->one('SELECT * FROM epics WHERE id = ? AND project_id = ?', [$id, $projectId]) ?? throw HttpException::notFound('La épica no existe.');
    }

    public static function save(int $projectId, ?int $id, array $input): int
    {
        $p = Projects::find($projectId);
        Modules::require($p, 'epics');
        Policy::authorizeProject($p, 'project.manage');
        $rules = ['name' => 'required,string,max:160', 'description' => 'nullable,string,max:5000', 'color' => 'nullable,color', 'is_closed' => 'nullable,bool'];
        if ($id) $rules = array_map(fn($r) => 'sometimes,' . $r, $rules);
        $v = Validator::check($input, $rules);
        $db = Database::get();
        if ($id) {
            $e = self::load($projectId, $id);
            if (array_key_exists('is_closed', $v)) $v['is_closed'] = $v['is_closed'] ? 1 : 0;
            if (array_key_exists('color', $v) && !$v['color']) unset($v['color']);
            if ($v) $db->update('epics', $v + ['updated_at' => now()], 'id = ?', [$id]);
            Activity::log('epic_updated', 'epic', $id, 'Actualizó la épica «' . ($v['name'] ?? $e['name']) . '»', $projectId);
            return $id;
        }
        $id = $db->insert('epics', ['project_id' => $projectId, 'name' => $v['name'], 'description' => $v['description'], 'color' => $v['color'] ?? '#8B5CF6', 'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now()]);
        Activity::log('epic_created', 'epic', $id, "Creó la épica «{$v['name']}»", $projectId);
        return $id;
    }

    public static function delete(int $projectId, int $id): void
    {
        $p = Projects::find($projectId);
        Modules::require($p, 'epics');
        Policy::authorizeProject($p, 'project.manage');
        $e = self::load($projectId, $id);
        Database::get()->run('DELETE FROM epics WHERE id = ?', [$id]); // tasks keep existing, epic_id set to NULL
        Activity::log('epic_deleted', 'epic', $id, "Eliminó la épica «{$e['name']}» (sus tareas se conservan)", $projectId);
    }
}
