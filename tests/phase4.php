<?php

// Requiere integration.php --phase4. Todos los datos, workers y destinos son desechables.
use App\Repositories\CommonRepository;
use App\Services\Common\ActorContext;
use App\Services\Common\ApprovalService;
use App\Services\Common\AuditService;
use App\Services\Common\BackupArchive;
use App\Services\Common\BackupCrypto;
use App\Services\Common\BackupDestination;
use App\Services\Common\BackupService;
use App\Services\Common\DatabaseDump;
use App\Services\Common\ExportService;
use App\Services\Common\FileService;
use App\Services\Common\FilesystemBackupDestination;
use App\Services\Common\NotificationProvider;
use App\Services\Common\NotificationService;
use App\Services\Common\OperationLock;
use App\Services\Common\PrivateStorage;
use App\Services\Common\QueueService;
use App\Services\Common\RestoreService;
use App\Services\Common\Sanitizer;
use App\Services\Common\SchedulerService;
use App\Services\Common\ServiceFailure;
use App\Services\Common\Values;
use App\Services\Common\WorkerService;

$phase4Start=$checks;
function phase4Reject(callable $work,string $description,?int $status = null): void {
	try { $work(); } catch (Throwable $error) { verify($status===null || ($error instanceof App\Core\HttpError && $error->statusCode===$status),$description.': '.$error->getMessage()); return; }
	throw new RuntimeException('No rechazó: '.$description);
}
function phase4Api(string $path,string $method = 'GET',?array $data = null,string $client = 'admin',int $status = 200): array {
	$response=phase3Api($path,$method,$data,$client); verify($response['status']===$status || ($status===200 && $response['status']===201),$method.' '.$path.': '.$response['body']); return $response['json']['data'] ?? [];
}
function phase4Upload(string $name,string $path,string $record,string $client = 'admin',?string $mime = null,?string $csrf = null): array {
	global $baseUrl,$temporaryDirectory,$adminToken,$phase3Tokens;
	$curl=curl_init($baseUrl.'/api/files');
	curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_TIMEOUT=>15,CURLOPT_COOKIEFILE=>$temporaryDirectory.'/'.$client.'.cookies',CURLOPT_HTTPHEADER=>['X-CSRF-Token: '.($csrf ?? $phase3Tokens[$client] ?? $adminToken)],CURLOPT_POSTFIELDS=>['entity'=>'personas','record'=>$record,'file'=>new CURLFile($path,$mime ?? 'application/octet-stream',$name)]]);
	$body=curl_exec($curl); return ['status'=>curl_getinfo($curl,CURLINFO_HTTP_CODE),'json'=>json_decode($body,true),'body'=>$body];
}
function phase4WorkerProcesses(array $environment,bool $claim = false): array {
	$command=[PHP_BINARY,__DIR__.'/queue-writer.php']; if ($claim) $command[]='--claim-only';
	$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__),$environment); fclose($pipes[0]); return [$process,$pipes];
}

phase4Api('/api/auth/election','POST',['process'=>$processIds[0]]);
$phase4Manager=phase4Api('/api/users','POST',['organization'=>$organizationIds[0],'username'=>'fase4-admin-cliente','password'=>'Fase4 cliente seguro','type'=>2,'name'=>'Administrador Fase 4']);
phase4Api('/api/users/'.$phase4Manager['id'].'/accesses','POST',['process'=>$processIds[0],'type'=>2]);
$phase3Tokens['phase4-manager']=login('phase4-manager','fase4-admin-cliente','Fase4 cliente seguro');
$repository=new CommonRepository($connection);
$_SESSION=['SICRAT_ID'=>1,'SICRAT_GLOBAL_TYPE_CODE'=>'superadmin','SICRAT_ORGANIZATION_PROCESS_ID'=>1];
$actors=new ActorContext($repository);
$audit=new AuditService($repository);
$filesRoot=new PrivateStorage($temporaryDirectory.'/private');
$_ENV['PRIVATE_STORAGE_PATH']=$filesRoot->root();
$_ENV['BACKUP_STORAGE_PATH']=$temporaryDirectory.'/backups';
$fileService=new FileService($repository,$filesRoot);
$queue=new QueueService($repository);
verify((new App\Core\Migrations($connection))->run()===[],'Fase 4 aplicada e idempotente en la base desechable.');

// Auditoría real y sanitización recursiva, con dos organizaciones y contexto global.
$audit->record(1,1,'prueba_fase4','personas',1,['nombre'=>'Anterior','password'=>'secreto','nested'=>['token'=>'abc','email'=>'privado@example.test']],['nombre'=>'Nuevo','curp'=>'DATO','password_hash'=>'hash'],['csrf_token'=>'invisible']);
$auditRow=$repository->one("SELECT * FROM auditoria WHERE accion='prueba_fase4' ORDER BY id DESC LIMIT 1");
verify((int) $auditRow['organizacion']===1 && (int) $auditRow['proceso']===1,'Auditoría conserva contexto.');
verify(json_decode($auditRow['datos_anteriores'],true)['nombre']==='Anterior' && json_decode($auditRow['datos_nuevos'],true)['nombre']==='Nuevo','Auditoría conserva antes y después.');
verify(!str_contains(Values::json($auditRow),'secreto') && !str_contains(Values::json($auditRow),'invisible') && !str_contains(Values::json($auditRow),'privado@example.test'),'Auditoría excluye secretos y datos personales innecesarios.');
$filtered=phase4Api('/api/audit?action=prueba_fase4&organization=1&process=1&limit=1&from='.date('Y-m-d').'&to='.date('Y-m-d'));
verify(count($filtered['rows'])===1 && !$filtered['pagination']['has_more'],'Filtros y paginación de auditoría.');
$paged=phase4Api('/api/audit?limit=1'); verify(count($paged['rows'])===1 && $paged['pagination']['has_more'],'Auditoría utiliza límite y sentinel.');
phase4Api('/api/audit','GET',null,'capture',403);
phase4Api('/api/audit?organization=2','GET',null,'phase4-manager',404);
phase4Api('/api/audit/'.$auditRow['id'],'PUT',['action'=>'borrar'],'admin',404);
verify($repository->one("SELECT id FROM auditoria WHERE entidad='permisos_usuarios' LIMIT 1")!==null,'Cambios de permisos anteriores están auditados.');
verify($repository->one("SELECT id FROM auditoria WHERE entidad='estructura_transferencias' LIMIT 1")!==null,'Solicitudes de transferencia Fase 3 están auditadas.');

// Aprobaciones: reglas entregadas por módulo, orden, asignación, caducidad y snapshot inmutable.
$person4=phase4Api('/api/persons','POST',['organization'=>$organizationIds[0],'name'=>'Evidencia Fase 4']);
phase4Api('/api/persons/'.$person4['id'].'/participations','POST',['processes'=>[$processIds[0]]]);
$person4Id=(int) $repository->one('SELECT id FROM personas WHERE uuid=?',[Values::binary($person4['id'])])['id'];
$managerId=(int) $repository->one("SELECT id FROM usuarios WHERE usuario='fase4-admin-cliente'")['id'];
$approvals=new ApprovalService($repository);
$snapshot=['rule'=>'específica','version'=>1,'secret'=>'omitido'];
$approval=$approvals->create(1,1,'personas',$person4Id,1,[1,$managerId],$snapshot);
$snapshot['version']=9;
$detail=phase4Api('/api/approvals/'.$approval);
verify($detail['regla']['version']===1 && !isset($detail['regla']['secret']),'Snapshot independiente de cambios posteriores y sin secretos.');
phase4Api('/api/approvals/'.$approval,'PUT',['approved'=>1,'reason'=>'Fuera de orden'],'phase4-manager',403);
phase4Api('/api/approvals/'.$approval,'PUT',['approved'=>1,'reason'=>'Primer paso']);
verify($repository->one('SELECT estado FROM aprobaciones WHERE uuid=?',[Values::binary($approval)])['estado']==='pendiente','Primer paso mantiene pendiente el flujo.');
phase4Api('/api/approvals/'.$approval,'PUT',['approved'=>1,'reason'=>'Segundo paso'],'phase4-manager');
verify($repository->one('SELECT estado FROM aprobaciones WHERE uuid=?',[Values::binary($approval)])['estado']==='aprobada','Varios pasos completan la aprobación.');
phase4Api('/api/approvals/'.$approval,'PUT',['approved'=>1,'reason'=>'Duplicado'],'admin',409);
$simpleApproval=$approvals->create(1,1,'personas',$person4Id,1,[1],['version'=>1]);
phase4Api('/api/approvals/'.$simpleApproval,'PUT',['approved'=>1,'reason'=>'Aprobación simple']);
verify($repository->one('SELECT estado FROM aprobaciones WHERE uuid=?',[Values::binary($simpleApproval)])['estado']==='aprobada','Un solo paso completa su solicitud.');
$rejected=$approvals->create(1,1,'personas',$person4Id,1,[$managerId],['version'=>1]);
phase4Api('/api/approvals/'.$rejected,'PUT',['approved'=>0,'reason'=>'Motivo de rechazo'],'phase4-manager');
verify($repository->one('SELECT estado FROM aprobaciones WHERE uuid=?',[Values::binary($rejected)])['estado']==='rechazada','Rechazo general registrado.');
$cancelled=$approvals->create(1,1,'personas',$person4Id,1,[1],[]);
phase4Api('/api/approvals/'.$cancelled.'/cancel','POST',['reason'=>'Ya no requerido']);
verify($repository->one('SELECT estado FROM aprobaciones WHERE uuid=?',[Values::binary($cancelled)])['estado']==='cancelada','Cancelación explícita conserva historia.');
$expiring=$approvals->create(1,1,'personas',$person4Id,1,[1],[],[],date('Y-m-d H:i:s',time()+60));
$repository->execute('UPDATE aprobaciones SET vence_en=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE uuid=?',[Values::binary($expiring)]);
phase4Api('/api/approvals/'.$expiring,'PUT',['approved'=>1,'reason'=>'Tarde'],'admin',409);
verify($approvals->expire()===1,'Expiración controlada por mantenimiento.');
phase4Api('/api/approvals/'.$approval,'GET',null,'other',403);
verify((int) $connection->query('SELECT COUNT(*) FROM estructura_transferencias')->fetchColumn()>0,'Motor común coexiste sin migración destructiva de Fase 3.');

// Archivos reales; se ignora MIME declarado y se valida el contenido real.
$png=$temporaryDirectory.'/test-image.png'; $image=imagecreatetruecolor(2100,1100); imagefill($image,0,0,imagecolorallocate($image,25,100,150)); imagepng($image,$png); unset($image);
$uploaded=phase4Upload('imagen.png',$png,$person4['id'],'admin','video/mp4'); verify($uploaded['status']===200,'MIME del navegador no determina aceptación de imagen real.');
$file4=$uploaded['json']['data']; $fileRow=$repository->one('SELECT * FROM archivos WHERE uuid=?',[Values::binary($file4['uuid'])]);
verify($fileRow['mime_type']==='image/webp' && (int) $fileRow['ancho']===1920 && (int) $fileRow['alto']<=1920,'GD normaliza WebP y limita dimensiones.');
verify(hash_equals($fileRow['sha256'],hash_file('sha256',$filesRoot->path($fileRow['ruta']))),'SHA-256 refleja el archivo normalizado.');
verify(!str_contains(Values::json($file4),$filesRoot->root()) && !str_starts_with($filesRoot->root(),dirname(__DIR__).'/public'),'Metadatos web no exponen ruta privada.');
$media=request('admin','GET','/media/'.$file4['uuid']); verify($media['status']===200 && substr($media['body'],0,4)==='RIFF','Media entrega imagen autorizada real.');
$thumb=request('admin','GET','/media/'.$file4['uuid'].'?size=thumb'); verify($thumb['status']===200 && strlen($thumb['body'])<strlen($media['body']),'Miniatura protegida y de menor tamaño.');
verify($media['headers']['content-type']==='image/webp' && $media['headers']['x-content-type-options']==='nosniff' && str_contains($media['headers']['cache-control'],'no-store'),'Media entrega MIME y cabeceras privadas seguras.');
verify(request('capture','GET','/media/'.$file4['uuid'])['status']===403,'Media exige permiso.');
verify(request('other','GET','/media/'.$file4['uuid'])['status']===403,'Media rechaza usuario ajeno sin permiso.');
verify(request('anonymous','GET','/media/'.$file4['uuid'])['status']===401,'Media exige sesión.');
verify(request('admin','GET','/media/'.Values::uuid())['status']===404,'UUID inexistente no expone almacenamiento.');
verify(request('admin','GET','/media/'.$file4['uuid'].'?size=../../etc/passwd')['status']===422,'Variante de media no admite traversal.');
$fake=$temporaryDirectory.'/fake.png'; file_put_contents($fake,'<?php echo "ejecutable";');
foreach ([['imagen.png',$fake],['archivo.php.png',$png],['../imagen.png',$png],['video.mp4',$png],['imagen.svg',$png]] as [$name,$source]) verify(phase4Upload($name,$source,$person4['id'])['status']===422,'Rechaza falso MIME, doble extensión, traversal o formato: '.$name);
verify(phase4Upload('imagen.png',$png,$person4['id'],'admin',null,'csrf-invalido')['status']===403,'Carga exige CSRF.');
$big=$temporaryDirectory.'/large.png'; $handle=fopen($big,'w'); ftruncate($handle,11*1024*1024); fclose($handle); phase4Reject(fn ()=>$fileService->ingestUpload($big,'large.png',1,1,'personas',$person4Id,1),'Tamaño excedido');
$jpeg=$temporaryDirectory.'/exif.jpg'; $image=imagecreatetruecolor(40,20); imagejpeg($image,$jpeg); unset($image);
$tiff='II'.pack('vV',42,8).pack('v',2).pack('vvV',0x0112,3,1).pack('v',6)."\0\0".pack('vvVV',0x8825,4,1,38).pack('V',0).pack('v',1).pack('vvV',1,2,2)."N\0\0\0".pack('V',0);
$exif="Exif\0\0".$tiff; $original=file_get_contents($jpeg); file_put_contents($jpeg,substr($original,0,2)."\xFF\xE1".pack('n',strlen($exif)+2).$exif.substr($original,2));
verify((int) exif_read_data($jpeg)['Orientation']===6,'Fixture JPEG contiene orientación EXIF real.');
$oriented=phase4Upload('foto.jpg',$jpeg,$person4['id']); verify($oriented['status']===200,'JPEG con EXIF se procesa.');
verify($oriented['json']['data']['width']===20 && $oriented['json']['data']['height']===40,'Orientación se corrige antes de normalizar.');
$normalized=$fileService->authorized($oriented['json']['data']['uuid']); verify(!str_contains(file_get_contents($normalized['path']),'Exif') && !str_contains(file_get_contents($normalized['path']),'GPS'),'Recodificación no conserva EXIF/GPS.');
$privateAttempt=request('admin','GET','/storage/private/'.$fileRow['ruta']); verify($privateAttempt['status']===404,'El almacenamiento no es accesible directamente.');

// Permisos concedidos no autorizan personas ajenas al alcance.
$limited4=phase4Api('/api/users','POST',['organization'=>$organizationIds[0],'username'=>'fase4-limitado','password'=>'Fase4 alcance seguro','type'=>8,'name'=>'Limitado','person'=>$person4['id']]);
phase4Api('/api/users/'.$limited4['id'].'/accesses','POST',['process'=>$processIds[0],'type'=>8]);
foreach (['archivos.ver','archivos.subir','archivos.eliminar','exportaciones.generar','exportaciones.descargar','aprobaciones.ver','aprobaciones.resolver'] as $permission) phase3Grant($limited4['id'],$permission,$processIds[0],'propio');
$phase3Tokens['limited4']=login('limited4','fase4-limitado','Fase4 alcance seguro');
verify(request('limited4','GET','/media/'.$file4['uuid'])['status']===200,'Media propia con permiso de alcance propio.');
$other4=phase4Api('/api/persons','POST',['organization'=>$organizationIds[0],'name'=>'Otra evidencia Fase 4']);
$otherUpload=phase4Upload('otra.png',$png,$other4['id']); verify($otherUpload['status']===200,'Archivo de otra persona de la misma organización.');
verify(request('limited4','GET','/media/'.$otherUpload['json']['data']['uuid'])['status']===404,'UUID de la misma organización no evade alcance propio.');
verify(phase4Upload('otra.png',$png,$other4['id'],'limited4')['status']===404,'Subir archivo exige alcance sobre propietario.');
$foreign4=phase4Api('/api/users','POST',['organization'=>$organizationIds[1],'username'=>'fase4-otra-organizacion','password'=>'Fase4 otro seguro','type'=>2,'name'=>'Otro administrador']);
phase4Api('/api/users/'.$foreign4['id'].'/accesses','POST',['process'=>$processIds[1],'type'=>2]);
$phase3Tokens['foreign4']=login('foreign4','fase4-otra-organizacion','Fase4 otro seguro');
verify(request('foreign4','GET','/media/'.$file4['uuid'])['status']===403,'Administrador ajeno con permiso no accede a elección ajena.');
$other4Id=(int) $repository->one('SELECT id FROM personas WHERE uuid=?',[Values::binary($other4['id'])])['id'];
$limited4Id=(int) $repository->one("SELECT id FROM usuarios WHERE usuario='fase4-limitado'")['id'];
phase4Reject(fn ()=>$approvals->create(1,1,'personas',$other4Id,1,[$limited4Id],[]),'Motor no asigna recurso fuera del alcance del aprobador',404);
$pdfUpload=$temporaryDirectory.'/administrativo.pdf'; (new App\Services\Common\PdfService())->table($pdfUpload,'Documento administrativo',['Dato'],[['Ejemplo']],['generator'=>'Prueba','filters'=>[]]);
$document4=phase4Upload('documento.pdf',$pdfUpload,$person4['id']); verify($document4['status']===200,'Documento PDF permitido con magic e integridad básica.');
verify(request('admin','GET','/media/'.$document4['json']['data']['uuid'].'?size=thumb')['status']===404,'Documento sin miniatura no usa variantes ajenas.');
phase4Reject(fn ()=>new PrivateStorage(dirname(__DIR__).'/public/uploads'),'Storage rechaza docroot');
phase4Reject(fn ()=>new FilesystemBackupDestination(dirname(__DIR__).'/storage/backups'),'Backup rechaza repositorio');
phase4Reject(fn ()=>$filesRoot->path('../../etc/passwd'),'Storage rechaza traversal interno');

// Notificaciones internas y canales externos, exclusivamente con proveedor falso.
$notifications=new NotificationService($repository);
$notice=$notifications->internal($managerId,1,1,'operativa','Aviso Fase 4','Resumen autorizado','personas',(string) $person4Id,'/persons/');
$notices=phase4Api('/api/notifications','GET',null,'phase4-manager'); verify(in_array($notice,array_column($notices['rows'],'uuid'),true),'Notificación visible sólo al destinatario.');
verify(!in_array($notice,array_column(phase4Api('/api/notifications')['rows'],'uuid'),true),'Otra cuenta no ve la notificación.');
phase4Api('/api/notifications/read','POST',['ids'=>[$notice]],'phase4-manager'); verify($repository->one('SELECT leida_en FROM notificaciones WHERE uuid=?',[Values::binary($notice)])['leida_en']!==null,'Lectura individual registrada.');
$secondNotice=$notifications->internal($managerId,1,1,'operativa','Otro aviso','Resumen');
phase4Api('/api/notifications/read','POST',['all'=>1],'admin'); verify($repository->one('SELECT leida_en FROM notificaciones WHERE uuid=?',[Values::binary($secondNotice)])['leida_en']===null,'Marcar todas no cambia avisos ajenos.');
phase4Api('/api/notifications/read','POST',['all'=>1],'phase4-manager');
phase4Reject(fn ()=>$notifications->internal($managerId,2,2,'x','x','x'),'Destinatario de otra organización');
foreach (['https://example.test','//example.test','/../secret','/%0d%0aLocation=evil'] as $url) phase4Reject(fn ()=>$notifications->internal($managerId,1,1,'x','x','x',null,null,$url),'Enlace externo o malicioso');
phase4Api('/api/notification-config','PUT',['email'=>1,'whatsapp'=>1]);
phase4Reject(fn ()=>$notifications->outgoing($person4Id,1,1,'email',['title'=>'Operativo','summary'=>'Contenido'],'sin-canal'),'No envía sin consentimiento');
foreach (['EMAIL'=>'persona@example.test','WHATSAPP'=>'+525555555555'] as $type=>$contact) $repository->execute('INSERT INTO canales_contacto (persona,tipo,valor,principal,verificado,permite_contacto,f_consentimiento) SELECT ?,id,?,1,1,1,NOW() FROM canales_contacto_tipos WHERE codigo=?',[$person4Id,$contact,$type]);
$_ENV['WHATSAPP_OPERATIONAL_TEMPLATES']='aviso_operativo';
$fakeProvider=new class implements NotificationProvider { public function assertReady(): void {} public int $calls=0; public function send(array $delivery): string { $this->calls++; return 'fake-'.$delivery['id']; } };
$email=$notifications->outgoing($person4Id,1,1,'email',['title'=>'Operativo','summary'=>'Contenido'],'correo-1');
verify($email===$notifications->outgoing($person4Id,1,1,'email',['title'=>'Operativo','summary'=>'Contenido'],'correo-1'),'Idempotencia de envío crea un solo registro.');
$notifications->deliver($email,$fakeProvider); $notifications->deliver($email,$fakeProvider); verify($fakeProvider->calls===1,'Repetir entrega completada no duplica efecto.');
$whatsapp=$notifications->outgoing($person4Id,1,1,'whatsapp',['language'=>'es_MX','parameters'=>['Aviso operativo']],'wa-1','aviso_operativo'); $notifications->deliver($whatsapp,$fakeProvider); verify($fakeProvider->calls===2,'WhatsApp utiliza proveedor falso y opt-in.');
phase4Reject(fn ()=>$notifications->outgoing($person4Id,1,1,'whatsapp',['language'=>'es_MX','parameters'=>[]],'wa-2','otra'),'Plantilla no autorizada');
$repository->execute("INSERT INTO consentimientos (persona,tipo,otorgado,f_registro) SELECT ?,id,0,NOW() FROM consentimientos_tipos WHERE codigo='WHATSAPP'",[$person4Id]);
phase4Reject(fn ()=>$notifications->outgoing($person4Id,1,1,'whatsapp',['language'=>'es_MX','parameters'=>[]],'wa-3','aviso_operativo'),'Revocación específica prevalece');
$uncertain=$notifications->outgoing($person4Id,1,1,'email',['title'=>'Operativo','summary'=>'Contenido'],'correo-incierto');
$badProvider=new class implements NotificationProvider { public function assertReady(): void {} public function send(array $delivery): string { throw new RuntimeException('password=topsecret token=Bearer SECRET'); } };
phase4Reject(fn ()=>$notifications->deliver($uncertain,$badProvider),'Proveedor incierto no reintenta ciegamente');
verify(!str_contains($repository->one('SELECT error FROM notificaciones_envios WHERE id=?',[$uncertain])['error'],'topsecret'),'Error externo sanitizado.');
$deliveryPanel=phase4Api('/api/notification-deliveries'); verify(!str_contains(Values::json($deliveryPanel),'persona@example.test'),'Panel de envíos omite destinatarios y contenido.');

// Cola: carreras entre procesos, lease vivo, caída, backoff, máximo e idempotencia.
$job=$queue->enqueue('prueba',['record_id'=>1],1,1,1,'único'); verify($queue->enqueue('prueba',['record_id'=>1],1,1,1,'único')===$job,'Enqueue idempotente.');
phase4Reject(fn ()=>$queue->enqueue('prueba',['password'=>'secreto']),'Cola rechaza secretos');
$claimed=$queue->claim('worker-a','prueba'); verify($claimed['id']===$job && $claimed['intentos']===1,'Claim cambia estado y número de intento.');
$connection2=new PDO('mysql:host='.env('DB_HOST').';port='.env('DB_PORT',3306).';dbname='.$databaseName.';charset=utf8mb4',env('DB_USERNAME'),env('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$queue2=new QueueService(new CommonRepository($connection2)); verify($queue2->claim('worker-b','prueba')===null,'Segundo worker no reclama trabajo en ejecución.');
$repository->execute('UPDATE trabajos SET bloqueo_hasta=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE id=?',[$job]); verify($queue2->recover()===0,'Lease vencido de worker vivo no duplica efecto.');
$queue->finish($claimed,new ServiceFailure('Fallo transitorio.')); $retry=$repository->one('SELECT * FROM trabajos WHERE id=?',[$job]); verify($retry['estado']==='reintento' && strtotime($retry['disponible_en'])>time(),'Reintento aplica backoff.');
for ($attempt=2;$attempt<=3;$attempt++) { $repository->execute('UPDATE trabajos SET disponible_en=NOW() WHERE id=?',[$job]); $claim=$queue->claim('worker-a','prueba'); $queue->finish($claim,new RuntimeException('SQLSTATE password secret')); }
$failed=$repository->one('SELECT * FROM trabajos WHERE id=?',[$job]); verify($failed['estado']==='fallido' && (int) $failed['intentos']===3 && !str_contains($failed['error'],'SQLSTATE'),'Máximo acotado y error seguro.');
phase4Api('/api/jobs/'.$job.'/retry','POST',[]);
verify($repository->one('SELECT estado,intentos FROM trabajos WHERE id=?',[$job])['estado']==='reintento','Reintento manual auditado y controlado.');
phase4Api('/api/jobs/'.$job.'/cancel','POST',[]);
verify($repository->one('SELECT estado FROM trabajos WHERE id=?',[$job])['estado']==='cancelado','Cancelación no procesa ni borra el trabajo.');
phase4Api('/api/jobs/'.$job.'/retry','POST',[],'admin',409);
$success=$queue->enqueue('prueba_ok',[],1,1,1); $claim=$queue->claim('worker-ok','prueba_ok'); $queue->finish($claim); verify($repository->one('SELECT estado FROM trabajos WHERE id=?',[$success])['estado']==='completado','Finalización de trabajo.');
$connection->exec('CREATE TABLE test_job_effects (job CHAR(36) PRIMARY KEY,valor INT NOT NULL) ENGINE=InnoDB');
for ($index=0;$index<2;$index++) $queue->enqueue('prueba_concurrencia',['value'=>$index],1,1,1);
$writers=[phase4WorkerProcesses($environment),phase4WorkerProcesses($environment)];
foreach ($writers as [$writer,$pipes]) { $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); verify(proc_close($writer)===0,'Worker concurrente termina: '.$out.$err); }
verify((int) $connection->query('SELECT COUNT(*) FROM test_job_effects')->fetchColumn()===2,'Dos workers producen exactamente dos efectos únicos.');
verify((int) $connection->query("SELECT COUNT(*) FROM trabajos WHERE tipo='prueba_concurrencia' AND estado='completado'")->fetchColumn()===2,'Dos workers completan sus trabajos distintos.');
$stuck=$queue->enqueue('prueba_concurrencia',['value'=>9],1,1,1); [$writer,$pipes]=phase4WorkerProcesses($environment,true); $out=stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); verify(proc_close($writer)===0 && json_decode($out,true)['claimed']===$stuck,'Fixture de worker caído después del claim.');
$repository->execute('UPDATE trabajos SET bloqueo_hasta=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE id=?',[$stuck]); verify($queue->recover()===1,'Trabajo de worker caído es recuperable.');
$worker=new WorkerService($repository); $worker->register('prueba_concurrencia',fn ($payload,$job)=>$repository->execute('INSERT INTO test_job_effects VALUES (?,?)',[$job['id'],$payload['value']])); verify($worker->run(1,5,'prueba_concurrencia')===1,'Worker procesa trabajo recuperado.');
phase4Api('/api/jobs','GET',null,'capture',403); phase4Api('/api/jobs','GET',null,'phase4-manager');

// Exportaciones streaming con formatos reales y autorizaciones en generación/descarga.
$exports=new ExportService($repository,null,$filesRoot); $generated=[];
foreach (['pdf','xlsx','csv'] as $format) {
	$export=phase4Api('/api/exports','POST',['type'=>'personas','format'=>$format,'filters'=>['search'=>'Evidencia Fase 4']],'admin',202)['uuid'];
	$row=$repository->one('SELECT * FROM exportaciones WHERE uuid=?',[Values::binary($export)]); $exports->generate((int) $row['id']);
	$download=request('admin','GET','/api/exports/'.$export.'/download'); verify($download['status']===200,'Descarga autorizada '.$format);
	verify(match($format) { 'pdf'=>str_starts_with($download['body'],'%PDF-'),'xlsx'=>str_starts_with($download['body'],'PK'),'csv'=>str_contains($download['body'],'Evidencia Fase 4') },'Contenido real '.$format);
	$generated[$format]=$export;
}
verify(request('phase4-manager','GET','/api/exports/'.$generated['xlsx'].'/download')['status']===404,'Exportación pertenece al solicitante incluso frente a otro administrador.');
phase4Api('/api/exports','POST',['type'=>'personas','format'=>'sql'],'admin',422);
$formula=phase4Api('/api/persons','POST',['organization'=>$organizationIds[0],'name'=>'=SUM(1,2)']);
$csv=phase4Api('/api/exports','POST',['type'=>'personas','format'=>'csv','filters'=>['search'=>'=SUM']],'admin',202)['uuid']; $exports->generate((int) $repository->one('SELECT id FROM exportaciones WHERE uuid=?',[Values::binary($csv)])['id']);
verify(str_contains(request('admin','GET','/api/exports/'.$csv.'/download')['body'],"'=SUM"),'CSV bloquea inyección de fórmulas.');
foreach (['participaciones','estructura','usuarios_accesos','paquete'] as $type) { $uuid=phase4Api('/api/exports','POST',['type'=>$type,'format'=>$type==='paquete' ? 'zip' : 'xlsx'],'admin',202)['uuid']; $exports->generate((int) $repository->one('SELECT id FROM exportaciones WHERE uuid=?',[Values::binary($uuid)])['id']); verify(request('admin','GET','/api/exports/'.$uuid.'/download')['status']===200,'Exporta datos existentes: '.$type); }
$pending=phase4Api('/api/exports','POST',['type'=>'personas','format'=>'csv'],'admin',202)['uuid']; verify($repository->one('SELECT estado FROM exportaciones WHERE uuid=?',[Values::binary($pending)])['estado']==='pendiente','Exportación grande utiliza trabajo asincrónico.');
verify((int) $connection->query('SELECT COUNT(*) FROM personas')->fetchColumn()>1000,'Exportación grande cubre el padrón de regresión de más de mil personas.');
(new WorkerService($repository))->run(100,120,'exportacion');
verify($repository->one('SELECT estado FROM exportaciones WHERE uuid=?',[Values::binary($pending)])['estado']==='completada','Worker genera exportación grande.');
phase4Api('/api/exports','POST',['organization'=>2,'process'=>2,'type'=>'personas','format'=>'xlsx'],'phase4-manager',403);

// Descargas vuelven a comprobar el alcance granular, además del permiso del módulo.
$limitedExport=phase4Api('/api/exports','POST',['type'=>'personas','format'=>'csv'],'limited4',202)['uuid'];
$exports->generate((int) $repository->one('SELECT id FROM exportaciones WHERE uuid=?',[Values::binary($limitedExport)])['id']);
$limitedDownload=request('limited4','GET','/api/exports/'.$limitedExport.'/download'); verify($limitedDownload['status']===200 && str_contains($limitedDownload['body'],'Evidencia Fase 4') && !str_contains($limitedDownload['body'],'Otra evidencia'),'Exportación respeta alcance propio en SQL.');
phase3Grant($limited4['id'],'exportaciones.descargar',$processIds[0],'propio',0);
verify(request('limited4','GET','/api/exports/'.$limitedExport.'/download')['status']===403,'Revocación individual bloquea descarga ya generada.');
$deniedJobExport=phase4Api('/api/exports','POST',['type'=>'personas','format'=>'csv'],'limited4',202)['uuid'];
phase3Grant($limited4['id'],'exportaciones.generar',$processIds[0],'propio',0);
(new WorkerService($repository))->run(100,120,'exportacion');
verify($repository->one('SELECT estado FROM exportaciones WHERE uuid=?',[Values::binary($deniedJobExport)])['estado']==='fallida','Permiso retirado antes del worker termina job y solicitud sin dejarlos pendientes.');
phase3Grant($limited4['id'],'exportaciones.generar',$processIds[0],'propio',1);
$clientExport=phase4Api('/api/exports','POST',['type'=>'personas','format'=>'csv'],'phase4-manager',202)['uuid'];
$exports->generate((int) $repository->one('SELECT id FROM exportaciones WHERE uuid=?',[Values::binary($clientExport)])['id']);
phase3Grant($phase4Manager['id'],'exportaciones.descargar',$processIds[0],'propio');
verify(request('phase4-manager','GET','/api/exports/'.$clientExport.'/download')['status']===403,'Reducir sólo alcance de descarga invalida datos más amplios.');
phase3Grant($phase4Manager['id'],'exportaciones.descargar',$processIds[0],'eleccion');

// Respaldo técnico: BD nativa, full, incremento, tombstones, integridad, retención y restauración independiente.
$dumpConfig=['host'=>(string) env('DB_HOST'),'port'=>(int) env('DB_PORT',3306),'database'=>$databaseName,'username'=>(string) env('DB_USERNAME'),'password'=>(string) env('DB_PASSWORD')];
$dump=new DatabaseDump($dumpConfig); $destination=new FilesystemBackupDestination($temporaryDirectory.'/backups');
$backups=new BackupService($repository,$destination,$filesRoot,$dump);
$first=$backups->request('archivos_full'); $backups->perform($first); $backups->verify($first);
$firstRow=$repository->one('SELECT * FROM respaldos WHERE id=?',[$first]); verify($firstRow['estado']==='completado' && $firstRow['sha256']!==null,'Full guarda tamaño/hash/manifiesto.');
$archivePath=$destination->path($firstRow['ruta']); $originalArchive=file_get_contents($archivePath); $testZip=new ZipArchive(); $testZip->open($archivePath);
$firstManifest=json_decode($firstRow['manifiesto'],true); $testZip->addFromString('files/'.$firstManifest['changed'][0],'contenido alterado'); $testZip->close();
$repository->execute('UPDATE respaldos SET sha256=?,tamanio=? WHERE id=?',[hash_file('sha256',$archivePath),filesize($archivePath),$first]);
phase4Reject(fn ()=>$backups->verify($first),'Hash interno detecta daño incluso con hash externo recalculado');
file_put_contents($archivePath,$originalArchive); $repository->execute('UPDATE respaldos SET sha256=?,tamanio=? WHERE id=?',[$firstRow['sha256'],$firstRow['tamanio'],$first]);

verify(!str_starts_with($destination->path($firstRow['ruta']),dirname(__DIR__).'/'),'Respaldos quedan fuera del repositorio.');
$newFile=$filesRoot->path('archivos/ficticio/normal.txt',true); file_put_contents($newFile,'nuevo');
$changedPath=$filesRoot->path($fileRow['ruta']); file_put_contents($changedPath,'modificado en fixture');
$deleteRow=$repository->one("SELECT ruta FROM archivos_variantes WHERE archivo=? AND variante='thumb'",[$fileRow['id']]); $filesRoot->remove($deleteRow['ruta']);
$increment=$backups->request('incremental'); $backups->perform($increment); $backups->verify($increment);
$incrementRow=$repository->one('SELECT * FROM respaldos WHERE id=?',[$increment]); $manifest=json_decode($incrementRow['manifiesto'],true);
verify($incrementRow['base_id']===$first && $incrementRow['anterior_id']===$first,'Incremental enlaza base y anterior.');
verify(in_array($fileRow['ruta'],$manifest['changed'],true) && in_array('archivos/ficticio/normal.txt',$manifest['changed'],true),'Incremental registra nuevos/modificados.');
verify(in_array($deleteRow['ruta'],$manifest['deleted'],true),'Incremental registra eliminación.');
verify(request('admin','GET','/media/'.$file4['uuid'])['status']===404,'Media rechaza contenido con hash alterado.');
verify(count($manifest['changed'])<count($manifest['files']),'Incremental evita copiar archivos sin cambios.');
$restoreDb='sicrat_phase1_test_'.bin2hex(random_bytes(6)); $connection->exec('CREATE DATABASE `'.$restoreDb.'` CHARACTER SET utf8mb4');
try {
	$_ENV['RESTORE_FILES_PATH']=$temporaryDirectory.'/restored-files';
	$restores=new RestoreService($repository,$backups,$filesRoot,$dump);
	$preview=$restores->preview($increment); phase4Reject(fn ()=>$restores->request($increment,'sin confirmación',$preview['review']),'Restauración exige confirmación',422);
	$restore=$restores->request($increment,$preview['confirmation'],$preview['review']); $restores->perform($restore);
	verify(file_get_contents($temporaryDirectory.'/restored-files/'.$fileRow['ruta'])==='modificado en fixture','Restauración full + incremental reconstruye modificación.');
	verify(!is_file($temporaryDirectory.'/restored-files/'.$deleteRow['ruta']) && file_get_contents($temporaryDirectory.'/restored-files/archivos/ficticio/normal.txt')==='nuevo','Restauración aplica tombstone y archivo nuevo.');
	$bd=$backups->request('bd'); $backups->perform($bd); $backups->verify($bd);
	$bdRow=$repository->one('SELECT * FROM respaldos WHERE id=?',[$bd]); verify(isset(json_decode($bdRow['manifiesto'],true)['database']),'Dump nativo de BD desechable se registra en manifiesto.');
	$_ENV['RESTORE_DATABASE']=$restoreDb;
	$preview=$restores->preview($bd); $restore=$restores->request($bd,$preview['confirmation'],$preview['review']); $restores->perform($restore);
	verify((int) $connection->query('SELECT COUNT(*) FROM `'.$restoreDb.'`.personas')->fetchColumn()===(int) $connection->query('SELECT COUNT(*) FROM personas')->fetchColumn(),'Dump restaurado en BD desechable conserva personas.');
	$full=$backups->request('completo'); $backups->perform($full); $backups->verify($full);
	$preview=$restores->preview($full); $restore=$restores->request($full,$preview['confirmation'],$preview['review']); $restores->perform($restore);
	verify($repository->one('SELECT estado FROM restauraciones WHERE id=?',[$restore])['estado']==='completada','Restauración conjunta completa en destinos desechables.');
	$_ENV['RESTORE_DATABASE']=$databaseName; phase4Reject(fn ()=>$restores->preview($bd),'Web nunca sobrescribe la base activa'); $_ENV['RESTORE_DATABASE']=$restoreDb;
	$corrupt=$destination->path($incrementRow['ruta']); $saved=file_get_contents($corrupt); file_put_contents($corrupt,'hash incorrecto'); phase4Reject(fn ()=>$backups->verify($increment),'Hash alterado rechazado'); file_put_contents($corrupt,$saved);
	$basePath=$destination->path($firstRow['ruta']); rename($basePath,$basePath.'.missing'); phase4Reject(fn ()=>$backups->verify($increment),'Falta base requerida'); rename($basePath.'.missing',$basePath);
	$chainFull=$backups->request('archivos_full'); $backups->perform($chainFull);
	file_put_contents($filesRoot->path('archivos/ficticio/segundo.txt',true),'segundo'); $chainIncrement1=$backups->request('incremental'); $backups->perform($chainIncrement1);
	file_put_contents($filesRoot->path('archivos/ficticio/tercero.txt',true),'tercero'); $chainIncrement2=$backups->request('incremental'); $backups->perform($chainIncrement2);
	$middle=$repository->one('SELECT * FROM respaldos WHERE id=?',[$chainIncrement1]); $middlePath=$destination->path($middle['ruta']); rename($middlePath,$middlePath.'.missing');
	phase4Reject(fn ()=>$backups->verify($chainIncrement2),'Falta un incremental intermedio de la cadena'); rename($middlePath.'.missing',$middlePath);
	$preview=$restores->preview($chainIncrement2); $chainRestore=$restores->request($chainIncrement2,$preview['confirmation'],$preview['review']); $restores->perform($chainRestore);
	verify(file_get_contents($temporaryDirectory.'/restored-files/archivos/ficticio/tercero.txt')==='tercero','Reconstruye full y dos incrementales consecutivos');

	$repository->execute('UPDATE respaldos SET fecha=DATE_SUB(NOW(),INTERVAL 30 DAY) WHERE id=?',[$first]); $backups->retention(1); verify(is_file($basePath),'Retención conserva base de incremental vigente.');
	$isolated=$backups->request('bd'); $backups->perform($isolated); $isolatedRow=$repository->one('SELECT * FROM respaldos WHERE id=?',[$isolated]);
	$repository->execute('UPDATE respaldos SET fecha=DATE_SUB(NOW(),INTERVAL 30 DAY) WHERE id=?',[$isolated]);
	$backups->protect($isolated,true); $backups->retention(1); verify(is_file($destination->path($isolatedRow['ruta'])),'Retención conserva respaldo protegido.');
	$backups->protect($isolated,false); $backups->retention(1); verify(!is_file($destination->path($isolatedRow['ruta'])),'Retención elimina respaldo vencido independiente.');
	$noSpace=new class($destination) implements BackupDestination { public function __construct(private BackupDestination $original) {} public function path(string $path,bool $parent=false): string { return $this->original->path($path,$parent); } public function available(): ?float { return 0; } public function remove(string $path): void { $this->original->remove($path); } public function label(): string { return 'Espacio simulado'; } };
	$spaceBackup=$backups->request('archivos_full'); $small=new BackupService($repository,$noSpace,$filesRoot,$dump); phase4Reject(fn ()=>$small->perform($spaceBackup),'Espacio insuficiente bloquea copia');
	verify($repository->one('SELECT estado,error FROM respaldos WHERE id=?',[$spaceBackup])['estado']==='fallido','Error de espacio queda registrado.');
	verify($repository->one("SELECT id FROM notificaciones WHERE tipo='respaldo_fallido' AND usuario=1")!==null,'Fallo de respaldo notifica al Super Administrador.');
	$waiting=$backups->request('bd'); phase4Reject(fn ()=>$backups->request('archivos_full'),'No crea respaldos incompatibles concurrentes',409); $backups->perform($waiting);
	$lock2=new OperationLock(new CommonRepository($connection2)); (new OperationLock($repository))->run('almacenamiento',fn ()=>phase4Reject(fn ()=>$lock2->run('almacenamiento',fn ()=>null),'Mutex de almacenamiento entre conexiones',409));
	$_ENV['BACKUP_ENCRYPTION_KEY']=base64_encode(random_bytes(32)); $encrypted=$backups->request('archivos_full'); $backups->perform($encrypted); $backups->verify($encrypted);
	$encryptedRow=$repository->one('SELECT * FROM respaldos WHERE id=?',[$encrypted]); verify((int) $encryptedRow['cifrado']===1 && str_starts_with(file_get_contents($destination->path($encryptedRow['ruta'])),'SICRATB1'),'Sodium cifra respaldo técnico.');
	$preview=$restores->preview($encrypted); $restore=$restores->request($encrypted,$preview['confirmation'],$preview['review']); $restores->perform($restore); verify(is_file($temporaryDirectory.'/restored-files/archivos/ficticio/normal.txt'),'Respaldo cifrado se descifra y restaura.');
	$correctKey=$_ENV['BACKUP_ENCRYPTION_KEY']; $_ENV['BACKUP_ENCRYPTION_KEY']=base64_encode(random_bytes(32)); phase4Reject(fn ()=>$backups->verify($encrypted),'Llave incorrecta no descifra'); $_ENV['BACKUP_ENCRYPTION_KEY']=$correctKey;
	unset($_ENV['BACKUP_ENCRYPTION_KEY']);
	$scheduled=(new SchedulerService($repository))->run(); verify(count($scheduled)<=1,'Scheduler coordina copias compatibles'); $count=(int) $connection->query('SELECT COUNT(*) FROM respaldos')->fetchColumn(); (new SchedulerService($repository))->run(); verify((int) $connection->query('SELECT COUNT(*) FROM respaldos')->fetchColumn()===$count,'Scheduler idempotente en la misma fecha.');
} finally { $connection->exec('DROP DATABASE IF EXISTS `'.$restoreDb.'`'); }
phase4Api('/api/backups','GET',null,'phase4-manager',403);
phase4Api('/api/backup-policy','PUT',['retention_days'=>1],'phase4-manager',403);
$globalAudit=phase4Api('/api/audit?process=all&entity=respaldos'); verify(count($globalAudit['rows'])>0 && $globalAudit['rows'][0]['organizacion']===null,'Super Administrador consulta auditoría global aunque tenga elección seleccionada.');
$backupPanel=phase4Api('/api/backups'); verify(!str_contains(Values::json($backupPanel),$destination->path('respaldos')),'Panel de respaldos muestra ubicación lógica.');
verify(isset($backupPanel['summary']['cantidad'],$backupPanel['summary']['utilizado'],$backupPanel['summary']['available']),'Panel muestra cantidad y espacio.');
$filteredPackage=phase4Api('/api/exports','POST',['type'=>'paquete','format'=>'zip','filters'=>['search'=>'Evidencia Fase 4']],'admin',202)['uuid'];
$exports->generate((int) $repository->one('SELECT id FROM exportaciones WHERE uuid=?',[Values::binary($filteredPackage)])['id']);
$packageResult=$exports->download($filteredPackage); $zipCheck=new ZipArchive(); $zipCheck->open($packageResult['path']);
verify($zipCheck->locateName('Datos del cliente/Personas.xlsx')!==false && $zipCheck->locateName('Datos del cliente/Archivos/Indice.csv')!==false,'Paquete legible contiene libros e índice privado.');
verify($zipCheck->locateName('database.sql')===false,'Paquete del cliente no contiene dump técnico.');
$zipCheck->close();
phase4Api('/api/services','GET',null,'admin',404);
verify(request('admin','GET','/services/')['status']===200,'Pantalla de servicios disponible con sesión.');
phase4Api('/api/files/'.$oriented['json']['data']['uuid'],'DELETE',[]);
verify(request('admin','GET','/media/'.$oriented['json']['data']['uuid'])['status']===404,'Baja lógica bloquea media sin borrar historia.');

echo 'Fase 4: '.($checks-$phase4Start)." comprobaciones adicionales correctas.\n";
