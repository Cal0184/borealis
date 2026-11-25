<?php
declare(strict_types=1);

namespace Borealis\Services;

/**
 * 1.16.4 · Checks from the server itself that the private folders cannot be downloaded from the web.
 *
 * storage/ keeps the encryption key, the full SQL copies and the uploaded files. Only the
 * .htaccess files protect it when everything is uploaded to the web root (Apache and LiteSpeed
 * read them; Nginx ignores them). This class writes a small file with a random text into storage/
 * and asks the public address for it; it also asks for database/migrations/001_core.sql. If either
 * answers with its content, the installation is exposed and Administración shows a red alert.
 *
 * The result is kept in storage/exposure.json; the scheduled job refreshes it every 12 hours and
 * Administración › Datos on demand. Without network access to itself the result is «unknown».
 */
final class Exposure
{
    public const MAX_AGE = 43200;

    private static function file(): string
    {
        return BASE_PATH . '/storage/exposure.json';
    }

    /** ['status' => 'safe'|'exposed'|'unknown'|null, 'checked_at' => int, 'urls' => [...], 'detail' => string] */
    public static function cached(): array
    {
        $j = is_file(self::file()) ? json_decode((string)@file_get_contents(self::file()), true) : null;
        return is_array($j) ? $j + ['status' => null, 'checked_at' => 0, 'urls' => [], 'detail' => ''] : ['status' => null, 'checked_at' => 0, 'urls' => [], 'detail' => ''];
    }

    public static function stale(): bool
    {
        return time() - (int)(self::cached()['checked_at'] ?? 0) > self::MAX_AGE;
    }

    public static function maybeCheck(): array
    {
        return self::stale() ? self::check() : self::cached();
    }

    /** Public address of the installation (origin + base path), without index.php. */
    public static function baseUrl(): string
    {
        $u = parse_url((string)config('app.url', '')) ?: [];
        if (empty($u['host'])) return '';
        $origin = ($u['scheme'] ?? 'https') . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
        $base = rtrim((string)config('app.base_path', $u['path'] ?? ''), '/');
        return $origin . $base;
    }

    public static function check(): array
    {
        $base = self::baseUrl();
        $res = ['status' => 'unknown', 'checked_at' => time(), 'urls' => [], 'detail' => ''];
        if ($base === '' || !function_exists('curl_init')) {
            $res['detail'] = $base === '' ? 'Falta app.url en config/config.php.' : 'Falta la extensión curl de PHP.';
            return self::save($res);
        }
        $probeName = 'exposure-probe.txt';
        $probe = BASE_PATH . '/storage/' . $probeName;
        $secret = 'borealis-probe-' . bin2hex(random_bytes(8));
        if (@file_put_contents($probe, $secret) === false) {
            $res['detail'] = 'No se pudo escribir en storage/.';
            return self::save($res);
        }
        try {
            $targets = [
                $base . '/storage/' . $probeName => $secret,
                $base . '/database/migrations/001_core.sql' => 'CREATE TABLE',
            ];
            $reached = false;
            foreach ($targets as $url => $needle) {
                [$code, $body] = self::get($url);
                if ($code > 0) $reached = true;
                if ($code >= 200 && $code < 300 && str_contains($body, $needle)) $res['urls'][] = $url;
            }
            if ($res['urls']) {
                $res['status'] = 'exposed';
                $res['detail'] = 'Estas direcciones se pueden descargar desde internet: ' . implode(', ', $res['urls']);
            } elseif ($reached) {
                $res['status'] = 'safe';
            } else {
                $res['detail'] = 'El servidor no pudo llamarse a sí mismo para comprobarlo. Compruébalo a mano (ver abajo).';
            }
        } finally {
            @unlink($probe);
        }
        return self::save($res);
    }

    /** @return array{0:int,1:string} */
    private static function get(string $url): array
    {
        if ($fake = getenv('BOREALIS_EXPOSURE_FAKE')) {
            // tests: «exposed» answers every address with what it asks for, «safe» with 404
            return $fake === 'exposed' ? [200, (string)@file_get_contents(BASE_PATH . '/storage/exposure-probe.txt') . ' CREATE TABLE'] : [404, ''];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_RANGE => '0-4095',
            CURLOPT_USERAGENT => 'Borealis-exposure-check',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, is_string($body) ? $body : ''];
    }

    private static function save(array $r): array
    {
        @file_put_contents(self::file(), json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $r;
    }
}
