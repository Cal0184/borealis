<div class="card-head"><h3><?= icon('bell') ?> Notificaciones</h3>
  <?php if ($items): ?><button class="btn btn--ghost btn--sm" data-call="/api/notifications/read-all">Marcar todo como leído</button><?php endif; ?>
</div>
<ul class="list">
  <?php foreach ($items as $n): ?>
    <li><a class="notif-item<?= $n['read_at'] ? '' : ' unread' ?>" href="<?= e(url('/notifications/' . $n['id'] . '/open')) ?>">
      <?= avatar($n['actor_name'] ? ['id' => $n['actor_id'], 'name' => $n['actor_name'], 'avatar_color' => $n['avatar_color']] : ['name' => 'Borealis', 'avatar_color' => '#142820'], 'sm') ?>
      <span style="flex:1;min-width:0"><span class="notif-text"><?= e($n['message']) ?></span><br><span class="muted small"><?= e(time_ago($n['created_at'])) ?></span></span>
    </a></li>
  <?php endforeach; ?>
  <?php if (!$items): ?><li class="empty">No tienes notificaciones.</li><?php endif; ?>
</ul>
<div class="card-foot"><a href="<?= e(url('/notifications')) ?>">Ver todas</a></div>
