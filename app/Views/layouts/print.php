<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e(($title ?? 'Informe') . ' · Borealis') ?></title>
<link rel="stylesheet" href="<?= e(asset('css/print.css')) ?>">
<script type="module" src="<?= e(asset('js/print.js')) ?>"></script>
</head>
<body>
<div class="toolbar no-print">
  <a href="<?= e($back ?? url('/reports')) ?>">← Volver a Borealis</a>
  <span>Este informe está listo para imprimir. Para obtener un PDF elige «Guardar como PDF» en el cuadro de impresión.</span>
  <button type="button" data-print>Imprimir / Guardar PDF</button>
</div>
<main class="sheet">
<?= $content ?>
<footer class="foot">Generado con Borealis <?= e(BOREALIS_VERSION) ?> el <?= e(fmt_datetime(gmdate('Y-m-d H:i:s'))) ?> por <?= e(\Borealis\Core\Auth::user()['name'] ?? '') ?>.</footer>
</main>
</body>
</html>
