<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Minimal HS256 JWT implementation (zero external deps).
 * Adequate for a single-user self-hosted app.
 */
final class Jwt
{
    public static function encode(array $payload, string $secret): string
    {
        $header  = ['alg' => 'HS256', 'typ' => 'JWT'];
        $segments = [
            self::base64UrlEncode((string) json_encode($header,  JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            self::base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ];
        $signing = implode('.', $segments);
        $sig     = hash_hmac('sha256', $signing, $secret, true);
        $segments[] = self::base64UrlEncode($sig);
        return implode('.', $segments);
    }

    public static function decode(string $token, string $secret): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed token');
        }
        [$h, $p, $s] = $parts;

        $header = json_decode((string) self::base64UrlDecode($h), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
            throw new RuntimeException('Unsupported alg');
        }

        $expected = hash_hmac('sha256', $h . '.' . $p, $secret, true);
        $given    = self::base64UrlDecode($s);
        if (!hash_equals($expected, $given)) {
            throw new RuntimeException('Invalid signature');
        }

        $payload = json_decode((string) self::base64UrlDecode($p), true);
        if (!is_array($payload)) {
            throw new RuntimeException('Invalid payload');
        }
        $now = time();
        if (isset($payload['nbf']) && $now < (int) $payload['nbf']) {
            throw new RuntimeException('Token not yet valid');
        }
        if (isset($payload['exp']) && $now >= (int) $payload['exp']) {
            throw new RuntimeException('Token expired');
        }
        return $payload;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('Invalid base64');
        }
        return $decoded;
    }
}
