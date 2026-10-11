<?php

declare(strict_types=1);

namespace App\Core;

use App\Auth\Authorization;
use App\Core\Security\Csrf;
use App\Middleware\AuthMiddleware;

class Router
{
    private Request $request;
    private Response $response;
    private array $routes = [];

    public function __construct(Request $request, Response $response)
    {
        $this->request = $request;
        $this->response = $response;
    }

    public function get(string $pattern, callable|array $handler, string $operation = 'authenticated'): void
    {
        $this->add('GET', $pattern, $handler, $operation);
    }

    public function post(string $pattern, callable|array $handler, string $operation = 'authenticated'): void
    {
        $this->add('POST', $pattern, $handler, $operation);
    }

    public function put(string $pattern, callable|array $handler, string $operation = 'authenticated'): void
    {
        $this->add('PUT', $pattern, $handler, $operation);
    }

    public function patch(string $pattern, callable|array $handler, string $operation = 'authenticated'): void
    {
        $this->add('PATCH', $pattern, $handler, $operation);
    }

    public function delete(string $pattern, callable|array $handler, string $operation = 'authenticated'): void
    {
        $this->add('DELETE', $pattern, $handler, $operation);
    }

    private function add(string $method, string $pattern, callable|array $handler, string $operation = 'authenticated'): void
    {
        $paramNames = [];

        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            function ($matches) use (&$paramNames) {
                $paramNames[] = $matches[1];
                return '([^/]+)';
            },
            $pattern
        );

        $regex = '#^' . $regex . '$#';

        $this->routes[$method][] = [
            'pattern'    => $pattern,
            'regex'      => $regex,
            'paramNames' => $paramNames,
            'handler'    => $handler,
            'operation'  => $operation,
        ];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';

        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

        if ($basePath !== '' && $basePath !== '/' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }

        if ($path === '' || $path === false) {
            $path = '/';
        }

        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        $routes = $this->routes[$method] ?? [];

        foreach ($routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }

            array_shift($matches);

            $params = [];
            foreach ($route['paramNames'] as $index => $name) {
                $params[$name] = $matches[$index] ?? null;
            }

            try {
                if ($route['operation'] !== 'public') {
                    if (!(new AuthMiddleware())->handle($this->request, $this->response)) {
                        return;
                    }
                    Authorization::requireOperation($route['operation']);
                }
                if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
                    Csrf::validate($this->request);
                }
                $this->request->setRouteParams($params);
                $this->executeHandler($route['handler'], $params);
            } catch (\Throwable $exception) {
                $this->response->exception($exception);
            }
            return;
        }

        // $response = new Response();
        // $response->error('Ruta no encontrada', 404);
        $this->response->error('Ruta no encontrada', 404);
    }

    private function executeHandler(callable|array $handler, array $routeParams = []): void {
        if (!is_array($handler)) {
            $handler($this->request, $this->response, ...array_values($routeParams));
            return;
        }
        [$controllerClass, $method] = $handler;

        if (!method_exists($controllerClass, $method)) {
            throw new HttpError('Ruta no disponible.', 404);
        }
        $controller = new $controllerClass();
        $controller->setRouteParams($routeParams);

        $arguments = array_merge(
            [$this->request, $this->response],
            array_values($routeParams)
        );

        call_user_func_array([$controller, $method], $arguments);
    }
}