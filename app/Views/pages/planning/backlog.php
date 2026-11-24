<?= partial('project-header', compact('project', 'modules', 'tab', 'can')) ?>
<?php $pid = (int)$project['id']; $canEdit = $can('task.edit'); ?>
<?= partial('task-filters', ['action' => '/projects/' . $pid . '/backlog', 'filters' => $filters, 'members' => $members, 'priorities' => $priorities, 'labels' => $labels, 'epics' => $epics, 'sprints' => $sprints, 'modules' => $modules, 'showSprint' => false]) ?>
<div class="stack" data-selectable>
<?php foreach ($groups as $g): $sp = $g['sprint']; $gid = $sp ? (int)$sp['id'] : 0; $pts = array_sum(array_map(fn($t) => (float)$t['estimate'], $g['tasks'])); ?>
  <section class="card" data-backlog-group="<?= $gid ?>">
    <div class="card-head">
      <h2><?= $sp ? icon('zap') . e($sp['name']) : icon('layers') . 'Backlog' ?>
        <?php if ($sp): ?><span class="badge<?= $sp['state'] === 'active' ? ' success' : '' ?>"><?= $sp['state'] === 'active' ? 'Activo' : 'Planificado' ?></span><?php endif; ?>
        <span class="muted small"><?= count($g['tasks']) ?> tareas<?= $modules['estimates'] ? ' · ' . rtrim(rtrim(number_format($pts, 1, '.', ''), '0'), '.') . ' pts' : '' ?></span></h2>
      <?php if ($sp): ?><span class="muted small"><?= $sp['start_date'] ? e(fmt_date($sp['start_date'])) . ' – ' . e(fmt_date($sp['end_date'])) : 'Sin fechas' ?></span><?php endif; ?>
    </div>
    <ul class="list" data-backlog-list="<?= $gid ?>" aria-label="<?= $sp ? e($sp['name']) : 'Backlog' ?>">
      <?php foreach ($g['tasks'] as $t): $tid = (int)$t['id']; $done = $t['status_category'] === 'done'; ?>
        <li data-backlog-item="<?= $tid ?>"<?= $canEdit ? ' data-draggable' : '' ?> tabindex="0" aria-label="<?= e($t['task_key'] . ': ' . $t['title']) ?>">
          <?php if ($canEdit): ?><span class="muted" aria-hidden="true" style="cursor:grab"><?= icon('grip', 'sm') ?></span><label class="sr-only" for="bsel-<?= $tid ?>">Seleccionar</label><input type="checkbox" id="bsel-<?= $tid ?>" data-select="<?= $tid ?>"><?php endif; ?>
          <span class="key"><?= e($t['task_key']) ?></span>
          <button class="task-title truncate" style="flex:1;<?= $done ? 'text-decoration:line-through;color:var(--text-3)' : '' ?>" data-open-task="<?= e($t['task_key']) ?>"><?= e($t['title']) ?></button>
          <?php if ($t['is_blocked']): ?><span class="blocked-tag hide-sm"><?= icon('block', 'sm') ?></span><?php endif; ?>
          <?php if ($t['priority_name']): ?><span class="prio hide-sm" style="--c:<?= e($t['priority_color']) ?>"><?= e($t['priority_name']) ?></span><?php endif; ?>
          <span class="badge hide-sm"><span class="dot" style="--c:<?= e($t['status_color']) ?>"></span><?= e($t['status_name']) ?></span>
          <?php if ($modules['estimates']): ?><span class="badge"><?= $t['estimate'] !== null ? e(rtrim(rtrim((string)$t['estimate'], '0'), '.')) : '–' ?></span><?php endif; ?>
          <?= avatar($t['assignee_id'] ? ['id' => $t['assignee_id'], 'name' => $t['assignee_name'], 'avatar_color' => $t['assignee_color']] : null, 'sm') ?>
          <?php if ($canEdit): ?>
            <div class="menu-wrap">
              <button class="btn btn--ghost btn--icon btn--sm" data-menu aria-label="Ordenar <?= e($t['task_key']) ?>" aria-haspopup="true" aria-expanded="false"><?= icon('more') ?></button>
              <div class="menu" role="menu">
                <button role="menuitem" data-backlog-move="up"><?= icon('arrow-up') ?> Subir prioridad</button>
                <button role="menuitem" data-backlog-move="down"><?= icon('arrow-down') ?> Bajar prioridad</button>
                <button role="menuitem" data-backlog-move="top"><?= icon('arrow-up') ?> Al principio</button>
                <?php if ($modules['sprints']): ?><hr><div class="menu-title">Mover a</div>
                  <?php if ($sp): ?><button role="menuitem" data-call="/api/tasks/<?= $tid ?>" data-method="PATCH" data-body='{"sprint_id":null}'><?= icon('layers') ?> Backlog</button><?php endif; ?>
                  <?php foreach ($sprints as $o): if ($o['state'] === 'completed' || ($sp && (int)$o['id'] === (int)$sp['id'])) continue; ?>
                    <button role="menuitem" data-call="/api/tasks/<?= $tid ?>" data-method="PATCH" data-body='{"sprint_id":<?= (int)$o['id'] ?>}'><?= icon('zap') ?> <?= e($o['name']) ?></button>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
      <?php if (!$g['tasks']): ?><li class="empty" data-empty><?= $sp ? 'Arrastra tareas aquí o usa «Mover a».' : 'El backlog está vacío.' ?></li><?php endif; ?>
    </ul>
    <?php if (!$sp && $can('task.create')): ?>
      <form class="quick-add" data-api="/api/projects/<?= $pid ?>/tasks" data-reset data-keep-open style="border-top:1px solid var(--border);border-bottom:0">
        <?= icon('plus') ?><label class="sr-only" for="bl-new">Nueva tarea</label>
        <input class="input" id="bl-new" name="title" required maxlength="255" placeholder="Añadir al backlog" autocomplete="off">
        <button class="btn btn--primary btn--sm" type="submit">Crear</button>
      </form>
    <?php endif; ?>
  </section>
<?php endforeach; ?>
</div>
<?php if ($canEdit): ?><?= partial('bulk-bar', compact('statuses', 'assignable', 'priorities', 'sprints', 'modules', 'can')) ?><?php endif; ?>
