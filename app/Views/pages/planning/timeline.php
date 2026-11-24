<?= partial('project-header', compact('project', 'modules', 'tab', 'can')) ?>
<?php
/** 1.14 · Carta Gantt. @var array $g (Gantt::build) */
$pid = (int)$project['id'];
$days = max(1, $g['days']);
$x = fn(string $d) => round(((strtotime($d . ' 12:00:00') - strtotime($g['from'] . ' 12:00:00')) / 86400) / $days * 100, 4);
$w = fn(string $a, string $b) => round(max(0.35, ((strtotime($b . ' 12:00:00') - strtotime($a . ' 12:00:00')) / 86400 + 1) / $days * 100), 4);
$in = fn(string $a, string $b) => $b >= $g['from'] && $a <= $g['to'];
$clip = fn(string $d) => max($g['from'], min($g['to'], $d));
$q = fn(array $extra) => url('/projects/' . $pid . '/timeline', array_filter($extra + ['scale' => $g['scale'], 'subtasks' => $subtasks ? null : '0'], fn($v) => $v !== null));
$todayX = $g['today'] >= $g['from'] && $g['today'] <= $g['to'] ? $x($g['today']) + 50 / $days : null;
$span = (int)((strtotime($g['to']) - strtotime($g['from'])) / 86400);
$shift = $g['scale'] === 'day' ? 14 : ($g['scale'] === 'week' ? 56 : 120);
$cols = $g['columns'];
$minW = $g['scale'] === 'day' ? count($cols) * 28 : ($g['scale'] === 'week' ? count($cols) * 56 : count($cols) * 70);
?>
<div class="toolbar wrap gantt-toolbar">
  <div class="btn-group">
    <a class="btn btn--sm" href="<?= e($q(['from' => date('Y-m-d', strtotime($g['from'] . " -$shift days")), 'to' => date('Y-m-d', strtotime($g['to'] . " -$shift days"))])) ?>" aria-label="Anterior"><?= icon('chevron-left', 'sm') ?></a>
    <a class="btn btn--sm" href="<?= e($q([])) ?>">Todo el proyecto</a>
    <a class="btn btn--sm" href="<?= e($q(['from' => date('Y-m-d', strtotime($g['from'] . " +$shift days")), 'to' => date('Y-m-d', strtotime($g['to'] . " +$shift days"))])) ?>" aria-label="Siguiente"><?= icon('chevron-right', 'sm') ?></a>
  </div>
  <h2 class="gantt-range"><?= e(fmt_date($g['from'], true)) ?> – <?= e(fmt_date($g['to'], true)) ?></h2>
  <span class="grow"></span>
  <nav class="segmented" aria-label="Escala"><?php foreach (\Borealis\Services\Gantt::SCALES as $k => $lbl): ?><a href="<?= e(url('/projects/' . $pid . '/timeline', ['scale' => $k] + ($subtasks ? [] : ['subtasks' => '0']))) ?>"<?= $g['scale'] === $k ? ' aria-current="page"' : '' ?> data-gantt-scale="<?= $k ?>"><?= e($lbl) ?></a><?php endforeach; ?></nav>
  <a class="btn btn--sm btn--ghost" href="<?= e(url('/projects/' . $pid . '/timeline', ['scale' => $g['scale'], 'from' => $g['from'], 'to' => $g['to']] + ($subtasks ? ['subtasks' => '0'] : []))) ?>"><?= $subtasks ? 'Ocultar subtareas' : 'Mostrar subtareas' ?></a>
</div>

<?php if ($g['late'] || $g['moved'] || $g['conflicts']): ?>
  <p class="gantt-summary small">
    <?php if ($g['late']): ?><span class="badge danger"><?= (int)$g['late'] ?> con retraso</span><?php endif; ?>
    <?php if ($g['moved']): ?><span class="badge warn"><?= (int)$g['moved'] ?> fuera de su plan original</span><?php endif; ?>
    <?php if ($g['conflicts']): ?><span class="badge warn"><?= (int)$g['conflicts'] ?> dependencias en conflicto</span><?php endif; ?>
  </p>
<?php endif; ?>

<div class="gantt" data-gantt style="--gantt-min:<?= (int)$minW ?>px">
  <div class="gantt-scroll">
    <div class="gantt-grid">
      <div class="gantt-row gantt-head">
        <div class="gantt-label muted small">Tarea</div>
        <div class="gantt-track">
          <?php foreach ($cols as $c): ?><div class="gantt-col<?= $c['weekend'] ? ' is-weekend' : '' ?>" style="left:<?= $x($c['start']) ?>%;width:<?= round($c['days'] / $days * 100, 4) ?>%"><span><?= e($c['label']) ?></span><small><?= e($c['sub']) ?></small></div><?php endforeach; ?>
        </div>
      </div>
      <?php if ($g['sprints']): ?>
        <div class="gantt-row gantt-sprints">
          <div class="gantt-label small"><?= icon('zap', 'sm') ?> Sprints</div>
          <div class="gantt-track">
            <?php foreach ($g['sprints'] as $sp): if (!$in($sp['start_date'], $sp['end_date'])) continue; $a = $clip($sp['start_date']); $b = $clip($sp['end_date']); ?>
              <span class="gantt-sprint<?= $sp['state'] === 'active' ? ' is-active' : '' ?>" style="left:<?= $x($a) ?>%;width:<?= $w($a, $b) ?>%" title="<?= e($sp['name'] . ' · ' . fmt_date($sp['start_date']) . ' – ' . fmt_date($sp['end_date'])) ?>"><?= e($sp['name']) ?></span>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
      <?php foreach ($g['rows'] as $r): $show = $in($r['start'], $r['end']); $a = $clip($r['start']); $b = $clip($r['end']); ?>
        <div class="gantt-row<?= !empty($r['child']) ? ' is-child' : '' ?>" data-gantt-row="<?= $r['id'] ?>">
          <div class="gantt-label">
            <button type="button" class="link-btn truncate" data-open-task="<?= e($r['key']) ?>"><span class="key"><?= e($r['key']) ?></span> <?= e($r['title']) ?></button>
            <span class="gantt-who muted small"><?= e($r['who'] ?? 'Sin responsable') ?></span>
          </div>
          <div class="gantt-track">
            <?php if ($todayX !== null): ?><span class="gantt-today" style="left:<?= $todayX ?>%"></span><?php endif; ?>
            <?php if ($r['moved'] && $r['base_start'] && $r['base_end'] && $in($r['base_start'], $r['base_end'])): $ba = $clip(min($r['base_start'], $r['base_end'])); $bb = $clip(max($r['base_start'], $r['base_end'])); ?>
              <span class="gantt-base" style="left:<?= $x($ba) ?>%;width:<?= $w($ba, $bb) ?>%" title="Plan original: <?= e(fmt_date($r['base_start']) . ' – ' . fmt_date($r['base_end'])) ?>" data-gantt-base></span>
            <?php endif; ?>
            <?php if ($show && $r['milestone']): ?>
              <span class="gantt-milestone<?= $r['done'] ? ' is-done' : '' ?><?= $r['late_open'] ? ' is-late' : '' ?>" style="left:<?= $x($b) + 50 / $days ?>%" data-gantt-bar="<?= $r['id'] ?>" title="Hito · <?= e(fmt_date($r['end'], true)) ?>"></span>
            <?php elseif ($show): ?>
              <button type="button" class="gantt-bar<?= $r['done'] ? ' is-done' : '' ?><?= $r['late_open'] ? ' is-late' : '' ?><?= $r['only_due'] ? ' is-due-only' : '' ?><?= $r['blocked'] ? ' is-blocked' : '' ?>" data-gantt-bar="<?= $r['id'] ?>" data-open-task="<?= e($r['key']) ?>"
                style="left:<?= $x($a) ?>%;width:<?= $w($a, $b) ?>%;--c:<?= e($r['color']) ?>;--p:<?= (int)($r['progress'] ?? 0) ?>%"
                title="<?= e($r['key'] . ' · ' . $r['title'] . "\n" . ($r['only_due'] ? 'Fecha límite ' . fmt_date($r['end']) : fmt_date($r['start']) . ' – ' . fmt_date($r['end'])) . ' · ' . $r['status'] . ($r['progress'] !== null ? ' · ' . $r['progress'] . ' %' : '') . ($r['late'] ? ' · ' . $r['late'] . ' días de retraso' : '') . ($r['changes'] ? ' · reprogramada ' . $r['changes'] . ($r['changes'] === 1 ? ' vez' : ' veces') : '') . ($r['agreed'] !== null ? ' · ' . $r['logged_h'] . ' de ' . $r['agreed'] . ' h' : '')) ?>">
                <span class="gantt-fill"></span><span class="gantt-text"><?= $r['progress'] !== null ? (int)$r['progress'] . ' %' : '' ?><?= $r['late_open'] ? ' · +' . (int)$r['late'] . ' d' : '' ?></span>
              </button>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$g['rows']): ?><div class="empty">Todavía no hay tareas con fechas. Pon inicio o vencimiento a las tareas y la carta Gantt se arma sola.</div><?php endif; ?>
    </div>
    <svg class="gantt-links" data-gantt-links aria-hidden="true"></svg>
  </div>
  <script type="application/json" data-gantt-deps><?= json_encode($g['deps'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</div>

<div class="gantt-legend small muted">
  <span><i class="lg-bar"></i> Plan actual y avance</span><span><i class="lg-base"></i> Plan original</span><span><i class="lg-late"></i> Con retraso</span><span><i class="lg-ms"></i> Hito</span><span><i class="lg-dep"></i> Dependencia</span>
  <?php if ($g['undated']): ?><span><?= (int)$g['undated'] ?> tareas sin fechas no aparecen.</span><?php endif; ?>
</div>
<?php if ($can('project.manage')): ?>
  <p class="small"><button class="link-btn" type="button" data-call="/api/projects/<?= $pid ?>/baseline" data-confirm="Las fechas actuales pasarán a ser la planificación original con la que se comparan los cambios. ¿Continuar?" data-confirm-style="primary"><?= icon('flag', 'sm') ?> Fijar las fechas actuales como planificación original</button>
  <?php if (\Borealis\Security\Policy::can('audit.view')): ?> · <a href="<?= e(url('/admin/audit', ['project_id' => $pid, 'category' => 'calendar'])) ?>">Historial de cambios de fechas</a><?php endif; ?></p>
<?php endif; ?>
