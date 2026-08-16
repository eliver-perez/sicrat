<?php
	require_once __DIR__.'/../../app/Core/init.php';

	if(!$session->verifyUserRights(['superadmin'])) {
		$session->denyAccess();
	}

	$uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
	$segments = explode('/', $uri);

	$index = array_search('electoral-process', $segments);

	$action = $segments[$index + 1] ?? null;
	$id     = $segments[$index + 1] ?? null;
	$next   = $segments[$index + 2] ?? null;

	// default
	$view = APP_PATH . '/Views/electoral-process/index.php';

	if (is_numeric($action)) {
		$id = $action;
		$view = APP_PATH . '/Views/electoral-process/view.php';

	} elseif ($action === 'add') {
		// /electoral-process/add
		$view = APP_PATH . '/Views/electoral-process/add.php';

	} elseif ($action === 'edit' && is_numeric($next)) {
		// /electoral-process/123/edit
		$id = $next;
		$view = APP_PATH . '/Views/electoral-process/edit.php';
	}

	require APP_PATH . '/Views/layout/main.php';