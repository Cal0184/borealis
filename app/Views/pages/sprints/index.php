<?php
use Borealis\Services\Sprints;
$groups = ['active' => 'En curso', 'planned' => 'Próximos', 'completed' => 'Cerrados en los últimos 60 días'];
$today = today();
?>
<div class="page-head">
  <div><h1>Sprints</h1><p class="sub">Los sprints de tus proyectos. Créalos con lo básico y planifícalos después, sin prisa.</p></div>
  <?php if ($plannable): ?><div class="row wrap"><button class="btn btn--primary" type="button" data-dialog="dlg-quick-sprint" data-tour="sprint-new"><?= icon('plus', 'sm') ?> Nuevo sprint</button></div><?php endif; ?>
</div>

<div id="sprints-region" data-region>
<?php if (!$sprints): ?>
  <section class="card"><div class="empty"><?= icon('zap') ?><h3>Aún no hay sprints</h3>
    <p>Un sprint es un periodo de trabajo con un objetivo. Basta con un nombre, el proyecto y unas fechas aproximadas; las tareas, responsables y horas se añaden después.</p>
    <?php if ($plannable): ?><button class="btn btn--primary" type="button" data-dialog="dlg-quick-sprint"><?= icon('plus', 'sm') ?> Crear el primero</button>
    <?php else: ?><p class="small muted">Para crear sprints, el proyecto debe tener activado el módulo «Sprints» y necesitas poder planificarlos.</p><?php endif; ?></div></section>
<?php endif; ?>
<?php foreach ($groups as $state => $label): $rows = array_values(array_filter($sprints, fn($s) => $s['state'] === $state)); if (!$rows) continue; ?>
  <section class="mine-block" aria-labelledby="sg-<?= $state ?>">
    <h2 class="mine-title" id="sg-<?= $state ?>"><?= e($label) ?> <span class="count"><?= count($rows) ?></span></h2>
    <div class="sprint-cards">
      <?php foreach ($rows as $s): $pc = pct((int)$s['done_count'], (int)$s['task_count']);
        $pendingClose = $s['state'] === 'active' && $s['end_date'] && $s['end_date'] < $today;
        $todo = [];
        if ($s['state'] !== 'completed') {
          if (!(int)$s['task_count']) $todo[] = 'sin tareas';
          if ((int)$s['unassigned']) $todo[] = (int)$s['unassigned'] . ' sin responsable';
          if ((int)$s['unestimated']) $todo[] = (int)$s['unestimated'] . ' sin tiempo estimado';
          if (!$s['start_date'] || !$s['end_date']) $todo[] = 'fechas por definir';
        } ?>
        <a class="card sprint-card" href="<?= e(url('/projects/' . (int)$s['project_id'] . '/sprints', ['sprint' => $s['id']])) ?>">
          <div class="sprint-card-head"><span class="dot" style="--c:<?= e($s['project_color']) ?>;background:var(--c)"></span><span class="muted small truncate"><?= e($s['project_name']) ?></span>
            <span class="badge<?= $s['state'] === 'active' ? ' success' : ($s['state'] === 'completed' ? ' info' : '') ?>"><?= $pendingClose ? 'Pendiente de cierre' : e(Sprints::STATES[$s['state']]) ?></span></div>
          <h3 class="sprint-card-title"><?= e($s['name']) ?></h3>
          <?php if ($s['goal']): ?><p class="small muted sprint-card-goal"><?= e(mb_strimwidth((string)$s['goal'], 0, 140, '…')) ?></p><?php endif; ?>
          <p class="small"><?= icon('calendar', 'sm') ?> <?= $s['start_date'] ? e(fmt_date($s['start_date'])) . ' – ' . ($s['end_date'] ? e(fmt_date($s['end_date'])) : '¿?') : 'Fechas por definir' ?>
            <?php if ($s['state'] === 'completed' && $s['completed_at']): ?> · cerrado el <?= e(fmt_date(local_date('Y-m-d', $s['completed_at']))) ?><?php endif; ?></p>
          <div class="progress sm" aria-hidden="true"><span style="--p:<?= $pc ?>%"></span></div>
          <p class="small muted"><?= (int)$s['done_count'] ?>/<?= (int)$s['task_count'] ?> tareas<?= (float)$s['hours'] ? ' · ' . e(rtrim(rtrim(number_format((float)$s['hours'], 1, ',', ''), '0'), ',')) . ' h estimadas' : '' ?></p>
          <?php if ($pendingClose): ?><p class="small sprint-warn"><?= icon('alert', 'sm') ?> Llegó a su fecha de fin. Revísalo y ciérralo cuando esté listo: no se cierra solo.</p><?php endif; ?>
          <?php if ($todo): ?><p class="small sprint-todo"><?= icon('list', 'sm') ?> Por planificar: <?= e(implode(', ', $todo)) ?></p><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
<?php endforeach; ?>
</div>

<?php if ($plannable): ?>
<dialog class="modal" id="dlg-quick-sprint" aria-labelledby="dqs-t">
  <form data-api="/api/projects/{project_id}/sprints" data-close data-reset>
    <div class="modal-head"><h2 id="dqs-t">Nuevo sprint</h2><button type="button" class="btn btn--ghost btn--icon" data-close-dialog aria-label="Cerrar"><?= icon('x') ?></button></div>
    <div class="modal-body">
      <div class="form-error" data-form-error></div>
      <p class="hint" style="margin-top:0">Primero lo básico. Las tareas, responsables, horas y prioridades se añaden después, en la planificación del sprint.</p>
      <div class="field"><label for="qs-n">Nombre</label><input class="input" id="qs-n" name="name" maxlength="120" placeholder="Sprint 1" autocomplete="off"><span class="hint">Si lo dejas vacío se numera solo.</span></div>
      <div class="field"><label for="qs-p">Proyecto</label>
        <select class="select" id="qs-p" name="project_id" required><?php foreach ($plannable as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="qs-g">Objetivo general</label><textarea class="textarea" id="qs-g" name="goal" rows="2" placeholder="¿Qué queremos lograr en este periodo?"></textarea></div>
      <div class="form-row"><div class="field"><label for="qs-s">Inicio estimado</label><input class="input" type="date" id="qs-s" name="start_date"></div><div class="field"><label for="qs-e">Fin estimado</label><input class="input" type="date" id="qs-e" name="end_date"><span class="error" data-error-for="end_date"></span></div></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-close-dialog>Cancelar</button><button class="btn btn--primary" type="submit">Crear sprint</button></div>
  </form>
</dialog>
<?php endif; ?>
