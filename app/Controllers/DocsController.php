<?php
declare(strict_types=1);

namespace Borealis\Controllers;

use Borealis\Core\Request;
use Borealis\Core\Response;
use Borealis\Core\View;
use Borealis\Services\Docs;
use Borealis\Services\Files;

final class DocsController
{
    public function index(Request $r): Response
    {
        $ctx = ProjectContext::load($r, 'docs');
        $q = trim((string)$r->q('q', ''));
        return View::page('docs/index', $ctx + ['tab' => 'docs', 'docs' => Docs::list((int)$ctx['project']['id'], $q ?: null), 'q' => $q, 'doc' => null]);
    }

    public function show(Request $r): Response
    {
        $ctx = ProjectContext::load($r, 'docs');
        $doc = Docs::find((int)$ctx['project']['id'], $r->intParam('doc'));
        return View::page('docs/show', $ctx + ['tab' => 'docs', 'doc' => $doc, 'docs' => Docs::list((int)$ctx['project']['id']), 'editing' => $r->q('edit') === '1', 'title' => $doc['title']]);
    }

    public function files(Request $r): Response
    {
        $ctx = ProjectContext::load($r); // every project has its own files, with or without Documentos
        $pid = (int)$ctx['project']['id'];
        $folderId = (int)$r->q('folder', 0) ?: null;
        if ($folderId) Files::folder($pid, $folderId);
        $q = trim((string)$r->q('q', ''));
        return View::page('docs/files', $ctx + [
            'tab' => 'files', 'folderId' => $folderId, 'q' => $q, 'view' => $r->q('view') === 'grid' ? 'grid' : ($r->q('view') === 'list' ? 'list' : null),
            'folders' => $q ? [] : Files::folders($pid, $folderId),
            'files' => Files::list($pid, ['folder_id' => $folderId, 'q' => $q]),
            'crumbs' => Files::breadcrumbs($pid, $folderId),
        ]);
    }

    public function download(Request $r): Response
    {
        $f = Files::find($r->intParam('id'));
        $inline = $r->q('inline') === '1' && Files::isInlineImage($f);
        return Response::download(Files::path($f), $f['original_name'], $inline ? $f['mime'] : 'application/octet-stream', $inline);
    }
}
