<?php
use Borealis\Services\Activity;
$cats = Activity::CATEGORIES;
$srcs = Activity::SOURCES;
$show = function (?string $json): string {
    if (!$json) return '';
    $d = json_decode($json, true);
    if (!is_array($d)) return e($json);
    $out = [];
    foreach ($d as $k => $v) $out[] = '<span class="muted">' . e((string)$k) . ':</span> ' . e(is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (is_bool($v) ? ($v ? 'sí' : 'no') : ($v === null || $v === '' ? '—' : (string)$v)));
    return implode('<br>', $out);
};
?>
<?= partial('admin-nav', compact('ws', 'section')) ?>
<?php if ($verify): ?>
  <div class="notice<?= $verify['ok'] ? '' : ' notice--danger' ?> mb" role="status" data-audit-verify="<?= $verify['ok'] ? 'ok' : 'broken' ?>">
    <?= icon($verify['ok'] ? 'check-circle' : 'alert') ?>
    <?= $verify['ok'] ? 'Integridad comprobada: ' . (int)$verify['checked'] . ' registros encadenados sin cambios.' : 'Se detectó una modificación o eliminación fuera de Borealis a partir del registro n.º ' . (int)$verify['broken'] . '.' ?>
    <?php if ($verify['legacy']): ?><span class="muted small"><?= (int)$verify['legacy'] ?> registros anteriores a la 1.14 no están encadenados.</span><?php endif; ?>
  </div>
<?php endif; ?>
<form class="toolbar wrap" method="get" action="<?= e(url('/admin/audit')) ?>" data-audit-filters>
  <?php if (!config('app.pretty_urls', true)): ?><input type="hidden" name="r" value="/admin/audit"><?php endif; ?>
  <label class="sr-only" for="au-u">Persona</label>
  <select class="select" id="au-u" name="user_id" data-autosubmit><option value="">Todas las personas</option><?php foreach ($members as $m): ?><option value="<?= (int)$m['id'] ?>"<?= selected($m['id'], $filters['user_id'] ?? '') ?>><?= e($m['name']) ?></option><?php endforeach; ?></select>
  <label class="sr-only" for="au-p">Proyecto</label>
  <select class="select" id="au-p" name="project_id" data-autosubmit><option value="">Todos los proyectos</option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>"<?= selected($p['id'], $filters['project_id'] ?? '') ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
  <label class="sr-only" for="au-c">Categoría</label>
  <select class="select" id="au-c" name="category" data-autosubmit><option value="">Todo</option><?php foreach ($cats as $k => $v): ?><option value="<?= $k ?>"<?= selected($k, $filters['category'] ?? '') ?>><?= e($v) ?></option><?php endforeach; ?></select>
  <label class="sr-only" for="au-per">Período</label>
  <select class="select" id="au-per" name="period" data-autosubmit><option value="">Todo el tiempo</option><?php foreach (['today' => 'Hoy', 'week' => 'Esta semana', 'month' => 'Este mes', 'quarter' => 'Este trimestre', 'year' => 'Este año', '90d' => 'Últimos 90 días'] as $k => $v): ?><option value="<?= $k ?>"<?= selected($k, $filters['period'] ?? '') ?>><?= e($v) ?></option><?php endforeach; ?></select>
  <details class="more-options inline">
    <summary><?= icon('filter', 'sm') ?> Más filtros</summary>
    <div class="row wrap mt-sm">
      <label class="field"><span>Acción</span><select class="select" name="action"><option value="">Todas</option><?php foreach ($actions as $a): ?><option value="<?= e($a) ?>"<?= selected($a, $filters['action'] ?? '') ?>><?= e($a) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>Origen</span><select class="select" name="source"><option value="">Todos</option><?php foreach ($srcs as $k => $v): ?><option value="<?= $k ?>"<?= selected($k, $filters['source'] ?? '') ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>Desde</span><input class="input" type="date" name="from" value="<?= e($filters['from'] ?? '') ?>"></label>
      <label class="field"><span>Hasta</span><input class="input" type="date" name="to" value="<?= e($filters['to'] ?? '') ?>"></label>
      <label class="field"><span>Texto</span><input class="input" type="search" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Buscar en el resumen"></label>
      <button class="btn btn--sm" type="submit">Aplicar</button>
    </div>
  </details>
  <span class="grow"></span>
  <a class="btn btn--sm btn--ghost" href="<?= e(url('/admin/audit', $filters + ['verify' => 1])) ?>"><?= icon('shield', 'sm') ?> Comprobar integridad</a>
  <a class="btn btn--sm btn--ghost" href="<?= e(url('/admin/audit.csv', $filters)) ?>"><?= icon('download', 'sm') ?> CSV</a>
</form>
<section class="card"><div class="table-wrap"><table class="table audit-table">
  <thead><tr><th>Cuándo</th><th>Quién</th><th>Acción</th><th class="hide-sm">Proyecto</th></tr></thead>
  <tbody>
    <?php foreach ($rows as $a): $more = $a['before_data'] || $a['after_data'] || $a['reason']; ?><tr data-audit-row="<?= (int)$a['id'] ?>">
      <td class="muted small" style="white-space:nowrap"><?= e(fmt_datetime($a['created_at'])) ?></td>
      <td><?= e($a['user_name'] ?? 'Sistema') ?><?php if (($a['source'] ?? '') && $a['source'] !== 'web'): ?> <span class="badge"><?= e($srcs[$a['source']] ?? $a['source']) ?></span><?php endif; ?></td>
      <td>
        <?= e($a['summary']) ?>
        <?php if ($more): ?><details class="audit-detail"><summary class="small muted">Detalle</summary>
          <div class="audit-diff">
            <?php if ($a['before_data']): ?><div><strong class="small">Antes</strong><div class="small"><?= $show($a['before_data']) ?></div></div><?php endif; ?>
            <?php if ($a['after_data']): ?><div><strong class="small">Después</strong><div class="small"><?= $show($a['after_data']) ?></div></div><?php endif; ?>
          </div>
          <?php if ($a['reason']): ?><p class="small"><strong>Motivo:</strong> <?= e($a['reason']) ?></p><?php endif; ?>
        </details><?php endif; ?>
      </td>
      <td class="hide-sm"><?= e($a['project_name'] ?? '') ?></td>
    </tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="4" class="empty">Sin registros.</td></tr><?php endif; ?>
  </tbody>
</table></div>
<div class="card-foot row between">
  <?php if ($page > 1): ?><a class="btn btn--sm" href="<?= e(url('/admin/audit', ['page' => $page - 1] + $filters)) ?>"><?= icon('chevron-left', 'sm') ?> Anteriores</a><?php else: ?><span></span><?php endif; ?>
  <span class="muted small">Los registros no se pueden editar ni borrar desde Borealis.</span>
  <?php if ($hasMore): ?><a class="btn btn--sm" href="<?= e(url('/admin/audit', ['page' => $page + 1] + $filters)) ?>">Más antiguos <?= icon('chevron-right', 'sm') ?></a><?php endif; ?>
</div></section>
