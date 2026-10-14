<?php

declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';require dirname(__DIR__).'/app/Support/helpers.php';loadEnv(dirname(__DIR__).'/.env');
$db=new PDO('mysql:host='.env('DB_HOST').';port='.env('DB_PORT',3306).';charset=utf8mb4',env('DB_USERNAME'),env('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$name='sicrat_post7_migrations_'.bin2hex(random_bytes(6));$checks=0;
function revisionMigrationCheck(bool $condition,string $message): void {global $checks;if(!$condition)throw new RuntimeException($message);$checks++;}
function revisionMigrationReject(callable $work): void {try{$work();}catch(RuntimeException $error){revisionMigrationCheck(str_contains($error->getMessage(),'incompatible'),'Conflicto diagnosticado antes de DDL.');return;}throw new RuntimeException('Se aceptó un conflicto.');}
try {
 $db->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$db->exec('USE `'.$name.'`');foreach(explode(';',file_get_contents(dirname(__DIR__).'/database/database.sql')) as $sql)if(trim($sql)!=='')$db->exec($sql);
 $db->exec('CREATE TABLE esquema_migraciones(nombre VARCHAR(190) PRIMARY KEY,checksum CHAR(64),aplicada_en DATETIME)');$runner=new App\Core\Migrations($db);$file=dirname(__DIR__).'/database/migrations/20261013_009_revision_post_fase7.php';
 foreach(glob(dirname(__DIR__).'/database/migrations/*.php') as $old)if($old<$file){(require $old)($db,$runner);$db->prepare('INSERT INTO esquema_migraciones VALUES(?,?,NOW())')->execute([basename($old),hash_file('sha256',$old)]);}
 $db->prepare("INSERT INTO organizaciones(uuid,organizacion,f_registro) VALUES(?,'Histórica',NOW())")->execute([random_bytes(16)]);foreach([1,2] as $n)$db->prepare("INSERT INTO procesos_electorales(uuid,organizacion,proceso,tipo,caracter,registro,f_registro) VALUES(?,1,?,1,1,1,NOW())")->execute([random_bytes(16),'Histórica '.$n]);
 $db->exec("INSERT INTO estados(estado) VALUES('Histórico');INSERT INTO municipios(estado,municipio) VALUES(1,'Histórico');INSERT INTO secciones_electorales(estado,municipio,seccion,registro,f_registro) VALUES(1,1,7500,1,NOW());INSERT INTO casillas(seccion,casilla,tipo,registro,f_registro) VALUES(1,'Histórica','BASICA',1,NOW())");
 foreach(['Limpieza','limpieza','LIMPIEZA'] as $index=>$category)$db->prepare("INSERT INTO peticiones(uuid,organizacion,proceso,capturado_por,categoria,descripcion,prioridad,fecha,folio) VALUES(?,1,1,1,?,'Datos históricos',1,NOW(),?)")->execute([random_bytes(16),$category,'HIST-'.$index]);
 $before=[];foreach($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table)$before[$table]=$db->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
 $db->exec('CREATE TABLE casos_categorias(id INT PRIMARY KEY)');revisionMigrationReject(fn()=>(require $file)($db,$runner));revisionMigrationCheck(!$runner->columnExists('peticiones','categoria_id'),'Rechazo sin agregar columnas.');revisionMigrationCheck(!(int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='secciones_procesos'")->fetchColumn(),'Rechazo sin crear asociaciones.');$db->exec('DROP TABLE casos_categorias');
 revisionMigrationCheck($runner->run()===[basename($file),'20261014_010_padron_tipos_casilla.php'],'Desde 008 ejecuta 009 y 010.');
 foreach($before as $table=>$rows){
  $columns=array_keys($rows[0] ?? []);if(!$columns){revisionMigrationCheck((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0 || in_array($table,['permisos','permisos_usuarios_tipo'],true),'No inventa datos en '.$table);continue;}
  if(in_array($table,['permisos','permisos_usuarios_tipo','esquema_migraciones'],true)){foreach($rows as $old)revisionMigrationCheck(in_array($old,$db->query('SELECT '.implode(',',$columns).' FROM '.$table)->fetchAll(PDO::FETCH_ASSOC),true),'Conserva fila anterior de '.$table);continue;}
  $now=$db->query('SELECT '.implode(',',$columns).' FROM '.$table.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);if(in_array($table,['secciones_electorales','casillas'],true))foreach($rows as $i=>&$row){revisionMigrationCheck($row['uuid']===null && strlen($now[$i]['uuid'])===16,'Completa solamente UUID nulos.');$row['uuid']=$now[$i]['uuid'];}unset($row);
  revisionMigrationCheck($now===$rows,'Conserva todos los campos originales de '.$table);
 }
 revisionMigrationCheck((int)$db->query("SELECT COUNT(*) FROM casos_categorias WHERE proceso=1 AND tipo='peticiones' AND nombre='Limpieza'")->fetchColumn()===1,'Variantes de mayúsculas comparten categoría.');revisionMigrationCheck((int)$db->query('SELECT COUNT(DISTINCT categoria_id) FROM peticiones')->fetchColumn()===1,'Relaciona las tres peticiones sin cambiar texto.');
 revisionMigrationCheck((int)$db->query('SELECT COUNT(*) FROM secciones_procesos')->fetchColumn()===0,'No asigna catálogo a elecciones sin referencias.');
 $snapshot=$db->query('SELECT * FROM casos_categorias ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);revisionMigrationCheck($runner->run()===[],'Reentrada mediante checksum.');(require $file)($db,$runner);revisionMigrationCheck($snapshot===$db->query('SELECT * FROM casos_categorias ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'Reentrada directa conserva catálogos/UUID.');
 $db->exec('ALTER TABLE secciones_procesos MODIFY activo TINYINT NULL');revisionMigrationReject(fn()=>(require $file)($db,$runner));$db->exec('ALTER TABLE secciones_procesos MODIFY activo TINYINT NOT NULL DEFAULT 1');
 $db->exec('ALTER TABLE casos_categorias DROP INDEX uuid');revisionMigrationReject(fn()=>(require $file)($db,$runner));$db->exec('ALTER TABLE casos_categorias ADD UNIQUE(uuid)');
 $fk=$db->query("SELECT constraint_name FROM information_schema.key_column_usage WHERE table_schema=DATABASE() AND table_name='casillas_procesos' AND referenced_table_name='casillas'")->fetchColumn();$db->exec('ALTER TABLE casillas_procesos DROP FOREIGN KEY `'.$fk.'`');revisionMigrationReject(fn()=>(require $file)($db,$runner));
 echo 'OK: '.$checks." comprobaciones de migración 009, conservación, conflictos e idempotencia.\n";
}finally{$db->exec('DROP DATABASE IF EXISTS `'.$name.'`');}
