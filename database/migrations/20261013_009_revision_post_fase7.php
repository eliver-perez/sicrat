<?php

declare(strict_types=1);

use App\Core\Migrations;

return static function(PDO $db,Migrations $schema): void {
    $definitions=[
        'secciones_procesos'=>'proceso INT NOT NULL,seccion INT NOT NULL,activo TINYINT NOT NULL DEFAULT 1,registrado_por INT NULL,fecha DATETIME NOT NULL,PRIMARY KEY(proceso,seccion),FOREIGN KEY(proceso) REFERENCES procesos_electorales(id),FOREIGN KEY(seccion) REFERENCES secciones_electorales(id),FOREIGN KEY(registrado_por) REFERENCES usuarios(id)',
        'casillas_procesos'=>'proceso INT NOT NULL,casilla INT NOT NULL,activo TINYINT NOT NULL DEFAULT 1,registrado_por INT NULL,fecha DATETIME NOT NULL,PRIMARY KEY(proceso,casilla),FOREIGN KEY(proceso) REFERENCES procesos_electorales(id),FOREIGN KEY(casilla) REFERENCES casillas(id),FOREIGN KEY(registrado_por) REFERENCES usuarios(id)',
        'casos_categorias'=>'id INT AUTO_INCREMENT PRIMARY KEY,uuid BINARY(16) NOT NULL UNIQUE,organizacion INT NOT NULL,proceso INT NOT NULL,tipo VARCHAR(16) NOT NULL,nombre VARCHAR(100) NOT NULL,activo TINYINT NOT NULL DEFAULT 1,registrado_por INT NULL,fecha DATETIME NOT NULL,UNIQUE(proceso,tipo,nombre),UNIQUE(id,organizacion,proceso),FOREIGN KEY(organizacion) REFERENCES organizaciones(id),FOREIGN KEY(proceso) REFERENCES procesos_electorales(id),FOREIGN KEY(registrado_por) REFERENCES usuarios(id)',
    ];
    // Rechazar tablas ajenas o pasos parciales incompatibles antes de cambiar el esquema.
    foreach($definitions as $table=>$definition) {
        $check=$db->prepare('SELECT engine FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$check->execute([$table]);$engine=$check->fetchColumn();
        if($engine===false)continue;
        if(strtolower($engine)!=='innodb')throw new RuntimeException('Motor incompatible: '.$table);
        preg_match_all('/(?:^|,)([a-z_]+) (INT|TINYINT|BINARY|VARCHAR|DATETIME)(?:\((\d+)\))?/',$definition,$columns,PREG_SET_ORDER);
        foreach($columns as $column){$check=$db->prepare('SELECT data_type,character_maximum_length,is_nullable,extra,column_type,collation_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');$check->execute([$table,$column[1]]);$metadata=$check->fetch(PDO::FETCH_ASSOC);if(!$metadata || $metadata['data_type']!==strtolower($column[2]) || (isset($column[3]) && (int)$metadata['character_maximum_length']!==(int)$column[3]))throw new RuntimeException('Columna incompatible: '.$table.'.'.$column[1]);$nullable=in_array($column[1],['registrado_por'],true);if($metadata['is_nullable']!==($nullable ? 'YES' : 'NO') || str_contains($metadata['column_type'],'unsigned') || ($column[1]==='id' && !str_contains($metadata['extra'],'auto_increment')) || ($column[2]==='VARCHAR' && $metadata['collation_name']!=='utf8mb4_unicode_ci'))throw new RuntimeException('Definición incompatible: '.$table.'.'.$column[1]);}
        preg_match_all('/(?:PRIMARY KEY|UNIQUE)\(([a-z_,]+)\)/',$definition,$keys,PREG_SET_ORDER);
        if($table==='casos_categorias')$keys[]=['','uuid'];
        $check=$db->prepare("SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND non_unique=0 GROUP BY index_name");$check->execute([$table]);$existing=$check->fetchAll(PDO::FETCH_COLUMN);
        foreach($keys as $key)if(!in_array($key[1],$existing,true))throw new RuntimeException('Unicidad incompatible: '.$table.'.'.$key[1]);
        preg_match_all('/FOREIGN KEY\(([a-z_,]+)\) REFERENCES ([a-z_]+)\(([a-z_,]+)\)/',$definition,$foreign,PREG_SET_ORDER);
        foreach($foreign as [, $child,$parent,$parentColumns]){$check=$db->prepare("SELECT GROUP_CONCAT(column_name ORDER BY ordinal_position SEPARATOR ',') child_fields,GROUP_CONCAT(referenced_column_name ORDER BY ordinal_position SEPARATOR ',') parent_fields FROM information_schema.key_column_usage WHERE table_schema=DATABASE() AND table_name=? AND referenced_table_name=? GROUP BY constraint_name");$check->execute([$table,$parent]);if(!array_filter($check->fetchAll(PDO::FETCH_ASSOC),static fn($row)=>$row['child_fields']===$child && $row['parent_fields']===$parentColumns))throw new RuntimeException('Relación incompatible: '.$table.'.'.$child);}
    }
    foreach(['peticiones','incidencias'] as $table)if($schema->columnExists($table,'categoria_id')){$check=$db->query("SELECT data_type,is_nullable FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='$table' AND column_name='categoria_id'")->fetch(PDO::FETCH_ASSOC);if($check['data_type']!=='int' || $check['is_nullable']!=='YES')throw new RuntimeException('Referencia de categoría incompatible: '.$table);}
    // Validar referencias previas antes del primer DDL (incluida reentrada parcial).
    foreach(['secciones_electorales','casillas'] as $table) {
        if($db->query("SELECT uuid FROM $table WHERE uuid IS NOT NULL GROUP BY uuid HAVING COUNT(*)>1 LIMIT 1")->fetchColumn()!==false)throw new RuntimeException('UUID incompatible: '.$table);
    }
    foreach(['peticiones','incidencias'] as $table) {
        if($db->query("SELECT r.id FROM $table r LEFT JOIN procesos_electorales pe ON pe.id=r.proceso AND pe.organizacion=r.organizacion WHERE pe.id IS NULL LIMIT 1")->fetchColumn()!==false)throw new RuntimeException('Contexto incompatible: '.$table);
        if($schema->columnExists($table,'categoria_id') && $db->query("SELECT COUNT(*) FROM $table WHERE categoria_id IS NOT NULL")->fetchColumn()) {
            if(!$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='casos_categorias'")->fetchColumn() || $db->query("SELECT r.id FROM $table r LEFT JOIN casos_categorias c ON c.id=r.categoria_id AND c.organizacion=r.organizacion AND c.proceso=r.proceso AND c.tipo='$table' WHERE r.categoria_id IS NOT NULL AND c.id IS NULL LIMIT 1")->fetchColumn()!==false)throw new RuntimeException('Categoría incompatible: '.$table);
        }
    }
    foreach($definitions as $table=>$definition)$db->exec("CREATE TABLE IF NOT EXISTS $table ($definition) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach(['peticiones','incidencias'] as $table){
        if(!$schema->columnExists($table,'categoria_id'))$db->exec("ALTER TABLE $table ADD categoria_id INT NULL");
        $check=$db->query("SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='$table' AND constraint_name='fk_{$table}_categoria'");
        if(!$check->fetchColumn())$db->exec("ALTER TABLE $table ADD CONSTRAINT fk_{$table}_categoria FOREIGN KEY(categoria_id,organizacion,proceso) REFERENCES casos_categorias(id,organizacion,proceso)");
        // Conservar los textos e IDs históricos; reunir únicamente variantes del mismo nombre/contexto.
        $categories=$db->query("SELECT DISTINCT r.organizacion,r.proceso,COALESCE(NULLIF(TRIM(r.categoria),''),'General') nombre FROM $table r JOIN procesos_electorales pe ON pe.id=r.proceso AND pe.organizacion=r.organizacion WHERE r.categoria_id IS NULL");
        $insertCategory=$db->prepare('INSERT IGNORE INTO casos_categorias(uuid,organizacion,proceso,tipo,nombre,fecha) VALUES(?,?,?,?,?,NOW())');
        foreach($categories as $category)$insertCategory->execute([random_bytes(16),$category['organizacion'],$category['proceso'],$table,$category['nombre']]);
        $db->exec("UPDATE $table r JOIN casos_categorias c ON c.organizacion=r.organizacion AND c.proceso=r.proceso AND c.tipo='$table' AND c.nombre=CONVERT(COALESCE(NULLIF(TRIM(r.categoria),''),'General') USING utf8mb4) COLLATE utf8mb4_unicode_ci SET r.categoria_id=c.id WHERE r.categoria_id IS NULL");
    }
    $defaultCategory=$db->prepare('INSERT IGNORE INTO casos_categorias(uuid,organizacion,proceso,tipo,nombre,fecha) VALUES(?,?,?,?,?,NOW())');
    foreach($db->query('SELECT id,organizacion FROM procesos_electorales') as $process)foreach(['peticiones','incidencias'] as $kind)$defaultCategory->execute([random_bytes(16),$process['organizacion'],$process['id'],$kind,'General']);
    // Identificadores de navegación para catálogos heredados que aún carecen de UUID.
    foreach(['secciones_electorales','casillas'] as $table) {
        $update=$db->prepare("UPDATE $table SET uuid=? WHERE id=? AND uuid IS NULL");
        foreach($db->query("SELECT id FROM $table WHERE uuid IS NULL") as $row)$update->execute([random_bytes(16),$row['id']]);
    }
    // Asociar sólo contextos respaldados por referencias existentes; no distribuir todo el catálogo.
    $db->exec('INSERT IGNORE INTO secciones_procesos(proceso,seccion,fecha) SELECT pp.proceso,p.seccion,NOW() FROM personas_procesos pp JOIN personas p ON p.id=pp.persona JOIN procesos_electorales pe ON pe.id=pp.proceso AND pe.organizacion=p.organizacion WHERE p.seccion IS NOT NULL');
    $db->exec('INSERT IGNORE INTO secciones_procesos(proceso,seccion,fecha) SELECT pe.id,p.seccion,NOW() FROM personas p JOIN procesos_electorales pe ON pe.organizacion=p.organizacion WHERE p.seccion IS NOT NULL AND (SELECT COUNT(*) FROM procesos_electorales current_pe WHERE current_pe.organizacion=p.organizacion)=1');
    $db->exec('INSERT IGNORE INTO secciones_procesos(proceso,seccion,fecha) SELECT proceso,seccion,NOW() FROM usuarios_territorios');
    $db->exec('INSERT IGNORE INTO casillas_procesos(proceso,casilla,fecha) SELECT proceso,casilla,NOW() FROM jornada_casillas');
    $db->exec('INSERT IGNORE INTO secciones_procesos(proceso,seccion,fecha) SELECT cp.proceso,c.seccion,NOW() FROM casillas_procesos cp JOIN casillas c ON c.id=cp.casilla');
    $permissions=['secciones.ver'=>['Consultar secciones electorales',null],'secciones.administrar'=>['Administrar secciones de la elección',null],'casillas.ver'=>['Consultar casillas electorales',null],'casillas.administrar'=>['Administrar casillas de la elección',null],'peticiones.categorias'=>['Administrar categorías de peticiones','peticiones_incidencias'],'incidencias.categorias'=>['Administrar categorías de incidencias','peticiones_incidencias']];
    $insert=$db->prepare("INSERT IGNORE INTO permisos(id,permiso,descripcion,ambito,modulo,f_registro) VALUES(?,?,?,'electoral',?,NOW())");
    $grant=$db->prepare("INSERT INTO permisos_usuarios_tipo(uuid,permiso,tipo,valor,alcance,f_actualizacion) SELECT ?,?,2,1,'eleccion',NOW() WHERE NOT EXISTS(SELECT 1 FROM permisos_usuarios_tipo WHERE tipo=2 AND permiso=? AND proceso IS NULL)");
    foreach($permissions as $code=>[$label,$module]){$insert->execute([$code,$label,$label,$module]);$grant->execute([random_bytes(16),$code,$code]);}
};
