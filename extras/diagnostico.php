<?php
/*
 * Borealis · Diagnóstico de servidor (traslado)
 * ---------------------------------------------
 * Sube este archivo por FTP a la carpeta de Borealis (la que tiene app/, config/, public/, storage/)
 * y ábrelo en el navegador: https://TU-DOMINIO/borealis/diagnostico.php
 *
 * Revisa: PHP y extensiones, permisos de escritura, config/config.php, conexión a la base de datos
 * (explica el error exacto), si storage/ o config.php se pueden descargar, URLs y salida a Gmail/Google.
 *
 * Seguridad: no guarda contraseñas, no muestra datos de personas, deja de funcionar 3 horas después
 * de la primera visita y tiene un botón para borrarse. Bórralo igual con FileZilla al terminar.
 * Funciona aunque Borealis aún no esté subido (solo PHP y base de datos). Compatible con PHP 5.6+.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
@set_time_limit(60);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

define('DIAG_HORAS', 3);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function sw($h, $n) { return $n === '' || strpos($h, $n) === 0; }

// ---------- caducidad y borrado ----------
$self = __FILE__;
$marker = dirname($self) . '/.diagnostico-inicio';
$inicio = @is_file($marker) ? (int)@file_get_contents($marker) : 0;
if ($inicio <= 0) {
    $inicio = time();
    if (@file_put_contents($marker, (string)$inicio) === false) $inicio = (int)@filemtime($self) ?: time();
}
$restante = $inicio + DIAG_HORAS * 3600 - time();
$borrar = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['borrar']);
if ($restante <= 0 || $borrar) {
    @unlink($marker);
    $ok = @unlink($self);
    http_response_code($borrar ? 200 : 410);
    echo '<!doctype html><meta charset="utf-8"><title>Diagnóstico</title><body style="font:16px system-ui;margin:40px">';
    echo $ok ? '<h1>Diagnóstico borrado</h1><p>El archivo se eliminó del servidor.</p>'
             : '<h1>Diagnóstico ' . ($borrar ? '' : 'caducado') . '</h1><p><strong>No se pudo borrar solo: bórralo con FileZilla</strong> (diagnostico.php y .diagnostico-inicio).</p>';
    exit;
}

// ---------- dónde está Borealis ----------
$dir = __DIR__;
$base = null; $split = false;
if (is_file($dir . '/nucleo.php')) { // 1.16.6: archivos públicos aquí, el resto en otra carpeta
    $c = trim((string)(include $dir . '/nucleo.php'));
    if ($c !== '') { $c = (sw($c, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $c)) ? rtrim($c, '/') : $dir . '/' . trim($c, '/');
        if (is_file($c . '/app/bootstrap.php')) { $base = $c; $split = true; } }
}
if ($base === null) foreach (array($dir, dirname($dir)) as $c) if (is_file($c . '/app/bootstrap.php')) { $base = $c; break; }
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
$origin = ($https ? 'https' : 'http') . '://' . $host;
$scriptDir = rtrim(str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/')), '/');
// web path of the Borealis folder (the diagnostic may be in it or in public/)
$baseWeb = ($base !== null && !$split && $base !== $dir) ? rtrim(dirname($scriptDir), '/') : $scriptDir;
// carpeta del disco que corresponde a $baseWeb
$webDir = ($base !== null && !$split) ? $base : $dir;
if ($baseWeb === '.' || $baseWeb === '\\') $baseWeb = '';

/** Web URL for a path inside the Borealis folder, or null when it is outside it. */
function web_url($abs) {
    global $base, $origin, $baseWeb;
    global $webDir;
    $abs = str_replace('\\', '/', $abs); $b = str_replace('\\', '/', $webDir);
    if (!sw($abs, $b . '/')) return null;
    return $origin . $baseWeb . substr($abs, strlen($b));
}

/** GET a URL of this same server: [status, body] or null when the server cannot call itself. */
function probe($url) {
    if (!function_exists('curl_init')) {
        $ctx = stream_context_create(array('http' => array('timeout' => 6, 'ignore_errors' => true), 'ssl' => array('verify_peer' => false, 'verify_peer_name' => false)));
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false || empty($http_response_header[0])) return null;
        preg_match('~\s(\d{3})\s~', $http_response_header[0] . ' ', $m);
        return array(isset($m[1]) ? (int)$m[1] : 0, substr($body, 0, 4000));
    }
    foreach (array(false, true) as $loop) {
        $ch = curl_init($url);
        $opt = array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_FOLLOWLOCATION => false, CURLOPT_RANGE => '0-3999');
        if ($loop) { // NAT sin "hairpin": se llama a sí mismo por 127.0.0.1
            $p = parse_url($url); $port = isset($p['port']) ? $p['port'] : ($p['scheme'] === 'https' ? 443 : 80);
            $opt[CURLOPT_RESOLVE] = array($p['host'] . ':' . $port . ':127.0.0.1');
        }
        curl_setopt_array($ch, $opt);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body !== false && $code > 0) return array($code, (string)$body);
    }
    return null;
}

// ---------- resultados ----------
$R = array(); // sección => lista de [estado ok|warn|bad|info, texto, detalle]
function add($sec, $st, $txt, $det = '') { global $R; $R[$sec][] = array($st, $txt, $det); }

// 1. PHP
$S = '1. PHP del servidor';
add($S, version_compare(PHP_VERSION, '8.2.0', '>=') ? 'ok' : 'bad', 'PHP ' . PHP_VERSION, version_compare(PHP_VERSION, '8.2.0', '>=') ? '' : 'Borealis necesita PHP 8.2 o superior. Pide al soporte de la U. que active PHP 8.2/8.3 para este sitio.');
foreach (array('pdo_mysql' => 'conectar a MySQL', 'mbstring' => 'textos', 'fileinfo' => 'revisar archivos subidos', 'iconv' => 'textos', 'openssl' => 'avisos al móvil y correo', 'sodium' => 'cifrado de datos', 'curl' => 'Google, correo y avisos', 'zip' => 'copia completa en ZIP', 'gd' => 'fotos de perfil', 'json' => 'datos') as $ext => $why) {
    $req = in_array($ext, array('pdo_mysql', 'mbstring', 'fileinfo', 'iconv', 'sodium', 'json'), true);
    add($S, extension_loaded($ext) ? 'ok' : ($req ? 'bad' : 'warn'), 'Extensión ' . $ext . (extension_loaded($ext) ? '' : ' — falta'), extension_loaded($ext) ? '' : 'Se usa para ' . $why . '.' . ($req ? ' Obligatoria.' : ' Opcional, pero recomendable.'));
}
add($S, 'info', 'Servidor: ' . (isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '?') . ' · PHP como ' . PHP_SAPI, PHP_SAPI === 'fpm-fcgi' ? 'PHP-FPM: el motor de avisos interno funciona.' : 'No es PHP-FPM: para avisos a la hora exacta usa cron-job.org (ver guía).');
add($S, 'info', 'Límites: subida ' . ini_get('upload_max_filesize') . ' · POST ' . ini_get('post_max_size') . ' · memoria ' . ini_get('memory_limit') . ' · tiempo ' . ini_get('max_execution_time') . ' s');
$dis = trim((string)ini_get('disable_functions'));
if ($dis !== '') add($S, preg_match('~\b(fsockopen|stream_socket_client|curl_exec)\b~', $dis) ? 'warn' : 'info', 'Funciones desactivadas por el hosting', $dis);
if (ini_get('open_basedir')) add($S, 'info', 'open_basedir activo', ini_get('open_basedir') . ' — «Proteger ahora» solo podrá usar carpetas dentro de estas rutas.');
add($S, 'info', 'Hora del servidor (UTC): ' . gmdate('d/m/Y H:i:s'));

// 2. Archivos
$S = '2. Archivos de Borealis';
$cfg = null; $storage = null;
if ($base === null) {
    add($S, 'warn', 'Borealis aún no está en esta carpeta', 'No se encontró app/bootstrap.php aquí ni en la carpeta de arriba (' . $dir . '). Las pruebas de PHP y base de datos sí sirven.');
} else {
    add($S, 'ok', 'Carpeta de Borealis: ' . $base);
    $verFile = $base . '/app/bootstrap.php';
    if (preg_match("~BOREALIS_VERSION'?\s*,\s*'([^']+)'~", (string)@file_get_contents($verFile), $m)) add($S, 'info', 'Versión subida: ' . $m[1]);
    if ($split) add($S, 'ok', 'Archivos públicos en ' . $dir . ' y el resto en una carpeta aparte (más seguro)');
    $pub = $split ? $dir : $base . '/public';
    foreach (array('index.php' => $pub . '/index.php', 'assets' => $pub . '/assets', 'app' => $base . '/app', 'database/migrations' => $base . '/database/migrations') as $p => $full) if (!file_exists($full)) add($S, 'bad', 'Falta ' . $p, 'La subida por FTP quedó incompleta: vuelve a subir el contenido del ZIP.');
    $cf = $base . '/config/config.php';
    if (!is_file($cf)) {
        add($S, 'bad', 'Falta config/config.php', 'Cópialo desde el servidor anterior (o desde la «copia completa» ZIP) y cambia en él la base de datos y la URL. Sin él, Borealis muestra el instalador.');
    } else {
        if (function_exists('opcache_invalidate')) @opcache_invalidate($cf, true);
        try { $cfg = include $cf; } catch (Exception $e) { $cfg = null; } catch (Error $e) { $cfg = null; add($S, 'bad', 'config/config.php tiene un error de sintaxis', $e->getMessage()); }
        if (is_array($cfg)) add($S, 'ok', 'config/config.php se lee bien');
        elseif ($cfg !== null) add($S, 'bad', 'config/config.php no devuelve la configuración', 'Debe empezar con <?php y contener return [ ... ];');
    }
    // storage (same rule as Borealis: config/storage.php › storage/)
    $storage = $base . '/storage'; $sp = $base . '/config/storage.php';
    if (is_file($sp)) {
        $c = @include $sp; $p = is_array($c) && isset($c['path']) ? trim((string)$c['path']) : '';
        if ($p !== '') {
            $abs = (sw($p, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $p)) ? $p : $base . '/' . $p;
            if (is_dir($abs)) { $storage = rtrim($abs, '/'); add($S, 'info', 'config/storage.php usa la carpeta de datos ' . $p); }
            else add($S, 'bad', 'config/storage.php apunta a ' . $p . ', que no existe aquí', 'Viene del servidor anterior: bórralo por FTP (Borealis usará storage/) y luego pulsa «Proteger ahora» en el servidor nuevo.');
        }
    }
    foreach (array('app.key' => 'la llave de cifrado (sin la del servidor anterior, Google y el correo guardados no se pueden leer)', 'vapid.json' => 'las llaves de los avisos al móvil', 'mail.json' => 'el correo configurado desde Administración', 'cron.json' => 'la clave de la tarea programada (cron-job.org)') as $f => $why) {
        $has = is_file($storage . '/' . $f);
        $inCfg = $f === 'app.key' && is_array($cfg) && !empty($cfg['app']['key']);
        add($S, $has || $inCfg ? 'ok' : ($f === 'app.key' ? 'warn' : 'info'), 'storage/' . $f . ($has ? ' copiado' : ($inCfg ? ': la llave está en config.php' : ' no está')), $has || $inCfg ? '' : 'Contiene: ' . $why . '. Cópialo del servidor anterior si existía allá.');
    }
    $n = 0; if (is_dir($storage . '/uploads')) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storage . '/uploads', FilesystemIterator::SKIP_DOTS)) as $f) if ($f->isFile()) $n++;
    add($S, 'info', 'Archivos adjuntos en storage/uploads: ' . $n, $n === 0 ? 'Si en el servidor anterior había adjuntos o fotos, copia también storage/uploads.' : '');
}

// 3. Permisos
$S = '3. Permisos de escritura';
if ($base !== null) {
    foreach (array('config' => $base . '/config', 'storage' => $storage, 'storage/uploads' => $storage . '/uploads', 'storage/backups' => $storage . '/backups', 'storage/logs' => $storage . '/logs', 'carpeta de Borealis (para «Proteger ahora»)' => $base) as $lbl => $d) {
        if (!is_dir($d)) { add($S, $lbl === 'storage/logs' ? 'warn' : 'bad', $lbl . ' no existe', 'Créala con FileZilla (clic derecho › Crear directorio).'); continue; }
        $t = $d . '/.diag-' . bin2hex(function_exists('random_bytes') ? random_bytes(4) : uniqid());
        $ok = @file_put_contents($t, 'x') !== false; @unlink($t);
        add($S, $ok ? 'ok' : ($lbl === 'config' || sw($lbl, 'carpeta') ? 'warn' : 'bad'), $lbl . ($ok ? ' se puede escribir' : ' NO se puede escribir'), $ok ? '' : 'En FileZilla: clic derecho › Permisos de archivo › 775 (o 777 si no basta), marcando «aplicar a subdirectorios».');
    }
} else add($S, 'info', 'Se revisa cuando Borealis esté subido.');

// 4. Base de datos
$S = '4. Base de datos';
$dbc = (is_array($cfg) && isset($cfg['db']) && is_array($cfg['db'])) ? $cfg['db'] : array();
$form = array(
    'host' => isset($_POST['db_host']) ? trim($_POST['db_host']) : (isset($dbc['host']) ? $dbc['host'] : 'localhost'),
    'port' => isset($_POST['db_port']) ? (int)$_POST['db_port'] : (isset($dbc['port']) ? (int)$dbc['port'] : 3306),
    'database' => isset($_POST['db_name']) ? trim($_POST['db_name']) : (isset($dbc['database']) ? $dbc['database'] : ''),
    'username' => isset($_POST['db_user']) ? trim($_POST['db_user']) : (isset($dbc['username']) ? $dbc['username'] : ''),
);
$pass = (isset($_POST['db_pass']) && $_POST['db_pass'] !== '') ? (string)$_POST['db_pass'] : (isset($dbc['password']) ? (string)$dbc['password'] : '');
$usingCfgPass = !(isset($_POST['db_pass']) && $_POST['db_pass'] !== '') && isset($dbc['password']);
$explain = array(
    1045 => 'Usuario o contraseña incorrectos PARA ESTE HOST. Si la contraseña es correcta, el usuario puede existir solo como \'usuario\'@\'localhost\': prueba host «localhost» en vez de 127.0.0.1 (o al revés).',
    1044 => 'Usuario y contraseña correctos, pero esa base no existe o el usuario no tiene permiso sobre ella (revisa el nombre abajo, en «bases que ve este usuario»).',
    1049 => 'La base de datos no existe con ese nombre (revisa mayúsculas y guiones bajos).',
    1130 => 'El servidor MySQL no acepta conexiones de este equipo para ese usuario.',
    2002 => 'No hay un servidor MySQL en ese host/puerto (o el socket no existe).',
    2005 => 'No se encuentra ese nombre de host.',
    2006 => 'El servidor MySQL cortó la conexión.',
    2054 => 'Método de autenticación que este PHP no soporta (caching_sha2_password). El soporte debe usar mysql_native_password para el usuario.',
    1251 => 'Método de autenticación que este PHP no soporta.',
);
$attempts = array(); $pdo = null; $winner = null;
$tryDb = $_SERVER['REQUEST_METHOD'] === 'POST' || (!empty($dbc) && $form['username'] !== '');
if ($tryDb && !extension_loaded('pdo_mysql')) { add($S, 'bad', 'No se puede probar: falta la extensión pdo_mysql'); $tryDb = false; }
if ($tryDb) {
    $hosts = array(array($form['host'], $form['port'], null));
    foreach (array('localhost', '127.0.0.1') as $alt) if (strcasecmp($alt, $form['host']) !== 0) $hosts[] = array($alt, $form['port'], null);
    $sockets = array_unique(array_filter(array(ini_get('pdo_mysql.default_socket'), '/run/mysqld/mysqld.sock', '/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock', '/var/lib/mysql/mysql.sock')));
    $seen = array(); foreach ($sockets as $s) { $rp = @realpath($s); if ($rp && @file_exists($rp) && !isset($seen[$rp])) { $seen[$rp] = 1; $hosts[] = array('localhost', $form['port'], $s); } }
    foreach ($hosts as $hp) {
        list($hh, $pp, $sock) = $hp;
        $dsn = $sock ? 'mysql:unix_socket=' . $sock . ';dbname=' . $form['database'] . ';charset=utf8mb4'
                     : 'mysql:host=' . $hh . ';port=' . $pp . ';dbname=' . $form['database'] . ';charset=utf8mb4';
        $label = $sock ? 'socket ' . $sock : $hh . ':' . $pp;
        try {
            $p = new PDO($dsn, $form['username'], $pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5));
            $attempts[] = array('ok', $label, 'Conecta');
            if (!$pdo) { $pdo = $p; $winner = $hp; }
        } catch (PDOException $e) {
            $code = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : (int)$e->getCode();
            if (!$code && preg_match('~\[(\d{4})\]~', $e->getMessage(), $m)) $code = (int)$m[1];
            $msg = preg_replace('~\(using password: \w+\)~', '', $e->getMessage());
            $attempts[] = array('bad', $label, (isset($explain[$code]) ? $explain[$code] . ' ' : '') . '[' . $code . '] ' . $msg);
        }
    }
    $who = 'usuario «' . $form['username'] . '», base «' . $form['database'] . '»' . ($usingCfgPass ? ', contraseña de config.php' : '');
    if ($pdo) {
        $first = $attempts[0][0] === 'ok';
        add($S, $first ? 'ok' : 'warn', 'Conexión correcta (' . $who . ') con ' . ($winner[2] ? 'socket' : 'host «' . $winner[0] . '»'),
            $first ? '' : 'Con el host indicado NO conecta, pero sí con otro: en config/config.php pon \'host\' => \'' . $winner[0] . '\'' . ($winner[2] ? ' y agrega \'unix_socket\' (pide ayuda)' : '') . '.');
        $v = $pdo->query('SELECT VERSION()')->fetchColumn();
        add($S, 'info', 'Servidor de base de datos: ' . $v);
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        if (!$tables) add($S, 'warn', 'La base está vacía', 'Falta importar la copia SQL del servidor anterior (phpMyAdmin › Importar). Ver guía, paso 3.');
        elseif (!in_array('schema_migrations', $tables, true)) add($S, 'warn', 'La base tiene ' . count($tables) . ' tablas, pero no son de Borealis', 'Puede ser de otro sistema: importa Borealis en una base vacía o confirma con el soporte.');
        else {
            $last = $pdo->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn();
            $users = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            add($S, 'ok', 'Borealis importado: ' . count($tables) . ' tablas, ' . $users . ' personas, última migración ' . $last);
        }
        try { $g = $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN); $all = implode(' ', $g);
            $can = preg_match('~ALL PRIVILEGES|CREATE.*ALTER|ALTER.*CREATE~i', $all);
            add($S, $can ? 'ok' : 'warn', $can ? 'El usuario puede crear y modificar tablas (necesario para actualizar)' : 'Revisa los permisos del usuario', $can ? '' : implode(' · ', $g));
        } catch (Exception $e) {}
        $pk = (int)$pdo->query('SELECT @@max_allowed_packet')->fetchColumn();
        add($S, $pk >= 16 * 1048576 ? 'info' : 'warn', 'max_allowed_packet: ' . round($pk / 1048576) . ' MB', $pk >= 16 * 1048576 ? '' : 'Si la importación falla con «packet too big», importa la copia sin adjuntos o pide subir este valor.');
    } else {
        foreach ($hosts as $hp) { // ¿entra sin elegir base? muestra qué bases ve
            try {
                $dsn = $hp[2] ? 'mysql:unix_socket=' . $hp[2] : 'mysql:host=' . $hp[0] . ';port=' . $hp[1];
                $p = new PDO($dsn, $form['username'], $pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5));
                $dbs = array_values(array_diff($p->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN), array('information_schema', 'performance_schema')));
                add($S, 'warn', 'El usuario y la contraseña SÍ funcionan (host «' . ($hp[2] ? 'localhost' : $hp[0]) . '»), pero no con la base «' . $form['database'] . '»', 'Bases que ve este usuario: ' . ($dbs ? implode(', ', $dbs) : '(ninguna: pide al soporte que le dé permiso sobre la base)') . '.');
                break;
            } catch (Exception $e) {}
        }
        add($S, 'bad', 'No conecta con ningún host (' . $who . ')', 'Mira el detalle de cada intento abajo. Si todos dicen 1045, la contraseña o el usuario no son los correctos: pide al soporte de la U. que la confirme o la restablezca.');
    }
} elseif (!$R || !isset($R[$S])) add($S, 'info', 'Completa el formulario de abajo y pulsa «Probar conexión».');

// 5. Seguridad y URLs (solo si Borealis está subido)
$S = '5. Seguridad y direcciones';
$rec = null;
if ($base !== null) {
    $probes = 0;
    // storage/ descargable
    $probeFile = $storage . '/diag-prueba-' . substr(md5(uniqid('', true)), 0, 10) . '.txt';
    if (@file_put_contents($probeFile, 'BOREALIS-DIAG') !== false) {
        $u = web_url($probeFile);
        if ($u === null) add($S, 'ok', 'La carpeta de datos está fuera de la web');
        else { $r = probe($u); $probes += $r ? 1 : 0;
            if ($r === null) add($S, 'warn', 'No pude comprobar si storage/ se puede descargar', 'El servidor no puede llamarse a sí mismo. Pruébalo tú: abre ' . web_url($storage . '/.htaccess') . ' — debe decir 403 o 404.');
            elseif ($r[0] === 200 && strpos($r[1], 'BOREALIS-DIAG') !== false) add($S, 'bad', 'storage/ SE PUEDE DESCARGAR desde internet', 'Este servidor (Nginx) ignora los .htaccess. Al terminar el traslado entra como administración y pulsa Administración › Datos › «Proteger ahora».');
            else add($S, 'ok', 'storage/ no se puede descargar (respuesta ' . $r[0] . ')');
        }
        @unlink($probeFile);
    }
    // config.php ejecutado como PHP (si no, se ve la contraseña)
    if (is_file($base . '/config/config.php')) { $cu0 = web_url($base . '/config/config.php'); $r = $cu0 ? probe($cu0) : null;
        if ($r && $r[0] === 200 && (strpos($r[1], "'password'") !== false || strpos($r[1], '<?php') !== false)) add($S, 'bad', '¡config/config.php se ve como texto desde internet!', 'Este servidor no ejecuta PHP en esa carpeta. Bórralo YA por FTP y avisa al soporte de la U.');
        elseif ($r) add($S, 'ok', 'config/config.php no muestra su contenido (respuesta ' . $r[0] . ')'); }
    // otras carpetas visibles
    $mig = glob($base . '/database/migrations/*.sql'); $mu = $mig ? web_url($mig[0]) : null;
    $r = $mu ? probe($mu) : null;
    if ($r && $r[0] === 200) add($S, 'warn', 'El código (database/, app/) se puede leer desde internet', $split ? 'Revisa que la carpeta del núcleo tenga su nombre secreto.' : 'No contiene datos privados, pero el paquete «uchile» lo evita: deja en la web solo los archivos públicos.');
    elseif ($mu && $r) add($S, 'ok', 'El código de Borealis no se puede leer desde internet (respuesta ' . $r[0] . ')');
    // dónde responde Borealis
    $candidates = array(
        array('url' => $origin . $baseWeb, 'idx' => $origin . $baseWeb . '/index.php', 'pretty' => $origin . $baseWeb . '/login'),
        array('url' => $origin . $baseWeb . '/public', 'idx' => $origin . $baseWeb . '/public/index.php', 'pretty' => $origin . $baseWeb . '/public/login'),
    );
    if ($split) $candidates = array($candidates[0]);
    foreach ($candidates as $c) {
        $r = probe($c['idx'] . '?r=/login'); if (!$r) continue; $probes++;
        $isB = $r[0] < 400 && (strpos($r[1], 'csrf-token') !== false || stripos($r[1], 'Borealis</title>') !== false);
        if (!$isB && $r[0] >= 500) add($S, 'bad', $c['idx'] . ' da error ' . $r[0], 'Revisa los pasos 2 y 4 de la guía (config.php, base de datos, permisos).');
        if (!$isB) continue;
        $p = probe($c['pretty']); $pretty = $p && (($p[0] >= 300 && $p[0] < 400) || ($p[0] < 300 && strpos($p[1], 'csrf-token') !== false));
        $rec = array('url' => $c['url'], 'base' => parse_url($c['url'], PHP_URL_PATH) ?: '', 'pretty' => $pretty);
        add($S, 'ok', 'Borealis responde en ' . $c['idx'], $pretty ? 'Las URLs amigables funcionan.' : 'Las URLs amigables NO funcionan aquí (Nginx sin reescritura): usa \'pretty_urls\' => false.');
        break;
    }
    if (!$rec && $probes) add($S, 'warn', 'Borealis todavía no responde', 'Sube los archivos, config/config.php y storage/, y recarga este diagnóstico. Si no conecta la base de datos, Borealis tampoco responde.');
    if (is_array($cfg) && $rec) {
        $cu = isset($cfg['app']['url']) ? rtrim($cfg['app']['url'], '/') : ''; $cb = isset($cfg['app']['base_path']) ? rtrim($cfg['app']['base_path'], '/') : '';
        $cp = isset($cfg['app']['pretty_urls']) ? (bool)$cfg['app']['pretty_urls'] : true;
        $same = $cu === $rec['url'] && $cb === $rec['base'] && $cp === $rec['pretty'];
        add($S, $same ? 'ok' : 'bad', $same ? 'La URL de config.php es la correcta' : 'La URL de config.php no corresponde a este servidor', 'Ahora: url «' . $cu . '», base_path «' . $cb . '», pretty_urls ' . ($cp ? 'true' : 'false') . '.');
    }
}

// 6. Salida a internet
$S = '6. Conexión hacia afuera';
foreach (array(array('smtp.gmail.com', 587, 'correo por Gmail (STARTTLS)'), array('smtp.gmail.com', 465, 'correo por Gmail (SSL)'), array('oauth2.googleapis.com', 443, 'Google Calendar'), array('fcm.googleapis.com', 443, 'avisos al móvil Android/Chrome'), array('web.push.apple.com', 443, 'avisos al iPhone')) as $t) {
    $e1 = 0; $e2 = '';
    $fp = function_exists('fsockopen') ? @fsockopen(($t[1] === 465 || $t[1] === 443 ? 'ssl://' : '') . $t[0], $t[1], $e1, $e2, 5) : false;
    if ($fp) fclose($fp);
    add($S, $fp ? 'ok' : 'warn', $t[0] . ':' . $t[1] . ($fp ? ' accesible' : ' bloqueado'), $fp ? $t[2] : 'Sin esto no funciona: ' . $t[2] . '. ' . ($e2 ? '(' . $e2 . ') ' : '') . 'La red de la U. puede bloquearlo: pregunta al soporte' . ($t[0] === 'smtp.gmail.com' ? ' o usa el SMTP institucional.' : '.'));
}

// ---------- resumen ----------
$count = array('ok' => 0, 'warn' => 0, 'bad' => 0, 'info' => 0);
foreach ($R as $items) foreach ($items as $i) $count[$i[0]]++;
$global = $count['bad'] ? 'bad' : ($count['warn'] ? 'warn' : 'ok');
$titles = array('ok' => 'Todo listo para usar Borealis aquí', 'warn' => 'Funciona, con avisos por revisar', 'bad' => 'Hay problemas que impiden el traslado');
$icon = array('ok' => '✔', 'warn' => '!', 'bad' => '✖', 'info' => 'i');
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>Diagnóstico de Borealis</title>
<style>
:root{--bg:#f6f7f9;--card:#fff;--tx:#1d2430;--mut:#5d6878;--ok:#17803d;--warn:#a15c00;--bad:#c0262d;--info:#3b5bdb;--bd:#dde2e8}
@media (prefers-color-scheme:dark){:root{--bg:#12151a;--card:#1b2028;--tx:#e6e9ee;--mut:#9aa4b2;--ok:#4cc97a;--warn:#f0a63a;--bad:#ff6b70;--info:#7d9bff;--bd:#2c333d}}
body{margin:0;background:var(--bg);color:var(--tx);font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
main{max-width:880px;margin:0 auto;padding:20px 16px 60px}h1{font-size:22px;margin:0 0 4px}h2{font-size:16px;margin:22px 0 8px}
.card{background:var(--card);border:1px solid var(--bd);border-radius:10px;padding:14px 16px;margin:10px 0}
.top{border-left:6px solid var(--g)}.top.ok{--g:var(--ok)}.top.warn{--g:var(--warn)}.top.bad{--g:var(--bad)}
ul{list-style:none;margin:0;padding:0}li{display:flex;gap:10px;padding:7px 0;border-top:1px solid var(--bd)}li:first-child{border-top:0}
.i{flex:0 0 22px;height:22px;border-radius:50%;color:#fff;font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center;margin-top:1px}
.ok .i,.i.ok{background:var(--ok)}.i.warn{background:var(--warn)}.i.bad{background:var(--bad)}.i.info{background:var(--info)}
.d{color:var(--mut);font-size:13.5px;word-break:break-word}.mut{color:var(--mut)}code,pre{font:13px ui-monospace,Menlo,Consolas,monospace}
pre{background:var(--bg);border:1px solid var(--bd);border-radius:8px;padding:10px;overflow:auto;white-space:pre-wrap}
form.db{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px}label{font-size:13px;color:var(--mut);display:block}
input{width:100%;box-sizing:border-box;padding:8px;border:1px solid var(--bd);border-radius:6px;background:var(--card);color:var(--tx);font:inherit}
button{padding:9px 14px;border-radius:6px;border:0;background:var(--info);color:#fff;font:inherit;font-weight:600;cursor:pointer}.del{background:var(--bad)}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between}
</style></head><body><main>
<div class="row"><div><h1>Diagnóstico de Borealis</h1><div class="mut"><?= h($origin . $scriptDir) ?>/ · <?= h(date('d/m/Y H:i')) ?> · se desactiva en <?= (int)ceil($restante / 60) ?> min</div></div>
<form method="post" onsubmit="return confirm('¿Borrar el diagnóstico del servidor?')"><button class="del" name="borrar" value="1">Borrar este archivo</button></form></div>

<div class="card top <?= $global ?>"><strong style="font-size:17px"><?= h($titles[$global]) ?></strong>
<div class="mut"><?= $count['ok'] ?> correctos · <?= $count['warn'] ?> avisos · <?= $count['bad'] ?> problemas</div></div>

<?php foreach ($R as $sec => $items): ?>
<h2><?= h($sec) ?></h2><div class="card"><ul>
<?php foreach ($items as $it): ?><li><span class="i <?= $it[0] ?>"><?= $icon[$it[0]] ?></span><div><?= h($it[1]) ?><?php if ($it[2] !== ''): ?><div class="d"><?= h($it[2]) ?></div><?php endif; ?></div></li><?php endforeach; ?>
<?php if ($sec === '4. Base de datos' && $attempts): ?><li><span class="i info">i</span><div>Intentos:<div class="d"><?php foreach ($attempts as $a): ?><div><strong style="color:var(--<?= $a[0] ?>)"><?= $a[0] === 'ok' ? '✔' : '✖' ?> <?= h($a[1]) ?></strong> — <?= h($a[2]) ?></div><?php endforeach; ?></div></div></li><?php endif; ?>
</ul>
<?php if ($sec === '4. Base de datos'): ?>
<form class="db" method="post" autocomplete="off" style="margin-top:12px">
<div><label>Host</label><input name="db_host" value="<?= h($form['host']) ?>"></div>
<div><label>Puerto</label><input name="db_port" value="<?= (int)$form['port'] ?>" inputmode="numeric"></div>
<div><label>Base de datos</label><input name="db_name" value="<?= h($form['database']) ?>"></div>
<div><label>Usuario</label><input name="db_user" value="<?= h($form['username']) ?>"></div>
<div><label>Contraseña<?= isset($dbc['password']) ? ' (vacío = la de config.php)' : '' ?></label><input type="password" name="db_pass" autocomplete="new-password"></div>
<div style="align-self:end"><button>Probar conexión</button></div>
</form><p class="d">La contraseña solo se usa para esta prueba: no se guarda ni se muestra. Se prueba el host indicado y, además, localhost, 127.0.0.1 y el socket local.</p>
<?php endif; ?></div>
<?php endforeach; ?>

<?php if ($rec): ?>
<h2>Valores para config/config.php en este servidor</h2><div class="card">
<pre>'app' => [
    'url' => '<?= h($rec['url']) ?>',
    'base_path' => '<?= h($rec['base']) ?>',
    'pretty_urls' => <?= $rec['pretty'] ? 'true' : 'false' ?>,
    ...
],
'db' => [
    'host' => '<?= h($winner ? $winner[0] : $form['host']) ?>',
    'port' => <?= (int)($winner ? $winner[1] : $form['port']) ?>,
    'database' => '<?= h($form['database']) ?>',
    'username' => '<?= h($form['username']) ?>',
    'password' => '(la contraseña)',
    ...
],</pre>
<p class="d">Google Cloud › Credenciales › tu cliente OAuth › «URI de redireccionamiento autorizados»: agrega
<code><?= h($rec['url'] . ($rec['pretty'] ? '/google/callback' : '/index.php?r=%2Fgoogle%2Fcallback')) ?></code></p>
<p class="d">cron-job.org: cambia la dirección a <code><?= h($rec['url'] . ($rec['pretty'] ? '/cron/run?token=' : '/index.php?r=%2Fcron%2Frun&amp;token=')) ?>…</code> (la clave está en Administración › Notificaciones).</p></div>
<?php endif; ?>
<p class="d">Al terminar, pulsa «Borrar este archivo» o bórralo con FileZilla (diagnostico.php y .diagnostico-inicio).</p>
</main></body></html>
