<?php

declare(strict_types=1);

namespace App\Core;

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

    public function get(string $pattern, callable|array $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable|array $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function put(string $pattern, callable|array $handler): void
    {
        $this->add('PUT', $pattern, $handler);
    }

    public function patch(string $pattern, callable|array $handler): void
    {
        $this->add('PATCH', $pattern, $handler);
    }

    public function delete(string $pattern, callable|array $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    private function add(string $method, string $pattern, callable|array $handler): void
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

            $this->executeHandler($route['handler'], $params);
            return;
        }

        // $response = new Response();
        // $response->error('Ruta no encontrada', 404);
        $this->response->error('Ruta no encontrada', 404);
    }

    private function executeHandler(array $handler, array $routeParams = []): void {
        [$controllerClass, $method] = $handler;

        $controller = new $controllerClass();

        $arguments = array_merge(
            [$this->request, $this->response],
            array_values($routeParams)
        );

        call_user_func_array([$controller, $method], $arguments);
    }
}