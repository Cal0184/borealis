<?php
/** Collective progress drawn as a trail with camps at 25/50/75/100 %. @var int $percent @var string $label */
$p = max(0, min(100, (int)$percent));
$len = 1000;
$off = $len - ($len * $p / 100);
$camps = [0, 25, 50, 75, 100];
// Gentle path across the width; camps placed on it by percentage.
$pts = fn($t) => [20 + $t * 9.6, 40 - sin($t / 100 * M_PI * 2) * 14];
?>
<div class="expedition" role="img" aria-label="<?= e($label ?? 'Progreso') ?>: <?= $p ?>%">
  <svg viewBox="0 0 1000 64" aria-hidden="true">
    <?php $d = 'M' . implode(' L', array_map(fn($t) => implode(',', array_map(fn($v) => round($v, 1), $pts($t))), range(0, 100, 2))); ?>
    <path class="trail-bg" d="<?= $d ?>" />
    <path class="trail" d="<?= $d ?>" pathLength="1000" stroke-dasharray="1000" stroke-dashoffset="<?= $off ?>" />
    <?php foreach ($camps as $c): [$x, $y] = $pts($c); ?>
      <circle class="camp<?= $p >= $c ? ' reached' : '' ?>" cx="<?= round($x, 1) ?>" cy="<?= round($y, 1) ?>" r="7" />
    <?php endforeach; ?>
  </svg>
</div>
