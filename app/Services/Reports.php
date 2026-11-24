<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Database;
use Borealis\Security\Policy;

/** Coordination metrics. Deliberately no per-person productivity rankings. */
final class Reports
{
    public static function project(array $p): array
    {
        $db = Database::get();
        $pid = (int)$p['id'];
        $byStatus = $db->all('SELECT s.id, s.name, s.color, s.category, COUNT(t.id) AS n FROM statuses s
            LEFT JOIN tasks t ON t.status_id = s.id AND t.deleted_at IS NULL AND t.parent_id IS NULL WHERE s.project_id = ? GROUP BY s.id ORDER BY s.position', [$pid]);
        $workload = $db->all("SELECT u.id, u.name, u.avatar_color, COUNT(t.id) AS open_tasks, SUM(t.due_date < ?) AS overdue, SUM(t.is_blocked) AS blocked
            FROM tasks t JOIN statuses s ON s.id = t.status_id LEFT JOIN users u ON u.id = t.assignee_id
            WHERE t.project_id = ? AND t.deleted_at IS NULL AND s.category <> 'done' GROUP BY u.id ORDER BY u.name IS NULL, u.name", [today(), $pid]);
        $weeks = [];
        for ($i = 7; $i >= 0; $i--) {
            $start = date('Y-m-d', strtotime("monday this week -$i week", strtotime(today())));
            $weeks[$start] = ['week' => $start, 'created' => 0, 'completed' => 0];
        }
        $first = array_key_first($weeks);
        foreach ($db->all("SELECT DATE(created_at) AS d FROM tasks WHERE project_id = ? AND deleted_at IS NULL AND parent_id IS NULL AND created_at >= ?", [$pid, $first]) as $r) {
            $wk = date('Y-m-d', strtotime('monday this week', strtotime($r['d'])));
            if (isset($weeks[$wk])) $weeks[$wk]['created']++;
        }
        foreach ($db->all("SELECT DATE(completed_at) AS d FROM tasks WHERE project_id = ? AND deleted_at IS NULL AND parent_id IS NULL AND completed_at >= ?", [$pid, $first]) as $r) {
            $wk = date('Y-m-d', strtotime('monday this week', strtotime($r['d'])));
            if (isset($weeks[$wk])) $weeks[$wk]['completed']++;
        }
        return [
            'by_status' => $byStatus,
            'workload' => $workload,
            'overdue' => Tasks::query(['project_id' => $pid, 'overdue' => 1, 'sort' => 'due', 'limit' => 50]),
            'blocked' => Tasks::query(['project_id' => $pid, 'blocked' => 1, 'category' => ['todo', 'in_progress'], 'sort' => 'priority', 'limit' => 50]),
            'weeks' => array_values($weeks),
            'activity' => Activity::recent(15, $pid),
            'progress' => Projects::withProgress([$p])[0],
            'hours_people' => TimeEntries::byPerson($pid),
            'hours_tasks' => array_slice(TimeEntries::byTask($pid), 0, 15),
        ];
    }

    public static function workspace(): array
    {
        $projects = Projects::list(['sort' => 'due']);
        return [
            'projects' => $projects,
            'overdue' => Tasks::query(['overdue' => 1, 'sort' => 'due', 'limit' => 30]),
            'blocked' => Tasks::query(['blocked' => 1, 'category' => ['todo', 'in_progress'], 'sort' => 'priority', 'limit' => 30]),
            'totals' => [
                'projects' => count($projects),
                'open' => array_sum(array_map(fn($p) => $p['total'] - $p['done'], $projects)),
                'done' => array_sum(array_column($projects, 'done')),
                'overdue' => array_sum(array_column($projects, 'overdue')),
                'blocked' => array_sum(array_column($projects, 'blocked')),
            ],
            'hours_people' => TimeEntries::workspaceByPerson(null, null),
        ];
    }

    /** Spreadsheet-safe CSV (UTF-8 with BOM, formula-looking cells neutralised). */
    public static function csv(array $header, iterable $rows): string
    {
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $header, ',', '"', '');
        foreach ($rows as $cells) {
            $cells = array_map(fn($c) => is_string($c) && preg_match('/^[=+\-@\t\r]/', $c) ? "'" . $c : $c, $cells);
            fputcsv($fh, $cells, ',', '"', '');
        }
        rewind($fh);
        return (string)stream_get_contents($fh);
    }

    public static function hours(int $minutes): string
    {
        return number_format($minutes / 60, 2, ',', '');
    }

    /** CSV export of tasks matching filters. */
    public static function tasksCsv(array $filters): string
    {
        $rows = Tasks::query($filters + ['with_subtasks' => 1, 'sort' => 'key', 'limit' => 5000]);
        return self::csv(['Clave', 'Título', 'Proyecto', 'Estado', 'Prioridad', 'Responsables', 'Inicio', 'Vencimiento', 'Estimación', 'Horas estimadas', 'Horas registradas', 'Sprint', 'Épica', 'Bloqueada', 'Etiquetas', 'Creada', 'Finalizada'],
            array_map(fn($t) => [$t['task_key'], $t['title'], $t['project_name'], $t['status_name'], $t['priority_name'], Tasks::responsibleLabel($t), fmt_dmy($t['start_date']), fmt_dmy($t['due_date']), $t['estimate'],
                $t['estimate_hours'] !== null ? number_format((float)$t['estimate_hours'], 2, ',', '') : '', self::hours((int)$t['logged_minutes']), $t['sprint_name'], $t['epic_name'], $t['is_blocked'] ? 'Sí' : 'No', implode('; ', array_column($t['labels'], 'name')), fmt_dmy_time($t['created_at']), fmt_dmy_time($t['completed_at'])], $rows));
    }

    /** CSV of individual time entries (one row per entry). */
    public static function hoursCsv(?int $projectId, ?string $from, ?string $to): string
    {
        return self::csv(['Fecha', 'Persona', 'Proyecto', 'Clave', 'Tarea', 'Horas', 'Minutos', 'Nota'],
            array_map(fn($e) => [fmt_dmy($e['work_date']), $e['user_name'], $e['project_name'], $e['task_key'], $e['task_title'], self::hours((int)$e['minutes']), (int)$e['minutes'], $e['note']], TimeEntries::entries($projectId, $from, $to)));
    }

    public static function projectsCsv(): string
    {
        return self::csv(['Clave', 'Proyecto', 'Equipo', 'Estado', 'Progreso (%)', 'Tareas', 'Finalizadas', 'Vencidas', 'Bloqueadas', 'Entrega'],
            array_map(fn($p) => [$p['project_key'], $p['name'], $p['team_name'] ?? '', $p['state'], (int)$p['progress'], (int)$p['total'], (int)$p['done'], (int)$p['overdue'], (int)$p['blocked'], fmt_dmy($p['due_date'])], Projects::list(['sort' => 'due'])));
    }

    /** Validated optional date range from the query string. */
    public static function range(array $q): array
    {
        $ok = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) ? $d : null;
        $from = $ok($q['from'] ?? null);
        $to = $ok($q['to'] ?? null);
        if ($from && $to && $from > $to) [$from, $to] = [$to, $from];
        return [$from, $to];
    }
}
