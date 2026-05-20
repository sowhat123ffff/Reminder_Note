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

    /**
     * If `jwt_secret` is the auto-marker (or the legacy placeholder), read or
     * generate a 64-byte hex secret stored in `data/.jwt_secret` and patch it
     * back into the in-memory config. Delete that file to invalidate every
     * existing token (forces all clients to re-login).
     */
    public static function resolveJwtSecret(): void
    {
        self::all();
        $current = (string) (self::$cache['jwt_secret'] ?? '');
        $needsAuto = $current === ''
            || $current === 'auto'
            || $current === 'REPLACE_ME_WITH_64_BYTE_HEX';
        if (!$needsAuto) {
            return;
        }

        $dbPath = (string) (self::$cache['db_path'] ?? (__DIR__ . '/../data/app.db'));
        $dataDir = dirname($dbPath);
        if (!is_dir($dataDir)) {
            if (!mkdir($dataDir, 0775, true) && !is_dir($dataDir)) {
                throw new RuntimeException("Cannot create data dir for jwt secret: {$dataDir}");
            }
        }
        $secretPath = $dataDir . '/.jwt_secret';

        if (is_readable($secretPath)) {
            $secret = trim((string) file_get_contents($secretPath));
        } else {
            $secret = bin2hex(random_bytes(64));
            $tmp = $secretPath . '.tmp';
            if (file_put_contents($tmp, $secret) === false) {
                throw new RuntimeException("Cannot write jwt secret to {$secretPath}");
            }
            @chmod($tmp, 0600);
            if (!@rename($tmp, $secretPath)) {
                @unlink($tmp);
                throw new RuntimeException("Cannot install jwt secret at {$secretPath}");
            }
        }

        if ($secret === '') {
            throw new RuntimeException("Empty jwt secret at {$secretPath}");
        }
        self::$cache['jwt_secret'] = $secret;
    }
}
