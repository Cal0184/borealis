<?php
declare(strict_types=1);

namespace Borealis\Security;

/**
 * Encryption of stored secrets (1.6: Google tokens). XSalsa20-Poly1305 (libsodium secretbox).
 *
 * The key comes from config 'app.key' (base64, 32 bytes) or, when absent, from storage/app.key,
 * created on first use with mode 0600. storage/ is not served (.htaccess «Require all denied»; on Nginx
 * 1.16.5 moves the folder, see Core/StoragePath).
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
        $file = storage_path('app.key');
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

    /** Keyed hash with the installation's secret key (1.9): signatures (password links) that nobody outside can forge. */
    public static function hmac(string $data): string
    {
        return hash_hmac('sha256', $data, self::key());
    }

    /**
     * 1.16.5 · Keyed hash for ids that must never change (events exported to Google Calendar). After the key
     * is renewed it keeps using the previous key, kept in storage/ids.key, so that no event is duplicated.
     */
    public static function stableHmac(string $data): string
    {
        $f = storage_path('ids.key');
        if (is_file($f)) {
            $k = base64_decode(trim((string)file_get_contents($f)), true);
            if ($k !== false && strlen($k) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) return hash_hmac('sha256', $data, $k);
        }
        return self::hmac($data);
    }

    /** 1.16.5 · Whether the key can be renewed from Administración (not when it is written in config/config.php). */
    public static function rotatable(): bool
    {
        return (string)config('app.key', '') === '' && function_exists('sodium_crypto_secretbox_keygen');
    }

    /**
     * 1.16.5 · Renews storage/app.key (for example after the folder could be downloaded from the web).
     * Everything encrypted with the old key is encrypted again with the new one: Google tokens, Discord and
     * GitHub secrets (database), the mail password, the /cron/run token and the push key (files). Password
     * links already sent stop working. A copy of the database is made first by the caller.
     *
     * @return array{db:int, files:int}
     */
    public static function rotate(): array
    {
        if (!self::rotatable()) throw new \RuntimeException('La llave está escrita en config/config.php (app.key): cámbiala allí.');
        $old = self::key();
        $new = sodium_crypto_secretbox_keygen();
        $re = static function (?string $stored) use ($old, $new): ?string {
            if ($stored === null || $stored === '' || !str_starts_with($stored, 'v1:')) return $stored;
            self::$key = $old;
            $plain = self::decrypt($stored);
            if ($plain === null) return $stored; // unreadable already: left as it was
            self::$key = $new;
            return self::encrypt($plain);
        };
        $db = \Borealis\Core\Database::get();
        $updates = [];
        foreach ([['google_accounts', ['access_token', 'refresh_token']], ['project_integrations', ['secret']]] as [$table, $cols]) {
            try { $rows = $db->all('SELECT id, ' . implode(', ', $cols) . " FROM `$table`"); } catch (\Throwable) { continue; }
            foreach ($rows as $row) {
                $set = [];
                foreach ($cols as $c) { $v = $re($row[$c]); if ($v !== $row[$c]) $set[$c] = $v; }
                if ($set) $updates[] = [$table, $set, (int)$row['id']];
            }
        }
        $files = [];
        foreach (['mail.json' => ['password'], 'cron.json' => ['token'], 'vapid.json' => ['private']] as $name => $fields) {
            $f = storage_path($name);
            $j = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
            if (!is_array($j)) continue;
            $changed = false;
            foreach ($fields as $k) if (isset($j[$k]) && is_string($j[$k])) { $v = $re($j[$k]); if ($v !== $j[$k]) { $j[$k] = $v; $changed = true; } }
            if ($changed) $files[$f] = json_encode($j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        self::$key = $old;

        $write = static function (string $file, string $data): void {
            $tmp = $file . '.' . bin2hex(random_bytes(4));
            if (@file_put_contents($tmp, $data) === false) throw new \RuntimeException('No se pudo escribir ' . basename($file) . ': revisa los permisos de la carpeta de datos.');
            @chmod($tmp, 0600);
            if (!@rename($tmp, $file)) { @unlink($tmp); throw new \RuntimeException('No se pudo guardar ' . basename($file) . '.'); }
        };
        // the ids of exported events keep the old key (written once, before anything changes)
        if (!is_file(storage_path('ids.key'))) $write(storage_path('ids.key'), base64_encode($old));
        // the re-encrypted files are written first next to the originals, and only put in place with the new key
        $staged = [];
        try {
            foreach ($files as $f => $data) {
                $tmp = $f . '.' . bin2hex(random_bytes(4));
                if (@file_put_contents($tmp, $data) === false) throw new \RuntimeException('No se pudo escribir ' . basename($f) . ': revisa los permisos de la carpeta de datos.');
                @chmod($tmp, 0600);
                $staged[$tmp] = $f;
            }
        } catch (\Throwable $e) {
            foreach (array_keys($staged) as $t) @unlink($t);
            throw $e;
        }
        $pdo = $db->pdo;
        $pdo->beginTransaction();
        try {
            foreach ($updates as [$table, $set, $id]) $db->update($table, $set, 'id = ?', [$id]);
            $write(storage_path('app.key'), base64_encode($new));
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach (array_keys($staged) as $t) @unlink($t);
            throw $e;
        }
        self::$key = $new;
        foreach ($staged as $tmp => $f) if (!@rename($tmp, $f)) error_log('[Borealis] No se pudo reemplazar ' . $f . ' tras renovar la llave.');
        return ['db' => count($updates), 'files' => count($files)];
    }
}
