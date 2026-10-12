<?php

// Requiere integration.php --phase3: sólo escribe en su base desechable.
$phase3Start = $checks;
function phase3Api(string $path, string $method = 'GET', ?array $data = null, string $client = 'admin'): array {
    global $adminToken, $phase3Tokens;
    return request($client,$method,$path,$data,$method==='GET' ? null : ($phase3Tokens[$client] ?? $adminToken));
}
function phase3Ok(string $path, string $method = 'GET', ?array $data = null, string $client = 'admin'): array {
    $response = phase3Api($path,$method,$data,$client);
    verify(in_array($response['status'],[200,201],true),$method . ' ' . $path . ': ' . $response['body']);
    return $response['json']['data'] ?? [];
}
function phase3Status(int $status, string $path, string $method = 'GET', ?array $data = null, string $client = 'admin'): void {
    $response=phase3Api($path,$method,$data,$client); verify($response['status']===$status,$method . ' ' . $path . ' esperaba ' . $status . ': ' . $response['body']);
}
function phase3Closure(): void {
    global $connection;
    $members=$connection->query('SELECT id,nodo_padre,contenedor FROM estructura')->fetchAll(PDO::FETCH_ASSOC); $byId=array_column($members,null,'id'); $expected=[];
    foreach ($members as $member) {
        $ancestor=$member['id']; $depth=0; $visited=[];
        while ($ancestor!==null) {
            verify(!isset($visited[$ancestor]),'No hay ciclos en la relación directa.'); $visited[$ancestor]=true;
            verify((int) $byId[$ancestor]['contenedor']===(int) $member['contenedor'],'La relación pertenece al mismo contenedor.');
            $expected[] = [(int) $ancestor,(int) $member['id'],$depth++]; $ancestor=$byId[$ancestor]['nodo_padre'];
        }
    }
    $actual=array_map(static fn ($row) => array_map('intval',array_values($row)),$connection->query('SELECT ancestro,descendiente,profundidad FROM estructura_relaciones')->fetchAll(PDO::FETCH_ASSOC));
    sort($expected); sort($actual); verify($expected===$actual,'Cierre transitivo coincide exactamente con las relaciones directas.');
}
function phase3Grant(string $user, string $permission, string $election, string $scope = 'organizacion', int $value = 1): void {
    phase3Ok('/api/users/' . $user . '/permissions','PUT',['permission'=>$permission,'election'=>$election,'scope'=>$scope,'value'=>$value]);
}
$phase3Tokens=[];
phase3Ok('/api/auth/election','POST',['process'=>$processIds[0]]);
verify((new App\Core\Migrations($connection))->run()===[],'El ejecutor no repite migraciones aplicadas.');
verify((int) $connection->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='permisos' AND column_name='id'")->fetchColumn()===128,'Identificadores de permisos ampliados.');
verify((int) $connection->query('SELECT COUNT(*) FROM usuarios WHERE persona IS NULL')->fetchColumn()>1,'La unicidad permite múltiples cuentas sin persona.');
$person=phase3Ok('/api/persons','POST',['organization'=>$organizationIds[0],'name'=>'Persona cuenta única']);
$newAccount=phase3Ok('/api/users','POST',['organization'=>$organizationIds[0],'username'=>'fase3-unica','password'=>'Fase3 prueba segura','type'=>8,'name'=>'Persona','last_name'=>'Única','person'=>$person['id']]);
phase3Status(409,'/api/users/' . $accountUuid . '/person','PUT',['person'=>$person['id']]);
verify((int) $connection->query("SELECT COUNT(*) FROM usuarios WHERE persona=(SELECT id FROM personas WHERE codigo_persona='" . $person['code'] . "')")->fetchColumn()===1,'La persona tiene exactamente una cuenta.');
$firstUser=phase3Ok('/api/users/' . $newAccount['id'] . '/accesses'); verify($firstUser['person']===$person['id'],'Alta de cuenta vincula la identidad existente.');
phase3Ok('/api/users/' . $newAccount['id'] . '/accesses','POST',['process'=>$processIds[0],'type'=>8]);
phase3Ok('/api/users/' . $newAccount['id'] . '/accesses','POST',['process'=>$secondElection,'type'=>3]);
verify(count(phase3Ok('/api/users/' . $newAccount['id'] . '/accesses')['accesses'])===2,'Una sola cuenta tiene dos accesos electorales.');
$third = phase3Ok('/api/organizations/' . $organizationIds[0] . '/electoral-process','POST',['process'=>'Tercera elección Fase 3','type'=>1,'character'=>1,'status'=>1]);
$thirdElection=$third['id'];
$elections=[$processIds[0],$secondElection,$thirdElection];
phase3Status(403,'/api/structures');
phase3Ok('/api/organizations/' . $organizationIds[0] . '/modules/estructura','PUT',['authorized'=>1,'selected'=>1]);
$catalog=phase3Ok('/api/organizations/' . $organizationIds[0] . '/modules');
verify(count(array_filter($catalog,static fn ($row) => (int) $row['esencial']===1 && !$row['habilitado']))===0,'Componentes esenciales siempre disponibles.');
phase3Status(422,'/api/organizations/' . $organizationIds[0] . '/modules/personas','PUT',['authorized'=>0]);
$levels=[];
foreach ($elections as $election) foreach ([1=>['Dirección',1,1],2=>['Enlace',1,1],3=>['Integrante',0,1]] as $order=>[$name,$recruit,$same]) $levels[$election][$order]=phase3Ok('/api/structure-levels','POST',['election'=>$election,'name'=>$name,'order'=>$order,'can_recruit'=>$recruit,'same_level'=>$same])['id'];
phase3Status(409,'/api/structure-levels','POST',['election'=>$elections[0],'name'=>'Duplicado','order'=>1]);
foreach ($elections as $election) phase3Ok('/api/structure-config','PUT',['election'=>$election,'approval_required'=>0,'auto_promote'=>0]);
$shared=phase3Ok('/api/structures','POST',['name'=>'Equipo compartido','elections'=>[$elections[0],$elections[1]]])['id'];
$membership=[]; $personIds=[];
$parents=['A'=>null,'B'=>'A','C'=>'A','D'=>'B','E'=>'D','F'=>null];
$orders=['A'=>1,'B'=>2,'C'=>2,'D'=>2,'E'=>3,'F'=>1];
foreach ($parents as $key=>$parent) {
    $personIds[$key]=phase3Ok('/api/persons','POST',['organization'=>$organizationIds[0],'name'=>'Integrante Fase3 ' . $key])['id'];
    $membership[$key]=phase3Ok('/api/structures/' . $shared . '/members','POST',['person'=>$personIds[$key],'responsible'=>$parent ? $membership[$parent] : null,'levels'=>[$elections[0]=>$levels[$elections[0]][$orders[$key]],$elections[1]=>$levels[$elections[1]][$orders[$key]]],'participations'=>[$elections[0],$elections[1]]])['id'];
}
$rootDetail=phase3Ok('/api/structures/' . $shared . '/members/' . $membership['A']);
verify(!$rootDetail['has_account'] && $rootDetail['direct_count']===2 && $rootDetail['team_count']===4,'Integrante sin cuenta tiene equipo e identidad propia.');
verify(count(phase3Ok('/api/structures/' . $shared . '/members/' . $membership['E'])['superiors'])===3,'Consulta de superiores y profundidad independiente del nivel.');
$beforePersons=(int) $connection->query('SELECT COUNT(*) FROM personas')->fetchColumn();
$independent=phase3Ok('/api/structures','POST',['name'=>'Equipo independiente','elections'=>[$elections[2]]])['id'];
$independentMember=phase3Ok('/api/structures/' . $independent . '/members','POST',['person'=>$personIds['B'],'levels'=>[$elections[2]=>$levels[$elections[2]][2]]])['id'];
verify((int) $connection->query('SELECT COUNT(*) FROM personas')->fetchColumn()===$beforePersons,'Las estructuras independientes reutilizan personas.');
phase3Status(409,'/api/structures/' . $independent . '/elections','POST',['election'=>$elections[0],'levels'=>[$independentMember=>$levels[$elections[0]][2]]]);
phase3Status(422,'/api/structures/' . $shared . '/members/' . $membership['A'] . '/transfers','POST',['mode'=>'equipo','destination'=>$membership['E'],'reason'=>'Ciclo rechazado']);
phase3Status(422,'/api/structures/' . $shared . '/members/' . $membership['F'] . '/transfers','POST',['mode'=>'equipo','destination'=>$membership['B'],'reason'=>'Relación ascendente']);
verify(phase3Ok('/api/structures/' . $shared . '/members/' . $membership['F'])['responsible']===null,'Una relación inválida revierte completamente.');
phase3Status(422,'/api/structures/' . $shared . '/members/' . $membership['B'] . '/state','PUT',['active'=>0]);
phase3Status(409,'/api/structures/' . $shared . '/members/' . $membership['B'] . '/state','PUT',['active'=>1,'responsible'=>$membership['F']]);
phase3Status(422,'/api/structures/' . $shared . '/members/' . $membership['B'] . '/transfers','POST',['mode'=>'integrante','destination'=>$membership['F'],'reason'=>'Necesita reemplazo']);
phase3Ok('/api/structures/' . $shared . '/members/' . $membership['B'] . '/transfers','POST',['mode'=>'equipo','destination'=>$membership['F'],'reason'=>'Mover equipo completo']);
verify(phase3Ok('/api/structures/' . $shared . '/members/' . $membership['D'])['responsible']===$membership['B'],'Transferir equipo conserva responsables internos.');
phase3Ok('/api/auth/election','POST',['process'=>$elections[1]]);
verify(phase3Ok('/api/structures/' . $shared . '/members/' . $membership['B'])['responsible']===$membership['F'],'Un movimiento compartido se refleja en la otra elección.');
phase3Ok('/api/auth/election','POST',['process'=>$elections[0]]);
phase3Closure();
phase3Ok('/api/structures/' . $shared . '/members/' . $membership['B'] . '/transfers','POST',['mode'=>'integrante','destination'=>$membership['A'],'replacement'=>$membership['F'],'reason'=>'Sólo el integrante']);
verify(phase3Ok('/api/structures/' . $shared . '/members/' . $membership['D'])['responsible']===$membership['F'],'Transferencia individual asigna explícitamente el equipo directo.');
verify(phase3Ok('/api/structures/' . $shared . '/members/' . $membership['E'])['responsible']===$membership['D'],'La reasignación conserva subestructuras.');
phase3Ok('/api/structures/' . $shared . '/members/' . $membership['B'] . '/state','PUT',['active'=>0]);
verify(phase3Ok('/api/structures/' . $shared . '/members/' . $membership['B'])['active']===0,'Baja lógica conserva la ficha.');
phase3Status(409,'/api/structures/' . $shared . '/members/' . $membership['B'] . '/state','PUT',['active'=>1,'responsible'=>$membership['F'],'levels'=>[$elections[0]=>$levels[$elections[0]][2],$elections[1]=>$levels[$elections[1]][2]]]);
phase3Ok('/api/structures/' . $shared . '/members/' . $membership['B'] . '/state','PUT',['active'=>1,'responsible'=>$membership['A'],'levels'=>[$elections[0]=>$levels[$elections[0]][2],$elections[1]=>$levels[$elections[1]][2]]]);
phase3Ok('/api/structures/' . $shared . '/members/' . $membership['D'] . '/transfers','POST',['mode'=>'equipo','destination'=>$membership['B'],'reason'=>'Restituir coordinación']);
phase3Closure();
phase3Status(422,'/api/structures/' . $shared . '/members/' . $membership['B'] . '/level','PUT',['levels'=>[$elections[0]=>$levels[$elections[0]][3]]]);
verify(phase3Ok('/api/structures/' . $shared . '/members/' . $membership['B'])['level']===$levels[$elections[0]][2],'El cambio de nivel incompatible revierte.');

$manager=phase3Ok('/api/users','POST',['organization'=>$organizationIds[0],'username'=>'fase3-admin-cliente','password'=>'Fase3 cliente seguro','type'=>8,'name'=>'Administrador','last_name'=>'Cliente','process'=>$elections[0]])['id'];
phase3Ok('/api/users/' . $manager . '/accesses','POST',['process'=>$elections[0],'type'=>2]);
phase3Ok('/api/users/' . $manager . '/accesses','POST',['process'=>$elections[1],'type'=>2]);
phase3Ok('/api/users/' . $manager . '/person','PUT',['person'=>$personIds['B']]);
$phase3Tokens['manager']=login('manager','fase3-admin-cliente','Fase3 cliente seguro');
phase3Ok('/api/auth/election','POST',['process'=>$elections[0]],'manager');
phase3Status(403,'/api/organizations/' . $organizationIds[0] . '/modules/finanzas','PUT',['selected'=>1],'manager');
phase3Status(403,'/api/organizations/' . $organizationIds[0] . '/modules/estructura','PUT',['authorized'=>1],'manager');
phase3Ok('/api/organizations/' . $organizationIds[0] . '/modules/estructura','PUT',['election'=>$elections[0],'selected'=>1],'manager');
phase3Status(403,'/api/users/' . $manager . '/permissions','PUT',['context'=>'global','permission'=>'personas.ver','value'=>1,'scope'=>'organizacion'],'manager');
phase3Grant($manager,'estructura.administrar',$elections[0]);
phase3Grant($manager,'estructura.administrar',$elections[1],'organizacion',0);
phase3Status(403,'/api/structures/' . $shared . '/members/' . $membership['B'] . '/level','PUT',['levels'=>[$elections[0]=>$levels[$elections[0]][2]]],'manager');
phase3Grant($manager,'estructura.administrar',$elections[1]);
phase3Ok('/api/structures/' . $shared . '/members/' . $membership['B'] . '/level','PUT',['levels'=>[$elections[0]=>$levels[$elections[0]][2]]],'manager');
phase3Status(409,'/api/users/' . $manager . '/permissions','PUT',['permission'=>'estructura.formar','election'=>$elections[0],'scope'=>'organizacion','value'=>0]);
phase3Status(409,'/api/users/' . $manager . '/accesses','POST',['process'=>$elections[0],'type'=>8]);
phase3Status(409,'/api/users/' . $manager . '/person','PUT',['person'=>null]);
phase3Status(409,'/api/users/' . $manager . '/state','PUT',['active'=>0]);
verify(phase3Ok('/api/structures/' . $shared . '/members/' . $membership['B'])['level']===$levels[$elections[0]][2],'Rol electoral no sincroniza el nivel.');
$clientAccount=phase3Ok('/api/users','POST',['organization'=>$organizationIds[0],'username'=>'fase3-creada-cliente','password'=>'Cuenta cliente segura','type'=>8,'name'=>'Cuenta de integrante','process'=>$elections[0],'person'=>$personIds['C']],'manager');
verify(phase3Ok('/api/users/' . $clientAccount['id'] . '/accesses')['person']===$personIds['C'],'Administrador cliente crea una cuenta para la persona existente dentro de sus permisos.');

// Alcances de información: una revocación individual limita incluso un permiso legado.
phase3Grant($manager,'personas.ver',$elections[0],'directos');
$direct=phase3Ok('/api/persons?limit=100', 'GET',null,'manager')['persons'];
verify(array_column($direct,'id')===[$personIds['D']],'Alcance directo excluye persona propia, superior, hermano y nieto.');
phase3Status(404,'/api/persons/' . $personIds['A'],'GET',null,'manager');
phase3Status(404,'/api/persons/' . $personIds['E'],'GET',null,'manager');
phase3Grant($manager,'personas.ver',$elections[0],'descendientes');
$descendants=array_column(phase3Ok('/api/persons?limit=100','GET',null,'manager')['persons'],'id'); sort($descendants); $expected=[$personIds['D'],$personIds['E']]; sort($expected);
verify($descendants===$expected,'Descendientes sólo incluye equipo a cargo, sin superiores ni hermanos.');
phase3Grant($manager,'personas.editar',$elections[0],'directos');
phase3Status(404,'/api/persons/' . $personIds['A'],'PUT',['phone'=>'123'],'manager');
phase3Ok('/api/persons/' . $personIds['D'],'PUT',['phone'=>'555'],'manager');
phase3Grant($manager,'estructura.ver',$elections[0],'descendientes');
verify(count(phase3Ok('/api/structures/' . $shared,'GET',null,'manager')['members'])===2,'Los integrantes se filtran en el servidor según alcance.');
phase3Status(404,'/api/structures/' . $shared . '/members/' . $membership['A'],'GET',null,'manager');
phase3Grant($manager,'personas.ver',$elections[1],'organizacion',0);
phase3Ok('/api/auth/election','POST',['process'=>$elections[1]],'manager');
phase3Status(403,'/api/persons','GET',null,'manager');
phase3Ok('/api/auth/election','POST',['process'=>$elections[0]],'manager');
phase3Status(200,'/api/persons','GET',null,'manager');
phase3Ok('/api/users/' . $manager . '/permissions','PUT',['context'=>'global','permission'=>'personas.editar','value'=>1,'scope'=>'organizacion']);
phase3Grant($manager,'personas.editar',$elections[1],'organizacion',0);
phase3Ok('/api/auth/election','POST',['process'=>$elections[1]],'manager');
phase3Status(403,'/api/persons/' . $personIds['D'],'PUT',['phone'=>'No debe cambiar'],'manager');
phase3Ok('/api/auth/election','POST',['process'=>$elections[0]],'manager');
phase3Ok('/api/persons/' . $personIds['D'],'PUT',['phone'=>'Permiso global'],'manager');
phase3Grant($manager,'personas.ver',$elections[0],'propio');
verify(array_column(phase3Ok('/api/persons?limit=100','GET',null,'manager')['persons'],'id')===[$personIds['B']],'Alcance propio utiliza la persona vinculada.');
phase3Grant($manager,'personas.ver',$elections[0],'territorio');
phase3Ok('/api/users/' . $manager . '/territories','PUT',['sections'=>[$uuidService->uuidBinaryToString($sectionUuid)]]);
verify(count(phase3Ok('/api/persons?limit=100','GET',null,'manager')['persons'])>0,'Alcance territorial utiliza secciones expresamente autorizadas.');
phase3Status(404,'/api/persons/' . $personIds['B'],'GET',null,'manager');
phase3Grant($manager,'personas.ver',$elections[0],'eleccion');
verify(in_array($personIds['B'],array_column(phase3Ok('/api/persons?limit=100','GET',null,'manager')['persons'],'id'),true),'Alcance electoral reconoce participación y estructura.');

// Aprobación, rechazo y detección de una solicitud obsoleta.
foreach ([$elections[0],$elections[1]] as $election) phase3Ok('/api/structure-config','PUT',['election'=>$election,'approval_required'=>1]);
$transfer=phase3Ok('/api/structures/' . $shared . '/members/' . $membership['D'] . '/transfers','POST',['mode'=>'equipo','destination'=>$membership['C'],'reason'=>'Revisión sensible']);
verify($transfer['state']==='pendiente','La transferencia sensible espera aprobación.');
verify(phase3Ok('/api/structures/' . $shared . '/members/' . $membership['D'])['responsible']===$membership['B'],'Solicitar no mueve el equipo.');
phase3Ok('/api/structures/' . $shared . '/transfers/' . $transfer['id'],'PUT',['approved'=>0,'reason'=>'No autorizado por coordinación']);
verify(phase3Ok('/api/structures/' . $shared . '/members/' . $membership['D'])['responsible']===$membership['B'],'El rechazo conserva la estructura.');
phase3Status(409,'/api/structures/' . $shared . '/transfers/' . $transfer['id'],'PUT',['approved'=>1,'reason'=>'Ya resuelta']);
$transfer=phase3Ok('/api/structures/' . $shared . '/members/' . $membership['D'] . '/transfers','POST',['mode'=>'equipo','destination'=>$membership['C'],'reason'=>'Mover equipo autorizado']);
phase3Ok('/api/structures/' . $shared . '/transfers/' . $transfer['id'],'PUT',['approved'=>1,'reason'=>'Coordinación aprobada']);
verify(phase3Ok('/api/structures/' . $shared . '/members/' . $membership['E'])['responsible']===$membership['D'],'Aprobación preserva las relaciones internas.');
phase3Status(409,'/api/structures/' . $shared . '/members/' . $membership['C'] . '/state','PUT',['active'=>0,'replacement'=>$membership['B']]);
$stale=phase3Ok('/api/structures/' . $shared . '/members/' . $membership['D'] . '/transfers','POST',['mode'=>'equipo','destination'=>$membership['B'],'reason'=>'Solicitud que quedará obsoleta']);
phase3Ok('/api/structures/' . $shared . '/members/' . $membership['C'] . '/level','PUT',['levels'=>[$elections[0]=>$levels[$elections[0]][2]]]);
phase3Status(409,'/api/structures/' . $shared . '/transfers/' . $stale['id'],'PUT',['approved'=>1,'reason'=>'Debe revisarse nuevamente']);
phase3Closure();

// Una copia completa se asocia a otra elección sin duplicar identidades.
phase3Ok('/api/structures/' . $independent . '/members/' . $independentMember . '/state','PUT',['active'=>0]);
$clone=phase3Ok('/api/structures/' . $shared . '/clone','POST',['name'=>'Copia independiente','elections'=>[$elections[2]]])['id'];
phase3Ok('/api/auth/election','POST',['process'=>$elections[2]]);
$cloneMembers=phase3Ok('/api/structures/' . $clone)['members'];
verify(count($cloneMembers)===6,'Clonación copia todos los integrantes.');
verify((int) $connection->query('SELECT COUNT(*) FROM personas')->fetchColumn()===$beforePersons,'Clonación no crea fichas duplicadas.');
$cloneByPerson=array_column($cloneMembers,null,'person');
phase3Ok('/api/structures/' . $clone . '/members/' . $cloneByPerson[$personIds['E']]['id'] . '/state','PUT',['active'=>0]);
phase3Ok('/api/auth/election','POST',['process'=>$elections[0]]);
verify(phase3Ok('/api/structures/' . $shared . '/members/' . $membership['E'])['active']===1,'Cambiar la copia no altera el original.');
phase3Closure();
phase3Ok('/api/organizations/' . $organizationIds[0] . '/modules/estructura','PUT',['election'=>$elections[1],'selected'=>0]);
$beforeHistory=(int) $connection->query('SELECT COUNT(*) FROM estructura_historial')->fetchColumn();
phase3Status(403,'/api/structures/' . $shared . '/members/' . $membership['A'] . '/level','PUT',['levels'=>[$elections[0]=>$levels[$elections[0]][1]]]);
verify((int) $connection->query('SELECT COUNT(*) FROM estructura_historial')->fetchColumn()===$beforeHistory,'Un módulo apagado bloquea cambios compartidos sin escribir historial.');
phase3Ok('/api/auth/election','POST',['process'=>$elections[1]]);
phase3Status(403,'/api/structures');
phase3Status(200,'/api/persons');
verify((int) $connection->query('SELECT COUNT(*) FROM estructura')->fetchColumn()===13,'Desactivar módulo conserva sus integrantes y copias.');
phase3Ok('/api/organizations/' . $organizationIds[0] . '/modules/estructura','PUT',['election'=>$elections[1],'selected'=>1]);
phase3Ok('/api/auth/election','POST',['process'=>$elections[0]]);
verify(count(phase3Ok('/api/structures/' . $shared . '/history'))>0,'Historial estructural consultable.');
phase3Status(403,'/api/structures/' . $shared . '/members','POST',['person'=>$personIds['A']],'other');

// Asociación posterior, alta rápida y selección explícita de participaciones.
$fourth=phase3Ok('/api/organizations/' . $organizationIds[0] . '/electoral-process','POST',['process'=>'Cuarta elección Fase 3','type'=>1,'character'=>1,'status'=>1])['id'];
$fifth=phase3Ok('/api/organizations/' . $organizationIds[0] . '/electoral-process','POST',['process'=>'Quinta elección Fase 3','type'=>1,'character'=>1,'status'=>1])['id'];
$newShared=phase3Ok('/api/structures','POST',['name'=>'Asociación posterior','elections'=>[$fourth]])['id'];
phase3Ok('/api/structures/' . $newShared . '/elections','POST',['election'=>$fifth]);
$quickMember=phase3Ok('/api/structures/' . $newShared . '/members','POST',['new_person'=>['name'=>'Alta rápida compartida'],'participations'=>[$fourth]]);
phase3Ok('/api/auth/election','POST',['process'=>$fourth]);
verify(count(phase3Ok('/api/structures/' . $newShared)['elections'])===2,'Asociar una elección reutiliza el mismo contenedor.');
$quickDetail=phase3Ok('/api/persons/' . $quickMember['person']);
verify(count($quickDetail['participations'])===1 && $quickDetail['participations'][0]['id']===$fourth,'La incorporación compartida registra sólo las participaciones seleccionadas.');
verify((int) $connection->query("SELECT COUNT(*) FROM estructura WHERE persona=(SELECT id FROM personas WHERE nombre='Alta rápida compartida')")->fetchColumn()===1,'Estructura compartida mantiene una sola fuente física.');
$quickCount=(int) $connection->query('SELECT COUNT(*) FROM personas')->fetchColumn();
phase3Status(409,'/api/structures/' . $newShared . '/members','POST',['new_person'=>['name'=>'Alta rápida compartida']]);
verify((int) $connection->query('SELECT COUNT(*) FROM personas')->fetchColumn()===$quickCount,'Alta rápida conserva detección de duplicados sin crear una ficha extra.');
phase3Ok('/api/structures/' . $newShared . '/elections','POST',['election'=>$fifth,'active'=>0]);
phase3Ok('/api/structures/' . $newShared . '/members/' . $quickMember['id'] . '/state','PUT',['active'=>0]);
phase3Ok('/api/structures/' . $newShared . '/members/' . $quickMember['id'] . '/state','PUT',['active'=>1]);
verify((int) $connection->query('SELECT ep.activo FROM estructura_integrantes_procesos ep JOIN estructura e ON e.id=ep.integrante JOIN estructuras_organizacionales c ON c.id=e.contenedor WHERE c.nombre="Asociación posterior" AND ep.proceso=(SELECT id FROM procesos_electorales WHERE proceso="Quinta elección Fase 3")')->fetchColumn()===0,'Reactivar no recupera asociaciones electorales retiradas.');

// Promoción únicamente por primera incorporación exitosa y autorizada.
$promotionElection=phase3Ok('/api/organizations/' . $organizationIds[0] . '/electoral-process','POST',['process'=>'Promoción Fase 3','type'=>1,'character'=>1,'status'=>1])['id'];
$promotionLevels=[];
foreach ([1=>['Dirección',1],2=>['Primer nivel de reclutamiento',1],3=>['Integrante',0]] as $order=>[$name,$capacity]) $promotionLevels[$order]=phase3Ok('/api/structure-levels','POST',['election'=>$promotionElection,'name'=>$name,'order'=>$order,'can_recruit'=>$capacity,'same_level'=>1])['id'];
$promotionStructure=phase3Ok('/api/structures','POST',['name'=>'Equipo de incorporación','elections'=>[$promotionElection]])['id'];
$promoterPerson=phase3Ok('/api/persons','POST',['organization'=>$organizationIds[0],'name'=>'Persona autorizada para incorporar'])['id'];
$promoterMember=phase3Ok('/api/structures/' . $promotionStructure . '/members','POST',['person'=>$promoterPerson,'levels'=>[$promotionElection=>$promotionLevels[3]]])['id'];
$promoterAccount=phase3Ok('/api/users','POST',['organization'=>$organizationIds[0],'username'=>'fase3-incorpora','password'=>'Fase3 incorporación segura','type'=>8,'name'=>'Incorporación','person'=>$promoterPerson,'process'=>$promotionElection])['id'];
phase3Ok('/api/auth/election','POST',['process'=>$promotionElection]);
verify(phase3Ok('/api/structures/' . $promotionStructure . '/members/' . $promoterMember)['level']===$promotionLevels[3],'Crear una cuenta no promueve al integrante.');
$phase3Tokens['promoter']=login('promoter','fase3-incorpora','Fase3 incorporación segura');
$incorporation=['new_person'=>['name'=>'Primera incorporación'],'responsible'=>$promoterMember,'levels'=>[$promotionElection=>$promotionLevels[3]]];
phase3Status(403,'/api/structures/' . $promotionStructure . '/members','POST',$incorporation,'promoter');
phase3Grant($promoterAccount,'estructura.formar',$promotionElection,'propio'); phase3Grant($promoterAccount,'estructura.ver',$promotionElection,'organizacion');
phase3Ok('/api/structure-config','PUT',['approval_required'=>0,'auto_promote'=>0]);
phase3Status(403,'/api/structures/' . $promotionStructure . '/members','POST',$incorporation,'promoter');
phase3Ok('/api/structure-config','PUT',['approval_required'=>0,'auto_promote'=>1]);
$beforeFailed=(int) $connection->query('SELECT COUNT(*) FROM personas')->fetchColumn();
phase3Status(422,'/api/structures/' . $promotionStructure . '/members','POST',[...$incorporation,'levels'=>[$promotionElection=>$promotionLevels[1]]],'promoter');
verify((int) $connection->query('SELECT COUNT(*) FROM personas')->fetchColumn()===$beforeFailed,'Una incorporación fallida revierte también el alta de identidad.');
verify(phase3Ok('/api/structures/' . $promotionStructure . '/members/' . $promoterMember)['level']===$promotionLevels[3],'Una incorporación fallida revierte la promoción.');
phase3Ok('/api/structures/' . $promotionStructure . '/members','POST',$incorporation,'promoter');
verify(phase3Ok('/api/structures/' . $promotionStructure . '/members/' . $promoterMember)['level']===$promotionLevels[2],'Primera incorporación promueve al primer nivel habilitado para reclutar.');
verify(phase3Ok('/api/users/' . $promoterAccount . '/accesses')['accesses'][0]['type']===8,'La promoción organizacional no cambia el rol de usuario.');
phase3Ok('/api/structures/' . $promotionStructure . '/members','POST',[...$incorporation,'new_person'=>['name'=>'Segunda incorporación']],'promoter');
verify((int) $connection->query("SELECT COUNT(*) FROM estructura_historial WHERE accion='promocion_primera_incorporacion'")->fetchColumn()===1,'No se inventan promociones adicionales.');
phase3Status(409,'/api/users/' . $promoterAccount . '/permissions','PUT',['permission'=>'estructura.formar','election'=>$promotionElection,'scope'=>'propio','value'=>0]);
phase3Status(409,'/api/users/' . $promoterAccount . '/accesses','POST',['process'=>$promotionElection,'type'=>8,'active'=>0]);
$newRole=phase3Ok('/api/roles','POST',['name'=>'Rol configurable Fase 3'])['id'];
verify(in_array($newRole,array_column(phase3Ok('/api/roles'),'id'),false),'Super Administrador ve roles propios de la organización seleccionada.');
verify(in_array($newRole,array_column(phase3Ok('/api/users-types')['users_types'],'id'),false),'Selector de cuentas reutiliza los roles configurados.');
phase3Ok('/api/roles/' . $newRole . '/permissions','PUT',['permission'=>'personas.ver','election'=>$promotionElection,'scope'=>'propio','value'=>1]);
verify(count(phase3Ok('/api/roles/' . $newRole . '/permissions'))===1,'Rol base configurable con permiso electoral independiente del nivel.');
phase3Ok('/api/roles/8/permissions','PUT',['context'=>'global','permission'=>'personas.ver','scope'=>'propio','value'=>1]);
$promoterNumeric=(int) $connection->query("SELECT id FROM usuarios WHERE usuario='fase3-incorpora'")->fetchColumn();
verify(in_array('personas.ver',(new App\Repositories\PermissionRepository($connection))->effective($promoterNumeric,null)['rights'],true),'Permiso global heredado existe sin contexto electoral.');
phase3Ok('/api/users/' . $promoterAccount . '/permissions','PUT',['permission'=>'PERSONAS.VER','election'=>$promotionElection,'scope'=>'organizacion','value'=>0]);
phase3Status(403,'/api/persons','GET',null,'promoter');
phase3Grant($promoterAccount,'personas.ver',$promotionElection,'organizacion');
$scopePerson=phase3Ok('/api/persons','POST',['organization'=>$organizationIds[0],'name'=>'Persona con alcance limitado'])['id'];
$scopeUser=phase3Ok('/api/users','POST',['organization'=>$organizationIds[0],'username'=>'fase3-alcance-limitado','password'=>'Alcance seguro prueba','type'=>8,'name'=>'Alcance limitado','process'=>$promotionElection,'person'=>$scopePerson])['id'];
$scopeRoles=[];
foreach (['propio','organizacion'] as $scope) {
    $role=phase3Ok('/api/roles','POST',['name'=>'Alcance ' . $scope])['id']; $scopeRoles[$scope]=$role;
    foreach (['usuarios.administrar'=>'propio','personas.ver'=>$scope] as $permission=>$permissionScope) phase3Ok('/api/roles/' . $role . '/permissions','PUT',['permission'=>$permission,'election'=>$promotionElection,'scope'=>$permissionScope,'value'=>1]);
}
phase3Ok('/api/users/' . $scopeUser . '/accesses','POST',['process'=>$promotionElection,'type'=>$scopeRoles['propio']]);
$phase3Tokens['scoped']=login('scoped','fase3-alcance-limitado','Alcance seguro prueba');
phase3Status(403,'/api/users/' . $scopeUser . '/accesses','POST',['process'=>$promotionElection,'type'=>$scopeRoles['organizacion']],'scoped');
verify(phase3Ok('/api/users/' . $scopeUser . '/accesses')['accesses'][0]['type']===$scopeRoles['propio'],'Cambiar a un rol con los mismos permisos no permite ampliar sus alcances.');

// Dos procesos reales escriben al mismo tiempo, con conexiones diferentes.
function phase3Concurrent(array $commands): array {
    global $environment,$temporaryDirectory;
    $workers=[];
    foreach ($commands as $index=>$command) {
        $worker=proc_open([PHP_BINARY,__DIR__ . '/structure-writer.php',...$command],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',$temporaryDirectory . '/structure-writer-' . $index . '.log','a']],$pipes,dirname(__DIR__),$environment);
        fclose($pipes[0]); $workers[]=[$worker,$pipes[1]];
    }
    $results=[];
    foreach ($workers as [$worker,$output]) { $result=stream_get_contents($output); fclose($output); verify(proc_close($worker)===0,'Escritor concurrente termina sin fallo técnico.'); $results[]=json_decode($result,true,512,JSON_THROW_ON_ERROR); }
    return $results;
}
$racePerson=phase3Ok('/api/persons','POST',['organization'=>$organizationIds[0],'name'=>'Persona concurrente de estructura'])['id'];
$raceResults=phase3Concurrent([['add',$newShared,$racePerson,'1'],['add',$newShared,$racePerson,'1']]);
verify(count(array_filter($raceResults,static fn ($row) => $row['success']))===1 && count(array_filter($raceResults,static fn ($row) => ($row['status'] ?? null)===409))===1,'Incorporación concurrente conserva una sola identidad estructural.');
$raceAccounts=[];
foreach (['a','b'] as $suffix) $raceAccounts[]=phase3Ok('/api/users','POST',['organization'=>$organizationIds[0],'username'=>'fase3-concurrente-' . $suffix,'password'=>'Prueba concurrente segura','type'=>8,'name'=>'Cuenta concurrente'])['id'];
$raceResults=phase3Concurrent([['link',$raceAccounts[0],$racePerson,'1'],['link',$raceAccounts[1],$racePerson,'1']]);
verify(count(array_filter($raceResults,static fn ($row) => $row['success']))===1 && count(array_filter($raceResults,static fn ($row) => ($row['status'] ?? null)===409))===1,'Vinculación concurrente conserva una sola cuenta por persona.');
phase3Ok('/api/structures/' . $newShared . '/members/' . $quickMember['id'] . '/state','PUT',['active'=>0]);
$historicalClone=phase3Ok('/api/structures/' . $newShared . '/clone','POST',['name'=>'Copia con integrante histórico','elections'=>[$fifth]])['id'];
phase3Ok('/api/auth/election','POST',['process'=>$fifth]);
$historicalMembers=phase3Ok('/api/structures/' . $historicalClone)['members'];
verify(count($historicalMembers)===2 && count(array_filter($historicalMembers,static fn ($member) => !$member['active']))===1,'La clonación incluye también integrantes inactivos sin perder su estado.');
phase3Ok('/api/auth/election','POST',['process'=>$promotionElection]);
$commonAccount=phase3Ok('/api/users','POST',['organization'=>$organizationIds[0],'username'=>'fase3-superior-comun','password'=>'Aprobación segura prueba','type'=>8,'name'=>'Superior común','person'=>$personIds['A'],'process'=>$elections[0]])['id'];
phase3Ok('/api/users/' . $commonAccount . '/accesses','POST',['process'=>$elections[1],'type'=>8]);
foreach ([$elections[0],$elections[1]] as $election) phase3Grant($commonAccount,'estructura.aprobar_transferencias',$election);
$commonRequest=phase3Ok('/api/structures/' . $shared . '/members/' . $membership['D'] . '/transfers','POST',['mode'=>'integrante','destination'=>$membership['B'],'replacement'=>$membership['C'],'reason'=>'Aprobar mediante superior común']);
$commonNumeric=(int) $connection->query("SELECT id FROM usuarios WHERE usuario='fase3-superior-comun'")->fetchColumn();
verify((int) $connection->query('SELECT aprobador FROM estructura_transferencias ORDER BY id DESC LIMIT 1')->fetchColumn()===$commonNumeric,'Superior común autorizado resulta elegido como aprobador.');
phase3Status(403,'/api/structures/' . $shared . '/transfers/' . $commonRequest['id'],'PUT',['approved'=>1,'reason'=>'Aprobador incorrecto'],'manager');
$phase3Tokens['common']=login('common','fase3-superior-comun','Aprobación segura prueba');
phase3Ok('/api/auth/election','POST',['process'=>$elections[0]],'common');
phase3Ok('/api/structures/' . $shared . '/transfers/' . $commonRequest['id'],'PUT',['approved'=>1,'reason'=>'Resolución del superior común'],'common');
phase3Ok('/api/auth/election','POST',['process'=>$elections[0]]);
verify(phase3Ok('/api/structures/' . $shared . '/members/' . $membership['D'])['responsible']===$membership['B'],'Aprobador con permiso específico resuelve sin convertirse en administrador de estructura.');
verify(phase3Ok('/api/structures/' . $shared . '/members/' . $membership['E'])['responsible']===$membership['C'],'Aprobación individual conserva y reasigna explícitamente el equipo directo.');
phase3Ok('/api/auth/election','POST',['process'=>$elections[2]]);
verify(phase3Ok('/api/structures/' . $clone . '/members/' . $cloneByPerson[$personIds['D']]['id'])['responsible']===$cloneByPerson[$personIds['C']]['id'],'Mover el original tampoco modifica la copia independiente.');
phase3Ok('/api/auth/election','POST',['process'=>$promotionElection]);
phase3Closure();
phase3Status(200,'/structure/'); phase3Status(200,'/administration/');
echo 'Fase 3: ' . ($checks-$phase3Start) . " comprobaciones adicionales correctas.\n";
