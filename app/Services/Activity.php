<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Security\Policy;

final class Activity
{
    public static function log(string $action, string $entityType, ?int $entityId, string $summary, ?int $projectId = null, ?int $taskId = null, array $data = [], ?int $workspaceId = null): void
    {
        Database::get()->insert('activity', [
            'workspace_id' => $workspaceId ?? Policy::wsId(),
            'project_id' => $projectId,
            'task_id' => $taskId,
            'user_id' => Auth::id() ?: null,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'summary' => mb_substr($summary, 0, 255),
            'data' => $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
            'created_at' => now(),
        ]);
    }

    /** Recent activity limited to projects the user can see (plus workspace-level events). */
    public static function recent(int $limit = 15, ?int $projectId = null, ?int $taskId = null): array
    {
        $db = Database::get();
        [$vis, $params] = Policy::visibleProjectsSql('p');
        $where = "a.workspace_id = ? AND (a.project_id IS NULL OR EXISTS (SELECT 1 FROM projects p WHERE p.id = a.project_id AND $vis))";
        $args = array_merge([Policy::wsId()], $params);
        if ($projectId) { $where .= ' AND a.project_id = ?'; $args[] = $projectId; }
        if ($taskId) { $where .= ' AND a.task_id = ?'; $args[] = $taskId; }
        $args[] = $limit;
        return $db->all("SELECT a.*, u.name AS user_name, u.avatar_color, pr.name AS project_name, pr.project_key, t.number AS task_number
            FROM activity a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN projects pr ON pr.id = a.project_id LEFT JOIN tasks t ON t.id = a.task_id
            WHERE $where ORDER BY a.id DESC LIMIT ?", $args);
    }
}
