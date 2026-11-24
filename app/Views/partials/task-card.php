<?php
/** @var array $t @var bool $canEdit @var array $modules */
$done = $t['status_category'] === 'done';
$ds = due_state($t['due_date'], $done);
?>
<article class="task-card<?= $t['is_blocked'] && !$done ? ' is-blocked' : '' ?><?= $done ? ' is-done' : '' ?>" tabindex="0"
  data-task-id="<?= (int)$t['id'] ?>" data-task-key="<?= e($t['task_key']) ?>" data-status-id="<?= (int)$t['status_id'] ?>" data-hours="<?= $t['estimate_hours'] !== null && !$t['parent_id'] ? e((string)(float)$t['estimate_hours']) : '' ?>" data-people="<?= ($t['assignee_id'] ? 1 : 0) + count($t['co_assignees'] ?? []) ?>"<?= $canEdit ? ' data-draggable' : '' ?>
  aria-label="<?= e($t['task_key'] . ': ' . $t['title']) ?>">
  <div class="task-card-top">
    <span class="key"><?= e($t['task_key']) ?></span>
    <?php if ($t['is_blocked'] && !$done): ?><span class="blocked-tag" title="<?= e($t['blocked_reason'] ?: 'Bloqueada') ?>"><?= icon('block', 'sm') ?> Bloqueada</span><?php endif; ?>
    <button class="btn btn--sm btn--open card-open" type="button" data-open-task="<?= e($t['task_key']) ?>" aria-label="Abrir <?= e($t['task_key']) ?>"><?= icon('external', 'sm') ?> Abrir</button>
    <?php if ($canEdit): ?>
      <div class="menu-wrap">
        <button class="btn btn--ghost btn--icon btn--sm" type="button" data-menu aria-label="Mover <?= e($t['task_key']) ?>" aria-haspopup="true" aria-expanded="false"><?= icon('more') ?></button>
        <div class="menu" role="menu">
          <div class="menu-title">Mover</div>
          <button type="button" role="menuitem" data-move="prev"><?= icon('arrow-left') ?> Columna anterior</button>
          <button type="button" role="menuitem" data-move="next"><?= icon('arrow-right') ?> Columna siguiente</button>
          <button type="button" role="menuitem" data-move="up"><?= icon('arrow-up') ?> Subir</button>
          <button type="button" role="menuitem" data-move="down"><?= icon('arrow-down') ?> Bajar</button>
          <hr>
          <button type="button" role="menuitem" data-open-task="<?= e($t['task_key']) ?>"><?= icon('edit') ?> Abrir y editar</button>
        </div>
      </div>
    <?php endif; ?>
  </div>
  <div class="task-card-title"><?= e($t['title']) ?></div>
  <?php if ($t['labels'] || ($t['epic_name'] ?? null)): ?>
    <div class="labels">
      <?php if ($t['epic_name'] && !empty($modules['epics'])): ?><span class="label-chip" style="--c:<?= e($t['epic_color']) ?>"><?= icon('flag', 'sm') ?>&nbsp;<?= e($t['epic_name']) ?></span><?php endif; ?>
      <?php foreach ($t['labels'] as $l): ?><span class="label-chip" style="--c:<?= e($l['color']) ?>"><?= e($l['name']) ?></span><?php endforeach; ?>
    </div>
  <?php endif; ?>
  <div class="meta">
    <?php if ($t['priority_name']): ?><span class="prio" style="--c:<?= e($t['priority_color']) ?>"><?= e($t['priority_name']) ?></span><?php endif; ?>
    <?php if ($t['due_date']): ?><span class="due <?= $ds ?>"><?= icon('calendar', 'sm') ?><?= e(fmt_date($t['due_date'])) ?><?= !empty($t['due_time']) ? ' · ' . e(substr((string)$t['due_time'], 0, 5)) : '' ?></span><?php endif; ?>
    <?= late_tag($t) ?>
    <?php if ($t['subtask_count']): ?><span class="row" title="Subtareas"><?= icon('subtask', 'sm') ?><?= (int)$t['subtask_done'] ?>/<?= (int)$t['subtask_count'] ?></span><?php endif; ?>
    <?php if ($t['comment_count']): ?><span class="row" title="Comentarios"><?= icon('message', 'sm') ?><?= (int)$t['comment_count'] ?></span><?php endif; ?>
    <?php if ($t['file_count']): ?><span class="row" title="Archivos"><?= icon('clip', 'sm') ?><?= (int)$t['file_count'] ?></span><?php endif; ?>
    <?php if (!empty($modules['estimates']) && $t['estimate'] !== null): ?><span class="badge" title="Estimación"><?= e(rtrim(rtrim((string)$t['estimate'], '0'), '.')) ?> pts</span><?php endif; ?>
    <?php
      $people = $t['assignee_id'] ? [['id' => (int)$t['assignee_id'], 'name' => $t['assignee_name'], 'avatar_color' => $t['assignee_color']]] : [];
      foreach ($t['co_assignees'] ?? [] as $co) $people[] = ['id' => (int)$co['id'], 'name' => $co['name'], 'avatar_color' => $co['avatar_color']];
      $movable = $movable ?? [];
    ?>
    <span class="push avatar-stack" data-card-people>
      <?php if (!empty($t['team_name'])): ?><span class="team-chip" style="--c:<?= e($t['team_color'] ?? '#10B981') ?>" title="Equipo responsable: <?= e($t['team_name']) ?>"><?= icon('users', 'sm') ?><span class="truncate"><?= e($t['team_name']) ?></span></span><?php endif; ?>
      <?php foreach (array_slice($people, 0, 3) as $pp): ?>
        <?php if ($canEdit && in_array($pp['id'], $movable, true)): ?><button type="button" class="person-drag" draggable="true" data-person="<?= $pp['id'] ?>" data-from-task="<?= (int)$t['id'] ?>" aria-label="<?= e($pp['name']) ?>: arrastra a otra tarjeta para moverla, o a la fila de personas para quitarla"><?= avatar($pp, 'sm') ?></button>
        <?php else: ?><?= avatar($pp, 'sm') ?><?php endif; ?>
      <?php endforeach; ?>
      <?php if (count($people) > 3): ?><span class="avatar sm avatar--more" title="<?= e(implode(', ', array_column(array_slice($people, 3), 'name'))) ?>">+<?= count($people) - 3 ?></span><?php endif; ?>
      <?php if (!$people && empty($t['team_name'])): ?><?= avatar(null, 'sm') ?><?php endif; ?>
    </span>
  </div>
</article>
