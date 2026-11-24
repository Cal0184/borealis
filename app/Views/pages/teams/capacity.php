<?php
use Borealis\Services\CapacityAlerts;
use Borealis\Services\TimeEntries;
?>
<div class="page-head">
  <div><h1>Alertas de capacidad</h1><p class="sub">Personas que ya usaron sus horas de la semana y todavía tienen trabajo comprometido. Sirve para replanificar; no es una evaluación de desempeño.</p></div>
  <div class="page-actions"><a class="btn btn--ghost" href="<?= e(url('/teams')) ?>"><?= icon('users') ?> Equipos</a></div>
</div>

<?php if ($alerts): ?>
  <?= partial('capacity-alerts', ['alerts' => $alerts, 'link' => false]) ?>
<?php else: ?>
  <div class="card mb"><div class="empty"><?= icon('check-circle') ?><h3>No hay alertas abiertas</h3><p>Nadie ha agotado sus horas con trabajo pendiente comprometido.</p></div></div>
<?php endif; ?>

<section class="card" aria-labelledby="cap-history-title">
  <div class="card-head"><h2 id="cap-history-title"><?= icon('timeline') ?> Historial</h2></div>
  <?php if ($history): ?>
    <ul class="list cap-history">
      <?php foreach ($history as $a): ?>
        <li class="cap-history-item">
          <div style="flex:1;min-width:0">
            <strong><?= e($a['user_name']) ?></strong> · semana del <?= e(fmt_date($a['week_start'], true)) ?>
            <span class="badge<?= $a['resolution'] === 'cleared' ? '' : ' success' ?>"><?= e(CapacityAlerts::RESOLUTIONS[$a['resolution']] ?? 'Resuelta') ?></span>
            <div class="muted small">Resuelta <?= $a['resolved_at'] ? e(fmt_datetime($a['resolved_at'])) : '' ?><?= $a['resolved_by_name'] ? ' por ' . e($a['resolved_by_name']) : ' automáticamente' ?> · <?= e(TimeEntries::fmt((int)$a['logged_minutes'])) ?> registradas de <?= e(TimeEntries::fmt((int)$a['capacity_minutes'])) ?></div>
            <ol class="cap-events small">
              <?php foreach ($a['events'] as $ev): ?>
                <li><span class="muted"><?= e(fmt_datetime($ev['created_at'])) ?></span> · <?= $ev['user_name'] ? e($ev['user_name']) . ': ' : '' ?><?= e($ev['detail']) ?></li>
              <?php endforeach; ?>
            </ol>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <div class="empty small">Todavía no se ha resuelto ninguna alerta.</div>
  <?php endif; ?>
</section>
