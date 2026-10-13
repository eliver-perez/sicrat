<?php

use App\Services\Common\Values;
function phase7Race(array $requests): array {
    global $environment,$phase7Process,$temporaryDirectory;$writers=[];$barrier=$temporaryDirectory.'/jornada-go-'.bin2hex(random_bytes(6));
    foreach($requests as $request){$writer=proc_open([PHP_BINARY,__DIR__.'/jornada-writer.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__),$environment);fwrite($pipes[0],json_encode(['actor'=>1,'process'=>$phase7Process,'barrier'=>$barrier,...$request],JSON_THROW_ON_ERROR));fclose($pipes[0]);$writers[]=[$writer,$pipes];}
    foreach($writers as [, $pipes])verify(trim(fgets($pipes[1]))==='READY','Escritor independiente está preparado antes de liberar la barrera.');
    file_put_contents($barrier,'go');$rows=[];
    foreach($writers as [$writer,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);verify(proc_close($writer)===0,'Escritor concurrente termina sin error técnico: '.$err);$rows[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);}unlink($barrier);return $rows;
}
function phase7Statuses(array $rows,array $expected,string $label): void {$actual=array_column($rows,'status');sort($actual);sort($expected);verify($actual===$expected,$label.': '.json_encode($rows));}
$initial=phase7Race([['actor'=>$phase7AccountIds[0],'action'=>'capture','data'=>phase7CaptureData($phase7Booths[1],[100,50,20,0])],['actor'=>$phase7AccountIds[1],'action'=>'capture','data'=>phase7CaptureData($phase7Booths[1],[100,60,20,0],'phase7-1')]]);
phase7Statuses($initial,[200,200],'Dos primeras capturas simultáneas se conservan');$states=array_column(array_column($initial,'data'),'estado');sort($states);verify($states===['pendiente','preliminar'],'Una sola captura inicial es vigente y la otra queda pendiente.');
$booth=phase7Api('booths/'.$phase7Booths[1]);$pending=array_values(array_filter($initial,static fn($row)=>$row['data']['estado']==='pendiente'))[0]['data']['uuid'];
$decision=['aprobar'=>1,'motivo'=>'Revisión simultánea del acta','actual_esperada'=>$booth['actual_esperada']];phase7Statuses(phase7Race(array_fill(0,2,['action'=>'decide','uuid'=>$pending,'data'=>$decision])),[200,409],'Dos validaciones no resuelven dos veces la versión.');
$base=phase7Api('captures','POST',phase7CaptureData($phase7Booths[3]),'phase7-0');$proposals=[];foreach([210,220] as $amount)$proposals[]=phase7Api('captures','POST',phase7CaptureData($phase7Booths[3],[$amount,0,0,0]),'phase7-0')['uuid'];
$race=phase7Race(array_map(static fn($uuid)=>['action'=>'decide','uuid'=>$uuid,'data'=>['aprobar'=>1,'motivo'=>'Publicación simultánea','actual_esperada'=>$base['uuid']]],$proposals));phase7Statuses($race,[200,409],'Dos propuestas que sustituyen la misma vigente no se sobrescriben.');
$base=phase7Api('captures','POST',phase7CaptureData($phase7Booths[4]),'phase7-0');
$correction=['casilla'=>$phase7Booths[4],'clave'=>Values::uuid(),'votos'=>array_combine($phase7Options,[330,0,0,0]),'actual_esperada'=>$base['uuid'],'motivo'=>'Corrección simultánea a captura','validar'=>1];
phase7Statuses(phase7Race([['actor'=>$phase7AccountIds[0],'action'=>'capture','data'=>phase7CaptureData($phase7Booths[4],[310,0,0,0])],['action'=>'correct','data'=>$correction]]),[200,200],'Captura y corrección conservan ambas versiones.');verify(phase7Api('booths/'.$phase7Booths[4])['vigente']['total']===330,'La propuesta no reemplaza la corrección validada.');
foreach([5,6] as $index) {
    $base=phase7Api('captures','POST',phase7CaptureData($phase7Booths[$index]),'phase7-0');$requests=[];
    foreach([210,220] as $amount)$requests[]=['action'=>'correct','data'=>['casilla'=>$phase7Booths[$index],'clave'=>Values::uuid(),'actual_esperada'=>$base['uuid'],'votos'=>array_combine($phase7Options,[$amount,0,0,0]),'motivo'=>'Corrección concurrente '.$amount,'validar'=>$index===5 ? 1 : 0]];
    phase7Statuses(phase7Race($requests),$index===5 ? [200,409] : [200,200],$index===5 ? 'Dos correcciones validadas no pisan la misma vigente' : 'Dos correcciones propuestas se conservan pendientes');
}
$data=phase7CaptureData($phase7Booths[7]);$config=phase7Api('configuration')['configuration'];
$race=phase7Race([['actor'=>$phase7AccountIds[0],'action'=>'capture','data'=>$data],['action'=>'state','transition'=>'cerrar','data'=>['version'=>$config['version'],'motivo'=>'Cierre concurrente a captura']]]);
$statuses=array_column($race,'status');sort($statuses);verify(in_array($statuses,[[200,200],[200,409]],true),'Cierre y captura respetan el orden del bloqueo sin escrituras posteriores.');verify(phase7Api('configuration')['configuration']['estado']==='cerrada','Jornada queda cerrada tras la carrera.');
phase7Api('captures','POST',[...$data,'clave'=>Values::uuid()],'phase7-0',409);
$config=phase7Api('configuration')['configuration'];phase7Api('state/reabrir','POST',['version'=>$config['version'],'motivo'=>'Reapertura tras ensayo concurrente']);
$data=phase7CaptureData($phase7Booths[8]);$race=phase7Race([['actor'=>$phase7AccountIds[0],'action'=>'capture','data'=>$data],['action'=>'assign','data'=>['casillas'=>[$phase7Booths[8]],'usuarios'=>[$phase7Accounts[0]],'activo'=>0]]]);$statuses=array_column($race,'status');sort($statuses);verify(in_array($statuses,[[200,200],[200,403]],true),'Retirar asignación y capturar respetan el bloqueo común.');
phase7Api('assignments','PUT',['casillas'=>[$phase7Booths[8]],'usuarios'=>[$phase7Accounts[0]],'activo'=>1]);
$data=phase7CaptureData($phase7Booths[9]);$file=phase7Upload($data['borrador'],'acta-concurrente.png',$temporaryDirectory.'/test-image.png');verify($file['status']===200,'Imagen real prepara carrera de acta versus baja.');$uuid=$file['json']['data']['uuid'];
$race=phase7Race([['actor'=>$phase7AccountIds[0],'action'=>'capture','data'=>[...$data,'foto'=>$uuid]],['actor'=>$phase7AccountIds[0],'action'=>'delete','uuid'=>$uuid]]);$statuses=array_column($race,'status');sort($statuses);verify(in_array($statuses,[[200,403],[200,404]],true),'Capturar o retirar foto no deja una versión con acta eliminada.');
verify((int)$phase5Repository->one("SELECT COUNT(*) n FROM jornada_versiones v JOIN archivos a ON a.id=v.foto WHERE a.estado<>'activo'")['n']===0,'Las versiones históricas mantienen sus actas activas.');
$data=phase7CaptureData($phase7Booths[10]);$race=phase7Race(array_fill(0,2,['actor'=>$phase7AccountIds[0],'action'=>'capture','data'=>$data]));phase7Statuses($race,[200,200],'Reintento concurrente es idempotente');verify($race[0]['data']['uuid']===$race[1]['data']['uuid'],'No duplica captura ni votos bajo dos conexiones.');
verify((int)$phase5Repository->one("SELECT COUNT(*) n FROM jornada_resultados r JOIN jornada_versiones v ON v.id=r.actual_version WHERE v.resultado<>r.id OR v.estado NOT IN('preliminar','validada')")['n']===0,'Cada puntero vigente pertenece a su casilla y estado permitido.');
