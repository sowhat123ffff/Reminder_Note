<?php
declare(strict_types=1);

/**
 * Application bootstrap.
 * Prefers Composer autoload, falls back to a minimal PSR-4 autoloader so the
 * project can run on a fresh XAMPP install before `composer install`.
 */

$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_readable($composerAutoload)) {
    require_once $composerAutoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'App\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $rel     = str_replace('\\', '/', $relative);
        $candidates = [
            __DIR__ . '/' . $rel . '.php',
        ];
        $segments = explode('/', $rel);
        if (count($segments) > 1) {
            $first = strtolower($segments[0]);
            $rest  = implode('/', array_slice($segments, 1));
            $candidates[] = __DIR__ . '/' . $first . '/' . $rest . '.php';
        }
        foreach ($candidates as $file) {
            if (is_readable($file)) {
                require_once $file;
                return;
            }
        }
    });
}

App\Config::all();

if (App\Config::isDebug()) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ERROR | E_WARNING | E_PARSE);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

App\Config::resolveJwtSecret();
App\Db::pdo();
