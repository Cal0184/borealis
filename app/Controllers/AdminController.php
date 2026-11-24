<?php
declare(strict_types=1);

namespace Borealis\Controllers;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Migrator;
use Borealis\Core\Request;
use Borealis\Core\Response;
use Borealis\Core\View;
use Borealis\Security\Permissions;
use Borealis\Security\Policy;
use Borealis\Services\Backup;
use Borealis\Services\Teams;
use Borealis\Services\Workspaces;

final class AdminController
{
    private function guard(string $perm): void
    {
        if (!Policy::can($perm)) throw HttpException::forbidden('Esta sección requiere permisos de administración.');
    }

    private function base(string $section): array
    {
        return ['title' => 'Administración', 'nav' => 'admin', 'section' => $section, 'ws' => Policy::workspace()];
    }

    public function settings(Request $r): Response
    {
        if (!Policy::can('workspace.manage')) {
            if (Policy::can('members.manage')) return Response::redirect(url('/admin/members'));
            if (Policy::can('roles.manage')) return Response::redirect(url('/admin/roles'));
            $this->guard('workspace.manage');
        }
        return View::page('admin/settings', $this->base('settings') + ['priorities' => Workspaces::priorities()]);
    }

    public function members(Request $r): Response
    {
        $this->guard('members.manage');
        return View::page('admin/members', $this->base('members') + [
            'members' => Workspaces::members(), 'roles' => Workspaces::roles('workspace'), 'invites' => Workspaces::pendingInvitations(), 'teams' => Teams::list(),
            'isOwner' => Policy::workspace()['role_key'] === 'owner',
            'instanceAdmin' => $ia = (bool)(int)(Auth::user()['is_instance_admin'] ?? 0), 'ghosts' => $ia ? \Borealis\Services\Ghosts::list() : [],
        ]);
    }

    public function roles(Request $r): Response
    {
        $this->guard('roles.manage');
        return View::page('admin/roles', $this->base('roles') + [
            'wsRoles' => Workspaces::roles('workspace'), 'projectRoles' => Workspaces::roles('project'),
            'wsCatalog' => Permissions::WORKSPACE, 'projectCatalog' => Permissions::PROJECT,
        ]);
    }

    public function audit(Request $r): Response
    {
        $this->guard('audit.view');
        $where = 'a.workspace_id = ?';
        $params = [Policy::wsId()];
        if ($r->q('user_id')) { $where .= ' AND a.user_id = ?'; $params[] = (int)$r->q('user_id'); }
        if ($r->q('type')) { $where .= ' AND a.entity_type = ?'; $params[] = (string)$r->q('type'); }
        $page = max(1, (int)$r->q('page', 1));
        $params[] = 51;
        $params[] = ($page - 1) * 50;
        $rows = Database::get()->all("SELECT a.*, u.name AS user_name, u.avatar_color, p.name AS project_name FROM activity a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN projects p ON p.id = a.project_id WHERE $where ORDER BY a.id DESC LIMIT ? OFFSET ?", $params);
        return View::page('admin/audit', $this->base('audit') + ['rows' => array_slice($rows, 0, 50), 'hasMore' => count($rows) > 50, 'page' => $page, 'members' => Workspaces::members(), 'filters' => $r->query]);
    }

    public function data(Request $r): Response
    {
        $this->guard('workspace.manage');
        $instanceAdmin = (bool)(int)(Auth::user()['is_instance_admin'] ?? 0);
        $m = new Migrator(Database::get()->pdo);
        return View::page('admin/data', $this->base('data') + [
            'instanceAdmin' => $instanceAdmin, 'backups' => $instanceAdmin ? Backup::list() : [],
            'applied' => $m->applied(), 'pending' => array_keys($m->pending()), 'imports' => $this->imports(),
        ]);
    }

    private function imports(): ?array
    {
        try {
            return Database::get()->all('SELECT li.*, u.name AS user_name FROM legacy_imports li LEFT JOIN users u ON u.id = li.imported_by WHERE li.workspace_id = ? ORDER BY li.id DESC', [Policy::wsId()]);
        } catch (\PDOException) {
            return null; // migration 002 not applied yet
        }
    }

    public function exportJson(Request $r): Response
    {
        $json = Backup::exportWorkspace();
        return new Response($json, 200, ['Content-Type' => 'application/json; charset=utf-8', 'Content-Disposition' => 'attachment; filename="borealis-espacio-' . today() . '.json"', 'Cache-Control' => 'no-store']);
    }

    public function downloadBackup(Request $r): Response
    {
        if (!(int)(Auth::user()['is_instance_admin'] ?? 0)) throw HttpException::forbidden();
        $name = basename($r->param('name'));
        if (!preg_match('/^borealis-[0-9]{8}-[0-9]{6}-[a-z0-9-]+\.sql$/', $name)) throw HttpException::notFound();
        $path = Backup::dir() . '/' . $name;
        if (!is_file($path)) throw HttpException::notFound();
        return Response::download($path, $name, 'application/sql');
    }
}
