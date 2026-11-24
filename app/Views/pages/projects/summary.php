<?= partial('project-header', compact('project', 'modules', 'tab', 'can')) ?>
<?= partial('capacity-alerts', ['alerts' => \Borealis\Services\CapacityAlerts::forView((int)$project['id'])]) ?>
<?php $p = $project; $total = array_sum(array_column($byStatus, 'n')); ?>
<div class="split">
  <div class="stack">
    <?php if ($p['description']): ?><section class="card"><div class="card-body rich"><?= rich_text($p['description']) ?></div></section><?php endif; ?>
    <section class="card">
      <div class="card-head"><h2><?= icon('target') ?> Avance de la expedición</h2><span class="badge success"><?= (int)$p['progress'] ?>%</span></div>
      <div class="card-body">
        <?= partial('expedition', ['percent' => (int)$p['progress'], 'label' => 'Avance del proyecto']) ?>
        <?php if ($total): ?>
          <div class="stacked mt" role="img" aria-label="Distribución por estado">
            <?php foreach ($byStatus as $s): if (!$s['n']) continue; ?><span style="--c:<?= e($s['color']) ?>;--w:<?= pct((int)$s['n'], $total) ?>%" title="<?= e($s['name']) ?>: <?= (int)$s['n'] ?>"></span><?php endforeach; ?>
          </div>
          <div class="legend mt row wrap"><?php foreach ($byStatus as $s): ?><span style="--c:<?= e($s['color']) ?>"><?= e($s['name']) ?> · <?= (int)$s['n'] ?></span><?php endforeach; ?></div>
        <?php else: ?>
          <div class="empty" style="padding:20px 0 0"><h3>Sin tareas todavía</h3><p>Crea una tarea escribiendo solo su título.</p>
            <?php if ($can('task.create')): ?><a class="btn btn--primary" href="<?= e(url('/projects/' . $p['id'] . '/board')) ?>"><?= icon('board') ?> Ir al tablero</a><?php endif; ?></div>
        <?php endif; ?>
      </div>
    </section>
    <?php if ($activeSprint): ?>
      <a class="card" href="<?= e(url('/projects/' . $p['id'] . '/sprints')) ?>"><div class="card-body row between wrap"><span class="row"><?= icon('zap') ?> <strong><?= e($activeSprint['name']) ?></strong> en curso<?= $activeSprint['end_date'] ? ' hasta el ' . e(fmt_date($activeSprint['end_date'])) : '' ?></span><span class="muted small"><?= e($activeSprint['goal'] ?? '') ?></span></div></a>
    <?php endif; ?>
    <section class="card">
      <div class="card-head"><h2><?= icon('calendar') ?> Próximas entregas</h2><a class="small" href="<?= e(url('/projects/' . $p['id'] . '/list', ['sort' => 'due'])) ?>">Ver lista</a></div>
      <ul class="list">
        <?php foreach ($upcoming as $t): ?>
          <li><span class="key"><?= e($t['task_key']) ?></span><button class="task-title truncate" style="flex:1" data-open-task="<?= e($t['task_key']) ?>"><?= e($t['title']) ?></button>
          <?= avatar($t['assignee_id'] ? ['id' => $t['assignee_id'], 'name' => $t['assignee_name'], 'avatar_color' => $t['assignee_color']] : null, 'sm') ?>
          <span class="due <?= due_state($t['due_date'] ?: $t['start_date']) ?>"><?= e(fmt_date($t['due_date'] ?: $t['start_date'])) ?></span></li>
        <?php endforeach; ?>
        <?php if (!$upcoming): ?><li class="empty">Ninguna tarea abierta tiene fecha.</li><?php endif; ?>
      </ul>
    </section>
  </div>
  <div class="stack">
    <section class="card"><div class="card-head"><h2><?= icon('block') ?> Bloqueos</h2><span class="badge<?= $blocked ? ' danger' : '' ?>"><?= count($blocked) ?></span></div>
      <ul class="list"><?php foreach ($blocked as $t): ?><li><span class="key"><?= e($t['task_key']) ?></span><button class="task-title truncate" style="flex:1" data-open-task="<?= e($t['task_key']) ?>" title="<?= e($t['blocked_reason']) ?>"><?= e($t['title']) ?></button></li><?php endforeach; ?>
      <?php if (!$blocked): ?><li class="empty">Nada bloqueado.</li><?php endif; ?></ul></section>
    <section class="card"><div class="card-head"><h2><?= icon('users') ?> Integrantes</h2><?php if ($can('project.manage')): ?><a class="small" href="<?= e(url('/projects/' . $p['id'] . '/settings', ['section' => 'members'])) ?>">Gestionar</a><?php endif; ?></div>
      <ul class="list"><?php foreach (array_slice($members, 0, 8) as $m): ?><li><a class="person" href="<?= e(url('/people/' . $m['id'])) ?>" style="flex:1;color:var(--text)"><?= avatar($m, 'sm') ?><span class="truncate"><?= e($m['name']) ?></span></a><span class="muted small"><?= e($m['role_name']) ?><?= $m['source'] === 'team' ? ' · equipo' : '' ?></span></li><?php endforeach; ?>
      <?php if (count($members) > 8): ?><li class="muted small">y <?= count($members) - 8 ?> más</li><?php endif; ?></ul></section>
    <?php if (!empty($decisions)): ?>
    <section class="card" data-decisions><div class="card-head"><h2><?= icon('flag-check') ?> Decisiones</h2><span class="badge"><?= count($decisions) ?></span></div>
      <div class="card-body"><ul class="history"><?php foreach ($decisions as $d): ?><li><span><strong><?= e($d['user_name'] ?? 'Usuario eliminado') ?></strong> en <a href="<?= e(url('/t/' . $d['task_key'])) ?>" data-open-task="<?= e($d['task_key']) ?>"><?= e($d['task_key']) ?></a>: <?= e(mb_strimwidth(strip_tags((string)$d['body']), 0, 160, '…')) ?> <span class="muted">· <?= e(fmt_date(substr((string)$d['created_at'], 0, 10), true)) ?></span></span></li><?php endforeach; ?></ul>
      <p class="muted small">Para registrar una decisión, marca «Es una decisión» al comentar una tarea.</p></div></section>
    <?php endif; ?>
    <section class="card"><div class="card-head"><h2><?= icon('clock') ?> Actividad</h2><?php if (\Borealis\Security\Policy::can('audit.view')): ?><a class="small" href="<?= e(url('/admin/audit', ['project_id' => $p['id']])) ?>">Registro completo</a><?php endif; ?></div>
      <div class="card-body"><ul class="history"><?php foreach ($activity as $a): ?><li><span><strong><?= e($a['user_name'] ?? 'Sistema') ?></strong> <?= e(lcfirst($a['summary'])) ?> <span class="muted">· <?= e(time_ago($a['created_at'])) ?></span></span></li><?php endforeach; ?>
      <?php if (!$activity): ?><li>Sin actividad.</li><?php endif; ?></ul></div></section>
  </div>
</div>
