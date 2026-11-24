<?php
use Borealis\Services\Projects;
/** @var array $project @var array $modules @var string $tab @var callable $can */
$pid = (int)$project['id'];
$tabs = [
    'summary' => ['/projects/' . $pid, 'Resumen', 'home', true],
    'list' => ['/projects/' . $pid . '/list', 'Lista', 'list', true],
    'board' => ['/projects/' . $pid . '/board', 'Tablero', 'board', true],
    'backlog' => ['/projects/' . $pid . '/backlog', 'Backlog', 'layers', $modules['backlog']],
    'sprints' => ['/projects/' . $pid . '/sprints', 'Sprints', 'zap', $modules['sprints']],
    'calendar' => ['/projects/' . $pid . '/calendar', 'Calendario', 'calendar', $modules['calendar']],
    'timeline' => ['/projects/' . $pid . '/timeline', 'Cronograma', 'timeline', $modules['timeline']],
    'epics' => ['/projects/' . $pid . '/epics', 'Épicas', 'flag', $modules['epics']],
    'docs' => ['/projects/' . $pid . '/docs', 'Documentos', 'doc', $modules['docs']],
    'files' => ['/projects/' . $pid . '/files', 'Archivos', 'clip', true],
    'reports' => ['/projects/' . $pid . '/reports', 'Reportes', 'chart', $modules['reports']],
    'settings' => ['/projects/' . $pid . '/settings', 'Ajustes', 'settings', $can('project.manage')],
];
$stateClass = ['planned' => '', 'active' => 'success', 'on_hold' => 'warn', 'completed' => 'info'][$project['state']] ?? '';
?>
<?php if ($project['archived_at']): ?>
  <div class="archived-banner">
    <span class="row"><?= icon('archive') ?> Este proyecto está archivado desde el <?= e(fmt_date(local_date('Y-m-d', $project['archived_at']), true)) ?>. Sus datos se conservan en modo de solo lectura.</span>
    <?php if ($can('project.archive')): ?><button class="btn btn--sm" data-call="/api/projects/<?= $pid ?>/restore"><?= icon('restore') ?> Restaurar</button><?php endif; ?>
  </div>
<?php endif; ?>
<section class="hero compact project-hero cover-<?= e($project['cover'] ?? 'forest') ?>" style="--pc:<?= e($project['color']) ?>">
  <div class="hero-row">
    <div style="min-width:0">
      <div class="row wrap" style="margin-bottom:6px">
        <span class="badge key-badge"><?= e($project['project_key']) ?></span>
        <span class="badge <?= $stateClass ?>"><?= e(Projects::STATES[$project['state']]) ?></span>
      </div>
      <h1 data-project-name="<?= $pid ?>"><?= e($project['name']) ?></h1>
      <div class="meta">
        <?php if ($project['team_name']): ?><a href="<?= e(url('/teams/' . $project['team_id'])) ?>" style="color:inherit" class="row"><?= icon('users', 'sm') ?> <?= e($project['team_name']) ?></a><?php endif; ?>
        <?php if ($project['due_date']): ?><span class="row"><?= icon('calendar', 'sm') ?> Entrega <?= e(fmt_date($project['due_date'], true)) ?></span><?php endif; ?>
        <span class="row" title="<?= (int)$project['done'] ?> de <?= (int)$project['total'] ?> tareas finalizadas">
          <span class="progress"><span style="--p:<?= (int)$project['progress'] ?>%"></span></span> <?= (int)$project['progress'] ?>%
        </span>
      </div>
    </div>
    <div class="page-actions">
      <?php if ($can('task.create')): ?><button class="btn btn--aurora" data-dialog="dlg-new-task"><?= icon('plus') ?> Tarea</button><?php endif; ?>
    </div>
  </div>
</section>
<nav class="tabs tabs--slider" aria-label="Secciones del proyecto" data-slider>
  <?php foreach ($tabs as $key => [$href, $label, $ic, $show]): if (!$show) continue; ?>
    <a href="<?= e(url($href)) ?>"<?= $key === 'sprints' ? ' data-tour="sprints"' : '' ?><?= $tab === $key ? ' aria-current="page"' : '' ?>><?= icon($ic) ?> <?= e($label) ?></a>
  <?php endforeach; ?>
</nav>
