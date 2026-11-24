<?php
use Borealis\Services\Calendars;
use Borealis\Services\Events;

$months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$dows = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
$keep = $tasksFilter === 'mine' ? ['tasks' => 'mine'] : [];
$scopeLabels = ['both' => 'Ambos', 'personal' => 'Personal', 'team' => 'Equipo'];
$groupsCal = ['personal' => array_values(array_filter($calendars, fn($c) => $c['kind'] === 'personal')), 'shared' => array_values(array_filter($calendars, fn($c) => $c['kind'] === 'shared'))];
$heading = match ($view) {
    'month' => ucfirst($months[(int)date('n', strtotime($anchor)) - 1]) . ' ' . date('Y', strtotime($anchor)),
    'week' => fmt_date($start) . ' – ' . fmt_date($end, true),
    default => ucfirst(['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'][(int)date('N', strtotime($anchor)) - 1]) . ' ' . fmt_date($anchor, true),
};
$H = 44; // px per hour in the time grid

/** One item chip (month cells and the all-day row). */
$chip = function (array $it) {
    $t = $it['type'];
    if ($t === 'event' || $t === 'meeting') {
        $time = $it['all_day'] ? '' : '<span class="ci-time">' . e(substr($it['occ_start'], 11, 5)) . '</span> ';
        $cls = 'cal-item cal-item--' . $t . ($it['status'] === 'cancelled' ? ' is-cancelled' : '') . ($it['all_day'] ? ' is-allday' : '');
        $drag = $it['recurrence'] === 'none' && !$it['google_recurring'] ? ' data-event-drag data-event-day="' . e(substr($it['occ_start'], 0, 10)) . '"' : '';
        $label = ($t === 'meeting' ? 'Reunión' : 'Evento') . ': ' . $it['title'] . ($it['all_day'] ? ' (todo el día)' : ' a las ' . substr($it['occ_start'], 11, 5)) . ' · ' . $it['calendar_name'] . ($it['status'] === 'cancelled' ? ' · cancelado' : '');
        $cls .= ($it['calendar_kind'] ?? '') === 'personal' ? ' is-personal' : ' is-team';
        $label .= ($it['calendar_kind'] ?? '') === 'personal' ? ' · calendario personal' : ' · calendario de equipo';
        return '<button type="button" class="' . $cls . '" style="--c:' . e($it['color']) . '" data-event="' . (int)$it['id'] . '"' . $drag . ' title="' . e($label) . '" aria-label="' . e($label) . '">'
            . (($it['calendar_kind'] ?? '') === 'personal' ? icon('lock', 'sm') : '') . ($t === 'meeting' ? icon('users', 'sm') : '') . ($it['recurrence'] !== 'none' ? icon('repeat', 'sm') : '') . $time . '<span class="ci-title">' . e($it['title']) . '</span></button>';
    }
    if ($t === 'task') {
        $done = $it['status_category'] === 'done';
        $drag = $it['editable'] ? ' data-cal-drag data-task-id="' . (int)$it['id'] . '" data-start="' . e($it['start_date']) . '" data-due="' . e($it['due_date']) . '"' : '';
        $label = 'Vence la tarea ' . $it['task_key'] . ': ' . $it['title'] . ($it['assignee_name'] ? ' · ' . $it['assignee_name'] : '');
        return '<button type="button" class="cal-task cal-item cal-item--task' . ($done ? ' done' : '') . '" style="--c:' . e($it['project_color']) . '" data-open-task="' . e($it['task_key']) . '"' . $drag . ' title="' . e($label) . '" aria-label="' . e($label) . '">'
            . icon('flag', 'sm') . '<span class="ci-title">' . e($it['title']) . '</span></button>';
    }
    if ($t === 'personal') {
        $label = 'Vence tu tarea personal: ' . $it['title'];
        return '<a class="cal-item cal-item--personal' . ($it['completed_at'] ? ' done' : '') . '" href="' . e(url('/tasks', ['list' => $it['list_id'], 'open' => $it['id']])) . '" title="' . e($label) . '" aria-label="' . e($label) . '">'
            . icon('check-circle', 'sm') . '<span class="ci-title">' . e($it['title']) . '</span></a>';
    }
    if ($t === 'milestone') {
        $label = ($it['edge'] === 'start' ? 'Inicio del proyecto ' : 'Entrega del proyecto ') . $it['name'];
        return '<a class="cal-item cal-item--milestone" style="--c:' . e($it['color']) . '" href="' . e(url('/projects/' . (int)$it['id'])) . '" title="' . e($label) . '" aria-label="' . e($label) . '">'
            . icon('target', 'sm') . '<span class="ci-title">' . e(($it['edge'] === 'start' ? 'Inicio: ' : 'Entrega: ') . $it['name']) . '</span></a>';
    }
    // sprint: start, planned end, end reached but still open (not closed automatically), real closing day
    [$pre, $ic, $what] = match ($it['edge']) {
        'start' => [$it['state'] === 'planned' ? 'Inicio previsto: ' : 'Inicio: ', 'play', $it['state'] === 'planned' ? 'Inicio previsto del sprint ' : 'Inicio del sprint '],
        'overdue' => ['Fin (sin cerrar): ', 'alert', 'Llegó la fecha de fin y sigue abierto, pendiente de cierre: sprint '],
        'closed' => ['Cerrado: ', 'flag-check', 'Se cerró el sprint '],
        default => ['Fin previsto: ', 'flag', 'Fin previsto del sprint '],
    };
    $label = $what . $it['name'] . ' · ' . $it['project_name'] . ' · ' . (int)($it['done_count'] ?? 0) . ' de ' . (int)($it['task_count'] ?? 0) . ' tareas completadas';
    return '<a class="cal-item cal-item--sprint is-' . e($it['edge']) . '" style="--c:' . e($it['project_color']) . '" href="' . e(url('/projects/' . (int)$it['project_id'] . '/sprints', ['sprint' => $it['id']])) . '" title="' . e($label) . '" aria-label="' . e($label) . '">'
        . icon($ic, 'sm') . '<span class="ci-title">' . e($pre . $it['name']) . '</span></a>';
};
$isTimed = fn($it) => in_array($it['type'], ['event', 'meeting'], true) && !$it['all_day'];
?>
<div class="page-head">
  <div><h1>Calendario</h1><p class="sub"><?= $scope === 'personal' ? 'Tu calendario personal: solo lo ves tú, con tus tareas y compromisos.' : ($scope === 'team' ? 'Calendarios de equipo: reuniones, hitos y sprints compartidos.' : 'Tu calendario personal y los de tu equipo, juntos y con su color.') ?></p></div>
  <div class="row wrap">
    <?php if ($editableCals): ?>
      <button class="btn" type="button" data-new-event data-kind="meeting"><?= icon('users', 'sm') ?> Nueva reunión</button>
      <button class="btn btn--primary" type="button" data-new-event data-kind="event" data-tour="cal-new"><?= icon('plus', 'sm') ?> Nuevo evento</button>
    <?php endif; ?>
  </div>
</div>

<div class="cal-layout" id="cal-region" data-region data-cal-view="<?= e($view) ?>" data-cal-anchor="<?= e($anchor) ?>"<?= $googleStale ? ' data-google-autosync' : '' ?><?= $openEvent ? ' data-open-event="' . (int)$openEvent . '"' : '' ?>>
  <aside class="cal-side" aria-label="Calendarios">
    <nav class="segmented cal-scope" aria-label="Qué calendarios ver" data-tour="cal-scope">
      <?php foreach ($scopeLabels as $k => $lbl): ?><a href="<?= e(url('/calendar', ['view' => $view, 'date' => $anchor, 'scope' => $k] + $keep)) ?>"<?= $scope === $k ? ' aria-current="page"' : '' ?>><?= $k === 'personal' ? icon('lock', 'sm') . ' ' : ($k === 'team' ? icon('users', 'sm') . ' ' : '') ?><?= e($lbl) ?></a><?php endforeach; ?>
    </nav>
    <div class="cal-side-box" data-tour="cal-list">
      <?php if ($scope !== 'team'): $list = $groupsCal['personal']; ?>
      <div class="cal-group cal-group--personal">
        <p class="cal-group-title"><?= icon('lock', 'sm') ?> <strong>Personal</strong> <span class="muted small">· solo tú</span></p>
        <ul class="cal-list">
        <?php foreach ($list as $c): $id = 'cal-show-' . (int)$c['id']; ?>
          <li>
            <label class="cal-check" for="<?= $id ?>" style="--c:<?= e($c['color']) ?>">
              <input type="checkbox" id="<?= $id ?>" data-cal-toggle="<?= (int)$c['id'] ?>"<?= checked($c['shown']) ?>>
              <span class="cal-swatch" aria-hidden="true"></span>
              <span class="grow"><?= e($c['name']) ?><?php if ($c['team_name'] ?? null): ?> <span class="muted small">· <?= e($c['team_name']) ?></span><?php elseif ($c['project_name'] ?? null): ?> <span class="muted small">· <?= e($c['project_name']) ?></span><?php endif; ?></span>
              <?php if ($c['source'] === 'google'): ?><span class="badge badge--google" title="Calendario de tu cuenta de Google">Google</span><?php endif; ?>
              <?php if ($c['access'] === 'read'): ?><span class="badge" title="Puedes verlo, no cambiarlo"><?= icon('eye', 'sm') ?></span><?php endif; ?>
            </label>
            <?php if ($c['access'] === 'manage' && $c['source'] !== 'google'): ?>
              <button type="button" class="btn btn--ghost btn--icon btn--xs" data-edit-calendar='<?= e(json_encode(['id' => (int)$c['id'], 'name' => $c['name'], 'color' => $c['color'], 'kind' => $c['kind'], 'default_access' => $c['default_access'], 'purpose' => $c['purpose'], 'team_id' => $c['team_id'] ? (int)$c['team_id'] : '', 'project_id' => $c['project_id'] ? (int)$c['project_id'] : ''])) ?>' aria-label="Ajustes de <?= e($c['name']) ?>"><?= icon('settings', 'sm') ?></button>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
      <?php if ($scope !== 'personal'): $list = $groupsCal['shared']; ?>
      <div class="cal-group cal-group--team">
        <p class="cal-group-title"><?= icon('users', 'sm') ?> <strong>De equipo</strong> <span class="muted small">· compartidos</span></p>
        <ul class="cal-list">
        <?php foreach ($list as $c): $id = 'cal-show-' . (int)$c['id']; ?>
          <li>
            <label class="cal-check" for="<?= $id ?>" style="--c:<?= e($c['color']) ?>">
              <input type="checkbox" id="<?= $id ?>" data-cal-toggle="<?= (int)$c['id'] ?>"<?= checked($c['shown']) ?>>
              <span class="cal-swatch" aria-hidden="true"></span>
              <span class="grow"><?= e($c['name']) ?><?php if ($c['team_name'] ?? null): ?> <span class="muted small">· <?= e($c['team_name']) ?></span><?php elseif ($c['project_name'] ?? null): ?> <span class="muted small">· <?= e($c['project_name']) ?></span><?php endif; ?></span>
              <?php if ($c['source'] === 'google'): ?><span class="badge badge--google" title="Calendario de tu cuenta de Google">Google</span><?php endif; ?>
              <?php if ($c['access'] === 'read'): ?><span class="badge" title="Puedes verlo, no cambiarlo"><?= icon('eye', 'sm') ?></span><?php endif; ?>
            </label>
            <?php if ($c['access'] === 'manage' && $c['source'] !== 'google'): ?>
              <button type="button" class="btn btn--ghost btn--icon btn--xs" data-edit-calendar='<?= e(json_encode(['id' => (int)$c['id'], 'name' => $c['name'], 'color' => $c['color'], 'kind' => $c['kind'], 'default_access' => $c['default_access'], 'purpose' => $c['purpose'], 'team_id' => $c['team_id'] ? (int)$c['team_id'] : '', 'project_id' => $c['project_id'] ? (int)$c['project_id'] : ''])) ?>' aria-label="Ajustes de <?= e($c['name']) ?>"><?= icon('settings', 'sm') ?></button>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
        <?php if (!$list): ?><li class="small muted">No participas en calendarios de equipo.</li><?php endif; ?>
        </ul>
      </div>
      <?php endif; ?>
      <button type="button" class="btn btn--ghost btn--sm" data-dialog="dlg-calendar" data-new-calendar><?= icon('plus', 'sm') ?> Nuevo calendario</button>
    </div>
    <details class="cal-side-box" open>
      <summary><strong>También mostrar</strong></summary>
      <ul class="cal-list">
        <?php $layerList = ['tasks' => ['flag', $scope === 'personal' ? 'Mis tareas de proyectos' : 'Tareas de proyectos'], 'personal' => ['check-circle', 'Mis tareas personales'], 'sprints' => ['zap', 'Inicio y cierre de sprints'], 'milestones' => ['target', 'Hitos de proyectos (inicio y entrega)']];
          if ($scope === 'personal') unset($layerList['sprints'], $layerList['milestones']);
          if ($scope === 'team') unset($layerList['personal']);
          foreach ($layerList as $k => [$ic, $lbl]): ?>
          <li><label class="cal-check cal-check--layer"><input type="checkbox" data-cal-layer="<?= $k ?>"<?= checked($layers[$k]) ?>> <?= icon($ic, 'sm') ?> <span class="grow"><?= e($lbl) ?></span></label></li>
        <?php endforeach; ?>
      </ul>
      <?php if ($layers['tasks'] && $scope !== 'personal'): ?>
        <nav class="segmented sm mt-sm" aria-label="Qué tareas">
          <a href="<?= e(url('/calendar', ['view' => $view, 'date' => $anchor])) ?>"<?= $tasksFilter === 'all' ? ' aria-current="page"' : '' ?>>Todas</a>
          <a href="<?= e(url('/calendar', ['view' => $view, 'date' => $anchor, 'tasks' => 'mine'])) ?>"<?= $tasksFilter === 'mine' ? ' aria-current="page"' : '' ?>>Asignadas a mí</a>
        </nav>
      <?php endif; ?>
    </details>
    <div class="cal-side-box cal-google" id="cal-google">
      <?php if (!$google): ?>
        <p class="small muted"><strong>Google Calendar</strong> es opcional: el calendario de Borealis funciona sin él.</p>
        <a class="btn btn--sm" href="<?= e(url('/profile')) ?>#google">Conectar desde tu perfil</a>
      <?php elseif ($google['status'] !== 'active'): ?>
        <p class="small" role="alert"><span class="badge danger">Google</span> <?= e($google['last_error'] ?: 'Hay un problema con la conexión.') ?></p>
        <a class="btn btn--sm" href="<?= e(url('/profile')) ?>#google">Revisar la conexión</a>
      <?php else: ?>
        <p class="small muted"><span class="badge badge--google">Google</span> <?= e($google['google_email'] ?? '') ?><br><span data-google-last><?= $google['last_sync_at'] ? 'Última sincronización: ' . e(fmt_date(substr($google['last_sync_at'], 0, 10))) . ' ' . e(substr($google['last_sync_at'], 11, 5)) : 'Aún sin sincronizar' ?></span></p>
        <?php if ($google['last_error']): ?><p class="small" role="alert" style="color:var(--danger-strong)"><?= e($google['last_error']) ?></p><?php endif; ?>
        <button type="button" class="btn btn--sm" data-call="/api/google/sync"><?= icon('repeat', 'sm') ?> Sincronizar ahora</button>
      <?php endif; ?>
    </div>
  </aside>

  <section class="cal-main">
    <div class="toolbar cal-toolbar">
      <div class="btn-group" role="group" aria-label="Navegar">
        <a class="btn btn--sm" href="<?= e(url('/calendar', ['view' => $view, 'date' => $prev] + $keep)) ?>" aria-label="Anterior"><?= icon('chevron-left', 'sm') ?></a>
        <a class="btn btn--sm" href="<?= e(url('/calendar', ['view' => $view, 'date' => today()] + $keep)) ?>">Hoy</a>
        <a class="btn btn--sm" href="<?= e(url('/calendar', ['view' => $view, 'date' => $next] + $keep)) ?>" aria-label="Siguiente"><?= icon('chevron-right', 'sm') ?></a>
      </div>
      <h2 class="cal-heading"><?= e($heading) ?></h2>
      <span class="grow"></span>
      <nav class="segmented" aria-label="Vista" data-tour="cal-views">
        <?php foreach (['day' => 'Día', 'week' => 'Semana', 'month' => 'Mes'] as $k => $lbl): ?>
          <a href="<?= e(url('/calendar', ['view' => $k, 'date' => $anchor] + $keep)) ?>"<?= $view === $k ? ' aria-current="page"' : '' ?>><?= $lbl ?></a>
        <?php endforeach; ?>
      </nav>
    </div>
    <p class="cal-legend small muted" aria-label="Leyenda">
      <span class="cal-legend-i cal-item--event"><?= icon('calendar', 'sm') ?> Evento</span>
      <span class="cal-legend-i cal-item--meeting"><?= icon('users', 'sm') ?> Reunión</span>
      <span class="cal-legend-i cal-item--task"><?= icon('flag', 'sm') ?> Vencimiento de tarea</span>
      <span class="cal-legend-i cal-item--personal"><?= icon('check-circle', 'sm') ?> Tarea personal</span>
      <span class="cal-legend-i cal-item--sprint"><?= icon('zap', 'sm') ?> Sprint</span>
      <span class="cal-legend-i cal-item--milestone"><?= icon('target', 'sm') ?> Hito</span>
      <span class="cal-legend-i"><?= icon('lock', 'sm') ?> Personal</span>
    </p>

    <?php if ($view === 'month'): ?>
      <div class="calendar cal-month" data-calendar data-new-on-click>
        <?php foreach ($dows as $d): ?><div class="dow"><?= $d ?></div><?php endforeach; ?>
        <?php for ($d = $start; $d <= $end; $d = date('Y-m-d', strtotime($d . ' +1 day'))):
          $its = $byDay[$d] ?? [];
          $other = date('m', strtotime($d)) !== date('m', strtotime($anchor)); ?>
          <div class="day<?= $other ? ' other' : '' ?><?= $d === today() ? ' today' : '' ?>" data-day="<?= $d ?>">
            <div class="day-head">
              <a class="num" href="<?= e(url('/calendar', ['view' => 'day', 'date' => $d] + $keep)) ?>" aria-label="<?= e(fmt_date($d, true)) ?>: <?= count($its) ?> elementos. Ver el día"><?= (int)date('j', strtotime($d)) ?></a>
              <?php if ($editableCals): ?><button type="button" class="day-add" data-new-event data-date="<?= $d ?>" aria-label="Nuevo evento el <?= e(fmt_date($d, true)) ?>"><?= icon('plus', 'sm') ?></button><?php endif; ?>
            </div>
            <?php foreach (array_slice($its, 0, 4) as $it) echo $chip($it); ?>
            <?php if (count($its) > 4): ?><a class="cal-more" href="<?= e(url('/calendar', ['view' => 'day', 'date' => $d] + $keep)) ?>">+<?= count($its) - 4 ?> más</a><?php endif; ?>
          </div>
        <?php endfor; ?>
      </div>
    <?php else:
      $days = [];
      for ($d = $start; $d <= $end; $d = date('Y-m-d', strtotime($d . ' +1 day'))) $days[] = $d; ?>
      <div class="tgrid" style="--days:<?= count($days) ?>;--hour:<?= $H ?>px" data-calendar>
        <div class="tg-row tg-head">
          <div class="tg-gutter"></div>
          <?php foreach ($days as $d): ?>
            <a class="tg-dayname<?= $d === today() ? ' today' : '' ?>" href="<?= e(url('/calendar', ['view' => 'day', 'date' => $d] + $keep)) ?>"><span><?= $dows[(int)date('N', strtotime($d)) - 1] ?></span> <strong><?= (int)date('j', strtotime($d)) ?></strong></a>
          <?php endforeach; ?>
        </div>
        <div class="tg-row tg-allday">
          <div class="tg-gutter small muted">Todo el día</div>
          <?php foreach ($days as $d): ?>
            <div class="day tg-allcell" data-day="<?= $d ?>"><?php foreach ($byDay[$d] ?? [] as $it) if (!$isTimed($it)) echo $chip($it); ?></div>
          <?php endforeach; ?>
        </div>
        <div class="tg-body" data-tg-scroll>
          <div class="tg-row" style="height:calc(var(--hour) * 24)">
            <div class="tg-gutter tg-hours"><?php for ($h = 0; $h < 24; $h++): ?><span style="top:<?= $h * $H ?>px"><?= sprintf('%02d:00', $h) ?></span><?php endfor; ?></div>
            <?php foreach ($days as $d):
              // timed events of the day, in side-by-side lanes when they overlap
              $evs = [];
              foreach ($byDay[$d] ?? [] as $it) {
                  if (!$isTimed($it)) continue;
                  $s = substr($it['occ_start'], 0, 10) < $d ? 0 : (int)substr($it['occ_start'], 11, 2) * 60 + (int)substr($it['occ_start'], 14, 2);
                  $en = substr($it['occ_end'], 0, 10) > $d ? 1440 : (int)substr($it['occ_end'], 11, 2) * 60 + (int)substr($it['occ_end'], 14, 2);
                  $evs[] = ['it' => $it, 's' => $s, 'e' => max($en, $s + 20)];
              }
              usort($evs, fn($a, $b) => [$a['s'], -$a['e']] <=> [$b['s'], -$b['e']]);
              $laneEnd = [];
              foreach ($evs as &$x) { $l = 0; while (isset($laneEnd[$l]) && $laneEnd[$l] > $x['s']) $l++; $laneEnd[$l] = $x['e']; $x['lane'] = $l; }
              unset($x);
              $lanes = max(1, count($laneEnd)); ?>
              <div class="tg-col<?= $d === today() ? ' today' : '' ?>" data-day="<?= $d ?>" data-new-on-click aria-label="<?= e(fmt_date($d, true)) ?>">
                <?php if ($d === today()): ?><span class="tg-now" data-tg-now aria-hidden="true"></span><?php endif; ?>
                <?php foreach ($evs as $x): $it = $x['it'];
                  $label = ($it['type'] === 'meeting' ? 'Reunión' : 'Evento') . ': ' . $it['title'] . ', ' . substr($it['occ_start'], 11, 5) . '–' . substr($it['occ_end'], 11, 5) . ' · ' . $it['calendar_name'] . (($it['calendar_kind'] ?? '') === 'personal' ? ' (personal)' : '') . ($it['status'] === 'cancelled' ? ' · cancelado' : ''); ?>
                  <button type="button" class="tg-event cal-item--<?= $it['type'] ?><?= $it['status'] === 'cancelled' ? ' is-cancelled' : '' ?><?= ($it['calendar_kind'] ?? '') === 'personal' ? ' is-personal' : ' is-team' ?>" data-event="<?= (int)$it['id'] ?>"
                    style="--c:<?= e($it['color']) ?>;top:<?= round($x['s'] / 60 * $H) ?>px;height:<?= max(18, round(($x['e'] - $x['s']) / 60 * $H) - 2) ?>px;left:calc(<?= $x['lane'] ?> * 100% / <?= $lanes ?>);width:calc(100% / <?= $lanes ?> - 3px)"
                    title="<?= e($label) ?>" aria-label="<?= e($label) ?>">
                    <strong><?= ($it['calendar_kind'] ?? '') === 'personal' ? icon('lock', 'sm') : '' ?><?= $it['type'] === 'meeting' ? icon('users', 'sm') : '' ?><?= $it['recurrence'] !== 'none' ? icon('repeat', 'sm') : '' ?><?= e($it['title']) ?></strong>
                    <span><?= e(substr($it['occ_start'], 11, 5)) ?>–<?= e(substr($it['occ_end'], 11, 5)) ?><?= $it['location'] ? ' · ' . e($it['location']) : '' ?></span>
                  </button>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>
    <p class="muted small mt"><?= $editableCals ? 'Haz clic en un día o en una hora libre para crear un evento. ' : '' ?>Arrastra un evento o una tarea a otro día para cambiar su fecha. Horario: <?= e((string)config('app.timezone', 'UTC')) ?>.</p>
  </section>
</div>

<div id="cal-dialogs" data-region><?= partial('calendar-dialogs', compact('calendars', 'editableCals', 'people', 'projects', 'teams')) ?></div>
