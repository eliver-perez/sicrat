<?php
// Navegación: misma base desechable y cuentas del fixture Personas/Estructura.
$emptyStructure=phase3Ok('/api/structures','POST',['name'=>'A estructura sin integrantes','elections'=>[$flowElection]])['id'];
$choices=phase3Ok('/api/structures','GET',null,'flow');
verify($choices[0]['id']===$emptyStructure && !$choices[0]['own'],'Fixture: primera estructura sin integrante propio');
verify(count(array_filter($choices,fn($row)=>$row['own'] && $row['id']===$flowStructure))===1,'Identifica la estructura propia sin ampliar permisos');
$search=phase3Ok('/api/structures/'.$flowStructure.'?parent=&search='.rawurlencode('Responsable UX'),'GET',null,'flow');
verify(array_column($search['members'],'id')===[$flowMembers['child']],'Consulta plana filtra por nombre en servidor');
$first=phase3Api('/api/structures/'.$flowStructure.'?parent=roots&limit=1','GET',null,'flow');
$next=phase3Api('/api/structures/'.$flowStructure.'?parent=roots&limit=1&offset=1','GET',null,'flow');
verify($first['json']['pagination']['has_more'] && !$next['json']['pagination']['has_more'] && $first['json']['data']['members'][0]['id']!==$next['json']['data']['members'][0]['id'],'Paginación jerárquica conserva límites');
phase3Grant($flowUser,'estructura.ver',$flowElection,'directos');
$choices=phase3Ok('/api/structures','GET',null,'flow');verify(!array_filter($choices,fn($row)=>$row['own']),'La etiqueta propia respeta el alcance existente');
$search=phase3Ok('/api/structures/'.$flowStructure.'?parent=&search='.rawurlencode('Fuera del equipo UX'),'GET',null,'flow');verify($search['members']===[],'La búsqueda plana no expone integrantes fuera del permiso');
phase3Status(404,'/api/structures/'.$flowStructure.'?parent='.$flowMembers['grand'],'GET',null,'flow');
phase3Grant($flowUser,'estructura.ver',$flowElection,'eleccion');
