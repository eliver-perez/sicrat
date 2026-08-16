<?php
	require_once __DIR__.'/../../app/Core/init.php';

	if(!$session->verifyUserRights(['superadmin'])) {
		$session->denyAccess();
	}

	$uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
	$segments = explode('/', $uri);

	$index = array_search('polling-booth', $segments);

	$action = $segments[$index + 1] ?? null;
	$id     = $segments[$index + 1] ?? null;
	$next   = $segments[$index + 2] ?? null;

	// default
	$view = APP_PATH . '/Views/polling-booth/index.php';

	if (is_numeric($action)) {
		$id = $action;
		$view = APP_PATH . '/Views/polling-booth/view.php';

	} elseif ($action === 'add') {
		$view = APP_PATH . '/Views/polling-booth/add.php';

	} elseif ($action === 'edit' && is_numeric($next)) {
		$id = $next;
		$view = APP_PATH . '/Views/polling-booth/edit.php';
	}

	require APP_PATH . '/Views/layout/main.php';