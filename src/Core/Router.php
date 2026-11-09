<?php

declare(strict_types=1);

namespace Mailing\Core;

/**
 * Small regex router. Handlers: [Controller::class, 'method'] or callables.
 * Route params ({id}) are passed in order. Middleware:
 *   auth    – signed-in user
 *   admin   – administrator
 *   edit    – administrator or editor (viewers can only read)
 *   project – a current project is selected and allowed
 *   public  – no session or CSRF (webhooks, API, public pages)
 */
final class Router
{
    /** @var array<int, array{0:string,1:string,2:array<int,string>,3:mixed,4:array<int,string>}> */
    private array $routes = [];

    public function get(string $path, mixed $handler, array $mw = []): void
    {
        $this->add('GET', $path, $handler, $mw);
    }

    public function post(string $path, mixed $handler, array $mw = []): void
    {
        $this->add('POST', $path, $handler, $mw);
    }

    public function add(string $method, string $path, mixed $handler, array $mw): void
    {
        $names = [];
        $regex = preg_replace_callback('#\{([a-z_]+)(?::([^}]+))?\}#', static function ($m) use (&$names) {
            $names[] = $m[1];
            return '(' . ($m[2] ?? '[^/]+') . ')';
        }, $path);
        $this->routes[] = [$method, '#^' . $regex . '$#', $names, $handler, $mw];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = '/' . trim((string) (parse_url($uri, PHP_URL_PATH) ?: '/'), '/');
        $method = strtoupper($method);
        if ($method === 'HEAD') {
            $method = 'GET';
        }
        $allowed = false;
        foreach ($this->routes as [$m, $regex, , $handler, $mw]) {
            if (!preg_match($regex, $path, $match)) {
                continue;
            }
            if ($m !== $method) {
                $allowed = true;
                continue;
            }
            if (!in_array('public', $mw, true)) {
                Session::start();
                if ($method === 'POST') {
                    Csrf::check();
                }
            }
            foreach ($mw as $name) {
                $this->middleware($name);
            }
            $args = array_map('urldecode', array_slice($match, 1));
            if (is_array($handler)) {
                [$class, $fn] = $handler;
                (new $class())->$fn(...$args);
            } else {
                $handler(...$args);
            }
            return;
        }
        Session::start();
        abort($allowed ? 405 : 404, $allowed ? 'Mètode no permès.' : 'No s\'ha trobat aquesta pàgina.');
    }

    private function middleware(string $name): void
    {
        switch ($name) {
            case 'auth':
                if (!Auth::check()) {
                    if (wants_json()) {
                        abort(401, 'Cal iniciar sessió.');
                    }
                    $_SESSION['_intended'] = $_SERVER['REQUEST_URI'] ?? '/';
                    redirect('/login');
                }
                break;
            case 'admin':
                if (!Auth::isAdmin()) {
                    abort(403, 'Només les persones administradores hi poden entrar.');
                }
                break;
            case 'edit':
                if (!Auth::canEdit()) {
                    abort(403, 'El teu usuari només pot consultar.');
                }
                break;
            case 'project':
                if (!Context::project()) {
                    if (wants_json()) {
                        abort(409, 'Tria primer un projecte.');
                    }
                    redirect(Auth::isAdmin() ? '/projectes' : '/');
                }
                break;
        }
    }
}
