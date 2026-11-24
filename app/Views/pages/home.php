<?php
use Borealis\Controllers\Shell;
$s = Shell::data();
$h = (int)local_date('G');
// Server-side fallback (installation time zone); the browser corrects it to the person's own local hour.
$greet = $h < 5 ? 'Buenas noches' : ($h < 12 ? 'Buenos días' : ($h < 20 ? 'Buenas tardes' : 'Buenas noches'));
$first = \Borealis\Services\Users::greetingName($s['user']);
$genericName = \Borealis\Services\Users::hasGenericName($s['user']);
$collective = pct($totals['done'], $totals['total']);
?>
<section class="hero">
  <div class="hero-row">
    <div>
      <h1 data-greeting data-name="<?= e($first) ?>"><?= e($greet . ($first !== '' ? ', ' . $first : '')) ?></h1>
      <p><?= $mine ? 'Tienes ' . count($mine) . ' ' . (count($mine) === 1 ? 'tarea abierta' : 'tareas abiertas') . ($overdue ? ', ' . $overdue . ' ' . ($overdue === 1 ? 'vencida' : 'vencidas') : '') . '.' : 'No tienes tareas pendientes asignadas.' ?></p>
      <?php if ($genericName): ?><p class="small"><a href="<?= e(url('/profile')) ?>" style="color:inherit;text-decoration:underline">Escribe tu nombre en tu perfil</a> para que Borealis te salude por él.</p><?php endif; ?>
    </div>
    <div class="page-actions">
      <a class="btn btn--ghost" href="<?= e(url('/my-work')) ?>"><?= icon('check-square') ?> Mi trabajo</a>
      <?php if ($s['can_create_project']): ?><button class="btn btn--ghost" data-dialog="dlg-new-project"><?= icon('folder') ?> Nuevo proyecto</button><?php endif; ?>
    </div>
  </div>
</section>

<div class="notice push-nudge row wrap" data-push-nudge hidden>
  <?= icon('bell', 'sm') ?> <span class="grow">Recibe tus avisos aunque Borealis esté cerrado.</span>
  <button type="button" class="btn btn--sm btn--primary" data-push-nudge-on>Activar</button>
  <button type="button" class="btn btn--sm btn--ghost" data-push-nudge-off>Ahora no</button>
</div>
<?= partial('capacity-alerts', ['alerts' => \Borealis\Services\CapacityAlerts::forView()]) ?>
<div class="grid grid-4 stats mb">
  <a class="card stat" href="<?= e(url('/projects')) ?>"><span class="label"><?= icon('folder', 'sm') ?> Proyectos activos</span><span class="value"><?= (int)$project_count ?></span></a>
  <a class="card stat" href="<?= e(url('/my-work', ['tab' => 'todo'])) ?>"><span class="label"><?= icon('circle', 'sm') ?> Mis pendientes</span><span class="value"><?= count($mine) - (int)$mine_progress ?></span></a>
  <a class="card stat" href="<?= e(url('/my-work', ['tab' => 'progress'])) ?>"><span class="label"><?= icon('clock', 'sm') ?> En proceso</span><span class="value"><?= (int)$mine_progress ?></span></a>
  <a class="card stat<?= $overdue ? ' danger' : '' ?>" href="<?= e(url('/my-work', ['tab' => $overdue ? 'overdue' : 'done'])) ?>">
    <?php if ($overdue): ?><span class="label"><?= icon('alert', 'sm') ?> Mis vencidas</span><span class="value"><?= (int)$overdue ?></span>
    <?php else: ?><span class="label"><?= icon('check-circle', 'sm') ?> Finalizadas esta semana</span><span class="value"><?= (int)$done_week ?></span><?php endif; ?>
  </a>
</div>

<div class="split">
  <div class="stack">
    <section class="card">
      <div class="card-head"><h2><?= icon('folder') ?> Proyectos activos</h2><a class="small" href="<?= e(url('/projects')) ?>">Ver todos</a></div>
      <?php if ($projects): ?>
        <ul class="list">
          <?php foreach ($projects as $p): ?>
            <li>
              <span class="avatar" style="--avatar:<?= e($p['color']) ?>;border-radius:8px;font-size:10px"><?= e(substr($p['project_key'], 0, 3)) ?></span>
              <div style="flex:1;min-width:0">
                <a class="truncate" style="display:block;font-weight:600;color:var(--text)" href="<?= e(url('/projects/' . $p['id'])) ?>"><?= e($p['name']) ?></a>
                <span class="muted small"><?= (int)$p['done'] ?> de <?= (int)$p['total'] ?> tareas<?= $p['blocked'] ? ' · ' . (int)$p['blocked'] . ' bloqueadas' : '' ?><?= $p['overdue'] ? ' · ' . (int)$p['overdue'] . ' vencidas' : '' ?></span>
              </div>
              <div style="width:140px" class="hide-sm"><div class="progress"><span style="--p:<?= (int)$p['progress'] ?>%"></span></div></div>
              <span class="small" style="width:38px;text-align:right;font-weight:650"><?= (int)$p['progress'] ?>%</span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div class="empty"><?= icon('folder') ?><h3>Aún no hay proyectos</h3><p>Un proyecto solo necesita un nombre para empezar.</p>
          <?php if ($s['can_create_project']): ?><button class="btn btn--primary" data-dialog="dlg-new-project"><?= icon('plus') ?> Crear proyecto</button><?php endif; ?></div>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card-head"><h2><?= icon('calendar') ?> Mis próximos vencimientos</h2><a class="small" href="<?= e(url('/my-work', ['tab' => 'upcoming'])) ?>">Ver más</a></div>
      <ul class="list">
        <?php foreach ($soon as $t): ?>
          <li>
            <span class="key"><?= e($t['task_key']) ?></span>
            <button class="task-title truncate" style="flex:1" data-open-task="<?= e($t['task_key']) ?>"><?= e($t['title']) ?></button>
            <span class="badge hide-sm"><span class="dot" style="--c:<?= e($t['status_color']) ?>"></span><?= e($t['status_name']) ?></span>
            <span class="due <?= due_state($t['due_date']) ?>"><?= icon('calendar', 'sm') ?><?= e(fmt_date($t['due_date'])) ?></span>
          </li>
        <?php endforeach; ?>
        <?php if (!$soon): ?><li class="empty">Nada vence en los próximos 14 días.</li><?php endif; ?>
      </ul>
    </section>
  </div>

  <div class="stack">
    <section class="card">
      <div class="card-head"><h2><?= icon('mountain') ?> Avance colectivo</h2><span class="badge success"><?= $collective ?>%</span></div>
      <div class="card-body">
        <?= partial('expedition', ['percent' => $collective, 'label' => 'Avance de los proyectos activos']) ?>
        <p class="muted small mt" style="margin-bottom:0"><?= (int)$totals['done'] ?> de <?= (int)$totals['total'] ?> tareas completadas en los proyectos activos<?= $done_week ? ', ' . (int)$done_week . ' esta semana' : '' ?>.</p>
      </div>
    </section>

    <section class="card">
      <div class="card-head"><h2><?= icon('users') ?> Mis equipos</h2><a class="small" href="<?= e(url('/teams')) ?>">Ver equipos</a></div>
      <ul class="list">
        <?php foreach ($teams as $t): ?>
          <li><span class="dot" style="width:10px;height:10px;border-radius:3px;background:<?= e($t['color']) ?>"></span>
            <a class="truncate" style="flex:1;color:var(--text);font-weight:550" href="<?= e(url('/teams/' . $t['id'])) ?>"><?= e($t['name']) ?></a>
            <span class="muted small"><?= $t['team_role'] === 'coordinator' ? 'Coordinación' : e($t['responsibility'] ?: 'Integrante') ?></span></li>
        <?php endforeach; ?>
        <?php if (!$teams): ?><li class="empty">Aún no perteneces a ningún equipo.</li><?php endif; ?>
      </ul>
    </section>

    <section class="card">
      <div class="card-head"><h2><?= icon('clock') ?> Actividad reciente</h2></div>
      <div class="card-body">
        <ul class="history">
          <?php foreach ($activity as $a): ?>
            <li><span><strong><?= e($a['user_name'] ?? 'Sistema') ?></strong> <?= e(lcfirst($a['summary'])) ?><?= $a['project_name'] ? ' · <a href="' . e(url('/projects/' . $a['project_id'])) . '">' . e($a['project_name']) . '</a>' : '' ?> <span class="muted">· <?= e(time_ago($a['created_at'])) ?></span></span></li>
          <?php endforeach; ?>
          <?php if (!$activity): ?><li>Sin actividad todavía.</li><?php endif; ?>
        </ul>
      </div>
    </section>
  </div>
</div>
