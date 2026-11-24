<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Security\Policy;

/**
 * Estimates of a task (1.14): the administrator's, the worker's and the agreed one, plus the real
 * hours (time entries) and the difference.
 *
 * tasks.estimate_hours keeps being THE planned hours that capacity, sprints, monitoring, analysis
 * and the Gantt use; in 1.14 it is called «acordado». So nothing downstream needed a second source.
 *  - Whoever manages the project (or the workspace) writes the «estimación de administración»;
 *    that value becomes the agreed one at once.
 *  - The responsible person writes «mi estimación» with a reason. With no agreed value yet, it becomes
 *    the plan. When it differs from the agreed value it waits for review (estimate_review = pending)
 *    and the managers are told; monitoring raises «ampliación de estimación» for a significant rise.
 *  - The manager agrees on a value (usually the worker's): agreed and reviewed.
 * Every step is kept in task_estimates (who, kind, before, after, reason) and in the audit log.
 */
final class TaskEstimates
{
    public const KINDS = ['admin' => 'Administración', 'worker' => 'Trabajador', 'agreed' => 'Acordado'];

    /** Manages the project of the task: may set the administrator's estimate and agree. */
    public static function canManage(array $t): bool
    {
        return Policy::canProject($t['project'], 'project.manage') || Policy::can('workspace.manage');
    }

    public static function isResponsible(array $t): bool
    {
        return in_array((int)Auth::id(), array_map('intval', Tasks::responsibleIds($t)), true);
    }

    private static function hours(mixed $raw, string $field): float
    {
        if (!is_numeric($raw) || (float)$raw < 0 || (float)$raw > 9999) throw HttpException::invalid([$field => 'Indica las horas entre 0 y 9999.']);
        return round((float)$raw * 2) / 2 === (float)$raw ? (float)$raw : round((float)$raw, 2);
    }

    /** The responsible person's estimate (with a reason when it changes an agreed value). */
    public static function propose(int $taskId, array $input): array
    {
        $t = Tasks::find($taskId);
        Policy::authorizeProject($t['project'], 'project.view');
        if (!self::isResponsible($t) && !self::canManage($t)) throw HttpException::forbidden('Solo quien es responsable de la tarea puede indicar su estimación.');
        $h = self::hours($input['hours'] ?? null, 'hours');
        $reason = trim((string)($input['reason'] ?? ''));
        $agreed = $t['estimate_hours'] !== null ? (float)$t['estimate_hours'] : null;
        if ($agreed !== null && abs($h - $agreed) > 0.001 && mb_strlen($reason) < 3) throw HttpException::invalid(['reason' => 'Cuenta brevemente por qué cambia (una dificultad, algo que no estaba previsto…).']);
        $old = $t['worker_estimate_hours'] !== null ? (float)$t['worker_estimate_hours'] : null;
        $db = Database::get();
        $data = ['worker_estimate_hours' => $h, 'updated_at' => now()];
        if ($agreed === null) { $data['estimate_hours'] = $h; $data['estimate_review'] = 'none'; }
        elseif (abs($h - $agreed) > 0.001) $data['estimate_review'] = 'pending';
        else $data['estimate_review'] = 'agreed';
        $db->transaction(function (Database $db) use ($t, $data, $h, $old, $reason, $agreed) {
            Capacity::guard($t, $t['sprint_id'] ? (int)$t['sprint_id'] : null, isset($data['estimate_hours']) ? $h : $agreed, Tasks::responsibleIdsDirect($t));
            $db->update('tasks', $data, 'id = ?', [$t['id']]);
            self::record((int)$t['id'], 'worker', $old, $h, $reason);
            if (isset($data['estimate_hours'])) self::record((int)$t['id'], 'agreed', null, $h, 'Primera estimación de la tarea');
        });
        $pid = (int)$t['project_id'];
        $logTask = $t['parent_id'] ? (int)$t['parent_id'] : (int)$t['id'];
        Activity::log('estimate_worker', 'task', (int)$t['id'], "Estimó {$t['task_key']} en " . self::fmt($h) . ($old !== null ? ' (antes ' . self::fmt($old) . ')' : ''), $pid, $logTask, [], null,
            ['before' => ['estimación del trabajador' => $old, 'acordado' => $agreed], 'after' => ['estimación del trabajador' => $h, 'acordado' => $data['estimate_hours'] ?? $agreed], 'reason' => $reason]);
        if (($data['estimate_review'] ?? '') === 'pending') {
            $msg = "{$t['task_key']}: " . (Auth::user()['name'] ?? 'Su responsable') . ' estima ' . self::fmt($h) . ' (acordado ' . self::fmt((float)$agreed) . ')' . ($reason ? " · $reason" : '');
            foreach (self::managers($t) as $uid) Notifications::send($uid, 'project', mb_substr($msg, 0, 250), url('/t/' . $t['task_key']), $pid, (int)$t['id'], 'est:' . $t['id'] . ':' . $h);
        }
        GoogleExport::touchTask((int)$t['id']);
        return Tasks::find($taskId);
    }

    /** The manager's value: administrator's estimate (= agreed), or agreeing on the worker's proposal. */
    public static function agree(int $taskId, array $input): array
    {
        $t = Tasks::find($taskId);
        if (!self::canManage($t)) throw HttpException::forbidden('Solo quien gestiona el proyecto acuerda las estimaciones.');
        $h = self::hours($input['hours'] ?? $t['worker_estimate_hours'] ?? null, 'hours');
        $reason = trim((string)($input['reason'] ?? ''));
        $old = $t['estimate_hours'] !== null ? (float)$t['estimate_hours'] : null;
        $asAdmin = !empty($input['as_admin']);
        $data = ['estimate_hours' => $h, 'estimate_review' => $t['worker_estimate_hours'] !== null ? 'agreed' : 'none', 'updated_at' => now()];
        if ($asAdmin) $data['admin_estimate_hours'] = $h;
        Database::get()->transaction(function (Database $db) use ($t, $data, $h, $old, $reason, $asAdmin) {
            Capacity::guard($t, $t['sprint_id'] ? (int)$t['sprint_id'] : null, $h, Tasks::responsibleIdsDirect($t));
            $db->update('tasks', $data, 'id = ?', [$t['id']]);
            if ($asAdmin) self::record((int)$t['id'], 'admin', $t['admin_estimate_hours'] !== null ? (float)$t['admin_estimate_hours'] : null, $h, $reason);
            self::record((int)$t['id'], 'agreed', $old, $h, $reason);
        });
        $pid = (int)$t['project_id'];
        Activity::log('estimate_agreed', 'task', (int)$t['id'], "Acordó " . self::fmt($h) . " para {$t['task_key']}" . ($old !== null ? ' (antes ' . self::fmt($old) . ')' : ''), $pid, $t['parent_id'] ? (int)$t['parent_id'] : (int)$t['id'], [], null,
            ['before' => ['acordado' => $old], 'after' => ['acordado' => $h], 'reason' => $reason]);
        foreach (Tasks::responsibleIds($t) as $uid) {
            Notifications::send((int)$uid, 'project', "{$t['task_key']}: se acordaron " . self::fmt($h) . ' para la tarea' . ($reason ? " · $reason" : ''), url('/t/' . $t['task_key']), $pid, (int)$t['id'], 'est-ok:' . $t['id'] . ':' . $h);
        }
        GoogleExport::touchTask((int)$t['id']);
        return Tasks::find($taskId);
    }

    /** Called by Tasks::update when estimate_hours is edited in the task itself (the 1.13 field). */
    public static function editedInline(array $t, ?float $new): void
    {
        $old = $t['estimate_hours'] !== null ? (float)$t['estimate_hours'] : null;
        if (self::canManage($t)) {
            Database::get()->update('tasks', ['admin_estimate_hours' => $new, 'estimate_review' => $t['worker_estimate_hours'] !== null && $new !== null ? 'agreed' : 'none'], 'id = ?', [$t['id']]);
            self::record((int)$t['id'], 'admin', $t['admin_estimate_hours'] !== null ? (float)$t['admin_estimate_hours'] : null, $new, null);
        } else {
            Database::get()->update('tasks', ['worker_estimate_hours' => $new], 'id = ?', [$t['id']]);
            self::record((int)$t['id'], 'worker', $t['worker_estimate_hours'] !== null ? (float)$t['worker_estimate_hours'] : null, $new, null);
        }
        self::record((int)$t['id'], 'agreed', $old, $new, null);
    }

    public static function record(int $taskId, string $kind, ?float $old, ?float $new, ?string $reason): void
    {
        Database::get()->insert('task_estimates', ['task_id' => $taskId, 'user_id' => Auth::id() ?: null, 'kind' => $kind, 'old_hours' => $old, 'new_hours' => $new,
            'reason' => $reason !== null && $reason !== '' ? mb_substr($reason, 0, 500) : null, 'created_at' => now()]);
    }

    public static function history(int $taskId): array
    {
        return Database::get()->all('SELECT e.*, u.name AS user_name FROM task_estimates e LEFT JOIN users u ON u.id = e.user_id WHERE e.task_id = ? ORDER BY e.id DESC LIMIT 30', [$taskId]);
    }

    /** The five numbers of the task: admin, worker, agreed, real (hours) and real − agreed. */
    public static function summary(array $t, int $loggedMinutes): array
    {
        $agreed = $t['estimate_hours'] !== null ? (float)$t['estimate_hours'] : null;
        $real = round($loggedMinutes / 60, 2);
        return [
            'admin' => isset($t['admin_estimate_hours']) && $t['admin_estimate_hours'] !== null ? (float)$t['admin_estimate_hours'] : null,
            'worker' => isset($t['worker_estimate_hours']) && $t['worker_estimate_hours'] !== null ? (float)$t['worker_estimate_hours'] : null,
            'agreed' => $agreed, 'real' => $real, 'diff' => $agreed !== null ? round($real - $agreed, 2) : null,
            'review' => $t['estimate_review'] ?? 'none',
        ];
    }

    /** People who manage the project of the task (to review estimates). */
    public static function managers(array $t): array
    {
        $ids = Database::get()->column("SELECT pm.user_id FROM project_members pm JOIN roles r ON r.id = pm.role_id WHERE pm.project_id = ? AND r.role_key = 'manager'", [$t['project_id']]);
        if ($t['project']['lead_user_id'] ?? null) $ids[] = $t['project']['lead_user_id'];
        return array_values(array_unique(array_map('intval', $ids)));
    }

    public static function fmt(float $h): string
    {
        return rtrim(rtrim(number_format($h, 2, ',', ''), '0'), ',') . ' h';
    }
}
