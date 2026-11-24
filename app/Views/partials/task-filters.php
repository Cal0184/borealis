<?php
/** Shared filter bar for list / board / backlog. Filters live in the URL so reloads keep them. */
$f = $filters;
$active = fn($k) => isset($f[$k]) && $f[$k] !== '' ? ' filter-active' : '';
$keep = $keep ?? [];
?>
<form class="toolbar" method="get" action="<?= e(url($action)) ?>" data-filter-form>
  <?php if (!config('app.pretty_urls', true)): ?><input type="hidden" name="r" value="<?= e($action) ?>"><?php endif; ?>
  <?php foreach ($keep as $k): if (isset($f[$k])): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($f[$k]) ?>"><?php endif; endforeach; ?>
  <label class="sr-only" for="tf-q">Buscar</label>
  <input class="input search-input" type="search" id="tf-q" name="q" value="<?= e($f['q'] ?? '') ?>" placeholder="Buscar tareas">
  <label class="sr-only" for="tf-a">Responsable</label>
  <select class="select<?= $active('assignee') ?>" id="tf-a" name="assignee" data-autosubmit>
    <option value="">Cualquier responsable</option><option value="me"<?= selected('me', $f['assignee'] ?? '') ?>>Asignadas a mí</option><option value="none"<?= selected('none', $f['assignee'] ?? '') ?>>Sin responsable</option>
    <?php foreach ($members as $m): ?><option value="<?= (int)$m['id'] ?>"<?= selected($m['id'], $f['assignee'] ?? '') ?>><?= e($m['name']) ?></option><?php endforeach; ?>
  </select>
  <label class="sr-only" for="tf-p">Prioridad</label>
  <select class="select<?= $active('priority_id') ?>" id="tf-p" name="priority_id" data-autosubmit>
    <option value="">Cualquier prioridad</option><?php foreach ($priorities as $pr): ?><option value="<?= (int)$pr['id'] ?>"<?= selected($pr['id'], $f['priority_id'] ?? '') ?>><?= e($pr['name']) ?></option><?php endforeach; ?>
  </select>
  <?php if ($labels): ?>
    <label class="sr-only" for="tf-l">Etiqueta</label>
    <select class="select<?= $active('label_id') ?>" id="tf-l" name="label_id" data-autosubmit><option value="">Cualquier etiqueta</option><?php foreach ($labels as $l): ?><option value="<?= (int)$l['id'] ?>"<?= selected($l['id'], $f['label_id'] ?? '') ?>><?= e($l['name']) ?></option><?php endforeach; ?></select>
  <?php endif; ?>
  <?php if (!empty($showSprint) && $modules['sprints']): ?>
    <label class="sr-only" for="tf-s">Sprint</label>
    <select class="select<?= $active('sprint_id') ?>" id="tf-s" name="sprint_id" data-autosubmit>
      <option value=""<?= selected('', $f['sprint_id'] ?? '') ?>>Todos los sprints</option><option value="none"<?= selected('none', $f['sprint_id'] ?? '') ?>>Sin sprint</option>
      <?php foreach ($sprints as $sp): if ($sp['state'] === 'completed') continue; ?><option value="<?= (int)$sp['id'] ?>"<?= selected($sp['id'], $f['sprint_id'] ?? '') ?>><?= e($sp['name']) ?><?= $sp['state'] === 'active' ? ' (activo)' : '' ?></option><?php endforeach; ?>
    </select>
  <?php endif; ?>
  <?php if ($modules['epics'] && $epics): ?>
    <label class="sr-only" for="tf-e">Épica</label>
    <select class="select<?= $active('epic_id') ?>" id="tf-e" name="epic_id" data-autosubmit><option value="">Cualquier épica</option><?php foreach ($epics as $ep): ?><option value="<?= (int)$ep['id'] ?>"<?= selected($ep['id'], $f['epic_id'] ?? '') ?>><?= e($ep['name']) ?></option><?php endforeach; ?></select>
  <?php endif; ?>
  <label class="check"><input type="checkbox" name="blocked" value="1"<?= checked(($f['blocked'] ?? '') === '1') ?> data-autosubmit> Solo bloqueadas</label>
  <?= $extra ?? '' ?>
  <button class="btn btn--sm" type="submit"><?= icon('filter', 'sm') ?> Aplicar</button>
  <?php $clear = array_diff_key(array_filter($f, fn($v) => $v !== ''), array_flip(array_merge($keep, ['task']))); if ($clear): ?><a class="btn btn--ghost btn--sm" href="<?= e(url($action, array_intersect_key($f, array_flip($keep)))) ?>">Limpiar filtros</a><?php endif; ?>
</form>
