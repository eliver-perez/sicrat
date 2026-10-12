<?php

declare(strict_types=1);

use App\Core\Migrations;

return static function (PDO $db, Migrations $migration): void {
    // Preflight antes del primer DDL. No se inventan identidades para cuentas antiguas.
    $legacy = $db->query('SELECT e.*,u.persona,p.organizacion persona_organizacion,pe.organizacion FROM estructura e JOIN usuarios u ON u.id=e.usuario LEFT JOIN personas p ON p.id=u.persona JOIN procesos_electorales pe ON pe.id=e.proceso')->fetchAll(PDO::FETCH_ASSOC);
    $byId = array_column($legacy, null, 'id');
    foreach ($legacy as $row) {
        if ($row['persona'] === null || (int) $row['persona_organizacion'] !== (int) $row['organizacion']) throw new RuntimeException('Estructura histórica sin persona válida. Revisar integrante ID ' . $row['id']);
        $visited = [(int) $row['id'] => true];
        $parent = $row['nodo_padre'];
        while ($parent !== null) {
            if (isset($visited[(int) $parent]) || !isset($byId[$parent]) || (int) $byId[$parent]['proceso'] !== (int) $row['proceso']) throw new RuntimeException('Ciclo o responsable histórico de otra elección. Revisar integrante ID ' . $row['id']);
            if ((int) $byId[$parent]['nivel'] > (int) $row['nivel']) throw new RuntimeException('Orden histórico ascendente. Revisar integrante ID ' . $row['id']);
            if ((int) $row['activo'] === 1 && (int) $byId[$parent]['activo'] !== 1) throw new RuntimeException('Responsable histórico inactivo. Revisar integrante ID ' . $row['id']);
            $visited[(int) $parent] = true;
            $parent = $byId[$parent]['nodo_padre'];
        }
    }
    $db->exec("CREATE TABLE IF NOT EXISTS estructuras_organizacionales (
        id INT AUTO_INCREMENT PRIMARY KEY, uuid BINARY(16) NOT NULL UNIQUE, organizacion INT NOT NULL, nombre VARCHAR(150) NOT NULL,
        clonada_de INT NULL, activo TINYINT NOT NULL DEFAULT 1, version INT NOT NULL DEFAULT 1, creado_por INT NULL, fecha DATETIME NOT NULL,
        FOREIGN KEY (organizacion) REFERENCES organizaciones(id), FOREIGN KEY (clonada_de) REFERENCES estructuras_organizacionales(id), FOREIGN KEY (creado_por) REFERENCES usuarios(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS estructuras_procesos (
        estructura INT NOT NULL, proceso INT NOT NULL, activo TINYINT NOT NULL DEFAULT 1, fecha DATETIME NOT NULL, fecha_baja DATETIME NULL,
        PRIMARY KEY (estructura,proceso), FOREIGN KEY (estructura) REFERENCES estructuras_organizacionales(id), FOREIGN KEY (proceso) REFERENCES procesos_electorales(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS niveles_organizacionales (
        id INT AUTO_INCREMENT PRIMARY KEY, uuid BINARY(16) NOT NULL UNIQUE, proceso INT NOT NULL, nombre VARCHAR(128) NOT NULL, orden SMALLINT NOT NULL,
        activo TINYINT NOT NULL DEFAULT 1, puede_reclutar TINYINT NOT NULL DEFAULT 0, permite_mismo_nivel TINYINT NOT NULL DEFAULT 0,
        editado_por INT NULL, fecha DATETIME NOT NULL, UNIQUE KEY uq_nivel_orden (proceso,orden), FOREIGN KEY (proceso) REFERENCES procesos_electorales(id), FOREIGN KEY (editado_por) REFERENCES usuarios(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec('ALTER TABLE estructura MODIFY proceso INT NULL, MODIFY usuario INT NULL');
    if (!$migration->columnExists('estructura', 'contenedor')) $db->exec('ALTER TABLE estructura ADD contenedor INT NULL, ADD persona INT NULL, ADD CONSTRAINT fk_integrante_contenedor FOREIGN KEY (contenedor) REFERENCES estructuras_organizacionales(id), ADD CONSTRAINT fk_integrante_persona FOREIGN KEY (persona) REFERENCES personas(id), ADD UNIQUE KEY uq_integrante_identidad (contenedor,persona)');
    $db->exec("CREATE TABLE IF NOT EXISTS estructura_integrantes_procesos (
        persona INT NOT NULL, proceso INT NOT NULL, integrante INT NOT NULL, nivel INT NULL, activo TINYINT NOT NULL DEFAULT 1, fecha DATETIME NOT NULL, fecha_baja DATETIME NULL,
        persona_activa INT GENERATED ALWAYS AS (CASE WHEN activo=1 THEN persona ELSE NULL END) STORED,
        PRIMARY KEY (integrante,proceso), UNIQUE KEY uq_persona_eleccion_activa (persona_activa,proceso), KEY ix_persona_contexto (persona,proceso,activo),
        FOREIGN KEY (persona) REFERENCES personas(id), FOREIGN KEY (proceso) REFERENCES procesos_electorales(id), FOREIGN KEY (integrante) REFERENCES estructura(id), FOREIGN KEY (nivel) REFERENCES niveles_organizacionales(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS procesos_estructura_config (
        proceso INT PRIMARY KEY, promocion_primera_incorporacion TINYINT NOT NULL DEFAULT 0, requiere_aprobacion TINYINT NOT NULL DEFAULT 1,
        aprobador INT NULL, editado_por INT NULL, fecha DATETIME NOT NULL, FOREIGN KEY (proceso) REFERENCES procesos_electorales(id), FOREIGN KEY (aprobador) REFERENCES usuarios(id), FOREIGN KEY (editado_por) REFERENCES usuarios(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS estructura_historial (
        id BIGINT AUTO_INCREMENT PRIMARY KEY, estructura INT NOT NULL, integrante INT NULL, proceso INT NULL, accion VARCHAR(48) NOT NULL,
        usuario INT NULL, antes MEDIUMTEXT NULL, despues MEDIUMTEXT NULL, motivo VARCHAR(500) NULL, fecha DATETIME NOT NULL,
        KEY ix_historial (estructura,id), FOREIGN KEY (estructura) REFERENCES estructuras_organizacionales(id), FOREIGN KEY (integrante) REFERENCES estructura(id),
        FOREIGN KEY (proceso) REFERENCES procesos_electorales(id), FOREIGN KEY (usuario) REFERENCES usuarios(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS estructura_transferencias (
        id INT AUTO_INCREMENT PRIMARY KEY, uuid BINARY(16) NOT NULL UNIQUE, estructura INT NOT NULL, integrante INT NOT NULL,
        origen INT NULL, destino INT NULL, reemplazo INT NULL, modalidad VARCHAR(16) NOT NULL, version INT NOT NULL,
        solicitante INT NOT NULL, aprobador INT NOT NULL, resuelto_por INT NULL, estado VARCHAR(16) NOT NULL DEFAULT 'pendiente', motivo VARCHAR(500) NOT NULL,
        motivo_resolucion VARCHAR(500) NULL, fecha DATETIME NOT NULL, fecha_resolucion DATETIME NULL,
        FOREIGN KEY (estructura) REFERENCES estructuras_organizacionales(id), FOREIGN KEY (integrante) REFERENCES estructura(id),
        FOREIGN KEY (origen) REFERENCES estructura(id), FOREIGN KEY (destino) REFERENCES estructura(id), FOREIGN KEY (reemplazo) REFERENCES estructura(id),
        FOREIGN KEY (solicitante) REFERENCES usuarios(id), FOREIGN KEY (aprobador) REFERENCES usuarios(id), FOREIGN KEY (resuelto_por) REFERENCES usuarios(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!$migration->indexExists('estructura_relaciones', 'ix_relaciones_descendiente')) $db->exec('ALTER TABLE estructura_relaciones ADD KEY ix_relaciones_descendiente (descendiente,profundidad,ancestro)');

    // La adaptación de registros y relaciones derivadas es una sola transacción.
    $db->beginTransaction();
    try {
        $containers = [];
        foreach ($legacy as $row) {
            if ($row['contenedor'] ?? null) { $containers[$row['proceso']] = (int) $row['contenedor']; continue; }
            if (!isset($containers[$row['proceso']])) {
                $statement = $db->prepare('INSERT INTO estructuras_organizacionales (uuid,organizacion,nombre,fecha) VALUES (?,?,?,NOW())');
                $statement->execute([random_bytes(16),$row['organizacion'],'Estructura histórica ' . $row['proceso']]);
                $containers[$row['proceso']] = (int) $db->lastInsertId();
                $db->prepare('INSERT INTO estructuras_procesos (estructura,proceso,fecha) VALUES (?,?,NOW())')->execute([$containers[$row['proceso']],$row['proceso']]);
            }
            $db->prepare('UPDATE estructura SET contenedor=?,persona=? WHERE id=?')->execute([$containers[$row['proceso']],$row['persona'],$row['id']]);
            $statement = $db->prepare('SELECT id FROM niveles_organizacionales WHERE proceso=? AND orden=?');
            $statement->execute([$row['proceso'],$row['nivel']]);
            $level = $statement->fetchColumn();
            if ($level === false) {
                $db->prepare('INSERT INTO niveles_organizacionales (uuid,proceso,nombre,orden,puede_reclutar,permite_mismo_nivel,fecha) VALUES (?,?,?,?,1,1,NOW())')->execute([random_bytes(16),$row['proceso'],'Nivel histórico ' . $row['nivel'],$row['nivel']]);
                $level = (int) $db->lastInsertId();
            }
            $db->prepare('INSERT INTO estructura_integrantes_procesos (persona,proceso,integrante,nivel,activo,fecha,fecha_baja) VALUES (?,?,?,?,?,?,?)')->execute([$row['persona'],$row['proceso'],$row['id'],$level,$row['activo'],$row['f_asignacion'],$row['f_baja']]);
            $db->prepare("INSERT INTO estructura_historial (estructura,integrante,proceso,accion,despues,fecha) VALUES (?,?,?,'adaptacion_historica',?,NOW())")->execute([$containers[$row['proceso']],$row['id'],$row['proceso'],json_encode(['responsable'=>$row['nodo_padre'],'nivel_historico'=>$row['nivel']],JSON_THROW_ON_ERROR)]);
        }
        if ($legacy !== []) {
            $db->exec('DELETE FROM estructura_relaciones');
            $insert = $db->prepare('INSERT INTO estructura_relaciones VALUES (?,?,?)');
            foreach ($legacy as $row) {
                $ancestor = $row['id']; $depth = 0;
                while ($ancestor !== null) { $insert->execute([$ancestor,$row['id'],$depth++]); $ancestor = $byId[$ancestor]['nodo_padre']; }
            }
        }
        $db->commit();
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
};
