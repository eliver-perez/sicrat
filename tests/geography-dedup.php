<?php
// Prueba aislada: reproduce dos cargas del SQL original y las referencias a duplicados.
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';require dirname(__DIR__).'/app/Support/helpers.php';loadEnv(dirname(__DIR__).'/.env');
$db=new PDO('mysql:host='.env('DB_HOST').';port='.env('DB_PORT',3306).';charset=utf8mb4',env('DB_USERNAME'),env('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='sicrat_geo_test_'.bin2hex(random_bytes(6));$checks=0;
$check=static function(bool $condition,string $message)use(&$checks){if(!$condition)throw new RuntimeException($message);$checks++;};
try {
    $db->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$db->exec("USE `$name`");
    foreach(explode(';',file_get_contents(dirname(__DIR__).'/database/database.sql')) as $sql)if(trim($sql)!=='')$db->exec($sql);
    $db->exec('SET @localidad_miguel_aleman=1');
    $seed=file_get_contents(dirname(__DIR__).'/database/colonias mysql.sql');
    for($i=0;$i<2;$i++)foreach(explode(';',$seed) as $sql)if(preg_match('/INSERT INTO (estados|municipios|localidades|colonias)\s*\(/i',$sql,$match,PREG_OFFSET_CAPTURE))$db->exec(substr($sql,$match[0][1]));
    $db->exec("INSERT INTO codigos_postales(codigo_postal,localidad,colonia) VALUES('88303',32,57)");
    $db->exec("INSERT INTO secciones_electorales(uuid,estado,municipio,seccion,registro,f_registro) VALUES(UNHEX('12345678901234567890123456789012'),60,4364,730,1,NOW())");
    // FK de una tabla adicional: la migración debe descubrirla, no ignorarla.
    $db->exec('CREATE TABLE geo_referencia(id INT PRIMARY KEY,colonia INT,FOREIGN KEY(colonia) REFERENCES colonias(id)) ENGINE=InnoDB');$db->exec('INSERT INTO geo_referencia VALUES(1,57)');
    $migration=require dirname(__DIR__).'/database/migrations/20261017_011_catalogos_geograficos.php';$schema=new App\Core\Migrations($db);$migration($db,$schema);
    foreach(['estados'=>32,'municipios'=>2400,'localidades'=>31,'colonias'=>45] as $table=>$expected)$check((int)$db->query("SELECT COUNT(*) FROM $table")->fetchColumn()===$expected,"Conteo $table: ".$db->query("SELECT COUNT(*) FROM $table")->fetchColumn());
    $check($db->query('SELECT localidad,colonia FROM codigos_postales')->fetch(PDO::FETCH_ASSOC)==['localidad'=>1,'colonia'=>12],'Referencias postales reasignadas');
    $check((int)$db->query('SELECT colonia FROM geo_referencia')->fetchColumn()===12,'Referencia adicional conservada');
    $check($db->query('SELECT estado,municipio FROM secciones_electorales')->fetch(PDO::FETCH_ASSOC)==['estado'=>28,'municipio'=>1960],'Sección conserva ubicación equivalente');
    $check((int)$db->query('SELECT COUNT(*) FROM geografia_fusiones')->fetchColumn()===2516,'Archivo de todos los duplicados');
    $migration($db,$schema);$check((int)$db->query('SELECT COUNT(*) FROM geografia_fusiones')->fetchColumn()===2516,'Reanudación no duplica archivo');
    foreach(["INSERT INTO estados(estado) VALUES('Aguascalientes')","INSERT INTO municipios(estado,municipio) VALUES(28,'Abasolo')","INSERT INTO localidades(municipio,localidad) VALUES(1960,'Charco Cercado')","INSERT INTO colonias(localidad,colonia,codigo_postal) VALUES(1,'Barrera','88303')"] as $sql){try{$db->exec($sql);throw new RuntimeException('Aceptó repetición');}catch(PDOException $e){$check(($e->errorInfo[1]??0)===1062,'Unicidad evita repetición');}}
    $db->exec("INSERT INTO localidades(municipio,localidad,codigo_postal) VALUES(1960,'Arcabuz','99999')");$check((int)$db->query("SELECT COUNT(*) FROM localidades WHERE localidad='Arcabuz'")->fetchColumn()===2,'Homónimo con CP diferente se conserva');
    $db->exec('ALTER TABLE estados DROP INDEX uq_geografia_identidad');$db->exec('ALTER TABLE municipios ADD INDEX ix_estado_prueba(estado), DROP INDEX uq_geografia_identidad');
    $db->exec("INSERT INTO estados(estado) VALUES('Aguascalientes');INSERT INTO municipios(estado,municipio,clave) VALUES(1,'Homónimo ambiguo','001'),(1,'Homónimo ambiguo','002')");
    try {$migration($db,$schema);throw new RuntimeException('No detectó claves distintas');}
    catch(RuntimeException $error){$check(str_contains($error->getMessage(),'claves distintas'),'Conflicto exige revisión explícita');}
    $check((int)$db->query("SELECT COUNT(*) FROM estados WHERE estado='Aguascalientes'")->fetchColumn()===2,'Un conflicto posterior revierte toda la limpieza');
    $check((int)$db->query('SELECT COUNT(*) FROM geografia_fusiones')->fetchColumn()===2516,'Rollback no deja archivo parcial');
    echo "OK: $checks comprobaciones de limpieza geográfica y referencias.\n";
} finally {$db->exec("DROP DATABASE IF EXISTS `$name`");}
