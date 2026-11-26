<?= partial('admin-nav', compact('ws', 'section')) ?>
<?php use Borealis\Services\Files; ?>
<?php if ($instanceAdmin && $security): $ex = $security['exposure']; ?>
<section class="card mb" id="seguridad"><div class="card-head"><h2><?= icon('shield') ?> Seguridad de la instalación</h2>
  <button class="btn btn--sm" data-call="/api/admin/exposure-check"><?= icon('restore', 'sm') ?> Comprobar ahora</button></div><div class="card-body">
  <?php $place = \Borealis\Services\StorageMover::placement(); $missing = \Borealis\Core\StoragePath::missing(); ?>
  <?php if ($missing !== null): ?>
    <div class="notice notice--danger" role="alert"><strong>No se encuentra la carpeta de datos <code><?= e($missing) ?></code></strong> que indica <code>config/storage.php</code>, y Borealis está usando <code>storage/</code>. Si cambiaste de servidor, sube esa carpeta o borra <code>config/storage.php</code> por FTP.</div>
  <?php endif; ?>
  <?php if ($ex['status'] === 'exposed'): ?>
    <div class="notice notice--danger" role="alert" data-exposed><strong>Los datos privados se pueden descargar desde internet.</strong> <?= e($ex['detail']) ?><br>
      Solución sin pedir nada al hosting: pulsa <strong>Proteger ahora</strong>. Borealis hace una copia de la base de datos, mueve sus datos privados <?= \Borealis\Services\StorageMover::target()['kind'] === 'outside' ? 'fuera de la carpeta web' : 'a una carpeta con nombre secreto' ?>, renueva la llave de cifrado y vuelve a comprobar. No hay que hacer nada por FTP.</div>
  <?php elseif ($ex['status'] === 'safe'): ?>
    <p class="notice"><?= icon('check', 'sm') ?> Los datos privados no se pueden descargar desde internet. Comprobado <?= e(fmt_datetime(gmdate('Y-m-d H:i:s', (int)$ex['checked_at']))) ?>.<?= $ex['detail'] ? ' ' . e($ex['detail']) : '' ?></p>
  <?php else: ?>
    <p class="notice">No se pudo comprobar automáticamente<?= $ex['detail'] ? ': ' . e($ex['detail']) : '' ?>. Compruébalo abriendo <code><?= e(\Borealis\Services\Exposure::coreUrl() . (($__rel = \Borealis\Services\Exposure::relative(storage_path())) !== null ? "/" . $__rel : "/storage")) ?>/.htaccess</code> en el navegador: debe responder «Prohibido» (403) o «No encontrado» (404).</p>
  <?php endif; ?>
  <p class="small" data-storage-place="<?= e($place) ?>"><strong>Dónde están los datos privados:</strong>
    <?= $place === 'outside' ? 'fuera de la carpeta web (ninguna dirección de internet llega a ellos).' : ($place === 'secret' ? 'en una carpeta con nombre secreto dentro de Borealis.' : 'en <code>storage/</code>, dentro de la carpeta web.') ?></p>
  <?php if ($place !== 'outside' || \Borealis\Security\Secrets::rotatable()): ?>
    <div class="row wrap">
      <?php if ($place !== 'outside'): ?>
        <button class="btn <?= $ex['status'] === 'exposed' ? 'btn--primary' : '' ?> btn--sm" data-call="/api/admin/protect-storage" data-confirm="Se hará una copia de la base de datos, se moverán los datos privados a un lugar que internet no alcanza, se renovará la llave de cifrado y se comprobará de nuevo. Los enlaces para cambiar contraseña ya enviados dejarán de servir. ¿Continuar?" data-confirm-label="Proteger ahora" data-confirm-style="primary"><?= icon('shield', 'sm') ?> Proteger ahora</button>
      <?php else: ?>
        <button class="btn btn--sm" data-call="/api/admin/protect-storage" data-confirm="Se hará una copia de la base de datos y se cifrará todo de nuevo con una llave nueva. Úsalo si la llave anterior pudo verse. Los enlaces para cambiar contraseña ya enviados dejarán de servir. ¿Continuar?" data-confirm-label="Renovar llave" data-confirm-style="primary"><?= icon('restore', 'sm') ?> Renovar la llave de cifrado</button>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <?php if ($security['admins'] < 2): ?>
    <p class="notice mt" role="status"><?= icon('alert', 'sm') ?> Solo hay <strong>una</strong> cuenta con administración de la instalación. Si pierde su contraseña y el correo no está configurado, nadie podrá entrar a administrar. Da ese permiso a una segunda persona en Administración › Usuarios.</p>
  <?php endif; ?>
</div></section>
<?php endif; ?>
<div class="grid grid-2">
  <section class="card"><div class="card-head"><h2><?= icon('download') ?> Exportar información</h2></div><div class="card-body">
    <p>Descarga en JSON todo el contenido de este espacio: equipos, proyectos, tareas, comentarios, documentos, historial y metadatos de archivos.</p>
    <a class="btn btn--primary" href="<?= e(url('/admin/export.json')) ?>"><?= icon('download') ?> Exportar espacio (JSON)</a>
    <p class="muted small mt">Cada proyecto también permite exportar sus tareas en CSV desde Reportes.</p>
  </div></section>
  <section class="card"><div class="card-head"><h2><?= icon('database') ?> Versión y migraciones</h2></div><div class="card-body">
    <p>Borealis <strong><?= e(BOREALIS_VERSION) ?></strong> · <?= count($applied) ?> migraciones aplicadas.</p>
    <?php if ($pending): ?><div class="form-error">Hay <?= count($pending) ?> migraciones pendientes: <?= e(implode(', ', $pending)) ?>. Se crea una copia completa antes de aplicarlas.</div>
      <?php if ($instanceAdmin): ?><button class="btn btn--primary btn--sm mt" data-call="/api/migrations" data-confirm="Se creará una copia de seguridad completa y después se aplicarán <?= count($pending) ?> migraciones. ¿Continuar?" data-confirm-label="Actualizar ahora" data-confirm-style="primary"><?= icon('restore', 'sm') ?> Actualizar base de datos</button>
      <?php endif; ?><p class="muted small">También desde consola: <code>php bin/migrate.php</code></p>
    <?php else: ?><p class="muted" style="margin:0">La base de datos está al día.</p><?php endif; ?>
  </div></section>
</div>
<?php if ($instanceAdmin): $mail = \Borealis\Services\Mailer::describe(); $msrc = \Borealis\Services\MailSettings::source(); $mf = \Borealis\Services\MailSettings::form(); $mst = \Borealis\Services\MailSettings::status(); ?>
<section class="card mt" id="correo" data-region><div class="card-head"><h2><?= icon('mail') ?> Correo</h2>
  <?php if ($mail['configured']): ?><button class="btn btn--sm" type="button" data-call="/api/mail/test"><?= icon('mail', 'sm') ?> Enviar correo de prueba</button><?php endif; ?></div><div class="card-body" data-mail-state="<?= $mail['configured'] ? 'on' : 'off' ?>">
  <?php if ($mail['configured']): ?>
    <p style="margin-top:0"><span class="badge success">Activo</span> <?= $mail['transport'] === 'smtp' ? 'SMTP ' . e($mail['host']) . ':' . (int)$mail['port'] . ' · ' . ($mail['encryption'] === 'ssl' ? 'SSL' : ($mail['encryption'] === 'tls' ? 'STARTTLS' : 'sin cifrado (solo local)')) . ($mail['username'] ? ' · usuario ' . e($mail['username']) : '') : 'Sistema de correo del servidor (mail() de PHP)' ?> · remitente <?= e($mail['from_name']) ?> &lt;<?= e($mail['from']) ?>&gt;</p>
  <?php else: ?>
    <p style="margin-top:0"><span class="badge warn">Sin configurar</span> Borealis todavía no envía correos. Complétalo aquí: no hace falta editar archivos por FTP.</p>
  <?php endif; ?>
  <?php if ($mst['ok'] || $mst['error']): ?>
    <p class="small" data-mail-status><?= $mst['ok'] ? 'Último envío correcto: ' . e(fmt_datetime($mst['ok'])) . '. ' : '' ?><?php if ($mst['error'] && (!$mst['ok'] || $mst['error_at'] > $mst['ok'])): ?><span class="text-danger">Último error (<?= e(fmt_datetime($mst['error_at'])) ?>): <?= e($mst['error']) ?></span><?php endif; ?></p>
  <?php endif; ?>
  <?php if ($msrc === 'config'): ?>
    <p class="small muted">Está definido en <code>config/config.php</code> (sección <code>mail</code>). Para configurarlo desde aquí, quita esa sección del archivo.</p>
  <?php else: ?>
    <form data-api="/api/mail/settings" class="mail-form" autocomplete="off">
      <div class="form-error" data-form-error></div>
      <div class="grid grid-2">
        <div class="field"><label for="m-preset">Proveedor</label><select class="input" id="m-preset" data-mail-preset>
          <option value="">Elegir para rellenar…</option>
          <?php foreach (\Borealis\Services\MailSettings::PRESETS as $k => [$lbl, $h, $p, $en]): ?><option value="<?= e($k) ?>" data-host="<?= e($h) ?>" data-port="<?= (int)$p ?>" data-enc="<?= e($en) ?>"><?= e($lbl) ?></option><?php endforeach; ?>
        </select></div>
        <div class="field"><label for="m-t">Cómo enviar</label><select class="input" id="m-t" name="transport">
          <option value="smtp"<?= $mf['transport'] === 'smtp' ? ' selected' : '' ?>>Buzón SMTP (recomendado)</option>
          <option value="sendmail"<?= $mf['transport'] === 'sendmail' ? ' selected' : '' ?>>Correo del servidor (mail() de PHP)</option>
          <?php if ($msrc === 'admin'): ?><option value="none">Desactivar el correo</option><?php endif; ?>
        </select></div>
        <div class="field"><label for="m-h">Servidor SMTP</label><input class="input" id="m-h" name="host" value="<?= e($mf['host']) ?>" placeholder="mail.tu-dominio.cl"><span class="error" data-error-for="host"></span></div>
        <div class="field"><label for="m-p">Puerto</label><select class="input" id="m-p" name="port"><?php foreach ([465 => '465 · SSL', 587 => '587 · STARTTLS', 2525 => '2525 · STARTTLS', 25 => '25'] as $pv => $pl): ?><option value="<?= $pv ?>"<?= (int)$mf['port'] === $pv ? ' selected' : '' ?>><?= $pl ?></option><?php endforeach; ?></select><span class="error" data-error-for="port"></span></div>
        <div class="field"><label for="m-u">Usuario</label><input class="input" id="m-u" name="username" value="<?= e($mf['username']) ?>" placeholder="avisos@tu-dominio.cl" autocomplete="off"></div>
        <div class="field"><label for="m-pw">Contraseña</label><input class="input" id="m-pw" name="password" type="password" autocomplete="new-password" placeholder="<?= $mf['has_password'] ? '•••••••• (guardada; escribe otra para cambiarla)' : '' ?>"></div>
        <div class="field"><label for="m-f">Remitente</label><input class="input" id="m-f" name="from_address" type="email" value="<?= e($mf['from_address']) ?>" placeholder="avisos@tu-dominio.cl" required><span class="error" data-error-for="from_address"></span></div>
        <div class="field"><label for="m-fn">Nombre del remitente</label><input class="input" id="m-fn" name="from_name" value="<?= e($mf['from_name']) ?>" maxlength="80"></div>
      </div>
      <input type="hidden" name="encryption" value="<?= e($mf['encryption']) ?>" data-mail-enc>
      <p class="hint">La contraseña se guarda cifrada en <code>storage/mail.json</code> y nunca se muestra. Con Gmail o Microsoft 365 usa una «contraseña de aplicación». El remitente debe ser el mismo buzón (o uno de tu dominio), para que los correos no lleguen a spam. Al guardar se envía un correo de prueba a tu dirección.</p>
      <div class="form-actions start"><button class="btn btn--primary" type="submit">Guardar y probar</button></div>
    </form>
  <?php endif; ?>
  <p class="small muted">Los avisos por correo salen en el momento en que se crean (asignaciones, cambios, comentarios) y, para los recordatorios por hora, con la tarea programada. Cada persona elige qué recibe en Perfil › Notificaciones.</p>
</div></section>
<section class="card mt"><div class="card-head"><h2><?= icon('archive') ?> Copias de seguridad completas</h2>
  <button class="btn btn--primary btn--sm" data-call="/api/backups" data-confirm="Se generará una copia SQL completa de la base de datos en storage/backups." data-confirm-label="Crear copia" data-confirm-style="primary"><?= icon('plus', 'sm') ?> Crear copia ahora</button></div>
  <ul class="list">
    <?php foreach ($backups as $b): ?><li><?= icon('database') ?><span style="flex:1" class="truncate"><?= e($b['name']) ?></span><span class="muted small"><?= e(Files::humanSize((int)$b['size'])) ?> · <?= e(fmt_datetime($b['time'])) ?></span><a class="btn btn--ghost btn--sm" href="<?= e(url('/admin/backups/' . $b['name'])) ?>"><?= icon('download', 'sm') ?> Descargar</a></li><?php endforeach; ?>
    <?php if (!$backups): ?><li class="empty">Aún no hay copias. También puedes crearlas con <code>php bin/backup.php</code> (ideal para cron).</li><?php endif; ?>
  </ul>
  <div class="card-foot muted small">Incluyen todos los espacios de la instalación. De las copias automáticas se guardan las <?= \Borealis\Services\Backup::KEEP ?> más recientes; las hechas a mano y las previas a una actualización no se borran. Estas copias están en el mismo servidor: para no perder nada si el servidor falla, descarga cada semana la copia completa (abajo) y guárdala en otro lugar.</div>
  <div class="card-body" style="border-top:1px solid var(--border)">
    <h3 class="small" style="margin:0 0 6px">Copia completa para guardar fuera del servidor</h3>
    <p class="small muted" style="margin:0 0 10px">Un ZIP con la base de datos, <code>config/config.php</code> y la carpeta <code>storage/</code> (archivos subidos, fotos, llave de cifrado, llaves de los avisos y correo). Con él se puede rearmar Borealis en otro servidor (instrucciones en el LEEME del ZIP). Contiene la llave y los datos de todos: guárdalo en un lugar seguro.</p>
    <form method="post" action="<?= e(url('/admin/copia-completa')) ?>"><?= csrf_field() ?><button class="btn btn--sm" type="submit"><?= icon('download', 'sm') ?> Descargar copia completa (.zip)</button></form>
  </div>
</section>
<?php endif; ?>

<section class="card mt" id="legacy-import" data-region><div class="card-head"><h2><?= icon('upload') ?> Importar desde Borealis v0.2</h2></div><div class="card-body">
  <?php if ($imports === null): ?>
    <p class="muted">Actualiza primero la base de datos (arriba) para habilitar la importación.</p>
  <?php else: ?>
    <p>Trae a este espacio los equipos, personas, proyectos, estados, sprints, tareas, checklists (como subtareas), comentarios y archivos de la versión anterior. No modifica ni borra nada existente y crea una copia de seguridad antes de empezar.</p>
    <form data-api="/api/import/legacy" data-multipart data-reset>
      <div class="form-error" data-form-error></div>
      <div class="field"><label for="li-file">Archivo de la versión anterior</label>
        <input class="input" type="file" id="li-file" name="file" accept=".json,.zip" required>
        <span class="hint">El archivo <code>storage/borealis.json</code>, o un ZIP de la carpeta completa de la versión anterior (así se incluyen también los archivos adjuntos).</span>
        <span class="error" data-error-for="file"></span></div>
      <button class="btn btn--primary" type="submit"><?= icon('upload', 'sm') ?> Importar</button>
    </form>
    <?php foreach ($imports as $im): $rep = json_decode((string)$im['summary'], true) ?: []; ?>
      <div class="mt">
        <p class="small"><strong><?= e(fmt_datetime($im['created_at'])) ?></strong> · <?= e($im['user_name'] ?? '') ?>: <?= (int)($rep['projects'] ?? 0) ?> proyectos, <?= (int)($rep['tasks'] ?? 0) ?> tareas, <?= (int)($rep['subtasks'] ?? 0) ?> subtareas, <?= (int)($rep['comments'] ?? 0) ?> comentarios, <?= (int)($rep['members'] ?? 0) ?> personas nuevas, <?= (int)($rep['files'] ?? 0) ?> archivos.</p>
        <?php if (!empty($rep['warnings'])): ?><ul class="muted small"><?php foreach ($rep['warnings'] as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <?php if (($rep['members'] ?? 0) > 0): ?><p class="muted small">Las personas importadas aún no pueden entrar: asígnales una contraseña en <a href="<?= e(url('/admin/members')) ?>">Personas</a>.</p><?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div></section>

