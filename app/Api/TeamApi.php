<?php
declare(strict_types=1);

namespace Borealis\Api;

use Borealis\Core\Request;
use Borealis\Core\Response;
use Borealis\Services\CapacityAlerts;
use Borealis\Services\Projects;
use Borealis\Services\Teams;

final class TeamApi
{
    /** 1.5: a decision taken from a «Capacidad agotada» alert. */
    public function resolveCapacityAlert(Request $r): Response
    {
        $a = CapacityAlerts::resolve($r->intParam('id'), $r->input());
        return Response::ok(['alert' => ['id' => (int)$a['id'], 'status' => $a['status']]],
            $a['still_open'] ? 'Cambio guardado. La alerta sigue abierta: ' . ($a['left'] === 1 ? 'queda 1 tarea pendiente' : "quedan {$a['left']} tareas pendientes") . '.' : 'Alerta resuelta');
    }

    public function create(Request $r): Response
    {
        $id = Teams::create($r->input());
        return Response::ok(['id' => $id, 'url' => url('/teams/' . $id)], 'Equipo creado');
    }

    public function update(Request $r): Response
    {
        Teams::update($r->intParam('id'), $r->input());
        return Response::ok();
    }

    public function archive(Request $r): Response
    {
        Teams::setArchived($r->intParam('id'), true);
        return Response::ok([], 'Equipo archivado');
    }

    public function restore(Request $r): Response
    {
        Teams::setArchived($r->intParam('id'), false);
        return Response::ok([], 'Equipo restaurado');
    }

    public function delete(Request $r): Response
    {
        Teams::delete($r->intParam('id'));
        return Response::ok(['redirect' => url('/teams')], 'Equipo eliminado');
    }

    public function addMember(Request $r): Response
    {
        Teams::addMember($r->intParam('id'), (int)$r->input('user_id'), (string)$r->input('team_role', 'member'), $r->input('responsibility'));
        return Response::ok([], 'Integrante añadido');
    }

    public function updateMember(Request $r): Response
    {
        Teams::updateMember($r->intParam('id'), $r->intParam('uid'), $r->input());
        return Response::ok();
    }

    public function removeMember(Request $r): Response
    {
        Teams::removeMember($r->intParam('id'), $r->intParam('uid'));
        return Response::ok([], 'Integrante retirado');
    }

    /** Link an existing project to this team. */
    public function assignProject(Request $r): Response
    {
        $team = Teams::find($r->intParam('id'));
        Projects::update((int)$r->input('project_id'), ['team_id' => $team['id']]);
        return Response::ok([], 'Proyecto asignado al equipo');
    }
}
