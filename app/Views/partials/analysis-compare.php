<?php
/** «Estado actual» frente a «Escenario simulado» (1.13). @var array $res result of Scenarios::simulate */
use Borealis\Services\Analysis\Analysis;
use Borealis\Services\Analysis\Metrics;

$a = $res['current']; $b = $res['scenario'];
$h = fn($x, bool $s = false) => Metrics::h($x, $s);
$diff = fn($x, $y) => $x === null || $y === null ? '—' : Metrics::h(round($y - $x, 2), true);
$risk = fn(string $k) => '<span class="badge ' . Analysis::RISK[$k][1] . '">' . e(Analysis::RISK[$k][0]) . '</span>';
$rows = [
    ['Trabajo pendiente estimado', $h($a['pending']), $h($b['pending']), $diff($a['pending'], $b['pending'])],
    ['Capacidad restante', $h($a['capacity_left']), $h($b['capacity_left']), $diff($a['capacity_left'], $b['capacity_left'])],
    ['Margen (capacidad − pendiente)', $h($a['margin'], true), $h($b['margin'], true), $diff($a['margin'], $b['margin'])],
    ['Tareas abiertas en el sprint', (string)$a['open'], (string)$b['open'], (string)($b['open'] - $a['open'])],
    ['Sin estimación', (string)$a['unestimated'], (string)$b['unestimated'], (string)($b['unestimated'] - $a['unestimated'])],
    ['Bloqueadas', (string)$a['blocked'], (string)$b['blocked'], (string)($b['blocked'] - $a['blocked'])],
    ['Vencen después del fin del sprint', (string)$a['due_after_end'], (string)$b['due_after_end'], (string)($b['due_after_end'] - $a['due_after_end'])],
];
$changed = $res['preview'];
?>
<div class="card-head"><h3>Estado actual frente a escenario simulado</h3></div>
<div class="table-wrap"><table class="table an-table an-compare" data-compare>
  <thead><tr><th></th><th>Estado actual</th><th>Escenario simulado</th><th>Diferencia</th></tr></thead>
  <tbody>
    <tr data-row="risk"><td>Riesgo</td><td><?= $risk($a['level']) ?></td><td data-sim-level="<?= e($b['level']) ?>"><?= $risk($b['level']) ?></td><td><?= (int)$b['points'] - (int)$a['points'] ?> puntos</td></tr>
    <?php foreach ($rows as $i => [$label, $x, $y, $d]): ?><tr data-row="<?= $i ?>"><td><?= e($label) ?></td><td><?= e($x) ?></td><td><strong><?= e($y) ?></strong></td><td><?= e($d) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
<div class="card-body">
  <p class="small"><strong>Actual:</strong> <?= e($a['sentence']) ?></p>
  <p class="small" data-sim-sentence><strong>Simulado:</strong> <?= e($b['sentence']) ?></p>
  <?php $over = array_filter($b['people'], fn($p) => $p['over']); if ($over): ?><p class="small an-warning"><?= icon('alert', 'sm') ?> En el escenario, <?= e(implode(', ', array_map(fn($p) => $p['name'] . ' (' . Metrics::h($p['load']) . ' de ' . Metrics::h($p['capacity']) . ')', $over))) ?> <?= count($over) === 1 ? 'tendría' : 'tendrían' ?> más horas que disponibles.</p><?php endif; ?>
  <?php foreach ($res['next_sprints'] as $n): ?><p class="small muted">«<?= e($n['name']) ?>» recibiría <?= e(Metrics::h($n['hours'])) ?> pendientes: revisa también su capacidad.</p><?php endforeach; ?>
  <?php foreach ($res['notes'] as $n): ?><p class="small muted"><?= e($n) ?></p><?php endforeach; ?>
  <details class="an-why"><summary>Supuestos del cálculo</summary><ul class="small"><?php foreach ($res['assumptions'] as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul></details>
  <?php if ($changed): ?>
  <div class="an-apply" data-sim-apply>
    <h4 class="small">Vista previa de los cambios</h4>
    <ul class="small"><?php foreach ($changed as $c): ?><li<?= $c['applies'] ? '' : ' class="muted"' ?>><?= e($c['text']) ?></li><?php endforeach; ?></ul>
    <?php if (array_filter($changed, fn($c) => $c['applies'])): ?>
      <label class="check small"><input type="checkbox" data-sim-confirm> Entiendo que estos cambios se guardarán en las tareas y en el sprint, con las mismas reglas y avisos que al hacerlos a mano.</label>
      <div class="row mt"><button class="btn btn--primary" type="button" data-sim-apply-btn disabled>Aplicar escenario</button><span class="muted small">Se comprobará que nadie haya cambiado los datos desde la simulación. Podrás revertirlo.</span></div>
    <?php endif; ?>
  </div>
  <?php else: ?><p class="muted small">Sin cambios: este es el estado actual del sprint.</p><?php endif; ?>
</div>
