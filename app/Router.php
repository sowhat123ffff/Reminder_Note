<?php
declare(strict_types=1);

namespace App;

final class Router
{
    /** @var array<int, array{method:string, pattern:string, regex:string, handler:callable, public:bool}> */
    private array $routes = [];

    public function get(string $path, callable $handler, bool $public = false): void
    {
        $this->add('GET', $path, $handler, $public);
    }

    public function post(string $path, callable $handler, bool $public = false): void
    {
        $this->add('POST', $path, $handler, $public);
    }

    public function patch(string $path, callable $handler, bool $public = false): void
    {
        $this->add('PATCH', $path, $handler, $public);
    }

    public function put(string $path, callable $handler, bool $public = false): void
    {
        $this->add('PUT', $path, $handler, $public);
    }

    public function delete(string $path, callable $handler, bool $public = false): void
    {
        $this->add('DELETE', $path, $handler, $public);
    }

    private function add(string $method, string $path, callable $handler, bool $public): void
    {
        $regex = '#^' . preg_replace('#\{([a-zA-Z_]+)\}#', '(?P<$1>[^/]+)', $path) . '$#';
        $this->routes[] = [
            'method'  => $method,
            'pattern' => $path,
            'regex'   => $regex,
            'handler' => $handler,
            'public'  => $public,
        ];
    }

    public function dispatch(Request $req): void
    {
        if ($req->method === 'OPTIONS') {
            http_response_code(204);
            return;
        }

        $allowedMethods = [];
        foreach ($this->routes as $r) {
            if (preg_match($r['regex'], $req->path, $m)) {
                if ($r['method'] !== $req->method) {
                    $allowedMethods[] = $r['method'];
                    continue;
                }
                if (!$r['public']) {
                    Auth::requireAccess();
                    Auth::touchCurrentSession();
                }
                $params = [];
                foreach ($m as $k => $v) {
                    if (!is_int($k)) {
                        $params[$k] = $v;
                    }
                }
                $result = ($r['handler'])($req, $params);
                if (is_array($result)) {
                    Response::json($result);
                }
                return;
            }
        }

        if (!empty($allowedMethods)) {
            header('Allow: ' . implode(', ', array_unique($allowedMethods)));
            Response::error(405, 'method_not_allowed', 'Method not allowed for this route');
            return;
        }

        Response::error(404, 'not_found', 'Route not found: ' . $req->method . ' ' . $req->path);
    }
}
