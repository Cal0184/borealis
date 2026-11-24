<?= partial('project-header', compact('project', 'modules', 'tab', 'can')) ?>
<?php $pid = (int)$project['id']; $canEdit = $can('task.edit'); ?>
<?= partial('task-filters', ['action' => '/projects/' . $pid . '/board', 'filters' => $filters, 'members' => $members, 'priorities' => $priorities, 'labels' => $labels, 'epics' => $epics, 'sprints' => $sprints, 'modules' => $modules, 'showSprint' => true]) ?>
<?php
$stripPeople = $sprintPeople ?: $boardPeople;
$pill = function (array $bp, bool $withLoad) use ($movableIds): string {
    $uid = (int)$bp['id'];
    $drag = in_array($uid, $movableIds, true) && !($withLoad && $bp['full']);
    $free = $withLoad && $bp['capacity'] !== null ? (string)round((float)$bp['free'], 2) : '';
    $label = $drag ? 'Arrastrar a ' . $bp['name'] : $bp['name'] . ($withLoad && $bp['full'] ? ': sin horas disponibles' : '');
    return '<' . ($drag ? 'button type="button" draggable="true"' : 'span aria-disabled="true"') . ' class="person-drag person-pill' . ($drag ? '' : ' is-static') . ($withLoad && $bp['full'] ? ' is-full' : '') . '" data-person="' . $uid . '" data-name="' . e(strtok($bp['name'], ' ')) . '" data-free="' . e($free) . '" aria-label="' . e($label) . '">'
        . avatar($bp, 'sm') . '<span class="pill-name">' . e(strtok($bp['name'], ' ')) . '</span>' . ($withLoad ? partial('load-meter', ['p' => $bp, 'compact' => true]) : '') . '</' . ($drag ? 'button' : 'span') . '>';
};
?>
<?php if ($canEdit && $stripPeople): ?>
<section class="people-strip<?= $sprintPeople ? ' has-load' : '' ?>" id="people-strip" aria-label="Personas del tablero" data-people-drop>
  <div class="people-strip-head">
    <span class="muted small"><?= icon('users', 'sm') ?> <?= $boardPeople ? 'Arrastra una persona a una tarjeta para asignarla, de una tarjeta a otra para moverla, o de vuelta aquí para quitarla.' : 'Carga de trabajo del sprint.' ?><?= $boardSprint ? ' Horas de «' . e($boardSprint['name']) . '».' : '' ?></span>
    <span class="save-state" data-save-state aria-live="polite"></span>
  </div>
  <?php if ($sprintPeople): $free = array_filter($sprintPeople, fn($x) => !$x['full']); $full = array_filter($sprintPeople, fn($x) => $x['full']); ?>
    <div class="people-group"><span class="people-group-title">Disponibles <span class="muted"><?= count($free) ?></span></span>
      <div class="people-strip-list"><?php foreach ($free as $bp) echo $pill($bp, true); ?><?php if (!$free): ?><span class="muted small">Nadie tiene horas libres en este sprint.</span><?php endif; ?></div></div>
    <?php if ($full): ?><div class="people-group is-full"><span class="people-group-title">Sin disponibilidad <span class="muted"><?= count($full) ?></span></span>
      <div class="people-strip-list"><?php foreach ($full as $bp) echo $pill($bp, true); ?></div></div><?php endif; ?>
  <?php else: ?>
    <div class="people-strip-list"><?php foreach ($boardPeople as $bp) echo $pill($bp, false); ?></div>
  <?php endif; ?>
  <div class="people-drop-hint" aria-hidden="true"><?= icon('x', 'sm') ?> Suelta aquí para quitarla de la tarea</div>
</section>
<?php endif; ?>
<?php if ($canEdit && !$boardPeople && $can('project.manage')): ?>
<p class="muted small people-hint"><?= icon('users', 'sm') ?> ¿Quieres mover personas entre tarjetas? Marca el proyecto como <a href="<?= e(url('/projects/' . $pid . '/settings')) ?>">polivalente</a> o un equipo participante como polivalente.</p>
<?php endif; ?>
<div class="board-wrap" id="board-scroll" data-keep-scroll>
  <div class="board" data-board="<?= $pid ?>" role="list" aria-label="Tablero de <?= e($project['name']) ?>">
    <?php foreach ($columns as $sid => $col): $s = $col['status']; $n = count($col['tasks']); $over = $s['wip_limit'] && $n > (int)$s['wip_limit']; ?>
      <section class="column" data-column="<?= (int)$sid ?>" role="listitem" aria-label="<?= e($s['name']) ?>">
        <header class="column-head">
          <h3><span class="dot" style="--c:<?= e($s['color']) ?>"></span><span class="truncate"><?= e($s['name']) ?></span></h3>
          <span class="count<?= $over ? ' over' : '' ?>" title="<?= $s['wip_limit'] ? 'Límite: ' . (int)$s['wip_limit'] : '' ?>"><?= $n ?><?= $s['wip_limit'] ? '/' . (int)$s['wip_limit'] : '' ?></span>
          <?php if ($can('project.manage')): ?>
            <div class="menu-wrap" style="margin-left:auto">
              <button class="btn btn--ghost btn--icon btn--sm" data-menu aria-label="Opciones de la columna <?= e($s['name']) ?>" aria-haspopup="true" aria-expanded="false"><?= icon('more') ?></button>
              <div class="menu" role="menu">
                <button role="menuitem" data-call="/api/projects/<?= $pid ?>/statuses/<?= (int)$sid ?>/move" data-body='{"dir":-1}' data-silent><?= icon('arrow-left') ?> Mover a la izquierda</button>
                <button role="menuitem" data-call="/api/projects/<?= $pid ?>/statuses/<?= (int)$sid ?>/move" data-body='{"dir":1}' data-silent><?= icon('arrow-right') ?> Mover a la derecha</button>
                <a role="menuitem" href="<?= e(url('/projects/' . $pid . '/settings', ['section' => 'statuses'])) ?>"><?= icon('edit') ?> Renombrar y configurar</a>
              </div>
            </div>
          <?php endif; ?>
        </header>
        <div class="column-body" data-column-body="<?= (int)$sid ?>">
          <?php foreach ($col['tasks'] as $t): ?><?= partial('task-card', ['t' => $t, 'canEdit' => $canEdit, 'modules' => $modules, 'movable' => $movableIds]) ?><?php endforeach; ?>
        </div>
        <?php if ($can('task.create')): ?>
          <div class="column-foot">
            <form class="column-add" id="add-<?= (int)$sid ?>" data-api="/api/projects/<?= $pid ?>/tasks" data-reset data-keep-open>
              <input type="hidden" name="status_id" value="<?= (int)$sid ?>">
              <?php if (!empty($filters['sprint_id']) && $filters['sprint_id'] !== 'none'): ?><input type="hidden" name="sprint_id" value="<?= e($filters['sprint_id']) ?>"><?php endif; ?>
              <label class="sr-only" for="add-title-<?= (int)$sid ?>">Título de la tarea</label>
              <textarea class="textarea" id="add-title-<?= (int)$sid ?>" name="title" required maxlength="255" placeholder="Título de la tarea" data-enter-submit></textarea>
              <div class="row"><button class="btn btn--primary btn--sm" type="submit">Añadir</button><button class="btn btn--ghost btn--sm" type="button" data-toggle-class="#add-<?= (int)$sid ?>" data-class="open">Cancelar</button></div>
            </form>
            <button class="btn btn--ghost btn--sm" type="button" data-toggle-class="#add-<?= (int)$sid ?>" data-class="open" data-focus="#add-title-<?= (int)$sid ?>"><?= icon('plus', 'sm') ?> Añadir tarea</button>
          </div>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
  </div>
</div>
<?php if (!$taskCount && empty(array_filter($filters))): ?><p class="muted small">Consejo: arrastra las tarjetas entre columnas, o usa su menú <?= icon('more', 'sm') ?> para moverlas con el teclado.</p><?php endif; ?>
