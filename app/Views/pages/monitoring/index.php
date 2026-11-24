<?php
use Borealis\Controllers\MonitoringController;
use Borealis\Services\Monitoring;
use Borealis\Services\TimeEntries;

/** @var string $tab @var array $filters @var array $cfg @var array $scope @var array $projects */
$f = $filters;
$keep = array_filter(['project_id' => $f['project_id'], 'sprint_id' => $f['sprint_id'], 'from' => $f['from'], 'to' => $f['to'], 'mine' => $f['mine'] ? '1' : null]);
$link = fn(string $tab, array $extra = []) => url('/monitoring', ['tab' => $tab] + $extra + $keep);
$h = fn(float $x, bool $sign = false) => Monitoring::fmtHours($x, $sign);
$health = fn(string $k) => '<span class="badge ' . Monitoring::HEALTH[$k][1] . '">' . e(Monitoring::HEALTH[$k][0]) . '</span>';
$sev = fn(string $k) => '<span class="badge ' . Monitoring::SEVERITY[$k][1] . '">' . e(Monitoring::SEVERITY[$k][0]) . '</span>';
$loadBadge = fn(string $k) => '<span class="badge ' . Monitoring::LOAD[$k][1] . '">' . e(Monitoring::LOAD[$k][0]) . '</span>';
$who = $scope['admin'] ? 'todos los proyectos del espacio' : ($scope['managed'] ? 'tus proyectos y los que llevas' : 'los proyectos en los que participas');
?>
<div class="page-head">
  <div><h1>Monitoreo</h1><p class="sub">Cuándo la planificación deja de coincidir con la realidad, con el porqué de cada aviso. Ves <?= e($who) ?>.</p>
    <?php if (!$scope['admin'] && !$scope['managed']): ?><p class="small muted" data-privacy-note><?= icon('eye', 'sm') ?> Aquí ves tu propio trabajo. Tus datos de carga y horas solo los ven, además de ti, quienes llevan tus proyectos y la administración del espacio. Borealis no mide tiempo en pantalla ni actividad fuera de las tareas.</p><?php endif; ?></div>
  <div class="page-actions">
    <button class="btn" type="button" data-call="/api/monitoring/scan" title="<?= $run ? 'Última revisión: ' . e(fmt_datetime($run['ran_at'])) : 'Aún no se ha revisado' ?>"><?= icon('repeat', 'sm') ?> Revisar ahora</button>
  </div>
</div>
<nav class="segmented mb mon-tabs" aria-label="Vistas de monitoreo">
  <?php foreach (MonitoringController::TABS as $k => $label): ?><a href="<?= e($link($k)) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?></a><?php endforeach; ?>
  <a href="<?= e(url('/reports')) ?>"><?= icon('chart', 'sm') ?> Reportes</a>
</nav>
<form class="toolbar" method="get" action="<?= e(url('/monitoring')) ?>" data-filter-form>
  <?php if (!config('app.pretty_urls', true)): ?><input type="hidden" name="r" value="/monitoring"><?php endif; ?>
  <input type="hidden" name="tab" value="<?= e($tab) ?>">
  <label class="sr-only" for="mon-p">Proyecto</label>
  <select class="select<?= $f['project_id'] ? ' filter-active' : '' ?>" id="mon-p" name="project_id" data-autosubmit>
    <option value="">Todos mis proyectos</option>
    <?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>"<?= selected($p['id'], $f['project_id']) ?>><?= e($p['name']) ?></option><?php endforeach; ?>
  </select>
  <?php if (in_array($tab, ['compliance', 'workload', 'times', 'report'], true)): ?>
  <label class="sr-only" for="mon-s">Sprint</label>
  <select class="select<?= $f['sprint_id'] ? ' filter-active' : '' ?>" id="mon-s" name="sprint_id" data-autosubmit>
    <option value="">Cualquier sprint</option>
    <?php foreach ($sprintOptions as $sp): if ($f['project_id'] && (int)$sp['project_id'] !== (int)$f['project_id']) continue; ?><option value="<?= (int)$sp['id'] ?>"<?= selected($sp['id'], $f['sprint_id']) ?>><?= e($sp['name']) ?><?= $sp['state'] === 'active' ? ' · activo' : ($sp['state'] === 'completed' ? ' · cerrado' : '') ?></option><?php endforeach; ?>
  </select>
  <?php endif; ?>
  <?php if (in_array($tab, ['compliance', 'times', 'report'], true)): ?>
  <label class="sr-only" for="mon-from">Desde</label><input class="input" type="date" id="mon-from" name="from" value="<?= e($f['from']) ?>" title="Desde">
  <label class="sr-only" for="mon-to">Hasta</label><input class="input" type="date" id="mon-to" name="to" value="<?= e($f['to']) ?>" title="Hasta">
  <label class="check"><input type="checkbox" name="mine" value="1"<?= checked((bool)$f['mine']) ?>> Solo lo mío</label>
  <?php endif; ?>
  <?php if ($tab === 'compliance'): ?><input type="hidden" name="class" value="<?= e($c['class']) ?>"><?php endif; ?>
  <button class="btn" type="submit"><?= icon('filter', 'sm') ?> Aplicar</button>
</form>

<?php if ($tab === 'summary'): $s = $s; $cn = $s['counts']; ?>
  <div class="grid grid-4 stats mb mon-kpis">
    <a class="card stat" href="<?= e(url('/projects', ['state' => 'active'])) ?>"><span class="label"><?= icon('folder', 'sm') ?> Proyectos activos</span><span class="value"><?= (int)$s['active_projects'] ?></span></a>
    <a class="card stat" href="<?= e($link('compliance', ['class' => 'open'])) ?>"><span class="label"><?= icon('circle', 'sm') ?> Tareas pendientes</span><span class="value"><?= (int)$cn['open'] ?></span></a>
    <a class="card stat" href="<?= e($link('compliance', ['class' => 'done'])) ?>"><span class="label"><?= icon('check', 'sm') ?> Tareas completadas</span><span class="value"><?= (int)$cn['done'] ?></span></a>
    <a class="card stat<?= $cn['late'] ? ' danger' : '' ?>" href="<?= e($link('compliance', ['class' => 'late'])) ?>" data-kpi="late"><span class="label"><?= icon('alert', 'sm') ?> Tareas atrasadas</span><span class="value"><?= (int)$cn['late'] ?></span></a>
    <a class="card stat<?= $cn['blocked'] ? ' warn' : '' ?>" href="<?= e($link('compliance', ['class' => 'blocked'])) ?>"><span class="label"><?= icon('block', 'sm') ?> Tareas bloqueadas</span><span class="value"><?= (int)$cn['blocked'] ?></span></a>
    <a class="card stat<?= $cn['soon'] ? ' warn' : '' ?>" href="<?= e($link('compliance', ['class' => 'soon'])) ?>"><span class="label"><?= icon('clock', 'sm') ?> Próximas a vencer</span><span class="value"><?= (int)$cn['soon'] ?></span></a>
    <a class="card stat" href="<?= e(url('/sprints')) ?>"><span class="label"><?= icon('zap', 'sm') ?> Sprints activos</span><span class="value"><?= count($s['sprints']) ?></span></a>
    <a class="card stat<?= $s['at_risk'] ? ' warn' : '' ?>" href="<?= e($link('risks')) ?>"><span class="label"><?= icon('flag', 'sm') ?> Compromisos en riesgo</span><span class="value"><?= (int)$s['at_risk'] ?></span></a>
  </div>
  <section class="card mb">
    <div class="card-head"><h3><?= icon('target') ?> Estado de los proyectos</h3><span class="muted small">Calculado con fechas, prioridades, bloqueos y dependencias. No cambia el estado del proyecto.</span></div>
    <ul class="list mon-health">
      <?php foreach ($s['health'] as $hp): ?>
        <li><span class="row" style="min-width:220px"><a href="<?= e($link('compliance', ['project_id' => $hp['project']['id'], 'class' => 'late'])) ?>" style="font-weight:600"><?= e($hp['project']['name']) ?></a> <?= $health($hp['status']) ?></span>
          <span class="muted small" style="flex:1"><?= e(implode(' ', $hp['why'])) ?></span></li>
      <?php endforeach; ?>
      <?php if (!$s['health']): ?><li class="empty">No participas en ningún proyecto activo.</li><?php endif; ?>
    </ul>
  </section>
  <div class="grid grid-2 mb">
    <section class="card"><div class="card-head"><h3><?= icon('flag') ?> Alertas relevantes</h3><a class="btn btn--sm btn--ghost" href="<?= e($link('risks')) ?>">Ver todas</a></div>
      <ul class="list">
        <?php foreach (array_slice(array_values(array_filter($s['alerts'], fn($a) => $a['severity'] !== 'info')), 0, 6) as $a): ?>
          <li><?= $sev($a['severity']) ?> <span class="truncate" style="flex:1" title="<?= e($a['explanation']) ?>"><?= e($a['message']) ?></span><?php if ($a['task_key']): ?><button class="btn btn--sm" type="button" data-open-task="<?= e($a['task_key']) ?>">Abrir</button><?php endif; ?></li>
        <?php endforeach; ?>
        <?php if (!array_filter($s['alerts'], fn($a) => $a['severity'] !== 'info')): ?><li class="empty">Sin advertencias ni alertas críticas.</li><?php endif; ?>
      </ul></section>
    <section class="card"><div class="card-head"><h3><?= icon('calendar') ?> Próximos compromisos (7 días)</h3></div>
      <ul class="list">
        <?php foreach ($s['upcoming'] as $t): ?><li><span class="key"><?= e($t['task_key']) ?></span><button class="task-title truncate" style="flex:1" data-open-task="<?= e($t['task_key']) ?>"><?= e($t['title']) ?></button><span class="due <?= due_state($t['due_date']) ?>"><?= e(fmt_date($t['due_date'])) ?></span></li><?php endforeach; ?>
        <?php foreach ($s['sprints'] as $sp): if (!$sp['sprint']['end_date'] || $sp['sprint']['end_date'] > date('Y-m-d', strtotime(today() . ' +7 days'))) continue; ?><li><?= icon('zap', 'sm') ?> <span style="flex:1">Fin de «<?= e($sp['sprint']['name']) ?>»</span><span class="due"><?= e(fmt_date($sp['sprint']['end_date'])) ?></span></li><?php endforeach; ?>
        <?php if (!$s['upcoming']): ?><li class="empty">Nada vence en los próximos 7 días.</li><?php endif; ?>
      </ul></section>
  </div>
  <section class="card mb"><div class="card-head"><h3><?= icon('users') ?> Carga de esta semana</h3><a class="btn btn--sm btn--ghost" href="<?= e($link('workload')) ?>">Ver por semanas</a></div>
    <?= partial('monitor-load', ['wl' => $wl, 'link' => $link, 'compact' => true]) ?>
  </section>
  <?php if ($s['sprints']): ?>
  <section class="card mb"><div class="card-head"><h3><?= icon('zap') ?> Sprints activos</h3></div>
    <div class="mon-sprints"><?php foreach ($s['sprints'] as $sp): ?><?= partial('monitor-sprint', ['sp' => $sp, 'h' => $h]) ?><?php endforeach; ?></div>
  </section>
  <?php endif; ?>

<?php elseif ($tab === 'compliance'): $cn = $c['counts']; ?>
  <nav class="segmented mb" aria-label="Cumplimiento">
    <?php foreach (['late' => 'Atrasadas', 'soon' => 'Próximas a vencer', 'ontime' => 'Dentro del plazo', 'done_late' => 'Completadas fuera del plazo', 'done_ontime' => 'Completadas dentro del plazo', 'nodate' => 'Sin fecha límite', 'blocked' => 'Bloqueadas', 'open' => 'Pendientes', 'done' => 'Completadas'] as $k => $label): ?>
      <a href="<?= e($link('compliance', ['class' => $k])) ?>"<?= $c['class'] === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?> <span class="count"><?= (int)($cn[$k] ?? 0) ?></span></a>
    <?php endforeach; ?>
  </nav>
  <p class="muted small mb">Son indicadores, no estados: una tarea puede estar «En progreso» y atrasada a la vez. «Próxima a vencer» = vence en <?= (int)$cfg['soon_days'] ?> días o menos.</p>
  <div class="card mb">
    <?php if ($c['tasks']): ?>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>Tarea</th><th class="hide-sm">Proyecto</th><th>Estado</th><th>Fecha límite</th><th class="hide-md">Responsable</th><th class="hide-md">Motivo registrado</th></tr></thead>
      <tbody>
      <?php foreach ($c['tasks'] as $t): ?>
        <tr><td style="min-width:220px"><span class="key"><?= e($t['task_key']) ?></span> <button class="task-title" data-open-task="<?= e($t['task_key']) ?>"><?= e($t['title']) ?></button><?= $t['is_blocked'] && !$t['completed_at'] ? ' <span class="blocked-tag">' . icon('block', 'sm') . ' Bloqueada</span>' : '' ?></td>
          <td class="hide-sm"><?= e($t['project_name']) ?></td>
          <td><?= e($t['status_name']) ?></td>
          <td><span class="due <?= due_state($t['due_date'], (bool)$t['completed_at']) ?>"><?= $t['due_date'] ? e(fmt_date($t['due_date'], true)) : '—' ?></span> <?= late_tag($t, (bool)$t['completed_at']) ?><?= $t['due_changes'] ? ' <span class="muted small" title="Veces que cambió la fecha límite">' . icon('repeat', 'sm') . (int)$t['due_changes'] . '</span>' : '' ?></td>
          <td class="hide-md"><?= e($t['assignee_name'] ?? ($t['team_name'] ?? '—')) ?></td>
          <td class="hide-md small"><?= $t['last_explanation'] ? e(Monitoring::REASONS[$t['last_explanation']['reason']] ?? '') . ($t['last_explanation']['corrective_action'] ? ' · ' . e(mb_strimwidth($t['last_explanation']['corrective_action'], 0, 60, '…')) : '') : '<span class="muted">—</span>' ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?php else: ?><div class="empty"><?= icon('check-circle') ?><h3>Nada en esta categoría</h3><p>Con los filtros elegidos no hay tareas aquí.</p></div><?php endif; ?>
  </div>
  <?php if ($deps): ?>
  <section class="card mb"><div class="card-head"><h3><?= icon('link') ?> Dependencias en riesgo</h3><span class="muted small">Borealis no cambia fechas por su cuenta.</span></div>
    <ul class="list"><?php foreach ($deps as $d): ?><li><span class="key"><?= e($d['b_key']) ?></span><button class="task-title truncate" style="flex:1" data-open-task="<?= e($d['b_key']) ?>"><?= e($d['b_title']) ?></button><span class="muted small"><?= e($d['why']) ?></span></li><?php endforeach; ?></ul></section>
  <?php endif; ?>

<?php elseif ($tab === 'workload'): ?>
  <div class="row mb mon-week">
    <a class="btn btn--sm" href="<?= e($link('workload', ['week' => date('Y-m-d', strtotime($week . ' -7 days')), 'view' => $view])) ?>"><?= icon('chevron-left', 'sm') ?> Anterior</a>
    <strong>Semanas desde el <?= e(fmt_date($week, true)) ?></strong>
    <a class="btn btn--sm" href="<?= e($link('workload', ['week' => date('Y-m-d', strtotime($week . ' +7 days')), 'view' => $view])) ?>">Siguiente <?= icon('chevron-right', 'sm') ?></a>
    <nav class="segmented push" aria-label="Ver la carga por"><?php foreach (['person' => 'Por persona', 'project' => 'Por proyecto', 'sprint' => 'Por sprint'] as $k => $label): ?><a href="<?= e($link('workload', ['view' => $k, 'week' => $week])) ?>"<?= $view === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?></a><?php endforeach; ?></nav>
  </div>
  <p class="muted small mb">Carga planificada = horas pendientes estimadas (las restantes si se indicaron; si no, estimadas menos registradas), repartidas en los días laborables entre el inicio y la fecha límite de cada tarea y divididas entre sus responsables. Orientativa: las tareas sin estimación o sin fecha se listan aparte y no reciben horas inventadas. Umbrales: disponible &lt; <?= (int)$cfg['load_available'] ?> %, equilibrada hasta <?= (int)$cfg['load_high'] ?> %, elevada hasta <?= (int)$cfg['load_over'] ?> %, sobrecarga por encima. La capacidad es una referencia de planificación, no un límite ni una medida de rendimiento.</p>
  <?php if ($view === 'person'): ?>
    <section class="card mb"><?= partial('monitor-load', ['wl' => $wl, 'link' => $link, 'compact' => false, 'focus' => $focus]) ?></section>
  <?php elseif ($view === 'project'): $byP = Monitoring::workloadByProject($wl); ?>
    <section class="card mb"><div class="table-wrap"><table class="table">
      <thead><tr><th>Proyecto</th><?php foreach ($wl['weeks'] as $w): ?><th class="num">Sem. <?= e(fmt_date($w)) ?></th><?php endforeach; ?></tr></thead>
      <tbody><?php foreach ($byP as $row): ?><tr><td><a href="<?= e($link('workload', ['project_id' => $row['project']['id'], 'week' => $week])) ?>"><?= e($row['project']['name']) ?></a></td><?php foreach ($wl['weeks'] as $w): ?><td class="num"><?= e($h($row['weeks'][$w] ?? 0)) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
      <?php if (!$byP): ?><tr><td colspan="<?= count($wl['weeks']) + 1 ?>" class="empty">Sin trabajo estimado con fecha en estas semanas.</td></tr><?php endif; ?></tbody></table></div></section>
  <?php else: ?>
    <?php foreach ($sprints as $sp): $people = \Borealis\Services\Capacity::people($sp['sprint'], $sp['project']); ?>
      <section class="card mb"><div class="card-head"><h3><?= icon('zap') ?> <?= e($sp['sprint']['name']) ?> <span class="muted small"><?= e($sp['project']['name']) ?></span></h3></div>
        <ul class="list"><?php foreach ($people as $pp): if (!in_array((int)$pp['id'], \Borealis\Services\Monitoring::visiblePeople(), true)) continue; $pct = $pp['capacity'] ? min(150, (int)round($pp['load'] / max(0.01, $pp['capacity']) * 100)) : 0; ?>
          <li><?= avatar($pp, 'sm') ?> <span style="min-width:140px"><?= e($pp['name']) ?></span><span class="load-bar<?= $pp['over'] ? ' over' : '' ?>" style="flex:1"><span style="--p:<?= min(100, $pct) ?>%"></span></span>
            <span class="small"><?= e($h((float)$pp['load'])) ?> de <?= $pp['capacity'] !== null ? e($h((float)$pp['capacity'])) : 'sin horas definidas' ?></span></li>
        <?php endforeach; ?></ul></section>
    <?php endforeach; ?>
    <?php if (!$sprints): ?><div class="card empty"><p>No hay sprints activos en tus proyectos.</p></div><?php endif; ?>
  <?php endif; ?>

<?php elseif ($tab === 'times'): $sm = $t['summary']; ?>
  <div class="grid grid-4 stats mb">
    <div class="card stat"><span class="label">Tareas comparables</span><span class="value"><?= (int)$sm['compared'] ?></span></div>
    <div class="card stat<?= $sm['over'] ? ' warn' : '' ?>"><span class="label">Con desviación ≥ <?= (int)$cfg['deviation_pct'] ?> %</span><span class="value"><?= (int)$sm['over'] ?></span></div>
    <div class="card stat"><span class="label" title="La mediana no se deja arrastrar por un valor extremo">Desviación típica (mediana, terminadas)</span><span class="value"><?= $sm['median_pct'] === null ? '—' : ($sm['median_pct'] > 0 ? '+' : '') . (int)$sm['median_pct'] . ' %' ?></span></div>
    <div class="card stat"><span class="label">Terminadas: estimado / registrado</span><span class="value" style="font-size:20px"><?= e($h($sm['estimate'])) ?> / <?= e($h($sm['logged'])) ?></span></div>
  </div>
  <p class="muted small mb">Se comparan solo tareas con horas estimadas. Una desviación no es un incumplimiento: ayuda a estimar mejor. <?= (int)$sm['no_estimate'] ?> con horas registradas pero sin estimación y <?= (int)$sm['incomplete'] ?> terminadas sin horas registradas (registro incompleto) no entran en los totales.</p>
  <div class="card mb"><div class="table-wrap"><table class="table">
    <thead><tr><th>Tarea</th><th class="hide-sm">Proyecto</th><th>Situación</th><th class="num">Estimado</th><th class="num">Registrado</th><th class="num">Desviación</th></tr></thead>
    <tbody>
    <?php foreach ($t['rows'] as $x): $d = $x['dev']; ?>
      <tr><td style="min-width:200px"><span class="key"><?= e($x['task_key']) ?></span> <button class="task-title" data-open-task="<?= e($x['task_key']) ?>"><?= e($x['title']) ?></button></td>
        <td class="hide-sm"><?= e($x['project_name']) ?></td>
        <td><?= $x['done'] ? 'Terminada' : 'En curso' ?><?= $x['flag'] === 'no_estimate' ? ' · <span class="badge">Sin estimación</span>' : ($x['flag'] === 'incomplete' ? ' · <span class="badge warn">Registro incompleto</span>' : '') ?></td>
        <td class="num"><?= $x['estimate_hours'] !== null ? e($h((float)$x['estimate_hours'])) : '—' ?></td>
        <td class="num"><?= e(TimeEntries::fmt((int)$x['logged_minutes'])) ?></td>
        <td class="num"><?= $d ? '<span class="' . ($d['pct'] >= $cfg['deviation_pct'] ? 'dev-over' : ($d['pct'] < 0 ? 'dev-under' : '')) . '">' . e($h($d['hours'], true)) . ' · ' . ($d['pct'] > 0 ? '+' : '') . (int)$d['pct'] . ' %</span>' : '—' ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$t['rows']): ?><tr><td colspan="6" class="empty">Sin tareas con horas estimadas o registradas.</td></tr><?php endif; ?>
    </tbody></table></div></div>

<?php elseif ($tab === 'risks'): ?>
  <div class="row mb mon-week">
    <nav class="segmented" aria-label="Estado de las alertas"><a href="<?= e($link('risks')) ?>"<?= $state === 'active' ? ' aria-current="page"' : '' ?>>Activas</a><a href="<?= e($link('risks', ['state' => 'resolved'])) ?>"<?= $state === 'resolved' ? ' aria-current="page"' : '' ?>>Resueltas</a></nav>
    <?php if ($capacityAlerts): ?><a class="btn btn--sm push" href="<?= e(url('/')) ?>"><?= icon('clock', 'sm') ?> <?= (int)$capacityAlerts ?> «capacidad agotada con trabajo pendiente» por decidir en Inicio</a><?php endif; ?>
  </div>
  <?php if (!empty($ownCapacity)): ?><p class="card card-body small mb" data-own-capacity><?= icon('clock', 'sm') ?> Esta semana registraste todas tus horas disponibles y aún tienes trabajo comprometido (capacidad agotada con trabajo pendiente). Quien gestiona el proyecto ya recibió el aviso para reprogramar, reasignar o autorizar horas.</p><?php endif; ?>
  <p class="muted small mb">Reglas fijas y explicadas (sin inteligencia artificial). Primero se avisa a quien es responsable; si nadie la marca como revisada en <?= (int)$cfg['escalate_l2_days'] ?> días (o si es crítica), a quien lleva el proyecto; una crítica sin revisar <?= (int)$cfg['escalate_l3_days'] ?> días llega a la administración<?= $cfg['escalate_admin'] ? '' : ' (desactivado)' ?>. Se resuelven solas cuando desaparece la causa.</p>
  <?php $groups = []; foreach ($alerts as $a) $groups[$a['project_name'] ?? 'Carga de las personas'][] = $a; ?>
  <?php foreach ($groups as $gname => $list): ?>
    <section class="card mb"><div class="card-head"><h3><?= e($gname) ?> <span class="count"><?= count($list) ?></span></h3></div>
      <ul class="list mon-alerts">
      <?php foreach ($list as $a): ?>
        <li data-alert="<?= (int)$a['id'] ?>">
          <div style="flex:1;min-width:0">
            <div class="row wrap"><?= $sev($a['severity']) ?> <span class="badge"><?= e(Monitoring::RULES[$a['rule']] ?? $a['rule']) ?></span><?php if ($a['state'] === 'ack'): ?><span class="badge success" title="<?= e($a['ack_note'] ?? '') ?>">Revisada por <?= e($a['ack_name'] ?? '') ?></span><?php endif; ?><?php if ((int)$a['level'] > 1 && $a['state'] !== 'resolved'): ?><span class="badge dark">Nivel <?= (int)$a['level'] ?></span><?php endif; ?></div>
            <div style="font-weight:600;margin-top:4px"><?= e($a['message']) ?></div>
            <div class="muted small"><?= e($a['explanation']) ?></div>
            <div class="muted small">Detectada <?= e(fmt_datetime($a['first_seen'])) ?><?= $a['resolved_at'] ? ' · resuelta ' . e(fmt_datetime($a['resolved_at'])) : '' ?></div>
          </div>
          <div class="row mon-actions">
            <?php if ($a['task_key']): ?><button class="btn btn--sm" type="button" data-open-task="<?= e($a['task_key']) ?>"><?= icon('external', 'sm') ?> Abrir tarea</button><?php endif; ?>
            <?php if ($a['rule'] === 'overload'): ?><a class="btn btn--sm" href="<?= e($link('workload', ['user' => $a['user_id']])) ?>"><?= icon('users', 'sm') ?> Ver la distribución</a><?php endif; ?>
            <?php if ($a['rule'] === 'sprint_pending' && $a['project_id']): ?><a class="btn btn--sm" href="<?= e(url('/projects/' . $a['project_id'] . '/sprints')) ?>"><?= icon('zap', 'sm') ?> Cerrar el sprint</a><?php endif; ?>
            <?php if ($a['state'] === 'open'): ?><button class="btn btn--sm" type="button" data-call="/api/monitoring/alerts/<?= (int)$a['id'] ?>/ack"><?= icon('check', 'sm') ?> Marcar revisada</button><?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
      </ul></section>
  <?php endforeach; ?>
  <?php if (!$alerts): ?><div class="card empty"><?= icon('check-circle') ?><h3><?= $state === 'resolved' ? 'Sin alertas resueltas' : 'Sin alertas activas' ?></h3><p>Las reglas no encontraron nada que revisar en tus proyectos.</p></div><?php endif; ?>

<?php elseif ($tab === 'report'): $cn = $c['counts']; $dn = $done['counts']; $sm = $t['summary']; ?>
  <div class="row mb"><p class="muted" style="margin:0">Del <?= e(fmt_date($f['from'], true)) ?> al <?= e(fmt_date($f['to'], true)) ?><?= $f['project_id'] ? ' · ' . e($scope['rows'][$f['project_id']]['name'] ?? '') : '' ?>. Datos reales de Borealis, sin valores estimados para rellenar.</p>
    <a class="btn btn--primary push" href="<?= e(url('/monitoring/report.csv', array_filter(['project_id' => $f['project_id'], 'sprint_id' => $f['sprint_id'], 'from' => $f['from'], 'to' => $f['to']]))) ?>" download><?= icon('download') ?> Descargar CSV</a></div>
  <div class="grid grid-4 stats mb">
    <div class="card stat"><span class="label">Finalizadas en el período</span><span class="value"><?= (int)$dn['done'] ?></span></div>
    <div class="card stat"><span class="label">A tiempo / fuera de plazo</span><span class="value"><?= (int)$dn['done_ontime'] ?> / <?= (int)$dn['done_late'] ?></span></div>
    <div class="card stat<?= $cn['late'] ? ' danger' : '' ?>"><span class="label">Atrasadas ahora</span><span class="value"><?= (int)$cn['late'] ?></span></div>
    <div class="card stat"><span class="label">Pendientes del período</span><span class="value"><?= (int)$cn['open'] ?></span></div>
  </div>
  <div class="grid grid-2 mb">
    <section class="card"><div class="card-head"><h3><?= icon('alert') ?> Retrasos</h3></div><ul class="list">
      <?php foreach (array_slice($c['tasks'], 0, 15) as $x): ?><li><span class="key"><?= e($x['task_key']) ?></span><button class="task-title truncate" style="flex:1" data-open-task="<?= e($x['task_key']) ?>"><?= e($x['title']) ?></button><?= late_tag($x) ?><span class="muted small hide-sm"><?= $x['last_explanation'] ? e(Monitoring::REASONS[$x['last_explanation']['reason']] ?? '') : 'Sin motivo' ?></span></li><?php endforeach; ?>
      <?php if (!$c['tasks']): ?><li class="empty">Sin retrasos.</li><?php endif; ?></ul></section>
    <section class="card"><div class="card-head"><h3><?= icon('clock') ?> Desviaciones de estimación</h3></div><ul class="list">
      <?php $devs = array_slice(array_values(array_filter($t['rows'], fn($x) => $x['dev'] && $x['dev']['pct'] >= $cfg['deviation_pct'])), 0, 15); foreach ($devs as $x): ?><li><span class="key"><?= e($x['task_key']) ?></span><span class="truncate" style="flex:1"><?= e($x['title']) ?></span><span class="dev-over small"><?= e($h($x['dev']['hours'], true)) ?> · +<?= (int)$x['dev']['pct'] ?> %</span></li><?php endforeach; ?>
      <?php if (!$devs): ?><li class="empty">Sin desviaciones por encima del <?= (int)$cfg['deviation_pct'] ?> %.</li><?php endif; ?>
      <li class="muted small">Mediana de las terminadas: <?= $sm['median_pct'] === null ? '—' : (($sm['median_pct'] > 0 ? '+' : '') . (int)$sm['median_pct'] . ' %') ?></li></ul></section>
  </div>
  <div class="grid grid-2 mb">
    <section class="card"><div class="card-head"><h3><?= icon('block') ?> Bloqueos y riesgos abiertos</h3></div><ul class="list">
      <?php $rk = array_slice(array_values(array_filter($alerts, fn($a) => $a['severity'] !== 'info')), 0, 15); foreach ($rk as $a): ?><li><?= $sev($a['severity']) ?><span class="truncate" style="flex:1"><?= e($a['message']) ?></span></li><?php endforeach; ?>
      <?php if (!$rk): ?><li class="empty">Sin riesgos abiertos.</li><?php endif; ?></ul></section>
    <section class="card"><div class="card-head"><h3><?= icon('users') ?> Carga planificada esta semana</h3></div><?= partial('monitor-load', ['wl' => $wl, 'link' => $link, 'compact' => true]) ?></section>
  </div>
  <section class="card mb"><div class="card-head"><h3><?= icon('zap') ?> Sprints cerrados en el período</h3></div>
    <?php if ($closed): ?><div class="mon-sprints"><?php foreach ($closed as $sp): ?><?= partial('monitor-sprint', ['sp' => $sp, 'h' => $h]) ?><?php endforeach; ?></div>
    <?php else: ?><p class="empty">Ningún sprint se cerró en el período.</p><?php endif; ?>
  </section>
<?php endif; ?>
