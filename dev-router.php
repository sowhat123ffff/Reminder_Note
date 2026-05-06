<?php
/**
 * Router for `php -S` development server.
 *
 * Recommended startup:
 *   php -S 127.0.0.1:8765 dev-router.php
 *
 * The router serves files from ./public regardless of the PHP server docroot,
 * so the same command works whether the docroot is project root or ./public.
 */
$uri      = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$publicDir = __DIR__ . '/public';

if (preg_match('#^/api(/|$)#', $uri)) {
    require $publicDir . '/api/index.php';
    return true;
}

$candidate = $publicDir . $uri;

if ($uri !== '/' && is_file($candidate)) {
    $mime = mimeFor($candidate);
    if ($mime) {
        header('Content-Type: ' . $mime);
    }
    header('Content-Length: ' . filesize($candidate));
    readfile($candidate);
    return true;
}

if (is_dir($candidate)) {
    foreach (['index.html', 'index.php'] as $idx) {
        $f = $candidate . '/' . $idx;
        if (is_file($f)) {
            if (str_ends_with($f, '.php')) {
                $_SERVER['SCRIPT_FILENAME'] = $f;
                require $f;
            } else {
                header('Content-Type: text/html; charset=utf-8');
                readfile($f);
            }
            return true;
        }
    }
}

foreach (['index.html', 'login.html'] as $fallback) {
    $f = $publicDir . '/' . $fallback;
    if (is_file($f)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($f);
        return true;
    }
}

http_response_code(404);
echo 'Not found';
return true;

function mimeFor(string $path): ?string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return match ($ext) {
        'html' => 'text/html; charset=utf-8',
        'css'  => 'text/css; charset=utf-8',
        'js', 'mjs' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'webmanifest' => 'application/manifest+json; charset=utf-8',
        'svg'  => 'image/svg+xml',
        'png'  => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'ico'  => 'image/x-icon',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'ttf'  => 'font/ttf',
        'map'  => 'application/json',
        default => null,
    };
}
