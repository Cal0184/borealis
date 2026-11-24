<?php
use Borealis\Services\TimeEntries;
$r = $report; $p = $r['progress'];
$total = array_sum(array_column($r['by_status'], 'n'));
$hoursTotal = array_sum(array_map(fn($x) => (int)$x['minutes'], $r['hours_people']));
?>
<header class="doc-head">
  <p class="eyebrow">Informe de proyecto · <?= e(fmt_date(today(), true)) ?></p>
  <h1><?= e($project['name']) ?> <span class="key"><?= e($project['project_key']) ?></span></h1>
  <p class="meta"><?= $project['team_name'] ? 'Equipo: ' . e($project['team_name']) . ' · ' : '' ?><?= $project['due_date'] ? 'Entrega ' . e(fmt_date($project['due_date'], true)) . ' · ' : '' ?><?= $project['archived_at'] ? 'Archivado' : 'Activo' ?></p>
  <?php if (!empty($project['description'])): ?><p><?= e(mb_strimwidth(strip_tags((string)$project['description']), 0, 400, '…')) ?></p><?php endif; ?>
</header>

<section class="kpis">
  <div><span><?= (int)$p['progress'] ?>%</span>Progreso</div>
  <div><span><?= (int)$p['total'] - (int)$p['done'] ?></span>Abiertas</div>
  <div><span><?= (int)$p['done'] ?></span>Finalizadas</div>
  <div><span><?= count($r['overdue']) ?></span>Vencidas</div>
  <div><span><?= count($r['blocked']) ?></span>Bloqueadas</div>
  <div><span><?= e(TimeEntries::fmt($hoursTotal)) ?></span>Horas registradas</div>
</section>

<h2>Tareas por estado</h2>
<table><thead><tr><th>Estado</th><th class="num">Tareas</th><th class="num">%</th></tr></thead><tbody>
<?php foreach ($r['by_status'] as $s): ?><tr><td><span class="dot" style="background:<?= e($s['color']) ?>"></span><?= e($s['name']) ?></td><td class="num"><?= (int)$s['n'] ?></td><td class="num"><?= pct((int)$s['n'], max(1, $total)) ?>%</td></tr><?php endforeach; ?>
</tbody></table>

<h2>Horas por persona</h2>
<?php if ($r['hours_people']): ?>
<table><thead><tr><th>Persona</th><th class="num">Tareas</th><th class="num">Horas</th></tr></thead><tbody>
<?php foreach ($r['hours_people'] as $h): ?><tr><td><?= e($h['name']) ?></td><td class="num"><?= (int)$h['tasks'] ?></td><td class="num"><?= e(TimeEntries::fmt((int)$h['minutes'])) ?></td></tr><?php endforeach; ?>
</tbody><tfoot><tr><th>Total</th><th></th><th class="num"><?= e(TimeEntries::fmt($hoursTotal)) ?></th></tr></tfoot></table>
<?php else: ?><p class="muted">Todavía no hay horas registradas.</p><?php endif; ?>

<?php if ($r['hours_tasks']): ?>
<h2>Tareas con más horas</h2>
<table><thead><tr><th>Clave</th><th>Tarea</th><th class="num">Estimadas</th><th class="num">Registradas</th><th class="num">Personas</th></tr></thead><tbody>
<?php foreach ($r['hours_tasks'] as $t): ?><tr><td class="mono"><?= e($t['task_key']) ?></td><td><?= e($t['title']) ?></td><td class="num"><?= $t['estimate_hours'] !== null ? e(rtrim(rtrim(number_format((float)$t['estimate_hours'], 1, ',', ''), '0'), ',')) . ' h' : '—' ?></td><td class="num"><?= e(TimeEntries::fmt((int)$t['minutes'])) ?></td><td class="num"><?= (int)$t['people'] ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php endif; ?>

<h2>Trabajo abierto por responsable</h2>
<table><thead><tr><th>Responsable</th><th class="num">Abiertas</th><th class="num">Vencidas</th><th class="num">Bloqueadas</th></tr></thead><tbody>
<?php foreach ($r['workload'] as $w): ?><tr><td><?= e($w['name'] ?? 'Sin responsable') ?></td><td class="num"><?= (int)$w['open_tasks'] ?></td><td class="num"><?= (int)$w['overdue'] ?></td><td class="num"><?= (int)$w['blocked'] ?></td></tr><?php endforeach; ?>
<?php if (!$r['workload']): ?><tr><td colspan="4" class="muted">No hay tareas abiertas.</td></tr><?php endif; ?>
</tbody></table>

<h2>Ritmo de las últimas 8 semanas</h2>
<table><thead><tr><th>Semana del</th><th class="num">Creadas</th><th class="num">Finalizadas</th></tr></thead><tbody>
<?php foreach ($r['weeks'] as $w): ?><tr><td><?= e(fmt_date($w['week'], true)) ?></td><td class="num"><?= (int)$w['created'] ?></td><td class="num"><?= (int)$w['completed'] ?></td></tr><?php endforeach; ?>
</tbody></table>

<?php if ($r['overdue'] || $r['blocked']): ?>
<h2>Vencidas y bloqueadas</h2>
<table><thead><tr><th>Clave</th><th>Tarea</th><th>Responsable</th><th>Situación</th></tr></thead><tbody>
<?php foreach ($r['overdue'] as $t): ?><tr><td class="mono"><?= e($t['task_key']) ?></td><td><?= e($t['title']) ?></td><td><?= e($t['assignee_name'] ?? '—') ?></td><td>Vencida el <?= e(fmt_date($t['due_date'], true)) ?></td></tr><?php endforeach; ?>
<?php foreach ($r['blocked'] as $t): ?><tr><td class="mono"><?= e($t['task_key']) ?></td><td><?= e($t['title']) ?></td><td><?= e($t['assignee_name'] ?? '—') ?></td><td>Bloqueada<?= $t['blocked_reason'] ? ': ' . e($t['blocked_reason']) : '' ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php endif; ?>
