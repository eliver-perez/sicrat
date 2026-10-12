<?php

require_once __DIR__ . '/../../app/Core/init.php';
if (!$session->verifyUserRights(['niveles.administrar','permisos.administrar','modulos.seleccionar'])) $session->denyAccess();
$view = APP_PATH . '/Views/administration/index.php';
require APP_PATH . '/Views/layout/main.php';
