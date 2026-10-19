<?php
// Reproduce el alcance real de Eliver; se ejecuta sólo en la BD desechable.
phase3Ok('/api/auth/election','POST',['process'=>$contextElection]);
foreach(['estructura.ver','estructura.formar','estructura.administrar'] as $right)phase3Grant($contextUser,$right,$contextElection,'descendientes');
$ownOptions=phase3Ok('/api/structures/'.$contextStructure.'/assignment-options?parent=mine','GET',null,'context');
verify($ownOptions['own']['id']===$contextMembers['root'] && $ownOptions['focus']['id']===$contextMembers['root'],'Alcance descendientes incluye titular como responsable propio');
verify(!in_array($contextMembers['alternative'],array_column($ownOptions['candidates'],'id')),'No se amplía alcance a equipos ajenos');
$index=phase3Ok('/api/structures?assignment=1','GET',null,'context');verify(count(array_filter($index,fn($r)=>$r['id']===$contextStructure&&$r['own']))===1,'Se reconoce la estructura propia con alcance descendientes');
phase3Status(404,'/api/structures/'.$contextStructure.'/members/'.$contextMembers['root'],'GET',null,'context');
foreach(['estructura.ver','estructura.formar'] as $right)phase3Grant($contextUser,$right,$contextElection,'directos');
$options=phase3Ok('/api/structures/'.$contextStructure.'/assignment-options?parent=mine','GET',null,'context');verify($options['own']['id']===$contextMembers['root'],'Alcance directos también incluye el titular válido');
foreach(['estructura.ver','estructura.formar'] as $right)phase3Grant($contextUser,$right,$contextElection,'descendientes');
$writePerson=phase3Ok('/api/persons','POST',['organization'=>$organizationIds[0],'name'=>'Prueba autorización titular'])['id'];
phase3Status(404,'/api/structures/'.$contextStructure.'/members','POST',['person'=>$writePerson,'responsible'=>$contextMembers['alternative'],'levels'=>[$contextElection=>$contextLevels[3]]],'context');
$added=phase3Ok('/api/structures/'.$contextStructure.'/members','POST',['person'=>$writePerson,'responsible'=>$contextMembers['root'],'levels'=>[$contextElection=>$contextLevels[2]]],'context');
phase3Status(422,'/api/structures/'.$contextStructure.'/members/'.$added['id'].'/level','PUT',['levels'=>[$contextElection=>'']],'context');
phase3Status(422,'/api/structures/'.$contextStructure.'/members/'.$added['id'].'/level','PUT',['levels'=>[$contextElection=>$contextLevels[1]]],'context');
$detail=phase3Ok('/api/structures/'.$contextStructure.'/members/'.$added['id'],'GET',null,'context');
verify($detail['levels'][$contextElection]===$contextLevels[2] && array_column($detail['level_options'][$contextElection],'id')===[$contextLevels[2],$contextLevels[3]],'Editor conserva nivel y excluye niveles superiores o mismo nivel prohibido del responsable');
// Retirar el fixture incorporado para no alterar los conteos de las pruebas previas.
phase3Ok('/api/structures/'.$contextStructure.'/members/'.$added['id'].'/state','PUT',['active'=>0]);
foreach(['estructura.ver','estructura.formar'] as $right)phase3Grant($contextUser,$right,$contextElection,'organizacion');
phase3Grant($contextUser,'estructura.administrar',$contextElection,'descendientes',0);
$browserPerson=phase3Ok('/api/persons','POST',['organization'=>$organizationIds[0],'name'=>'Prueba Responsable Nivel'])['id'];
file_put_contents($temporaryDirectory.'/own-responsible-level.json',json_encode(['user'=>$contextUser,'person'=>$browserPerson,'election'=>$contextElection,'structure'=>$contextStructure,'root'=>$contextMembers['root'],'child'=>$contextMembers['child'],'levels'=>$contextLevels],JSON_THROW_ON_ERROR));
phase3Ok('/api/auth/election','POST',['process'=>$promotionElection]);
