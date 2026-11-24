<?php
declare(strict_types=1);

// Writes a full SQL backup to storage/backups. Suitable for cron.
// Usage: php bin/backup.php [--keep=N]   With --keep, only the newest N automatic copies are kept.

if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/app/bootstrap.php';

use Borealis\Core\Config;
use Borealis\Services\Backup;

Config::load();
if (!Config::isInstalled()) {
    fwrite(STDERR, "Borealis no está instalado.\n");
    exit(1);
}
$keep = null;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--keep=(\d+)$/', $a, $m)) $keep = max(1, (int)$m[1]);
}
$file = Backup::create(null, 'cron');
echo "Copia creada: $file\n";
if ($keep === null) exit(0);
// Only automatic copies are rotated; manual and pre-update copies are never removed.
$auto = glob(Backup::dir() . '/borealis-*-cron.sql') ?: [];
rsort($auto);
foreach (array_slice($auto, $keep) as $old) {
    unlink($old);
    echo "Rotada: " . basename($old) . "\n";
}
