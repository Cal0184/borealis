<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Database;
use Borealis\Core\HttpException;

/**
 * Progressive complexity (PCBM). Core tools are always on; optional modules are
 * toggled per project. Disabling only hides a module: its data stays intact.
 */
final class Modules
{
    public const CORE = ['tasks' => 'Tareas', 'list' => 'Lista', 'board' => 'Tablero', 'comments' => 'Comentarios', 'members' => 'Integrantes'];

    /** key => [name, description, requires[]] */
    public const OPTIONAL = [
        'backlog' => ['Backlog', 'Lista priorizada del trabajo pendiente', []],
        'sprints' => ['Sprints', 'Periodos de trabajo con objetivos y cierre', ['backlog']],
        'estimates' => ['Estimaciones', 'Puntos de esfuerzo por tarea', []],
        'epics' => ['Épicas', 'Agrupa tareas en objetivos mayores', []],
        'calendar' => ['Calendario', 'Fechas de vencimiento por mes y semana', []],
        'timeline' => ['Cronograma', 'Barras de inicio a fin por tarea', []],
        'docs' => ['Documentación', 'Páginas y biblioteca de archivos del proyecto', []],
        'reports' => ['Reportes', 'Progreso, bloqueos y distribución del trabajo', []],
    ];

    public static function enabled(int $projectId): array
    {
        $rows = Database::get()->all('SELECT module_key FROM project_modules WHERE project_id = ? AND enabled = 1', [$projectId]);
        $on = array_fill_keys(array_column($rows, 'module_key'), true);
        $out = [];
        foreach (self::OPTIONAL as $k => $_) $out[$k] = isset($on[$k]);
        return $out;
    }

    public static function isEnabled(int $projectId, string $key): bool
    {
        if (isset(self::CORE[$key])) return true;
        return (bool)Database::get()->value('SELECT enabled FROM project_modules WHERE project_id = ? AND module_key = ?', [$projectId, $key]);
    }

    public static function require(array $project, string $key): void
    {
        if (!self::isEnabled((int)$project['id'], $key)) {
            throw HttpException::notFound('El módulo «' . (self::OPTIONAL[$key][0] ?? $key) . '» no está activo en este proyecto.');
        }
    }

    /**
     * Toggle a module honouring dependencies. Enabling also enables what it needs;
     * disabling also disables what depends on it. Returns the keys changed.
     */
    public static function set(int $projectId, string $key, bool $enabled): array
    {
        if (!isset(self::OPTIONAL[$key])) throw HttpException::invalid(['module' => 'Módulo desconocido.']);
        $changes = [];
        $current = self::enabled($projectId);
        $apply = function (string $k, bool $on) use (&$apply, &$changes, &$current) {
            if ($current[$k] === $on) return;
            $current[$k] = $on;
            $changes[$k] = $on;
            if ($on) {
                foreach (self::OPTIONAL[$k][2] as $req) $apply($req, true);
            } else {
                foreach (self::OPTIONAL as $other => [, , $reqs]) {
                    if (in_array($k, $reqs, true)) $apply($other, false);
                }
            }
        };
        $apply($key, $enabled);
        $db = Database::get();
        foreach ($changes as $k => $on) {
            $db->run('INSERT INTO project_modules (project_id, module_key, enabled, updated_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_at = VALUES(updated_at)', [$projectId, $k, $on ? 1 : 0, now()]);
        }
        return $changes;
    }
}
