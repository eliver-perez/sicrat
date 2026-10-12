<?php

require_once __DIR__.'/../../app/Core/init.php';
// Toda cuenta autenticada recibe sus notificaciones; cada sección administrativa comprueba sus permisos.
if (\App\Core\Auth::id() === null) $session->denyAccess();
$view=APP_PATH.'/Views/services/index.php';
require APP_PATH.'/Views/layout/main.php';
