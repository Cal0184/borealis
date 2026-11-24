<?= partial('admin-nav', compact('ws', 'section')) ?>
<?php use Borealis\Services\Files; ?>
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
<?php if ($instanceAdmin): $mail = \Borealis\Services\Mailer::describe(); ?>
<section class="card mt" id="correo"><div class="card-head"><h2><?= icon('mail') ?> Correo</h2>
  <?php if ($mail['configured']): ?><button class="btn btn--sm" type="button" data-call="/api/mail/test"><?= icon('mail', 'sm') ?> Enviar correo de prueba</button><?php endif; ?></div><div class="card-body">
  <?php if (!$mail['configured']): ?>
    <p style="margin-top:0">Borealis todavía no envía correos. Para activarlo, añade la sección <code>mail</code> en <code>config/config.php</code> con un buzón de tu dominio (pasos en <code>docs/CONFIGURACION-1.9.md</code>). Mientras tanto, los avisos siguen en la campana y en el móvil.</p>
  <?php else: ?>
    <p style="margin-top:0"><?= $mail['transport'] === 'smtp' ? 'SMTP ' . e($mail['host']) . ':' . (int)$mail['port'] . ' · ' . ($mail['encryption'] === 'ssl' ? 'SSL' : ($mail['encryption'] === 'tls' ? 'STARTTLS' : 'sin cifrado (solo local)')) . ($mail['username'] ? ' · usuario ' . e($mail['username']) : '') : 'Sistema de correo del servidor (mail() de PHP)' ?> · remitente <?= e($mail['from_name']) ?> &lt;<?= e($mail['from']) ?>&gt;</p>
    <p class="small muted">«Enviar correo de prueba» manda un mensaje a tu dirección. Los avisos por correo los envía el cron (<code>bin/notify.php</code>).</p>
  <?php endif; ?>
</div></section>
<section class="card mt"><div class="card-head"><h2><?= icon('archive') ?> Copias de seguridad completas</h2>
  <button class="btn btn--primary btn--sm" data-call="/api/backups" data-confirm="Se generará una copia SQL completa de la base de datos en storage/backups." data-confirm-label="Crear copia" data-confirm-style="primary"><?= icon('plus', 'sm') ?> Crear copia ahora</button></div>
  <ul class="list">
    <?php foreach ($backups as $b): ?><li><?= icon('database') ?><span style="flex:1" class="truncate"><?= e($b['name']) ?></span><span class="muted small"><?= e(Files::humanSize((int)$b['size'])) ?> · <?= e(fmt_datetime($b['time'])) ?></span><a class="btn btn--ghost btn--sm" href="<?= e(url('/admin/backups/' . $b['name'])) ?>"><?= icon('download', 'sm') ?> Descargar</a></li><?php endforeach; ?>
    <?php if (!$backups): ?><li class="empty">Aún no hay copias. También puedes crearlas con <code>php bin/backup.php</code> (ideal para cron).</li><?php endif; ?>
  </ul>
  <div class="card-foot muted small">Incluyen todos los espacios de la instalación. Los archivos subidos están en <code>storage/uploads</code> y deben copiarse aparte.</div>
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

