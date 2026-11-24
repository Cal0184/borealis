<?php use Borealis\Services\Projects; $arch = (string)($filters['archived'] ?? '0'); ?>
<div class="page-head">
  <div><h1>Proyectos</h1><p class="sub"><?= count($projects) ?> <?= count($projects) === 1 ? 'proyecto' : 'proyectos' ?><?= $arch === '1' ? ' archivados' : '' ?></p></div>
  <div class="page-actions"><?php if ($canCreate): ?><button class="btn" data-dialog="dlg-import-project"><?= icon('upload') ?> Importar</button><button class="btn btn--primary" data-dialog="dlg-new-project"><?= icon('plus') ?> Nuevo proyecto</button><?php endif; ?></div>
</div>
<form class="toolbar" data-tour="projects-filters" method="get" action="<?= e(url('/projects')) ?>">
  <?php if (!config('app.pretty_urls', true)): ?><input type="hidden" name="r" value="/projects"><?php endif; ?>
  <label class="sr-only" for="pf-q">Buscar</label>
  <input class="input search-input" type="search" id="pf-q" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Buscar por nombre o clave">
  <label class="sr-only" for="pf-state">Estado</label>
  <select class="select<?= !empty($filters['state']) ? ' filter-active' : '' ?>" id="pf-state" name="state" data-autosubmit><option value="">Cualquier estado</option><?php foreach (Projects::STATES as $k => $v): ?><option value="<?= $k ?>"<?= selected($k, $filters['state'] ?? '') ?>><?= e($v) ?></option><?php endforeach; ?></select>
  <label class="sr-only" for="pf-team">Equipo</label>
  <select class="select<?= !empty($filters['team_id']) ? ' filter-active' : '' ?>" id="pf-team" name="team_id" data-autosubmit><option value="">Todos los equipos</option><?php foreach ($teams as $t): ?><option value="<?= (int)$t['id'] ?>"<?= selected($t['id'], $filters['team_id'] ?? '') ?>><?= e($t['name']) ?></option><?php endforeach; ?></select>
  <label class="sr-only" for="pf-arch">Archivo</label>
  <select class="select<?= $arch !== '0' ? ' filter-active' : '' ?>" id="pf-arch" name="archived" data-autosubmit><option value="0"<?= selected('0', $arch) ?>>Sin archivados</option><option value="1"<?= selected('1', $arch) ?>>Solo archivados</option><option value="all"<?= selected('all', $arch) ?>>Todos</option></select>
  <?php if (\Borealis\Security\Policy::can('projects.manage_all')): ?>
  <label class="sr-only" for="pf-mine">Qué proyectos</label>
  <select class="select<?= ($filters['mine'] ?? '') === '1' ? ' filter-active' : '' ?>" id="pf-mine" name="mine" data-autosubmit title="Como administración ves todos los proyectos del espacio"><option value="">Todos los proyectos</option><option value="1"<?= selected('1', $filters['mine'] ?? '') ?>>Solo donde participo</option></select>
  <?php endif; ?>
  <label class="sr-only" for="pf-sort">Ordenar</label>
  <select class="select" id="pf-sort" name="sort" data-autosubmit><option value="">Por nombre</option><option value="recent"<?= selected('recent', $filters['sort'] ?? '') ?>>Actividad reciente</option><option value="due"<?= selected('due', $filters['sort'] ?? '') ?>>Fecha de entrega</option></select>
  <button class="btn" type="submit"><?= icon('filter', 'sm') ?> Aplicar</button>
</form>
<?php if ($projects): ?>
<div class="grid grid-cards" id="project-grid">
  <?php foreach ($projects as $p): $state = ['planned' => '', 'active' => 'success', 'on_hold' => 'warn', 'completed' => 'info'][$p['state']]; ?>
    <a class="card project-card" href="<?= e(url('/projects/' . $p['id'])) ?>" data-project-card="<?= (int)$p['id'] ?>">
      <div class="top">
        <span class="swatch" style="--c:<?= e($p['color']) ?>"><?= e(substr($p['project_key'], 0, 3)) ?></span>
        <div style="min-width:0;flex:1"><h3 data-project-name="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></h3><div class="desc"><?= e($p['description'] ?: ($p['team_name'] ? 'Equipo ' . $p['team_name'] : 'Sin descripción')) ?></div></div>
      </div>
      <div class="row wrap">
        <?php if ($p['archived_at']): ?><span class="badge"><?= icon('archive', 'sm') ?> Archivado</span><?php else: ?><span class="badge <?= $state ?>"><?= e(Projects::STATES[$p['state']]) ?></span><?php endif; ?>
        <?php if ($p['blocked']): ?><span class="badge danger"><?= icon('block', 'sm') ?> <?= (int)$p['blocked'] ?></span><?php endif; ?>
        <?php if ($p['overdue']): ?><span class="badge warn"><?= icon('alert', 'sm') ?> <?= (int)$p['overdue'] ?> vencidas</span><?php endif; ?>
      </div>
      <div>
        <div class="progress-label"><span><?= (int)$p['done'] ?> de <?= (int)$p['total'] ?> tareas</span><span><?= (int)$p['progress'] ?>%</span></div>
        <div class="progress"><span style="--p:<?= (int)$p['progress'] ?>%"></span></div>
      </div>
      <div class="foot">
        <span class="person"><?= avatar($p['lead_name'] ? ['id' => $p['lead_user_id'], 'name' => $p['lead_name'], 'avatar_color' => $p['lead_color']] : null, 'sm') ?><span class="truncate"><?= e($p['lead_name'] ?? 'Sin responsable') ?></span></span>
        <?php if ($p['due_date']): ?><span class="due <?= due_state($p['due_date'], $p['state'] === 'completed') ?>"><?= icon('calendar', 'sm') ?><?= e(fmt_date($p['due_date'], true)) ?></span><?php endif; ?>
      </div>
    </a>
  <?php endforeach; ?>
</div>
<?php else: ?>
  <div class="card"><div class="empty"><?= icon('folder') ?><h3><?= array_filter($filters) ? 'Ningún proyecto coincide' : 'Crea el primer proyecto' ?></h3>
    <p><?= array_filter($filters) ? 'Prueba con otros filtros.' : 'Solo necesitas un nombre. Lo demás puede esperar.' ?></p>
    <?php if ($canCreate && !array_filter($filters)): ?><button class="btn btn--primary" data-dialog="dlg-new-project"><?= icon('plus') ?> Nuevo proyecto</button><?php endif; ?></div></div>
<?php endif; ?>

<?php if ($canCreate): ?>
<dialog class="modal" id="dlg-import-project" aria-labelledby="dip-t">
  <form data-api="/api/projects/import" data-multipart data-reset data-close data-toast-link="project">
    <div class="modal-head"><h2 id="dip-t">Importar proyecto</h2><button type="button" class="btn btn--ghost btn--icon" data-close-dialog aria-label="Cerrar"><?= icon('x') ?></button></div>
    <div class="modal-body">
      <div class="form-error" data-form-error></div>
      <div class="field"><label for="ip-file">Archivo exportado de Borealis</label><input class="input" type="file" id="ip-file" name="file" accept=".json,application/json" required><span class="hint">El .json que genera Ajustes del proyecto › Exportar proyecto.</span><span class="error" data-error-for="file"></span></div>
      <div class="field"><label for="ip-name">Nombre (opcional)</label><input class="input" id="ip-name" name="name" maxlength="160" placeholder="Igual que en el archivo"><span class="error" data-error-for="name"></span></div>
      <p class="muted small">Se crea un proyecto nuevo; no se modifica nada existente. Las personas y equipos se reconocen por correo y nombre; quien no exista en este espacio queda anotado y sus tareas quedan sin responsable.</p>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-close-dialog>Cancelar</button><button class="btn btn--primary" type="submit"><?= icon('upload', 'sm') ?> Importar</button></div>
  </form>
</dialog>
<?php endif; ?>
