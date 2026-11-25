<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1); // never runs when requested from the web
/*
 * 1.16.6 · Public files apart from the core (nucleo.php), without a server or database:
 *   php tests/unit/layout-1166.php      → prints one JSON line {"ok":bool,"checks":[...]}
 * Each case runs in its own PHP process, because PUBLIC_PATH and BASE_PATH are constants.
 */
$root = dirname(__DIR__, 2);
$checks = [];
$c = function (string $name, bool $ok, string $detail = '') use (&$checks) { $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $ok ? '' : $detail]; };
$tmp = sys_get_temp_dir() . '/borealis-layout-' . getmypid();
@mkdir($tmp . '/web/borealis', 0777, true);
@mkdir($tmp . '/web/borealis/nucleo-0123456789abcdef0123456789abcdef', 0777, true);
shell_exec('cp -R ' . escapeshellarg($root . '/app') . ' ' . escapeshellarg($tmp . '/web/borealis/nucleo-0123456789abcdef0123456789abcdef/'));
$run = function (string $public, string $url, string $basePath, string $core) use ($tmp): string {
    $code = 'putenv("BOREALIS_CONFIG=' . $tmp . '/no-config.php"); define("PUBLIC_PATH", ' . var_export($public, true) . ');'
        . 'require ' . var_export($core . '/app/bootstrap.php', true) . ';'
        . 'Borealis\Core\Config::set("app.url", ' . var_export($url, true) . '); Borealis\Core\Config::set("app.base_path", ' . var_export($basePath, true) . ');'
        . 'echo Borealis\Services\Exposure::coreUrl(), "|", asset("css/borealis.css");';
    return (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1');
};
$core = $tmp . '/web/borealis/nucleo-0123456789abcdef0123456789abcdef';
[$u, $a] = explode('|', $run($tmp . '/web/borealis', 'https://gep.uchile.cl/borealis', '/borealis', $core)) + ['', ''];
$c('Separado: la comprobación busca el núcleo dentro de la carpeta pública', $u === 'https://gep.uchile.cl/borealis/nucleo-0123456789abcdef0123456789abcdef', $u);
$c('Separado: los estilos se sirven desde la carpeta pública', str_starts_with($a, '/borealis/assets/css/borealis.css?v='), $a);
[$u] = explode('|', $run($root . '/public', 'https://gepdev.bonfyr.cl/borealis/public', '/borealis/public', $root));
$c('Todo subido con Nginx (…/public): el núcleo está una carpeta arriba', $u === 'https://gepdev.bonfyr.cl/borealis', $u);
[$u] = explode('|', $run($root . '/public', 'https://borealis.example.cl', '', $root));
$c('Raíz web en public/: la misma dirección', $u === 'https://borealis.example.cl', $u);
[$u] = explode('|', $run($root . '/public', 'https://gepdev.bonfyr.cl/borealis', '/borealis', $root));
$c('Apache con .htaccess (sin …/public): la misma dirección', $u === 'https://gepdev.bonfyr.cl/borealis', $u);
shell_exec('rm -rf ' . escapeshellarg($tmp));
$ok = !array_filter($checks, fn($x) => !$x['ok']);
echo json_encode(['ok' => $ok, 'checks' => $checks], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
exit($ok ? 0 : 1);
