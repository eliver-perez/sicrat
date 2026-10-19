<?php
// Sólo se incluye desde integration.php: fixtures en su base desechable.
$flowStart=$checks;
$flowElection=phase3Ok('/api/organizations/'.$organizationIds[0].'/electoral-process','POST',['process'=>'Personas y equipo UX','type'=>1,'character'=>1,'status'=>1])['id'];
phase3Ok('/api/auth/election','POST',['process'=>$flowElection]);
$flowLevel=phase3Ok('/api/structure-levels','POST',['associated_role'=>3,'election'=>$flowElection,'name'=>'Coordinador','order'=>1,'can_recruit'=>1,'same_level'=>1])['id'];
// UUID binario deliberadamente no UTF-8: regresión del historial al editar niveles.
$binaryLevel=$uuidService->uuidStringToBinary('ffffffff-ffff-4fff-bfff-ffffffffffff');
$connection->prepare('UPDATE niveles_organizacionales SET uuid=? WHERE uuid=?')->execute([$binaryLevel,$uuidService->uuidStringToBinary($flowLevel)]);
$flowLevel=$uuidService->uuidBinaryToString($binaryLevel);
$flowStructure=phase3Ok('/api/structures','POST',['name'=>'Equipo navegable UX','elections'=>[$flowElection]])['id'];
$flowPersons=[];$flowMembers=[];
foreach(['root'=>'Coordinador UX','child'=>'Responsable UX','grand'=>'Descendiente UX','outside'=>'Fuera del equipo UX','candidate'=>'Persona por incorporar UX'] as $key=>$label){
    $flowPersons[$key]=phase3Ok('/api/persons','POST',['organization'=>$organizationIds[0],'name'=>$label])['id'];
    if($key==='candidate')continue;
    $parent=$key==='child' ? $flowMembers['root'] : ($key==='grand' ? $flowMembers['child'] : null);
    $flowMembers[$key]=phase3Ok('/api/structures/'.$flowStructure.'/members','POST',['person'=>$flowPersons[$key],'responsible'=>$parent,'levels'=>[$flowElection=>$flowLevel],'participations'=>[$flowElection]])['id'];
}
$flowUser=phase3Ok('/api/users','POST',['organization'=>$organizationIds[0],'username'=>'personas-equipo-ux','password'=>'Persona equipo segura','name'=>'Coordinador UX','type'=>3,'process'=>$flowElection,'person'=>$flowPersons['root']])['id'];
foreach(['personas.ver'=>'organizacion','personas.editar'=>'organizacion','personas.participar'=>'organizacion','estructura.ver'=>'eleccion','estructura.formar'=>'propio'] as $permission=>$scope)phase3Grant($flowUser,$permission,$flowElection,$scope);
$phase3Tokens['flow']=login('flow','personas-equipo-ux','Persona equipo segura');phase3Ok('/api/auth/election','POST',['process'=>$flowElection],'flow');
$detail=phase3Ok('/api/persons/'.$flowPersons['candidate'],'GET',null,'flow');verify(!$detail['structure']['participates'] && $detail['structure']['members']===[],'Identidad sin participación ni estructura');
phase3Ok('/api/persons/'.$flowPersons['candidate'].'/participations','POST',['processes'=>[$flowElection],'active'=>1],'flow');
$detail=phase3Ok('/api/persons/'.$flowPersons['candidate'],'GET',null,'flow');verify($detail['structure']['participates'] && $detail['structure']['members']===[] && $detail['structure']['can_form'],'Participación no inventa responsable ni equipo');
$own=phase3Ok('/api/structures/'.$flowStructure.'?parent=mine','GET',null,'flow');verify($own['own']['id']===$flowMembers['root'] && $own['focus']['id']===$flowMembers['root'] && $own['can_add_to_own'],'Equipo propio disponible según permiso y nivel');
verify(array_column($own['members'],'id')===[$flowMembers['child']],'Mi equipo muestra únicamente directos');
$child=phase3Ok('/api/structures/'.$flowStructure.'?parent='.$flowMembers['child'],'GET',null,'flow');verify(array_column($child['members'],'id')===[$flowMembers['grand']],'Navegación hacia descendientes');
phase3Grant($flowUser,'estructura.ver',$flowElection,'directos');
$restricted=phase3Ok('/api/structures/'.$flowStructure.'?parent=roots','GET',null,'flow');verify(array_column($restricted['members'],'id')===[$flowMembers['child']] && $restricted['members'][0]['team_count']===0,'La navegación y los conteos respetan el alcance directo');
phase3Status(404,'/api/structures/'.$flowStructure.'?parent='.$flowMembers['grand'],'GET',null,'flow');
phase3Status(404,'/api/structures/'.$flowStructure.'/members/'.$flowMembers['outside'],'GET',null,'flow');
phase3Status(404,'/api/structures/'.$promotionStructure,'GET',null,'flow');
$otherPerson=phase3Ok('/api/persons','POST',['organization'=>$organizationIds[1],'name'=>'Otra organización UX'])['id'];phase3Status(404,'/api/persons/'.$otherPerson,'GET',null,'flow');
phase3Grant($flowUser,'estructura.ver',$flowElection,'eleccion');
phase3Status(404,'/api/structures/'.$flowStructure.'/members','POST',['person'=>$otherPerson,'responsible'=>$flowMembers['root'],'levels'=>[$flowElection=>$flowLevel]],'flow');
// La ficha conserva la cadena real de IDs geográficos al editar.
phase3Ok('/api/persons/'.$flowPersons['candidate'],'PUT',['neighborhood'=>1],'flow');
$detail=phase3Ok('/api/persons/'.$flowPersons['candidate'],'GET',null,'flow');verify($detail['location']['neighborhood']==='Colonia de prueba' && (int)$detail['location']['state']===1,'Ficha muestra colonia y cadena geográfica real');
require __DIR__.'/structure-interface.php';
require __DIR__.'/structure-navigation.php';
file_put_contents($temporaryDirectory.'/persons-structure.json',json_encode(['election'=>$flowElection,'structure'=>$flowStructure,'level'=>$flowLevel,'lowerLevel'=>$interfaceLowerLevel,'user'=>$flowUser,'persons'=>$flowPersons,'members'=>$flowMembers],JSON_THROW_ON_ERROR));
phase3Ok('/api/auth/election','POST',['process'=>$promotionElection]);
require __DIR__.'/person-context.php';
echo 'Personas y Estructura: '.($checks-$flowStart)." comprobaciones HTTP/BD.\n";
