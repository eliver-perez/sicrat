<?php
	require_once __DIR__.'/../../app/Core/init.php';

	if(!$session->verifyUserRights(['superadmin']) && !$session->verifyUserRights(['captura-personas'])) {
		$session->denyAccess();
	}

	$uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
	$segments = explode('/', $uri);

	$index = array_search('profile', $segments);

	$action = $segments[$index + 1] ?? null;
	$id     = $segments[$index + 1] ?? null;
	$next   = $segments[$index + 2] ?? null;

	// default
	$view = APP_PATH . '/Views/profile/index.php';

	if (is_uuid($action)) {
		$id = $action;
		$view = APP_PATH . '/Views/profile/index.php';
	} else {
		header('Location: '.base_url('?callBack=missingInfo'));
	}

	require APP_PATH . '/Views/layout/main.php';