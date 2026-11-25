<?php
/** 1.14 · Administración › Notificaciones */
$st = $job['state'];
$row = $job['row'];
$labels = ['cli' => 'cron (php bin/notify.php)', 'http' => 'cron por dirección (/cron/run)', 'visit' => 'una visita a Borealis (respaldo)', 'engine' => 'el motor interno de Borealis', 'device' => 'un dispositivo que recibió un aviso (service worker)'];
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
        <p class="small"><span class="badge">No disponible</span> <?= $hb['fpm'] ? 'Desactivado en config/config.php (cron.heartbeat).' : 'Este servidor no puede seguir trabajando después de responder; usa el servicio externo gratuito de abajo.' ?></p>
      <?php endif; ?>
      <p class="small muted">Servidor: <strong data-server-mode><?= e($hb['server']) ?></strong>. El motor mantiene los avisos funcionando sin configurar nada; si el alojamiento lo detiene (reinicio, límite de tiempo), vuelve a arrancar con la siguiente visita o con el siguiente aviso que reciba cualquier dispositivo.</p>
    </div>
    <?php if ($row && $row['last_summary']): ?><p class="small muted"><?= e($row['last_summary']) ?> · <?= (int)$row['runs'] ?> ejecuciones en total</p><?php endif; ?>

    <h3 class="mt">Qué funciona sin acceso al servidor</h3>
    <ul class="small" data-layers>
      <li><strong>Al instante, sin configurar nada:</strong> asignaciones, cambios de fecha y de estado, comentarios, menciones y alertas se envían a los dispositivos en el mismo momento en que ocurren, aunque la persona que los recibe tenga Borealis cerrado o su sesión haya caducado.</li>
      <li><strong>Recordatorios por hora</strong> (por vencer, vencidas, reuniones, sprints, monitoreo): los crea la tarea programada. Borealis la mantiene funcionando solo con el motor interno, con cada visita y con cada dispositivo que recibe un aviso.</li>
      <li><strong>Garantía completa, gratis y en 5 minutos:</strong> un servicio externo que abra la dirección secreta de abajo cada 5 minutos. Así los recordatorios llegan a su hora también después de un reinicio del servidor, de noche y en fin de semana, sin que nadie entre. No hace falta SSH, cron ni instalar nada.</li>
    </ul>
    <?php if ($isInstanceAdmin && $cronUrl): ?>
      <h3 class="mt">Servicio externo (recomendado)</h3>
      <ol class="small">
        <li>Crea una cuenta gratuita en <strong>cron-job.org</strong> (o en otro servicio que visite direcciones, como UptimeRobot).</li>
        <li><strong>Create cronjob</strong> › URL: pega esta dirección · Execution schedule: <strong>Every 5 minutes</strong> · Guardar.</li>
      </ol>
      <p><code class="copyable" data-cron-url><?= e($cronUrl) ?></code> <button class="btn btn--sm btn--ghost" type="button" data-copy="<?= e($cronUrl) ?>" data-copy-msg="Dirección copiada"><?= icon('copy', 'sm') ?> Copiar</button>
        <button class="btn btn--sm btn--ghost" type="button" data-call="/api/admin/cron-token" data-confirm="La dirección actual dejará de funcionar. ¿Generar una nueva?"><?= icon('key', 'sm') ?> Generar otra</button></p>
      <p class="hint">La dirección es secreta: quien la tenga solo puede iniciar la tarea (nunca ver datos), y no se ejecuta dos veces en menos de un minuto. Si se filtra, pulsa «Generar otra» y cámbiala en el servicio. Cuando el servicio funcione, arriba dirá «cron por dirección».</p>
    <?php endif; ?>
    <details class="small mt"><summary>Si tu alojamiento tiene un panel con «Tareas Cron»</summary>
      <p>Cada 5 minutos, con la misma versión de PHP del sitio (PHP <?= e($phpVersion) ?>):<br>
        <code class="copyable" data-copy-text="<?= e($cmd) ?>"><?= e($cmd) ?></code> <button class="btn btn--sm btn--ghost" type="button" data-copy="<?= e($cmd) ?>" data-copy-msg="Comando copiado"><?= icon('copy', 'sm') ?> Copiar</button></p>
    </details>
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
