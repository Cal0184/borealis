<?= partial('project-header', compact('project', 'modules', 'tab', 'can')) ?>
<?php
$pid = (int)$project['id'];
$days = (strtotime($to) - strtotime($from)) / 86400 + 1;
$pos = fn($d) => ((strtotime($d) - strtotime($from)) / 86400) / $days * 100;
$todayPos = today() >= $from && today() <= $to ? $pos(today()) + 50 / $days : null;
?>
<div class="toolbar">
  <div class="btn-group">
    <a class="btn btn--sm" href="<?= e(url('/projects/' . $pid . '/timeline', ['from' => date('Y-m-d', strtotime($from . ' -' . $weeks . ' weeks')), 'weeks' => $weeks])) ?>" aria-label="Anterior"><?= icon('chevron-left', 'sm') ?></a>
    <a class="btn btn--sm" href="<?= e(url('/projects/' . $pid . '/timeline', ['weeks' => $weeks])) ?>">Hoy</a>
    <a class="btn btn--sm" href="<?= e(url('/projects/' . $pid . '/timeline', ['from' => date('Y-m-d', strtotime($from . ' +' . $weeks . ' weeks')), 'weeks' => $weeks])) ?>" aria-label="Siguiente"><?= icon('chevron-right', 'sm') ?></a>
  </div>
  <h2 style="margin:0 8px"><?= e(fmt_date($from, true)) ?> – <?= e(fmt_date($to, true)) ?></h2>
  <span class="grow"></span>
  <nav class="segmented" aria-label="Escala"><?php foreach ([4, 8, 12, 26] as $w): ?><a href="<?= e(url('/projects/' . $pid . '/timeline', ['from' => $from, 'weeks' => $w])) ?>"<?= $weeks === $w ? ' aria-current="page"' : '' ?>><?= $w ?> sem</a><?php endforeach; ?></nav>
</div>
<div class="timeline">
  <div class="timeline-grid">
    <div class="timeline-row timeline-head"><div class="label muted small">Tarea</div>
      <div class="timeline-track" style="grid-template-columns:repeat(<?= $weeks ?>,1fr)"><?php for ($i = 0; $i < $weeks; $i++): ?><div class="wk"><?= e(fmt_date(date('Y-m-d', strtotime($from . " +$i weeks")))) ?></div><?php endfor; ?></div></div>
    <?php foreach ($dated as $t): $l = $pos($t['bar_start']); $w = max(0.8, $pos($t['bar_end']) - $l + 100 / $days); ?>
      <div class="timeline-row">
        <div class="label"><span class="key"><?= e($t['task_key']) ?></span><span class="truncate"><?= e($t['title']) ?></span></div>
        <div class="timeline-track">
          <?php if ($todayPos !== null): ?><span class="timeline-today" style="left:<?= round($todayPos, 3) ?>%"></span><?php endif; ?>
          <button type="button" class="timeline-bar<?= $t['status_category'] === 'done' ? ' done' : '' ?>" style="left:<?= round($l, 3) ?>%;width:<?= round($w, 3) ?>%;--c:<?= e($t['status_color']) ?>" data-open-task="<?= e($t['task_key']) ?>"
            title="<?= e($t['title'] . ' · ' . fmt_date($t['start_date'] ?: $t['due_date']) . ' – ' . fmt_date($t['due_date'] ?: $t['start_date'])) ?>"><?= e($t['assignee_name'] ?? '') ?></button>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$dated): ?><div class="empty">Ninguna tarea con fechas en este periodo.</div><?php endif; ?>
  </div>
</div>
<?php if ($undated): ?><p class="muted small mt"><?= count($undated) ?> tareas sin fechas no aparecen en el cronograma. Asigna inicio o vencimiento desde su detalle.</p><?php endif; ?>
<p class="muted small">El cronograma muestra barras de inicio a vencimiento. Dependencias y ruta crítica están previstas para una versión posterior.</p>
