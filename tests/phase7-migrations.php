<?php

declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';require dirname(__DIR__).'/app/Support/helpers.php';loadEnv(dirname(__DIR__).'/.env');
$db=new PDO('mysql:host='.env('DB_HOST').';port='.env('DB_PORT',3306).';charset=utf8mb4',env('DB_USERNAME'),env('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='sicrat_phase7_migrations_'.bin2hex(random_bytes(6));$checks=0;
function migration7Check(bool $condition,string $description): void {global $checks;if(!$condition)throw new RuntimeException($description);$checks++;}
function migration7Reject(callable $work): void {try{$work();throw new LogicException('El conflicto no fue rechazado.');}catch(RuntimeException $error){migration7Check(str_contains($error->getMessage(),'incompatible'),'Preflight informa incompatibilidad concreta.');}}
try {
    $db->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$db->exec('USE `'.$name.'`');
    foreach(explode(';',file_get_contents(dirname(__DIR__).'/database/database.sql')) as $sql)if(trim($sql)!=='')$db->exec($sql);
    $runner=new App\Core\Migrations($db);$file=dirname(__DIR__).'/database/migrations/20261013_008_jornada_resultados.php';
    $db->exec('CREATE TABLE esquema_migraciones(nombre VARCHAR(190) PRIMARY KEY,checksum CHAR(64),aplicada_en DATETIME)');
    foreach(glob(dirname(__DIR__).'/database/migrations/*.php') as $migration)if($migration<$file){(require $migration)($db,$runner);$db->prepare('INSERT INTO esquema_migraciones VALUES(?,?,NOW())')->execute([basename($migration),hash_file('sha256',$migration)]);}
    $db->prepare("INSERT INTO organizaciones(uuid,organizacion,f_registro) VALUES(?,'Organización histórica',NOW())")->execute([random_bytes(16)]);
    $db->prepare("INSERT INTO procesos_electorales(uuid,organizacion,proceso,tipo,caracter,registro,f_registro) VALUES(?,1,'Elección histórica',1,1,1,NOW())")->execute([random_bytes(16)]);
    $before=[];foreach($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table)$before[$table]=$db->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
    $db->exec('CREATE TABLE jornada_versiones(id INT PRIMARY KEY,uuid BINARY(16))');migration7Reject(fn()=>(require $file)($db,$runner));migration7Check(!$runner->columnExists('jornada_config','proceso'),'El conflicto no crea tablas previas al DDL.');$db->exec('DROP TABLE jornada_versiones');
    migration7Check($runner->run()===[basename($file),'20261013_009_revision_post_fase7.php','20261014_010_padron_tipos_casilla.php'],'Desde Fase 6 aplica 008 y la revisión incremental 009.');
    foreach($before as $table=>$rows) {
        $now=$db->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
        if(in_array($table,['permisos','permisos_usuarios_tipo','esquema_migraciones'],true)) {migration7Check(count($now)>=count($rows),'Sólo añade catálogo en '.$table);continue;}
        if($table==='modulos'){foreach($rows as $row){$found=array_values(array_filter($now,static fn($new)=>$new['codigo']===$row['codigo']))[0];if($row['codigo']==='jornada_electoral')$row['implementado']=1;migration7Check($row===$found,'Autorizaciones conservadas: '.$row['codigo']);}continue;}
        migration7Check($now===$rows,'Datos históricos intactos: '.$table);
    }
    foreach($db->query("SHOW TABLES LIKE 'jornada_%'")->fetchAll(PDO::FETCH_COLUMN) as $table)migration7Check((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'No inventa datos en '.$table);
    migration7Check($runner->run()===[],'Idempotencia con checksums.');
    (require $file)($db,$runner);migration7Check($runner->run()===[],'Reanudación directa no cambia registro.');
    $db->exec('ALTER TABLE jornada_casillas DROP INDEX uuid');migration7Reject(fn()=>(require $file)($db,$runner));$db->exec('ALTER TABLE jornada_casillas ADD UNIQUE(uuid)');
    $key=$db->query("SELECT constraint_name FROM information_schema.key_column_usage WHERE table_schema=DATABASE() AND table_name='jornada_casillas' AND referenced_table_name='casillas'")->fetchColumn();
    $db->exec('ALTER TABLE jornada_casillas DROP FOREIGN KEY `'.$key.'`');migration7Reject(fn()=>(require $file)($db,$runner));$db->exec('ALTER TABLE jornada_casillas ADD FOREIGN KEY(casilla) REFERENCES casillas(id)');
    (require $file)($db,$runner);
    echo 'OK: '.$checks." comprobaciones Fase 7 de migración incremental, historia, conflictos y reentrada.\n";
} finally {$db->exec('DROP DATABASE IF EXISTS `'.$name.'`');}
