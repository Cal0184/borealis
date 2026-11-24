<?php
declare(strict_types=1);

namespace Borealis\Security;

/**
 * Encryption of stored secrets (1.6: Google tokens). XSalsa20-Poly1305 (libsodium secretbox).
 *
 * The key comes from config 'app.key' (base64, 32 bytes) or, when absent, from storage/app.key,
 * created on first use with mode 0600. storage/ is not served (.htaccess «Require all denied»).
 * Losing the key only means people have to connect Google again.
 */
final class Secrets
{
    private static ?string $key = null;

    private static function key(): string
    {
        if (self::$key !== null) return self::$key;
        $cfg = (string)config('app.key', '');
        if ($cfg !== '') {
            $k = base64_decode(preg_replace('/^base64:/', '', $cfg), true);
            if ($k === false || strlen($k) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new \RuntimeException('app.key debe ser una clave de 32 bytes en base64.');
            return self::$key = $k;
        }
        $file = BASE_PATH . '/storage/app.key';
        if (!is_file($file)) {
            $k = sodium_crypto_secretbox_keygen();
            $tmp = $file . '.' . bin2hex(random_bytes(4));
            if (@file_put_contents($tmp, base64_encode($k)) === false) throw new \RuntimeException('No se pudo crear storage/app.key: revisa los permisos de storage/.');
            @chmod($tmp, 0600);
            if (!@rename($tmp, $file) && !is_file($file)) throw new \RuntimeException('No se pudo crear storage/app.key.');
            @unlink($tmp);
        }
        $k = base64_decode(trim((string)file_get_contents($file)), true);
        if ($k === false || strlen($k) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new \RuntimeException('storage/app.key está dañado.');
        return self::$key = $k;
    }

    public static function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') return null;
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    public static function decrypt(?string $stored): ?string
    {
        if ($stored === null || $stored === '' || !str_starts_with($stored, 'v1:')) return null;
        $raw = base64_decode(substr($stored, 3), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::key());
        return $plain === false ? null : $plain;
    }

    /** Keyed hash with the installation's secret key (1.9): stable ids that nobody outside can guess. */
    public static function hmac(string $data): string
    {
        return hash_hmac('sha256', $data, self::key());
    }
}
