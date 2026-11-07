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
// Upgrade fixture, available only behind this router's disposable-database guard.
if (in_array($path, ['/service-worker.js', '/pwa-assets.js'], true) && env('PWA_TEST_DIRECTORY')) {
    $revisionFile = env('PWA_TEST_DIRECTORY') . '/pwa-revision';
    $revision = is_file($revisionFile) ? trim(file_get_contents($revisionFile)) : '1';
    if (!preg_match('/^[1-9]$/D', $revision)) throw new RuntimeException('Invalid test revision.');
    $source = file_get_contents($public . $path);
    if ($path === '/pwa-assets.js') $source = preg_replace('/(const SICRAT_STATIC_VERSION = "[a-f0-9]+)(")/', '$1-test-' . $revision . '$2', $source);
    header('Content-Type: application/javascript');
    header('Cache-Control: no-store');
    echo $source . "\n// Disposable PWA test revision " . $revision;
    return;
}
if(str_starts_with($path,'/media/')) {$_SERVER['SCRIPT_NAME']='/media/index.php';require $public.'/media/index.php';return;}
if (str_starts_with($path, '/api/')) {
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
if (in_array($first, ['autenticacion', 'users', 'persons', 'organizations', 'sections', 'electoral-process', 'profile', 'polling-booth', 'structure', 'administration', 'services', 'operations', 'resources', 'jornada'], true)) {
    require $public . '/' . $first . '/index.php';
    return;
}
http_response_code(404);
