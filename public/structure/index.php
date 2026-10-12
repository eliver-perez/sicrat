<?php

require_once __DIR__ . '/../../app/Core/init.php';
if (!$session->verifyUserRights(['estructura.ver'])) $session->denyAccess();
if (\App\Core\Auth::processId()) {
    try { (new \App\Repositories\ModuleRepository((new \App\Core\Database())->getConnection()))->require('estructura',\App\Core\Auth::processId()); }
    catch (\App\Core\HttpError) { $session->denyAccess(); }
}
$view = APP_PATH . '/Views/structure/index.php';
require APP_PATH . '/Views/layout/main.php';
