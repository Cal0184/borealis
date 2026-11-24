<?php
declare(strict_types=1);

define('BOREALIS_VERSION', '1.13.0');
define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'Borealis\\')) {
        return;
    }
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 9)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require BASE_PATH . '/app/helpers.php';

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

set_exception_handler([Borealis\Core\ErrorHandler::class, 'handle']);
set_error_handler(function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($str, 0, $no, $file, $line);
});
