<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

final class Auth
{
    public const SUBJECT = 'owner';

    public static function login(string $username, string $password, string $ip): array
    {
        self::enforceRateLimit($ip);

        $cfgUser = (string) Config::get('username');
        $cfgHash = (string) Config::get('password_hash');

        $userOk = hash_equals($cfgUser, $username);
        $passOk = $userOk && password_verify($password, $cfgHash);

        self::recordAttempt($ip, $passOk);

        if (!$passOk) {
            usleep(800_000);
            throw new HttpException(401, 'invalid_credentials', 'Invalid username or password');
        }

        return self::issueTokens();
    }

    public static function refresh(string $refreshToken): array
    {
        $secret = (string) Config::get('jwt_secret');
        try {
            $payload = Jwt::decode($refreshToken, $secret);
        } catch (\Throwable $e) {
            throw new HttpException(401, 'invalid_refresh', 'Invalid refresh token');
        }

        if (($payload['typ'] ?? '') !== 'refresh' || empty($payload['jti'])) {
            throw new HttpException(401, 'invalid_refresh', 'Invalid refresh token');
        }

        $stmt = Db::pdo()->prepare('SELECT jti, revoked, expires_at FROM refresh_tokens WHERE jti = :jti');
        $stmt->execute([':jti' => $payload['jti']]);
        $row = $stmt->fetch();
        if (!$row || (int) $row['revoked'] === 1 || (int) $row['expires_at'] < time()) {
            throw new HttpException(401, 'invalid_refresh', 'Refresh token revoked or expired');
        }

        return self::issueTokens($payload['jti']);
    }

    public static function logout(?string $refreshToken): void
    {
        if (!$refreshToken) {
            return;
        }
        try {
            $payload = Jwt::decode($refreshToken, (string) Config::get('jwt_secret'));
        } catch (\Throwable $e) {
            return;
        }
        if (!empty($payload['jti'])) {
            $stmt = Db::pdo()->prepare('UPDATE refresh_tokens SET revoked = 1 WHERE jti = :jti');
            $stmt->execute([':jti' => $payload['jti']]);
        }
    }

    public static function requireAccess(): array
    {
        $token = self::extractBearer();
        if (!$token) {
            throw new HttpException(401, 'unauthorized', 'Missing bearer token');
        }
        try {
            $payload = Jwt::decode($token, (string) Config::get('jwt_secret'));
        } catch (\Throwable $e) {
            throw new HttpException(401, 'unauthorized', 'Invalid or expired token');
        }
        if (($payload['typ'] ?? '') !== 'access') {
            throw new HttpException(401, 'unauthorized', 'Wrong token type');
        }
        return $payload;
    }

    private static function issueTokens(?string $reuseJti = null): array
    {
        $secret    = (string) Config::get('jwt_secret');
        $issuer    = (string) Config::get('jwt_issuer', 'reminder-note');
        $accessTtl = (int) Config::get('jwt_access_ttl', 900);
        $refreshTtl= (int) Config::get('jwt_refresh_ttl', 2592000);
        $now       = time();

        $access = Jwt::encode([
            'iss' => $issuer,
            'sub' => self::SUBJECT,
            'typ' => 'access',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $accessTtl,
        ], $secret);

        if ($reuseJti) {
            $stmt = Db::pdo()->prepare('UPDATE refresh_tokens SET revoked = 1 WHERE jti = :jti');
            $stmt->execute([':jti' => $reuseJti]);
        }

        $jti = bin2hex(random_bytes(16));
        $refreshExp = $now + $refreshTtl;
        $stmt = Db::pdo()->prepare('INSERT INTO refresh_tokens (jti, expires_at, revoked, created_at) VALUES (:jti, :exp, 0, :now)');
        $stmt->execute([
            ':jti' => $jti,
            ':exp' => $refreshExp,
            ':now' => $now,
        ]);

        $refresh = Jwt::encode([
            'iss' => $issuer,
            'sub' => self::SUBJECT,
            'typ' => 'refresh',
            'jti' => $jti,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $refreshExp,
        ], $secret);

        return [
            'accessToken'      => $access,
            'refreshToken'     => $refresh,
            'accessExpiresAt'  => ($now + $accessTtl) * 1000,
            'refreshExpiresAt' => $refreshExp * 1000,
        ];
    }

    private static function extractBearer(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!$header && function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $header = $value;
                    break;
                }
            }
        }
        if (!$header) {
            return null;
        }
        if (preg_match('/Bearer\s+(\S+)/i', $header, $m)) {
            return $m[1];
        }
        return null;
    }

    private static function enforceRateLimit(string $ip): void
    {
        $window = (int) Config::get('login_rate_window', 60);
        $limit  = (int) Config::get('login_rate_limit', 5);
        $since  = time() - $window;

        $stmt = Db::pdo()->prepare('SELECT COUNT(*) AS c FROM login_attempts WHERE ip = :ip AND success = 0 AND created_at >= :since');
        $stmt->execute([':ip' => $ip, ':since' => $since]);
        $count = (int) ($stmt->fetch()['c'] ?? 0);

        if ($count >= $limit) {
            throw new HttpException(429, 'rate_limited', 'Too many login attempts, slow down');
        }
    }

    private static function recordAttempt(string $ip, bool $success): void
    {
        $stmt = Db::pdo()->prepare('INSERT INTO login_attempts (ip, success, created_at) VALUES (:ip, :s, :now)');
        $stmt->execute([':ip' => $ip, ':s' => $success ? 1 : 0, ':now' => time()]);

        $cleanup = Db::pdo()->prepare('DELETE FROM login_attempts WHERE created_at < :cutoff');
        $cleanup->execute([':cutoff' => time() - 86400]);
    }
}
