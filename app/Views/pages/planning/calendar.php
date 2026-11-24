<?php
$c = $cal;
$inProject = !empty($project);
if ($inProject) echo partial('project-header', compact('project', 'modules', 'tab', 'can'));
$base = $inProject ? '/projects/' . $project['id'] . '/calendar' : '/calendar';
$keep = array_intersect_key($c['filters'], array_flip(['project_id', 'assignee', 'hide_done', 'view']));
$months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$title = $c['view'] === 'month' ? ucfirst($months[(int)date('n', strtotime($c['anchor'])) - 1]) . ' ' . date('Y', strtotime($c['anchor'])) : 'Semana del ' . fmt_date($c['start']) . ' al ' . fmt_date($c['end'], true);
$canEditProject = [];
foreach ($c['calendarProjects'] as $cp) $canEditProject[(int)$cp['id']] = !$cp['archived_at'] && \Borealis\Security\Policy::canProject($cp, 'task.edit');
$maxPerDay = $c['view'] === 'month' ? 4 : 40;
?>
<?php if (!$inProject): ?><div class="page-head"><div><h1>Calendario</h1><p class="sub">Tareas con fecha de los proyectos que tienen el calendario activo.</p></div></div><?php endif; ?>
<div class="toolbar">
  <div class="btn-group" role="group" aria-label="Navegar">
    <a class="btn btn--sm" href="<?= e(url($base, ['date' => $c['prev']] + $keep)) ?>" aria-label="Anterior"><?= icon('chevron-left', 'sm') ?></a>
    <a class="btn btn--sm" href="<?= e(url($base, ['date' => today()] + $keep)) ?>">Hoy</a>
    <a class="btn btn--sm" href="<?= e(url($base, ['date' => $c['next']] + $keep)) ?>" aria-label="Siguiente"><?= icon('chevron-right', 'sm') ?></a>
  </div>
  <h2 style="margin:0 8px"><?= e($title) ?></h2>
  <span class="grow"></span>
  <form class="row wrap" method="get" action="<?= e(url($base)) ?>">
    <?php if (!config('app.pretty_urls', true)): ?><input type="hidden" name="r" value="<?= e($base) ?>"><?php endif; ?>
    <input type="hidden" name="date" value="<?= e($c['anchor']) ?>"><input type="hidden" name="view" value="<?= e($c['view']) ?>">
    <?php if (!$inProject): ?>
      <label class="sr-only" for="cal-p">Proyecto</label>
      <select class="select sm<?= !empty($keep['project_id']) ? ' filter-active' : '' ?>" id="cal-p" name="project_id" data-autosubmit><option value="">Todos los proyectos</option><?php foreach ($c['calendarProjects'] as $cp): ?><option value="<?= (int)$cp['id'] ?>"<?= selected($cp['id'], $keep['project_id'] ?? '') ?>><?= e($cp['name']) ?></option><?php endforeach; ?></select>
    <?php endif; ?>
    <label class="sr-only" for="cal-a">Responsable</label>
    <select class="select sm<?= !empty($keep['assignee']) ? ' filter-active' : '' ?>" id="cal-a" name="assignee" data-autosubmit style="width:auto"><option value="">Cualquier responsable</option><option value="me"<?= selected('me', $keep['assignee'] ?? '') ?>>Asignadas a mí</option><?php foreach ($c['people'] as $m): ?><option value="<?= (int)$m['id'] ?>"<?= selected($m['id'], $keep['assignee'] ?? '') ?>><?= e($m['name']) ?></option><?php endforeach; ?></select>
    <label class="check"><input type="checkbox" name="hide_done" value="1"<?= checked(($keep['hide_done'] ?? '') === '1') ?> data-autosubmit> Ocultar finalizadas</label>
  </form>
  <nav class="segmented" aria-label="Vista">
    <a href="<?= e(url($base, ['view' => 'month', 'date' => $c['anchor']] + $keep)) ?>"<?= $c['view'] === 'month' ? ' aria-current="page"' : '' ?>>Mes</a>
    <a href="<?= e(url($base, ['view' => 'week', 'date' => $c['anchor']] + $keep)) ?>"<?= $c['view'] === 'week' ? ' aria-current="page"' : '' ?>>Semana</a>
  </nav>
</div>
<?php if (!$inProject && !$c['calendarProjects']): ?>
  <div class="card"><div class="empty"><?= icon('calendar') ?><h3>Ningún proyecto usa el calendario</h3><p>Actívalo en Ajustes › Módulos de un proyecto.</p></div></div>
<?php else: ?>
<div class="calendar<?= $c['view'] === 'week' ? ' week' : '' ?>" data-calendar>
  <?php foreach (['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'] as $d): ?><div class="dow"><?= $d ?></div><?php endforeach; ?>
  <?php for ($d = $c['start']; $d <= $c['end']; $d = date('Y-m-d', strtotime($d . ' +1 day'))):
    $tasks = $c['byDay'][$d] ?? [];
    $other = $c['view'] === 'month' && date('m', strtotime($d)) !== date('m', strtotime($c['anchor'])); ?>
    <div class="day<?= $other ? ' other' : '' ?><?= $d === today() ? ' today' : '' ?>" data-day="<?= $d ?>" aria-label="<?= e(fmt_date($d, true)) ?>: <?= count($tasks) ?> tareas">
      <span class="num"><?= (int)date('j', strtotime($d)) ?></span>
      <?php foreach (array_slice($tasks, 0, $maxPerDay) as $t): $editable = $canEditProject[(int)$t['project_id']] ?? false; ?>
        <button type="button" class="cal-task<?= $t['status_category'] === 'done' ? ' done' : '' ?>" style="--c:<?= e($t['priority_color'] ?: $t['project_color']) ?>" data-open-task="<?= e($t['task_key']) ?>"
          <?= $editable ? 'data-cal-drag data-task-id="' . (int)$t['id'] . '" data-start="' . e($t['start_date']) . '" data-due="' . e($t['due_date']) . '"' : '' ?>
          title="<?= e($t['task_key'] . ' · ' . $t['title'] . ($t['assignee_name'] ? ' · ' . $t['assignee_name'] : '')) ?>">
          <span><?= e($t['title']) ?></span>
        </button>
      <?php endforeach; ?>
      <?php if (count($tasks) > $maxPerDay): ?><a class="cal-more" href="<?= e(url($base, ['view' => 'week', 'date' => $d] + $keep)) ?>">+<?= count($tasks) - $maxPerDay ?> más</a><?php endif; ?>
    </div>
  <?php endfor; ?>
</div>
<p class="muted small mt">Arrastra una tarea a otro día para reprogramarla; si tiene inicio y vencimiento, ambos se desplazan. También puedes cambiar las fechas desde su detalle.</p>
<?php endif; ?>
