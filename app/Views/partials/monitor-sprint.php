<?php
/** One sprint's commitment, progress, hours and risks (Monitoreo 1.12). $sp: Monitoring::sprintSummary(), $h: hours formatter. */
$s = $sp['sprint']; $c = $sp['counts']; $active = $s['state'] === 'active';
$total = $active ? $c['done'] + $c['pending'] : $c['done'] + $c['moved'] + $c['backlog'];
$pct = $total ? (int)round($c['done'] / $total * 100) : 0;
?>
<article class="card mon-sprint" data-sprint="<?= (int)$s['id'] ?>">
  <div class="card-head"><h4 style="margin:0"><?= icon('zap', 'sm') ?> <a href="<?= e(url('/projects/' . $s['project_id'] . '/sprints')) ?>"><?= e($s['name']) ?></a> <span class="muted small"><?= e($sp['project']['name'] ?? '') ?></span></h4>
    <span class="badge<?= $active ? ' info' : '' ?>"><?= $active ? 'Activo' : 'Cerrado' ?></span></div>
  <?php if (!empty($s['goal'])): ?><p class="small" style="margin:4px 0"><strong>Objetivo:</strong> <?= e($s['goal']) ?></p><?php endif; ?>
  <p class="muted small" style="margin:4px 0"><?= $s['start_date'] ? e(fmt_date($s['start_date'], true)) : '¿?' ?> → <?= $s['end_date'] ? e(fmt_date($s['end_date'], true)) : '¿?' ?></p>
  <span class="load-bar mon-bar" aria-hidden="true"><span style="--p:<?= $pct ?>%"></span></span>
  <ul class="mon-sprint-counts small">
    <li><b><?= (int)$c['planned'] ?></b> planificadas al inicio</li>
    <li><b><?= (int)$c['added'] ?></b> añadidas después</li>
    <?php if ($c['unknown']): ?><li title="Sprint iniciado antes de 1.12: no se sabe qué entró al inicio"><b><?= (int)$c['unknown'] ?></b> sin dato de origen</li><?php endif; ?>
    <li><b><?= (int)$c['done'] ?></b> completadas (<?= $pct ?> %)</li>
    <?php if ($active): ?><li><b><?= (int)$c['pending'] ?></b> pendientes</li>
    <?php else: ?><li><b><?= (int)$c['moved'] ?></b> movidas a otro sprint</li><li><b><?= (int)$c['backlog'] ?></b> devueltas al backlog</li><?php endif; ?>
    <?php if ($c['removed']): ?><li><b><?= (int)$c['removed'] ?></b> quitadas del sprint</li><?php endif; ?>
    <?php if ($c['blocked']): ?><li class="dev-over"><b><?= (int)$c['blocked'] ?></b> bloqueadas</li><?php endif; ?>
    <li>Horas: <?= e($h((float)$c['estimate'])) ?> estimadas / <?= e($h((float)$c['logged'])) ?> registradas</li>
  </ul>
  <?php if ($c['over']): ?><p class="small dev-over" style="margin:4px 0"><?= count($c['over']) ?> con desviación alta: <?= e(implode(', ', array_map(fn($o) => $o['title'] . ' (+' . (int)$o['dev']['pct'] . ' %)', array_slice($c['over'], 0, 3)))) ?></p><?php endif; ?>
  <?php foreach ($sp['risks'] as $rk): ?><p class="small" style="margin:2px 0"><?= icon('alert', 'sm') ?> <?= e($rk) ?></p><?php endforeach; ?>
</article>
