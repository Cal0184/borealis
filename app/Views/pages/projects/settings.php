<?= partial('project-header', compact('project', 'modules', 'tab', 'can')) ?>
<?php
use Borealis\Services\Projects;
$p = $project;
$pid = (int)$p['id'];
$manage = $can('project.manage');
$sections = ['general' => ['General', 'settings'], 'members' => ['Integrantes', 'users'], 'statuses' => ['Columnas y estados', 'board'], 'labels' => ['Etiquetas', 'tag'], 'modules' => ['Módulos', 'layers']];
if (!isset($sections[$section])) $section = 'general';
$categories = ['todo' => 'Por hacer', 'in_progress' => 'En curso', 'done' => 'Finalizado'];
?>
<div class="settings-layout">
  <nav class="side-nav" aria-label="Ajustes del proyecto">
    <?php foreach ($sections as $k => [$label, $ic]): ?>
      <a href="<?= e(url('/projects/' . $pid . '/settings', ['section' => $k])) ?>"<?= $section === $k ? ' aria-current="page"' : '' ?>><?= icon($ic) ?> <?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="stack">
  <?php if ($section === 'general'): ?>
    <section class="card">
      <div class="card-head"><h2>Datos del proyecto</h2><span class="key">ID interno <?= $pid ?> · clave <?= e($p['project_key']) ?></span></div>
      <div class="card-body">
        <form data-api="/api/projects/<?= $pid ?>" data-method="PATCH" id="project-form">
          <div class="form-error" data-form-error></div>
          <div class="field"><label for="ps-name">Nombre</label><input class="input" id="ps-name" name="name" value="<?= e($p['name']) ?>" required maxlength="160"><span class="error" data-error-for="name"></span></div>
          <div class="field"><label for="ps-desc">Descripción</label><textarea class="textarea" id="ps-desc" name="description" rows="4" maxlength="5000"><?= e($p['description']) ?></textarea></div>
          <div class="form-row">
            <div class="field"><label for="ps-state">Estado</label><select class="select" id="ps-state" name="state"><?php foreach (Projects::STATES as $k => $v): ?><option value="<?= $k ?>"<?= selected($k, $p['state']) ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="ps-lead">Responsable del proyecto</label><select class="select" id="ps-lead" name="lead_user_id"><option value="">Sin responsable</option><?php foreach ($people as $u): ?><option value="<?= (int)$u['id'] ?>"<?= selected($u['id'], $p['lead_user_id']) ?>><?= e($u['name']) ?></option><?php endforeach; ?></select></div>
          </div>
          <div class="form-row">
            <div class="field"><label for="ps-start">Inicio</label><input class="input" type="date" id="ps-start" name="start_date" value="<?= e($p['start_date']) ?>"></div>
            <div class="field"><label for="ps-due">Entrega</label><input class="input" type="date" id="ps-due" name="due_date" value="<?= e($p['due_date']) ?>"><span class="error" data-error-for="due_date"></span></div>
            <div class="field"><label for="ps-color">Color</label><input class="color-input" type="color" id="ps-color" name="color" value="<?= e($p['color']) ?>"></div>
          </div>
          <div class="form-row">
            <div class="field"><label for="ps-team">Equipo</label><select class="select" id="ps-team" name="team_id"><option value="">Sin equipo</option><?php foreach ($teams as $t): ?><option value="<?= (int)$t['id'] ?>"<?= selected($t['id'], $p['team_id']) ?>><?= e($t['name']) ?></option><?php endforeach; ?></select><span class="hint">Las personas del equipo obtienen acceso automáticamente.</span></div>
            <div class="field"><label for="ps-teamrole">Rol de las personas del equipo</label><select class="select" id="ps-teamrole" name="team_role_id"><option value="">Colaborador (predeterminado)</option><?php foreach ($projectRoles as $r): if ($r['role_key'] === 'manager') continue; ?><option value="<?= (int)$r['id'] ?>"<?= selected($r['id'], $p['team_role_id']) ?>><?= e($r['name']) ?></option><?php endforeach; ?></select><span class="hint">La coordinación del equipo siempre es responsable.</span></div>
          </div>
          <div class="form-row">
            <div class="field key-field"><label for="ps-key">Siglas</label><input class="input key-input" id="ps-key" name="project_key" value="<?= e($p['project_key']) ?>" required maxlength="6" pattern="[A-Za-z][A-Za-z0-9]{1,5}" title="De 2 a 6 letras o números, empezando por letra"><span class="error" data-error-for="project_key"></span></div>
            <div class="field grow"><span class="hint" style="margin-top:28px;display:block">Encabezan las tareas (<?= e($p['project_key']) ?>-1). Si las cambias, todas las tareas pasan a las nuevas siglas y los enlaces antiguos siguen funcionando.</span></div>
          </div>
          <fieldset class="field cover-field"><legend>Fondo del proyecto</legend>
            <div class="cover-grid">
              <?php foreach (Projects::COVERS as $ck => $cn): ?>
                <label class="cover-opt"><input type="radio" name="cover" value="<?= $ck ?>"<?= checked(($p['cover'] ?? 'forest') === $ck) ?>><span class="cover-thumb cover-<?= $ck ?>" style="--pc:<?= e($p['color']) ?>"></span><span class="small"><?= e($cn) ?></span></label>
              <?php endforeach; ?>
            </div>
          </fieldset>
          <p class="hint"><?= icon('lock', 'sm') ?> Quién lo ve: sus integrantes (pestaña «Integrantes»), los equipos que participan y la administración del espacio. Para dar acceso a alguien, añádelo como integrante.</p>
          <div class="field"><input type="hidden" name="is_flexible" value="0"><label class="check"><input type="checkbox" id="ps-flex" name="is_flexible" value="1"<?= checked((int)($p['is_flexible'] ?? 0) === 1) ?>> Proyecto polivalente: las personas se pueden mover entre tarjetas desde el tablero</label><span class="hint">Si no lo marcas, también puedes marcar como polivalente solo a un equipo participante.</span></div>
          <div class="form-actions start"><button class="btn btn--primary" type="submit">Guardar cambios</button></div>
        </form>
      </div>
    </section>
    <section class="card mb" id="project-export">
      <div class="card-head"><h2><?= icon('download') ?> Exportar proyecto</h2></div>
      <div class="card-body">
        <p class="muted" style="margin:0 0 10px">Descarga el proyecto completo (columnas, etiquetas, épicas, sprints, tareas con subtareas, responsables, comentarios, horas y documentos) en un archivo .json. Puedes importarlo en este u otro Borealis desde Proyectos › Importar. Las personas se reconocen por su correo.</p>
        <div class="row wrap">
          <a class="btn" href="<?= e(url('/projects/' . $pid . '/export.json')) ?>" download><?= icon('download', 'sm') ?> Exportar sin archivos</a>
          <a class="btn" href="<?= e(url('/projects/' . $pid . '/export.json', ['files' => 1])) ?>" download><?= icon('clip', 'sm') ?> Exportar con archivos adjuntos</a>
        </div>
        <p class="muted small" style="margin:8px 0 0">Con archivos, se incluyen hasta 25 MB de adjuntos dentro del .json.</p>
      </div>
    </section>
    <?php if ($can('project.archive')): ?>
    <section class="card">
      <div class="card-head"><h2><?= icon('archive') ?> Archivo</h2></div>
      <div class="card-body row between wrap">
        <p class="muted" style="margin:0">Archivar oculta el proyecto de las listas y lo deja en solo lectura. Nada se elimina y puedes restaurarlo cuando quieras.</p>
        <?php if ($p['archived_at']): ?>
          <button class="btn" data-call="/api/projects/<?= $pid ?>/restore"><?= icon('restore') ?> Restaurar proyecto</button>
        <?php else: ?>
          <button class="btn btn--danger" data-call="/api/projects/<?= $pid ?>/archive" data-confirm="¿Archivar «<?= e($p['name']) ?>»? Quedará en solo lectura hasta que lo restaures." data-confirm-label="Archivar"><?= icon('archive') ?> Archivar proyecto</button>
        <?php endif; ?>
      </div>
    </section>
    <?php endif; ?>

  <?php elseif ($section === 'members'): ?>
    <?php if ($accessTeams): ?>
    <section class="card mb">
      <div class="card-head"><h2><?= icon('users') ?> Equipos participantes</h2></div>
      <ul class="list">
        <?php foreach ($accessTeams as $at): ?>
          <li><a class="person" style="flex:1" href="<?= e(url('/teams/' . (int)$at['id'])) ?>"><span class="dot" style="--dot:<?= e($at['color']) ?>;--c:<?= e($at['color']) ?>"></span><strong><?= e($at['name']) ?></strong></a>
            <?php if ($at['main']): ?><span class="badge">Equipo principal</span><span class="muted small">Se cambia en General</span>
            <?php else: ?><span class="muted small"><?= e(\Borealis\Services\Workspaces::role((int)$at['role_id'])['name'] ?? '') ?></span>
              <?php if ($manage): ?><button class="btn btn--ghost btn--icon btn--sm" data-call="/api/projects/<?= $pid ?>/teams/<?= (int)$at['id'] ?>" data-method="DELETE" data-confirm="¿Retirar al equipo <?= e($at['name']) ?> del proyecto? Sus integrantes dejan de tener acceso salvo que estén añadidos de forma individual." data-confirm-label="Retirar" aria-label="Retirar al equipo <?= e($at['name']) ?>"><?= icon('x', 'sm') ?></button><?php endif; ?>
            <?php endif; ?></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>
    <section class="card">
      <div class="card-head"><h2><?= icon('users') ?> Integrantes del proyecto</h2></div>
      <ul class="list">
        <?php foreach ($members as $m): ?>
          <li>
            <a class="person" style="flex:1" href="<?= e(url('/people/' . (int)$m['id'])) ?>"><?= avatar($m, 'sm') ?><span class="truncate"><strong><?= e($m['name']) ?></strong> <span class="muted small"><?= e($m['email']) ?></span></a></span>
            <?php if ($m['source'] === 'team'): ?>
              <span class="badge" title="Participa por el equipo <?= e($m['team_name'] ?? '') ?>"><?= icon('users', 'sm') ?> <?= e($m['team_name'] ?? 'Por el equipo') ?></span><span class="muted small"><?= e($m['role_name']) ?></span>
            <?php elseif ($manage): ?>
              <form data-api="/api/projects/<?= $pid ?>/members" class="row">
                <input type="hidden" name="user_id" value="<?= (int)$m['id'] ?>">
                <label class="sr-only" for="pm-r-<?= (int)$m['id'] ?>">Rol de <?= e($m['name']) ?></label>
                <select class="select sm" id="pm-r-<?= (int)$m['id'] ?>" name="role_id" data-autosubmit style="width:auto"><?php foreach ($projectRoles as $r): ?><option value="<?= (int)$r['id'] ?>"<?= selected($r['id'], $m['role_id']) ?>><?= e($r['name']) ?></option><?php endforeach; ?></select>
              </form>
              <button class="btn btn--ghost btn--icon btn--sm" data-call="/api/projects/<?= $pid ?>/members/<?= (int)$m['id'] ?>" data-method="DELETE" data-confirm="¿Quitar a <?= e($m['name']) ?> del proyecto? Sus tareas asignadas se conservan." data-confirm-label="Quitar" aria-label="Quitar a <?= e($m['name']) ?>"><?= icon('x', 'sm') ?></button>
            <?php else: ?><span class="muted small"><?= e($m['role_name']) ?></span><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php $linkedTeamIds = array_map('intval', array_column($accessTeams, 'id')); $teamCandidates = array_filter($teams, fn($t) => !in_array((int)$t['id'], $linkedTeamIds, true)); ?>
      <?php if ($manage && $teamCandidates): ?>
        <div class="card-foot">
          <form class="row wrap" data-api="/api/projects/<?= $pid ?>/teams" data-reset>
            <label class="sr-only" for="pt-add">Equipo</label>
            <select class="select sm" id="pt-add" name="team_id" required style="width:auto;min-width:220px"><option value="">Añadir equipo completo…</option><?php foreach ($teamCandidates as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?> (<?= (int)$t['member_count'] ?>)</option><?php endforeach; ?></select>
            <label class="sr-only" for="pt-add-role">Rol de sus integrantes</label>
            <select class="select sm" id="pt-add-role" name="role_id" style="width:auto"><?php foreach ($projectRoles as $r): ?><option value="<?= (int)$r['id'] ?>"<?= selected($r['role_key'], 'contributor') ?>><?= e($r['name']) ?></option><?php endforeach; ?></select>
            <button class="btn btn--sm" type="submit"><?= icon('users', 'sm') ?> Añadir equipo</button>
          </form>
          <p class="muted small" style="margin:6px 0 0">Todo el equipo participa: quien entre al equipo después también tendrá acceso. La coordinación del equipo queda como responsable del proyecto.</p>
        </div>
      <?php endif; ?>
      <?php $candidates = array_filter($people, fn($u) => !in_array((int)$u['id'], $memberIds, true)); if ($manage && $candidates): ?>
        <div class="card-foot">
          <form class="row wrap" data-api="/api/projects/<?= $pid ?>/members" data-reset>
            <label class="sr-only" for="pm-add">Persona</label>
            <select class="select sm" id="pm-add" name="user_id" required style="width:auto;min-width:220px"><option value="">Añadir persona…</option><?php foreach ($candidates as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?></select>
            <label class="sr-only" for="pm-add-role">Rol</label>
            <select class="select sm" id="pm-add-role" name="role_id" style="width:auto"><?php foreach ($projectRoles as $r): ?><option value="<?= (int)$r['id'] ?>"<?= selected($r['role_key'], 'contributor') ?>><?= e($r['name']) ?></option><?php endforeach; ?></select>
            <button class="btn btn--primary btn--sm" type="submit">Añadir</button>
          </form>
        </div>
      <?php endif; ?>
    </section>

  <?php elseif ($section === 'statuses'): ?>
    <section class="card">
      <div class="card-head"><h2><?= icon('board') ?> Columnas del tablero</h2><span class="muted small">Cada columna es un estado. El tipo indica si cuenta como pendiente, en curso o finalizada.</span></div>
      <div>
        <?php foreach ($statuses as $i => $s): $sid = (int)$s['id']; ?>
          <div class="status-row">
            <div class="row" style="gap:2px">
              <button class="btn btn--ghost btn--icon btn--sm" data-call="/api/projects/<?= $pid ?>/statuses/<?= $sid ?>/move" data-body='{"dir":-1}' aria-label="Subir <?= e($s['name']) ?>"<?= $i === 0 ? ' disabled' : '' ?>><?= icon('arrow-up', 'sm') ?></button>
              <button class="btn btn--ghost btn--icon btn--sm" data-call="/api/projects/<?= $pid ?>/statuses/<?= $sid ?>/move" data-body='{"dir":1}' aria-label="Bajar <?= e($s['name']) ?>"<?= $i === count($statuses) - 1 ? ' disabled' : '' ?>><?= icon('arrow-down', 'sm') ?></button>
            </div>
            <label class="sr-only" for="st-c-<?= $sid ?>">Color</label>
            <input class="color-input" type="color" id="st-c-<?= $sid ?>" name="color" value="<?= e($s['color']) ?>" data-patch="/api/projects/<?= $pid ?>/statuses/<?= $sid ?>">
            <label class="sr-only" for="st-n-<?= $sid ?>">Nombre</label>
            <input class="input sm" id="st-n-<?= $sid ?>" name="name" value="<?= e($s['name']) ?>" required maxlength="60" data-patch="/api/projects/<?= $pid ?>/statuses/<?= $sid ?>">
            <label class="sr-only" for="st-k-<?= $sid ?>">Tipo</label>
            <select class="select sm" id="st-k-<?= $sid ?>" name="category" style="width:auto" data-patch="/api/projects/<?= $pid ?>/statuses/<?= $sid ?>"><?php foreach ($categories as $k => $v): ?><option value="<?= $k ?>"<?= selected($k, $s['category']) ?>><?= e($v) ?></option><?php endforeach; ?></select>
            <label class="sr-only" for="st-w-<?= $sid ?>">Límite de tarjetas</label>
            <input class="input sm" type="number" min="0" id="st-w-<?= $sid ?>" name="wip_limit" value="<?= e($s['wip_limit']) ?>" placeholder="Sin límite" style="width:110px" title="Límite de tarjetas (opcional)" data-patch="/api/projects/<?= $pid ?>/statuses/<?= $sid ?>">
            <button class="btn btn--ghost btn--icon btn--sm" data-dialog="dlg-del-status-<?= $sid ?>" aria-label="Eliminar <?= e($s['name']) ?>"><?= icon('trash', 'sm') ?></button>
          </div>
          <dialog class="modal" id="dlg-del-status-<?= $sid ?>" aria-labelledby="dds-<?= $sid ?>">
            <form data-api="/api/projects/<?= $pid ?>/statuses/<?= $sid ?>" data-method="DELETE" data-close>
              <div class="modal-head"><h2 id="dds-<?= $sid ?>">Eliminar «<?= e($s['name']) ?>»</h2><button type="button" class="btn btn--ghost btn--icon" data-close-dialog aria-label="Cerrar"><?= icon('x') ?></button></div>
              <div class="modal-body">
                <div class="form-error" data-form-error></div>
                <p>Las tareas de esta columna no se eliminan: se moverán a la columna que elijas.</p>
                <div class="field"><label for="dst-<?= $sid ?>">Mover sus tareas a</label><select class="select" id="dst-<?= $sid ?>" name="target_id"><?php foreach ($statuses as $o): if ((int)$o['id'] === $sid) continue; ?><option value="<?= (int)$o['id'] ?>"><?= e($o['name']) ?></option><?php endforeach; ?></select><span class="error" data-error-for="target_id"></span></div>
              </div>
              <div class="modal-foot"><button type="button" class="btn" data-close-dialog>Cancelar</button><button type="submit" class="btn btn--danger-solid">Eliminar columna</button></div>
            </form>
          </dialog>
        <?php endforeach; ?>
      </div>
      <div class="card-foot">
        <form class="row wrap" data-api="/api/projects/<?= $pid ?>/statuses" data-reset>
          <label class="sr-only" for="ns-name">Nueva columna</label>
          <input class="input sm" id="ns-name" name="name" placeholder="Nueva columna" required maxlength="60" style="width:220px">
          <label class="sr-only" for="ns-cat">Tipo</label>
          <select class="select sm" id="ns-cat" name="category" style="width:auto"><?php foreach ($categories as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select>
          <input type="hidden" name="color" value="#94A3B8">
          <button class="btn btn--primary btn--sm" type="submit"><?= icon('plus', 'sm') ?> Añadir columna</button>
        </form>
      </div>
    </section>

  <?php elseif ($section === 'labels'): ?>
    <section class="card">
      <div class="card-head"><h2><?= icon('tag') ?> Etiquetas</h2></div>
      <div>
        <?php foreach ($labels as $l): $lid = (int)$l['id']; ?>
          <div class="status-row">
            <label class="sr-only" for="lb-c-<?= $lid ?>">Color</label>
            <input class="color-input" type="color" id="lb-c-<?= $lid ?>" name="color" value="<?= e($l['color']) ?>" data-patch="/api/projects/<?= $pid ?>/labels/<?= $lid ?>" data-patch-with="name:#lb-n-<?= $lid ?>">
            <label class="sr-only" for="lb-n-<?= $lid ?>">Nombre</label>
            <input class="input sm" id="lb-n-<?= $lid ?>" name="name" value="<?= e($l['name']) ?>" maxlength="40" required data-patch="/api/projects/<?= $pid ?>/labels/<?= $lid ?>" data-patch-with="color:#lb-c-<?= $lid ?>">
            <span class="label-chip" style="--c:<?= e($l['color']) ?>"><?= e($l['name']) ?></span>
            <button class="btn btn--ghost btn--icon btn--sm" data-call="/api/projects/<?= $pid ?>/labels/<?= $lid ?>" data-method="DELETE" data-confirm="¿Eliminar la etiqueta «<?= e($l['name']) ?>»? Se quitará de las tareas, que se conservan." data-confirm-label="Eliminar" aria-label="Eliminar <?= e($l['name']) ?>"><?= icon('trash', 'sm') ?></button>
          </div>
        <?php endforeach; ?>
        <?php if (!$labels): ?><div class="empty" style="padding:20px">Sin etiquetas todavía.</div><?php endif; ?>
      </div>
      <div class="card-foot">
        <form class="row wrap" data-api="/api/projects/<?= $pid ?>/labels" data-reset>
          <label class="sr-only" for="nl-color">Color</label><input class="color-input" type="color" id="nl-color" name="color" value="#0EA5E9">
          <label class="sr-only" for="nl-name">Nueva etiqueta</label><input class="input sm" id="nl-name" name="name" placeholder="Nueva etiqueta" required maxlength="40" style="width:220px">
          <button class="btn btn--primary btn--sm" type="submit"><?= icon('plus', 'sm') ?> Crear etiqueta</button>
        </form>
      </div>
    </section>

  <?php elseif ($section === 'modules'): ?>
    <section class="card">
      <div class="card-head"><h2><?= icon('layers') ?> Herramientas del proyecto</h2></div>
      <div class="card-body" style="padding-bottom:0"><p class="muted">Tareas, lista, tablero, comentarios e integrantes están siempre activos. Activa solo lo que tu equipo necesite: al desactivar un módulo sus datos se conservan y vuelven al reactivarlo.</p></div>
      <div>
        <?php foreach ($catalog as $key => [$name, $desc, $requires]): ?>
          <div class="module-row">
            <div class="info">
              <strong><?= e($name) ?></strong><div class="muted small"><?= e($desc) ?></div>
              <?php if ($requires): ?><div class="req">Requiere: <?= e(implode(', ', array_map(fn($r) => $catalog[$r][0], $requires))) ?> (se activa automáticamente)</div><?php endif; ?>
            </div>
            <label class="switch" title="<?= $modules[$key] ? 'Desactivar' : 'Activar' ?> <?= e($name) ?>">
              <span class="sr-only"><?= e($name) ?></span>
              <input type="checkbox" data-module-toggle="<?= e($key) ?>" data-project="<?= $pid ?>"<?= checked($modules[$key]) ?>>
              <span class="track"></span>
            </label>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
  </div>
</div>
