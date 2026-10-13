<?php

use App\Services\Common\ActorContext;
use App\Services\Common\NotificationProvider;
use App\Services\Common\NotificationService;
use App\Services\Common\WorkerService;
use App\Services\Common\ExportService;
use App\Services\Common\FileService;
use App\Services\Common\Values;

function phase7Json(string $path,array $data,string $client='phase7-0',int $expected=422,string $method='POST'): array {
    global $baseUrl,$temporaryDirectory,$phase3Tokens,$adminToken;
    $curl=curl_init($baseUrl.'/api/jornada/'.$path);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_COOKIEFILE=>$temporaryDirectory.'/'.$client.'.cookies',CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-CSRF-Token: '.($phase3Tokens[$client] ?? $adminToken)],CURLOPT_POSTFIELDS=>json_encode($data,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)]);
    $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);verify($status===$expected,'JSON '.$path.' esperaba '.$expected.': '.$body);return json_decode($body,true);
}
function phase7Upload(string $record,string $name,string $source,string $client='phase7-0',?int $process=null,string $entity='jornada_borradores'): array {
    global $baseUrl,$temporaryDirectory,$phase3Tokens,$adminToken,$phase7Process;
    $curl=curl_init($baseUrl.'/api/files');curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_COOKIEFILE=>$temporaryDirectory.'/'.$client.'.cookies',CURLOPT_HTTPHEADER=>['X-CSRF-Token: '.($phase3Tokens[$client] ?? $adminToken)],CURLOPT_POSTFIELDS=>['entity'=>$entity,'record'=>$record,'process'=>$process ?? $phase7Process,'file'=>new CURLFile($source,'application/octet-stream',$name)]]);
    $body=curl_exec($curl);return ['status'=>curl_getinfo($curl,CURLINFO_HTTP_CODE),'json'=>json_decode($body,true),'body'=>$body];
}
$invalid=phase7CaptureData($phase7Booths[2]);
foreach([-1,1.0,1.25,true,false,null,'1.0','1e3','-2','texto','2,000',2147483648] as $value)phase7Json('captures',[...$invalid,'clave'=>Values::uuid(),'votos'=>array_combine($phase7Options,[$value,80,20,0])]);
phase7Json('captures',[...$invalid,'votos'=>array_combine(array_slice($phase7Options,0,3),[100,80,20])]);
phase7Json('captures',[...$invalid,'process'=>$phase5Process]);
verify(request('phase7-0','POST','/api/jornada/captures',$invalid)['status']===403,'El CSRF común protege resultados.');
$photo=phase7Upload($invalid['borrador'],'acta-prueba.png',$temporaryDirectory.'/test-image.png');verify($photo['status']===200 && $photo['json']['data']['mime']==='image/webp','Acta real se normaliza a WebP privado.');$phase7Photo=$photo['json']['data']['uuid'];
verify(request('phase7-1','GET','/media/'.$phase7Photo)['status']===404,'Un UUID no permite abrir una fotografía de casilla fuera del alcance.');
$privateDraft=phase7Draft($phase7Booths[1]);$privatePhoto=phase7Upload($privateDraft,'borrador-privado.png',$temporaryDirectory.'/test-image.png');
verify($privatePhoto['status']===200 && request('phase7-1','GET','/media/'.$privatePhoto['json']['data']['uuid'])['status']===403,'Incluso un capturista asignado a la casilla no abre el borrador ajeno.');
foreach(['acta.php','acta.svg','video.mp4'] as $name)verify(phase7Upload($invalid['borrador'],$name,$temporaryDirectory.'/test-image.png')['status']===422,'Acta rechaza '.$name);
verify(phase7Upload($invalid['borrador'],'acta.pdf',$temporaryDirectory.'/administrativo.pdf')['status']===422,'Actas no admiten PDF; sólo fotografías.');
$withPhoto=[...$invalid,'clave'=>Values::uuid(),'foto'=>$phase7Photo];$saved=phase7Api('captures','POST',$withPhoto,'phase7-0');
verify(phase7Api('captures','POST',$withPhoto,'phase7-0')['uuid']===$saved['uuid'],'Repetir captura devuelve la misma versión y no duplica votos.');
verify(phase7Upload($invalid['borrador'],'alteracion.png',$temporaryDirectory.'/test-image.png')['status']===403,'No se agregan imágenes a un acta enviada.');
phase7Api('/api/files/'.$phase7Photo,'DELETE',[],'phase7-0',403);
$thumbnail=request('phase7-0','GET','/media/'.$phase7Photo.'?size=thumb');verify($thumbnail['status']===200 && str_starts_with($thumbnail['headers']['content-type'] ?? '','image/webp'),'Descarga de thumbnail requiere propietario y alcance.');
verify(str_contains($thumbnail['headers']['cache-control'] ?? '','no-store'),'Las actas privadas no se almacenan en caché compartida.');
phase7Api('assignments','PUT',['casillas'=>[$phase7Booths[2]],'usuarios'=>[$phase7Accounts[0]],'activo'=>0]);
phase7Api('drafts','POST',['casilla'=>$phase7Booths[2],'clave'=>Values::uuid()],'phase7-0',403);
verify(phase7Api('booths/'.$phase7Booths[2].'/history','GET',null,'phase7-0')['rows'][0]['uuid']===$saved['uuid'],'Retirar asignación conserva el historial propio autorizado.');
phase7Api('assignments','PUT',['casillas'=>[$phase7Booths[2]],'usuarios'=>[$phase7Accounts[0]],'activo'=>1]);
phase7Api('/api/organizations/'.$organizationIds[0].'/modules/jornada_electoral','PUT',['selected'=>0,'election'=>$phase7Election]);
verify(phase7Api('summary?modo=validado')['total_acta']===225,'Módulo apagado conserva resultados vigentes autorizados.');
phase7Api('drafts','POST',['casilla'=>$phase7Booths[1],'clave'=>Values::uuid()],'phase7-0',403);
verify(request('phase7-0','GET','/media/'.$phase7Photo)['status']===200,'El modo histórico conserva la fotografía autorizada.');
phase7Api('/api/organizations/'.$organizationIds[0].'/modules/jornada_electoral','PUT',['selected'=>1,'election'=>$phase7Election]);

// La misma cuenta trabaja en otra elección, con configuración y permisos independientes.
$phase7ElectionB=phase7Api('/api/organizations/'.$organizationIds[0].'/electoral-process','POST',['process'=>'Jornada Fase 7 validación inicial','type'=>1,'character'=>1])['id'];
phase7Api('/api/auth/election','POST',['process'=>$phase7ElectionB]);
$phase7ProcessB=(int)$phase5Repository->one('SELECT id FROM procesos_electorales WHERE uuid=?',[Values::binary($phase7ElectionB)])['id'];
$phase7B=phase7Api('configuration','PUT',['zona'=>'America/Monterrey','captura_local'=>gmdate('Y-m-d\TH:i',time()+86400),'politica_inicial'=>'validar','foto_obligatoria'=>1,'denominador'=>'seleccionadas','fecha_eleccion'=>date('Y-m-d')]);
$partyB=phase7Api('catalogs/partidos','POST',['nombre'=>'Partido configurable B','siglas'=>'PCB','color'=>'#2355aa'])['uuid'];
$candidateB=phase7Api('catalogs/candidaturas','POST',['nombre'=>'=SUM(1,2)','cargo'=>'Cargo de prueba'])['uuid'];
$optionB=phase7Api('catalogs/opciones','POST',['nombre'=>'Fila exacta B','tipo'=>'partido','orden'=>1,'partido'=>$partyB,'candidatura'=>$candidateB,'suma_candidatura'=>1,'en_denominador'=>1])['uuid'];
$physical=(int)$phase5Repository->one('SELECT casilla FROM jornada_casillas WHERE uuid=?',[Values::binary($phase7Booths[0])])['casilla'];
$boothB=phase7Api('booths','POST',['fisica'=>$physical])['uuid'];
verify((int)$phase5Repository->one('SELECT COUNT(*) n FROM casillas WHERE id=?',[$physical])['n']===1,'Dos elecciones reutilizan una casilla física, sin duplicar el catálogo.');
phase7Api('/api/users/'.$phase7Accounts[0].'/accesses','POST',['process'=>$phase7ElectionB,'type'=>3]);
foreach(['resultados.capturar','resultados.ver','archivos.subir','archivos.ver'] as $permission)phase3Grant($phase7Accounts[0],$permission,$phase7ElectionB,'propio');
phase7Api('assignments','PUT',['casillas'=>[$boothB],'usuarios'=>[$phase7Accounts[0]],'activo'=>1]);
$phase7B=phase7Api('configuration');phase7Api('state/abrir','POST',['version'=>$phase7B['configuration']['version'],'motivo'=>'Prueba de validación inicial']);
phase7Api('/api/auth/election','POST',['process'=>$phase7ElectionB],'phase7-0');
phase7Api('drafts','POST',['casilla'=>$boothB,'clave'=>Values::uuid()],'phase7-0',409);
$phase7B=phase7Api('configuration');phase7Api('configuration','PUT',['zona'=>'America/Monterrey','captura_local'=>gmdate('Y-m-d\TH:i',time()-86400),'politica_inicial'=>'validar','foto_obligatoria'=>1,'denominador'=>'seleccionadas','version'=>$phase7B['configuration']['version']]);
$draftB=phase7Draft($boothB);$captureB=['casilla'=>$boothB,'borrador'=>$draftB,'clave'=>Values::uuid(),'votos'=>[$optionB=>15]];
phase7Api('captures','POST',$captureB,'phase7-0',422);
verify(phase7Upload($draftB,'foto-ajena.png',$temporaryDirectory.'/test-image.png','phase7-0',$phase7Process)['status']===404,'El contexto de archivo no puede pertenecer a otra elección.');
$photoB=phase7Upload($draftB,'acta-b.png',$temporaryDirectory.'/test-image.png','phase7-0',$phase7ProcessB);verify($photoB['status']===200,'Foto obligatoria se guarda en la elección correcta.');
$firstB=phase7Api('captures','POST',[...$captureB,'foto'=>$photoB['json']['data']['uuid']],'phase7-0');verify($firstB['estado']==='pendiente','Política B conserva la primera captura pendiente.');
verify(phase7Api('summary?modo=validado')['total_acta']===0 && phase7Api('summary?modo=preliminar')['total_acta']===0,'Una captura inicial pendiente no contribuye a ningún concentrado.');
phase7Api('versions/'.$phase7Correction['uuid'],'GET',null,'admin',404);
phase7Json('versions/'.$firstB['uuid'],['aprobar'=>1,'motivo'=>'Validación inicial de foto obligatoria','actual_esperada'=>null],'admin',200,'PUT');
$summaryB=phase7Api('summary?modo=validado');verify($summaryB['denominador']===15 && $summaryB['candidaturas'][0]['porcentaje']===100,'Denominador seleccionado se utiliza explícitamente.');
verify(request('phase7-0','GET','/media/'.$phase7Photo)['status']===404,'Conocer un UUID de acta de otra elección no permite descargarla.');
foreach(['pdf','xlsx','csv'] as $format) {
    $export=phase7Api('/api/exports','POST',['type'=>'jornada_candidaturas','format'=>$format,'filters'=>['modo'=>'validado']],'admin',202);
    verify(request('admin','GET','/api/exports/'.$export['uuid'].'/download')['status']===404,'Una exportación pendiente todavía no permite descargar archivos.');
    (new WorkerService($phase5Repository))->run(100,60,'exportacion');
    $file=request('admin','GET','/api/exports/'.$export['uuid'].'/download');
    $exportState=$phase5Repository->one('SELECT id,estado,error FROM exportaciones WHERE uuid=?',[Values::binary($export['uuid'])]);
    if($file['status']!==200)throw new RuntimeException('Informe B '.$format.' '.$file['status'].' '.json_encode($exportState).' '.json_encode($phase5Repository->rows("SELECT tipo,estado,error,disponible_en FROM trabajos WHERE tipo='exportacion' ORDER BY id DESC LIMIT 3")));
    verify($file['status']===200,'Informe B real '.$format.' se descarga.');
    if($format==='csv')verify(str_contains($file['body'],"'=SUM(1,2)"),'CSV neutraliza fórmulas de nombres configurables.');
}
phase7Api('/api/auth/election','POST',['process'=>$phase7Election]);phase7Api('/api/auth/election','POST',['process'=>$phase7Election],'phase7-0');
phase3Grant($phase7Accounts[0],'resultados.validar',$phase7Election,'territorio');
$foreignPending=phase7Api('captures','POST',phase7CaptureData($phase7Booths[0],[135,80,20,0],'phase7-1'),'phase7-1');
verify(phase7Api('versions/'.$foreignPending['uuid'],'GET',null,'phase7-0')['visible']===false,'Tener validación fuera de alcance no revela votos pendientes ajenos.');
phase7Api('versions/'.$foreignPending['uuid'],'PUT',['aprobar'=>1,'motivo'=>'Fuera de territorio','actual_esperada'=>$phase7Correction['uuid']],'phase7-0',404);
phase3Grant($phase7Accounts[0],'resultados.validar',$phase7Election,'propio',0);

require __DIR__.'/phase7-concurrency.php';
require __DIR__.'/phase7-services.php';
file_put_contents($temporaryDirectory.'/phase7-fixtures.json',json_encode(['election'=>$phase7Election,'process'=>$phase7Process,'election_b'=>$phase7ElectionB,'booth_b'=>$boothB,'booths'=>$phase7Booths,'options'=>$phase7Options,'accounts'=>$phase7Accounts,'current'=>$phase7Correction['uuid'],'photo'=>$phase7Photo,'pending'=>$foreignPending['uuid']],JSON_PRETTY_PRINT));
