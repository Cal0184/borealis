<?php
declare(strict_types=1);

use Borealis\Core\Config;
use Borealis\Core\Csrf;
use Borealis\Core\View;

function config(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

/** Escape for HTML text and attribute contexts. */
function e(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = '/', array $query = []): string
{
    $base = rtrim((string)config('app.base_path', ''), '/');
    $path = '/' . ltrim($path, '/');
    if (config('app.pretty_urls', true)) {
        $out = $base . $path;
        $qs = $query ? '?' . http_build_query($query) : '';
    } else {
        $out = $base . '/index.php';
        $qs = '?' . http_build_query(['r' => $path] + $query);
    }
    return $out . $qs;
}

/**
 * 1.7: full link for messages that leave Borealis (account created, invitations, copied links).
 * Takes only the scheme and host from app.url and adds url(), which already carries base_path:
 * app.url is usually written with the folder too (https://example.org/borealis), so joining
 * app.url and url() repeated the folder (/borealis/borealis/login).
 */
function absolute_url(string $path = '/', array $query = []): string
{
    $u = parse_url((string)config('app.url', '')) ?: [];
    if (!empty($u['host'])) {
        $origin = ($u['scheme'] ?? 'https') . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
    } else {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $origin = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    return $origin . url($path, $query);
}

function asset(string $path): string
{
    $base = rtrim((string)config('app.base_path', ''), '/');
    $file = BASE_PATH . '/public/assets/' . ltrim($path, '/');
    $v = is_file($file) ? substr(md5((string)filemtime($file)), 0, 8) : BOREALIS_VERSION;
    return $base . '/assets/' . ltrim($path, '/') . '?v=' . $v;
}

function now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function today(): string
{
    return local_date('Y-m-d');
}

function local_date(string $format, ?string $utc = null): string
{
    $tz = new DateTimeZone((string)config('app.timezone', 'UTC'));
    $dt = $utc ? new DateTimeImmutable($utc, new DateTimeZone('UTC')) : new DateTimeImmutable('now', new DateTimeZone('UTC'));
    return $dt->setTimezone($tz)->format($format);
}

function csrf_token(): string
{
    return Csrf::token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function partial(string $name, array $data = []): string
{
    return View::render('partials/' . $name, $data);
}

function icon(string $name, string $class = ''): string
{
    return '<svg class="icon ' . e($class) . '" aria-hidden="true" focusable="false"><use href="' . e(asset('img/icons.svg')) . '#i-' . e($name) . '"></use></svg>';
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $out .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $out ?: '?';
}

function avatar(?array $user, string $size = ''): string
{
    if (!$user || empty($user['name'])) {
        return '<span class="avatar avatar--empty ' . e($size) . '" title="Sin responsable">' . icon('user') . '</span>';
    }
    $color = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($user['avatar_color'] ?? '')) ? $user['avatar_color'] : '#10B981';
    // 1.7: the person's photo when they have one; otherwise their initials on their colour.
    $id = (int)($user['id'] ?? $user['user_id'] ?? 0);
    $photo = $id ? \Borealis\Services\Avatars::urlFor($id, $size) : null;
    if ($photo) {
        return '<span class="avatar has-photo ' . e($size) . '" style="--avatar:' . e($color) . '" title="' . e($user['name']) . '"><img src="' . e($photo) . '" alt="" loading="lazy" decoding="async" width="96" height="96"><span class="sr-only">' . e($user['name']) . '</span></span>';
    }
    return '<span class="avatar ' . e($size) . '" style="--avatar:' . e($color) . '" title="' . e($user['name']) . '">' . e(initials($user['name'])) . '</span>';
}

function fmt_date(?string $date, bool $withYear = false): string
{
    if (!$date) {
        return '';
    }
    $months = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    $ts = strtotime($date);
    $s = (int)date('j', $ts) . ' ' . $months[(int)date('n', $ts) - 1];
    if ($withYear || date('Y', $ts) !== date('Y')) {
        $s .= ' ' . date('Y', $ts);
    }
    return $s;
}

/** 1.7.1: numeric dates are always day/month/year: 2026-11-09 → 09/11/2026. */
function fmt_dmy(?string $date): string
{
    if (!$date || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $m)) return '';
    return "$m[3]/$m[2]/$m[1]";
}

/** A UTC moment as local dd/mm/aaaa hh:mm (for exports). */
function fmt_dmy_time(?string $utc): string
{
    return $utc ? fmt_dmy(local_date('Y-m-d', $utc)) . ' ' . local_date('H:i', $utc) : '';
}

function fmt_datetime(?string $utc): string
{
    if (!$utc) {
        return '';
    }
    return fmt_date(local_date('Y-m-d', $utc)) . ', ' . local_date('H:i', $utc);
}

function time_ago(?string $utc): string
{
    if (!$utc) {
        return '';
    }
    $diff = time() - strtotime($utc . ' UTC');
    if ($diff < 60) return 'hace un momento';
    if ($diff < 3600) return 'hace ' . intdiv($diff, 60) . ' min';
    if ($diff < 86400) return 'hace ' . intdiv($diff, 3600) . ' h';
    if ($diff < 86400 * 7) return 'hace ' . intdiv($diff, 86400) . ' d';
    return fmt_date(local_date('Y-m-d', $utc));
}

function due_state(?string $due, bool $done = false): string
{
    if (!$due || $done) return '';
    $t = today();
    if ($due < $t) return 'overdue';
    if ($due <= date('Y-m-d', strtotime($t . ' +2 days'))) return 'soon';
    return '';
}

/**
 * 1.11: punctuality of a task, without a new status. An open task past its date is «Atrasada»
 * (it keeps its status: «En progreso» + «Atrasada»); a finished one says whether it was finished
 * after the date it had at that moment (tasks.completed_due_date). Date level, in the
 * installation's zone, like the «Vencidas» filter. Returns null when there is nothing to say.
 * @return array{kind:string,days:int,text:string,title:string}|null
 */
function lateness(array $t): ?array
{
    $days = static fn(string $from, string $to): int => (int)(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->format('%r%a');
    $plural = static fn(int $n): string => $n === 1 ? '1 día' : $n . ' días';
    if (!empty($t['completed_at'])) {
        $due = $t['completed_due_date'] ?? null;
        if (!$due) return null;
        $doneDay = local_date('Y-m-d', (string)$t['completed_at']);
        $late = $days($due, $doneDay);
        if ($late <= 0) return ['kind' => 'ontime', 'days' => 0, 'text' => 'A tiempo', 'title' => 'Finalizada el ' . fmt_date($doneDay, true) . '; vencía el ' . fmt_date($due, true)];
        return ['kind' => 'done-late', 'days' => $late, 'text' => 'Fuera de plazo · ' . $plural($late),
            'title' => 'Finalizada el ' . fmt_date($doneDay, true) . ', ' . $plural($late) . ' después de su fecha límite (' . fmt_date($due, true) . ')'];
    }
    if (empty($t['due_date'])) return null;
    $late = $days((string)$t['due_date'], today());
    if ($late <= 0) return null;
    return ['kind' => 'late', 'days' => $late, 'text' => $plural($late) . ' de retraso',
        'title' => $plural($late) . ' de retraso: vencía el ' . fmt_date((string)$t['due_date'], true)];
}

/** The small «Atrasada · 3 días» / «Fuera de plazo» tag; empty for tasks on time. */
function late_tag(array $t, bool $showOnTime = false): string
{
    $l = lateness($t);
    if (!$l || ($l['kind'] === 'ontime' && !$showOnTime)) return '';
    return '<span class="late-tag ' . $l['kind'] . '" title="' . e($l['title']) . '">' . icon($l['kind'] === 'ontime' ? 'check' : 'clock', 'sm') . ' ' . e($l['text']) . '</span>';
}

function pct(int|float $part, int|float $total): int
{
    return $total > 0 ? (int)round($part * 100 / $total) : 0;
}

function selected(mixed $a, mixed $b): string
{
    return (string)$a === (string)$b ? ' selected' : '';
}

function checked(bool $v): string
{
    return $v ? ' checked' : '';
}

/** Minimal, safe text formatting: escape first, then a tiny markdown subset. */
function rich_text(?string $text): string
{
    $text = (string)$text;
    if ($text === '') return '';
    $lines = preg_split("/\r\n|\n|\r/", $text);
    $html = '';
    $inList = false;
    foreach ($lines as $line) {
        $esc = e($line);
        $esc = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $esc);
        $esc = preg_replace('/(?<![\w*])_(.+?)_(?!\w)/u', '<em>$1</em>', $esc);
        $esc = preg_replace('/`([^`]+)`/u', '<code>$1</code>', $esc);
        $esc = preg_replace('~\bhttps?://[^\s<]+~u', '<a href="$0" rel="noopener noreferrer nofollow" target="_blank">$0</a>', $esc);
        $esc = preg_replace('/(^|\s)@([a-z0-9._-]{2,60})/u', '$1<span class="mention">@$2</span>', $esc);
        if (preg_match('/^\s*[-*]\s+(.*)$/u', $esc, $m)) {
            if (!$inList) { $html .= '<ul>'; $inList = true; }
            $html .= '<li>' . $m[1] . '</li>';
            continue;
        }
        if ($inList) { $html .= '</ul>'; $inList = false; }
        if (preg_match('/^(#{1,3})\s+(.*)$/u', $esc, $m)) {
            $lvl = strlen($m[1]) + 2;
            $html .= "<h{$lvl}>{$m[2]}</h{$lvl}>";
        } elseif (trim($esc) === '') {
            $html .= '';
        } else {
            $html .= '<p>' . $esc . '</p>';
        }
    }
    if ($inList) $html .= '</ul>';
    return $html;
}
