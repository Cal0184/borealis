<?php
/**
 * Compact workload indicator: "15 / 40 h · 25 h libres" with a thin bar.
 * $p: a row from Capacity::people(). $compact drops the text after the hours.
 */
$n = fn(float $x): string => rtrim(rtrim(number_format($x, 1, ',', ''), '0'), ',');
$cap = $p['capacity'];
$pct = $cap ? min(100, (int)round($p['load'] / $cap * 100)) : 0;
$state = $cap === null ? 'none' : ($p['over'] ? 'over' : ($p['full'] ? 'full' : ($pct >= 80 ? 'high' : 'ok')));
$compact = $compact ?? false;
?>
<span class="load-meter is-<?= $state ?>" title="<?= e($p['name']) ?>: <?= $cap !== null ? e($n($p['load']) . ' de ' . $n((float)$cap) . ' h') : e($n($p['load']) . ' h asignadas, sin límite definido') ?><?= $p['pending'] ? e(' · ' . $p['pending'] . ' sin estimar') : '' ?>">
  <span class="load-text"><b><?= e($n($p['load'])) ?></b><?= $cap !== null ? ' / ' . e($n((float)$cap)) : '' ?> h<?php if (!$compact): ?><span class="muted"> · <?= $cap === null ? 'sin límite' : ($p['over'] ? e($n($p['load'] - $cap)) . ' h de más' : ($p['full'] ? 'sin disponibilidad' : e($n((float)$p['free'])) . ' h libres')) ?></span><?php endif; ?></span>
  <?php if ($cap !== null): ?><span class="load-bar" aria-hidden="true"><span style="width:<?= $pct ?>%"></span></span><?php endif; ?>
  <?php if ($p['pending'] && !$compact): ?><span class="load-pending"><?= (int)$p['pending'] ?> sin estimar</span><?php endif; ?>
</span>
