<?php

declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    protected Request $request;
    protected Response $response;

    public function __construct()
    {
        $this->request = new Request();
        $this->response = new Response();
    }

    public function setRouteParams(array $params): void
    {
        $this->request->setRouteParams($params);
    }

    protected function json(array $data, int $statusCode = 200): void
    {
        $this->response->json($data, $statusCode);
    }

    protected function success(
        mixed $data = null,
        string $message = 'OK',
        int $statusCode = 200
    ): void {
        $this->response->success($data, $message, $statusCode);
    }

    protected function error(
        string $message = 'Error',
        int $statusCode = 400,
        ?array $errors = null,
        mixed $data = null
    ): void {
        $this->response->error($message, $statusCode, $errors, $data);
    }

    protected function noContent(): void
    {
        $this->response->noContent();
    }
}