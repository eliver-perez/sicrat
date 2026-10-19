<?php

declare(strict_types=1);

use App\Core\Migrations;

return static function (PDO $db, Migrations $schema): void {
    // El seed original se conserva. La unicidad impide repetir sus INSERT sin control.
    $db->exec("CREATE TABLE IF NOT EXISTS geografia_fusiones (
        tabla VARCHAR(32) NOT NULL, origen INT NOT NULL, destino INT NOT NULL,
        datos_originales LONGTEXT NOT NULL, fecha DATETIME NOT NULL,
        PRIMARY KEY(tabla,origen)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $catalogs = [
        'estados'=>['estado'],
        'municipios'=>['estado','municipio'],
        'localidades'=>['municipio','localidad','codigo_postal'],
        'colonias'=>['localidad','colonia','codigo_postal'],
    ];
    $quote = static fn (string $name): string => '`' . str_replace('`','``',$name) . '`';
    $db->beginTransaction();
    try {
        foreach ($catalogs as $table=>$keys) {
            $rows = $db->query("SELECT * FROM `$table` ORDER BY id FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
            $match = implode(' AND ',array_map(static fn ($key) => $key==='codigo_postal' ? "COALESCE(a.`$key`,'')=COALESCE(b.`$key`,'')" : "a.`$key`=b.`$key`",$keys));
            $pairs = $db->query("SELECT a.id origen,MIN(b.id) destino FROM `$table` a JOIN `$table` b ON $match AND b.id<a.id GROUP BY a.id")->fetchAll(PDO::FETCH_ASSOC);
            $byId = array_column($rows,null,'id');
            // No fusionar municipios homónimos con claves oficiales diferentes.
            if ($table==='municipios') foreach ($pairs as $pair) {
                $source=$byId[$pair['origen']]['clave']; $target=$byId[$pair['destino']]['clave'];
                if ($source!==$target) throw new RuntimeException('Municipios homónimos con claves distintas: revisar antes de fusionar (' . $pair['origen'] . ', ' . $pair['destino'] . ').');
            }
            $references=$db->prepare('SELECT TABLE_SCHEMA,TABLE_NAME,COLUMN_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME=?');
            $references->execute([$table]); $references=$references->fetchAll(PDO::FETCH_ASSOC);
            foreach ($references as $reference) if ($reference['REFERENCED_COLUMN_NAME']!=='id' || $reference['TABLE_SCHEMA']!==$db->query('SELECT DATABASE()')->fetchColumn()) throw new RuntimeException('Referencia geográfica no compatible con la limpieza.');
            $archive=$db->prepare('INSERT INTO geografia_fusiones(tabla,origen,destino,datos_originales,fecha) VALUES(?,?,?,?,NOW())');
            $delete=$db->prepare("DELETE FROM `$table` WHERE id=?");
            foreach ($pairs as $pair) {
                $archive->execute([$table,$pair['origen'],$pair['destino'],json_encode($byId[$pair['origen']],JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
                foreach ($references as $reference) {
                    $target=$quote($reference['TABLE_NAME']); $column=$quote($reference['COLUMN_NAME']);
                    $update=$db->prepare("UPDATE $target SET $column=? WHERE $column=?");
                    $update->execute([$pair['destino'],$pair['origen']]);
                    $check=$db->prepare("SELECT 1 FROM $target WHERE $column=? LIMIT 1");$check->execute([$pair['origen']]);
                    if ($check->fetchColumn()) throw new RuntimeException('Quedaron referencias pendientes de reasignar.');
                }
                $delete->execute([$pair['origen']]);
            }
        }
        $db->commit();
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    // DDL reanudable. CP NULL y vacío representan la misma ausencia; CP distintos se conservan.
    foreach (['localidades','colonias'] as $table) if (!$schema->columnExists($table,'cp_unicidad')) $db->exec("ALTER TABLE `$table` ADD cp_unicidad CHAR(5) GENERATED ALWAYS AS (COALESCE(codigo_postal,'')) STORED");
    foreach ($catalogs as $table=>$keys) {
        $keys=array_map(static fn ($key) => $key==='codigo_postal' ? 'cp_unicidad' : $key,$keys);
        if (!$schema->indexExists($table,'uq_geografia_identidad')) $db->exec("ALTER TABLE `$table` ADD UNIQUE KEY uq_geografia_identidad (" . implode(',',array_map($quote,$keys)) . ')');
    }
};
