<?php
use Borealis\Core\Auth;
use Borealis\Services\Files;
use Borealis\Services\Tasks;
/** @var array $task @var array $project @var callable $can */
$t = $task;
$tid = (int)$t['id'];
$pid = (int)$project['id'];
$done = $t['status_category'] === 'done';
$edit = $can('task.edit');
$dis = $edit ? '' : ' disabled';
$patch = '/api/tasks/' . $tid;
$taskLabelIds = array_map('intval', array_column($t['labels'], 'id'));
?>
<div class="task-detail-root" data-task-detail="<?= e($t['task_key']) ?>" data-task-id="<?= $tid ?>">
  <div class="drawer-head" id="td-head" data-region>
    <div class="grow">
      <div class="breadcrumbs" style="margin:0">
        <a href="<?= e(url('/projects/' . $pid)) ?>"><?= e($project['name']) ?></a>
        <?php if (!empty($parent)): ?><?= icon('chevron-right', 'sm') ?><a href="<?= e(url('/t/' . $parent['task_key'])) ?>" data-open-task="<?= e($parent['task_key']) ?>"><?= e($parent['task_key']) ?></a><?php endif; ?>
        <?= icon('chevron-right', 'sm') ?><span class="key"><?= e($t['task_key']) ?></span>
        <?php if ($done): ?><span class="badge success"><?= icon('check', 'sm') ?> Finalizada</span><?php endif; ?>
      </div>
    </div>
    <?php if ($inDrawer): ?><a class="btn btn--ghost btn--sm hide-sm" href="<?= e(url('/t/' . $t['task_key'])) ?>"><?= icon('external', 'sm') ?> Abrir página</a><?php endif; ?>
    <div class="menu-wrap">
      <button class="btn btn--ghost btn--icon btn--sm" type="button" data-menu aria-label="Más acciones" aria-haspopup="true" aria-expanded="false"><?= icon('more') ?></button>
      <div class="menu" role="menu">
        <button type="button" role="menuitem" data-copy="<?= e(absolute_url('/t/' . $t['task_key'])) ?>"><?= icon('link') ?> Copiar enlace</button>
        <?php if ($can('task.create')): ?><button type="button" role="menuitem" data-call="/api/tasks/<?= $tid ?>/duplicate" data-open-result><?= icon('copy') ?> Duplicar</button><?php endif; ?>
        <?php if ($can('task.delete')): ?><hr><button type="button" role="menuitem" class="danger" data-call="/api/tasks/<?= $tid ?>" data-method="DELETE" data-confirm="¿Eliminar <?= e($t['task_key']) ?> «<?= e($t['title']) ?>»<?= $t['subtask_count'] ? ' y sus ' . (int)$t['subtask_count'] . ' subtareas' : '' ?>? Podrá recuperarse desde la base de datos, pero dejará de verse en Borealis." data-confirm-label="Eliminar tarea" data-after="close-drawer"><?= icon('trash') ?> Eliminar</button><?php endif; ?>
      </div>
    </div>
    <?php if ($inDrawer): ?><button class="btn btn--ghost btn--icon btn--sm" type="button" data-drawer-close aria-label="Cerrar detalle"><?= icon('x') ?></button><?php endif; ?>
  </div>

  <div class="task-title-row">
    <label class="sr-only" for="td-title-<?= $tid ?>">Título</label>
    <input class="inline-input title-input" id="td-title-<?= $tid ?>" name="title" value="<?= e($t['title']) ?>" maxlength="255" required data-patch="<?= e($patch) ?>"<?= $dis ?>>
  </div>
  <div class="task-detail">
    <div class="task-main">

      <div id="td-blocked" data-region>
        <?php if ($t['is_blocked'] && !$done): ?>
          <div class="form-error mt row"><?= icon('block') ?> <strong>Bloqueada.</strong> <?= e($t['blocked_reason'] ?: 'Sin motivo indicado.') ?></div>
        <?php endif; ?>
      </div>

      <section class="task-section">
        <h3><?= icon('doc') ?> Descripción</h3>
        <div id="td-desc" data-region>
          <div class="description-view rich<?= $t['description'] ? '' : ' empty' ?>"<?= $edit ? ' data-toggle="#td-desc-form-' . $tid . '" role="button" tabindex="0" aria-label="Editar descripción"' : '' ?> id="td-desc-view-<?= $tid ?>">
            <?= $t['description'] ? rich_text($t['description']) : ($edit ? 'Añade una descripción…' : 'Sin descripción.') ?>
          </div>
          <?php if ($edit): ?>
            <form id="td-desc-form-<?= $tid ?>" hidden data-api="<?= e($patch) ?>" data-method="PATCH" data-toast="Descripción guardada">
              <div class="form-error" data-form-error></div>
              <textarea class="textarea" name="description" rows="6" maxlength="60000" placeholder="Contexto, criterios, enlaces… Admite **negrita**, listas con - y @menciones."><?= e($t['description']) ?></textarea>
              <div class="form-actions start"><button class="btn btn--primary btn--sm" type="submit">Guardar</button><button class="btn btn--sm" type="button" data-toggle="#td-desc-form-<?= $tid ?>" data-reset-form>Cancelar</button></div>
            </form>
          <?php endif; ?>
        </div>
      </section>

      <section class="task-section">
        <h3><?= icon('link') ?> Enlaces de trabajo</h3>
        <div id="td-worklinks" class="work-links" data-region>
          <?php foreach ([
              'drive_url' => ['Carpeta de Drive', 'folder', 'https://drive.google.com/drive/folders/…', 'Sin carpeta de Drive.', 'Enlace de la carpeta de Drive copiado', 'drive'],
              'github_url' => ['Repositorio de GitHub', 'code', 'https://github.com/organización/repositorio', 'Sin repositorio de GitHub.', 'Enlace del repositorio de GitHub copiado', 'github'],
          ] as $lk => [$lLabel, $lIcon, $lPh, $lNone, $lMsg, $lSlug]): $lUrl = (string)($t[$lk] ?? ''); $lId = 'td-' . $lSlug . '-' . $tid; ?>
            <div class="work-link" data-work-link="<?= e($lSlug) ?>">
              <label class="work-link-label" for="<?= e($lId) ?>"><?= icon($lIcon, 'sm') ?> <?= e($lLabel) ?></label>
              <div class="work-link-row">
                <?php if ($edit): ?>
                  <input class="input sm work-link-input" type="text" inputmode="url" autocomplete="off" spellcheck="false" maxlength="500" id="<?= e($lId) ?>" name="<?= e($lk) ?>" value="<?= e($lUrl) ?>" placeholder="<?= e($lPh) ?>" data-patch="<?= e($patch) ?>">
                <?php elseif ($lUrl): ?>
                  <a class="work-link-url truncate" id="<?= e($lId) ?>" href="<?= e($lUrl) ?>" target="_blank" rel="noopener noreferrer"><?= e($lUrl) ?></a>
                <?php else: ?>
                  <span class="work-link-url muted" id="<?= e($lId) ?>"><?= e($lNone) ?></span>
                <?php endif; ?>
                <button class="btn btn--sm" type="button" data-copy="<?= e($lUrl) ?>"<?= $edit ? ' data-copy-from="#' . e($lId) . '"' : '' ?> data-copy-msg="<?= e($lMsg) ?>" aria-label="Copiar enlace: <?= e(mb_strtolower($lLabel)) ?>"<?= !$edit && !$lUrl ? ' disabled' : '' ?>><?= icon('copy', 'sm') ?> Copiar</button>
                <?php if ($lUrl): ?><a class="btn btn--ghost btn--sm btn--icon" href="<?= e($lUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="Abrir <?= e(mb_strtolower($lLabel)) ?> en otra pestaña" title="Abrir en otra pestaña"><?= icon('external', 'sm') ?></a><?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <?php if (!$t['parent_id']): ?>
      <section class="task-section subtasks">
        <div id="td-subtasks" data-region>
          <?php $sc = count($subtasks); $sd = count(array_filter($subtasks, fn($s) => $s['status_category'] === 'done')); ?>
          <h3><?= icon('subtask') ?> Subtareas <span class="count"><?= $sd ?>/<?= $sc ?></span></h3>
          <?php if ($sc): ?><div class="progress mb"><span style="--p:<?= pct($sd, $sc) ?>%"></span></div><?php endif; ?>
          <ul class="list">
            <?php foreach ($subtasks as $st): $sdone = $st['status_category'] === 'done'; ?>
              <li>
                <button type="button" class="subtask-check<?= $sdone ? ' done' : '' ?>" data-call="/api/tasks/<?= (int)$st['id'] ?>/done" data-body='{"done":<?= $sdone ? 'false' : 'true' ?>}' aria-label="<?= $sdone ? 'Reabrir' : 'Finalizar' ?> <?= e($st['task_key']) ?>"<?= $dis ?>><?= icon('check') ?></button>
                <span class="key"><?= e($st['task_key']) ?></span>
                <a href="<?= e(url('/t/' . $st['task_key'])) ?>" data-open-task="<?= e($st['task_key']) ?>" class="truncate" style="flex:1;<?= $sdone ? 'color:var(--text-3);text-decoration:line-through' : 'color:var(--text)' ?>"><?= e($st['title']) ?></a>
                <?= avatar($st['assignee_id'] ? ['id' => $st['assignee_id'], 'name' => $st['assignee_name'], 'avatar_color' => $st['assignee_color']] : null, 'sm') ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php if ($can('task.create')): ?>
          <form class="row mt" data-api="/api/projects/<?= $pid ?>/tasks" data-reset data-toast="Subtarea añadida">
            <input type="hidden" name="parent_id" value="<?= $tid ?>">
            <label class="sr-only" for="td-sub-<?= $tid ?>">Nueva subtarea</label>
            <input class="input sm" id="td-sub-<?= $tid ?>" name="title" placeholder="Añadir subtarea y pulsar Enter" maxlength="255" required autocomplete="off">
            <button class="btn btn--sm" type="submit"><?= icon('plus', 'sm') ?> Añadir</button>
          </form>
        <?php endif; ?>
      </section>
      <?php endif; ?>

      <section class="task-section">
        <div id="td-links" data-region>
          <h3><?= icon('link') ?> Relaciones <span class="count"><?= count($links) ?></span></h3>
          <?php if ($links): ?>
            <ul class="list">
              <?php foreach ($links as $l):
                $label = $l['dir'] === 'out' ? Tasks::LINK_TYPES[$l['link_type']] : ['relates' => 'Relacionada con', 'blocks' => 'Bloqueada por', 'duplicates' => 'Duplicada por'][$l['link_type']]; ?>
                <li>
                  <span class="badge<?= $l['link_type'] === 'blocks' ? ' danger' : '' ?>"><?= e($label) ?></span>
                  <a class="truncate" style="flex:1" href="<?= e(url('/t/' . $l['task_key'])) ?>" data-open-task="<?= e($l['task_key']) ?>"><span class="key"><?= e($l['task_key']) ?></span> <?= e($l['title']) ?></a>
                  <?php if ($l['status_category'] === 'done'): ?><?= icon('check-circle', 'sm') ?><?php endif; ?>
                  <?php if ($edit): ?><button class="btn btn--ghost btn--icon btn--sm" type="button" data-call="/api/tasks/<?= $tid ?>/links/<?= (int)$l['id'] ?>" data-method="DELETE" aria-label="Quitar relación"><?= icon('x', 'sm') ?></button><?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
        <?php if ($edit): ?>
          <form class="row wrap mt" data-api="/api/tasks/<?= $tid ?>/links" data-reset>
            <label class="sr-only" for="td-lt-<?= $tid ?>">Tipo de relación</label>
            <select class="select sm" id="td-lt-<?= $tid ?>" name="link_type" style="width:auto"><?php foreach (Tasks::LINK_TYPES as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select>
            <label class="sr-only" for="td-lk-<?= $tid ?>">Clave de la tarea</label>
            <input class="input sm" id="td-lk-<?= $tid ?>" name="target" placeholder="Clave, p. ej. <?= e($project['project_key']) ?>-1" required style="width:170px" autocomplete="off">
            <button class="btn btn--sm" type="submit">Relacionar</button>
            <span class="error small" data-error-for="target" style="color:var(--danger-strong)"></span>
          </form>
        <?php endif; ?>
      </section>

      <section class="task-section">
        <div id="td-files" data-region>
          <h3><?= icon('clip') ?> Archivos <span class="count"><?= count($files) ?></span></h3>
          <div class="grid" style="gap:8px">
            <?php foreach ($files as $f): ?>
              <div class="file-tile">
                <?= icon('doc') ?>
                <div style="flex:1;min-width:0"><a class="name truncate" style="display:block" href="<?= e(url('/files/' . $f['id'])) ?>"><?= e($f['original_name']) ?></a><span class="muted small"><?= e(Files::humanSize((int)$f['size_bytes'])) ?> · <?= e($f['uploader_name'] ?? '') ?> · <?= e(time_ago($f['created_at'])) ?></span></div>
                <a class="btn btn--ghost btn--icon btn--sm" href="<?= e(url('/files/' . $f['id'])) ?>" aria-label="Descargar <?= e($f['original_name']) ?>"><?= icon('download', 'sm') ?></a>
                <?php if ($can('file.delete') || ((int)$f['uploader_id'] === Auth::id() && $can('file.upload'))): ?>
                  <button class="btn btn--ghost btn--icon btn--sm" type="button" data-call="/api/files/<?= (int)$f['id'] ?>" data-method="DELETE" data-confirm="¿Eliminar el archivo «<?= e($f['original_name']) ?>»?" data-confirm-label="Eliminar archivo" aria-label="Eliminar <?= e($f['original_name']) ?>"><?= icon('trash', 'sm') ?></button>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php if ($can('file.upload')): ?>
          <form class="mt" data-api="/api/projects/<?= $pid ?>/files" data-multipart data-reset>
            <input type="hidden" name="task_id" value="<?= $tid ?>">
            <label class="drop-zone" data-drop-zone>
              <input type="file" name="file[]" multiple data-autosubmit>
              <?= icon('upload') ?> Arrastra archivos aquí o <strong>elige desde tu equipo</strong>
              <span class="small" style="display:block">Máximo <?= round((int)config('uploads.max_bytes', 10485760) / 1048576) ?> MB por archivo</span>
            </label>
            <div class="form-error mt" data-form-error></div>
          </form>
        <?php endif; ?>
      </section>

      <section class="task-section">
        <div id="td-time" data-region>
          <?php $tm = $time; $est = $t['estimate_hours'] !== null ? (float)$t['estimate_hours'] * 60 : null; ?>
          <h3><?= icon('clock') ?> Horas <span class="count"><?= e(\Borealis\Services\TimeEntries::fmt((int)$tm['total'])) ?><?= $est ? ' de ' . e(\Borealis\Services\TimeEntries::fmt((int)$est)) . ' estimadas' : '' ?></span></h3>
          <?php if ($est): ?><div class="progress mb<?= $tm['total'] > $est ? ' over' : '' ?>"><span style="--p:<?= min(100, pct((int)$tm['total'], (int)$est)) ?>%"></span></div><?php endif; ?>
          <?php if ($tm['people']): ?>
            <div class="time-people">
              <?php foreach ($tm['people'] as $pp): ?><span class="time-chip"><?= avatar(['id' => $pp['id'] ?? null, 'name' => $pp['name'], 'avatar_color' => $pp['avatar_color']], 'sm') ?> <?= e($pp['name']) ?> <strong><?= e(\Borealis\Services\TimeEntries::fmt((int)$pp['minutes'])) ?></strong></span><?php endforeach; ?>
            </div>
            <details class="time-log"><summary class="small muted">Ver registros (<?= count($tm['entries']) ?>)</summary>
              <ul class="list">
                <?php foreach ($tm['entries'] as $te): $canDel = (int)$te['user_id'] === Auth::id() || (int)$te['created_by'] === Auth::id() || $can('project.manage'); ?>
                  <li class="small"><span class="muted" style="width:86px"><?= e(fmt_date($te['work_date'])) ?></span><strong><?= e(\Borealis\Services\TimeEntries::fmt((int)$te['minutes'])) ?></strong><span class="truncate" style="flex:1"><?= e($te['user_name']) ?><?= (int)$te['task_id'] !== $tid ? ' · ' . e($project['project_key'] . '-' . $te['number']) : '' ?><?= $te['note'] ? ' · ' . e($te['note']) : '' ?></span>
                    <?php if ($canDel && $edit): ?><details class="time-fix"><summary class="btn btn--ghost btn--icon btn--sm" aria-label="Corregir registro" title="Corregir"><?= icon('edit', 'sm') ?></summary>
                      <form class="row wrap mt" data-api="/api/time/<?= (int)$te['id'] ?>" data-method="PATCH">
                        <input class="input sm" name="hours" value="<?= e(sprintf('%d:%02d', intdiv((int)$te['minutes'], 60), (int)$te['minutes'] % 60)) ?>" aria-label="Horas (1.5 o 1:30)" style="width:90px" required>
                        <input class="input sm" type="date" name="work_date" value="<?= e($te['work_date']) ?>" max="<?= e(today()) ?>" aria-label="Fecha" style="width:140px">
                        <input class="input sm" name="note" value="<?= e($te['note'] ?? '') ?>" maxlength="255" aria-label="Nota" placeholder="Nota" style="flex:1;min-width:100px">
                        <button class="btn btn--sm" type="submit">Guardar</button>
                      </form></details><button class="btn btn--ghost btn--icon btn--sm" type="button" data-call="/api/time/<?= (int)$te['id'] ?>" data-method="DELETE" data-confirm="¿Quitar este registro de horas?" data-confirm-label="Quitar" aria-label="Quitar registro"><?= icon('trash', 'sm') ?></button><?php endif; ?></li>
                <?php endforeach; ?>
              </ul>
            </details>
          <?php else: ?><p class="muted small">Aún no hay horas registradas.</p><?php endif; ?>
        </div>
        <?php if ($edit): ?>
          <form class="time-form" data-api="/api/tasks/<?= $tid ?>/time" data-reset>
            <div class="form-error" data-form-error></div>
            <div class="row wrap">
              <label class="sr-only" for="tf-h-<?= $tid ?>">Horas</label><input class="input sm" id="tf-h-<?= $tid ?>" name="hours" required placeholder="Horas (1.5 o 1:30)" style="width:150px" autocomplete="off">
              <label class="sr-only" for="tf-d-<?= $tid ?>">Fecha</label><input class="input sm" type="date" id="tf-d-<?= $tid ?>" name="work_date" value="<?= e(today()) ?>" max="<?= e(today()) ?>" style="width:150px">
              <?php if ($can('project.manage')): ?><label class="sr-only" for="tf-u-<?= $tid ?>">Persona</label><select class="select sm" id="tf-u-<?= $tid ?>" name="user_id" style="width:auto"><?php foreach ($assignable as $m): ?><option value="<?= (int)$m['id'] ?>"<?= selected($m['id'], Auth::id()) ?>><?= e($m['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
              <label class="sr-only" for="tf-n-<?= $tid ?>">Nota</label><input class="input sm" id="tf-n-<?= $tid ?>" name="note" maxlength="255" placeholder="Nota (opcional)" style="flex:1;min-width:120px">
              <button class="btn btn--sm" type="submit"><?= icon('plus', 'sm') ?> Registrar</button>
            </div>
            <span class="error" data-error-for="hours"></span>
          </form>
        <?php endif; ?>
      </section>

      <?php
        $mon = \Borealis\Services\Monitoring::class;
        $monCls = $mon::classify($t);
        $monDev = $mon::deviation($t['estimate_hours'] !== null ? (float)$t['estimate_hours'] : null, (int)$time['total']);
        $monDue = $mon::dueChanges($tid);
        $monWhy = $mon::explanations($tid);
        $monCfg = $mon::settings();
        $monNeeds = $monCls === 'late' || $monCls === 'done_late' || ($monDev && $monDev['pct'] >= $monCfg['deviation_pct']);
      ?>
      <section class="task-section" id="td-tracking-sec">
        <div id="td-tracking" data-region>
          <h3><?= icon('target') ?> Seguimiento <span class="badge <?= in_array($monCls, ['late', 'done_late'], true) ? 'danger' : ($monCls === 'soon' ? 'warn' : ($monCls === 'nodate' ? '' : 'success')) ?>" data-punctuality="<?= e($monCls) ?>"><?= e($mon::CLASSES[$monCls]) ?></span></h3>
          <div class="tracking-box">
            <dl>
              <?php if ($t['started_at']): ?><dt>Inicio real</dt><dd><?= e(fmt_datetime($t['started_at'])) ?></dd><?php endif; ?>
              <?php if ($t['is_blocked'] && $t['blocked_at']): $bb = $t['blocked_by'] ? \Borealis\Core\Database::get()->value('SELECT name FROM users WHERE id = ?', [(int)$t['blocked_by']]) : null; ?><dt>Bloqueada desde</dt><dd><?= e(fmt_datetime($t['blocked_at'])) ?><?= $bb ? ' · marcada por ' . e($bb) : '' ?></dd><?php endif; ?>
              <dt>Horas</dt><dd data-deviation><?php if ($monDev): ?><?= e($mon::fmtHours($monDev['estimate'])) ?> estimadas · <?= e($mon::fmtHours($monDev['logged'])) ?> registradas · <span class="<?= $monDev['pct'] >= $monCfg['deviation_pct'] ? 'dev-over' : ($monDev['pct'] < 0 ? 'dev-under' : '') ?>"><?= e($mon::fmtHours($monDev['hours'], true)) ?> (<?= $monDev['pct'] > 0 ? '+' : '' ?><?= (int)$monDev['pct'] ?> %)</span><?php elseif ((int)$time['total']): ?>Sin estimación: no se puede comparar<?php else: ?><span class="muted">Sin estimación ni registros</span><?php endif; ?></dd>
              <?php if (!$done): ?><dt><label for="td-rh-<?= $tid ?>">Horas restantes</label></dt><dd class="ts-inline"><input class="input sm ts-num" type="number" min="0" step="0.5" id="td-rh-<?= $tid ?>" name="remaining_hours" value="<?= $t['remaining_hours'] !== null ? e(rtrim(rtrim((string)$t['remaining_hours'], '0'), '.')) : '' ?>" placeholder="auto" title="Opcional: si lo dejas vacío se usa estimado menos registrado" data-patch="<?= e($patch) ?>"<?= $dis ?>><span class="muted small">h · opcional, mejora la carga planificada</span></dd><?php endif; ?>
            </dl>
            <?php if ($monDue): ?>
              <details class="small" data-due-history><summary>La fecha límite cambió <?= count($monDue) ?> <?= count($monDue) === 1 ? 'vez' : 'veces' ?></summary>
                <ul class="list"><?php foreach ($monDue as $d): ?><li class="small"><span style="flex:1"><?= $d['old_due'] ? e(fmt_date($d['old_due'], true)) : 'sin fecha' ?> → <?= $d['new_due'] ? e(fmt_date($d['new_due'], true)) : 'sin fecha' ?></span><span class="muted"><?= e($d['user_name'] ?? 'Sistema') ?> · <?= e(fmt_datetime($d['changed_at'])) ?></span></li><?php endforeach; ?></ul>
              </details>
            <?php endif; ?>
            <?php if ($monWhy): ?>
              <ul class="list" data-explanations><?php foreach ($monWhy as $x): ?><li class="small" style="flex-direction:column;align-items:flex-start"><span><span class="badge"><?= $x['kind'] === 'delay' ? 'Retraso' : 'Desviación de horas' ?></span> <strong><?= e($mon::REASONS[$x['reason']] ?? $x['reason']) ?></strong> · <span class="muted"><?= e($x['user_name'] ?? '') ?> · <?= e(fmt_datetime($x['created_at'])) ?></span></span>
                <?php if ($x['comment']): ?><span><?= e($x['comment']) ?></span><?php endif; ?>
                <?php if ($x['corrective_action']): ?><span class="muted">Acción correctiva: <?= e($x['corrective_action']) ?></span><?php endif; ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
            <?php if ($edit): ?>
              <details class="small"<?= $monNeeds && !$monWhy ? ' open' : '' ?>><summary><?= $monNeeds ? 'Registrar el motivo (opcional)' : 'Registrar un motivo de retraso o desviación' ?></summary>
                <form class="mt" data-api="/api/tasks/<?= $tid ?>/explanations" data-reset>
                  <div class="form-error" data-form-error></div>
                  <div class="row wrap">
                    <label class="sr-only" for="tx-k-<?= $tid ?>">Tipo</label><select class="select sm" id="tx-k-<?= $tid ?>" name="kind" style="width:auto"><option value="delay"<?= $monDev && $monDev['pct'] >= $monCfg['deviation_pct'] && !in_array($monCls, ['late', 'done_late'], true) ? '' : ' selected' ?>>Retraso</option><option value="deviation"<?= $monDev && $monDev['pct'] >= $monCfg['deviation_pct'] && !in_array($monCls, ['late', 'done_late'], true) ? ' selected' : '' ?>>Desviación de horas</option></select>
                    <label class="sr-only" for="tx-r-<?= $tid ?>">Motivo</label><select class="select sm" id="tx-r-<?= $tid ?>" name="reason" required style="width:auto"><option value="">Motivo…</option><?php foreach ($mon::REASONS as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select>
                  </div>
                  <label class="sr-only" for="tx-c-<?= $tid ?>">Comentario</label><textarea class="textarea mt" id="tx-c-<?= $tid ?>" name="comment" rows="2" maxlength="1000" placeholder="Comentario (opcional)"></textarea>
                  <label class="sr-only" for="tx-a-<?= $tid ?>">Acción correctiva</label><input class="input sm mt" id="tx-a-<?= $tid ?>" name="corrective_action" maxlength="1000" placeholder="Acción correctiva (opcional)">
                  <span class="error" data-error-for="reason"></span>
                  <button class="btn btn--sm mt" type="submit"><?= icon('check', 'sm') ?> Guardar motivo</button>
                </form>
              </details>
            <?php endif; ?>
          </div>
        </div>
      </section>

      <section class="task-section">
        <div id="td-comments" data-region>
          <h3><?= icon('message') ?> Comentarios <span class="count"><?= count($comments) ?></span></h3>
          <?php foreach ($comments as $c): $mine = (int)$c['user_id'] === Auth::id(); ?>
            <div class="comment" id="comment-<?= (int)$c['id'] ?>">
              <?= avatar($c['user_name'] ? ['id' => $c['user_id'], 'name' => $c['user_name'], 'avatar_color' => $c['avatar_color']] : null, 'sm') ?>
              <div class="comment-body">
                <div class="comment-meta">
                  <strong><?= e($c['user_name'] ?? 'Usuario eliminado') ?></strong>
                  <span class="muted" title="<?= e(fmt_datetime($c['created_at'])) ?>"><?= e(time_ago($c['created_at'])) ?><?= $c['edited_at'] ? ' · editado' : '' ?></span>
                  <span class="comment-actions">
                    <?php if ($mine && $can('comment.create')): ?><button class="btn btn--ghost btn--icon btn--sm" type="button" data-toggle="#cv-<?= (int)$c['id'] ?>,#ce-<?= (int)$c['id'] ?>" aria-label="Editar comentario"><?= icon('edit', 'sm') ?></button><?php endif; ?>
                    <?php if ($mine || $can('project.manage')): ?><button class="btn btn--ghost btn--icon btn--sm" type="button" data-call="/api/comments/<?= (int)$c['id'] ?>" data-method="DELETE" data-confirm="¿Eliminar este comentario?" data-confirm-label="Eliminar" aria-label="Eliminar comentario"><?= icon('trash', 'sm') ?></button><?php endif; ?>
                  </span>
                </div>
                <div class="rich" id="cv-<?= (int)$c['id'] ?>"><?= rich_text($c['body']) ?></div>
                <?php if ($mine): ?>
                  <form id="ce-<?= (int)$c['id'] ?>" hidden data-api="/api/comments/<?= (int)$c['id'] ?>" data-method="PATCH">
                    <textarea class="textarea" name="body" rows="3" required maxlength="10000"><?= e($c['body']) ?></textarea>
                    <div class="form-actions start"><button class="btn btn--primary btn--sm" type="submit">Guardar</button><button class="btn btn--sm" type="button" data-toggle="#cv-<?= (int)$c['id'] ?>,#ce-<?= (int)$c['id'] ?>" data-reset-form>Cancelar</button></div>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if (!$comments): ?><p class="muted small">Todavía no hay comentarios.</p><?php endif; ?>
        </div>
        <?php if ($can('comment.create')): ?>
          <form class="comment" data-api="/api/tasks/<?= $tid ?>/comments" data-reset>
            <?= avatar(Auth::user(), 'sm') ?>
            <div style="flex:1;min-width:0;position:relative">
              <div class="form-error" data-form-error></div>
              <label class="sr-only" for="td-comment-<?= $tid ?>">Comentario</label>
              <textarea class="textarea" id="td-comment-<?= $tid ?>" name="body" rows="2" maxlength="10000" required placeholder="Escribe un comentario. Usa @ para mencionar." data-mentions="/api/projects/<?= $pid ?>/members"></textarea>
              <div class="form-actions start"><button class="btn btn--primary btn--sm" type="submit">Comentar</button><span class="muted small">Ctrl + Enter para enviar</span></div>
            </div>
          </form>
        <?php endif; ?>
      </section>

      <section class="task-section">
        <div id="td-history" data-region>
          <h3><?= icon('clock') ?> Historial</h3>
          <ul class="history">
            <?php foreach ($history as $h): ?>
              <li><span><strong><?= e($h['user_name'] ?? 'Sistema') ?></strong> <?= e(lcfirst($h['summary'])) ?> <span class="muted">· <?= e(time_ago($h['created_at'])) ?></span></span></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </section>
    </div>

    <aside class="task-side ts" id="td-side" data-region aria-label="Propiedades de la tarea">
      <?php
        $people = $t['assignee_id'] ? [['id' => (int)$t['assignee_id'], 'name' => $t['assignee_name'], 'avatar_color' => $t['assignee_color']]] : [];
        foreach ($t['co_assignees'] ?? [] as $co) $people[] = ['id' => (int)$co['id'], 'name' => $co['name'], 'avatar_color' => $co['avatar_color']];
        $peopleIds = array_column($people, 'id');
        $pickList = $assignable;
        $assignableIds = array_map('intval', array_column($assignable, 'id'));
        foreach ($people as $pp) if (!in_array($pp['id'], $assignableIds, true)) $pickList[] = $pp;
        $num = fn($v) => $v !== null ? rtrim(rtrim((string)$v, '0'), '.') : '';
        // Task in a sprint: show each person's free hours there; nobody can be given more than they have.
        $capBy = [];
        $capSprint = null;
        if (!empty($modules['sprints']) && $t['sprint_id'] && !$t['parent_id']) {
            $capSprint = \Borealis\Core\Database::get()->one('SELECT * FROM sprints WHERE id = ?', [(int)$t['sprint_id']]);
            if ($capSprint) foreach (\Borealis\Services\Capacity::people($capSprint, $t['project']) as $cp) $capBy[(int)$cp['id']] = $cp;
        }
        $est = $t['estimate_hours'] !== null ? (float)$t['estimate_hours'] : null;
        $hrs = fn(float $x) => rtrim(rtrim(number_format($x, 1, ',', ''), '0'), ',');
      ?>
      <div class="ts-top">
        <label class="sr-only" for="td-status-<?= $tid ?>">Estado</label>
        <select class="select sm ts-status" id="td-status-<?= $tid ?>" name="status_id" data-patch="<?= e($patch) ?>"<?= $dis ?>>
          <?php foreach ($statuses as $s): ?><option value="<?= (int)$s['id'] ?>"<?= selected($s['id'], $t['status_id']) ?>><?= e($s['name']) ?></option><?php endforeach; ?>
        </select>
        <?php if ($edit && !$done): ?>
          <button class="btn btn--aurora btn--sm" type="button" data-call="/api/tasks/<?= $tid ?>/done" data-body='{"done":true}' title="Marcar como finalizada"><?= icon('check') ?> Finalizar</button>
        <?php elseif ($edit && $done): ?>
          <button class="btn btn--sm" type="button" data-call="/api/tasks/<?= $tid ?>/done" data-body='{"done":false}'><?= icon('restore') ?> Reabrir</button>
        <?php endif; ?>
      </div>
      <span class="save-state ts-save" data-save-state aria-live="polite"></span>

      <dl class="ts-props">
        <dt class="ts-wide" id="td-people-l-<?= $tid ?>">Responsables</dt>
        <dd class="ts-wide">
          <details class="multi-pick ts-pick" id="td-people-<?= $tid ?>" data-live data-patch-multi="<?= e($patch) ?>" data-main="<?= (int)$t['assignee_id'] ?>">
            <summary aria-labelledby="td-people-l-<?= $tid ?>"<?= $edit ? '' : ' tabindex="-1"' ?>>
              <?php if (!$people && empty($t['team_name'])): ?><span class="muted">Sin asignar</span><?php endif; ?>
              <?php foreach ($people as $i => $pp): ?><span class="ts-person" title="<?= e($pp['name']) ?><?= $i === 0 ? ' (principal)' : '' ?>"><?= avatar($pp, 'sm') ?><span class="truncate"><?= e(count($people) > 2 ? strtok($pp['name'], ' ') : $pp['name']) ?></span></span><?php endforeach; ?>
              <?php if (!empty($t['team_name'])): ?><span class="team-chip" style="--c:<?= e($t['team_color'] ?? '#10B981') ?>"><?= icon('users', 'sm') ?><span class="truncate"><?= e($t['team_name']) ?></span></span><?php endif; ?>
            </summary>
            <?php if ($edit): ?>
            <div class="multi-pick-list" role="group" aria-labelledby="td-people-l-<?= $tid ?>">
              <span class="ts-pick-title">Personas <span class="muted">· la primera es la principal<?= $capSprint ? ' · horas libres en ' . e($capSprint['name']) : '' ?></span></span>
              <?php foreach ($pickList as $m): $mid = (int)$m['id']; $on = in_array($mid, $peopleIds, true); $cp = $capBy[$mid] ?? null;
                $noRoom = false; $freeTxt = '';
                if ($cp && $cp['capacity'] !== null) {
                    $freeTxt = $cp['over'] ? $hrs($cp['load'] - (float)$cp['capacity']) . ' h de más' : ($cp['full'] ? 'sin horas' : $hrs((float)$cp['free']) . ' h libres');
                    $noRoom = !$on && $est !== null && $est > 0 && (float)$cp['free'] + 0.001 < $est / (count($peopleIds) + 1);
                } ?>
                <label class="check<?= $noRoom ? ' is-disabled' : '' ?>"<?= $noRoom ? ' title="' . e(strtok($m['name'], ' ') . ' solo tiene ' . $hrs(max(0, (float)$cp['free'])) . ' h disponibles') . '"' : '' ?>><input type="checkbox" name="co_assignee" value="<?= $mid ?>"<?= checked($on) ?><?= $noRoom ? ' disabled' : '' ?>> <?= avatar($m, 'sm') ?> <span class="pick-name"><?= e($m['name']) ?></span><?= $mid === (int)$t['assignee_id'] ? ' <span class="badge">Principal</span>' : '' ?><?php if ($freeTxt): ?><span class="pick-free<?= $cp['full'] ? ' is-full' : '' ?>" data-free-for="<?= $mid ?>"><?= e($freeTxt) ?></span><?php endif; ?></label>
              <?php endforeach; ?>
              <?php if ($capSprint && $est === null): ?><p class="muted small" style="margin:2px 0 0">Sin horas estimadas: su carga queda pendiente de definir.</p><?php endif; ?>
              <?php if (!$pickList): ?><p class="muted small" style="margin:0">Nadie del proyecto puede recibir tareas todavía.</p><?php endif; ?>
              <label class="ts-pick-title" for="td-team-<?= $tid ?>">Equipo</label>
              <select class="select sm" id="td-team-<?= $tid ?>" name="team_id" data-patch="<?= e($patch) ?>">
                <option value="">Ningún equipo</option>
                <?php $teamIds = []; foreach ($taskTeams ?? [] as $tt): $teamIds[] = (int)$tt['id']; ?><option value="<?= (int)$tt['id'] ?>"<?= selected($tt['id'], $t['team_id']) ?>><?= e($tt['name']) ?></option><?php endforeach; ?>
                <?php if ($t['team_id'] && !in_array((int)$t['team_id'], $teamIds, true)): ?><option value="<?= (int)$t['team_id'] ?>" selected><?= e($t['team_name']) ?></option><?php endif; ?>
              </select>
            </div>
            <?php endif; ?>
          </details>
        </dd>

        <dt><label for="td-prio-<?= $tid ?>">Prioridad</label></dt>
        <dd><select class="select sm ts-control" id="td-prio-<?= $tid ?>" name="priority_id" data-patch="<?= e($patch) ?>"<?= $dis ?>>
          <option value="">Sin prioridad</option>
          <?php foreach ($priorities as $pr): ?><option value="<?= (int)$pr['id'] ?>"<?= selected($pr['id'], $t['priority_id']) ?>><?= e($pr['name']) ?></option><?php endforeach; ?>
        </select></dd>

        <dt><label for="td-start-<?= $tid ?>">Inicio</label></dt>
        <dd><input class="input sm ts-control" type="date" id="td-start-<?= $tid ?>" name="start_date" value="<?= e($t['start_date']) ?>" data-patch="<?= e($patch) ?>"<?= $dis ?>></dd>
        <dt><label for="td-due-<?= $tid ?>">Vence</label></dt>
        <dd class="ts-due"><input class="input sm ts-control" type="date" id="td-due-<?= $tid ?>" name="due_date" value="<?= e($t['due_date']) ?>" data-patch="<?= e($patch) ?>"<?= $dis ?>>
          <label class="sr-only" for="td-dt-<?= $tid ?>">Hora de entrega</label><input class="input sm ts-control ts-time" type="time" id="td-dt-<?= $tid ?>" name="due_time" value="<?= e(substr((string)($t['due_time'] ?? ''), 0, 5)) ?>" title="Hora de entrega (opcional)" data-patch="<?= e($patch) ?>"<?= $dis ?>>
          <?php if ($lt = late_tag($t, true)): ?><span class="ts-late"><?= $lt ?></span><?php endif; ?></dd>

        <dt><label for="td-eh-<?= $tid ?>">Estimado</label></dt>
        <dd class="ts-inline"><input class="input sm ts-control ts-num" type="number" min="0" step="0.5" id="td-eh-<?= $tid ?>" name="estimate_hours" value="<?= e($num($t['estimate_hours'])) ?>" placeholder="—" data-patch="<?= e($patch) ?>"<?= $dis ?>><span class="muted small">h</span>
          <?php if ($modules['estimates']): ?><label class="sr-only" for="td-est-<?= $tid ?>">Estimación en puntos</label><input class="input sm ts-control ts-num" type="number" min="0" step="0.5" id="td-est-<?= $tid ?>" name="estimate" value="<?= e($num($t['estimate'])) ?>" placeholder="—" data-patch="<?= e($patch) ?>"<?= $dis ?>><span class="muted small">pts</span><?php endif; ?></dd>
        <?php if ($edit && !$t['parent_id']): ?><dd class="ts-suggest"><button class="link-btn small" type="button" data-estimate-suggest data-task="<?= $tid ?>" data-for="#td-eh-<?= $tid ?>">Sugerencia según el historial</button><div data-estimate-out></div></dd><?php endif; ?>

        <?php if ($modules['sprints'] && !$t['parent_id']): ?>
          <dt><label for="td-sprint-<?= $tid ?>">Sprint</label></dt>
          <dd><select class="select sm ts-control" id="td-sprint-<?= $tid ?>" name="sprint_id" data-patch="<?= e($patch) ?>"<?= $dis ?>>
            <option value="">Backlog</option>
            <?php foreach ($sprints as $sp): ?><option value="<?= (int)$sp['id'] ?>"<?= selected($sp['id'], $t['sprint_id']) ?><?= $sp['state'] === 'completed' ? ' disabled' : '' ?>><?= e($sp['name']) ?><?= $sp['state'] === 'active' ? ' (activo)' : '' ?></option><?php endforeach; ?>
          </select></dd>
        <?php endif; ?>
        <?php if ($modules['epics']): ?>
          <dt><label for="td-epic-<?= $tid ?>">Épica</label></dt>
          <dd><select class="select sm ts-control" id="td-epic-<?= $tid ?>" name="epic_id" data-patch="<?= e($patch) ?>"<?= $dis ?>>
            <option value="">Sin épica</option>
            <?php foreach ($epics as $ep): ?><option value="<?= (int)$ep['id'] ?>"<?= selected($ep['id'], $t['epic_id']) ?>><?= e($ep['name']) ?></option><?php endforeach; ?>
          </select></dd>
        <?php endif; ?>

        <dt>Etiquetas</dt>
        <dd class="ts-inline wrap">
          <?php foreach ($t['labels'] as $l): ?><span class="label-chip" style="--c:<?= e($l['color']) ?>"><?= e($l['name']) ?></span><?php endforeach; ?>
          <?php if ($edit): ?>
            <div class="menu-wrap">
              <button class="btn btn--ghost btn--sm ts-add" type="button" data-menu aria-haspopup="true" aria-expanded="false" aria-label="Editar etiquetas"><?= icon($t['labels'] ? 'edit' : 'plus', 'sm') ?><?= $t['labels'] ? '' : ' Añadir' ?></button>
              <div class="menu left" style="width:230px">
                <form data-api="<?= e($patch) ?>" data-method="PATCH" data-array="label_ids">
                  <input type="hidden" name="label_ids[]" value="">
                  <?php foreach ($labels as $l): ?>
                    <label class="check" style="padding:5px 8px"><input type="checkbox" name="label_ids[]" value="<?= (int)$l['id'] ?>"<?= checked(in_array((int)$l['id'], $taskLabelIds, true)) ?>> <span class="label-chip" style="--c:<?= e($l['color']) ?>"><?= e($l['name']) ?></span></label>
                  <?php endforeach; ?>
                  <?php if (!$labels): ?><p class="muted small" style="padding:4px 8px">Aún no hay etiquetas.</p><?php endif; ?>
                  <div style="padding:6px 8px"><button class="btn btn--primary btn--sm" type="submit" style="width:100%">Aplicar</button></div>
                </form>
                <hr>
                <form class="row" style="padding:4px 8px" data-api="/api/projects/<?= $pid ?>/labels" data-reset>
                  <input type="hidden" name="task_id" value="<?= $tid ?>">
                  <input class="input sm" name="name" placeholder="Nueva etiqueta" maxlength="40" required aria-label="Nueva etiqueta">
                  <button class="btn btn--sm" type="submit" aria-label="Crear etiqueta"><?= icon('plus', 'sm') ?></button>
                </form>
              </div>
            </div>
          <?php elseif (!$t['labels']): ?><span class="muted small">Ninguna</span><?php endif; ?>
        </dd>

        <dt>Bloqueada</dt>
        <dd>
          <label class="check"><input type="checkbox" name="is_blocked" data-patch="<?= e($patch) ?>"<?= checked((bool)$t['is_blocked']) ?><?= $dis ?>> <span class="muted small"><?= $t['is_blocked'] ? 'Sí' : 'No' ?></span></label>
          <?php if ($t['is_blocked']): ?>
            <label class="sr-only" for="td-br-<?= $tid ?>">Motivo del bloqueo</label>
            <input class="input sm ts-control mt" id="td-br-<?= $tid ?>" name="blocked_reason" value="<?= e($t['blocked_reason']) ?>" placeholder="¿Qué lo impide?" maxlength="255" data-patch="<?= e($patch) ?>"<?= $dis ?>>
          <?php endif; ?>
        </dd>
      </dl>

      <p class="ts-meta muted small">
        Creada <?= e(fmt_datetime($t['created_at'])) ?> · actualizada <?= e(time_ago($t['updated_at'])) ?>
        <?php if ($t['completed_at']): ?><br>Finalizada <?= e(fmt_datetime($t['completed_at'])) ?><?php $lt = lateness($t); if ($lt && $lt['kind'] === 'done-late'): ?> · fuera de plazo (fecha límite original: <?= e(fmt_date((string)$t['completed_due_date'], true)) ?>)<?php endif; ?><?php endif; ?>
      </p>
    </aside>
  </div>
</div>
