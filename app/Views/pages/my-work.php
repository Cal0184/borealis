<?php use Borealis\Controllers\HomeController; ?>
<div class="page-head">
  <div><h1>Mi trabajo</h1><p class="sub">Todo lo que tienes asignado, de todos tus proyectos.</p></div>
</div>
<nav class="segmented mb" aria-label="Filtrar por estado">
  <?php foreach (HomeController::MY_TABS as $k => $label): ?>
    <a href="<?= e(url('/my-work', ['tab' => $k] + array_intersect_key($filters, array_flip(['q', 'project_id', 'priority_id'])))) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?><?php if ($counts[$k] !== null): ?> <span class="count"><?= (int)$counts[$k] ?></span><?php endif; ?></a>
  <?php endforeach; ?>
</nav>
<form class="toolbar" method="get" action="<?= e(url('/my-work')) ?>" data-filter-form>
  <?php if (!config('app.pretty_urls', true)): ?><input type="hidden" name="r" value="/my-work"><?php endif; ?>
  <input type="hidden" name="tab" value="<?= e($tab) ?>">
  <label class="sr-only" for="mw-q">Buscar</label>
  <input class="input search-input" type="search" id="mw-q" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Buscar por título o clave">
  <label class="sr-only" for="mw-p">Proyecto</label>
  <select class="select<?= !empty($filters['project_id']) ? ' filter-active' : '' ?>" id="mw-p" name="project_id" data-autosubmit>
    <option value="">Todos los proyectos</option>
    <?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>"<?= selected($p['id'], $filters['project_id'] ?? '') ?>><?= e($p['name']) ?></option><?php endforeach; ?>
  </select>
  <label class="sr-only" for="mw-pr">Prioridad</label>
  <select class="select<?= !empty($filters['priority_id']) ? ' filter-active' : '' ?>" id="mw-pr" name="priority_id" data-autosubmit>
    <option value="">Cualquier prioridad</option>
    <?php foreach ($priorities as $pr): ?><option value="<?= (int)$pr['id'] ?>"<?= selected($pr['id'], $filters['priority_id'] ?? '') ?>><?= e($pr['name']) ?></option><?php endforeach; ?>
  </select>
  <label class="sr-only" for="mw-s">Ordenar</label>
  <select class="select" id="mw-s" name="sort" data-autosubmit>
    <?php foreach (['due' => 'Por vencimiento', 'priority' => 'Por prioridad', 'updated' => 'Actualizadas recientemente', 'key' => 'Por clave'] as $k => $v): ?><option value="<?= $k ?>"<?= selected($k, $filters['sort'] ?? 'due') ?>><?= e($v) ?></option><?php endforeach; ?>
  </select>
  <button class="btn" type="submit"><?= icon('filter', 'sm') ?> Aplicar</button>
  <?php if (array_filter(array_intersect_key($filters, array_flip(['q', 'project_id', 'priority_id'])))): ?><a class="btn btn--ghost" href="<?= e(url('/my-work', ['tab' => $tab])) ?>">Limpiar</a><?php endif; ?>
</form>

<div class="card">
  <?php if ($tasks): ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th class="col-check"><span class="sr-only">Finalizar</span></th><th>Tarea</th><th class="hide-sm">Proyecto</th><th>Estado</th><th class="hide-md">Prioridad</th><th>Vencimiento</th></tr></thead>
      <tbody>
      <?php foreach ($tasks as $t): $done = $t['status_category'] === 'done'; $canEdit = $editable[$t['project_id']] ?? false; ?>
        <tr class="<?= $done ? 'is-done' : '' ?>">
          <td><button type="button" class="subtask-check<?= $done ? ' done' : '' ?>" data-call="/api/tasks/<?= (int)$t['id'] ?>/done" data-body='{"done":<?= $done ? 'false' : 'true' ?>}' aria-label="<?= $done ? 'Reabrir' : 'Finalizar' ?> <?= e($t['task_key']) ?>"<?= $canEdit ? '' : ' disabled' ?>><?= icon('check') ?></button></td>
          <td style="min-width:220px"><span class="key"><?= e($t['task_key']) ?></span> <button class="task-title" data-open-task="<?= e($t['task_key']) ?>"><?= e($t['title']) ?></button>
            <?php if ($t['is_blocked'] && !$done): ?> <span class="blocked-tag"><?= icon('block', 'sm') ?> Bloqueada</span><?php endif; ?></td>
          <td class="hide-sm"><a href="<?= e(url('/projects/' . $t['project_id'])) ?>" class="row"><span class="dot" style="width:8px;height:8px;border-radius:3px;background:<?= e($t['project_color']) ?>"></span><?= e($t['project_name']) ?></a></td>
          <td>
            <label class="sr-only" for="mw-st-<?= (int)$t['id'] ?>">Estado de <?= e($t['task_key']) ?></label>
            <select class="select sm" id="mw-st-<?= (int)$t['id'] ?>" name="status_id" data-patch="/api/tasks/<?= (int)$t['id'] ?>"<?= $canEdit ? '' : ' disabled' ?>>
              <?php foreach ($statuses[$t['project_id']] as $s): ?><option value="<?= (int)$s['id'] ?>"<?= selected($s['id'], $t['status_id']) ?>><?= e($s['name']) ?></option><?php endforeach; ?>
            </select>
          </td>
          <td class="hide-md"><?php if ($t['priority_name']): ?><span class="prio" style="--c:<?= e($t['priority_color']) ?>"><?= e($t['priority_name']) ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
          <td>
            <label class="sr-only" for="mw-due-<?= (int)$t['id'] ?>">Vencimiento de <?= e($t['task_key']) ?></label>
            <input class="input sm <?= due_state($t['due_date'], $done) ?>" style="width:150px<?= due_state($t['due_date'], $done) === 'overdue' ? ';color:var(--danger-strong);font-weight:600' : '' ?>" type="date" id="mw-due-<?= (int)$t['id'] ?>" name="due_date" value="<?= e($t['due_date']) ?>" data-patch="/api/tasks/<?= (int)$t['id'] ?>"<?= $canEdit ? '' : ' disabled' ?>>
            <?= late_tag($t, $done) ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <div class="empty"><?= icon('check-circle') ?><h3><?= $tab === 'done' ? 'Aún no has finalizado tareas' : 'Nada por aquí' ?></h3><p><?= $tab === 'overdue' ? 'No tienes tareas vencidas. Buen ritmo.' : ($tab === 'today' ? 'Nada vence hoy.' . ($counts['overdue'] ? ' Tienes ' . (int)$counts['overdue'] . ' ' . ((int)$counts['overdue'] === 1 ? 'vencida' : 'vencidas') . ' en «Vencidas».' : '') : 'No hay tareas que coincidan con este filtro.') ?></p></div>
  <?php endif; ?>
</div>
