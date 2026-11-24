<?php
use Borealis\Services\Integrations;
$kindIcon = fn($k) => Integrations::LINK_KINDS[$k][1] ?? 'link';
$iconOk = ['folder', 'calendar', 'link'];
$ic = fn($k) => in_array($kindIcon($k), $iconOk, true) ? $kindIcon($k) : ($k === 'discord' ? 'message' : ($k === 'github' ? 'doc' : 'link'));
?>
<div class="page-head">
  <div><h1>Todo a mano</h1><p class="sub">Las herramientas de cada proyecto en un solo lugar. Todo es opcional: sin nada conectado, Borealis funciona igual.</p></div>
</div>

<section class="card mb tools-legend">
  <div class="card-body row wrap" style="gap:18px">
    <span class="row"><span class="badge"><?= icon('link', 'sm') ?> Enlace directo</span> <span class="small muted">Solo abre la herramienta. Borealis no se conecta a ella.</span></span>
    <span class="row"><span class="badge success"><?= icon('switch', 'sm') ?> Integración</span> <span class="small muted">Borealis se comunica con el servicio mediante su API oficial.</span></span>
  </div>
</section>

<section class="mine-block" aria-labelledby="tools-me">
  <h2 class="mine-title" id="tools-me"><?= icon('user', 'sm') ?> Tus cuentas</h2>
  <div class="grid grid-2">
    <div class="card tool-card">
      <div class="card-head"><h3><?= icon('calendar') ?> Google Calendar</h3>
        <?php if ($google && $google['status'] === 'active'): ?><span class="badge success">Conectado</span><?php elseif ($google): ?><span class="badge danger">Revisar</span><?php else: ?><span class="badge">Sin conectar</span><?php endif; ?></div>
      <div class="card-body">
        <p class="small"><span class="badge success"><?= icon('switch', 'sm') ?> Integración</span> Con OAuth de Google: eliges qué calendarios sincronizar, ves sus eventos en Borealis y, si el calendario lo permite, los creas o cambias desde aquí.</p>
        <?php if ($google): ?><p class="small muted"><?= e((string)$google['google_email']) ?><?= $google['last_sync_at'] ? ' · última sincronización ' . e(time_ago($google['last_sync_at'])) : '' ?></p><?php endif; ?>
        <?php if (!$googleReady): ?><p class="small" role="note"><?= icon('lock', 'sm') ?> La administración de esta instalación aún no configuró el cliente OAuth de Google (pasos en el README, sección «Google Calendar y Google Tasks»). Mientras tanto, el calendario de Borealis funciona sin Google.</p><?php endif; ?>
        <a class="btn btn--sm" href="<?= e(url('/profile')) ?>#google"><?= $google ? 'Elegir calendarios o desconectar' : 'Vincular Google Calendar' ?></a>
      </div>
    </div>
    <div class="card tool-card">
      <div class="card-head"><h3><?= icon('bell') ?> Notificaciones en el móvil</h3><span class="badge" data-push-state>—</span></div>
      <div class="card-body">
        <p class="small"><span class="badge success"><?= icon('switch', 'sm') ?> Integración</span> Estándar Web Push del navegador: avisos de tareas por vencer, vencidas, asignaciones y cambios de fecha aunque Borealis esté cerrado.</p>
        <a class="btn btn--sm" href="<?= e(url('/profile')) ?>#notificaciones">Configurar avisos</a>
      </div>
    </div>
  </div>
</section>

<section class="mine-block" aria-labelledby="tools-projects">
  <h2 class="mine-title" id="tools-projects"><?= icon('folder', 'sm') ?> Por proyecto</h2>
  <?php if (!$hub): ?><div class="card"><div class="empty"><p>Cuando participes en un proyecto, aquí verás sus herramientas.</p></div></div><?php endif; ?>
  <?php foreach ($hub as $h): $p = $h['project']; $pid = (int)$p['id']; $dc = $h['integrations']['discord'] ?? null; $gh = $h['integrations']['github'] ?? null; $g = $github[$pid] ?? null; ?>
    <article class="card tools-project mb" id="tools-<?= $pid ?>">
      <div class="card-head"><h3><span class="dot" style="width:10px;height:10px;border-radius:3px;background:<?= e($p['color']) ?>"></span> <a href="<?= e(url('/projects/' . $pid)) ?>"><?= e($p['name']) ?></a></h3>
        <?php if (!$h['links'] && !$dc && !$gh): ?><span class="muted small">Sin herramientas todavía</span><?php endif; ?></div>
      <div class="card-body stack">
        <?php if ($h['links']): ?>
          <ul class="tool-links">
            <?php foreach ($h['links'] as $l): ?>
              <li><a class="tool-link" href="<?= e($l['url']) ?>" target="_blank" rel="noopener noreferrer"><?= icon($ic($l['kind']), 'sm') ?> <span class="grow truncate"><?= e($l['title']) ?></span><span class="badge"><?= e(Integrations::LINK_KINDS[$l['kind']][0]) ?> · enlace</span><?= icon('external', 'sm') ?></a>
                <?php if ($h['manage']): ?><button type="button" class="btn btn--ghost btn--icon btn--xs" data-call="/api/links/<?= (int)$l['id'] ?>" data-method="DELETE" data-confirm="¿Quitar el enlace «<?= e($l['title']) ?>»? La herramienta no se modifica." data-confirm-label="Quitar" aria-label="Quitar <?= e($l['title']) ?>"><?= icon('x', 'sm') ?></button><?php endif; ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php if ($gh && $g): ?>
          <div class="tool-api">
            <p class="row wrap"><strong><?= icon('doc', 'sm') ?> GitHub</strong> <span class="badge success">Integración</span> <a href="<?= e($g['url'] ?? ('https://github.com/' . $g['repo'])) ?>" target="_blank" rel="noopener noreferrer"><?= e($g['name'] ?? $g['repo']) ?> <?= icon('external', 'sm') ?></a><?php if (!empty($g['private'])): ?><span class="badge"><?= icon('lock', 'sm') ?> privado</span><?php endif; ?></p>
            <?php if ($g['error']): ?><p class="small" role="alert" style="color:var(--danger-strong)"><?= e($g['error']) ?></p><?php endif; ?>
            <?php if (isset($g['open_issues'])): ?>
              <div class="grid grid-3 gh-cols">
                <div><p class="small muted">Incidencias abiertas</p><ul class="small gh-list"><?php foreach ($g['issues'] as $x): ?><li><a href="<?= e($x['url']) ?>" target="_blank" rel="noopener noreferrer">#<?= (int)$x['number'] ?> <?= e($x['title']) ?></a></li><?php endforeach; ?><?php if (!$g['issues']): ?><li class="muted">Ninguna</li><?php endif; ?></ul></div>
                <div><p class="small muted">Pull requests abiertos</p><ul class="small gh-list"><?php foreach ($g['pulls'] as $x): ?><li><a href="<?= e($x['url']) ?>" target="_blank" rel="noopener noreferrer">#<?= (int)$x['number'] ?> <?= e($x['title']) ?></a> <span class="muted"><?= e($x['user']) ?></span></li><?php endforeach; ?><?php if (!$g['pulls']): ?><li class="muted">Ninguno</li><?php endif; ?></ul></div>
                <div><p class="small muted">Actividad reciente</p><ul class="small gh-list"><?php foreach ($g['commits'] as $x): ?><li><a href="<?= e($x['url']) ?>" target="_blank" rel="noopener noreferrer"><code><?= e($x['sha']) ?></code> <?= e(mb_strimwidth($x['message'], 0, 70, '…')) ?></a></li><?php endforeach; ?><?php if (!$g['commits']): ?><li class="muted">Sin commits</li><?php endif; ?></ul></div>
              </div>
            <?php endif; ?>
            <button type="button" class="btn btn--ghost btn--sm" data-call="/api/projects/<?= $pid ?>/integrations/github/refresh"><?= icon('repeat', 'sm') ?> Actualizar</button>
          </div>
        <?php endif; ?>

        <?php if ($dc): ?>
          <p class="row wrap small"><strong><?= icon('message', 'sm') ?> Discord</strong> <span class="badge success">Integración</span> <?= e((string)$dc['target']) ?> · <?= $dc['enabled'] ? ($dc['events'] ? count($dc['events']) . ' tipos de aviso activos' : 'ningún aviso activo') : 'en pausa' ?>
            <?= $dc['last_ok_at'] ? '· último envío ' . e(time_ago($dc['last_ok_at'])) : '' ?></p>
          <?php if ($dc['last_error']): ?><p class="small" role="alert" style="color:var(--danger-strong)"><?= e($dc['last_error']) ?></p><?php endif; ?>
        <?php endif; ?>

        <?php if ($h['manage']): ?>
          <details class="more-options tools-config"<?= $only === $pid ? ' open' : '' ?>><summary><?= icon('settings', 'sm') ?> Configurar herramientas</summary>
            <div class="stack">
              <form class="tool-form" data-api="/api/projects/<?= $pid ?>/links" data-reset>
                <h4><?= icon('link', 'sm') ?> Añadir un enlace directo</h4>
                <p class="hint">Carpeta o documento de Google Drive, canal de Discord, repositorio, tablero… Se abre con un clic; no se copia ningún archivo.</p>
                <div class="form-row">
                  <div class="field"><label for="tl-u-<?= $pid ?>">Dirección</label><input class="input" id="tl-u-<?= $pid ?>" name="url" type="url" required placeholder="https://drive.google.com/drive/folders/…"><span class="error" data-error-for="url"></span></div>
                  <div class="field"><label for="tl-t-<?= $pid ?>">Nombre (opcional)</label><input class="input" id="tl-t-<?= $pid ?>" name="title" maxlength="120" placeholder="Carpeta del proyecto"></div>
                </div>
                <button class="btn btn--sm" type="submit"><?= icon('plus', 'sm') ?> Añadir enlace</button>
              </form>

              <form class="tool-form" data-api="/api/projects/<?= $pid ?>/integrations/discord">
                <h4><?= icon('message', 'sm') ?> Discord <span class="badge success">Integración</span></h4>
                <p class="hint">Publica avisos en un canal mediante un <strong>webhook</strong> de Discord (Ajustes del servidor › Integraciones › Webhooks › Nuevo webhook › Copiar URL). Solo se envía lo que marques, con texto breve: nunca tareas personales, comentarios ni datos de cuentas.</p>
                <div class="field"><label for="dc-h-<?= $pid ?>">URL del webhook</label><input class="input" id="dc-h-<?= $pid ?>" name="webhook" type="url" autocomplete="off" placeholder="<?= $dc ? 'Guardado (' . e((string)$dc['target']) . '). Pega otro para cambiarlo.' : 'https://discord.com/api/webhooks/…' ?>"><span class="error" data-error-for="webhook"></span></div>
                <fieldset class="field"><legend class="label">Qué compartir</legend>
                  <?php foreach (Integrations::DISCORD_EVENTS as $k => $lbl): ?><label class="check"><input type="checkbox" name="events[]" value="<?= $k ?>"<?= $dc && in_array($k, $dc['events'], true) ? ' checked' : '' ?>> <?= e($lbl) ?></label><?php endforeach; ?>
                </fieldset>
                <?php if ($dc): ?><label class="check"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1"<?= $dc['enabled'] ? ' checked' : '' ?>> Avisos activos</label><?php endif; ?>
                <div class="row wrap"><button class="btn btn--sm btn--primary" type="submit">Guardar Discord</button>
                  <?php if ($dc): ?><button type="button" class="btn btn--sm" data-call="/api/projects/<?= $pid ?>/integrations/discord/test"><?= icon('play', 'sm') ?> Enviar prueba</button>
                  <button type="button" class="btn btn--ghost btn--sm" data-call="/api/projects/<?= $pid ?>/integrations/discord" data-method="DELETE" data-confirm="¿Desconectar Discord de «<?= e($p['name']) ?>»? El canal no se modifica." data-confirm-label="Desconectar">Desconectar</button><?php endif; ?></div>
              </form>

              <form class="tool-form" data-api="/api/projects/<?= $pid ?>/integrations/github">
                <h4><?= icon('doc', 'sm') ?> GitHub <span class="badge success">Integración</span></h4>
                <p class="hint">Muestra incidencias y pull requests abiertos y los últimos commits de un repositorio con la API de GitHub. Los públicos no necesitan token (GitHub permite 60 consultas por hora); para uno privado crea un <em>fine-grained token</em> de solo lectura (Issues, Pull requests, Contents) limitado a ese repositorio. Se guarda cifrado.</p>
                <div class="form-row">
                  <div class="field"><label for="gh-r-<?= $pid ?>">Repositorio</label><input class="input" id="gh-r-<?= $pid ?>" name="repo" required value="<?= e((string)($gh['target'] ?? '')) ?>" placeholder="organizacion/repositorio"><span class="error" data-error-for="repo"></span></div>
                  <div class="field"><label for="gh-t-<?= $pid ?>">Token (opcional)</label><input class="input" id="gh-t-<?= $pid ?>" name="token" type="password" autocomplete="off" placeholder="<?= $gh && $gh['has_secret'] ? 'Guardado. Pega otro para cambiarlo.' : 'Solo para repositorios privados' ?>"></div>
                </div>
                <div class="row wrap"><button class="btn btn--sm btn--primary" type="submit">Guardar GitHub</button>
                  <?php if ($gh): ?><button type="button" class="btn btn--ghost btn--sm" data-call="/api/projects/<?= $pid ?>/integrations/github" data-method="DELETE" data-confirm="¿Desvincular el repositorio de «<?= e($p['name']) ?>»? GitHub no se modifica." data-confirm-label="Desvincular">Desvincular</button><?php endif; ?></div>
              </form>
              <p class="small muted"><?= icon('folder', 'sm') ?> <strong>Google Drive:</strong> por ahora, como enlace directo. Leer carpetas con la API de Drive exige permisos de Google que requieren verificar la aplicación; está documentado en el README.</p>
            </div>
          </details>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
</section>
