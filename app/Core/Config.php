<?php
declare(strict_types=1);

namespace Borealis\Core;

final class Config
{
    private static array $data = [];
    private static bool $loaded = false;

    public static function path(): string
    {
        return getenv('BOREALIS_CONFIG') ?: BASE_PATH . '/config/config.php';
    }

    public static function isInstalled(): bool
    {
        return is_file(self::path());
    }

    public static function load(): void
    {
        if (self::$loaded) return;
        self::$loaded = true;
        if (self::isInstalled()) {
            self::$data = require self::path();
        }
    }

    public static function set(string $key, mixed $value): void
    {
        $ref = &self::$data;
        foreach (explode('.', $key) as $k) {
            if (!isset($ref[$k]) || !is_array($ref[$k])) $ref[$k] = $ref[$k] ?? [];
            $ref = &$ref[$k];
        }
        $ref = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        $ref = self::$data;
        foreach (explode('.', $key) as $k) {
            if (!is_array($ref) || !array_key_exists($k, $ref)) return $default;
            $ref = $ref[$k];
        }
        return $ref;
    }
}
