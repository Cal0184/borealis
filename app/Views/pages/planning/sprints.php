<?= partial('project-header', compact('project', 'modules', 'tab', 'can')) ?>
<?= partial('capacity-alerts', ['alerts' => \Borealis\Services\CapacityAlerts::forView((int)$project['id'])]) ?>
<?php
use Borealis\Services\Sprints;
$pid = (int)$project['id'];
$manage = $can('sprint.manage');
$planned = array_values(array_filter($sprints, fn($s) => $s['state'] === 'planned'));
$pts = fn($v) => rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.');
?>
<div class="split">
  <div class="stack">
    <?php if ($selected): $s = $selected; $sid = (int)$s['id']; $pc = pct((int)$s['done_count'], (int)$s['task_count']); ?>
      <section class="card">
        <div class="card-head">
          <h2><?= icon('zap') ?> <?= e($s['name']) ?> <span class="badge<?= $s['state'] === 'active' ? ' success' : ($s['state'] === 'completed' ? ' info' : '') ?>"><?= e(Sprints::STATES[$s['state']]) ?></span></h2>
          <div class="row">
            <?php if ($manage && $s['state'] === 'planned'): ?><button class="btn btn--aurora btn--sm" data-call="/api/sprints/<?= $sid ?>/start" data-confirm="¿Iniciar «<?= e($s['name']) ?>» con <?= (int)$s['task_count'] ?> tareas?" data-confirm-label="Iniciar sprint" data-confirm-style="primary"><?= icon('play', 'sm') ?> Iniciar</button><?php endif; ?>
            <?php if ($manage && $s['state'] === 'active'): ?><button class="btn btn--primary btn--sm" data-dialog="dlg-complete-sprint"><?= icon('flag-check', 'sm') ?> Finalizar sprint</button><?php endif; ?>
            <?php if ($manage && $s['state'] !== 'completed'): ?><button class="btn btn--sm" data-dialog="dlg-edit-sprint"><?= icon('edit', 'sm') ?> Editar</button><?php endif; ?>
          </div>
        </div>
        <div class="card-body">
          <?php if ($s['goal']): ?><p><strong>Objetivo:</strong> <?= e($s['goal']) ?></p><?php endif; ?>
          <div class="row wrap muted small mb"><?= icon('calendar', 'sm') ?> <?= $s['start_date'] ? e(fmt_date($s['start_date'], true)) . ' – ' . e(fmt_date($s['end_date'], true)) : 'Fechas por definir' ?></div>
          <?php if ($s['state'] === 'active' && $s['end_date'] && $s['end_date'] < today()): ?><p class="sprint-warn small" role="status"><?= icon('alert', 'sm') ?> Llegó a su fecha de fin (<?= e(fmt_date($s['end_date'], true)) ?>) y sigue abierto. No se cierra solo: <?= $manage ? 'revisa las tareas y ciérralo con «Finalizar sprint» cuando esté listo.' : 'quien coordina el sprint lo revisará y lo cerrará.' ?></p><?php endif; ?>
          <div class="progress-label"><span><?= (int)$s['done_count'] ?> de <?= (int)$s['task_count'] ?> tareas<?= $modules['estimates'] ? ' · ' . $pts($s['done_points']) . ' de ' . $pts($s['points']) . ' pts' : '' ?></span><span><?= $pc ?>%</span></div>
          <div class="progress lg"><span style="--p:<?= $pc ?>%"></span></div>
          <div class="row wrap sprint-facts">
            <span class="badge success"><?= (int)$s['done_count'] ?> completadas</span>
            <span class="badge"><?= max(0, (int)$s['task_count'] - (int)$s['done_count']) ?> pendientes</span>
            <?php $who = $control ? array_values(array_filter($control['people'], fn($x) => $x['tasks'] > 0)) : []; if ($who): ?>
              <span class="avatar-stack" title="Integrantes con tareas en el sprint"><?php foreach (array_slice($who, 0, 6) as $w): ?><?= avatar($w, 'sm') ?><?php endforeach; ?><?php if (count($who) > 6): ?><span class="avatar sm avatar--more">+<?= count($who) - 6 ?></span><?php endif; ?></span>
              <span class="muted small"><?= count($who) ?> <?= count($who) === 1 ? 'integrante' : 'integrantes' ?></span>
            <?php endif; ?>
            <a class="btn btn--sm push" href="<?= e(url('/projects/' . $pid . '/board', ['sprint_id' => $sid])) ?>"><?= icon('board', 'sm') ?> Ver en el tablero</a>
          </div>
          <?php if ($s['state'] === 'completed'): ?>
            <div class="grid grid-3 mt">
              <div class="stat card"><span class="label">Comprometidas</span><span class="value"><?= (int)$s['committed_count'] ?></span></div>
              <div class="stat card"><span class="label">Completadas</span><span class="value"><?= (int)$s['completed_count'] ?></span></div>
              <div class="stat card"><span class="label">Puntos completados</span><span class="value"><?= $pts($s['completed_points']) ?>/<?= $pts($s['committed_points']) ?></span></div>
            </div>
            <?php if ($s['result_notes']): ?><div class="rich mt"><strong>Resultados:</strong><?= rich_text($s['result_notes']) ?></div><?php endif; ?>
          <?php endif; ?>
        </div>
        <?php if ($manage && $s['state'] !== 'completed' && $planning): $pl = $planning; ?>
        <div class="sprint-plan" id="sprint-plan" data-region>
          <h3 class="sprint-plan-title"><?= icon('list', 'sm') ?> Planificación <span class="muted small">· se completa poco a poco, nada es obligatorio</span></h3>
          <ul class="plan-checks">
            <li class="<?= $pl['goal'] && $pl['dates'] ? 'ok' : '' ?>"><?= icon($pl['goal'] && $pl['dates'] ? 'check-circle' : 'circle', 'sm') ?> Objetivo y fechas<?= !$pl['dates'] ? ' <span class="muted">· fechas por definir</span>' : '' ?><?= !$pl['goal'] ? ' <span class="muted">· sin objetivo</span>' : '' ?></li>
            <li class="<?= $pl['tasks'] ? 'ok' : '' ?>"><?= icon($pl['tasks'] ? 'check-circle' : 'circle', 'sm') ?> <?= $pl['tasks'] ?> <?= $pl['tasks'] === 1 ? 'tarea' : 'tareas' ?></li>
            <li class="<?= $pl['tasks'] && !$pl['unassigned'] ? 'ok' : '' ?>"><?= icon($pl['tasks'] && !$pl['unassigned'] ? 'check-circle' : 'circle', 'sm') ?> Responsables<?= $pl['unassigned'] ? ' <span class="muted">· ' . $pl['unassigned'] . ' sin asignar</span>' : '' ?></li>
            <li class="<?= $pl['tasks'] && !$pl['unestimated'] ? 'ok' : '' ?>"><?= icon($pl['tasks'] && !$pl['unestimated'] ? 'check-circle' : 'circle', 'sm') ?> Tiempo estimado<?= $pl['unestimated'] ? ' <span class="muted">· ' . $pl['unestimated'] . ' sin estimar</span>' : '' ?><?= $pl['hours'] ? ' <span class="muted">· ' . e($pts($pl['hours'])) . ' h' . ($control && $control['capacity'] !== null ? ' de ' . e($pts($control['capacity'])) . ' h disponibles' : '') . '</span>' : '' ?></li>
            <li class="<?= $pl['tasks'] && !$pl['unprioritized'] ? 'ok' : '' ?>"><?= icon($pl['tasks'] && !$pl['unprioritized'] ? 'check-circle' : 'circle', 'sm') ?> Prioridades<?= $pl['unprioritized'] ? ' <span class="muted">· ' . $pl['unprioritized'] . ' sin prioridad</span>' : '' ?></li>
          </ul>
          <form class="ptask-add plan-add" data-api="/api/projects/<?= $pid ?>/tasks" data-reset data-keep-open>
            <input type="hidden" name="sprint_id" value="<?= $sid ?>">
            <label class="sr-only" for="sp-add-<?= $sid ?>">Nueva tarea del sprint</label>
            <input class="input" id="sp-add-<?= $sid ?>" name="title" required maxlength="255" placeholder="Añadir una tarea a este sprint" autocomplete="off">
            <label class="sr-only" for="sp-add-h-<?= $sid ?>">Horas estimadas</label>
            <input class="input hours-input" id="sp-add-h-<?= $sid ?>" name="estimate_hours" type="number" min="0" step="0.5" placeholder="h" style="width:80px">
            <button class="btn btn--primary" type="submit"><?= icon('plus', 'sm') ?> Añadir</button>
          </form>
          <p class="small muted">También puedes traer tareas existentes desde el <a href="<?= e(url('/projects/' . $pid . '/backlog')) ?>">backlog</a> y reordenarlas allí arrastrándolas.</p>
        </div>
        <div class="table-wrap"><table class="table plan-table">
          <thead><tr><th>Tarea</th><th>Responsable</th><th>Tiempo estimado</th><th>Prioridad</th><th class="hide-sm">Estado</th></tr></thead>
          <tbody>
          <?php foreach ($sprintTasks as $t): $tid = (int)$t['id']; ?>
            <tr>
              <td style="min-width:200px"><span class="key"><?= e($t['task_key']) ?></span> <button class="task-title" data-open-task="<?= e($t['task_key']) ?>"><?= e($t['title']) ?></button></td>
              <td><label class="sr-only" for="pl-as-<?= $tid ?>">Responsable de <?= e($t['task_key']) ?></label>
                <select class="select sm" id="pl-as-<?= $tid ?>" name="assignee_id" data-patch="/api/tasks/<?= $tid ?>"><option value="">Sin asignar</option><?php foreach ($assignable as $m): ?><option value="<?= (int)$m['id'] ?>"<?= selected($m['id'], $t['assignee_id']) ?>><?= e($m['name']) ?></option><?php endforeach; ?></select>
                <?php if (!empty($t['co_assignees']) || !empty($t['team_name'])): ?><span class="muted small">+ <?= e(implode(', ', array_filter(array_merge(array_column($t['co_assignees'] ?? [], 'name'), [$t['team_name'] ?? null])))) ?></span><?php endif; ?></td>
              <td><span class="input-unit"><label class="sr-only" for="pl-h-<?= $tid ?>">Horas estimadas de <?= e($t['task_key']) ?></label><input class="input sm hours-input" type="number" min="0" step="0.5" id="pl-h-<?= $tid ?>" name="estimate_hours" value="<?= e($t['estimate_hours'] !== null ? $pts($t['estimate_hours']) : '') ?>" placeholder="—" data-patch="/api/tasks/<?= $tid ?>" style="width:80px"><span class="muted small">h</span></span></td>
              <td><label class="sr-only" for="pl-pr-<?= $tid ?>">Prioridad de <?= e($t['task_key']) ?></label>
                <select class="select sm" id="pl-pr-<?= $tid ?>" name="priority_id" data-patch="/api/tasks/<?= $tid ?>"><option value="">Sin prioridad</option><?php foreach ($priorities as $pr): ?><option value="<?= (int)$pr['id'] ?>"<?= selected($pr['id'], $t['priority_id']) ?>><?= e($pr['name']) ?></option><?php endforeach; ?></select></td>
              <td class="hide-sm"><span class="badge"><span class="dot" style="--c:<?= e($t['status_color']) ?>"></span><?= e($t['status_name']) ?></span></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$sprintTasks): ?><tr><td colspan="5" class="empty small">Sin tareas todavía. Añade la primera arriba o tráela desde el backlog.</td></tr><?php endif; ?>
          </tbody>
        </table></div>
        <?php else: ?>
        <ul class="list" style="border-top:1px solid var(--border)">
          <?php foreach ($sprintTasks as $t): ?>
            <li><span class="key"><?= e($t['task_key']) ?></span><button class="task-title truncate" style="flex:1" data-open-task="<?= e($t['task_key']) ?>"><?= e($t['title']) ?></button>
              <span class="badge"><span class="dot" style="--c:<?= e($t['status_color']) ?>"></span><?= e($t['status_name']) ?></span>
              <?php if ($modules['estimates']): ?><span class="badge"><?= $t['estimate'] !== null ? $pts($t['estimate']) : '–' ?></span><?php endif; ?>
              <?= avatar($t['assignee_id'] ? ['id' => $t['assignee_id'], 'name' => $t['assignee_name'], 'avatar_color' => $t['assignee_color']] : null, 'sm') ?></li>
          <?php endforeach; ?>
          <?php if (!$sprintTasks): ?><li class="empty">Sin tareas. Añádelas desde el <a href="<?= e(url('/projects/' . $pid . '/backlog')) ?>">backlog</a>.</li><?php endif; ?>
        </ul>
        <?php endif; ?>
      </section>
      <?php if ($control): $c = $control; $u = ['hours' => 'h', 'points' => 'pts', 'tasks' => 'tareas'][$c['unit']]; ?>
      <section class="card" id="sprint-control">
        <div class="card-head"><h2><?= icon('target') ?> Control del sprint</h2><?php if ($manage && $s['state'] !== 'completed'): ?><button class="btn btn--sm" data-dialog="dlg-edit-sprint"><?= icon('edit', 'sm') ?> Capacidad</button><?php endif; ?></div>
        <div class="card-body">
          <div class="grid grid-4 stats">
            <div class="stat card"><span class="label">Capacidad</span><span class="value"><?= $c['capacity'] !== null ? $pts($c['capacity']) . ' h' : '—' ?></span><span class="muted small"><?= $c['override'] ? 'fijada a mano' : (array_filter($c['people'], fn($x) => $x['set']) ? 'suma de las horas disponibles de cada persona' : ($c['weeks'] !== null ? 'horas semanales × ' . $pts($c['weeks']) . ' semanas' : 'faltan fechas')) ?></span></div>
            <div class="stat card<?= $c['capacity'] !== null && $c['planned'] > $c['capacity'] ? ' is-over' : '' ?>"><span class="label">Planificado</span><span class="value"><?= $pts($c['planned']) ?> h</span><span class="muted small">horas estimadas de las tareas</span></div>
            <div class="stat card"><span class="label">Registrado</span><span class="value"><?= $pts($c['logged']) ?> h</span><span class="muted small">en tareas del sprint</span></div>
            <div class="stat card"><span class="label">Pendiente</span><span class="value"><?php $known = array_values(array_filter($c['series'], fn($x) => $x['remaining'] !== null)); echo $pts($known ? $known[count($known) - 1]['remaining'] : $c['total']); ?> <?= $u ?></span><span class="muted small">de <?= $pts($c['total']) ?> <?= $u ?></span></div>
          </div>
          <?php foreach ($c['alerts'] as [$lvl, $msg]): ?><div class="alert alert-<?= $lvl ?>"><?= icon($lvl === 'danger' ? 'alert' : ($lvl === 'warn' ? 'alert' : 'circle')) ?> <?= e($msg) ?></div><?php endforeach; ?>
          <?php if (count($c['series']) > 1 && $c['total'] > 0):
            $W = 640; $H = 210; $L = 44; $R = 12; $T = 12; $B = 30; $n = count($c['series']) - 1; $max = $c['total'];
            $x = fn($i) => $L + ($W - $L - $R) * $i / max(1, $n); $y = fn($v) => $T + ($H - $T - $B) * (1 - $v / $max);
            $pts2 = []; foreach ($c['series'] as $i => $pt) if ($pt['remaining'] !== null) $pts2[] = round($x($i), 1) . ',' . round($y($pt['remaining']), 1);
            $todayIdx = array_search(today(), array_column($c['series'], 'day'), true); ?>
            <figure class="burndown">
              <svg viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="Gráfico de avance: <?= e($pts($c['total'])) ?> <?= $u ?> al inicio; línea ideal hasta cero al final del sprint">
                <?php foreach ([0, .5, 1] as $g): $gy = $y($max * $g); ?><line x1="<?= $L ?>" x2="<?= $W - $R ?>" y1="<?= $gy ?>" y2="<?= $gy ?>" class="grid-line"/><text x="<?= $L - 6 ?>" y="<?= $gy + 4 ?>" class="axis" text-anchor="end"><?= e($pts($max * $g)) ?></text><?php endforeach; ?>
                <line x1="<?= $x(0) ?>" y1="<?= $y($max) ?>" x2="<?= $x($n) ?>" y2="<?= $y(0) ?>" class="ideal"/>
                <?php if ($todayIdx !== false): ?><line x1="<?= $x($todayIdx) ?>" x2="<?= $x($todayIdx) ?>" y1="<?= $T ?>" y2="<?= $H - $B ?>" class="today"/><text x="<?= $x($todayIdx) ?>" y="<?= $H - 6 ?>" class="axis" text-anchor="middle">hoy</text><?php endif; ?>
                <?php if ($pts2): ?><polyline points="<?= implode(' ', $pts2) ?>" class="actual"/><?php $lp = explode(',', end($pts2)); ?><circle cx="<?= $lp[0] ?>" cy="<?= $lp[1] ?>" r="4" class="actual-dot"/><?php endif; ?>
                <text x="<?= $x(0) ?>" y="<?= $H - 6 ?>" class="axis"><?= e(fmt_date($c['series'][0]['day'])) ?></text>
                <text x="<?= $x($n) ?>" y="<?= $H - 6 ?>" class="axis" text-anchor="end"><?= e(fmt_date($c['series'][$n]['day'])) ?></text>
              </svg>
              <figcaption class="muted small"><span class="key-swatch actual"></span> Trabajo pendiente (<?= $u ?>) <span class="key-swatch ideal"></span> Ritmo ideal</figcaption>
            </figure>
          <?php endif; ?>
          <?php if ($c['people']): $capEdit = $manage && $s['state'] !== 'completed'; ?>
            <h3 class="cap-title" id="cap-title">Horas disponibles por persona</h3>
            <div class="table-wrap"><table class="table cap-table" aria-labelledby="cap-title">
              <thead><tr><th>Persona</th><th>Disponibles</th><th>Carga</th><th>Registrado</th><th>Abiertas</th></tr></thead>
              <tbody><?php foreach ($c['people'] as $pp): $uid = (int)$pp['id']; $auto = $pp['weekly'] !== null && $c['weeks'] !== null ? $pts(round($pp['weekly'] * $c['weeks'], 1)) : null; ?>
                <tr<?= $pp['over'] ? ' class="is-over"' : '' ?>><td><span class="person"><?= avatar($pp, 'sm') ?> <?= e($pp['name']) ?></span></td>
                  <td><?php if ($capEdit): ?><input type="hidden" id="cap-u-<?= $uid ?>" value="<?= $uid ?>"><label class="sr-only" for="cap-h-<?= $uid ?>">Horas disponibles de <?= e($pp['name']) ?></label><span class="ts-inline"><input class="input sm ts-num cap-input" type="number" min="0" max="999" step="0.5" id="cap-h-<?= $uid ?>" name="hours" value="<?= $pp['set'] ? e($pts((float)$pp['capacity'])) : '' ?>" placeholder="<?= $auto !== null ? e($auto) : '—' ?>" data-patch="/api/sprints/<?= (int)$s['id'] ?>/capacity" data-patch-with="user_id:#cap-u-<?= $uid ?>"><span class="muted small">h</span></span>
                    <?php else: ?><?= $pp['capacity'] !== null ? $pts((float)$pp['capacity']) . ' h' : '<span class="muted">sin límite</span>' ?><?php endif; ?>
                    <span class="muted small cap-src"><?= $pp['set'] ? (($pp['cap_source'] ?? '') === 'history' ? 'las que tenía este sprint (no cambian con las horas semanales)' : 'fijadas para este sprint') : ($auto !== null ? $pts($pp['weekly']) . ' h/sem × ' . $pts($c['weeks']) . ' sem' : ($pp['weekly'] === null ? 'sin horas semanales' : 'faltan fechas')) ?></span></td>
                  <td><?= partial('load-meter', ['p' => $pp]) ?></td>
                  <td><?= $pts($pp['logged']) ?> h</td><td><?= (int)$pp['open'] ?></td></tr>
              <?php endforeach; ?></tbody>
            </table></div>
            <p class="muted small" style="margin:8px 0 0">Si dejas «Disponibles» vacío se usan sus horas semanales × la duración del sprint. Una tarea con varias personas reparte sus horas a partes iguales. Las tareas sin horas estimadas aparecen como pendientes de estimar.</p>
          <?php endif; ?>
        </div>
      </section>
      <?php endif; ?>
    <?php else: ?>
      <section class="card"><div class="empty"><?= icon('zap') ?><h3>No hay un sprint activo</h3><p>Un sprint es un periodo de trabajo con un objetivo. Planifícalo, añade tareas desde el backlog e inícialo.</p>
        <?php if ($manage): ?><button class="btn btn--primary" data-dialog="dlg-new-sprint"><?= icon('plus') ?> Planificar sprint</button><?php endif; ?></div></section>
    <?php endif; ?>
  </div>
  <section class="card" data-tour="sprint-new">
    <div class="card-head"><h2><?= icon('clock') ?> Sprints</h2><?php if ($manage): ?><button class="btn btn--sm btn--primary" data-dialog="dlg-new-sprint"><?= icon('plus', 'sm') ?> Nuevo</button><?php endif; ?></div>
    <?php $groups = ['active' => ['Activo', 'Ningún sprint en curso.'], 'planned' => ['Planificados', 'Nada planificado todavía.'], 'completed' => ['Finalizados', 'Aún no hay sprints finalizados.']]; ?>
    <?php foreach ($groups as $state => [$gTitle, $gEmpty]): $inGroup = array_values(array_filter($sprints, fn($x) => $x['state'] === $state)); ?>
      <h3 class="sprint-group-title"><?= e($gTitle) ?> <span class="muted"><?= count($inGroup) ?></span></h3>
      <ul class="list sprint-list" data-sprint-group="<?= e($state) ?>">
        <?php foreach ($inGroup as $sp): $spc = pct((int)$sp['done_count'], (int)$sp['task_count']); $isSel = $selected && (int)$selected['id'] === (int)$sp['id']; ?>
          <li class="sprint-item<?= $isSel ? ' is-selected' : '' ?>">
            <a class="sprint-link" href="<?= e(url('/projects/' . $pid . '/sprints', ['sprint' => $sp['id']])) ?>"<?= $isSel ? ' aria-current="true"' : '' ?>>
              <span class="sprint-name truncate"><?= e($sp['name']) ?></span>
              <span class="muted small"><?= $sp['start_date'] ? e(fmt_date($sp['start_date'])) . ' – ' . e(fmt_date($sp['end_date'])) : 'Sin fechas' ?> · <?= (int)$sp['done_count'] ?>/<?= (int)$sp['task_count'] ?> tareas</span>
              <span class="progress sm" aria-hidden="true"><span style="--p:<?= $spc ?>%"></span></span>
            </a>
            <?php if ($manage && $sp['state'] === 'planned'): ?><button class="btn btn--ghost btn--icon btn--sm" data-call="/api/sprints/<?= (int)$sp['id'] ?>" data-method="DELETE" data-confirm="¿Eliminar «<?= e($sp['name']) ?>»? Sus tareas vuelven al backlog." data-confirm-label="Eliminar" aria-label="Eliminar <?= e($sp['name']) ?>"><?= icon('trash', 'sm') ?></button><?php endif; ?></li>
        <?php endforeach; ?>
        <?php if (!$inGroup): ?><li class="empty small muted"><?= e($gEmpty) ?></li><?php endif; ?>
      </ul>
    <?php endforeach; ?>
  </section>
</div>

<?php if ($manage): ?>
<dialog class="modal" id="dlg-new-sprint" aria-labelledby="dns-t">
  <form data-api="/api/projects/<?= $pid ?>/sprints" data-close data-reset>
    <div class="modal-head"><h2 id="dns-t">Nuevo sprint</h2><button type="button" class="btn btn--ghost btn--icon" data-close-dialog aria-label="Cerrar"><?= icon('x') ?></button></div>
    <div class="modal-body">
      <div class="form-error" data-form-error></div>
      <p class="hint" style="margin-top:0">Primero lo básico. Tareas, responsables, horas y prioridades se añaden después, en la planificación.</p>
      <div class="field"><label for="ns-n">Nombre</label><input class="input" id="ns-n" name="name" maxlength="120" placeholder="Sprint <?= count($sprints) + 1 ?>"></div>
      <div class="field"><label for="ns-g">Objetivo</label><textarea class="textarea" id="ns-g" name="goal" rows="2" placeholder="¿Qué queremos lograr en este periodo?"></textarea></div>
      <div class="form-row"><div class="field"><label for="ns-s">Inicio estimado</label><input class="input" type="date" id="ns-s" name="start_date"></div><div class="field"><label for="ns-e">Fin estimado</label><input class="input" type="date" id="ns-e" name="end_date"><span class="error" data-error-for="end_date"></span></div></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-close-dialog>Cancelar</button><button class="btn btn--primary" type="submit">Crear sprint</button></div>
  </form>
</dialog>
<?php if ($selected && $selected['state'] !== 'completed'): $s = $selected; ?>
<dialog class="modal" id="dlg-edit-sprint" aria-labelledby="des-t">
  <form data-api="/api/sprints/<?= (int)$s['id'] ?>" data-method="PATCH" data-close>
    <div class="modal-head"><h2 id="des-t">Editar <?= e($s['name']) ?></h2><button type="button" class="btn btn--ghost btn--icon" data-close-dialog aria-label="Cerrar"><?= icon('x') ?></button></div>
    <div class="modal-body">
      <div class="form-error" data-form-error></div>
      <div class="field"><label for="es-n">Nombre</label><input class="input" id="es-n" name="name" value="<?= e($s['name']) ?>" required maxlength="120"></div>
      <div class="field"><label for="es-g">Objetivo</label><textarea class="textarea" id="es-g" name="goal" rows="2"><?= e($s['goal']) ?></textarea></div>
      <div class="form-row"><div class="field"><label for="es-s">Inicio</label><input class="input" type="date" id="es-s" name="start_date" value="<?= e($s['start_date']) ?>"></div><div class="field"><label for="es-e">Fin</label><input class="input" type="date" id="es-e" name="end_date" value="<?= e($s['end_date']) ?>"><span class="error" data-error-for="end_date"></span></div></div>
      <div class="field"><label for="es-cap">Capacidad del sprint (horas)</label><input class="input hours-input" type="number" min="0" max="99999" step="0.5" id="es-cap" name="capacity_hours" value="<?= e($s['capacity_hours'] ?? '') ?>" placeholder="<?= isset($control['autoCap']) && $control['autoCap'] !== null ? e($pts($control['autoCap'])) . ' (calculada)' : 'Automática' ?>"><span class="hint">Déjala vacía para calcularla con las horas semanales de las personas del proyecto.</span><span class="error" data-error-for="capacity_hours"></span></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-close-dialog>Cancelar</button><button class="btn btn--primary" type="submit">Guardar</button></div>
  </form>
</dialog>
<?php endif; ?>
<?php if ($selected && $selected['state'] === 'active'): $s = $selected; $open = (int)$s['task_count'] - (int)$s['done_count']; ?>
<dialog class="modal" id="dlg-complete-sprint" aria-labelledby="dcs-t">
  <form data-api="/api/sprints/<?= (int)$s['id'] ?>/complete" data-close>
    <div class="modal-head"><h2 id="dcs-t">Finalizar <?= e($s['name']) ?></h2><button type="button" class="btn btn--ghost btn--icon" data-close-dialog aria-label="Cerrar"><?= icon('x') ?></button></div>
    <div class="modal-body">
      <div class="form-error" data-form-error></div>
      <p><strong><?= (int)$s['done_count'] ?></strong> tareas completadas y <strong><?= $open ?></strong> sin terminar.</p>
      <?php $pendingList = array_values(array_filter($sprintTasks, fn($t) => $t['status_category'] !== 'done')); if ($pendingList): ?>
        <details class="more-options"><summary><?= icon('list', 'sm') ?> Ver las <?= count($pendingList) ?> sin terminar</summary>
          <ul class="small close-list"><?php foreach (array_slice($pendingList, 0, 30) as $t): ?><li><span class="key"><?= e($t['task_key']) ?></span> <?= e($t['title']) ?><?= $t['assignee_name'] ? ' · <span class="muted">' . e($t['assignee_name']) . '</span>' : '' ?></li><?php endforeach; ?></ul>
        </details>
      <?php endif; ?>
      <?php if ($s['end_date'] && $s['end_date'] > today()): ?><p class="hint">Todavía no llega su fecha de fin (<?= e(fmt_date($s['end_date'], true)) ?>). Puedes cerrarlo antes si el equipo terminó.</p><?php endif; ?>
      <?php if ($open): ?>
        <div class="field"><label for="cs-m">Las tareas sin terminar pasan a</label><select class="select" id="cs-m" name="move_to"><option value="">Backlog</option><?php foreach ($planned as $pl): ?><option value="<?= (int)$pl['id'] ?>"><?= e($pl['name']) ?></option><?php endforeach; ?></select></div>
      <?php endif; ?>
      <div class="field"><label for="cs-n">Resultados y aprendizajes</label><textarea class="textarea" id="cs-n" name="result_notes" rows="3" placeholder="Opcional: qué salió bien, qué mejorar"></textarea></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-close-dialog>Cancelar</button><button class="btn btn--primary" type="submit">Finalizar sprint</button></div>
  </form>
</dialog>
<?php endif; ?>
<?php endif; ?>
