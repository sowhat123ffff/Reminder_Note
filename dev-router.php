<?php
/**
 * Router for `php -S` development server.
 */
$uri      = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$publicDir = __DIR__ . '/public';

if (preg_match('#^/api(/|$)#', $uri)) {
    require $publicDir . '/api/index.php';
    return true;
}

$file = $publicDir . $uri;

if ($uri !== '/' && file_exists($file) && !is_dir($file)) {
    return false;
}

if (is_dir($file)) {
    foreach (['index.html', 'index.php'] as $idx) {
        if (file_exists($file . '/' . $idx)) {
            $_SERVER['SCRIPT_FILENAME'] = $file . '/' . $idx;
            require $_SERVER['SCRIPT_FILENAME'];
            return true;
        }
    }
}

$fallback = $publicDir . '/index.html';
if (file_exists($fallback)) {
    require $fallback;
    return true;
}
$fallback = $publicDir . '/login.html';
if (file_exists($fallback)) {
    require $fallback;
    return true;
}

http_response_code(404);
echo 'Not found';
return true;
