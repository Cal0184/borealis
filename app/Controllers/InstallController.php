<?php
declare(strict_types=1);

namespace Borealis\Controllers;

use Borealis\Core\Auth;
use Borealis\Core\Config;
use Borealis\Core\Csrf;
use Borealis\Core\Database;
use Borealis\Core\Migrator;
use Borealis\Core\Request;
use Borealis\Core\Response;
use Borealis\Core\Session;
use Borealis\Core\View;
use Borealis\Services\Users;
use Borealis\Services\Workspaces;

/** Web installer. Only reachable while config/config.php does not exist. */
final class InstallController
{
    public function handle(): Response
    {
        $base = $this->detectBase();
        Config::set('app.base_path', $base);
        Config::set('app.pretty_urls', false);
        Session::start();
        $req = Request::capture();
        $path = $req->path;
        if (str_starts_with($path, '/assets/')) return Response::html('', 404);

        // 1.16.4: storage/ already holds data of an installation (key, copies, files) but config/config.php
        // is missing: most likely the files were moved and config.php was not uploaded yet. The installer
        // stays closed, so that nobody who arrives first can take the site over with another database.
        if ($leftovers = self::previousInstallation()) {
            return Response::html(View::render('layouts/auth', [
                'title' => 'Falta la configuración',
                'content' => '<h1>Falta config/config.php</h1><p class="sub">Esta carpeta ya contiene una instalación de Borealis (' . e(implode(', ', $leftovers)) . '), pero falta el archivo <code>config/config.php</code>.</p>'
                    . '<p>Sube por FTP el <code>config/config.php</code> de la instalación (está en la copia completa o en el servidor anterior) y recarga esta página.</p>'
                    . '<p class="muted small">Para instalar desde cero en esta misma carpeta, crea un archivo vacío llamado <code>storage/install.allow</code> y recarga; se borra solo al terminar.</p>',
            ]), 503);
        }

        $checks = $this->requirements();
        $values = [
            'db_host' => '127.0.0.1', 'db_port' => '3306', 'db_name' => 'borealis', 'db_user' => '', 'db_pass' => '',
            'app_url' => $this->detectUrl($base), 'base_path' => $base, 'timezone' => 'America/Santiago', 'pretty_urls' => '1',
            'workspace_name' => '', 'admin_name' => '', 'admin_email' => '',
        ];
        $errors = [];
        if ($req->method === 'POST') {
            try {
                Csrf::verify($req);
            } catch (\Throwable $e) {
                $errors['_'] = $e->getMessage();
            }
            $values = array_merge($values, array_map(fn($v) => is_string($v) ? trim($v) : $v, array_intersect_key($req->post, $values)));
            $values['pretty_urls'] = isset($req->post['pretty_urls']) ? '1' : '0';
            if (!$errors) $errors = $this->install($values, (string)($req->post['admin_password'] ?? ''), $checks);
            if (!$errors) {
                return Response::redirect(rtrim($values['base_path'], '/') . ($values['pretty_urls'] === '1' ? '/' : '/index.php'));
            }
        }
        return Response::html(View::render('layouts/auth', [
            'title' => 'Instalar Borealis',
            'wide' => true,
            'content' => View::render('pages/install', compact('checks', 'values', 'errors')),
        ]));
    }

    /** 1.16.4: what in storage/ shows that Borealis was already installed here (empty: nothing). */
    public static function previousInstallation(): array
    {
        $st = storage_path();
        if (is_file($st . '/install.allow')) return [];
        $found = [];
        if (is_file($st . '/app.key')) $found[] = 'storage/app.key';
        if (is_file($st . '/vapid.json')) $found[] = 'storage/vapid.json';
        if (glob($st . '/backups/*.sql')) $found[] = 'copias en storage/backups';
        if (is_dir($st . '/uploads')) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($st . '/uploads', \FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile() && $f->getFilename() !== '.gitkeep') { $found[] = 'archivos en storage/uploads'; break; }
            }
        }
        return $found;
    }

    private function detectBase(): string
    {
        $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        $script = rtrim($script, '/');
        $uri = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if ($script !== '' && !str_starts_with($uri, $script) && str_ends_with($script, '/public')) {
            $script = substr($script, 0, -7);
        }
        return $script;
    }

    private function detectUrl(string $base): string
    {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base;
    }

    public function requirements(): array
    {
        $writable = fn($d) => is_dir($d) && is_writable($d);
        return [
            ['PHP 8.2 o superior (actual ' . PHP_VERSION . ')', version_compare(PHP_VERSION, '8.2.0', '>=')],
            ['Extensión PDO MySQL', extension_loaded('pdo_mysql')],
            ['Extensión mbstring', extension_loaded('mbstring')],
            ['Extensión fileinfo', extension_loaded('fileinfo')],
            ['Extensión iconv', extension_loaded('iconv')],
            ['Carpeta config/ con permiso de escritura', $writable(BASE_PATH . '/config')],
            ['Carpeta storage/ con permiso de escritura', $writable(storage_path()) && $writable(storage_path('uploads'))],
        ];
    }

    /** @return array<string,string> errors (empty on success) */
    private function install(array $v, string $password, array $checks): array
    {
        $errors = [];
        foreach ($checks as [$label, $ok]) if (!$ok) $errors['_'] = "Requisito pendiente: $label.";
        foreach (['db_host', 'db_name', 'db_user', 'workspace_name', 'admin_name', 'admin_email', 'app_url'] as $k) {
            if (($v[$k] ?? '') === '') $errors[$k] = 'Este campo es obligatorio.';
        }
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $v['db_name'])) $errors['db_name'] = 'Usa solo letras, números y guion bajo.';
        if ($emailErr = \Borealis\Core\Validator::emailError((string)$v['admin_email'])) $errors['admin_email'] = $emailErr;
        if (mb_strlen($password) < 8) $errors['admin_password'] = 'Mínimo 8 caracteres.';
        if (!in_array($v['timezone'], \DateTimeZone::listIdentifiers(), true)) $errors['timezone'] = 'Zona horaria no válida.';
        if (!filter_var($v['app_url'], FILTER_VALIDATE_URL)) $errors['app_url'] = 'URL no válida.';
        if ($errors) return $errors;

        $db = ['driver' => 'mysql', 'host' => $v['db_host'], 'port' => (int)$v['db_port'], 'database' => $v['db_name'], 'username' => $v['db_user'], 'password' => $v['db_pass'], 'charset' => 'utf8mb4'];
        try {
            try {
                $pdo = Database::connect($db);
            } catch (\PDOException $e) {
                if (!str_contains($e->getMessage(), '1049')) throw $e; // unknown database: try to create it
                $server = new \PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], $db['port']), $db['username'], $db['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                $server->exec("CREATE DATABASE `{$db['database']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo = Database::connect($db);
            }
        } catch (\PDOException $e) {
            return ['_' => 'No fue posible conectar con la base de datos: ' . $e->getMessage()];
        }
        $existing = $pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
        if ($existing && (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
            return ['_' => 'Esa base de datos ya contiene una instalación de Borealis con usuarios. Para conservarla, restaura config/config.php y usa el actualizador; para empezar de cero, elige otra base de datos.'];
        }
        try {
            (new Migrator($pdo))->migrate();
            Database::setInstance(new Database($pdo));
            Config::set('app.timezone', $v['timezone']);
            $uid = Users::create($v['admin_name'], $v['admin_email'], $password);
            $pdo->prepare('UPDATE users SET is_instance_admin = 1 WHERE id = ?')->execute([$uid]);
            Workspaces::create($v['workspace_name'], $uid);
        } catch (\Throwable $e) {
            return ['_' => 'La instalación no pudo completarse: ' . $e->getMessage()];
        }

        $config = [
            'app' => ['name' => 'Borealis', 'url' => rtrim($v['app_url'], '/'), 'base_path' => rtrim($v['base_path'], '/'), 'pretty_urls' => $v['pretty_urls'] === '1',
                'debug' => false, 'timezone' => $v['timezone'], 'allow_registration' => false, 'allow_workspace_creation' => false],
            'db' => $db,
            'uploads' => ['max_bytes' => 10 * 1024 * 1024, 'allowed_extensions' => ['png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf', 'txt', 'md', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'zip', 'json']],
            'session' => ['name' => 'borealis_' . bin2hex(random_bytes(4)), 'idle_minutes' => 240],
        ];
        // 1.16.4: the encryption key goes in config.php (executed by PHP, never downloadable as text) instead
        // of storage/app.key, unless a key from an earlier installation is already there.
        if (!is_file(storage_path('app.key')) && function_exists('sodium_crypto_secretbox_keygen')) {
            $config['app']['key'] = 'base64:' . base64_encode(sodium_crypto_secretbox_keygen());
            Config::set('app.key', $config['app']['key']);
        }
        $php = "<?php\n// Generated by the Borealis installer on " . gmdate('c') . "\nreturn " . var_export($config, true) . ";\n";
        $target = Config::path();
        $tmp = $target . '.tmp';
        @unlink(storage_path('install.allow'));
        if (file_put_contents($tmp, $php, LOCK_EX) === false || !rename($tmp, $target)) {
            return ['_' => 'No se pudo escribir config/config.php. Revisa los permisos de la carpeta config/.'];
        }
        @chmod($target, 0640);

        // Sign the administrator in with the new session cookie name.
        Session::destroy();
        Config::set('session.name', $config['session']['name']);
        Config::set('app.base_path', $config['app']['base_path']);
        Session::start();
        Auth::login(Database::get()->one('SELECT * FROM users WHERE id = ?', [$uid]));
        return [];
    }
}
