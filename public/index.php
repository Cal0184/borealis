<?php
declare(strict_types=1);

// 1.16.6: the public files can live apart from the rest (core in a secret-named folder or outside the web).
// nucleo.php, next to this file, returns where the core is (relative to this folder or absolute).
$core = dirname(__DIR__);
if (is_file(__DIR__ . '/nucleo.php')) {
    $c = trim((string)(require __DIR__ . '/nucleo.php'));
    if ($c !== '') $core = (str_starts_with($c, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $c) === 1) ? rtrim($c, '/\\') : __DIR__ . '/' . trim($c, '/\\');
}
define('PUBLIC_PATH', __DIR__);
require $core . '/app/bootstrap.php';

use Borealis\Core\Config;
use Borealis\Core\ErrorHandler;
use Borealis\Core\Request;
use Borealis\Core\Session;

Config::load();
date_default_timezone_set('UTC');

if (!Config::isInstalled()) {
    (new Borealis\Controllers\InstallController())->handle()->send();
    exit;
}

// 1.14: the scheduled job by URL (/cron/run?token=…), without session or cookies.
$request = Request::capture();
// 1.15: one beat of the internal notification engine (see Services/Heartbeat.php).
if ($request->path === '/cron/tick') {
    try { Borealis\Controllers\CronController::tick($request); } catch (Throwable $e) { error_log('[Borealis] motor de avisos: ' . $e->getMessage()); }
    exit;
}
// 1.16: a device (service worker) or a page says «somebody is around» (see CronController::ping).
if ($request->path === '/cron/ping') {
    try { Borealis\Controllers\CronController::ping($request); } catch (Throwable $e) { error_log('[Borealis] ping: ' . $e->getMessage()); }
    exit;
}
if ($request->path === '/cron/run') {
    try { Borealis\Controllers\CronController::handle($request)->send(); } catch (Throwable $e) { error_log('[Borealis] cron: ' . $e->getMessage()); http_response_code(500); echo "Error\n"; }
    exit;
}
Session::start();
$GLOBALS['__borealis_request'] = $request;

try {
    /** @var Borealis\Core\Router $router */
    $router = require BASE_PATH . '/app/routes.php';
    $response = Borealis\Controllers\UpdateGate::check($request) ?? $router->dispatch($request);
} catch (Throwable $e) {
    $response = ErrorHandler::toResponse($e, $request);
}
$response->send();
// 1.14: if the cron job did not run in the last 5 minutes, run it now, after the answer was sent.
Borealis\Services\Scheduler::maybeAfterResponse();
