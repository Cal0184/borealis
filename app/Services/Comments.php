<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Validator;
use Borealis\Security\Policy;

final class Comments
{
    public static function forTask(int $taskId): array
    {
        return Database::get()->all('SELECT c.*, u.name AS user_name, u.avatar_color FROM comments c LEFT JOIN users u ON u.id = c.user_id
            WHERE c.task_id = ? AND c.deleted_at IS NULL ORDER BY c.created_at, c.id', [$taskId]);
    }

    public static function create(int $taskId, string $body): int
    {
        $t = Tasks::find($taskId);
        Policy::authorizeProject($t['project'], 'comment.create');
        $v = Validator::check(['body' => $body], ['body' => 'required,string,max:10000']);
        $id = Database::get()->insert('comments', ['task_id' => $taskId, 'user_id' => Auth::id(), 'body' => $v['body'], 'created_at' => now()]);
        Activity::log('commented', 'task', $taskId, "Comentó en {$t['task_key']}", (int)$t['project_id'], $t['parent_id'] ? (int)$t['parent_id'] : $taskId);
        Database::get()->update('tasks', ['updated_at' => now()], 'id = ?', [$taskId]);

        $link = url('/t/' . $t['task_key']);
        $mentioned = self::mentions($v['body'], $t['project']);
        foreach ($mentioned as $uid) {
            Notifications::send($uid, 'mention', Auth::user()['name'] . " te mencionó en {$t['task_key']}", $link, (int)$t['project_id'], $taskId);
        }
        // Relevant people: assignee and reporter (not already notified by a mention).
        foreach (array_unique(array_filter(array_merge([(int)$t['assignee_id'], (int)$t['reporter_id']], array_map('intval', array_column($t['co_assignees'] ?? [], 'id'))))) as $uid) {
            if (!in_array($uid, $mentioned, true)) {
                Notifications::send($uid, 'comment', Auth::user()['name'] . " comentó en {$t['task_key']}: {$t['title']}", $link, (int)$t['project_id'], $taskId, "comment:$id:$uid");
            }
        }
        return $id;
    }

    /** @handle mentions that resolve to project members. */
    public static function mentions(string $body, array $project): array
    {
        if (!preg_match_all('/(?:^|\s)@([a-z0-9._-]{2,60})/u', $body, $m)) return [];
        $handles = array_values(array_unique($m[1]));
        $members = Projects::members($project);
        $out = [];
        foreach ($members as $mem) if (in_array($mem['handle'], $handles, true)) $out[] = (int)$mem['id'];
        return $out;
    }

    public static function update(int $id, string $body): void
    {
        $c = self::own($id);
        $v = Validator::check(['body' => $body], ['body' => 'required,string,max:10000']);
        Database::get()->update('comments', ['body' => $v['body'], 'edited_at' => now()], 'id = ?', [$id]);
        unset($c);
    }

    public static function delete(int $id): void
    {
        $c = Database::get()->one('SELECT * FROM comments WHERE id = ? AND deleted_at IS NULL', [$id]) ?? throw HttpException::notFound();
        $t = Tasks::find((int)$c['task_id']);
        if ((int)$c['user_id'] !== Auth::id() && !Policy::canProject($t['project'], 'project.manage')) throw HttpException::forbidden('Solo puedes eliminar tus comentarios.');
        Database::get()->update('comments', ['deleted_at' => now()], 'id = ?', [$id]);
        Activity::log('comment_deleted', 'task', (int)$c['task_id'], "Eliminó un comentario de {$t['task_key']}", (int)$t['project_id'], (int)$c['task_id']);
    }

    private static function own(int $id): array
    {
        $c = Database::get()->one('SELECT * FROM comments WHERE id = ? AND deleted_at IS NULL', [$id]) ?? throw HttpException::notFound();
        $t = Tasks::find((int)$c['task_id']);
        Policy::authorizeProject($t['project'], 'comment.create');
        if ((int)$c['user_id'] !== Auth::id()) throw HttpException::forbidden('Solo puedes editar tus comentarios.');
        return $c;
    }
}
