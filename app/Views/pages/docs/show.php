<?= partial('project-header', compact('project', 'modules', 'tab', 'can')) ?>
<?php $pid = (int)$project['id']; $did = (int)$doc['id']; ?>
<div class="doc-layout">
  <nav class="card" aria-label="Documentos">
    <ul class="list">
      <?php foreach ($docs as $d): ?><li style="padding:8px 12px"><a class="truncate" style="font-weight:<?= (int)$d['id'] === $did ? '700' : '500' ?>;color:var(--text)" href="<?= e(url('/projects/' . $pid . '/docs/' . $d['id'])) ?>"><?= e($d['title']) ?></a></li><?php endforeach; ?>
    </ul>
  </nav>
  <article class="card">
    <?php if ($editing && $can('doc.edit')): ?>
      <form class="card-body doc-editor" data-api="/api/projects/<?= $pid ?>/docs/<?= $did ?>" data-method="PATCH" data-redirect="<?= e(url('/projects/' . $pid . '/docs/' . $did)) ?>">
        <div class="form-error" data-form-error></div>
        <div class="field"><label for="ed-t">Título</label><input class="input" id="ed-t" name="title" value="<?= e($doc['title']) ?>" required maxlength="200"></div>
        <div class="field"><label for="ed-b">Contenido</label><textarea class="textarea" id="ed-b" name="body"><?= e($doc['body']) ?></textarea></div>
        <div class="form-actions start"><button class="btn btn--primary" type="submit">Guardar documento</button><a class="btn" href="<?= e(url('/projects/' . $pid . '/docs/' . $did)) ?>" data-confirm-leave>Cancelar</a></div>
      </form>
    <?php else: ?>
      <div class="card-head"><h2><?= e($doc['title']) ?></h2>
        <div class="row"><span class="muted small hide-sm">Editado <?= e(time_ago($doc['updated_at'])) ?><?= $doc['updated_by_name'] ? ' por ' . e($doc['updated_by_name']) : '' ?></span>
          <?php if ($can('doc.edit')): ?><a class="btn btn--sm" href="<?= e(url('/projects/' . $pid . '/docs/' . $did, ['edit' => 1])) ?>"><?= icon('edit', 'sm') ?> Editar</a><?php endif; ?>
          <?php if ($can('project.manage')): ?><button class="btn btn--ghost btn--icon btn--sm" data-call="/api/projects/<?= $pid ?>/docs/<?= $did ?>" data-method="DELETE" data-confirm="¿Eliminar el documento «<?= e($doc['title']) ?>»? Su contenido queda guardado en el historial de actividad." data-confirm-label="Eliminar" aria-label="Eliminar documento"><?= icon('trash', 'sm') ?></button><?php endif; ?></div></div>
      <div class="doc-body rich"><?= $doc['body'] ? rich_text($doc['body']) : '<p class="muted">Documento vacío.</p>' ?></div>
    <?php endif; ?>
  </article>
</div>
