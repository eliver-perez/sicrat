<?php
	require_once __DIR__.'/../../app/Core/init.php';

	if(!$session->verifyUserRights(['casillas.ver','casillas.administrar'])) {
		$session->denyAccess();
	}

	$view = APP_PATH . '/Views/polling-booth/index.php';
	require APP_PATH . '/Views/layout/main.php';
