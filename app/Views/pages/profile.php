<?php use Borealis\Services\Users; $prefs = $me['prefs']; ?>
<?php $hasPhoto = !empty($me['avatar_photo']); ?>
<?php $myWeekly = \Borealis\Core\Database::get()->value('SELECT weekly_hours FROM workspace_members WHERE workspace_id = ? AND user_id = ?', [\Borealis\Security\Policy::wsId(), $me['id']]); ?>
<?php // 1.15 · profile as a settings page: a summary header, a section index and one column of sections ?>
<section class="profile-hero">
  <div class="profile-hero-photo"><?= avatar($me, 'xl') ?></div>
  <div class="profile-hero-text">
    <h1><?= e($me['name']) ?></h1>
    <p class="sub"><?= $me['title'] ? e($me['title']) . ' · ' : '' ?><?= e($membership['role_name']) ?> en <?= e($membership['name']) ?></p>
    <div class="profile-hero-meta">
      <span><?= icon('mail', 'sm') ?> <?= e($me['email']) ?></span>
      <span class="mention">@<?= e($me['handle']) ?></span>
      <?php if ($myWeekly !== null): ?><span><?= icon('clock', 'sm') ?> <?= e(rtrim(rtrim((string)$myWeekly, '0'), '.')) ?> h por semana</span><?php endif; ?>
    </div>
  </div>
  <a class="btn btn--sm profile-hero-link" href="<?= e(url('/people/' . $me['id'])) ?>"><?= icon('eye', 'sm') ?> Ver mi ficha</a>
</section>

<div class="settings-layout profile-layout">
  <nav class="side-nav profile-nav" data-profile-nav aria-label="Secciones del perfil">
    <a href="#datos"><?= icon('user', 'sm') ?> Datos personales</a>
    <a href="#apariencia"><?= icon('eye', 'sm') ?> Apariencia</a>
    <a href="#notificaciones"><?= icon('bell', 'sm') ?> Notificaciones</a>
    <a href="#conexiones"><?= icon('link', 'sm') ?> Conexiones</a>
    <a href="#seguridad"><?= icon('lock', 'sm') ?> Seguridad</a>
    <a href="#ayuda"><?= icon('play', 'sm') ?> Ayuda</a>
  </nav>
  <div class="profile-sections">
  <section class="card" id="datos"><div class="card-head"><h2><?= icon('user') ?> Datos personales</h2></div><div class="card-body">
    <div class="photo-box" id="foto">
      <?= avatar($me, 'lg') ?>
      <div class="photo-box-text">
        <span class="label">Fotografía de perfil</span>
        <span class="hint">JPG, PNG, WebP o GIF de hasta 8 MB. Antes de guardarla eliges el recorte.<?= $hasPhoto ? '' : ' Mientras no subas una, se muestran tus iniciales.' ?></span>
        <?php if (!\Borealis\Services\Avatars::supported()): ?><p class="notice small">Este servidor no tiene la extensión GD de PHP con WebP, necesaria para las fotografías. Pide a la administración que la active.</p><?php endif; ?>
      </div>
      <div class="row wrap photo-box-actions"<?= \Borealis\Services\Avatars::supported() ? '' : ' hidden' ?>>
        <label class="btn btn--sm" for="photo-file"><?= icon('upload', 'sm') ?> <?= $hasPhoto ? 'Cambiar fotografía' : 'Subir fotografía' ?></label>
        <input class="sr-only" type="file" id="photo-file" accept="image/jpeg,image/png,image/webp,image/gif" data-photo-input>
        <?php if ($hasPhoto): ?><button type="button" class="btn btn--ghost btn--sm" data-call="/api/profile/photo" data-method="DELETE" data-confirm="¿Eliminar tu fotografía? Volverás a ver tus iniciales." data-confirm-label="Eliminar"><?= icon('trash', 'sm') ?> Eliminar</button><?php endif; ?>
      </div>
    </div>
    <form data-api="/api/profile" data-method="PATCH">
      <div class="form-error" data-form-error></div>
      <div class="form-row">
        <div class="field"><label for="pf-n">Nombre</label><input class="input" id="pf-n" name="name" value="<?= e($me['name']) ?>" required maxlength="120"><span class="error" data-error-for="name"></span></div>
        <div class="field"><label for="pf-e">Correo</label><input class="input" type="email" id="pf-e" name="email" value="<?= e($me['email']) ?>" required><span class="error" data-error-for="email"></span></div>
      </div>
      <div class="form-row">
        <div class="field"><label for="pf-t">Cargo o función</label><input class="input" id="pf-t" name="title" value="<?= e($me['title']) ?>" maxlength="120" placeholder="p. ej. Coordinación de diseño"></div>
        <div class="field"><label for="pf-h">Horas semanales en este espacio</label><input class="input" type="number" id="pf-h" name="weekly_hours" min="0" max="168" step="0.5" value="<?= $myWeekly !== null ? e(rtrim(rtrim((string)$myWeekly, '0'), '.')) : '' ?>" placeholder="p. ej. 40"><span class="hint">Para la capacidad de los sprints y tu resumen semanal.</span><span class="error" data-error-for="weekly_hours"></span></div>
      </div>
      <fieldset class="field"><legend class="label">Color de las iniciales <span class="muted small">(cuando no hay fotografía)</span></legend><div class="swatch-row">
        <?php foreach (Users::COLORS as $c): ?><label class="swatch" title="<?= e($c) ?>"><input class="sr-only" type="radio" name="avatar_color" value="<?= e($c) ?>"<?= checked($me['avatar_color'] === $c) ?>><span class="avatar sm" style="--avatar:<?= e($c) ?>"><?= e(initials($me['name'])) ?></span></label><?php endforeach; ?>
      </div></fieldset>
      <div class="form-actions start"><button class="btn btn--primary" type="submit">Guardar</button><span class="muted small">Te mencionan escribiendo <span class="mention">@<?= e($me['handle']) ?></span></span></div>
    </form>
  </div></section>
    <section class="card" id="apariencia"><div class="card-head"><h2><?= icon('eye') ?> Apariencia</h2><span class="save-state" data-save-state aria-live="polite"></span></div><div class="card-body">
      <p class="muted small" style="margin-top:0">Elige el tema que verás tú: cambia el fondo, la barra lateral y el símbolo de Borealis. Las demás personas no ven ningún cambio.</p>
      <div class="ambient-grid" role="group" aria-label="Tema de Borealis">
        <?php $cur = $prefs['ambient'] ?? 'default'; foreach (Users::AMBIENTS as $key => [$aName, $aHint]): ?>
          <button type="button" class="ambient-pick" data-ambient-pick="<?= e($key) ?>" aria-pressed="<?= $cur === $key ? 'true' : 'false' ?>">
            <span class="ambient-thumb<?= $key === 'default' ? ' is-default' : '' ?>"<?= $key === 'default' ? '' : ' data-ambient="' . e($key) . '"' ?>></span>
            <span class="ambient-name"><?= e($aName) ?><?= $cur === $key ? ' ' . icon('check', 'sm') : '' ?></span>
            <span class="muted small"><?= e($key === 'default' ? 'Volver al predeterminado' : $aHint) ?></span>
          </button>
        <?php endforeach; ?>
      </div>
      <form class="appearance-motion" data-api="/api/profile/prefs" data-method="PATCH" data-on-success="reload">
        <div class="field"><label for="pf-m">Animaciones</label>
          <div class="row wrap"><select class="select" id="pf-m" name="motion" style="max-width:280px"><option value="full"<?= selected('full', $prefs['motion'] ?? 'full') ?>>Completas</option><option value="reduced"<?= selected('reduced', $prefs['motion'] ?? '') ?>>Reducidas (sin celebraciones)</option><option value="off"<?= selected('off', $prefs['motion'] ?? '') ?>>Desactivadas</option></select>
          <button class="btn" type="submit">Guardar</button></div>
          <span class="hint">Borealis también respeta la preferencia de movimiento reducido de tu sistema.</span></div>
      </form>
    </div></section>
    <?php $np = \Borealis\Services\Notifications::prefs((int)$me['id']); $devices = \Borealis\Services\Push::devices((int)$me['id']); ?>
    <section class="card" id="notificaciones"><div class="card-head"><h2><?= icon('bell') ?> Notificaciones</h2></div><div class="card-body">
      <form data-api="/api/profile/notifications" data-method="PATCH" data-notify-form>
        <fieldset class="field"><legend class="label">Qué quieres recibir en Borealis</legend><div class="notif-cat-grid">
          <?php foreach (\Borealis\Services\Notifications::CATEGORIES as $k => [$lbl, $desc]): ?>
            <label class="check notif-cat"><input type="checkbox" name="in_app[]" value="<?= $k ?>"<?= checked(!in_array($k, $np['off'], true)) ?>> <span><strong><?= e($lbl) ?></strong><br><span class="small muted"><?= e($desc) ?></span></span></label>
          <?php endforeach; ?>
        </div></fieldset>
        <fieldset class="field"><legend class="label">Avisar antes de la entrega</legend>
          <div class="row wrap gx-before"><?php foreach (\Borealis\Services\Notifications::BEFORE as $m => $lbl): ?>
            <label class="check"><input type="checkbox" name="before[]" value="<?= $m ?>"<?= checked(in_array($m, $np['before'], true)) ?>> <?= e($lbl) ?></label>
          <?php endforeach; ?></div>
          <input type="hidden" name="before[]" value="">
          <span class="hint">Si la tarea tiene hora de entrega, a esa distancia exacta; si solo tiene fecha, el día que corresponde («vence mañana», «vence hoy»). Si ya pasaron varios momentos, llega solo el más cercano. Las tareas completadas nunca avisan.</span></fieldset>
        <div class="grid grid-2 nf-grid">
          <div class="field"><label for="nf-tz">Tu zona horaria</label>
            <select class="select" id="nf-tz" name="timezone"><?php $myTz = $np['timezone'] ?? \Borealis\Services\UserTime::installZone(); foreach (\Borealis\Services\UserTime::choices() as $z): ?><option value="<?= e($z) ?>"<?= selected($z, $myTz) ?>><?= e(str_replace('_', ' ', $z)) ?><?= $z === \Borealis\Services\UserTime::installZone() ? ' (la de Borealis)' : '' ?></option><?php endforeach; ?></select>
            <span class="hint">Para tus recordatorios, el horario de silencio, los correos y tu Google Calendar.</span></div>
          <fieldset class="field"><legend class="label">Horario de silencio</legend>
            <label class="check"><input type="checkbox" name="quiet_on" value="1"<?= checked($np['quiet']['on']) ?>> No enviar al móvil ni por correo</label>
            <div class="row"><label class="sr-only" for="nf-qf">Desde</label><input class="input sm" type="time" id="nf-qf" name="quiet_from" value="<?= e($np['quiet']['from']) ?>" style="max-width:120px"> <span class="muted small">a</span>
              <label class="sr-only" for="nf-qt">Hasta</label><input class="input sm" type="time" id="nf-qt" name="quiet_to" value="<?= e($np['quiet']['to']) ?>" style="max-width:120px"></div>
            <span class="hint">Los avisos se guardan en la campana y llegan juntos al terminar.</span></fieldset>
        </div>

        <?php $mailOk = \Borealis\Services\Mailer::configured(); $myMail = \Borealis\Services\UserTime::emailOf((int)$me['id']); ?>
        <div class="push-box" data-mail-box>
          <h3 class="small-title"><?= icon('mail', 'sm') ?> Por correo</h3>
          <label class="check"><input type="checkbox" name="email" value="1"<?= checked($np['email']) ?>> <span>Enviarme los avisos importantes a <strong style="overflow-wrap:anywhere"><?= e((string)($me['email'] ?? '')) ?></strong></span></label>
          <p class="small muted" data-mail-status><?= !$mailOk ? 'Esta instalación todavía no puede enviar correos: la administración debe configurarlo. Mientras tanto no se envía ninguno.' : (!$myMail ? 'Tu dirección de correo no es válida; cámbiala en Datos personales para recibirlos.' : 'Si hay varios avisos juntos llegan en un solo correo. No se envía lo que ya leíste en Borealis.') ?></p>
          <details class="more-options"><summary><?= icon('settings', 'sm') ?> Qué avisos llegan por correo</summary>
            <?php foreach (\Borealis\Services\Notifications::CATEGORIES as $k => [$lbl]): ?>
              <label class="check"><input type="checkbox" name="email_cats[]" value="<?= $k ?>"<?= checked(!in_array($k, $np['email_off'], true)) ?>> <?= e($lbl) ?></label>
            <?php endforeach; ?>
            <input type="hidden" name="email_cats[]" value="">
          </details>
        </div>

        <div class="push-box" data-push-box data-has-devices="<?= $devices ? '1' : '0' ?>">
          <h3 class="small-title"><?= icon('bell', 'sm') ?> En el móvil y en este equipo</h3>
          <p class="small" data-push-status>Comprobando si este navegador admite notificaciones…</p>
          <p class="small muted" data-push-ios hidden>En iPhone o iPad: abre Borealis en Safari, pulsa Compartir › «Añadir a pantalla de inicio», ábrelo desde ese icono y vuelve aquí para activarlas (iOS 16.4 o posterior).</p>
          <div class="row wrap">
            <button type="button" class="btn btn--primary btn--sm" data-push-on hidden><?= icon('bell', 'sm') ?> Activar en este dispositivo</button>
            <button type="button" class="btn btn--sm" data-push-test hidden><?= icon('play', 'sm') ?> Enviar una prueba</button>
            <button type="button" class="btn btn--sm" data-push-test-closed<?= $devices ? '' : ' hidden' ?>><?= icon('clock', 'sm') ?> Probar con Borealis cerrado (30 s)</button>
            <button type="button" class="btn btn--ghost btn--sm" data-push-off hidden>Desactivar en este dispositivo</button>
          </div>
          <input type="hidden" name="push" value="<?= $np['push'] ? '1' : '0' ?>" data-push-flag>
          <input type="hidden" name="has_sound" value="1">
          <label class="check"><input type="checkbox" name="sound" value="1"<?= checked($np['sound']) ?> data-pref-sound> Con sonido <span class="muted small">(el sonido de avisos del sistema; con Borealis abierto, además, un tono corto)</span></label>
          <label class="check"><input type="checkbox" name="sticky" value="1"<?= checked($np['sticky']) ?>> Asignaciones, vencimientos y alertas se quedan en pantalla hasta cerrarlos</label>
          <details class="more-options" data-push-cats<?= $np['push'] ? '' : ' hidden' ?>><summary><?= icon('settings', 'sm') ?> Qué avisos llegan al móvil</summary>
            <?php foreach (\Borealis\Services\Notifications::CATEGORIES as $k => [$lbl]): ?>
              <label class="check"><input type="checkbox" name="push_cats[]" value="<?= $k ?>"<?= checked(!in_array($k, $np['push_off'], true)) ?>> <?= e($lbl) ?></label>
            <?php endforeach; ?>
          </details>
          <?php $job = \Borealis\Services\Scheduler::status(); if (in_array($job['state'], ['never', 'stale'], true)): ?>
            <p class="small notice" data-push-job="<?= e($job['state']) ?>"><?= icon('alert', 'sm') ?> La tarea programada de avisos todavía no se ejecutó en este servidor: los recordatorios por hora pueden llegar tarde. La administración puede revisarlo en Administración › Notificaciones.</p>
          <?php endif; ?>
          <?php if ($devices): ?>
            <?php /* 1.16.1: each device with its last delivery, so «sent» can be matched to a real device */ ?>
            <p class="small muted mt">Dispositivos que reciben tus avisos:</p>
            <ul class="small device-list" data-devices>
              <?php foreach ($devices as $dv): ?>
                <li><strong><?= e($dv['device'] ?: 'Dispositivo') ?></strong> · activado <?= e(fmt_datetime($dv['created_at'])) ?> ·
                  <?= $dv['last_ok_at'] ? 'última entrega ' . e(fmt_datetime($dv['last_ok_at'])) : 'sin entregas todavía' ?><?= $dv['last_error'] ? ' · problema: ' . e($dv['last_error']) : '' ?></li>
              <?php endforeach; ?>
            </ul>
            <p class="hint">«Entregado» significa que el servicio del navegador (Google, Microsoft, Mozilla o Apple) recibió el aviso. Ese servicio lo muestra cuando el navegador está en marcha; si el navegador está cerrado del todo, aparece al abrirlo. En Windows, el Asistente de concentración o «No molestar» lo guardan en el centro de notificaciones sin mostrarlo ni sonar.</p>
          <?php endif; ?>
        </div>
        <div class="form-actions start"><button class="btn btn--primary" type="submit">Guardar notificaciones</button></div>
      </form>
    </div></section>
    <h2 class="profile-group-title" id="conexiones">Conexiones</h2>
    <?php $g = \Borealis\Services\Google::account(); $gmsg = \Borealis\Core\Session::flash('google_msg');
      $gx = $g ? \Borealis\Services\GoogleExport::status((int)$me['id']) : ['state' => 'disconnected']; $gxo = $gx['options'] ?? \Borealis\Services\GoogleExport::DEFAULTS;
      $gState = !$g ? ['Desconectado', ''] : ($g['status'] === 'revoked' ? ['Error de autorización', 'danger'] : (($gx['state'] ?? '') === 'pending' ? ['Sincronizando', 'warn'] : (($gx['state'] ?? '') === 'error' || $g['status'] === 'error' ? ['Con errores', 'warn'] : ['Conectado', 'success']))); ?>
    <section class="card" id="google" data-region><div class="card-head"><h2><?= icon('calendar') ?> Google Calendar</h2><span class="badge<?= $gState[1] ? ' ' . $gState[1] : '' ?>" data-google-state><?= e($gState[0]) ?></span></div><div class="card-body">
      <?php if ($gmsg): ?><p class="notice<?= $gmsg[0] === 'error' ? ' notice--danger' : '' ?>" role="<?= $gmsg[0] === 'error' ? 'alert' : 'status' ?>"><?= e($gmsg[1]) ?></p><?php endif; ?>
      <?php if (!\Borealis\Services\Google::configured()): ?>
        <p style="margin-top:0">La conexión con Google todavía no está configurada en esta instalación. El calendario y las tareas de Borealis funcionan igual sin ella.</p>
        <?php if (\Borealis\Security\AccountPolicy::isInstanceAdmin()): ?><p class="small muted">Para activarla, crea un cliente OAuth en Google Cloud y añade sus datos en <code>config/config.php</code>. Los pasos exactos están en <code>docs/CONFIGURACION-1.9.md</code> (y en el README, sección «Google Calendar»).</p><?php endif; ?>
      <?php elseif (!$g): ?>
        <p style="margin-top:0">Lleva tus tareas con fecha a tu Google Calendar y mira tus calendarios de Google en Borealis.</p>
        <ol class="gx-steps small"><li>Conecta</li><li>Autoriza en Google</li><li>Elige el calendario</li><li>Elige qué enviar</li><li>Listo</li></ol>
        <p class="small muted">Google te pedirá permiso para ver tu correo, ver la lista de tus calendarios, ver y editar sus eventos, y ver y editar tus tareas. Borealis nunca ve tu contraseña de Google y solo cambia los eventos que él mismo crea.</p>
        <a class="btn btn--primary" href="<?= e(url('/google/connect')) ?>"><?= icon('link', 'sm') ?> Conectar con Google</a>
      <?php else: ?>
        <p style="margin-top:0">
          <span class="badge badge--google">Google</span> <strong><?= e($g['google_email'] ?: 'Cuenta conectada') ?></strong>
          <br><span class="small muted"><?= $g['last_sync_at'] ? 'Última sincronización: ' . e(fmt_date(substr($g['last_sync_at'], 0, 10), true)) . ' a las ' . e(substr(fmt_datetime($g['last_sync_at']), -5)) : 'Aún sin sincronizar' ?></span>
        </p>
        <?php if ($g['last_error'] && $g['status'] !== 'active'): ?><p class="notice notice--danger" role="alert"><?= e($g['last_error']) ?></p><?php endif; ?>
        <?php if ($g['status'] === 'revoked'): ?>
          <p class="small">Google retiró el permiso (caducó, cambiaste la contraseña o lo quitaste desde tu cuenta). Lo que ya estaba en tu calendario se queda allí; al reconectar se actualiza sin duplicarse.</p>
          <a class="btn btn--primary" href="<?= e(url('/google/connect')) ?>"><?= icon('link', 'sm') ?> Volver a conectar</a>
        <?php else: ?>
          <form class="gx-form" data-api="/api/google/export" data-google-export>
            <h3 class="small-title"><?= icon('upload', 'sm') ?> Enviar mi trabajo a Google Calendar</h3>
            <?php if ($gx['state'] !== 'off'): ?>
              <p class="gx-status small" data-gx-status>
                <?php if ($gx['state'] === 'error'): ?><span class="badge warn">Error</span> <?= e($gx['error'] ?: 'No se pudo enviar a Google.') ?> Se reintentará solo.
                <?php else: ?><span class="badge <?= $gx['state'] === 'pending' ? 'warn' : 'success' ?>"><?= $gx['state'] === 'pending' ? 'Sincronizando' : 'Funcionando' ?></span>
                  <?= (int)$gx['events'] === 1 ? '1 evento' : (int)$gx['events'] . ' eventos' ?> en «<?= e((string)$gx['calendar_name']) ?>»<?= $gx['synced_at'] ? ' · al día desde ' . e(substr(fmt_datetime($gx['synced_at']), -5)) : '' ?>.
                <?php endif; ?>
                <a href="https://calendar.google.com/calendar/r" target="_blank" rel="noopener">Abrir Google Calendar</a>
              </p>
            <?php else: ?>
              <p class="small muted" style="margin-top:0">Elige un calendario y qué enviar. Solo va de Borealis a Google: los cambios se cambian en Borealis.</p>
            <?php endif; ?>
            <div class="field"><label for="gx-cal">Calendario de Google</label>
              <select class="select" id="gx-cal" name="calendar_id" required data-gx-calendars data-current="<?= e((string)($gx['calendar_id'] ?? '')) ?>">
                <?php if (!empty($gx['calendar_id'])): ?><option value="<?= e((string)$gx['calendar_id']) ?>" selected><?= e((string)$gx['calendar_name']) ?></option><?php else: ?><option value="">Cargando tus calendarios…</option><?php endif; ?>
              </select>
              <span class="hint">Solo aparecen los calendarios donde puedes crear eventos. Si eliges uno compartido, quienes lo comparten en Google verán estos eventos.</span><span class="error" data-error-for="calendar_id"></span></div>
            <fieldset class="field"><legend class="label">Qué enviar</legend>
              <label class="check"><input type="checkbox" name="mine" value="1"<?= checked($gxo['mine']) ?>> Tareas con fecha de las que soy responsable</label>
              <label class="check"><input type="checkbox" name="team" value="1"<?= checked($gxo['team']) ?>> También las tareas asignadas a mis equipos</label>
              <label class="check"><input type="checkbox" name="sprints" value="1"<?= checked($gxo['sprints']) ?>> Sprints y entregas de los proyectos en que participo</label>
              <span class="error" data-error-for="mine"></span>
            </fieldset>
            <details class="more-options"><summary><?= icon('settings', 'sm') ?> Más opciones</summary>
              <fieldset class="field"><legend class="label">Información en cada evento</legend>
                <label class="check"><input type="radio" name="details" value="full"<?= checked($gxo['details'] === 'full') ?>> Título, proyecto, estado, tiempo estimado, descripción breve y enlace</label>
                <label class="check"><input type="radio" name="details" value="basic"<?= checked($gxo['details'] === 'basic') ?>> Solo título, proyecto y enlace</label>
              </fieldset>
              <fieldset class="field"><legend class="label">Al completar una tarea</legend>
                <label class="check"><input type="radio" name="done" value="remove"<?= checked($gxo['done'] === 'remove') ?>> Quitarla del calendario</label>
                <label class="check"><input type="radio" name="done" value="keep"<?= checked($gxo['done'] === 'keep') ?>> Dejarla, marcada con ✓</label>
              </fieldset>
              <label class="check"><input type="checkbox" name="reminders" value="1"<?= checked($gxo['reminders']) ?>> Usar también los recordatorios de Google Calendar (Borealis ya te avisa; así no se repiten)</label>
            </details>
            <p class="small muted">Las tareas con hora aparecen como un bloque que termina a esa hora (tan largo como su tiempo estimado, 30 minutos si no tiene); las que solo tienen fecha, como evento de todo el día.</p>
            <div class="form-actions start"><button class="btn btn--primary" type="submit"><?= $gx['state'] === 'off' ? 'Empezar a enviar' : 'Guardar y sincronizar' ?></button>
              <?php if ($gx['state'] !== 'off'): ?><button class="btn" type="button" data-call="/api/google/export/sync"><?= icon('repeat', 'sm') ?> Sincronizar ahora</button>
                <button class="btn btn--ghost btn--sm" type="button" data-call="/api/google/export/stop" data-body='{"remove":true}' data-confirm="¿Dejar de enviar y quitar de Google Calendar los eventos que creó Borealis? Solo se quitan esos; el resto de tu calendario no se toca." data-confirm-label="Dejar de enviar">Dejar de enviar</button><?php endif; ?></div>
          </form>
          <details class="more-options mt"><summary><?= icon('eye', 'sm') ?> Ver mis calendarios de Google dentro de Borealis</summary>
            <form data-api="/api/google/calendars" data-google-calendars>
              <p class="label">Calendarios de Google que se muestran en Borealis</p>
              <input type="hidden" name="calendars[]" value="">
              <div class="multi-pick-list" data-google-list><p class="small muted">Cargando tus calendarios de Google…</p></div>
              <div class="form-actions start"><button class="btn btn--primary" type="submit">Guardar y sincronizar</button>
                <button class="btn" type="button" data-call="/api/google/sync"><?= icon('repeat', 'sm') ?> Sincronizar ahora</button></div>
            </form>
            <p class="small muted">Las listas de Google Tasks aparecen en <a href="<?= e(url('/tasks')) ?>">Tareas › Mis tareas</a>.</p>
          </details>
        <?php endif; ?>
        <p class="small muted mt">Desconectar borra los permisos guardados en Borealis. Puedes dejar en Google lo que ya se envió o quitar solo los eventos que creó Borealis.</p>
        <div class="row wrap">
          <button class="btn btn--danger btn--sm" type="button" data-call="/api/google/disconnect" data-confirm="¿Desconectar tu cuenta de Google? Los calendarios de Google dejarán de verse en Borealis y no se enviará nada más. En Google no se elimina nada." data-confirm-label="Desconectar">Desconectar Google</button>
          <?php if ((int)($gx['events'] ?? 0) > 0 && $g['status'] !== 'revoked'): ?><button class="btn btn--ghost btn--sm" type="button" data-call="/api/google/disconnect" data-body='{"remove_events":true}' data-confirm="¿Desconectar y quitar de tu Google Calendar los <?= (int)$gx['events'] ?> eventos creados por Borealis? Nada más se toca." data-confirm-label="Desconectar y quitar">Desconectar y quitar sus eventos</button><?php endif; ?>
        </div>
      <?php endif; ?>
    </div></section>
    <section class="card pwa-box" id="aplicacion" data-pwa-box><div class="card-head"><h2><?= icon('download') ?> Borealis como aplicación</h2></div><div class="card-body">
      <p style="margin-top:0" data-pwa-status>Instala Borealis en tu computador, tablet o celular: se abre en su propia ventana, con su icono, y recibe avisos como una aplicación.</p>
      <button class="btn btn--primary" type="button" data-pwa-install hidden><?= icon('download', 'sm') ?> Instalar Borealis</button>
      <p class="small muted" data-pwa-ios hidden>En iPhone o iPad: abre Borealis en Safari, pulsa Compartir y elige «Añadir a pantalla de inicio».</p>
      <p class="small muted" data-pwa-manual hidden>En este navegador: menú del navegador › «Instalar Borealis» o «Añadir a la pantalla de inicio». Necesita una dirección https.</p>
      <p class="small muted" data-pwa-done hidden>Ya estás usando Borealis instalado.</p>
    </div></section>
    <section class="card" id="seguridad"><div class="card-head"><h2><?= icon('lock') ?> Contraseña y sesiones</h2></div><div class="card-body">
      <form data-api="/api/profile/password" data-reset>
        <div class="form-error" data-form-error></div>
        <div class="field"><label for="pw-c">Contraseña actual</label><input class="input" type="password" id="pw-c" name="current_password" required autocomplete="current-password"><span class="error" data-error-for="current_password"></span></div>
        <div class="form-row">
          <div class="field"><label for="pw-n">Nueva contraseña</label><input class="input" type="password" id="pw-n" name="password" required minlength="8" autocomplete="new-password"><span class="error" data-error-for="password"></span></div>
          <div class="field"><label for="pw-r">Repítela</label><input class="input" type="password" id="pw-r" name="password_confirm" required minlength="8" autocomplete="new-password"><span class="error" data-error-for="password_confirm"></span></div>
        </div>
        <p class="muted small">Al cambiarla se cierran tus sesiones en otros dispositivos.</p>
        <div class="form-actions start"><button class="btn btn--primary" type="submit">Cambiar contraseña</button></div>
      </form>
    </div></section>
    <section class="card" id="ayuda"><div class="card-head"><h2><?= icon('circle') ?> Ayuda</h2></div><div class="card-body">
      <p style="margin-top:0">El recorrido guiado pasa por Inicio, Proyectos, Tareas, Responsables, Sprints, Calendarios y Configuración, señalando los controles reales de cada página.</p>
      <button class="btn" type="button" data-tour-start><?= icon('play', 'sm') ?> Ver el tutorial otra vez</button>
    </div></section>
  </div>
</div>

<dialog class="modal" id="dlg-photo" aria-labelledby="dlg-photo-title">
  <div class="modal-head"><h2 id="dlg-photo-title">Ajustar la fotografía</h2><button type="button" class="btn btn--ghost btn--icon" data-close-dialog aria-label="Cerrar"><?= icon('x') ?></button></div>
  <div class="modal-body">
    <div class="form-error" data-photo-error></div>
    <div class="crop-stage" data-crop-stage tabindex="0" role="application" aria-label="Recorte: arrastra o usa las flechas para mover la imagen">
      <canvas width="560" height="560" data-crop-canvas></canvas>
      <span class="crop-ring" aria-hidden="true"></span>
    </div>
    <div class="field crop-zoom"><label for="crop-zoom">Zoom</label><input type="range" id="crop-zoom" min="1" max="4" step="0.01" value="1" data-crop-zoom></div>
    <p class="hint">Arrastra la imagen (o usa las flechas del teclado) para encuadrarla. Lo que queda dentro del círculo es lo que verán los demás.</p>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close-dialog>Cancelar</button><button type="button" class="btn btn--primary" data-crop-save>Guardar fotografía</button></div>
</dialog>
