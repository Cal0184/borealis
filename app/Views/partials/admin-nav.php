<?php use Borealis\Security\Policy; ?>
<div class="page-head"><div><h1>Administración</h1><p class="sub"><?= e($ws['name']) ?></p></div></div>
<nav class="tabs" aria-label="Administración">
  <?php if (Policy::can('workspace.manage')): ?><a href="<?= e(url('/admin')) ?>"<?= $section === 'settings' ? ' aria-current="page"' : '' ?>><?= icon('settings') ?> Espacio</a><?php endif; ?>
  <?php if (Policy::can('members.manage')): ?><a href="<?= e(url('/admin/members')) ?>"<?= $section === 'members' ? ' aria-current="page"' : '' ?>><?= icon('users') ?> Usuarios</a><?php endif; ?>
  <?php if (Policy::can('roles.manage')): ?><a href="<?= e(url('/admin/roles')) ?>"<?= $section === 'roles' ? ' aria-current="page"' : '' ?>><?= icon('shield') ?> Roles y permisos</a><?php endif; ?>
  <?php if (Policy::can('audit.view')): ?><a href="<?= e(url('/admin/audit')) ?>"<?= $section === 'audit' ? ' aria-current="page"' : '' ?>><?= icon('clock') ?> Historial</a><?php endif; ?>
  <?php if (Policy::can('workspace.manage')): ?><a href="<?= e(url('/admin/notifications')) ?>"<?= $section === 'notifications' ? ' aria-current="page"' : '' ?>><?= icon('bell') ?> Notificaciones</a><?php endif; ?>
  <?php if (Policy::can('workspace.manage')): ?><a href="<?= e(url('/admin/data')) ?>"<?= $section === 'data' ? ' aria-current="page"' : '' ?>><?= icon('database') ?> Datos y copias</a><?php endif; ?>
</nav>
