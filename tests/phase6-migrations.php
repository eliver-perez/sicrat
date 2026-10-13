<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php'; require dirname(__DIR__).'/app/Support/helpers.php'; loadEnv(dirname(__DIR__).'/.env');
$db=new PDO('mysql:host='.env('DB_HOST').';port='.env('DB_PORT',3306).';charset=utf8mb4',env('DB_USERNAME'),env('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='sicrat_phase6_migrations_'.bin2hex(random_bytes(6));$checks=0;
function migration6Check(bool $condition,string $description): void {global $checks;if(!$condition)throw new RuntimeException($description);$checks++;}
function migration6Reject(callable $work,string $description): void {try{$work();migration6Check(false,$description);}catch(RuntimeException $error){migration6Check(str_contains($error->getMessage(),'incompatible'),$description);}}
try {
	$db->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $db->exec('USE `'.$name.'`');
	foreach(explode(';',file_get_contents(dirname(__DIR__).'/database/database.sql')) as $sql)if(trim($sql)!=='')$db->exec($sql);
	$db->exec('CREATE TABLE esquema_migraciones (nombre VARCHAR(190) PRIMARY KEY,checksum CHAR(64),aplicada_en DATETIME)');$runner=new App\Core\Migrations($db);$file=dirname(__DIR__).'/database/migrations/20261012_007_finanzas_inventario.php';
	foreach(glob(dirname(__DIR__).'/database/migrations/*.php') as $migration){if($migration===$file)continue;(require $migration)($db,$runner);$db->prepare('INSERT INTO esquema_migraciones VALUES (?,?,NOW())')->execute([basename($migration),hash_file('sha256',$migration)]);}
	foreach(['Histórica A','Histórica B'] as $organization)$db->prepare('INSERT INTO organizaciones (uuid,organizacion,f_registro) VALUES (?,?,NOW())')->execute([random_bytes(16),$organization]);
	foreach([1,2] as $organization)$db->prepare('INSERT INTO procesos_electorales (uuid,organizacion,proceso,tipo,caracter,registro,f_registro) VALUES (?,?,?,1,1,1,NOW())')->execute([random_bytes(16),$organization,'Elección histórica '.$organization]);
	$db->prepare("INSERT INTO personas (uuid,codigo_persona,organizacion,nombre,registrado_por,f_registro) VALUES (?,'F6HIST001',1,'Persona histórica preservada',1,NOW())")->execute([random_bytes(16)]);$db->exec("INSERT INTO personas_procesos (persona,proceso,f_registro) VALUES (1,1,NOW())");
	$db->prepare("INSERT INTO eventos (uuid,organizacion,proceso,evento,creado_por,estado,fecha_inicio) VALUES (?,1,1,'Evento preservado',1,'finalizado',NOW())")->execute([random_bytes(16)]);
	$before=[];foreach($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table)$before[$table]=$db->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
	$db->exec('CREATE TABLE finanzas_categorias (id INT PRIMARY KEY,uuid BINARY(16))');migration6Reject(fn()=>(require $file)($db,$runner),'Tabla financiera incompleta se rechaza antes del DDL');migration6Check(!$runner->columnExists('inventario_articulos','uuid'),'Preflight conflictivo no crea inventario');$db->exec('DROP TABLE finanzas_categorias');
	migration6Check($runner->run()===[basename($file)],'Estado posterior a Fase 5 aplica sólo migración 007');
	foreach($before as $table=>$rows){$now=$db->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);if(in_array($table,['permisos','permisos_usuarios_tipo','esquema_migraciones'],true)){migration6Check(count($now)>=count($rows),'Sólo añade catálogo permitido: '.$table);continue;}if($table==='modulos'){foreach($rows as $row){$found=array_values(array_filter($now,fn($new)=>$new['codigo']===$row['codigo']))[0];if(in_array($row['codigo'],['finanzas','inventario'],true))$row['implementado']=1;migration6Check($row===$found,'Módulo conserva autorización y sólo cambia disponibilidad: '.$row['codigo']);}continue;}migration6Check($now===$rows,'Conserva todos los datos históricos de '.$table);}
	foreach(['finanzas_gastos','finanzas_pagos','finanzas_anticipos','finanzas_ingresos','inventario_movimientos','finanzas_caja'] as $table)migration6Check((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'No inventa movimientos en '.$table);
	migration6Check($runner->run()===[],'Ejecutor reejecutado mantiene checksums e idempotencia');
	$counts=[];foreach($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table)$counts[$table]=(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();(require $file)($db,$runner);foreach($counts as $table=>$count)migration6Check((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===$count,'Reanudación directa no duplica '.$table);
	$db->exec('ALTER TABLE recursos_folios DROP FOREIGN KEY recursos_folios_ibfk_1');migration6Reject(fn()=>(require $file)($db,$runner),'Tabla coincidente sin integridad referencial se rechaza');$db->exec('ALTER TABLE recursos_folios ADD FOREIGN KEY(organizacion) REFERENCES organizaciones(id)');
	$db->exec('ALTER TABLE finanzas_metodos DROP INDEX uuid');migration6Reject(fn()=>(require $file)($db,$runner),'Tabla coincidente sin identificador único se rechaza');$db->exec('ALTER TABLE finanzas_metodos ADD UNIQUE(uuid)');
	// Una recepción histórica preparada de otra organización no se corrige ni se borra automáticamente.
	$db->prepare("INSERT INTO inventario_categorias (uuid,organizacion,creado_por,fecha,nombre) VALUES (?,1,1,NOW(),'Categoría histórica')")->execute([random_bytes(16)]);
	$db->prepare("INSERT INTO inventario_articulos (uuid,organizacion,creado_por,fecha,nombre,codigo,categoria,unidad) VALUES (?,1,1,NOW(),'Artículo histórico','HIST',1,'Pieza')")->execute([random_bytes(16)]);
	$db->prepare("INSERT INTO inventario_almacenes (uuid,organizacion,creado_por,fecha,nombre) VALUES (?,2,1,NOW(),'Almacén ajeno')")->execute([random_bytes(16)]);
	$db->prepare("INSERT INTO inventario_movimientos (uuid,organizacion,proceso,creado_por,fecha,folio,estado,articulo,almacen,tipo,cantidad,valor,operacion,idempotencia,motivo,fecha_operacion) VALUES (?,1,1,1,NOW(),'MOV-HIST','aplicado',1,1,'entrada',1,0,?,'historica','Recepción histórica',CURRENT_DATE())")->execute([random_bytes(16),random_bytes(16)]);
	migration6Reject(fn()=>(require $file)($db,$runner),'Recepción histórica de almacén ajeno rechazada');migration6Check((int)$db->query('SELECT COUNT(*) FROM inventario_movimientos')->fetchColumn()===1,'El conflicto conserva la recepción preparada intacta');
	$db->exec('UPDATE inventario_almacenes SET organizacion=1');(require $file)($db,$runner);migration6Check((int)$db->query('SELECT COUNT(*) FROM inventario_movimientos')->fetchColumn()===1,'Reanudación conserva movimiento histórico compatible');
	echo 'OK: '.$checks." comprobaciones de migraciones de Fase 6, datos históricos, conflictos e idempotencia.\n";
} finally { $db->exec('DROP DATABASE IF EXISTS `'.$name.'`'); }
