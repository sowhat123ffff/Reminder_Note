<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Db;
use App\HttpException;
use App\Request;
use App\Validator;

final class AuthController
{
    public function register(Request $req): array
    {
        $data = Validator::check($req->body, [
            'username' => 'required|string|max:32',
            'password' => 'required|string|max:256',
        ]);
        return Auth::register((string) $data['username'], (string) $data['password'], $req->ip, $req->userAgent);
    }

    public function login(Request $req): array
    {
        $data = Validator::check($req->body, [
            'username' => 'required|string|max:64',
            'password' => 'required|string|max:256',
        ]);
        return Auth::login((string) $data['username'], (string) $data['password'], $req->ip, $req->userAgent);
    }

    public function refresh(Request $req): array
    {
        $token = (string) ($req->body['refreshToken'] ?? '');
        if ($token === '') {
            throw new HttpException(400, 'missing_refresh', 'refreshToken required');
        }
        return Auth::refresh($token, $req->ip, $req->userAgent);
    }

    public function logout(Request $req): array
    {
        $token = $req->body['refreshToken'] ?? null;
        Auth::logout(is_string($token) ? $token : null);
        return ['ok' => true];
    }

    public function me(Request $req): array
    {
        $payload = Auth::requireAccess();
        $uid     = (string) $payload['sub'];

        $stmt = Db::pdo()->prepare('SELECT id, username, created_at FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $uid]);
        $user = $stmt->fetch();
        if (!$user) {
            // The token is signed but the user has been deleted out from
            // under us. Treat as logged-out so the client clears tokens.
            throw new HttpException(401, 'user_gone', '账号不存在');
        }
        return [
            'user' => [
                'id'         => (string) $user['id'],
                'username'   => (string) $user['username'],
                'created_at' => (int) $user['created_at'],
            ],
            'session' => [
                'jti' => $payload['pjti'] ?? null,
                'exp' => $payload['exp'] ?? null,
            ],
        ];
    }

    public function changePassword(Request $req): array
    {
        $data = Validator::check($req->body, [
            'oldPassword' => 'required|string|max:256',
            'newPassword' => 'required|string|max:256',
        ]);
        $uid = Auth::userId();
        $remaining = Auth::changePassword($uid, (string) $data['oldPassword'], (string) $data['newPassword']);
        return ['ok' => true, 'activeSessions' => $remaining];
    }

    public function sessions(Request $req): array
    {
        return ['sessions' => Auth::listSessions(Auth::userId())];
    }

    public function revokeSession(Request $req, array $params): array
    {
        Auth::revokeSession(Auth::userId(), (string) $params['jti']);
        return ['ok' => true];
    }

    public function revokeAllSessions(Request $req): array
    {
        $count = Auth::revokeAllSessionsExceptCurrent(Auth::userId());
        return ['ok' => true, 'revoked' => $count];
    }

    public function loginHistory(Request $req): array
    {
        $limit = (int) ($req->query['limit'] ?? 50);
        return ['attempts' => Auth::loginHistory(Auth::userId(), $limit)];
    }
}
