<?= partial('admin-nav', compact('ws', 'section')) ?>
<?php
$grid = function (array $roles, array $catalog, string $scope) {
    ob_start(); ?>
    <div class="table-wrap"><table class="perm-grid">
      <thead><tr><th>Permiso</th><?php foreach ($roles as $r): ?><th><?= e($r['name']) ?><?= $r['is_system'] ? '' : ' <span class="badge">personalizado</span>' ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($catalog as $perm => $label): ?>
        <tr><td><?= e($label) ?><br><code><?= e($perm) ?></code></td>
          <?php foreach ($roles as $r): $locked = $r['role_key'] === 'owner' || ($scope === 'project' && $perm === 'project.view'); ?>
            <td><input type="checkbox" form="role-form-<?= (int)$r['id'] ?>" name="perms[]" value="<?= e($perm) ?>"<?= checked(in_array($perm, $r['perms'], true)) ?><?= $locked ? ' disabled' : '' ?> aria-label="<?= e($label . ' para ' . $r['name']) ?>"></td>
          <?php endforeach; ?></tr>
      <?php endforeach; ?>
      <tr><td></td><?php foreach ($roles as $r): ?><td>
        <?php if ($r['role_key'] !== 'owner'): ?>
          <form id="role-form-<?= (int)$r['id'] ?>" data-api="/api/roles/<?= (int)$r['id'] ?>" data-method="PATCH">
            <input type="hidden" name="name" value="<?= e($r['name']) ?>"><input type="hidden" name="description" value="<?= e($r['description']) ?>"><input type="hidden" name="perms[]" value="">
            <button class="btn btn--sm btn--primary" type="submit">Guardar</button>
          </form>
          <?php if (!$r['is_system']): ?><button class="btn btn--ghost btn--sm mt" data-call="/api/roles/<?= (int)$r['id'] ?>" data-method="DELETE" data-confirm="¿Eliminar el rol «<?= e($r['name']) ?>»?" data-confirm-label="Eliminar">Eliminar</button><?php endif; ?>
        <?php else: ?><span class="muted small">Siempre completo</span><?php endif; ?>
      </td><?php endforeach; ?></tr>
      </tbody>
    </table></div>
    <?php return ob_get_clean();
};
?>
<section class="card mb"><div class="card-head"><h2><?= icon('shield') ?> Roles del espacio</h2></div><div class="card-body"><?= $grid($wsRoles, $wsCatalog, 'workspace') ?></div></section>
<section class="card mb"><div class="card-head"><h2><?= icon('folder') ?> Roles de proyecto</h2><span class="muted small">Se asignan a cada integrante dentro de un proyecto.</span></div><div class="card-body"><?= $grid($projectRoles, $projectCatalog, 'project') ?></div></section>
<section class="card"><div class="card-head"><h2><?= icon('plus') ?> Crear rol personalizado</h2></div><div class="card-body">
  <form data-api="/api/roles" data-reset>
    <div class="form-error" data-form-error></div>
    <div class="form-row">
      <div class="field"><label for="nr-n">Nombre</label><input class="input" id="nr-n" name="name" required maxlength="80"></div>
      <div class="field"><label for="nr-s">Ámbito</label><select class="select" id="nr-s" name="scope"><option value="project">Proyecto</option><option value="workspace">Espacio</option></select></div>
      <div class="field"><label for="nr-d">Descripción</label><input class="input" id="nr-d" name="description" maxlength="255"></div>
    </div>
    <p class="muted small">Se crea sin permisos adicionales; después márcalos en la tabla correspondiente.</p>
    <div class="form-actions start"><button class="btn btn--primary" type="submit">Crear rol</button></div>
  </form>
</div></section>
