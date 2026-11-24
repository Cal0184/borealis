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

Session::start();
$request = Request::capture();
$GLOBALS['__borealis_request'] = $request;

try {
    /** @var Borealis\Core\Router $router */
    $router = require BASE_PATH . '/app/routes.php';
    $response = Borealis\Controllers\UpdateGate::check($request) ?? $router->dispatch($request);
} catch (Throwable $e) {
    $response = ErrorHandler::toResponse($e, $request);
}
$response->send();
