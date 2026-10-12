<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/app/Support/helpers.php';
loadEnv(dirname(__DIR__).'/.env');
$name='sicrat_phase1_test_'.bin2hex(random_bytes(6));
$db=new PDO('mysql:host='.env('DB_HOST').';port='.env('DB_PORT',3306).';charset=utf8mb4',env('DB_USERNAME'),env('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$checks=0;
function migration4Check(bool $condition,string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
function migration4Reject(callable $work,string $message): void { try { $work(); } catch (RuntimeException $error) { migration4Check(str_contains($error->getMessage(),$message),'Conflicto informado antes del DDL.'); return; } throw new RuntimeException('No rechazó conflicto histórico.'); }
try {
	$db->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4'); $db->exec('USE `'.$name.'`');
	foreach (explode(';',file_get_contents(dirname(__DIR__).'/database/database.sql')) as $sql) if (trim($sql)!=='') $db->exec($sql);
	$db->exec('CREATE TABLE esquema_migraciones (nombre VARCHAR(190) PRIMARY KEY,checksum CHAR(64),aplicada_en DATETIME)');
	$runner=new App\Core\Migrations($db);
	foreach (glob(dirname(__DIR__).'/database/migrations/*.php') as $file) {
		if (basename($file)==='20261012_005_servicios_comunes.php') continue;
		(require $file)($db,$runner); $db->prepare('INSERT INTO esquema_migraciones VALUES (?,?,NOW())')->execute([basename($file),hash_file('sha256',$file)]);
	}
	foreach ([1,2] as $organization) $db->prepare('INSERT INTO organizaciones (uuid,organizacion,f_registro) VALUES (?,?,NOW())')->execute([random_bytes(16),'Org '.$organization]);
	$db->prepare("INSERT INTO procesos_electorales (uuid,organizacion,proceso,tipo,caracter,registro,f_registro) VALUES (?,2,'Elección',1,1,1,NOW())")->execute([random_bytes(16)]);
	$db->exec("INSERT INTO auditoria (organizacion,proceso,usuario,accion,entidad,entidad_id,datos_anteriores,datos_nuevos,f_registro) VALUES (1,1,1,'editar','historico',8,'{\"valor\":1}','{\"valor\":2}',NOW())");
	$file=dirname(__DIR__).'/database/migrations/20261012_005_servicios_comunes.php';
	migration4Reject(fn ()=>(require $file)($db,$runner),'Auditoría histórica');
	migration4Check(!$runner->columnExists('auditoria','referencia'),'Preflight no cambia el esquema ante conflicto de auditoría.');
	$db->exec('UPDATE auditoria SET organizacion=2');
	$db->prepare("INSERT INTO usuarios (uuid,organizacion,usuario,nombre,password_hash,tipo_usuario,f_registro) VALUES (?,1,'archivo','Usuario','ficticio',8,NOW())")->execute([random_bytes(16)]);
	$db->prepare("INSERT INTO archivos (uuid,organizacion,entidad,entidad_id,tipo,nombre_original,ruta,mime_type,tamanio,subido_por,f_registro) VALUES (?,2,'historico',8,1,'antiguo.jpg','/ruta/antigua.jpg','image/jpeg',5,2,NOW())")->execute([random_bytes(16)]);
	migration4Reject(fn ()=>(require $file)($db,$runner),'Archivos históricos');
	migration4Check(!$runner->columnExists('archivos','estado'),'Conflicto de archivos tampoco modifica el esquema.');
	$db->exec('UPDATE archivos SET organizacion=1');
	$previous=[]; foreach (['auditoria','archivos','usuarios','organizaciones','procesos_electorales','estructura_transferencias'] as $table) $previous[$table]=$db->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
	migration4Check($runner->run()===['20261012_005_servicios_comunes.php'],'Migra exclusivamente Fase 4 desde Fase 3.');
	foreach ($previous as $table=>$rows) {
		$now=$db->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
		foreach ($rows as $index=>$row) migration4Check(array_intersect_key($now[$index],$row)===$row,'Conserva ID y campos originales de '.$table);
	}
	migration4Check($db->query('SELECT estado FROM archivos')->fetchColumn()==='historico','Archivo histórico se conserva sin publicar ni asumir su ruta válida.');
	migration4Check($runner->run()===[],'Reejecución no reaplica DDL ni catálogos.');
	migration4Check((int) $db->query("SELECT COUNT(*) FROM permisos WHERE id='exportaciones.generar'")->fetchColumn()===1,'Catálogo granular sin duplicados.');
	migration4Check((int) $db->query('SELECT retencion_dias FROM respaldos_politica')->fetchColumn()===14,'Política inicial persistida.');
	echo "OK: $checks comprobaciones de migraciones Fase 4.\n";
} finally { if (preg_match('/^sicrat_phase1_test_[a-f0-9]{12}$/D',$name)) $db->exec('DROP DATABASE IF EXISTS `'.$name.'`'); }
