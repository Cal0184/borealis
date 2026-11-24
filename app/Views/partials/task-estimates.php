<?php
/** 1.14 · the five numbers of a task's time and the estimate workflow. @var array $t @var int $logged (minutes) */
use Borealis\Services\TaskEstimates as TE;
$es = TE::summary($t, $logged);
$tid = (int)$t['id'];
$canManage = TE::canManage($t);
$mine = TE::isResponsible($t);
$h = fn(?float $x) => $x === null ? '—' : TE::fmt($x);
$hist = TE::history($tid);
?>
<div class="estimate-box" data-estimates="<?= e($es['review']) ?>">
  <dl class="estimate-grid">
    <div><dt>Administración</dt><dd data-est-admin><?= e($h($es['admin'])) ?></dd></div>
    <div><dt>Trabajador</dt><dd data-est-worker><?= e($h($es['worker'])) ?><?php if ($es['review'] === 'pending'): ?> <span class="badge warn">por revisar</span><?php endif; ?></dd></div>
    <div><dt>Acordado</dt><dd data-est-agreed><strong><?= e($h($es['agreed'])) ?></strong></dd></div>
    <div><dt>Real</dt><dd data-est-real><?= e($h($es['real'])) ?></dd></div>
    <div><dt>Diferencia</dt><dd data-est-diff class="<?= $es['diff'] !== null && $es['diff'] > 0 ? 'dev-over' : '' ?>"><?= $es['diff'] === null ? '—' : e(($es['diff'] > 0 ? '+' : '') . TE::fmt($es['diff'])) ?></dd></div>
  </dl>
  <?php if ($mine && !$t['archived_project']): ?>
    <details class="small"<?= $es['worker'] === null && $es['agreed'] === null ? ' open' : '' ?>><summary><?= $es['worker'] === null ? 'Indicar cuánto tiempo necesito' : 'Cambiar mi estimación' ?></summary>
      <form class="row wrap mt-sm" data-api="/api/tasks/<?= $tid ?>/estimate" data-reset data-estimate-form>
        <div class="form-error" data-form-error></div>
        <label class="sr-only" for="te-h-<?= $tid ?>">Horas</label><input class="input sm" id="te-h-<?= $tid ?>" name="hours" type="number" min="0" max="9999" step="0.5" required placeholder="Horas" style="width:90px">
        <label class="sr-only" for="te-r-<?= $tid ?>">Motivo</label><input class="input sm grow" id="te-r-<?= $tid ?>" name="reason" maxlength="500" placeholder="<?= $es['agreed'] !== null ? 'Motivo (obligatorio si cambia lo acordado)' : 'Motivo (opcional)' ?>">
        <button class="btn btn--sm" type="submit">Guardar</button>
        <span class="error" data-error-for="hours"></span><span class="error" data-error-for="reason"></span>
      </form>
    </details>
  <?php endif; ?>
  <?php if ($canManage && !$t['archived_project'] && ($es['review'] === 'pending' || $es['worker'] !== null)): ?>
    <form class="row wrap mt-sm" data-api="/api/tasks/<?= $tid ?>/estimate/agree" data-estimate-agree>
      <div class="form-error" data-form-error></div>
      <label class="small" for="ta-h-<?= $tid ?>">Acordar</label><input class="input sm" id="ta-h-<?= $tid ?>" name="hours" type="number" min="0" max="9999" step="0.5" value="<?= e((string)($es['worker'] ?? $es['agreed'] ?? '')) ?>" style="width:90px">
      <input class="input sm grow" name="reason" maxlength="500" placeholder="Comentario (opcional)" aria-label="Comentario">
      <button class="btn btn--sm btn--primary" type="submit"><?= icon('check', 'sm') ?> Acordar</button>
    </form>
  <?php endif; ?>
  <?php if ($hist): ?>
    <details class="small" data-estimate-history><summary>Historial de estimaciones (<?= count($hist) ?>)</summary>
      <ul class="list"><?php foreach ($hist as $x): ?><li class="small" style="flex-direction:column;align-items:flex-start">
        <span><span class="badge"><?= e(TE::KINDS[$x['kind']]) ?></span> <?= e($h($x['old_hours'] !== null ? (float)$x['old_hours'] : null)) ?> → <strong><?= e($h($x['new_hours'] !== null ? (float)$x['new_hours'] : null)) ?></strong> · <span class="muted"><?= e($x['user_name'] ?? 'Sistema') ?> · <?= e(fmt_datetime($x['created_at'])) ?></span></span>
        <?php if ($x['reason']): ?><span class="muted"><?= e($x['reason']) ?></span><?php endif; ?>
      </li><?php endforeach; ?></ul>
    </details>
  <?php endif; ?>
</div>
