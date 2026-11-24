<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Database;
use Borealis\Security\Policy;

final class Search
{
    public static function run(string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) return ['projects' => [], 'tasks' => [], 'teams' => [], 'people' => []];
        $like = '%' . $q . '%';
        $db = Database::get();
        // 1.4.1: guests only find the people and teams within their reach, and never by e-mail.
        $users = Policy::visibleUserIds();
        $teams = Policy::visibleTeamIds();
        $teamSql = 'SELECT id, name, color FROM teams WHERE workspace_id = ? AND name LIKE ?';
        $teamParams = [Policy::wsId(), $like];
        if ($teams !== null) {
            if (!$teams) $teamSql .= ' AND 0';
            else { $teamSql .= ' AND id IN (' . Database::in($teams) . ')'; $teamParams = array_merge($teamParams, $teams); }
        }
        $peopleSql = "SELECT u.id, u.name, u.title, u.avatar_color FROM workspace_members wm JOIN users u ON u.id = wm.user_id WHERE wm.workspace_id = ? AND wm.status = 'active'";
        $peopleParams = [Policy::wsId()];
        if ($users === null) {
            $peopleSql .= ' AND (u.name LIKE ? OR u.email LIKE ?)';
            array_push($peopleParams, $like, $like);
        } else {
            $peopleSql .= ' AND u.name LIKE ? AND u.id IN (' . Database::in($users) . ')';
            $peopleParams = array_merge($peopleParams, [$like], $users);
        }
        return [
            'projects' => Projects::list(['q' => $q, 'archived' => 'all']),
            'tasks' => Tasks::query(['q' => $q, 'with_subtasks' => 1, 'sort' => 'updated', 'limit' => 30]),
            'teams' => $db->all($teamSql . ' ORDER BY name LIMIT 10', $teamParams),
            'people' => $db->all($peopleSql . ' ORDER BY u.name LIMIT 10', $peopleParams),
        ];
    }
}
