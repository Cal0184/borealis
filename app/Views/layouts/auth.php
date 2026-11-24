<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e(($title ?? 'Borealis') . ' · Borealis') ?></title>
<link rel="stylesheet" href="<?= e(asset('css/borealis.css')) ?>">
<script type="module" src="<?= e(asset('js/auth.js')) ?>"></script>
</head>
<body>
<div class="auth<?= !empty($wide) ? ' wide' : '' ?>">
  <div class="auth-art" aria-hidden="true">
    <div class="brand"><span class="brand-mark"><?= icon('tree') ?></span> Borealis</div>
    <p class="tagline">Organiza equipos y proyectos con <span>calma, claridad</span> y avance compartido.</p>
  </div>
  <main class="auth-panel">
    <?= $content ?>
  </main>
</div>
</body>
</html>
