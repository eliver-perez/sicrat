<?php

namespace App\Core\Http;

final class HttpResponse
{
    private ?array $json = null;

    public function __construct(
        public readonly int $statusCode,
        public readonly string $body,
        public readonly array $headers = []
    ) {
    }

    public function successful(): bool {
        return $this->statusCode >= 200
            && $this->statusCode < 300;
    }

    public function redirect(): bool {
        return $this->statusCode >= 300
            && $this->statusCode < 400;
    }

    public function clientError(): bool {
        return $this->statusCode >= 400
            && $this->statusCode < 500;
    }

    public function serverError(): bool {
        return $this->statusCode >= 500
            && $this->statusCode < 600;
    }

    public function failed(): bool {
        return !$this->successful();
    }

    public function notFound(): bool {
        return $this->statusCode === 404;
    }

    public function unauthorized(): bool {
        return $this->statusCode === 401;
    }

    public function forbidden(): bool {
        return $this->statusCode === 403;
    }

    public function unprocessable(): bool {
        return $this->statusCode === 422;
    }

    public function tooManyRequests(): bool {
        return $this->statusCode === 429;
    }

    public function internalServerError(): bool {
        return $this->statusCode === 500;
    }

    public function hasHeader(string $name): bool {
        foreach ($this->headers as $headerName => $value) {
            if (strcasecmp($headerName, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $headerName => $value) {
            if (strcasecmp($headerName, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }

        if ($this->body === '') {
            return $this->json = [];
        }

        try {
            $data = json_decode(
                $this->body,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new HttpException(
                'La respuesta HTTP no contiene un JSON válido.',
                previous: $exception
            );
        }

        if (!is_array($data)) {
            throw new HttpException(
                'La respuesta HTTP no contiene un objeto o arreglo JSON.'
            );
        }

        return $this->json = $data;
    }
}