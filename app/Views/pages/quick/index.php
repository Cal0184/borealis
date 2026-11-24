<?php
use Borealis\Services\QuickWork;
use Borealis\Services\Tasks;
$sun = date('Y-m-d', strtotime($monday . ' +6 days'));
$prev = date('Y-m-d', strtotime($monday . ' -7 days'));
$next = date('Y-m-d', strtotime($monday . ' +7 days'));
$me = (int)\Borealis\Core\Auth::id();
$friday = date('Y-m-d', strtotime($monday . ' +4 days'));
$defaultDue = today() > $friday ? today() : max(today(), $monday);
$open = array_values(array_filter($rows, fn($t) => $t['status_category'] !== 'done'));
$done = array_values(array_filter($rows, fn($t) => $t['status_category'] === 'done'));
$hours = fn($t) => $t['estimate_hours'] !== null ? rtrim(rtrim(number_format((float)$t['estimate_hours'], 1, ',', ''), '0'), ',') . ' h' : '';
?>
<div class="page-head">
  <div><h1><?= icon('pin') ?> <?= e(QuickWork::NAME) ?></h1>
    <p class="sub">Urgencias, solicitudes, tareas administrativas y trabajo de la semana que no pertenece a un proyecto. Cuentan en la carga de cada persona.</p></div>
</div>

<section class="card mb">
  <form class="quick-form" data-api="/api/quick" data-reset data-keep-open data-toast-link="task" data-quick-form>
    <div class="form-error" data-form-error></div>
    <div class="quick-row">
      <div class="field grow"><label for="qw-title">¿Qué hay que hacer?</label><input class="input" id="qw-title" name="title" required maxlength="255" autocomplete="off" placeholder="Por ejemplo: preparar el informe para dirección" data-keep-focus><span class="error" data-error-for="title"></span></div>
      <?php if ($people): ?>
        <div class="field"><label for="qw-who">Para</label>
          <select class="select" id="qw-who" name="assignee_id" data-workload-select data-keep-value>
            <?php foreach ($people as $p): ?><option value="<?= (int)$p['id'] ?>"<?= selected($p['id'], $me) ?>><?= e($p['name']) ?><?= (int)$p['id'] === $me ? ' (yo)' : '' ?></option><?php endforeach; ?>
          </select><span class="hint" data-workload-hint></span><span class="error" data-error-for="assignee_id"></span></div>
      <?php endif; ?>
      <div class="field"><label for="qw-h">Horas</label><input class="input hours-input" id="qw-h" type="number" name="estimate_hours" min="0" max="9999" step="0.5" placeholder="—" inputmode="decimal" style="width:90px"><span class="error" data-error-for="estimate_hours"></span></div>
      <div class="field"><label for="qw-due">Para el</label><input class="input" id="qw-due" type="date" name="due_date" value="<?= e($defaultDue) ?>" data-keep-value><span class="error" data-error-for="due_date"></span></div>
      <div class="field"><label for="qw-type">Tipo</label><select class="select" id="qw-type" name="work_type" data-keep-value><?php foreach (Tasks::WORK_TYPES as $k => $v): ?><option value="<?= $k ?>"<?= selected($k, 'request') ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
      <div class="field quick-submit"><span class="label">&nbsp;</span><button class="btn btn--primary" type="submit"><?= icon('plus', 'sm') ?> Añadir</button></div>
    </div>
    <p class="hint">Se queda abierto para añadir varios seguidos. «Solicitud imprevista» y «Urgente» cuentan como trabajo fuera de la planificación en Análisis.</p>
  </form>
</section>

<div class="toolbar">
  <div class="btn-group">
    <a class="btn btn--sm" href="<?= e(url('/quick', ['week' => $prev] + ($mine ? ['mine' => 1] : []))) ?>" aria-label="Semana anterior"><?= icon('chevron-left', 'sm') ?></a>
    <a class="btn btn--sm" href="<?= e(url('/quick', $mine ? ['mine' => 1] : [])) ?>">Esta semana</a>
    <a class="btn btn--sm" href="<?= e(url('/quick', ['week' => $next] + ($mine ? ['mine' => 1] : []))) ?>" aria-label="Semana siguiente"><?= icon('chevron-right', 'sm') ?></a>
  </div>
  <h2 style="margin:0 8px"><?= e(fmt_date($monday)) ?> – <?= e(fmt_date($sun, true)) ?></h2>
  <span class="grow"></span>
  <nav class="segmented" aria-label="Quién"><a href="<?= e(url('/quick', ['week' => $monday])) ?>"<?= !$mine ? ' aria-current="page"' : '' ?>>Todos</a><a href="<?= e(url('/quick', ['week' => $monday, 'mine' => 1])) ?>"<?= $mine ? ' aria-current="page"' : '' ?>>Míos</a></nav>
</div>

<section class="card" id="quick-list" data-region>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Trabajo</th><th>Responsable</th><th class="hide-sm">Tipo</th><th>Para el</th><th class="hide-sm">Horas</th><th>Estado</th></tr></thead>
    <tbody>
      <?php foreach (array_merge($open, $done) as $t): $isDone = $t['status_category'] === 'done'; ?>
        <tr data-row-open="<?= e($t['task_key']) ?>" data-quick-row="<?= (int)$t['id'] ?>"<?= $isDone ? ' class="is-done"' : '' ?>>
          <td><span class="key"><?= e($t['task_key']) ?></span> <?= e($t['title']) ?><?php if ($t['unplanned']): ?> <span class="badge warn" title="Fuera de la planificación">Imprevisto</span><?php endif; ?></td>
          <td><?= e($t['assignee_name'] ?? '—') ?></td>
          <td class="hide-sm small"><?= e(Tasks::WORK_TYPES[$t['work_type'] ?? 'other'] ?? '') ?></td>
          <td class="small"><?= $t['due_date'] ? e(fmt_date($t['due_date'])) : '—' ?><?php if ($lt = late_tag($t)): ?> <?= $lt ?><?php endif; ?></td>
          <td class="hide-sm small"><?= e($hours($t)) ?></td>
          <td><span class="badge" style="--c:<?= e($t['status_color']) ?>"><span class="dot"></span><?= e($t['status_name']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="6" class="empty">Nada esta semana. Lo que añadas arriba aparece aquí y en Mi trabajo.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>
