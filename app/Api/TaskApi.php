<?php
declare(strict_types=1);

namespace Borealis\Api;

use Borealis\Core\Database;
use Borealis\Core\Request;
use Borealis\Core\Response;
use Borealis\Services\Comments;
use Borealis\Services\Projects;
use Borealis\Services\Tasks;
use Borealis\Services\TimeEntries;
use Borealis\Core\HttpException;

final class TaskApi
{
    public function index(Request $r): Response
    {
        $p = Projects::find($r->intParam('id'));
        $rows = Tasks::query(['project_id' => $p['id']] + array_intersect_key($r->query, array_flip(['assignee', 'status_id', 'priority_id', 'q', 'sort', 'sprint_id', 'label_id', 'blocked'])));
        return Response::json(['ok' => true, 'tasks' => array_map([$this, 'dto'], $rows)]);
    }

    public function create(Request $r): Response
    {
        // 1.14: «Trabajos rápidos» from the «Nueva tarea» dialog (it makes sure the people can work there)
        $quick = $r->param('id') === 'quick' || ((int)$r->param('id') > 0 && (\Borealis\Services\Projects::find($r->intParam('id'))['kind'] ?? 'standard') === 'quick');
        $t = $quick ? \Borealis\Services\QuickWork::create($r->input()) : Tasks::create($r->intParam('id'), $r->input());
        return Response::ok(['task' => $this->dto($t)] + $this->celebration($t, null), 'Tarea creada: ' . $t['task_key']);
    }

    public function show(Request $r): Response
    {
        return Response::json(['ok' => true, 'task' => $this->dto(Tasks::find($r->intParam('id')))]);
    }

    public function update(Request $r): Response
    {
        $before = Tasks::find($r->intParam('id'));
        $t = Tasks::update($r->intParam('id'), $r->input());
        return Response::ok(['task' => $this->dto($t)] + $this->celebration($t, $before));
    }

    public function removePerson(Request $r): Response
    {
        $t = Tasks::removePerson($r->intParam('id'), $r->intParam('uid'));
        return Response::ok(['task' => $this->dto($t)], 'Responsables de ' . $t['task_key'] . ': ' . (Tasks::responsibleLabel($t) ?: 'nadie'));
    }

    public function movePerson(Request $r): Response
    {
        $from = $r->input('from_task_id');
        $t = Tasks::movePerson($r->intParam('id'), (int)$r->input('user_id'), $from ? (int)$from : null);
        return Response::ok(['task' => $this->dto($t)], 'Responsables de ' . $t['task_key'] . ': ' . (Tasks::responsibleLabel($t) ?: 'nadie'));
    }

    public function move(Request $r): Response
    {
        $before = Tasks::find($r->intParam('id'));
        $after = $r->input('after_id');
        $t = Tasks::move($r->intParam('id'), (int)$r->input('status_id'), $after ? (int)$after : null);
        return Response::ok(['task' => $this->dto($t)] + $this->celebration($t, $before), 'Tarea movida a ' . $t['status_name']);
    }

    public function backlogMove(Request $r): Response
    {
        $after = $r->input('after_id');
        Tasks::reorderBacklog($r->intParam('id'), $after ? (int)$after : null);
        if ($r->has('sprint_id')) Tasks::update($r->intParam('id'), ['sprint_id' => $r->input('sprint_id') ?: null]);
        return Response::ok([], 'Orden guardado');
    }

    public function done(Request $r): Response
    {
        $before = Tasks::find($r->intParam('id'));
        $t = Tasks::setDone($r->intParam('id'), in_array($r->input('done'), [true, 1, '1', 'true'], true));
        $msg = $t['status_category'] === 'done' ? 'Tarea finalizada' : 'Tarea reabierta';
        $extra = [];
        if ($t['status_category'] === 'done' && $before['status_category'] !== 'done') {
            // 1.12: an important deviation is pointed out once, and explaining it stays optional
            $dev = \Borealis\Services\Monitoring::deviation($t['estimate_hours'] !== null ? (float)$t['estimate_hours'] : null, (int)$t['logged_minutes']);
            $cfg = \Borealis\Services\Monitoring::settings();
            if ($dev && $dev['estimate'] >= $cfg['deviation_min_hours'] && abs($dev['pct']) >= $cfg['deviation_pct']) {
                $extra['deviation'] = $dev;
                $msg .= '. Se registraron ' . \Borealis\Services\Monitoring::fmtHours($dev['logged']) . ' de ' . \Borealis\Services\Monitoring::fmtHours($dev['estimate']) . ' estimadas (' . ($dev['pct'] > 0 ? '+' : '') . $dev['pct'] . ' %): si quieres, anota el motivo en «Seguimiento» de la tarea';
            }
        }
        return Response::ok(['task' => $this->dto($t)] + $this->celebration($t, $before) + $extra, $msg);
    }

    /** 1.12: explanation of a delay or a deviation (reason, comment, corrective action). */
    /** 1.14: the responsible person's estimate, with its reason. */
    public function proposeEstimate(Request $r): Response
    {
        $t = \Borealis\Services\TaskEstimates::propose($r->intParam('id'), $r->input());
        return Response::ok(['task' => ['id' => (int)$t['id'], 'estimate_hours' => $t['estimate_hours'], 'worker_estimate_hours' => $t['worker_estimate_hours'], 'estimate_review' => $t['estimate_review']]],
            $t['estimate_review'] === 'pending' ? 'Estimación enviada para revisión' : 'Estimación guardada');
    }

    /** 1.14: the manager agrees on the hours of a task. */
    public function agreeEstimate(Request $r): Response
    {
        $t = \Borealis\Services\TaskEstimates::agree($r->intParam('id'), $r->input());
        return Response::ok(['task' => ['id' => (int)$t['id'], 'estimate_hours' => $t['estimate_hours'], 'estimate_review' => $t['estimate_review']]], 'Horas acordadas');
    }

    public function explain(Request $r): Response
    {
        $id = \Borealis\Services\Monitoring::explain($r->intParam('id'), $r->input());
        return Response::ok(['id' => $id], 'Motivo registrado');
    }

    /** 1.12: correct a time entry (the change stays in the history). */
    public function updateTime(Request $r): Response
    {
        TimeEntries::update($r->intParam('id'), $r->input());
        return Response::ok([], 'Registro de horas corregido');
    }

    public function duplicate(Request $r): Response
    {
        $t = Tasks::duplicate($r->intParam('id'));
        return Response::ok(['task' => $this->dto($t)], 'Tarea duplicada: ' . $t['task_key']);
    }

    public function delete(Request $r): Response
    {
        Tasks::delete($r->intParam('id'));
        return Response::ok([], 'Tarea eliminada');
    }

    public function bulk(Request $r): Response
    {
        $n = Tasks::bulk((array)$r->input('ids', []), (string)$r->input('action'), $r->input('value'));
        return Response::ok(['count' => $n], $n === 1 ? '1 tarea actualizada' : "$n tareas actualizadas");
    }

    public function logTime(Request $r): Response
    {
        $id = TimeEntries::log($r->intParam('id'), $r->input());
        return Response::ok(['id' => $id], 'Horas registradas');
    }

    public function deleteTime(Request $r): Response
    {
        TimeEntries::delete($r->intParam('id'));
        return Response::ok([], 'Registro de horas eliminado');
    }

    /** Optional wrap-up after finishing a task: a closing comment and/or the hours it took. */
    public function wrapUp(Request $r): Response
    {
        $id = $r->intParam('id');
        $comment = trim((string)$r->input('comment', ''));
        $hours = trim((string)$r->input('hours', ''));
        if ($comment === '' && $hours === '') throw HttpException::invalid(['comment' => 'Escribe un comentario o las horas, o pulsa «Omitir».']);
        $db = Database::get();
        $db->transaction(function () use ($id, $comment, $hours) {
            if ($hours !== '') TimeEntries::log($id, ['hours' => $hours]);
            if ($comment !== '') Comments::create($id, $comment);
        });
        return Response::ok([], $comment !== '' && $hours !== '' ? 'Comentario y horas guardados' : ($comment !== '' ? 'Comentario publicado' : 'Horas registradas'));
    }

    public function comment(Request $r): Response
    {
        $decision = in_array($r->input('decision'), [true, 1, '1', 'on', 'true'], true);
        $id = Comments::create($r->intParam('id'), (string)$r->input('body'), $decision);
        return Response::ok(['id' => $id], $decision ? 'Decisión registrada' : 'Comentario publicado');
    }

    /** 1.15 · follow / stop following a task. */
    public function watch(Request $r): Response
    {
        $on = \Borealis\Services\Watchers::toggle($r->intParam('id'));
        return Response::ok(['watching' => $on], $on ? 'Ahora sigues esta tarea' : 'Dejaste de seguir esta tarea');
    }

    public function updateComment(Request $r): Response
    {
        Comments::update($r->intParam('id'), (string)$r->input('body'));
        return Response::ok();
    }

    public function deleteComment(Request $r): Response
    {
        Comments::delete($r->intParam('id'));
        return Response::ok([], 'Comentario eliminado');
    }

    public function addLink(Request $r): Response
    {
        Tasks::addLink($r->intParam('id'), (string)$r->input('target'), (string)$r->input('link_type', 'relates'));
        return Response::ok([], 'Relación añadida');
    }

    public function removeLink(Request $r): Response
    {
        Tasks::removeLink($r->intParam('id'), $r->intParam('lid'));
        return Response::ok([], 'Relación eliminada');
    }

    /** Tells the UI when to play the completion animation and the project summary. */
    private function celebration(array $t, ?array $before): array
    {
        $out = [];
        if ($t['status_category'] === 'done' && ($before === null || $before['status_category'] !== 'done')) {
            $out['celebrate'] = true;
            $out['completed'] = ['id' => (int)$t['id'], 'key' => $t['task_key'], 'title' => $t['title'], 'logged' => (int)($t['logged_minutes'] ?? 0)];
            $open = (int)Database::get()->value("SELECT COUNT(*) FROM tasks k JOIN statuses s ON s.id = k.status_id WHERE k.project_id = ? AND k.deleted_at IS NULL AND k.parent_id IS NULL AND s.category <> 'done'", [$t['project_id']]);
            if ($open === 0 && !$t['parent_id']) {
                $total = (int)Database::get()->value('SELECT COUNT(*) FROM tasks WHERE project_id = ? AND deleted_at IS NULL AND parent_id IS NULL', [$t['project_id']]);
                $out['project_complete'] = ['id' => (int)$t['project_id'], 'name' => $t['project_name'], 'total' => $total, 'state' => $t['project']['state'], 'url' => url('/projects/' . $t['project_id'])];
            }
        }
        return $out;
    }

    private function dto(array $t): array
    {
        return [
            'id' => (int)$t['id'], 'key' => $t['task_key'], 'title' => $t['title'], 'description' => $t['description'], 'project_id' => (int)$t['project_id'],
            'status_id' => (int)$t['status_id'], 'status' => $t['status_name'], 'category' => $t['status_category'], 'priority_id' => $t['priority_id'] ? (int)$t['priority_id'] : null,
            'assignee_id' => $t['assignee_id'] ? (int)$t['assignee_id'] : null, 'assignee' => $t['assignee_name'],
            'assignee_ids' => array_values(array_filter(array_merge([$t['assignee_id'] ? (int)$t['assignee_id'] : 0], array_map('intval', array_column($t['co_assignees'] ?? [], 'id'))))),
            'team_id' => $t['team_id'] ? (int)$t['team_id'] : null, 'team' => $t['team_name'] ?? null, 'responsible' => Tasks::responsibleLabel($t), 'due_date' => $t['due_date'], 'due_time' => isset($t['due_time']) ? substr((string)$t['due_time'], 0, 5) : null, 'start_date' => $t['start_date'], 'drive_url' => $t['drive_url'] ?? null, 'github_url' => $t['github_url'] ?? null, 'remaining_hours' => isset($t['remaining_hours']) && $t['remaining_hours'] !== null ? (float)$t['remaining_hours'] : null, 'logged_minutes' => (int)($t['logged_minutes'] ?? 0),
            'started_at' => $t['started_at'] ?? null, 'blocked_at' => $t['blocked_at'] ?? null,
            // 1.11: punctuality (no new status): days late while open; for a finished task, the due date it had then
            'completed_at' => $t['completed_at'] ?? null, 'completed_due_date' => $t['completed_due_date'] ?? null,
            'punctuality' => ($l = lateness($t)) ? ['kind' => $l['kind'], 'days' => $l['days']] : null,
            'parent_id' => $t['parent_id'] ? (int)$t['parent_id'] : null, 'sprint_id' => $t['sprint_id'] ? (int)$t['sprint_id'] : null, 'epic_id' => $t['epic_id'] ? (int)$t['epic_id'] : null,
            'estimate' => $t['estimate'] !== null ? (float)$t['estimate'] : null, 'estimate_hours' => $t['estimate_hours'] !== null ? (float)$t['estimate_hours'] : null, 'logged_minutes' => (int)($t['logged_minutes'] ?? 0), 'is_blocked' => (bool)$t['is_blocked'], 'blocked_reason' => $t['blocked_reason'],
            'labels' => array_map(fn($l) => ['id' => (int)$l['id'], 'name' => $l['name']], $t['labels'] ?? []), 'url' => url('/t/' . $t['task_key']),
        ];
    }
}
