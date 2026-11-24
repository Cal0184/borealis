<?php use Borealis\Services\Notifications; ?>
<div class="page-head"><div><h1>Notificaciones</h1><p class="sub">Asignaciones, vencimientos, reuniones, sprints y cambios en tus proyectos. <a href="<?= e(url('/profile') . '#notificaciones') ?>">Elegir qué avisos recibir</a></p></div>
  <div class="page-actions"><nav class="segmented"><a href="<?= e(url('/notifications', $cat ? ['cat' => $cat] : [])) ?>"<?= !$unreadOnly ? ' aria-current="page"' : '' ?>>Todas</a><a href="<?= e(url('/notifications', ['filter' => 'unread'] + ($cat ? ['cat' => $cat] : []))) ?>"<?= $unreadOnly ? ' aria-current="page"' : '' ?>>Sin leer</a></nav>
    <button class="btn" data-call="/api/notifications/read-all"><?= icon('check') ?> Marcar todo como leído</button></div></div>
<nav class="chips notif-cats mb" aria-label="Tipo de aviso">
  <a class="chip" href="<?= e(url('/notifications', $unreadOnly ? ['filter' => 'unread'] : [])) ?>"<?= !$cat ? ' aria-current="page"' : '' ?>>Todos los tipos</a>
  <?php foreach (Notifications::CATEGORIES as $k => [$lbl]): ?><a class="chip" href="<?= e(url('/notifications', ['cat' => $k] + ($unreadOnly ? ['filter' => 'unread'] : []))) ?>"<?= $cat === $k ? ' aria-current="page"' : '' ?>><?= e($lbl) ?></a><?php endforeach; ?>
</nav>
<section class="card"><ul class="list">
  <?php foreach ($items as $n): ?>
    <li>
      <a class="notif-item<?= $n['read_at'] ? '' : ' unread' ?>" href="<?= e(url('/notifications/' . $n['id'] . '/open')) ?>">
        <?= avatar($n['actor_name'] ? ['id' => $n['actor_id'], 'name' => $n['actor_name'], 'avatar_color' => $n['avatar_color']] : ['name' => 'Borealis', 'avatar_color' => '#142820'], 'sm') ?>
        <span style="flex:1;min-width:0"><span class="notif-text"><?= e($n['message']) ?></span><br><span class="muted small"><?= e(Notifications::TYPES[$n['type']] ?? $n['type']) ?> · <?= e(time_ago($n['created_at'])) ?></span></span>
      </a>
      <?php if (!$n['read_at']): ?><button class="btn btn--ghost btn--sm" data-call="/api/notifications/<?= (int)$n['id'] ?>/read" data-silent>Marcar leída</button><?php endif; ?>
    </li>
  <?php endforeach; ?>
  <?php if (!$items): ?><li class="empty"><?= $unreadOnly ? 'Estás al día.' : 'Aún no tienes notificaciones.' ?></li><?php endif; ?>
</ul></section>
