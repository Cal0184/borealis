<?php
declare(strict_types=1);

namespace Borealis\Api;

use Borealis\Core\HttpException;
use Borealis\Core\Request;
use Borealis\Core\Response;
use Borealis\Services\Docs;
use Borealis\Services\Epics;
use Borealis\Services\Files;
use Borealis\Services\Sprints;

final class PlanningApi
{
    public function upload(Request $r): Response
    {
        $files = $r->files['file'] ?? null;
        if (!$files) throw HttpException::invalid(['file' => 'Selecciona un archivo.']);
        // Normalise single and multiple uploads.
        $list = is_array($files['name']) ? array_map(fn($i) => array_combine(array_keys($files), array_column($files, $i)), array_keys($files['name'])) : [$files];
        $taskId = (int)($r->post['task_id'] ?? 0) ?: null;
        $folderId = (int)($r->post['folder_id'] ?? 0) ?: null;
        $out = [];
        foreach ($list as $f) {
            $saved = Files::upload($r->intParam('id'), $f, $taskId, $folderId);
            $out[] = ['id' => (int)$saved['id'], 'name' => $saved['original_name'], 'size' => (int)$saved['size_bytes']];
        }
        return Response::ok(['files' => $out], count($out) === 1 ? 'Archivo subido' : count($out) . ' archivos subidos');
    }

    public function moveFile(Request $r): Response
    {
        if ($r->input('name') !== null) {
            $f = Files::rename($r->intParam('id'), (string)$r->input('name'));
            return Response::ok(['name' => $f['original_name']], 'Nombre cambiado');
        }
        $folder = $r->input('folder_id');
        Files::move($r->intParam('id'), $folder ? (int)$folder : null);
        return Response::ok([], 'Archivo movido');
    }

    public function deleteFile(Request $r): Response
    {
        Files::delete($r->intParam('id'));
        return Response::ok([], 'Archivo eliminado');
    }

    public function createFolder(Request $r): Response
    {
        $parent = $r->input('parent_id');
        $id = Files::createFolder($r->intParam('id'), (string)$r->input('name'), $parent ? (int)$parent : null);
        return Response::ok(['id' => $id], 'Carpeta creada');
    }

    public function renameFolder(Request $r): Response
    {
        Files::renameFolder($r->intParam('id'), $r->intParam('fid'), (string)$r->input('name'));
        return Response::ok();
    }

    public function deleteFolder(Request $r): Response
    {
        Files::deleteFolder($r->intParam('id'), $r->intParam('fid'));
        return Response::ok([], 'Carpeta eliminada');
    }

    public function createSprint(Request $r): Response
    {
        $id = Sprints::create($r->intParam('id'), $r->input());
        return Response::ok(['id' => $id], 'Sprint creado');
    }

    public function updateSprint(Request $r): Response
    {
        Sprints::update($r->intParam('id'), $r->input());
        return Response::ok();
    }

    public function sprintCapacity(Request $r): Response
    {
        $warn = \Borealis\Services\Capacity::set($r->intParam('id'), (int)$r->input('user_id'), $r->input('hours'));
        return Response::ok(['warning' => $warn], $warn ?? 'Horas disponibles guardadas');
    }

    public function startSprint(Request $r): Response
    {
        Sprints::start($r->intParam('id'));
        return Response::ok(['celebrate' => true], 'Sprint iniciado');
    }

    public function completeSprint(Request $r): Response
    {
        $move = $r->input('move_to');
        if ($move !== null && $move !== '' && !(is_int($move) || ctype_digit((string)$move))) {
            throw HttpException::invalid(['move_to' => 'Elige el backlog o un sprint planificado.']);
        }
        $notes = $r->input('result_notes');
        $res = Sprints::complete($r->intParam('id'), $move ? (int)$move : null, is_string($notes) ? $notes : null);
        return Response::ok(['result' => $res, 'celebrate' => $res['done'] > 0], "Sprint finalizado: {$res['done']} tareas completadas");
    }

    public function deleteSprint(Request $r): Response
    {
        Sprints::delete($r->intParam('id'));
        return Response::ok([], 'Sprint eliminado');
    }

    public function saveEpic(Request $r): Response
    {
        $id = Epics::save($r->intParam('id'), isset($r->params['eid']) ? $r->intParam('eid') : null, $r->input());
        return Response::ok(['id' => $id]);
    }

    public function deleteEpic(Request $r): Response
    {
        Epics::delete($r->intParam('id'), $r->intParam('eid'));
        return Response::ok([], 'Épica eliminada');
    }

    public function saveDoc(Request $r): Response
    {
        $pid = $r->intParam('id');
        $id = Docs::save($pid, isset($r->params['did']) ? $r->intParam('did') : null, $r->input());
        return Response::ok(['id' => $id, 'redirect' => isset($r->params['did']) ? null : url("/projects/$pid/docs/$id")], 'Documento guardado');
    }

    public function deleteDoc(Request $r): Response
    {
        $pid = $r->intParam('id');
        Docs::delete($pid, $r->intParam('did'));
        return Response::ok(['redirect' => url("/projects/$pid/docs")], 'Documento eliminado');
    }
}
