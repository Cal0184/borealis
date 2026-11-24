<?php
declare(strict_types=1);

/*
 * Links that leave Borealis (1.7), without a server or database:
 *   php tests/unit/urls.php      → prints one JSON line {"ok":bool,"checks":[...]}
 * With Borealis in a folder (Cal's case: https://gepdev.bonfyr.cl/borealis) the folder must appear once.
 */
putenv('BOREALIS_CONFIG=' . sys_get_temp_dir() . '/borealis-no-config-' . getmypid() . '.php');
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use Borealis\Core\Config;
use Borealis\Services\Google;

$checks = [];
$c = function (string $name, bool $ok, string $detail = '') use (&$checks) { $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $ok ? '' : $detail]; };

$cases = [
    // app.url as the installer writes it (with the folder), as some admins write it (without), and with a trailing slash
    ['https://gepdev.bonfyr.cl/borealis', '/borealis', true, 'https://gepdev.bonfyr.cl/borealis/login'],
    ['https://gepdev.bonfyr.cl', '/borealis', true, 'https://gepdev.bonfyr.cl/borealis/login'],
    ['https://gepdev.bonfyr.cl/borealis/', '/borealis/', true, 'https://gepdev.bonfyr.cl/borealis/login'],
    ['https://example.org', '', true, 'https://example.org/login'],
    ['http://127.0.0.1:8080', '', true, 'http://127.0.0.1:8080/login'],
    ['https://gepdev.bonfyr.cl/borealis', '/borealis', false, 'https://gepdev.bonfyr.cl/borealis/index.php?r=%2Flogin'],
];
foreach ($cases as [$appUrl, $base, $pretty, $want]) {
    Config::set('app.url', $appUrl);
    Config::set('app.base_path', $base);
    Config::set('app.pretty_urls', $pretty);
    $got = absolute_url('/login');
    $c("Inicio de sesión con app.url «{$appUrl}» y ruta base «{$base}»" . ($pretty ? '' : ' (sin URL amigables)'), $got === $want, $got);
}
Config::set('app.url', 'https://gepdev.bonfyr.cl/borealis');
Config::set('app.base_path', '/borealis');
Config::set('app.pretty_urls', true);
$inv = absolute_url('/invite/abc123');
$c('Invitación: la carpeta aparece una sola vez', $inv === 'https://gepdev.bonfyr.cl/borealis/invite/abc123', $inv);
$c('Enlace copiado de una tarea: la carpeta aparece una sola vez', absolute_url('/t/WEB-12') === 'https://gepdev.bonfyr.cl/borealis/t/WEB-12', absolute_url('/t/WEB-12'));
$c('Dirección de regreso de Google: la carpeta aparece una sola vez', Google::redirectUri() === 'https://gepdev.bonfyr.cl/borealis/google/callback', Google::redirectUri());

// 1.7.1: every import between modules carries the version, so a server that caches .js files for
// a long time (Nginx «expires max») cannot keep serving the modules of an older version.
$bad = [];
foreach (glob(dirname(__DIR__, 2) . '/public/assets/js/*.js') as $f) {
    preg_match_all("~(?:\\bfrom|\\bimport)\\s*\\(?\\s*'(\\./[^']+)'~", file_get_contents($f), $mm);
    foreach ($mm[1] as $spec) if (!str_ends_with($spec, '?v=' . BOREALIS_VERSION)) $bad[] = basename($f) . ': ' . $spec;
}
$c('Cada módulo de JavaScript se importa con la versión (?v=' . BOREALIS_VERSION . ')', !$bad, implode(', ', array_slice($bad, 0, 5)));

echo json_encode(['ok' => !in_array(false, array_column($checks, 'ok'), true), 'checks' => $checks], JSON_UNESCAPED_UNICODE), "\n";
