<?php
use Borealis\Services\Activity;
use Borealis\Services\Automations;
$used = [];
foreach ($rules as $r) $used[$r['template'] . ':' . (int)$r['project_id']] = true;
?>
<?= partial('admin-nav', compact('ws', 'section')) ?>
<p class="muted mb">Cada automatización sigue la forma <strong>cuando pasa algo → si se cumple una condición → Borealis avisa</strong>. Solo envían avisos: no cambian tareas, así que nunca se disparan entre ellas ni se repiten. Lo que hacen queda en el <a href="<?= e(url('/admin/audit', ['category' => 'automation'])) ?>">registro maestro</a>.</p>

<section class="card mb" data-automation-rules>
  <div class="card-head"><h2><?= icon('zap') ?> Activas en este espacio</h2><span class="badge"><?= count($rules) ?></span></div>
  <?php if ($rules): ?>
  <ul class="auto-list">
    <?php foreach ($rules as $r): $t = Automations::TEMPLATES[$r['template']] ?? null; if (!$t) continue; $p = json_decode((string)$r['params'], true) ?: []; ?>
      <li class="auto-rule<?= $r['enabled'] ? '' : ' is-paused' ?>" data-rule="<?= e($r['template']) ?>">
        <div class="auto-main">
          <strong><?= e($r['name']) ?></strong> <span class="badge"><?= $r['project_id'] ? e($r['project_name']) : 'Todo el espacio' ?></span><?php if (!$r['enabled']): ?> <span class="badge warn">En pausa</span><?php endif; ?>
          <div class="auto-flow small"><span><?= icon('clock', 'sm') ?> <?= e($t[1]) ?></span><span><?= icon('filter', 'sm') ?> <?= e($t[2]) ?><?= isset($p['pct']) ? ' (+' . (int)$p['pct'] . ' %)' : '' ?><?= isset($p['days']) ? ' (' . (int)$p['days'] . ' días)' : '' ?></span><span><?= icon('bell', 'sm') ?> <?= e($t[3]) ?></span></div>
          <div class="muted small"><?= $r['last_run_at'] ? 'Última revisión: ' . e(fmt_datetime($r['last_run_at'])) . ' · ' : '' ?>Creada por <?= e($r['created_by_name'] ?? '—') ?></div>
        </div>
        <div class="auto-actions">
          <?php if (isset($p['pct']) || isset($p['days'])): ?>
            <form class="row" data-api="/api/automations/<?= (int)$r['id'] ?>" data-method="PATCH">
              <label class="sr-only" for="ap-<?= (int)$r['id'] ?>"><?= isset($p['pct']) ? 'Porcentaje' : 'Días' ?></label>
              <input class="input sm" style="width:70px" type="number" id="ap-<?= (int)$r['id'] ?>" name="<?= isset($p['pct']) ? 'pct' : 'days' ?>" value="<?= (int)($p['pct'] ?? $p['days']) ?>" min="1" max="500"><span class="small muted"><?= isset($p['pct']) ? '%' : 'días' ?></span>
              <button class="btn btn--sm" type="submit">Guardar</button>
            </form>
          <?php endif; ?>
          <button class="btn btn--sm" type="button" data-call="/api/automations/<?= (int)$r['id'] ?>" data-method="PATCH" data-body='{"enabled":<?= $r['enabled'] ? 'false' : 'true' ?>}'><?= $r['enabled'] ? 'Pausar' : 'Reanudar' ?></button>
          <button class="btn btn--ghost btn--sm" type="button" data-call="/api/automations/<?= (int)$r['id'] ?>" data-method="DELETE" data-confirm="¿Eliminar «<?= e($r['name']) ?>»? Lo que ya hizo sigue en el registro." data-confirm-label="Eliminar" aria-label="Eliminar"><?= icon('trash', 'sm') ?></button>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php else: ?><div class="empty"><p>Todavía no hay automatizaciones. Elige una plantilla abajo.</p></div><?php endif; ?>
</section>

<section class="card mb">
  <div class="card-head"><h2><?= icon('plus') ?> Plantillas</h2></div>
  <div class="auto-templates">
    <?php foreach (Automations::TEMPLATES as $k => $t): ?>
      <form class="auto-tpl" data-api="/api/automations" data-template="<?= $k ?>">
        <input type="hidden" name="template" value="<?= $k ?>">
        <h3><?= e($t[0]) ?></h3>
        <dl class="small"><dt>Cuándo</dt><dd><?= e($t[1]) ?></dd><dt>Si</dt><dd><?= e($t[2]) ?></dd><dt>Entonces</dt><dd><?= e($t[3]) ?></dd></dl>
        <div class="row wrap">
          <label class="sr-only" for="at-p-<?= $k ?>">Alcance</label>
          <select class="select sm" id="at-p-<?= $k ?>" name="project_id"><option value="">Todo el espacio</option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select>
          <?php if (isset($t[4]['pct'])): ?><label class="row small">+<input class="input sm" style="width:64px" type="number" name="pct" value="<?= (int)$t[4]['pct'] ?>" min="5" max="500"> %</label><?php endif; ?>
          <?php if (isset($t[4]['days'])): ?><label class="row small"><input class="input sm" style="width:64px" type="number" name="days" value="<?= (int)$t[4]['days'] ?>" min="1" max="60"> días</label><?php endif; ?>
          <button class="btn btn--primary btn--sm" type="submit"<?= isset($used[$k . ':0']) ? ' title="Ya está activa para todo el espacio; puedes activarla para un proyecto"' : '' ?>>Activar</button>
        </div>
      </form>
    <?php endforeach; ?>
  </div>
</section>

<section class="card">
  <div class="card-head"><h2><?= icon('clock') ?> Lo último que hicieron</h2><a class="small" href="<?= e(url('/admin/audit', ['category' => 'automation'])) ?>">Ver todo</a></div>
  <div class="card-body"><ul class="history">
    <?php foreach ($runs as $a): ?><li><span<?= !empty($a['result']) ? ' class="text-danger"' : '' ?>><?= e($a['summary']) ?> <span class="muted">· <?= e(time_ago($a['created_at'])) ?></span></span></li><?php endforeach; ?>
    <?php if (!$runs): ?><li class="muted">Nada todavía.</li><?php endif; ?>
  </ul>
  <p class="muted small">Las automatizaciones por fecha se revisan en cada ejecución de la tarea programada (ver Administración › Notificaciones). Los avisos llegan como «Automatizaciones» y cada persona puede apagarlos en su perfil.</p></div>
</section>
