<?php
declare(strict_types=1);

namespace Borealis\Controllers;

use Borealis\Core\Auth;
use Borealis\Core\Csrf;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Migrator;
use Borealis\Core\Request;
use Borealis\Core\Response;
use Borealis\Core\View;
use Borealis\Services\Backup;

/**
 * After new files are uploaded, the database may be behind the code. Until the pending
 * migrations run, every page would fail, so only sign-in and this update screen are served.
 */
final class UpdateGate
{
    /** Returns a response when the update screen must be shown instead of the requested page. */
    public static function check(Request $r): ?Response
    {
        $pending = array_keys((new Migrator(Database::get()->pdo))->pending());
        if (!$pending) return null;
        if (in_array($r->path, ['/login', '/logout'], true)) return null;
        $user = Auth::user();
        if (!$user) {
            if ($r->isApi()) throw HttpException::unauthorized();
            return Response::redirect(url('/login'));
        }
        $admin = (bool)(int)($user['is_instance_admin'] ?? 0);
        $error = null;
        if ($r->path === '/update' && $r->method === 'POST') {
            Csrf::verify($r);
            if (!$admin) throw HttpException::forbidden('Solo la administración de la instalación puede actualizar la base de datos.');
            try {
                $file = Backup::create(null, 'pre-update');
                (new Migrator(Database::get()->pdo))->migrate();
                return Response::redirect(url('/?updated=' . rawurlencode(basename($file))));
            } catch (\Throwable $e) {
                error_log('[borealis] update failed: ' . $e->getMessage());
                $error = 'No se pudo completar la actualización: ' . $e->getMessage() . ' La copia de seguridad previa quedó en storage/backups.';
                $pending = array_keys((new Migrator(Database::get()->pdo))->pending());
            }
        }
        if ($r->isApi()) throw new HttpException(503, 'Borealis se está actualizando. Recarga la página.');
        return View::page('update', ['title' => 'Actualización pendiente', 'layout' => 'auth', 'pending' => $pending, 'admin' => $admin, 'error' => $error], $error ? 500 : 503);
    }
}
