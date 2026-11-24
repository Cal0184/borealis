<h1>Crea tu espacio</h1>
<p class="sub">Un espacio reúne a tus equipos y proyectos.</p>
<?php if (!empty($errors['_'])): ?><div class="form-error" role="alert"><?= e($errors['_']) ?></div><?php endif; ?>
<form method="post" action="<?= e(url('/register')) ?>" data-plain-form>
  <?= csrf_field() ?>
  <div class="field"><label for="name">Tu nombre</label><input class="input" id="name" name="name" value="<?= e($values['name'] ?? '') ?>" required maxlength="120" autocomplete="name"><span class="error"><?= e($errors['name'] ?? '') ?></span></div>
  <div class="field"><label for="email">Correo electrónico</label><input class="input" type="email" id="email" name="email" value="<?= e($values['email'] ?? '') ?>" required autocomplete="email"><span class="error"><?= e($errors['email'] ?? '') ?></span></div>
  <div class="field"><label for="password">Contraseña</label><input class="input" type="password" id="password" name="password" required minlength="8" autocomplete="new-password"><span class="hint">Mínimo 8 caracteres.</span><span class="error"><?= e($errors['password'] ?? '') ?></span></div>
  <div class="field"><label for="workspace">Nombre del espacio</label><input class="input" id="workspace" name="workspace" value="<?= e($values['workspace'] ?? '') ?>" required maxlength="120" placeholder="p. ej. Estudio Norte"><span class="error"><?= e($errors['workspace'] ?? '') ?></span></div>
  <button class="btn btn--primary" type="submit" style="width:100%">Crear espacio</button>
</form>
<p class="muted mt">¿Ya tienes cuenta? <a href="<?= e(url('/login')) ?>">Inicia sesión</a></p>
