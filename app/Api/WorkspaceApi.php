<?php
declare(strict_types=1);

namespace Borealis\Api;

use Borealis\Core\Auth;
use Borealis\Core\HttpException;
use Borealis\Core\Request;
use Borealis\Core\Response;
use Borealis\Core\Validator;
use Borealis\Security\AccountPolicy;
use Borealis\Security\Policy;
use Borealis\Services\Avatars;
use Borealis\Services\Backup;
use Borealis\Services\Notifications;
use Borealis\Services\Users;
use Borealis\Services\Workspaces;

final class WorkspaceApi
{
    /** 1.14 · new secret for /cron/run (the old address stops working). Installation administration only. */
    public function rotateCronToken(Request $r): Response
    {
        if (!(int)(\Borealis\Core\Auth::user()['is_instance_admin'] ?? 0)) throw \Borealis\Core\HttpException::forbidden('Solo la administración de la instalación.');
        \Borealis\Services\Scheduler::rotateToken();
        \Borealis\Services\Activity::log('settings', 'settings', null, 'Generó una dirección nueva para la tarea programada (/cron/run)', null, null, [], null, ['category' => 'settings']);
        return Response::ok(['redirect' => url('/admin/notifications')], 'Dirección nueva generada: actualiza la tarea programada');
    }

    public function switch(Request $r): Response
    {
        Workspaces::switchTo((int)$r->input('workspace_id'));
        return Response::ok(['redirect' => url('/')], 'Cambiaste de espacio');
    }

    public function create(Request $r): Response
    {
        // 1.4.1: only the installation's administration, unless config app.allow_workspace_creation = true.
        AccountPolicy::authorizeCreateWorkspace();
        $v = Validator::check($r->input(), ['name' => 'required,string,max:120']);
        $id = Workspaces::create($v['name'], Auth::id());
        Workspaces::switchTo($id);
        return Response::ok(['id' => $id, 'redirect' => url('/')], 'Espacio creado');
    }

    public function update(Request $r): Response
    {
        Workspaces::rename((string)$r->input('name'));
        return Response::ok();
    }

    public function createUser(Request $r): Response
    {
        $in = $r->input();
        $res = Workspaces::createUser($in);
        if (isset($res['invitation'])) {
            // The e-mail already has an account: it joins only by accepting this invitation with its own password.
            $inv = $res['invitation'];
            return Response::ok($inv + [
                'invited' => true,
                'share_text' => "Te invito a unirte a Borealis. Abre este enlace y entra con tu cuenta de siempre para aceptar (vale 14 días):\n" . $inv['link'],
                'share_title' => 'Esa persona ya tiene cuenta en Borealis, así que no se creó otra ni se cambió su contraseña. Se generó una invitación: copia este mensaje y envíaselo para que la acepte.',
            ], 'Invitación creada');
        }
        $id = $res['id'];
        $login = absolute_url('/login');
        // Text the admin can copy and send: Borealis does not send e-mail.
        $share = "Te creé una cuenta en Borealis.\nEntra en: $login\nCorreo: " . mb_strtolower((string)($in['email'] ?? '')) . "\nContraseña temporal: " . (string)($in['password'] ?? '') . "\nCámbiala en tu perfil al entrar.";
        return Response::ok(['id' => $id, 'share_text' => $share, 'share_title' => 'Cuenta creada. Copia estos datos y envíaselos a la persona:'], 'Integrante incorporado');
    }

    public function updateMember(Request $r): Response
    {
        $id = $r->intParam('id');
        $account = array_intersect_key($r->input(), array_flip(['name', 'email', 'title']));
        if ($account) {
            Workspaces::updateAccount($id, $account);
            if (!$r->has('role_id') && !$r->has('status') && !$r->has('weekly_hours')) return Response::ok([], 'Cuenta actualizada');
        }
        if ($r->has('role_id')) Workspaces::changeMemberRole($id, (int)$r->input('role_id'));
        if ($r->has('status')) Workspaces::setMemberStatus($id, (string)$r->input('status'));
        if ($r->has('weekly_hours')) Workspaces::setWeeklyHours($id, $r->input('weekly_hours'));
        return Response::ok([], $r->has('weekly_hours') ? 'Horas semanales guardadas' : 'Guardado');
    }

    public function resetPassword(Request $r): Response
    {
        Workspaces::resetPassword($r->intParam('id'), (string)$r->input('password'));
        return Response::ok([], 'Contraseña restablecida');
    }

    /** 1.16.3 · Administración › Usuarios › «Eliminar usuario» (with a full backup first). */
    public function deleteMember(Request $r): Response
    {
        if (!filter_var($r->input('confirm'), FILTER_VALIDATE_BOOLEAN)) throw HttpException::invalid(['confirm' => 'Marca la casilla para confirmar.']);
        $to = $r->input('reassign_to');
        $res = \Borealis\Services\MemberDeletion::delete($r->intParam('id'), is_numeric($to) && (int)$to > 0 ? (int)$to : null, (string)$r->input('reason', ''));
        $msg = "Se eliminó a {$res['name']}" . ($res['reassigned_to'] ? ". Su trabajo abierto pasó a {$res['reassigned_to']}" : '') . ". Copia de seguridad: {$res['backup']}";
        return Response::ok($res + ['redirect' => url('/admin/members')], $msg);
    }

    /** 1.16.3 · «Correo al soporte»: the address (and an optional note) set in Administración › Ajustes. */
    public function supportSettings(Request $r): Response
    {
        $saved = \Borealis\Services\Support::save($r->input());
        return Response::ok($saved, $saved['email'] ? 'Correo de soporte guardado' : 'Se quitó el correo de soporte');
    }

    public function invite(Request $r): Response
    {
        $inv = Workspaces::invite($r->input());
        $inv['share_text'] = "Te invito a unirte a Borealis. Abre este enlace para crear tu cuenta (vale 14 días):\n" . $inv['link'];
        $inv['share_title'] = 'Invitación lista. Borealis no envía correos: copia este mensaje y envíalo por WhatsApp, correo o chat.';
        return Response::ok($inv, 'Invitación creada');
    }

    public function renewInvite(Request $r): Response
    {
        $inv = Workspaces::renewInvitation($r->intParam('id'));
        $inv['share_text'] = "Te invito a unirte a Borealis. Abre este enlace para crear tu cuenta (vale 14 días):\n" . $inv['link'];
        $inv['share_title'] = 'Enlace nuevo para ' . $inv['email'] . '. El anterior deja de funcionar. Copia este mensaje y envíalo:';
        $inv['share_dialog'] = true;
        return Response::ok($inv, 'Enlace nuevo generado');
    }

    public function revokeInvite(Request $r): Response
    {
        Workspaces::revokeInvitation($r->intParam('id'));
        return Response::ok([], 'Invitación anulada');
    }

    public function saveRole(Request $r): Response
    {
        $id = Workspaces::saveRole(isset($r->params['id']) ? $r->intParam('id') : null, $r->input());
        return Response::ok(['id' => $id]);
    }

    public function deleteRole(Request $r): Response
    {
        Workspaces::deleteRole($r->intParam('id'));
        return Response::ok([], 'Rol eliminado');
    }

    public function savePriority(Request $r): Response
    {
        $id = Workspaces::savePriority(isset($r->params['id']) ? $r->intParam('id') : null, $r->input());
        return Response::ok(['id' => $id]);
    }

    public function deletePriority(Request $r): Response
    {
        Workspaces::deletePriority($r->intParam('id'));
        return Response::ok([], 'Prioridad eliminada');
    }

    public function backup(Request $r): Response
    {
        if (!(int)(Auth::user()['is_instance_admin'] ?? 0)) throw HttpException::forbidden('Solo la administración de la instalación puede crear copias completas.');
        $file = Backup::create(null, 'manual');
        return Response::ok(['file' => basename($file)], 'Copia de seguridad creada');
    }

    /** 1.16.4 · Administración › Datos › «Comprobar ahora»: can storage/ be downloaded from the web? */
    public function exposureCheck(Request $r): Response
    {
        if (!(int)(Auth::user()['is_instance_admin'] ?? 0)) throw HttpException::forbidden('Solo la administración de la instalación.');
        $res = \Borealis\Services\Exposure::check();
        $msg = match ($res['status']) { 'exposed' => 'Peligro: storage/ se puede descargar desde internet', 'safe' => 'Bien: las carpetas privadas no se pueden descargar', default => 'No se pudo comprobar automáticamente' };
        return Response::ok(['status' => $res['status'], 'reload' => true], $msg);
    }

    /**
     * 1.16.5 · Administración › Datos › «Proteger ahora»: a copy of the database, then the data folder moves
     * where the web cannot reach it, the encryption key is renewed and the check runs again. Nothing to do by FTP.
     */
    public function protectStorage(Request $r): Response
    {
        if (!(int)(Auth::user()['is_instance_admin'] ?? 0)) throw HttpException::forbidden('Solo la administración de la instalación.');
        @set_time_limit(0);
        $done = [];
        $where = \Borealis\Services\StorageMover::placement();
        if ($where !== 'outside') {
            try {
                $m = \Borealis\Services\StorageMover::protect();
            } catch (\RuntimeException $e) {
                throw HttpException::conflict($e->getMessage());
            }
            $done[] = $m['kind'] === 'outside' ? 'los datos quedaron fuera de la carpeta web' : 'los datos quedaron en una carpeta con nombre secreto';
            \Borealis\Services\Activity::security('storage_moved', (int)Auth::id(), 'Movió la carpeta de datos privados (' . ($m['kind'] === 'outside' ? 'fuera de la web' : 'nombre secreto') . ')', $r->ip());
        }
        $rotated = false;
        if (\Borealis\Security\Secrets::rotatable() && ($r->input('rotate', '1') !== '0')) {
            Backup::create(null, 'pre-llave');
            \Borealis\Security\Secrets::rotate();
            $rotated = true;
            $done[] = 'la llave de cifrado es nueva';
            \Borealis\Services\Activity::security('key_rotated', (int)Auth::id(), 'Renovó la llave de cifrado', $r->ip());
        }
        $res = \Borealis\Services\Exposure::check();
        $msg = ($done ? 'Listo: ' . implode(' y ', $done) . '. ' : '') . match ($res['status']) { 'exposed' => 'Todavía se puede descargar algo: mira el detalle.', 'safe' => 'Comprobado: ya no se puede descargar.', default => 'No se pudo comprobar desde el servidor.' };
        return Response::ok(['status' => $res['status'], 'rotated' => $rotated, 'reload' => true], $msg);
    }

    /** Applies pending migrations from the browser (for hosting without shell access). Always backs up first. */
    public function migrate(Request $r): Response
    {
        if (!(int)(Auth::user()['is_instance_admin'] ?? 0)) throw HttpException::forbidden('Solo la administración de la instalación puede actualizar la base de datos.');
        $m = new \Borealis\Core\Migrator(\Borealis\Core\Database::get()->pdo);
        if (!$m->pending()) return Response::ok(['applied' => []], 'La base de datos ya estaba al día');
        $file = Backup::create(null, 'pre-update');
        $ran = $m->migrate();
        return Response::ok(['applied' => $ran, 'backup' => basename($file)], 'Base de datos actualizada (' . count($ran) . ' migraciones). Copia previa: ' . basename($file));
    }

    /** Imports a Borealis v0.2 export (borealis.json or a ZIP of the old folder). A full backup is made first. */
    public function importLegacy(Request $r): Response
    {
        Policy::authorize('workspace.manage');
        $f = $_FILES['file'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
            throw HttpException::invalid(['file' => 'Selecciona el archivo borealis.json o el ZIP de la versión anterior.']);
        }
        $name = strtolower((string)$f['name']);
        if (!str_ends_with($name, '.json') && !str_ends_with($name, '.zip')) throw HttpException::invalid(['file' => 'Usa el archivo borealis.json o un .zip de la versión anterior.']);
        [$data, $reader, $hash] = \Borealis\Services\LegacyImport::open((string)$f['tmp_name'], $name);
        \Borealis\Services\LegacyImport::assertNotImported($hash);
        $backup = Backup::create(null, 'pre-import');
        $rep = \Borealis\Services\LegacyImport::run($data, $reader, $hash);
        $msg = "Importados {$rep['projects']} proyectos, {$rep['tasks']} tareas y {$rep['comments']} comentarios";
        return Response::ok(['report' => $rep, 'backup' => basename($backup)], $msg);
    }

    /** 1.7: profile photo (already cropped to a square in the browser). */
    public function photo(Request $r): Response
    {
        Avatars::save($r->files['photo'] ?? null);
        return Response::ok(['url' => Avatars::urlFor(Auth::id(), 'lg')], 'Fotografía guardada');
    }

    public function removePhoto(Request $r): Response
    {
        Avatars::remove();
        return Response::ok([], 'Fotografía eliminada');
    }

    public function profile(Request $r): Response
    {
        \Borealis\Core\Database::get()->transaction(function () use ($r) {
            Users::updateProfile($r->input());
            if ($r->has('weekly_hours')) Workspaces::setWeeklyHours(Auth::id(), $r->input('weekly_hours'));
        });
        return Response::ok();
    }

    public function password(Request $r): Response
    {
        if ((string)$r->input('password') !== (string)$r->input('password_confirm')) {
            throw HttpException::invalid(['password_confirm' => 'Las contraseñas no coinciden.']);
        }
        Users::changePassword((string)$r->input('current_password'), (string)$r->input('password'));
        return Response::ok([], 'Contraseña actualizada');
    }

    public function prefs(Request $r): Response
    {
        return Response::ok(['prefs' => Users::savePrefs($r->input())], 'Preferencias guardadas');
    }

    public function read(Request $r): Response
    {
        Notifications::markRead($r->intParam('id'));
        return Response::ok(['unread' => Notifications::unreadCount()], '');
    }

    /**
     * 1.15 · live notices for an open page (notify-live.js): unread count and anything newer than
     * «after». Read-only and cheap; the page asks every minute while it is visible, at once when it
     * becomes visible again or the connection returns, and when the service worker received a push.
     */
    public function notificationStatus(Request $r): Response
    {
        $after = max(0, (int)$r->q('after'));
        $db = \Borealis\Core\Database::get();
        $rows = $db->all('SELECT id, type, message, url, created_at FROM notifications WHERE user_id = ? AND workspace_id = ? AND read_at IS NULL AND id > ? ORDER BY id DESC LIMIT 5',
            [\Borealis\Core\Auth::id(), Policy::wsId(), $after]);
        $latest = (int)$db->value('SELECT COALESCE(MAX(id), 0) FROM notifications WHERE user_id = ? AND workspace_id = ?', [\Borealis\Core\Auth::id(), Policy::wsId()]);
        $items = array_map(fn($n) => ['id' => (int)$n['id'], 'title' => Notifications::TYPES[$n['type']] ?? 'Borealis', 'body' => $n['message'],
            'url' => url('/notifications/' . (int)$n['id'] . '/open')], $rows);
        return Response::ok(['unread' => Notifications::unreadCount(), 'latest' => $latest, 'items' => $after ? $items : []], '');
    }

    /** 1.15 · star / unstar a project, task or team (sidebar «Favoritos»). */
    public function favorite(Request $r): Response
    {
        $in = $r->input();
        $on = \Borealis\Services\Navigation::toggleFavorite((string)($in['type'] ?? ''), (int)($in['id'] ?? 0));
        return Response::ok(['favorite' => $on], $on ? 'Agregado a favoritos' : 'Quitado de favoritos');
    }

    /** 1.15 · automations (administration) */
    public function createAutomation(Request $r): Response
    {
        $id = \Borealis\Services\Automations::create($r->input());
        return Response::ok(['id' => $id], 'Automatización activada');
    }

    public function updateAutomation(Request $r): Response
    {
        \Borealis\Services\Automations::update($r->intParam('id'), $r->input());
        return Response::ok([], 'Automatización guardada');
    }

    public function deleteAutomation(Request $r): Response
    {
        \Borealis\Services\Automations::delete($r->intParam('id'));
        return Response::ok([], 'Automatización eliminada');
    }

    /** 1.15 · saved task filters */
    public function saveView(Request $r): Response
    {
        $id = \Borealis\Services\SavedViews::create($r->input());
        return Response::ok(['id' => $id], 'Vista guardada');
    }

    public function deleteView(Request $r): Response
    {
        \Borealis\Services\SavedViews::delete($r->intParam('id'));
        return Response::ok([], 'Vista borrada');
    }

    /** 1.15 · favorites and recently opened items, for the quick search before typing. */
    public function navRecent(Request $r): Response
    {
        return Response::ok(['favorites' => \Borealis\Services\Navigation::favorites(), 'recent' => \Borealis\Services\Navigation::recent(8)], '');
    }

    public function readAll(Request $r): Response
    {
        Notifications::markAllRead();
        Policy::wsId();
        return Response::ok(['unread' => 0], 'Notificaciones leídas');
    }
}
