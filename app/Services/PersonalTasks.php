<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Validator;

/**
 * «Mis tareas» (1.6): personal to-dos, private to their owner.
 *
 * Every query is filtered by the signed-in person's id; there is no administrative way to read
 * someone else's personal tasks, and they never appear in projects, team views, reports,
 * exports or sprint capacity. They are separate from project tasks on purpose.
 */
final class PersonalTasks
{
    public const PRIORITIES = ['none' => 'Sin prioridad', 'low' => 'Baja', 'medium' => 'Media', 'high' => 'Alta'];

    // ---- lists ------------------------------------------------------------------

    /** The person's lists; a first list «Mis tareas» is created the first time. */
    public static function lists(): array
    {
        $db = Database::get();
        $uid = Auth::id();
        $rows = $db->all('SELECT l.*, (SELECT COUNT(*) FROM personal_tasks t WHERE t.list_id = l.id AND t.deleted_at IS NULL AND t.parent_id IS NULL AND t.completed_at IS NULL) AS open_count
            FROM personal_lists l WHERE l.user_id = ? AND l.deleted_at IS NULL ORDER BY l.position, l.id', [$uid]);
        if (!$rows) {
            $db->insert('personal_lists', ['user_id' => $uid, 'name' => 'Mis tareas', 'position' => 1024, 'created_at' => now(), 'updated_at' => now()]);
            return self::lists();
        }
        return $rows;
    }

    public static function list(int $id): array
    {
        $l = Database::get()->one('SELECT * FROM personal_lists WHERE id = ? AND user_id = ? AND deleted_at IS NULL', [$id, Auth::id()]);
        if (!$l) throw HttpException::notFound('Esa lista no existe.');
        return $l;
    }

    public static function createList(array $input): int
    {
        $v = Validator::check($input, ['name' => 'required,string,max:120']);
        $db = Database::get();
        $pos = (float)$db->value('SELECT COALESCE(MAX(position), 0) FROM personal_lists WHERE user_id = ?', [Auth::id()]) + 1024;
        return $db->insert('personal_lists', ['user_id' => Auth::id(), 'name' => trim($v['name']), 'position' => $pos, 'created_at' => now(), 'updated_at' => now()]);
    }

    public static function renameList(int $id, array $input): void
    {
        self::list($id);
        $v = Validator::check($input, ['name' => 'required,string,max:120']);
        Database::get()->update('personal_lists', ['name' => trim($v['name']), 'updated_at' => now()], 'id = ?', [$id]);
    }

    /** Deleting a list hides it and its tasks (recoverable in the database); the last list cannot go. */
    public static function deleteList(int $id): void
    {
        self::list($id);
        $db = Database::get();
        if ((int)$db->value('SELECT COUNT(*) FROM personal_lists WHERE user_id = ? AND deleted_at IS NULL', [Auth::id()]) <= 1) {
            throw HttpException::conflict('Necesitas al menos una lista.');
        }
        $db->transaction(function (Database $db) use ($id) {
            $db->update('personal_lists', ['deleted_at' => now(), 'updated_at' => now()], 'id = ?', [$id]);
            $db->run('UPDATE personal_tasks SET deleted_at = ?, updated_at = ? WHERE list_id = ? AND deleted_at IS NULL', [now(), now(), $id]);
        });
    }

    // ---- tasks -------------------------------------------------------------------

    /** Tasks of one list (top level first, each followed by its subtasks). $show: open | done | all. */
    public static function tasks(int $listId, string $show = 'open'): array
    {
        self::list($listId);
        $where = $show === 'done' ? 'AND t.completed_at IS NOT NULL' : ($show === 'all' ? '' : 'AND (t.completed_at IS NULL OR t.parent_id IS NOT NULL)');
        $rows = Database::get()->all("SELECT t.* FROM personal_tasks t WHERE t.list_id = ? AND t.user_id = ? AND t.deleted_at IS NULL $where
            ORDER BY t.parent_id IS NOT NULL, " . ($show === 'done' ? 't.completed_at DESC' : 't.position, t.id'), [$listId, Auth::id()]);
        $top = [];
        $subs = [];
        foreach ($rows as $r) {
            if ($r['parent_id']) $subs[(int)$r['parent_id']][] = $r; else $top[] = $r;
        }
        foreach ($top as &$t) $t['subtasks'] = $subs[(int)$t['id']] ?? [];
        unset($t);
        if ($show === 'done') {
            // completed subtasks whose parent is still open are listed on their own
            $ids = array_map(fn($t) => (int)$t['id'], $top);
            foreach ($subs as $pid => $list) if (!in_array($pid, $ids, true)) foreach ($list as $s) $top[] = $s + ['subtasks' => []];
        }
        return $top;
    }

    /** All open personal tasks with a due date in [from, to] (for the calendar). */
    public static function dueBetween(string $from, string $to): array
    {
        return Database::get()->all('SELECT t.*, l.name AS list_name FROM personal_tasks t JOIN personal_lists l ON l.id = t.list_id AND l.deleted_at IS NULL
            WHERE t.user_id = ? AND t.deleted_at IS NULL AND t.due_date BETWEEN ? AND ? ORDER BY t.due_date, t.position', [Auth::id(), $from, $to]);
    }

    public static function find(int $id): array
    {
        $t = Database::get()->one('SELECT * FROM personal_tasks WHERE id = ? AND user_id = ? AND deleted_at IS NULL', [$id, Auth::id()]);
        if (!$t) throw HttpException::notFound('Esa tarea no existe.');
        $t['subtasks'] = Database::get()->all('SELECT * FROM personal_tasks WHERE parent_id = ? AND user_id = ? AND deleted_at IS NULL ORDER BY position, id', [$id, Auth::id()]);
        return $t;
    }

    private static function rules(bool $creating): array
    {
        $r = ['title' => 'required,string,max:255', 'notes' => 'nullable,string,max:20000', 'priority' => 'nullable,in:' . implode('|', array_keys(self::PRIORITIES)),
            'due_date' => 'nullable,date', 'list_id' => 'nullable,int', 'parent_id' => 'nullable,int'];
        return $creating ? $r : array_map(fn($x) => 'sometimes,' . $x, $r);
    }

    public static function create(array $input): array
    {
        $v = Validator::check($input, self::rules(true));
        $db = Database::get();
        $parent = null;
        if (!empty($v['parent_id'])) {
            $parent = self::find((int)$v['parent_id']);
            if ($parent['parent_id']) throw HttpException::invalid(['parent_id' => 'Las subtareas no pueden tener subtareas.']);
            $listId = (int)$parent['list_id'];
        } else {
            $listId = !empty($v['list_id']) ? (int)self::list((int)$v['list_id'])['id'] : (int)self::lists()[0]['id'];
        }
        $pos = (float)$db->value('SELECT COALESCE(MAX(position), 0) FROM personal_tasks WHERE list_id = ? AND ' . ($parent ? 'parent_id = ?' : 'parent_id IS NULL'), $parent ? [$listId, (int)$parent['id']] : [$listId]) + 1024;
        $id = $db->insert('personal_tasks', [
            'list_id' => $listId, 'user_id' => Auth::id(), 'parent_id' => $parent['id'] ?? null, 'title' => trim($v['title']), 'notes' => $v['notes'] ?: null,
            'priority' => $v['priority'] ?: 'none', 'due_date' => $v['due_date'] ?: null, 'position' => $pos, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return self::find($id);
    }

    public static function update(int $id, array $input): array
    {
        $t = self::find($id);
        $v = Validator::check($input, self::rules(false));
        $data = [];
        foreach (['title', 'notes', 'priority', 'due_date'] as $k) {
            if (!array_key_exists($k, $v)) continue;
            $val = $k === 'title' ? trim((string)$v[$k]) : ($v[$k] === '' ? null : $v[$k]);
            if ($k === 'priority') $val = $val ?: 'none';
            if ((string)$val !== (string)($t[$k] ?? '')) $data[$k] = $val;
        }
        if (array_key_exists('done', $input)) {
            $done = in_array($input['done'], [true, 1, '1', 'true', 'on'], true);
            if ($done !== ($t['completed_at'] !== null)) $data['completed_at'] = $done ? now() : null;
        }
        if (!empty($v['list_id']) && (int)$v['list_id'] !== (int)$t['list_id'] && !$t['parent_id']) {
            $data['list_id'] = (int)self::list((int)$v['list_id'])['id'];
        }
        if (!$data) return $t;
        $data['updated_at'] = now();
        $data['pushed_at'] = null; // pending for Google Tasks, when the list is linked
        $db = Database::get();
        $db->transaction(function (Database $db) use ($id, $data) {
            $db->update('personal_tasks', $data, 'id = ?', [$id]);
            if (isset($data['list_id'])) $db->run('UPDATE personal_tasks SET list_id = ?, updated_at = ? WHERE parent_id = ?', [$data['list_id'], now(), $id]);
            // completing a task completes its open subtasks too (as in Google Tasks); reopening leaves them as they are
            if (!empty($data['completed_at'])) $db->run('UPDATE personal_tasks SET completed_at = ?, updated_at = ?, pushed_at = NULL WHERE parent_id = ? AND completed_at IS NULL AND deleted_at IS NULL', [$data['completed_at'], now(), $id]);
        });
        return self::find($id);
    }

    public static function delete(int $id): void
    {
        self::find($id);
        Database::get()->run('UPDATE personal_tasks SET deleted_at = ?, updated_at = ? WHERE (id = ? OR parent_id = ?) AND user_id = ?', [now(), now(), $id, $id, Auth::id()]);
    }

    /** Reorders top-level tasks of a list (or the subtasks of one task) to the given order of ids. */
    public static function reorder(int $listId, array $ids, ?int $parentId = null): void
    {
        self::list($listId);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $db = Database::get();
        $own = array_map('intval', $db->column('SELECT id FROM personal_tasks WHERE list_id = ? AND user_id = ? AND deleted_at IS NULL AND ' . ($parentId ? 'parent_id = ?' : 'parent_id IS NULL'),
            $parentId ? [$listId, Auth::id(), $parentId] : [$listId, Auth::id()]));
        if (array_diff($ids, $own)) throw HttpException::invalid(['ids' => 'La lista cambió; recarga la página.']);
        $db->transaction(function (Database $db) use ($ids) {
            foreach ($ids as $i => $tid) $db->update('personal_tasks', ['position' => ($i + 1) * 1024, 'updated_at' => now()], 'id = ?', [$tid]);
        });
    }
}
