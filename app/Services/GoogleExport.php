<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Security\Secrets;

/**
 * Borealis → Google Calendar (1.9), one way.
 *
 * Each person may choose ONE of their Google calendars (where they can write) and what to send:
 * the tasks they are responsible for (and, if they want, those of their teams), and the sprints and
 * delivery dates of the projects they take part in. Borealis keeps one row per event it created
 * (google_exports) and only ever changes or removes those events: nothing else in the person's
 * calendar is read for this, changed or removed. Changes made in Google to these events are not
 * brought back (one way); the next change in Borealis overwrites them.
 *
 * No duplicates: every event gets an id derived from the installation key, the person, the item and
 * the calendar. If a request is repeated (a timeout, two processes, a reconnection after
 * «Desconectar») Google answers 409 and Borealis updates the existing event instead.
 *
 * Runs right after the response for the people a task change affects, from bin/notify.php (cron)
 * for everything else, and with «Sincronizar ahora». Failures (network, quota, Google down) never
 * block Borealis: they are kept on the account and retried with a growing pause.
 */
final class GoogleExport
{
    private const API = '/calendar/v3';
    public const DEFAULTS = ['mine' => true, 'team' => false, 'sprints' => false, 'details' => 'full', 'done' => 'remove', 'reminders' => false];
    private const MAX_CALLS = 120;

    private static array $pending = [];
    private static bool $hooked = false;

    public static function options(?array $acc): array
    {
        $o = $acc && $acc['export_options'] ? (json_decode((string)$acc['export_options'], true) ?: []) : [];
        $out = self::DEFAULTS;
        foreach (['mine', 'team', 'sprints', 'reminders'] as $k) if (array_key_exists($k, $o)) $out[$k] = (bool)$o[$k];
        if (in_array($o['details'] ?? '', ['full', 'basic'], true)) $out['details'] = $o['details'];
        if (in_array($o['done'] ?? '', ['remove', 'keep'], true)) $out['done'] = $o['done'];
        return $out;
    }

    /** Status for the profile: off | pending | ok | error, with counts and the last problem. */
    public static function status(?int $uid = null): array
    {
        $uid ??= Auth::id();
        $acc = Google::account($uid);
        if (!$acc) return ['state' => 'disconnected'];
        $n = (int)Database::get()->value('SELECT COUNT(*) FROM google_exports WHERE user_id = ?', [$uid]);
        $state = $acc['status'] === 'revoked' ? 'auth' : ($acc['export_calendar_id'] ? $acc['export_state'] : 'off');
        if ($state === 'error' && $acc['status'] === 'revoked') $state = 'auth';
        return ['state' => $state, 'calendar_id' => $acc['export_calendar_id'], 'calendar_name' => $acc['export_calendar_name'], 'events' => $n,
            'synced_at' => $acc['export_synced_at'], 'error' => $acc['export_error'], 'options' => self::options($acc), 'email' => $acc['google_email']];
    }

    /** Google calendars where the person can create events (owner or writer). */
    public static function writableCalendars(): array
    {
        return array_values(array_filter(GoogleCalendar::available(), fn($c) => in_array($c['access'], ['owner', 'writer'], true)));
    }

    /** Saves the destination calendar and the options, then sends everything once. */
    public static function configure(array $in): array
    {
        $acc = Google::account();
        if (!$acc) throw HttpException::conflict('Conecta primero tu cuenta de Google.');
        if ($acc['status'] === 'revoked') throw HttpException::conflict('Google retiró el permiso de Borealis. Vuelve a conectar tu cuenta.');
        if (!Google::hasScope('https://www.googleapis.com/auth/calendar.events')) throw HttpException::conflict('Al conectar no diste permiso para crear eventos en Google Calendar. Vuelve a conectar tu cuenta y acepta ese permiso.');
        $cid = trim((string)($in['calendar_id'] ?? ''));
        $cal = null;
        foreach (self::writableCalendars() as $c) if ($c['id'] === $cid) $cal = $c;
        if (!$cal) throw HttpException::invalid(['calendar_id' => 'Elige uno de tus calendarios de Google en los que puedes crear eventos.']);
        $bool = fn($k) => in_array($in[$k] ?? false, [true, 1, '1', 'on', 'true'], true);
        $opts = ['mine' => $bool('mine'), 'team' => $bool('team'), 'sprints' => $bool('sprints'),
            'details' => ($in['details'] ?? 'full') === 'basic' ? 'basic' : 'full', 'done' => ($in['done'] ?? 'remove') === 'keep' ? 'keep' : 'remove', 'reminders' => $bool('reminders')];
        if (!$opts['mine'] && !$opts['team'] && !$opts['sprints']) throw HttpException::invalid(['mine' => 'Elige al menos qué enviar a Google Calendar.']);
        Database::get()->update('google_accounts', ['export_calendar_id' => $cid, 'export_calendar_name' => mb_substr($cal['name'], 0, 190), 'export_options' => json_encode($opts),
            'export_state' => 'pending', 'export_error' => null, 'export_failures' => 0, 'export_retry_at' => null, 'updated_at' => now()], 'id = ?', [$acc['id']]);
        if ($acc['export_calendar_id'] !== $cid) Activity::log('google_export', 'user', Auth::id(), 'Envía su trabajo de Borealis al calendario de Google «' . $cal['name'] . '»');
        return self::run(Auth::id(), true);
    }

    /** Stops sending. With $remove, also removes from Google the events Borealis created (only those). */
    public static function stop(bool $remove, ?int $uid = null): array
    {
        $uid ??= Auth::id();
        $acc = Google::account($uid);
        if (!$acc) return ['removed' => 0];
        $removed = 0;
        if ($remove && $acc['status'] !== 'revoked') {
            foreach (Database::get()->all('SELECT * FROM google_exports WHERE user_id = ?', [$uid]) as $row) {
                try { self::deleteEvent($uid, $row); $removed++; }
                catch (GoogleError $e) { if (!in_array($e->googleStatus, [404, 410], true)) throw $e; }
                Database::get()->run('DELETE FROM google_exports WHERE id = ?', [$row['id']]);
            }
        }
        // Without $remove the rows are forgotten but the events stay in Google; their ids are
        // deterministic, so turning it on again later updates them instead of duplicating them.
        Database::get()->run('DELETE FROM google_exports WHERE user_id = ?', [$uid]);
        Database::get()->update('google_accounts', ['export_calendar_id' => null, 'export_calendar_name' => null, 'export_state' => 'off', 'export_error' => null, 'export_retry_at' => null, 'export_failures' => 0, 'updated_at' => now()], 'id = ?', [$acc['id']]);
        return ['removed' => $removed];
    }

    // ------------------------------------------------------------------ what should be in Google

    /** Desired events of a person: key «type:id» => Google event body. */
    public static function desired(int $uid, array $opts): array
    {
        $db = Database::get();
        $tz = UserTime::zone($uid);
        $from = (new \DateTimeImmutable('now', $tz))->modify('-30 days')->format('Y-m-d');
        $out = [];
        if ($opts['mine'] || $opts['team']) {
            $who = [];
            $params = [$uid];
            if ($opts['mine']) { $who[] = 't.assignee_id = ?'; $who[] = 'EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id = t.id AND ta.user_id = ?)'; $params[] = $uid; $params[] = $uid; }
            if ($opts['team']) { $who[] = '(t.team_id IS NOT NULL AND EXISTS (SELECT 1 FROM team_members tm WHERE tm.team_id = t.team_id AND tm.user_id = ?))'; $params[] = $uid; }
            $params[] = $from;
            $done = $opts['done'] === 'keep' ? '' : " AND s.category <> 'done' AND t.completed_at IS NULL";
            $rows = $db->all("SELECT t.id, t.number, t.title, t.description, t.due_date, t.due_time, t.estimate_hours, t.completed_at, s.category AS status_category, s.name AS status_name,
                    p.name AS project_name, p.project_key
                FROM tasks t JOIN projects p ON p.id = t.project_id JOIN statuses s ON s.id = t.status_id
                JOIN workspace_members wm ON wm.workspace_id = p.workspace_id AND wm.user_id = ? AND wm.status = 'active'
                WHERE (" . implode(' OR ', $who) . ") AND t.deleted_at IS NULL AND p.archived_at IS NULL AND t.due_date IS NOT NULL AND t.due_date >= ?$done
                ORDER BY t.due_date, t.id LIMIT 500", $params);
            foreach ($rows as $t) $out['task:' . $t['id']] = self::taskEvent($t, $tz, $opts, $uid);
        }
        if ($opts['sprints']) {
            $member = "(EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = p.id AND pm.user_id = ?)
                OR EXISTS (SELECT 1 FROM team_members tm JOIN teams tt ON tt.id = tm.team_id AND tt.archived_at IS NULL WHERE tm.team_id = p.team_id AND tm.user_id = ?)
                OR EXISTS (SELECT 1 FROM project_teams ptv JOIN team_members tmv ON tmv.team_id = ptv.team_id WHERE ptv.project_id = p.id AND tmv.user_id = ?))";
            $base = "JOIN workspace_members wm ON wm.workspace_id = p.workspace_id AND wm.user_id = ? AND wm.status = 'active' WHERE p.archived_at IS NULL AND $member";
            foreach ($db->all("SELECT sp.id, sp.name, sp.goal, sp.start_date, sp.end_date, sp.state, p.id AS project_id, p.name AS project_name FROM sprints sp JOIN projects p ON p.id = sp.project_id $base
                    AND sp.start_date IS NOT NULL AND sp.end_date IS NOT NULL AND sp.state <> 'completed' AND sp.end_date >= ? ORDER BY sp.start_date LIMIT 200", [$uid, $uid, $uid, $uid, $from]) as $s) {
                $out['sprint:' . $s['id']] = self::allDay('Sprint «' . $s['name'] . '» · ' . $s['project_name'], $s['start_date'], $s['end_date'],
                    self::describe($opts, ['Proyecto: ' . $s['project_name'], 'Del ' . fmt_dmy($s['start_date']) . ' al ' . fmt_dmy($s['end_date']) . ' (fin previsto; el sprint se cierra en Borealis)'], $s['goal'] ? 'Objetivo: ' . $s['goal'] : null, absolute_url('/projects/' . $s['project_id'] . '/sprints')),
                    'sprint:' . $s['id'], $opts, absolute_url('/projects/' . $s['project_id'] . '/sprints'), $uid);
            }
            foreach ($db->all("SELECT p.id, p.name, p.due_date, p.description FROM projects p $base AND p.due_date IS NOT NULL AND p.due_date >= ? ORDER BY p.due_date LIMIT 200", [$uid, $uid, $uid, $uid, $from]) as $p) {
                $out['project:' . $p['id']] = self::allDay('Entrega del proyecto «' . $p['name'] . '»', $p['due_date'], $p['due_date'],
                    self::describe($opts, ['Hito de proyecto en Borealis'], null, absolute_url('/projects/' . $p['id'])), 'project:' . $p['id'], $opts, absolute_url('/projects/' . $p['id']), $uid);
            }
        }
        return $out;
    }

    private static function taskEvent(array $t, \DateTimeZone $tz, array $opts, int $uid): array
    {
        $key = $t['project_key'] . '-' . $t['number'];
        $link = absolute_url('/t/' . $key);
        $done = $t['status_category'] === 'done' || $t['completed_at'];
        $hours = $t['estimate_hours'] !== null ? (float)$t['estimate_hours'] : null;
        $lines = ['Proyecto: ' . $t['project_name'], 'Estado: ' . $t['status_name']];
        if ($hours) $lines[] = 'Tiempo estimado: ' . rtrim(rtrim(number_format($hours, 2, ',', ''), '0'), ',') . ' h';
        $desc = $t['description'] ? trim(preg_replace('/\s+/u', ' ', strip_tags(preg_replace('/[*_`#>\[\]]+/', '', (string)$t['description'])))) : '';
        if (mb_strlen($desc) > 300) $desc = mb_substr($desc, 0, 297) . '…';
        $title = ($done ? '✓ ' : '') . "[$key] " . $t['title'];
        if ($t['due_time']) {
            $end = (new \DateTimeImmutable($t['due_date'] . ' ' . substr((string)$t['due_time'], 0, 5) . ':00', new \DateTimeZone(UserTime::installZone())))->setTimezone($tz);
            $mins = $hours ? (int)round(min(8, max(0.25, $hours)) * 60) : 30;
            $start = $end->modify("-$mins minutes");
            $lines[] = 'Entrega: ' . $end->format('d/m/Y') . ' a las ' . $end->format('H:i');
            $b = ['summary' => $title, 'start' => ['dateTime' => $start->format('Y-m-d\TH:i:sP'), 'timeZone' => $tz->getName()], 'end' => ['dateTime' => $end->format('Y-m-d\TH:i:sP'), 'timeZone' => $tz->getName()]];
        } else {
            $lines[] = 'Entrega: ' . fmt_dmy($t['due_date']) . ' (todo el día)';
            $b = ['summary' => $title, 'start' => ['date' => $t['due_date']], 'end' => ['date' => date('Y-m-d', strtotime($t['due_date'] . ' +1 day'))]];
        }
        return $b + self::common(self::describe($opts, $lines, $desc ?: null, $link), 'task:' . $t['id'], $opts, $link, $uid);
    }

    private static function allDay(string $title, string $from, string $to, string $desc, string $ref, array $opts, string $link, int $uid): array
    {
        return ['summary' => $title, 'start' => ['date' => $from], 'end' => ['date' => date('Y-m-d', strtotime($to . ' +1 day'))]] + self::common($desc, $ref, $opts, $link, $uid);
    }

    private static function describe(array $opts, array $lines, ?string $text, string $link): string
    {
        if ($opts['details'] === 'basic') $lines = array_slice($lines, 0, 1);
        elseif ($text) $lines[] = "\n" . $text;
        $lines[] = "\nAbrir en Borealis: $link";
        $lines[] = 'Creado por Borealis. Los cambios hechos aquí se reemplazan al cambiar la tarea en Borealis.';
        return implode("\n", $lines);
    }

    private static function common(string $desc, string $ref, array $opts, string $link, int $uid): array
    {
        $b = ['description' => $desc, 'transparency' => 'transparent', 'status' => 'confirmed',
            'extendedProperties' => ['private' => ['borealis' => '1', 'borealis_owner' => self::owner($uid), 'borealis_ref' => $ref]],
            'reminders' => $opts['reminders'] ? ['useDefault' => true] : ['useDefault' => false, 'overrides' => []]];
        if (preg_match('#^https?://#', $link)) $b['source'] = ['title' => 'Borealis', 'url' => $link];
        return $b;
    }

    /** Private mark of the person's events (two people may send to one shared calendar). Not guessable. */
    public static function owner(int $uid): string
    {
        return substr(Secrets::hmac("gexp-owner|$uid"), 0, 20);
    }

    /**
     * Events of this person that Borealis created earlier in this calendar (ref => Google id), read
     * only through Borealis' own private marks. Used when Borealis has no record of them (first time
     * after reconnecting, or a lost answer), so it updates them instead of creating copies.
     */
    private static function existing(int $uid, string $cal, int &$calls): array
    {
        $map = [];
        $page = null;
        for ($i = 0; $i < 20; $i++) {
            $calls++;
            $q = 'maxResults=250&showDeleted=false&privateExtendedProperty=' . rawurlencode('borealis=1') . '&privateExtendedProperty=' . rawurlencode('borealis_owner=' . self::owner($uid))
                . ($page ? '&pageToken=' . rawurlencode($page) : '');
            $r = Google::api('GET', self::API . '/calendars/' . rawurlencode($cal) . '/events?' . $q, null, [], $uid);
            foreach ($r['items'] ?? [] as $e) {
                $ref = (string)($e['extendedProperties']['private']['borealis_ref'] ?? '');
                if ($ref !== '' && ($e['status'] ?? '') !== 'cancelled' && !isset($map[$ref])) $map[$ref] = (string)$e['id'];
            }
            if (!($page = $r['nextPageToken'] ?? null)) break;
        }
        return $map;
    }

    /** Google event id: base32hex (0-9, a-v), stable for the same person, item and calendar. */
    public static function eventId(int $uid, string $ref, string $calendarId): string
    {
        return 'brl' . substr(Secrets::hmac("gexp|$uid|$ref|$calendarId"), 0, 40);
    }

    // ------------------------------------------------------------------ sending

    /**
     * Brings the person's calendar in line with what Borealis has. $manual ignores the retry pause.
     * Returns counts; throws only for «Sincronizar ahora» / configure (the person sees the reason).
     */
    public static function run(int $uid, bool $manual = false): array
    {
        $db = Database::get();
        $n = ['created' => 0, 'updated' => 0, 'removed' => 0, 'pending' => 0, 'unchanged' => 0];
        $acc = $db->one('SELECT * FROM google_accounts WHERE user_id = ?', [$uid]);
        if (!$acc || !$acc['export_calendar_id'] || $acc['status'] === 'revoked') return $n;
        if (!$manual && $acc['export_retry_at'] && strtotime($acc['export_retry_at'] . ' UTC') > time()) return $n;
        $opts = self::options($acc);
        $cal = (string)$acc['export_calendar_id'];
        $calls = 0;
        try {
            $want = self::desired($uid, $opts);
            $have = [];
            foreach ($db->all('SELECT * FROM google_exports WHERE user_id = ?', [$uid]) as $r) $have[$r['ref_type'] . ':' . $r['ref_id']] = $r;
            // 1. remove what is no longer wanted (or lives in a calendar no longer chosen)
            foreach ($have as $k => $row) {
                if (isset($want[$k]) && $row['google_calendar_id'] === $cal) continue;
                if ($calls++ >= self::MAX_CALLS) { $n['pending']++; continue; }
                try { self::deleteEvent($uid, $row); } catch (GoogleError $e) { if (!in_array($e->googleStatus, [404, 410], true)) throw $e; }
                $db->run('DELETE FROM google_exports WHERE id = ?', [$row['id']]);
                unset($have[$k]);
                if (!isset($want[$k])) $n['removed']++;
            }
            // 2. create or update the rest (looking first for copies Borealis made before, if any is unknown)
            $found = null;
            foreach ($want as $k => $body) {
                if (!isset($have[$k]) && $found === null) $found = self::existing($uid, $cal, $calls);
            }
            foreach ($want as $k => $body) {
                $hash = sha1($cal . '|' . json_encode($body, JSON_UNESCAPED_UNICODE));
                $row = $have[$k] ?? null;
                if ($row && $row['content_hash'] === $hash) { $n['unchanged']++; continue; }
                if ($calls++ >= self::MAX_CALLS) { $n['pending']++; continue; }
                [$type, $id] = explode(':', $k);
                $known = $row ? (string)$row['google_event_id'] : ($found[$k] ?? null);
                $gid = self::write($uid, $cal, $known ?? self::eventId($uid, $k, $cal), $body, $known !== null);
                $db->run('INSERT INTO google_exports (user_id, ref_type, ref_id, google_calendar_id, google_event_id, content_hash, synced_at) VALUES (?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE google_calendar_id = VALUES(google_calendar_id), google_event_id = VALUES(google_event_id), content_hash = VALUES(content_hash), synced_at = VALUES(synced_at)',
                    [$uid, $type, (int)$id, $cal, $gid, $hash, now()]);
                $n[$row ? 'updated' : 'created']++;
            }
            $db->update('google_accounts', ['export_state' => $n['pending'] ? 'pending' : 'ok', 'export_error' => null, 'export_failures' => 0, 'export_retry_at' => null,
                'export_synced_at' => now(), 'updated_at' => now()], 'id = ?', [$acc['id']]);
        } catch (GoogleError $e) {
            $f = (int)$acc['export_failures'] + 1;
            $wait = min(360, 5 * (2 ** min(7, $f - 1)));
            $msg = $e->googleStatus === 404 ? 'El calendario elegido ya no existe en tu cuenta de Google. Elige otro.' :
                ($e->googleStatus === 403 && !str_contains(strtolower($e->reason), 'ratelimit') ? 'Google no permite crear eventos en el calendario elegido. Elige otro calendario.' : $e->getMessage());
            $db->update('google_accounts', ['export_state' => 'error', 'export_error' => mb_substr($msg, 0, 500), 'export_failures' => $f,
                'export_retry_at' => gmdate('Y-m-d H:i:s', time() + $wait * 60), 'updated_at' => now()], 'id = ?', [$acc['id']]);
            if ($manual) throw new GoogleError($msg, $e->googleStatus, $e->reason);
            error_log('[Borealis] Google Calendar (persona ' . $uid . '): ' . $e->getMessage()); // never tokens or contents
        }
        return $n;
    }

    /** Creates or updates one event; returns its Google id. Handles «already exists» and «gone». */
    private static function write(int $uid, string $cal, string $gid, array $body, bool $known): string
    {
        $path = self::API . '/calendars/' . rawurlencode($cal) . '/events';
        if ($known) {
            try { return (string)Google::api('PATCH', $path . '/' . rawurlencode($gid), $body, [], $uid)['id']; }
            catch (GoogleError $e) {
                if (!in_array($e->googleStatus, [404, 410], true)) throw $e;
                // removed for good in Google: create it again with an id chosen by Google
                return (string)Google::api('POST', $path, $body, [], $uid)['id'];
            }
        }
        try { return (string)Google::api('POST', $path, $body + ['id' => $gid], [], $uid)['id']; }
        catch (GoogleError $e) {
            if ($e->googleStatus !== 409) throw $e;
            // it exists already (a repeated request, or sent before «Desconectar»): update it, never duplicate
            try { return (string)Google::api('PATCH', $path . '/' . rawurlencode($gid), $body, [], $uid)['id']; }
            catch (GoogleError $x) {
                if (!in_array($x->googleStatus, [404, 410], true)) throw $x;
                return (string)Google::api('POST', $path, $body, [], $uid)['id'];
            }
        }
    }

    private static function deleteEvent(int $uid, array $row): void
    {
        Google::api('DELETE', self::API . '/calendars/' . rawurlencode((string)$row['google_calendar_id']) . '/events/' . rawurlencode((string)$row['google_event_id']), null, [], $uid);
    }

    // ------------------------------------------------------------------ triggers

    /** A task changed: the people who have it (now or before) in Google get updated after the response. */
    public static function touchTask(int $taskId): void
    {
        try {
            $db = Database::get();
            $ids = $db->column("SELECT DISTINCT g.user_id FROM google_accounts g WHERE g.export_calendar_id IS NOT NULL AND g.status <> 'revoked' AND (
                    g.user_id IN (SELECT user_id FROM google_exports WHERE ref_type = 'task' AND ref_id = ?)
                 OR g.user_id = (SELECT assignee_id FROM tasks WHERE id = ?)
                 OR g.user_id IN (SELECT user_id FROM task_assignees WHERE task_id = ?)
                 OR g.user_id IN (SELECT tm.user_id FROM team_members tm JOIN tasks t ON t.team_id = tm.team_id WHERE t.id = ?))", [$taskId, $taskId, $taskId, $taskId]);
        } catch (\Throwable) {
            return; // before the 1.9 migration, or anything else: the cron catches up
        }
        foreach ($ids as $uid) self::later((int)$uid);
    }

    public static function later(int $uid): void
    {
        self::$pending[$uid] = true;
        if (self::$hooked || PHP_SAPI === 'cli') return;
        self::$hooked = true;
        register_shutdown_function(function () {
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            foreach (array_keys(self::$pending) as $uid) {
                try { self::run((int)$uid); } catch (\Throwable $e) { error_log('[Borealis] Google Calendar: ' . $e->getMessage()); }
            }
        });
    }

    /** Everyone with the export on (cron). */
    public static function runAll(): int
    {
        $done = 0;
        foreach (Database::get()->column("SELECT user_id FROM google_accounts WHERE export_calendar_id IS NOT NULL AND status <> 'revoked'") as $uid) {
            try { self::run((int)$uid); $done++; } catch (\Throwable $e) { error_log('[Borealis] Google Calendar: ' . $e->getMessage()); }
        }
        return $done;
    }
}
