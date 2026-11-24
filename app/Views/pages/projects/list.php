<?= partial('project-header', compact('project', 'modules', 'tab', 'can')) ?>
<?php
$pid = (int)$project['id'];
$canEdit = $can('task.edit');
$sortLink = function (string $key, string $label) use ($filters, $sort, $pid) {
    $dir = ($sort === $key && ($filters['dir'] ?? 'asc') === 'asc') ? 'desc' : 'asc';
    $arrow = $sort === $key ? icon(($filters['dir'] ?? 'asc') === 'asc' ? 'arrow-up' : 'arrow-down', 'sm') : '';
    return '<a href="' . e(url('/projects/' . $pid . '/list', ['sort' => $key, 'dir' => $dir] + array_diff_key($filters, ['sort' => 1, 'dir' => 1]))) . '">' . e($label) . $arrow . '</a>';
};
$showSeg = '<nav class="segmented" aria-label="Mostrar">';
foreach (['open' => 'Abiertas', 'done' => 'Finalizadas', 'all' => 'Todas'] as $k => $v) {
    $showSeg .= '<a href="' . e(url('/projects/' . $pid . '/list', ['show' => $k] + array_diff_key($filters, ['show' => 1]))) . '"' . ($show === $k ? ' aria-current="page"' : '') . '>' . e($v) . '</a>';
}
$showSeg .= '</nav>';
?>
<?= partial('task-filters', ['action' => '/projects/' . $pid . '/list', 'filters' => $filters, 'members' => $members, 'priorities' => $priorities, 'labels' => $labels, 'epics' => $epics, 'sprints' => $sprints, 'modules' => $modules, 'showSprint' => true, 'keep' => ['show', 'sort', 'dir'], 'extra' => $showSeg]) ?>

<div class="card" data-selectable>
  <?php if ($can('task.create')): ?>
    <form class="quick-add" data-api="/api/projects/<?= $pid ?>/tasks" data-reset data-keep-open>
      <?= icon('plus') ?>
      <label class="sr-only" for="qa-title">Nueva tarea</label>
      <input class="input" id="qa-title" name="title" required maxlength="255" placeholder="Escribe una tarea y pulsa Enter" autocomplete="off">
      <button class="btn btn--primary btn--sm" type="submit">Crear</button>
    </form>
  <?php endif; ?>
  <?php if ($tasks): ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr>
        <?php if ($canEdit): ?><th class="col-check"><label class="sr-only" for="sel-all">Seleccionar todas</label><input type="checkbox" id="sel-all" data-select-all></th><?php endif; ?>
        <th><?= $sortLink('key', 'Clave') ?></th>
        <th><?= $sortLink('title', 'Título') ?></th>
        <th><?= $sortLink('status', 'Estado') ?></th>
        <th><?= $sortLink('assignee', 'Responsable') ?></th>
        <th class="hide-sm"><?= $sortLink('priority', 'Prioridad') ?></th>
        <th><?= $sortLink('due', 'Vence') ?></th>
        <?php if ($modules['sprints']): ?><th class="hide-md">Sprint</th><?php endif; ?>
        <?php if ($modules['estimates']): ?><th class="hide-md">Pts</th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($tasks as $t): $tid = (int)$t['id']; $done = $t['status_category'] === 'done'; $dis = $canEdit ? '' : ' disabled'; $patch = '/api/tasks/' . $tid; ?>
        <tr class="<?= $done ? 'is-done' : '' ?>" data-row="<?= $tid ?>" data-row-open="<?= e($t['task_key']) ?>">
          <?php if ($canEdit): ?><td><label class="sr-only" for="sel-<?= $tid ?>">Seleccionar <?= e($t['task_key']) ?></label><input type="checkbox" id="sel-<?= $tid ?>" data-select="<?= $tid ?>"></td><?php endif; ?>
          <td><button type="button" class="key key-link" data-open-task="<?= e($t['task_key']) ?>"><?= e($t['task_key']) ?></button></td>
          <td style="min-width:240px">
            <div class="row">
              <button type="button" class="task-title task-title-open" id="lt-<?= $tid ?>" data-open-task="<?= e($t['task_key']) ?>" title="Abrir para ver y editar"><?= e($t['title']) ?></button>
            </div>
            <div class="row wrap" style="gap:4px;padding-left:6px">
              <?php if ($t['is_blocked'] && !$done): ?><span class="blocked-tag"><?= icon('block', 'sm') ?> Bloqueada</span><?php endif; ?>
              <?php foreach ($t['labels'] as $l): ?><span class="label-chip" style="--c:<?= e($l['color']) ?>"><?= e($l['name']) ?></span><?php endforeach; ?>
              <?php if ($t['subtask_count']): ?><span class="muted small row"><?= icon('subtask', 'sm') ?><?= (int)$t['subtask_done'] ?>/<?= (int)$t['subtask_count'] ?></span><?php endif; ?>
            </div>
          </td>
          <td><label class="sr-only" for="ls-<?= $tid ?>">Estado</label><select class="select sm" id="ls-<?= $tid ?>" name="status_id" data-patch="<?= e($patch) ?>"<?= $dis ?>><?php foreach ($statuses as $s): ?><option value="<?= (int)$s['id'] ?>"<?= selected($s['id'], $t['status_id']) ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></td>
          <td><label class="sr-only" for="la-<?= $tid ?>">Responsable</label><select class="select sm" id="la-<?= $tid ?>" name="assignee_id" data-patch="<?= e($patch) ?>"<?= $dis ?>><option value="">Sin asignar</option><?php foreach ($assignable as $m): ?><option value="<?= (int)$m['id'] ?>"<?= selected($m['id'], $t['assignee_id']) ?>><?= e($m['name']) ?></option><?php endforeach; ?></select></td>
          <td class="hide-sm"><label class="sr-only" for="lp-<?= $tid ?>">Prioridad</label><select class="select sm" id="lp-<?= $tid ?>" name="priority_id" data-patch="<?= e($patch) ?>"<?= $dis ?>><option value="">—</option><?php foreach ($priorities as $pr): ?><option value="<?= (int)$pr['id'] ?>"<?= selected($pr['id'], $t['priority_id']) ?>><?= e($pr['name']) ?></option><?php endforeach; ?></select></td>
          <td><label class="sr-only" for="ld-<?= $tid ?>">Vencimiento</label><input class="input sm" type="date" id="ld-<?= $tid ?>" name="due_date" value="<?= e($t['due_date']) ?>" data-patch="<?= e($patch) ?>"<?= $dis ?> style="width:140px<?= due_state($t['due_date'], $done) === 'overdue' ? ';color:var(--danger-strong);font-weight:600' : '' ?>"></td>
          <?php if ($modules['sprints']): ?><td class="hide-md"><label class="sr-only" for="lsp-<?= $tid ?>">Sprint</label><select class="select sm" id="lsp-<?= $tid ?>" name="sprint_id" data-patch="<?= e($patch) ?>"<?= $dis ?>><option value="">Backlog</option><?php foreach ($sprints as $sp): if ($sp['state'] === 'completed' && (int)$sp['id'] !== (int)$t['sprint_id']) continue; ?><option value="<?= (int)$sp['id'] ?>"<?= selected($sp['id'], $t['sprint_id']) ?>><?= e($sp['name']) ?></option><?php endforeach; ?></select></td><?php endif; ?>
          <?php if ($modules['estimates']): ?><td class="hide-md"><label class="sr-only" for="le-<?= $tid ?>">Estimación</label><input class="input sm" type="number" min="0" step="0.5" id="le-<?= $tid ?>" name="estimate" value="<?= e($t['estimate'] !== null ? rtrim(rtrim((string)$t['estimate'], '0'), '.') : '') ?>" data-patch="<?= e($patch) ?>"<?= $dis ?> style="width:70px"></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <div class="empty"><?= icon('list') ?><h3>No hay tareas<?= array_filter(array_diff_key($filters, ['show' => 1, 'sort' => 1, 'dir' => 1])) ? ' con estos filtros' : '' ?></h3><p>Escribe un título arriba para crear la primera.</p></div>
  <?php endif; ?>
</div>
<?php if ($canEdit): ?>
<?= partial('bulk-bar', compact('statuses', 'assignable', 'priorities', 'sprints', 'modules', 'can')) ?>
<?php endif; ?>
