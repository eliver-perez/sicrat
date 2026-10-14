<?php
	$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '';
	if (preg_match('#/(?:api|media)(?:/|$)#', $requestPath)) { require __DIR__.'/api.php'; exit; }
	require_once __DIR__.'/../app/Core/init.php';

	$view = APP_PATH . '/Views/dashboard/index.php';
	require APP_PATH . '/Views/layout/main.php';
?>