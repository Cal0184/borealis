<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Validator;
use Borealis\Security\Policy;

/**
 * 1.15 · saved filters of «Tareas › Equipo» (personal, or shared with the whole workspace).
 * Only the filter values are stored (the query string), never results: a shared view shows each
 * person only what they can see.
 */
final class SavedViews
{
    /** Filter keys a view may keep. */
    public const KEYS = ['project_id', 'assignee', 'priority_id', 'q', 'due', 'view', 'group', 'blocked'];

    public static function list(string $page = 'tasks'): array
    {
        if (!Activity::v115()) return [];
        return Database::get()->all('SELECT v.*, u.name AS owner_name FROM saved_views v LEFT JOIN users u ON u.id = v.user_id
            WHERE v.workspace_id = ? AND v.page = ? AND (v.user_id = ? OR v.shared = 1) ORDER BY v.user_id <> ?, v.name', [Policy::wsId(), $page, Auth::id(), Auth::id()]);
    }

    public static function create(array $in): int
    {
        $v = Validator::check($in, ['name' => 'required,string,max:80', 'query' => 'required,string,max:1000', 'shared' => 'sometimes,bool']);
        parse_str((string)$v['query'], $q);
        $q = array_intersect_key($q, array_flip(self::KEYS));
        $q = array_filter(array_map(fn($x) => is_scalar($x) ? mb_substr(trim((string)$x), 0, 80) : '', $q), fn($x) => $x !== '');
        if (!$q) throw HttpException::invalid(['query' => 'Elige al menos un filtro antes de guardar la vista.']);
        $shared = !empty($v['shared']) && Policy::can('projects.create') ? 1 : 0;
        $db = Database::get();
        if ((int)$db->value('SELECT COUNT(*) FROM saved_views WHERE workspace_id = ? AND user_id = ?', [Policy::wsId(), Auth::id()]) >= 30) {
            throw HttpException::invalid(['name' => 'Ya tienes 30 vistas guardadas. Borra alguna para guardar otra.']);
        }
        return $db->insert('saved_views', ['workspace_id' => Policy::wsId(), 'user_id' => Auth::id(), 'page' => 'tasks', 'name' => $v['name'], 'query' => http_build_query($q), 'shared' => $shared, 'created_at' => now()]);
    }

    public static function delete(int $id): void
    {
        $db = Database::get();
        $row = $db->one('SELECT * FROM saved_views WHERE id = ? AND workspace_id = ?', [$id, Policy::wsId()]);
        if (!$row) throw HttpException::notFound();
        if ((int)$row['user_id'] !== Auth::id() && !Policy::can('workspace.manage')) throw HttpException::forbidden('Solo quien creó la vista puede borrarla.');
        $db->run('DELETE FROM saved_views WHERE id = ?', [$id]);
    }
}
