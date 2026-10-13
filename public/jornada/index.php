<?php

require_once __DIR__.'/../../app/Core/init.php';
if(!$session->verifyUserRights(['resultados.ver','resultados.capturar','resultados.validar','jornada.configurar','jornada.asignar_capturistas'])) $session->denyAccess();
$view=APP_PATH.'/Views/jornada/index.php';require APP_PATH.'/Views/layout/main.php';
