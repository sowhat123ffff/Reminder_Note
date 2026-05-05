<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\HttpException;
use App\Request;
use App\Validator;

final class AuthController
{
    public function login(Request $req): array
    {
        $data = Validator::check($req->body, [
            'username' => 'required|string|max:64',
            'password' => 'required|string|max:256',
        ]);
        $tokens = Auth::login($data['username'], $data['password'], $req->ip);
        return $tokens + ['user' => ['username' => $data['username']]];
    }

    public function refresh(Request $req): array
    {
        $token = (string) ($req->body['refreshToken'] ?? '');
        if ($token === '') {
            throw new HttpException(400, 'missing_refresh', 'refreshToken required');
        }
        return Auth::refresh($token);
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
        return [
            'sub'   => $payload['sub'] ?? null,
            'iss'   => $payload['iss'] ?? null,
            'exp'   => $payload['exp'] ?? null,
        ];
    }
}
