<h1>Únete a <?= e($inv['workspace_name']) ?></h1>
<p class="sub">Invitación para <strong><?= e($inv['email']) ?></strong></p>
<?php if (!empty($errors['_'])): ?><div class="form-error" role="alert"><?= e($errors['_']) ?></div><?php endif; ?>
<form method="post" action="<?= e(url('/invite/' . $token)) ?>" data-plain-form>
  <?= csrf_field() ?>
  <?php if ($hasAccount): ?>
    <p>Ya tienes una cuenta en Borealis. Confirma tu contraseña para añadir este espacio a tu cuenta.</p>
    <div class="field"><label for="password">Contraseña</label><input class="input" type="password" id="password" name="password" required autocomplete="current-password"></div>
    <button class="btn btn--primary" type="submit" style="width:100%">Unirme</button>
  <?php else: ?>
    <div class="field"><label for="name">Tu nombre</label><input class="input" id="name" name="name" value="<?= e($values['name'] ?? '') ?>" required maxlength="120" autocomplete="name"><span class="error"><?= e($errors['name'] ?? '') ?></span></div>
    <div class="field"><label for="password">Contraseña</label><input class="input" type="password" id="password" name="password" required minlength="8" autocomplete="new-password"><span class="hint">Mínimo 8 caracteres.</span><span class="error"><?= e($errors['password'] ?? '') ?></span></div>
    <div class="field"><label for="password_confirm">Repite la contraseña</label><input class="input" type="password" id="password_confirm" name="password_confirm" required minlength="8" autocomplete="new-password"><span class="error"><?= e($errors['password_confirm'] ?? '') ?></span></div>
    <button class="btn btn--primary" type="submit" style="width:100%">Crear cuenta y unirme</button>
  <?php endif; ?>
</form>
