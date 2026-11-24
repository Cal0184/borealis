<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Security\Policy;

/**
 * Quick work (1.14 · «Trabajos rápidos»): momentary jobs, unforeseen requests, administrative
 * activities, urgencies and the work of the week that belongs to no project.
 *
 * They are ordinary tasks of one special project per workspace (projects.kind = 'quick'), created on
 * first use. So they log hours, count in the real workload and capacity of each person, appear in
 * Mi trabajo, the calendar, Monitoreo, Análisis and the audit log, without anybody configuring a
 * project: the quick project never shows among the projects and needs no settings.
 */
final class QuickWork
{
    public const NAME = 'Trabajos rápidos';

    public static function project(): array
    {
        $db = Database::get();
        $id = $db->value("SELECT id FROM projects WHERE workspace_id = ? AND kind = 'quick' ORDER BY id LIMIT 1", [Policy::wsId()]);
        if (!$id) {
            $id = $db->transaction(function (Database $db) {
                $again = $db->value("SELECT id FROM projects WHERE workspace_id = ? AND kind = 'quick' ORDER BY id LIMIT 1 FOR UPDATE", [Policy::wsId()]);
                if ($again) return (int)$again;
                $key = Projects::suggestKey('Trabajo Rapido');
                $pid = $db->insert('projects', [
                    'workspace_id' => Policy::wsId(), 'team_id' => null, 'project_key' => $key, 'name' => self::NAME,
                    'description' => 'Trabajos momentáneos, solicitudes imprevistas, tareas administrativas y urgencias que no pertenecen a un proyecto.',
                    'color' => '#64748B', 'visibility' => 'private', 'lead_user_id' => null, 'state' => 'active', 'kind' => 'quick',
                    'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach (Projects::DEFAULT_STATUSES as $i => [$n, $cat, $color]) $db->insert('statuses', ['project_id' => $pid, 'name' => $n, 'category' => $cat, 'color' => $color, 'position' => $i]);
                Activity::log('created', 'project', $pid, 'Se preparó el espacio de trabajos rápidos', $pid, null, [], null, ['source' => 'system']);
                return $pid;
            });
        }
        // not Projects::find(): the person may not be in it yet (ensureMember adds them before creating anything)
        return Database::get()->one('SELECT p.*, NULL AS team_name FROM projects p WHERE p.id = ?', [(int)$id]);
    }

    /** Quick work can be given to other people by whoever can create projects; anyone can create their own. */
    public static function canAssignOthers(): bool
    {
        return Policy::can('projects.create') || Policy::can('members.manage');
    }

    /**
     * People the current person may give quick work to (and pick as responsible of a new project):
     * the workspace administration sees everyone; anybody else the people they already share a
     * project with, plus themselves. So nobody learns the names of people outside their projects
     * (the 1.12 rule of Monitoreo) just by opening a form.
     */
    public static function people(): array
    {
        $me = Auth::user();
        $self = $me ? [['id' => (int)$me['id'], 'name' => $me['name']]] : [];
        if (!self::canAssignOthers()) return $self;
        $all = Workspaces::members(false);
        if (Policy::can('members.manage') || Policy::can('projects.manage_all')) return $all;
        $ids = [(int)Auth::id() => true];
        foreach (Projects::list() as $p) {
            if (($p['kind'] ?? 'standard') !== 'standard') continue;
            foreach (Projects::members($p) as $m) $ids[(int)$m['id']] = true;
        }
        return array_values(array_filter($all, fn($m) => isset($ids[(int)$m['id']])));
    }

    public static function canGiveTo(int $userId): bool
    {
        return in_array($userId, array_map(fn($m) => (int)$m['id'], self::people()), true);
    }

    /** Makes sure the person can work in the quick project (contributor), without notifying anything. */
    private static function ensureMember(int $projectId, int $userId, bool $manager = false): void
    {
        if (!Workspaces::isActiveMember($userId)) throw HttpException::invalid(['assignee_id' => 'La persona no pertenece al espacio.']);
        $role = Policy::projectRoleId($manager ? 'manager' : 'contributor');
        Database::get()->run('INSERT INTO project_members (project_id, user_id, role_id, added_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE role_id = IF(? = 1, VALUES(role_id), role_id)',
            [$projectId, $userId, $role, now(), $manager ? 1 : 0]);
        Policy::forgetProject($projectId);
    }

    /** Creates one quick job. Input: title, assignee_id, estimate_hours, due_date, work_type, priority_id, description. */
    public static function create(array $input): array
    {
        $p = self::project();
        $me = (int)Auth::id();
        $who = !empty($input['assignee_id']) ? (int)$input['assignee_id'] : $me;
        if ($who !== $me && !self::canAssignOthers()) throw HttpException::forbidden('Solo puedes crear trabajos rápidos para ti.');
        if ($who !== $me && !self::canGiveTo($who)) throw HttpException::invalid(['assignee_id' => 'Solo puedes asignar trabajos rápidos a personas con las que compartes algún proyecto.']);
        self::ensureMember((int)$p['id'], $me); // never «manager» here: that would show the load of everybody in the quick space
        if ($who !== $me) self::ensureMember((int)$p['id'], $who);
        $type = (string)($input['work_type'] ?? 'other');
        if (!isset(Tasks::WORK_TYPES[$type])) $type = 'other';
        $data = [
            'title' => $input['title'] ?? null, 'assignee_id' => $who, 'estimate_hours' => $input['estimate_hours'] ?? null, 'due_date' => ($input['due_date'] ?? '') ?: null,
            'priority_id' => $input['priority_id'] ?? null, 'description' => $input['description'] ?? null, 'work_type' => $type,
            'start_date' => ($input['start_date'] ?? '') ?: null, 'due_time' => ($input['due_time'] ?? '') ?: null, 'is_milestone' => !empty($input['is_milestone']),
            'unplanned' => !empty($input['unplanned']) || in_array($type, ['urgent', 'request'], true),
        ];
        return Tasks::create((int)$p['id'], $data);
    }

    /** The quick work of a week (Monday), for the page: open first, by due date. */
    public static function week(string $monday, ?int $userId = null): array
    {
        $p = self::project();
        $sun = date('Y-m-d', strtotime($monday . ' +6 days'));
        $rows = Tasks::query(['project_id' => (int)$p['id'], 'sort' => 'due', 'limit' => 2000]);
        // everyone in the quick space could otherwise read everybody's quick work: only one's own, what one
        // created, and that of the people one shares a project with (everyone, for the administration)
        $me = (int)Auth::id();
        $allowed = array_flip(array_map(fn($m) => (int)$m['id'], self::people()));
        $out = [];
        foreach ($rows as $t) {
            if ($userId && (int)$t['assignee_id'] !== $userId) continue;
            if ((int)$t['assignee_id'] !== $me && (int)($t['reporter_id'] ?? 0) !== $me && !isset($allowed[(int)$t['assignee_id']])) continue;
            $d = $t['due_date'] ?: substr((string)$t['created_at'], 0, 10);
            $open = $t['status_category'] !== 'done';
            // this week's, plus anything still open from before
            if (($d >= $monday && $d <= $sun) || ($open && $d < $monday)) $out[] = $t;
        }
        usort($out, fn($a, $b) => [$a['status_category'] === 'done', $a['due_date'] ?? '9999'] <=> [$b['status_category'] === 'done', $b['due_date'] ?? '9999']);
        return $out;
    }
}
