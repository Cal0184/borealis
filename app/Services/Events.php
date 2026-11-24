<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Validator;
use Borealis\Security\Policy;

/**
 * Events and meetings (1.6). Stored in UTC; entered and shown in the installation's time zone
 * (app.timezone), so a weekly 10:00 meeting stays at 10:00 across daylight-saving changes.
 *
 * A meeting is an event whose purpose is to coordinate people: it has participants and can carry a
 * place or video link. Participants see it in their calendar even without access to its calendar.
 * Repeating events are stored once and expanded when a range is shown. Events in a Google calendar
 * are written to Google first (see GoogleCalendar); if Google refuses, nothing changes here.
 */
final class Events
{
    public const KINDS = ['event' => 'Evento', 'meeting' => 'Reunión'];
    public const RECURRENCE = ['none' => 'No se repite', 'daily' => 'Cada día', 'weekly' => 'Cada semana', 'biweekly' => 'Cada dos semanas', 'monthly' => 'Cada mes'];
    public const REMINDERS = ['' => 'Sin recordatorio', '0' => 'Al empezar', '10' => '10 minutos antes', '30' => '30 minutos antes', '60' => '1 hora antes', '1440' => '1 día antes'];

    private static function tz(): \DateTimeZone { return new \DateTimeZone((string)config('app.timezone', 'UTC')); }

    public static function toUtc(string $localDateTime): string
    {
        return (new \DateTimeImmutable($localDateTime, self::tz()))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function toLocal(string $utc, string $format = 'Y-m-d H:i'): string
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(self::tz())->format($format);
    }

    // ---- reading -----------------------------------------------------------------

    /**
     * Occurrences between two local dates (inclusive) of the given calendars, plus meetings where the
     * person participates. Each occurrence has local start/end ('Y-m-d H:i') and its event id.
     */
    public static function range(string $from, string $to, array $calendarIds): array
    {
        $db = Database::get();
        // one extra day each side: all-day events are stored as plain dates (no time zone); occurrences() trims exactly
        $fromUtc = self::toUtc(date('Y-m-d', strtotime($from . ' -1 day')) . ' 00:00');
        $toUtc = self::toUtc(date('Y-m-d', strtotime($to . ' +2 days')) . ' 00:00');
        $ids = array_values(array_map('intval', $calendarIds));
        $where = $ids ? 'e.calendar_id IN (' . Database::in($ids) . ') OR ' : '';
        $rows = $db->all("SELECT e.*, c.name AS calendar_name, c.color, c.source, c.kind AS calendar_kind, p.name AS project_name, p.project_key, tm.name AS team_name
            FROM events e JOIN calendars c ON c.id = e.calendar_id AND c.deleted_at IS NULL LEFT JOIN projects p ON p.id = e.project_id LEFT JOIN teams tm ON tm.id = e.team_id
            WHERE c.workspace_id = ? AND e.deleted_at IS NULL AND ($where EXISTS (SELECT 1 FROM event_participants ep WHERE ep.event_id = e.id AND ep.user_id = ?))
              AND e.starts_at < ? AND (e.ends_at > ? OR (e.recurrence <> 'none' AND (e.recurrence_until IS NULL OR e.recurrence_until >= ?)))
            ORDER BY e.all_day DESC, e.starts_at", array_merge([Policy::wsId()], $ids, [Auth::id(), $toUtc, $fromUtc, $from]));
        $out = [];
        foreach ($rows as $e) {
            $e['guest'] = !in_array((int)$e['calendar_id'], $ids, true); // shown because the person participates
            foreach (self::occurrences($e, $from, $to) as [$s, $en]) $out[] = $e + ['occ_start' => $s, 'occ_end' => $en];
        }
        usort($out, fn($a, $b) => [$b['all_day'], $a['occ_start']] <=> [$a['all_day'], $b['occ_start']]);
        return $out;
    }

    /** Local [start, end] pairs of an event inside [from, to]. */
    public static function occurrences(array $e, string $from, string $to): array
    {
        $tz = self::tz();
        if (!empty($e['all_day'])) { // plain dates
            $start = new \DateTimeImmutable($e['starts_at'], $tz);
            $end = new \DateTimeImmutable($e['ends_at'], $tz);
        } else {
            $start = (new \DateTimeImmutable($e['starts_at'], new \DateTimeZone('UTC')))->setTimezone($tz);
            $end = (new \DateTimeImmutable($e['ends_at'], new \DateTimeZone('UTC')))->setTimezone($tz);
        }
        $len = $end->getTimestamp() - $start->getTimestamp();
        $lo = new \DateTimeImmutable($from . ' 00:00', $tz);
        $hi = new \DateTimeImmutable($to . ' 23:59:59', $tz);
        $step = ['daily' => 'P1D', 'weekly' => 'P1W', 'biweekly' => 'P2W', 'monthly' => 'P1M'][$e['recurrence']] ?? null;
        $until = $e['recurrence_until'] ? new \DateTimeImmutable($e['recurrence_until'] . ' 23:59:59', $tz) : null;
        $out = [];
        $cur = $start;
        $month = 0;
        for ($i = 0; $i < 1000 && $cur !== null; $i++) {
            if ($cur > $hi || ($until && $cur > $until)) break;
            $curEnd = $cur->modify('+' . $len . ' seconds');
            if ($curEnd > $lo || ($len === 0 && $cur >= $lo)) $out[] = [$cur->format('Y-m-d H:i'), $curEnd->format('Y-m-d H:i')];
            if (!$step) break;
            if ($e['recurrence'] === 'monthly') {
                // same day number as the first one; months without that day are skipped
                $cur = null;
                for ($k = 0; $k < 12 && $cur === null; $k++) {
                    $month++;
                    $c = $start->modify("first day of +$month month");
                    if ((int)$c->format('t') >= (int)$start->format('j')) $cur = $c->setDate((int)$c->format('Y'), (int)$c->format('n'), (int)$start->format('j'))->setTime((int)$start->format('H'), (int)$start->format('i'));
                }
            } else {
                $cur = $cur->add(new \DateInterval($step)); // local wall time: 10:00 stays 10:00 across DST
            }
        }
        return $out;
    }

    /** An event the person may see (calendar access or participant), with participants. */
    public static function find(int $id): array
    {
        $db = Database::get();
        $e = $db->one('SELECT e.*, c.name AS calendar_name, c.color, c.source, c.kind AS calendar_kind FROM events e JOIN calendars c ON c.id = e.calendar_id AND c.deleted_at IS NULL
            WHERE e.id = ? AND c.workspace_id = ? AND e.deleted_at IS NULL', [$id, Policy::wsId()]);
        if (!$e) throw HttpException::notFound('Ese evento no existe.');
        $cal = $db->one('SELECT * FROM calendars WHERE id = ?', [$e['calendar_id']]);
        $lvl = Calendars::level($cal);
        $participant = (bool)$db->value('SELECT 1 FROM event_participants WHERE event_id = ? AND user_id = ?', [$id, Auth::id()]);
        if ($lvl === 'none' && !$participant) throw HttpException::notFound('Ese evento no existe.');
        $e['access'] = $lvl;
        $e['can_edit'] = Calendars::LEVELS[$lvl] >= Calendars::LEVELS['edit'] && $e['source'] !== 'google' || ($e['source'] === 'google' && $lvl === 'manage' && in_array($cal['google_access'], ['owner', 'writer'], true));
        $e['participants'] = $db->all('SELECT u.id, u.name, u.avatar_color FROM event_participants ep JOIN users u ON u.id = ep.user_id WHERE ep.event_id = ? ORDER BY u.name', [$id]);
        $e['local_start'] = $e['all_day'] ? substr($e['starts_at'], 0, 16) : self::toLocal($e['starts_at']);
        $e['local_end'] = $e['all_day'] ? substr($e['ends_at'], 0, 16) : self::toLocal($e['ends_at']);
        return $e;
    }

    // ---- writing -------------------------------------------------------------------

    /** Validates the form and returns the columns to store (UTC times). */
    private static function fields(array $input, ?array $existing): array
    {
        $v = Validator::check($input, [
            'title' => 'required,string,max:255', 'kind' => 'nullable,in:event|meeting', 'description' => 'nullable,string,max:20000',
            'location' => 'nullable,string,max:255', 'video_url' => 'nullable,string,max:500', 'all_day' => 'nullable,bool',
            'start_date' => 'required,date', 'start_time' => 'nullable,string,max:5', 'end_date' => 'nullable,date', 'end_time' => 'nullable,string,max:5',
            'recurrence' => 'nullable,in:' . implode('|', array_keys(self::RECURRENCE)), 'recurrence_until' => 'nullable,date',
            'reminder_minutes' => 'nullable,int', 'project_id' => 'nullable,int', 'team_id' => 'nullable,int', 'calendar_id' => 'nullable,int',
        ]);
        $allDay = in_array($v['all_day'] ?? null, [true, 1, '1', 'true', 'on'], true);
        $time = fn($t, $def) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$t) ? (string)$t : (($t === null || $t === '') ? $def : throw HttpException::invalid(['start_time' => 'Hora no válida (HH:MM).']));
        $endDate = $v['end_date'] ?: $v['start_date'];
        if ($allDay) {
            if ($endDate < $v['start_date']) throw HttpException::invalid(['end_date' => 'El final no puede ser antes del inicio.']);
            $s = $v['start_date'] . ' 00:00:00';                                    // all-day: plain dates, no time zone
            $en = date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00';     // exclusive end
        } else {
            $s = self::toUtc($v['start_date'] . ' ' . $time($v['start_time'] ?? null, '09:00'));
            $en = self::toUtc($endDate . ' ' . $time($v['end_time'] ?? null, date('H:i', strtotime('2000-01-01 ' . $time($v['start_time'] ?? null, '09:00') . ' +1 hour'))));
            if ($en <= $s) throw HttpException::invalid(['end_time' => 'El final debe ser posterior al inicio.']);
        }
        $url = trim((string)($v['video_url'] ?? ''));
        if ($url !== '' && !preg_match('#^https://[^\s<>"]+$#i', $url)) throw HttpException::invalid(['video_url' => 'Escribe un enlace que empiece por https://']);
        $rec = $v['recurrence'] ?: 'none';
        if ($rec !== 'none' && $v['recurrence_until'] && $v['recurrence_until'] < $v['start_date']) throw HttpException::invalid(['recurrence_until' => 'La repetición termina antes de empezar.']);
        if (!empty($v['project_id'])) Projects::find((int)$v['project_id']);
        if (!empty($v['team_id']) && !Database::get()->value('SELECT 1 FROM teams WHERE id = ? AND workspace_id = ?', [(int)$v['team_id'], Policy::wsId()])) throw HttpException::invalid(['team_id' => 'Ese equipo no existe.']);
        $rem = ($v['reminder_minutes'] === null || $v['reminder_minutes'] === '') ? null : (int)$v['reminder_minutes'];
        if ($rem !== null && !array_key_exists((string)$rem, self::REMINDERS)) throw HttpException::invalid(['reminder_minutes' => 'Recordatorio no válido.']);
        return [
            'kind' => $v['kind'] ?: ($existing['kind'] ?? 'event'), 'title' => trim($v['title']), 'description' => $v['description'] ?: null,
            'location' => $v['location'] ?: null, 'video_url' => $url ?: null, 'all_day' => $allDay ? 1 : 0, 'starts_at' => $s, 'ends_at' => $en,
            'recurrence' => $rec, 'recurrence_until' => $rec === 'none' ? null : ($v['recurrence_until'] ?: null), 'reminder_minutes' => $rem,
            'project_id' => $v['project_id'] ?: null, 'team_id' => $v['team_id'] ?: null,
        ];
    }

    private static function participants(array $input): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($input['participant_ids'] ?? [])))));
        if (count($ids) > 100) throw HttpException::invalid(['participant_ids' => 'Máximo 100 participantes.']);
        foreach ($ids as $uid) if (!Workspaces::isActiveMember($uid)) throw HttpException::invalid(['participant_ids' => 'Alguien de la lista no pertenece al espacio.']);
        return $ids;
    }

    public static function create(array $input): int
    {
        $cal = Calendars::find((int)($input['calendar_id'] ?? 0), 'edit');
        $f = self::fields($input, null);
        $people = self::participants($input);
        if ($cal['source'] === 'google') {
            if ($f['recurrence'] !== 'none') throw HttpException::invalid(['recurrence' => 'Para eventos que se repiten en un calendario de Google, créalos en Google Calendar.']);
            $f += GoogleCalendar::push($cal, null, $f);
        }
        $db = Database::get();
        $id = $db->transaction(function (Database $db) use ($cal, $f, $people) {
            $id = $db->insert('events', $f + ['calendar_id' => $cal['id'], 'status' => 'confirmed', 'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now()]);
            foreach ($people as $uid) $db->insert('event_participants', ['event_id' => $id, 'user_id' => $uid]);
            return $id;
        });
        $label = $f['kind'] === 'meeting' ? 'la reunión' : 'el evento';
        Activity::log('event_created', 'event', $id, "Creó $label «{$f['title']}» · " . self::when($f), $f['project_id'] ? (int)$f['project_id'] : null, null, [], null,
            ['after' => self::auditView($f, $people), 'source' => $cal['source'] === 'google' ? 'web' : null, 'category' => 'calendar']);
        foreach ($people as $uid) {
            Notifications::send($uid, 'calendar', ($f['kind'] === 'meeting' ? 'Te invitaron a la reunión' : 'Te añadieron al evento') . " «{$f['title']}» · " . self::when($f), url('/calendar', ['date' => self::toLocal($f['starts_at'], 'Y-m-d'), 'event' => $id]), $f['project_id'] ? (int)$f['project_id'] : null, null, "ev-inv-$id-$uid");
        }
        return $id;
    }

    public static function update(int $id, array $input): array
    {
        $e = self::find($id);
        if (!$e['can_edit']) throw HttpException::forbidden('No puedes modificar este evento.');
        $f = self::fields($input + ['title' => $e['title']], $e);
        $people = array_key_exists('participant_ids', $input) ? self::participants($input) : null;
        $db = Database::get();
        // moving to another calendar of the person (internal ones only)
        if (!empty($input['calendar_id']) && (int)$input['calendar_id'] !== (int)$e['calendar_id']) {
            $dest = Calendars::find((int)$input['calendar_id'], 'edit');
            if ($dest['source'] === 'google' || $e['source'] === 'google') throw HttpException::invalid(['calendar_id' => 'Los eventos no se mueven entre Borealis y Google.']);
            $f['calendar_id'] = (int)$dest['id'];
        }
        if ($e['source'] === 'google') {
            $cal = $db->one('SELECT * FROM calendars WHERE id = ?', [$e['calendar_id']]);
            $f += GoogleCalendar::push($cal, $e, $f);
        }
        $db->transaction(function (Database $db) use ($id, $f, $people) {
            $db->update('events', $f + ['updated_at' => now()], 'id = ?', [$id]);
            if ($people !== null) {
                $db->run('DELETE FROM event_participants WHERE event_id = ?', [$id]);
                foreach ($people as $uid) $db->insert('event_participants', ['event_id' => $id, 'user_id' => $uid]);
            }
        });
        if ($people !== null) {
            $before = array_map('intval', array_column($e['participants'], 'id'));
            foreach (array_diff($people, $before) as $uid) Notifications::send($uid, 'calendar', ($f['kind'] === 'meeting' ? 'Te invitaron a la reunión' : 'Te añadieron al evento') . " «{$f['title']}» · " . self::when($f), url('/calendar', ['date' => self::toLocal($f['starts_at'], 'Y-m-d'), 'event' => $id]), null, null, "ev-inv-$id-$uid");
        }
        // 1.14: calendar history with before and after; a new start is a «reprogramación»
        $before = self::auditView($e, array_map('intval', array_column($e['participants'], 'id')));
        $after = self::auditView($f + $e, $people ?? array_map('intval', array_column($e['participants'], 'id')));
        $changed = array_keys(array_filter($after, fn($v, $k) => ($before[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));
        $what = $f['kind'] === 'meeting' ? 'la reunión' : 'el evento';
        $moved = in_array('inicio', $changed, true) || in_array('fin', $changed, true);
        Activity::log($moved ? 'event_rescheduled' : (array_diff($changed, ['participantes']) ? 'event_updated' : 'event_participants'), 'event', $id,
            ($moved ? 'Reprogramó ' . $what . " «{$f['title']}»: " . self::when($e) . ' → ' . self::when($f + $e) : (in_array('participantes', $changed, true) && count($changed) === 1 ? "Cambió los participantes de $what «{$f['title']}»" : "Cambió $what «{$f['title']}»")),
            $f['project_id'] ? (int)$f['project_id'] : null, null, [], null,
            ['before' => array_intersect_key($before, array_flip($changed)), 'after' => array_intersect_key($after, array_flip($changed)), 'reason' => $input['change_reason'] ?? null, 'category' => 'calendar']);
        return self::find($id);
    }

    /** Cancelling keeps the event visible as «Cancelado» and tells the participants; deleting hides it. */
    public static function cancel(int $id, bool $delete = false): void
    {
        $e = self::find($id);
        if (!$e['can_edit']) throw HttpException::forbidden('No puedes cancelar este evento.');
        $db = Database::get();
        if ($e['source'] === 'google') GoogleCalendar::remove($db->one('SELECT * FROM calendars WHERE id = ?', [$e['calendar_id']]), $e);
        $db->update('events', $delete || $e['source'] === 'google' ? ['deleted_at' => now(), 'status' => 'cancelled', 'updated_at' => now()] : ['status' => 'cancelled', 'updated_at' => now()], 'id = ?', [$id]);
        $what = $e['kind'] === 'meeting' ? 'la reunión' : 'el evento';
        Activity::log($delete ? 'event_deleted' : 'event_cancelled', 'event', $id, ($delete ? 'Eliminó ' : 'Canceló ') . "$what «{$e['title']}» (" . self::when($e) . ')', $e['project_id'] ? (int)$e['project_id'] : null, null, [], null,
            ['before' => self::auditView($e, array_map('intval', array_column($e['participants'], 'id'))), 'category' => 'calendar']);
        foreach ($e['participants'] as $p) Notifications::send((int)$p['id'], 'calendar', 'Se canceló ' . $what . " «{$e['title']}» (" . self::when($e) . ')', url('/calendar', ['date' => self::toLocal($e['starts_at'], 'Y-m-d')]), null, null, "ev-cancel-$id-{$p['id']}");
    }

    /** 1.14: what the calendar history keeps of an event (no descriptions or links). */
    private static function auditView(array $e, array $people): array
    {
        sort($people);
        $names = $people ? Database::get()->column('SELECT name FROM users WHERE id IN (' . Database::in($people) . ') ORDER BY name', $people) : [];
        return ['título' => $e['title'] ?? null, 'inicio' => isset($e['starts_at']) ? self::toLocal((string)$e['starts_at']) : null, 'fin' => isset($e['ends_at']) && $e['ends_at'] ? self::toLocal((string)$e['ends_at']) : null,
            'todo el día' => !empty($e['all_day']), 'calendario' => (int)($e['calendar_id'] ?? 0) ?: null, 'participantes' => implode(', ', $names)];
    }

    public static function restore(int $id): void
    {
        $e = self::find($id);
        if (!$e['can_edit'] || $e['source'] === 'google') throw HttpException::forbidden('No puedes reactivar este evento.');
        Database::get()->update('events', ['status' => 'confirmed', 'updated_at' => now()], 'id = ?', [$id]);
        Activity::log('event_restored', 'event', $id, 'Reactivó ' . ($e['kind'] === 'meeting' ? 'la reunión' : 'el evento') . " «{$e['title']}»", $e['project_id'] ? (int)$e['project_id'] : null, null, [], null, ['category' => 'calendar']);
    }

    /** «lun 3 nov, 10:00» in the installation's time zone. */
    public static function when(array $e): string
    {
        if (!empty($e['all_day'])) return fmt_date(substr((string)$e['starts_at'], 0, 10)) . ' (todo el día)';
        return fmt_date(self::toLocal((string)$e['starts_at'], 'Y-m-d')) . ', ' . self::toLocal((string)$e['starts_at'], 'H:i');
    }

    /**
     * In-app reminders (no cron): when the person opens Borealis, events they take part in (or created)
     * that start within their reminder window produce one notification per occurrence.
     */
    public static function generateReminders(): void
    {
        $db = Database::get();
        $today = today();
        $tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
        $rows = $db->all("SELECT e.* FROM events e JOIN calendars c ON c.id = e.calendar_id AND c.deleted_at IS NULL
            WHERE c.workspace_id = ? AND e.deleted_at IS NULL AND e.status = 'confirmed' AND e.reminder_minutes IS NOT NULL
              AND (e.created_by = ? OR EXISTS (SELECT 1 FROM event_participants ep WHERE ep.event_id = e.id AND ep.user_id = ?))
              AND (e.recurrence <> 'none' OR e.starts_at <= ?) LIMIT 200", [Policy::wsId(), Auth::id(), Auth::id(), gmdate('Y-m-d H:i:s', time() + 2 * 86400)]);
        $now = time();
        foreach ($rows as $e) {
            foreach (self::occurrences($e, $today, $tomorrow) as [$s]) {
                $ts = (new \DateTimeImmutable($s, self::tz()))->getTimestamp();
                if ($ts < $now - 3600 || $ts - (int)$e['reminder_minutes'] * 60 > $now) continue;
                $msg = ($e['kind'] === 'meeting' ? 'Reunión' : 'Evento') . " «{$e['title']}» · " . ($e['all_day'] ? fmt_date(substr($s, 0, 10)) : fmt_date(substr($s, 0, 10)) . ', ' . substr($s, 11, 5));
                $db->run('INSERT IGNORE INTO notifications (user_id, workspace_id, type, actor_id, project_id, task_id, message, url, dedupe_key, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [Auth::id(), Policy::wsId(), 'calendar', null, $e['project_id'], null, mb_substr($msg, 0, 255), url('/calendar', ['date' => substr($s, 0, 10), 'event' => $e['id']]), 'ev-rem:' . $e['id'] . ':' . $s, now()]);
            }
        }
    }
}
