<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

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
