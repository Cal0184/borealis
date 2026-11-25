<?php
declare(strict_types=1);

namespace Borealis\Core;

/**
 * 1.16.5 · Where the private data folder (key, copies, uploaded files, mail settings) lives.
 *
 * By default it is storage/ inside Borealis. On hosting where that folder can be downloaded from the web
 * (Nginx ignores the .htaccess files) Administración › Datos › «Proteger ahora» moves it outside the web
 * folder or, when that is not possible, to a folder with a secret name, and writes the new place into
 * config/storage.php (a PHP file: requested from the web it shows nothing).
 *
 * Order: BOREALIS_STORAGE (environment, tests) › config/storage.php › storage/.
 * A relative path in config/storage.php is relative to the Borealis folder.
 */
final class StoragePath
{
    private static ?string $path = null;
    /** The place written in config/storage.php when that folder does not exist (shown in Administración). */
    private static ?string $missing = null;

    public static function configFile(): string
    {
        return BASE_PATH . '/config/storage.php';
    }

    public static function get(): string
    {
        return self::$path ??= self::resolve();
    }

    /** Used right after moving the folder, so that the rest of the same request writes to the new place. */
    public static function set(string $path): void
    {
        self::$path = rtrim($path, '/\\');
        self::$missing = null;
    }

    public static function missing(): ?string
    {
        self::get();
        return self::$missing;
    }

    public static function isDefault(): bool
    {
        return self::get() === BASE_PATH . '/storage';
    }

    /** Absolute path for what config/storage.php says (relative paths are inside the Borealis folder). */
    public static function absolute(string $p): string
    {
        $p = rtrim($p, '/\\');
        $abs = str_starts_with($p, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $p) === 1;
        return $abs ? $p : BASE_PATH . '/' . $p;
    }

    private static function resolve(): string
    {
        $env = getenv('BOREALIS_STORAGE');
        if (is_string($env) && $env !== '') return self::absolute($env);
        $f = self::configFile();
        if (is_file($f)) {
            $c = (static function (string $file) { return require $file; })($f);
            $p = is_array($c) ? trim((string)($c['path'] ?? '')) : '';
            if ($p !== '') {
                $abs = self::absolute($p);
                if (is_dir($abs)) return $abs;
                self::$missing = $p;
                error_log('[Borealis] config/storage.php indica ' . $p . ', que no existe: se usa storage/.');
            }
        }
        return BASE_PATH . '/storage';
    }
}
