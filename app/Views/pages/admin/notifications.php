<?php
/** 1.14 · Administración › Notificaciones */
$st = $job['state'];
$row = $job['row'];
$labels = ['cli' => 'cron (php bin/notify.php)', 'http' => 'cron por dirección (/cron/run)', 'visit' => 'una visita a Borealis (respaldo)', 'engine' => 'el motor interno de Borealis'];
$hb = \Borealis\Services\Heartbeat::status();
$c = $stats['counts'];
$cmd = 'cd ' . $basePath . ' && /usr/bin/php' . $phpVersion . ' bin/notify.php >> storage/logs/notify.log 2>&1';
?>
<?= partial('admin-nav', compact('ws', 'section')) ?>
<section class="card mb" data-job-state="<?= e($st) ?>">
  <div class="card-head"><h2><?= icon('clock') ?> Avisos con Borealis cerrado</h2></div>
  <div class="card-body">
    <?php if ($st === 'ok' && $job['cli']): ?>
      <p class="notice"><?= icon('check-circle', 'sm') ?> La tarea programada funciona. Última ejecución hace <?= (int)$job['minutes'] ?> min desde <?= e($labels[$row['last_source']] ?? $row['last_source']) ?>.</p>
    <?php elseif ($st === 'ok'): ?>
      <p class="notice"><?= icon('alert', 'sm') ?> Última ejecución hace <?= (int)$job['minutes'] ?> min desde <?= e($labels[$row['last_source']] ?? $row['last_source']) ?>.
        <?php if (($row['last_source'] ?? '') === 'visit'): ?> Funciona solo mientras alguien usa Borealis: configura la tarea programada para que los avisos lleguen también de noche o en fin de semana.<?php endif; ?></p>
    <?php elseif ($st === 'never'): ?>
      <p class="notice notice--danger"><?= icon('alert', 'sm') ?> La tarea programada nunca se ejecutó. Por eso los recordatorios aparecen solo cuando alguien entra a Borealis. Configúrala como se indica abajo.</p>
    <?php elseif ($st === 'stale'): ?>
      <p class="notice notice--danger"><?= icon('alert', 'sm') ?> La tarea programada no se ejecuta desde hace <?= (int)$job['minutes'] ?> minutos (<?= e(fmt_datetime($row['last_started_at'])) ?>). Mientras tanto los recordatorios esperan a que alguien entre.</p>
    <?php else: ?>
      <p class="notice notice--danger"><?= icon('alert', 'sm') ?> La última ejecución terminó con errores: <?= e($row['last_error']) ?></p>
    <?php endif; ?>
    <div class="engine-box" data-engine="<?= $hb['alive'] ? 'on' : ($hb['cron'] ? 'cron' : ($hb['enabled'] ? 'off' : 'unavailable')) ?>">
      <h3 class="small-title"><?= icon('repeat', 'sm') ?> Motor interno de avisos <span class="muted small">(1.15)</span></h3>
      <?php if ($hb['alive']): ?>
        <p class="small"><span class="badge success">Funcionando</span> Último latido hace <?= (int)$hb['seconds'] ?> s · <?= (int)$hb['ticks'] ?> latidos desde <?= e(fmt_datetime(gmdate('Y-m-d H:i:s', (int)$hb['since']))) ?>. Los avisos llegan aunque nadie tenga Borealis abierto ni haya iniciado sesión.</p>
      <?php elseif ($hb['cron']): ?>
        <p class="small"><span class="badge">En reposo</span> La tarea programada del servidor ya se ejecuta, así que el motor interno no hace falta. Si el cron deja de funcionar, el motor vuelve a arrancar solo.</p>
      <?php elseif ($hb['enabled']): ?>
        <p class="small"><span class="badge warn">Detenido</span> <?= $hb['error'] ? 'No pudo llamarse a sí mismo: ' . e($hb['error']) . '.' : 'Arranca con la próxima visita a cualquier página.' ?> Revisa que el servidor pueda abrir su propia dirección (<code><?= e(\Borealis\Services\Heartbeat::tickUrl()) ?></code>) o configura la tarea programada.</p>
      <?php else: ?>
        <p class="small"><span class="badge">No disponible</span> <?= $hb['fpm'] ? 'Desactivado en config/config.php (cron.heartbeat).' : 'Este servidor no ejecuta PHP con PHP-FPM; usa la tarea programada.' ?></p>
      <?php endif; ?>
      <p class="small muted">El motor mantiene los avisos funcionando sin configurar nada. La tarea programada sigue siendo lo más robusto: también funciona justo después de reiniciar el servidor, sin esperar una visita.</p>
    </div>
    <?php if ($row && $row['last_summary']): ?><p class="small muted"><?= e($row['last_summary']) ?> · <?= (int)$row['runs'] ?> ejecuciones en total</p><?php endif; ?>

    <h3 class="mt">Configurar en HestiaCP</h3>
    <ol class="small">
      <li>Panel de HestiaCP › <strong>Cron Jobs</strong> › <strong>Add Cron Job</strong>.</li>
      <li>Minuto <code>*/5</code>, y <code>*</code> en hora, día, mes y día de la semana.</li>
      <li>Comando (usa la misma versión de PHP del sitio; esta instalación corre PHP <?= e($phpVersion) ?>):<br>
        <code class="copyable" data-copy-text="<?= e($cmd) ?>"><?= e($cmd) ?></code> <button class="btn btn--sm btn--ghost" type="button" data-copy="<?= e($cmd) ?>" data-copy-msg="Comando copiado"><?= icon('copy', 'sm') ?> Copiar</button></li>
    </ol>
    <?php if ($isInstanceAdmin && $cronUrl): ?>
      <p class="small">Si tu alojamiento solo permite llamar a una dirección, usa esta en su lugar (es secreta: quien la tenga puede iniciar la tarea, no ver datos):</p>
      <p><code class="copyable" data-cron-url><?= e('curl -fsS "' . $cronUrl . '" >/dev/null') ?></code> <button class="btn btn--sm btn--ghost" type="button" data-copy="<?= e('curl -fsS "' . $cronUrl . '" >/dev/null') ?>" data-copy-msg="Comando copiado"><?= icon('copy', 'sm') ?> Copiar</button>
        <button class="btn btn--sm btn--ghost" type="button" data-call="/api/admin/cron-token" data-confirm="La dirección actual dejará de funcionar. ¿Generar una nueva?"><?= icon('key', 'sm') ?> Generar otra</button></p>
    <?php endif; ?>
  </div>
</section>

<section class="card">
  <div class="card-head"><h2><?= icon('bell') ?> Envíos a dispositivos (últimos 7 días)</h2></div>
  <div class="card-body">
    <?php if (!$pushOk): ?><p class="notice notice--danger">Este servidor no puede enviar notificaciones a dispositivos: falta OpenSSL con curvas EC o cURL en PHP.</p><?php endif; ?>
    <p class="row wrap" data-delivery-stats>
      <span class="badge success"><?= (int)($c['sent'] ?? 0) ?> entregados</span>
      <span class="badge warn"><?= (int)($c['retry'] ?? 0) ?> en reintento</span>
      <span class="badge danger"><?= (int)($c['failed'] ?? 0) ?> fallidos</span>
      <span class="badge"><?= (int)($c['gone'] ?? 0) ?> dispositivos que se desactivaron</span>
      <span class="badge"><?= (int)$devices ?> dispositivos activos</span>
      <span class="badge"><?= $mailOk ? 'Correo configurado' : 'Correo sin configurar' ?></span>
    </p>
    <?php if ($stats['errors']): ?>
      <div class="table-wrap"><table class="table"><thead><tr><th>Cuándo</th><th>Persona</th><th class="hide-sm">Dispositivo</th><th>Error</th></tr></thead><tbody>
        <?php foreach ($stats['errors'] as $er): ?><tr><td class="small muted"><?= e(fmt_datetime($er['updated_at'])) ?></td><td><?= e($er['user_name']) ?></td><td class="hide-sm small"><?= e($er['device'] ?? '—') ?></td>
          <td class="small"><?= e($er['last_error'] ?? '') ?><?= $er['state'] === 'retry' ? ' · nuevo intento ' . e(fmt_datetime($er['next_attempt_at'])) : ' · ' . (int)$er['attempts'] . ' intentos' ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php else: ?><p class="muted small">Sin errores de envío.</p><?php endif; ?>
    <p class="hint">Los errores pasajeros (sin conexión, servicio saturado) se reintentan a los 5, 15, 60 y 180 minutos. Cada aviso llega una sola vez a cada dispositivo.</p>
  </div>
</section>
