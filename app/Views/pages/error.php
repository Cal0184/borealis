<div class="card" style="max-width:560px;margin:40px auto">
  <div class="empty">
    <?= icon($status === 404 ? 'mountain' : ($status === 403 ? 'lock' : 'alert')) ?>
    <h3><?= e(['404' => 'No encontrado', '403' => 'Acceso restringido', '419' => 'Página caducada', '429' => 'Demasiados intentos'][(string)$status] ?? 'Algo salió mal') ?></h3>
    <p><?= e($message) ?></p>
    <a class="btn btn--primary" href="<?= e(url('/')) ?>">Volver al inicio</a>
  </div>
</div>
