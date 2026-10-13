<?php

// Router exclusivo para el servidor local de pruebas; no se utiliza en producción.
require_once dirname(__DIR__) . '/app/Support/helpers.php';
loadEnv(dirname(__DIR__) . '/.env');
if (!preg_match('/^sicrat_phase1_test_[a-f0-9]{12}$/', (string) env('DB_DATABASE', ''))) {
    http_response_code(503);
    exit('Las pruebas requieren una base desechable.');
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$public = dirname(__DIR__) . '/public';
if (str_starts_with($path, '/api/') || str_starts_with($path, '/media/')) {
    $_SERVER['SCRIPT_NAME'] = '/api.php';
    require $public . '/api.php';
    return;
}
$file = realpath($public . $path);
if ($file && str_starts_with($file, $public . '/') && is_file($file)) return false;
if ($path === '/') {
    require $public . '/index.php';
    return;
}
$first = explode('/', trim($path, '/'))[0];
if (in_array($first, ['autenticacion', 'users', 'persons', 'organizations', 'sections', 'electoral-process', 'profile', 'polling-booth', 'structure', 'administration', 'services', 'operations', 'resources'], true)) {
    require $public . '/' . $first . '/index.php';
    return;
}
http_response_code(404);
