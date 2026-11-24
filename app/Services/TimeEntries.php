<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Validator;
use Borealis\Security\Policy;

/** Hours worked on tasks, per person. Everyone logs their own; project managers can log for participants. */
final class TimeEntries
{
    public static function parseHours(mixed $v): int
    {
        // Accepts 1.5, "1,5", "1:30", "90m", "2h".
        $s = strtolower(trim((string)$v));
        if (preg_match('/^(\d{1,3}):([0-5]\d)$/', $s, $m)) return (int)$m[1] * 60 + (int)$m[2];
        if (preg_match('/^(\d{1,4})\s*m(in)?$/', $s, $m)) return (int)$m[1];
        $s = rtrim(str_replace(',', '.', $s), 'h ');
        if ($s === '' || !is_numeric($s)) throw HttpException::invalid(['hours' => 'Escribe las horas, por ejemplo 1.5 o 1:30.']);
        return (int)round((float)$s * 60);
    }

    public static function log(int $taskId, array $input): int
    {
        $t = Tasks::find($taskId);
        $p = $t['project'];
        Policy::authorizeProject($p, 'task.edit');
        $v = Validator::check($input, ['hours' => 'required', 'work_date' => 'nullable,date', 'note' => 'nullable,string,max:255', 'user_id' => 'nullable,int']);
        $minutes = self::parseHours($v['hours']);
        if ($minutes < 1 || $minutes > 24 * 60) throw HttpException::invalid(['hours' => 'Indica entre 1 minuto y 24 horas por registro.']);
        $date = $v['work_date'] ?: today();
        if ($date > today()) throw HttpException::invalid(['work_date' => 'No se pueden registrar horas en el futuro.']);
        $uid = (int)($v['user_id'] ?: Auth::id());
        if ($uid !== Auth::id()) {
            if (!Policy::canProject($p, 'project.manage')) throw HttpException::forbidden('Solo la persona responsable del proyecto puede registrar horas de otras personas.');
            if (!in_array($uid, array_map('intval', array_column(Projects::members($p), 'id')), true)) throw HttpException::invalid(['user_id' => 'Esa persona no participa en el proyecto.']);
        }
        // 1.12: the same entry sent twice (double click, a retried request) is stored once
        $dup = Database::get()->value('SELECT id FROM time_entries WHERE task_id = ? AND user_id = ? AND work_date = ? AND minutes = ? AND COALESCE(note, \'\') = ? AND created_by = ? AND deleted_at IS NULL AND created_at >= ?',
            [$taskId, $uid, $date, $minutes, (string)($v['note'] ?: ''), Auth::id(), gmdate('Y-m-d H:i:s', time() - 60)]);
        if ($dup) return (int)$dup;
        $id = Database::get()->insert('time_entries', [
            'project_id' => $p['id'], 'task_id' => $taskId, 'user_id' => $uid, 'minutes' => $minutes, 'work_date' => $date,
            'note' => $v['note'] ?: null, 'created_by' => Auth::id(), 'created_at' => now(),
        ]);
        $who = $uid === Auth::id() ? '' : ' para ' . (Users::find($uid)['name'] ?? '');
        Activity::log('time_logged', 'task', $taskId, 'Registró ' . self::fmt($minutes) . "$who en {$t['task_key']}", (int)$p['id'], $taskId);
        CapacityAlerts::check($uid, $date);
        return $id;
    }

    /**
     * 1.12: correct an entry (hours, date, note). Same permissions as deleting it: whoever logged it
     * or the person it belongs to, or a project manager. The before and after stay in the history.
     */
    public static function update(int $id, array $input): array
    {
        $db = Database::get();
        $e = $db->one('SELECT te.*, p.workspace_id FROM time_entries te JOIN projects p ON p.id = te.project_id WHERE te.id = ? AND te.deleted_at IS NULL', [$id]);
        if (!$e || (int)$e['workspace_id'] !== Policy::wsId()) throw HttpException::notFound();
        $t = Tasks::find((int)$e['task_id']);
        $mine = (int)$e['user_id'] === Auth::id() || (int)$e['created_by'] === Auth::id();
        Policy::authorizeProject($t['project'], $mine ? 'task.edit' : 'project.manage');
        $v = Validator::check($input, ['hours' => 'sometimes,required', 'work_date' => 'sometimes,required,date', 'note' => 'sometimes,nullable,string,max:255']);
        $data = [];
        if (array_key_exists('hours', $v)) {
            $m = self::parseHours($v['hours']);
            if ($m < 1 || $m > 24 * 60) throw HttpException::invalid(['hours' => 'Indica entre 1 minuto y 24 horas por registro.']);
            if ($m !== (int)$e['minutes']) $data['minutes'] = $m;
        }
        if (array_key_exists('work_date', $v)) {
            if ($v['work_date'] > today()) throw HttpException::invalid(['work_date' => 'No se pueden registrar horas en el futuro.']);
            if ($v['work_date'] !== $e['work_date']) $data['work_date'] = $v['work_date'];
        }
        if (array_key_exists('note', $v) && (string)($v['note'] ?? '') !== (string)($e['note'] ?? '')) $data['note'] = $v['note'] ?: null;
        if (!$data) return $e;
        $db->update('time_entries', $data, 'id = ?', [$id]);
        $before = self::fmt((int)$e['minutes']) . ' el ' . fmt_dmy($e['work_date']);
        $after = self::fmt((int)($data['minutes'] ?? $e['minutes'])) . ' el ' . fmt_dmy($data['work_date'] ?? $e['work_date']);
        Activity::log('time_updated', 'task', (int)$e['task_id'], "Corrigió un registro de horas en {$t['task_key']}: $before → $after", (int)$t['project_id'], (int)$t['id'],
            ['entry' => $id, 'before' => ['minutes' => (int)$e['minutes'], 'work_date' => $e['work_date'], 'note' => $e['note']], 'after' => $data]);
        CapacityAlerts::check((int)$e['user_id'], (string)$e['work_date']);
        if (isset($data['work_date'])) CapacityAlerts::check((int)$e['user_id'], (string)$data['work_date']);
        return $db->one('SELECT * FROM time_entries WHERE id = ?', [$id]);
    }

    public static function delete(int $id): void
    {
        $db = Database::get();
        $e = $db->one('SELECT te.*, p.workspace_id FROM time_entries te JOIN projects p ON p.id = te.project_id WHERE te.id = ? AND te.deleted_at IS NULL', [$id]);
        if (!$e || (int)$e['workspace_id'] !== Policy::wsId()) throw HttpException::notFound();
        $t = Tasks::find((int)$e['task_id']);
        $mine = (int)$e['user_id'] === Auth::id() || (int)$e['created_by'] === Auth::id();
        Policy::authorizeProject($t['project'], $mine ? 'task.edit' : 'project.manage');
        $db->update('time_entries', ['deleted_at' => now()], 'id = ?', [$id]);
        Activity::log('time_deleted', 'task', (int)$e['task_id'], 'Quitó un registro de ' . self::fmt((int)$e['minutes']) . " en {$t['task_key']}", (int)$t['project_id'], (int)$t['id']);
        CapacityAlerts::check((int)$e['user_id'], (string)$e['work_date']);
    }

    /** Entries of a task (and its subtasks), plus totals per person. */
    public static function forTask(int $taskId): array
    {
        $rows = Database::get()->all('SELECT te.*, u.name AS user_name, u.avatar_color, t.number, t.parent_id FROM time_entries te JOIN users u ON u.id = te.user_id JOIN tasks t ON t.id = te.task_id
            WHERE te.deleted_at IS NULL AND (te.task_id = ? OR t.parent_id = ?) ORDER BY te.work_date DESC, te.id DESC', [$taskId, $taskId]);
        $by = [];
        foreach ($rows as $r) {
            $by[$r['user_id']] ??= ['user_id' => (int)$r['user_id'], 'name' => $r['user_name'], 'avatar_color' => $r['avatar_color'], 'minutes' => 0];
            $by[$r['user_id']]['minutes'] += (int)$r['minutes'];
        }
        usort($by, fn($a, $b) => $b['minutes'] <=> $a['minutes']);
        return ['entries' => $rows, 'people' => array_values($by), 'total' => array_sum(array_column($rows, 'minutes'))];
    }

    /** Minutes per person in a project (optionally between dates). */
    public static function byPerson(int $projectId, ?string $from = null, ?string $to = null): array
    {
        $w = 'te.project_id = ? AND te.deleted_at IS NULL';
        $p = [$projectId];
        if ($from) { $w .= ' AND te.work_date >= ?'; $p[] = $from; }
        if ($to) { $w .= ' AND te.work_date <= ?'; $p[] = $to; }
        return Database::get()->all("SELECT u.id, u.name, u.avatar_color, SUM(te.minutes) AS minutes, COUNT(DISTINCT te.task_id) AS tasks
            FROM time_entries te JOIN users u ON u.id = te.user_id WHERE $w GROUP BY u.id, u.name, u.avatar_color ORDER BY minutes DESC", $p);
    }

    /** Hours per task in a project (top-level tasks include their subtasks' hours). */
    public static function byTask(int $projectId, ?string $from = null, ?string $to = null): array
    {
        $w = 'te.project_id = ? AND te.deleted_at IS NULL';
        $p = [$projectId];
        if ($from) { $w .= ' AND te.work_date >= ?'; $p[] = $from; }
        if ($to) { $w .= ' AND te.work_date <= ?'; $p[] = $to; }
        return Database::get()->all("SELECT r.id, CONCAT(pr.project_key, '-', r.number) AS task_key, r.title, r.estimate_hours, SUM(te.minutes) AS minutes, COUNT(DISTINCT te.user_id) AS people
            FROM time_entries te JOIN tasks t ON t.id = te.task_id JOIN tasks r ON r.id = COALESCE(t.parent_id, t.id) JOIN projects pr ON pr.id = r.project_id
            WHERE $w AND r.deleted_at IS NULL GROUP BY r.id, pr.project_key, r.number, r.title, r.estimate_hours ORDER BY minutes DESC", $p);
    }

    /** Individual entries for export, limited to projects the current user can see. */
    public static function entries(?int $projectId, ?string $from, ?string $to): array
    {
        [$vis, $p] = Policy::visibleProjectsSql('pr');
        $w = "te.deleted_at IS NULL AND t.deleted_at IS NULL AND $vis";
        if ($projectId) { $w .= ' AND te.project_id = ?'; $p[] = $projectId; }
        if ($from) { $w .= ' AND te.work_date >= ?'; $p[] = $from; }
        if ($to) { $w .= ' AND te.work_date <= ?'; $p[] = $to; }
        return Database::get()->all("SELECT te.work_date, te.minutes, te.note, u.name AS user_name, pr.name AS project_name, CONCAT(pr.project_key, '-', t.number) AS task_key, t.title AS task_title
            FROM time_entries te JOIN tasks t ON t.id = te.task_id JOIN projects pr ON pr.id = te.project_id JOIN users u ON u.id = te.user_id
            WHERE $w ORDER BY te.work_date, pr.project_key, t.number, te.id LIMIT 20000", $p);
    }

    /** Hours per person across the visible projects of the workspace. */
    public static function workspaceByPerson(?string $from, ?string $to): array
    {
        [$vis, $p] = Policy::visibleProjectsSql('pr');
        $w = "te.deleted_at IS NULL AND $vis";
        if ($from) { $w .= ' AND te.work_date >= ?'; $p[] = $from; }
        if ($to) { $w .= ' AND te.work_date <= ?'; $p[] = $to; }
        return Database::get()->all("SELECT u.id, u.name, u.avatar_color, SUM(te.minutes) AS minutes, COUNT(DISTINCT te.task_id) AS tasks, COUNT(DISTINCT te.project_id) AS projects
            FROM time_entries te JOIN projects pr ON pr.id = te.project_id JOIN users u ON u.id = te.user_id WHERE $w GROUP BY u.id, u.name, u.avatar_color ORDER BY u.name", $p);
    }

    /**
     * A person's hours for their profile, limited to projects the viewer can see:
     * totals for this week / this month / the chosen period, the last 8 weeks, per project and
     * the most recent entries.
     */
    public static function forPerson(int $userId, ?string $from): array
    {
        [$vis, $vp] = Policy::visibleProjectsSql('pr');
        $db = Database::get();
        $base = "FROM time_entries te JOIN projects pr ON pr.id = te.project_id JOIN tasks t ON t.id = te.task_id WHERE te.user_id = ? AND te.deleted_at IS NULL AND t.deleted_at IS NULL AND $vis";
        $params = array_merge([$userId], $vp);
        $sum = fn(?string $since) => (int)$db->value("SELECT COALESCE(SUM(te.minutes), 0) $base" . ($since ? ' AND te.work_date >= ?' : ''), $since ? array_merge($params, [$since]) : $params);
        $today = today();
        $weekStart = date('Y-m-d', strtotime('monday this week', strtotime($today)));
        $monthStart = substr($today, 0, 8) . '01';
        $weeks = [];
        for ($i = 7; $i >= 0; $i--) {
            $ws = date('Y-m-d', strtotime("$weekStart -$i week"));
            $weeks[$ws] = ['week' => $ws, 'minutes' => 0];
        }
        $first = array_key_first($weeks);
        foreach ($db->all("SELECT te.work_date, te.minutes $base AND te.work_date >= ?", array_merge($params, [$first])) as $row) {
            $wk = date('Y-m-d', strtotime('monday this week', strtotime($row['work_date'])));
            if (isset($weeks[$wk])) $weeks[$wk]['minutes'] += (int)$row['minutes'];
        }
        $pf = $from ? ' AND te.work_date >= ?' : '';
        $pp = $from ? array_merge($params, [$from]) : $params;
        return [
            'week' => $sum($weekStart),
            'month' => $sum($monthStart),
            'period' => $sum($from),
            'total' => $sum(null),
            'weeks' => array_values($weeks),
            'projects' => $db->all("SELECT pr.id, pr.name, pr.color, SUM(te.minutes) AS minutes, COUNT(DISTINCT te.task_id) AS tasks $base$pf GROUP BY pr.id, pr.name, pr.color ORDER BY minutes DESC", $pp),
            'recent' => $db->all("SELECT te.id, te.work_date, te.minutes, te.note, CONCAT(pr.project_key, '-', t.number) AS task_key, t.title AS task_title, pr.name AS project_name $base$pf ORDER BY te.work_date DESC, te.id DESC LIMIT 25", $pp),
        ];
    }

    /** Minutes the current user logged this week (Monday–Sunday) in this workspace. */
    public static function myWeek(): int
    {
        $start = date('Y-m-d', strtotime('monday this week', strtotime(today())));
        return (int)Database::get()->value('SELECT COALESCE(SUM(te.minutes), 0) FROM time_entries te JOIN projects p ON p.id = te.project_id
            WHERE te.user_id = ? AND p.workspace_id = ? AND te.deleted_at IS NULL AND te.work_date >= ?', [Auth::id(), Policy::wsId(), $start]);
    }

    public static function fmt(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return $h && $m ? "{$h} h {$m} min" : ($h ? "{$h} h" : "{$m} min");
    }
}
