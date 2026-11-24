<?php
declare(strict_types=1);

namespace Borealis\Services\Analysis;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Security\Policy;
use Borealis\Services\Activity;
use Borealis\Services\Monitoring;

/**
 * Análisis (1.13): settings, who may see what, and the result cache.
 *
 * Access reuses Monitoreo's scope (checked in PHP on every request):
 *  - administración («projects.manage_all»): every project of the workspace;
 *  - quien gestiona un proyecto: análisis completo de ese proyecto, escenarios y su aplicación;
 *  - integrantes: indicadores de los proyectos en los que participan (agregados, sin desglose
 *    por persona salvo el suyo) y sus indicadores personales («Solo lo mío»).
 * No ranking, no personal score: every indicator is about projects, sprints and plans.
 */
final class Analysis
{
    public const DEFAULTS = [
        'history_months' => 6,          // period analysed by default
        'min_sample' => 5,              // records needed before a statistic, a pattern or a suggestion is shown
        'trend_min_change' => 10,       // percentage points (or %) below which a series is «estable»
        'pattern_deviation_pct' => 25,  // median deviation from which a category «suele superar su estimación»
        'reprogram_threshold' => 3,     // due-date changes from which a task is «reprogramada repetidamente»
        'overload_high_pct' => 130,     // pending ÷ capacity from which overload weighs 3 points instead of 2
        'risk_moderate' => 2,           // points for «Riesgo moderado»
        'risk_elevated' => 4,           // points for «Riesgo elevado»
        'horizon_weeks' => 4,           // weeks ahead in Planificación
    ];
    private const LIMITS = [
        'history_months' => [1, 36], 'min_sample' => [3, 50], 'trend_min_change' => [1, 100], 'pattern_deviation_pct' => [5, 500],
        'reprogram_threshold' => [2, 20], 'overload_high_pct' => [101, 500], 'risk_moderate' => [1, 20], 'risk_elevated' => [2, 30], 'horizon_weeks' => [1, 12],
    ];
    public const LABELS = [
        'history_months' => ['Período analizado por defecto', 'meses'], 'min_sample' => ['Registros mínimos para una conclusión', 'registros'],
        'trend_min_change' => ['Cambio mínimo para hablar de tendencia', 'puntos'], 'pattern_deviation_pct' => ['Desviación habitual desde', '% sobre lo estimado'],
        'reprogram_threshold' => ['Reprogramada repetidamente desde', 'cambios de fecha'], 'overload_high_pct' => ['Sobrecarga alta desde', '% de la capacidad'],
        'risk_moderate' => ['Riesgo moderado desde', 'puntos'], 'risk_elevated' => ['Riesgo elevado desde', 'puntos'], 'horizon_weeks' => ['Semanas que mira Planificación', 'semanas'],
    ];

    public const RISK = ['low' => ['Riesgo bajo', 'success'], 'moderate' => ['Riesgo moderado', 'warn'], 'high' => ['Riesgo elevado', 'danger'], 'nodata' => ['Sin datos suficientes', '']];

    public static function settings(?array $ws = null): array
    {
        $ws ??= Policy::workspace();
        $saved = (array)(($ws['settings']['analysis'] ?? null) ?: []);
        $out = self::DEFAULTS;
        foreach ($saved as $k => $v) if (isset(self::DEFAULTS[$k]) && is_numeric($v)) $out[$k] = (int)$v;
        return $out + ['soon_days' => Monitoring::settings($ws)['soon_days'], 'deviation_min_hours' => Monitoring::settings($ws)['deviation_min_hours']];
    }

    /** Only the administration changes global parameters; every change is written to the activity log with before and after. */
    public static function saveSettings(array $input): array
    {
        Policy::authorize('workspace.manage');
        $old = array_intersect_key(self::settings(), self::DEFAULTS);
        $new = $old;
        $errors = [];
        foreach (self::LIMITS as $k => [$min, $max]) {
            if (!array_key_exists($k, $input)) continue;
            $v = $input[$k];
            if (!is_numeric($v) || (int)$v != $v || (int)$v < $min || (int)$v > $max) { $errors[$k] = "Escribe un número entero entre $min y $max."; continue; }
            $new[$k] = (int)$v;
        }
        if (!$errors && $new['risk_moderate'] >= $new['risk_elevated']) $errors['risk_elevated'] = 'El riesgo elevado necesita más puntos que el moderado.';
        if ($errors) throw HttpException::invalid($errors);
        $db = Database::get();
        $settings = json_decode((string)$db->value('SELECT settings FROM workspaces WHERE id = ?', [Policy::wsId()]), true) ?: [];
        $settings['analysis'] = $new;
        $db->update('workspaces', ['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE), 'updated_at' => now()], 'id = ?', [Policy::wsId()]);
        $changed = [];
        foreach ($new as $k => $v) if ($old[$k] !== $v) $changed[$k] = ['antes' => $old[$k], 'ahora' => $v];
        Activity::log('settings', 'workspace', Policy::wsId(), 'Cambió los parámetros de análisis' . ($changed ? ': ' . implode(', ', array_map(fn($k, $c) => self::LABELS[$k][0] . ' ' . $c['antes'] . ' → ' . $c['ahora'], array_keys($changed), $changed)) : ''), null, null, ['analysis' => $changed]);
        Policy::reset();
        self::flush();
        return $new;
    }

    // ------------------------------------------------------------------ scope

    public static function scope(): array
    {
        return Monitoring::scope();
    }

    /** Project ids to analyse: one (checked) or every visible one. */
    public static function pids(?int $projectId): array
    {
        return $projectId ? [$projectId] : self::scope()['projects'];
    }

    public static function canManage(int $projectId): bool
    {
        $sc = self::scope();
        return $sc['admin'] || in_array($projectId, $sc['managed'], true);
    }

    /** A sprint the viewer can see (404 otherwise, without saying whether it exists). */
    public static function sprint(int $id): array
    {
        $sp = Database::get()->one('SELECT * FROM sprints WHERE id = ?', [$id]);
        if (!$sp || !in_array((int)$sp['project_id'], self::scope()['projects'], true)) throw HttpException::notFound('El sprint no existe o no tienes acceso.');
        return $sp;
    }

    /** A team the viewer may filter by: one they belong to, or any for the administration. */
    public static function team(mixed $raw): ?int
    {
        if ($raw === null || $raw === '' || $raw === '0') return null;
        $id = (int)$raw;
        $ok = (bool)Database::get()->value('SELECT 1 FROM teams WHERE id = ? AND workspace_id = ?', [$id, Policy::wsId()]);
        $vis = Policy::visibleTeamIds();
        if (!$ok || ($vis !== null && !in_array($id, array_map('intval', $vis), true))) throw HttpException::notFound('El equipo no existe o no tienes acceso.');
        return $id;
    }

    /** SQL predicate on alias t for «Solo lo mío» / a team (responsible person or responsible team). */
    public static function peopleFilter(array $f): array
    {
        if (!empty($f['sprint_id'])) {
            [$w, $p] = self::peopleFilter(['sprint_id' => null] + $f);
            return [' AND t.sprint_id = ?' . $w, array_merge([(int)$f['sprint_id']], $p)];
        }
        if (!empty($f['mine'])) {
            $u = Auth::id();
            // same meaning as «Solo lo mío» elsewhere: main assignee, co-assignee or the task's team
            return [' AND (t.assignee_id = ? OR EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id = t.id AND ta.user_id = ?)'
                . ' OR (t.team_id IS NOT NULL AND EXISTS (SELECT 1 FROM team_members tmx WHERE tmx.team_id = t.team_id AND tmx.user_id = ?)))', [$u, $u, $u]];
        }
        if (!empty($f['team_id'])) {
            $tid = (int)$f['team_id'];
            return [' AND (t.team_id = ? OR t.assignee_id IN (SELECT tm.user_id FROM team_members tm WHERE tm.team_id = ?) OR EXISTS (SELECT 1 FROM task_assignees ta JOIN team_members tm ON tm.user_id = ta.user_id WHERE ta.task_id = t.id AND tm.team_id = ?))', [$tid, $tid, $tid]];
        }
        return ['', []];
    }

    // ------------------------------------------------------------------ cache

    /**
     * Results are kept in analysis_cache under a key (viewer + filters) and a fingerprint of the
     * data they depend on (tasks, hours, sprints, capacity, dependencies, settings, today's date).
     * Any change to those rows changes the fingerprint, so a stale result is never served; the
     * fingerprint itself is a handful of indexed aggregates.
     */
    public static function remember(string $key, array $pids, callable $fn): array
    {
        $db = Database::get();
        $fp = self::fingerprint($pids);
        $k = substr(hash('sha256', Auth::id() . '|' . Policy::wsId() . '|' . $key), 0, 64);
        $row = $db->one('SELECT fingerprint, payload FROM analysis_cache WHERE workspace_id = ? AND cache_key = ?', [Policy::wsId(), $k]);
        if ($row && hash_equals((string)$row['fingerprint'], $fp)) {
            $data = json_decode((string)$row['payload'], true);
            if (is_array($data)) return $data + ['_cached' => true];
        }
        $data = $fn();
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json !== false && strlen($json) < 4_000_000) {
            $db->run('INSERT INTO analysis_cache (workspace_id, cache_key, fingerprint, payload, computed_at) VALUES (?,?,?,?,?)
                ON DUPLICATE KEY UPDATE fingerprint = VALUES(fingerprint), payload = VALUES(payload), computed_at = VALUES(computed_at)', [Policy::wsId(), $k, $fp, $json, now()]);
            if (random_int(1, 50) === 1) $db->run('DELETE FROM analysis_cache WHERE computed_at < ?', [date('Y-m-d H:i:s', time() - 7 * 86400)]);
        }
        return $data + ['_cached' => false];
    }

    public static function fingerprint(array $pids): string
    {
        $db = Database::get();
        $parts = [today(), json_encode(self::settings()), json_encode(Monitoring::settings())];
        if ($pids) {
            $in = Database::in($pids);
            $parts[] = json_encode($db->one("SELECT COUNT(*) c, MAX(updated_at) u, SUM(COALESCE(estimate_hours,0)) e, SUM(COALESCE(remaining_hours,0)) r, SUM(COALESCE(TO_DAYS(due_date),0)) d,
                SUM(COALESCE(TO_DAYS(start_date),0)) s, SUM(status_id) st, SUM(is_blocked) b, SUM(COALESCE(sprint_id,0)) sp, SUM(COALESCE(assignee_id,0)) a, SUM(COALESCE(priority_id,0)) pr,
                SUM(deleted_at IS NULL) del FROM tasks WHERE project_id IN ($in)", $pids));
            $parts[] = json_encode($db->one("SELECT COUNT(*) c, MAX(id) m, SUM(IF(deleted_at IS NULL, minutes, 0)) s FROM time_entries WHERE project_id IN ($in)", $pids));
            $parts[] = json_encode($db->one("SELECT COUNT(*) c, MAX(updated_at) u, SUM(COALESCE(TO_DAYS(end_date),0)) e FROM sprints WHERE project_id IN ($in)", $pids));
            $parts[] = json_encode($db->one("SELECT COUNT(*) c, SUM(hours) h FROM sprint_capacity sc JOIN sprints s ON s.id = sc.sprint_id WHERE s.project_id IN ($in)", $pids));
            $parts[] = json_encode($db->one("SELECT COUNT(*) c, MAX(l.id) m FROM task_links l JOIN tasks t ON t.id = l.source_task_id WHERE t.project_id IN ($in)", $pids));
            $parts[] = json_encode($db->one("SELECT COUNT(*) c, MAX(d.id) m FROM task_due_changes d JOIN tasks t ON t.id = d.task_id WHERE t.project_id IN ($in)", $pids));
            $parts[] = json_encode($db->one("SELECT COUNT(*) c, SUM(ta.user_id) m FROM task_assignees ta WHERE ta.task_id IN (SELECT id FROM tasks WHERE project_id IN ($in))", $pids));
            $parts[] = json_encode($db->one("SELECT MAX(updated_at) u, COUNT(*) c FROM projects WHERE id IN ($in)", $pids));
            $parts[] = json_encode($db->one("SELECT COUNT(*) c, SUM(label_id) l FROM task_labels WHERE task_id IN (SELECT id FROM tasks WHERE project_id IN ($in))", $pids));
        }
        $parts[] = json_encode($db->one("SELECT SUM(COALESCE(weekly_hours,0)) h, COUNT(*) c FROM workspace_members WHERE workspace_id = ? AND status = 'active'", [Policy::wsId()]));
        $parts[] = json_encode($db->one('SELECT COUNT(*) c, SUM(minutes) m FROM capacity_extras WHERE workspace_id = ?', [Policy::wsId()]));
        $parts[] = json_encode($db->one('SELECT COUNT(*) c, SUM(user_id) u FROM team_members tm JOIN teams te ON te.id = tm.team_id WHERE te.workspace_id = ?', [Policy::wsId()]));
        return hash('sha256', implode('|', $parts));
    }

    public static function flush(): void
    {
        Database::get()->run('DELETE FROM analysis_cache WHERE workspace_id = ?', [Policy::wsId()]);
    }

    /** [from, to] of the analysis: the filters, or the last «history_months» months up to today. */
    public static function period(?string $from, ?string $to): array
    {
        $to = $to ?: today();
        $from = $from ?: date('Y-m-d', strtotime($to . ' 12:00:00 -' . self::settings()['history_months'] . ' months +1 day'));
        if ($from > $to) [$from, $to] = [$to, $from];
        return [$from, $to];
    }
}
