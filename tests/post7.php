<?php
// Se ejecuta únicamente en la BD/servidor desechables de integration.php --post7.
$revisionStart=$checks;
function revisionApi(string $path,string $method='GET',?array $data=null,string $client='revision-admin',int $status=200): array {
    $response=phase3Api($path,$method,$data,$client);verify($response['status']===$status || ($status===200 && $response['status']===201),$method.' '.$path.' esperaba '.$status.': '.$response['body']);return $response['json']['data'] ?? [];
}
phase3Ok('/api/auth/election','POST',['process'=>$phase7Election]);
phase3Status(409,'/api/sections/'.$phase7Section,'PUT',['section'=>9999]);
$historicBooth=App\Services\Common\Values::uuidText($phase5Repository->one('SELECT c.uuid FROM casillas c JOIN jornada_casillas jc ON jc.casilla=c.id WHERE jc.uuid=?',[App\Services\Common\Values::binary($phase7Booths[0])])['uuid']);
phase3Status(409,'/api/polling-booths/'.$historicBooth,'PUT',['name'=>'Cambio de una casilla con acta']);
$revisionElection=phase3Ok('/api/organizations/'.$organizationIds[0].'/electoral-process','POST',['process'=>'Revisión post Fase 7','type'=>1,'character'=>1])['id'];
$revisionOther=phase3Ok('/api/organizations/'.$organizationIds[0].'/electoral-process','POST',['process'=>'Otra elección de revisión','type'=>1,'character'=>1])['id'];
foreach(['estructura','interacciones','encuestas','tareas_metas','eventos','peticiones_incidencias','transporte','mensajeria','finanzas','inventario'] as $module)phase3Ok('/api/organizations/'.$organizationIds[0].'/modules/'.$module,'PUT',['authorized'=>1,'selected'=>1]);
$revisionUser=phase3Ok('/api/users','POST',['organization'=>$organizationIds[0],'username'=>'revision-admin','password'=>'Revision local segura','type'=>2,'name'=>'Administración de revisión'])['id'];
phase3Ok('/api/users/'.$revisionUser.'/accesses','POST',['process'=>$revisionElection,'type'=>2]);
$phase3Tokens['revision-admin']=login('revision-admin','revision-admin','Revision local segura');revisionApi('/api/auth/election','POST',['process'=>$revisionElection]);
$me=revisionApi('/api/auth/me');foreach(['secciones.ver','secciones.administrar','casillas.ver','casillas.administrar','peticiones.categorias','incidencias.categorias'] as $right)verify(in_array($right,$me['rights'],true),'Plantilla Administrador hereda '.$right);
verify(revisionApi('/api/sections')['sections']===[],'Una elección nueva no hereda el catálogo de otra elección.');
$revisionSection=revisionApi('/api/sections','POST',['section'=>7201,'state'=>1,'municipality'=>1])['id'];
verify(count(revisionApi('/api/sections')['sections'])===1,'Consulta de sección propia.');
verify(revisionApi('/api/sections/'.$revisionSection,'PUT',['section'=>7202])['seccion']===7202,'Edición real de sección por administrador electoral.');
$revisionBooth=revisionApi('/api/polling-booths','POST',['section'=>$revisionSection,'name'=>'Básica revisión','type'=>'BASICA','location'=>'Escuela de prueba'])['uuid'];
verify(revisionApi('/api/polling-booths/'.$revisionBooth,'PUT',['name'=>'Básica editada'])['casilla']==='Básica editada','Edición real de casilla.');
verify(count(revisionApi('/api/polling-booths?limit=1')['rows'])===1,'Casillas con paginación.');
$revisionPerson=revisionApi('/api/persons','POST',['organization'=>$organizationIds[0],'name'=>'Persona revisión','section'=>$revisionSection])['id'];revisionApi('/api/persons/'.$revisionPerson.'/participations','POST',['processes'=>[$revisionElection]]);
phase3Ok('/api/auth/election','POST',['process'=>$revisionOther]);
$revisionForeignSection=phase3Ok('/api/sections','POST',['section'=>7203,'state'=>1,'municipality'=>1])['id'];
$revisionForeignBooth=phase3Ok('/api/polling-booths','POST',['section'=>$revisionForeignSection,'name'=>'Ajena','type'=>'BASICA'])['uuid'];
revisionApi('/api/sections/'.$revisionForeignSection,'GET',null,'revision-admin',404);revisionApi('/api/sections/'.$revisionForeignSection,'PUT',['section'=>7204],'revision-admin',404);
revisionApi('/api/polling-booths/'.$revisionForeignBooth,'GET',null,'revision-admin',404);revisionApi('/api/polling-booths/'.$revisionForeignBooth,'PUT',['name'=>'Intrusión'],'revision-admin',404);
revisionApi('/api/polling-booths','POST',['section'=>$revisionForeignSection,'name'=>'Intrusión','type'=>'BASICA'],'revision-admin',404);
revisionApi('/api/persons','POST',['organization'=>$organizationIds[0],'name'=>'Intrusión por persona','section'=>$revisionForeignSection],'revision-admin',404);
verify(count(revisionApi('/api/sections?search=7203')['sections'])===0,'Búsqueda no revela sección ajena.');
revisionApi('/api/users/'.$revisionUser.'/territories','PUT',['sections'=>[$revisionSection]]);
revisionApi('/api/users/'.$revisionUser.'/territories','PUT',['sections'=>[$revisionForeignSection]],'revision-admin',404);
$revisionLevel=revisionApi('/api/structure-levels','POST',['name'=>'Nivel revisión','order'=>27,'can_recruit'=>1,'same_level'=>0])['id'];
foreach([0,1] as $active){revisionApi('/api/structure-levels/'.$revisionLevel,'PUT',['name'=>'Nivel editado','order'=>27,'active'=>$active,'can_recruit'=>0,'same_level'=>1]);$levels=revisionApi('/api/structure-levels');$level=array_values(array_filter($levels,static fn($row)=>$row['id']===$revisionLevel))[0];verify($level['active']===$active && $level['same_level']===1,'Editar y activar/desactivar nivel conserva configuración.');}
$otherLevel=phase3Ok('/api/structure-levels','POST',['name'=>'Nivel ajeno','order'=>29])['id'];revisionApi('/api/structure-levels/'.$otherLevel,'PUT',['name'=>'Intrusión','order'=>29],'revision-admin',404);
revisionApi('/api/structure-levels?election='.$revisionOther,'GET',null,'revision-admin',404);
phase3Grant($revisionUser,'estructura.ver',$revisionElection,'eleccion',0);revisionApi('/api/structure-levels');phase3Grant($revisionUser,'estructura.ver',$revisionElection,'eleccion',1);
$revisionCategories=[];
foreach(['peticiones','incidencias'] as $kind){
 $path='/api/operations/case-categories/'.$kind;
 $category=revisionApi($path,'POST',['name'=>'Limpieza revisión'])['uuid'];$revisionCategories[$kind]=$category;
 revisionApi($path,'POST',['name'=>'LIMPIEZA REVISIÓN'],'revision-admin',409);
 revisionApi($path.'/'.$category,'PUT',['name'=>'Servicios urbanos','active'=>0]);
 verify(!in_array($category,array_column(revisionApi($path.'?active=1')['rows'],'uuid'),true),'Categoría inactiva fuera del selector.');
 revisionApi('/api/operations/'.$kind,'POST',['category_id'=>$category,'description'=>'Intento con categoría inactiva'],'revision-admin',422);
 revisionApi($path.'/'.$category,'PUT',['name'=>'Servicios urbanos','active'=>1]);
 $record=revisionApi('/api/operations/'.$kind,'POST',['category_id'=>$category,'description'=>'Registro de revisión','person'=>$revisionPerson])['uuid'];
 $detail=revisionApi('/api/operations/'.$kind.'/'.$record);verify($detail['categoria_uuid']===$category,'Registro conserva referencia al catálogo.');
 revisionApi('/api/operations/'.$kind.'/'.$record,'PUT',['state'=>'en_atencion','reason'=>'Seguimiento inicial','version'=>$detail['version']]);
 $history=revisionApi('/api/operations/'.$kind.'/'.$record.'/history');verify((bool)array_filter($history['rows'],static fn($row)=>str_contains($row['nota'],'Estado cambiado de Abierta a En atención') && str_contains($row['nota'],'Seguimiento inicial')),'Historial registra transición y conserva comentario.');
 revisionApi($path.'/'.$category,'PUT',['name'=>'Servicios urbanos editados','active'=>0]);
 $detail=revisionApi('/api/operations/'.$kind.'/'.$record);revisionApi('/api/operations/'.$kind.'/'.$record,'PUT',['description'=>'Registro editado sin borrar categoría histórica','version'=>$detail['version']]);
 verify(revisionApi('/api/operations/'.$kind.'/'.$record)['categoria_uuid']===$category,'Edición retiene categoría histórica inactiva.');
 revisionApi($path.'/'.$category,'PUT',['name'=>'Servicios urbanos','active'=>1]);
 $foreign=phase3Ok($path,'POST',['name'=>'Catálogo ajeno'])['uuid'];revisionApi($path.'/'.$foreign,'PUT',['name'=>'Intrusión'],'revision-admin',404);
 revisionApi('/api/operations/'.$kind,'POST',['category_id'=>$foreign,'description'=>'Intrusión por categoría'],'revision-admin',422);
}
revisionApi('/api/operations/incidencias','POST',['category_id'=>$revisionCategories['peticiones'],'description'=>'Tipo incorrecto'],'revision-admin',422);
$revisionProcess=(int)$phase5Repository->one('SELECT id FROM procesos_electorales WHERE uuid=?',[App\Services\Common\Values::binary($revisionElection)])['id'];
$revisionNotice=(new App\Services\Common\NotificationService($phase5Repository))->internal(1,1,$revisionProcess,'revision','Aviso de revisión','Prueba del header con enlace real',null,null,'/sections/');
foreach(['finanzas.ingresos.crear'=>'ingreso','finanzas.gastos.crear'=>'gasto','finanzas.presupuestos.administrar'=>'gasto'] as $permission=>$type){$options=revisionApi('/api/resources/options?tipo=categorias&permiso='.$permission);foreach($options['rows'] as $option)verify($phase5Repository->one('SELECT tipo FROM finanzas_categorias WHERE uuid=?',[App\Services\Common\Values::binary($option['uuid'])])['tipo']===$type,'Selector financiero filtra categorías de '.$type);}
$summary=revisionApi('/api/notifications/summary');verify(isset($summary['unread']) || isset($summary['unread_count']),'Resumen propio de notificaciones.');
phase3Grant($revisionUser,'casillas.administrar',$revisionElection,'eleccion',0);revisionApi('/api/polling-booths','POST',['section'=>$revisionSection,'name'=>'Revocada','type'=>'BASICA'],'revision-admin',403);phase3Grant($revisionUser,'casillas.administrar',$revisionElection,'eleccion',1);
phase3Ok('/api/auth/election','POST',['process'=>$revisionElection]);
file_put_contents($temporaryDirectory.'/post7-fixtures.json',json_encode(['election'=>$revisionElection,'other'=>$revisionOther,'user'=>$revisionUser,'person'=>$revisionPerson,'section'=>$revisionSection,'booth'=>$revisionBooth,'categories'=>$revisionCategories,'notice'=>$revisionNotice],JSON_PRETTY_PRINT));
require __DIR__.'/post7-resume.php';
echo 'Revisión post Fase 7: '.($checks-$revisionStart)." comprobaciones adicionales correctas.\n";
