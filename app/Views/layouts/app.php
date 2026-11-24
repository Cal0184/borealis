<?php
use Borealis\Controllers\Shell;
$s = Shell::data();
$nav = $nav ?? '';
$currentProjectId = isset($project['id']) ? (int)$project['id'] : 0;
?><!doctype html>
<html lang="es" data-motion="<?= e($s['motion']) ?>"<?= !empty($s['user']['prefs']['nav_compact']) ? ' data-nav="compact"' : '' ?> data-ambient="<?= e($s['ambient']) ?>"<?= $s['tour'] === null ? ' data-tour-auto' . ($s['tour_step'] !== null ? ' data-tour-step="' . (int)$s['tour_step'] . '"' : '') : '' ?><?= $s['tour_project'] ? ' data-tour-project="' . (int)$s['tour_project'] . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-path" content="<?= e(rtrim((string)config('app.base_path', ''), '/')) ?>">
<meta name="pretty-urls" content="<?= config('app.pretty_urls', true) ? '1' : '0' ?>">
<title><?= e(($title ?? 'Borealis') . ' · ' . $s['workspace']['name']) ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Crect width='24' height='24' rx='6' fill='%23142820'/%3E%3Cpath d='M12 4 7 11h2.5L6 16h4.5v4h3v-4H18l-3.5-5H17z' fill='%2310B981'/%3E%3C/svg%3E">
<link rel="manifest" href="<?= e(rtrim((string)config('app.base_path', ''), '/')) ?>/manifest.webmanifest">
<link rel="apple-touch-icon" href="<?= e(asset('img/apple-touch-icon.png')) ?>">
<meta name="theme-color" content="#142820">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Borealis">
<link rel="stylesheet" href="<?= e(asset('css/borealis.css')) ?>">
<script type="module" src="<?= e(asset('js/app.js')) ?>"></script>
</head>
<body>
<a class="skip-link" href="#page">Saltar al contenido</a>
<div class="app">
  <aside class="sidebar" id="sidebar" aria-label="Navegación principal">
    <a class="brand" href="<?= e(url('/')) ?>"><span class="brand-mark"><?= icon('tree') ?></span> Borealis</a>
    <div class="ws-switch">
      <?php if (count($s['workspaces']) > 1): ?>
        <label class="sr-only" for="ws-select">Espacio de trabajo</label>
        <select id="ws-select" data-switch-workspace>
          <?php foreach ($s['workspaces'] as $w): ?>
            <option value="<?= (int)$w['id'] ?>"<?= selected($w['id'], $s['workspace']['id']) ?>><?= e($w['name']) ?></option>
          <?php endforeach; ?>
        </select>
      <?php else: ?>
        <div class="ws-name truncate" title="Espacio de trabajo"><?= e($s['workspace']['name']) ?></div>
      <?php endif; ?>
    </div>
    <?php
    // 1.15 · the sidebar is grouped by area. A group can be folded (remembered per person); the group of
    // the current page always stays open. Groups and items only appear when the person can use them.
    $section = $section ?? '';
    $closed = (array)($s['user']['prefs']['nav_closed'] ?? []);
    $cur = fn(bool $on) => $on ? ' aria-current="page"' : '';
    $groups = [
        'mine' => ['Mi espacio', [
            ['/', 'home', 'Inicio', $nav === 'home', ''],
            ['/my-work', 'check-square', 'Mis tareas', $nav === 'my-work', 'my-work', 'mine'],
            ['/calendar', 'calendar', 'Mi calendario', $nav === 'calendar', 'nav-calendar'],
            ['/notifications', 'inbox', 'Bandeja de entrada', $nav === 'notifications', '', 'unread'],
            ['/integrations', 'link', 'Todo a mano', $nav === 'integrations', 'nav-tools'],
        ]],
        'org' => ['Organización', array_values(array_filter([
            ['/projects', 'folder', 'Proyectos', $nav === 'projects' && !$currentProjectId, 'projects'],
            ['/tasks', 'list', 'Todas las tareas', $nav === 'tasks', 'nav-tasks'],
            ['/quick', 'pin', 'Trabajos rápidos', $nav === 'quick', ''],
            ['/teams', 'users', 'Equipos', $nav === 'teams', ''],
            $s['show_sprints'] ? ['/sprints', 'zap', 'Planificación', $nav === 'sprints', 'nav-sprints'] : null,
        ]))],
        'watch' => ['Seguimiento', [
            ['/monitoring', 'chart', 'Carga y alertas', in_array($nav, ['monitoring', 'reports'], true), ''],
            ['/analysis', 'timeline', 'Análisis e informes', $nav === 'analysis', 'nav-analysis'],
        ]],
        'admin' => ['Administración', array_values(array_filter([
            $s['can']['members'] ? ['/admin/members', 'users', 'Usuarios', $nav === 'admin' && $section === 'members', ''] : null,
            $s['can']['audit'] ? ['/admin/audit', 'clock', 'Registro maestro', $nav === 'admin' && $section === 'audit', ''] : null,
            $s['can']['workspace'] ? ['/admin/automations', 'zap', 'Automatizaciones', $nav === 'admin' && $section === 'automations', ''] : null,
            $s['can']['workspace'] ? ['/admin/notifications', 'bell', 'Notificaciones y tareas', $nav === 'admin' && $section === 'notifications', ''] : null,
            $s['is_admin'] ? ['/admin', 'settings', 'Configuración', $nav === 'admin' && !in_array($section, ['members', 'audit', 'automations', 'notifications'], true), 'nav-admin'] : null,
        ]))],
    ];
    ?>
    <nav class="nav nav-groups" id="main-nav" data-region aria-label="Secciones">
      <?php foreach ($groups as $gk => [$glabel, $items]): if (!$items) continue;
        $active = (bool)array_filter($items, fn($i) => $i[3]); ?>
        <details class="nav-group" data-nav-group="<?= $gk ?>"<?= $active || !in_array($gk, $closed, true) ? ' open' : '' ?>>
          <summary class="nav-group-head"><span><?= e($glabel) ?></span><?= icon('chevron-down', 'sm') ?></summary>
          <?php foreach ($items as $it): [$href, $ic, $label, $on, $tour] = $it; $badge = $it[5] ?? null; ?>
            <a class="nav-item" href="<?= e(url($href)) ?>"<?= $tour ? ' data-tour="' . e($tour) . '"' : '' ?><?= $cur($on) ?> title="<?= e($label) ?>"><?= icon($ic) ?> <span class="nav-text"><?= e($label) ?></span>
              <?php if ($badge === 'mine'): ?><?php if ($s['my_overdue']): ?><span class="count danger" title="<?= (int)$s['my_overdue'] ?> vencidas"><?= (int)$s['my_overdue'] ?></span><?php endif; ?><?php if ($s['my_open']): ?><span class="count<?= $s['my_overdue'] ? ' soft' : '' ?>" title="<?= (int)$s['my_open'] ?> abiertas"><?= (int)$s['my_open'] ?></span><?php endif; ?><?php endif; ?>
              <?php if ($badge === 'unread'): ?><span class="count" data-nav-unread<?= $s['unread'] ? '' : ' hidden' ?>><?= $s['unread'] ? (int)$s['unread'] : '' ?></span><?php endif; ?>
            </a>
          <?php endforeach; ?>
        </details>
      <?php endforeach; ?>
    </nav>
    <?php if ($s['favorites']): ?>
      <div class="nav-label"><span>Favoritos</span></div>
      <nav class="nav" id="sidebar-favorites" aria-label="Favoritos">
        <?php foreach ($s['favorites'] as $f): ?>
          <a class="nav-project" href="<?= e($f['url']) ?>" title="<?= e($f['label']) ?>" data-fav="<?= e($f['type'] . ':' . $f['id']) ?>"><span class="dot" style="--dot:<?= e($f['color'] ?: '#10B981') ?>"></span><span class="name"><?= e($f['type'] === 'task' ? $f['sub'] . ' · ' . $f['label'] : $f['label']) ?></span></a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>
    <?php $wk = $s['week_minutes']; $wh = $s['weekly_hours']; $wp = $wh ? min(100, (int)round($wk / ($wh * 60) * 100)) : 0; ?>
    <a class="week-card" id="sidebar-week" data-tour="hours" data-region href="<?= e(url('/my-work')) ?>" aria-label="Tu semana: <?= e(\Borealis\Services\TimeEntries::fmt($wk)) ?> registradas<?= $wh ? ' de ' . e(rtrim(rtrim(number_format($wh, 1, '.', ''), '0'), '.')) . ' h' : '' ?>">
      <span class="week-head"><?= icon('clock', 'sm') ?> Tu semana</span>
      <span class="week-value"><strong><?= e(\Borealis\Services\TimeEntries::fmt($wk)) ?></strong><?= $wh ? ' <span>de ' . e(rtrim(rtrim(number_format($wh, 1, '.', ''), '0'), '.')) . ' h</span>' : '' ?></span>
      <?php if ($wh): ?><span class="week-bar"><span style="--p:<?= $wp ?>%"></span></span><?php else: ?><span class="week-hint">Define tus horas semanales en tu perfil</span><?php endif; ?>
    </a>
    <div class="nav-label"><span>Proyectos recientes</span><?php if ($s['can_create_project']): ?><a href="#" data-dialog="dlg-new-project" aria-label="Nuevo proyecto" title="Nuevo proyecto"><?= icon('plus', 'sm') ?></a><?php endif; ?></div>
    <nav class="nav" id="sidebar-projects" data-region aria-label="Proyectos recientes">
      <?php foreach ($s['projects'] as $sp): ?>
        <a class="nav-project" href="<?= e(url('/projects/' . $sp['id'])) ?>"<?= $currentProjectId === (int)$sp['id'] ? ' aria-current="page"' : '' ?> title="<?= e($sp['name']) ?> · <?= (int)$sp['progress'] ?>%">
          <span class="pkey" style="--dot:<?= e($sp['color']) ?>"><?= e(mb_substr($sp['project_key'], 0, 3)) ?></span>
          <span class="pmain"><span class="name"><?= e($sp['name']) ?></span><span class="pbar"><span style="--p:<?= (int)$sp['progress'] ?>%;--dot:<?= e($sp['color']) ?>"></span></span></span>
          <?php if ($sp['overdue']): ?><span class="pwarn" title="<?= (int)$sp['overdue'] ?> vencidas"><?= (int)$sp['overdue'] ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
      <?php if (!$s['projects']): ?><span class="nav-project muted">Sin proyectos todavía</span><?php endif; ?>
    </nav>
    <?php if ($s['teams']): ?>
      <div class="nav-label"><span>Mis equipos</span></div>
      <nav class="nav" aria-label="Mis equipos">
        <?php foreach ($s['teams'] as $st): ?><a class="nav-project nav-team" href="<?= e(url('/teams/' . $st['id'])) ?>"><span class="dot" style="--dot:<?= e($st['color']) ?>"></span><span class="name"><?= e($st['name']) ?></span></a><?php endforeach; ?>
      </nav>
    <?php endif; ?>
    <div class="sidebar-foot">
      <div class="sidebar-forest" aria-hidden="true"></div>
      <button class="nav-collapse" type="button" data-nav-compact aria-pressed="<?= !empty($s['user']['prefs']['nav_compact']) ? 'true' : 'false' ?>" title="Barra lateral compacta"><?= icon('chevron-left', 'sm') ?> <span class="nav-text">Compactar barra</span></button>
      <a class="me-card" href="<?= e(url('/profile')) ?>"><span id="sidebar-me-photo" data-region><?= avatar($s['user'], 'sm') ?></span><span><strong class="truncate"><?= e($s['user']['name']) ?></strong><span class="truncate"><?= e($s['workspace']['role_name'] ?? 'Mi perfil') ?></span></span></a>
    </div>
  </aside>
  <div class="sidebar-scrim" data-sidebar-close></div>
  <div class="main">
    <?php if (\Borealis\Services\Ghosts::impersonating()): ?>
      <div class="impersonation-bar" role="status"><?= icon('eye', 'sm') ?> <span>Estás usando la cuenta fantasma <strong><?= e(\Borealis\Core\Auth::user()['name']) ?></strong>. Lo que hagas queda a su nombre.</span>
        <button class="btn btn--sm" data-call="/api/ghosts/leave"><?= icon('arrow-left', 'sm') ?> Volver a mi cuenta</button></div>
    <?php endif; ?>
    <header class="topbar">
      <button class="btn btn--ghost btn--icon menu-toggle" type="button" data-sidebar-toggle aria-label="Abrir menú" aria-controls="sidebar"><?= icon('menu') ?></button>
      <form class="search" action="<?= e(url('/search')) ?>" method="get" role="search">
        <?php if (!config('app.pretty_urls', true)): ?><input type="hidden" name="r" value="/search"><?php endif; ?>
        <?= icon('search') ?>
        <label class="sr-only" for="global-search">Buscar</label>
        <input id="global-search" type="search" name="q" placeholder="Buscar tareas, proyectos, personas…  ( / )" value="<?= e($_GET['q'] ?? '') ?>" autocomplete="off">
      </form>
      <div class="topbar-actions">
        <button class="btn btn--aurora" type="button" data-dialog="dlg-new-task" data-tour="new-task"><?= icon('plus') ?><span class="hide-sm">Nueva tarea</span></button>
        <div class="menu-wrap">
          <button class="btn btn--ghost btn--icon" type="button" data-menu data-menu-src="<?= e(url('/notifications/menu')) ?>" aria-label="Notificaciones" data-tour="notifications" aria-haspopup="true" aria-expanded="false">
            <?= icon('bell') ?><span id="topbar-notif" data-region><?php if ($s['unread']): ?><span class="badge success notif-badge" data-unread="<?= (int)$s['unread'] ?>"><?= $s['unread'] > 99 ? '99+' : (int)$s['unread'] ?></span><?php endif; ?></span>
          </button>
          <div class="menu notif-menu" role="menu"><div class="loading"></div></div>
        </div>
        <div class="menu-wrap">
          <button class="btn btn--ghost btn--icon" type="button" data-menu data-tour="account" aria-label="Mi cuenta" aria-haspopup="true" aria-expanded="false" style="padding:0"><span id="topbar-me-photo" data-region><?= avatar($s['user']) ?></span></button>
          <div class="menu" role="menu">
            <div class="menu-title"><?= e($s['user']['name']) ?></div>
            <a href="<?= e(url('/profile')) ?>" role="menuitem"><?= icon('user') ?> Mi perfil y preferencias</a>
            <a href="<?= e(url('/profile')) ?>#apariencia" role="menuitem"><?= icon('eye') ?> Apariencia</a>
            <button type="button" role="menuitem" data-tour-start><?= icon('play') ?> Ver el tutorial</button>
            <a href="<?= e(url('/people/' . $s['user']['id'])) ?>" role="menuitem"><?= icon('eye') ?> Mi ficha de integrante</a>
            <hr>
            <form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?><button type="submit" role="menuitem"><?= icon('logout') ?> Cerrar sesión</button></form>
          </div>
        </div>
      </div>
    </header>
    <main class="content<?= !empty($wide) ? ' wide' : '' ?>" id="page" data-region tabindex="-1">
      <?= $content ?>
    </main>
  </div>
</div>

<nav class="mobile-tabs" aria-label="Accesos rápidos">
  <a href="<?= e(url('/')) ?>"<?= $nav === 'home' ? ' aria-current="page"' : '' ?>><?= icon('home') ?><span>Inicio</span></a>
  <a href="<?= e(url('/my-work')) ?>"<?= $nav === 'my-work' ? ' aria-current="page"' : '' ?>><?= icon('check-square') ?><span>Mis tareas</span></a>
  <button type="button" class="mobile-tabs-add" data-dialog="dlg-new-task" aria-label="Nueva tarea"><?= icon('plus') ?></button>
  <a href="<?= e(url('/notifications')) ?>"<?= $nav === 'notifications' ? ' aria-current="page"' : '' ?>><?= icon('inbox') ?><span>Bandeja</span><span class="count" data-nav-unread<?= $s['unread'] ? '' : ' hidden' ?>><?= $s['unread'] ? (int)$s['unread'] : '' ?></span></a>
  <button type="button" data-sidebar-toggle aria-controls="sidebar"><?= icon('menu') ?><span>Menú</span></button>
</nav>
<div class="drawer-scrim" data-drawer-close></div>
<aside class="drawer" id="drawer" aria-label="Detalle de tarea" aria-hidden="true">
  <div class="drawer-body" id="drawer-body"></div>
</aside>
<div class="toasts" id="toasts" role="status" aria-live="polite"></div>

<?= partial('dialogs', ['s' => $s, 'currentProjectId' => $currentProjectId]) ?>
</body>
</html>
