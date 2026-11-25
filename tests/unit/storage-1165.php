<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1); // 1.16.5: never runs when requested from the web
/*
 * 1.16.5 · «Proteger ahora» without a server or database:
 *   php tests/unit/storage-1165.php      → prints one JSON line {"ok":bool,"checks":[...]}
 * A copy of app/ in a temporary site folder: where the data folder is, moving it outside the web folder
 * (or to a secret name when that is not possible), config/storage.php, and what happens if it goes missing.
 */
$src = dirname(__DIR__, 2);
$checks = [];
$c = function (string $name, bool $ok, string $detail = '') use (&$checks) { $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $ok ? '' : $detail]; };

$copy = function (string $from, string $to) use (&$copy): void {
    if (is_dir($from)) { @mkdir($to, 0777, true); foreach (array_diff(scandir($from), ['.', '..']) as $e) $copy("$from/$e", "$to/$e"); }
    else copy($from, $to);
};
$rm = function (string $p) use (&$rm): void {
    if (is_dir($p) && !is_link($p)) { foreach (array_diff(scandir($p), ['.', '..']) as $e) $rm("$p/$e"); @rmdir($p); } elseif (file_exists($p)) @unlink($p);
};
/** Runs PHP code inside the temporary installation with the given document root; returns its JSON answer. */
$run = function (string $base, string $docroot, string $code): mixed {
    $script = $base . '/run-' . bin2hex(random_bytes(3)) . '.php';
    file_put_contents($script, "<?php\nputenv('BOREALIS_CONFIG=' . sys_get_temp_dir() . '/borealis-no-config.php');\n\$_SERVER['DOCUMENT_ROOT'] = " . var_export($docroot, true) . ";\nrequire " . var_export($base . '/app/bootstrap.php', true) . ";\n" . $code);
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>/dev/null');
    unlink($script);
    $j = json_decode((string)$out, true);
    return $j ?? ['raw' => $out];
};
$site = function () use ($src, $copy): array {
    $t = sys_get_temp_dir() . '/borealis-1165-' . bin2hex(random_bytes(4));
    $base = "$t/public_html/borealis";
    $copy("$src/app", "$base/app");
    @mkdir("$base/config", 0777, true);
    @mkdir("$base/storage/uploads/avatars", 0777, true);
    @mkdir("$base/storage/backups", 0777, true);
    file_put_contents("$base/storage/.htaccess", "Require all denied\n");
    file_put_contents("$base/storage/app.key", base64_encode(random_bytes(32)));
    file_put_contents("$base/storage/mail.json", '{"transport":"smtp"}');
    file_put_contents("$base/storage/uploads/avatars/a.webp", 'img');
    file_put_contents("$base/storage/backups/borealis-20261125-120000-manual.sql", '-- copia');
    return [$t, $base];
};

// 1 · Borealis inside the web folder (as on Cal's hosting): outside is possible
[$t, $base] = $site();
$key = file_get_contents("$base/storage/app.key");
$r = $run($base, "$t/public_html", 'echo json_encode(["place" => Borealis\Services\StorageMover::placement(), "target" => Borealis\Services\StorageMover::target()]);');
$c('storage/ dentro de la carpeta web se reconoce como «web»', ($r['place'] ?? '') === 'web', json_encode($r));
$c('El destino propuesto está fuera de la carpeta web, al lado de public_html', ($r['target']['kind'] ?? '') === 'outside' && ($r['target']['path'] ?? '') === "$t/borealis-datos", json_encode($r));
$r = $run($base, "$t/public_html", 'echo json_encode(Borealis\Services\StorageMover::protect() + ["now" => storage_path(), "place" => Borealis\Services\StorageMover::placement()]);');
$c('«Proteger ahora» mueve los datos fuera de la web', ($r['kind'] ?? '') === 'outside' && ($r['now'] ?? '') === "$t/borealis-datos" && ($r['place'] ?? '') === 'outside', json_encode($r));
$c('…con la llave, el correo, las fotos y las copias intactas', @file_get_contents("$t/borealis-datos/app.key") === $key && is_file("$t/borealis-datos/mail.json") && @file_get_contents("$t/borealis-datos/uploads/avatars/a.webp") === 'img' && is_file("$t/borealis-datos/backups/borealis-20261125-120000-manual.sql"));
$left = array_values(array_diff(scandir("$base/storage"), ['.', '..']));
sort($left);
$c('En storage/ quedan solo .htaccess e index.html', $left === ['.htaccess', 'index.html'], json_encode($left));
$cfg = (string)@file_get_contents("$base/config/storage.php");
$c('config/storage.php guarda el lugar nuevo y es PHP (desde la web no muestra nada)', str_starts_with($cfg, '<?php') && str_contains($cfg, "$t/borealis-datos"), $cfg);
$r = $run($base, "$t/public_html", 'echo json_encode(["now" => storage_path(), "missing" => Borealis\Core\StoragePath::missing()]);');
$c('Las peticiones siguientes usan el lugar nuevo', ($r['now'] ?? '') === "$t/borealis-datos" && $r['missing'] === null, json_encode($r));
$r = $run($base, "$t/public_html", 'try { Borealis\Services\StorageMover::protect(); echo json_encode(["ok" => true]); } catch (Throwable $e) { echo json_encode(["error" => $e->getMessage()]); }');
$c('Pulsarlo otra vez no mueve nada (ya está fuera)', str_contains((string)($r['error'] ?? ''), 'ya está fuera'), json_encode($r));
// the folder disappears (for example after copying Borealis to another server without it)
rename("$t/borealis-datos", "$t/otro-nombre");
$r = $run($base, "$t/public_html", 'echo json_encode(["now" => storage_path(), "missing" => Borealis\Core\StoragePath::missing()]);');
$c('Si la carpeta indicada no existe, usa storage/ y lo avisa', ($r['now'] ?? '') === "$base/storage" && ($r['missing'] ?? '') === "$t/borealis-datos", json_encode($r));
$rm($t);

// 2 · nothing writable above the web folder (document root «/»): secret name inside Borealis
[$t, $base] = $site();
$r = $run($base, '/', 'echo json_encode(Borealis\Services\StorageMover::protect() + ["now" => storage_path(), "place" => Borealis\Services\StorageMover::placement()]);');
$name = basename((string)($r['path'] ?? ''));
$c('Sin poder salir de la web, usa una carpeta con nombre secreto de 32 caracteres', ($r['kind'] ?? '') === 'secret' && preg_match('/^storage-[a-f0-9]{32}$/', $name) === 1 && ($r['place'] ?? '') === 'secret', json_encode($r));
$c('…con su propio .htaccess, un index.html vacío y los datos', is_file("$base/$name/.htaccess") && is_file("$base/$name/index.html") && is_file("$base/$name/app.key") && is_file("$base/$name/uploads/avatars/a.webp"));
$cfg = (string)@file_get_contents("$base/config/storage.php");
$c('config/storage.php guarda el nombre relativo (sirve aunque cambie la ruta del servidor)', str_contains($cfg, "'$name'") && !str_contains($cfg, $t), $cfg);
$r = $run($base, '/', 'echo json_encode(["rel" => Borealis\Services\Exposure::relative(storage_path())]);');
$c('La comprobación sabe en qué dirección buscarla', ($r['rel'] ?? '') === $name, json_encode($r));
$rm($t);

// 3 · config/ without write permission: nothing moves
[$t, $base] = $site();
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    $c('(config/ sin permiso: no se puede simular como root, se omite)', true);
} else {
    chmod("$base/config", 0555);
    $r = $run($base, "$t/public_html", 'try { Borealis\Services\StorageMover::protect(); echo json_encode(["ok" => true]); } catch (Throwable $e) { echo json_encode(["error" => $e->getMessage()]); }');
    chmod("$base/config", 0777);
    $c('Sin permiso en config/ lo dice y no mueve nada', str_contains((string)($r['error'] ?? ''), 'config/') && is_file("$base/storage/app.key"), json_encode($r));
}
$rm($t);

echo json_encode(['ok' => !array_filter($checks, fn($x) => !$x['ok']), 'checks' => $checks], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
