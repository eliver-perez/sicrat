<?php

declare(strict_types=1);

namespace App\Core;

class Response
{
    public function json(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

    public function noContent(): void
    {
        http_response_code(204);
    }
}