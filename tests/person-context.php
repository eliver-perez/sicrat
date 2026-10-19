<?php
// Fixtures propios: nunca utiliza datos de la instalación.
$contextElection=phase3Ok('/api/organizations/'.$organizationIds[0].'/electoral-process','POST',['process'=>'Asignación contextual UX','type'=>1,'character'=>1,'status'=>1])['id'];
phase3Ok('/api/auth/election','POST',['process'=>$contextElection]);
$contextLevels=[];foreach([1=>['Dirección',1,0],2=>['Zona',1,1],3=>['Base',0,0]] as $order=>[$name,$recruit,$same])$contextLevels[$order]=phase3Ok('/api/structure-levels','POST',['associated_role'=>3,'name'=>$name,'order'=>$order,'can_recruit'=>$recruit,'same_level'=>$same])['id'];
$contextStructure=phase3Ok('/api/structures','POST',['name'=>'Equipo contextual','elections'=>[$contextElection]])['id'];
$contextPeople=[];$contextMembers=[];
foreach(['root'=>['Eliver','Perez',1],'child'=>['Claudio Javier','Acevedo Ochoa',2],'grand'=>['José Luis','Acosta Baizabal',3],'invalid'=>['Sin capacidad','UX',3],'alternative'=>['Patricia','Abundiz',2],'candidate'=>['Persona contextual','UX',null]] as $key=>[$name,$last,$level]){
    $contextPeople[$key]=phase3Ok('/api/persons','POST',['organization'=>$organizationIds[0],'name'=>$name,'last_name'=>$last])['id'];
    if($level)$contextMembers[$key]=phase3Ok('/api/structures/'.$contextStructure.'/members','POST',['person'=>$contextPeople[$key],'responsible'=>$key==='child'?$contextMembers['root']:($key==='grand'?$contextMembers['child']:null),'levels'=>[$contextElection=>$contextLevels[$level]],'participations'=>[$contextElection]])['id'];
}
$contextUser=phase3Ok('/api/users','POST',['organization'=>$organizationIds[0],'username'=>'contexto-personas','password'=>'Contexto seguro pruebas','name'=>'Eliver','last_name'=>'Perez','type'=>3,'process'=>$contextElection,'person'=>$contextPeople['root']])['id'];
foreach(['personas.ver','personas.editar','personas.participar','estructura.ver','estructura.formar'] as $permission)phase3Grant($contextUser,$permission,$contextElection,'organizacion');
$phase3Tokens['context']=login('context','contexto-personas','Contexto seguro pruebas');phase3Ok('/api/auth/election','POST',['process'=>$contextElection],'context');
$options=phase3Ok('/api/structures/'.$contextStructure.'/assignment-options','GET',null,'context');
verify($options['own']['id']===$contextMembers['root'] && array_column($options['elections'][0]['levels'],'order')===[1,2,3],'Opciones identifican cuenta/persona/integrante y orden real');
verify(count($options['candidates'])===3 && !in_array($contextMembers['grand'],array_column($options['candidates'],'id')) && !in_array($contextMembers['invalid'],array_column($options['candidates'],'id')),'No se ofrecen responsables sin capacidad organizacional');
phase3Status(422,'/api/structures/'.$contextStructure.'/members','POST',['person'=>$contextPeople['candidate'],'responsible'=>$contextMembers['root'],'levels'=>[$contextElection=>$contextLevels[1]]],'context');
phase3Status(403,'/api/structures/'.$contextStructure.'/members','POST',['person'=>$contextPeople['candidate'],'responsible'=>$contextMembers['invalid'],'levels'=>[$contextElection=>$contextLevels[3]]],'context');
$detail=phase3Ok('/api/structures/'.$contextStructure.'/members/'.$contextMembers['root'],'GET',null,'context');
verify(array_column($detail['team'],'id')===[$contextMembers['child']] && $detail['team_count']===2 && $detail['team'][0]['initials']==='CA','Ficha lista sólo directos y conserva agregado e iniciales');
$detail=phase3Ok('/api/structures/'.$contextStructure.'/members/'.$contextMembers['grand'],'GET',null,'context');verify(array_column($detail['superiors'],'id')===[$contextMembers['root'],$contextMembers['child']],'Superiores en orden natural de lectura');
phase3Grant($contextUser,'estructura.formar',$contextElection,'propio');
$options=phase3Ok('/api/structures/'.$contextStructure.'/assignment-options','GET',null,'context');verify(!$options['can_root'] && array_column($options['candidates'],'id')===[$contextMembers['root']],'Candidatos y raíz respetan alcance de incorporación');
phase3Grant($contextUser,'estructura.formar',$contextElection,'organizacion');
phase3Ok('/api/persons/'.$contextPeople['candidate'],'PUT',['latitude'=>'0','longitude'=>'0'],'context');
$location=phase3Ok('/api/persons/'.$contextPeople['candidate'],'GET',null,'context');verify((float)$location['latitude']===0.0&&(float)$location['longitude']===0.0,'Coordenadas cero válidas');
phase3Ok('/api/persons/'.$contextPeople['candidate'],'PUT',['latitude'=>'','longitude'=>''],'context');
$location=phase3Ok('/api/persons/'.$contextPeople['candidate'],'GET',null,'context');verify($location['latitude']===null&&$location['longitude']===null,'Ubicación sigue siendo opcional');
$near=phase3Ok('/api/structures/'.$contextStructure.'/assignment-options?parent=mine','GET',null,'context');
verify($near['focus']['id']===$contextMembers['root'] && array_column($near['candidates'],'id')===[$contextMembers['child']],'Selector inicia en equipo cercano sin lista completa');
$near=phase3Ok('/api/structures/'.$contextStructure.'/assignment-options?parent='.$contextMembers['child'],'GET',null,'context');
verify(array_column($near['ancestors'],'id')===[$contextMembers['root']] && $near['candidates']===[],'Navegación descendente y breadcrumb excluyen responsables inválidos');
phase3Status(403,'/api/structures/'.$contextStructure.'/assignment-options?parent='.$contextMembers['invalid'],'GET',null,'context');
$found=phase3Ok('/api/structures/'.$contextStructure.'/assignment-options?parent=mine&search=Patricia','GET',null,'context');
verify(array_column($found['candidates'],'id')===[$contextMembers['alternative']],'Búsqueda permitida fuera del nivel actual');
$code=$found['candidates'][0]['code'];
$found=phase3Ok('/api/structures/'.$contextStructure.'/assignment-options?parent=mine&search='.$code,'GET',null,'context');
verify(array_column($found['candidates'],'id')===[$contextMembers['alternative']],'Búsqueda por código');
$page=phase3Ok('/api/structures/'.$contextStructure.'/assignment-options?parent=roots&limit=1','GET',null,'context');
verify(count($page['candidates'])===1 && $page['pagination']['has_more'],'Raíces paginadas sin descargar lista completa');
$next=phase3Ok('/api/structures/'.$contextStructure.'/assignment-options?parent=roots&limit=1&offset='.$page['pagination']['next_offset'],'GET',null,'context');
verify(count($next['candidates'])===1 && $next['candidates'][0]['id']!==$page['candidates'][0]['id'],'Cursor entrega siguiente candidato sin duplicar');
$settingsPath='/api/organizations/'.$organizationIds[0].'/person-map';
phase3Ok($settingsPath,'PUT',['latitude'=>26.39,'longitude'=>-99.03,'zoom'=>13]);
$settings=phase3Ok($settingsPath,'GET',null,'context');verify($settings===['latitude'=>26.39,'longitude'=>-99.03,'zoom'=>13],'Configuración de mapa por organización');
phase3Status(403,$settingsPath,'PUT',['latitude'=>0,'longitude'=>0,'zoom'=>1],'context');
phase3Status(404,'/api/organizations/'.$organizationIds[1].'/person-map','GET',null,'context');
foreach([['latitude'=>91,'longitude'=>0,'zoom'=>1],['latitude'=>0,'longitude'=>-181,'zoom'=>1],['latitude'=>0,'longitude'=>0,'zoom'=>20],['latitude'=>0,'longitude'=>0,'zoom'=>1.5],['latitude'=>'','longitude'=>0,'zoom'=>1]] as $invalidMap)phase3Status(422,$settingsPath,'PUT',$invalidMap);
$otherSettings=phase3Ok('/api/organizations/'.$organizationIds[1].'/person-map');verify($otherSettings['zoom']===5,'Configuración no altera otra organización');
$settings=phase3Ok($settingsPath);verify($settings['latitude']===26.39 && $settings['zoom']===13,'Valores inválidos no modificaron configuración');
file_put_contents($temporaryDirectory.'/person-context.json',json_encode(['organization'=>$organizationIds[0],'alternativeCode'=>$code,'election'=>$contextElection,'structure'=>$contextStructure,'levels'=>$contextLevels,'persons'=>$contextPeople,'members'=>$contextMembers],JSON_THROW_ON_ERROR));
phase3Ok('/api/auth/election','POST',['process'=>$promotionElection]);

require __DIR__.'/own-responsible-level.php';
