<?php
declare(strict_types=1);
/*
 * 1.16.4 (audit fixes), without a server or database:
 *   php tests/unit/security-1164.php      → prints one JSON line {"ok":bool,"checks":[...]}
 * Real IP behind a proxy, push addresses, exposure check address, text links.
 */
putenv('BOREALIS_CONFIG=' . sys_get_temp_dir() . '/borealis-no-config-' . getmypid() . '.php');
require dirname(__DIR__, 2) . '/app/bootstrap.php';
use Borealis\Core\Config;
use Borealis\Core\Request;
use Borealis\Services\Exposure;
use Borealis\Services\Push;
$checks = [];
$c = function (string $name, bool $ok, string $detail = '') use (&$checks) { $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $ok ? '' : $detail]; };
$ip = function (string $remote, ?string $xff, array $trusted) {
    Config::set('app.trusted_proxies', $trusted);
    $_SERVER['REMOTE_ADDR'] = $remote;
    if ($xff === null) unset($_SERVER['HTTP_X_FORWARDED_FOR']); else $_SERVER['HTTP_X_FORWARDED_FOR'] = $xff;
    return (new Request('GET', '/', [], [], [], []))->ip();
};
$c('Sin proxies de confianza, X-Forwarded-For se ignora (no se puede falsificar la IP)', $ip('203.0.113.9', '1.2.3.4', []) === '203.0.113.9');
$c('Desde el proxy de confianza se usa la IP real de la persona', $ip('10.0.0.5', '198.51.100.7', ['10.0.0.5']) === '198.51.100.7');
$c('Una IP inventada por la persona a la izquierda no cuenta', $ip('10.0.0.5', '6.6.6.6, 198.51.100.7', ['10.0.0.5']) === '198.51.100.7');
$c('Cadena de proxies de confianza: la primera IP que no es de un proxy', $ip('10.0.0.5', '198.51.100.7, 10.0.0.6', ['10.0.0.5', '10.0.0.6']) === '198.51.100.7');
$c('Si la petición no viene del proxy, X-Forwarded-For se ignora', $ip('203.0.113.9', '1.2.3.4', ['10.0.0.5']) === '203.0.113.9');
$c("Con ['*']: la última IP que agregó el proxy", $ip('172.16.0.1', '6.6.6.6, 198.51.100.7', ['*']) === '198.51.100.7');
$c('Valor que no es una IP: se usa la del servidor', $ip('10.0.0.5', 'basura', ['10.0.0.5']) === '10.0.0.5');
Config::set('app.trusted_proxies', []);

foreach (['https://fcm.googleapis.com/fcm/send/abc', 'https://updates.push.services.mozilla.com/wpush/v2/x', 'https://web.push.apple.com/QG', 'https://wns2-par02p.notify.windows.com/w/?token=x'] as $u) {
    $c("Servicio push aceptado: " . parse_url($u, PHP_URL_HOST), Push::validEndpoint($u), $u);
}
foreach (['https://intranet.local/hook', 'http://fcm.googleapis.com/x', 'https://fcm.googleapis.com.evil.cl/x', 'https://evilfcm.googleapis.com.cl/x', 'https://fcm.googleapis.com:8443/x', 'https://u:p@fcm.googleapis.com/x', 'https://127.0.0.1/x'] as $u) {
    $c("Dirección rechazada: $u", !Push::validEndpoint($u), $u);
}
Config::set('push.allowed_hosts', ['push.example.cl']);
$c('push.allowed_hosts agrega servicios', Push::validEndpoint('https://push.example.cl/x'));
Config::set('push.allowed_hosts', []);

Config::set('app.url', 'https://gepdev.bonfyr.cl/borealis');
Config::set('app.base_path', '/borealis');
$c('La comprobación de storage/ usa la dirección pública con la carpeta', Exposure::baseUrl() === 'https://gepdev.bonfyr.cl/borealis', Exposure::baseUrl());
Config::set('app.url', 'https://example.org');
Config::set('app.base_path', '');
$c('…y sin carpeta', Exposure::baseUrl() === 'https://example.org', Exposure::baseUrl());

$html = rich_text('Ver https://x.cl/a**b**c y https://drive.google.com/d/1a_B_c/view **listo**');
$c('Los enlaces con ** o _ no se cortan', str_contains($html, 'href="https://x.cl/a**b**c"') && str_contains($html, 'href="https://drive.google.com/d/1a_B_c/view"') && str_contains($html, '<strong>listo</strong>'), $html);
$c('El texto sigue escapado', !str_contains(rich_text('<script>alert(1)</script> https://x.cl/"onmouseover=x'), '<script>') && !str_contains(rich_text('https://x.cl/"onmouseover=x'), '"onmouseover'), rich_text('https://x.cl/"onmouseover=x'));

echo json_encode(['ok' => !in_array(false, array_column($checks, 'ok'), true), 'checks' => $checks], JSON_UNESCAPED_UNICODE), "\n";
