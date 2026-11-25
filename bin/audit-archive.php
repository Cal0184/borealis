<?php
declare(strict_types=1);

/*
 * Borealis 1.15 — retention of the Master Log (Registro maestro).
 *
 *   php bin/audit-archive.php --months=24            moves entries older than 24 months to files
 *   php bin/audit-archive.php --months=24 --dry-run  only says how many it would move
 *
 * Nothing in Borealis deletes the log. This script is for the person who runs the server, when the
 * table becomes too large or a retention policy requires it. For each workspace it:
 *   1. writes the old entries, with their hashes, to storage/audit-archive/ws<N>-<until>.jsonl.gz
 *      (one JSON object per line, gzip; storage/ is not reachable from the web);
 *   2. checks that the file reads back complete;
 *   3. only then deletes those entries, and records where the chain continues (audit_chain.base_id /
 *      base_hash), so «Comprobar integridad» keeps checking what stays;
 *   4. writes an entry in the log saying what was archived.
 * The archived file can be checked with the same hashes (each line has row_hash and prev_hash).
 * Without --months it uses config 'audit' => ['retention_months' => N]; with neither it does nothing.
 * Minimum: 6 months.
 */

if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/app/bootstrap.php';

use Borealis\Core\Database;
use Borealis\Services\Activity;

$opts = getopt('', ['months::', 'dry-run']);
$months = (int)($opts['months'] ?? config('audit.retention_months', 0));
$dry = isset($opts['dry-run']);
if ($months <= 0) { fwrite(STDOUT, "Sin política de conservación: no se archiva nada (usa --months=N o 'audit' => ['retention_months' => N]).\n"); exit(0); }
if ($months < 6) { fwrite(STDERR, "El mínimo es 6 meses.\n"); exit(2); }
if (!Activity::v115()) { fwrite(STDERR, "Falta la migración 017_v115: ejecuta php bin/migrate.php.\n"); exit(2); }

$db = Database::get();
$until = date('Y-m-d 00:00:00', strtotime("-$months months"));
$dir = storage_path('audit-archive');
if (!$dry && !is_dir($dir) && !@mkdir($dir, 0750, true)) { fwrite(STDERR, "No se pudo crear $dir\n"); exit(1); }

foreach ($db->column('SELECT id FROM workspaces ORDER BY id') as $ws) {
    $ws = (int)$ws;
    $count = (int)$db->value('SELECT COUNT(*) FROM activity WHERE workspace_id = ? AND created_at < ?', [$ws, $until]);
    if (!$count) continue;
    if ($dry) { fwrite(STDOUT, "Espacio $ws: $count registros anteriores a " . substr($until, 0, 10) . "\n"); continue; }
    $file = sprintf('%s/ws%d-%s.jsonl.gz', $dir, $ws, substr($until, 0, 10));
    $gz = gzopen($file, 'wb9');
    if (!$gz) { fwrite(STDERR, "No se pudo escribir $file\n"); exit(1); }
    $last = 0; $lastChained = null; $written = 0;
    do {
        $rows = $db->all('SELECT * FROM activity WHERE workspace_id = ? AND created_at < ? AND id > ? ORDER BY id LIMIT 2000', [$ws, $until, $last]);
        foreach ($rows as $r) {
            gzwrite($gz, json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            $last = (int)$r['id'];
            if ($r['row_hash']) $lastChained = $r;
            $written++;
        }
    } while (count($rows) === 2000);
    gzclose($gz);
    // read it back before deleting anything
    $lines = 0;
    $in = gzopen($file, 'rb');
    while (!gzeof($in)) { $l = gzgets($in); if ($l !== false && trim($l) !== '' && json_decode($l) !== null) $lines++; }
    gzclose($in);
    if ($lines !== $written) { fwrite(STDERR, "Espacio $ws: el archivo no coincide ($lines de $written); no se borra nada.\n"); exit(1); }
    $db->transaction(function (Database $db) use ($ws, $last, $lastChained) {
        $db->run('DELETE FROM activity WHERE workspace_id = ? AND id <= ?', [$ws, $last]);
        if ($lastChained) $db->run('UPDATE audit_chain SET base_id = ?, base_hash = ? WHERE workspace_id = ?', [$lastChained['id'], $lastChained['row_hash'], $ws]);
    });
    Activity::log('audit_archived', 'system', null, "Se archivaron $written registros anteriores al " . date('d/m/Y', strtotime($until)), null, null, [], $ws,
        ['category' => 'system', 'source' => 'cli', 'user_id' => null, 'after' => ['archivo' => basename($file), 'registros' => $written]]);
    fwrite(STDOUT, "Espacio $ws: $written registros en " . basename($file) . "\n");
}
