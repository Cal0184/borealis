<div class="bulk-bar" data-bulk-bar role="region" aria-label="Acciones para tareas seleccionadas">
  <strong data-bulk-count>0 seleccionadas</strong>
  <label class="sr-only" for="bulk-status">Cambiar estado</label>
  <select class="select" id="bulk-status" data-bulk="status"><option value="">Mover a…</option><?php foreach ($statuses as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select>
  <label class="sr-only" for="bulk-assignee">Asignar</label>
  <select class="select" id="bulk-assignee" data-bulk="assignee"><option value="">Asignar a…</option><option value="none">Sin responsable</option><?php foreach ($assignable as $m): ?><option value="<?= (int)$m['id'] ?>"><?= e($m['name']) ?></option><?php endforeach; ?></select>
  <label class="sr-only" for="bulk-priority">Prioridad</label>
  <select class="select" id="bulk-priority" data-bulk="priority"><option value="">Prioridad…</option><option value="none">Sin prioridad</option><?php foreach ($priorities as $pr): ?><option value="<?= (int)$pr['id'] ?>"><?= e($pr['name']) ?></option><?php endforeach; ?></select>
  <?php if ($modules['sprints']): ?>
    <label class="sr-only" for="bulk-sprint">Sprint</label>
    <select class="select" id="bulk-sprint" data-bulk="sprint"><option value="">Sprint…</option><option value="none">Quitar del sprint</option><?php foreach ($sprints as $sp): if ($sp['state'] === 'completed') continue; ?><option value="<?= (int)$sp['id'] ?>"><?= e($sp['name']) ?></option><?php endforeach; ?></select>
  <?php endif; ?>
  <?php if ($can('task.delete')): ?><button class="btn btn--sm btn--danger" type="button" data-bulk-delete><?= icon('trash', 'sm') ?> Eliminar</button><?php endif; ?>
  <button class="btn btn--sm btn--ghost" type="button" data-bulk-clear style="color:#fff">Cancelar selección</button>
</div>
