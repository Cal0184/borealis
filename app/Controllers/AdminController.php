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
        return View::page('admin/settings', $this->base('settings') + ['priorities' => Workspaces::priorities(), 'support' => \Borealis\Services\Support::settings()]);
    }

    public function members(Request $r): Response
    {
        $this->guard('members.manage');
        return View::page('admin/members', $this->base('members') + [
            'members' => Workspaces::members(), 'roles' => Workspaces::roles('workspace'), 'invites' => Workspaces::pendingInvitations(), 'teams' => Teams::list(),
            'isOwner' => Policy::workspace()['role_key'] === 'owner',
            'instanceAdmin' => $ia = (bool)(int)(Auth::user()['is_instance_admin'] ?? 0), 'ghosts' => $ia ? \Borealis\Services\Ghosts::list() : [],
            'impact' => \Borealis\Services\MemberDeletion::impact(),
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

    /**
     * 1.15 · Registro maestro: one log for everything (work, administration, automatic actions and
     * errors), newest first, grouped by day, week or month. Pages go by id («older than n»), which
     * stays fast with millions of rows. Nothing here edits or deletes an entry.
     */
    public function audit(Request $r): Response
    {
        $this->guard('audit.view');
        $f = self::auditFilters($r);
        $rows = \Borealis\Services\Activity::search($f, 101, 0);
        $hasMore = count($rows) > 100;
        $rows = array_slice($rows, 0, 100);
        $verify = $r->q('verify') ? \Borealis\Services\Activity::verify() : null;
        $projects = Database::get()->all('SELECT id, name, project_key FROM projects WHERE workspace_id = ? ORDER BY name', [Policy::wsId()]);
        $group = in_array($r->q('group'), ['day', 'week', 'month'], true) ? (string)$r->q('group') : 'day';
        $filters = array_filter($f, fn($v) => $v !== null && $v !== '');
        unset($filters['before']);
        return View::page('admin/audit', $this->base('audit') + ['rows' => $rows, 'hasMore' => $hasMore, 'next' => $hasMore ? (int)end($rows)['id'] : null, 'paged' => !empty($f['before']), 'members' => Workspaces::members(),
            'filters' => $filters, 'group' => $group, 'projects' => $projects, 'actions' => \Borealis\Services\Activity::actions(), 'verify' => $verify, 'title' => 'Registro maestro']);
    }

    /** 1.14 · the same search as a CSV file (opens in Excel / LibreOffice / Google Sheets). */
    public function auditCsv(Request $r): Response
    {
        $this->guard('audit.view');
        $A = \Borealis\Services\Activity::class;
        $rows = $A::search(self::auditFilters($r), 5000, 0);
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['N.º', 'Fecha (UTC)', 'Persona', 'Tipo', 'Categoría', 'Acción', 'Resumen', 'Proyecto', 'Antes', 'Después', 'Motivo', 'Origen', 'Resultado', 'Enlace'], ';');
        foreach ($rows as $a) fputcsv($out, [$a['id'], $a['created_at'], $a['user_name'] ?? 'Sistema', $A::KINDS[$A::kind($a)], $A::CATEGORIES[$a['category'] ?? ''] ?? '', $a['action'], $a['summary'], $a['project_name'] ?? '', $a['before_data'] ?? '', $a['after_data'] ?? '', $a['reason'] ?? '',
            $A::SOURCES[$a['source'] ?? ''] ?? '', $A::RESULTS[$a['result'] ?? ''] ?? 'Hecho', self::absolute($A::link($a))], ';');
        rewind($out);
        $csv = (string)stream_get_contents($out);
        return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="borealis-registro-' . date('Y-m-d') . '.csv"', 'Cache-Control' => 'no-store']);
    }

    /** 1.15 · the same search as JSON (for archiving or another tool); the hashes go along so it can be checked. */
    public function auditJson(Request $r): Response
    {
        $this->guard('audit.view');
        $A = \Borealis\Services\Activity::class;
        $items = array_map(fn($a) => [
            'id' => (int)$a['id'], 'fecha_utc' => $a['created_at'], 'persona' => $a['user_name'] ?? null, 'persona_id' => $a['user_id'] ? (int)$a['user_id'] : null,
            'tipo' => $A::kind($a), 'categoria' => $a['category'] ?? null, 'accion' => $a['action'], 'elemento' => ['tipo' => $a['entity_type'], 'id' => $a['entity_id'] ? (int)$a['entity_id'] : null, 'enlace' => self::absolute($A::link($a))],
            'proyecto' => $a['project_id'] ? ['id' => (int)$a['project_id'], 'nombre' => $a['project_name']] : null, 'tarea_id' => $a['task_id'] ? (int)$a['task_id'] : null,
            'resumen' => $a['summary'], 'antes' => $a['before_data'] ? json_decode($a['before_data'], true) : null, 'despues' => $a['after_data'] ? json_decode($a['after_data'], true) : null,
            'motivo' => $a['reason'] ?? null, 'origen' => $a['source'] ?? null, 'resultado' => $a['result'] ?? 'ok', 'huella' => $a['row_hash'] ?? null, 'huella_anterior' => $a['prev_hash'] ?? null,
        ], $A::search(self::auditFilters($r), 5000, 0));
        $body = json_encode(['espacio' => Policy::workspace()['name'], 'generado_utc' => now(), 'registros' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        return new Response((string)$body, 200, ['Content-Type' => 'application/json; charset=utf-8', 'Content-Disposition' => 'attachment; filename="borealis-registro-' . date('Y-m-d') . '.json"', 'Cache-Control' => 'no-store']);
    }

    private static function absolute(?string $path): string
    {
        if (!$path) return '';
        $base = rtrim((string)config('app.url', ''), '/');
        return $base !== '' && str_starts_with($path, '/') ? $base . preg_replace('~^' . preg_quote(rtrim((string)config('app.base_path', ''), '/'), '~') . '~', '', $path) : $path;
    }

    private static function auditFilters(Request $r): array
    {
        $f = [];
        foreach (['user_id', 'project_id', 'task_id', 'category', 'action', 'source', 'from', 'to', 'q', 'type', 'kind', 'result', 'before'] as $k) $f[$k] = is_scalar($r->q($k)) ? trim((string)$r->q($k)) : null;
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
    /** 1.15 · Automatizaciones: ready templates (event → condition → action) the administration turns on. */
    public function automations(Request $r): Response
    {
        $this->guard('workspace.manage');
        $runs = \Borealis\Services\Activity::search(['category' => 'automation'], 15, 0);
        return View::page('admin/automations', $this->base('automations') + ['rules' => \Borealis\Services\Automations::rules(), 'runs' => $runs,
            'projects' => Database::get()->all('SELECT id, name FROM projects WHERE workspace_id = ? AND archived_at IS NULL ORDER BY name', [Policy::wsId()]), 'title' => 'Automatizaciones']);
    }

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
            'security' => $instanceAdmin ? ['exposure' => \Borealis\Services\Exposure::maybeCheck(), 'admins' => (int)Database::get()->value('SELECT COUNT(*) FROM users WHERE is_instance_admin = 1 AND is_active = 1')] : null,
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
        if (!preg_match(Backup::NAME_RE, $name)) throw HttpException::notFound();
        $path = Backup::dir() . '/' . $name;
        if (!is_file($path)) throw HttpException::notFound();
        return Response::download($path, $name, 'application/sql');
    }

    /** 1.16.4 · «Descargar todo»: SQL + config/config.php + storage/ in one ZIP, to keep outside the server. */
    public function downloadAll(Request $r): Response
    {
        if (!(int)(Auth::user()['is_instance_admin'] ?? 0)) throw HttpException::forbidden();
        $zip = Backup::fullArchive();
        register_shutdown_function(static fn() => @unlink($zip));
        \Borealis\Services\Activity::log('exported', 'workspace', \Borealis\Security\Policy::wsId(), 'Descargó la copia completa de la instalación');
        return Response::download($zip, 'borealis-copia-completa-' . gmdate('Y-m-d') . '.zip', 'application/zip');
    }
}
