<?php
/**
 * Análisis (1.13): small view helpers shared by the tabs, the report and the print view.
 * Defines closures in the including scope: $an_h, $an_pct, $an_risk, $an_tag, $an_def, $an_rec, $an_riskItem, $an_trend.
 */
use Borealis\Services\Analysis\Analysis;
use Borealis\Services\Analysis\Metrics;

$an_h = fn(?float $x, bool $sign = false) => Metrics::h($x, $sign);
$an_pct = fn(?float $x, bool $sign = false) => Metrics::pct($x, $sign);
$an_risk = fn(string $k) => '<span class="badge ' . Analysis::RISK[$k][1] . '" data-risk="' . e($k) . '">' . e(Analysis::RISK[$k][0]) . '</span>';
/** Hecho (dato registrado) / Estimación (cálculo con supuestos) / Recomendación (sugerencia). */
$an_tag = fn(string $k) => '<span class="an-tag an-tag--' . e($k) . '">' . e(['fact' => 'Hecho', 'estimate' => 'Estimación', 'rec' => 'Recomendación'][$k]) . '</span>';
/** «¿Cómo se calcula?» for one indicator, with the counted and excluded records of this view. */
$an_def = function (string $id, string $extra = '') {
    [$name, $formula, $counted, $excluded] = Metrics::DEFINITIONS[$id];
    return '<details class="an-why"><summary>¿Cómo se calcula?</summary><dl><dt>Fórmula</dt><dd>' . e($formula) . '</dd><dt>Se cuentan</dt><dd>' . e($counted) . '</dd><dt>Quedan fuera</dt><dd>' . e($excluded) . '</dd>'
        . ($extra !== '' ? '<dt>En esta vista</dt><dd>' . $extra . '</dd>' : '') . '</dl></details>';
};
$an_rec = function (array $r) use ($an_tag) {
    $sev = ['danger' => 'danger', 'warn' => 'warn', 'info' => 'info'][$r['severity']] ?? 'info';
    $h = '<li class="an-rec an-rec--' . $sev . '" data-rec="' . e($r['kind']) . '"><div class="an-rec-head">' . $an_tag('rec') . ' <strong>' . e($r['problem']) . '</strong></div>';
    $h .= '<dl class="an-rec-body"><dt>Evidencia</dt><dd><ul>' . implode('', array_map(fn($x) => '<li>' . e($x) . '</li>', $r['evidence'])) . '</ul></dd>';
    $h .= '<dt>Acción sugerida</dt><dd>' . e($r['action']) . '</dd>';
    if (!empty($r['impact'])) $h .= '<dt>Impacto esperado</dt><dd>' . $an_tag('estimate') . ' ' . e($r['impact']) . '</dd>';
    $h .= '<dt>Confianza</dt><dd>' . e($r['confidence']) . '</dd></dl><div class="row">';
    if (!empty($r['task_key'])) $h .= '<button class="btn btn--sm" type="button" data-open-task="' . e($r['task_key']) . '">Abrir ' . e($r['task_key']) . '</button>';
    if (!empty($r['link'])) $h .= '<a class="btn btn--sm" href="' . e($r['link']) . '">' . e($r['link_label'] ?? 'Abrir') . '</a>';
    $h .= '<span class="muted small">Nada cambia hasta que alguien con permiso lo decida.</span></div></li>';
    return $h;
};
/** A project or sprint evaluation: level, the sentence, and (on demand) every factor with its points. */
$an_riskItem = function (string $title, ?string $href, array $ev, ?string $extraHtml = '') use ($an_risk, $an_tag) {
    $h = '<li class="an-risk" data-level="' . e($ev['level']) . '"><div class="an-risk-head"><span class="an-risk-title">' . ($href ? '<a href="' . e($href) . '">' . e($title) . '</a>' : e($title)) . '</span> ' . $an_risk($ev['level']) . '</div>';
    $h .= '<p class="small an-risk-sentence">' . e($ev['sentence']) . '</p>';
    $h .= '<details class="an-why"><summary>Por qué</summary><ul class="an-factors">';
    foreach ($ev['factors'] as $f) $h .= '<li>' . $an_tag(!empty($f['estimate']) ? 'estimate' : 'fact') . ' ' . e($f['text']) . ' <span class="muted small">(+' . (int)$f['points'] . ')</span></li>';
    if (!$ev['factors']) $h .= '<li class="muted">Ninguna regla de riesgo se cumple.</li>';
    foreach ($ev['missing'] ?? [] as $m) $h .= '<li class="muted">Falta: ' . e($m) . '</li>';
    $cfg = Analysis::settings();
    $h .= '</ul><p class="muted small">Clasificación por reglas, no una probabilidad: ' . (int)$cfg['risk_moderate'] . ' puntos o más = moderado; ' . (int)$cfg['risk_elevated'] . ' o más = elevado. Total: ' . (int)array_sum(array_column($ev['factors'], 'points')) . '.</p></details>' . $extraHtml . '</li>';
    return $h;
};
$an_trend = function (array $t, bool $higherIsBetter, string $unit = 'puntos') {
    if ($t['dir'] === 'nodata') return '<p class="muted small" data-trend="nodata">' . e($t['text']) . ' (' . (int)$t['points'] . ' períodos con datos suficientes; hacen falta 3).</p>';
    $better = $t['dir'] === 'stable' ? null : (($t['dir'] === 'up') === $higherIsBetter);
    $word = $t['dir'] === 'stable' ? 'Estable' : ($better ? 'Mejora' : 'Empeora');
    return '<p class="small" data-trend="' . e($t['dir']) . '"><span class="badge ' . ($better === null ? '' : ($better ? 'success' : 'warn')) . '">' . $word . '</span> Cambio de ' . e(Metrics::pct($t['change'], true)) . ' ' . ($unit === 'puntos' ? '(puntos)' : '') . ' a lo largo de ' . (int)$t['points'] . ' períodos. <span class="muted">Describe lo ocurrido; no explica la causa.</span></p>';
};
