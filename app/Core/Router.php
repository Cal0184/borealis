<?php
declare(strict_types=1);

namespace Borealis\Core;

final class Router
{
    /** @var array<int, array{0:string,1:string,2:callable|array,3:array}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable|array $handler, array $opts = []): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', rtrim($pattern, '/') ?: '/') . '$#';
        $this->routes[] = [$method, $regex, $handler, $opts];
    }

    public function get(string $p, callable|array $h, array $o = []): void { $this->add('GET', $p, $h, $o); }
    public function post(string $p, callable|array $h, array $o = []): void { $this->add('POST', $p, $h, $o); }
    public function patch(string $p, callable|array $h, array $o = []): void { $this->add('PATCH', $p, $h, $o); }
    public function delete(string $p, callable|array $h, array $o = []): void { $this->add('DELETE', $p, $h, $o); }

    public function dispatch(Request $req): Response
    {
        $allowed = false;
        foreach ($this->routes as [$method, $regex, $handler, $opts]) {
            if (!preg_match($regex, $req->path, $m)) continue;
            $allowed = true;
            if ($method !== $req->method) continue;
            $req->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return $this->invoke($handler, $opts, $req);
        }
        if ($allowed) throw new HttpException(405, 'Método no permitido.');
        throw HttpException::notFound('Esta página no existe.');
    }

    private function invoke(callable|array $handler, array $opts, Request $req): Response
    {
        $public = $opts['public'] ?? false;
        if (!$public) {
            Auth::requireUser();
        }
        if ($req->method !== 'GET' && !($opts['nocsrf'] ?? false)) {
            Csrf::verify($req);
        }
        if (is_array($handler)) {
            [$class, $method] = $handler;
            $obj = new $class();
            return $obj->$method($req);
        }
        return $handler($req);
    }
}
