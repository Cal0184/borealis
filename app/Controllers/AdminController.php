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
        $f = self::auditFilters($r);
        $page = max(1, (int)$r->q('page', 1));
        $rows = \Borealis\Services\Activity::search($f, 51, ($page - 1) * 50);
        $verify = $r->q('verify') ? \Borealis\Services\Activity::verify() : null;
        $projects = Database::get()->all('SELECT id, name, project_key FROM projects WHERE workspace_id = ? ORDER BY name', [Policy::wsId()]);
        return View::page('admin/audit', $this->base('audit') + ['rows' => array_slice($rows, 0, 50), 'hasMore' => count($rows) > 50, 'page' => $page, 'members' => Workspaces::members(),
            'filters' => array_filter($f, fn($v) => $v !== null && $v !== ''), 'projects' => $projects, 'actions' => \Borealis\Services\Activity::actions(), 'verify' => $verify]);
    }

    /** 1.14 · the same search as a CSV file (opens in Excel / LibreOffice / Google Sheets). */
    public function auditCsv(Request $r): Response
    {
        $this->guard('audit.view');
        $rows = \Borealis\Services\Activity::search(self::auditFilters($r), 5000, 0);
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Fecha (UTC)', 'Persona', 'Categoría', 'Acción', 'Resumen', 'Proyecto', 'Antes', 'Después', 'Motivo', 'Origen'], ';');
        foreach ($rows as $a) fputcsv($out, [$a['created_at'], $a['user_name'] ?? 'Sistema', \Borealis\Services\Activity::CATEGORIES[$a['category'] ?? ''] ?? '', $a['action'], $a['summary'], $a['project_name'] ?? '', $a['before_data'] ?? '', $a['after_data'] ?? '', $a['reason'] ?? '', \Borealis\Services\Activity::SOURCES[$a['source'] ?? ''] ?? ''], ';');
        rewind($out);
        $csv = (string)stream_get_contents($out);
        return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="borealis-historial-' . date('Y-m-d') . '.csv"', 'Cache-Control' => 'no-store']);
    }

    private static function auditFilters(Request $r): array
    {
        $f = [];
        foreach (['user_id', 'project_id', 'category', 'action', 'source', 'from', 'to', 'q', 'type'] as $k) $f[$k] = is_scalar($r->q($k)) ? trim((string)$r->q($k)) : null;
        // quick periods: hoy, 7 / 30 / 90 días, or this week, month, quarter or year (the same as Análisis)
        $f['period'] = null;
        if (is_string($r->q('period')) && $r->q('period') !== '') {
            $pp = (string)$r->q('period');
            $days = ['today' => 0, '7d' => 6, '30d' => 29, '90d' => 89][$pp] ?? null;
            $range = $days !== null ? [date('Y-m-d', strtotime(today() . " -$days days")), today()] : \Borealis\Services\Analysis\Analysis::preset($pp);
            if ($range) { [$f['from'], $f['to']] = $range; $f['period'] = $pp; }
        }
        return $f;
    }

    /** 1.14 · Administración › Notificaciones: does the scheduled job run, and are notices delivered. */
    public function notifications(Request $r): Response
    {
        $this->guard('workspace.manage');
        $admin = (bool)(int)(Auth::user()['is_instance_admin'] ?? 0);
        return View::page('admin/notifications', $this->base('notifications') + [
            'job' => \Borealis\Services\Scheduler::status(), 'stats' => \Borealis\Services\Push::deliveryStats(),
            'pushOk' => \Borealis\Services\Push::available(), 'mailOk' => \Borealis\Services\Mailer::configured(),
            'cronUrl' => $admin ? absolute_url('/cron/run', ['token' => \Borealis\Services\Scheduler::token()]) : null, 'isInstanceAdmin' => $admin,
            'devices' => (int)Database::get()->value('SELECT COUNT(*) FROM push_subscriptions s JOIN workspace_members wm ON wm.user_id = s.user_id AND wm.workspace_id = ?', [Policy::wsId()]),
            'basePath' => BASE_PATH, 'phpBinary' => PHP_BINARY, 'phpVersion' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
        ]);
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
