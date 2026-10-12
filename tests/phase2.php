<?php

// Se ejecuta mediante integration.php --phase2, dentro de la misma base desechable.
$phase1Checks = $checks;
$captureToken = login('capture', 'captura-a', $password);
$otherToken = login('other', 'captura-b', $password);
$identity = new App\Services\AuthService();
function apiData(array $response): array { verify($response['status'] < 400,'Respuesta válida: '.$response['body']); return $response['json']['data'] ?? []; }

$oneElection = apiData(request('capture','GET','/api/auth/elections'))['electoral_processes'];
verify(count($oneElection) === 1 && $oneElection[0]['selected'], 'Un acceso selecciona su única elección al iniciar sesión.');
$secondElectionUuid = random_bytes(16);
$secondElection = $identity->uuidBinaryToString($secondElectionUuid);
$statement = $connection->prepare('INSERT INTO procesos_electorales (uuid,organizacion,proceso,tipo,caracter,registro,f_registro) VALUES (?,1,\'Segunda elección\',1,1,1,NOW())');
$statement->execute([$secondElectionUuid]);
$secondElectionId = (int) $connection->lastInsertId();
$accountUuid = $identity->uuidBinaryToString($connection->query('SELECT uuid FROM usuarios WHERE id=2')->fetchColumn());
verify(request('capture','POST','/api/users/'.$accountUuid.'/accesses',['process'=>$secondElection,'type'=>3],$captureToken)['status']===403,'Capturador no administra sus propios accesos.');
verify(request('admin','POST','/api/users/'.$accountUuid.'/accesses',['process'=>$processIds[1],'type'=>3],$adminToken)['status']===404,'Ni el alta administrativa permite elección de otra organización.');
apiData(request('admin','POST','/api/users/'.$accountUuid.'/accesses',['process'=>$secondElection,'type'=>3],$adminToken));
$many = apiData(request('capture','GET','/api/auth/elections'))['electoral_processes'];
verify(count($many)===2,'Misma cuenta dispone de dos elecciones.');
verify(request('capture','POST','/api/auth/election',['process'=>$secondElection])['status']===403,'Cambio de contexto exige CSRF.');
verify(request('capture','POST','/api/auth/election',['process'=>$processIds[1]],$captureToken)['status']===404,'No se selecciona una elección ajena.');
$unassignedUuid = $identity->uuidBinaryToString(random_bytes(16));
$statement = $connection->prepare('INSERT INTO procesos_electorales (uuid,organizacion,proceso,tipo,caracter,registro,f_registro) VALUES (?,1,\'Sin asignación\',1,1,1,NOW())');
$statement->execute([$identity->uuidStringToBinary($unassignedUuid)]);
verify(request('capture','POST','/api/auth/election',['process'=>$unassignedUuid],$captureToken)['status']===404,'No se selecciona elección propia sin asignación.');
$switched=apiData(request('capture','POST','/api/auth/election',['process'=>$secondElection],$captureToken));
verify($switched['type']==='coordinador-general' && !in_array('captura-personas',$switched['rights'],true),'El rol y sus permisos se recalculan al cambiar.');
verify(request('capture','GET','/api/persons')['status']===403,'Cambiar a coordinador no concede captura.');
apiData(request('capture','POST','/api/auth/election',['process'=>$processIds[0]],$captureToken));
verify(request('capture','GET','/api/persons')['status']===200,'Cambio de regreso sin nuevo login.');
verify(request('admin','POST','/api/users/'.$accountUuid.'/accesses',['process'=>$secondElection,'type'=>1],$adminToken)['status']===422,'No se asigna el privilegio global como tipo electoral a una cuenta ordinaria.');
$connection->exec('UPDATE usuarios_procesos SET tipo_usuario=1 WHERE usuario=2 AND proceso=1');
apiData(request('capture','POST','/api/auth/election',['process'=>$processIds[0]],$captureToken));
verify(request('capture','GET','/api/users')['status']===403,'Incluso una relación histórica alterada no concede administración global al seleccionar.');
verify(request('capture','POST','/api/organizations',['organization'=>'No permitido'],$captureToken)['status']===403,'Un tipo electoral privilegiado no habilita operaciones globales.');
$connection->exec('UPDATE usuarios_procesos SET tipo_usuario=8 WHERE usuario=2 AND proceso=1');
verify(request('capture','GET','/api/persons')['status']===200,'El tipo electoral restaurado vuelve a conceder solo sus permisos existentes.');

$captureToken=login('capture','captura-a',$password);
verify(apiData(request('capture','GET','/api/auth/me'))['process_id']===null,'Con varios accesos el login espera selección.');
apiData(request('capture','POST','/api/auth/election',['process'=>$processIds[0]],$captureToken));
verify(request('capture','POST','/api/auth/election',['process'=>$identity->uuidBinaryToString($connection->query("SELECT uuid FROM procesos_electorales WHERE proceso='Proceso inactivo'")->fetchColumn())],$captureToken)['status']===404,'No se selecciona elección inactiva de la propia organización.');
apiData(request('admin','POST','/api/auth/election',['process'=>$secondElection],$adminToken));
verify(count(apiData(request('admin','GET','/api/organizations'))['organizations'])>=2,'Super Administrador conserva alcance global después de seleccionar.');

$before=(int) $connection->query('SELECT COUNT(*) FROM personas')->fetchColumn();
$quick=apiData(request('capture','POST','/api/persons',['name'=>'Persona rápida'],$captureToken));
verify(preg_match('/^[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{10}$/',$quick['code'])===1,'Código legible y aleatorio de diez caracteres.');
$detail=apiData(request('capture','GET','/api/persons/'.$quick['id']));
verify($detail['section']===null && $detail['gender']===null && $detail['last_name']===null && $detail['participations']===[],'Solo nombre crea identidad sin datos inventados ni elección.');
apiData(request('capture','POST','/api/persons/'.$quick['id'].'/participations',['processes'=>[$processIds[0]],'active'=>1],$captureToken));
apiData(request('capture','POST','/api/persons/'.$quick['id'].'/participations',['processes'=>[$processIds[0],$secondElection],'active'=>1],$captureToken));
verify(count(apiData(request('capture','GET','/api/persons/'.$quick['id']))['participations'])===2,'Una persona participa en dos elecciones sin repetir relación.');
verify((int) $connection->query('SELECT COUNT(*) FROM personas')->fetchColumn()===$before+1,'Las participaciones no duplican identidad.');
verify(request('capture','POST','/api/persons/'.$quick['id'].'/participations',['processes'=>[$processIds[1]],'active'=>1],$captureToken)['status']===404,'Participación ajena rechazada.');
apiData(request('capture','POST','/api/persons/'.$quick['id'].'/participations',['processes'=>[$processIds[0]],'active'=>0],$captureToken));
$detail=apiData(request('capture','GET','/api/persons/'.$quick['id']));
verify(count(array_filter($detail['participations'],fn($row)=>$row['active']===0))===1,'Retirar mantiene la ficha y estado de participación.');
verify(request('other','GET','/api/persons/'.$quick['id'])['status']===404,'La ficha es invisible en otra organización.');
apiData(request('capture','PUT','/api/persons/'.$quick['id'],['name'=>'Identidad editada','code'=>'CAMBIADO','phone'=>'5551234567','street'=>'Calle de prueba','exterior_number'=>'123','interior_number'=>'2A','neighborhood'=>'1','latitude'=>'25.5','longitude'=>'-100.3'],$captureToken));
$detail=apiData(request('capture','GET','/api/persons/'.$quick['id']));
verify($detail['code']===$quick['code'] && $detail['street']==='Calle de prueba' && $detail['exterior_number']==='123' && $detail['interior_number']==='2A' && (int) $detail['neighborhood']===1 && (float) $detail['latitude']===25.5,'Editar conserva código y permite domicilio.');
verify(count(apiData(request('capture','GET','/api/persons?search='.$quick['code']))['persons'])===1,'Se localiza persona por código.');
apiData(request('admin','PUT','/api/users/'.$accountUuid.'/person',['person'=>$quick['id']],$adminToken));
verify(apiData(request('admin','GET','/api/users/'.$accountUuid.'/accesses'))['person']===$quick['id'],'Relación opcional persona y cuenta sin duplicación.');
$otherPerson=apiData(request('other','GET','/api/persons'))['persons'][0]['id'];
verify(request('admin','PUT','/api/users/'.$accountUuid.'/person',['person'=>$otherPerson],$adminToken)['status']===422,'No se vincula cuenta y persona de organizaciones diferentes.');

$strictPerson=['name'=>'Identidad estricta','curp'=>'AAAA000101HDFBBB01','elector_key'=>'CLAVEUNICA123','email'=>'original@example.com'];
$strict=apiData(request('capture','POST','/api/persons',$strictPerson,$captureToken));
verify(request('capture','POST','/api/persons',['name'=>'Otra CURP','curp'=>$strictPerson['curp'],'confirm_duplicate'=>1],$captureToken)['status']===409,'CURP estricta no se evade confirmando.');
verify(request('capture','POST','/api/persons',['name'=>'Otra clave','elector_key'=>$strictPerson['elector_key'],'confirm_duplicate'=>1],$captureToken)['status']===409,'Clave estricta impide duplicado.');
verify(request('capture','PUT','/api/organizations/'.$organizationIds[0].'/identity-policy',['curp_unique'=>0,'elector_key_unique'=>0],$captureToken)['status']===403,'Configuración necesita autorización administrativa.');
apiData(request('admin','PUT','/api/organizations/'.$organizationIds[0].'/identity-policy',['curp_unique'=>0,'elector_key_unique'=>1],$adminToken));
$warning=request('capture','POST','/api/persons',['name'=>'Duplicado permitido','curp'=>$strictPerson['curp']],$captureToken);
verify($warning['status']===409 && !$warning['json']['data']['blocked'],'CURP desactivada advierte antes de continuar.');
apiData(request('capture','POST','/api/persons',['name'=>'Duplicado permitido','curp'=>$strictPerson['curp'],'confirm_duplicate'=>1],$captureToken));
verify(request('capture','POST','/api/persons',['name'=>'Clave todavía estricta','elector_key'=>$strictPerson['elector_key'],'confirm_duplicate'=>1],$captureToken)['status']===409,'Las reglas son independientes.');
apiData(request('admin','PUT','/api/organizations/'.$organizationIds[0].'/identity-policy',['curp_unique'=>1,'elector_key_unique'=>0],$adminToken));
apiData(request('capture','POST','/api/persons',['name'=>'Clave permitida','elector_key'=>$strictPerson['elector_key'],'confirm_duplicate'=>1],$captureToken));
apiData(request('admin','PUT','/api/organizations/'.$organizationIds[0].'/identity-policy',['curp_unique'=>1,'elector_key_unique'=>1],$adminToken));
apiData(request('other','POST','/api/persons',$strictPerson,$otherToken));
verify((int) $connection->query("SELECT COUNT(*) FROM personas WHERE curp='AAAA000101HDFBBB01'")->fetchColumn()===3,'Mismo identificador se admite en organizaciones distintas y datos anteriores se conservan.');
verify(request('capture','PUT','/api/persons/'.$quick['id'],['curp'=>$strictPerson['curp']],$captureToken)['status']===409,'Edición aplica unicidad estricta.');
verify(request('capture','PUT','/api/persons/'.$quick['id'],['birth_date'=>'2026-02-30'],$captureToken)['status']===422,'Fecha imposible se rechaza.');
verify(request('other','PUT','/api/persons/'.$quick['id'],['name'=>'Ajeno'],$otherToken)['status']===404,'Edición también aísla organizaciones.');
$similar=apiData(request('capture','POST','/api/persons',['name'=>'José Álvarez','phone'=>'5557778888'],$captureToken));
$warning=request('capture','POST','/api/persons',['name'=>'JOSE ALVAREZ','phone'=>'5557778888'],$captureToken);
verify($warning['status']===409 && !$warning['json']['data']['blocked'],'Coincidencia por nombre sin acentos y teléfono es advertencia no unicidad.');
apiData(request('capture','POST','/api/persons',['name'=>'JOSE ALVAREZ','phone'=>'5557778888','confirm_duplicate'=>1],$captureToken));
verify((int) $connection->query("SELECT COUNT(*) FROM personas WHERE telefono='5557778888'")->fetchColumn()===2,'No hay fusión automática.');

apiData(request('capture','POST','/api/persons',['name'=>'Identificador cero','curp'=>'0'],$captureToken));
verify(request('capture','POST','/api/persons',['name'=>'Cero repetido','curp'=>'0','confirm_duplicate'=>1],$captureToken)['status']===409,'Identificador textual cero no se confunde con ausencia y mantiene unicidad.');

// Colisión real del índice: un generador de prueba devuelve primero un código ya usado.
$_SESSION=['SICRAT_ID'=>1,'SICRAT_GLOBAL_TYPE_CODE'=>'superadmin'];
$collisionCodes=new class($quick['code']) extends App\Core\PersonCode {
    public int $attempts=0;
    public function __construct(private string $existing) {}
    public function generate(): string { $this->attempts++; return $this->attempts===1 ? $this->existing : parent::generate(); }
};
$collisionService=new App\Services\PersonIdentityService(new App\Repositories\PersonIdentityRepository($connection),$collisionCodes);
$collision=$collisionService->create(['name'=>'Colisión simulada','organization'=>$organizationIds[0]]);
verify($collision['created'] && $collisionCodes->attempts===2 && $collision['code']!==$quick['code'],'Colisión simulada reintenta sin perder la persona.');
verify((new App\Core\Migrations($connection))->run()===[],'Migraciones repetidas no cambian códigos ni datos.');
verify((int) $connection->query('SELECT COUNT(*) FROM personas WHERE codigo_persona IS NULL')->fetchColumn()===0,'Migración asignó códigos a todas las personas existentes.');
$writers = [];
foreach ([1,2] as $writerNumber) {
    $writer = proc_open([PHP_BINARY, __DIR__ . '/identity-writer.php', $organizationIds[0], 'CCCC000101HDFBBB01'],
        [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['file', $temporaryDirectory . '/writer.log', 'a']], $writerPipes, dirname(__DIR__), $environment);
    $writers[] = ['process'=>$writer, 'pipes'=>$writerPipes];
}
$writerResults = [];
foreach ($writers as $writer) {
    fclose($writer['pipes'][0]);
    $writerResults[] = json_decode(stream_get_contents($writer['pipes'][1]), true, 512, JSON_THROW_ON_ERROR);
    fclose($writer['pipes'][1]);
    verify(proc_close($writer['process'])===0, 'Proceso concurrente terminó correctamente.');
}
verify(count(array_filter($writerResults, fn($result)=>$result['created']))===1, 'Dos altas concurrentes producen una sola persona con CURP estricta.');
verify((int) $connection->query("SELECT COUNT(*) FROM personas WHERE curp='CCCC000101HDFBBB01'")->fetchColumn()===1, 'Bloqueo transaccional conserva unicidad frente a conexiones independientes.');


function uploadImport(string $client,string $csrf,string $file,string $organization): array {
    global $baseUrl,$temporaryDirectory;
    $curl=curl_init($baseUrl.'/api/person-imports');
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_COOKIEFILE=>$temporaryDirectory.'/'.$client.'.cookies',CURLOPT_COOKIEJAR=>$temporaryDirectory.'/'.$client.'.cookies',CURLOPT_HTTPHEADER=>['X-CSRF-Token: '.$csrf],CURLOPT_POSTFIELDS=>['organization'=>$organization,'file'=>new CURLFile($file)],CURLOPT_TIMEOUT=>30]);
    $body=curl_exec($curl);
    return ['status'=>curl_getinfo($curl,CURLINFO_HTTP_CODE),'body'=>$body,'json'=>json_decode($body,true)];
}
function prepareImport(string $id, array $mapping,int $detect,string $token): array {
    apiData(request('capture','PUT','/api/person-imports/'.$id.'/mapping',['mapping'=>$mapping,'detect_duplicates'=>$detect],$token));
    $after=0;$rows=[];
    do {
        $preview=apiData(request('capture','POST','/api/person-imports/'.$id.'/preview',['after'=>$after],$token));
        verify(count($preview['rows'])<=100,'Vista previa limitada a cien filas.');
        $rows=[...$rows,...$preview['rows']];$after=$preview['next_after'];
    } while (!$preview['review_complete']);
    return $rows;
}
function finishImport(string $id,string $token): array {
    apiData(request('capture','POST','/api/person-imports/'.$id.'/confirm',[],$token));
    verify(request('capture','POST','/api/person-imports/'.$id.'/confirm',[],$token)['status']===200,'Reintentar confirmación no bloquea una importación ya confirmada.');
    do {
        $result=apiData(request('capture','POST','/api/person-imports/'.$id.'/apply',[],$token));
        verify(count($result['rows'])<=100,'La aplicación utiliza lotes de hasta cien filas.');
    } while (!$result['complete']);
    return $result['summary'];
}
$csv=$temporaryDirectory.'/personas.csv';
$handle=fopen($csv,'wb'); fputcsv($handle,['Nombre','Correo','CURP','Columna desconocida'],',','"','');
for ($row=0;$row<1205;$row++) fputcsv($handle,['Lote persona '.$row,'lote'.$row.'@example.com','','ignorar'],',','"','');
fputcsv($handle,['Correo inválido','not-email','',''],',','"','');fputcsv($handle,['Sin nombre','','',''],',','"',''); // nombre válido; siguiente fila no tiene nombre.
fputcsv($handle,['','empty@example.com','',''],',','"','');
fputcsv($handle,['CURP repetida','',$strictPerson['curp'],''],',','"','');fclose($handle);
$import=apiData(uploadImport('capture',$captureToken,$csv,$organizationIds[0]));
verify(request('other','GET','/api/person-imports/'.$import['id'])['status']===404,'Importación ajena permanece invisible.');
verify(request('capture','POST','/api/person-imports/'.$import['id'].'/apply',[],$captureToken)['status']===409,'No se importa antes de confirmar.');
verify(request('capture','PUT','/api/person-imports/'.$import['id'].'/mapping',['mapping'=>['name'=>0,'invented'=>3]],$captureToken)['status']===422,'Destino desconocido no se inserta.');
$rows=prepareImport($import['id'],['name'=>0,'email'=>1,'curp'=>2],0,$captureToken);
$exact=end($rows);
verify($exact['review']['blocked'] && $exact['state']==='decision','Detección desactivada sigue aplicando CURP estricta.');
apiData(request('capture','PUT','/api/person-imports/'.$import['id'].'/decisions',['decisions'=>[['row'=>$exact['row'],'action'=>'existing','person'=>$strict['id']]]],$captureToken));
$participationsBefore=(int) $connection->query('SELECT COUNT(*) FROM personas_procesos')->fetchColumn();
$summary=finishImport($import['id'],$captureToken);
verify($summary['nuevo']===1206 && $summary['rechazado']===2 && $summary['vinculado']===1,'Importación válida por lotes informa nuevos, inválidos y vinculados.');
verify((int) $connection->query('SELECT COUNT(*) FROM personas_procesos')->fetchColumn()===$participationsBefore,'Importar no asigna elecciones automáticamente.');
verify(apiData(request('capture','POST','/api/person-imports/'.$import['id'].'/apply',[],$captureToken))['summary']===$summary,'Reintento de lote final no duplica personas.');
$results=apiData(request('capture','GET','/api/person-imports/'.$import['id'].'/results'));
verify(count($results['rows'])===100 && $results['has_more'],'Resultados históricos se consultan por páginas acotadas.');
verify(request('other','GET','/api/person-imports/'.$import['id'].'/results')['status']===404,'Resultados también mantienen aislamiento.');

$duplicates=$temporaryDirectory.'/duplicados.csv';
$handle=fopen($duplicates,'wb'); fputcsv($handle,['name','email','curp','phone'],',','"','');
fputcsv($handle,['Nombre que no debe reemplazar','reemplazo@example.com',$strictPerson['curp'],'5550001111'],',','"','');
fputcsv($handle,['José Álvarez','','','5557778888'],',','"','');
fputcsv($handle,['Nueva identidad intrarchivo','','BBBB000101HDFBBB01',''],',','"','');
fputcsv($handle,['Misma intrarchivo','','BBBB000101HDFBBB01',''],',','"','');fclose($handle);
$import2=apiData(uploadImport('capture',$captureToken,$duplicates,$organizationIds[0]));
$rows=prepareImport($import2['id'],['name'=>0,'email'=>1,'curp'=>2,'phone'=>3],1,$captureToken);
verify($rows[1]['review']['matches']!==[] && !$rows[1]['review']['blocked'],'Detección activada presenta coincidencias no únicas.');
verify($rows[3]['review']['blocked'] && (int) $rows[3]['review']['file_matches'][0]['fila']===3,'Se detectan identificadores repetidos dentro del archivo.');
verify(request('capture','POST','/api/person-imports/'.$import2['id'].'/confirm',[],$captureToken)['status']===409,'Coincidencias sin decisión impiden confirmación.');
apiData(request('capture','PUT','/api/person-imports/'.$import2['id'].'/decisions',['decisions'=>[
    ['row'=>1,'action'=>'fill','person'=>$strict['id']],['row'=>2,'action'=>'skip'],['row'=>4,'action'=>'existing','source_row'=>3]
]],$captureToken));
$plan=apiData(request('capture','GET','/api/person-imports/'.$import2['id']))['planned'];
verify($plan['new']===1 && $plan['fill']===1 && $plan['skip']===1 && $plan['existing']===1,'Resumen previo muestra las decisiones antes de importar.');
$summary2=finishImport($import2['id'],$captureToken);
verify($summary2['completado']===1 && $summary2['omitido']===1 && $summary2['nuevo']===1 && $summary2['vinculado']===1,'Decisiones explícitas completan, omiten y usan fila anterior.');
$filled=apiData(request('capture','GET','/api/persons/'.$strict['id']));
verify($filled['name']===$strictPerson['name'] && $filled['email']===$strictPerson['email'] && $filled['phone']==='5550001111','Completar llena vacíos sin sustituir datos existentes.');

$approx=$temporaryDirectory.'/sin_deteccion.csv';file_put_contents($approx,"name,phone\nJosé Álvarez,5557778888\n");
$import3=apiData(uploadImport('capture',$captureToken,$approx,$organizationIds[0]));
$rows=prepareImport($import3['id'],['name'=>0,'phone'=>1],0,$captureToken);
verify($rows[0]['review']['matches']===[] && $rows[0]['state']==='listo','Sin detección no se buscan nombres y teléfonos similares.');
verify(finishImport($import3['id'],$captureToken)['nuevo']===1,'Sin detección se importa coincidencia no única sin fusionar.');

$xlsx=$temporaryDirectory.'/personas.xlsx';
$writer=new OpenSpout\Writer\XLSX\Writer();$writer->openToFile($xlsx);
$writer->addRow(OpenSpout\Common\Entity\Row::fromValues(['Nombre','Correo','Sección']));
$writer->addRow(OpenSpout\Common\Entity\Row::fromValues(['Persona XLSX','xlsx@example.com','100']));$writer->close();
$xlsxImport=apiData(uploadImport('capture',$captureToken,$xlsx,$organizationIds[0]));
prepareImport($xlsxImport['id'],['name'=>0,'email'=>1,'section'=>2],1,$captureToken);
verify(finishImport($xlsxImport['id'],$captureToken)['nuevo']===1,'Archivo XLSX real se importa con OpenSpout.');
$formula=$temporaryDirectory.'/formula.xlsx';$writer=new OpenSpout\Writer\XLSX\Writer();$writer->openToFile($formula);
$writer->addRow(OpenSpout\Common\Entity\Row::fromValues(['Nombre']));$writer->addRow(OpenSpout\Common\Entity\Row::fromValues(['=1+1']));$writer->close();
$macro=$temporaryDirectory.'/macro.xlsx'; copy($xlsx,$macro); $zip=new ZipArchive(); $zip->open($macro); $zip->addFromString('xl/vbaProject.bin','contenido'); $zip->close();
verify(uploadImport('capture',$captureToken,$macro,$organizationIds[0])['status']===422,'Se rechaza contenido de macro dentro de extensión XLSX.');
verify(uploadImport('capture',$captureToken,$formula,$organizationIds[0])['status']===422,'XLSX con fórmulas no ejecuta ni importa contenido activo.');
verify(uploadImport('capture','incorrecto',$xlsx,$organizationIds[0])['status']===403,'Carga multipart mantiene CSRF.');
$statement=$connection->prepare("UPDATE personas_importaciones SET expira_en='2000-01-01' WHERE id=?");
$statement->execute([$import3['id']]);
verify(request('capture','GET','/api/person-imports/'.$import3['id'])['status']===404,'Importación caducada no permite acceso.');
apiData(uploadImport('capture',$captureToken,$xlsx,$organizationIds[0]));
$statement=$connection->prepare('SELECT COUNT(*) FROM personas_importacion_filas WHERE importacion=?');
$statement->execute([$import3['id']]);
verify((int) $statement->fetchColumn()===0,'Limpieza acotada elimina filas temporales caducadas mediante FK.');
$connection->exec('UPDATE procesos_electorales SET estatus=0 WHERE id=1');
verify(request('capture','GET','/api/persons')['status']===401,'Elección inactiva invalida el contexto seleccionado.');
$connection->exec('UPDATE procesos_electorales SET estatus=1 WHERE id=1');
$captureToken=login('capture','captura-a',$password);
verify(request('capture','POST','/api/auth/election',['process'=>$secondElection],$captureToken)['status']===200,'Usuario vuelve a seleccionar tras cambio administrativo.');
$connection->exec('UPDATE usuarios_procesos SET activo=0 WHERE usuario=2 AND proceso='.$secondElectionId);
verify(request('capture','GET','/api/auth/me')['status']===401,'Asignación revocada no mantiene contexto ni permisos.');
$sessionFiles=glob($temporaryDirectory.'/sess_*');
$sessionToken=null;
foreach ($sessionFiles as $file) {
    $serialized=file_get_contents($file);
    if (str_contains($serialized,'SICRAT_ID|i:2;') && preg_match('/SICRAT_AUTH_TOKEN\|s:64:"([a-f0-9]{64})"/',$serialized,$match)) $sessionToken=$match[1];
}
// En la prueba HTTP previa una invalidación limpia el fichero; probar con un login nuevo.
$captureToken=login('capture','captura-a',$password);
foreach (glob($temporaryDirectory.'/sess_*') as $file) {
    $serialized=file_get_contents($file);
    if (str_contains($serialized,'SICRAT_ID|i:2;') && preg_match('/SICRAT_AUTH_TOKEN\|s:64:"([a-f0-9]{64})"/',$serialized,$match)) $sessionToken=$match[1];
}
$foreignRole=(int) $connection->query('SELECT id FROM usuarios_procesos WHERE usuario=3 LIMIT 1')->fetchColumn();
$_SESSION=['SICRAT_ID'=>2,'SICRAT_AUTH_TOKEN'=>$sessionToken,'SICRAT_USER_ROLE'=>$foreignRole,'SICRAT_ORGANIZATION_ID'=>2,'SICRAT_GLOBAL_TYPE_CODE'=>'superadmin'];
verify(!(new App\Auth\SessionValidator($connection))->validate(),'Manipular organización, rol y privilegio en sesión no autoriza un acceso electoral ajeno.');
echo 'Fase 2: '.($checks-$phase1Checks)." comprobaciones adicionales correctas.\n";
