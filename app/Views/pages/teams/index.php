<div class="page-head">
  <div><h1>Equipos</h1><p class="sub">Personas que trabajan juntas. Cada persona tiene una sola cuenta y puede estar en varios equipos.</p></div>
  <div class="page-actions">
    <nav class="segmented" aria-label="Mostrar"><a href="<?= e(url('/teams')) ?>"<?= !$archived ? ' aria-current="page"' : '' ?>>Activos</a><a href="<?= e(url('/teams', ['archived' => 1])) ?>"<?= $archived ? ' aria-current="page"' : '' ?>>Archivados</a></nav>
    <?php if ($canCreate): ?><button class="btn btn--primary" data-dialog="dlg-new-team"><?= icon('plus') ?> Nuevo equipo</button><?php endif; ?>
  </div>
</div>
<?= partial('capacity-alerts', ['alerts' => \Borealis\Services\CapacityAlerts::forView()]) ?>
<?php if ($teams): ?>
<div class="grid grid-cards">
  <?php foreach ($teams as $t): ?>
    <a class="card project-card team-card" href="<?= e(url('/teams/' . $t['id'])) ?>">
      <div class="top">
        <span class="swatch" style="--c:<?= e($t['color']) ?>"><?= icon('users') ?></span>
        <div style="min-width:0"><h3><?= e($t['name']) ?></h3><div class="desc"><?= e($t['description'] ?: 'Sin descripción') ?></div></div>
      </div>
      <div class="members"><div class="avatars"><?php foreach ($t['members'] as $m) echo avatar($m, 'sm'); ?></div><span class="muted small"><?= (int)$t['member_count'] ?> <?= (int)$t['member_count'] === 1 ? 'integrante' : 'integrantes' ?></span></div>
      <div class="foot"><span class="row"><?= icon('folder', 'sm') ?> <?= (int)$t['project_count'] ?> proyectos</span><span class="row"><?= icon('circle', 'sm') ?> <?= (int)$t['open_tasks'] ?> tareas abiertas</span></div>
    </a>
  <?php endforeach; ?>
</div>
<?php else: ?>
  <div class="card"><div class="empty"><?= icon('users') ?><h3><?= $archived ? 'No hay equipos archivados' : 'Crea tu primer equipo' ?></h3><p><?= $archived ? '' : 'Solo necesitas un nombre. Después añades integrantes y proyectos.' ?></p>
    <?php if ($canCreate && !$archived): ?><button class="btn btn--primary" data-dialog="dlg-new-team"><?= icon('plus') ?> Nuevo equipo</button><?php endif; ?></div></div>
<?php endif; ?>

<?php if ($canCreate): ?>
<dialog class="modal" id="dlg-new-team" aria-labelledby="dlg-new-team-title">
  <form data-api="/api/teams" data-reset data-close data-toast-link="url" data-toast-label="Abrir equipo">
    <div class="modal-head"><h2 id="dlg-new-team-title">Nuevo equipo</h2><button type="button" class="btn btn--ghost btn--icon" data-close-dialog aria-label="Cerrar"><?= icon('x') ?></button></div>
    <div class="modal-body">
      <div class="form-error" data-form-error></div>
      <div class="field"><label for="nt-name">Nombre</label><input class="input" id="nt-name" name="name" required maxlength="120" autocomplete="off"><span class="error" data-error-for="name"></span></div>
      <details class="more-options"><summary><?= icon('plus', 'sm') ?> Más opciones</summary>
        <div class="field"><label for="nt-desc">Descripción</label><textarea class="textarea" id="nt-desc" name="description" rows="2"></textarea></div>
        <div class="field"><label for="nt-color">Color</label><input class="color-input" type="color" id="nt-color" name="color" value="#10B981"></div>
      </details>
      <p class="muted small">Quedarás como coordinador o coordinadora del equipo.</p>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-close-dialog>Cancelar</button><button type="submit" class="btn btn--primary">Crear equipo</button></div>
  </form>
</dialog>
<?php endif; ?>
