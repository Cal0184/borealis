<?= partial('project-header', compact('project', 'modules', 'tab', 'can')) ?>
<?php $pid = (int)$project['id']; $manage = $can('project.manage'); ?>
<div class="page-head"><div><h2>Épicas</h2><p class="sub">Objetivos mayores que agrupan tareas.</p></div>
  <?php if ($manage): ?><button class="btn btn--primary" data-dialog="dlg-new-epic"><?= icon('plus') ?> Nueva épica</button><?php endif; ?></div>
<div class="stack">
<?php foreach ($epics as $ep): $eid = (int)$ep['id']; $tasks = $byEpic[$eid] ?? []; $pc = pct((int)$ep['done_count'], (int)$ep['task_count']); ?>
  <section class="card">
    <div class="card-head">
      <h3><span class="dot" style="width:10px;height:10px;border-radius:3px;background:<?= e($ep['color']) ?>"></span><?= e($ep['name']) ?><?php if ($ep['is_closed']): ?> <span class="badge">Cerrada</span><?php endif; ?></h3>
      <div class="row"><span class="muted small"><?= (int)$ep['done_count'] ?>/<?= (int)$ep['task_count'] ?></span><div style="width:120px"><div class="progress"><span style="--p:<?= $pc ?>%"></span></div></div>
        <?php if ($manage): ?><button class="btn btn--ghost btn--icon btn--sm" data-dialog="dlg-epic-<?= $eid ?>" aria-label="Editar <?= e($ep['name']) ?>"><?= icon('edit', 'sm') ?></button><?php endif; ?></div>
    </div>
    <?php if ($ep['description']): ?><div class="card-body rich" style="padding-bottom:0"><?= rich_text($ep['description']) ?></div><?php endif; ?>
    <ul class="list"><?php foreach ($tasks as $t): ?><li><span class="key"><?= e($t['task_key']) ?></span><button class="task-title truncate" style="flex:1" data-open-task="<?= e($t['task_key']) ?>"><?= e($t['title']) ?></button><span class="badge"><span class="dot" style="--c:<?= e($t['status_color']) ?>"></span><?= e($t['status_name']) ?></span></li><?php endforeach; ?>
      <?php if (!$tasks): ?><li class="empty">Asigna tareas a esta épica desde su detalle.</li><?php endif; ?></ul>
  </section>
  <?php if ($manage): ?>
  <dialog class="modal" id="dlg-epic-<?= $eid ?>" aria-labelledby="de-<?= $eid ?>">
    <form data-api="/api/projects/<?= $pid ?>/epics/<?= $eid ?>" data-method="PATCH" data-close>
      <div class="modal-head"><h2 id="de-<?= $eid ?>">Editar épica</h2><button type="button" class="btn btn--ghost btn--icon" data-close-dialog aria-label="Cerrar"><?= icon('x') ?></button></div>
      <div class="modal-body">
        <div class="form-error" data-form-error></div>
        <div class="field"><label for="en-<?= $eid ?>">Nombre</label><input class="input" id="en-<?= $eid ?>" name="name" value="<?= e($ep['name']) ?>" required maxlength="160"></div>
        <div class="field"><label for="ed-<?= $eid ?>">Descripción</label><textarea class="textarea" id="ed-<?= $eid ?>" name="description" rows="3"><?= e($ep['description']) ?></textarea></div>
        <div class="form-row"><div class="field"><label for="ec-<?= $eid ?>">Color</label><input class="color-input" type="color" id="ec-<?= $eid ?>" name="color" value="<?= e($ep['color']) ?>"></div>
          <div class="field"><span class="label">Estado</span><input type="hidden" name="is_closed" value="0"><label class="check"><input type="checkbox" name="is_closed" value="1"<?= checked((bool)$ep['is_closed']) ?>> Cerrada</label></div></div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn--danger" data-call="/api/projects/<?= $pid ?>/epics/<?= $eid ?>" data-method="DELETE" data-confirm="¿Eliminar la épica «<?= e($ep['name']) ?>»? Sus tareas se conservan." data-confirm-label="Eliminar">Eliminar</button>
        <span style="flex:1"></span>
        <button type="button" class="btn" data-close-dialog>Cancelar</button><button class="btn btn--primary" type="submit">Guardar</button>
      </div>
    </form>
  </dialog>
  <?php endif; ?>
<?php endforeach; ?>
<?php if (!$epics): ?><div class="card"><div class="empty"><?= icon('flag') ?><h3>Sin épicas</h3><p>Crea una épica para agrupar tareas relacionadas con un mismo objetivo.</p></div></div><?php endif; ?>
</div>
<?php if ($manage): ?>
<dialog class="modal" id="dlg-new-epic" aria-labelledby="dne-t">
  <form data-api="/api/projects/<?= $pid ?>/epics" data-close data-reset>
    <div class="modal-head"><h2 id="dne-t">Nueva épica</h2><button type="button" class="btn btn--ghost btn--icon" data-close-dialog aria-label="Cerrar"><?= icon('x') ?></button></div>
    <div class="modal-body">
      <div class="form-error" data-form-error></div>
      <div class="field"><label for="ne-n">Nombre</label><input class="input" id="ne-n" name="name" required maxlength="160"><span class="error" data-error-for="name"></span></div>
      <div class="field"><label for="ne-d">Descripción</label><textarea class="textarea" id="ne-d" name="description" rows="3"></textarea></div>
      <div class="field"><label for="ne-c">Color</label><input class="color-input" type="color" id="ne-c" name="color" value="#8B5CF6"></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-close-dialog>Cancelar</button><button class="btn btn--primary" type="submit">Crear épica</button></div>
  </form>
</dialog>
<?php endif; ?>
