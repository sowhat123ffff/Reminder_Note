<?php
declare(strict_types=1);
chdir(__DIR__ . '/..');
require __DIR__ . '/../app/bootstrap.php';

echo "PHP: ", PHP_VERSION, PHP_EOL;
echo "DB ok: ", App\Db::pdo() ? 'yes' : 'no', PHP_EOL;

$tokens = App\Auth::login('jian', '123456', '127.0.0.1');
echo "access (first 40): ", substr($tokens['accessToken'], 0, 40), "...", PHP_EOL;
echo "refresh (first 40): ", substr($tokens['refreshToken'], 0, 40), "...", PHP_EOL;

$payload = App\Jwt::decode($tokens['accessToken'], (string) App\Config::get('jwt_secret'));
echo "decoded.sub=", $payload['sub'], " typ=", $payload['typ'], PHP_EOL;

try {
    App\Auth::login('jian', 'wrong', '127.0.0.1');
} catch (App\HttpException $e) {
    echo "wrong-pass status=", $e->status, " code=", $e->errorCode, PHP_EOL;
}

echo "OK\n";
