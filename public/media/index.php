<?php

declare(strict_types=1);

// Entrada para Nginx que reescribe /media/{uuid} a /media/index.php.
// Se conserva el URI original y se despacha por el router autenticado común.
$base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/media/index.php')), '/');
$_SERVER['SCRIPT_NAME'] = $base . '/api.php';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === $base . '/media/index.php' || $path === $base . '/media/') {
    $uuid = $_GET['uuid'] ?? '';
    $_SERVER['REQUEST_URI'] = $base . '/media/' . (is_string($uuid) ? rawurlencode($uuid) : 'invalid');
}
require dirname(__DIR__) . '/api.php';
