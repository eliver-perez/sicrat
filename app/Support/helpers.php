<?php
function loadEnv(string $path): void {
    if (!file_exists($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (!str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);

        $name = trim($name);
        $value = trim($value);

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

function env(string $key, mixed $default = null): mixed {
    return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
}

function config(string $key, mixed $default = null): mixed {
    static $config = null;

    if ($config === null) {
        $config = require __DIR__ . '/../Core/config.php';
    }

    return $config[$key] ?? $default;
}

function base_url(string $path = ''): string {
    $base = rtrim((string) env('APP_URL', config('url')), '/');

    return $path === ''
        ? $base
        : $base . '/' . ltrim($path, '/');
}

function base_path(string $path = ''): string {
    $base = rtrim((string) config('path'), DIRECTORY_SEPARATOR);

    return $path === ''
        ? $base
        : $base . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
}

function asset(string $path): string
{
    $base = rtrim(env('APP_URL', ''), '/');
    return $base . '/assets/' . ltrim($path, '/');
}

function is_uuid(mixed $value): bool {
    return is_string($value)
        && preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value
        ) === 1;
}

function moduleEnabled(array $modules, string $module) : ?stdClass {
    foreach($modules as $m) {
        if($m->code === $module)
            return $m;
    }
    return null;
}