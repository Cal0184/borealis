<?php
use Borealis\Services\Activity;
$cats = Activity::CATEGORIES;
$srcs = Activity::SOURCES;
$kinds = Activity::KINDS;
$days = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
$months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
// the heading of the group a row falls in (local time of the person reading)
$groupOf = function (string $utc) use ($group, $days, $months): array {
    $d = local_date('Y-m-d', $utc);
    $ts = strtotime($d);
    return match ($group) {
        'week' => [date('o-W', $ts), 'Semana del ' . date('d/m/Y', strtotime('monday this week', $ts))],
        'month' => [date('Y-m', $ts), ucfirst($months[(int)date('n', $ts) - 1]) . ' de ' . date('Y', $ts)],
        default => [$d, ($d === today() ? 'Hoy, ' : '') . $days[(int)date('w', $ts)] . ' ' . date('d/m/Y', $ts)],
    };
};
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
<p class="muted mb">El <strong>registro maestro</strong> reúne todo lo que pasó en el espacio: el trabajo, la administración, lo que Borealis hizo solo y los errores. El historial de cada proyecto y de cada tarea sale de aquí mismo.</p>
<form class="toolbar wrap" method="get" action="<?= e(url('/admin/audit')) ?>" data-audit-filters>
  <?php if (!config('app.pretty_urls', true)): ?><input type="hidden" name="r" value="/admin/audit"><?php endif; ?>
  <label class="sr-only" for="au-u">Persona</label>
  <select class="select" id="au-u" name="user_id" data-autosubmit><option value="">Todas las personas</option><?php foreach ($members as $m): ?><option value="<?= (int)$m['id'] ?>"<?= selected($m['id'], $filters['user_id'] ?? '') ?>><?= e($m['name']) ?></option><?php endforeach; ?></select>
  <label class="sr-only" for="au-p">Proyecto</label>
  <select class="select" id="au-p" name="project_id" data-autosubmit><option value="">Todos los proyectos</option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>"<?= selected($p['id'], $filters['project_id'] ?? '') ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
  <label class="sr-only" for="au-c">Categoría</label>
  <select class="select" id="au-c" name="category" data-autosubmit><option value="">Todo</option><?php foreach ($cats as $k => $v): ?><option value="<?= $k ?>"<?= selected($k, $filters['category'] ?? '') ?>><?= e($v) ?></option><?php endforeach; ?></select>
  <label class="sr-only" for="au-k">Tipo</label>
  <select class="select" id="au-k" name="kind" data-autosubmit><option value="">Todos los tipos</option><?php foreach ($kinds as $k => $v): ?><option value="<?= $k ?>"<?= selected($k, $filters['kind'] ?? '') ?>><?= e($v) ?></option><?php endforeach; ?></select>
  <label class="sr-only" for="au-per">Período</label>
  <select class="select" id="au-per" name="period" data-autosubmit><option value="">Todo el tiempo</option><?php foreach (['today' => 'Hoy', 'week' => 'Esta semana', 'month' => 'Este mes', 'quarter' => 'Este trimestre', 'year' => 'Este año', '90d' => 'Últimos 90 días'] as $k => $v): ?><option value="<?= $k ?>"<?= selected($k, $filters['period'] ?? '') ?>><?= e($v) ?></option><?php endforeach; ?></select>
  <details class="more-options inline">
    <summary><?= icon('filter', 'sm') ?> Más filtros</summary>
    <div class="row wrap mt-sm">
      <label class="field"><span>Acción</span><select class="select" name="action"><option value="">Todas</option><?php foreach ($actions as $a): ?><option value="<?= e($a) ?>"<?= selected($a, $filters['action'] ?? '') ?>><?= e($a) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>Origen</span><select class="select" name="source"><option value="">Todos</option><?php foreach ($srcs as $k => $v): ?><option value="<?= $k ?>"<?= selected($k, $filters['source'] ?? '') ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>Desde</span><input class="input" type="date" name="from" value="<?= e($filters['from'] ?? '') ?>"></label>
      <label class="field"><span>Hasta</span><input class="input" type="date" name="to" value="<?= e($filters['to'] ?? '') ?>"></label>
      <label class="field"><span>Resultado</span><select class="select" name="result"><option value="">Todos</option><?php foreach (Activity::RESULTS as $k => $v): ?><option value="<?= $k ?>"<?= selected($k, $filters['result'] ?? '') ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>Agrupar por</span><select class="select" name="group"><?php foreach (['day' => 'Día', 'week' => 'Semana', 'month' => 'Mes'] as $k => $v): ?><option value="<?= $k ?>"<?= selected($k, $group) ?>><?= $v ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>Texto</span><input class="input" type="search" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Buscar en el resumen"></label>
      <button class="btn btn--sm" type="submit">Aplicar</button>
    </div>
  </details>
  <span class="grow"></span>
  <a class="btn btn--sm btn--ghost" href="<?= e(url('/admin/audit', $filters + ['verify' => 1])) ?>"><?= icon('shield', 'sm') ?> Comprobar integridad</a>
  <a class="btn btn--sm btn--ghost" href="<?= e(url('/admin/audit.csv', $filters)) ?>"><?= icon('download', 'sm') ?> CSV</a>
  <a class="btn btn--sm btn--ghost" href="<?= e(url('/admin/audit.json', $filters)) ?>"><?= icon('download', 'sm') ?> JSON</a>
</form>
<p class="audit-legend small muted" aria-hidden="true"><?php foreach ($kinds as $k => $v): ?><span class="audit-kind-dot kind-<?= $k ?>"></span> <?= e($v) ?> &nbsp; <?php endforeach; ?></p>
<section class="card audit-log" data-audit-group="<?= e($group) ?>">
  <?php $cur = null; foreach ($rows as $a):
    [$gk, $gl] = $groupOf($a['created_at']);
    $kind = Activity::kind($a);
    $link = Activity::link($a);
    $more = $a['before_data'] || $a['after_data'] || $a['reason'];
    if ($gk !== $cur): if ($cur !== null): ?></ol><?php endif; $cur = $gk; ?>
      <h2 class="audit-day" data-audit-heading><?= e($gl) ?></h2><ol class="audit-timeline">
    <?php endif; ?>
    <li class="audit-entry kind-<?= $kind ?>" data-audit-row="<?= (int)$a['id'] ?>" data-kind="<?= $kind ?>">
      <time class="audit-time small muted" datetime="<?= e($a['created_at']) ?>Z" title="<?= e(fmt_datetime($a['created_at'])) ?>"><?= e(local_date($group === 'day' ? 'H:i' : 'd/m H:i', $a['created_at'])) ?></time>
      <div class="audit-body">
        <div class="audit-line">
          <strong><?= e($a['user_name'] ?? 'Sistema') ?></strong>
          <?php if ($link): ?><a href="<?= e($link) ?>"><?= e($a['summary']) ?></a><?php else: ?><span><?= e($a['summary']) ?></span><?php endif; ?>
        </div>
        <div class="audit-meta small muted">
          <span class="badge kind-<?= $kind ?>"><?= e($kinds[$kind]) ?></span>
          <?= e($cats[$a['category'] ?? ''] ?? '') ?>
          <?php if (!empty($a['project_name'])): ?> · <?= e($a['project_name']) ?><?php endif; ?>
          <?php if (($a['source'] ?? '') && $a['source'] !== 'web'): ?> · <?= e($srcs[$a['source']] ?? $a['source']) ?><?php endif; ?>
          <?php if (!empty($a['result'])): ?> · <strong class="text-danger"><?= e(Activity::RESULTS[$a['result']] ?? $a['result']) ?></strong><?php endif; ?>
          · n.º <?= (int)$a['id'] ?>
        </div>
        <?php if ($more): ?><details class="audit-detail"><summary class="small muted">Antes y después</summary>
          <div class="audit-diff">
            <?php if ($a['before_data']): ?><div><strong class="small">Antes</strong><div class="small"><?= $show($a['before_data']) ?></div></div><?php endif; ?>
            <?php if ($a['after_data']): ?><div><strong class="small">Después</strong><div class="small"><?= $show($a['after_data']) ?></div></div><?php endif; ?>
          </div>
          <?php if ($a['reason']): ?><p class="small"><strong>Motivo:</strong> <?= e($a['reason']) ?></p><?php endif; ?>
        </details><?php endif; ?>
      </div>
    </li>
  <?php endforeach; if ($cur !== null): ?></ol><?php endif; ?>
  <?php if (!$rows): ?><p class="empty">Sin registros.</p><?php endif; ?>
<div class="card-foot row between">
  <?php if ($paged): ?><a class="btn btn--sm" href="<?= e(url('/admin/audit', $filters + ['group' => $group])) ?>"><?= icon('chevron-left', 'sm') ?> Más recientes</a><?php else: ?><span></span><?php endif; ?>
  <span class="muted small">Se guardan <?= ($m = (int)config('audit.retention_months', 0)) ? "los últimos $m meses en línea; lo anterior, en archivos de respaldo" : 'sin fecha de caducidad' ?>. Los registros no se pueden editar ni borrar desde Borealis.</span>
  <?php if ($hasMore): ?><a class="btn btn--sm" href="<?= e(url('/admin/audit', ['before' => $next, 'group' => $group] + $filters)) ?>">Más antiguos <?= icon('chevron-right', 'sm') ?></a><?php endif; ?>
</div></section>
