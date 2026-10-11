<?php

declare(strict_types=1);

namespace App\Core;

class Response
{
    public function json(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    public function success(
        mixed $data = null,
        string $message = 'OK',
        int $statusCode = 200
    ): void {
        $response = [
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ];

        $this->json($response, $statusCode);
    }

    public function error(
        string $message = 'Error',
        int $statusCode = 400,
        ?array $errors = null,
        mixed $data = null
    ): void {
        $response = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        if ($data !== null) {
            $response['data'] = $data;
        }

        $this->json($response, $statusCode);
    }

    public function exception(\Throwable $exception): void
    {
        if ($exception instanceof HttpError) {
            $this->error($exception->getMessage(), $exception->statusCode);
        } elseif ($exception instanceof \InvalidArgumentException) {
            $this->error($exception->getMessage(), 422);
        } else {
            // Los detalles técnicos permanecen en el registro PHP, nunca en la respuesta.
            error_log((string) $exception);
            $this->error('No fue posible completar la operación. Inténtalo de nuevo.', 500);
        }
    }

    public function noContent(): void
    {
        http_response_code(204);
    }
}