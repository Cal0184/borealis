<?= partial('admin-nav', compact('ws', 'section')) ?>
<form class="toolbar" method="get" action="<?= e(url('/admin/audit')) ?>">
  <?php if (!config('app.pretty_urls', true)): ?><input type="hidden" name="r" value="/admin/audit"><?php endif; ?>
  <label class="sr-only" for="au-u">Persona</label>
  <select class="select" id="au-u" name="user_id" data-autosubmit><option value="">Todas las personas</option><?php foreach ($members as $m): ?><option value="<?= (int)$m['id'] ?>"<?= selected($m['id'], $filters['user_id'] ?? '') ?>><?= e($m['name']) ?></option><?php endforeach; ?></select>
  <label class="sr-only" for="au-t">Tipo</label>
  <select class="select" id="au-t" name="type" data-autosubmit><option value="">Todos los tipos</option><?php foreach (['project' => 'Proyectos', 'task' => 'Tareas', 'team' => 'Equipos', 'user' => 'Usuarios', 'role' => 'Roles', 'sprint' => 'Sprints', 'file' => 'Archivos', 'document' => 'Documentos', 'workspace' => 'Espacio', 'invitation' => 'Invitaciones'] as $k => $v): ?><option value="<?= $k ?>"<?= selected($k, $filters['type'] ?? '') ?>><?= e($v) ?></option><?php endforeach; ?></select>
</form>
<section class="card"><div class="table-wrap"><table class="table">
  <thead><tr><th>Cuándo</th><th>Quién</th><th>Acción</th><th class="hide-sm">Proyecto</th></tr></thead>
  <tbody>
    <?php foreach ($rows as $a): ?><tr><td class="muted small" style="white-space:nowrap"><?= e(fmt_datetime($a['created_at'])) ?></td><td><?= e($a['user_name'] ?? 'Sistema') ?></td><td><?= e($a['summary']) ?></td><td class="hide-sm"><?= e($a['project_name'] ?? '') ?></td></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="4" class="empty">Sin registros.</td></tr><?php endif; ?>
  </tbody>
</table></div>
<div class="card-foot row between">
  <?php if ($page > 1): ?><a class="btn btn--sm" href="<?= e(url('/admin/audit', ['page' => $page - 1] + $filters)) ?>"><?= icon('chevron-left', 'sm') ?> Anteriores</a><?php else: ?><span></span><?php endif; ?>
  <?php if ($hasMore): ?><a class="btn btn--sm" href="<?= e(url('/admin/audit', ['page' => $page + 1] + $filters)) ?>">Más antiguos <?= icon('chevron-right', 'sm') ?></a><?php endif; ?>
</div></section>
