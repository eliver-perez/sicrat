<?php

require_once __DIR__.'/../../app/Core/init.php';
$resourceModule=$_GET['module'] ?? 'finanzas'; if(!in_array($resourceModule,['finanzas','inventario'],true)) { http_response_code(404); exit('Módulo no disponible.'); }
$rights=$resourceModule==='finanzas' ? ['finanzas.ver','finanzas.presupuestos.ver','finanzas.ingresos.ver','finanzas.reembolsos.solicitar'] : ['inventario.ver'];
if(!$session->verifyUserRights($rights)) $session->denyAccess();
$view=APP_PATH.'/Views/resources/index.php'; require APP_PATH.'/Views/layout/main.php';
