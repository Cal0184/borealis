<?php
use Borealis\Controllers\CalendarController;
use Borealis\Services\PersonalTasks;

$prioColor = ['none' => '', 'low' => '#0EA5E9', 'medium' => '#F59E0B', 'high' => '#EF4444'];
$openId = (int)($filters['open'] ?? 0);
/** One personal task row (top level or subtask). */
$row = function (array $t, bool $sub = false) use (&$row, $prioColor, $openId) {
    $id = (int)$t['id'];
    $done = $t['completed_at'] !== null;
    $state = due_state($t['due_date'], $done);
    ob_start(); ?>
    <li class="ptask<?= $done ? ' is-done' : '' ?><?= $sub ? ' ptask--sub' : '' ?>" data-ptask="<?= $id ?>" data-draggable id="ptask-<?= $id ?>">
      <div class="ptask-row">
        <span class="ptask-grip" aria-hidden="true" title="Arrastra para ordenar"><?= icon('grip', 'sm') ?></span>
        <button type="button" class="subtask-check<?= $done ? ' done' : '' ?>" data-call="/api/my-tasks/<?= $id ?>" data-method="PATCH" data-body='{"done":<?= $done ? 'false' : 'true' ?>}' aria-label="<?= $done ? 'Reabrir' : 'Completar' ?> «<?= e($t['title']) ?>»"><?= icon('check') ?></button>
        <label class="sr-only" for="pt-title-<?= $id ?>">Título</label>
        <input class="input ptask-title" id="pt-title-<?= $id ?>" name="title" value="<?= e($t['title']) ?>" maxlength="255" required data-patch="/api/my-tasks/<?= $id ?>">
        <?php if ($t['google_task_id']): ?><span class="badge badge--google" title="Sincronizada con Google Tasks">G</span><?php endif; ?>
        <label class="sr-only" for="pt-prio-<?= $id ?>">Prioridad</label>
        <select class="select sm ptask-prio" id="pt-prio-<?= $id ?>" name="priority" data-patch="/api/my-tasks/<?= $id ?>" style="--c:<?= e($prioColor[$t['priority']] ?: 'var(--border-strong)') ?>">
          <?php foreach (PersonalTasks::PRIORITIES as $k => $v): ?><option value="<?= $k ?>"<?= selected($k, $t['priority']) ?>><?= e($v) ?></option><?php endforeach; ?>
        </select>
        <label class="sr-only" for="pt-due-<?= $id ?>">Fecha límite</label>
        <input class="input sm ptask-due <?= $state ?>" type="date" id="pt-due-<?= $id ?>" name="due_date" value="<?= e($t['due_date']) ?>" data-patch="/api/my-tasks/<?= $id ?>">
      </div>
      <details class="ptask-more" id="ptask-more-<?= $id ?>"<?= $openId === $id ? ' open' : '' ?>>
        <summary class="small"><?= $t['notes'] ? icon('doc', 'sm') . ' ' : '' ?><?= $sub ? 'Notas' : 'Notas y subtareas' ?><?php if (!$sub && $t['subtasks']): ?> <span class="count"><?= count(array_filter($t['subtasks'], fn($s) => $s['completed_at'])) ?>/<?= count($t['subtasks']) ?></span><?php endif; ?></summary>
        <label class="sr-only" for="pt-notes-<?= $id ?>">Notas</label>
        <textarea class="textarea" id="pt-notes-<?= $id ?>" name="notes" rows="2" placeholder="Notas" data-patch="/api/my-tasks/<?= $id ?>"><?= e((string)$t['notes']) ?></textarea>
        <?php if (!$sub): ?>
          <ul class="ptask-list ptask-subs" data-ptask-list="<?= (int)$t['list_id'] ?>" data-parent="<?= $id ?>">
            <?php foreach ($t['subtasks'] as $s) echo $row($s, true); ?>
          </ul>
          <form class="ptask-add ptask-add--sub" data-api="/api/my-tasks" data-reset data-keep-open>
            <input type="hidden" name="parent_id" value="<?= $id ?>">
            <label class="sr-only" for="pt-sub-<?= $id ?>">Nueva subtarea</label>
            <input class="input sm" id="pt-sub-<?= $id ?>" name="title" required maxlength="255" placeholder="Añadir subtarea" autocomplete="off">
            <button class="btn btn--sm" type="submit"><?= icon('plus', 'sm') ?> Añadir</button>
          </form>
        <?php endif; ?>
        <div class="row wrap ptask-tools">
          <button type="button" class="btn btn--ghost btn--sm" data-ptask-move="up" aria-label="Subir «<?= e($t['title']) ?>»"><?= icon('arrow-up', 'sm') ?> Subir</button>
          <button type="button" class="btn btn--ghost btn--sm" data-ptask-move="down" aria-label="Bajar «<?= e($t['title']) ?>»"><?= icon('arrow-down', 'sm') ?> Bajar</button>
          <span class="grow"></span>
          <button type="button" class="btn btn--ghost btn--sm" data-call="/api/my-tasks/<?= $id ?>" data-method="DELETE" data-confirm="¿Eliminar «<?= e($t['title']) ?>»<?= !$sub && $t['subtasks'] ? ' y sus subtareas' : '' ?>?" data-confirm-label="Eliminar"><?= icon('trash', 'sm') ?> Eliminar</button>
        </div>
      </details>
    </li>
    <?php return ob_get_clean();
};
?>
<?php
/** Block that could not be loaded: the reason is in the server log, the page keeps working. */
$loadError = fn(string $what) => '<div class="card load-error" role="alert"><div class="empty">' . icon('alert') . '<h3>No se pudieron cargar ' . e($what) . '</h3><p>El resto de la página funciona. Vuelve a intentarlo en un momento; si se repite, la administración encontrará el detalle en el registro del servidor.</p><a class="btn btn--sm" href="' . e($_SERVER['REQUEST_URI'] ?? url('/tasks')) . '">Reintentar</a></div></div>';
$statuses ??= []; $editable ??= [];
/** One project task row (Mis tareas, Equipo). */
$projRow = function (array $t, bool $withPerson = false) use ($statuses, $editable) {
    $canEdit = $editable[$t['project_id']] ?? false;
    ob_start(); ?>
        <tr>
          <td class="col-check"><button type="button" class="subtask-check" data-call="/api/tasks/<?= (int)$t['id'] ?>/done" data-body='{"done":true}' aria-label="Finalizar <?= e($t['task_key']) ?>"<?= $canEdit ? '' : ' disabled' ?>><?= icon('check') ?></button></td>
          <td style="min-width:200px"><span class="key"><?= e($t['task_key']) ?></span> <button class="task-title" data-open-task="<?= e($t['task_key']) ?>"><?= e($t['title']) ?></button><?php if ($t['sprint_name'] ?? null): ?> <span class="badge"><?= icon('zap', 'sm') ?> <?= e($t['sprint_name']) ?></span><?php endif; ?><?php if (($t['estimate_hours'] ?? null) !== null): ?> <span class="badge" title="Tiempo estimado"><?= icon('clock', 'sm') ?> <?= e(rtrim(rtrim(number_format((float)$t['estimate_hours'], 1, ',', ''), '0'), ',')) ?> h</span><?php endif; ?></td>
          <td class="hide-sm"><a href="<?= e(url('/projects/' . $t['project_id'])) ?>" class="row"><span class="dot" style="width:8px;height:8px;border-radius:3px;background:<?= e($t['project_color']) ?>"></span><?= e($t['project_name']) ?></a></td>
          <?php if ($withPerson): ?><td class="hide-sm"><?= $t['assignee_name'] ? e($t['assignee_name']) : ($t['team_name'] ?? null ? e($t['team_name']) : '<span class="muted">Sin responsable</span>') ?></td><?php endif; ?>
          <td>
            <label class="sr-only" for="tk-st-<?= (int)$t['id'] ?>">Estado de <?= e($t['task_key']) ?></label>
            <select class="select sm" id="tk-st-<?= (int)$t['id'] ?>" name="status_id" data-patch="/api/tasks/<?= (int)$t['id'] ?>"<?= $canEdit ? '' : ' disabled' ?>>
              <?php foreach ($statuses[$t['project_id']] as $st): ?><option value="<?= (int)$st['id'] ?>"<?= selected($st['id'], $t['status_id']) ?>><?= e($st['name']) ?></option><?php endforeach; ?>
            </select>
          </td>
          <td>
            <label class="sr-only" for="tk-due-<?= (int)$t['id'] ?>">Vencimiento de <?= e($t['task_key']) ?></label>
            <input class="input sm <?= due_state($t['due_date'], false) ?>" style="width:150px" type="date" id="tk-due-<?= (int)$t['id'] ?>" name="due_date" value="<?= e($t['due_date']) ?>" data-patch="/api/tasks/<?= (int)$t['id'] ?>"<?= $canEdit ? '' : ' disabled' ?>>
            <?= late_tag($t) ?>
          </td>
        </tr>
    <?php return ob_get_clean();
};
$subs = ['mine' => 'Lo que te asignaron en los proyectos y tus tareas personales, en un solo lugar.', 'team' => 'Tareas abiertas de los proyectos en los que participas.', 'done' => 'Lo que ya terminaste.'];
?>
<div class="page-head">
  <div><h1>Tareas</h1><p class="sub"><?= e($subs[$tab]) ?></p></div>
</div>
<nav class="segmented mb" aria-label="Vistas de tareas" data-tour="tasks-tabs">
  <?php foreach (CalendarController::TASK_TABS as $k => $label): ?>
    <a href="<?= e(url('/tasks', ['tab' => $k])) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?><?php if ($k === 'mine' && $tab === 'mine' && isset($assignedCount) && $assignedCount): ?> <span class="count"><?= (int)$assignedCount ?></span><?php endif; ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'mine'): ?>
<div class="row wrap mine-filters mb">
  <nav class="chips" aria-label="Mostrar">
    <?php foreach (CalendarController::MINE_SHOW as $k => $label): ?>
      <a class="chip<?= $show === $k ? ' is-on' : '' ?>" href="<?= e(url('/tasks', ['show' => $k])) ?>"<?= $show === $k ? ' aria-current="true"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
</div>

<?php if ($show !== 'personal'): ?>
<section class="mine-block" aria-labelledby="mine-projects-h">
  <h2 class="mine-title" id="mine-projects-h"><?= icon('folder', 'sm') ?> Asignadas en proyectos <span class="muted small">· visibles para tu equipo</span></h2>
  <?php if (!empty($errors['projects'])): ?><?= $loadError('las tareas de tus proyectos') ?>
  <?php else: ?>
  <div class="card" id="mine-projects-region" data-region>
    <?php if (!empty($assignedCount)): ?>
      <div class="table-wrap"><table class="table mine-table">
        <thead><tr><th class="col-check"><span class="sr-only">Finalizar</span></th><th>Tarea</th><th class="hide-sm">Proyecto</th><th>Estado</th><th>Vencimiento</th></tr></thead>
        <?php $glabels = ['overdue' => 'Vencidas', 'today' => 'Para hoy', 'week' => 'Próximos 7 días', 'later' => 'Más adelante', 'none' => 'Sin fecha']; ?>
        <?php foreach ($assigned as $g => $rows): if (!$rows) continue; ?>
          <tbody class="mine-group mine-group--<?= e($g) ?>">
            <tr class="group-row"><th colspan="5" scope="colgroup"><?= e($glabels[$g]) ?> <span class="count"><?= count($rows) ?></span></th></tr>
            <?php foreach ($rows as $t) echo $projRow($t); ?>
          </tbody>
        <?php endforeach; ?>
      </table></div>
    <?php else: ?>
      <div class="empty"><?= icon('check-circle') ?><h3>Sin tareas de proyectos pendientes</h3><p>Cuando alguien te haga responsable de una tarea (a ti o a tu equipo), aparecerá aquí.</p></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($show !== 'projects'): ?>
<section class="mine-block" aria-labelledby="mine-personal-h">
  <h2 class="mine-title" id="mine-personal-h"><?= icon('lock', 'sm') ?> Personales <span class="muted small">· solo las ves tú</span></h2>
  <?php if (!empty($errors['personal'])): ?><?= $loadError('tus tareas personales') ?>
  <?php else: ?>
<div class="ptasks-layout" id="ptasks-region" data-region>
  <aside class="ptask-lists" aria-label="Listas">
    <ul class="cal-list">
      <?php foreach ($lists as $l): ?>
        <li><a class="nav-item<?= (int)$l['id'] === (int)$list['id'] ? ' is-current' : '' ?>" href="<?= e(url('/tasks', ['list' => $l['id']])) ?>"<?= (int)$l['id'] === (int)$list['id'] ? ' aria-current="page"' : '' ?>>
          <?= icon('list', 'sm') ?> <span class="grow"><?= e($l['name']) ?></span><?php if ($l['google_list_id']): ?><span class="badge badge--google" title="Sincronizada con Google Tasks">G</span><?php endif; ?><?php if ($l['open_count']): ?><span class="count"><?= (int)$l['open_count'] ?></span><?php endif; ?></a></li>
      <?php endforeach; ?>
    </ul>
    <form class="ptask-add" data-api="/api/my-lists" data-reset>
      <label class="sr-only" for="pl-new">Nueva lista</label>
      <input class="input sm" id="pl-new" name="name" required maxlength="120" placeholder="Nueva lista" autocomplete="off">
      <button class="btn btn--sm" type="submit" aria-label="Crear lista"><?= icon('plus', 'sm') ?></button>
    </form>
    <?php if ($google && $google['status'] === 'active' && str_contains((string)$google['scopes'], 'auth/tasks')): ?>
      <p class="small muted mt"><span class="badge badge--google">Google Tasks</span><br><?= $google['last_sync_at'] ? 'Sincronizado: ' . e(fmt_date(substr($google['last_sync_at'], 0, 10))) . ' ' . e(substr($google['last_sync_at'], 11, 5)) : 'Aún sin sincronizar' ?></p>
      <?php if ($google['last_error']): ?><p class="small" role="alert" style="color:var(--danger-strong)"><?= e($google['last_error']) ?></p><?php endif; ?>
      <button type="button" class="btn btn--sm" data-call="/api/google/sync"><?= icon('repeat', 'sm') ?> Sincronizar</button>
    <?php elseif ($google && $google['status'] !== 'active'): ?>
      <p class="small mt" role="alert"><span class="badge danger">Google</span> <?= e($google['last_error'] ?: 'Revisa la conexión en tu perfil.') ?></p>
    <?php else: ?>
      <p class="small muted mt">¿Usas Google Tasks? <a href="<?= e(url('/profile')) ?>#google">Conéctalo desde tu perfil</a> (opcional).</p>
    <?php endif; ?>
  </aside>
  <section class="card ptask-card">
    <div class="ptask-head">
      <div class="row grow">
        <label class="sr-only" for="pl-name">Nombre de la lista</label>
        <input class="input ptask-listname" id="pl-name" name="name" value="<?= e($list['name']) ?>" maxlength="120" required data-patch="/api/my-lists/<?= (int)$list['id'] ?>">
      </div>
      <div class="menu-wrap">
        <button class="btn btn--ghost btn--icon btn--sm" type="button" data-menu aria-label="Opciones de la lista" aria-haspopup="true" aria-expanded="false"><?= icon('more', 'sm') ?></button>
        <div class="menu" role="menu">
          <?php if (!$list['google_list_id'] && $google && $google['status'] === 'active' && str_contains((string)$google['scopes'], 'auth/tasks')): ?>
            <button type="button" role="menuitem" data-call="/api/my-lists/<?= (int)$list['id'] ?>/google"><?= icon('upload', 'sm') ?> Enviar a Google Tasks</button>
          <?php endif; ?>
          <?php if (count($lists) > 1): ?>
            <button type="button" role="menuitem" class="danger" data-call="/api/my-lists/<?= (int)$list['id'] ?>" data-method="DELETE" data-confirm="¿Eliminar la lista «<?= e($list['name']) ?>» y sus tareas?<?= $list['google_list_id'] ? ' En Google Tasks seguirán existiendo.' : '' ?>" data-confirm-label="Eliminar lista"><?= icon('trash', 'sm') ?> Eliminar lista</button>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <form class="ptask-add ptask-add--main" data-api="/api/my-tasks" data-reset data-keep-open data-tour="tasks-add">
      <input type="hidden" name="list_id" value="<?= (int)$list['id'] ?>">
      <label class="sr-only" for="pt-new">Nueva tarea</label>
      <input class="input" id="pt-new" name="title" required maxlength="255" placeholder="Añadir una tarea y pulsar Enter" autocomplete="off">
      <label class="sr-only" for="pt-new-due">Fecha límite</label>
      <input class="input" type="date" id="pt-new-due" name="due_date" style="width:auto">
      <button class="btn btn--primary" type="submit"><?= icon('plus', 'sm') ?> Añadir</button>
    </form>
    <?php if ($items): ?>
      <ul class="ptask-list" data-ptask-list="<?= (int)$list['id'] ?>" data-parent="">
        <?php foreach ($items as $t) echo $row($t); ?>
      </ul>
      <p class="small muted mt">Arrastra una tarea para ordenarla, o usa Subir y Bajar dentro de «Notas y subtareas». Los cambios se guardan solos.</p>
    <?php else: ?>
      <div class="empty" data-empty><?= icon('check-circle') ?><h3>Lista despejada</h3><p>Añade lo que tengas pendiente. Las completadas están en «Completadas».</p></div>
    <?php endif; ?>
  </section>
</div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php elseif ($tab === 'done'): ?>
<div class="grid grid-2 done-grid" id="ptasks-region" data-region>
  <section class="card">
    <div class="card-head"><h2><?= icon('lock', 'sm') ?> Personales completadas</h2></div>
    <?php if (!empty($errors['personal'])): ?><?= $loadError('tus tareas personales') ?>
    <?php elseif (!empty($personalDone)): ?>
      <ul class="ptask-list ptask-list--done">
        <?php foreach ($personalDone as $t): ?>
          <li class="ptask is-done"><div class="ptask-row">
            <button type="button" class="subtask-check done" data-call="/api/my-tasks/<?= (int)$t['id'] ?>" data-method="PATCH" data-body='{"done":false}' aria-label="Reabrir «<?= e($t['title']) ?>»"><?= icon('check') ?></button>
            <span class="done-main"><span class="done-title"><?= e($t['title']) ?></span><span class="done-meta"><?= e($t['list_name']) ?></span></span>
            <span class="done-date"><?= e(fmt_date(substr((string)$t['completed_at'], 0, 10))) ?></span>
          </div></li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?><div class="empty"><p>Aún no completaste tareas personales.</p></div><?php endif; ?>
  </section>
  <section class="card">
    <div class="card-head"><h2><?= icon('folder', 'sm') ?> De proyectos finalizadas</h2></div>
    <?php if (!empty($errors['projects'])): ?><?= $loadError('las tareas de tus proyectos') ?>
    <?php elseif (!empty($teamDone)): ?>
      <ul class="ptask-list ptask-list--done">
        <?php foreach ($teamDone as $t): ?>
          <li class="ptask is-done"><div class="ptask-row">
            <span class="done-icon" aria-hidden="true"><?= icon('check-circle', 'sm') ?></span>
            <span class="done-main"><span class="done-title"><span class="key"><?= e($t['task_key']) ?></span> <button class="task-title" data-open-task="<?= e($t['task_key']) ?>"><?= e($t['title']) ?></button></span><span class="done-meta"><?= e($t['project_name']) ?></span></span>
            <span class="done-date"><?= e(fmt_date(substr((string)($t['completed_at'] ?? $t['updated_at']), 0, 10))) ?></span>
          </div></li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?><div class="empty"><p>Todavía no finalizaste tareas de proyectos.</p></div><?php endif; ?>
  </section>
</div>

<?php else: ?>
<?php if (!empty($errors['projects'])): ?><?= $loadError('las tareas del equipo') ?>
<?php else: ?>
<?php
  $view ??= 'table'; $group ??= 'none'; $grouped ??= ['' => $tasks]; $savedViews ??= [];
  $keep = array_filter(array_intersect_key($filters, array_flip(\Borealis\Services\SavedViews::KEYS)), fn($v) => is_scalar($v) && $v !== '');
  $qsNow = http_build_query(array_diff_key($keep, ['view' => 1, 'group' => 1]) + ['view' => $view, 'group' => $group]);
  $nFilters = count(array_diff_key($keep, ['view' => 1, 'group' => 1]));
?>
<?php if ($savedViews || $nFilters): ?>
<nav class="chips saved-views mb" aria-label="Vistas guardadas" data-saved-views>
  <?php foreach ($savedViews as $sv): parse_str($sv['query'], $svq); $on = $svq == array_diff_key($keep, ['page' => 1]) ; ?>
    <span class="chip-wrap"><a class="chip<?= $on ? ' is-on' : '' ?>" href="<?= e(url('/tasks', ['tab' => 'team'] + $svq)) ?>" title="<?= $sv['shared'] ? 'Compartida por ' . e($sv['owner_name']) : 'Solo tú la ves' ?>"><?= $sv['shared'] ? icon('users', 'sm') . ' ' : '' ?><?= e($sv['name']) ?></a><?php if ((int)$sv['user_id'] === \Borealis\Core\Auth::id()): ?><button class="chip-x" type="button" data-call="/api/views/<?= (int)$sv['id'] ?>" data-method="DELETE" aria-label="Borrar la vista «<?= e($sv['name']) ?>»"><?= icon('x', 'sm') ?></button><?php endif; ?></span>
  <?php endforeach; ?>
  <?php if ($nFilters): ?>
    <details class="save-view"><summary class="chip"><?= icon('plus', 'sm') ?> Guardar esta vista</summary>
      <form class="card save-view-form" data-api="/api/views" data-reset>
        <input type="hidden" name="query" value="<?= e($qsNow) ?>">
        <label class="field"><span>Nombre</span><input class="input" name="name" maxlength="80" required placeholder="Ej.: Urgentes de mi equipo"></label>
        <?php if (\Borealis\Security\Policy::can('projects.create')): ?><label class="check small"><input type="checkbox" name="shared" value="1"> Compartir con todo el espacio (cada persona verá solo lo que puede ver)</label><?php endif; ?>
        <button class="btn btn--primary btn--sm" type="submit">Guardar</button>
      </form>
    </details>
  <?php endif; ?>
</nav>
<?php endif; ?>
<form class="toolbar wrap" method="get" action="<?= e(url('/tasks')) ?>" data-task-filters>
  <?php if (!config('app.pretty_urls', true)): ?><input type="hidden" name="r" value="/tasks"><?php endif; ?>
  <input type="hidden" name="tab" value="<?= e($tab) ?>">
  <label class="sr-only" for="tk-q">Buscar</label>
  <input class="input<?= !empty($filters['q']) ? ' filter-active' : '' ?>" type="search" id="tk-q" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Filtrar por texto" style="max-width:200px">
  <label class="sr-only" for="tk-p">Proyecto</label>
  <select class="select<?= !empty($filters['project_id']) ? ' filter-active' : '' ?>" id="tk-p" name="project_id" data-autosubmit>
    <option value="">Todos los proyectos</option>
    <?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>"<?= selected($p['id'], $filters['project_id'] ?? '') ?>><?= e($p['name']) ?></option><?php endforeach; ?>
  </select>
  <label class="sr-only" for="tk-a">Responsable</label>
  <select class="select<?= !empty($filters['assignee']) ? ' filter-active' : '' ?>" id="tk-a" name="assignee" data-autosubmit>
    <option value="">Cualquier responsable</option><option value="me"<?= selected('me', $filters['assignee'] ?? '') ?>>Yo</option><option value="none"<?= selected('none', $filters['assignee'] ?? '') ?>>Sin responsable</option>
    <?php foreach ($people ?? [] as $m): ?><option value="<?= (int)$m['id'] ?>"<?= selected($m['id'], $filters['assignee'] ?? '') ?>><?= e($m['name']) ?></option><?php endforeach; ?>
  </select>
  <label class="sr-only" for="tk-d">Fecha</label>
  <select class="select<?= !empty($filters['due']) ? ' filter-active' : '' ?>" id="tk-d" name="due" data-autosubmit>
    <option value="">Cualquier fecha</option><?php foreach (['overdue' => 'Vencidas', 'week' => 'Próximos 7 días', 'none' => 'Sin fecha'] as $k => $v): ?><option value="<?= $k ?>"<?= selected($k, $filters['due'] ?? '') ?>><?= $v ?></option><?php endforeach; ?>
  </select>
  <details class="more-options inline">
    <summary><?= icon('filter', 'sm') ?> Más</summary>
    <div class="row wrap mt-sm">
      <label class="field"><span>Prioridad</span><select class="select" name="priority_id"><option value="">Todas</option><?php foreach ($priorities ?? [] as $pr): ?><option value="<?= (int)$pr['id'] ?>"<?= selected($pr['id'], $filters['priority_id'] ?? '') ?>><?= e($pr['name']) ?></option><?php endforeach; ?></select></label>
      <label class="check"><input type="checkbox" name="blocked" value="1"<?= ($filters['blocked'] ?? '') === '1' ? ' checked' : '' ?>> Solo bloqueadas</label>
      <button class="btn btn--sm" type="submit">Aplicar</button>
    </div>
  </details>
  <span class="grow"></span>
  <label class="sr-only" for="tk-g">Agrupar por</label>
  <select class="select" id="tk-g" name="group" data-autosubmit title="Agrupar por"><?php foreach (CalendarController::GROUPS as $k => $v): ?><option value="<?= $k ?>"<?= selected($k, $group) ?>><?= $k === 'none' ? $v : 'Por ' . mb_strtolower($v) ?></option><?php endforeach; ?></select>
  <span class="segmented sm" role="group" aria-label="Vista">
    <?php foreach (CalendarController::VIEWS as $k => $v): ?><a href="<?= e(url('/tasks', ['tab' => 'team', 'view' => $k, 'group' => $group] + array_diff_key($keep, ['view' => 1, 'group' => 1]))) ?>"<?= $view === $k ? ' aria-current="page"' : '' ?> title="<?= e($v) ?>" data-task-view="<?= $k ?>"><?= icon($k === 'table' ? 'table' : ($k === 'list' ? 'list' : 'board'), 'sm') ?><span class="hide-sm"> <?= e($v) ?></span></a><?php endforeach; ?>
  </span>
  <?php if ($nFilters): ?><a class="btn btn--ghost btn--sm" href="<?= e(url('/tasks', ['tab' => 'team'])) ?>">Quitar filtros</a><?php endif; ?>
</form>
<div class="card" id="ptasks-region" data-region data-view="<?= e($view) ?>">
  <?php if ($tasks): ?>
  <?php if ($view === 'board'): ?>
    <?php // board: one column per state category; inside, the same grouping as headings
      $cols = ['todo' => 'Por hacer', 'in_progress' => 'En curso', 'blocked' => 'Bloqueadas'];
      $byCol = ['todo' => [], 'in_progress' => [], 'blocked' => []];
      foreach ($tasks as $t) $byCol[!empty($t['is_blocked']) ? 'blocked' : ($t['status_category'] === 'in_progress' ? 'in_progress' : 'todo')][] = $t;
    ?>
    <div class="task-board">
      <?php foreach ($cols as $ck => $cl): ?>
        <section class="task-board-col" aria-label="<?= e($cl) ?>"><h3><?= e($cl) ?> <span class="count"><?= count($byCol[$ck]) ?></span></h3>
          <?php foreach (CalendarController::groupTasks($byCol[$ck], $group) as $gl => $gt): ?>
            <?php if ($gl !== ''): ?><div class="task-board-group small muted"><?= e($gl) ?></div><?php endif; ?>
            <?php foreach ($gt as $t): ?>
              <button class="task-board-card" type="button" data-open-task="<?= e($t['task_key']) ?>">
                <span class="key"><?= e($t['task_key']) ?></span> <span class="t"><?= e($t['title']) ?></span>
                <span class="small muted row wrap"><?= $t['assignee_name'] ? e($t['assignee_name']) : 'Sin responsable' ?><?php if ($t['due_date']): ?> · <span class="<?= due_state($t['due_date'], false) ?>"><?= e(fmt_date($t['due_date'])) ?></span><?php endif; ?></span>
              </button>
            <?php endforeach; ?>
          <?php endforeach; ?>
          <?php if (!$byCol[$ck]): ?><p class="muted small">Nada aquí.</p><?php endif; ?>
        </section>
      <?php endforeach; ?>
    </div>
  <?php elseif ($view === 'list'): ?>
    <?php foreach ($grouped as $gl => $gt): ?>
      <?php if ($gl !== ''): ?><h3 class="task-group-head"><?= e($gl) ?> <span class="count"><?= count($gt) ?></span></h3><?php endif; ?>
      <ul class="task-compact">
        <?php foreach ($gt as $t): ?>
          <li><button class="task-title" type="button" data-open-task="<?= e($t['task_key']) ?>"><span class="key"><?= e($t['task_key']) ?></span> <?= e($t['title']) ?></button>
            <span class="small muted hide-sm"><?= e($t['project_name']) ?></span>
            <span class="small"><?= $t['assignee_name'] ? e($t['assignee_name']) : '<span class="muted">—</span>' ?></span>
            <span class="small <?= due_state($t['due_date'], false) ?>"><?= $t['due_date'] ? e(fmt_date($t['due_date'])) : '' ?></span></li>
        <?php endforeach; ?>
      </ul>
    <?php endforeach; ?>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table mine-table">
      <thead><tr><th class="col-check"><span class="sr-only">Finalizar</span></th><th>Tarea</th><th class="hide-sm">Proyecto</th><th class="hide-sm">Responsable</th><th>Estado</th><th>Vencimiento</th></tr></thead>
      <?php foreach ($grouped as $gl => $gt): ?>
      <tbody>
      <?php if ($gl !== ''): ?><tr class="group-row"><th colspan="6" scope="colgroup"><?= e($gl) ?> <span class="count"><?= count($gt) ?></span></th></tr><?php endif; ?>
      <?php foreach ($gt as $t) echo $projRow($t, true); ?>
      </tbody>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>
  <?php if (!empty($hasMore)): ?><div class="card-foot"><a class="btn btn--sm" href="<?= e(url('/tasks', ['tab' => 'team', 'page' => $page + 1, 'view' => $view, 'group' => $group] + array_diff_key($keep, ['view' => 1, 'group' => 1]))) ?>">Mostrar más tareas</a> <span class="muted small">Se muestran <?= count($tasks) ?>. Usa los filtros para acotar.</span></div><?php endif; ?>
  <?php else: ?>
    <div class="empty"><?= icon('check-circle') ?><h3>Nada por aquí</h3><p><?= $nFilters ? 'Ninguna tarea abierta coincide con los filtros.' : 'No hay tareas abiertas en tus proyectos.' ?></p></div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>
