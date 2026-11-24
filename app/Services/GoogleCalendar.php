<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Security\Policy;

/**
 * Google Calendar mirrors (1.6).
 *
 * The person chooses which of their Google calendars appear in Borealis. Each one becomes a personal
 * calendar row (source «google») whose events are copies keyed by Google's event id, so repeated
 * syncs update rows instead of duplicating them. Sync is incremental (syncToken); a 410 restarts it.
 * Repeating Google events arrive expanded (singleEvents), each instance with its own stable id.
 *
 * Conflict policy: changes made in Borealis are written to Google first, with the etag Borealis saw
 * (If-Match). If Google has a newer version (412) Google wins: Borealis loads it and tells the person.
 * Borealis never deletes anything in Google by itself; only an explicit «Eliminar» of one event does.
 */
final class GoogleCalendar
{
    private const API = '/calendar/v3';

    /** The person's Google calendars, marking the ones already shown in Borealis. */
    public static function available(): array
    {
        $items = [];
        $page = null;
        for ($i = 0; $i < 10; $i++) {
            $r = Google::api('GET', self::API . '/users/me/calendarList?' . http_build_query(['maxResults' => 250, 'pageToken' => $page]));
            $items = array_merge($items, $r['items'] ?? []);
            if (!($page = $r['nextPageToken'] ?? null)) break;
        }
        $linked = Database::get()->column("SELECT google_calendar_id FROM calendars WHERE workspace_id = ? AND owner_id = ? AND source = 'google' AND deleted_at IS NULL", [Policy::wsId(), Auth::id()]);
        $out = [];
        foreach ($items as $c) {
            if (in_array($c['accessRole'] ?? '', ['freeBusyReader'], true)) continue;
            $out[] = ['id' => (string)$c['id'], 'name' => (string)($c['summaryOverride'] ?? $c['summary'] ?? $c['id']), 'color' => self::color($c['backgroundColor'] ?? null),
                'access' => (string)($c['accessRole'] ?? 'reader'), 'primary' => !empty($c['primary']), 'linked' => in_array((string)$c['id'], $linked, true)];
        }
        return $out;
    }

    private static function color(?string $c): string
    {
        return is_string($c) && preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtoupper($c) : '#4285F4';
    }

    /** Shows exactly the chosen Google calendars in this workspace (others are hidden; nothing changes in Google). */
    public static function choose(array $googleIds): array
    {
        $acc = Google::account();
        if (!$acc) throw HttpException::conflict('Conecta primero tu cuenta de Google.');
        $want = array_values(array_unique(array_map('strval', $googleIds)));
        $avail = [];
        foreach (self::available() as $c) $avail[$c['id']] = $c;
        $db = Database::get();
        $ws = Policy::wsId();
        foreach ($want as $gid) {
            if (!isset($avail[$gid])) throw HttpException::invalid(['calendars' => 'Ese calendario ya no está en tu cuenta de Google.']);
            $c = $avail[$gid];
            $row = $db->one("SELECT * FROM calendars WHERE workspace_id = ? AND owner_id = ? AND google_calendar_id = ?", [$ws, Auth::id(), $gid]);
            $data = ['name' => mb_substr($c['name'], 0, 120), 'google_access' => $c['access'], 'google_account_id' => $acc['id'], 'deleted_at' => null, 'updated_at' => now()];
            if ($row) {
                if ($row['deleted_at']) $data['sync_token'] = null; // shown again: full resync
                $db->update('calendars', $data, 'id = ?', [$row['id']]);
            } else {
                $db->insert('calendars', $data + ['workspace_id' => $ws, 'owner_id' => Auth::id(), 'color' => $c['color'], 'kind' => 'personal', 'default_access' => 'none',
                    'purpose' => 'personal', 'source' => 'google', 'google_calendar_id' => $gid, 'created_by' => Auth::id(), 'created_at' => now()]);
            }
        }
        $params = [$ws, Auth::id()];
        $not = $want ? ' AND google_calendar_id NOT IN (' . Database::in($want) . ')' : '';
        $db->run("UPDATE calendars SET deleted_at = ?, updated_at = ? WHERE workspace_id = ? AND owner_id = ? AND source = 'google' AND deleted_at IS NULL$not", array_merge([now(), now()], $params, $want));
        return self::syncAll();
    }

    /** Syncs every Google calendar the person shows. Returns counts; errors are kept on the account and rethrown. */
    public static function syncAll(): array
    {
        $acc = Google::account();
        if (!$acc) return ['calendars' => 0, 'created' => 0, 'updated' => 0, 'removed' => 0];
        $cals = Database::get()->all("SELECT * FROM calendars WHERE owner_id = ? AND source = 'google' AND deleted_at IS NULL", [Auth::id()]);
        $tot = ['calendars' => count($cals), 'created' => 0, 'updated' => 0, 'removed' => 0];
        try {
            foreach ($cals as $c) foreach (self::sync($c) as $k => $n) $tot[$k] += $n;
            Database::get()->update('google_accounts', ['last_sync_at' => now(), 'last_error' => null, 'status' => 'active', 'updated_at' => now()], 'id = ? AND status <> \'revoked\'', [$acc['id']]);
        } catch (GoogleError $e) {
            if (($acc = Google::account()) && $acc['status'] !== 'revoked') Google::fail((int)$acc['id'], 'error', $e->getMessage());
            throw $e;
        }
        return $tot;
    }

    /** Incremental sync of one calendar. */
    public static function sync(array $cal): array
    {
        $db = Database::get();
        self::$counts = ['created' => 0, 'updated' => 0, 'removed' => 0];
        $token = $cal['sync_token'];
        $page = null;
        for ($i = 0; $i < 40; $i++) {
            $q = ['singleEvents' => 'true', 'maxResults' => 250, 'showDeleted' => 'true', 'pageToken' => $page];
            if ($token) $q['syncToken'] = $token; else $q['timeMin'] = gmdate('Y-m-d\TH:i:s\Z', strtotime('-90 days'));
            try {
                $r = Google::api('GET', self::API . '/calendars/' . rawurlencode($cal['google_calendar_id']) . '/events?' . http_build_query($q));
            } catch (GoogleError $e) {
                if ($e->googleStatus === 410 && $token) { $token = null; $page = null; $db->update('calendars', ['sync_token' => null], 'id = ?', [$cal['id']]); continue; }
                throw $e;
            }
            foreach ($r['items'] ?? [] as $g) self::apply((int)$cal['id'], $g);
            $page = $r['nextPageToken'] ?? null;
            if (!$page) {
                $db->update('calendars', ['sync_token' => $r['nextSyncToken'] ?? null, 'synced_at' => now()], 'id = ?', [$cal['id']]);
                break;
            }
        }
        return self::$counts;
    }

    private static array $counts = ['created' => 0, 'updated' => 0, 'removed' => 0];

    /** Upserts one Google event into Borealis by its stable id. Returns created | updated | removed | same. */
    private static function apply(int $calendarId, array $g): string
    {
        $db = Database::get();
        $gid = (string)($g['id'] ?? '');
        if ($gid === '') return 'same';
        $row = $db->one('SELECT * FROM events WHERE calendar_id = ? AND google_event_id = ?', [$calendarId, $gid]);
        if (($g['status'] ?? '') === 'cancelled') {
            if ($row && !$row['deleted_at']) { $db->update('events', ['deleted_at' => now(), 'status' => 'cancelled', 'updated_at' => now()], 'id = ?', [$row['id']]); self::$counts['removed']++; return 'removed'; }
            return 'same';
        }
        $f = self::fromGoogle($g);
        if (!$f) return 'same';
        if ($row) {
            if ($row['google_etag'] === ($g['etag'] ?? null) && !$row['deleted_at']) return 'same';
            $db->update('events', $f + ['deleted_at' => null, 'status' => 'confirmed', 'updated_at' => now()], 'id = ?', [$row['id']]);
            self::$counts['updated']++;
            return 'updated';
        }
        $db->insert('events', $f + ['calendar_id' => $calendarId, 'kind' => !empty($g['attendees']) ? 'meeting' : 'event', 'status' => 'confirmed', 'recurrence' => 'none', 'created_at' => now(), 'updated_at' => now()]);
        self::$counts['created']++;
        return 'created';
    }

    /** Google event → Borealis columns (UTC). */
    public static function fromGoogle(array $g): ?array
    {
        $s = $g['start'] ?? [];
        $e = $g['end'] ?? [];
        if (!empty($s['date'])) {
            $allDay = 1;
            $start = $s['date'] . ' 00:00:00';
            $end = (!empty($e['date']) ? $e['date'] : date('Y-m-d', strtotime($s['date'] . ' +1 day'))) . ' 00:00:00';
        } elseif (!empty($s['dateTime'])) {
            $allDay = 0;
            $utc = new \DateTimeZone('UTC');
            $start = (new \DateTimeImmutable($s['dateTime']))->setTimezone($utc)->format('Y-m-d H:i:s');
            $end = !empty($e['dateTime']) ? (new \DateTimeImmutable($e['dateTime']))->setTimezone($utc)->format('Y-m-d H:i:s') : $start;
        } else {
            return null;
        }
        $video = $g['extendedProperties']['private']['borealis_video'] ?? ($g['hangoutLink'] ?? null);
        return [
            'title' => mb_substr((string)($g['summary'] ?? '(Sin título)'), 0, 255) ?: '(Sin título)', 'description' => isset($g['description']) ? mb_substr((string)$g['description'], 0, 20000) : null,
            'location' => isset($g['location']) ? mb_substr((string)$g['location'], 0, 255) : null, 'video_url' => is_string($video) && str_starts_with($video, 'https://') ? mb_substr($video, 0, 500) : null,
            'all_day' => $allDay, 'starts_at' => $start, 'ends_at' => $end, 'google_event_id' => (string)$g['id'], 'google_etag' => $g['etag'] ?? null,
            'google_updated' => !empty($g['updated']) ? (new \DateTimeImmutable($g['updated']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') : null,
            'google_recurring' => !empty($g['recurringEventId']) ? 1 : 0,
        ];
    }

    /** Borealis columns → Google event body. */
    private static function toGoogle(array $f): array
    {
        $tz = (string)config('app.timezone', 'UTC');
        if ($f['all_day']) {
            $start = ['date' => substr($f['starts_at'], 0, 10)];
            $end = ['date' => substr($f['ends_at'], 0, 10)];
        } else {
            $fmt = fn($utc) => (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($tz))->format('Y-m-d\TH:i:sP');
            $start = ['dateTime' => $fmt($f['starts_at']), 'timeZone' => $tz];
            $end = ['dateTime' => $fmt($f['ends_at']), 'timeZone' => $tz];
        }
        $b = ['summary' => $f['title'], 'description' => $f['description'] ?? '', 'location' => $f['location'] ?? '', 'start' => $start, 'end' => $end,
            'extendedProperties' => ['private' => ['borealis_video' => $f['video_url'] ?? '']]];
        if (isset($f['reminder_minutes'])) $b['reminders'] = ['useDefault' => false, 'overrides' => [['method' => 'popup', 'minutes' => (int)$f['reminder_minutes']]]];
        return $b;
    }

    private static function writable(array $cal): void
    {
        if (!in_array($cal['google_access'], ['owner', 'writer'], true)) throw HttpException::forbidden('En Google solo puedes ver este calendario; no puedes cambiar sus eventos.');
    }

    /**
     * Writes a create/change to Google before Borealis stores it. Returns the Google columns to keep.
     * On 412 (changed in Google meanwhile) Google wins: the Google version is loaded and the person is told.
     */
    public static function push(array $cal, ?array $existing, array $f): array
    {
        self::writable($cal);
        $path = self::API . '/calendars/' . rawurlencode($cal['google_calendar_id']) . '/events';
        try {
            $g = $existing
                ? Google::api('PATCH', $path . '/' . rawurlencode($existing['google_event_id']), self::toGoogle($f), $existing['google_etag'] ? ['If-Match' => $existing['google_etag']] : [])
                : Google::api('POST', $path, self::toGoogle($f));
        } catch (GoogleError $e) {
            if ($existing && $e->googleStatus === 412) {
                $g = Google::api('GET', $path . '/' . rawurlencode($existing['google_event_id']));
                self::apply((int)$cal['id'], $g);
                throw HttpException::conflict('Este evento se cambió en Google mientras lo editabas. Se cargó la versión de Google; revisa y vuelve a guardar si hace falta.');
            }
            throw $e;
        }
        $m = self::fromGoogle($g) ?? [];
        return ['google_event_id' => (string)$g['id'], 'google_etag' => $g['etag'] ?? null, 'google_updated' => $m['google_updated'] ?? null];
    }

    /** Explicit «Eliminar» of one event of a Google calendar. A 404/410 means it was already gone. */
    public static function remove(array $cal, array $e): void
    {
        self::writable($cal);
        try {
            Google::api('DELETE', self::API . '/calendars/' . rawurlencode($cal['google_calendar_id']) . '/events/' . rawurlencode((string)$e['google_event_id']));
        } catch (GoogleError $x) {
            if (!in_array($x->googleStatus, [404, 410], true)) throw $x;
        }
    }
}
