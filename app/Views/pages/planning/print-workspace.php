<?php
use Borealis\Services\TimeEntries;
$r = $report; $t = $r['totals'];
$ws = \Borealis\Security\Policy::workspace();
$hoursTotal = array_sum(array_map(fn($x) => (int)$x['minutes'], $r['hours_people']));
?>
<header class="doc-head">
  <p class="eyebrow">Informe del espacio de trabajo · <?= e(fmt_date(today(), true)) ?></p>
  <h1><?= e($ws['name'] ?? 'Espacio de trabajo') ?></h1>
  <p class="meta">Incluye los proyectos activos que puedes ver.</p>
</header>
<section class="kpis">
  <div><span><?= (int)$t['projects'] ?></span>Proyectos</div>
  <div><span><?= (int)$t['open'] ?></span>Tareas abiertas</div>
  <div><span><?= (int)$t['done'] ?></span>Finalizadas</div>
  <div><span><?= (int)$t['overdue'] ?></span>Vencidas</div>
  <div><span><?= (int)$t['blocked'] ?></span>Bloqueadas</div>
  <div><span><?= e(TimeEntries::fmt($hoursTotal)) ?></span>Horas registradas</div>
</section>
<h2>Proyectos</h2>
<table><thead><tr><th>Proyecto</th><th>Equipo</th><th class="num">Progreso</th><th class="num">Abiertas</th><th class="num">Vencidas</th><th class="num">Bloqueadas</th><th>Entrega</th></tr></thead><tbody>
<?php foreach ($r['projects'] as $p): ?><tr><td><?= e($p['name']) ?> <span class="mono muted"><?= e($p['project_key']) ?></span></td><td><?= e($p['team_name'] ?? '—') ?></td><td class="num"><?= (int)$p['progress'] ?>%</td><td class="num"><?= (int)$p['total'] - (int)$p['done'] ?></td><td class="num"><?= (int)$p['overdue'] ?></td><td class="num"><?= (int)$p['blocked'] ?></td><td><?= $p['due_date'] ? e(fmt_date($p['due_date'], true)) : '—' ?></td></tr><?php endforeach; ?>
<?php if (!$r['projects']): ?><tr><td colspan="7" class="muted">No hay proyectos activos.</td></tr><?php endif; ?>
</tbody></table>
<h2>Horas por persona</h2>
<?php if ($r['hours_people']): ?>
<table><thead><tr><th>Persona</th><th class="num">Proyectos</th><th class="num">Tareas</th><th class="num">Horas</th></tr></thead><tbody>
<?php foreach ($r['hours_people'] as $h): ?><tr><td><?= e($h['name']) ?></td><td class="num"><?= (int)$h['projects'] ?></td><td class="num"><?= (int)$h['tasks'] ?></td><td class="num"><?= e(TimeEntries::fmt((int)$h['minutes'])) ?></td></tr><?php endforeach; ?>
</tbody><tfoot><tr><th>Total</th><th></th><th></th><th class="num"><?= e(TimeEntries::fmt($hoursTotal)) ?></th></tr></tfoot></table>
<?php else: ?><p class="muted">Todavía no hay horas registradas.</p><?php endif; ?>
<?php if ($r['overdue']): ?>
<h2>Tareas vencidas</h2>
<table><thead><tr><th>Clave</th><th>Tarea</th><th>Responsable</th><th>Vencimiento</th></tr></thead><tbody>
<?php foreach ($r['overdue'] as $x): ?><tr><td class="mono"><?= e($x['task_key']) ?></td><td><?= e($x['title']) ?></td><td><?= e($x['assignee_name'] ?? '—') ?></td><td><?= e(fmt_date($x['due_date'], true)) ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php endif; ?>
<?php if ($r['blocked']): ?>
<h2>Tareas bloqueadas</h2>
<table><thead><tr><th>Clave</th><th>Tarea</th><th>Responsable</th><th>Motivo</th></tr></thead><tbody>
<?php foreach ($r['blocked'] as $x): ?><tr><td class="mono"><?= e($x['task_key']) ?></td><td><?= e($x['title']) ?></td><td><?= e($x['assignee_name'] ?? '—') ?></td><td><?= e($x['blocked_reason'] ?? '') ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php endif; ?>
