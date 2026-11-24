<div class="breadcrumbs"><a href="<?= e(url('/teams')) ?>">Equipos</a><?= icon('chevron-right', 'sm') ?><span><?= e($person['name']) ?></span></div>
<div class="page-head">
  <div class="row" style="gap:14px"><?= avatar($person, 'lg') ?><div><h1><?= e($person['name']) ?></h1><p class="sub"><?= e($person['title'] ?: $person['role_name']) ?> · <?= e($person['email']) ?><?= $person['status'] === 'suspended' ? ' · Suspendido' : '' ?></p></div></div>
  <?php if (!empty($canManage)): ?><div class="page-actions"><a class="btn" href="<?= e(url('/admin/members')) ?>"><?= icon('edit') ?> Editar en Administración</a></div><?php endif; ?>
</div>
<?= partial('capacity-alerts', ['alerts' => \Borealis\Services\CapacityAlerts::forView(null, (int)$person['id'])]) ?>
<?php
  use Borealis\Services\TimeEntries;
  $cap = $person['weekly_hours'] !== null ? (float)$person['weekly_hours'] : null;
  $pct = $cap ? min(100, (int)round($hours['week'] / 60 / $cap * 100)) : null;
  $maxWeek = max(1, ...array_map(fn($w) => (int)$w['minutes'], $hours['weeks']));
?>
<section class="card mb" id="person-hours">
  <div class="card-head"><h2><?= icon('clock') ?> Horas registradas</h2>
    <nav class="segmented" aria-label="Período"><?php foreach ($periods as $k => [$label]): ?><a href="<?= e(url('/people/' . (int)$person['id'], ['periodo' => $k])) ?>"<?= $k === $period ? ' aria-current="page"' : '' ?>><?= e($label) ?></a><?php endforeach; ?></nav>
  </div>
  <div class="card-body">
    <div class="hours-grid mb">
      <div class="hours-stat"><span class="muted small">Esta semana</span><strong data-hours-week><?= e(TimeEntries::fmt($hours['week'])) ?></strong>
        <?php if ($cap !== null): ?><div class="hbar" title="<?= $pct ?>% de <?= e(rtrim(rtrim((string)$person['weekly_hours'], '0'), '.')) ?> h"><span class="<?= $hours['week'] / 60 > $cap ? 'over' : '' ?>" style="width:<?= (int)$pct ?>%"></span></div><span class="muted small">de <?= e(rtrim(rtrim((string)$person['weekly_hours'], '0'), '.')) ?> h disponibles</span>
        <?php else: ?><span class="muted small">Sin horas semanales definidas</span><?php endif; ?></div>
      <div class="hours-stat"><span class="muted small">Este mes</span><strong><?= e(TimeEntries::fmt($hours['month'])) ?></strong></div>
      <?php if (!in_array($period, ['semana', 'mes'], true)): ?><div class="hours-stat"><span class="muted small"><?= e($periods[$period][0]) ?></span><strong data-hours-period><?= e(TimeEntries::fmt($hours['period'])) ?></strong></div><?php endif; ?>
      <div class="hours-stat"><span class="muted small">Total</span><strong><?= e(TimeEntries::fmt($hours['total'])) ?></strong></div>
    </div>
    <h3 class="small muted" style="margin:0 0 6px">Últimas 8 semanas</h3>
    <div class="week-bars mb" role="list">
      <?php foreach ($hours['weeks'] as $w): ?>
        <div role="listitem" class="row" style="gap:8px"><span class="muted small" style="width:70px"><?= e(fmt_date($w['week'])) ?></span><div class="hbar" style="flex:1"><span class="<?= $cap && $w['minutes'] / 60 > $cap ? 'over' : '' ?>" style="width:<?= (int)round($w['minutes'] / $maxWeek * 100) ?>%"></span></div><span class="small" style="width:80px;text-align:right"><?= e(TimeEntries::fmt((int)$w['minutes'])) ?></span></div>
      <?php endforeach; ?>
    </div>
    <div class="split">
      <div>
        <h3 class="small muted" style="margin:0 0 6px">Por proyecto (<?= e(mb_strtolower($periods[$period][0])) ?>)</h3>
        <ul class="list"><?php foreach ($hours['projects'] as $hp): ?><li><a class="truncate" style="flex:1;color:var(--text);font-weight:550" href="<?= e(url('/projects/' . (int)$hp['id'] . '/reports')) ?>"><?= e($hp['name']) ?></a><span class="muted small"><?= (int)$hp['tasks'] ?> <?= (int)$hp['tasks'] === 1 ? 'tarea' : 'tareas' ?></span><strong class="small"><?= e(TimeEntries::fmt((int)$hp['minutes'])) ?></strong></li><?php endforeach; ?>
        <?php if (!$hours['projects']): ?><li class="empty">Sin horas en este período.</li><?php endif; ?></ul>
      </div>
      <div>
        <h3 class="small muted" style="margin:0 0 6px">Registros recientes</h3>
        <ul class="list"><?php foreach (array_slice($hours['recent'], 0, 10) as $he): ?><li><span class="muted small" style="width:56px"><?= e(fmt_date($he['work_date'])) ?></span><button class="task-title truncate" style="flex:1" data-open-task="<?= e($he['task_key']) ?>"><?= e($he['task_key'] . ' · ' . $he['task_title']) ?></button><strong class="small"><?= e(TimeEntries::fmt((int)$he['minutes'])) ?></strong></li><?php endforeach; ?>
        <?php if (!$hours['recent']): ?><li class="empty">Sin registros.</li><?php endif; ?></ul>
      </div>
    </div>
    <p class="muted small" style="margin:10px 0 0">Solo se cuentan las horas de proyectos que puedes ver.</p>
  </div>
</section>
<div class="split">
  <section class="card">
    <div class="card-head"><h2><?= icon('check-square') ?> Tareas abiertas a su cargo</h2><span class="muted small"><?= count($tasks) ?></span></div>
    <ul class="list">
      <?php foreach ($tasks as $t): ?>
        <li><span class="key"><?= e($t['task_key']) ?></span><button class="task-title truncate" style="flex:1" data-open-task="<?= e($t['task_key']) ?>"><?= e($t['title']) ?></button>
          <span class="muted small hide-sm"><?= e($t['project_name']) ?></span>
          <span class="badge"><span class="dot" style="--c:<?= e($t['status_color']) ?>"></span><?= e($t['status_name']) ?></span>
          <?php if ($t['due_date']): ?><span class="due <?= due_state($t['due_date']) ?>"><?= e(fmt_date($t['due_date'])) ?></span><?php endif; ?></li>
      <?php endforeach; ?>
      <?php if (!$tasks): ?><li class="empty">Sin tareas abiertas en los proyectos que puedes ver.</li><?php endif; ?>
    </ul>
  </section>
  <div class="stack">
    <section class="card"><div class="card-head"><h2><?= icon('users') ?> Equipos</h2></div>
      <ul class="list"><?php foreach ($teams as $t): ?><li><a style="flex:1;color:var(--text);font-weight:550" href="<?= e(url('/teams/' . $t['id'])) ?>"><?= e($t['name']) ?></a><span class="muted small"><?= $t['team_role'] === 'coordinator' ? 'Coordinación' : e($t['responsibility'] ?: 'Integrante') ?></span></li><?php endforeach; ?>
      <?php if (!$teams): ?><li class="empty">No pertenece a equipos.</li><?php endif; ?></ul></section>
    <section class="card"><div class="card-head"><h2><?= icon('folder') ?> Proyectos</h2></div>
      <ul class="list"><?php foreach ($projects as $p): ?><li><a class="truncate" style="flex:1;color:var(--text);font-weight:550" href="<?= e(url('/projects/' . $p['id'])) ?>"><?= e($p['name']) ?></a><span class="muted small"><?= (int)$p['progress'] ?>%</span></li><?php endforeach; ?>
      <?php if (!$projects): ?><li class="empty">Sin proyectos visibles.</li><?php endif; ?></ul></section>
  </div>
</div>
