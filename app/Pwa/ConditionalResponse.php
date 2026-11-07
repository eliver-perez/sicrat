<?php

declare(strict_types=1);

namespace App\Pwa;

/** Explicit opt-in, after the normal controller has checked permission and row scope. */
final class ConditionalResponse
{
    public static function eligible(string $path): bool
    {
        $uuid = '[a-fA-F0-9-]{36}';
        return (bool) preg_match(
            '#^/api/(?:persons(?:/' . $uuid . ')?|structures(?:/' . $uuid . '(?:/members/' . $uuid . ')?)?'
            . '|operations/(?:tareas|peticiones|incidencias)(?:/' . $uuid . ')?'
            . '|operations/conversaciones(?:/' . $uuid . '(?:/messages)?)?'
            . '|jornada/(?:booths(?:/' . $uuid . ')?|summary|versions/' . $uuid . '))$#D',
            $path
        );
    }

    public static function apply(string $json, int $status): bool
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        if ($status !== 200 || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
            || ($_SERVER['HTTP_X_SICRAT_OFFLINE'] ?? '') !== '1' || !self::eligible($path)) {
            return false;
        }
        $context = OfflineContext::current();
        $etag = '"' . hash('sha256', $context['profile'] . $context['access'] . $json) . '"';
        header('ETag: ' . $etag);
        header('Vary: Cookie, X-Sicrat-Offline, Accept-Encoding');
        header('X-Sicrat-Profile: ' . $context['profile']);
        header('X-Sicrat-Access: ' . $context['access']);
        header('X-Sicrat-Session: ' . $context['session']);
        header('X-Sicrat-Validated: ' . $context['validated_at']);
        // Keep the HTTP/browser cache out of private data. IndexedDB owns the encrypted copy.
        foreach (explode(',', $_SERVER['HTTP_IF_NONE_MATCH'] ?? '') as $candidate) {
            if (preg_replace('/^W\//', '', trim($candidate)) === $etag) {
                http_response_code(304);
                return true;
            }
        }
        return false;
    }
}
