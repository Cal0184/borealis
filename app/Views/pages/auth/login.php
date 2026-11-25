<h1>Hola de nuevo</h1>
<p class="sub">Inicia sesión para continuar con tu equipo.</p>
<?php if (!empty($notice)): ?><div class="notice"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($errors['_'])): ?><div class="form-error" role="alert"><?= e($errors['_']) ?></div><?php endif; ?>
<form method="post" action="<?= e(url('/login')) ?>" data-plain-form>
  <?= csrf_field() ?>
  <input type="hidden" name="next" value="<?= e($next) ?>">
  <div class="field"><label for="email">Correo electrónico</label><input class="input" type="email" data-no-email-check id="email" name="email" value="<?= e($email) ?>" required autocomplete="email" autofocus></div>
  <div class="field"><label for="password">Contraseña</label><input class="input" type="password" id="password" name="password" required autocomplete="current-password"></div>
  <button class="btn btn--primary" type="submit" style="width:100%">Iniciar sesión</button>
</form>
<?php if (config('app.allow_registration')): ?><p class="muted mt">¿Primera vez? <a href="<?= e(url('/register')) ?>">Crea tu espacio</a></p><?php endif; ?>
<p class="muted small mt"><a href="<?= e(url('/forgot')) ?>">¿Olvidaste tu contraseña?</a></p>
<?php if ($supportEmail = \Borealis\Services\Support::installationEmail()): ?><p class="auth-support"><a class="btn btn--ghost btn--sm" href="<?= e(\Borealis\Services\Support::mailto($supportEmail, '¿No puedo entrar a Borealis?', null, null, absolute_url('/login'))) ?>" data-support><?= icon('mail', 'sm') ?> Correo al soporte</a></p><?php endif; ?>
