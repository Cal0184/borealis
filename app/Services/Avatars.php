<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Auth;
use Borealis\Core\Database;
use Borealis\Core\HttpException;
use Borealis\Security\Policy;

/**
 * Profile photos (1.7). The browser crops the picture to a square; the server checks it is a real
 * image, re-encodes it (which drops any metadata or hidden content) and keeps two small WebP sizes
 * in storage/uploads/avatars, outside the web root. Each photo has a random token that is part of
 * its address, so a new photo gets a new address and browsers can keep the old one cached.
 */
final class Avatars
{
    /** Sizes kept, in pixels: 96 for lists and menus (2× for sharp screens), 256 for the profile. */
    public const SIZES = [96, 256];
    private const MAX_BYTES = 8 * 1024 * 1024;
    private const MAX_SIDE = 6000;

    private static ?array $map = null;

    public static function dir(): string
    {
        return rtrim((string)config('uploads.path', BASE_PATH . '/storage/uploads'), '/') . '/avatars';
    }

    /** The server can make the photos (PHP GD with WebP). */
    public static function supported(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagewebp');
    }

    /** user id => token of everyone who has a photo (one query per request). */
    public static function map(): array
    {
        if (self::$map !== null) return self::$map;
        try {
            $rows = Database::get()->all('SELECT id, avatar_photo FROM users WHERE avatar_photo IS NOT NULL');
        } catch (\Throwable) {
            $rows = []; // database not migrated yet (update pending): initials only
        }
        return self::$map = array_column($rows, 'avatar_photo', 'id');
    }

    public static function urlFor(int $userId, string $size = ''): ?string
    {
        $token = self::map()[$userId] ?? null;
        if (!$token) return null;
        return url('/avatars/' . $userId . '/' . $token . '/' . ($size === 'lg' || $size === 'xl' ? 256 : 96));
    }

    /** Saves the uploaded photo of the person who is signed in. */
    public static function save(?array $upload): string
    {
        $uid = Auth::id();
        if (!$upload || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) throw HttpException::invalid(['photo' => 'Elige una imagen.']);
        if (($upload['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($upload['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE) throw HttpException::invalid(['photo' => 'La imagen es demasiado grande (máximo 8 MB).']);
        if (($upload['error'] ?? 0) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$upload['tmp_name'])) throw HttpException::invalid(['photo' => 'No se pudo recibir la imagen. Inténtalo de nuevo.']);
        $path = (string)$upload['tmp_name'];
        $size = (int)filesize($path);
        if ($size <= 0) throw HttpException::invalid(['photo' => 'La imagen está vacía.']);
        if ($size > self::MAX_BYTES) throw HttpException::invalid(['photo' => 'La imagen es demasiado grande (máximo 8 MB).']);
        if (!self::supported()) throw HttpException::invalid(['photo' => 'El servidor no tiene la extensión GD de PHP con WebP, necesaria para las fotografías. Pide a la administración que la active.']);
        $info = @getimagesize($path);
        $types = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF];
        if (!$info || !in_array($info[2], $types, true)) throw HttpException::invalid(['photo' => 'Usa una imagen JPG, PNG, WebP o GIF.']);
        if ($info[0] < 32 || $info[1] < 32) throw HttpException::invalid(['photo' => 'La imagen es demasiado pequeña (mínimo 32 × 32 píxeles).']);
        if ($info[0] > self::MAX_SIDE || $info[1] > self::MAX_SIDE) throw HttpException::invalid(['photo' => 'La imagen es demasiado grande (máximo 6000 píxeles por lado).']);
        $src = @imagecreatefromstring((string)file_get_contents($path));
        if (!$src) throw HttpException::invalid(['photo' => 'No se pudo leer la imagen.']);

        // Square from the centre (the browser already sends a square; this covers any other upload).
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $sx = intdiv($w - $side, 2);
        $sy = intdiv($h - $side, 2);
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new \RuntimeException('No se pudo crear storage/uploads/avatars.');
        $token = bin2hex(random_bytes(8));
        foreach (self::SIZES as $px) {
            $dst = imagecreatetruecolor($px, $px);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
            imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $px, $px, $side, $side);
            $ok = imagewebp($dst, "$dir/$uid-$token-$px.webp", 82);
            imagedestroy($dst);
            if (!$ok) throw new \RuntimeException('No se pudo guardar la fotografía.');
        }
        imagedestroy($src);
        $old = (string)Database::get()->value('SELECT avatar_photo FROM users WHERE id = ?', [$uid]);
        Database::get()->update('users', ['avatar_photo' => $token, 'updated_at' => now()], 'id = ?', [$uid]);
        if ($old !== '') self::deleteFiles($uid, $old);
        self::$map = null;
        return $token;
    }

    public static function remove(): void
    {
        $uid = Auth::id();
        $old = (string)Database::get()->value('SELECT avatar_photo FROM users WHERE id = ?', [$uid]);
        Database::get()->update('users', ['avatar_photo' => null, 'updated_at' => now()], 'id = ?', [$uid]);
        if ($old !== '') self::deleteFiles($uid, $old);
        self::$map = null;
    }

    /** File of a photo the viewer may see: same workspace, and within a guest's limited circle. */
    public static function file(int $userId, string $token, int $px): string
    {
        if (!in_array($px, self::SIZES, true) || !preg_match('/^[0-9a-f]{16}$/', $token)) throw HttpException::notFound();
        $current = (string)Database::get()->value('SELECT avatar_photo FROM users WHERE id = ?', [$userId]);
        if ($current === '' || !hash_equals($current, $token)) throw HttpException::notFound();
        if ($userId !== Auth::id()) {
            if (!Database::get()->value('SELECT 1 FROM workspace_members WHERE workspace_id = ? AND user_id = ?', [Policy::wsId(), $userId])) throw HttpException::notFound();
            if (($uv = Policy::visibleUserIds()) !== null && !in_array($userId, $uv, true)) throw HttpException::notFound();
        }
        $f = self::dir() . "/$userId-$token-$px.webp";
        if (!is_file($f)) throw HttpException::notFound();
        return $f;
    }

    private static function deleteFiles(int $uid, string $token): void
    {
        if (!preg_match('/^[0-9a-f]{16}$/', $token)) return;
        foreach (self::SIZES as $px) @unlink(self::dir() . "/$uid-$token-$px.webp");
    }
}
