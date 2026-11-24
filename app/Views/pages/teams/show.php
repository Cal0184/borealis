<?php $tid = (int)$team['id']; $totalOpen = array_sum(array_column($members, 'open_tasks')); ?>
<div class="breadcrumbs"><a href="<?= e(url('/teams')) ?>">Equipos</a><?= icon('chevron-right', 'sm') ?><span><?= e($team['name']) ?></span></div>
<?php if ($team['archived_at']): ?>
  <div class="archived-banner"><span class="row"><?= icon('archive') ?> Equipo archivado. Sus integrantes ya no reciben acceso a proyectos a través de él.</span>
  <?php if ($canManage): ?><button class="btn btn--sm" data-call="/api/teams/<?= $tid ?>/restore"><?= icon('restore') ?> Restaurar</button><?php endif; ?></div>
<?php endif; ?>
<div class="page-head">
  <div class="row" style="align-items:flex-start;gap:14px">
    <span class="swatch" style="width:46px;height:46px;border-radius:12px;background:<?= e($team['color']) ?>;display:grid;place-items:center;color:#fff"><?= icon('users', 'lg') ?></span>
    <div><h1><?= e($team['name']) ?></h1><p class="sub"><?= e($team['description'] ?: count($members) . ' integrantes') ?></p></div>
  </div>
  <?php if ($canManage): ?>
  <div class="page-actions">
    <button class="btn" data-dialog="dlg-edit-team"><?= icon('edit') ?> Editar</button>
    <div class="menu-wrap">
      <button class="btn btn--icon" data-menu aria-label="Más acciones" aria-haspopup="true" aria-expanded="false"><?= icon('more') ?></button>
      <div class="menu" role="menu">
        <?php if (!$team['archived_at']): ?><button role="menuitem" data-call="/api/teams/<?= $tid ?>/archive" data-confirm="¿Archivar el equipo «<?= e($team['name']) ?>»? Sus proyectos y tareas se conservan." data-confirm-label="Archivar" data-confirm-style="primary"><?= icon('archive') ?> Archivar equipo</button><?php endif; ?>
        <?php if ($canDelete): ?><button role="menuitem" class="danger" data-call="/api/teams/<?= $tid ?>" data-method="DELETE" data-confirm="¿Eliminar definitivamente «<?= e($team['name']) ?>»? Los proyectos y tareas NO se eliminan: solo pierden la relación con el equipo." data-confirm-label="Eliminar equipo"><?= icon('trash') ?> Eliminar equipo</button><?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<div class="split">
  <div class="stack">
    <section class="card">
      <div class="card-head"><h2><?= icon('users') ?> Integrantes <span class="muted">(<?= count($members) ?>)</span></h2>
        <?php if ($canManage && !$team['archived_at']): ?><button class="btn btn--sm btn--primary" data-dialog="dlg-add-member"><?= icon('plus', 'sm') ?> Añadir</button><?php endif; ?></div>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Persona</th><th>Rol en el equipo</th><th class="hide-sm">Responsabilidad</th><th class="hide-sm">Horas/semana</th><th class="hide-sm">Tareas abiertas</th><th><span class="sr-only">Acciones</span></th></tr></thead>
          <tbody>
          <?php foreach ($members as $m): $uid = (int)$m['id']; ?>
            <tr>
              <td><a class="person" href="<?= e(url('/people/' . $uid)) ?>"><?= avatar($m, 'sm') ?><span><strong style="color:var(--text)"><?= e($m['name']) ?></strong><?= !empty($m['is_ghost']) ? ' <span class="badge warn">Fantasma</span>' : '' ?><br><span class="muted small"><?= e($m['title'] ?: $m['email']) ?></span></span></a></td>
              <td>
                <?php if ($canManage): ?>
                  <label class="sr-only" for="tr-<?= $uid ?>">Rol de <?= e($m['name']) ?></label>
                  <select class="select sm" id="tr-<?= $uid ?>" name="team_role" data-patch="/api/teams/<?= $tid ?>/members/<?= $uid ?>" style="width:auto">
                    <option value="member"<?= selected('member', $m['team_role']) ?>>Integrante</option>
                    <option value="coordinator"<?= selected('coordinator', $m['team_role']) ?>>Coordinación</option>
                  </select>
                <?php else: ?><span class="badge<?= $m['team_role'] === 'coordinator' ? ' dark' : '' ?>"><?= $m['team_role'] === 'coordinator' ? 'Coordinación' : 'Integrante' ?></span><?php endif; ?>
              </td>
              <td class="hide-sm">
                <?php if ($canManage): ?>
                  <label class="sr-only" for="trs-<?= $uid ?>">Responsabilidad de <?= e($m['name']) ?></label>
                  <input class="inline-input" id="trs-<?= $uid ?>" name="responsibility" value="<?= e($m['responsibility']) ?>" placeholder="p. ej. Diseño, logística…" maxlength="160" data-patch="/api/teams/<?= $tid ?>/members/<?= $uid ?>">
                <?php else: ?><?= e($m['responsibility'] ?: '—') ?><?php endif; ?>
              </td>
              <td class="hide-sm"><?= $m['weekly_hours'] !== null ? e(rtrim(rtrim((string)$m['weekly_hours'], '0'), '.')) . ' h' : '<span class="muted">—</span>' ?></td>
              <td class="hide-sm"><?= (int)$m['open_tasks'] ?></td>
              <td style="text-align:right">
                <?php if ($canManage || $uid === \Borealis\Core\Auth::id()): ?>
                  <button class="btn btn--ghost btn--icon btn--sm" data-call="/api/teams/<?= $tid ?>/members/<?= $uid ?>" data-method="DELETE" data-confirm="¿Quitar a <?= e($m['name']) ?> del equipo? Conserva su cuenta y sus tareas." data-confirm-label="Quitar" aria-label="Quitar a <?= e($m['name']) ?>"><?= icon('x', 'sm') ?></button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="card">
      <div class="card-head"><h2><?= icon('folder') ?> Proyectos del equipo</h2>
        <?php if ($canManage && $linkable && !$team['archived_at']): ?><button class="btn btn--sm" data-dialog="dlg-link-project"><?= icon('link', 'sm') ?> Asignar proyecto</button><?php endif; ?></div>
      <ul class="list">
        <?php foreach ($projects as $p): ?>
          <li>
            <a class="truncate" style="flex:1;font-weight:600;color:var(--text)" href="<?= e(url('/projects/' . $p['id'])) ?>"><?= e($p['name']) ?></a>
            <?php if ($p['archived_at']): ?><span class="badge">Archivado</span><?php endif; ?>
            <span class="muted small hide-sm"><?= (int)$p['done'] ?>/<?= (int)$p['total'] ?></span>
            <div style="width:120px"><div class="progress"><span style="--p:<?= (int)$p['progress'] ?>%"></span></div></div>
          </li>
        <?php endforeach; ?>
        <?php if (!$projects): ?><li class="empty">Este equipo aún no tiene proyectos.</li><?php endif; ?>
      </ul>
    </section>
  </div>

  <section class="card">
    <div class="card-head"><h2><?= icon('chart') ?> Distribución del trabajo</h2></div>
    <div class="card-body">
      <?php if ($totalOpen): ?>
        <div class="bars">
          <?php $max = max(array_column($members, 'open_tasks') ?: [1]); foreach ($members as $m): ?>
            <div class="bar-row"><span class="truncate"><?= e($m['name']) ?></span><span class="track"><span class="fill" style="--w:<?= pct((int)$m['open_tasks'], max(1, $max)) ?>%;--c:<?= e($m['avatar_color']) ?>"></span></span><span class="n"><?= (int)$m['open_tasks'] ?></span></div>
          <?php endforeach; ?>
        </div>
        <p class="muted small mt" style="margin-bottom:0">Tareas abiertas en los proyectos del equipo. Sirve para equilibrar la carga, no para comparar personas.</p>
      <?php else: ?><p class="muted" style="margin:0">No hay tareas abiertas asignadas en los proyectos del equipo.</p><?php endif; ?>
    </div>
  </section>
</div>

<?php if ($canManage): ?>
<dialog class="modal" id="dlg-edit-team" aria-labelledby="dlg-edit-team-title">
  <form data-api="/api/teams/<?= $tid ?>" data-method="PATCH" data-close>
    <div class="modal-head"><h2 id="dlg-edit-team-title">Editar equipo</h2><button type="button" class="btn btn--ghost btn--icon" data-close-dialog aria-label="Cerrar"><?= icon('x') ?></button></div>
    <div class="modal-body">
      <div class="form-error" data-form-error></div>
      <div class="field"><label for="et-name">Nombre</label><input class="input" id="et-name" name="name" value="<?= e($team['name']) ?>" required maxlength="120"><span class="error" data-error-for="name"></span></div>
      <div class="field"><label for="et-desc">Descripción</label><textarea class="textarea" id="et-desc" name="description" rows="3"><?= e($team['description']) ?></textarea></div>
      <div class="field"><label for="et-color">Color</label><input class="color-input" type="color" id="et-color" name="color" value="<?= e($team['color']) ?>"></div>
      <div class="field"><input type="hidden" name="is_flexible" value="0"><label class="check"><input type="checkbox" id="et-flex" name="is_flexible" value="1"<?= checked((int)($team['is_flexible'] ?? 0) === 1) ?>> Equipo polivalente: sus integrantes se pueden mover entre tarjetas en el tablero de los proyectos donde participa</label></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-close-dialog>Cancelar</button><button type="submit" class="btn btn--primary">Guardar cambios</button></div>
  </form>
</dialog>

<dialog class="modal" id="dlg-add-member" aria-labelledby="dlg-add-member-title">
  <div class="modal-head"><h2 id="dlg-add-member-title">Añadir integrante</h2><button type="button" class="btn btn--ghost btn--icon" data-close-dialog aria-label="Cerrar"><?= icon('x') ?></button></div>
  <div class="modal-body">
    <nav class="segmented mb" aria-label="Cómo añadir">
      <a href="#" aria-current="page" data-am-tab="am-existing">Ya está en Borealis</a>
      <?php if ($canInvite): ?><a href="#" data-am-tab="am-new">Crear su cuenta</a><a href="#" data-am-tab="am-invite">Invitar con enlace</a><?php endif; ?>
    </nav>
    <div id="am-existing" data-am-panel>
    <?php if ($candidates): ?>
    <form data-api="/api/teams/<?= $tid ?>/members" data-close data-reset>
      <div class="form-error" data-form-error></div>
      <div class="field"><label for="am-user">Persona del espacio</label>
        <select class="select" id="am-user" name="user_id" required><option value="">Elige una persona…</option><?php foreach ($candidates as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?> · <?= e($c['email']) ?></option><?php endforeach; ?></select>
        <span class="error" data-error-for="user_id"></span></div>
      <div class="form-row">
        <div class="field"><label for="am-role">Rol</label><select class="select" id="am-role" name="team_role"><option value="member">Integrante</option><option value="coordinator">Coordinación</option></select></div>
        <div class="field"><label for="am-resp">Responsabilidad</label><input class="input" id="am-resp" name="responsibility" maxlength="160" placeholder="Opcional"></div>
      </div>
      <div class="form-actions"><button type="submit" class="btn btn--primary">Añadir al equipo</button></div>
    </form>
    <?php else: ?><p class="muted">Todas las personas del espacio ya forman parte de este equipo.<?= $canInvite ? ' Usa «Crear su cuenta» para sumar a alguien nuevo.' : '' ?></p><?php endif; ?>
    </div>
    <?php if ($canInvite): ?>
    <div id="am-new" data-am-panel hidden>
      <p class="muted small">Creas la cuenta ahora y la persona queda en el equipo al instante. Después le pasas el correo y la contraseña temporal.</p>
      <form data-api="/api/members" data-reset data-show-link>
        <input type="hidden" name="team_id" value="<?= $tid ?>">
        <div class="form-error" data-form-error></div>
        <div class="form-row">
          <div class="field"><label for="nu-name">Nombre</label><input class="input" id="nu-name" name="name" required maxlength="120"><span class="error" data-error-for="name"></span></div>
          <div class="field"><label for="nu-email">Correo</label><input class="input" type="email" id="nu-email" name="email" required><span class="error" data-error-for="email"></span></div>
        </div>
        <div class="form-row">
          <div class="field"><label for="nu-pass">Contraseña temporal</label><input class="input" id="nu-pass" name="password" required minlength="8" autocomplete="off" data-generate-password><span class="error" data-error-for="password"></span></div>
          <div class="field"><label for="nu-role">Rol en el espacio</label><select class="select" id="nu-role" name="role_id"><?php foreach ($roles as $r): if ($r['role_key'] === 'owner') continue; ?><option value="<?= (int)$r['id'] ?>"<?= selected($r['role_key'], 'member') ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="field"><label for="nu-h">Horas semanales disponibles</label><input class="input hours-input" type="number" id="nu-h" name="weekly_hours" min="0" max="168" step="0.5" placeholder="p. ej. 15"><span class="hint">Se usan para la capacidad de cada sprint.</span><span class="error" data-error-for="weekly_hours"></span></div>
        <div class="form-actions"><button type="submit" class="btn btn--primary">Crear cuenta y añadir</button></div>
        <div class="notice" data-invite-link hidden></div>
      </form>
    </div>
    <div id="am-invite" data-am-panel hidden>
      <p class="muted small">Borealis no envía correos. Se genera un enlace (válido 14 días) que copias y envías tú; al abrirlo, la persona crea su cuenta y entra al equipo.</p>
      <form data-api="/api/invitations" data-reset data-show-link>
        <input type="hidden" name="team_id" value="<?= $tid ?>">
        <div class="form-error" data-form-error></div>
        <div class="form-row">
          <div class="field"><label for="inv-email">Correo</label><input class="input" type="email" id="inv-email" name="email" required><span class="error" data-error-for="email"></span></div>
          <div class="field"><label for="inv-role">Rol en el espacio</label><select class="select" id="inv-role" name="role_id"><?php foreach ($roles as $r): if ($r['role_key'] === 'owner') continue; ?><option value="<?= (int)$r['id'] ?>"<?= selected($r['role_key'], 'member') ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="field"><label for="inv-h">Horas semanales disponibles</label><input class="input hours-input" type="number" id="inv-h" name="weekly_hours" min="0" max="168" step="0.5" placeholder="p. ej. 15"><span class="error" data-error-for="weekly_hours"></span></div>
        <div class="form-actions"><button type="submit" class="btn">Generar enlace</button></div>
        <div class="notice" data-invite-link hidden></div>
      </form>
    </div>
    <?php endif; ?>
  </div>
</dialog>

<?php if ($linkable): ?>
<dialog class="modal" id="dlg-link-project" aria-labelledby="dlg-link-project-title">
  <form data-api="/api/teams/<?= $tid ?>/projects" data-close>
    <div class="modal-head"><h2 id="dlg-link-project-title">Asignar proyecto al equipo</h2><button type="button" class="btn btn--ghost btn--icon" data-close-dialog aria-label="Cerrar"><?= icon('x') ?></button></div>
    <div class="modal-body">
      <div class="form-error" data-form-error></div>
      <div class="field"><label for="lp-project">Proyecto</label><select class="select" id="lp-project" name="project_id" required><?php foreach ($linkable as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?><?= $p['team_name'] ? ' (ahora en ' . e($p['team_name']) . ')' : '' ?></option><?php endforeach; ?></select></div>
      <p class="muted small">Las personas del equipo obtienen acceso al proyecto como colaboradoras; la coordinación, como responsable.</p>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-close-dialog>Cancelar</button><button type="submit" class="btn btn--primary">Asignar</button></div>
  </form>
</dialog>
<?php endif; ?>
<?php endif; ?>
