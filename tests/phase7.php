<?php

// integration.php crea el único contexto permitido: BD, cookies y storage desechables.
use App\Services\Common\Values;
$phase7Start=$checks;
function phase7Api(string $path,string $method='GET',?array $data=null,string $client='admin',int $status=200): array {
    $response=phase3Api(str_starts_with($path,'/api/') ? $path : '/api/jornada/'.$path,$method,$data,$client);
    verify($response['status']===$status || ($status===200 && $response['status']===201),$method.' '.$path.' esperaba '.$status.': '.$response['body']);return $response['json']['data'] ?? [];
}
$phase7Election=phase7Api('/api/organizations/'.$organizationIds[0].'/electoral-process','POST',['process'=>'Jornada Fase 7','type'=>1,'character'=>1])['id'];
phase7Api('/api/auth/election','POST',['process'=>$phase7Election]);
$phase7Process=(int)$phase5Repository->one('SELECT id FROM procesos_electorales WHERE uuid=?',[Values::binary($phase7Election)])['id'];
phase7Api('/api/organizations/'.$organizationIds[0].'/modules/jornada_electoral','PUT',['authorized'=>1,'selected'=>1]);
$phase7Config=phase7Api('configuration','PUT',['zona'=>'America/Monterrey','captura_local'=>gmdate('Y-m-d\TH:i',time()-86400),'politica_inicial'=>'preliminar','foto_obligatoria'=>0,'denominador'=>'total_acta','fecha_eleccion'=>date('Y-m-d')]);
verify($phase7Config['configuration']['estado']==='preparacion','La configuración no abre la Jornada automáticamente.');
phase7Api('state/abrir','POST',['version'=>$phase7Config['configuration']['version'],'motivo'=>'Apertura incompleta'],'admin',409);
$phase7Parties=[];
foreach(['PAN','PRI'] as $name)$phase7Parties[]=phase7Api('catalogs/partidos','POST',['nombre'=>$name,'siglas'=>$name])['uuid'];
phase7Api('catalogs/partidos','POST',['nombre'=>'PAN','siglas'=>'PAN'],'admin',409);
$phase7Candidate=phase7Api('catalogs/candidaturas','POST',['nombre'=>'Candidatura de prueba A','cargo'=>'Cargo de prueba'])['uuid'];
phase7Api('catalogs/coaliciones','POST',['nombre'=>'Coalición incompleta','partidos'=>[$phase7Parties[0]]],'admin',422);
$phase7Coalition=phase7Api('catalogs/coaliciones','POST',['nombre'=>'Coalición configurada','candidatura'=>$phase7Candidate,'partidos'=>$phase7Parties])['uuid'];
verify(count(phase7Api('configuration')['catalogs']['opciones'])===0,'Crear coalición no inventa combinaciones ni filas de acta.');
$phase7Options=[];
foreach([['PAN','partido',$phase7Parties[0]],['PRI','partido',$phase7Parties[1]],['PAN + PRI','combinacion',null],['Nulos','nulos',null]] as $index=>[$name,$type,$party])$phase7Options[]=phase7Api('catalogs/opciones','POST',['nombre'=>$name,'tipo'=>$type,'orden'=>$index+1,'partido'=>$party,'coalicion'=>$index===2 ? $phase7Coalition : null,'candidatura'=>$index<3 ? $phase7Candidate : null,'suma_candidatura'=>$index<3 ? 1 : 0,'clasificacion'=>$index===3 ? 'nulo' : 'valido'])['uuid'];
phase7Api('catalogs/opciones','POST',['nombre'=>'Fila con orden ocupado','tipo'=>'otro','orden'=>1],'admin',409);
$phase7Section=Values::uuidText($phase5Repository->one('SELECT uuid FROM secciones_electorales ORDER BY id LIMIT 1')['uuid']);
$phase7Booths=[];
foreach(['Básica F7','Contigua F7','Extraordinaria F7',...array_map(static fn($n)=>'Casilla concurrente F7 '.$n,range(3,11))] as $name)$phase7Booths[]=phase7Api('booths','POST',['seccion'=>$phase7Section,'nombre'=>$name,'tipo'=>'BASICA'])['uuid'];
$phase7Accounts=[];$phase7AccountIds=[];
foreach([0,1] as $index) {
    $account=phase7Api('/api/users','POST',['organization'=>$organizationIds[0],'username'=>'fase7-captura-'.$index,'password'=>'Fase7 captura segura','type'=>3,'name'=>'Capturista de prueba '.$index]);$phase7Accounts[]=$account['id'];
    phase7Api('/api/users/'.$account['id'].'/accesses','POST',['process'=>$phase7Election,'type'=>3]);
    foreach(['resultados.capturar','resultados.ver','archivos.subir','archivos.ver','archivos.eliminar'] as $permission)phase3Grant($account['id'],$permission,$phase7Election,'propio');
    $phase3Tokens['phase7-'.$index]=login('phase7-'.$index,'fase7-captura-'.$index,'Fase7 captura segura');
    phase7Api('/api/auth/election','POST',['process'=>$phase7Election],'phase7-'.$index);
    $phase7AccountIds[]=(int)$phase5Repository->one('SELECT id FROM usuarios WHERE uuid=?',[Values::binary($account['id'])])['id'];
}
phase7Api('assignments','PUT',['casillas'=>array_slice($phase7Booths,0,2),'usuarios'=>$phase7Accounts,'activo'=>1]);
phase7Api('assignments','PUT',['casillas'=>[$phase7Booths[2]],'usuarios'=>[$phase7Accounts[0]],'activo'=>1]);
phase7Api('assignments','PUT',['casillas'=>array_slice($phase7Booths,3),'usuarios'=>$phase7Accounts,'activo'=>1]);
verify(count(phase7Api('accounts?assigned=1')['rows'])===2,'Filtro de capturistas ofrece sólo cuentas con asignaciones visibles.');
verify(count(phase7Api('booths?usuario='.$phase7Accounts[1])['rows'])===11,'Filtro por capturista aplica las asignaciones activas.');
$phase7Config=phase7Api('configuration');verify($phase7Config['checklist']['listo'],'Checklist verifica acta, casillas y cuentas asignadas.');
phase7Api('state/abrir','POST',['version'=>$phase7Config['configuration']['version'],'motivo'=>'Inicio de prueba']);
function phase7Draft(string $booth,string $client='phase7-0'): string {return phase7Api('drafts','POST',['casilla'=>$booth,'clave'=>Values::uuid()],$client)['uuid'];}
function phase7CaptureData(string $booth,array $numbers=[100,80,20,0],string $client='phase7-0'): array {global $phase7Options;return ['casilla'=>$booth,'borrador'=>phase7Draft($booth,$client),'clave'=>Values::uuid(),'votos'=>array_combine($phase7Options,$numbers),'notas'=>'Acta ficticia de pruebas'];}
$phase7First=phase7Api('captures','POST',phase7CaptureData($phase7Booths[0]),'phase7-0');
verify($phase7First['estado']==='preliminar' && $phase7First['total']===200,'Política A publica una preliminar interna de 200 votos crudos.');
$summary=phase7Api('summary?modo=preliminar');verify($summary['total_acta']===200 && $summary['candidaturas'][0]['votos']===200,'PAN 100 + PRI 80 + combinación 20 derivan 200, sin duplicar 20.');
verify(array_column($summary['opciones'],'votos')===[100,80,20,0],'Concentrado conserva cada fila exacta de votos crudos.');
verify(phase7Api('summary?modo=validado')['total_acta']===0,'La preliminar no aparece en el concentrado validado.');
phase7Api('summary?modo=preliminar','GET',null,'phase7-0',403);
phase7Api('drafts','POST',['casilla'=>$phase7Booths[2],'clave'=>Values::uuid()],'phase7-1',403);
$phase7Second=phase7Api('captures','POST',phase7CaptureData($phase7Booths[0],[120,80,20,0],'phase7-1'),'phase7-1');
verify($phase7Second['estado']==='pendiente' && $phase7Second['segunda'],'Una segunda captura conserva la vigente y exige validación.');
verify(phase7Api('summary?modo=preliminar')['total_acta']===200,'La propuesta no altera el concentrado preliminar.');
phase7Api('versions/'.$phase7Second['uuid'],'PUT',['aprobar'=>1,'motivo'=>'Acta revisada','actual_esperada'=>$phase7First['uuid']]);
verify(phase7Api('summary?modo=validado')['total_acta']===220,'La versión validada sustituye atómicamente la contribución.');
verify(phase7Api('versions/'.$phase7First['uuid'])['estado']==='sustituida','La versión anterior permanece histórica.');
$phase7Pending=phase7Api('captures','POST',phase7CaptureData($phase7Booths[0],[130,80,20,0]),'phase7-0');
phase7Api('versions/'.$phase7Pending['uuid'],'PUT',['aprobar'=>1,'motivo'=>'Decisión con referencia obsoleta','actual_esperada'=>$phase7First['uuid']],'admin',409);
verify(phase7Api('summary?modo=validado')['total_acta']===220,'Una decisión obsoleta no sobrescribe la vigente.');
phase7Api('versions/'.$phase7Pending['uuid'],'PUT',['aprobar'=>0,'motivo'=>'La foto revisada conserva el dato anterior','actual_esperada'=>$phase7Second['uuid']]);
verify(phase7Api('versions/'.$phase7Pending['uuid'])['estado']==='rechazada','El rechazo conserva la propuesta sin contribuir.');
$phase7Correction=phase7Api('corrections','POST',['casilla'=>$phase7Booths[0],'clave'=>Values::uuid(),'actual_esperada'=>$phase7Second['uuid'],'votos'=>array_combine($phase7Options,[125,80,20,0]),'motivo'=>'Corrección justificada de la fila PAN','validar'=>1]);
verify($phase7Correction['estado']==='validada' && phase7Api('summary?modo=validado')['total_acta']===225,'Corrección con permiso explícito crea y valida otra versión.');
phase7Api('catalogs/opciones/'.$phase7Options[0],'PUT',['nombre'=>'Alteración histórica','tipo'=>'partido','orden'=>1],'admin',409);
phase7Api('configuration','PUT',['zona'=>'UTC','captura_local'=>'2026-10-13T12:00','politica_inicial'=>'validar','denominador'=>'total_acta','version'=>3],'admin',409);
verify(count(phase7Api('booths/'.$phase7Booths[0].'/history')['rows'])===4,'El historial mantiene las cuatro versiones y sus votos originales.');
file_put_contents($temporaryDirectory.'/phase7-fixtures.json',json_encode(['election'=>$phase7Election,'process'=>$phase7Process,'booths'=>$phase7Booths,'options'=>$phase7Options,'accounts'=>$phase7Accounts,'current'=>$phase7Correction['uuid']],JSON_PRETTY_PRINT));
require __DIR__.'/phase7-coverage.php';
echo 'Fase 7: '.($checks-$phase7Start)." comprobaciones adicionales correctas.\n";
