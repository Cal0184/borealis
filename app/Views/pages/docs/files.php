<?= partial('project-header', compact('project', 'modules', 'tab', 'can')) ?>
<?php use Borealis\Core\Auth; use Borealis\Services\Files; $pid = (int)$project['id']; $base = '/projects/' . $pid . '/files'; ?>
<div class="toolbar">
  <nav class="breadcrumbs" style="margin:0" aria-label="Carpetas"><a href="<?= e(url($base)) ?>"><?= icon('folder', 'sm') ?> Archivos</a>
    <?php foreach ($crumbs as $c): ?><?= icon('chevron-right', 'sm') ?><a href="<?= e(url($base, ['folder' => $c['id']])) ?>"><?= e($c['name']) ?></a><?php endforeach; ?></nav>
  <span class="grow"></span>
  <form class="row" method="get" action="<?= e(url($base)) ?>">
    <?php if (!config('app.pretty_urls', true)): ?><input type="hidden" name="r" value="<?= e($base) ?>"><?php endif; ?>
    <label class="sr-only" for="fq">Buscar archivos</label><input class="input search-input" type="search" id="fq" name="q" value="<?= e($q) ?>" placeholder="Buscar en todo el proyecto">
  </form>
  <?php if ($can('file.upload')): ?><button class="btn" data-dialog="dlg-new-folder"><?= icon('folder', 'sm') ?> Nueva carpeta</button><?php endif; ?>
</div>
<?php if ($can('file.upload') && !$q): ?>
  <form class="mb" data-api="/api/projects/<?= $pid ?>/files" data-multipart data-reset>
    <?php if ($folderId): ?><input type="hidden" name="folder_id" value="<?= (int)$folderId ?>"><?php endif; ?>
    <label class="drop-zone" data-drop-zone><input type="file" name="file[]" multiple data-autosubmit><?= icon('upload') ?> Arrastra archivos o <strong>elige desde tu equipo</strong> · máximo <?= round((int)config('uploads.max_bytes', 10485760) / 1048576) ?> MB</label>
    <div class="form-error mt" data-form-error></div>
  </form>
<?php endif; ?>
<?php
$imgCount = count(array_filter($files, fn($f) => Files::isInlineImage($f)));
$view = $view ?? (($files && $imgCount * 2 >= count($files)) ? 'grid' : 'list');
$q2 = array_filter(['folder' => $folderId, 'q' => $q]);
$canManage = fn($f) => $can('file.delete') || ((int)$f['uploader_id'] === Auth::id() && $can('file.upload'));
$ext = fn($f) => strtoupper(pathinfo((string)$f['original_name'], PATHINFO_EXTENSION)) ?: '—';
$actions = function (array $f) use ($canManage): string {
    $id = (int)$f['id'];
    $open = Files::isInlineImage($f) ? '<a class="btn btn--ghost btn--icon btn--sm" href="' . e(url('/files/' . $id, ['inline' => 1])) . '" target="_blank" rel="noopener" aria-label="Abrir ' . e($f['original_name']) . '">' . icon('eye', 'sm') . '</a>' : '';
    $out = $open . '<a class="btn btn--ghost btn--icon btn--sm" href="' . e(url('/files/' . $id)) . '" aria-label="Descargar ' . e($f['original_name']) . '">' . icon('download', 'sm') . '</a>';
    if ($canManage($f)) {
        $base = pathinfo((string)$f['original_name'], PATHINFO_FILENAME);
        $out .= '<div class="menu-wrap"><button class="btn btn--ghost btn--icon btn--sm" type="button" data-menu aria-haspopup="true" aria-expanded="false" aria-label="Más acciones para ' . e($f['original_name']) . '">' . icon('more', 'sm') . '</button>'
            . '<div class="menu" role="menu" style="width:250px"><form class="file-rename" data-api="/api/files/' . $id . '" data-method="PATCH"><label class="menu-title" for="fr-' . $id . '">Cambiar nombre</label>'
            . '<div class="row" style="padding:0 8px 6px"><input class="input sm" id="fr-' . $id . '" name="name" value="' . e($base) . '" maxlength="190" required><button class="btn btn--sm btn--primary" type="submit">Guardar</button></div></form><hr>'
            . '<button type="button" role="menuitem" class="danger" data-call="/api/files/' . $id . '" data-method="DELETE" data-confirm="¿Eliminar «' . e($f['original_name']) . '»?" data-confirm-label="Eliminar">' . icon('trash') . ' Eliminar</button></div></div>';
    }
    return $out;
};
?>
<div class="row files-head">
  <span class="muted small"><?= count($files) ?> <?= count($files) === 1 ? 'archivo' : 'archivos' ?><?= $folders ? ' · ' . count($folders) . ' carpetas' : '' ?></span>
  <div class="seg push" role="group" aria-label="Vista">
    <a class="seg-btn<?= $view === 'grid' ? ' is-on' : '' ?>" href="<?= e(url($base, $q2 + ['view' => 'grid'])) ?>"<?= $view === 'grid' ? ' aria-current="true"' : '' ?>><?= icon('board', 'sm') ?> Cuadrícula</a>
    <a class="seg-btn<?= $view === 'list' ? ' is-on' : '' ?>" href="<?= e(url($base, $q2 + ['view' => 'list'])) ?>"<?= $view === 'list' ? ' aria-current="true"' : '' ?>><?= icon('list', 'sm') ?> Lista</a>
  </div>
</div>
<?php if ($view === 'grid'): ?>
<section class="file-grid" aria-label="Archivos">
  <?php foreach ($folders as $fo): ?>
    <a class="file-tile is-folder" href="<?= e(url($base, ['folder' => $fo['id'], 'view' => 'grid'])) ?>"><span class="file-thumb"><?= icon('folder') ?></span><span class="file-name truncate"><?= e($fo['name']) ?></span><span class="muted small"><?= (int)$fo['file_count'] ?> archivos</span></a>
  <?php endforeach; ?>
  <?php foreach ($files as $f): $img = Files::isInlineImage($f); ?>
    <article class="file-tile" data-file="<?= (int)$f['id'] ?>">
      <a class="file-thumb" href="<?= e(url('/files/' . $f['id'], $img ? ['inline' => 1] : [])) ?>"<?= $img ? ' target="_blank" rel="noopener"' : '' ?> aria-label="<?= $img ? 'Abrir' : 'Descargar' ?> <?= e($f['original_name']) ?>">
        <?php if ($img): ?><img src="<?= e(url('/files/' . $f['id'], ['inline' => 1])) ?>" alt="" loading="lazy" decoding="async"><?php else: ?><span class="file-ext"><?= e($ext($f)) ?></span><?php endif; ?>
      </a>
      <span class="file-name truncate" title="<?= e($f['original_name']) ?>"><?= e($f['original_name']) ?></span>
      <span class="muted small"><?= e($ext($f)) ?> · <?= e(Files::humanSize((int)$f['size_bytes'])) ?> · <?= e(fmt_date(substr((string)$f['created_at'], 0, 10))) ?></span>
      <span class="file-actions"><?= $actions($f) ?></span>
    </article>
  <?php endforeach; ?>
  <?php if (!$folders && !$files): ?><div class="empty"><?= icon('clip') ?><h3><?= $q ? 'Sin resultados' : 'Aún no hay archivos' ?></h3><?php if (!$q): ?><p>Sube imágenes, documentos o archivos de referencia del proyecto.</p><?php endif; ?></div><?php endif; ?>
</section>
<?php else: ?>
<section class="card">
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Nombre</th><th class="hide-sm">Formato</th><th class="hide-sm">Tamaño</th><th class="hide-md">Tarea</th><th class="hide-sm">Subido</th><th><span class="sr-only">Acciones</span></th></tr></thead>
    <tbody>
      <?php foreach ($folders as $fo): ?>
        <tr><td><a class="row" style="font-weight:600" href="<?= e(url($base, ['folder' => $fo['id']])) ?>"><?= icon('folder') ?> <?= e($fo['name']) ?></a></td><td class="hide-sm muted">Carpeta</td><td class="hide-sm muted"><?= (int)$fo['file_count'] ?> archivos</td><td class="hide-md"></td><td class="hide-sm"></td>
          <td style="text-align:right"><?php if ($can('file.delete')): ?><button class="btn btn--ghost btn--icon btn--sm" data-call="/api/projects/<?= $pid ?>/folders/<?= (int)$fo['id'] ?>" data-method="DELETE" data-confirm="¿Eliminar la carpeta «<?= e($fo['name']) ?>»? Solo se puede si está vacía." data-confirm-label="Eliminar" aria-label="Eliminar carpeta"><?= icon('trash', 'sm') ?></button><?php endif; ?></td></tr>
      <?php endforeach; ?>
      <?php foreach ($files as $f): $img = Files::isInlineImage($f); ?>
        <tr data-file="<?= (int)$f['id'] ?>"><td><a class="row" href="<?= e(url('/files/' . $f['id'], $img ? ['inline' => 1] : [])) ?>"<?= $img ? ' target="_blank" rel="noopener"' : '' ?>><?php if ($img): ?><img class="file-mini" src="<?= e(url('/files/' . $f['id'], ['inline' => 1])) ?>" alt="" loading="lazy" width="28" height="28"><?php else: ?><?= icon('doc') ?><?php endif; ?> <span class="truncate file-name" style="max-width:340px"><?= e($f['original_name']) ?></span></a></td>
          <td class="hide-sm muted"><?= e($ext($f)) ?></td>
          <td class="hide-sm muted"><?= e(Files::humanSize((int)$f['size_bytes'])) ?></td>
          <td class="hide-md"><?php if ($f['task_key']): ?><a href="<?= e(url('/t/' . $f['task_key'])) ?>" data-open-task="<?= e($f['task_key']) ?>" class="key"><?= e($f['task_key']) ?></a><?php endif; ?></td>
          <td class="hide-sm muted small"><?= e($f['uploader_name'] ?? '') ?> · <?= e(time_ago($f['created_at'])) ?></td>
          <td style="text-align:right;white-space:nowrap"><span class="file-actions"><?= $actions($f) ?></span></td></tr>
      <?php endforeach; ?>
      <?php if (!$folders && !$files): ?><tr><td colspan="6"><div class="empty"><?= icon('clip') ?><h3><?= $q ? 'Sin resultados' : 'Carpeta vacía' ?></h3></div></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>
<?php if ($can('file.upload')): ?>
<dialog class="modal" id="dlg-new-folder" aria-labelledby="dnf-t">
  <form data-api="/api/projects/<?= $pid ?>/folders" data-close data-reset>
    <div class="modal-head"><h2 id="dnf-t">Nueva carpeta</h2><button type="button" class="btn btn--ghost btn--icon" data-close-dialog aria-label="Cerrar"><?= icon('x') ?></button></div>
    <div class="modal-body"><div class="form-error" data-form-error></div>
      <?php if ($folderId): ?><input type="hidden" name="parent_id" value="<?= (int)$folderId ?>"><?php endif; ?>
      <div class="field"><label for="nf-n">Nombre</label><input class="input" id="nf-n" name="name" required maxlength="120"></div></div>
    <div class="modal-foot"><button type="button" class="btn" data-close-dialog>Cancelar</button><button class="btn btn--primary" type="submit">Crear</button></div>
  </form>
</dialog>
<?php endif; ?>
