<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\StoragePath;

/**
 * 1.16.5 · Administración › Datos › «Proteger ahora»: moves the private data folder where the web cannot
 * reach it, without asking the hosting for anything.
 *
 * 1. Outside the web folder (one level above the site's document root), when PHP may write there. Safe on
 *    any server: no address leads to it.
 * 2. Otherwise, a folder with a secret 32-character name inside Borealis (storage-…). Still inside the web
 *    folder, but nobody can guess its address; it gets its own .htaccess and an empty index.html.
 *
 * The new place is written into config/storage.php. The old storage/ keeps only its .htaccess and index.html.
 */
final class StorageMover
{
    /** Document root of the site (realpath), or '' when unknown (console). */
    public static function webRoot(): string
    {
        $d = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
        $r = $d !== '' ? realpath($d) : false;
        return $r === false ? '' : rtrim(str_replace('\\', '/', $r), '/');
    }

    /** 'outside' (no address reaches it), 'secret' (inside the web, secret name) or 'web' (storage/ inside the web). */
    public static function placement(?string $path = null): string
    {
        $path = rtrim(str_replace('\\', '/', (string)(realpath($path ?? storage_path()) ?: ($path ?? storage_path()))), '/');
        $root = self::webRoot();
        $base = rtrim(str_replace('\\', '/', (string)(realpath(BASE_PATH) ?: BASE_PATH)), '/');
        $inside = static fn(string $p, string $dir): bool => $dir !== '' && ($p === $dir || str_starts_with($p, $dir . '/'));
        // with the document root known, what is outside it is outside; without it, assume Borealis is served whole
        $served = $root !== '' ? $inside($path, $root) : $inside($path, $base);
        if (!$served) return 'outside';
        return preg_match('~/storage-[a-f0-9]{32}$~', $path) ? 'secret' : 'web';
    }

    /** Where «Proteger ahora» would move the folder: ['path' => absolute, 'stored' => what goes in config/storage.php, 'kind' => 'outside'|'secret']. */
    public static function target(): array
    {
        $root = self::webRoot();
        if ($root !== '' && ($parent = dirname($root)) !== $root && self::writableDir($parent)) {
            $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', basename(BASE_PATH)) ?: 'borealis');
            $name = 'borealis-datos' . ($slug === 'borealis' ? '' : '-' . trim($slug, '-'));
            $p = $parent . '/' . $name;
            if (file_exists($p) && !self::emptyDir($p)) $p .= '-' . bin2hex(random_bytes(3));
            if (self::placement($p) === 'outside') return ['path' => $p, 'stored' => $p, 'kind' => 'outside'];
        }
        $name = 'storage-' . bin2hex(random_bytes(16));
        return ['path' => BASE_PATH . '/' . $name, 'stored' => $name, 'kind' => 'secret'];
    }

    /** @return array{path:string, kind:string, moved:int} */
    public static function protect(): array
    {
        $from = storage_path();
        if (self::placement($from) === 'outside') throw new \RuntimeException('La carpeta de datos ya está fuera de la carpeta web.');
        // 1.16.6: from one secret folder to another gains nothing (and renewed the key every time)
        if (self::placement($from) === 'secret' && self::target()['kind'] === 'secret') throw new \RuntimeException('La carpeta de datos ya tiene un nombre secreto y este servidor no deja guardarla fuera de la carpeta web.');
        $cfgDir = BASE_PATH . '/config';
        if (!is_writable($cfgDir)) throw new \RuntimeException('Borealis no puede escribir en config/: dale permiso de escritura por FTP (clic derecho › Permisos de archivo › 755) y vuelve a intentarlo.');
        $t = self::target();
        $to = $t['path'];
        if (!is_dir($to) && !@mkdir($to, 0750, true)) throw new \RuntimeException('No se pudo crear la carpeta ' . $to . '.');
        $probe = $to . '/.write-test';
        if (@file_put_contents($probe, 'ok') === false) throw new \RuntimeException('No se puede escribir en ' . $to . '.');
        @unlink($probe);

        $lock = @fopen($from . '/notify.lock', 'c');
        if ($lock) flock($lock, LOCK_EX);
        $moved = [];
        try {
            foreach (scandir($from) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || $entry === '.htaccess' || $entry === 'index.html' || $entry === '.gitkeep' || $entry === 'notify.lock') continue;
                self::moveEntry($from . '/' . $entry, $to . '/' . $entry);
                $moved[] = $entry;
            }
            @file_put_contents($to . '/.htaccess', "Require all denied\nDeny from all\n");
            @file_put_contents($to . '/index.html', '');
            foreach (['uploads', 'backups', 'logs'] as $d) if (!is_dir($to . '/' . $d)) @mkdir($to . '/' . $d, 0750, true);
            self::writeConfig($t['stored']);
        } catch (\Throwable $e) {
            foreach (array_reverse($moved) as $entry) { try { self::moveEntry($to . '/' . $entry, $from . '/' . $entry); } catch (\Throwable) {} }
            throw $e;
        } finally {
            if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
        }
        @unlink($from . '/notify.lock');
        if (!is_file($from . '/index.html')) @file_put_contents($from . '/index.html', '');
        StoragePath::set($to);
        return ['path' => $to, 'kind' => $t['kind'], 'moved' => count($moved)];
    }

    private static function moveEntry(string $src, string $dst): void
    {
        if (@rename($src, $dst)) return;
        // another disk: copy, then delete
        self::copy($src, $dst);
        self::remove($src);
    }

    private static function copy(string $src, string $dst): void
    {
        if (is_dir($src)) {
            if (!is_dir($dst) && !@mkdir($dst, 0750, true)) throw new \RuntimeException('No se pudo crear ' . $dst . '.');
            foreach (scandir($src) ?: [] as $e) if ($e !== '.' && $e !== '..') self::copy($src . '/' . $e, $dst . '/' . $e);
            return;
        }
        if (!@copy($src, $dst) || filesize($dst) !== filesize($src)) throw new \RuntimeException('No se pudo copiar ' . basename($src) . '.');
        @chmod($dst, fileperms($src) & 0777);
    }

    private static function remove(string $p): void
    {
        if (is_dir($p) && !is_link($p)) {
            foreach (scandir($p) ?: [] as $e) if ($e !== '.' && $e !== '..') self::remove($p . '/' . $e);
            @rmdir($p);
        } else {
            @unlink($p);
        }
    }

    private static function writeConfig(string $stored): void
    {
        $f = StoragePath::configFile();
        $php = "<?php\n// Borealis 1.16.5: carpeta de datos privados (llave, copias, archivos subidos).\n"
            . "// La escribe Administración › Datos › «Proteger ahora». No subas este archivo a otro servidor.\n"
            . "return ['path' => " . var_export($stored, true) . "];\n";
        $tmp = $f . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $php) === false || !@rename($tmp, $f)) { @unlink($tmp); throw new \RuntimeException('No se pudo escribir config/storage.php.'); }
        @chmod($f, 0640);
        if (function_exists('opcache_invalidate')) @opcache_invalidate($f, true);
    }

    private static function writableDir(string $d): bool
    {
        // open_basedir may forbid even looking: is_dir() then answers false without a warning
        return @is_dir($d) && @is_writable($d);
    }

    private static function emptyDir(string $d): bool
    {
        return is_dir($d) && count(array_diff(scandir($d) ?: [], ['.', '..'])) === 0;
    }
}
