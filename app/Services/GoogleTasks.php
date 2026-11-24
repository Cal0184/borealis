<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;

/**
 * Google Tasks ⇄ «Mis tareas» (1.6), optional.
 *
 * Google task lists become personal lists linked by Google's list id; tasks are matched by Google's
 * task id, so a repeated sync never duplicates. Lists created in Borealis stay local until the person
 * links one («Enviar a Google Tasks»).
 *
 * Two-way: a task changed only in Borealis is sent to Google; changed only in Google is updated here.
 * Changed in both since the last sync: Google wins and the sync reports it as a conflict.
 * Borealis never deletes in Google: a task deleted here stays deleted here only (it is not imported
 * again); a task deleted in Google is hidden here. Priority exists only in Borealis.
 */
final class GoogleTasks
{
    private const API = '/tasks/v1';
    public const SCOPE = 'https://www.googleapis.com/auth/tasks';

    public static function sync(): array
    {
        $acc = Google::account();
        if (!$acc || !Google::hasScope(self::SCOPE)) return ['lists' => 0, 'created' => 0, 'updated' => 0, 'pushed' => 0, 'conflicts' => 0];
        $n = ['lists' => 0, 'created' => 0, 'updated' => 0, 'pushed' => 0, 'conflicts' => 0];
        try {
            $db = Database::get();
            $remoteLists = self::all(self::API . '/users/@me/lists', ['maxResults' => 100]);
            foreach ($remoteLists as $i => $gl) {
                $row = $db->one('SELECT * FROM personal_lists WHERE user_id = ? AND google_list_id = ?', [Auth::id(), $gl['id']]);
                if ($row && $row['deleted_at']) continue; // removed from Borealis on purpose
                if (!$row) {
                    $id = $db->insert('personal_lists', ['user_id' => Auth::id(), 'name' => mb_substr((string)$gl['title'], 0, 120) ?: 'Google Tasks', 'position' => 100000 + $i,
                        'google_account_id' => $acc['id'], 'google_list_id' => $gl['id'], 'google_etag' => $gl['etag'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
                    $row = $db->one('SELECT * FROM personal_lists WHERE id = ?', [$id]);
                } elseif ($row['google_etag'] !== ($gl['etag'] ?? null)) {
                    $db->update('personal_lists', ['name' => mb_substr((string)$gl['title'], 0, 120) ?: $row['name'], 'google_etag' => $gl['etag'] ?? null, 'google_account_id' => $acc['id'], 'updated_at' => now()], 'id = ?', [$row['id']]);
                }
                $n['lists']++;
                foreach (self::syncList($row) as $k => $v) $n[$k] += $v;
            }
            $db->update('google_accounts', ['last_sync_at' => now(), 'last_error' => $n['conflicts'] ? ($n['conflicts'] === 1 ? '1 tarea cambió en Borealis y en Google a la vez: se conservó la versión de Google.' : "{$n['conflicts']} tareas cambiaron en Borealis y en Google a la vez: se conservó la versión de Google.") : null,
                'status' => 'active', 'updated_at' => now()], 'id = ?', [$acc['id']]);
        } catch (GoogleError $e) {
            if (($a = Google::account()) && $a['status'] !== 'revoked') Google::fail((int)$a['id'], 'error', $e->getMessage());
            throw $e;
        }
        return $n;
    }

    /** All items of a paginated Google Tasks collection. */
    private static function all(string $path, array $q): array
    {
        $out = [];
        $page = null;
        for ($i = 0; $i < 50; $i++) {
            $r = Google::api('GET', $path . '?' . http_build_query($q + ['pageToken' => $page]));
            $out = array_merge($out, $r['items'] ?? []);
            if (!($page = $r['nextPageToken'] ?? null)) break;
        }
        return $out;
    }

    private static function utc(?string $ts): ?string
    {
        return $ts ? (new \DateTimeImmutable($ts))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') : null;
    }

    private static function syncList(array $list): array
    {
        $db = Database::get();
        $n = ['created' => 0, 'updated' => 0, 'pushed' => 0, 'conflicts' => 0];
        $lid = (int)$list['id'];
        $path = self::API . '/lists/' . rawurlencode($list['google_list_id']) . '/tasks';
        $remote = self::all($path, ['maxResults' => 100, 'showCompleted' => 'true', 'showHidden' => 'true', 'showDeleted' => 'true']);
        usort($remote, fn($a, $b) => [(string)($a['parent'] ?? ''), (string)($a['position'] ?? '')] <=> [(string)($b['parent'] ?? ''), (string)($b['position'] ?? '')]);
        $byGid = [];
        foreach ($remote as $pos => $g) {
            $gid = (string)$g['id'];
            $row = $db->one('SELECT * FROM personal_tasks WHERE list_id = ? AND google_task_id = ?', [$lid, $gid]);
            $gUpdated = self::utc($g['updated'] ?? null);
            $f = ['title' => mb_substr(trim((string)($g['title'] ?? '')), 0, 255) ?: '(Sin título)', 'notes' => isset($g['notes']) ? mb_substr((string)$g['notes'], 0, 20000) : null,
                'due_date' => !empty($g['due']) ? substr((string)$g['due'], 0, 10) : null, 'completed_at' => ($g['status'] ?? '') === 'completed' ? (self::utc($g['completed'] ?? null) ?? now()) : null,
                'google_etag' => $g['etag'] ?? null, 'google_updated' => $gUpdated];
            $gone = !empty($g['deleted']);
            if (!$row) {
                if ($gone) continue;
                $stamp = now();
                $id = $db->insert('personal_tasks', $f + ['list_id' => $lid, 'user_id' => Auth::id(), 'priority' => 'none', 'position' => ($pos + 1) * 1024, 'google_task_id' => $gid, 'pushed_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp]);
                $byGid[$gid] = ['id' => $id, 'parent' => $g['parent'] ?? null];
                $n['created']++;
                continue;
            }
            $byGid[$gid] = ['id' => (int)$row['id'], 'parent' => $g['parent'] ?? null];
            if ($row['deleted_at']) continue;                           // deleted in Borealis: stays deleted here; Google keeps its copy
            $stamp = now();
            if ($gone) { $db->update('personal_tasks', ['deleted_at' => $stamp, 'updated_at' => $stamp, 'pushed_at' => $stamp], 'id = ?', [$row['id']]); $n['updated']++; continue; }
            $localDirty = $row['pushed_at'] === null;                    // edits in Borealis clear pushed_at
            $remoteChanged = $gUpdated !== $row['google_updated'];
            if ($remoteChanged) {
                if ($localDirty) $n['conflicts']++;                     // both changed: Google wins
                $db->update('personal_tasks', $f + ['updated_at' => $stamp, 'pushed_at' => $stamp], 'id = ?', [$row['id']]);
                $n['updated']++;
            } elseif ($localDirty) {
                $g2 = Google::api('PATCH', $path . '/' . rawurlencode($gid), self::toGoogle($row));
                $db->update('personal_tasks', ['google_etag' => $g2['etag'] ?? null, 'google_updated' => self::utc($g2['updated'] ?? null), 'pushed_at' => now()], 'id = ?', [$row['id']]);
                $n['pushed']++;
            }
        }
        // parents chosen in Google
        foreach ($byGid as $x) {
            $pid = $x['parent'] && isset($byGid[$x['parent']]) ? $byGid[$x['parent']]['id'] : null;
            $db->run('UPDATE personal_tasks SET parent_id = ? WHERE id = ? AND NOT (parent_id <=> ?)', [$pid, $x['id'], $pid]);
        }
        // tasks created in Borealis: parents first
        $new = $db->all('SELECT * FROM personal_tasks WHERE list_id = ? AND user_id = ? AND google_task_id IS NULL AND deleted_at IS NULL ORDER BY parent_id IS NOT NULL, position, id', [$lid, Auth::id()]);
        foreach ($new as $t) {
            $q = [];
            if ($t['parent_id']) {
                $pg = $db->value('SELECT google_task_id FROM personal_tasks WHERE id = ?', [$t['parent_id']]);
                if (!$pg) continue;
                $q['parent'] = $pg;
            }
            $g = Google::api('POST', $path . ($q ? '?' . http_build_query($q) : ''), self::toGoogle($t));
            $db->update('personal_tasks', ['google_task_id' => (string)$g['id'], 'google_etag' => $g['etag'] ?? null, 'google_updated' => self::utc($g['updated'] ?? null), 'pushed_at' => now()], 'id = ?', [$t['id']]);
            $n['pushed']++;
        }
        $db->update('personal_lists', ['synced_at' => now()], 'id = ?', [$lid]);
        return $n;
    }

    private static function toGoogle(array $t): array
    {
        return ['title' => $t['title'], 'notes' => (string)($t['notes'] ?? ''), 'due' => $t['due_date'] ? $t['due_date'] . 'T00:00:00.000Z' : null,
            'status' => $t['completed_at'] ? 'completed' : 'needsAction'] + ($t['completed_at'] ? ['completed' => gmdate('Y-m-d\TH:i:s.000\Z', strtotime($t['completed_at'] . ' UTC'))] : []);
    }

    /** Creates a Google list for a Borealis list and links them; its tasks go up on the next sync. */
    public static function link(int $listId): array
    {
        $l = PersonalTasks::list($listId);
        if ($l['google_list_id']) throw HttpException::conflict('Esta lista ya está en Google Tasks.');
        $acc = Google::account();
        if (!$acc || !Google::hasScope(self::SCOPE)) throw HttpException::conflict('Conecta tu cuenta de Google con permiso para Google Tasks desde tu perfil.');
        $g = Google::api('POST', self::API . '/users/@me/lists', ['title' => $l['name']]);
        Database::get()->update('personal_lists', ['google_account_id' => $acc['id'], 'google_list_id' => (string)$g['id'], 'google_etag' => $g['etag'] ?? null, 'updated_at' => now()], 'id = ?', [$listId]);
        return self::sync();
    }
}
