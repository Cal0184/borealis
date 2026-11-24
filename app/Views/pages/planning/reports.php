<?= partial('project-header', compact('project', 'modules', 'tab', 'can')) ?>
<?php
$r = $report;
$pid = (int)$project['id'];
$total = array_sum(array_column($r['by_status'], 'n'));
$maxWeek = max(1, ...array_map(fn($w) => max($w['created'], $w['completed']), $r['weeks']));
$maxLoad = max(1, ...array_map(fn($w) => (int)$w['open_tasks'], $r['workload'] ?: [['open_tasks' => 1]]));
?>
<div class="page-head"><div><h2>Reportes del proyecto</h2><p class="sub">Para coordinar: dónde está el trabajo y qué lo frena.</p></div>
  <div class="row export-row" role="group" aria-label="Exportar reportes">
    <a class="btn btn--primary" href="<?= e(url('/projects/' . $pid . '/report/print')) ?>" target="_blank" rel="noopener"><?= icon('doc') ?> Informe (imprimir o PDF)</a>
    <a class="btn" href="<?= e(url('/projects/' . $pid . '/export.csv')) ?>" download><?= icon('download') ?> Tareas (CSV)</a>
    <a class="btn" href="<?= e(url('/projects/' . $pid . '/hours.csv')) ?>" download><?= icon('download') ?> Horas (CSV)</a>
  </div></div>
<div class="grid grid-4 stats mb">
  <div class="card stat"><span class="label"><?= icon('target', 'sm') ?> Progreso</span><span class="value"><?= (int)$r['progress']['progress'] ?>%</span></div>
  <div class="card stat"><span class="label"><?= icon('circle', 'sm') ?> Abiertas</span><span class="value"><?= (int)$r['progress']['total'] - (int)$r['progress']['done'] ?></span></div>
  <div class="card stat<?= $r['overdue'] ? ' danger' : '' ?>"><span class="label"><?= icon('alert', 'sm') ?> Vencidas</span><span class="value"><?= count($r['overdue']) ?></span></div>
  <div class="card stat<?= $r['blocked'] ? ' warn' : '' ?>"><span class="label"><?= icon('block', 'sm') ?> Bloqueadas</span><span class="value"><?= count($r['blocked']) ?></span></div>
</div>
<div class="grid grid-2 mb">
  <section class="card"><div class="card-head"><h3><?= icon('board') ?> Tareas por estado</h3></div><div class="card-body"><div class="bars">
    <?php foreach ($r['by_status'] as $s): ?><div class="bar-row"><span class="truncate"><?= e($s['name']) ?></span><span class="track"><span class="fill" style="--w:<?= pct((int)$s['n'], max(1, $total)) ?>%;--c:<?= e($s['color']) ?>"></span></span><span class="n"><?= (int)$s['n'] ?></span></div><?php endforeach; ?>
  </div></div></section>
  <section class="card"><div class="card-head"><h3><?= icon('chart') ?> Ritmo semanal</h3><div class="legend"><span style="--c:#BAE6FD">Creadas</span><span style="--c:var(--aurora)">Finalizadas</span></div></div><div class="card-body">
    <div class="columns-chart" role="img" aria-label="Tareas creadas y finalizadas por semana">
      <?php foreach ($r['weeks'] as $w): ?><div class="col"><div class="pair"><span class="b created" style="height:<?= pct($w['created'], $maxWeek) ?>%" title="<?= (int)$w['created'] ?> creadas"></span><span class="b completed" style="height:<?= pct($w['completed'], $maxWeek) ?>%" title="<?= (int)$w['completed'] ?> finalizadas"></span></div><span class="x"><?= e(fmt_date($w['week'])) ?></span></div><?php endforeach; ?>
    </div>
  </div></section>
</div>
<div class="grid grid-2 mb">
  <section class="card"><div class="card-head"><h3><?= icon('users') ?> Distribución del trabajo abierto</h3></div><div class="card-body">
    <?php if ($r['workload']): ?><div class="bars"><?php foreach ($r['workload'] as $w): ?><div class="bar-row"><span class="truncate"><?= e($w['name'] ?? 'Sin responsable') ?></span><span class="track"><span class="fill" style="--w:<?= pct((int)$w['open_tasks'], $maxLoad) ?>%;--c:<?= e($w['avatar_color'] ?? '#CBD5CC') ?>"></span></span><span class="n"><?= (int)$w['open_tasks'] ?></span></div><?php endforeach; ?></div>
    <p class="muted small mt" style="margin-bottom:0">Ayuda a repartir la carga; no mide el desempeño individual.</p>
    <?php else: ?><p class="muted" style="margin:0">No hay tareas abiertas.</p><?php endif; ?>
  </div></section>
  <section class="card"><div class="card-head"><h3><?= icon('alert') ?> Vencidas y bloqueadas</h3></div>
    <ul class="list">
      <?php foreach ($r['overdue'] as $t): ?><li><span class="badge danger">Vencida</span><span class="key"><?= e($t['task_key']) ?></span><button class="task-title truncate" style="flex:1" data-open-task="<?= e($t['task_key']) ?>"><?= e($t['title']) ?></button><span class="due overdue"><?= e(fmt_date($t['due_date'])) ?></span></li><?php endforeach; ?>
      <?php foreach ($r['blocked'] as $t): ?><li><span class="badge warn">Bloqueada</span><span class="key"><?= e($t['task_key']) ?></span><button class="task-title truncate" style="flex:1" data-open-task="<?= e($t['task_key']) ?>" title="<?= e($t['blocked_reason']) ?>"><?= e($t['title']) ?></button></li><?php endforeach; ?>
      <?php if (!$r['overdue'] && !$r['blocked']): ?><li class="empty">Sin vencimientos ni bloqueos.</li><?php endif; ?>
    </ul></section>
</div>
<?php $hTot = array_sum(array_map(fn($x) => (int)$x['minutes'], $r['hours_people'])); $hMax = max(1, ...array_map(fn($x) => (int)$x['minutes'], $r['hours_people'] ?: [['minutes' => 1]])); ?>
<div class="grid grid-2 mb">
  <section class="card"><div class="card-head"><h3><?= icon('clock') ?> Horas por persona</h3><span class="muted small">Total <?= e(\Borealis\Services\TimeEntries::fmt($hTot)) ?></span></div><div class="card-body">
    <?php if ($r['hours_people']): ?><div class="bars bars--hours"><?php foreach ($r['hours_people'] as $h): ?><div class="bar-row"><span class="truncate"><?= e($h['name']) ?></span><span class="track"><span class="fill" style="--w:<?= pct((int)$h['minutes'], $hMax) ?>%;--c:<?= e($h['avatar_color'] ?? '#CBD5CC') ?>"></span></span><span class="n hours-n"><?= e(\Borealis\Services\TimeEntries::fmt((int)$h['minutes'])) ?></span></div><?php endforeach; ?></div>
    <?php else: ?><p class="muted" style="margin:0">Todavía no hay horas registradas. Se registran desde cada tarea, en la sección «Horas».</p><?php endif; ?>
  </div></section>
  <section class="card"><div class="card-head"><h3><?= icon('check-square') ?> Horas por tarea</h3></div>
    <ul class="list">
      <?php foreach ($r['hours_tasks'] as $t): $over = $t['estimate_hours'] !== null && (int)$t['minutes'] > (float)$t['estimate_hours'] * 60; ?><li><span class="key"><?= e($t['task_key']) ?></span><button class="task-title truncate" style="flex:1" data-open-task="<?= e($t['task_key']) ?>"><?= e($t['title']) ?></button><span class="small<?= $over ? ' badge warn' : ' muted' ?>"><?= e(\Borealis\Services\TimeEntries::fmt((int)$t['minutes'])) ?><?= $t['estimate_hours'] !== null ? ' / ' . e(rtrim(rtrim(number_format((float)$t['estimate_hours'], 1, '.', ''), '0'), '.')) . ' h' : '' ?></span></li><?php endforeach; ?>
      <?php if (!$r['hours_tasks']): ?><li class="empty">Sin horas registradas.</li><?php endif; ?>
    </ul></section>
</div>
<section class="card"><div class="card-head"><h3><?= icon('clock') ?> Actividad reciente</h3></div><div class="card-body"><ul class="history">
  <?php foreach ($r['activity'] as $a): ?><li><span><strong><?= e($a['user_name'] ?? 'Sistema') ?></strong> <?= e(lcfirst($a['summary'])) ?> <span class="muted">· <?= e(time_ago($a['created_at'])) ?></span></span></li><?php endforeach; ?>
</ul></div></section>
