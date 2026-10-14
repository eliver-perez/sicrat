<?php

declare(strict_types=1);

use App\Core\Migrations;

return static function (PDO $db, Migrations $schema): void {
    // 009 ya aplicada permanece intacta. Sólo catálogo y modalidad de importación.
    $exists = $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='casillas_tipos'")->fetchColumn();
    if ($exists) {
        $columns = $db->query('SHOW COLUMNS FROM casillas_tipos')->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
        foreach (['codigo'=>'varchar(20)','nombre'=>'varchar(60)','activo'=>'tinyint'] as $name=>$type) {
            if (!isset($columns[$name]) || !str_starts_with(strtolower($columns[$name]['Type']),$type) || $columns[$name]['Null']!=='NO') throw new RuntimeException('Catálogo casillas_tipos incompatible.');
        }
        if ($columns['codigo']['Key']!=='PRI' || strtoupper((string)$db->query("SELECT engine FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='casillas_tipos'")->fetchColumn())!=='INNODB') throw new RuntimeException('Catálogo casillas_tipos incompatible.');
    }
    if ($schema->columnExists('personas_importaciones','modo')) {
        $mode=$db->query("SHOW COLUMNS FROM personas_importaciones LIKE 'modo'")->fetch(PDO::FETCH_ASSOC);
        if (strtolower($mode['Type'])!=='varchar(20)' || $mode['Null']!=='NO') throw new RuntimeException('Modalidad de importación incompatible.');
    }
    $db->exec("CREATE TABLE IF NOT EXISTS casillas_tipos (
        codigo VARCHAR(20) PRIMARY KEY, nombre VARCHAR(60) NOT NULL,
        activo TINYINT NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $insert=$db->prepare('INSERT INTO casillas_tipos(codigo,nombre,activo) SELECT ?,?,1 WHERE NOT EXISTS(SELECT 1 FROM casillas_tipos WHERE codigo=?)');
    foreach (['BASICA'=>'Básica','CONTIGUA'=>'Contigua','EXTRAORDINARIA'=>'Extraordinaria','ESPECIAL'=>'Especial'] as $code=>$name) $insert->execute([$code,$name,$code]);
    // No renombra ni sustituye ningún tipo físico histórico, ni reactiva decisiones existentes.
    foreach ($db->query('SELECT DISTINCT tipo FROM casillas')->fetchAll(PDO::FETCH_COLUMN) as $type) $insert->execute([$type,$type,$type]);
    if (!$schema->columnExists('personas_importaciones','modo')) $db->exec("ALTER TABLE personas_importaciones ADD modo VARCHAR(20) NOT NULL DEFAULT 'batches'");
};
