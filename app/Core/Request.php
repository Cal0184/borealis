<?php
declare(strict_types=1);

namespace Borealis\Core;

final class Request
{
    private ?array $json = null;
    public array $params = [];

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $files,
        public readonly array $headers,
        private readonly string $rawBody = '',
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $query = $_GET;
        if (isset($query['r'])) {
            $path = (string)$query['r'];
            unset($query['r']);
        } else {
            $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            $base = rtrim((string)Config::get('app.base_path', ''), '/');
            if ($base !== '' && str_starts_with($uri, $base)) $uri = substr($uri, strlen($base));
            if (str_starts_with($uri, '/index.php')) $uri = substr($uri, 10);
            $path = $uri ?: '/';
        }
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
        }
        if (isset($_SERVER['CONTENT_TYPE'])) $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        $raw = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? (string)file_get_contents('php://input') : '';
        return new self($method, '/' . trim($path, '/'), $query, $_POST, $_FILES, $headers, $raw);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isApi(): bool
    {
        return str_starts_with($this->path, '/api/');
    }

    public function wantsPartial(): bool
    {
        return $this->header('x-borealis') === 'partial';
    }

    /** Body input: JSON when sent as JSON, form fields otherwise. */
    public function input(?string $key = null, mixed $default = null): mixed
    {
        if ($this->json === null) {
            $ct = (string)$this->header('content-type');
            if (str_contains($ct, 'application/json') && $this->rawBody !== '') {
                $decoded = json_decode($this->rawBody, true);
                $this->json = is_array($decoded) ? $decoded : [];
            } else {
                $this->json = $this->post;
            }
        }
        if ($key === null) return $this->json;
        return $this->json[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        $this->input();
        return array_key_exists($key, $this->json);
    }

    public function q(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * The visitor's address. 1.16.4: behind a proxy or CDN, REMOTE_ADDR is the proxy for everybody (and a
     * few wrong passwords would lock out the whole company). With 'app.trusted_proxies' => ['10.0.0.5', …]
     * (or ['*'] when the server is only reachable through the proxy) the address comes from X-Forwarded-For,
     * read from the right and skipping the trusted proxies. Without that setting it is never trusted.
     */
    public function ip(): string
    {
        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $trusted = (array)Config::get('app.trusted_proxies', []);
        if (!$trusted) return $remote;
        $any = in_array('*', $trusted, true);
        if (!$any && !in_array($remote, $trusted, true)) return $remote;
        $chain = array_values(array_filter(array_map('trim', explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')))));
        // '*': one proxy in front, so the last address it added is the visitor
        if ($any) return ($last = end($chain)) && filter_var($last, FILTER_VALIDATE_IP) ? $last : $remote;
        foreach (array_reverse($chain) as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) break;
            if (!in_array($ip, $trusted, true)) return $ip;
        }
        return $remote;
    }

    public function param(string $key): string
    {
        return (string)($this->params[$key] ?? '');
    }

    public function intParam(string $key): int
    {
        $v = $this->params[$key] ?? '';
        if (!ctype_digit((string)$v)) throw HttpException::notFound();
        return (int)$v;
    }
}
