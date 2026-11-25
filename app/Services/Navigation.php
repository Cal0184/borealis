<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Security\Policy;

/**
 * 1.15 · favorites and recently opened items, per person and workspace.
 *
 * Favorites: projects, tasks and teams marked with the star; they show at the top of the sidebar.
 * Recents: the last projects and tasks a person opened (the quick search shows them before typing).
 * Both only ever list what the person can still see: access is checked on every read, so losing
 * access to a project also removes it from these lists.
 */
final class Navigation
{
    public const TYPES = ['project', 'task', 'team'];
    private const MAX_FAVORITES = 20;
    private const KEEP_RECENT = 30;

    public static function ready(): bool
    {
        return Activity::v115();
    }

    /** Marks or unmarks; returns the new state. */
    public static function toggleFavorite(string $type, int $id): bool
    {
        if (!in_array($type, self::TYPES, true)) throw HttpException::invalid(['type' => 'No se puede marcar eso como favorito.']);
        self::assertVisible($type, $id);
        $db = Database::get();
        $key = [Auth::id(), Policy::wsId(), $type, $id];
        if ($db->value('SELECT 1 FROM user_favorites WHERE user_id = ? AND workspace_id = ? AND entity_type = ? AND entity_id = ?', $key)) {
            $db->run('DELETE FROM user_favorites WHERE user_id = ? AND workspace_id = ? AND entity_type = ? AND entity_id = ?', $key);
            return false;
        }
        if ((int)$db->value('SELECT COUNT(*) FROM user_favorites WHERE user_id = ? AND workspace_id = ?', [Auth::id(), Policy::wsId()]) >= self::MAX_FAVORITES) {
            throw HttpException::invalid(['type' => 'Ya tienes ' . self::MAX_FAVORITES . ' favoritos. Quita alguno para agregar otro.']);
        }
        $db->run('INSERT INTO user_favorites (user_id, workspace_id, entity_type, entity_id, position, created_at) VALUES (?,?,?,?,0,?)', array_merge($key, [now()]));
        return true;
    }

    public static function isFavorite(string $type, int $id): bool
    {
        if (!self::ready()) return false;
        return (bool)Database::get()->value('SELECT 1 FROM user_favorites WHERE user_id = ? AND workspace_id = ? AND entity_type = ? AND entity_id = ?', [Auth::id(), Policy::wsId(), $type, $id]);
    }

    /** Favorites the person can still see: [type, id, label, sub, url, color]. */
    public static function favorites(): array
    {
        if (!self::ready()) return [];
        return self::resolve(Database::get()->all('SELECT entity_type, entity_id FROM user_favorites WHERE user_id = ? AND workspace_id = ? ORDER BY position, created_at',
            [Auth::id(), Policy::wsId()]));
    }

    /** Remembers that the person opened a project or a task (one row per item, the date moves). */
    public static function touch(string $type, int $id): void
    {
        if (!self::ready() || !Auth::id() || !in_array($type, ['project', 'task'], true)) return;
        try {
            $db = Database::get();
            $db->run('INSERT INTO user_recent (user_id, workspace_id, entity_type, entity_id, visited_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE visited_at = VALUES(visited_at)',
                [Auth::id(), Policy::wsId(), $type, $id, now()]);
            // keep the table small: only the newest few per person
            if (random_int(1, 20) === 1) {
                $cut = $db->value('SELECT visited_at FROM user_recent WHERE user_id = ? AND workspace_id = ? ORDER BY visited_at DESC LIMIT 1 OFFSET ' . self::KEEP_RECENT, [Auth::id(), Policy::wsId()]);
                if ($cut) $db->run('DELETE FROM user_recent WHERE user_id = ? AND workspace_id = ? AND visited_at <= ?', [Auth::id(), Policy::wsId(), $cut]);
            }
        } catch (\Throwable $e) {
            \Borealis\Core\ErrorHandler::log($e);
        }
    }

    public static function recent(int $limit = 8): array
    {
        if (!self::ready()) return [];
        return array_slice(self::resolve(Database::get()->all('SELECT entity_type, entity_id FROM user_recent WHERE user_id = ? AND workspace_id = ? ORDER BY visited_at DESC LIMIT 40',
            [Auth::id(), Policy::wsId()])), 0, $limit);
    }

    /** Turns (type, id) rows into links, dropping what is gone or no longer visible. */
    private static function resolve(array $rows): array
    {
        $ids = ['project' => [], 'task' => [], 'team' => []];
        foreach ($rows as $r) if (isset($ids[$r['entity_type']])) $ids[$r['entity_type']][] = (int)$r['entity_id'];
        $db = Database::get();
        [$vis, $vp] = Policy::visibleProjectsSql('p');
        $found = [];
        if ($ids['project']) {
            foreach ($db->all('SELECT p.id, p.name, p.project_key, p.color, p.kind FROM projects p WHERE p.workspace_id = ? AND p.archived_at IS NULL AND p.id IN (' . Database::in($ids['project']) . ") AND $vis",
                array_merge([Policy::wsId()], $ids['project'], $vp)) as $p) {
                $found['project:' . $p['id']] = ['type' => 'project', 'id' => (int)$p['id'], 'label' => $p['name'], 'sub' => $p['project_key'], 'url' => url('/projects/' . $p['id']), 'color' => $p['color']];
            }
        }
        if ($ids['task']) {
            foreach ($db->all('SELECT t.id, t.title, t.number, p.project_key, p.color FROM tasks t JOIN projects p ON p.id = t.project_id WHERE p.workspace_id = ? AND t.deleted_at IS NULL AND t.id IN (' . Database::in($ids['task']) . ") AND $vis",
                array_merge([Policy::wsId()], $ids['task'], $vp)) as $t) {
                $key = $t['project_key'] . '-' . $t['number'];
                $found['task:' . $t['id']] = ['type' => 'task', 'id' => (int)$t['id'], 'label' => $t['title'], 'sub' => $key, 'url' => url('/t/' . $key), 'color' => $t['color'], 'key' => $key];
            }
        }
        if ($ids['team']) {
            $teams = Policy::visibleTeamIds();
            foreach ($db->all('SELECT id, name, color FROM teams WHERE workspace_id = ? AND archived_at IS NULL AND id IN (' . Database::in($ids['team']) . ')', array_merge([Policy::wsId()], $ids['team'])) as $t) {
                if ($teams !== null && !in_array((int)$t['id'], array_map('intval', $teams), true)) continue;
                $found['team:' . $t['id']] = ['type' => 'team', 'id' => (int)$t['id'], 'label' => $t['name'], 'sub' => 'Equipo', 'url' => url('/teams/' . $t['id']), 'color' => $t['color']];
            }
        }
        $out = [];
        foreach ($rows as $r) if (isset($found[$r['entity_type'] . ':' . $r['entity_id']])) $out[] = $found[$r['entity_type'] . ':' . $r['entity_id']];
        return $out;
    }

    private static function assertVisible(string $type, int $id): void
    {
        match ($type) {
            'project' => Projects::find($id),
            'task' => Tasks::find($id),
            'team' => (bool)Database::get()->value('SELECT 1 FROM teams WHERE id = ? AND workspace_id = ?', [$id, Policy::wsId()]) ?: throw HttpException::notFound(),
        };
    }
}
