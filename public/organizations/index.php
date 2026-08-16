<?php
	require_once __DIR__.'/../../app/Core/init.php';

	if(!$session->verifyUserRights(['superadmin'])) {
		$session->denyAccess();
	}

	$uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
	$segments = explode('/', $uri);

	$index = array_search('organizations', $segments);

	$action = $segments[$index + 1] ?? null;
	$id     = $segments[$index + 1] ?? null;
	$next   = $segments[$index + 2] ?? null;

	// default
	$view = APP_PATH . '/Views/organizations/index.php';

	if (is_numeric($action)) {
		$id = $action;
		$view = APP_PATH . '/Views/organizations/view.php';

	} elseif ($action === 'add') {
		// /organizations/add
		$view = APP_PATH . '/Views/organizations/add.php';

	} elseif ($action === 'edit' && is_numeric($next)) {
		// /organizations/123/edit
		$id = $next;
		$view = APP_PATH . '/Views/organizations/edit.php';
	}

	require APP_PATH . '/Views/layout/main.php';