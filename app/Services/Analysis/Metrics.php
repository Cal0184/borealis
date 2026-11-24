<?php
declare(strict_types=1);

namespace Borealis\Services\Analysis;

/**
 * Análisis (1.13): the formulas, in one place, as pure functions (no database, no session).
 *
 * Every indicator of the «Análisis» section, its report, its CSV and its tests use these functions,
 * so a number means the same everywhere. DEFINITIONS documents each one (formula, what counts, what
 * is left out); the interface shows it under «¿Cómo se calcula?».
 */
final class Metrics
{
    /** id => [name, formula, counted, excluded]. Shown in the interface and in docs/ANALISIS-1.13.md. */
    public const DEFINITIONS = [
        'ontime' => ['Cumplimiento de plazos', 'finalizadas a tiempo ÷ finalizadas con fecha límite válida × 100',
            'Tareas (y subtareas con fecha propia) finalizadas en el período cuya fecha límite al finalizar está registrada. «A tiempo» = finalizada el mismo día de su fecha límite o antes.',
            'Tareas pendientes (nunca se mezclan), finalizadas sin fecha límite y finalizadas antes de 1.11 (no guardaron la fecha que tenían al cerrarse).'],
        'late' => ['Finalizadas fuera de plazo', 'finalizadas después de su fecha límite ÷ finalizadas con fecha límite válida × 100',
            'Las mismas tareas que el cumplimiento de plazos.', 'Las mismas que el cumplimiento de plazos.'],
        'deviation' => ['Desviación de estimaciones', 'mediana de (horas registradas − horas estimadas) ÷ horas estimadas × 100',
            'Tareas principales finalizadas en el período con estimación mayor que 0 y al menos un registro de horas.',
            'Sin estimación, sin horas registradas (registro incompleto) y estimaciones menores que el mínimo configurado en Monitoreo. Se usa la mediana para que un valor extremo no la distorsione.'],
        'reprogram' => ['Frecuencia de reprogramaciones', 'cambios de una fecha límite existente ÷ tareas con fecha límite',
            'Cambios registrados en el historial de fechas (desde 1.12) dentro del período, en tareas de los proyectos analizados.',
            'Poner la primera fecha a una tarea que no tenía (no es reprogramar). Cambios anteriores a 1.12: no se registraban.'],
        'blocked' => ['Duración de bloqueos', 'mediana de (desbloqueo − bloqueo) en días',
            'Bloqueos que terminaron en el período, según el historial de actividad (marcó como bloqueada → desbloqueó).',
            'Bloqueos que siguen abiertos (se cuentan aparte, con su antigüedad) y bloqueos sin su inicio registrado.'],
        'sprint' => ['Cumplimiento de compromisos de sprint', 'tareas comprometidas al iniciar que se completaron ÷ tareas comprometidas al iniciar × 100',
            'Sprints cerrados en el período. Desde 1.12 se usa el compromiso guardado al iniciar; antes, el recuento que se guardó al cerrar.',
            'Trabajo añadido después de iniciar (se muestra aparte) y sprints sin dato de compromiso.'],
        'backlog' => ['Evolución del trabajo pendiente', 'pendientes al final de cada semana = creadas hasta ese día − finalizadas hasta ese día',
            'Tareas principales de los proyectos analizados.', 'Tareas eliminadas (no queda constancia de cuándo dejaron de estar pendientes).'],
        'capacity' => ['Carga planificada frente a capacidad', 'horas pendientes estimadas de la semana − capacidad de la semana',
            'Horas restantes de tareas abiertas con estimación y fecha (o sprint), repartidas entre sus responsables y en los días laborables hasta su fecha límite, como en Monitoreo › Carga. Capacidad = horas semanales de cada persona + horas extra de esa semana.',
            'Tareas sin estimación o sin fecha (se listan aparte, sin inventar horas) y personas sin horas semanales configuradas.'],
    ];

    /** Percentage with its numerator and denominator; null value when there is nothing to divide. */
    public static function rate(int $num, int $den): array
    {
        return ['value' => $den > 0 ? round($num / $den * 100, 1) : null, 'num' => $num, 'den' => $den];
    }

    public static function median(array $xs): ?float
    {
        $xs = array_values(array_map('floatval', $xs));
        $n = count($xs);
        if (!$n) return null;
        sort($xs);
        return $n % 2 ? $xs[intdiv($n, 2)] : ($xs[$n / 2 - 1] + $xs[$n / 2]) / 2;
    }

    /** Percentile with linear interpolation (p in 0..100). */
    public static function percentile(array $xs, float $p): ?float
    {
        $xs = array_values(array_map('floatval', $xs));
        $n = count($xs);
        if (!$n) return null;
        sort($xs);
        $pos = ($n - 1) * $p / 100;
        $lo = (int)floor($pos);
        $hi = (int)ceil($pos);
        return $xs[$lo] + ($xs[$hi] - $xs[$lo]) * ($pos - $lo);
    }

    /**
     * Extreme values by Tukey's fences: outside [Q1 − k·IQR, Q3 + k·IQR], with k = 3 («far out»)
     * so that small, ordinary differences are never discarded. Only applied with 5 values or more
     * (with fewer there is no meaningful spread). Returns [kept keys, removed keys].
     */
    public static function outliers(array $values, float $k = 3.0): array
    {
        if (count($values) < 5) return [array_keys($values), []];
        $q1 = self::percentile($values, 25);
        $q3 = self::percentile($values, 75);
        $iqr = max($q3 - $q1, 0.1 * abs((float)self::median($values))); // identical values: a 10 % band, not zero
        $lo = $q1 - $k * $iqr;
        $hi = $q3 + $k * $iqr;
        $keep = $out = [];
        foreach ($values as $key => $v) { if ($v < $lo - 1e-9 || $v > $hi + 1e-9) $out[] = $key; else $keep[] = $key; }
        return [$keep, $out];
    }

    /**
     * Trend over ordered buckets [['label'=>…, 'value'=>?float, 'n'=>int], …]. A trend is only
     * reported with at least $minBuckets buckets that each have $minN records; the change is the
     * least-squares slope projected over the series, in the unit of the values. Below $minChange
     * it is «estable». It describes, never explains: the caller says so.
     */
    public static function trend(array $buckets, int $minN, int $minBuckets = 3, float $minChange = 10.0): array
    {
        $pts = [];
        foreach (array_values($buckets) as $b) if ($b['value'] !== null && (int)$b['n'] >= $minN) $pts[] = (float)$b['value'];
        $n = count($pts);
        if ($n < $minBuckets) return ['dir' => 'nodata', 'change' => null, 'points' => $n,
            'text' => 'No hay datos suficientes para identificar una tendencia confiable.'];
        $mx = ($n - 1) / 2;
        $my = array_sum($pts) / $n;
        $sxy = $sxx = 0.0;
        foreach ($pts as $i => $y) { $sxy += ($i - $mx) * ($y - $my); $sxx += ($i - $mx) ** 2; }
        $slope = $sxx > 0 ? $sxy / $sxx : 0.0;
        $change = round($slope * ($n - 1), 1);
        $dir = abs($change) < $minChange ? 'stable' : ($change > 0 ? 'up' : 'down');
        return ['dir' => $dir, 'change' => $change, 'points' => $n, 'text' => null];
    }

    /** Remaining hours of an open task: the remaining hours written down, otherwise estimate − logged (never negative). */
    public static function remaining(?float $estimate, ?float $remaining, float $loggedHours): ?float
    {
        if ($remaining !== null) return max(0.0, $remaining);
        if ($estimate === null) return null;
        return max(0.0, round($estimate - $loggedHours, 2));
    }

    /** Share of the capacity still ahead in a period: capacity × working days left ÷ working days. */
    public static function capacityLeft(float $capacity, int $daysTotal, int $daysLeft): float
    {
        if ($daysTotal <= 0) return 0.0;
        return round($capacity * max(0, min($daysLeft, $daysTotal)) / $daysTotal, 2);
    }

    /** Working days (Mon–Fri) between two dates, both included. */
    public static function workdays(string $from, string $to): int
    {
        if ($to < $from) return 0;
        $n = 0;
        for ($d = strtotime($from . ' 12:00:00'), $e = strtotime($to . ' 12:00:00'); $d <= $e; $d += 86400) if ((int)date('N', $d) <= 5) $n++;
        return $n;
    }

    /**
     * Rule-based risk classification (not a probability). $factors: [['points'=>int, 'text'=>string, 'kind'=>…], …].
     * Sum of points ≥ elevated → «Riesgo elevado»; ≥ moderate → «Riesgo moderado»; otherwise «Riesgo bajo».
     * $enoughData false → «Sin datos suficientes», whatever the points.
     */
    public static function riskLevel(array $factors, bool $enoughData, int $moderate, int $elevated): string
    {
        if (!$enoughData) return 'nodata';
        $p = array_sum(array_map(fn($f) => (int)$f['points'], $factors));
        if ($p >= $elevated) return 'high';
        if ($p >= $moderate) return 'moderate';
        return 'low';
    }

    /** «Confianza» of a statistic by sample size (Alta from 3× the minimum, Media from the minimum). */
    public static function confidence(int $n, int $min): string
    {
        if ($n >= $min * 3) return 'Alta';
        if ($n >= $min) return 'Media';
        return 'Insuficiente';
    }

    /** Lowest-priority pending tasks whose hours cover $gap (for «trasladar X y Y»). $tasks sorted by the caller. */
    public static function coverGap(array $tasks, float $gap): array
    {
        $pick = [];
        $sum = 0.0;
        foreach ($tasks as $t) {
            if ($sum >= $gap - 1e-9) break;
            if (($t['hours'] ?? 0) <= 0) continue;
            $pick[] = $t;
            $sum += (float)$t['hours'];
        }
        return [$pick, round($sum, 2)];
    }

    public static function h(?float $x, bool $sign = false): string
    {
        if ($x === null) return '—';
        $s = rtrim(rtrim(number_format(abs($x), 1, ',', '.'), '0'), ',');
        return ($sign ? ($x > 0 ? '+' : ($x < 0 ? '−' : '')) : ($x < 0 ? '−' : '')) . $s . ' h';
    }

    public static function pct(?float $x, bool $sign = false): string
    {
        if ($x === null) return '—';
        $s = rtrim(rtrim(number_format(abs($x), 1, ',', '.'), '0'), ',');
        return ($sign ? ($x > 0 ? '+' : ($x < 0 ? '−' : '')) : ($x < 0 ? '−' : '')) . $s . ' %';
    }
}
