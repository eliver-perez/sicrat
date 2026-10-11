<?php
	require_once __DIR__.'/../../app/Core/init.php';

	if(!$session->verifyUserRights(['superadmin'])) {
		$session->denyAccess();
	}

	$view = APP_PATH . '/Views/sections/index.php';
	require APP_PATH . '/Views/layout/main.php';
