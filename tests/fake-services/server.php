<?php
declare(strict_types=1);

/*
 * Local stand-ins, ONLY for the test-suite, of three outside services Borealis 1.8 talks to:
 *   /push/{name}                 a browser push service (FCM, Mozilla, Apple). Stores what it gets;
 *                                /push/gone answers 410 (the device unsubscribed); /push/flaky answers
 *                                503 after POST /_down (until POST /_up); /push/badauth answers 403.
 *   /discord/api/webhooks/{id}/{token}  a Discord channel webhook; …/broken answers 404.
 *   /gh/repos/{owner}/{name}[/issues|/pulls|/commits]  the GitHub REST API (acme/web is public,
 *                                acme/secret needs «Authorization: Bearer good-token»).
 * It proves Borealis' side (what is sent, how it is encrypted and signed, how errors are handled);
 * it does not prove that Google, Mozilla, Apple, Discord or GitHub accept it.
 *
 *   FAKE_SERVICES_LOG=/tmp/fs.jsonl php -S 127.0.0.1:8098 tests/fake-services/server.php
 * GET /_log returns every request received (JSON list); POST /_reset empties it.
 */

$log = getenv('FAKE_SERVICES_LOG') ?: sys_get_temp_dir() . '/fake-services.jsonl';
$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$body = (string)file_get_contents('php://input');
$headers = function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : [];

function out(int $code, mixed $data = null): never
{
    http_response_code($code);
    if ($data !== null) { header('Content-Type: application/json'); echo json_encode($data); }
    exit;
}

if ($path === '/_log') {
    $rows = is_file($log) ? array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)file_get_contents($log)))) : [];
    out(200, $rows);
}
if ($path === '/_reset') { @unlink($log); out(204); }
// 1.14: /push/flaky answers 503 (temporary failure) while «down» is on, so retries can be tested.
if ($path === '/_down') { touch($log . '.down'); out(204); }
if ($path === '/_up') { @unlink($log . '.down'); out(204); }

file_put_contents($log, json_encode(['method' => $method, 'path' => $path, 'query' => $_SERVER['QUERY_STRING'] ?? '', 'headers' => $headers, 'body' => base64_encode($body)]) . "\n", FILE_APPEND | LOCK_EX);

if (str_starts_with($path, '/push/')) {
    if ($path === '/push/gone') out(410, ['error' => 'gone']);
    if ($path === '/push/flaky' && is_file($log . '.down')) out(503, ['error' => 'try later']);
    if ($path === '/push/badauth') out(403, ['error' => 'invalid VAPID']);
    out(201);
}
if (preg_match('~^/discord/api/webhooks/(\d+)/([\w-]+)$~', $path, $m)) {
    if ($m[2] === 'broken') out(404, ['message' => 'Unknown Webhook', 'code' => 10015]);
    out(200, ['id' => '1', 'channel_id' => '99', 'content' => json_decode($body, true)['content'] ?? '']);
}
if (preg_match('~^/gh/repos/(acme)/(web|secret)(/(issues|pulls|commits))?$~', $path, $m)) {
    if ($m[2] === 'secret' && ($headers['authorization'] ?? '') !== 'Bearer good-token') out(404, ['message' => 'Not Found']);
    $full = "acme/{$m[2]}";
    $sub = $m[4] ?? '';
    if ($sub === 'issues') out(200, [
        ['number' => 12, 'title' => 'El botón de exportar no responde', 'html_url' => "https://github.com/$full/issues/12"],
        ['number' => 13, 'title' => 'PR disfrazada de issue', 'html_url' => "https://github.com/$full/pull/13", 'pull_request' => ['url' => 'x']],
    ]);
    if ($sub === 'pulls') out(200, [['number' => 13, 'title' => 'Arregla la exportación', 'html_url' => "https://github.com/$full/pull/13", 'user' => ['login' => 'lissette']]]);
    if ($sub === 'commits') out(200, [['sha' => 'abcdef1234567890', 'html_url' => "https://github.com/$full/commit/abcdef1", 'commit' => ['message' => "Primera versión\n\ndetalle", 'author' => ['date' => '2026-11-01T10:00:00Z']]]]);
    out(200, ['full_name' => $full, 'html_url' => "https://github.com/$full", 'private' => $m[2] === 'secret', 'description' => 'Repositorio de prueba', 'open_issues_count' => 2, 'pushed_at' => '2026-11-01T10:00:00Z', 'default_branch' => 'main']);
}
out(404, ['message' => 'Not Found']);
