<?php
declare(strict_types=1);

namespace Borealis\Core;

final class Response
{
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = ['Content-Type' => 'text/html; charset=utf-8'],
        public ?string $file = null,
    ) {
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $status,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    public static function ok(array $data = [], string $message = 'Guardado'): self
    {
        return self::json(['ok' => true, 'message' => $message] + $data);
    }

    public static function redirect(string $to, int $status = 303): self
    {
        return new self('', $status, ['Location' => $to]);
    }

    public static function download(string $path, string $name, string $mime, bool $inline = false): self
    {
        $safe = preg_replace('/[^\w.\- ()]+/u', '_', $name) ?: 'archivo';
        return new self('', 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string)filesize($path),
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . addcslashes($safe, '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($name),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, max-age=0',
        ], $path);
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            $defaults = [
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'DENY',
                'Referrer-Policy' => 'same-origin',
                'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'self'",
            ];
            // 1.16.6: over HTTPS, browsers must not fall back to plain HTTP for this host (no subdomains).
            if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || str_starts_with((string)Config::get('app.url', ''), 'https://')) {
                $defaults['Strict-Transport-Security'] = 'max-age=15552000';
            }
            // 1.6: pages are personal (greeting, notifications, permissions). Never let a proxy or the
            // browser keep one person's page for another (e.g. a caching Nginx template in HestiaCP).
            if (str_starts_with($this->headers['Content-Type'] ?? '', 'text/html') && !isset($this->headers['Cache-Control'])) {
                $defaults['Cache-Control'] = 'no-store, private';
                $defaults['Vary'] = 'Cookie';
            }
            foreach ($this->headers + $defaults as $k => $v) {
                header($k . ': ' . $v);
            }
            // 1.16: on Apache mod_php the page must say where it ends, so that the work done after
            // it (notices, the engine) never keeps the browser waiting (Core/Background.php)
            if ($this->file === null) Background::compatHeaders(strlen($this->body));
        }
        if ($this->file !== null) {
            readfile($this->file);
            return;
        }
        echo $this->body;
    }
}
