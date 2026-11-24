<?php
declare(strict_types=1);

// Borealis 1.4.1 — read-only review of accounts and memberships that the 1.4.0 flaws could have produced.
// Usage: php bin/audit-accounts.php
// It changes NOTHING. It lists what deserves a human look; decide case by case in Administración.

if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/app/bootstrap.php';

use Borealis\Core\Config;
use Borealis\Core\Database;

Config::load();
if (!Config::isInstalled()) { fwrite(STDERR, "Borealis no está instalado.\n"); exit(1); }
$db = Database::get();

$sections = [
    '1. Espacios creados por cuentas que no administran la instalación' =>
        'SELECT w.id AS espacio, w.name AS nombre, u.email AS creado_por, w.created_at AS fecha
         FROM workspaces w LEFT JOIN users u ON u.id = w.created_by
         WHERE COALESCE(u.is_instance_admin, 0) = 0 ORDER BY w.created_at',
    '2. Cuentas incorporadas a un espacio con «Crear cuenta» cuando ya pertenecían a otro (sin invitación)' =>
        "SELECT a.workspace_id AS espacio, u.email AS cuenta, actor.email AS incorporada_por, a.created_at AS fecha
         FROM activity a JOIN users u ON u.id = a.entity_id LEFT JOIN users actor ON actor.id = a.user_id
         WHERE a.action = 'created' AND a.entity_type = 'user' AND a.summary LIKE 'Incorporó a %'
           AND EXISTS (SELECT 1 FROM workspace_members o WHERE o.user_id = u.id AND o.workspace_id <> a.workspace_id AND o.joined_at < a.created_at)
         ORDER BY a.created_at",
    '3. Contraseñas restablecidas por la administración de un espacio en cuentas compartidas o de la instalación' =>
        "SELECT a.workspace_id AS espacio, u.email AS cuenta, actor.email AS restablecida_por, a.created_at AS fecha, u.is_instance_admin AS admin_instalacion
         FROM activity a JOIN users u ON u.id = a.entity_id LEFT JOIN users actor ON actor.id = a.user_id
         WHERE a.action = 'password_reset' AND (u.is_instance_admin = 1 OR (SELECT COUNT(*) FROM workspace_members wm WHERE wm.user_id = u.id) > 1)
           AND COALESCE(actor.is_instance_admin, 0) = 0
         ORDER BY a.created_at",
    '4. Personas que cambiaron su propio rol' =>
        "SELECT a.workspace_id AS espacio, u.email AS cuenta, a.summary AS cambio, a.created_at AS fecha
         FROM activity a JOIN users u ON u.id = a.entity_id
         WHERE a.action = 'role_changed' AND a.user_id = a.entity_id ORDER BY a.created_at",
];

$found = 0;
foreach ($sections as $title => $sql) {
    $rows = $db->all($sql);
    echo "\n== $title: " . count($rows) . "\n";
    foreach ($rows as $r) {
        echo '  - ' . implode(' · ', array_map(fn($k, $v) => "$k: " . ($v ?? '—'), array_keys($r), $r)) . "\n";
    }
    $found += count($rows);
}
echo "\n" . ($found ? "Revisa estos casos en Administración › Usuarios e Historial. Este script no ha modificado nada.\n" : "No se encontró nada sospechoso. Este script no ha modificado nada.\n");
