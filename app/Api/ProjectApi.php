<?php
declare(strict_types=1);

namespace Borealis\Api;

use Borealis\Core\Request;
use Borealis\Core\Response;
use Borealis\Services\Modules;
use Borealis\Services\Projects;
use Borealis\Services\Tasks;

final class ProjectApi
{
    public function create(Request $r): Response
    {
        $id = Projects::create($r->input());
        $p = Projects::find($id);
        return Response::ok(['id' => $id, 'project' => $this->dto($p), 'url' => url('/projects/' . $id)], 'Proyecto creado');
    }

    public function show(Request $r): Response
    {
        return Response::json(['ok' => true, 'project' => $this->dto(Projects::find($r->intParam('id')))]);
    }

    public function update(Request $r): Response
    {
        $p = Projects::update($r->intParam('id'), $r->input());
        return Response::ok(['project' => $this->dto($p)]);
    }

    public function archive(Request $r): Response
    {
        Projects::setArchived($r->intParam('id'), true);
        return Response::ok([], 'Proyecto archivado');
    }

    public function restore(Request $r): Response
    {
        Projects::setArchived($r->intParam('id'), false);
        return Response::ok([], 'Proyecto restaurado');
    }

    public function members(Request $r): Response
    {
        $p = Projects::find($r->intParam('id'));
        $out = array_map(fn($m) => ['id' => (int)$m['id'], 'name' => $m['name'], 'handle' => $m['handle']], Projects::members($p));
        return Response::json(['ok' => true, 'members' => $out]);
    }

    public function addMember(Request $r): Response
    {
        Projects::addMember($r->intParam('id'), (int)$r->input('user_id'), (int)$r->input('role_id'));
        return Response::ok([], 'Integrante añadido');
    }

    /** Imports a project exported from Borealis as a new project (nothing existing changes). */
    public function import(Request $r): Response
    {
        \Borealis\Security\Policy::authorize('projects.create');
        $f = $_FILES['file'] ?? null;
        $err = is_array($f) ? (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw \Borealis\Core\HttpException::invalid(['file' => 'El archivo supera el tamaño que permite el servidor. Exporta sin archivos adjuntos o pide subir el límite upload_max_filesize.']);
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) throw \Borealis\Core\HttpException::invalid(['file' => 'Selecciona el archivo .json exportado desde Borealis.']);
        if (!str_ends_with(strtolower((string)$f['name']), '.json')) throw \Borealis\Core\HttpException::invalid(['file' => 'Usa el archivo .json que genera «Exportar proyecto».']);
        $data = \Borealis\Services\ProjectTransfer::decode((string)file_get_contents((string)$f['tmp_name']));
        $res = \Borealis\Services\ProjectTransfer::import($data, trim((string)($_POST['name'] ?? '')) ?: null);
        $rep = $res['report'];
        $msg = "Proyecto importado con las siglas {$rep['key']}: {$rep['tasks']} tareas, {$rep['comments']} comentarios"
            . ($rep['hours'] ? ', ' . \Borealis\Services\TimeEntries::fmt((int)$rep['hours']) . ' registradas' : '') . ($rep['files'] ? ", {$rep['files']} archivos" : '');
        if ($rep['unknown_people']) $msg .= '. ' . count($rep['unknown_people']) . ' personas no existen en este espacio: sus tareas quedaron sin responsable.';
        if ($rep['files_rejected']) $msg .= '. ' . count($rep['files_rejected']) . ' archivos no se importaron porque su tipo o contenido no está permitido.';
        return Response::ok(['id' => $res['id'], 'report' => $rep, 'url' => url('/projects/' . $res['id'])], $msg);
    }

    public function addTeam(Request $r): Response
    {
        $pid = $r->intParam('id');
        Projects::addTeam($pid, (int)$r->input('team_id'), (int)$r->input('role_id'));
        return Response::ok([], 'Equipo añadido al proyecto');
    }

    public function removeTeam(Request $r): Response
    {
        Projects::removeTeam($r->intParam('id'), $r->intParam('tid'));
        return Response::ok([], 'Equipo retirado del proyecto');
    }

    public function removeMember(Request $r): Response
    {
        Projects::removeMember($r->intParam('id'), $r->intParam('uid'));
        return Response::ok([], 'Integrante retirado');
    }

    public function module(Request $r): Response
    {
        $key = (string)$r->input('module');
        $on = in_array($r->input('enabled'), [true, 1, '1', 'true', 'on'], true);
        $changes = Projects::setModule($r->intParam('id'), $key, $on);
        $names = array_map(fn($k) => Modules::OPTIONAL[$k][0], array_keys($changes));
        $msg = $changes ? (($on ? 'Activado: ' : 'Desactivado: ') . implode(', ', $names)) : 'Sin cambios';
        return Response::ok(['changes' => $changes], $msg);
    }

    public function saveStatus(Request $r): Response
    {
        $id = Projects::saveStatus($r->intParam('id'), isset($r->params['sid']) ? $r->intParam('sid') : null, $r->input());
        return Response::ok(['id' => $id]);
    }

    public function moveStatus(Request $r): Response
    {
        Projects::moveStatus($r->intParam('id'), $r->intParam('sid'), (int)$r->input('dir'));
        return Response::ok([], 'Columna movida');
    }

    public function deleteStatus(Request $r): Response
    {
        $target = $r->input('target_id');
        Projects::deleteStatus($r->intParam('id'), $r->intParam('sid'), $target ? (int)$target : null);
        return Response::ok([], 'Columna eliminada');
    }

    public function saveLabel(Request $r): Response
    {
        $id = Projects::saveLabel($r->intParam('id'), isset($r->params['lid']) ? $r->intParam('lid') : null, $r->input());
        if ($r->input('task_id')) {
            // Created from a task: attach the new label to it right away.
            $t = Tasks::find((int)$r->input('task_id'));
            if ((int)$t['project_id'] === $r->intParam('id')) Tasks::setLabels((int)$t['id'], array_merge(array_column($t['labels'], 'id'), [$id]));
        }
        return Response::ok(['id' => $id], 'Etiqueta guardada');
    }

    public function deleteLabel(Request $r): Response
    {
        Projects::deleteLabel($r->intParam('id'), $r->intParam('lid'));
        return Response::ok([], 'Etiqueta eliminada');
    }

    private function dto(array $p): array
    {
        return ['id' => (int)$p['id'], 'key' => $p['project_key'], 'name' => $p['name'], 'description' => $p['description'], 'state' => $p['state'],
            'team_id' => $p['team_id'] ? (int)$p['team_id'] : null, 'visibility' => $p['visibility'], 'start_date' => $p['start_date'], 'due_date' => $p['due_date'],
            'archived' => $p['archived_at'] !== null];
    }
}
