<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Core\Validator;
use Borealis\Security\Policy;
use Borealis\Security\Secrets;

/**
 * «Todo a mano» (1.8): the tools of each project in one place.
 *
 * Two different things, always labelled differently in the interface:
 *  - Direct links (project_links): a Drive folder, a Discord channel, a repository… Borealis only
 *    opens them; it does not connect to those services.
 *  - Integrations through the service's API (project_integrations), only where Borealis really talks
 *    to the service: Discord (incoming webhook: publishes notices in one channel) and GitHub (REST
 *    API: open issues, pull requests and recent commits of one repository). Google Calendar lives in
 *    the person's profile (OAuth, since 1.6).
 * Everything is optional: with nothing configured, or with the service down, Borealis works the same.
 */
final class Integrations
{
    public const LINK_KINDS = [
        'drive' => ['Google Drive', 'folder'],
        'discord' => ['Discord', 'chat'],
        'github' => ['GitHub', 'code'],
        'calendar' => ['Calendario', 'calendar'],
        'other' => ['Otro enlace', 'link'],
    ];

    /** Events a project can share in its Discord channel (all off until someone turns them on). */
    public const DISCORD_EVENTS = [
        'sprint_start' => 'Inicio de un sprint',
        'sprint_close' => 'Cierre de un sprint',
        'sprint_soon' => 'Un sprint empieza hoy o mañana',
        'sprint_ending' => 'Un sprint termina pronto',
        'sprint_overdue' => 'Un sprint llegó a su fecha de fin sin cerrarse',
        'milestone' => 'Hitos del proyecto (entrega, proyecto completado)',
        'task_due' => 'Tareas del proyecto que vencen mañana (solo clave y título)',
    ];

    private static function canManage(array $p): bool
    {
        return Policy::canProject($p, 'project.manage');
    }

    public static function authorizeManage(array $p): void
    {
        if (!self::canManage($p)) throw HttpException::forbidden('Solo quien administra el proyecto puede cambiar sus herramientas.');
    }

    // ------------------------------------------------------------------ direct links

    public static function links(int $projectId): array
    {
        return Database::get()->all('SELECT * FROM project_links WHERE project_id = ? ORDER BY position, id', [$projectId]);
    }

    /** Guesses the kind of a link from its address (only to show the right icon). */
    public static function kindOf(string $url): string
    {
        $h = strtolower((string)parse_url($url, PHP_URL_HOST));
        return match (true) {
            str_ends_with($h, 'drive.google.com'), str_ends_with($h, 'docs.google.com') => 'drive',
            str_ends_with($h, 'discord.com'), str_ends_with($h, 'discord.gg') => 'discord',
            str_ends_with($h, 'github.com') => 'github',
            str_ends_with($h, 'calendar.google.com') => 'calendar',
            default => 'other',
        };
    }

    public static function addLink(int $projectId, array $input): int
    {
        $p = Projects::find($projectId);
        self::authorizeManage($p);
        $v = Validator::check($input, ['title' => 'nullable,string,max:120', 'url' => 'required,string,max:1000', 'kind' => 'nullable,in:' . implode('|', array_keys(self::LINK_KINDS))]);
        $url = trim($v['url']);
        if (!preg_match('~^https?://~i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) throw HttpException::invalid(['url' => 'Escribe una dirección completa que empiece por https://']);
        $kind = $v['kind'] ?: self::kindOf($url);
        $db = Database::get();
        if ($db->value('SELECT 1 FROM project_links WHERE project_id = ? AND url = ?', [$projectId, $url])) throw HttpException::invalid(['url' => 'Ese enlace ya está en el proyecto.']);
        $title = trim((string)$v['title']) ?: (self::LINK_KINDS[$kind][0] . ' · ' . (parse_url($url, PHP_URL_HOST) ?: ''));
        $pos = (float)$db->value('SELECT COALESCE(MAX(position), 0) FROM project_links WHERE project_id = ?', [$projectId]) + 1024;
        $id = $db->insert('project_links', ['project_id' => $projectId, 'kind' => $kind, 'title' => mb_substr($title, 0, 120), 'url' => $url, 'position' => $pos, 'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now()]);
        Activity::log('link_added', 'project', $projectId, "Añadió el enlace «{$title}»", $projectId);
        return $id;
    }

    public static function removeLink(int $linkId): void
    {
        $l = Database::get()->one('SELECT * FROM project_links WHERE id = ?', [$linkId]) ?? throw HttpException::notFound();
        self::authorizeManage(Projects::find((int)$l['project_id']));
        Database::get()->run('DELETE FROM project_links WHERE id = ?', [$linkId]);
    }

    // ------------------------------------------------------------------ API integrations

    public static function get(int $projectId, string $provider): ?array
    {
        return Database::get()->one('SELECT * FROM project_integrations WHERE project_id = ? AND provider = ?', [$projectId, $provider]);
    }

    /** Integrations of a project as shown to people (secrets never leave the server). */
    public static function forProject(int $projectId): array
    {
        $out = [];
        foreach (Database::get()->all('SELECT * FROM project_integrations WHERE project_id = ?', [$projectId]) as $i) {
            $i['events'] = array_values(array_filter(explode(',', (string)$i['events'])));
            $i['has_secret'] = (bool)$i['secret'];
            unset($i['secret']);
            $i['cache'] = $i['cache'] ? json_decode((string)$i['cache'], true) : null;
            $out[$i['provider']] = $i;
        }
        return $out;
    }

    /** Discord: an incoming webhook (Server settings › Integrations › Webhooks › New webhook). */
    public static function saveDiscord(int $projectId, array $input): void
    {
        $p = Projects::find($projectId);
        self::authorizeManage($p);
        $v = Validator::check($input, ['webhook' => 'nullable,string,max:500', 'events' => 'nullable', 'enabled' => 'nullable,bool']);
        $cur = self::get($projectId, 'discord');
        $hook = trim((string)$v['webhook']);
        if ($hook !== '' && !self::validDiscordHook($hook)) throw HttpException::invalid(['webhook' => 'No parece un webhook de Discord. Cópialo desde Ajustes del servidor › Integraciones › Webhooks («Copiar URL del webhook»).']);
        if ($hook === '' && !$cur) throw HttpException::invalid(['webhook' => 'Pega la dirección del webhook del canal.']);
        $events = array_values(array_intersect((array)($v['events'] ?? []), array_keys(self::DISCORD_EVENTS)));
        $data = ['events' => implode(',', $events), 'enabled' => array_key_exists('enabled', $input) ? ((bool)$v['enabled'] ? 1 : 0) : 1, 'updated_at' => now()];
        if ($hook !== '') $data += ['secret' => Secrets::encrypt($hook), 'target' => self::discordTarget($hook), 'last_error' => null];
        $db = Database::get();
        if ($cur) $db->update('project_integrations', $data, 'id = ?', [$cur['id']]);
        else $db->insert('project_integrations', $data + ['project_id' => $projectId, 'provider' => 'discord', 'created_by' => Auth::id(), 'created_at' => now()]);
        Activity::log('integration', 'project', $projectId, 'Configuró la integración con Discord', $projectId);
    }

    private static function validDiscordHook(string $url): bool
    {
        if (preg_match('~^https://(?:(?:canary|ptb)\.)?discord(?:app)?\.com/api/webhooks/\d+/[\w-]+$~', $url)) return true;
        $test = (string)(getenv('BOREALIS_DISCORD_TEST_PREFIX') ?: config('integrations.discord_test_prefix', ''));
        return $test !== '' && str_starts_with($url, $test);
    }

    /** What is shown instead of the secret address: «webhook …/1234». */
    private static function discordTarget(string $url): string
    {
        return preg_match('~/webhooks/(\d+)/~', $url, $m) ? 'Webhook …' . substr($m[1], -4) : 'Webhook';
    }

    public static function remove(int $projectId, string $provider): void
    {
        self::authorizeManage(Projects::find($projectId));
        Database::get()->run('DELETE FROM project_integrations WHERE project_id = ? AND provider = ?', [$projectId, $provider]);
        Activity::log('integration', 'project', $projectId, 'Desconectó ' . ($provider === 'discord' ? 'Discord' : 'GitHub'), $projectId);
    }

    /** Sends a test message, so whoever configures it sees it arrive in the channel. */
    public static function testDiscord(int $projectId): void
    {
        $p = Projects::find($projectId);
        self::authorizeManage($p);
        $i = self::get($projectId, 'discord') ?? throw HttpException::invalid(['webhook' => 'Primero guarda el webhook.']);
        $err = self::postDiscord($i, "Borealis está conectado a este canal para el proyecto «{$p['name']}».", absolute_url('/projects/' . $projectId));
        if ($err) throw HttpException::invalid(['webhook' => $err]);
    }

    /**
     * Publishes a project event in its Discord channel, if the project turned that event on.
     * Once per event (dedupe), short text without private data (never personal tasks or comments).
     * A failure is recorded and shown in the project's settings; it never stops Borealis.
     */
    public static function announce(int $projectId, string $event, string $text, ?string $link, string $dedupe): void
    {
        try {
            $i = self::get($projectId, 'discord');
            if (!$i || !$i['enabled'] || !in_array($event, explode(',', (string)$i['events']), true)) return;
            $db = Database::get();
            $ins = $db->run('INSERT IGNORE INTO integration_deliveries (integration_id, dedupe_key, status, created_at) VALUES (?,?,?,?)', [$i['id'], mb_substr($dedupe, 0, 120), 'sent', now()])->rowCount();
            if (!$ins) return;
            $err = self::postDiscord($i, $text, $link ? absolute_url(self::stripBase($link)) : null);
            if ($err) $db->run('UPDATE integration_deliveries SET status = ?, detail = ? WHERE integration_id = ? AND dedupe_key = ?', ['failed', mb_substr($err, 0, 255), $i['id'], mb_substr($dedupe, 0, 120)]);
        } catch (\Throwable $e) {
            error_log('[Borealis] Discord: ' . $e->getMessage());
        }
    }

    private static function stripBase(string $u): string
    {
        $base = rtrim((string)config('app.base_path', ''), '/');
        return $base !== '' && str_starts_with($u, $base) ? substr($u, strlen($base)) : $u;
    }

    /** Returns null when Discord accepted it, or the reason in Spanish. */
    private static function postDiscord(array $i, string $text, ?string $link): ?string
    {
        $hook = Secrets::decrypt((string)$i['secret']);
        if (!$hook) return 'No se pudo leer el webhook guardado (¿cambió storage/app.key?). Vuelve a pegarlo.';
        $body = ['content' => mb_substr($text . ($link ? "\n<$link>" : ''), 0, 1900), 'username' => 'Borealis', 'allowed_mentions' => ['parse' => []]];
        $ch = curl_init($hook . (str_contains($hook, '?') ? '&' : '?') . 'wait=true');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE), CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4]);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        $db = Database::get();
        if ($code >= 200 && $code < 300) { $db->update('project_integrations', ['last_ok_at' => now(), 'last_error' => null], 'id = ?', [$i['id']]); return null; }
        $err = match (true) {
            $code === 0 => 'Sin conexión con Discord: ' . $cerr,
            $code === 401, $code === 404 => 'Discord no reconoce el webhook (¿se eliminó del canal?). Crea uno nuevo y pégalo aquí.',
            $code === 429 => 'Discord pidió esperar (demasiados mensajes). Se reintentará con el próximo aviso.',
            default => "Discord respondió $code.",
        };
        $db->update('project_integrations', ['last_error' => mb_substr($err, 0, 500)], 'id = ?', [$i['id']]);
        return $err;
    }

    // ------------------------------------------------------------------ GitHub

    private static function githubApi(): string
    {
        return rtrim((string)(getenv('BOREALIS_GITHUB_API') ?: config('integrations.github_api', 'https://api.github.com')), '/');
    }

    /**
     * GitHub: one repository per project («owner/name» or its address). Public repositories work
     * without a token (GitHub allows 60 queries per hour per server); private ones need a
     * fine-grained token with read-only access to «Issues», «Pull requests» and «Contents».
     */
    public static function saveGithub(int $projectId, array $input): void
    {
        $p = Projects::find($projectId);
        self::authorizeManage($p);
        $v = Validator::check($input, ['repo' => 'required,string,max:200', 'token' => 'nullable,string,max:300']);
        $repo = trim($v['repo']);
        if (preg_match('~github\.com/([\w.-]+)/([\w.-]+?)(?:\.git)?(?:/.*)?$~i', $repo, $m)) $repo = $m[1] . '/' . $m[2];
        if (!preg_match('~^[\w.-]+/[\w.-]+$~', $repo)) throw HttpException::invalid(['repo' => 'Escribe el repositorio como «organización/nombre» o pega su dirección de GitHub.']);
        $cur = self::get($projectId, 'github');
        $token = trim((string)$v['token']);
        $data = ['target' => $repo, 'enabled' => 1, 'cache' => null, 'cached_at' => null, 'last_error' => null, 'updated_at' => now()];
        if ($token !== '') $data['secret'] = Secrets::encrypt($token);
        $db = Database::get();
        if ($cur) $db->update('project_integrations', $data, 'id = ?', [$cur['id']]);
        else $db->insert('project_integrations', $data + ['project_id' => $projectId, 'provider' => 'github', 'created_by' => Auth::id(), 'created_at' => now()]);
        Activity::log('integration', 'project', $projectId, "Vinculó el repositorio $repo de GitHub", $projectId);
        self::github($projectId, true);
    }

    /** Repository summary, cached 10 minutes. Errors are returned, not thrown. */
    public static function github(int $projectId, bool $fresh = false): ?array
    {
        $i = self::get($projectId, 'github');
        if (!$i) return null;
        if (!$fresh && $i['cached_at'] && strtotime($i['cached_at'] . ' UTC') > time() - 600 && $i['cache']) return json_decode((string)$i['cache'], true) + ['repo' => $i['target'], 'error' => $i['last_error']];
        $token = $i['secret'] ? Secrets::decrypt((string)$i['secret']) : null;
        $repo = (string)$i['target'];
        $get = function (string $path) use ($token): array {
            $ch = curl_init(self::githubApi() . $path);
            $h = ['Accept: application/vnd.github+json', 'User-Agent: Borealis', 'X-GitHub-Api-Version: 2022-11-28'];
            if ($token) $h[] = 'Authorization: Bearer ' . $token;
            curl_setopt_array($ch, [CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return [$code, is_string($body) ? json_decode($body, true) : null];
        };
        [$code, $info] = $get("/repos/$repo");
        $db = Database::get();
        if ($code !== 200 || !is_array($info)) {
            $err = match (true) {
                $code === 0 => 'Sin conexión con GitHub.',
                $code === 404 => $token ? 'GitHub no encuentra el repositorio o el token no tiene acceso a él.' : 'GitHub no encuentra el repositorio. Si es privado, añade un token de solo lectura.',
                $code === 401 => 'GitHub rechazó el token (¿caducó?). Pega uno nuevo.',
                $code === 403 || $code === 429 => 'GitHub limitó las consultas (sin token: 60 por hora). Prueba más tarde o añade un token.',
                default => "GitHub respondió $code.",
            };
            $db->update('project_integrations', ['last_error' => $err, 'cached_at' => now()], 'id = ?', [$i['id']]);
            $old = $i['cache'] ? json_decode((string)$i['cache'], true) : [];
            return ($old ?: []) + ['repo' => $repo, 'error' => $err];
        }
        [, $issues] = $get("/repos/$repo/issues?state=open&per_page=5");
        [, $pulls] = $get("/repos/$repo/pulls?state=open&per_page=5");
        [, $commits] = $get("/repos/$repo/commits?per_page=5");
        $pick = fn($list, $fn) => is_array($list) && array_is_list($list) ? array_map($fn, array_slice($list, 0, 5)) : [];
        $data = [
            'name' => $info['full_name'] ?? $repo, 'url' => $info['html_url'] ?? "https://github.com/$repo", 'private' => !empty($info['private']), 'description' => $info['description'] ?? null,
            'open_issues' => (int)($info['open_issues_count'] ?? 0), 'pushed_at' => $info['pushed_at'] ?? null, 'default_branch' => $info['default_branch'] ?? null,
            'issues' => $pick(array_values(array_filter(is_array($issues) ? $issues : [], fn($x) => is_array($x) && !isset($x['pull_request']))), fn($x) => ['number' => $x['number'] ?? 0, 'title' => $x['title'] ?? '', 'url' => $x['html_url'] ?? '']),
            'pulls' => $pick($pulls, fn($x) => ['number' => $x['number'] ?? 0, 'title' => $x['title'] ?? '', 'url' => $x['html_url'] ?? '', 'user' => $x['user']['login'] ?? '']),
            'commits' => $pick($commits, fn($x) => ['sha' => substr((string)($x['sha'] ?? ''), 0, 7), 'message' => strtok((string)($x['commit']['message'] ?? ''), "\n"), 'url' => $x['html_url'] ?? '', 'date' => $x['commit']['author']['date'] ?? null]),
        ];
        $db->update('project_integrations', ['cache' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'cached_at' => now(), 'last_ok_at' => now(), 'last_error' => null], 'id = ?', [$i['id']]);
        return $data + ['repo' => $repo, 'error' => null];
    }

    /** Projects the person sees, with their links and integrations, for «Todo a mano». */
    public static function hub(): array
    {
        $out = [];
        foreach (Projects::list() as $p) {
            $pid = (int)$p['id'];
            $links = self::links($pid);
            $ints = self::forProject($pid);
            $out[] = ['project' => $p, 'links' => $links, 'integrations' => $ints, 'manage' => self::canManage($p)];
        }
        return $out;
    }
}
