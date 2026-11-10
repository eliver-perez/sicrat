<?php
// Loaded only by integration.php --dashboard, in its disposable database.
$dashboardStart=$checks;
$dashboardTokens=['admin'=>login('admin','administrador',' nueva contraseña ')];
$adminToken=$dashboardTokens['admin'];
function dashboardApi(string $path,string $method='GET',?array $data=null,string $client='admin',int $status=200): array {
    global $dashboardTokens;
    $response=request($client,$method,$path,$data,$method==='GET' ? null : $dashboardTokens[$client]);
    verify($response['status']===$status || ($status===200 && $response['status']===201),"Dashboard $method $path ($client): ".$response['body']);
    return $response['json']['data'] ?? [];
}
$dashboardElection=dashboardApi('/api/organizations/'.$organizationIds[0].'/electoral-process','POST',['process'=>'Elección Dashboard','type'=>1,'character'=>1,'status'=>1])['id'];
dashboardApi('/api/auth/election','POST',['process'=>$dashboardElection]);
$dashboardProcess=(int)$connection->query("SELECT id FROM procesos_electorales WHERE proceso='Elección Dashboard'")->fetchColumn();
foreach(['estructura','tareas_metas','peticiones_incidencias','interacciones','jornada_electoral'] as $module) dashboardApi('/api/organizations/'.$organizationIds[0].'/modules/'.$module,'PUT',['authorized'=>1,'selected'=>1]);
$dashboardLevel=dashboardApi('/api/structure-levels','POST',['associated_role'=>8,'election'=>$dashboardElection,'name'=>'Equipo Dashboard','order'=>1,'can_recruit'=>1,'same_level'=>1])['id'];
dashboardApi('/api/structure-config','PUT',['election'=>$dashboardElection,'approval_required'=>0,'auto_promote'=>0]);
$dashboardStructure=dashboardApi('/api/structures','POST',['name'=>'Equipo Dashboard','elections'=>[$dashboardElection]])['id'];
$dashboardPeople=[];$dashboardUsers=[];$dashboardMembers=[];
foreach(['a','b','c'] as $suffix) {
    $person=dashboardApi('/api/persons','POST',['organization'=>$organizationIds[0],'name'=>'Persona Dashboard '.strtoupper($suffix)]);
    $dashboardPeople[$suffix]=$person['id'];
    $member=dashboardApi('/api/structures/'.$dashboardStructure.'/members','POST',['person'=>$person['id'],'responsible'=>$suffix==='b' ? $dashboardMembers['a'] : null,'levels'=>[$dashboardElection=>$dashboardLevel],'participations'=>[$dashboardElection]]);
    $dashboardMembers[$suffix]=$member['id'];
    $dashboardUsers[$suffix]=dashboardApi('/api/users','POST',['organization'=>$organizationIds[0],'username'=>'dashboard-'.$suffix,'password'=>'Dashboard segura 2026','type'=>8,'name'=>'Equipo '.strtoupper($suffix),'person'=>$person['id']])['id'];
    dashboardApi('/api/users/'.$dashboardUsers[$suffix].'/accesses','POST',['process'=>$dashboardElection,'type'=>8]);
}
// Restrict each fixture to a small explicit permission set; no reliance on legacy role defaults.
$permissions=$connection->query("SELECT id FROM permisos WHERE ambito IN ('electoral','ambos') AND id NOT IN ('superadmin','captura-personas')")->fetchAll(PDO::FETCH_COLUMN);
$insert=$connection->prepare('INSERT INTO permisos_usuarios (usuario,permiso,organizacion,proceso,valor,alcance,uuid,f_actualizacion) VALUES (?,?,1,?,0,\'propio\',UNHEX(REPLACE(UUID(),\'-\',\'\')),NOW())');
$dashboardIds=[];
foreach(['a','b','c'] as $suffix) {
    $id=(int)$connection->query("SELECT id FROM usuarios WHERE usuario='dashboard-$suffix'")->fetchColumn(); $dashboardIds[$suffix]=$id;
    foreach($permissions as $permission) $insert->execute([$id,$permission,$dashboardProcess]);
    foreach(['personas.ver','estructura.ver','tareas.ver','tareas.completar','incidencias.ver','incidencias.editar','peticiones.ver','peticiones.editar','interacciones.ver'] as $permission) {
        $stmt=$connection->prepare('UPDATE permisos_usuarios SET valor=1,alcance=? WHERE usuario=? AND permiso=? AND proceso=?');
        $stmt->execute([$suffix==='a' ? 'descendientes' : 'propio',$id,$permission,$dashboardProcess]);
    }
    $dashboardTokens[$suffix]=login($suffix,'dashboard-'.$suffix,'Dashboard segura 2026');
}
// A's descendants scope includes B, not the separate branch C. Include own scope explicitly as another grant.
$connection->prepare("INSERT INTO permisos_usuarios(usuario,permiso,organizacion,proceso,valor,alcance,uuid,f_actualizacion) SELECT usuario,permiso,organizacion,proceso,valor,'propio',UNHEX(REPLACE(UUID(),'-','')),NOW() FROM permisos_usuarios WHERE usuario=? AND proceso=? AND valor=1")->execute([$dashboardIds['a'],$dashboardProcess]);
$task=dashboardApi('/api/operations/tareas','POST',['title'=>'Preparar equipo Dashboard','assignees'=>[$dashboardPeople['a']],'start'=>date('Y-m-d H:i:s',time()-172800),'deadline'=>date('Y-m-d H:i:s',time()-86400)]);
$otherTask=dashboardApi('/api/operations/tareas','POST',['title'=>'SECRETO rama C','assignees'=>[$dashboardPeople['c']]]);
// A person-scoped incident and petition, with actual current module states.
$personA=(int)$connection->query("SELECT persona FROM usuarios WHERE usuario='dashboard-a'")->fetchColumn();
$personB=(int)$connection->query("SELECT persona FROM usuarios WHERE usuario='dashboard-b'")->fetchColumn();
$personC=(int)$connection->query("SELECT persona FROM usuarios WHERE usuario='dashboard-c'")->fetchColumn();
$connection->prepare("INSERT INTO incidencias(uuid,organizacion,proceso,persona,tipo,descripcion,prioridad,estatus,reportado_por,responsable,estado,f_reporte,folio) VALUES (?,1,?,?,1,'Atender incidencia Dashboard',4,1,?,?,'abierta',NOW(),'DB-INC')")->execute([random_bytes(16),$dashboardProcess,$personA,$dashboardIds['a'],$personA]);
$connection->prepare("INSERT INTO peticiones(uuid,organizacion,proceso,persona,capturado_por,categoria,descripcion,prioridad,estado,responsable,fecha,folio) VALUES (?,1,?,?,?,'Apoyo','Atender petición Dashboard',2,'abierta',?,NOW(),'DB-PET')")->execute([random_bytes(16),$dashboardProcess,$personA,$dashboardIds['a'],$personA]);
$taskId=(int)$connection->query("SELECT id FROM tareas WHERE titulo='Preparar equipo Dashboard'")->fetchColumn();
$audit=$connection->prepare("INSERT INTO auditoria(organizacion,proceso,usuario,accion,entidad,entidad_id,f_registro) VALUES (1,?,?,'editar','tareas',?,NOW())");
foreach(['a','b','c'] as $suffix) $audit->execute([$dashboardProcess,$dashboardIds[$suffix],$taskId]);
// A shared structure event needs its historical election snapshot, never just today's membership.
$memberB=$connection->prepare('SELECT id FROM estructura WHERE uuid=?');$memberB->execute([App\Services\Common\Values::binary($dashboardMembers['b'])]);$memberBId=(int)$memberB->fetchColumn();
$sharedAudit=$connection->prepare("INSERT INTO auditoria(organizacion,proceso,usuario,accion,entidad,entidad_id,informacion,f_registro) VALUES (1,NULL,?,'alta','estructura',?,?,NOW())");
$sharedAudit->execute([$dashboardIds['b'],$memberBId,json_encode(['elecciones'=>[$dashboardProcess]])]);
$sharedAudit->execute([$dashboardIds['b'],$memberBId,json_encode(['elecciones'=>[1]])]);
$connection->prepare("INSERT INTO auditoria(organizacion,proceso,usuario,accion,entidad,entidad_id,f_registro) VALUES (2,?,?, 'editar','tareas',?,NOW()),(1,1,?,'editar','tareas',?,NOW())")->execute([$dashboardProcess,$dashboardIds['b'],$taskId,$dashboardIds['b'],$taskId]);
$summary=dashboardApi('/api/dashboard',client:'a');
verify(count($summary['activity'])===3 && in_array('Estructura',array_column($summary['activity'],'module'),true),'Activity rejects foreign organization/election and ambiguous historical events.');
verify($summary['election']==='Elección Dashboard','Dashboard uses active election.');
$kpis=array_column($summary['kpis'],null,'key');
verify($kpis['personas']['value']===2 && $kpis['estructura']['value']===2 && $kpis['tareas']['value']===1,'Dashboard counts enforce own and descendant scopes: '.json_encode($kpis));
verify(!isset($kpis['jornada']),'No Jornada widget without a Jornada permission.');
verify(count($summary['charts'])>=2 && count($summary['charts'])<=3,'Dashboard selects at most three meaningful charts.');
verify(in_array('Tú',array_column($summary['activity'],'actor'),true) && in_array('Equipo B',array_column($summary['activity'],'actor'),true),'Own and authorized team activity are present.');
verify(!in_array('Equipo C',array_column($summary['activity'],'actor'),true) && !str_contains(json_encode($summary),'SECRETO'),'Other branch activity and records are absent, even on a readable task.');
verify(count($summary['pending'])===3 && $summary['pending'][0]['urgent'],'Mixed task/case pending and urgent priority.');
verify(count(array_filter($summary['pending'],fn($r)=>$r['deadline']===null))===2,'Cases do not invent deadlines.');
$connection->prepare("UPDATE permisos_usuarios SET valor=0 WHERE usuario=? AND permiso='incidencias.ver'")->execute([$dashboardIds['a']]);
$denied=dashboardApi('/api/dashboard',client:'a');
verify(!in_array('incidencias',array_column($denied['kpis'],'key'),true) && !in_array('Incidencias',array_column($denied['pending'],'module'),true),'Revoked source permission removes its entire component/data.');
$connection->prepare("UPDATE permisos_usuarios SET valor=1 WHERE usuario=? AND permiso='incidencias.ver'")->execute([$dashboardIds['a']]);
$note=dashboardApi('/api/dashboard/notes','POST',['texto'=>'PRIVADA A <img src=x onerror=alert(1)>','recordatorio'=>'2027-01-01T12:30:00Z','fijada'=>1],'a');
verify($note['recordatorio']==='2027-01-01T12:30:00Z' && $note['fijada'] && !$note['realizada'],'Private reminder and pin persist.');
foreach(['b','admin'] as $client) {
    dashboardApi('/api/dashboard/notes/'.$note['uuid'],client:$client,status:404);
    dashboardApi('/api/dashboard/notes/'.$note['uuid'],'PUT',['texto'=>'Acceso ajeno'],$client,404);
    dashboardApi('/api/dashboard/notes/'.$note['uuid'],'DELETE',null,$client,404);
    verify(!str_contains(json_encode(dashboardApi('/api/dashboard/notes',client:$client)),'PRIVADA A'),'Even administrators cannot list another owner notes.');
}
$changed=dashboardApi('/api/dashboard/notes/'.$note['uuid'],'PUT',['texto'=>'Nota completada','realizada'=>1,'fijada'=>0],'a');
verify($changed['realizada'] && !$changed['fijada'] && $changed['recordatorio']===null,'Edit, complete, unpin and clear reminder.');
dashboardApi('/api/dashboard/notes/'.$note['uuid'],'DELETE',null,'a');
dashboardApi('/api/dashboard/notes/'.$note['uuid'],client:'a',status:404);
verify(!(int)$connection->query("SELECT COUNT(*) FROM auditoria WHERE datos_nuevos LIKE '%PRIVADA A%'")->fetchColumn(),'Private note contents never enter general audit.');
dashboardApi('/api/dashboard/notes','POST',['texto'=>'Recordatorio personal Dashboard','recordatorio'=>'2027-01-01T12:30:00Z','fijada'=>1],'a');
// Same organization with a different election must not mix operations or notes ownership.
$otherElection=dashboardApi('/api/organizations/'.$organizationIds[0].'/electoral-process','POST',['process'=>'Dashboard vacío','type'=>1,'character'=>1,'status'=>1])['id'];
dashboardApi('/api/auth/election','POST',['process'=>$otherElection]);
$empty=dashboardApi('/api/dashboard');
verify(!$empty['activity'] && !$empty['charts'] && !$empty['pending'],'Empty election has no fabricated activity/charts/pending.');
// Test no-election contexts directly: normal request middleware may automatically choose a valid election.
$_SESSION=['SICRAT_ID'=>$dashboardIds['a']];
$none=(new App\Services\Dashboard\Summary(new App\Repositories\CommonRepository($connection)))->get();
verify(!$none['kpis'] && !$none['charts'] && !$none['pending'] && $none['message']!=='','Normal user with no election has no operational data.');
$_SESSION=['SICRAT_ID'=>1];
$global=(new App\Services\Dashboard\Summary(new App\Repositories\CommonRepository($connection)))->get();
verify(count($global['kpis'])===3 && !$global['activity'],'Superadmin without election gets administrative global counts.');
$_SESSION=[];
// Jornada data visibility and module availability.
$connection->prepare("INSERT INTO jornada_config(proceso,organizacion,zona,captura_desde,estado,editado_por,fecha) VALUES (?,1,'America/Mexico_City','2020-01-01','abierta',1,NOW())")->execute([$dashboardProcess]);
$connection->prepare("INSERT INTO casillas(uuid,seccion,tipo,casilla,registro,f_registro) VALUES (?,1,'B','Dashboard casilla',1,NOW())")->execute([random_bytes(16)]);
$booth=(int)$connection->lastInsertId();
$connection->prepare('INSERT INTO jornada_casillas(uuid,organizacion,proceso,casilla) VALUES (?,1,?,?)')->execute([random_bytes(16),$dashboardProcess,$booth]);
$journeyBooth=(int)$connection->lastInsertId();
$connection->prepare('INSERT INTO jornada_capturistas(uuid,organizacion,proceso,casilla,usuario,asignado_por,fecha) VALUES (?,1,?,?,?,1,NOW())')->execute([random_bytes(16),$dashboardProcess,$journeyBooth,$dashboardIds['a']]);
$connection->prepare("UPDATE permisos_usuarios SET valor=1 WHERE usuario=? AND permiso IN ('resultados.ver','resultados.capturar')")->execute([$dashboardIds['a']]);
$journey=dashboardApi('/api/dashboard',client:'a');
verify(in_array('jornada',array_column($journey['kpis'],'key'),true) && in_array('jornada',array_column($journey['charts'],'key'),true),'Authorized Jornada scope supplies KPI and chart.');
verify(in_array('Jornada',array_column($journey['pending'],'module'),true),'Open Jornada with an assigned uncaptured booth requires attention.');
$connection->prepare("UPDATE permisos_usuarios SET valor=0 WHERE usuario=? AND permiso IN ('resultados.ver','resultados.capturar')")->execute([$dashboardIds['a']]);
// Same query count as records grow: bounded queries, SQL aggregation, no per-row service calls.
class DashboardCountingPDO extends PDO {
    public int $queries=0;
    public function prepare(string $query,array $options=[]): PDOStatement|false { $this->queries++;return parent::prepare($query,$options); }
}
$counting=new DashboardCountingPDO('mysql:host='.env('DB_HOST').';port='.env('DB_PORT',3306).';dbname='.$databaseName.';charset=utf8mb4',env('DB_USERNAME'),env('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$_SESSION=['SICRAT_ID'=>$dashboardIds['a'],'SICRAT_ORGANIZATION_PROCESS_ID'=>$dashboardProcess];
(new App\Services\Dashboard\Summary(new App\Repositories\CommonRepository($counting)))->get();$queryCount=$counting->queries;
for($i=0;$i<30;$i++) $audit->execute([$dashboardProcess,$dashboardIds['b'],$taskId]);
$counting->queries=0;(new App\Services\Dashboard\Summary(new App\Repositories\CommonRepository($counting)))->get();
verify($queryCount===$counting->queries,'Increasing audit rows does not increase SQL queries (no N+1).');
$_SESSION=[];
file_put_contents($temporaryDirectory.'/dashboard.json',json_encode(['election'=>$dashboardElection,'database'=>$databaseName,'queries'=>$queryCount]));
echo 'Dashboard PHP: '.($checks-$dashboardStart).' comprobaciones; '.$queryCount." consultas fijas (incluye autorización de módulos).\n";
// Approval steps: readable source plus validation scope plus current assigned step.
dashboardApi('/api/auth/election','POST',['process'=>$dashboardElection]);
$validationTask=dashboardApi('/api/operations/tareas','POST',['title'=>'Validación Dashboard','assignees'=>[$dashboardPeople['b']]]);
$validationTaskId=(int)$connection->query("SELECT id FROM tareas WHERE titulo='Validación Dashboard'")->fetchColumn();
$validationItem=(int)$connection->query('SELECT id FROM tareas_asignaciones WHERE tarea='.$validationTaskId)->fetchColumn();
$otherItem=(int)$connection->query("SELECT a.id FROM tareas_asignaciones a JOIN tareas t ON t.id=a.tarea WHERE t.titulo='SECRETO rama C'")->fetchColumn();
$connection->prepare("UPDATE permisos_usuarios SET valor=1,alcance='descendientes' WHERE usuario=? AND permiso IN ('aprobaciones.ver','aprobaciones.resolver','tareas.validar')")->execute([$dashboardIds['a']]);
$approvalInsert=$connection->prepare("INSERT INTO aprobaciones(uuid,organizacion,proceso,entidad,entidad_id,solicitante,regla,fecha,vence_en) VALUES (?,1,?,'tareas_asignaciones',?,?, '{}',NOW(),?)");
foreach([[$validationItem,null,false],[$otherItem,null,false],[$validationItem,'2020-01-01',false],[$validationItem,null,true]] as [$item,$expires,$later]) {
    $approvalInsert->execute([random_bytes(16),$dashboardProcess,$item,$dashboardIds['b'],$expires]);$approvalId=(int)$connection->lastInsertId();
    if($later) $connection->prepare('INSERT INTO aprobaciones_pasos(aprobacion,orden,aprobador) VALUES (?,1,?)')->execute([$approvalId,$dashboardIds['b']]);
    $connection->prepare('INSERT INTO aprobaciones_pasos(aprobacion,orden,aprobador) VALUES (?,?,?)')->execute([$approvalId,$later ? 2 : 1,$dashboardIds['a']]);
}
$approvedView=dashboardApi('/api/dashboard',client:'a');
verify(count(array_filter($approvedView['pending'],fn($row)=>$row['module']==='Validaciones'))===1,'Only current, unexpired approval on a readable and validatable task item appears.');
$connection->prepare("UPDATE permisos_usuarios SET valor=0 WHERE usuario=? AND permiso='tareas.validar'")->execute([$dashboardIds['a']]);
verify(!in_array('Validaciones',array_column(dashboardApi('/api/dashboard',client:'a')['pending'],'module'),true),'Revoking task validation also removes approval pending.');
// Ignore arbitrary client-selected identities. Notes accept no ownership override.
$own=dashboardApi('/api/dashboard/notes?user='.$dashboardIds['b'],client:'a');
verify(str_contains(json_encode($own),'Recordatorio personal Dashboard'),'Client owner parameter cannot select another account.');
$connection->prepare("UPDATE permisos_usuarios SET valor=0 WHERE usuario=?")->execute([$dashboardIds['c']]);
$noRights=dashboardApi('/api/dashboard',client:'c');
verify(!$noRights['kpis'] && !$noRights['charts'] && !$noRights['activity_available'] && !$noRights['pending_available'],'No permissions means no operational components, not disabled placeholders.');
// Conditional responses are checked at origin, independently of the worker's 304 reconstruction.
function dashboardConditional(string $client,string $path,?string $etag=null): array {
    global $baseUrl,$temporaryDirectory;
    $headers=[];$curl=curl_init($baseUrl.$path);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$temporaryDirectory.'/'.$client.'.cookies',CURLOPT_HTTPHEADER=>['X-Sicrat-Offline: 1',...($etag ? ['If-None-Match: '.$etag] : [])],CURLOPT_HEADERFUNCTION=>static function($handle,$line)use(&$headers){if(str_contains($line,':')){[$key,$value]=explode(':',$line,2);$headers[strtolower(trim($key))]=trim($value);}return strlen($line);}]);
    $body=curl_exec($curl);return ['status'=>curl_getinfo($curl,CURLINFO_HTTP_CODE),'headers'=>$headers,'body'=>$body];
}
foreach(['/api/dashboard?days=7','/api/dashboard/notes?offset=0'] as $path) {
    $first=dashboardConditional('a',$path);verify($first['status']===200 && isset($first['headers']['etag']),'Dashboard and personal notes opt in to private ETag.');
    $same=dashboardConditional('a',$path,$first['headers']['etag']);verify($same['status']===304 && $same['body']==='','Unchanged Dashboard/notes returns 304.');
    $other=dashboardConditional('b',$path,$first['headers']['etag']);verify($other['status']===200 && !str_contains($other['body'],'Recordatorio personal Dashboard'),'Another account cannot reuse note ETag or data.');
}
echo 'Dashboard ampliado: '.($checks-$dashboardStart)." comprobaciones PHP.\n";
// Parent task may await another assignee's validation while mine is still actionable.
$connection->prepare("UPDATE tareas SET estado='validacion',prioridad=NULL WHERE id=?")->execute([$taskId]);
verify(in_array('Tareas',array_column(dashboardApi('/api/dashboard',client:'a')['pending'],'module'),true),'An open assignment remains actionable during another item validation; legacy null priority is supported.');
$connection->prepare("UPDATE tareas SET estado='pendiente',prioridad=2 WHERE id=?")->execute([$taskId]);
$connection->prepare("UPDATE incidencias SET responsable=NULL,asignado_a=? WHERE folio='DB-INC'")->execute([$dashboardIds['a']]);
verify(in_array('Incidencias',array_column(dashboardApi('/api/dashboard',client:'a')['pending'],'module'),true),'Legacy incident account assignment stays actionable.');
$connection->prepare("UPDATE incidencias SET responsable=?,asignado_a=NULL WHERE folio='DB-INC'")->execute([$personA]);
foreach([date('Y-m-d H:i:s'),date('Y-m-d H:i:s',time()-60*86400)] as $visitDate) dashboardApi('/api/operations/interacciones','POST',['person'=>$dashboardPeople['b'],'type'=>'VISITA','result'=>'Visita Dashboard','date'=>$visitDate]);
$visits=array_column(dashboardApi('/api/dashboard?days=7',client:'a')['kpis'],null,'key');
verify($visits['visitas']['value']===1,'Visit KPI respects the selected period, not all history.');
echo 'Dashboard final: '.($checks-$dashboardStart)." comprobaciones PHP.\n";
