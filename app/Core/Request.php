<?php

declare(strict_types=1);

namespace App\Core;

class Request
{
    private string $method;
    private string $uri;
    private array $queryParams;
    private array $bodyParams;
    private array $headers;
    private array $routeParams = [];
    private array $server;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->uri = $_SERVER['REQUEST_URI'] ?? '/';
        $this->queryParams = $_GET ?? [];
        $this->headers = $this->parseHeaders();
        $this->bodyParams = $this->parseBody();
        $this->server = $_SERVER ?? [];

        if ($this->method === 'POST' && isset($this->bodyParams['_method'])) {
            $this->method = strtoupper((string)$this->bodyParams['_method']);
        }
    }

    public function method(): string
    {
        return $this->method;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function path(): string
    {
        return parse_url($this->uri, PHP_URL_PATH) ?? '/';
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->queryParams;
        }

        return $this->queryParams[$key] ?? $default;
    }

    public function queryInt(string $key, ?int $default = null): ?int
    {
        $value = $this->query($key);

        if ($value === null || $value === '') {
            return $default;
        }

        return (int) $value;
    }

    public function input(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->bodyParams;
        }

        return $this->bodyParams[$key] ?? $default;
    }

    public function file(string $key): ?array
    {
        return $_FILES[$key] ?? null;
    }

    public function all(): array
    {
        return array_merge($this->queryParams, $this->bodyParams);
    }

    public function header(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->headers;
        }

        $normalizedKey = strtolower($key);
        return $this->headers[$normalizedKey] ?? $default;
    }

    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function route(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->routeParams;
        }

        return $this->routeParams[$key] ?? $default;
    }

    public function server(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->server;
        }

        return $this->server[$key] ?? $default;
    }

    public function isJson(): bool
    {
        $contentType = $this->header('Content-Type', '');
        return str_contains(strtolower((string)$contentType), 'application/json');
    }

    private function parseBody(): array
    {
        $raw = file_get_contents('php://input');

        if ($raw === false || trim($raw) === '') {
            return $_POST ?? [];
        }

        if ($this->isJson()) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }

        if (in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $data = [];
            parse_str($raw, $data);

            if (!empty($data)) {
                return $data;
            }
        }

        return $_POST ?? [];
    }

    private function parseHeaders(): array
    {
        $headers = [];

        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $key => $value) {
                $headers[strtolower($key)] = $value;
            }
            return $headers;
        }

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $header = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$header] = $value;
            }
        }

        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }

        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = $_SERVER['CONTENT_LENGTH'];
        }

        return $headers;
    }
}