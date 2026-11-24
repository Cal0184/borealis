<?php
declare(strict_types=1);

// Imports a Borealis v0.2.x installation (file-based) into a Borealis 1.0 workspace.
// Usage: php bin/import-v022.php --email=admin@example.com [--workspace=ID] [--dry-run] /path/to/old/borealis
//        (the path may be the old folder, its storage/ folder, storage/borealis.json, or a ZIP of the old installation)
// Nothing existing is modified; a full backup is written before importing.

if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/app/bootstrap.php';

use Borealis\Core\Auth;
use Borealis\Core\Config;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Services\Backup;
use Borealis\Services\LegacyImport;

Config::load();
if (!Config::isInstalled()) { fwrite(STDERR, "Borealis 1.0 no está instalado todavía.\n"); exit(1); }
$opts = ['email' => null, 'workspace' => null, 'dry' => false, 'path' => null];
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--email=')) $opts['email'] = substr($a, 8);
    elseif (str_starts_with($a, '--workspace=')) $opts['workspace'] = (int)substr($a, 12);
    elseif ($a === '--dry-run') $opts['dry'] = true;
    else $opts['path'] = $a;
}
if (!$opts['email'] || !$opts['path']) { fwrite(STDERR, "Uso: php bin/import-v022.php --email=TU_CORREO [--workspace=ID] [--dry-run] RUTA\n"); exit(2); }
$path = rtrim($opts['path'], '/');
if (is_dir($path . '/storage')) $path .= '/storage';
if (is_dir($path)) $path .= '/borealis.json';
if (!is_file($path)) { fwrite(STDERR, "No se encontró $path\n"); exit(1); }

$db = Database::get();
$user = $db->one('SELECT * FROM users WHERE email = ? AND is_active = 1', [mb_strtolower($opts['email'])]);
if (!$user) { fwrite(STDERR, "No existe una cuenta activa con ese correo.\n"); exit(1); }
// Act as that person: permissions are checked exactly as in the web interface.
$_SESSION = ['uid' => (int)$user['id'], 'pwv' => Auth::passwordVersion($user), 'wsid' => $opts['workspace'] ?: (int)$user['last_workspace_id']];

try {
    [$data, $reader, $hash] = LegacyImport::open($path);
    $p = LegacyImport::preview($data);
    echo "Datos encontrados: {$p['projects']} proyectos, {$p['tasks']} tareas, {$p['subtasks']} elementos de checklist, {$p['comments']} comentarios, {$p['members']} personas, {$p['teams']} equipos, {$p['sprints']} sprints, {$p['files']} archivos.\n";
    LegacyImport::assertNotImported($hash);
    if ($opts['dry']) { echo "Simulación: no se escribió nada.\n"; exit(0); }
    echo 'Copia de seguridad previa: ' . Backup::create($db->pdo, 'pre-import') . "\n";
    $r = LegacyImport::run($data, $reader, $hash);
    echo "Importado: {$r['projects']} proyectos, {$r['tasks']} tareas, {$r['subtasks']} subtareas, {$r['comments']} comentarios, {$r['members']} personas nuevas ({$r['members_existing']} ya existían), {$r['teams']} equipos, {$r['sprints']} sprints, {$r['files']} archivos.\n";
    foreach ($r['warnings'] as $w) echo "Aviso: $w\n";
    echo "Las personas importadas no tienen contraseña utilizable: asígnasela en Administración › Personas.\n";
} catch (HttpException $e) {
    $msg = $e->getMessage();
    if (property_exists($e, 'errors') && $e->errors) $msg .= ' ' . implode(' ', (array)$e->errors);
    fwrite(STDERR, "No se importó nada: $msg\n");
    exit(1);
}
