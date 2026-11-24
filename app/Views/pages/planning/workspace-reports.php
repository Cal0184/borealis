<?php $r = $report; $t = $r['totals']; ?>
<div class="page-head"><div><h1>Reportes</h1><p class="sub">Estado general de los proyectos que puedes ver.</p></div>
  <div class="row export-row" role="group" aria-label="Exportar reportes">
    <a class="btn btn--primary" href="<?= e(url('/reports/print')) ?>" target="_blank" rel="noopener"><?= icon('doc') ?> Informe (imprimir o PDF)</a>
    <a class="btn" href="<?= e(url('/reports/projects.csv')) ?>" download><?= icon('download') ?> Proyectos (CSV)</a>
    <a class="btn" href="<?= e(url('/reports/tasks.csv')) ?>" download><?= icon('download') ?> Tareas (CSV)</a>
    <a class="btn" href="<?= e(url('/reports/hours.csv')) ?>" download><?= icon('download') ?> Horas (CSV)</a>
  </div></div>
<div class="grid grid-4 stats mb">
  <div class="card stat"><span class="label"><?= icon('folder', 'sm') ?> Proyectos</span><span class="value"><?= (int)$t['projects'] ?></span></div>
  <div class="card stat"><span class="label"><?= icon('circle', 'sm') ?> Tareas abiertas</span><span class="value"><?= (int)$t['open'] ?></span></div>
  <div class="card stat<?= $t['overdue'] ? ' danger' : '' ?>"><span class="label"><?= icon('alert', 'sm') ?> Vencidas</span><span class="value"><?= (int)$t['overdue'] ?></span></div>
  <div class="card stat<?= $t['blocked'] ? ' warn' : '' ?>"><span class="label"><?= icon('block', 'sm') ?> Bloqueadas</span><span class="value"><?= (int)$t['blocked'] ?></span></div>
</div>
<section class="card mb">
  <div class="card-head"><h3><?= icon('target') ?> Progreso por proyecto</h3></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Proyecto</th><th class="hide-sm">Equipo</th><th>Progreso</th><th>Abiertas</th><th class="hide-sm">Vencidas</th><th class="hide-sm">Bloqueadas</th><th class="hide-md">Entrega</th></tr></thead>
    <tbody>
      <?php foreach ($r['projects'] as $p): ?>
        <tr><td><a href="<?= e(url('/projects/' . $p['id'])) ?>" style="font-weight:600"><?= e($p['name']) ?></a> <span class="key"><?= e($p['project_key']) ?></span></td>
          <td class="hide-sm"><?= e($p['team_name'] ?? '—') ?></td>
          <td style="min-width:160px"><div class="row"><div class="progress" style="flex:1"><span style="--p:<?= (int)$p['progress'] ?>%"></span></div><span class="small" style="width:36px;text-align:right"><?= (int)$p['progress'] ?>%</span></div></td>
          <td><?= (int)$p['total'] - (int)$p['done'] ?></td>
          <td class="hide-sm"><?= $p['overdue'] ? '<span class="badge danger">' . (int)$p['overdue'] . '</span>' : '0' ?></td>
          <td class="hide-sm"><?= $p['blocked'] ? '<span class="badge warn">' . (int)$p['blocked'] . '</span>' : '0' ?></td>
          <td class="hide-md"><?= $p['due_date'] ? '<span class="due ' . due_state($p['due_date'], $p['state'] === 'completed') . '">' . e(fmt_date($p['due_date'], true)) . '</span>' : '—' ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$r['projects']): ?><tr><td colspan="7" class="empty">No hay proyectos activos.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>
<div class="grid grid-2">
  <section class="card"><div class="card-head"><h3><?= icon('alert') ?> Vencidas</h3></div><ul class="list">
    <?php foreach ($r['overdue'] as $x): ?><li><span class="key"><?= e($x['task_key']) ?></span><button class="task-title truncate" style="flex:1" data-open-task="<?= e($x['task_key']) ?>"><?= e($x['title']) ?></button><span class="muted small hide-sm"><?= e($x['assignee_name'] ?? 'Sin responsable') ?></span><span class="due overdue"><?= e(fmt_date($x['due_date'])) ?></span></li><?php endforeach; ?>
    <?php if (!$r['overdue']): ?><li class="empty">Nada vencido.</li><?php endif; ?></ul></section>
  <section class="card"><div class="card-head"><h3><?= icon('block') ?> Bloqueadas</h3></div><ul class="list">
    <?php foreach ($r['blocked'] as $x): ?><li><span class="key"><?= e($x['task_key']) ?></span><button class="task-title truncate" style="flex:1" data-open-task="<?= e($x['task_key']) ?>" title="<?= e($x['blocked_reason']) ?>"><?= e($x['title']) ?></button><span class="muted small hide-sm"><?= e($x['project_name']) ?></span></li><?php endforeach; ?>
    <?php if (!$r['blocked']): ?><li class="empty">Sin bloqueos.</li><?php endif; ?></ul></section>
</div>
<?php $hTot = array_sum(array_map(fn($x) => (int)$x['minutes'], $r['hours_people'])); ?>
<section class="card mt">
  <div class="card-head"><h3><?= icon('clock') ?> Horas por persona</h3><span class="muted small">Total <?= e(\Borealis\Services\TimeEntries::fmt($hTot)) ?></span></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Persona</th><th>Proyectos</th><th>Tareas</th><th>Horas</th></tr></thead>
    <tbody><?php foreach ($r['hours_people'] as $h): ?><tr><td><span class="person"><?= avatar($h, 'sm') ?> <?= e($h['name']) ?></span></td><td><?= (int)$h['projects'] ?></td><td><?= (int)$h['tasks'] ?></td><td><?= e(\Borealis\Services\TimeEntries::fmt((int)$h['minutes'])) ?></td></tr><?php endforeach; ?>
    <?php if (!$r['hours_people']): ?><tr><td colspan="4" class="empty">Todavía no hay horas registradas.</td></tr><?php endif; ?></tbody>
  </table></div>
</section>
