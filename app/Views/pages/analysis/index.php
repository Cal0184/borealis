<?php
use Borealis\Controllers\AnalysisController;
use Borealis\Services\Analysis\Analysis;
use Borealis\Services\Analysis\Metrics;
use Borealis\Services\Analysis\Scenarios;
use Borealis\Services\Monitoring;

/** @var string $tab @var array $filters @var array $cfg @var array $scope @var array $projects @var array $teams */
require BASE_PATH . '/app/Views/partials/analysis-bits.php';
$f = $filters;
$keep = array_filter(['project_id' => $f['project_id'], 'team_id' => $f['team_id'], 'mine' => $f['mine'] ? '1' : null, 'user_id' => $f['user_id'] ?? null]);
$link = fn(string $tab, array $extra = []) => url('/analysis', ['tab' => $tab] + $extra + $keep);
$who = $scope['admin'] ? 'todos los proyectos del espacio' : ($scope['managed'] ? 'tus proyectos y los que gestionas' : 'los proyectos en los que participas');
$periodTxt = fmt_date($f['from'], true) . ' – ' . fmt_date($f['to'], true);
$cached = !empty($d['_cached']);
?>
<div class="page-head">
  <div><h1>Análisis</h1><p class="sub">Qué ocurrió, qué se repite, qué está en riesgo y qué alternativas hay. Borealis recomienda; el equipo decide. Ves <?= e($who) ?>.</p></div>
  <?php if ($tab === 'report'): ?><div class="page-actions">
    <a class="btn" href="<?= e(url('/analysis/report/print', array_filter(['project_id' => $f['project_id'], 'team_id' => $f['team_id'], 'sprint_id' => $f['sprint_id'], 'from' => $f['from'], 'to' => $f['to'], 'mine' => $f['mine'] ? '1' : null]))) ?>" target="_blank" rel="noopener"><?= icon('doc', 'sm') ?> Vista imprimible</a>
    <a class="btn" href="<?= e(url('/analysis/report.csv', array_filter(['project_id' => $f['project_id'], 'team_id' => $f['team_id'], 'sprint_id' => $f['sprint_id'], 'from' => $f['from'], 'to' => $f['to'], 'mine' => $f['mine'] ? '1' : null]))) ?>"><?= icon('download', 'sm') ?> CSV</a>
  </div><?php endif; ?>
</div>
<nav class="segmented mb mon-tabs" aria-label="Vistas de análisis">
  <?php foreach (AnalysisController::TABS as $k => $label): ?><a href="<?= e($link($k)) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?></a><?php endforeach; ?>
</nav>
<?php if ($tab !== 'scenarios'): ?>
<form class="toolbar" method="get" action="<?= e(url('/analysis')) ?>" data-filter-form>
  <?php if (!config('app.pretty_urls', true)): ?><input type="hidden" name="r" value="/analysis"><?php endif; ?>
  <input type="hidden" name="tab" value="<?= e($tab) ?>">
  <label class="sr-only" for="an-p">Proyecto</label>
  <select class="select<?= $f['project_id'] ? ' filter-active' : '' ?>" id="an-p" name="project_id" data-autosubmit>
    <option value="">Todos mis proyectos</option>
    <?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>"<?= selected($p['id'], $f['project_id']) ?>><?= e($p['name']) ?></option><?php endforeach; ?>
  </select>
  <?php if ($teams && $tab !== 'estimates'): ?><label class="sr-only" for="an-t">Equipo</label>
  <select class="select<?= $f['team_id'] ? ' filter-active' : '' ?>" id="an-t" name="team_id" data-autosubmit><option value="">Cualquier equipo</option>
    <?php foreach ($teams as $t): ?><option value="<?= (int)$t['id'] ?>"<?= selected($t['id'], $f['team_id']) ?>><?= e($t['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
  <?php if (in_array($tab, ['report', 'trends'], true)): ?><label class="sr-only" for="an-s">Sprint</label>
  <select class="select<?= $f['sprint_id'] ? ' filter-active' : '' ?>" id="an-s" name="sprint_id" data-autosubmit><option value="">Cualquier sprint</option>
    <?php foreach ($sprintOptions as $sp): if ($f['project_id'] && (int)$sp['project_id'] !== (int)$f['project_id']) continue; ?><option value="<?= (int)$sp['id'] ?>"<?= selected($sp['id'], $f['sprint_id']) ?>><?= e($sp['name']) ?><?= $sp['state'] === 'active' ? ' · activo' : ($sp['state'] === 'completed' ? ' · cerrado' : '') ?></option><?php endforeach; ?></select><?php endif; ?>
  <?php if (!empty($people) && $tab !== 'estimates'): ?><label class="sr-only" for="an-u">Persona</label>
  <select class="select<?= !empty($f['user_id']) ? ' filter-active' : '' ?>" id="an-u" name="user_id" data-autosubmit title="Para entender la carga de alguien, no para comparar personas"><option value="">Cualquier persona</option>
    <?php foreach ($people as $pp): ?><option value="<?= (int)$pp['id'] ?>"<?= selected($pp['id'], $f['user_id'] ?? '') ?>><?= e($pp['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
  <?php if ($tab !== 'planning'): ?>
  <label class="sr-only" for="an-from">Desde</label><input class="input" type="date" id="an-from" name="from" value="<?= e($f['from']) ?>" title="Desde">
  <label class="sr-only" for="an-to">Hasta</label><input class="input" type="date" id="an-to" name="to" value="<?= e($f['to']) ?>" title="Hasta">
  <?php endif; ?>
  <?php if ($tab === 'report'): ?><label class="sr-only" for="an-st">Estado</label><select class="select" id="an-st" name="state"><option value="all">Todas las tareas</option><option value="open"<?= selected('open', $f['state']) ?>>Pendientes</option><option value="done"<?= selected('done', $f['state']) ?>>Finalizadas</option></select><?php endif; ?>
  <label class="check" title="Tus indicadores personales: tareas de las que eres responsable"><input type="checkbox" name="mine" value="1"<?= checked((bool)$f['mine']) ?>> Solo lo mío</label>
  <button class="btn" type="submit"><?= icon('filter', 'sm') ?> Aplicar</button>
</form>
<?php if ($tab !== 'planning'): ?><nav class="segmented an-periods mb" aria-label="Período"><?php foreach (Analysis::PERIODS as $pk => $pl): ?><a href="<?= e($link($tab, ['period' => $pk])) ?>"<?= ($f['period'] ?? null) === $pk ? ' aria-current="page"' : '' ?> data-period="<?= $pk ?>"><?= e($pl) ?></a><?php endforeach; ?></nav><?php endif; ?>
<?php endif; ?>

<?php if ($tab === 'summary'): $i = $d['ind']; $cap = $d['cap']; ?>
  <?php $levels = array_count_values(array_map(fn($p) => $p['level'], $d['projects'])); $riskCount = ($levels['high'] ?? 0) + ($levels['moderate'] ?? 0) + count(array_filter($d['sprints'], fn($s) => in_array($s['eval']['level'], ['high', 'moderate'], true)));
  $week0 = $cap['weeks'][0] ?? null; $maxGap = $cap['weeks'] ? max(array_map(fn($w) => $w['gap'] ?? -INF, $cap['weeks'])) : null; ?>
  <div class="grid grid-4 stats mb an-kpis">
    <a class="card stat<?= ($levels['high'] ?? 0) ? ' danger' : (($levels['moderate'] ?? 0) ? ' warn' : '') ?>" href="<?= e($link('planning')) ?>" data-kpi="state">
      <span class="label"><?= icon('target', 'sm') ?> Estado de <?= $f['project_id'] ? 'el proyecto' : 'los proyectos' ?></span>
      <?php if ($f['project_id'] && $d['projects']): ?><span class="value an-value-sm"><?= e(Analysis::RISK[$d['projects'][0]['level']][0]) ?></span>
      <?php else: ?><span class="value"><?= (int)($levels['high'] ?? 0) ?> <small class="muted">elevado</small></span><span class="small muted"><?= (int)($levels['moderate'] ?? 0) ?> moderado · <?= (int)($levels['low'] ?? 0) ?> bajo · <?= (int)($levels['nodata'] ?? 0) ?> sin datos</span><?php endif; ?>
    </a>
    <a class="card stat<?= $i['ontime']['value'] !== null && $i['ontime']['value'] < 70 ? ' warn' : '' ?>" href="<?= e($link('trends')) ?>" data-kpi="ontime">
      <span class="label"><?= icon('check', 'sm') ?> Cumplimiento de plazos</span><span class="value"><?= e($an_pct($i['ontime']['value'])) ?></span>
      <span class="small muted"><?= $i['ontime']['den'] ? (int)$i['ontime']['num'] . ' de ' . (int)$i['ontime']['den'] . ' a tiempo' : 'Sin tareas finalizadas con fecha' ?></span>
    </a>
    <a class="card stat<?= $week0 && $week0['gap'] !== null && $week0['gap'] > 0.05 ? ' danger' : ($maxGap !== null && $maxGap > 0.05 ? ' warn' : '') ?>" href="<?= e($link('planning')) ?>" data-kpi="capacity">
      <span class="label"><?= icon('users', 'sm') ?> Capacidad proyectada</span>
      <?php if ($week0 && $week0['gap'] !== null): ?><span class="value"><?= e($an_h($week0['gap'] > 0 ? -$week0['gap'] : $week0['capacity'] - $week0['planned'])) ?></span><span class="small muted"><?= $week0['gap'] > 0.05 ? 'por encima de la capacidad esta semana' : 'de margen esta semana' ?><?= $maxGap > 0.05 && $week0['gap'] <= 0.05 ? ' · sobrecarga en semanas próximas' : '' ?></span>
      <?php else: ?><span class="value an-value-sm">Sin datos</span><span class="small muted">Nadie tiene horas semanales configuradas</span><?php endif; ?>
    </a>
    <a class="card stat<?= $riskCount ? ' warn' : '' ?>" href="#an-risks" data-kpi="risks"><span class="label"><?= icon('flag', 'sm') ?> Riesgos relevantes</span><span class="value"><?= (int)$riskCount ?></span><span class="small muted">proyectos y sprints con riesgo moderado o elevado</span></a>
  </div>
  <?php $mv = $i['movement'] ?? null; if ($mv): ?>
  <section class="card mb" data-movement><div class="card-head"><h3><?= icon('clock') ?> ¿Qué ocurrió en el período?</h3><span class="muted small"><?= e($periodTxt) ?></span></div><div class="card-body">
    <div class="grid grid-4 stats an-movement">
      <div class="stat"><span class="label">Proyectos</span><span class="value an-value-sm" data-mv="projects"><?= (int)$mv['projects_started'] ?> iniciados · <?= (int)$mv['projects_finished'] ?> finalizados</span></div>
      <div class="stat"><span class="label">Trabajos</span><span class="value an-value-sm" data-mv="tasks"><?= (int)$mv['created'] ?> creados · <?= (int)$mv['done'] ?> finalizados</span><span class="small muted">Hoy: <?= (int)$mv['open'] ?> pendientes, <?= (int)$mv['overdue'] ?> vencidos</span></div>
      <div class="stat"><span class="label">Horas registradas</span><span class="value an-value-sm" data-mv="hours"><?= e($an_h($mv['hours'])) ?></span><span class="small muted"><?= e($an_h($mv['unplanned_hours'])) ?> en imprevistos<?= $mv['quick_hours'] ? ' · ' . e($an_h($mv['quick_hours'])) . ' en trabajos rápidos' : '' ?></span></div>
      <div class="stat"><span class="label">Acordado frente a real</span><span class="value an-value-sm" data-mv="estimates"><?= $mv['compared'] ? e($an_h($mv['agreed'])) . ' → ' . e($an_h($mv['real'])) : 'Sin datos' ?></span><span class="small muted"><?= (int)$mv['compared'] ?> trabajos finalizados con horas acordadas</span></div>
    </div>
    <?php if ($mv['by_project']): $maxH = max(array_column($mv['by_project'], 'hours')) ?: 1; ?>
      <h4 class="small-title mt">¿En qué se usaron las horas?</h4>
      <ul class="an-bars" data-mv="by-project"><?php foreach ($mv['by_project'] as $bp): ?><li><span class="truncate"><?= e($bp['name']) ?></span><span class="an-bar"><span style="width:<?= round($bp['hours'] / $maxH * 100, 1) ?>%"></span></span><span class="small"><?= e($an_h($bp['hours'])) ?></span></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if ($mv['unplanned_created']): ?><p class="small muted"><?= (int)$mv['unplanned_created'] ?> trabajos imprevistos o urgentes se crearon en el período: no estaban en la planificación original.</p><?php endif; ?>
  </div></section>
  <?php endif; ?>
  <p class="muted small mb">Período analizado: <?= e($periodTxt) ?> · <?= (int)$i['finished'] ?> tareas finalizadas. <?= $cached ? 'Resultado guardado; se recalcula en cuanto cambian los datos.' : 'Calculado ahora.' ?></p>

  <section class="card mb" id="an-risks"><div class="card-head"><h3><?= icon('flag') ?> Riesgos relevantes</h3><span class="muted small">Clasificación por reglas configurables. No es una probabilidad.</span></div>
    <ul class="list an-risks">
      <?php $shown = 0; foreach ($d['projects'] as $p) { if (!in_array($p['level'], ['high', 'moderate'], true)) continue; $shown++; echo $an_riskItem($p['project']['name'], url('/projects/' . $p['project']['id']), $p); }
      foreach ($d['sprints'] as $s) { if (!in_array($s['eval']['level'], ['high', 'moderate'], true)) continue; $shown++;
        echo $an_riskItem($s['sprint']['project_name'] . ' · ' . $s['sprint']['name'], url('/projects/' . $s['sprint']['project_id'] . '/sprints'), $s['eval'], $s['canSim'] ? '<a class="btn btn--sm" href="' . e($link('scenarios', ['sprint_id' => $s['sprint']['id']])) . '">' . icon('switch', 'sm') . ' Explorar escenarios</a>' : ''); }
      if (!$shown): ?><li class="empty">Ningún proyecto ni sprint presenta riesgo moderado o elevado.</li><?php endif; ?>
      <?php $nod = count(array_filter($d['projects'], fn($p) => $p['level'] === 'nodata')) + count(array_filter($d['sprints'], fn($s) => $s['eval']['level'] === 'nodata')); if ($nod): ?><li class="muted small"><?= $nod ?> <?= $nod === 1 ? 'proyecto o sprint no se puede evaluar' : 'proyectos o sprints no se pueden evaluar' ?> por falta de datos (estimaciones, fechas u horas disponibles). <a href="<?= e($link('planning')) ?>">Ver qué falta</a></li><?php endif; ?>
    </ul>
  </section>

  <div class="grid grid-2 mb">
    <section class="card"><div class="card-head"><h3><?= icon('timeline') ?> Tendencias</h3><a class="btn btn--sm btn--ghost" href="<?= e($link('trends')) ?>">Ver detalle</a></div><div class="card-body">
      <p class="small"><strong>Cumplimiento de plazos</strong></p><?= $an_trend($d['trend']['ontime'], true) ?>
      <p class="small"><strong>Desviación de estimaciones</strong></p><?= $an_trend($d['trend']['deviation'], false) ?>
    </div></section>
    <section class="card"><div class="card-head"><h3><?= icon('repeat') ?> Problemas recurrentes</h3></div>
      <ul class="list an-patterns">
        <?php foreach ($d['patterns'] as $pt): ?><li data-pattern="<?= e($pt['kind']) ?>"><div><strong class="small"><?= e($pt['title']) ?></strong><p class="small"><?= e($pt['text']) ?></p><p class="muted small"><?= e($pt['caveat']) ?> Confianza: <?= e($pt['confidence']) ?> (<?= (int)$pt['n'] ?> registros, <?= e($periodTxt) ?>).</p></div></li><?php endforeach; ?>
        <?php if (!$d['patterns']): ?><li class="empty" data-pattern="none">No hay datos suficientes para identificar patrones confiables en este período (hacen falta al menos <?= (int)$cfg['min_sample'] ?> registros comparables).</li><?php endif; ?>
      </ul></section>
  </div>

  <section class="card mb"><div class="card-head"><h3><?= icon('check-circle') ?> Recomendaciones</h3><?php if ($canManageAny): ?><a class="btn btn--sm" href="<?= e($link('scenarios')) ?>"><?= icon('switch', 'sm') ?> Abrir el simulador</a><?php endif; ?></div>
    <?php if ($d['recs']): ?><ul class="an-recs"><?php foreach (array_slice($d['recs'], 0, 8) as $r) echo $an_rec($r); ?></ul>
    <?php else: ?><p class="empty">Sin recomendaciones: ninguna regla detecta un problema con los datos disponibles.</p><?php endif; ?>
  </section>

  <section class="card mb"><details class="an-quality" <?= array_filter($d['quality'], fn($q) => !empty($q['insufficient'])) ? 'open' : '' ?>><summary class="card-head"><h3><?= icon('database') ?> Calidad de los datos</h3><span class="muted small">Qué falta para que los análisis sean fiables</span></summary>
    <ul class="list"><?php foreach ($d['quality'] as $q): ?><li data-quality="<?= e($q['key']) ?>"><span class="badge <?= !empty($q['info']) ? (!empty($q['insufficient']) ? 'warn' : 'success') : ($q['n'] ? 'warn' : 'success') ?>"><?= (int)$q['n'] ?></span>
      <div style="flex:1"><strong class="small"><?= e($q['label']) ?></strong><p class="muted small"><?= e($q['why']) ?></p>
      <?php if ($q['examples']): ?><p class="small"><?php foreach ($q['examples'] as $x): ?><button class="btn btn--sm btn--ghost" type="button" data-open-task="<?= e($x['task_key']) ?>" title="<?= e($x['title']) ?>"><?= e($x['task_key']) ?></button> <?php endforeach; ?></p><?php endif; ?></div></li><?php endforeach; ?></ul>
  </details></section>

<?php elseif ($tab === 'trends'): $i = $d['ind']; $tr = $d['trends']; ?>
  <section class="card mb"><div class="card-head"><h3><?= icon('chart') ?> Indicadores del período</h3><span class="muted small"><?= e($periodTxt) ?></span></div>
    <div class="table-wrap"><table class="table an-table" data-indicators><thead><tr><th>Indicador</th><th>Valor</th><th>Registros</th><th>Excluidos</th></tr></thead><tbody>
      <tr data-ind="ontime"><td><?= e(Metrics::DEFINITIONS['ontime'][0]) ?><?= $an_def('ontime', (int)$i['ontime']['num'] . ' a tiempo ÷ ' . (int)$i['ontime']['den'] . ' con fecha válida') ?></td><td><strong><?= e($an_pct($i['ontime']['value'])) ?></strong></td><td><?= (int)$i['ontime']['num'] ?> de <?= (int)$i['ontime']['den'] ?></td><td><?= (int)$i['ontime']['excluded'] ?> sin fecha límite registrada</td></tr>
      <tr data-ind="late"><td><?= e(Metrics::DEFINITIONS['late'][0]) ?><?= $an_def('late') ?></td><td><strong><?= e($an_pct($i['late']['value'])) ?></strong></td><td><?= (int)$i['late']['num'] ?> de <?= (int)$i['late']['den'] ?></td><td><?= (int)$i['late']['excluded'] ?></td></tr>
      <tr data-ind="deviation"><td><?= e(Metrics::DEFINITIONS['deviation'][0]) ?><?= $an_def('deviation', 'Rango habitual (P25–P75): ' . e($an_pct($i['deviation']['p25'], true)) . ' a ' . e($an_pct($i['deviation']['p75'], true))) ?></td><td><strong><?= e($an_pct($i['deviation']['median_pct'], true)) ?></strong><br><span class="muted small"><?= e($an_h($i['deviation']['median_hours'], true)) ?> por tarea</span></td><td><?= (int)$i['deviation']['n'] ?> tareas</td>
        <td class="small"><?= (int)$i['deviation']['excluded']['no_estimate'] ?> sin estimación · <?= (int)$i['deviation']['excluded']['no_logged'] ?> sin horas · <?= (int)$i['deviation']['excluded']['small'] ?> con estimación menor de <?= e($an_h((float)$cfg['deviation_min_hours'])) ?></td></tr>
      <tr data-ind="reprogram"><td><?= e(Metrics::DEFINITIONS['reprogram'][0]) ?><?= $an_def('reprogram') ?></td><td><strong><?= $i['reprogram']['rate'] !== null ? e(str_replace('.', ',', (string)$i['reprogram']['rate'])) : '—' ?></strong> <span class="muted small">cambios por tarea</span></td><td><?= (int)$i['reprogram']['changes'] ?> cambios en <?= (int)$i['reprogram']['tasks'] ?> tareas con fecha (<?= (int)$i['reprogram']['reprogrammed'] ?> reprogramadas)</td><td>—</td></tr>
      <tr data-ind="blocked"><td><?= e(Metrics::DEFINITIONS['blocked'][0]) ?><?= $an_def('blocked') ?></td><td><strong><?= $i['blocks']['median_days'] !== null ? e(str_replace('.', ',', (string)$i['blocks']['median_days'])) . ' días' : '—' ?></strong></td><td><?= (int)$i['blocks']['closed'] ?> bloqueos resueltos</td><td><?= count($i['blocks']['open']) ?> abiertos<?= $i['blocks']['unknown_start'] ? ' · ' . (int)$i['blocks']['unknown_start'] . ' sin inicio registrado' : '' ?></td></tr>
    </tbody></table></div>
    <?php if (!$i['enough']): ?><p class="muted small">Con menos de <?= (int)$i['min_sample'] ?> tareas finalizadas con fecha, estos valores son poco representativos.</p><?php endif; ?>
  </section>

  <div class="grid grid-2 mb">
    <?php foreach (['ontime' => ['Cumplimiento de plazos por ' . ($tr['weekly'] ? 'semana' : 'mes'), true, '%'], 'deviation' => ['Desviación de estimaciones (mediana)', false, '%']] as $k => [$title, $better, $unit]): ?>
    <section class="card" data-series="<?= e($k) ?>"><div class="card-head"><h3><?= e($title) ?></h3></div><div class="card-body">
      <?= $an_trend($tr['trend'][$k], $better) ?>
      <div class="bars"><?php foreach ($tr['series'][$k] as $b): $v = $b['value']; $w = $v === null ? 0 : min(100, abs($v)); ?>
        <div class="bar-row"><span class="truncate small"><?= e($b['label']) ?></span><span class="track"><span class="fill" style="--w:<?= $w ?>%;--c:<?= $v !== null && $b['n'] < $cfg['min_sample'] ? 'var(--text-4, #B8C2BB)' : 'var(--aurora)' ?>"></span></span><span class="n small" title="<?= (int)$b['n'] ?> registros"><?= $v === null ? '—' : e($an_pct($v, $k === 'deviation')) ?></span></div>
      <?php endforeach; ?></div>
      <p class="muted small">Barras grises: menos de <?= (int)$cfg['min_sample'] ?> registros en ese período (no cuentan para la tendencia).</p>
    </div></section>
    <?php endforeach; ?>
  </div>

  <section class="card mb" data-backlog><div class="card-head"><h3><?= icon('layers') ?> Evolución del trabajo pendiente</h3><?= $an_def('backlog') ?></div><div class="card-body">
    <?php $bl = $i['backlog']; $mx = max(1, ...array_map(fn($p) => $p['pending'], $bl ?: [['pending' => 1]])); ?>
    <div class="bars"><?php foreach (array_slice($bl, -16) as $p): ?><div class="bar-row"><span class="small">Sem. <?= e(fmt_date($p['week'])) ?></span><span class="track"><span class="fill" style="--w:<?= round($p['pending'] / $mx * 100) ?>%"></span></span><span class="n small" title="+<?= (int)$p['created'] ?> creadas, −<?= (int)$p['done'] ?> finalizadas"><?= (int)$p['pending'] ?></span></div><?php endforeach; ?></div>
    <?php if (!$bl): ?><p class="empty">Sin tareas en el período.</p><?php endif; ?>
  </div></section>

  <section class="card mb"><div class="card-head"><h3><?= icon('repeat') ?> Patrones detectados</h3></div>
    <ul class="list an-patterns"><?php foreach ($d['patterns'] as $pt): ?><li data-pattern="<?= e($pt['kind']) ?>"><div><strong class="small"><?= e($pt['title']) ?></strong><p class="small"><?= e($pt['text']) ?></p><p class="muted small"><?= e($pt['caveat']) ?> Confianza: <?= e($pt['confidence']) ?> · <?= (int)$pt['n'] ?> registros · <?= e($periodTxt) ?>.</p></div></li><?php endforeach; ?>
      <?php if (!$d['patterns']): ?><li class="empty" data-pattern="none">No hay datos suficientes para identificar una tendencia confiable.</li><?php endif; ?></ul>
  </section>

  <section class="card mb" data-sprint-compare><div class="card-head"><h3><?= icon('zap') ?> Sprints cerrados</h3><?= $an_def('sprint') ?></div>
    <?php $sc = $d['sprints']; if ($sc['rows']): ?>
    <div class="table-wrap"><table class="table an-table"><thead><tr><th>Sprint</th><th>Comprometido</th><th>Añadido después</th><th>Completado</th><th>Trasladado / backlog</th><th>Cumplimiento</th></tr></thead><tbody>
      <?php foreach ($sc['rows'] as $s): $c = $s['c']; ?><tr><td><?= e($s['name']) ?><?= $f['project_id'] ? '' : '<br><span class="muted small">' . e($s['project_name']) . '</span>' ?><br><span class="muted small"><?= e(fmt_date($s['start'])) ?> – <?= e(fmt_date($s['end'])) ?></span></td>
        <?php if ($s['source'] === 'commitment'): ?><td><?= (int)$c['committed'] ?> tareas · <?= e($an_h($c['committed_hours'])) ?></td><td><?= (int)$c['added'] ?><?= $c['added'] ? ' · ' . e($an_h($c['added_hours'])) : '' ?></td><td><?= (int)$c['committed_done'] ?> comprometidas<?= $c['added_done'] ? ' + ' . (int)$c['added_done'] . ' añadidas' : '' ?></td><td><?= (int)$c['moved'] ?> / <?= (int)$c['backlog'] ?></td>
        <?php elseif ($s['source'] === 'close'): ?><td><?= (int)$s['committed_count'] ?> tareas</td><td class="muted">sin dato</td><td><?= (int)$s['completed_count'] ?> <span class="muted small">(incluye lo añadido)</span></td><td class="muted">—</td>
        <?php else: ?><td colspan="4" class="muted">Sin dato de compromiso</td><?php endif; ?>
        <td><strong><?= e($an_pct($s['rate']['value'])) ?></strong><?= $s['source'] === 'close' ? ' <span class="badge">aproximado</span>' : '' ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <div class="card-body">
      <?php if ($sc['trend']): ?><?= $an_trend($sc['trend'], true) ?><p class="muted small">Una mejora del porcentaje no significa que el equipo sea más productivo: el alcance, la complejidad y las estimaciones cambian entre sprints.</p>
      <?php else: ?><p class="muted small">Elige un proyecto para comparar sus sprints entre sí (solo se comparan sprints del mismo proyecto).</p><?php endif; ?>
      <?php if ($sc['reference']): $rf = $sc['reference']; ?><p class="small" data-reference><?= $an_tag('estimate') ?> Para planificar el próximo sprint: en los últimos <?= (int)$rf['n'] ?> sprints se comprometieron de mediana <?= e($an_h($rf['committed_hours'])) ?> y se completaron <?= e($an_h($rf['done_hours'])) ?> de lo comprometido (<?= e($an_pct($rf['rate'])) ?>).</p><?php endif; ?>
    </div>
    <?php else: ?><p class="empty">No hay sprints cerrados en el período.</p><?php endif; ?>
  </section>

<?php elseif ($tab === 'estimates'): $dv = $d['ind']['deviation']; ?>
  <div class="grid grid-2 mb">
    <section class="card"><div class="card-head"><h3><?= icon('clock') ?> Estimado frente a registrado</h3></div><div class="card-body">
      <?php if ($dv['n']): ?><p><?= $an_tag('fact') ?> Mediana de desviación <strong data-dev-median><?= e($an_pct($dv['median_pct'], true)) ?></strong> (<?= e($an_h($dv['median_hours'], true)) ?> por tarea) en <?= (int)$dv['n'] ?> tareas finalizadas entre el <?= e($periodTxt) ?>.</p>
        <p class="small">Rango habitual (la mitad central de las tareas): de <?= e($an_pct($dv['p25'], true)) ?> a <?= e($an_pct($dv['p75'], true)) ?>.</p>
      <?php else: ?><p class="empty">No hay tareas finalizadas con horas estimadas y registradas en el período.</p><?php endif; ?>
      <p class="muted small">Quedan fuera <?= (int)$dv['excluded']['no_estimate'] ?> sin estimación, <?= (int)$dv['excluded']['no_logged'] ?> sin horas registradas (registro incompleto) y <?= (int)$dv['excluded']['small'] ?> con estimaciones muy pequeñas. Se comparan horas de trabajo registradas, no la duración en el calendario.</p>
      <?= $an_def('deviation') ?>
    </div></section>
    <section class="card" data-estimate-try><div class="card-head"><h3><?= icon('target') ?> Probar una sugerencia</h3></div><div class="card-body">
      <?php if ($f['project_id']): ?>
        <div class="form-row"><div class="field"><label for="an-try-l">Etiquetas</label><select class="select" id="an-try-l" multiple size="3"><?php foreach ($labels as $l): ?><option value="<?= (int)$l['id'] ?>"><?= e($l['name']) ?></option><?php endforeach; ?></select><span class="hint">Sin etiqueta = se compara con tareas sin etiqueta.</span></div>
          <div class="field"><label for="an-try-h">Tu estimación</label><span class="input-unit"><input class="input hours-input" type="number" min="0" step="0.5" id="an-try-h" inputmode="decimal" placeholder="Opcional"><span class="muted small">horas</span></span></div></div>
        <button class="btn" type="button" data-estimate-suggest data-project="<?= (int)$f['project_id'] ?>" data-for="#an-try-h" data-labels-from="#an-try-l"><?= icon('target', 'sm') ?> Consultar el historial</button>
        <div data-estimate-out></div>
      <?php else: ?><p class="muted">Elige un proyecto arriba: las sugerencias solo usan tareas del mismo proyecto.</p><?php endif; ?>
      <p class="muted small">La misma sugerencia aparece al crear o editar una tarea, junto al tiempo estimado. Es opcional y nunca cambia las horas por sí sola.</p>
    </div></section>
  </div>
  <section class="card mb" data-categories><div class="card-head"><h3><?= icon('tag') ?> Por categoría (etiqueta)</h3><span class="muted small">Solo se comparan tareas del mismo proyecto y la misma etiqueta.</span></div>
    <div class="table-wrap"><table class="table an-table"><thead><tr><th>Categoría</th><th>Tareas</th><th>Estimado / registrado</th><th>Desviación mediana</th><th></th></tr></thead><tbody>
      <?php foreach ($d['cats'] as $c): ?><tr<?= $c['flag'] ? ' class="an-flag"' : '' ?>><td><?= e($c['label']) ?><?= $f['project_id'] ? '' : '<br><span class="muted small">' . e($c['project']) . '</span>' ?></td><td><?= (int)$c['n'] ?></td><td><?= e($an_h($c['estimate'])) ?> / <?= e($an_h($c['logged'])) ?></td>
        <td><?= $c['enough'] ? '<strong>' . e($an_pct($c['median_pct'], true)) . '</strong> <span class="muted small">(' . e($an_h($c['median_hours'], true)) . ')</span>' : '<span class="muted small">Muestra insuficiente (' . (int)$c['n'] . ' de ' . (int)$cfg['min_sample'] . ')</span>' ?></td>
        <td><?= $c['flag'] ? '<span class="badge warn">Suele superar su estimación</span>' : '' ?></td></tr><?php endforeach; ?>
      <?php if (!$d['cats']): ?><tr><td colspan="5" class="empty">Sin tareas comparables en el período.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>

<?php elseif ($tab === 'planning'): $cap = $d['cap']; ?>
  <section class="card mb" data-capacity><div class="card-head"><h3><?= icon('users') ?> Capacidad de las próximas <?= count($cap['weeks']) ?> semanas</h3><?= $an_def('capacity', 'Se suma' . ((int)$cap['count'] === 1 ? ' 1 persona' : 'n ' . (int)$cap['count'] . ' personas') . ($f['project_id'] ? ' del proyecto' : ($f['team_id'] ? ' del equipo' : '')) . '. La carga incluye todos sus proyectos, porque cada persona tiene una sola capacidad.') ?></div>
    <div class="table-wrap"><table class="table an-table"><thead><tr><th>Semana</th><th>Capacidad</th><th>Carga planificada</th><th>Diferencia</th><th></th></tr></thead><tbody>
      <?php foreach ($cap['weeks'] as $w): ?><tr data-week="<?= e($w['week']) ?>"><td><?= e(fmt_date($w['week'], true)) ?><br><span class="muted small"><?= (int)$w['people'] ?> con horas configuradas</span></td><td><?= e($an_h($w['capacity'])) ?></td><td><?= e($an_h($w['planned'])) ?></td>
        <td><?= $w['gap'] === null ? '—' : '<strong data-gap="' . e((string)$w['gap']) . '">' . e($an_h($w['gap'], true)) . '</strong>' ?></td><td><span class="badge <?= Monitoring::LOAD[$w['class']][1] ?>"><?= e(Monitoring::LOAD[$w['class']][0]) ?></span></td></tr>
        <?php if ($w['message'] || $w['no_capacity']): ?><tr class="an-subrow"><td colspan="5">
          <?php if ($w['message']): ?><p class="an-warning" data-overload><?= icon('alert', 'sm') ?> <?= e($w['message']) ?></p>
            <details class="an-why" open><summary>Alternativas de reorganización</summary><ul class="small"><?php foreach (\Borealis\Services\Analysis\Planning::ALTERNATIVES as $alt): ?><li><?= e($alt) ?></li><?php endforeach; ?></ul>
            <?php if ($w['tasks']): ?><p class="small">Tareas que más pesan esa semana: <?php foreach (array_slice($w['tasks'], 0, 6) as $t): ?><button class="btn btn--sm btn--ghost" type="button" data-open-task="<?= e($t['key']) ?>"><?= e($t['key']) ?> · <?= e($an_h($t['hours'])) ?></button> <?php endforeach; ?></p><?php endif; ?>
            <p class="muted small">No se propone ampliar la jornada. La disponibilidad no se deduce de las horas registradas: se usa la capacidad configurada.</p></details><?php endif; ?>
          <?php if ($w['no_capacity']): ?><p class="muted small">Sin horas semanales configuradas (no cuentan): <?= e(implode(', ', array_unique($w['no_capacity']))) ?>.</p><?php endif; ?>
        </td></tr><?php endif; ?>
      <?php endforeach; ?>
      <?php if (!$cap['weeks']): ?><tr><td colspan="5" class="empty">No hay personas en este filtro.</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if ($cap['no_estimate'] || $cap['no_date']): ?><p class="muted small card-body">No suman horas: <?= count($cap['no_estimate']) ?> tareas sin estimación<?= $cap['no_estimate'] ? ' (' . e(implode(', ', array_slice($cap['no_estimate'], 0, 8))) . (count($cap['no_estimate']) > 8 ? '…' : '') . ')' : '' ?> y <?= count($cap['no_date']) ?> sin fecha ni sprint.</p><?php endif; ?>
    <?php if (count($cap['people']) > 1): ?>
    <details class="card-body"><summary class="small">Por persona (solo quienes ya ves en Monitoreo › Carga)</summary>
      <div class="table-wrap"><table class="table an-table small"><thead><tr><th>Persona</th><?php foreach ($cap['weeks'] as $w): ?><th><?= e(fmt_date($w['week'])) ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach ($cap['people'] as $p): ?><tr><td><?= e($p['user']['name']) ?></td><?php foreach ($p['weeks'] as $c): ?><td><?= e($an_h($c['planned'])) ?> / <?= $c['capacity'] === null ? '—' : e($an_h($c['capacity'])) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
      </tbody></table></div><p class="muted small">Carga / capacidad. Sirve para redistribuir trabajo, no para comparar personas.</p></details>
    <?php endif; ?>
  </section>
  <section class="card mb"><div class="card-head"><h3><?= icon('zap') ?> Sprints activos y planificados</h3><span class="muted small">Lo pendiente frente a la capacidad que queda en cada sprint.</span></div>
    <ul class="list an-risks"><?php foreach ($d['sprints'] as $s): $ev = $s['eval'];
      $extra = '<p class="small muted">' . $an_tag('fact') . ' Pendiente: ' . e($an_h($ev['pending'])) . ' · capacidad restante: ' . e($an_h($ev['capacity_left'])) . ' · margen: ' . e($an_h($ev['margin'], true)) . ($ev['unestimated'] ? ' · ' . (int)$ev['unestimated'] . ' sin estimar' : '') . '</p>'
        . ($s['canSim'] ? '<a class="btn btn--sm" href="' . e($link('scenarios', ['sprint_id' => $s['sprint']['id']])) . '">' . icon('switch', 'sm') . ' Explorar escenarios</a>' : '');
      echo $an_riskItem($s['sprint']['project_name'] . ' · ' . $s['sprint']['name'] . ($s['sprint']['state'] === 'planned' ? ' (planificado)' : ''), url('/projects/' . $s['sprint']['project_id'] . '/sprints'), $ev, $extra); endforeach; ?>
      <?php if (!$d['sprints']): ?><li class="empty">No hay sprints activos ni planificados.</li><?php endif; ?></ul>
  </section>
  <section class="card mb"><div class="card-head"><h3><?= icon('folder') ?> Proyectos</h3></div>
    <ul class="list an-risks"><?php foreach ($d['projects'] as $p) echo $an_riskItem($p['project']['name'], url('/projects/' . $p['project']['id']), $p); ?>
      <?php if (!$d['projects']): ?><li class="empty">No participas en ningún proyecto activo.</li><?php endif; ?></ul>
  </section>

<?php elseif ($tab === 'scenarios'): ?>
  <?php if (!$simSprints): ?>
    <section class="card mb"><p class="empty">Los escenarios están disponibles para quien gestiona un proyecto con sprints activos o planificados. <?= $canManageAny ? 'Tus proyectos no tienen sprints abiertos.' : 'Pide a quien lleva el proyecto que los explore contigo.' ?></p></section>
  <?php else: ?>
  <form class="toolbar" method="get" action="<?= e(url('/analysis')) ?>">
    <?php if (!config('app.pretty_urls', true)): ?><input type="hidden" name="r" value="/analysis"><?php endif; ?>
    <input type="hidden" name="tab" value="scenarios">
    <label for="an-sim-s" class="small">Sprint</label>
    <select class="select" id="an-sim-s" name="sprint_id" data-autosubmit><?php foreach ($simSprints as $s): ?><option value="<?= (int)$s['id'] ?>"<?= selected($s['id'], $sim['sprint']['id'] ?? null) ?>><?= e($s['project_name']) ?> · <?= e($s['name']) ?><?= $s['state'] === 'active' ? ' · activo' : ' · planificado' ?></option><?php endforeach; ?></select>
  </form>
  <?php if (!empty($sim)): $snap = $sim['snap']; $res = $sim['result']; ?>
  <div id="an-sim" data-sprint="<?= (int)$sim['sprint']['id'] ?>" data-fingerprint="<?= e($res['fingerprint']) ?>">
    <p class="muted small mb">Cambia variables y pulsa «Simular». Nada se guarda hasta que apliques el escenario y lo confirmes. El simulador es determinista: recalcula capacidad, fechas y riesgo con las mismas reglas que Planificación; no recalcula en cascada las dependencias.</p>
    <div class="grid an-sim-grid mb">
      <section class="card"><div class="card-head"><h3><?= icon('list') ?> Tareas abiertas del sprint</h3></div>
        <div class="table-wrap"><table class="table an-table an-sim-tasks small"><thead><tr><th>Tarea</th><th>Trasladar a</th><th>Estimación</th><th>Vence</th><th>Responsable</th><th>Prioridad</th></tr></thead><tbody>
          <?php foreach ($snap['tasks'] as $t): if ($t['done']) continue; $pre = in_array($t['id'], $sim['premove'], true); ?>
          <tr data-task="<?= (int)$t['id'] ?>" data-orig-est="<?= e((string)$t['estimate']) ?>" data-orig-due="<?= e((string)$t['due']) ?>" data-orig-user="<?= (int)($t['people'][0] ?? 0) ?>" data-orig-prio="<?= (int)$t['priority_id'] ?>">
            <td><button class="btn btn--sm btn--ghost" type="button" data-open-task="<?= e($t['key']) ?>"><?= e($t['key']) ?></button> <span class="truncate" title="<?= e($t['title']) ?>"><?= e(mb_strimwidth($t['title'], 0, 40, '…')) ?></span>
              <br><span class="muted"><?= e($an_h(Metrics::remaining($t['estimate'], $t['remaining_set'], $t['logged']))) ?> pendientes<?= $t['blocked'] ? ' · <span class="badge danger">bloqueada</span>' : '' ?></span>
              <?php if ($t['blocked']): ?><label class="check small"><input type="checkbox" data-sim="unblock"> Resolver el bloqueo (hipotético)</label><?php endif; ?></td>
            <td><select class="select sm" data-sim="move"><option value="">— se queda —</option><option value="backlog"<?= $pre && !$sim['targets'] ? ' selected' : '' ?>>Backlog</option><?php foreach ($sim['targets'] as $i2 => $tg): ?><option value="<?= (int)$tg['id'] ?>"<?= $pre && $i2 === 0 ? ' selected' : '' ?>><?= e($tg['name']) ?></option><?php endforeach; ?></select></td>
            <td><input class="input sm ts-num" type="number" min="0" step="0.5" data-sim="estimate" value="<?= e((string)$t['estimate']) ?>" placeholder="—" aria-label="Estimación de <?= e($t['key']) ?>"></td>
            <td><input class="input sm" type="date" data-sim="due" value="<?= e((string)$t['due']) ?>" aria-label="Fecha límite de <?= e($t['key']) ?>"></td>
            <td><select class="select sm" data-sim="assign" aria-label="Responsable de <?= e($t['key']) ?>"><?php if (!$t['people']): ?><option value="0">Sin asignar</option><?php endif; ?><?php foreach ($snap['capacity'] as $uid => $c): ?><option value="<?= (int)$uid ?>"<?= selected($uid, $t['people'][0] ?? null) ?>><?= e($c['name']) ?></option><?php endforeach; ?></select><?= count($t['people']) > 1 ? '<br><span class="muted">+' . (count($t['people']) - 1) . ' más</span>' : '' ?></td>
            <td><select class="select sm" data-sim="priority" aria-label="Prioridad de <?= e($t['key']) ?>"><option value="0">—</option><?php foreach ($sim['priorities'] as $pr): ?><option value="<?= (int)$pr['id'] ?>"<?= selected($pr['id'], $t['priority_id']) ?>><?= e($pr['name']) ?></option><?php endforeach; ?></select></td>
          </tr><?php endforeach; ?>
        </tbody></table></div>
        <div class="card-body"><h4 class="small">Horas disponibles en el sprint</h4><div class="an-sim-cap">
          <?php foreach ($snap['capacity'] as $uid => $c): ?><label class="small" data-user="<?= (int)$uid ?>" data-orig="<?= e((string)$c['hours']) ?>"><?= e($c['name']) ?> <input class="input sm ts-num" type="number" min="0" step="0.5" data-sim="capacity" value="<?= e((string)$c['hours']) ?>" placeholder="—"> h</label><?php endforeach; ?>
        </div>
        <div class="row mt"><button class="btn btn--primary" type="button" data-sim-run><?= icon('switch', 'sm') ?> Simular</button><button class="btn btn--ghost" type="button" data-sim-reset>Volver al estado actual</button></div></div>
      </section>
      <section class="card" data-sim-result><?= partial('analysis-compare', ['res' => $res]) ?></section>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
  <?php if ($history): ?>
  <section class="card mb" data-sim-history><div class="card-head"><h3><?= icon('repeat') ?> Escenarios aplicados</h3><span class="muted small">Registro de auditoría: quién, cuándo y cada valor antes y después.</span></div>
    <ul class="list"><?php foreach ($history as $h): $ch = json_decode((string)$h['changes'], true) ?: []; ?><li data-scenario="<?= (int)$h['id'] ?>"><div style="flex:1"><strong class="small"><?= e($h['sprint_name'] ?? 'Sprint eliminado') ?></strong> · <span class="small"><?= e($h['user_name'] ?? '') ?>, <?= e(fmt_datetime((string)$h['created_at'])) ?></span>
      <ul class="small muted"><?php foreach ($ch as $c): ?><li><?= e(($c['key'] ?? 'Capacidad') . ': ' . ($c['field'] ?? 'horas') . ' ' . json_encode($c['before'], JSON_UNESCAPED_UNICODE) . ' → ' . json_encode($c['after'], JSON_UNESCAPED_UNICODE)) ?></li><?php endforeach; ?></ul></div>
      <?php if ($h['state'] === 'applied'): ?><button class="btn btn--sm" type="button" data-call="<?= e('/api/analysis/scenarios/' . (int)$h['id'] . '/revert') ?>" data-confirm="¿Revertir este escenario? Cada valor vuelve a como estaba antes de aplicarlo, si nadie lo ha cambiado después." data-confirm-label="Revertir" data-confirm-style="primary">Revertir</button>
      <?php else: ?><span class="badge">Revertido<?= $h['reverted_name'] ? ' por ' . e($h['reverted_name']) : '' ?></span><?php endif; ?></li><?php endforeach; ?></ul>
  </section>
  <?php endif; ?>

<?php elseif ($tab === 'report'): ?>
  <section class="card mb"><div class="card-body">
    <?php require __DIR__ . '/report-body.php'; ?>
  </div></section>
  <section class="card mb"><details><summary class="card-head"><h3><?= icon('doc') ?> Definiciones de los indicadores</h3></summary><div class="card-body">
    <?php foreach (Metrics::DEFINITIONS as $id => [$name, $formula, $counted, $excluded]): ?><div class="mb" data-definition="<?= e($id) ?>"><strong><?= e($name) ?></strong><p class="small"><?= e($formula) ?></p><p class="muted small">Se cuentan: <?= e($counted) ?> Quedan fuera: <?= e($excluded) ?></p></div><?php endforeach; ?>
  </div></details></section>
<?php endif; ?>
