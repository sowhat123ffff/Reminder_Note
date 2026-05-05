<?php
declare(strict_types=1);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/api/health';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['CONTENT_TYPE']   = '';

ob_start();
require __DIR__ . '/../public/api/index.php';
$out = ob_get_clean();

echo "STATUS: ", http_response_code(), PHP_EOL;
echo "OUTPUT: ", $out, PHP_EOL;
