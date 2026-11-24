<?php
declare(strict_types=1);

/*
 * Borealis — stamps the version on the imports between JavaScript modules.
 *
 *   php bin/stamp-assets.php
 *
 * The page loads app.js with ?v=…, but the modules it imports («./tour.js», «./core.js»…) are
 * requested by the browser without it. Servers that tell browsers to keep .js files for a long
 * time (Nginx with «expires max», HestiaCP's default) then keep serving the modules of an older
 * version after an update: that is why the 1.6 tutorial kept behaving like the old one.
 * Every import carries «?v=<version>», always the same string, so each module is loaded once.
 * It runs before packaging a release; tests/unit/urls.php checks that nothing was left out.
 */

if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
$src = file_get_contents($root . '/app/bootstrap.php');
if (!preg_match("/define\('BOREALIS_VERSION', '([^']+)'\)/", $src, $m)) { fwrite(STDERR, "Versión no encontrada\n"); exit(1); }
$v = $m[1];
$n = 0;
foreach (glob($root . '/public/assets/js/*.js') as $file) {
    $code = file_get_contents($file);
    $new = preg_replace_callback("~((?:\\bfrom|\\bimport)\\s*\\(?\\s*)'(\\./[A-Za-z0-9_-]+\\.js)(?:\\?v=[^']*)?'~", fn($x) => $x[1] . "'" . $x[2] . '?v=' . $v . "'", $code, -1, $c);
    if ($new !== $code) { file_put_contents($file, $new); $n += $c; }
}
echo "Importaciones con ?v=$v: $n\n";
