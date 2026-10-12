<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/app/Support/helpers.php';
loadEnv(dirname(__DIR__) . '/.env');
$name = 'sicrat_phase1_test_' . bin2hex(random_bytes(6));
$db = new PDO('mysql:host=' . env('DB_HOST') . ';port=' . env('DB_PORT',3306) . ';charset=utf8mb4',env('DB_USERNAME'),env('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$checks = 0;
function migrationCheck(bool $condition, string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
function migrationReject(callable $work, string $message): void { try { $work(); } catch (RuntimeException $error) { migrationCheck(str_contains($error->getMessage(),$message),'Conflicto identificado sin corrección automática.'); return; } throw new RuntimeException('No se rechazó el conflicto esperado.'); }
try {
    $db->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $db->exec('USE `' . $name . '`');
    foreach (explode(';',file_get_contents(dirname(__DIR__) . '/database/database.sql')) as $sql) if (trim($sql)!=='') $db->exec($sql);
    $runner = new App\Core\Migrations($db);
    $db->exec('CREATE TABLE esquema_migraciones (nombre VARCHAR(190) PRIMARY KEY,checksum CHAR(64) NOT NULL,aplicada_en DATETIME NOT NULL) ENGINE=InnoDB');
    foreach (glob(dirname(__DIR__) . '/database/migrations/20261011_*.php') as $file) {
        (require $file)($db,$runner); $db->prepare('INSERT INTO esquema_migraciones VALUES (?,?,NOW())')->execute([basename($file),hash_file('sha256',$file)]);
    }
    $db->prepare("INSERT INTO organizaciones (uuid,organizacion,contacto,registro,f_registro) VALUES (?,'Histórica','Contacto',1,NOW())")->execute([random_bytes(16)]);
    $db->prepare("INSERT INTO procesos_electorales (uuid,organizacion,proceso,tipo,caracter,registro,f_registro) VALUES (?,1,'Histórico',1,1,1,NOW())")->execute([random_bytes(16)]);
    foreach ([1,2] as $index) {
        $db->prepare('INSERT INTO personas (uuid,codigo_persona,organizacion,nombre,registrado_por,f_registro) VALUES (?,?,1,?,1,NOW())')->execute([random_bytes(16),'HISTORICO' . $index,'Histórica ' . $index]);
        $db->prepare('INSERT INTO usuarios (uuid,organizacion,usuario,nombre,password_hash,tipo_usuario,persona,f_registro) VALUES (?,1,?,?,?,8,?,NOW())')->execute([random_bytes(16),'historico-' . $index,'Histórico',password_hash('Prueba segura',PASSWORD_BCRYPT,['cost'=>4]),$index]);
    }
    $securityFile = dirname(__DIR__) . '/database/migrations/20261012_003_autorizacion.php';
    $db->exec('UPDATE usuarios SET persona=1 WHERE id=3');
    migrationReject(fn () => (require $securityFile)($db,$runner),'varias cuentas');
    migrationCheck(!$runner->indexExists('usuarios','uq_usuarios_persona'),'El conflicto no crea la restricción.');
    migrationCheck((int) $db->query('SELECT COUNT(*) FROM usuarios WHERE persona=1')->fetchColumn()===2,'Las cuentas en conflicto permanecen intactas.');
    $db->exec('UPDATE usuarios SET persona=2 WHERE id=3');
    (require $securityFile)($db,$runner); $db->prepare('INSERT INTO esquema_migraciones VALUES (?,?,NOW())')->execute([basename($securityFile),hash_file('sha256',$securityFile)]);
    foreach ([2,3] as $user) $db->prepare('INSERT INTO estructura (uuid,proceso,usuario,nivel,activo,f_asignacion) VALUES (?,1,?,?,1,NOW())')->execute([random_bytes(16),$user,$user-1]);
    $db->exec('UPDATE estructura SET nodo_padre=1 WHERE id=2');
    $db->exec('INSERT INTO personas_procesos (persona,proceso,f_registro) VALUES (1,1,NOW())');
    $db->exec('INSERT INTO personas_responsables (persona_proceso,estructura,f_asignacion,asignado_por) VALUES (1,2,NOW(),1)');
    $columns='id,uuid,proceso,usuario,nodo_padre,nivel,nombre_nodo,activo,f_asignacion,f_baja';
    $previous=$db->query('SELECT ' . $columns . ' FROM estructura ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $responsibles=$db->query('SELECT * FROM personas_responsables')->fetchAll(PDO::FETCH_ASSOC);
    $structureFile=dirname(__DIR__) . '/database/migrations/20261012_004_estructuras.php';
    $db->exec('UPDATE usuarios SET persona=NULL WHERE id=2');
    migrationReject(fn () => (require $structureFile)($db,$runner),'sin persona');
    migrationCheck(!$runner->columnExists('estructura','contenedor'),'Preflight histórico se ejecuta antes del DDL.');
    $db->exec('UPDATE usuarios SET persona=1 WHERE id=2');
    $db->exec('UPDATE estructura SET nivel=1 WHERE id=2');
    $db->exec('UPDATE estructura SET nodo_padre=2 WHERE id=1');
    migrationReject(fn () => (require $structureFile)($db,$runner),'Ciclo');
    $db->exec('UPDATE estructura SET nodo_padre=NULL WHERE id=1');
    $db->exec('UPDATE estructura SET nivel=2 WHERE id=2');
    migrationCheck(count($runner->run())===1,'Migra desde un estado post-Fase 2 con estructura histórica.');
    migrationCheck($db->query('SELECT ' . $columns . ' FROM estructura ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$previous,'Conserva todos los campos históricos e IDs de estructura.');
    migrationCheck($db->query('SELECT * FROM personas_responsables')->fetchAll(PDO::FETCH_ASSOC)===$responsibles,'Referencias anteriores conservan los mismos IDs.');
    migrationCheck((int) $db->query('SELECT COUNT(*) FROM estructura_relaciones')->fetchColumn()===3,'Reconstruye correctamente el cierre de relaciones históricas.');
    migrationCheck((int) $db->query('SELECT COUNT(*) FROM estructura WHERE persona IS NOT NULL AND contenedor IS NOT NULL')->fetchColumn()===2,'Vincula las personas reales sin cuentas ni identidades ficticias.');
    migrationCheck($runner->run()===[],'Reejecución es idempotente.');
    $db->exec('UPDATE estructura_integrantes_procesos SET activo=0 WHERE persona=1');
    $db->prepare("INSERT INTO estructuras_organizacionales (uuid,organizacion,nombre,fecha) VALUES (?,1,'Segundo contenedor',NOW())")->execute([random_bytes(16)]);
    $secondContainer=(int) $db->lastInsertId();
    $db->prepare('INSERT INTO estructura (uuid,contenedor,persona,nivel,activo,f_asignacion) VALUES (?,?,1,1,1,NOW())')->execute([random_bytes(16),$secondContainer]); $newMember=(int) $db->lastInsertId();
    $db->prepare('INSERT INTO estructura_integrantes_procesos (persona,proceso,integrante,activo,fecha) VALUES (1,1,?,1,NOW())')->execute([$newMember]);
    migrationCheck((int) $db->query('SELECT COUNT(*) FROM estructura_integrantes_procesos WHERE persona=1')->fetchColumn()===2,'Preserva proyecciones históricas junto a una sola activa.');
    try { $db->exec('UPDATE estructura_integrantes_procesos SET activo=1 WHERE integrante=1'); throw new RuntimeException('No se protegió la ubicación activa única.'); }
    catch (PDOException $error) { migrationCheck(($error->errorInfo[1] ?? null)===1062,'La BD rechaza dos responsables efectivos en una elección.'); }
    echo "OK: $checks comprobaciones de migraciones históricas y conflictos.\n";
} finally { if (preg_match('/^sicrat_phase1_test_[a-f0-9]{12}$/',$name)) $db->exec('DROP DATABASE IF EXISTS `' . $name . '`'); }
