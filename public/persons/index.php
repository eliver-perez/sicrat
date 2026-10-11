<?php
	require_once __DIR__.'/../../app/Core/init.php';

	if(!$session->verifyUserRights(['superadmin']) && !$session->verifyUserRights(['captura-personas'])) {
		$session->denyAccess();
	}

	$view = APP_PATH . '/Views/persons/index.php';
	require APP_PATH . '/Views/layout/main.php';
