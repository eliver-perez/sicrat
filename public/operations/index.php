<?php

require_once __DIR__.'/../../app/Core/init.php';
$operationEntity=$_GET['module'] ?? 'interacciones';
if (!is_string($operationEntity) || !isset(\App\Services\Operations\Catalog::ENTITIES[$operationEntity])) { http_response_code(404); exit('Módulo no disponible.'); }
$operationDefinition=\App\Services\Operations\Catalog::entity($operationEntity);
$operationRights=$operationEntity==='encuestas' ? ['encuestas.ver','encuestas.crear','encuestas.aplicar','encuestas.ver_resultados','encuestas.corregir','encuestas.administrar','encuestas.aprobar'] : [$operationDefinition['permission'].'.ver'];
if (!$session->verifyUserRights($operationRights)) $session->denyAccess();
if (\App\Core\Auth::processId()) {
	try { (new \App\Repositories\ModuleRepository((new \App\Core\Database())->getConnection()))->require($operationDefinition['module'],\App\Core\Auth::processId()); }
	catch (\App\Core\HttpError) { $session->denyAccess(); }
}
$view=APP_PATH.'/Views/operations/index.php';
require APP_PATH.'/Views/layout/main.php';
