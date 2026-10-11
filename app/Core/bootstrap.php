<?php

declare(strict_types=1);

use App\Auth\Session;
use App\Core\Response;
use App\Core\Security\EncryptionService;

define('BASE_PATH', dirname(__DIR__, 2));
define('APP_PATH', BASE_PATH . '/app');
define('PUBLIC_PATH', BASE_PATH . '/public');
define('STORAGE_PATH', BASE_PATH . '/storage');

require BASE_PATH . '/vendor/autoload.php';
require_once APP_PATH . '/Support/helpers.php';

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $exception): void {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
    if (str_contains($path, '/api/')) {
        (new Response())->exception($exception);
    } else {
        error_log((string) $exception);
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo 'No fue posible cargar la página. Inténtalo de nuevo.';
    }
});

loadEnv(BASE_PATH . '/.env');
date_default_timezone_set('America/Mexico_City');
$config = require __DIR__ . '/config.php';
$encryptionService = new EncryptionService(env('APP_ENCRYPTION_KEY', ''));
Session::start();
