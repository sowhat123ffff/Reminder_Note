<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

final class Config
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $path = __DIR__ . '/../config/config.php';
        if (!is_readable($path)) {
            throw new RuntimeException('config/config.php not found. Copy config/config.example.php and fill in secrets.');
        }
        $config = require $path;
        if (!is_array($config)) {
            throw new RuntimeException('config/config.php must return an array');
        }
        self::$cache = $config;

        if (!empty($config['app_tz'])) {
            date_default_timezone_set($config['app_tz']);
        }

        return self::$cache;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    public static function isDebug(): bool
    {
        return (bool) self::get('app_debug', false);
    }
}
