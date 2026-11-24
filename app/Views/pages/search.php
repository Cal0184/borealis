<div class="page-head"><div><h1>Buscar</h1><p class="sub"><?= $q !== '' ? 'Resultados para «' . e($q) . '»' : 'Escribe al menos dos letras.' ?></p></div></div>
<form class="toolbar" method="get" action="<?= e(url('/search')) ?>"><?php if (!config('app.pretty_urls', true)): ?><input type="hidden" name="r" value="/search"><?php endif; ?>
  <label class="sr-only" for="sq">Buscar</label><input class="input search-input" type="search" id="sq" name="q" value="<?= e($q) ?>" autofocus style="flex:1;max-width:520px"><button class="btn btn--primary" type="submit"><?= icon('search', 'sm') ?> Buscar</button></form>
<?php $r = $results; $none = !$r['projects'] && !$r['tasks'] && !$r['teams'] && !$r['people']; ?>
<?php if ($q !== '' && $none): ?><div class="card"><div class="empty"><?= icon('search') ?><h3>Sin resultados</h3><p>Prueba con otra palabra o con la clave de una tarea, p. ej. WEB-12.</p></div></div><?php endif; ?>
<div class="split">
  <?php if ($r['tasks']): ?><section class="card"><div class="card-head"><h2><?= icon('check-square') ?> Tareas</h2></div><ul class="list">
    <?php foreach ($r['tasks'] as $t): ?><li><span class="key"><?= e($t['task_key']) ?></span><button class="task-title truncate" style="flex:1" data-open-task="<?= e($t['task_key']) ?>"><?= e($t['title']) ?></button><span class="muted small hide-sm"><?= e($t['project_name']) ?></span><span class="badge"><span class="dot" style="--c:<?= e($t['status_color']) ?>"></span><?= e($t['status_name']) ?></span></li><?php endforeach; ?>
  </ul></section><?php endif; ?>
  <div class="stack">
    <?php if ($r['projects']): ?><section class="card"><div class="card-head"><h2><?= icon('folder') ?> Proyectos</h2></div><ul class="list"><?php foreach ($r['projects'] as $p): ?><li><a style="flex:1;font-weight:600;color:var(--text)" href="<?= e(url('/projects/' . $p['id'])) ?>"><?= e($p['name']) ?></a><?= $p['archived_at'] ? '<span class="badge">Archivado</span>' : '' ?><span class="key"><?= e($p['project_key']) ?></span></li><?php endforeach; ?></ul></section><?php endif; ?>
    <?php if ($r['teams']): ?><section class="card"><div class="card-head"><h2><?= icon('users') ?> Equipos</h2></div><ul class="list"><?php foreach ($r['teams'] as $t): ?><li><a style="flex:1;font-weight:600;color:var(--text)" href="<?= e(url('/teams/' . $t['id'])) ?>"><?= e($t['name']) ?></a></li><?php endforeach; ?></ul></section><?php endif; ?>
    <?php if ($r['people']): ?><section class="card"><div class="card-head"><h2><?= icon('user') ?> Personas</h2></div><ul class="list"><?php foreach ($r['people'] as $u): ?><li><a class="person" style="color:var(--text)" href="<?= e(url('/people/' . $u['id'])) ?>"><?= avatar($u, 'sm') ?><?= e($u['name']) ?></a><span class="muted small"><?= e($u['title'] ?? '') ?></span></li><?php endforeach; ?></ul></section><?php endif; ?>
  </div>
</div>
