
<h1>Actualización pendiente</h1>
  <p class="sub">Se subieron los archivos de Borealis <?= e(BOREALIS_VERSION) ?>, pero la base de datos todavía es de la versión anterior. Falta aplicar: <strong><?= e(implode(', ', $pending)) ?></strong>.</p>
  <?php if ($error): ?><div class="form-error" role="alert" style="display:block"><?= e($error) ?></div><?php endif; ?>
  <?php if ($admin): ?>
    <p>Antes de cambiar nada se guarda una copia de seguridad completa en <code>storage/backups</code>. Tus datos se conservan.</p>
    <form method="post" action="<?= e(url('/update')) ?>">
      <?= csrf_field() ?>
      <button class="btn btn--primary" type="submit">Actualizar la base de datos ahora</button>
    </form>
    <p class="muted small" style="margin-top:12px">También puedes hacerlo por consola: <code>php bin/migrate.php</code></p>
  <?php else: ?>
    <p>La persona que administra la instalación debe entrar para completar la actualización. Mientras tanto Borealis no está disponible.</p>
  <?php endif; ?>
  <form method="post" action="<?= e(url('/logout')) ?>" style="margin-top:16px"><?= csrf_field() ?><button class="btn btn--ghost btn--sm" type="submit">Cerrar sesión</button></form>
