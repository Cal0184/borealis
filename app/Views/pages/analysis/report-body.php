<?php
/**
 * Executive report (1.13), shared by Análisis › Informes and the print view.
 * Needs in scope: $d (report data), $f (filters), and the closures of partials/analysis-bits.php.
 */
use Borealis\Services\Analysis\Analysis;
use Borealis\Services\Analysis\Metrics;

$i = $d['ind'];
$highP = array_filter($d['projects'], fn($p) => $p['level'] === 'high');
$modP = array_filter($d['projects'], fn($p) => $p['level'] === 'moderate');
$riskS = array_filter($d['sprints'], fn($s) => in_array($s['eval']['level'], ['high', 'moderate'], true));
$overWeeks = array_filter($d['cap']['weeks'], fn($w) => $w['gap'] !== null && $w['gap'] > 0.05);
?>
<section class="an-report" data-report>
  <h2>1. Resumen general</h2>
  <p>Período: <strong><?= e(fmt_date($f['from'], true)) ?> – <?= e(fmt_date($f['to'], true)) ?></strong>. <?= count($d['projects']) ?> <?= count($d['projects']) === 1 ? 'proyecto analizado' : 'proyectos analizados' ?>, <?= (int)$i['finished'] ?> tareas finalizadas en el período.</p>
  <p><?php if ($highP): ?><?= count($highP) ?> con riesgo elevado (<?= e(implode(', ', array_map(fn($p) => $p['project']['name'], $highP))) ?>)<?= $modP ? ' y ' . count($modP) . ' con riesgo moderado' : '' ?>.<?php elseif ($modP): ?><?= count($modP) ?> con riesgo moderado y ninguno elevado.<?php else: ?>Ningún proyecto presenta riesgo moderado ni elevado según las reglas configuradas.<?php endif; ?>
    <?= $overWeeks ? 'En ' . count($overWeeks) . (count($overWeeks) === 1 ? ' de las próximas semanas' : ' de las próximas semanas') . ' la carga planificada supera la capacidad.' : 'En las próximas semanas la carga planificada no supera la capacidad configurada.' ?></p>

  <h2>2. Cumplimiento de compromisos</h2>
  <?php if ($i['ontime']['den']): ?>
    <p><?= $an_tag('fact') ?> <strong><?= e($an_pct($i['ontime']['value'])) ?></strong> de cumplimiento de plazos: <?= (int)$i['ontime']['num'] ?> de <?= (int)$i['ontime']['den'] ?> tareas finalizadas con fecha límite válida se completaron a tiempo; <?= (int)$i['late']['num'] ?> fuera de plazo. <?= (int)$i['ontime']['excluded'] ?> finalizadas sin fecha límite registrada quedan fuera.</p>
    <?php if (!$i['enough']): ?><p class="muted small">Con menos de <?= (int)$i['min_sample'] ?> tareas el porcentaje es poco representativo.</p><?php endif; ?>
  <?php else: ?><p class="muted">No hay tareas finalizadas con fecha límite en el período: no se puede calcular el cumplimiento.</p><?php endif; ?>
  <?php $sp = array_filter($i['sprints'], fn($s) => $s['source'] !== 'none'); if ($sp): ?>
    <p>Sprints cerrados: <?= implode('; ', array_map(fn($s) => e($s['name']) . ' ' . e($an_pct($s['rate']['value'])) . ' (' . $s['rate']['num'] . '/' . $s['rate']['den'] . ($s['source'] === 'close' ? ', aproximado' : '') . ')', $sp)) ?>.</p>
  <?php endif; ?>

  <h2>3. Evolución del proyecto</h2>
  <?= $an_trend($d['trend']['ontime'], true) ?>
  <?php $bl = $i['backlog']; if (count($bl) >= 2): $a = $bl[0]; $b = end($bl); ?>
    <p><?= $an_tag('fact') ?> Trabajo pendiente: <?= (int)$a['pending'] ?> tareas al final de la primera semana del período y <?= (int)$b['pending'] ?> al final de la última (<?= $b['pending'] - $a['pending'] >= 0 ? '+' : '−' ?><?= abs($b['pending'] - $a['pending']) ?>).</p>
  <?php endif; ?>

  <h2>4. Carga planificada</h2>
  <div class="table-wrap"><table class="table an-table"><thead><tr><th>Semana</th><th>Capacidad</th><th>Planificado</th><th>Diferencia</th></tr></thead><tbody>
    <?php foreach ($d['cap']['weeks'] as $w): ?><tr><td><?= e(fmt_date($w['week'], true)) ?></td><td><?= e($an_h($w['capacity'])) ?></td><td><?= e($an_h($w['planned'])) ?></td><td><?= $w['gap'] === null ? '—' : e($an_h($w['gap'], true)) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php foreach ($overWeeks as $w): ?><p class="small"><?= $an_tag('fact') ?> Semana del <?= e(fmt_date($w['week'], true)) ?>: <?= e($w['message']) ?></p><?php endforeach; ?>
  <?php if ($d['cap']['no_estimate']): ?><p class="muted small"><?= count($d['cap']['no_estimate']) ?> tareas abiertas sin estimación no suman horas.</p><?php endif; ?>

  <h2>5. Desviaciones</h2>
  <?php if ($i['deviation']['n']): ?>
    <p><?= $an_tag('fact') ?> Mediana de desviación: <strong><?= e($an_pct($i['deviation']['median_pct'], true)) ?></strong> (<?= e($an_h($i['deviation']['median_hours'], true)) ?> por tarea) en <?= (int)$i['deviation']['n'] ?> tareas con horas estimadas y registradas.<?= $i['deviation']['enough'] ? '' : ' Muestra pequeña: tómalo como orientativo.' ?></p>
    <?php if ($d['deviations']): ?><ul class="small"><?php foreach (array_slice($d['deviations'], 0, 5) as $x): ?><li><?= e($x['key']) ?> · <?= e($x['title']) ?>: <?= e($an_h($x['dev']['estimate'])) ?> estimadas, <?= e($an_h($x['dev']['logged'])) ?> registradas (<?= e($an_h($x['dev']['hours'], true)) ?>)</li><?php endforeach; ?></ul><?php endif; ?>
  <?php else: ?><p class="muted">No hay tareas finalizadas con horas estimadas y registradas en el período.</p><?php endif; ?>

  <h2>6. Bloqueos relevantes</h2>
  <?php if ($i['blocks']['open']): ?><ul class="small"><?php foreach (array_slice($i['blocks']['open'], 0, 8) as $b): ?><li><?= e($b['key']) ?> · <?= e($b['title']) ?><?= $b['days'] !== null ? ' — bloqueada hace ' . (int)$b['days'] . ' días' : '' ?><?= $b['blocked_reason'] ? ': ' . e($b['blocked_reason']) : '' ?></li><?php endforeach; ?></ul>
  <?php else: ?><p class="muted">No hay tareas bloqueadas.</p><?php endif; ?>
  <?php if ($i['blocks']['closed']): ?><p class="small"><?= $an_tag('fact') ?> <?= (int)$i['blocks']['closed'] ?> bloqueos resueltos en el período, con una duración mediana de <?= e(str_replace('.', ',', (string)$i['blocks']['median_days'])) ?> días.</p><?php endif; ?>

  <h2>7. Riesgos</h2>
  <ul class="list an-risks">
    <?php foreach ($d['projects'] as $p) if ($p['level'] !== 'low') echo $an_riskItem($p['project']['name'], null, $p); ?>
    <?php foreach ($riskS as $s) echo $an_riskItem($s['sprint']['project_name'] . ' · ' . $s['sprint']['name'], null, $s['eval']); ?>
    <?php if (!array_filter($d['projects'], fn($p) => $p['level'] !== 'low') && !$riskS): ?><li class="empty">Sin riesgos moderados ni elevados.</li><?php endif; ?>
  </ul>

  <h2>8. Recomendaciones</h2>
  <?php if ($d['recs']): ?><ul class="an-recs"><?php foreach (array_slice($d['recs'], 0, 8) as $r) echo $an_rec($r); ?></ul>
  <?php else: ?><p class="muted">Sin recomendaciones: ninguna regla detecta un problema con los datos disponibles.</p><?php endif; ?>

  <h2>9. Decisiones pendientes</h2>
  <?php if ($d['pending']): ?><ul class="small"><?php foreach ($d['pending'] as $p): ?><li><?= e($p['text']) ?></li><?php endforeach; ?></ul>
  <?php else: ?><p class="muted">No hay alertas de capacidad abiertas ni alertas críticas sin revisar.</p><?php endif; ?>
  <p class="muted small">Las conclusiones se basan en reglas y en los registros de Borealis. Las asociaciones no prueban causas y ningún indicador evalúa a personas. Borealis recomienda; el equipo decide.</p>
</section>
