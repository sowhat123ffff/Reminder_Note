<?php
declare(strict_types=1);

namespace App;

final class Request
{
    public string $method;
    public string $path;
    public array $query;
    public array $body;
    public string $ip;

    public function __construct(string $method, string $path, array $query, array $body, string $ip)
    {
        $this->method = strtoupper($method);
        $this->path   = $path;
        $this->query  = $query;
        $this->body   = $body;
        $this->ip     = $ip;
    }

    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        $rawUri = $_SERVER['REQUEST_URI'] ?? '/';
        $path   = parse_url($rawUri, PHP_URL_PATH) ?: '/';

        $apiPos = strpos($path, '/api/');
        if ($apiPos !== false) {
            $path = substr($path, $apiPos + 4);
        } elseif (str_ends_with($path, '/api') || str_ends_with($path, '/api/')) {
            $path = '/';
        }

        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        $path = rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        $body = [];
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            if (stripos($contentType, 'application/json') !== false) {
                $raw = file_get_contents('php://input') ?: '';
                if ($raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $body = $decoded;
                    }
                }
            } elseif (stripos($contentType, 'multipart/form-data') !== false || stripos($contentType, 'application/x-www-form-urlencoded') !== false) {
                $body = $_POST;
            } else {
                $raw = file_get_contents('php://input') ?: '';
                if ($raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $body = $decoded;
                    }
                }
            }
        }

        return new self(
            $method,
            $path,
            $_GET,
            $body,
            self::clientIp()
        );
    }

    public static function clientIp(): string
    {
        $candidates = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];
        foreach ($candidates as $key) {
            if (!empty($_SERVER[$key])) {
                $val = (string) $_SERVER[$key];
                if (str_contains($val, ',')) {
                    $val = trim(explode(',', $val)[0]);
                }
                return $val;
            }
        }
        return '0.0.0.0';
    }
}
