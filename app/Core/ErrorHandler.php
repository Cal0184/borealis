<?php
declare(strict_types=1);

namespace Borealis\Core;

final class ErrorHandler
{
    public static function handle(\Throwable $e): void
    {
        self::toResponse($e, $GLOBALS['__borealis_request'] ?? null)->send();
    }

    public static function toResponse(\Throwable $e, ?Request $req): Response
    {
        $status = $e instanceof HttpException ? $e->status : 500;
        if ($status >= 500) {
            self::log($e);
        }
        $message = $e instanceof HttpException ? $e->getMessage() : 'Ocurrió un error inesperado. El problema quedó registrado.';
        if ($status === 500 && Config::get('app.debug')) {
            $message .= ' [' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ']';
        }
        $isApi = $req?->isApi() || ($req && str_contains((string)$req->header('accept'), 'application/json'));
        if ($isApi) {
            return Response::json(['ok' => false, 'error' => $message, 'errors' => $e instanceof HttpException ? (object)$e->errors : new \stdClass()], $status);
        }
        if ($status === 401 && $req) {
            $next = $req->path . ($req->query ? '?' . http_build_query($req->query) : '');
            return Response::redirect(url('/login', ['next' => $next]));
        }
        try {
            $layout = Auth::user() ? 'app' : 'auth';
            return View::page('error', ['status' => $status, 'message' => $message, 'title' => 'Error', 'layout' => $layout], $status);
        } catch (\Throwable $inner) {
            self::log($inner);
            return Response::html('<h1>Error ' . $status . '</h1><p>' . e($message) . '</p>', $status);
        }
    }

    public static function log(\Throwable $e): void
    {
        $line = sprintf("[%s] %s: %s in %s:%d\n%s\n\n", gmdate('c'), get_class($e), $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
        @file_put_contents(BASE_PATH . '/storage/logs/error.log', $line, FILE_APPEND | LOCK_EX);
        // 1.15: also in the Master Log, without the trace (Administración › Registro maestro › Errores)
        try { \Borealis\Services\Activity::systemError($e); } catch (\Throwable) {}
    }
}
