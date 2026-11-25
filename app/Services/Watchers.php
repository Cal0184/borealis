<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;

/**
 * 1.15 · people who follow a task without being responsible for it («Seguir»).
 *
 * They hear about new comments and changes of state. Whoever comments is added automatically (they
 * can stop following with one click). Responsible people and the reporter already get those notices,
 * so they are never notified twice. Each person decides in the profile whether these notices reach
 * the phone («Tareas que sigues»).
 */
final class Watchers
{
    public static function ready(): bool
    {
        return Activity::v115();
    }

    public static function toggle(int $taskId): bool
    {
        Tasks::find($taskId); // access check
        $db = Database::get();
        if (self::isWatching($taskId)) {
            $db->run('DELETE FROM task_watchers WHERE task_id = ? AND user_id = ?', [$taskId, Auth::id()]);
            return false;
        }
        self::add($taskId, (int)Auth::id());
        return true;
    }

    public static function add(int $taskId, int $userId): void
    {
        if (!self::ready() || !$userId) return;
        Database::get()->run('INSERT IGNORE INTO task_watchers (task_id, user_id, created_at) VALUES (?,?,?)', [$taskId, $userId, now()]);
    }

    public static function isWatching(int $taskId): bool
    {
        if (!self::ready()) return false;
        return (bool)Database::get()->value('SELECT 1 FROM task_watchers WHERE task_id = ? AND user_id = ?', [$taskId, Auth::id()]);
    }

    /** @return array<int, array{id:int,name:string}> */
    public static function of(int $taskId): array
    {
        if (!self::ready()) return [];
        return Database::get()->all('SELECT u.id, u.name, u.avatar_color FROM task_watchers w JOIN users u ON u.id = w.user_id AND u.is_active = 1 WHERE w.task_id = ? ORDER BY u.name', [$taskId]);
    }

    /**
     * Notifies the followers of a task, except whoever did it and the people in $skip (already told).
     * Only people who can still see the project receive it.
     */
    public static function notify(array $t, string $message, array $skip = [], ?string $dedupe = null): void
    {
        if (!self::ready()) return;
        $skip = array_map('intval', array_merge($skip, [(int)Auth::id(), (int)$t['assignee_id'], (int)$t['reporter_id']], array_column($t['co_assignees'] ?? [], 'id')));
        foreach (self::of((int)$t['id']) as $w) {
            $uid = (int)$w['id'];
            if (in_array($uid, $skip, true)) continue;
            if (!Database::get()->value("SELECT 1 FROM workspace_members WHERE user_id = ? AND workspace_id = ? AND status = 'active'", [$uid, (int)$t['project']['workspace_id']])) continue;
            Notifications::send($uid, 'watch', $message, url('/t/' . $t['task_key']), (int)$t['project_id'], (int)$t['id'], $dedupe ? "$dedupe:$uid" : null);
        }
    }
}
