<?php

declare(strict_types=1);

use App\Core\Migrations;

return static function (PDO $db, Migrations $schema): void {
    $identity = 'id INT AUTO_INCREMENT PRIMARY KEY, uuid BINARY(16) NOT NULL UNIQUE, organizacion INT NOT NULL, proceso INT NOT NULL';
    $context = 'FOREIGN KEY (organizacion) REFERENCES organizaciones(id), FOREIGN KEY (proceso) REFERENCES procesos_electorales(id)';
    $catalog = "$identity, nombre VARCHAR(180) NOT NULL, activo TINYINT NOT NULL DEFAULT 1, UNIQUE(id,proceso), UNIQUE(proceso,nombre), $context";
    $tables = [
        'jornada_config' => "proceso INT PRIMARY KEY, organizacion INT NOT NULL, zona VARCHAR(64) NOT NULL, captura_desde DATETIME NOT NULL, estado VARCHAR(16) NOT NULL DEFAULT 'preparacion', politica_inicial VARCHAR(16) NOT NULL DEFAULT 'validar', foto_obligatoria TINYINT NOT NULL DEFAULT 0, denominador VARCHAR(24) NOT NULL DEFAULT 'total_acta', version INT NOT NULL DEFAULT 1, editado_por INT NOT NULL, fecha DATETIME NOT NULL, abierto_en DATETIME NULL, cerrado_en DATETIME NULL, motivo VARCHAR(500) NULL, $context, FOREIGN KEY(editado_por) REFERENCES usuarios(id)",
        'jornada_partidos' => "$catalog, siglas VARCHAR(40) NOT NULL, color CHAR(7) NULL, logo INT NULL, FOREIGN KEY(logo) REFERENCES archivos(id)",
        'jornada_candidaturas' => "$catalog, cargo VARCHAR(120) NOT NULL, foto INT NULL, FOREIGN KEY(foto) REFERENCES archivos(id)",
        'jornada_coaliciones' => "$catalog, candidatura INT NULL, FOREIGN KEY(candidatura,proceso) REFERENCES jornada_candidaturas(id,proceso)",
        'jornada_coalicion_partidos' => 'coalicion INT NOT NULL, partido INT NOT NULL, proceso INT NOT NULL, PRIMARY KEY(coalicion,partido), FOREIGN KEY(coalicion,proceso) REFERENCES jornada_coaliciones(id,proceso), FOREIGN KEY(partido,proceso) REFERENCES jornada_partidos(id,proceso)',
        'jornada_opciones' => "$catalog, tipo VARCHAR(24) NOT NULL, orden INT NOT NULL, partido INT NULL, coalicion INT NULL, candidatura INT NULL, suma_candidatura TINYINT NOT NULL DEFAULT 0, en_denominador TINYINT NOT NULL DEFAULT 1, clasificacion VARCHAR(16) NOT NULL DEFAULT 'valido', UNIQUE(proceso,orden), FOREIGN KEY(partido,proceso) REFERENCES jornada_partidos(id,proceso), FOREIGN KEY(coalicion,proceso) REFERENCES jornada_coaliciones(id,proceso), FOREIGN KEY(candidatura,proceso) REFERENCES jornada_candidaturas(id,proceso)",
        'jornada_casillas' => "$identity, casilla INT NOT NULL, activo TINYINT NOT NULL DEFAULT 1, UNIQUE(proceso,casilla), UNIQUE(id,proceso), $context, FOREIGN KEY(casilla) REFERENCES casillas(id)",
        'jornada_capturistas' => "$identity, casilla INT NOT NULL, usuario INT NOT NULL, activo TINYINT NOT NULL DEFAULT 1, asignado_por INT NOT NULL, fecha DATETIME NOT NULL, retirado_en DATETIME NULL, UNIQUE(casilla,usuario), INDEX(proceso,usuario,activo,casilla), $context, FOREIGN KEY(casilla,proceso) REFERENCES jornada_casillas(id,proceso), FOREIGN KEY(usuario) REFERENCES usuarios(id), FOREIGN KEY(asignado_por) REFERENCES usuarios(id)",
        'jornada_borradores' => "$identity, UNIQUE(id,proceso), casilla INT NOT NULL, usuario INT NOT NULL, estado VARCHAR(16) NOT NULL DEFAULT 'abierto', fecha DATETIME NOT NULL, clave VARCHAR(80) CHARACTER SET ascii NOT NULL, UNIQUE(proceso,usuario,clave), INDEX(proceso,casilla,estado), $context, FOREIGN KEY(casilla,proceso) REFERENCES jornada_casillas(id,proceso), FOREIGN KEY(usuario) REFERENCES usuarios(id)",
        'jornada_resultados' => "$identity, casilla INT NOT NULL, actual_version INT NULL, consecutivo INT NOT NULL DEFAULT 0, revision INT NOT NULL DEFAULT 0, UNIQUE(casilla), UNIQUE(id,proceso,organizacion), $context, FOREIGN KEY(casilla,proceso) REFERENCES jornada_casillas(id,proceso)",
        'jornada_versiones' => "$identity, resultado INT NOT NULL, numero INT NOT NULL, usuario INT NOT NULL, fecha DATETIME NOT NULL, origen VARCHAR(16) NOT NULL, estado VARCHAR(16) NOT NULL, base_version INT NULL, borrador INT NULL UNIQUE, foto INT NULL, notas VARCHAR(2000) NULL, motivo VARCHAR(500) NULL, total BIGINT NOT NULL, firma CHAR(64) CHARACTER SET ascii NOT NULL, clave VARCHAR(80) CHARACTER SET ascii NOT NULL, UNIQUE(resultado,numero), UNIQUE(resultado,id), UNIQUE(id,proceso), UNIQUE(proceso,usuario,clave), INDEX(proceso,estado,fecha,id), INDEX(proceso,usuario,id), $context, FOREIGN KEY(resultado,proceso,organizacion) REFERENCES jornada_resultados(id,proceso,organizacion), FOREIGN KEY(usuario) REFERENCES usuarios(id), FOREIGN KEY(resultado,base_version) REFERENCES jornada_versiones(resultado,id), FOREIGN KEY(borrador,proceso) REFERENCES jornada_borradores(id,proceso), FOREIGN KEY(foto) REFERENCES archivos(id), CHECK(total>=0)",
        'jornada_votos' => 'version INT NOT NULL, opcion INT NOT NULL, votos INT NOT NULL, proceso INT NOT NULL, PRIMARY KEY(version,opcion), FOREIGN KEY(version,proceso) REFERENCES jornada_versiones(id,proceso), FOREIGN KEY(opcion,proceso) REFERENCES jornada_opciones(id,proceso), CHECK(votos>=0)',
        'jornada_decisiones' => "$identity, resultado INT NOT NULL, version_resultado INT NOT NULL, usuario INT NOT NULL, accion VARCHAR(16) NOT NULL, motivo VARCHAR(500) NOT NULL, anterior INT NULL, nueva INT NULL, cambios JSON NOT NULL, fecha DATETIME NOT NULL, INDEX(proceso,resultado,id), $context, FOREIGN KEY(resultado) REFERENCES jornada_resultados(id), FOREIGN KEY(version_resultado) REFERENCES jornada_versiones(id), FOREIGN KEY(usuario) REFERENCES usuarios(id), FOREIGN KEY(anterior) REFERENCES jornada_versiones(id), FOREIGN KEY(nueva) REFERENCES jornada_versiones(id)",
        'jornada_avisos' => "$identity, usuario INT NOT NULL, canal VARCHAR(16) NOT NULL, modo VARCHAR(16) NOT NULL, frecuencia INT NOT NULL, incluir_totales TINYINT NOT NULL DEFAULT 1, incluir_avance TINYINT NOT NULL DEFAULT 1, incluir_estado TINYINT NOT NULL DEFAULT 1, plantilla VARCHAR(128) NULL, idioma VARCHAR(12) NULL, activo TINYINT NOT NULL DEFAULT 1, creado_por INT NOT NULL, version INT NOT NULL DEFAULT 1, UNIQUE(proceso,usuario,canal), $context, FOREIGN KEY(usuario) REFERENCES usuarios(id), FOREIGN KEY(creado_por) REFERENCES usuarios(id), CHECK(frecuencia>=5)",
        'jornada_envios' => 'envio CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, aviso INT NOT NULL, modo VARCHAR(16) NOT NULL, version INT NOT NULL, FOREIGN KEY(envio) REFERENCES notificaciones_envios(id), FOREIGN KEY(aviso) REFERENCES jornada_avisos(id)',
    ];
    // Comprobar todos los nombres existentes antes del primer DDL. Nunca sobrescribir una tabla ajena.
    foreach ($tables as $table => $definition) {
        $statement = $db->prepare('SELECT engine FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $statement->execute([$table]);
        $engine = $statement->fetchColumn();
        if ($engine === false) continue;
        if (strtolower($engine) !== 'innodb') throw new RuntimeException('Motor incompatible: '.$table.'.');
        preg_match_all('/(?:^|,\s*)([a-z_]+)\s+(INT|BIGINT|BINARY|VARCHAR|TINYINT|DATETIME|JSON|CHAR)(?:\((\d+)\))?/', $definition, $columns, PREG_SET_ORDER);
        foreach ($columns as $column) {
            $statement = $db->prepare('SELECT data_type,character_maximum_length,is_nullable,extra FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
            $statement->execute([$table,$column[1]]);
            $metadata = $statement->fetch(PDO::FETCH_ASSOC);
            $type = strtolower($column[2]);
            if (!$metadata || ($metadata['data_type'] !== $type && !($type === 'json' && $metadata['data_type'] === 'longtext')) || (isset($column[3]) && in_array($type,['binary','char','varchar'],true) && (int)$metadata['character_maximum_length'] !== (int)$column[3])) throw new RuntimeException('Columna incompatible: '.$table.'.'.$column[1].'.');
            preg_match('/(?:^|,\s*)'.preg_quote($column[1],'/').'\s+[^,]+/',$definition,$specification);
            $nullable=preg_match('/NOT NULL|PRIMARY KEY/',$specification[0]) ? 'NO' : 'YES';
            if($metadata['is_nullable']!==$nullable || (str_contains($specification[0],'AUTO_INCREMENT') && !str_contains($metadata['extra'],'auto_increment'))) throw new RuntimeException('Definición incompatible: '.$table.'.'.$column[1].'.');
        }
        preg_match_all('/FOREIGN KEY\s*\(([a-z_,]+)\) REFERENCES ([a-z_]+)\(([a-z_,]+)\)/', $definition, $foreign, PREG_SET_ORDER);
        foreach ($foreign as [, $child, $parent, $parentColumns]) {
            $statement = $db->prepare("SELECT GROUP_CONCAT(column_name ORDER BY ordinal_position SEPARATOR ',') child_columns, GROUP_CONCAT(referenced_column_name ORDER BY ordinal_position SEPARATOR ',') parent_columns FROM information_schema.key_column_usage WHERE table_schema=DATABASE() AND table_name=? AND referenced_table_name=? GROUP BY constraint_name");
            $statement->execute([$table,$parent]);
            if (!array_filter($statement->fetchAll(PDO::FETCH_ASSOC),static fn($key) => $key['child_columns'] === $child && $key['parent_columns'] === $parentColumns)) throw new RuntimeException('Relación incompatible: '.$table.'.'.$child.'.');
            $childFields=explode(',',$child);$parentFields=explode(',',$parentColumns);$conditions=[];$present=[];
            foreach($childFields as $index=>$field) { $conditions[]='p.'.$parentFields[$index].'=c.'.$field;$present[]='c.'.$field.' IS NOT NULL'; }
            if(!$schema->columnExists($parent,$parentFields[0]) || $db->query("SELECT 1 FROM $table c LEFT JOIN $parent p ON ".implode(' AND ',$conditions).' WHERE '.implode(' AND ',$present).' AND p.'.$parentFields[0].' IS NULL LIMIT 1')->fetchColumn()!==false) throw new RuntimeException('Datos referenciales incompatibles: '.$table.'.'.$child.'.');
        }
        preg_match_all('/(?:PRIMARY KEY|UNIQUE)\s*\(([a-z_,]+)\)/', $definition, $keys, PREG_SET_ORDER);
        foreach ($columns as $column) if (preg_match('/(?:^|,\s*)'.preg_quote($column[1],'/').'\s+[^,]+?\b(?:UNIQUE|PRIMARY KEY)\b/', $definition)) $keys[] = ['', $column[1]];
        $statement = $db->prepare("SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND non_unique=0 GROUP BY index_name");
        $statement->execute([$table]); $unique = $statement->fetchAll(PDO::FETCH_COLUMN);
        foreach ($keys as $key) if (!in_array($key[1],$unique,true)) throw new RuntimeException('Clave incompatible: '.$table.'.'.$key[1].'.');
        if ($schema->columnExists($table,'organizacion')) {
            if ($db->query("SELECT j.proceso FROM $table j JOIN procesos_electorales pe ON pe.id=j.proceso WHERE j.organizacion<>pe.organizacion LIMIT 1")->fetchColumn() !== false) throw new RuntimeException('Contexto histórico incompatible: '.$table.'.');
        }
    }
    if($schema->columnExists('jornada_resultados','actual_version') && $schema->columnExists('jornada_versiones','resultado')) {
        if($db->query("SELECT r.id FROM jornada_resultados r LEFT JOIN jornada_versiones v ON v.id=r.actual_version AND v.resultado=r.id WHERE r.actual_version IS NOT NULL AND (v.id IS NULL OR v.estado NOT IN('preliminar','validada')) LIMIT 1")->fetchColumn()!==false) throw new RuntimeException('Versión vigente incompatible.');
    }
    if($schema->columnExists('jornada_versiones','total') && $schema->columnExists('jornada_votos','votos')) {
        if($db->query('SELECT v.id FROM jornada_versiones v LEFT JOIN jornada_votos votes ON votes.version=v.id GROUP BY v.id,v.total HAVING v.total<0 OR v.total<>COALESCE(SUM(votes.votos),0) LIMIT 1')->fetchColumn()!==false || $db->query('SELECT version FROM jornada_votos WHERE votos<0 LIMIT 1')->fetchColumn()!==false) throw new RuntimeException('Votos históricos incompatibles.');
    }
    foreach ($tables as $table => $definition) $db->exec("CREATE TABLE IF NOT EXISTS $table ($definition) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // El puntero sólo puede referir una versión de la misma casilla. Una columna impide dos vigentes.
    $statement = $db->query("SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='jornada_resultados' AND constraint_name='fk_jornada_actual'");
    if (!$statement->fetchColumn()) $db->exec('ALTER TABLE jornada_resultados ADD CONSTRAINT fk_jornada_actual FOREIGN KEY(id,actual_version) REFERENCES jornada_versiones(resultado,id)');
    $permissions = [
        'jornada.configurar'=>'Configurar Jornada Electoral', 'jornada.asignar_capturistas'=>'Asignar capturistas a casillas',
        'jornada.abrir'=>'Abrir Jornada Electoral', 'jornada.cerrar'=>'Cerrar Jornada Electoral', 'jornada.reabrir'=>'Reabrir Jornada Electoral',
        'resultados.capturar'=>'Capturar resultados de casillas asignadas', 'resultados.ver'=>'Consultar resultados e historial',
        'resultados.ver_preliminares'=>'Consultar resultados preliminares internos', 'resultados.validar'=>'Validar o rechazar versiones',
        'resultados.corregir'=>'Crear correcciones de resultados', 'resultados.administrar'=>'Capturar fuera de asignaciones explícitas',
        'resultados.exportar'=>'Exportar Jornada Electoral y resultados',
    ];
    $insert = $db->prepare("INSERT IGNORE INTO permisos(id,permiso,descripcion,ambito,modulo,f_registro) VALUES(?,?,?,'electoral','jornada_electoral',NOW())");
    $grant = $db->prepare("INSERT INTO permisos_usuarios_tipo(uuid,permiso,tipo,valor,alcance,f_actualizacion) SELECT ?,?,2,1,'eleccion',NOW() WHERE NOT EXISTS(SELECT 1 FROM permisos_usuarios_tipo WHERE tipo=2 AND permiso=? AND proceso IS NULL)");
    foreach ($permissions as $code => $label) { $insert->execute([$code,$label,$label]); $grant->execute([random_bytes(16),$code,$code]); }
    $db->exec("UPDATE modulos SET implementado=1 WHERE codigo='jornada_electoral'");
};
