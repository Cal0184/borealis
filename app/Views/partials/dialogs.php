<?php
use Borealis\Security\Policy;
use Borealis\Services\Projects;
use Borealis\Services\Teams;
use Borealis\Services\Workspaces;
$creatable = array_values(array_filter(Projects::list(), fn($p) => Policy::canProject($p, 'task.create')));
// 1.7.1: sprints that can still receive tasks, for «Nueva tarea» (filtered by the chosen project).
$openSprints = $creatable ? \Borealis\Core\Database::get()->all("SELECT id, project_id, name, state, start_date, end_date FROM sprints WHERE state <> 'completed' AND project_id IN (" . \Borealis\Core\Database::in(array_column($creatable, 'id')) . ") ORDER BY state = 'active' DESC, start_date IS NULL, start_date, id", array_map('intval', array_column($creatable, 'id'))) : [];
?>
<dialog class="modal" id="dlg-new-task" aria-labelledby="dlg-new-task-title">
  <form data-api="/api/projects/{project_id}/tasks" data-reset data-close data-toast-link="task">
    <div class="modal-head"><h2 id="dlg-new-task-title">Nueva tarea</h2><button type="button" class="btn btn--ghost btn--icon" data-close-dialog aria-label="Cerrar"><?= icon('x') ?></button></div>
    <div class="modal-body">
      <div class="form-error" data-form-error></div>
      <?php if (!$creatable): ?>
        <div class="empty"><?= icon('folder') ?><h3>Primero necesitas un proyecto</h3><p>Las tareas viven dentro de un proyecto donde puedas colaborar.</p></div>
      <?php else: ?>
        <div class="field"><label for="nt-title">Título</label><input class="input" id="nt-title" name="title" required maxlength="255" autocomplete="off" placeholder="¿Qué hay que hacer?"><span class="error" data-error-for="title"></span></div>
        <div class="field"><label for="nt-project">Proyecto</label>
          <select class="select" id="nt-project" name="project_id" required>
            <?php foreach ($creatable as $p): ?><option value="<?= (int)$p['id'] ?>"<?= selected($p['id'], $currentProjectId) ?>><?= e($p['name']) ?> (<?= e($p['project_key']) ?>)</option><?php endforeach; ?>
          </select>
        </div>
        <?php $byUser = [];
          foreach ($creatable as $p) foreach (Projects::assignableUsers($p) as $m) { $byUser[(int)$m['id']]['name'] = $m['name']; $byUser[(int)$m['id']]['p'][] = (int)$p['id']; }
          uasort($byUser, fn($a, $b) => strcasecmp($a['name'], $b['name'])); ?>
        <div class="form-row">
          <div class="field"><label for="nt-assignee">Responsable</label>
            <select class="select" id="nt-assignee" name="assignee_id" data-filter-by="#nt-project">
              <option value="">Sin asignar</option>
              <?php foreach ($byUser as $uid => $u): ?><option value="<?= $uid ?>" data-projects="<?= e(implode(',', $u['p'])) ?>"><?= e($u['name']) ?><?= $uid === (int)$s['user']['id'] ? ' (yo)' : '' ?></option><?php endforeach; ?>
            </select>
            <span class="error" data-error-for="assignee_id"></span>
          </div>
          <div class="field"><label for="nt-sprint">Sprint</label>
            <select class="select" id="nt-sprint" name="sprint_id" data-filter-by="#nt-project">
              <option value="">Sin sprint (backlog)</option>
              <?php foreach ($openSprints as $sp): ?><option value="<?= (int)$sp['id'] ?>" data-projects="<?= (int)$sp['project_id'] ?>"><?= e($sp['name']) ?><?= $sp['state'] === 'active' ? ' · en curso' : '' ?></option><?php endforeach; ?>
            </select>
            <span class="error" data-error-for="sprint_id"></span>
          </div>
        </div>
        <div class="form-row">
          <div class="field"><label for="nt-hours">Tiempo estimado</label>
            <span class="input-unit"><input class="input hours-input" type="number" min="0" max="9999" step="0.5" id="nt-hours" name="estimate_hours" placeholder="Opcional" inputmode="decimal"><span class="muted small">horas</span></span>
            <span class="hint">Opcional. Cuenta en las horas disponibles del sprint. <button class="link-btn" type="button" data-estimate-suggest data-project-from="#nt-project" data-for="#nt-hours">Consultar el historial</button></span>
            <span class="error" data-error-for="estimate_hours"></span>
            <div data-estimate-out></div>
          </div>
          <div class="field"><label for="nt-prio">Prioridad</label><select class="select" id="nt-prio" name="priority_id"><option value="">Sin prioridad</option><?php foreach (Workspaces::priorities() as $pr): ?><option value="<?= (int)$pr['id'] ?>"><?= e($pr['name']) ?></option><?php endforeach; ?></select></div>
        </div>
        <details class="more-options"><summary><?= icon('plus', 'sm') ?> Más opciones</summary>
          <div class="form-row">
            <div class="field"><label for="nt-due">Vencimiento</label><input class="input" type="date" id="nt-due" name="due_date"><span class="error" data-error-for="due_date"></span></div>
            <div class="field"><label for="nt-dt">Hora de entrega <span class="muted small">(opcional)</span></label><input class="input" type="time" id="nt-dt" name="due_time"><span class="error" data-error-for="due_time"></span></div>
          </div>
          <div class="field"><label for="nt-desc">Descripción</label><textarea class="textarea" id="nt-desc" name="description" rows="3"></textarea></div>
          <p class="hint">Solo aparecen como responsables quienes pueden trabajar en el proyecto elegido.</p>
        </details>
      <?php endif; ?>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn" data-close-dialog>Cancelar</button>
      <?php if ($creatable): ?><button type="submit" class="btn btn--primary">Crear tarea</button><?php endif; ?>
    </div>
  </form>
</dialog>

<?php if ($s['can_create_project']): ?>
<dialog class="modal" id="dlg-new-project" aria-labelledby="dlg-new-project-title">
  <form data-api="/api/projects" data-reset data-close data-toast-link="project">
    <div class="modal-head"><h2 id="dlg-new-project-title">Nuevo proyecto</h2><button type="button" class="btn btn--ghost btn--icon" data-close-dialog aria-label="Cerrar"><?= icon('x') ?></button></div>
    <div class="modal-body">
      <div class="form-error" data-form-error></div>
      <div class="form-row key-row">
        <div class="field grow"><label for="np-name">Nombre del proyecto</label><input class="input" id="np-name" name="name" required maxlength="160" autocomplete="off" data-key-source="#np-key"><span class="error" data-error-for="name"></span></div>
        <div class="field key-field"><label for="np-key">Siglas</label><input class="input key-input" id="np-key" name="project_key" maxlength="6" placeholder="WEB" autocomplete="off" pattern="[A-Za-z][A-Za-z0-9]{1,5}" title="De 2 a 6 letras o números, empezando por letra"><span class="error" data-error-for="project_key"></span></div>
      </div>
      <p class="hint" style="margin-top:-6px">Las siglas encabezan las tareas: <strong data-key-preview>WEB</strong>-1, <span data-key-preview-2>WEB</span>-2… Se proponen solas a partir del nombre y puedes cambiarlas.</p>
      <details class="more-options"><summary><?= icon('plus', 'sm') ?> Más opciones</summary>
        <div class="field"><label for="np-desc">Descripción</label><textarea class="textarea" id="np-desc" name="description" rows="3"></textarea></div>
        <div class="form-row">
          <div class="field"><label for="np-team">Equipo</label><select class="select" id="np-team" name="team_id"><option value="">Sin equipo</option><?php foreach (Teams::list() as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
        </div>
        <p class="hint mb-sm"><?= icon('lock', 'sm') ?> Lo ven solo sus integrantes, los equipos que participan y la administración del espacio.</p>
        <div class="form-row">
          <div class="field"><label for="np-start">Inicio</label><input class="input" type="date" id="np-start" name="start_date"></div>
          <div class="field"><label for="np-due">Entrega</label><input class="input" type="date" id="np-due" name="due_date"><span class="error" data-error-for="due_date"></span></div>
          <div class="field"><label for="np-state">Estado</label><select class="select" id="np-state" name="state"><option value="active">Activo</option><option value="planned">Planificado (aún no empieza)</option></select></div>
        </div>
      </details>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn" data-close-dialog>Cancelar</button>
      <button type="submit" class="btn btn--primary">Crear proyecto</button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<dialog class="modal" id="dlg-confirm" aria-labelledby="dlg-confirm-title">
  <div class="modal-head"><h2 id="dlg-confirm-title">Confirmar</h2></div>
  <div class="modal-body"><p data-confirm-text></p></div>
  <div class="modal-foot">
    <button type="button" class="btn" data-confirm-cancel>Cancelar</button>
    <button type="button" class="btn btn--danger-solid" data-confirm-ok>Confirmar</button>
  </div>
</dialog>

<dialog class="modal" id="dlg-summary" aria-labelledby="dlg-summary-title">
  <div class="hero compact" style="margin:0;border-radius:var(--radius-lg) var(--radius-lg) 0 0"><h2 id="dlg-summary-title" style="font-size:19px">Expedición completada</h2><p data-summary-text></p></div>
  <div class="modal-body"><div class="progress lg"><span style="--p:100%"></span></div><p class="muted mt" data-summary-detail></p></div>
  <div class="modal-foot">
    <button type="button" class="btn" data-close-dialog>Seguir trabajando</button>
    <button type="button" class="btn btn--aurora" data-summary-complete hidden><?= icon('flag-check') ?> Marcar proyecto como completado</button>
  </div>
</dialog>

<dialog class="modal" id="dlg-wrapup" aria-labelledby="dlg-wrapup-title">
  <form data-api="/api/tasks/{task_id}/wrap-up" data-close data-reset>
    <input type="hidden" name="task_id" value="">
    <div class="modal-head"><h2 id="dlg-wrapup-title"><?= icon('check-circle') ?> Tarea finalizada</h2><button type="button" class="btn btn--ghost btn--icon" data-close-dialog aria-label="Cerrar"><?= icon('x') ?></button></div>
    <div class="modal-body">
      <p class="muted" data-wrapup-text></p>
      <div class="form-error" data-form-error></div>
      <div class="field"><label for="wu-c">Comentario de cierre (opcional)</label><textarea class="textarea" id="wu-c" name="comment" rows="3" maxlength="10000" placeholder="Qué se hizo, qué quedó pendiente, dónde está el resultado…"></textarea><span class="error" data-error-for="comment"></span></div>
      <div class="field"><label for="wu-h">Horas que te tomó (opcional)</label><input class="input" id="wu-h" name="hours" placeholder="Por ejemplo 2.5 o 2:30" autocomplete="off" style="max-width:220px"><span class="hint" data-wrapup-logged></span><span class="error" data-error-for="hours"></span></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-close-dialog>Omitir</button><button type="submit" class="btn btn--primary">Guardar</button></div>
  </form>
</dialog>

<dialog class="modal" id="dlg-share" aria-labelledby="dlg-share-title">
  <div class="modal-head"><h2 id="dlg-share-title"><?= icon('mail') ?> Mensaje listo para enviar</h2><button type="button" class="btn btn--ghost btn--icon" data-close-dialog aria-label="Cerrar"><?= icon('x') ?></button></div>
  <div class="modal-body"><div class="notice" data-share-slot></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close-dialog>Cerrar</button></div>
</dialog>
