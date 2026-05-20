<?php
declare(strict_types=1);

namespace App;

use App\Helpers\Uuid;

final class Auth
{
    /** Cached payload for the current request, populated lazily by requireAccess(). */
    private static ?array $currentPayload = null;

    /**
     * Open registration. The first user is just a regular user — there is no
     * admin/owner concept. Per-IP rate-limited via `auth_attempts(kind='register')`.
     */
    public static function register(string $username, string $password, string $ip, string $ua): array
    {
        self::enforceRegisterRateLimit($ip);

        $username = trim($username);
        if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username)) {
            throw new HttpException(422, 'invalid_username', '用户名需为 3–32 位字母 / 数字 / 下划线');
        }
        if (mb_strlen($password) < 8) {
            throw new HttpException(422, 'weak_password', '密码至少 8 位');
        }
        if (mb_strlen($password) > 256) {
            throw new HttpException(422, 'invalid_password', '密码过长');
        }

        $stmt = Db::pdo()->prepare('SELECT 1 FROM users WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $username]);
        if ($stmt->fetch()) {
            self::recordAttempt('register', $ip, false, null, $ua);
            throw new HttpException(409, 'username_taken', '用户名已被占用');
        }

        $now  = Db::now();
        $uid  = Uuid::v4();
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        Db::pdo()->prepare('INSERT INTO users (id, username, password_hash, created_at, updated_at) VALUES (:id, :u, :h, :c, :u2)')
            ->execute([
                ':id' => $uid,
                ':u'  => $username,
                ':h'  => $hash,
                ':c'  => $now,
                ':u2' => $now,
            ]);

        self::recordAttempt('register', $ip, true, $uid, $ua);
        return self::issueTokens($uid, $ip, $ua) + ['user' => ['id' => $uid, 'username' => $username]];
    }

    public static function login(string $username, string $password, string $ip, string $ua): array
    {
        self::enforceLoginRateLimit($ip);

        $stmt = Db::pdo()->prepare('SELECT id, password_hash FROM users WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $username]);
        $row = $stmt->fetch();

        $uid    = $row ? (string) $row['id'] : null;
        $passOk = $row && password_verify($password, (string) $row['password_hash']);

        // Always record the attempt so the user can audit it. Recording the
        // resolved user_id even on failure is intentional — it lets the
        // legitimate owner see hostile attempts. The /login response is
        // identical regardless of whether $username exists, so this does not
        // expose enumeration to outside callers.
        self::recordAttempt('login', $ip, $passOk, $uid, $ua);

        if (!$passOk) {
            usleep(800_000);
            throw new HttpException(401, 'invalid_credentials', '用户名或密码错误');
        }

        $tokens = self::issueTokens($uid, $ip, $ua);
        return $tokens + ['user' => ['id' => $uid, 'username' => $username]];
    }

    public static function refresh(string $refreshToken, string $ip, string $ua): array
    {
        $secret = (string) Config::get('jwt_secret');
        try {
            $payload = Jwt::decode($refreshToken, $secret);
        } catch (\Throwable $e) {
            throw new HttpException(401, 'invalid_refresh', 'Invalid refresh token');
        }

        if (($payload['typ'] ?? '') !== 'refresh' || empty($payload['jti']) || empty($payload['sub'])) {
            throw new HttpException(401, 'invalid_refresh', 'Invalid refresh token');
        }

        $stmt = Db::pdo()->prepare('SELECT jti, user_id, revoked, expires_at FROM refresh_tokens WHERE jti = :jti');
        $stmt->execute([':jti' => $payload['jti']]);
        $row = $stmt->fetch();
        if (!$row || (int) $row['revoked'] === 1 || (int) $row['expires_at'] < time()) {
            throw new HttpException(401, 'invalid_refresh', 'Refresh token revoked or expired');
        }
        if ((string) $row['user_id'] !== (string) $payload['sub']) {
            throw new HttpException(401, 'invalid_refresh', 'Refresh token mismatch');
        }

        return self::issueTokens((string) $row['user_id'], $ip, $ua, $payload['jti']);
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
        if (self::$currentPayload !== null) {
            return self::$currentPayload;
        }
        $token = self::extractBearer();
        if (!$token) {
            throw new HttpException(401, 'unauthorized', 'Missing bearer token');
        }
        try {
            $payload = Jwt::decode($token, (string) Config::get('jwt_secret'));
        } catch (\Throwable $e) {
            throw new HttpException(401, 'unauthorized', 'Invalid or expired token');
        }
        if (($payload['typ'] ?? '') !== 'access' || empty($payload['sub'])) {
            throw new HttpException(401, 'unauthorized', 'Wrong token type');
        }
        self::$currentPayload = $payload;
        return $payload;
    }

    /** Convenience: throws 401 if not authenticated, returns the user id otherwise. */
    public static function userId(): string
    {
        return (string) self::requireAccess()['sub'];
    }

    /** The jti of the refresh token that minted the current access token (if any). */
    public static function currentJti(): ?string
    {
        $jti = self::requireAccess()['pjti'] ?? null;
        return is_string($jti) ? $jti : null;
    }

    /**
     * Change the current user's password. Requires the old password, revokes
     * every other refresh token (logging out other devices) and keeps the
     * current session alive so the user stays signed in on this browser.
     */
    public static function changePassword(string $uid, string $oldPassword, string $newPassword): int
    {
        if (mb_strlen($newPassword) < 8) {
            throw new HttpException(422, 'weak_password', '新密码至少 8 位');
        }
        if (mb_strlen($newPassword) > 256) {
            throw new HttpException(422, 'invalid_password', '新密码过长');
        }

        $stmt = Db::pdo()->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $uid]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($oldPassword, (string) $row['password_hash'])) {
            usleep(500_000);
            throw new HttpException(401, 'invalid_credentials', '原密码不正确');
        }
        if ($oldPassword === $newPassword) {
            throw new HttpException(422, 'same_password', '新密码不能与旧密码相同');
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $now  = Db::now();

        Db::transaction(function () use ($uid, $hash, $now) {
            Db::pdo()->prepare('UPDATE users SET password_hash = :h, updated_at = :u WHERE id = :id')
                ->execute([':h' => $hash, ':u' => $now, ':id' => $uid]);
            $current = self::currentJti();
            if ($current) {
                $sql = 'UPDATE refresh_tokens SET revoked = 1 WHERE user_id = :uid AND jti != :jti AND revoked = 0';
                Db::pdo()->prepare($sql)->execute([':uid' => $uid, ':jti' => $current]);
            } else {
                Db::pdo()->prepare('UPDATE refresh_tokens SET revoked = 1 WHERE user_id = :uid AND revoked = 0')
                    ->execute([':uid' => $uid]);
            }
        });

        return self::countActiveSessions($uid);
    }

    /** Active (not revoked, not expired) sessions for the user. */
    public static function listSessions(string $uid): array
    {
        $now = time();
        $sql = 'SELECT jti, user_agent, ip, last_used_at, created_at, expires_at
                FROM refresh_tokens
                WHERE user_id = :uid AND revoked = 0 AND expires_at > :now
                ORDER BY last_used_at DESC, created_at DESC';
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute([':uid' => $uid, ':now' => $now]);
        $current = self::currentJti();
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[] = [
                'jti'          => $r['jti'],
                'user_agent'   => (string) $r['user_agent'],
                'ip'           => (string) $r['ip'],
                'last_used_at' => (int) $r['last_used_at'],
                'created_at'   => (int) $r['created_at'],
                'expires_at'   => (int) $r['expires_at'],
                'is_current'   => $current !== null && hash_equals((string) $r['jti'], $current),
            ];
        }
        return $rows;
    }

    public static function revokeSession(string $uid, string $jti): void
    {
        $stmt = Db::pdo()->prepare('UPDATE refresh_tokens SET revoked = 1 WHERE user_id = :uid AND jti = :jti AND revoked = 0');
        $stmt->execute([':uid' => $uid, ':jti' => $jti]);
        if ($stmt->rowCount() === 0) {
            throw new HttpException(404, 'session_not_found', '会话不存在或已注销');
        }
    }

    public static function revokeAllSessionsExceptCurrent(string $uid): int
    {
        $current = self::currentJti();
        if ($current === null) {
            $stmt = Db::pdo()->prepare('UPDATE refresh_tokens SET revoked = 1 WHERE user_id = :uid AND revoked = 0');
            $stmt->execute([':uid' => $uid]);
            return $stmt->rowCount();
        }
        $stmt = Db::pdo()->prepare('UPDATE refresh_tokens SET revoked = 1 WHERE user_id = :uid AND jti != :jti AND revoked = 0');
        $stmt->execute([':uid' => $uid, ':jti' => $current]);
        return $stmt->rowCount();
    }

    /** Most recent login attempts (success + failure) for the user. */
    public static function loginHistory(string $uid, int $limit = 50): array
    {
        $limit  = max(1, min(200, $limit));
        $cutoff = time() - 30 * 86400;
        $sql = 'SELECT created_at, ip, user_agent, success
                FROM auth_attempts
                WHERE user_id = :uid AND kind = :k AND created_at >= :cutoff
                ORDER BY created_at DESC
                LIMIT ' . $limit;
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute([':uid' => $uid, ':k' => 'login', ':cutoff' => $cutoff]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'created_at' => (int) $r['created_at'],
                'ip'         => (string) $r['ip'],
                'user_agent' => (string) ($r['user_agent'] ?? ''),
                'success'    => (int) $r['success'] === 1,
            ];
        }
        return $out;
    }

    /**
     * @param string|null $reuseJti when refreshing, the jti of the refresh
     *                              token being rotated out (revoked here).
     */
    private static function issueTokens(string $uid, string $ip, string $ua, ?string $reuseJti = null): array
    {
        $secret    = (string) Config::get('jwt_secret');
        $issuer    = (string) Config::get('jwt_issuer', 'reminder-note');
        $accessTtl = (int) Config::get('jwt_access_ttl', 900);
        $refreshTtl= (int) Config::get('jwt_refresh_ttl', 2592000);
        $now       = time();

        if ($reuseJti) {
            $stmt = Db::pdo()->prepare('UPDATE refresh_tokens SET revoked = 1 WHERE jti = :jti');
            $stmt->execute([':jti' => $reuseJti]);
        }

        $jti = bin2hex(random_bytes(16));
        $refreshExp = $now + $refreshTtl;
        $insert = 'INSERT INTO refresh_tokens (jti, user_id, expires_at, revoked, created_at, user_agent, ip, last_used_at)
                   VALUES (:jti, :uid, :exp, 0, :now, :ua, :ip, :now)';
        $stmt = Db::pdo()->prepare($insert);
        $stmt->execute([
            ':jti' => $jti,
            ':uid' => $uid,
            ':exp' => $refreshExp,
            ':now' => $now,
            ':ua'  => mb_substr($ua, 0, 500),
            ':ip'  => mb_substr($ip, 0, 64),
        ]);

        $access = Jwt::encode([
            'iss'  => $issuer,
            'sub'  => $uid,
            'pjti' => $jti,
            'typ'  => 'access',
            'iat'  => $now,
            'nbf'  => $now,
            'exp'  => $now + $accessTtl,
        ], $secret);

        $refresh = Jwt::encode([
            'iss' => $issuer,
            'sub' => $uid,
            'typ' => 'refresh',
            'jti' => $jti,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $refreshExp,
        ], $secret);

        // The newly-minted access token's pjti points to this refresh token —
        // make sure subsequent calls in this request know about the new uid.
        self::$currentPayload = null;

        return [
            'accessToken'      => $access,
            'refreshToken'     => $refresh,
            'accessExpiresAt'  => ($now + $accessTtl) * 1000,
            'refreshExpiresAt' => $refreshExp * 1000,
        ];
    }

    /** Update last_used_at for the current refresh token (best-effort, never throws). */
    public static function touchCurrentSession(): void
    {
        $jti = self::currentJti();
        if (!$jti) {
            return;
        }
        try {
            $stmt = Db::pdo()->prepare('UPDATE refresh_tokens SET last_used_at = :now WHERE jti = :jti AND revoked = 0');
            $stmt->execute([':now' => time(), ':jti' => $jti]);
        } catch (\Throwable $e) {
            // best-effort; ignore
        }
    }

    private static function countActiveSessions(string $uid): int
    {
        $stmt = Db::pdo()->prepare('SELECT COUNT(*) c FROM refresh_tokens WHERE user_id = :uid AND revoked = 0 AND expires_at > :now');
        $stmt->execute([':uid' => $uid, ':now' => time()]);
        return (int) ($stmt->fetch()['c'] ?? 0);
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

    private static function enforceLoginRateLimit(string $ip): void
    {
        $window = (int) Config::get('login_rate_window', 60);
        $limit  = (int) Config::get('login_rate_limit', 5);
        $since  = time() - $window;
        $stmt = Db::pdo()->prepare('SELECT COUNT(*) c FROM auth_attempts WHERE kind = :k AND ip = :ip AND success = 0 AND created_at >= :since');
        $stmt->execute([':k' => 'login', ':ip' => $ip, ':since' => $since]);
        if ((int) ($stmt->fetch()['c'] ?? 0) >= $limit) {
            throw new HttpException(429, 'rate_limited', '登录尝试过多，请稍后再试');
        }
    }

    private static function enforceRegisterRateLimit(string $ip): void
    {
        $window = (int) Config::get('login_rate_window', 60);
        $limit  = (int) Config::get('register_rate_limit', 3);
        $since  = time() - $window;
        $stmt = Db::pdo()->prepare('SELECT COUNT(*) c FROM auth_attempts WHERE kind = :k AND ip = :ip AND created_at >= :since');
        $stmt->execute([':k' => 'register', ':ip' => $ip, ':since' => $since]);
        if ((int) ($stmt->fetch()['c'] ?? 0) >= $limit) {
            throw new HttpException(429, 'rate_limited', '注册过于频繁，请稍后再试');
        }
    }

    private static function recordAttempt(string $kind, string $ip, bool $success, ?string $userId, string $ua): void
    {
        $stmt = Db::pdo()->prepare('INSERT INTO auth_attempts (ip, kind, success, user_id, user_agent, created_at) VALUES (:ip, :k, :s, :u, :ua, :now)');
        $stmt->execute([
            ':ip'  => $ip,
            ':k'   => $kind,
            ':s'   => $success ? 1 : 0,
            ':u'   => $userId,
            ':ua'  => mb_substr($ua, 0, 500),
            ':now' => time(),
        ]);
        // Keep the table from growing without bound.
        $cleanup = Db::pdo()->prepare('DELETE FROM auth_attempts WHERE created_at < :cutoff');
        $cleanup->execute([':cutoff' => time() - 60 * 86400]);
    }
}
