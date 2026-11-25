<h1>Nueva contraseña</h1>
<?php if (!$valid): ?>
  <p class="sub">Este enlace no es válido, ya venció o ya se usó.</p>
  <p><a class="btn btn--primary" href="<?= e(url('/forgot')) ?>">Pedir otro enlace</a></p>
<?php else: ?>
  <p class="sub">Elige una contraseña nueva para tu cuenta. Al guardarla se cerrarán tus otras sesiones abiertas.</p>
  <?php if (!empty($errors['_'])): ?><div class="form-error" role="alert"><?= e($errors['_']) ?></div><?php endif; ?>
  <form method="post" action="<?= e(url('/reset')) ?>" data-plain-form>
    <?= csrf_field() ?>
    <input type="hidden" name="t" value="<?= e($token) ?>">
    <div class="field"><label for="password">Contraseña nueva</label><input class="input" type="password" id="password" name="password" required minlength="8" autocomplete="new-password" autofocus><span class="hint">Mínimo 8 caracteres.</span><?php if (!empty($errors['password'])): ?><span class="error"><?= e($errors['password']) ?></span><?php endif; ?></div>
    <button class="btn btn--primary" type="submit" style="width:100%">Guardar contraseña</button>
  </form>
<?php endif; ?>
<p class="muted mt"><a href="<?= e(url('/login')) ?>">Volver a iniciar sesión</a></p>
