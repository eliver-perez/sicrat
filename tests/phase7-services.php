<?php

use App\Services\Common\Values;
use App\Services\Common\ActorContext;
use App\Services\Common\WorkerService;
use App\Services\Common\NotificationService;
use App\Services\Common\NotificationProvider;
use App\Services\Jornada\Notifications;
use App\Services\Jornada\Exports;

// Exportadores registrados, tres formatos reales y permisos revalidados.
phase3Grant($phase7Accounts[1],'exportaciones.descargar',$phase7Election,'propio');
foreach(array_keys(Exports::DEFINITIONS) as $type)foreach(['pdf','xlsx','csv'] as $format) {
    $export=phase7Api('/api/exports','POST',['type'=>$type,'format'=>$format,'filters'=>['modo'=>'validado']],'admin',202);
    (new WorkerService($phase5Repository))->run(10,30,'exportacion');
    $file=request('admin','GET','/api/exports/'.$export['uuid'].'/download');verify($file['status']===200,'Descarga autorizada de '.$type.' '.$format.': '.$file['body']);
    if($format==='pdf')verify(str_starts_with($file['body'],'%PDF-') && str_contains($file['body'],'%%EOF'),'PDF válido de '.$type);
    if($format==='xlsx') { $path=$temporaryDirectory.'/'.$type.'.xlsx';file_put_contents($path,$file['body']);$zip=new ZipArchive();verify($zip->open($path)===true,'XLSX real ZIP de '.$type);verify(str_contains($zip->getFromName('xl/workbook.xml'),'Informe') && str_contains($zip->getFromName('xl/workbook.xml'),'Datos'),'Excel incluye información del informe y datos');$zip->close(); }
    if($format==='csv') { verify(str_starts_with($file['body'],"\xEF\xBB\xBF"),'CSV UTF-8 de '.$type);verify(str_contains($file['body'],'Validado interno'),'CSV identifica el modo incluso en informes vacíos: '.$type); }
    verify(request('phase7-1','GET','/api/exports/'.$export['uuid'].'/download')['status']===404,'El archivo generado es privado de su solicitante.');
}
phase7Api('/api/exports','POST',['type'=>'jornada_candidaturas','format'=>'csv','filters'=>['modo'=>'preliminar']],'phase7-0',403);
$preliminaryExport=phase7Api('/api/exports','POST',['type'=>'jornada_opciones','format'=>'csv','filters'=>['modo'=>'preliminar']],'admin',202);(new WorkerService($phase5Repository))->run(10,30,'exportacion');
$preliminaryFile=request('admin','GET','/api/exports/'.$preliminaryExport['uuid'].'/download');verify($preliminaryFile['status']===200 && str_contains($preliminaryFile['body'],'Preliminar interno') && str_contains($preliminaryFile['body'],'No constituyen resultados oficiales.'),'CSV preliminar conserva números e identifica el resultado interno.');
$actor=new ActorContext($phase5Repository);
$recipient=(int)$phase5Repository->one('SELECT id FROM usuarios WHERE uuid=?',[Values::binary($phase5Accounts[0])])['id'];
$person=(int)$phase5Repository->one('SELECT persona FROM usuarios WHERE id=?',[$recipient])['persona'];
phase7Api('/api/users/'.$phase5Accounts[0].'/accesses','POST',['process'=>$phase7Election,'type'=>2]);
phase7Api('/api/notification-config','PUT',['email'=>1,'whatsapp'=>1]);
$phase5Repository->execute("INSERT INTO canales_contacto(persona,tipo,valor,principal,verificado,permite_contacto,f_consentimiento) SELECT ?,id,'jornada@example.invalid',1,1,1,NOW() FROM canales_contacto_tipos WHERE codigo='EMAIL'",[$person]);
$setting=['usuario'=>$phase5Accounts[0],'canal'=>'email','modo'=>'validado','frecuencia'=>30,'activo'=>1];phase7Api('notifications','PUT',$setting);
$now=new DateTimeImmutable();$actor->run(1,$phase7Process,fn()=>(new Notifications($phase5Repository))->schedule($now));
$before=(int)$phase5Repository->one("SELECT COUNT(*) n FROM trabajos WHERE tipo='jornada_resumen'")['n'];
$actor->run(1,$phase7Process,fn()=>(new Notifications($phase5Repository))->schedule($now));
verify((int)$phase5Repository->one("SELECT COUNT(*) n FROM trabajos WHERE tipo='jornada_resumen'")['n']===$before,'Scheduler respeta frecuencia e idempotencia, sin avisos por refrescar la pantalla.');
(new WorkerService($phase5Repository))->run(5,20,'jornada_resumen');
$delivery=$phase5Repository->one('SELECT e.* FROM jornada_envios j JOIN notificaciones_envios e ON e.id=j.envio ORDER BY e.fecha DESC LIMIT 1');
verify($delivery!==null,'El handler común prepara el resumen administrativo en cola.');
$content=json_decode($delivery['contenido'],true);verify(isset($content['summary']) && !str_contains($content['summary'],'Capturista de prueba') && !str_contains($content['summary'],'Ana Operación') && !str_contains($content['summary'],$phase7Photo),'Resumen externo contiene totales y progreso, sin personas ni actas privadas.');
$provider=new class implements NotificationProvider {public int $calls=0;public function assertReady(): void {}public function send(array $delivery): string {$this->calls++;return 'fake-jornada';}};
(new NotificationService($phase5Repository))->deliver($delivery['id'],$provider);(new NotificationService($phase5Repository))->deliver($delivery['id'],$provider);verify($provider->calls===1,'Entrega simulada se procesa una vez, sin proveedor externo real.');
$next=$now->modify('+31 minutes');$actor->run(1,$phase7Process,fn()=>(new Notifications($phase5Repository))->schedule($next));(new WorkerService($phase5Repository))->run(5,20,'jornada_resumen');
$latest=$phase5Repository->one('SELECT e.* FROM jornada_envios j JOIN notificaciones_envios e ON e.id=j.envio WHERE e.id<>? ORDER BY e.fecha DESC LIMIT 1',[$delivery['id']]);verify($latest!==null,'Una frecuencia nueva programa otro resumen.');
phase3Grant($phase5Accounts[0],'resultados.ver',$phase7Election,'eleccion',0);
try{(new NotificationService($phase5Repository))->deliver($latest['id'],$provider);verify(false,'No envía tras revocación.');}catch(App\Services\Common\ServiceFailure){verify($provider->calls===1,'Permisos del destinatario se revalidan antes de tocar el proveedor.');}
phase3Grant($phase5Accounts[0],'resultados.ver',$phase7Election,'eleccion');
$actor->run(1,$phase7Process,fn()=>(new Notifications($phase5Repository))->schedule($now->modify('+62 minutes')));(new WorkerService($phase5Repository))->run(5,20,'jornada_resumen');
$changed=$phase5Repository->one('SELECT e.id FROM jornada_envios j JOIN notificaciones_envios e ON e.id=j.envio WHERE e.id<>? AND e.id<>? ORDER BY e.fecha DESC LIMIT 1',[$delivery['id'],$latest['id']]);verify($changed!==null,'Se prepara un resumen antes de cambiar la configuración.');
phase7Api('notifications','PUT',[...$setting,'incluir_totales'=>0]);
try{(new NotificationService($phase5Repository))->deliver($changed['id'],$provider);verify(false,'No envía contenido de una configuración obsoleta.');}catch(App\Services\Common\ServiceFailure){verify($provider->calls===1,'El contenido y versión del aviso se revalidan antes del proveedor.');}
phase7Api('notifications','PUT',[...$setting,'activo'=>0]);
phase7Api('notifications','PUT',[...$setting,'usuario'=>$phase7Accounts[0]],'admin',422);
phase7Api('notifications','PUT',[...$setting,'incluir_totales'=>0,'incluir_avance'=>0,'incluir_estado'=>0],'admin',422);
phase7Api('notifications','PUT',[...$setting,'frecuencia'=>1],'admin',422);
verify((int)$phase5Repository->one("SELECT COUNT(*) n FROM auditoria WHERE proceso=? AND entidad='jornada_versiones' AND accion IN('capturar','corregir','validar','rechazar')",[$phase7Process])['n']>15,'Auditoría común conserva capturas, correcciones y decisiones.');
$notices=phase7Api('/api/notifications','GET',null,'phase7-0');verify((bool)array_filter($notices['rows'],static fn($row)=>str_starts_with($row['tipo'],'jornada.')),'Capturista recibe notificaciones internas relevantes.');
$config=phase7Api('configuration')['configuration'];phase7Api('state/cerrar','POST',['version'=>$config['version'],'motivo'=>'Cierre tras completar la validación']);
phase7Api('corrections','POST',['casilla'=>$phase7Booths[0],'clave'=>Values::uuid(),'actual_esperada'=>$phase7Correction['uuid'],'votos'=>array_combine($phase7Options,[1,0,0,0]),'motivo'=>'Bloqueado por cierre'],'admin',409);
$actual=phase7Api('booths/'.$phase7Booths[0])['actual_esperada'];phase7Api('versions/'.$foreignPending['uuid'],'PUT',['aprobar'=>0,'motivo'=>'Revisión autorizada tras cierre','actual_esperada'=>$actual]);
verify(phase7Api('versions/'.$foreignPending['uuid'])['estado']==='rechazada','Cerrar la Jornada conserva la revisión autorizada de propuestas pendientes.');
$config=phase7Api('configuration')['configuration'];phase7Api('state/reabrir','POST',['version'=>$config['version'],'motivo'=>'Reapertura para revisión visual final']);
