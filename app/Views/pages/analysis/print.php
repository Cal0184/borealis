<?php
/** Printable executive report (1.13): same data and permissions as Análisis › Informes. */
require BASE_PATH . '/app/Views/partials/analysis-bits.php';
$f = $filters;
$back = url('/analysis', array_filter(['tab' => 'report', 'project_id' => $f['project_id'], 'from' => $f['from'], 'to' => $f['to']]));
$pname = $f['project_id'] ? ($scope['rows'][$f['project_id']]['name'] ?? '') : 'Todos los proyectos visibles';
?>
<h1>Informe de análisis</h1>
<p class="meta"><?= e($pname) ?> · <?= e(fmt_date($f['from'], true)) ?> – <?= e(fmt_date($f['to'], true)) ?><?= $f['mine'] ? ' · Solo mis tareas' : '' ?></p>
<?php require __DIR__ . '/report-body.php'; ?>
