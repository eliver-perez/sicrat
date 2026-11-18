<?php
// Reutiliza las identidades, membresías y permisos de tests/person-context.php.
$mapPath = '/api/persons/map?south=26&north=27&west=-100&east=-99';
$mapPosition = ['latitude'=>26.39,'longitude'=>-99.03];
$mapSection = $uuidService->uuidBinaryToString($sectionUuid);
foreach (['root','child','alternative'] as $key) {
    phase3Ok('/api/persons/'.$contextPeople[$key],'PUT',$mapPosition + ($key==='root' ? ['section'=>$mapSection] : []));
}
$unrelated = phase3Ok('/api/persons','POST',$mapPosition + ['organization'=>$organizationIds[0],'name'=>'Sólo padrón cartográfico'])['id'];
$otherOrg = phase3Ok('/api/persons','POST',$mapPosition + ['organization'=>$organizationIds[1],'name'=>'Otra organización cartográfica'])['id'];
$points = phase3Ok($mapPath,'GET',null,'context')['points'];
verify(count($points)===1 && $points[0]['count']===3 && !isset($points[0]['name'],$points[0]['id']),'Agrupa sólo las tres ubicaciones de la elección antes de entregar datos');
$points = phase3Ok($mapPath.'&view=people','GET',null,'context')['points'];
verify(count($points)===3 && !array_intersect([$unrelated,$otherOrg,$contextPeople['grand']],array_column($points,'id')),'La lista del grupo excluye padrón ajeno, otras elecciones y personas sin coordenadas');
verify(array_column(phase3Ok($mapPath.'&section='.$mapSection,'GET',null,'context')['points'],'id')===[$contextPeople['root']],'Filtro de sección antes de agrupar');
verify(array_column(phase3Ok($mapPath.'&search=CLAUDIO','GET',null,'context')['points'],'id')===[$contextPeople['child']],'Búsqueda de nombre compartida con Personas');
phase3Grant($contextUser,'personas.ver',$contextElection,'directos');
verify(array_column(phase3Ok($mapPath,'GET',null,'context')['points'],'id')===[$contextPeople['child']],'El mapa usa el alcance directo antes del clustering');
verify(phase3Ok($mapPath.'&search=Patricia&view=people','GET',null,'context')['points']===[],'Abrir un grupo o buscar no amplía el alcance');
phase3Grant($contextUser,'personas.ver',$contextElection,'propio',0);
phase3Status(403,$mapPath,'GET',null,'context');
phase3Grant($contextUser,'personas.ver',$contextElection,'organizacion');
phase3Status(422,'/api/persons/map?south=-91&north=27&west=-100&east=-99','GET',null,'context');
verify(phase3Ok('/api/persons/map?south=0&north=1&west=0&east=1','GET',null,'context')['points']===[],'Mover la ventana excluye puntos externos');
$coincidentIds = [];
for ($i=1; $i<=26; $i++) {
    $id = phase3Ok('/api/persons','POST',['organization'=>$organizationIds[0],'name'=>'Grupo cartográfico '.$i,'latitude'=>-32,'longitude'=>25])['id'];
    phase3Ok('/api/persons/'.$id.'/participations','POST',['processes'=>[$contextElection]]);
    $coincidentIds[] = $id;
}
$groupPath = '/api/persons/map?south=-32&north=-32&west=25&east=25';
$group = phase3Ok($groupPath,'GET',null,'context')['points'];
verify(count($group)===1 && $group[0]['count']===26,'Los puntos coincidentes forman un solo marcador incluso con límites idénticos');
$firstPage = phase3Ok($groupPath.'&view=people','GET',null,'context');
$lastPage = phase3Ok($groupPath.'&view=people&offset=25','GET',null,'context');
verify(count($firstPage['points'])===25 && $firstPage['has_more'] && !$lastPage['has_more']
    && [...array_column($firstPage['points'],'id'),...array_column($lastPage['points'],'id')]===$coincidentIds,
    'El grupo entrega páginas acotadas sin omitir ni repetir personas');
verify(phase3Ok('/api/persons/'.$contextPeople['candidate'],'GET',null,'context')['latitude']===null,'El centro inicial no crea una ubicación personal');
$modulePath = '/api/organizations/'.$organizationIds[0].'/modules/tareas_metas';
$rolePath = '/api/roles/3/permissions';
phase3Ok($modulePath,'PUT',['authorized'=>1,'selected'=>1]);
phase3Ok($modulePath,'PUT',['authorized'=>1,'selected'=>1,'election'=>$contextElection]);
phase3Ok($rolePath,'PUT',['permission'=>'tareas.ver','scope'=>'propio','value'=>1,'election'=>$contextElection]);
phase3Ok($modulePath,'PUT',['selected'=>0,'election'=>$contextElection]);
verify(!in_array('tareas.ver',array_column(phase3Ok('/api/permissions')['permissions'],'id'),true),'Oculta los permisos del módulo desactivado');
verify(!in_array('tareas.ver',array_column(phase3Ok($rolePath),'permission'),true),'El rol oculta su configuración deshabilitada');
phase3Status(403,'/api/operations/tareas','GET',null,'context');
verify(!in_array('tareas.ver',phase3Ok('/api/auth/me','GET',null,'context')['rights'],true),'El permiso almacenado deja de ser efectivo');
phase3Ok($modulePath,'PUT',['selected'=>1,'election'=>$contextElection]);
verify(in_array('tareas.ver',array_column(phase3Ok('/api/permissions')['permissions'],'id'),true)
    && in_array('tareas.ver',array_column(phase3Ok($rolePath),'permission'),true)
    && in_array('tareas.ver',phase3Ok('/api/auth/me','GET',null,'context')['rights'],true),'Rehabilitar el módulo recupera catálogo y concesión sin reescribir el rol');
$detail = phase3Ok('/api/persons/'.$contextPeople['root'],'GET',null,'context');
verify(!$detail['structure']['can_form'] && $detail['structure']['members'][0]['active'],'Integrante activo conserva acción de consulta');
$reader=phase3Ok('/api/users','POST',['organization'=>$organizationIds[0],'username'=>'mapa-consulta','password'=>'Consulta segura prueba','name'=>'Sólo lectura','type'=>8,'process'=>$contextElection])['id'];
foreach (['personas.ver','estructura.ver'] as $permission) phase3Grant($reader,$permission,$contextElection,'organizacion');
$phase3Tokens['mapreader']=login('mapreader','mapa-consulta','Consulta segura prueba');
phase3Ok('/api/auth/election','POST',['process'=>$contextElection],'mapreader');
$readerDetail=phase3Ok('/api/persons/'.$contextPeople['candidate'],'GET',null,'mapreader');
verify(!$readerDetail['structure']['can_form'],'Sin permiso de incorporación no hay acción de asignación');
$emptyRoot=phase3Ok('/api/structures/'.$contextStructure.'?parent=mine','GET',null,'mapreader');
verify($emptyRoot['focus']===null && $emptyRoot['own']===null,'La cuenta sin membresía no recibe tarjetas ajenas');
