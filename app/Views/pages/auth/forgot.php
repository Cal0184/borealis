<h1>Recuperar contraseña</h1>
<?php if ($sent): ?>
  <p class="sub">Si <strong><?= e($email) ?></strong> tiene una cuenta en Borealis, en unos minutos llegará un correo con un enlace para elegir una contraseña nueva. Revisa también la carpeta de spam.</p>
  <p class="muted small">El enlace vale <?= \Borealis\Services\PasswordReset::MINUTES ?> minutos.</p>
<?php elseif (!$available): ?>
  <p class="sub">Este Borealis todavía no tiene el correo configurado, así que no puede enviarte un enlace.</p>
  <p>Pide a una persona administradora de tu espacio que restablezca tu contraseña (Administración › Usuarios).</p>
<?php else: ?>
  <p class="sub">Escribe el correo de tu cuenta y te enviaremos un enlace para elegir una contraseña nueva.</p>
  <?php if (!empty($errors['_'])): ?><div class="form-error" role="alert"><?= e($errors['_']) ?></div><?php endif; ?>
  <form method="post" action="<?= e(url('/forgot')) ?>" data-plain-form>
    <?= csrf_field() ?>
    <div class="field"><label for="email">Correo electrónico</label><input class="input" type="email" data-no-email-check id="email" name="email" value="<?= e($email) ?>" required autocomplete="email" autofocus></div>
    <button class="btn btn--primary" type="submit" style="width:100%">Enviar enlace</button>
  </form>
<?php endif; ?>
<p class="muted mt"><a href="<?= e(url('/login')) ?>">Volver a iniciar sesión</a></p>
