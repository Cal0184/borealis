<?php
/**
 * 1.5 · «Capacidad agotada — Trabajo pendiente».
 * $alerts: rows from CapacityAlerts::forView(). $link: show the «Ver historial» link (default true).
 * Always renders its (possibly empty) region so the panel refreshes in place after a decision.
 */
use Borealis\Services\Capacity;
use Borealis\Services\TimeEntries;

$link = $link ?? true;
$h = fn(int $m): string => TimeEntries::fmt($m);
?>
<div id="cap-alerts-region" data-region>
<?php if (!empty($alerts)): ?>
<section class="card cap-alerts mb" aria-labelledby="cap-alerts-title" data-capacity-alerts>
  <div class="card-head">
    <h2 id="cap-alerts-title"><?= icon('alert') ?> Capacidad agotada — Trabajo pendiente <span class="badge danger"><?= count($alerts) ?></span></h2>
    <?php if ($link): ?><a class="small" href="<?= e(url('/capacity')) ?>">Ver alertas e historial</a><?php endif; ?>
  </div>
  <ul class="list cap-alert-list">
  <?php foreach ($alerts as $a): $m = $a['measure']; $id = (int)$a['id']; ?>
    <li class="cap-alert" id="cap-alert-<?= $id ?>">
      <div class="cap-alert-main">
        <p class="cap-alert-text"><strong><?= e($a['user_name']) ?></strong> ha utilizado <b><?= e($h($m['logged_minutes'])) ?></b> de sus <b><?= e($h((int)$m['capacity_minutes'])) ?></b> disponibles<?= $a['current_week'] ? ' esta semana' : ' la semana del ' . e(fmt_date($a['week_start'], true)) ?>, pero todavía tiene <?= count($m['tasks']) === 1 ? 'una tarea pendiente' : count($m['tasks']) . ' tareas pendientes' ?>.</p>
        <p class="muted small cap-alert-facts">
          Horas registradas (consumidas): <?= e($h($m['logged_minutes'])) ?> ·
          Capacidad: <?= e($h((int)$m['weekly_minutes'])) ?> semanales<?= $m['extra_minutes'] ? ' + ' . e($h($m['extra_minutes'])) . ' adicionales autorizadas' : '' ?> ·
          Trabajo pendiente estimado: <?= e(Capacity::num((float)$m['pending_hours'])) ?> h<?= $m['unestimated'] ? ' · ' . (int)$m['unestimated'] . ' sin estimar' : '' ?>
          <?php if (!$a['current_week']): ?> · <span class="badge warn">Semana anterior</span><?php endif; ?>
        </p>
        <ul class="cap-tasks">
          <?php foreach ($m['tasks'] as $t): ?>
            <li>
              <span class="key"><?= e($t['task_key']) ?></span>
              <button class="task-title truncate" data-open-task="<?= e($t['task_key']) ?>"><?= e($t['title']) ?></button>
              <span class="muted small"><?= e($t['project_name']) ?><?= $t['sprint_name'] ? ' · ' . e($t['sprint_name']) : '' ?><?= $t['due_date'] ? ' · vence ' . e(fmt_date($t['due_date'])) : '' ?></span>
              <span class="small cap-share"><?= $t['share'] === null ? '<span class="badge">sin estimar</span>' : e(Capacity::num((float)$t['share'])) . ' h estimadas' ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if ($m['overdue']): ?>
          <p class="muted small cap-overdue"><?= icon('clock', 'sm') ?> Además tiene <?= count($m['overdue']) ?> <?= count($m['overdue']) === 1 ? 'tarea vencida' : 'tareas vencidas' ?> de semanas anteriores (no cuentan para esta alerta):
            <?= implode(', ', array_map(fn($t) => '<button class="link-btn" data-open-task="' . e($t['task_key']) . '">' . e($t['task_key']) . '</button>', array_slice($m['overdue'], 0, 8))) ?><?= count($m['overdue']) > 8 ? '…' : '' ?></p>
        <?php endif; ?>
      </div>
      <?php if ($a['can_act']): ?>
        <div class="cap-alert-actions"><button class="btn btn--primary btn--sm" data-dialog="dlg-cap-<?= $id ?>">Resolver</button></div>
        <dialog class="modal lg" id="dlg-cap-<?= $id ?>" aria-labelledby="dlg-cap-<?= $id ?>-t">
          <div class="modal-head"><h2 id="dlg-cap-<?= $id ?>-t">Resolver la alerta de <?= e($a['user_name']) ?></h2><button type="button" class="btn btn--ghost btn--icon" data-close-dialog aria-label="Cerrar"><?= icon('x') ?></button></div>
          <div class="modal-body cap-resolve">
            <p class="muted small">Ninguna opción completa tareas ni añade horas por sí sola. Cada decisión queda registrada con tu nombre.</p>

            <details>
              <summary><?= icon('calendar', 'sm') ?> Reprogramar a otro periodo o sprint</summary>
              <?php foreach ($m['tasks'] as $t): if (empty($t['manageable'])) continue; ?>
                <form data-api="/api/capacity-alerts/<?= $id ?>/resolve" data-close class="cap-form">
                  <input type="hidden" name="action" value="reschedule"><input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
                  <div class="form-error" data-form-error></div>
                  <strong class="small"><?= e($t['task_key']) ?> · <?= e($t['title']) ?></strong>
                  <div class="cap-row">
                    <?php if (!empty($t['sprints'])): ?>
                      <label class="small">Sprint <select class="input" name="sprint_id"><option value="">— Sin cambiar —</option><?php foreach ($t['sprints'] as $sp): ?><option value="<?= (int)$sp['id'] ?>"><?= e($sp['name']) ?><?= $sp['start_date'] ? ' (' . e(fmt_date($sp['start_date'])) . ')' : '' ?></option><?php endforeach; ?></select></label>
                      <span class="muted small">o</span>
                    <?php endif; ?>
                    <label class="small">Nueva fecha <input class="input" type="date" name="due_date" min="<?= e(date('Y-m-d', strtotime($m['week_end'] . ' +1 day'))) ?>"></label>
                    <button class="btn btn--sm" type="submit">Reprogramar</button>
                  </div>
                  <span class="error" data-error-for="due_date"></span><span class="error" data-error-for="sprint_id"></span>
                </form>
              <?php endforeach; ?>
            </details>

            <details>
              <summary><?= icon('users', 'sm') ?> Reasignar a otra persona con horas disponibles</summary>
              <?php foreach ($m['tasks'] as $t): if (empty($t['manageable'])) continue; ?>
                <form data-api="/api/capacity-alerts/<?= $id ?>/resolve" data-close class="cap-form">
                  <input type="hidden" name="action" value="reassign"><input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
                  <div class="form-error" data-form-error></div>
                  <strong class="small"><?= e($t['task_key']) ?> · <?= e($t['title']) ?></strong>
                  <?php if (!empty($t['candidates'])): ?>
                    <div class="cap-row">
                      <select class="input" name="user_id" required aria-label="Persona"><option value="">Elige a quién</option><?php foreach ($t['candidates'] as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?> — <?= $c['free_minutes'] === null ? 'sin límite definido' : e($h((int)$c['free_minutes'])) . ' libres' ?></option><?php endforeach; ?></select>
                      <button class="btn btn--sm" type="submit">Reasignar</button>
                    </div>
                  <?php else: ?><p class="muted small">Nadie más en el proyecto tiene horas disponibles esta semana.</p><?php endif; ?>
                  <span class="error" data-error-for="user_id"></span>
                </form>
              <?php endforeach; ?>
            </details>

            <details>
              <summary><?= icon('target', 'sm') ?> Revisar la estimación</summary>
              <?php foreach ($m['tasks'] as $t): if (empty($t['manageable'])) continue; ?>
                <form data-api="/api/capacity-alerts/<?= $id ?>/resolve" data-close class="cap-form">
                  <input type="hidden" name="action" value="estimate"><input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
                  <div class="form-error" data-form-error></div>
                  <strong class="small"><?= e($t['task_key']) ?> · <?= e($t['title']) ?> <span class="muted">(ahora: <?= $t['estimate_hours'] !== null ? e(Capacity::num((float)$t['estimate_hours'])) . ' h' : 'sin estimar' ?>)</span></strong>
                  <div class="cap-row">
                    <label class="small">Horas <input class="input" type="number" name="estimate_hours" min="0" max="999" step="0.25" required value="<?= $t['estimate_hours'] !== null ? e((string)(float)$t['estimate_hours']) : '' ?>"></label>
                    <input class="input" name="note" maxlength="500" placeholder="Motivo (opcional)" aria-label="Motivo">
                    <button class="btn btn--sm" type="submit">Guardar estimación</button>
                  </div>
                  <span class="error" data-error-for="estimate_hours"></span>
                </form>
              <?php endforeach; ?>
            </details>

            <details>
              <summary><?= icon('clock', 'sm') ?> Autorizar horas adicionales</summary>
              <form data-api="/api/capacity-alerts/<?= $id ?>/resolve" data-close class="cap-form">
                <input type="hidden" name="action" value="extra">
                <div class="form-error" data-form-error></div>
                <div class="cap-row">
                  <label class="small">Horas adicionales para esa semana <input class="input" name="hours" required placeholder="Ej.: 3 o 1:30" inputmode="decimal"></label>
                  <input class="input" name="note" maxlength="500" placeholder="Motivo (opcional)" aria-label="Motivo">
                  <button class="btn btn--sm" type="submit">Autorizar</button>
                </div>
                <span class="hint">Quedará registrado quién las autorizó y cuántas. Solo amplían la capacidad de esa semana.</span>
                <span class="error" data-error-for="hours"></span>
              </form>
            </details>

            <details>
              <summary><?= icon('flag', 'sm') ?> Mantener pendiente con justificación</summary>
              <form data-api="/api/capacity-alerts/<?= $id ?>/resolve" data-close class="cap-form">
                <input type="hidden" name="action" value="keep">
                <div class="form-error" data-form-error></div>
                <textarea class="input" name="note" required minlength="5" maxlength="500" rows="2" placeholder="Por qué se mantiene así (por ejemplo, se termina el lunes sin horas extra)" aria-label="Justificación"></textarea>
                <span class="error" data-error-for="note"></span>
                <div class="cap-row"><button class="btn btn--sm" type="submit">Mantener pendiente</button></div>
              </form>
            </details>
          </div>
          <div class="modal-foot"><button type="button" class="btn" data-close-dialog>Cerrar</button></div>
        </dialog>
      <?php endif; ?>
    </li>
  <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
</div>
