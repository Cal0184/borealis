<?php
use Borealis\Services\Monitoring;

/**
 * Planned load against weekly capacity (Monitoreo 1.12).
 * $wl: Monitoring::workload(). $link: tab URL builder. $compact: first week only. $focus: user id to expand.
 */
$compact = $compact ?? false;
$focus = $focus ?? null;
$h = fn(float $x, bool $s = false) => Monitoring::fmtHours($x, $s);
$weeks = $compact ? array_slice($wl['weeks'], 0, 1) : $wl['weeks'];
$people = $wl['people'];
if ($compact) $people = array_values(array_filter($people, fn($r) => $r['user']['weekly_hours'] !== null && (float)$r['user']['weekly_hours'] > 0 || $r['weeks'][$weeks[0]]['planned'] > 0 || $r['notes'])); // the summary skips people with nothing to show
if ($focus) $people = array_values(array_filter($people, fn($r) => (int)$r['user']['id'] === (int)$focus)) ?: $wl['people'];
?>
<?php if (!$people): ?>
  <p class="empty">No hay personas visibles con trabajo en tus proyectos.</p>
<?php else: ?>
<ul class="list mon-load">
  <?php foreach ($people as $r): $u = $r['user']; $notes = $r['notes']; ?>
  <li class="mon-person" data-user="<?= (int)$u['id'] ?>">
    <div class="row mon-person-head"><?= avatar($u, 'sm') ?> <a href="<?= e($link('workload', ['user' => $u['id']])) ?>" style="font-weight:600"><?= e($u['name']) ?></a>
      <?php if ($u['weekly_hours'] === null || (float)$u['weekly_hours'] <= 0): ?><span class="muted small">sin horas semanales definidas</span><?php endif; ?></div>
    <div class="mon-weeks">
    <?php foreach ($weeks as $w): $c = $r['weeks'][$w]; $pct = $c['pct'] ?? 0; ?>
      <div class="mon-cell" data-week="<?= e($w) ?>" data-class="<?= e($c['class']) ?>">
        <?php if (!$compact): ?><span class="muted small">Sem. <?= e(fmt_date($w)) ?></span><?php endif; ?>
        <span class="load-bar mon-bar<?= $c['class'] === 'over' ? ' over' : ($c['class'] === 'high' ? ' high' : '') ?>" aria-hidden="true"><span style="--p:<?= min(100, max(0, (int)$pct)) ?>%"></span></span>
        <span class="small"><b><?= e($h($c['planned'])) ?></b><?= $c['capacity'] !== null ? ' de ' . e($h($c['capacity'])) . ' · ' . (int)$pct . ' %' : '' ?></span>
        <span class="badge <?= Monitoring::LOAD[$c['class']][1] ?>"><?= e(Monitoring::LOAD[$c['class']][0]) ?></span>
        <?php if ($c['over'] !== null && $c['over'] > 0): ?><span class="small dev-over"><?= e($h($c['over'])) ?> por encima</span><?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
    <?php $bits = [];
      if (!empty($notes['overdue'])) $bits[] = count($notes['overdue']) . ' vencida(s) cargada(s) en la semana actual';
      if (!empty($notes['no_estimate'])) $bits[] = count($notes['no_estimate']) . ' sin estimación (' . implode(', ', array_slice($notes['no_estimate'], 0, 5)) . ')';
      if (!empty($notes['no_date'])) $bits[] = count($notes['no_date']) . ' sin fecha (' . $h((float)($notes['no_date_hours'] ?? 0)) . ' sin repartir)'; ?>
    <?php if ($bits): ?><div class="muted small"><?= e(implode(' · ', $bits)) ?></div><?php endif; ?>
    <?php if (!$compact && ($focus || count($people) === 1)): ?>
      <details class="mon-breakdown" open><summary class="small">Tareas que forman la carga</summary>
        <?php foreach ($weeks as $w): $c = $r['weeks'][$w]; if (!$c['tasks']) continue; ?>
          <div class="small" style="margin-top:6px"><strong>Semana del <?= e(fmt_date($w, true)) ?></strong></div>
          <ul class="list"><?php foreach ($c['tasks'] as $t): ?><li class="small"><?php if ($t['project_id']): ?><span class="key"><?= e($t['key']) ?></span><button class="task-title truncate" style="flex:1" type="button" data-open-task="<?= e($t['key']) ?>"><?= e($t['title']) ?></button><?php else: ?><span class="muted" style="flex:1">Trabajo en un proyecto que no ves</span><?php endif; ?><span><?= e($h($t['hours'])) ?></span></li><?php endforeach; ?></ul>
        <?php endforeach; ?>
      </details>
    <?php endif; ?>
  </li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>
