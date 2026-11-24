<?= partial('admin-nav', compact('ws', 'section')) ?>
<div class="grid grid-2">
  <section class="card"><div class="card-head"><h2>Espacio de trabajo</h2></div><div class="card-body">
    <form data-api="/api/workspace" data-method="PATCH">
      <div class="form-error" data-form-error></div>
      <div class="field"><label for="ws-n">Nombre</label><input class="input" id="ws-n" name="name" value="<?= e($ws['name']) ?>" required maxlength="120"><span class="error" data-error-for="name"></span></div>
      <div class="form-actions start"><button class="btn btn--primary" type="submit">Guardar</button></div>
    </form>
    <?php if (\Borealis\Security\AccountPolicy::canCreateWorkspace()): ?>
    <hr>
    <form data-api="/api/workspaces" data-reset>
      <div class="form-error" data-form-error></div>
      <div class="field"><label for="ws-new">Crear otro espacio</label><input class="input" id="ws-new" name="name" required maxlength="120" placeholder="Nombre del nuevo espacio"><span class="hint">Útil para organizaciones separadas. Tu cuenta es la misma en todos.</span></div>
      <div class="form-actions start"><button class="btn" type="submit"><?= icon('plus', 'sm') ?> Crear espacio</button></div>
    </form>
    <?php endif; ?>
  </div></section>
  <section class="card"><div class="card-head"><h2><?= icon('flag') ?> Prioridades</h2></div>
    <div>
      <?php foreach ($priorities as $p): $id = (int)$p['id']; ?>
        <form class="status-row" data-api="/api/priorities/<?= $id ?>" data-method="PATCH">
          <label class="sr-only" for="pr-c-<?= $id ?>">Color</label><input class="color-input" type="color" id="pr-c-<?= $id ?>" name="color" value="<?= e($p['color']) ?>">
          <label class="sr-only" for="pr-n-<?= $id ?>">Nombre</label><input class="input sm" id="pr-n-<?= $id ?>" name="name" value="<?= e($p['name']) ?>" required maxlength="40">
          <label class="sr-only" for="pr-w-<?= $id ?>">Peso</label><input class="input sm" type="number" id="pr-w-<?= $id ?>" name="weight" value="<?= (int)$p['weight'] ?>" style="width:80px" title="Peso para ordenar (mayor = más urgente)">
          <button class="btn btn--sm" type="submit">Guardar</button>
          <button class="btn btn--ghost btn--icon btn--sm" type="button" data-call="/api/priorities/<?= $id ?>" data-method="DELETE" data-confirm="¿Eliminar la prioridad «<?= e($p['name']) ?>»? Las tareas que la usan quedarán sin prioridad." data-confirm-label="Eliminar" aria-label="Eliminar <?= e($p['name']) ?>"><?= icon('trash', 'sm') ?></button>
        </form>
      <?php endforeach; ?>
    </div>
    <div class="card-foot">
      <form class="row wrap" data-api="/api/priorities" data-reset>
        <input class="color-input" type="color" name="color" value="#8B5CF6" aria-label="Color">
        <input class="input sm" name="name" placeholder="Nueva prioridad" required maxlength="40" aria-label="Nombre" style="width:160px">
        <input class="input sm" type="number" name="weight" value="15" style="width:80px" aria-label="Peso">
        <button class="btn btn--primary btn--sm" type="submit">Añadir</button>
      </form>
    </div>
  </section>
</div>
<?php $mc = \Borealis\Services\Monitoring::settings(); $num = fn(string $k, string $label, string $unit, string $hint = '') => '<div class="field"><label for="mc-' . $k . '">' . e($label) . '</label><div class="row"><input class="input" type="number" id="mc-' . $k . '" name="' . $k . '" value="' . (int)$mc[$k] . '" style="width:110px" required><span class="muted small">' . e($unit) . '</span></div>' . ($hint ? '<span class="hint">' . e($hint) . '</span>' : '') . '<span class="error" data-error-for="' . $k . '"></span></div>'; ?>
<section class="card mt" id="monitoreo"><div class="card-head"><h2><?= icon('target') ?> Umbrales de monitoreo</h2><a class="btn btn--sm btn--ghost" href="<?= e(url('/monitoring')) ?>">Ir a Monitoreo</a></div><div class="card-body">
  <form data-api="/api/monitoring/settings" data-method="PATCH">
    <div class="form-error" data-form-error></div>
    <p class="muted small">Las alertas usan reglas fijas y explicadas. Ajusta aquí a partir de cuándo avisan. La capacidad semanal de cada persona se define en Miembros.</p>
    <div class="grid grid-3">
      <?= $num('soon_days', 'Próxima a vencer', 'días o menos') ?>
      <?= $num('critical_overdue_days', 'Vencida crítica desde', 'días de atraso') ?>
      <?= $num('critical_priority', 'Prioridad crítica desde el peso', 'Alta = 30, Urgente = 40') ?>
      <?= $num('deviation_pct', 'Desviación de horas', '% sobre lo estimado') ?>
      <?= $num('deviation_min_hours', 'Ignorar estimaciones menores de', 'h') ?>
      <?= $num('blocked_critical_days', 'Bloqueo crítico tras', 'días bloqueada') ?>
      <?= $num('load_available', 'Capacidad disponible por debajo de', '%') ?>
      <?= $num('load_high', 'Carga equilibrada hasta', '%') ?>
      <?= $num('load_over', 'Sobrecarga por encima de', '%') ?>
      <?= $num('escalate_l2_days', 'Avisar a quien lleva el proyecto tras', 'días sin revisar') ?>
      <?= $num('escalate_l3_days', 'Avisar a la administración tras', 'días (solo críticas)') ?>
      <div class="field"><span class="label">Escalado a administración</span><input type="hidden" name="escalate_admin" value="0"><label class="check"><input type="checkbox" name="escalate_admin" value="1"<?= checked((bool)$mc['escalate_admin']) ?>> Activado</label></div>
    </div>
    <div class="form-actions start"><button class="btn btn--primary" type="submit">Guardar umbrales</button></div>
  </form>
</div></section>
<?php $ac = \Borealis\Services\Analysis\Analysis::settings(); ?>
<section class="card mt" id="analisis"><div class="card-head"><h2><?= icon('timeline') ?> Parámetros de análisis</h2><a class="btn btn--sm btn--ghost" href="<?= e(url('/analysis')) ?>">Ir a Análisis</a></div><div class="card-body">
  <form data-api="/api/analysis/settings" data-method="PATCH">
    <div class="form-error" data-form-error></div>
    <p class="muted small">Valores con los que Análisis decide si hay datos suficientes, qué es una tendencia y cuántos puntos de riesgo hacen falta. Cada cambio queda registrado en la actividad con el valor anterior y el nuevo. La proximidad de fechas y la estimación mínima se toman de los umbrales de monitoreo.</p>
    <div class="grid grid-3">
      <?php foreach (\Borealis\Services\Analysis\Analysis::LABELS as $k => [$label, $unit]): ?><div class="field"><label for="ac-<?= e($k) ?>"><?= e($label) ?></label><div class="row"><input class="input" type="number" id="ac-<?= e($k) ?>" name="<?= e($k) ?>" value="<?= (int)$ac[$k] ?>" style="width:110px" required><span class="muted small"><?= e($unit) ?></span></div><span class="error" data-error-for="<?= e($k) ?>"></span></div><?php endforeach; ?>
    </div>
    <div class="form-actions start"><button class="btn btn--primary" type="submit">Guardar parámetros</button></div>
  </form>
</div></section>
