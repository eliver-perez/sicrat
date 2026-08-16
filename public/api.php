<?php

declare(strict_types=1);

use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Security\EncryptionService;

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('PUBLIC_PATH', BASE_PATH . '/public');
define('STORAGE_PATH', BASE_PATH . '/storage');

require BASE_PATH . '/vendor/autoload.php';

loadEnv(BASE_PATH . '/.env');

/**
 * Encryption data
 */
$encryptionKey = env('APP_ENCRYPTION_KEY');

if (!is_string($encryptionKey) || trim($encryptionKey) === '') {
    throw new RuntimeException(
        'No se encontró APP_ENCRYPTION_KEY.'
    );
}

$encryptionService = new EncryptionService(
    $encryptionKey
);

date_default_timezone_set('America/Mexico_City');

$appDebug = filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOL);

ini_set('display_errors', $appDebug ? '1' : '0');
ini_set('display_startup_errors', $appDebug ? '1' : '0');
error_reporting(E_ALL);

$request = new Request();
$response = new Response();
$router = new Router($request, $response);

require APP_PATH . '/Routes/api.php';

// $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
// $uri = $_SERVER['REQUEST_URI'] ?? '/';
$method = $request->method();
$uri = $request->uri();
// die($uri);
$router->dispatch($method, $uri);

function loadEnv(string $path): void
{
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

function env(string $key, mixed $default = null): mixed
{
    return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
}