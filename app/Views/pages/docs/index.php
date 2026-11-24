<?= partial('project-header', compact('project', 'modules', 'tab', 'can')) ?>
<?php $pid = (int)$project['id']; ?>
<div class="toolbar">
  <form class="row" method="get" action="<?= e(url('/projects/' . $pid . '/docs')) ?>">
    <?php if (!config('app.pretty_urls', true)): ?><input type="hidden" name="r" value="/projects/<?= $pid ?>/docs"><?php endif; ?>
    <label class="sr-only" for="dq">Buscar documentos</label><input class="input search-input" type="search" id="dq" name="q" value="<?= e($q) ?>" placeholder="Buscar en documentos">
    <button class="btn btn--sm" type="submit"><?= icon('search', 'sm') ?> Buscar</button>
  </form>
  <span class="grow"></span>
  <?php if ($can('doc.edit')): ?><button class="btn btn--primary" data-dialog="dlg-new-doc"><?= icon('plus') ?> Nuevo documento</button><?php endif; ?>
</div>
<section class="card">
  <ul class="list">
    <?php foreach ($docs as $d): ?>
      <li><?= icon('doc') ?><a style="flex:1;font-weight:600;color:var(--text)" href="<?= e(url('/projects/' . $pid . '/docs/' . $d['id'])) ?>"><?= e($d['title']) ?></a><span class="muted small">Editado <?= e(time_ago($d['updated_at'])) ?><?= $d['updated_by_name'] ? ' por ' . e($d['updated_by_name']) : '' ?></span></li>
    <?php endforeach; ?>
    <?php if (!$docs): ?><li class="empty"><?= $q ? 'Ningún documento coincide.' : 'Aún no hay documentos. Úsalos para acuerdos, guías o notas del proyecto.' ?></li><?php endif; ?>
  </ul>
</section>
<?php if ($can('doc.edit')): ?>
<dialog class="modal lg" id="dlg-new-doc" aria-labelledby="dnd-t">
  <form data-api="/api/projects/<?= $pid ?>/docs" data-close data-reset>
    <div class="modal-head"><h2 id="dnd-t">Nuevo documento</h2><button type="button" class="btn btn--ghost btn--icon" data-close-dialog aria-label="Cerrar"><?= icon('x') ?></button></div>
    <div class="modal-body doc-editor">
      <div class="form-error" data-form-error></div>
      <div class="field"><label for="nd-t">Título</label><input class="input" id="nd-t" name="title" required maxlength="200"><span class="error" data-error-for="title"></span></div>
      <div class="field"><label for="nd-b">Contenido</label><textarea class="textarea" id="nd-b" name="body" rows="12" placeholder="# Título&#10;Texto con **negrita**, listas con - y enlaces."></textarea></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-close-dialog>Cancelar</button><button class="btn btn--primary" type="submit">Crear documento</button></div>
  </form>
</dialog>
<?php endif; ?>
