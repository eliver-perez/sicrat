<?php
// Regresiones de Configuración electoral; sólo en la base desechable de integration.php.
$levelData=['election'=>$flowElection,'name'=>'Coordinador','order'=>1,'active'=>1,'can_recruit'=>1,'same_level'=>0];
$invalid=phase3Api('/api/structure-levels/'.$flowLevel,'PUT',$levelData);
verify($invalid['status']===422 && str_contains($invalid['body'],'responsables del mismo nivel'),'La validación explica la relación del mismo nivel');
$catalog=phase3Ok('/api/structure-levels');
verify($catalog[0]['same_level']===1,'La configuración inválida se revierte');
$interfaceLowerLevel=phase3Ok('/api/structure-levels','POST',['associated_role'=>3,'name'=>'Equipo operativo','order'=>2,'can_recruit'=>1,'same_level'=>1])['id'];
foreach(['grand','child'] as $key)phase3Ok('/api/structures/'.$flowStructure.'/members/'.$flowMembers[$key].'/level','PUT',['levels'=>[$flowElection=>$interfaceLowerLevel]]);
phase3Ok('/api/structure-levels/'.$flowLevel,'PUT',$levelData);
$statement=$connection->prepare("SELECT h.antes FROM estructura_historial h JOIN estructuras_organizacionales e ON e.id=h.estructura WHERE e.uuid=? AND h.accion='configuracion_nivel' ORDER BY h.id DESC LIMIT 1");
$statement->execute([$uuidService->uuidStringToBinary($flowStructure)]);$before=json_decode($statement->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
verify($before['uuid']===$flowLevel && $before['permite_mismo_nivel']==1,'El historial guarda UUID textual y estado anterior íntegro');
$invalid=phase3Api('/api/structure-levels/'.$flowLevel,'PUT',array_replace($levelData,['order'=>3]));
verify($invalid['status']===422 && str_contains($invalid['body'],'responsables de un nivel inferior'),'El orden invertido tiene un mensaje distinto');
$catalog=phase3Ok('/api/structure-levels');verify($catalog[0]['same_level']===0 && $catalog[0]['order']===1,'La configuración válida persiste y el orden inválido se revierte');
// Restaurar las relaciones para repetir incompatibilidad -> corrección -> guardado en Chrome.
phase3Ok('/api/structure-levels/'.$flowLevel,'PUT',array_replace($levelData,['same_level'=>1]));
foreach(['child','grand'] as $key)phase3Ok('/api/structures/'.$flowStructure.'/members/'.$flowMembers[$key].'/level','PUT',['levels'=>[$flowElection=>$flowLevel]]);
for($i=1;$i<=27;$i++)phase3Ok('/api/persons','POST',['organization'=>$organizationIds[0],'name'=>'Persona catálogo UI '.sprintf('%02d',$i),'phone'=>'812345'.sprintf('%04d',$i)]);

$accentSearch=phase3Ok('/api/persons?limit=25&search='.rawurlencode('Persona catálogo UI'),'GET',null,'flow');
verify(count($accentSearch['persons'])===25,'La búsqueda con acentos funciona también al comparar el código ASCII');
$code=$accentSearch['persons'][0]['code'];$codeSearch=phase3Ok('/api/persons?search='.rawurlencode($code),'GET',null,'flow');
verify(count($codeSearch['persons'])===1 && $codeSearch['persons'][0]['code']===$code,'La búsqueda por código conserva su resultado');
