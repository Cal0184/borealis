<?php
declare(strict_types=1);

namespace Borealis\Api;

use Borealis\Core\Auth;
use Borealis\Core\Request;
use Borealis\Core\Response;
use Borealis\Security\Policy;
use Borealis\Services\CapacityAlerts;
use Borealis\Services\QuickWork;
use Borealis\Services\Workspaces;

/** 1.14 · quick work and the weekly load shown while assigning. */
final class QuickApi
{
    public function create(Request $r): Response
    {
        $t = QuickWork::create($r->input());
        return Response::ok(['task' => ['id' => (int)$t['id'], 'key' => $t['task_key'], 'title' => $t['title'], 'url' => url('/t/' . $t['task_key'])]], "Trabajo rápido {$t['task_key']} creado");
    }

    /**
     * Load of this week per person: weekly hours (+ extra), hours logged and planned open work
     * (agreed hours of what is due this week or in a sprint ending this week, quick work included).
     * Visible as in Monitoreo › Carga: oneself, the people of the projects one manages, everyone for the administration.
     */
    public function workload(Request $r): Response
    {
        $week = CapacityAlerts::weekStart();
        // the same rule as Monitoreo › Carga: oneself, the people of the projects one manages, or everyone for the administration
        $ids = array_values(array_intersect(\Borealis\Services\Monitoring::visiblePeople(), array_map(fn($m) => (int)$m['id'], QuickWork::people())));
        if (!in_array((int)Auth::id(), $ids, true)) $ids[] = (int)Auth::id();
        $out = [];
        foreach (array_slice($ids, 0, 200) as $uid) {
            $m = CapacityAlerts::measure($uid, $week);
            $cap = $m['capacity_minutes'] !== null ? round($m['capacity_minutes'] / 60, 1) : null;
            $planned = round((float)$m['pending_hours'], 1);
            $logged = round($m['logged_minutes'] / 60, 1);
            $free = $cap !== null ? round($cap - $logged - $planned, 1) : null;
            $fmt = fn($x) => rtrim(rtrim(number_format((float)$x, 1, ',', ''), '0'), ',');
            $out[$uid] = ['capacity' => $cap, 'logged' => $logged, 'planned' => $planned, 'tasks' => count($m['tasks']), 'free' => $free,
                'state' => $cap === null ? 'unknown' : ($free < 0 ? 'over' : ($free < $cap * 0.15 ? 'full' : 'ok')),
                'text' => $cap === null ? count($m['tasks']) . ' tareas esta semana' : $fmt($logged + $planned) . ' de ' . $fmt($cap) . ' h esta semana' . ($free < 0 ? ' · sobrecarga' : '')];
        }
        return Response::ok(['week' => $week, 'people' => $out], '');
    }
}
