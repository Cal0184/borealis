<?php
declare(strict_types=1);

namespace Borealis\Services\Analysis;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Security\Policy;
use Borealis\Services\Activity;
use Borealis\Services\Capacity;
use Borealis\Services\Projects;
use Borealis\Services\Tasks;

/**
 * Análisis (1.13): «Escenarios», a deterministic simulator of capacity and dates for one sprint.
 *
 * The person changes variables in memory (move a task out of the sprint, change an estimate, a due
 * date, a responsible person, a priority, someone's available hours, or resolve a block
 * hypothetically) and Borealis recalculates the same indicators as the risk engine (Planning::
 * evaluate) for «Estado actual» and «Escenario simulado». Nothing is written while simulating.
 *
 * Applying (only quien gestiona el proyecto): preview → permission check → the data must not have
 * changed since the simulation (fingerprint) → confirmation → one transaction through the normal
 * services (same validation, capacity guard, notifications and history as by hand) → audit row in
 * scenario_applications → revert while nobody has touched those tasks since.
 * Limits: it does not reschedule dependencies in cascade nor model partial days; a resolved block
 * is hypothetical only and is never applied.
 */
final class Scenarios
{
    public const TYPES = ['move' => 'Trasladar fuera del sprint', 'estimate' => 'Cambiar la estimación', 'due' => 'Cambiar la fecha límite', 'assign' => 'Cambiar responsable',
        'priority' => 'Cambiar prioridad', 'capacity' => 'Ajustar horas disponibles', 'unblock' => 'Resolver el bloqueo (hipotético)'];

    public static function load(int $sprintId): array
    {
        $sp = Analysis::sprint($sprintId);
        if (!in_array($sp['state'], ['active', 'planned'], true)) throw HttpException::conflict('Solo se simulan sprints activos o planificados.');
        if (!Analysis::canManage((int)$sp['project_id'])) throw HttpException::forbidden('Solo quien gestiona el proyecto puede simular y aplicar escenarios.');
        return [$sp, Planning::snapshot($sp)];
    }

    /** Fingerprint of everything the simulation read: if any of it changes, the scenario is stale. */
    public static function fingerprint(array $snap): string
    {
        $t = array_map(fn($x) => [$x['id'], $x['updated_at'], $x['estimate'], $x['remaining_set'], $x['due'], $x['blocked'], $x['people'], $x['priority_id'], $x['logged'], $x['done']], array_values($snap['tasks']));
        $c = array_map(fn($x) => [$x['hours'], $x['set']], $snap['capacity']);
        return hash('sha256', json_encode([$snap['sprint'], $t, $c]));
    }

    /** Validates the changes against the snapshot and applies them in memory. Pure apart from the reference lookups. */
    public static function applyInMemory(array $snap, array $changes, array $targets = [], array $priorities = []): array
    {
        $m = $snap;
        $notes = [];
        foreach (array_values($changes) as $i => $c) {
            $type = (string)($c['type'] ?? '');
            if (!isset(self::TYPES[$type])) throw HttpException::invalid(["changes.$i" => 'Cambio desconocido.']);
            if ($type === 'capacity') {
                $u = (int)($c['user_id'] ?? 0);
                if (!isset($m['capacity'][$u])) throw HttpException::invalid(["changes.$i" => 'Esa persona no participa en el proyecto.']);
                $h = $c['hours'] ?? null;
                if (!is_numeric($h) || (float)$h < 0 || (float)$h > 999) throw HttpException::invalid(["changes.$i" => 'Las horas disponibles deben estar entre 0 y 999.']);
                $m['capacity'][$u]['hours'] = (float)$h;
                continue;
            }
            $tid = (int)($c['task_id'] ?? 0);
            if (!isset($m['tasks'][$tid])) throw HttpException::invalid(["changes.$i" => 'La tarea no está en este sprint.']);
            $t = &$m['tasks'][$tid];
            switch ($type) {
                case 'move':
                    $to = $c['to_sprint_id'] ?? null;
                    if ($to !== null && $to !== '' && !isset($targets[(int)$to])) throw HttpException::invalid(["changes.$i" => 'Elige un sprint planificado del mismo proyecto o el backlog.']);
                    $t['in'] = false;
                    break;
                case 'estimate':
                    $h = $c['hours'] ?? null;
                    if (!is_numeric($h) || (float)$h < 0 || (float)$h > 9999) throw HttpException::invalid(["changes.$i" => 'Indica horas entre 0 y 9999.']);
                    $t['estimate'] = (float)$h;
                    $t['remaining_set'] = null; // remaining = new estimate − logged
                    break;
                case 'due':
                    $d = (string)($c['date'] ?? '');
                    if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) throw HttpException::invalid(["changes.$i" => 'Fecha no válida.']);
                    $t['due'] = $d ?: null;
                    break;
                case 'assign':
                    $u = (int)($c['user_id'] ?? 0);
                    if (!isset($m['capacity'][$u])) throw HttpException::invalid(["changes.$i" => 'Esa persona no participa en el proyecto.']);
                    $t['people'] = [$u];
                    break;
                case 'priority':
                    $p = $c['priority_id'] ?? null;
                    if ($p !== null && $p !== '' && !isset($priorities[(int)$p])) throw HttpException::invalid(["changes.$i" => 'Prioridad no válida.']);
                    $t['priority_id'] = $p ? (int)$p : null;
                    $t['weight'] = $p ? (int)$priorities[(int)$p]['weight'] : 0;
                    $t['priority'] = $p ? $priorities[(int)$p]['name'] : null;
                    break;
                case 'unblock':
                    $t['blocked'] = false;
                    // the tasks that depend on it stop counting it as blocked
                    foreach ($m['tasks'] as &$o) foreach ($o['deps'] as &$d) if ((int)$d['a'] === $tid) $d['a_blocked'] = 0;
                    unset($o, $d);
                    $notes[] = 'Resolver el bloqueo de ' . $t['key'] . ' es un supuesto: no se aplicará.';
                    break;
            }
            unset($t);
        }
        return [$m, $notes];
    }

    /** Planned sprints of the same project (destinations for «trasladar») and the workspace priorities. */
    public static function references(array $sp): array
    {
        $db = Database::get();
        $targets = array_column($db->all("SELECT id, name FROM sprints WHERE project_id = ? AND state = 'planned' AND id <> ? ORDER BY start_date IS NULL, start_date, id", [(int)$sp['project_id'], (int)$sp['id']]), null, 'id');
        $prios = array_column($db->all('SELECT id, name, weight FROM priorities WHERE workspace_id = ? ORDER BY weight', [Policy::wsId()]), null, 'id');
        return [$targets, $prios];
    }

    public static function simulate(int $sprintId, array $changes): array
    {
        [$sp, $snap] = self::load($sprintId);
        [$targets, $prios] = self::references($sp);
        $cfg = Analysis::settings();
        [$from, $to] = Analysis::period(null, null);
        $hist = History::indicators([(int)$sp['project_id']], $from, $to)['deviation'];
        $dev = $hist['enough'] ? $hist['median_pct'] : null;
        [$sim, $notes] = self::applyInMemory($snap, $changes, $targets, $prios);
        $now = Planning::evaluate($snap, $cfg, $dev);
        $after = Planning::evaluate($sim, $cfg, $dev);
        $moved = array_values(array_filter($sim['tasks'], fn($t) => !$t['in']));
        $nextLoad = null;
        foreach ($changes as $c) if (($c['type'] ?? '') === 'move' && !empty($c['to_sprint_id']) && isset($targets[(int)$c['to_sprint_id']])) {
            $nextLoad[(int)$c['to_sprint_id']] = ($nextLoad[(int)$c['to_sprint_id']] ?? 0) + (Metrics::remaining($sim['tasks'][(int)$c['task_id']]['estimate'], $sim['tasks'][(int)$c['task_id']]['remaining_set'], $sim['tasks'][(int)$c['task_id']]['logged']) ?? 0);
        }
        return ['sprint' => $snap['sprint'], 'fingerprint' => self::fingerprint($snap), 'current' => $now, 'scenario' => $after, 'notes' => $notes,
            'moved' => array_map(fn($t) => ['id' => $t['id'], 'key' => $t['key'], 'hours' => Metrics::remaining($t['estimate'], $t['remaining_set'], $t['logged'])], $moved),
            'next_sprints' => $nextLoad ? array_map(fn($id, $h) => ['id' => $id, 'name' => $targets[$id]['name'], 'hours' => round($h, 2)], array_keys($nextLoad), $nextLoad) : [],
            'preview' => self::preview($snap, $changes, $targets, $prios), 'assumptions' => self::assumptions($dev)];
    }

    public static function assumptions(?float $dev): array
    {
        return ['Lo pendiente de cada tarea = horas restantes anotadas o, si no hay, estimación − horas registradas.',
            'Capacidad = horas disponibles en el sprint de las personas responsables de tareas abiertas, proporcional a los días laborables que quedan.',
            'Las tareas sin estimación no suman horas: se cuentan aparte.', 'No se recalculan en cascada las fechas de las tareas dependientes.',
            $dev !== null ? 'La desviación habitual del proyecto (' . Metrics::pct($dev, true) . ') solo se usa como estimación separada, marcada como tal.' : 'El proyecto no tiene historial suficiente de desviaciones: no se usa ninguna corrección histórica.'];
    }

    /** Human-readable list of what applying would change (before → after). */
    public static function preview(array $snap, array $changes, array $targets, array $prios): array
    {
        $out = [];
        foreach ($changes as $c) {
            $type = (string)($c['type'] ?? '');
            if ($type === 'capacity') { $u = (int)$c['user_id']; $out[] = ['type' => $type, 'applies' => true, 'text' => 'Horas disponibles de ' . $snap['capacity'][$u]['name'] . ': ' . Metrics::h($snap['capacity'][$u]['hours']) . ' → ' . Metrics::h((float)$c['hours'])]; continue; }
            $t = $snap['tasks'][(int)($c['task_id'] ?? 0)] ?? null;
            if (!$t) continue;
            $out[] = match ($type) {
                'move' => ['type' => $type, 'applies' => true, 'text' => $t['key'] . ': de «' . $snap['sprint']['name'] . '» a ' . (!empty($c['to_sprint_id']) ? '«' . $targets[(int)$c['to_sprint_id']]['name'] . '»' : 'el backlog')],
                'estimate' => ['type' => $type, 'applies' => true, 'text' => $t['key'] . ': estimación ' . Metrics::h($t['estimate']) . ' → ' . Metrics::h((float)$c['hours'])],
                'due' => ['type' => $type, 'applies' => true, 'text' => $t['key'] . ': fecha límite ' . ($t['due'] ? fmt_date($t['due'], true) : 'sin fecha') . ' → ' . (!empty($c['date']) ? fmt_date((string)$c['date'], true) : 'sin fecha')],
                'assign' => ['type' => $type, 'applies' => true, 'text' => $t['key'] . ': responsable → ' . ($snap['capacity'][(int)$c['user_id']]['name'] ?? '')],
                'priority' => ['type' => $type, 'applies' => true, 'text' => $t['key'] . ': prioridad ' . ($t['priority'] ?: 'sin prioridad') . ' → ' . (!empty($c['priority_id']) ? $prios[(int)$c['priority_id']]['name'] : 'sin prioridad')],
                'unblock' => ['type' => $type, 'applies' => false, 'text' => $t['key'] . ': bloqueo resuelto (solo hipotético, no se aplica)'],
                default => ['type' => $type, 'applies' => false, 'text' => ''],
            };
        }
        return $out;
    }

    /**
     * Applies a simulated scenario for real. $fingerprint must match the data as it is now (otherwise
     * 409: simulate again). Runs in one transaction through Tasks::update / Capacity::set, so the
     * same rules as by hand apply (permissions, sprint capacity, notifications, history).
     */
    public static function apply(int $sprintId, array $changes, string $fingerprint, bool $confirmed): array
    {
        [$sp, $snap] = self::load($sprintId);
        Policy::authorizeProject(Projects::find((int)$sp['project_id']), 'sprint.manage');
        if (!$confirmed) throw HttpException::invalid(['confirm' => 'Confirma que quieres aplicar los cambios.']);
        if (!hash_equals(self::fingerprint($snap), $fingerprint)) throw HttpException::conflict('Los datos del sprint cambiaron desde la simulación. Vuelve a simular antes de aplicar.');
        [$targets, $prios] = self::references($sp);
        self::applyInMemory($snap, $changes, $targets, $prios); // validates every change
        $real = array_values(array_filter($changes, fn($c) => ($c['type'] ?? '') !== 'unblock'));
        if (!$real) throw HttpException::invalid(['changes' => 'El escenario no tiene cambios aplicables (resolver un bloqueo es solo hipotético).']);
        // capacity first, then tasks leaving the sprint, then the rest: the capacity guard sees the final picture as late as possible
        $order = ['capacity' => 0, 'move' => 1, 'estimate' => 2, 'assign' => 3, 'due' => 4, 'priority' => 5];
        usort($real, fn($a, $b) => $order[$a['type']] <=> $order[$b['type']]);
        $db = Database::get();
        return $db->transaction(function () use ($sp, $snap, $real, $db) {
            $log = [];
            foreach ($real as $c) {
                if ($c['type'] === 'capacity') {
                    $u = (int)$c['user_id'];
                    $before = $db->value('SELECT hours FROM sprint_capacity WHERE sprint_id = ? AND user_id = ?', [(int)$sp['id'], $u]);
                    Capacity::set((int)$sp['id'], $u, (float)$c['hours']);
                    $log[] = ['type' => 'capacity', 'user_id' => $u, 'before' => $before !== null ? (float)$before : null, 'after' => (float)$c['hours']];
                    continue;
                }
                $tid = (int)$c['task_id'];
                $t = $snap['tasks'][$tid];
                [$field, $value, $before] = match ($c['type']) {
                    'move' => ['sprint_id', !empty($c['to_sprint_id']) ? (int)$c['to_sprint_id'] : null, (int)$sp['id']],
                    'estimate' => ['estimate_hours', (float)$c['hours'], $t['estimate']],
                    'due' => ['due_date', !empty($c['date']) ? (string)$c['date'] : null, $t['due']],
                    'assign' => ['assignee_ids', [(int)$c['user_id']], $t['people']],
                    'priority' => ['priority_id', !empty($c['priority_id']) ? (int)$c['priority_id'] : null, $t['priority_id']],
                };
                if ($field === 'assignee_ids') Tasks::update($tid, ['assignee_id' => $value[0], 'assignee_ids' => []]);
                else Tasks::update($tid, [$field => $value]);
                $log[] = ['type' => $c['type'], 'task_id' => $tid, 'key' => $t['key'], 'field' => $field, 'before' => $before, 'after' => $value];
            }
            $id = $db->insert('scenario_applications', ['workspace_id' => Policy::wsId(), 'project_id' => (int)$sp['project_id'], 'sprint_id' => (int)$sp['id'], 'user_id' => Auth::id(),
                'changes' => json_encode($log, JSON_UNESCAPED_UNICODE), 'state' => 'applied', 'created_at' => now()]);
            Activity::log('scenario_applied', 'sprint', (int)$sp['id'], 'Aplicó un escenario en «' . $sp['name'] . '»: ' . count($log) . (count($log) === 1 ? ' cambio' : ' cambios'), (int)$sp['project_id'], null, ['scenario' => $id, 'changes' => $log]);
            return ['id' => $id, 'changes' => $log];
        });
    }

    /** Undo an applied scenario, only if every touched value is still what the scenario left. */
    public static function revert(int $id): array
    {
        $db = Database::get();
        $row = $db->one('SELECT * FROM scenario_applications WHERE id = ? AND workspace_id = ?', [$id, Policy::wsId()]);
        if (!$row || !in_array((int)$row['project_id'], Analysis::scope()['projects'], true)) throw HttpException::notFound('El escenario no existe o no tienes acceso.');
        if (!Analysis::canManage((int)$row['project_id'])) throw HttpException::forbidden('Solo quien gestiona el proyecto puede revertir un escenario.');
        if ($row['state'] !== 'applied') throw HttpException::conflict('Ese escenario ya se revirtió.');
        $log = json_decode((string)$row['changes'], true) ?: [];
        $changed = [];
        foreach ($log as $c) {
            if ($c['type'] === 'capacity') {
                $now = $db->value('SELECT hours FROM sprint_capacity WHERE sprint_id = ? AND user_id = ?', [(int)$row['sprint_id'], (int)$c['user_id']]);
                if ($now === null || abs((float)$now - (float)$c['after']) > 0.001) $changed[] = 'las horas disponibles de una persona';
                continue;
            }
            $t = $db->one('SELECT id, sprint_id, estimate_hours, due_date, assignee_id, priority_id, deleted_at FROM tasks WHERE id = ?', [(int)$c['task_id']]);
            $cur = !$t || $t['deleted_at'] ? '__gone' : match ($c['field']) {
                'sprint_id' => $t['sprint_id'] !== null ? (int)$t['sprint_id'] : null, 'estimate_hours' => $t['estimate_hours'] !== null ? (float)$t['estimate_hours'] : null,
                'due_date' => $t['due_date'], 'priority_id' => $t['priority_id'] !== null ? (int)$t['priority_id'] : null,
                'assignee_ids' => array_values(array_unique(array_filter(array_merge([(int)$t['assignee_id']], array_map('intval', $db->column('SELECT user_id FROM task_assignees WHERE task_id = ?', [(int)$t['id']])))))),
            };
            $after = $c['after'];
            if ($c['field'] === 'estimate_hours' && $after !== null) $after = (float)$after;
            if ($cur !== $after) $changed[] = $c['key'];
        }
        if ($changed) throw HttpException::conflict('No se puede revertir: después del escenario cambiaron ' . implode(', ', array_unique($changed)) . '. Corrígelo a mano.');
        try {
            return self::undo($row, $log, $db, $id);
        } catch (HttpException $e) {
            // e.g. the sprint no longer has hours for the task coming back: same rule as by hand
            if ($e->status === 409) throw HttpException::conflict('No se puede revertir: ' . $e->getMessage());
            throw $e;
        }
    }

    private static function undo(array $row, array $log, Database $db, int $id): array
    {
        return $db->transaction(function () use ($row, $log, $db, $id) {
            foreach (array_reverse($log) as $c) {
                if ($c['type'] === 'capacity') {
                    if ($c['before'] === null) {
                        // there was no sprint-specific value: back to the weekly hours
                        Capacity::set((int)$row['sprint_id'], (int)$c['user_id'], null);
                    } else Capacity::set((int)$row['sprint_id'], (int)$c['user_id'], (float)$c['before']);
                    continue;
                }
                if ($c['field'] === 'assignee_ids') { $b = array_values((array)$c['before']); Tasks::update((int)$c['task_id'], ['assignee_id' => $b[0] ?? null, 'assignee_ids' => array_slice($b, 1)]); }
                else Tasks::update((int)$c['task_id'], [$c['field'] => $c['before']]);
            }
            $db->update('scenario_applications', ['state' => 'reverted', 'reverted_at' => now(), 'reverted_by' => Auth::id()], 'id = ?', [$id]);
            Activity::log('scenario_reverted', 'sprint', (int)$row['sprint_id'], 'Revirtió el escenario aplicado el ' . fmt_datetime((string)$row['created_at']), (int)$row['project_id'], null, ['scenario' => $id]);
            return ['id' => $id, 'reverted' => count($log)];
        });
    }

    /** Last applications of the projects the viewer manages (audit trail shown in the tab). */
    public static function history(array $pids): array
    {
        $managed = array_values(array_filter($pids, fn($p) => Analysis::canManage($p)));
        if (!$managed) return [];
        return Database::get()->all('SELECT a.*, u.name AS user_name, s.name AS sprint_name, r.name AS reverted_name FROM scenario_applications a LEFT JOIN users u ON u.id = a.user_id
            LEFT JOIN sprints s ON s.id = a.sprint_id LEFT JOIN users r ON r.id = a.reverted_by WHERE a.workspace_id = ? AND a.project_id IN (' . Database::in($managed) . ') ORDER BY a.id DESC LIMIT 20',
            array_merge([Policy::wsId()], $managed));
    }
}
