<?= partial('admin-nav', compact('ws', 'section')) ?>
<?php use Borealis\Core\Auth; ?>
<div class="page-head" style="margin-bottom:14px"><h2>Usuarios del espacio <span class="muted">(<?= count($members) ?>)</span></h2>
  <div class="page-actions"><button class="btn" data-dialog="dlg-invite"><?= icon('mail') ?> Invitar</button><button class="btn btn--primary" data-dialog="dlg-create-user"><?= icon('plus') ?> Crear cuenta</button></div></div>
<section class="card mb"><div class="table-wrap"><table class="table">
  <thead><tr><th>Persona</th><th>Rol</th><th>Horas/semana</th><th class="hide-sm">Estado</th><th class="hide-md">Último acceso</th><th><span class="sr-only">Acciones</span></th></tr></thead>
  <tbody>
  <?php foreach ($members as $m): $uid = (int)$m['id']; $self = $uid === Auth::id(); $ownerLocked = $m['role_key'] === 'owner' && !$isOwner; ?>
    <tr>
      <td><a class="person" href="<?= e(url('/people/' . $uid)) ?>"><?= avatar($m, 'sm') ?><span><strong style="color:var(--text)"><?= e($m['name']) ?></strong><?= $self ? ' <span class="badge">Tú</span>' : '' ?><?= !empty($m['is_ghost']) ? ' <span class="badge warn">Fantasma</span>' : '' ?><br><span class="muted small"><?= e($m['email']) ?></span></span></a></td>
      <td>
        <label class="sr-only" for="mr-<?= $uid ?>">Rol de <?= e($m['name']) ?></label>
        <select class="select sm" id="mr-<?= $uid ?>" name="role_id" data-patch="/api/members/<?= $uid ?>" style="width:auto"<?= $ownerLocked || $self ? ' disabled' : '' ?><?= $self ? ' title="No puedes cambiar tu propio rol"' : '' ?>>
          <?php foreach ($roles as $r): if ($r['role_key'] === 'owner' && !$isOwner && $m['role_key'] !== 'owner') continue; ?><option value="<?= (int)$r['id'] ?>"<?= selected($r['id'], $m['role_id']) ?>><?= e($r['name']) ?></option><?php endforeach; ?>
        </select>
      </td>
      <td><label class="sr-only" for="mh-<?= $uid ?>">Horas por semana de <?= e($m['name']) ?></label><input class="input sm hours-input" type="number" min="0" max="168" step="0.5" id="mh-<?= $uid ?>" name="weekly_hours" value="<?= $m['weekly_hours'] !== null ? e(rtrim(rtrim((string)$m['weekly_hours'], '0'), '.')) : '' ?>" placeholder="—" data-patch="/api/members/<?= $uid ?>"></td>
      <td class="hide-sm"><?= $m['status'] === 'active' ? '<span class="badge success">Activo</span>' : '<span class="badge warn">Suspendido</span>' ?></td>
      <td class="hide-md muted small"><?= $m['last_login_at'] ? e(time_ago($m['last_login_at'])) : 'Nunca' ?></td>
      <td style="text-align:right;white-space:nowrap">
        <?php if (!$self && !$ownerLocked): ?>
          <div class="menu-wrap"><button class="btn btn--ghost btn--icon btn--sm" data-menu aria-label="Acciones para <?= e($m['name']) ?>" aria-haspopup="true" aria-expanded="false"><?= icon('more') ?></button>
            <div class="menu" role="menu">
              <button role="menuitem" data-dialog="dlg-edit-<?= $uid ?>"><?= icon('edit') ?> Editar cuenta</button>
              <a role="menuitem" href="<?= e(url('/people/' . $uid)) ?>"><?= icon('user') ?> Ver perfil y horas</a>
              <button role="menuitem" data-dialog="dlg-pass-<?= $uid ?>"><?= icon('key') ?> Restablecer contraseña</button>
              <?php if ($m['status'] === 'active'): ?>
                <button role="menuitem" class="danger" data-call="/api/members/<?= $uid ?>" data-method="PATCH" data-body='{"status":"suspended"}' data-confirm="¿Suspender a <?= e($m['name']) ?>? No podrá entrar a este espacio; sus datos se conservan." data-confirm-label="Suspender"><?= icon('lock') ?> Suspender acceso</button>
              <?php else: ?>
                <button role="menuitem" data-call="/api/members/<?= $uid ?>" data-method="PATCH" data-body='{"status":"active"}'><?= icon('restore') ?> Reactivar</button>
              <?php endif; ?>
              <hr>
              <button role="menuitem" class="danger" data-dialog="dlg-del-<?= $uid ?>"><?= icon('trash') ?> Eliminar usuario</button>
            </div></div>
          <dialog class="modal" id="dlg-edit-<?= $uid ?>" aria-labelledby="de-<?= $uid ?>">
            <form data-api="/api/members/<?= $uid ?>" data-method="PATCH" data-close>
              <div class="modal-head"><h2 id="de-<?= $uid ?>">Editar la cuenta de <?= e($m['name']) ?></h2><button type="button" class="btn btn--ghost btn--icon" data-close-dialog aria-label="Cerrar"><?= icon('x') ?></button></div>
              <div class="modal-body"><div class="form-error" data-form-error></div>
                <div class="field"><label for="en-<?= $uid ?>">Nombre</label><input class="input" id="en-<?= $uid ?>" name="name" value="<?= e($m['name']) ?>" required maxlength="120"><span class="error" data-error-for="name"></span></div>
                <?php if (empty($m['is_ghost'])): ?><div class="field"><label for="ee-<?= $uid ?>">Correo</label><input class="input" type="email" id="ee-<?= $uid ?>" name="email" value="<?= e($m['email']) ?>" required maxlength="190"><span class="hint">Es el correo con el que inicia sesión. Avísale si lo cambias.</span><span class="error" data-error-for="email"></span></div><?php endif; ?>
                <div class="field"><label for="et-<?= $uid ?>">Cargo</label><input class="input" id="et-<?= $uid ?>" name="title" value="<?= e($m['title']) ?>" maxlength="120" placeholder="Opcional"><span class="error" data-error-for="title"></span></div>
              </div>
              <div class="modal-foot"><button type="button" class="btn" data-close-dialog>Cancelar</button><button class="btn btn--primary" type="submit">Guardar cambios</button></div>
            </form>
          </dialog>
          <dialog class="modal" id="dlg-pass-<?= $uid ?>" aria-labelledby="dp-<?= $uid ?>">
            <form data-api="/api/members/<?= $uid ?>/password" data-close data-reset>
              <div class="modal-head"><h2 id="dp-<?= $uid ?>">Nueva contraseña para <?= e($m['name']) ?></h2><button type="button" class="btn btn--ghost btn--icon" data-close-dialog aria-label="Cerrar"><?= icon('x') ?></button></div>
              <div class="modal-body"><div class="form-error" data-form-error></div>
                <div class="field"><label for="pw-<?= $uid ?>">Contraseña temporal</label><input class="input" type="text" id="pw-<?= $uid ?>" name="password" required minlength="8" autocomplete="off"><span class="hint">Compártela por un canal seguro y pide que la cambie en su perfil.</span><span class="error" data-error-for="password"></span></div></div>
              <div class="modal-foot"><button type="button" class="btn" data-close-dialog>Cancelar</button><button class="btn btn--primary" type="submit">Restablecer</button></div>
            </form>
          </dialog>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></section>
<?php // 1.16.3 · «Eliminar usuario» dialogs live outside the table, so a row never contains other people's names
foreach ($members as $m): $uid = (int)$m['id']; if ($uid === Auth::id() || ($m['role_key'] === 'owner' && !$isOwner)) continue; ?>
          <?php $imp = $impact[$uid] ?? []; ?>
          <dialog class="modal" id="dlg-del-<?= $uid ?>" aria-labelledby="dd-<?= $uid ?>">
            <form data-api="/api/members/<?= $uid ?>/delete" data-close>
              <div class="modal-head"><h2 id="dd-<?= $uid ?>">Eliminar a <?= e($m['name']) ?></h2><button type="button" class="btn btn--ghost btn--icon" data-close-dialog aria-label="Cerrar"><?= icon('x') ?></button></div>
              <div class="modal-body"><div class="form-error" data-form-error></div>
                <p>Sale de este espacio para siempre. Antes se guarda una copia de seguridad completa en Administración › Datos.</p>
                <ul class="small muted">
                  <li>Tiene <strong><?= (int)($imp['tasks'] ?? 0) ?></strong> <?= ($imp['tasks'] ?? 0) === 1 ? 'tarea abierta asignada' : 'tareas abiertas asignadas' ?><?= !empty($imp['projects']) ? ' y lidera <strong>' . (int)$imp['projects'] . '</strong> ' . ($imp['projects'] === 1 ? 'proyecto' : 'proyectos') : '' ?>.</li>
                  <li>Sus comentarios, horas registradas, tareas terminadas y el registro maestro se conservan.</li>
                  <li>Si su cuenta no se usa en otro espacio, se cierra: no podrá entrar y su nombre pasa a «Usuario eliminado».</li>
                </ul>
                <div class="field"><label for="dr-<?= $uid ?>">Pasar su trabajo abierto a</label>
                  <select class="select" id="dr-<?= $uid ?>" name="reassign_to"><option value="">Nadie: quedan sin responsable</option>
                    <?php foreach ($members as $o): if ((int)$o['id'] === $uid || $o['status'] !== 'active' || !empty($o['is_ghost'])) continue; ?><option value="<?= (int)$o['id'] ?>"><?= e($o['name']) ?></option><?php endforeach; ?>
                  </select><span class="hint">Tareas abiertas, proyectos que lidera y su lugar en esos proyectos.</span><span class="error" data-error-for="reassign_to"></span></div>
                <div class="field"><label for="dm-<?= $uid ?>">Motivo <span class="muted">(opcional, queda en el registro maestro)</span></label><input class="input" id="dm-<?= $uid ?>" name="reason" maxlength="500"></div>
                <label class="check"><input type="checkbox" name="confirm" value="1" required> Entiendo que no se puede deshacer</label><span class="error" data-error-for="confirm"></span>
              </div>
              <div class="modal-foot"><button type="button" class="btn" data-close-dialog>Cancelar</button><button class="btn btn--danger-solid" type="submit"><?= icon('trash', 'sm') ?> Eliminar usuario</button></div>
            </form>
          </dialog>
<?php endforeach; ?>

<?php if ($instanceAdmin): ?>
<section class="card mb ghost-card" id="ghosts">
  <div class="card-head"><h3><?= icon('eye') ?> Cuentas fantasma para pruebas</h3>
    <div class="row"><button class="btn btn--sm" data-dialog="dlg-ghost"><?= icon('plus', 'sm') ?> Crear fantasma</button>
    <?php if ($ghosts): ?><button class="btn btn--sm btn--danger" data-dialog="dlg-ghost-delete"><?= icon('trash', 'sm') ?> Borrar todas</button><?php endif; ?></div></div>
  <div class="card-body">
    <p class="muted small" style="margin:0 0 10px">Sirven para probar Borealis como lo vería otra persona: asígnales tareas, entra como ellas y vuelve a tu cuenta con un clic. No pueden iniciar sesión por su cuenta y solo existen en este espacio. «Entrar como» solo funciona con cuentas fantasma, nunca con personas reales.</p>
    <?php if ($ghosts): ?><ul class="list ghost-list"><?php foreach ($ghosts as $g): ?>
      <li><span class="person" style="flex:1"><?= avatar($g, 'sm') ?> <strong><?= e($g['name']) ?></strong> <span class="muted small">· <?= e($g['role_name']) ?></span></span>
        <button class="btn btn--sm btn--primary" data-call="/api/ghosts/<?= (int)$g['id'] ?>/enter"><?= icon('switch', 'sm') ?> Entrar como <?= e(strtok($g['name'], ' ')) ?></button></li>
    <?php endforeach; ?></ul>
    <?php else: ?><p class="muted" style="margin:0">Aún no hay cuentas fantasma.</p><?php endif; ?>
  </div>
</section>
<dialog class="modal" id="dlg-ghost" aria-labelledby="dg-t">
  <form data-api="/api/ghosts" data-reset data-close>
    <div class="modal-head"><h2 id="dg-t">Crear cuenta fantasma</h2><button type="button" class="btn btn--ghost btn--icon" data-close-dialog aria-label="Cerrar"><?= icon('x') ?></button></div>
    <div class="modal-body">
      <div class="form-error" data-form-error></div>
      <div class="field"><label for="g-n">Nombre</label><input class="input" id="g-n" name="name" required maxlength="120" placeholder="Por ejemplo: Diseñadora de prueba"><span class="error" data-error-for="name"></span></div>
      <div class="form-row">
        <div class="field"><label for="g-r">Rol</label><select class="select" id="g-r" name="role_id"><?php foreach ($roles as $r): if ($r['role_key'] === 'owner') continue; ?><option value="<?= (int)$r['id'] ?>"<?= selected($r['role_key'], 'member') ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="g-t">Equipo (opcional)</label><select class="select" id="g-t" name="team_id"><option value="">Ninguno</option><?php foreach ($teams as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="field"><label for="g-h">Horas por semana (opcional)</label><input class="input hours-input" type="number" id="g-h" name="weekly_hours" min="0" max="168" step="0.5"></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-close-dialog>Cancelar</button><button class="btn btn--primary" type="submit">Crear fantasma</button></div>
  </form>
</dialog>
<dialog class="modal" id="dlg-ghost-delete" aria-labelledby="dgd-t">
  <form data-api="/api/ghosts/delete-all" data-close>
    <div class="modal-head"><h2 id="dgd-t">Borrar todas las cuentas fantasma</h2><button type="button" class="btn btn--ghost btn--icon" data-close-dialog aria-label="Cerrar"><?= icon('x') ?></button></div>
    <div class="modal-body">
      <div class="form-error" data-form-error></div>
      <p>Se borrarán <strong><?= count($ghosts) ?></strong> <?= count($ghosts) === 1 ? 'cuenta fantasma' : 'cuentas fantasma' ?> de este espacio. Antes se guarda una copia de seguridad completa en Administración › Datos.</p>
      <ul class="small muted"><li>Sus tareas, comentarios e historial se conservan a nombre de «Usuario eliminado».</li><li>Las tareas que tenían asignadas quedan sin responsable.</li></ul>
      <label class="check"><input type="checkbox" name="keep_hours" value="1"> Conservar las horas que registraron</label>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-close-dialog>Cancelar</button><button class="btn btn--danger-solid" type="submit">Borrar fantasmas</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($invites): ?>
<section class="card"><div class="card-head"><h3><?= icon('mail') ?> Invitaciones pendientes</h3></div>
  <ul class="list"><?php foreach ($invites as $i): ?>
    <li><span style="flex:1"><strong><?= e($i['email']) ?></strong> <span class="muted small">· <?= e($i['role_name']) ?><?= $i['team_name'] ? ' · equipo ' . e($i['team_name']) : '' ?> · caduca <?= e(fmt_date(local_date('Y-m-d', $i['expires_at']))) ?></span></span>
      <button class="btn btn--sm" data-call="/api/invitations/<?= (int)$i['id'] ?>/renew" data-silent><?= icon('link', 'sm') ?> Nuevo enlace</button>
      <button class="btn btn--ghost btn--sm" data-call="/api/invitations/<?= (int)$i['id'] ?>" data-method="DELETE" data-confirm="¿Anular la invitación para <?= e($i['email']) ?>?" data-confirm-label="Anular">Anular</button></li>
  <?php endforeach; ?></ul></section>
<?php endif; ?>

<dialog class="modal" id="dlg-invite" aria-labelledby="di-t">
  <form data-api="/api/invitations" data-reset data-show-link>
    <div class="modal-head"><h2 id="di-t">Invitar al espacio</h2><button type="button" class="btn btn--ghost btn--icon" data-close-dialog aria-label="Cerrar"><?= icon('x') ?></button></div>
    <div class="modal-body">
      <div class="form-error" data-form-error></div>
      <div class="field"><label for="iv-e">Correo</label><input class="input" type="email" id="iv-e" name="email" required><span class="error" data-error-for="email"></span></div>
      <div class="form-row">
        <div class="field"><label for="iv-r">Rol</label><select class="select" id="iv-r" name="role_id"><?php foreach ($roles as $r): if ($r['role_key'] === 'owner') continue; ?><option value="<?= (int)$r['id'] ?>"<?= selected($r['role_key'], 'member') ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="iv-t">Equipo (opcional)</label><select class="select" id="iv-t" name="team_id"><option value="">Ninguno</option><?php foreach ($teams as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="field"><label for="iv-h">Horas semanales disponibles</label><input class="input hours-input" type="number" id="iv-h" name="weekly_hours" min="0" max="168" step="0.5" placeholder="p. ej. 15"><span class="hint">Se usan para la capacidad de cada sprint: 15 h por semana en un sprint de dos semanas son 30 h. Se pueden cambiar después en esta lista.</span><span class="error" data-error-for="weekly_hours"></span></div>
      <p class="muted small">Borealis genera un enlace personal válido 14 días. Envíalo a la persona; si ya tiene cuenta, se añadirá este espacio a esa misma cuenta.</p>
      <div class="notice" data-invite-link hidden></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-close-dialog>Cerrar</button><button class="btn btn--primary" type="submit">Crear enlace de invitación</button></div>
  </form>
</dialog>
<dialog class="modal" id="dlg-create-user" aria-labelledby="dcu-t">
  <form data-api="/api/members" data-reset data-show-link>
    <div class="modal-head"><h2 id="dcu-t">Crear cuenta</h2><button type="button" class="btn btn--ghost btn--icon" data-close-dialog aria-label="Cerrar"><?= icon('x') ?></button></div>
    <div class="modal-body">
      <div class="form-error" data-form-error></div>
      <div class="field"><label for="cu-n">Nombre</label><input class="input" id="cu-n" name="name" required maxlength="120"><span class="error" data-error-for="name"></span></div>
      <div class="field"><label for="cu-e">Correo</label><input class="input" type="email" id="cu-e" name="email" required><span class="hint">Si ya existe una cuenta con este correo, se añade al espacio sin duplicarla.</span><span class="error" data-error-for="email"></span></div>
      <div class="field"><label for="cu-p">Contraseña inicial</label><input class="input" type="text" id="cu-p" name="password" required minlength="8" autocomplete="off" data-generate-password><span class="hint">Propuesta al azar; puedes cambiarla. Borealis no envía correos: al crear la cuenta te mostramos el mensaje para copiar y enviar.</span><span class="error" data-error-for="password"></span></div>
      <div class="form-row">
        <div class="field"><label for="cu-r">Rol</label><select class="select" id="cu-r" name="role_id"><?php foreach ($roles as $r): if ($r['role_key'] === 'owner' && !$isOwner) continue; ?><option value="<?= (int)$r['id'] ?>"<?= selected($r['role_key'], 'member') ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="cu-t">Equipo (opcional)</label><select class="select" id="cu-t" name="team_id"><option value="">Ninguno</option><?php foreach ($teams as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="field"><label for="cu-h">Horas semanales disponibles</label><input class="input hours-input" type="number" id="cu-h" name="weekly_hours" min="0" max="168" step="0.5" placeholder="p. ej. 15"><span class="hint">Se usan para la capacidad de cada sprint: 15 h por semana en un sprint de dos semanas son 30 h. Se pueden cambiar después en esta lista.</span><span class="error" data-error-for="weekly_hours"></span></div>
      <div class="notice" data-invite-link hidden></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-close-dialog>Cerrar</button><button class="btn btn--primary" type="submit">Crear cuenta</button></div>
  </form>
</dialog>
