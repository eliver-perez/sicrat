<?php

namespace App\Core\Http;

final class HttpClient
{
    public static function get(
        string $url,
        array $options = []
    ): HttpResponse {
        return self::request('GET', $url, $options);
    }

    public static function post(
        string $url,
        array $options = []
    ): HttpResponse {
        return self::request('POST', $url, $options);
    }

    public static function put(
        string $url,
        array $options = []
    ): HttpResponse {
        return self::request('PUT', $url, $options);
    }

    public static function delete(
        string $url,
        array $options = []
    ): HttpResponse {
        return self::request('DELETE', $url, $options);
    }

    public static function request(
        string $method,
        string $url,
        array $options = []
    ): HttpResponse {
        $curl = curl_init();

        if (!$curl instanceof \CurlHandle) {
            throw new HttpException(
                'No fue posible inicializar cURL.'
            );
        }

        $headers = self::normalizeHeaders(
            $options['headers'] ?? []
        );

        $responseHeaders = [];

        $curlOptions = [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $options['connect_timeout'] ?? 10,
            CURLOPT_TIMEOUT        => $options['timeout'] ?? 30,
            CURLOPT_HTTPHEADER     => $headers,

            CURLOPT_HEADERFUNCTION => static function (
                \CurlHandle $curl,
                string $header
            ) use (&$responseHeaders): int {
                $length = strlen($header);
                $header = trim($header);

                /*
                * Ignora líneas vacías y la línea de estado:
                * HTTP/2 200
                */
                if (
                    $header === '' ||
                    !str_contains($header, ':')
                ) {
                    return $length;
                }

                [$name, $value] = explode(':', $header, 2);

                $responseHeaders[trim($name)] = trim($value);

                return $length;
            },
        ];

        if (array_key_exists('json', $options)) {
            $curlOptions[CURLOPT_POSTFIELDS] = self::encodeJson(
                $options['json']
            );

            if (!self::hasHeader($headers, 'Content-Type')) {
                $curlOptions[CURLOPT_HTTPHEADER][] =
                    'Content-Type: application/json';
            }
        } elseif (array_key_exists('body', $options)) {
            $curlOptions[CURLOPT_POSTFIELDS] = $options['body'];
        }

        if (!curl_setopt_array($curl, $curlOptions)) {
            throw new HttpException(
                'No fue posible configurar la solicitud HTTP.'
            );
        }

        $body = curl_exec($curl);

        if ($body === false) {
            $errorCode = curl_errno($curl);
            $error = curl_error($curl);

            throw new HttpException(
                "Error HTTP {$errorCode}: {$error}",
                $errorCode
            );
        }

        $statusCode = (int) curl_getinfo(
            $curl,
            CURLINFO_HTTP_CODE
        );

        return new HttpResponse(
            statusCode: $statusCode,
            body: (string) $body,
            headers: $responseHeaders
        );
    }

    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (is_int($name)) {
                $normalized[] = $value;
                continue;
            }

            $normalized[] = "{$name}: {$value}";
        }

        return $normalized;
    }

    private static function hasHeader(
        array $headers,
        string $headerName
    ): bool {
        $headerName = strtolower($headerName);

        foreach ($headers as $header) {
            $currentName = strtolower(
                trim(strtok($header, ':'))
            );

            if ($currentName === $headerName) {
                return true;
            }
        }

        return false;
    }

    private static function encodeJson(mixed $data): string
    {
        try {
            return json_encode(
                $data,
                JSON_THROW_ON_ERROR |
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException(
                'No fue posible convertir los datos a JSON.',
                previous: $exception
            );
        }
    }
}