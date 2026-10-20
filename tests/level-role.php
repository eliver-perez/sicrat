<?php
// Integración sobre la base desechable de integration.php; asociaciones explícitas, nunca inferidas.
$levelRoleStart=$checks;
$lrElection=phase3Ok('/api/organizations/'.$organizationIds[0].'/electoral-process','POST',['process'=>'Relación Nivel Rol','type'=>1,'character'=>1,'status'=>1])['id'];
phase3Ok('/api/auth/election','POST',['process'=>$lrElection]);
$lrRoles=[];
foreach(['general'=>'Rol General prueba','zona'=>'Rol Zona prueba','manual'=>'Rol Manual prueba'] as $key=>$name)$lrRoles[$key]=phase3Ok('/api/roles','POST',['name'=>$name])['id'];
foreach(['general'=>['personas.ver','personas.editar'],'zona'=>['personas.ver']] as $key=>$rights)foreach($rights as $right)phase3Ok('/api/roles/'.$lrRoles[$key].'/permissions','PUT',['permission'=>$right,'value'=>1,'scope'=>'eleccion','election'=>$lrElection]);
$lrLevels=[];
foreach(['general'=>'Coordinador General','zona'=>'Coordinador de Zona','missing'=>'Nivel sin configurar'] as $key=>$name){
    $lrLevels[$key]=phase3Ok('/api/structure-levels','POST',['name'=>$name,'order'=>count($lrLevels)+1,'can_recruit'=>1,'same_level'=>1])['id'];
    $catalog=phase3Ok('/api/structure-levels');
    verify(array_values(array_filter($catalog,fn($l)=>$l['id']===$lrLevels[$key]))[0]['associated_role']===null,'Nivel nuevo sin asociación inventada');
    if(isset($lrRoles[$key]))phase3Ok('/api/structure-levels/'.$lrLevels[$key],'PUT',['name'=>$name,'order'=>count($lrLevels),'can_recruit'=>1,'same_level'=>1,'associated_role'=>$lrRoles[$key]]);
}
$lrStructure=phase3Ok('/api/structures','POST',['name'=>'Equipo Nivel Rol','elections'=>[$lrElection]])['id'];
$lrPeople=[];$lrMembers=[];
foreach(['account'=>'Persona con rol automático','missing'=>'Persona sin rol configurado','browser'=>'Persona cuenta desde modal'] as $key=>$name){
 $lrPeople[$key]=phase3Ok('/api/persons','POST',['organization'=>$organizationIds[0],'name'=>$name])['id'];
 $lrMembers[$key]=phase3Ok('/api/structures/'.$lrStructure.'/members','POST',['person'=>$lrPeople[$key],'levels'=>[$lrElection=>$lrLevels[$key==='missing'?'missing':'zona']]])['id'];
}
$lrUser=phase3Ok('/api/users','POST',['organization'=>$organizationIds[0],'process'=>$lrElection,'person'=>$lrPeople['account'],'name'=>'Persona con rol automático','username'=>'nivel-rol-cuenta','password'=>'Nivel rol seguro prueba','type'=>1])['id'];
$access=fn()=>phase3Ok('/api/users/'.$lrUser.'/accesses')['accesses'][0];
verify($access()['type']===$lrRoles['zona'],'Backend ignora rol libre manipulado y deriva Zona');
$lrUserId=$connection->query("SELECT id FROM usuarios WHERE usuario='nivel-rol-cuenta'")->fetchColumn();
$lrProcessId=$connection->query("SELECT id FROM procesos_electorales WHERE proceso='Relación Nivel Rol'")->fetchColumn();
$effective=fn()=>(new App\Repositories\PermissionRepository($connection))->effective((int)$lrUserId,(int)$lrProcessId)['rights'];
verify(!in_array('personas.editar',$effective(),true),'Zona no hereda permiso de General');
$phase3Tokens['levelrole']=login('levelrole','nivel-rol-cuenta','Nivel rol seguro prueba');phase3Ok('/api/auth/election','POST',['process'=>$lrElection],'levelrole');
phase3Ok('/api/structures/'.$lrStructure.'/members/'.$lrMembers['account'].'/level','PUT',['levels'=>[$lrElection=>$lrLevels['general']]]);
verify($access()['type']===$lrRoles['general'] && in_array('personas.editar',$effective(),true),'Cambiar a General sincroniza rol y permisos efectivos');
$me=phase3Ok('/api/auth/me','GET',null,'levelrole');verify(str_contains(json_encode($me),'personas.editar'),'Sesión ya abierta refleja permisos nuevos');
phase3Ok('/api/users/'.$lrUser.'/accesses','POST',['process'=>$lrElection,'type'=>$lrRoles['manual']]);
$member=fn()=>phase3Ok('/api/structures/'.$lrStructure.'/members/'.$lrMembers['account']);
verify($member()['level']===$lrLevels['general'] && $access()['type']===$lrRoles['manual'],'Cambiar rol manual no modifica nivel');
phase3Ok('/api/structures/'.$lrStructure.'/members/'.$lrMembers['account'].'/level','PUT',['levels'=>[$lrElection=>$lrLevels['general']]]);
verify($access()['type']===$lrRoles['manual'],'Guardar el mismo nivel conserva excepción manual');
$blocked=phase3Api('/api/users','POST',['organization'=>$organizationIds[0],'process'=>$lrElection,'person'=>$lrPeople['missing'],'name'=>'Sin rol','username'=>'nivel-rol-bloqueado','password'=>'Nivel rol seguro prueba','type'=>8]);
verify($blocked['status']===422 && str_contains($blocked['body'],'Este nivel no tiene un rol asociado'),'Crear cuenta sin asociación muestra configuración faltante');
verify((int)$connection->query("SELECT COUNT(*) FROM usuarios WHERE usuario='nivel-rol-bloqueado'")->fetchColumn()===0,'Alta bloqueada no deja cuenta parcial');
$blocked=phase3Api('/api/structures/'.$lrStructure.'/members/'.$lrMembers['account'].'/level','PUT',['levels'=>[$lrElection=>$lrLevels['missing']]]);
verify($blocked['status']===422 && str_contains($blocked['body'],'Este nivel no tiene un rol asociado'),'Cambiar nivel con cuenta a nivel sin rol se bloquea');
verify($member()['level']===$lrLevels['general'] && $access()['type']===$lrRoles['manual'],'Reversión conserva nivel y rol manual');
verify(phase3Ok('/api/structures/'.$lrStructure.'/members/'.$lrMembers['missing'])['level']===$lrLevels['missing'],'Persona sin cuenta conserva nivel sin rol');
// Un editor de niveles sin administración de permisos puede consultar el catálogo, pero no asignar roles ajenos.
phase3Grant($lrUser,'niveles.administrar',$lrElection,'eleccion');
$available=phase3Ok('/api/structure-level-roles','GET',null,'levelrole');verify(in_array($lrRoles['zona'],array_column($available,'id')),'Catálogo accesible con permiso de niveles');
phase3Status(403,'/api/roles','GET',null,'levelrole');
phase3Status(422,'/api/structure-levels/'.$lrLevels['zona'],'PUT',['name'=>'Coordinador de Zona','order'=>2,'can_recruit'=>1,'same_level'=>1,'associated_role'=>1]);
$foreignRole=phase3Ok('/api/roles','POST',['election'=>$processIds[1],'name'=>'Rol organización ajena'])['id'];
phase3Status(422,'/api/structure-levels/'.$lrLevels['zona'],'PUT',['name'=>'Coordinador de Zona','order'=>2,'can_recruit'=>1,'same_level'=>1,'associated_role'=>$foreignRole]);
verify(!in_array($foreignRole,array_column(phase3Ok('/api/structure-level-roles'),'id')),'Catálogo excluye roles de otra organización');
// Falta usuarios.administrar: se revierte incluso si el nivel ya se escribió dentro de la transacción.
phase3Grant($lrUser,'estructura.administrar',$lrElection,'eleccion');phase3Grant($lrUser,'estructura.ver',$lrElection,'eleccion');
phase3Status(403,'/api/structures/'.$lrStructure.'/members/'.$lrMembers['account'].'/level','PUT',['levels'=>[$lrElection=>$lrLevels['zona']]],'levelrole');
verify($member()['level']===$lrLevels['general'] && $access()['type']===$lrRoles['manual'],'Denegación de permiso revierte nivel y rol');
// Otra elección y el tipo global de cuenta no se alteran por la sincronización electoral.
phase3Ok('/api/users/'.$lrUser.'/accesses','POST',['process'=>$secondElection,'type'=>8]);
phase3Ok('/api/structures/'.$lrStructure.'/members/'.$lrMembers['account'].'/level','PUT',['levels'=>[$lrElection=>$lrLevels['zona']]]);
$allAccess=phase3Ok('/api/users/'.$lrUser.'/accesses')['accesses'];verify(array_values(array_filter($allAccess,fn($a)=>$a['process']===$secondElection))[0]['type']===8,'Otro acceso electoral permanece intacto');
verify((int)$connection->query("SELECT tipo_usuario FROM usuarios WHERE usuario='nivel-rol-cuenta'")->fetchColumn()===$lrRoles['zona'],'Tipo global permanece intacto');
// Error jerárquico posterior a la escritura del rol: rollback también de accesos y auditoría.
$lrInvalid=phase3Ok('/api/structure-levels','POST',['name'=>'Nivel inferior temporal','order'=>4,'associated_role'=>$lrRoles['general'],'can_recruit'=>1,'same_level'=>1])['id'];
$lrChild=phase3Ok('/api/persons','POST',['organization'=>$organizationIds[0],'name'=>'Hijo para validar transacción'])['id'];
phase3Ok('/api/structures/'.$lrStructure.'/members','POST',['person'=>$lrChild,'responsible'=>$lrMembers['account'],'levels'=>[$lrElection=>$lrLevels['zona']]]);
phase3Status(422,'/api/structures/'.$lrStructure.'/members/'.$lrMembers['account'].'/level','PUT',['levels'=>[$lrElection=>$lrInvalid]]);
$accesses=phase3Ok('/api/users/'.$lrUser.'/accesses')['accesses'];
verify($member()['level']===$lrLevels['zona'] && array_values(array_filter($accesses,fn($a)=>$a['process']===$lrElection))[0]['type']===$lrRoles['zona'],'Error jerárquico revierte incluso el rol ya escrito');
phase3Ok('/api/structure-levels/'.$lrInvalid,'PUT',['name'=>'Nivel inferior temporal','order'=>4,'active'=>0,'can_recruit'=>1,'same_level'=>1]);
file_put_contents($temporaryDirectory.'/level-role.json',json_encode(['election'=>$lrElection,'structure'=>$lrStructure,'roles'=>$lrRoles,'levels'=>$lrLevels,'people'=>$lrPeople,'members'=>$lrMembers],JSON_THROW_ON_ERROR));
require __DIR__ . '/account-creation.php';

echo 'Nivel → Rol: '.($checks-$levelRoleStart)." comprobaciones HTTP/BD.\n";
