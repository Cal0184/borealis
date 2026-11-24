<?php
declare(strict_types=1);

// Applies pending database migrations. A full SQL backup is written first.
// Usage: php bin/migrate.php [--status] [--no-backup]

if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/app/bootstrap.php';

use Borealis\Core\Config;
use Borealis\Core\Database;
use Borealis\Core\Migrator;
use Borealis\Services\Backup;

Config::load();
if (!Config::isInstalled()) {
    fwrite(STDERR, "Borealis no está instalado: abre la aplicación en el navegador para ejecutar el instalador.\n");
    exit(1);
}
$args = array_slice($argv, 1);
$pdo = Database::get()->pdo;
$m = new Migrator($pdo);
$pending = $m->pending();

echo 'Borealis ' . BOREALIS_VERSION . ' · aplicadas: ' . count($m->applied()) . ' · pendientes: ' . count($pending) . "\n";
foreach (array_keys($pending) as $v) echo "  - $v\n";
if (in_array('--status', $args, true)) exit(0);
if (!$pending) {
    echo "La base de datos está al día.\n";
    exit(0);
}
if (!in_array('--no-backup', $args, true)) {
    $file = Backup::create($pdo, 'pre-update');
    echo "Copia de seguridad: $file\n";
}
try {
    foreach ($m->migrate() as $v) echo "Aplicada: $v\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Error al migrar: " . $e->getMessage() . "\nLa copia previa permite restaurar el estado anterior.\n");
    exit(1);
}
echo "Listo.\n";
