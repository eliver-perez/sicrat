<?php
	require_once __DIR__.'/../../app/Core/init.php';

	if(!$session->verifyUserRights(['usuarios.administrar'])) {
		$session->denyAccess();
	}

	$view = APP_PATH . '/Views/users/index.php';
	require APP_PATH . '/Views/layout/main.php';
