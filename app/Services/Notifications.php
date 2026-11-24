<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Security\Policy;

/**
 * Notification centre (1.8). Every notice belongs to a category the person can turn off, for the
 * app and for their phone separately. Reminders that depend on time (tasks about to be due,
 * overdue, sprints starting or ending) are created when the person uses Borealis and, if the
 * installation has the cron job of bin/notify.php, also while nobody has it open.
 * A notice is created once per event (dedupe key), never for completed tasks.
 */
final class Notifications
{
    /** Notice types (stored in notifications.type) and their label. */
    public const TYPES = [
        'assigned' => 'Asignación',
        'mention' => 'Mención',
        'comment' => 'Comentario',
        'due' => 'Vencimiento',
        'overdue' => 'Vencida',
        'date' => 'Cambio de fecha',
        'status' => 'Cambio de estado',
        'team' => 'Equipo',
        'project' => 'Proyecto',
        'capacity' => 'Capacidad',
        'calendar' => 'Calendario',
        'sprint' => 'Sprint',
        'weekly' => 'Resumen semanal',
        'monitor' => 'Monitoreo',
    ];

    /** What the person chooses to receive. key => [label, description, push by default]. */
    public const CATEGORIES = [
        'assigned' => ['Nuevas asignaciones', 'Cuando te hacen responsable de una tarea, a ti o a tu equipo, o dejas de serlo.', true],
        'due' => ['Tareas por vencer', 'Antes de la fecha límite de tus tareas abiertas.', true],
        'overdue' => ['Tareas vencidas', 'Un solo aviso cuando una tarea tuya queda vencida.', true],
        'date' => ['Cambios de fecha de entrega', 'Cuando cambia la fecha límite de una tarea tuya.', true],
        'calendar' => ['Reuniones y eventos', 'Invitaciones, cancelaciones y recordatorios de eventos próximos.', true],
        'sprint' => ['Sprints', 'Próximo inicio, inicio, cierre cercano, fecha de fin alcanzada y cierre.', true],
        'project' => ['Cambios en proyectos', 'Te añaden a un proyecto, cambia su entrega, se completa o finaliza una tarea que creaste.', false],
        'comments' => ['Menciones y comentarios', 'Te mencionan o comentan una tarea tuya.', false],
        'capacity' => ['Capacidad', 'Alguien completó sus horas de la semana y aún tiene tareas planificadas pendientes.', false],
        'weekly' => ['Resumen semanal', 'Los lunes por la mañana: lo que vence en la semana y lo vencido.', false],
        'monitor' => ['Alertas de monitoreo', 'Bloqueos, dependencias en riesgo, sobrecarga y desviaciones (1.12). Primero a quien es responsable; si nadie la revisa, a quien lleva el proyecto y, si es crítica, a la administración.', true],
    ];

    /** 1.9: categories that also go by e-mail by default (only if the installation can send e-mail). */
    public const EMAIL_DEFAULT = ['assigned', 'due', 'overdue', 'sprint', 'capacity', 'weekly'];

    /** 1.9: reminders before the due moment (minutes). Default: 24 hours and 30 minutes. */
    public const BEFORE = [10080 => '1 semana antes', 4320 => '3 días antes', 1440 => '24 horas antes', 120 => '2 horas antes', 30 => '30 minutos antes'];
    public const BEFORE_DEFAULT = [1440, 30];

    /** 1.8 setting (days before), still read to translate old choices into BEFORE. */
    public const LEAD = [0 => 'El mismo día', 1 => '1 día antes', 2 => '2 días antes', 3 => '3 días antes', 7 => '1 semana antes'];

    public static function category(string $type): string
    {
        return match ($type) {
            'mention', 'comment' => 'comments',
            'status', 'team' => 'project',
            'weekly' => 'weekly',
            default => isset(self::CATEGORIES[$type]) ? $type : 'project',
        };
    }

    /** @var array<int, array> */
    private static array $prefsCache = [];

    /** Notification preferences of a person, with defaults. */
    public static function prefs(int $userId): array
    {
        if (!isset(self::$prefsCache[$userId])) {
            $raw = $userId === Auth::id() ? (Auth::user()['prefs'] ?? []) : (json_decode((string)Database::get()->value('SELECT prefs FROM users WHERE id = ?', [$userId]), true) ?: []);
            $n = is_array($raw['notify'] ?? null) ? $raw['notify'] : [];
            $pushOff = $n['push_off'] ?? array_keys(array_filter(self::CATEGORIES, fn($c) => !$c[2]));
            $lead = isset(self::LEAD[(int)($n['lead'] ?? 1)]) ? (int)($n['lead'] ?? 1) : 1;
            // 1.9: «before» replaces «lead»; a 1.8 choice of N days becomes N days (closest option) + 30 minutes
            $before = isset($n['before']) ? array_values(array_intersect(array_map('intval', (array)$n['before']), array_keys(self::BEFORE)))
                : ($lead === 1 || !isset($n['lead']) ? self::BEFORE_DEFAULT : ($lead === 0 ? [30] : [$lead >= 7 ? 10080 : ($lead >= 3 ? 4320 : 1440), 30]));
            rsort($before);
            $off = array_values(array_intersect((array)($n['off'] ?? []), array_keys(self::CATEGORIES)));
            // the weekly summary is off unless the person turned it on (it did not exist in 1.8)
            if (empty($n['weekly'])) $off[] = 'weekly';
            $q = is_array($n['quiet'] ?? null) ? $n['quiet'] : [];
            $hm = fn($v, $d) => is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : $d;
            self::$prefsCache[$userId] = [
                'off' => array_values(array_unique($off)),
                // 1.14: on unless the person turned it off. Before, it was off until the profile form was saved
                // with «Activar», so a device could be subscribed while its owner's notices were dropped.
                'push' => array_key_exists('push', $n) ? !empty($n['push']) : true,
                'push_off' => array_values(array_intersect((array)$pushOff, array_keys(self::CATEGORIES))),
                'lead' => $lead,
                'before' => $before,
                'email' => array_key_exists('email', $n) ? (bool)$n['email'] : true,
                'email_off' => array_values(array_intersect((array)($n['email_off'] ?? array_diff(array_keys(self::CATEGORIES), self::EMAIL_DEFAULT)), array_keys(self::CATEGORIES))),
                'quiet' => ['on' => array_key_exists('on', $q) ? !empty($q['on']) : true, 'from' => $hm($q['from'] ?? null, '22:00'), 'to' => $hm($q['to'] ?? null, '07:00')],
                'timezone' => UserTime::valid($raw['timezone'] ?? null) ? $raw['timezone'] : null,
            ];
        }
        return self::$prefsCache[$userId];
    }

    public static function forgetPrefs(int $userId): void
    {
        unset(self::$prefsCache[$userId]);
        UserTime::forget($userId);
    }

    /** Saves the person's choices (Perfil › Notificaciones). */
    public static function savePrefs(array $input): array
    {
        $cats = array_keys(self::CATEGORIES);
        $on = array_values(array_intersect((array)($input['in_app'] ?? []), $cats));
        $pushOn = array_values(array_intersect((array)($input['push_cats'] ?? []), $cats));
        $yes = fn($v) => in_array($v, [true, 1, '1', 'on', 'true'], true);
        $cur = self::prefs(Auth::id());
        // 1.9: reminders before the due moment (several), e-mail, quiet hours, time zone
        $before = array_key_exists('before', $input) ? array_values(array_intersect(array_map('intval', (array)$input['before']), array_keys(self::BEFORE))) : $cur['before'];
        if (array_key_exists('lead', $input) && !array_key_exists('before', $input)) {
            $lead = (int)$input['lead'];
            if (!isset(self::LEAD[$lead])) throw HttpException::invalid(['lead' => 'Elige cuándo avisar.']);
            $before = $lead === 0 ? [30] : [$lead >= 7 ? 10080 : ($lead >= 3 ? 4320 : 1440), 30];
        }
        $emailOn = array_key_exists('email_cats', $input) ? array_values(array_intersect((array)$input['email_cats'], $cats)) : array_values(array_diff($cats, $cur['email_off']));
        $quiet = ['on' => $yes($input['quiet_on'] ?? ($input['quiet']['on'] ?? false)), 'from' => (string)($input['quiet_from'] ?? '22:00'), 'to' => (string)($input['quiet_to'] ?? '07:00')];
        foreach (['from', 'to'] as $k) {
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $quiet[$k])) throw HttpException::invalid(['quiet_' . $k => 'Hora no válida (hh:mm).']);
        }
        $tz = trim((string)($input['timezone'] ?? ''));
        if ($tz !== '' && !UserTime::valid($tz)) throw HttpException::invalid(['timezone' => 'Zona horaria no válida.']);
        $n = ['off' => array_values(array_diff($cats, $on)), 'push' => $yes($input['push'] ?? false),
            'push_off' => array_values(array_diff($cats, $pushOn)), 'lead' => $cur['lead'], 'before' => $before, 'weekly' => in_array('weekly', $on, true),
            'email' => array_key_exists('email', $input) ? $yes($input['email']) : $cur['email'], 'email_off' => array_values(array_diff($cats, $emailOn)), 'quiet' => $quiet];
        $prefs = Auth::user()['prefs'] ?? [];
        $prefs['notify'] = $n;
        if ($tz === '' || $tz === UserTime::installZone()) unset($prefs['timezone']); else $prefs['timezone'] = $tz;
        Database::get()->update('users', ['prefs' => json_encode($prefs), 'updated_at' => now()], 'id = ?', [Auth::id()]);
        Auth::reset();
        self::forgetPrefs(Auth::id());
        return $n;
    }

    public static function wants(int $userId, string $type): bool
    {
        return !in_array(self::category($type), self::prefs($userId)['off'], true);
    }

    public static function wantsPush(int $userId, string $type): bool
    {
        $p = self::prefs($userId);
        return $p['push'] && self::wants($userId, $type) && !in_array(self::category($type), $p['push_off'], true);
    }

    /** 1.9: the person wants this by e-mail (the installation may still be unable to send it: Mailer). */
    public static function wantsEmail(int $userId, string $type): bool
    {
        $p = self::prefs($userId);
        return $p['email'] && self::wants($userId, $type) && !in_array(self::category($type), $p['email_off'], true);
    }

    /** A notice for another person (never for whoever caused it), if they want that category. */
    public static function send(int $userId, string $type, string $message, ?string $url = null, ?int $projectId = null, ?int $taskId = null, ?string $dedupe = null): void
    {
        if ($userId === Auth::id() || $userId <= 0) return;
        self::store($userId, Policy::wsId(), $type, Auth::id() ?: null, $message, $url, $projectId, $taskId, $dedupe);
    }

    /** Inserts the notice (once per dedupe key) and offers it to the person's devices. */
    public static function store(int $userId, int $wsId, string $type, ?int $actorId, string $message, ?string $url, ?int $projectId, ?int $taskId, ?string $dedupe): bool
    {
        if (!self::wants($userId, $type)) return false;
        $db = Database::get();
        $sql = 'INSERT ' . ($dedupe ? 'IGNORE ' : '') . 'INTO notifications (user_id, workspace_id, type, actor_id, project_id, task_id, message, url, dedupe_key, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)';
        $n = $db->run($sql, [$userId, $wsId, $type, $actorId, $projectId, $taskId, mb_substr($message, 0, 255), $url, $dedupe ? mb_substr($dedupe, 0, 80) : null, now()])->rowCount();
        if ($n > 0) Push::later($userId);
        return $n > 0;
    }

    /**
     * 1.9: is this notice still worth sending to a phone or by e-mail? Not when its task was completed
     * or deleted meanwhile, and not when it is a reminder for a due date (or hour) that changed since.
     */
    public static function stillRelevant(array $n): bool
    {
        if (!$n['task_id'] || !in_array($n['type'], ['due', 'overdue', 'date', 'assigned'], true)) return true;
        $t = Database::get()->one("SELECT t.due_date, t.due_time, t.deleted_at, s.category FROM tasks t JOIN statuses s ON s.id = t.status_id WHERE t.id = ?", [$n['task_id']]);
        if (!$t || $t['deleted_at'] || $t['category'] === 'done') return false;
        if (in_array($n['type'], ['due', 'overdue'], true) && $n['dedupe_key'] && preg_match('/^(?:due|overdue):\d+:([0-9-]{10})(?:T(\d\d:\d\d))?/', (string)$n['dedupe_key'], $m)) {
            if ($m[1] !== $t['due_date']) return false;
            if (isset($m[2]) && $m[2] !== substr((string)$t['due_time'], 0, 5)) return false;
            if (!isset($m[2]) && $t['due_time']) return false;
        }
        return true;
    }

    public static function unreadCount(): int
    {
        return (int)Database::get()->value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND workspace_id = ? AND read_at IS NULL', [Auth::id(), Policy::wsId()]);
    }

    public static function list(int $limit = 50, bool $unreadOnly = false, ?string $category = null): array
    {
        $types = $category && isset(self::CATEGORIES[$category]) ? array_keys(array_filter(self::TYPES, fn($_, $t) => self::category($t) === $category, ARRAY_FILTER_USE_BOTH)) : [];
        $sql = 'SELECT n.*, u.name AS actor_name, u.avatar_color, u.avatar_photo FROM notifications n LEFT JOIN users u ON u.id = n.actor_id
                WHERE n.user_id = ? AND n.workspace_id = ?' . ($unreadOnly ? ' AND n.read_at IS NULL' : '') . ($types ? ' AND n.type IN (' . Database::in($types) . ')' : '') . ' ORDER BY n.id DESC LIMIT ?';
        return Database::get()->all($sql, array_merge([Auth::id(), Policy::wsId()], $types, [$limit]));
    }

    public static function markRead(int $id): void
    {
        $n = Database::get()->update('notifications', ['read_at' => now()], 'id = ? AND user_id = ? AND read_at IS NULL', [$id, Auth::id()]);
        if ($n === 0 && !Database::get()->value('SELECT 1 FROM notifications WHERE id = ? AND user_id = ?', [$id, Auth::id()])) {
            throw HttpException::notFound();
        }
    }

    public static function markAllRead(): int
    {
        return Database::get()->update('notifications', ['read_at' => now()], 'user_id = ? AND workspace_id = ? AND read_at IS NULL', [Auth::id(), Policy::wsId()]);
    }

    /** Reminders of the person using Borealis now (no cron needed). */
    public static function generateDueReminders(): void
    {
        self::generateFor(Auth::id(), Policy::wsId());
    }

    /**
     * Time-based reminders of one person in one workspace (1.9 rules):
     *  - Tasks with an hour: one reminder per chosen moment before it (24 h and 30 min by default).
     *    If several moments already passed (the task was created late, or the cron was stopped) only
     *    the closest one is sent: never a burst of old reminders.
     *  - Tasks with only a date: «vence mañana» / «vence el …» on the matching day, and the moments
     *    shorter than a day become a single «vence hoy» on the day itself. (Quiet hours, on by
     *    default from 22:00 to 07:00, keep these off the phone and the mailbox at night.)
     *  - Overdue: once per task and due date (hour). Completed tasks never generate anything.
     *  - All in the person's own time zone. Sprints and the weekly summary follow.
     * A changed due date or hour gives new keys, so the old reminders are not sent any more
     * (stillRelevant), and the new ones follow the same rules.
     */
    public static function generateFor(int $uid, int $wsId, ?int $ts = null): void
    {
        if ($uid <= 0) return;
        $db = Database::get();
        $p = self::prefs($uid);
        $now = UserTime::now($uid, $ts);
        $today = $now->format('Y-m-d');
        $maxBefore = $p['before'] ? max($p['before']) : 0;
        $limit = $now->modify('+' . max(1, (int)ceil($maxBefore / 1440)) . ' day')->format('Y-m-d');
        $rows = $db->all("SELECT t.id, t.number, t.title, t.due_date, t.due_time, p.id AS project_id, p.project_key
            FROM tasks t JOIN projects p ON p.id = t.project_id JOIN statuses s ON s.id = t.status_id
            WHERE (t.assignee_id = ? OR EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id = t.id AND ta.user_id = ?)
                   OR (t.team_id IS NOT NULL AND EXISTS (SELECT 1 FROM team_members tm WHERE tm.team_id = t.team_id AND tm.user_id = ?)))
              AND p.workspace_id = ? AND t.deleted_at IS NULL AND p.archived_at IS NULL
              AND s.category <> 'done' AND t.completed_at IS NULL AND t.due_date IS NOT NULL AND t.due_date <= ? LIMIT 200", [$uid, $uid, $uid, $wsId, $limit]);
        foreach ($rows as $r) {
            $key = $r['project_key'] . '-' . $r['number'];
            $link = url('/t/' . $key);
            $due = UserTime::dueAt($uid, $r['due_date'], $r['due_time']);
            $stamp = $r['due_date'] . ($due ? 'T' . substr((string)$r['due_time'], 0, 5) : ''); // installation's hour, as stored
            if ($due ? $due <= $now : $r['due_date'] < $today) {
                $since = $due ? 'desde el ' . fmt_date($r['due_date']) . ' a las ' . $due->format('H:i') : 'desde el ' . fmt_date($r['due_date']);
                self::store($uid, $wsId, 'overdue', null, "$key está vencida $since: {$r['title']}", $link, (int)$r['project_id'], (int)$r['id'], 'overdue:' . $r['id'] . ':' . $stamp);
                continue;
            }
            $lead = self::dueLead($p['before'], $now, $r['due_date'], $due);
            if ($lead === null) continue;
            self::store($uid, $wsId, 'due', null, "$key " . self::dueText($now, $r['due_date'], $due) . ": {$r['title']}", $link, (int)$r['project_id'], (int)$r['id'], 'due:' . $r['id'] . ':' . $stamp . ':' . $lead);
        }
        Sprints::remindersFor($uid, $wsId);
        self::weeklySummary($uid, $wsId, $ts);
    }

    /**
     * Which reminder applies now (minutes before), or null. Timed: the smallest chosen moment that
     * has already been reached. Date only: whole days; moments under a day = «hoy».
     */
    public static function dueLead(array $before, \DateTimeImmutable $now, string $date, ?\DateTimeImmutable $due): ?int
    {
        if (!$before) return null;
        if ($due) {
            $mins = ($due->getTimestamp() - $now->getTimestamp()) / 60;
            $hit = array_filter($before, fn($b) => $mins <= $b);
            return $hit ? min($hit) : null;
        }
        $days = (int)$now->setTime(0, 0)->diff(new \DateTimeImmutable($date, $now->getTimezone()))->format('%r%a');
        $hit = array_filter($before, fn($b) => $b < 1440 ? $days === 0 : $days <= intdiv($b, 1440));
        if (!$hit) return null;
        $min = min($hit);
        return $min < 1440 ? 0 : $min; // all the «hours before» of a date-only task are one «hoy»
    }

    public static function dueText(\DateTimeImmutable $now, string $date, ?\DateTimeImmutable $due): string
    {
        $tomorrow = $now->modify('+1 day')->format('Y-m-d');
        if ($due) {
            $mins = (int)ceil(($due->getTimestamp() - $now->getTimestamp()) / 60);
            if ($mins <= 90) return 'vence en ' . max(1, $mins) . ' minutos (a las ' . $due->format('H:i') . ')';
            if ($date === $now->format('Y-m-d')) return 'vence hoy a las ' . $due->format('H:i');
            if ($date === $tomorrow) return 'vence mañana a las ' . $due->format('H:i');
            return 'vence el ' . fmt_date($date) . ' a las ' . $due->format('H:i');
        }
        return $date === $now->format('Y-m-d') ? 'vence hoy' : ($date === $tomorrow ? 'vence mañana' : 'vence el ' . fmt_date($date));
    }

    /** 1.9: Monday from 08:00 (person's zone), once a week, only if they turned it on. */
    public static function weeklySummary(int $uid, int $wsId, ?int $ts = null): void
    {
        if (!self::wants($uid, 'weekly')) return;
        $now = UserTime::now($uid, $ts);
        if ($now->format('N') !== '1' || $now->format('H') < '08') return;
        $mon = $now->format('Y-m-d');
        $sun = $now->modify('+6 day')->format('Y-m-d');
        $db = Database::get();
        $who = "(t.assignee_id = ? OR EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id = t.id AND ta.user_id = ?))";
        $base = "FROM tasks t JOIN projects p ON p.id = t.project_id JOIN statuses s ON s.id = t.status_id WHERE $who AND p.workspace_id = ? AND t.deleted_at IS NULL AND p.archived_at IS NULL AND s.category <> 'done'";
        $week = (int)$db->value("SELECT COUNT(*) $base AND t.due_date BETWEEN ? AND ?", [$uid, $uid, $wsId, $mon, $sun]);
        $late = (int)$db->value("SELECT COUNT(*) $base AND t.due_date < ?", [$uid, $uid, $wsId, $mon]);
        $msg = 'Tu semana: ' . ($week === 1 ? '1 tarea vence' : "$week tareas vencen") . ' esta semana' . ($late ? ' y ' . ($late === 1 ? '1 está vencida' : "$late están vencidas") : '') . '.';
        self::store($uid, $wsId, 'weekly', null, $msg, url('/my-work'), null, null, 'weekly:' . $mon);
    }

    /** Responsible people of a task whose due date (1.8) or hour (1.9) changed. */
    public static function dueChanged(array $t, ?string $old, ?string $new, ?string $oldTime = null, ?string $newTime = null): void
    {
        $oldTime = $old && $oldTime ? substr($oldTime, 0, 5) : null;
        $newTime = $new && $newTime ? substr($newTime, 0, 5) : null;
        if ($old === $new && $oldTime === $newTime) return;
        $ids = array_unique(Tasks::responsibleIds($t));
        $fmt = fn($d, $h) => fmt_date($d) . ($h ? " a las $h" : '');
        $msg = "{$t['task_key']}: la fecha de entrega " . ($new ? ($old ? 'pasó del ' . $fmt($old, $oldTime) . ' al ' . $fmt($new, $newTime) : 'es el ' . $fmt($new, $newTime)) : 'se quitó') . " · {$t['title']}";
        foreach ($ids as $uid) self::send((int)$uid, 'date', $msg, url('/t/' . $t['task_key']), (int)$t['project_id'], (int)$t['id'], 'date:' . $t['id'] . ':' . ($new ?? 'none') . ($newTime ? 'T' . $newTime : ''));
    }
}
