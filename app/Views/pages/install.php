<?php $err = fn($k) => isset($errors[$k]) ? '<span class="error">' . e($errors[$k]) . '</span>' : ''; ?>
<h1>Instalar Borealis</h1>
<p class="sub">Configura la base de datos y la primera cuenta de administración. Tarda menos de un minuto.</p>
<ul class="req-list" aria-label="Requisitos">
  <?php foreach ($checks as [$label, $ok]): ?>
    <li class="<?= $ok ? 'ok' : 'fail' ?>"><?= icon($ok ? 'check-circle' : 'alert', 'sm') ?> <?= e($label) ?></li>
  <?php endforeach; ?>
</ul>
<?php if (!empty($errors['_'])): ?><div class="form-error" role="alert"><?= e($errors['_']) ?></div><?php endif; ?>
<form method="post" data-plain-form>
  <?= csrf_field() ?>
  <h2 class="mb">Base de datos MySQL / MariaDB</h2>
  <div class="form-row">
    <div class="field"><label for="db_host">Servidor</label><input class="input" id="db_host" name="db_host" value="<?= e($values['db_host']) ?>" required><?= $err('db_host') ?></div>
    <div class="field"><label for="db_port">Puerto</label><input class="input" id="db_port" name="db_port" value="<?= e($values['db_port']) ?>" inputmode="numeric"></div>
  </div>
  <div class="form-row">
    <div class="field"><label for="db_name">Nombre de la base</label><input class="input" id="db_name" name="db_name" value="<?= e($values['db_name']) ?>" required><span class="hint">Se crea si no existe y el usuario tiene permiso.</span><?= $err('db_name') ?></div>
    <div class="field"><label for="db_user">Usuario</label><input class="input" id="db_user" name="db_user" value="<?= e($values['db_user']) ?>" required autocomplete="off"><?= $err('db_user') ?></div>
    <div class="field"><label for="db_pass">Contraseña</label><input class="input" type="password" id="db_pass" name="db_pass" value="" autocomplete="new-password"></div>
  </div>
  <h2 class="mb mt">Aplicación</h2>
  <div class="form-row">
    <div class="field"><label for="app_url">URL pública</label><input class="input" id="app_url" name="app_url" value="<?= e($values['app_url']) ?>" required><?= $err('app_url') ?></div>
    <div class="field"><label for="base_path">Ruta base</label><input class="input" id="base_path" name="base_path" value="<?= e($values['base_path']) ?>" placeholder="(raíz)"><span class="hint">Vacío si Borealis está en la raíz del dominio.</span></div>
    <div class="field"><label for="timezone">Zona horaria</label><input class="input" id="timezone" name="timezone" value="<?= e($values['timezone']) ?>" list="tz-list" required><?= $err('timezone') ?>
      <datalist id="tz-list"><?php foreach (['America/Mexico_City', 'America/Bogota', 'America/Lima', 'America/Santiago', 'America/Argentina/Buenos_Aires', 'America/Caracas', 'Europe/Madrid', 'UTC'] as $tz): ?><option value="<?= $tz ?>"><?php endforeach; ?></datalist>
    </div>
  </div>
  <label class="check mb"><input type="checkbox" name="pretty_urls" value="1"<?= checked($values['pretty_urls'] === '1') ?>> URLs amigables (requiere mod_rewrite o reescritura equivalente; el .htaccess incluido lo configura en Apache)</label>
  <h2 class="mb mt">Tu espacio y tu cuenta</h2>
  <div class="field"><label for="workspace_name">Nombre del espacio de trabajo</label><input class="input" id="workspace_name" name="workspace_name" value="<?= e($values['workspace_name']) ?>" required maxlength="120"><?= $err('workspace_name') ?></div>
  <div class="form-row">
    <div class="field"><label for="admin_name">Tu nombre</label><input class="input" id="admin_name" name="admin_name" value="<?= e($values['admin_name']) ?>" required maxlength="120"><?= $err('admin_name') ?></div>
    <div class="field"><label for="admin_email">Correo</label><input class="input" type="email" id="admin_email" name="admin_email" value="<?= e($values['admin_email']) ?>" required><?= $err('admin_email') ?></div>
    <div class="field"><label for="admin_password">Contraseña</label><input class="input" type="password" id="admin_password" name="admin_password" required minlength="8" autocomplete="new-password"><?= $err('admin_password') ?></div>
  </div>
  <button class="btn btn--primary" type="submit">Instalar Borealis</button>
</form>
