<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/app/Support/helpers.php';
loadEnv(dirname(__DIR__).'/.env');
$name='sicrat_phase1_test_'.bin2hex(random_bytes(6));
$db=new PDO('mysql:host='.env('DB_HOST').';port='.env('DB_PORT',3306).';charset=utf8mb4',env('DB_USERNAME'),env('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$checks=0;
function migration5Check(bool $condition,string $message): void { global $checks; if(!$condition)throw new RuntimeException($message); $checks++; }
function migration5Reject(callable $work,string $message): void { try{$work();}catch(RuntimeException $error){migration5Check(str_contains($error->getMessage(),$message),'Conflicto reconocido antes del DDL: '.$message);return;}throw new RuntimeException('No rechazó el conflicto histórico.'); }
try {
	$db->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4');$db->exec('USE `'.$name.'`');
	foreach(explode(';',file_get_contents(dirname(__DIR__).'/database/database.sql')) as $sql)if(trim($sql)!=='')$db->exec($sql);
	$db->exec('CREATE TABLE esquema_migraciones (nombre VARCHAR(190) PRIMARY KEY,checksum CHAR(64),aplicada_en DATETIME)');$runner=new App\Core\Migrations($db);
	foreach(glob(dirname(__DIR__).'/database/migrations/*.php') as $file){if(basename($file)>='20261012_006_operaciones.php')continue;(require $file)($db,$runner);$db->prepare('INSERT INTO esquema_migraciones VALUES (?,?,NOW())')->execute([basename($file),hash_file('sha256',$file)]);}
	foreach(['Uno','Dos'] as $organization)$db->prepare('INSERT INTO organizaciones (uuid,organizacion,f_registro) VALUES (?,?,NOW())')->execute([random_bytes(16),$organization]);
	foreach([1,1,2] as $organization)$db->prepare('INSERT INTO procesos_electorales (uuid,organizacion,proceso,tipo,caracter,registro,f_registro) VALUES (?,?,?,1,1,1,NOW())')->execute([random_bytes(16),$organization,'Histórica '.$organization]);
	$db->prepare("INSERT INTO personas (uuid,codigo_persona,organizacion,nombre,registrado_por,f_registro) VALUES (?,'HISTORICO1',1,'Persona histórica',1,NOW())")->execute([random_bytes(16)]);
	$person=(int)$db->lastInsertId();$db->prepare("INSERT INTO usuarios (uuid,organizacion,usuario,nombre,password_hash,tipo_usuario,f_registro) VALUES (?,1,'historico','Capturista histórico','ficticio',8,NOW())")->execute([random_bytes(16)]);$user=(int)$db->lastInsertId();
	$db->prepare('INSERT INTO personas_procesos (proceso,persona,requiere_transporte,f_registro) VALUES (1,?,1,NOW()),(2,?,0,NOW())')->execute([$person,$person]);
	$file=dirname(__DIR__).'/database/migrations/20261012_006_operaciones.php';
	$db->exec('UPDATE personas_procesos SET proceso=3 WHERE id=2');migration5Reject(fn()=>(require $file)($db,$runner),'Participación de otra organización');migration5Check(!$runner->columnExists('interacciones','uuid'),'Conflicto de organización no toca esquema.');$db->exec('UPDATE personas_procesos SET proceso=2 WHERE id=2');
	// Se prepara primero el cruce original y se verifica que no haya DDL parcial.
	$db->exec('INSERT INTO encuestas (proceso,encuesta) VALUES (1,\'Encuesta histórica\')');
	$db->prepare('INSERT INTO encuestas_aplicaciones (encuesta,persona_proceso,aplicado_por,f_aplicacion) VALUES (1,2,?,NOW())')->execute([$user]);
	migration5Reject(fn()=>(require $file)($db,$runner),'Aplicaciones de encuesta');migration5Check(!$runner->columnExists('interacciones','uuid'),'Conflicto electoral no toca columnas.');$db->exec('UPDATE encuestas_aplicaciones SET persona_proceso=1');
	$db->exec("INSERT INTO encuestas_preguntas (encuesta,pregunta,tipo,obligatorio,orden) VALUES (1,'Texto antiguo',1,1,1)");$db->exec("INSERT INTO encuestas_respuestas (aplicacion,pregunta,respuesta) VALUES (1,1,'Respuesta anterior')");
	$db->prepare('INSERT INTO interacciones (persona_proceso,tipo,usuario,resultado,f_interaccion,f_registro) VALUES (1,1,?,\'Histórica\',NOW(),NOW())')->execute([$user]);
	$db->prepare("INSERT INTO tareas (proceso,persona_proceso,asignado_a,creado_por,titulo,estatus,f_registro,f_finalizacion) VALUES (1,1,?,1,'Tarea histórica',3,NOW(),NOW())")->execute([$user]);
	$db->prepare("INSERT INTO estructuras_organizacionales (uuid,organizacion,nombre,fecha) VALUES (?,1,'Estructura histórica',NOW())")->execute([random_bytes(16)]);$container=(int)$db->lastInsertId();$db->prepare('INSERT INTO estructura (uuid,contenedor,persona,nivel,f_asignacion) VALUES (?,?,?,1,NOW())')->execute([random_bytes(16),$container,$person]);$member=(int)$db->lastInsertId();
	$db->prepare('INSERT INTO metas (proceso,estructura,tipo,cantidad,fecha_inicio,fecha_fin,asignado_por) VALUES (1,?,1,10,CURRENT_DATE,CURRENT_DATE,1)')->execute([$member]);
	$db->prepare("INSERT INTO eventos (proceso,evento,fecha_inicio,creado_por) VALUES (1,'Evento histórico',NOW(),1)")->execute([]);$event=(int)$db->lastInsertId();$db->prepare('INSERT INTO eventos_asistentes (evento,persona_proceso,confirmado,asistio,registrado_por) VALUES (?,1,1,1,?)')->execute([$event,$user]);
	$db->prepare("INSERT INTO incidencias (proceso,tipo,descripcion,prioridad,estatus,reportado_por,f_reporte,f_resolucion) VALUES (1,1,'Incidencia histórica',1,4,?,NOW(),NOW())")->execute([$user]);
	$db->prepare("INSERT INTO conversaciones (uuid,proceso,tipo,titulo,creado_por,f_registro) VALUES (?,1,2,'Conversación histórica',1,NOW())")->execute([random_bytes(16)]);$conversation=(int)$db->lastInsertId();$db->prepare("INSERT INTO mensajes (conversacion,usuario,mensaje,f_envio) VALUES (?,?,'Mensaje histórico',NOW())")->execute([$conversation,$user]);$message=(int)$db->lastInsertId();
	$db->prepare('INSERT INTO conversaciones_usuarios (conversacion,usuario,ultimo_mensaje_leido) VALUES (?,?,?)')->execute([$conversation,$user,$message]);
	$db->exec('INSERT INTO conversaciones_usuarios (conversacion,usuario) VALUES (999,1)');migration5Reject(fn()=>(require $file)($db,$runner),'Membresía huérfana');migration5Check(!$runner->columnExists('interacciones','uuid'),'Membresía huérfana tampoco toca el esquema.');$db->exec('DELETE FROM conversaciones_usuarios WHERE conversacion=999');
	$tables=['personas','personas_procesos','usuarios','interacciones','encuestas','encuestas_preguntas','encuestas_opciones','encuestas_aplicaciones','encuestas_respuestas','tareas','metas','eventos','eventos_asistentes','incidencias','conversaciones','conversaciones_usuarios','mensajes'];$before=[];
	foreach($tables as $table)$before[$table]=$db->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
	migration5Check($runner->run()===['20261012_006_operaciones.php','20261012_007_finanzas_inventario.php','20261013_008_jornada_resultados.php','20261013_009_revision_post_fase7.php','20261014_010_padron_tipos_casilla.php'],'Migra desde base post-Fase 4 con datos históricos.');
	foreach($before as $table=>$rows){$now=$db->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);migration5Check(count($rows)===count($now),'Conserva cantidad original de '.$table);foreach($rows as $index=>$row)migration5Check(array_intersect_key($now[$index],$row)===$row,'Conserva todos los campos e IDs originales de '.$table);}
	migration5Check($runner->run()===[],'Ejecutor idempotente con checksums.');
	$counts=[];foreach($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table)$counts[$table]=(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
	(require $file)($db,$runner);
	foreach($counts as $table=>$quantity)migration5Check((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===$quantity,'Reanudación directa no duplica '.$table);
	migration5Check((int)$db->query('SELECT COUNT(*) FROM interacciones_procesos')->fetchColumn()===1,'Backfill de relación multielección preserva referencia.');
	migration5Check($db->query('SELECT estado FROM tareas_asignaciones')->fetchColumn()==='completada','Estado histórico de asignación conservado sin inventar persona de la cuenta.');
	migration5Check($db->query('SELECT persona FROM tareas_asignaciones')->fetchColumn()===null,'Cuenta histórica sin persona conserva vínculo histórico explícito.');
	migration5Check($db->query('SELECT modalidad FROM encuestas_aplicaciones')->fetchColumn()==='historica','No inventa autocaptura para respuestas históricas.');
	migration5Check($db->query('SELECT estado FROM eventos_participantes')->fetchColumn()==='asistio','Asistencia histórica se conserva.');
	migration5Check($db->query('SELECT estado FROM incidencias')->fetchColumn()==='cerrada','Resolución histórica sigue cerrada.');
	migration5Check($db->query('SELECT necesidad_transporte FROM personas_procesos WHERE id=1')->fetchColumn()==='requiere','Transporte histórico positivo conservado.');
	migration5Check($db->query('SELECT necesidad_transporte FROM personas_procesos WHERE id=2')->fetchColumn()==='no_definido','Cero histórico no inventa transporte propio.');
	migration5Check((int)$db->query('SELECT COUNT(*) FROM mensajes_lecturas')->fetchColumn()===0,'No inventa fechas de lectura a partir del cursor histórico.');
	migration5Check((int)$db->query("SELECT COUNT(*) FROM modulos_organizaciones WHERE modulo<>'estructura' AND seleccionado=1")->fetchColumn()===0,'Migrar no activa módulos en clientes.');
	echo "OK: $checks comprobaciones de migraciones, historia, conflictos e idempotencia de Fase 5.\n";
}finally{if(preg_match('/^sicrat_phase1_test_[a-f0-9]{12}$/D',$name))$db->exec('DROP DATABASE IF EXISTS `'.$name.'`');}
