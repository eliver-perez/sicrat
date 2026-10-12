<?php

declare(strict_types=1);

use App\Core\Migrations;

return static function (PDO $db, Migrations $migration): void {
    $conflicts = $db->query('SELECT persona FROM usuarios WHERE persona IS NOT NULL GROUP BY persona HAVING COUNT(*)>1')->fetchAll(PDO::FETCH_COLUMN);
    if ($conflicts !== []) throw new RuntimeException('Hay personas vinculadas a varias cuentas. Resolver manualmente antes de migrar. IDs: ' . implode(', ', $conflicts));
    if (!$migration->indexExists('usuarios', 'uq_usuarios_persona')) $db->exec('ALTER TABLE usuarios ADD UNIQUE KEY uq_usuarios_persona (persona)');

    // Se amplían las tres columnas juntas; no se cambian identificadores existentes.
    foreach (['permisos_usuarios' => 'FK_permisosusuarios_permiso', 'permisos_usuarios_tipo' => 'FK_permisosusuariostipo_permiso'] as $table => $constraint) {
        $statement = $db->prepare('SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name=? AND constraint_name=?');
        $statement->execute([$table, $constraint]);
        if ($statement->fetchColumn()) $db->exec("ALTER TABLE $table DROP FOREIGN KEY $constraint");
    }
    $db->exec('ALTER TABLE permisos MODIFY id VARCHAR(128) NOT NULL');
    $db->exec('ALTER TABLE permisos_usuarios MODIFY permiso VARCHAR(128) NOT NULL');
    $db->exec('ALTER TABLE permisos_usuarios_tipo MODIFY permiso VARCHAR(128) NOT NULL');
    $db->exec('ALTER TABLE permisos_usuarios ADD CONSTRAINT FK_permisosusuarios_permiso FOREIGN KEY (permiso) REFERENCES permisos(id)');
    $db->exec('ALTER TABLE permisos_usuarios_tipo ADD CONSTRAINT FK_permisosusuariostipo_permiso FOREIGN KEY (permiso) REFERENCES permisos(id)');
    $db->exec('ALTER TABLE usuarios_tipos MODIFY tipo VARCHAR(128) NOT NULL');
    if (!$migration->columnExists('usuarios_tipos', 'organizacion')) $db->exec('ALTER TABLE usuarios_tipos ADD organizacion INT NULL, ADD CONSTRAINT fk_roles_organizacion FOREIGN KEY (organizacion) REFERENCES organizaciones(id)');
    if (!$migration->columnExists('permisos', 'ambito')) $db->exec("ALTER TABLE permisos ADD ambito VARCHAR(12) NOT NULL DEFAULT 'ambos', ADD modulo VARCHAR(64) NULL");
    foreach (['permisos_usuarios', 'permisos_usuarios_tipo'] as $table) {
        if (!$migration->columnExists($table, 'proceso')) $db->exec("ALTER TABLE $table ADD proceso INT NULL, ADD alcance VARCHAR(24) NOT NULL DEFAULT 'organizacion', ADD editado_por INT NULL, ADD CONSTRAINT fk_{$table}_proceso FOREIGN KEY (proceso) REFERENCES procesos_electorales(id), ADD CONSTRAINT fk_{$table}_editor FOREIGN KEY (editado_por) REFERENCES usuarios(id), ADD KEY ix_{$table}_contexto (proceso,permiso)");
        $defaultContext = $table === 'permisos_usuarios_tipo' ? 'plantilla' : 'global';
        if (!$migration->columnExists($table, 'contexto')) $db->exec("ALTER TABLE $table ADD contexto VARCHAR(12) NOT NULL DEFAULT '$defaultContext'");
    }
    $db->exec("CREATE TABLE IF NOT EXISTS usuarios_territorios (
        usuario INT NOT NULL, proceso INT NOT NULL, seccion INT NOT NULL, editado_por INT NOT NULL, fecha DATETIME NOT NULL,
        PRIMARY KEY (usuario,proceso,seccion), FOREIGN KEY (usuario) REFERENCES usuarios(id), FOREIGN KEY (proceso) REFERENCES procesos_electorales(id),
        FOREIGN KEY (seccion) REFERENCES secciones_electorales(id), FOREIGN KEY (editado_por) REFERENCES usuarios(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS modulos (
        codigo VARCHAR(64) PRIMARY KEY, nombre VARCHAR(128) NOT NULL, esencial TINYINT NOT NULL DEFAULT 0, implementado TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS modulos_organizaciones (
        organizacion INT NOT NULL, modulo VARCHAR(64) NOT NULL, autorizado TINYINT NOT NULL DEFAULT 0, seleccionado TINYINT NOT NULL DEFAULT 0,
        autorizado_por INT NULL, seleccionado_por INT NULL, fecha DATETIME NOT NULL,
        PRIMARY KEY (organizacion,modulo), FOREIGN KEY (organizacion) REFERENCES organizaciones(id), FOREIGN KEY (modulo) REFERENCES modulos(codigo),
        FOREIGN KEY (autorizado_por) REFERENCES usuarios(id), FOREIGN KEY (seleccionado_por) REFERENCES usuarios(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS modulos_procesos (
        proceso INT NOT NULL, modulo VARCHAR(64) NOT NULL, autorizado TINYINT NOT NULL DEFAULT 1, seleccionado TINYINT NOT NULL DEFAULT 0,
        autorizado_por INT NULL, seleccionado_por INT NULL, fecha DATETIME NOT NULL,
        PRIMARY KEY (proceso,modulo), FOREIGN KEY (proceso) REFERENCES procesos_electorales(id), FOREIGN KEY (modulo) REFERENCES modulos(codigo),
        FOREIGN KEY (autorizado_por) REFERENCES usuarios(id), FOREIGN KEY (seleccionado_por) REFERENCES usuarios(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $modules = ['organizacion' => ['Organización',1,1], 'usuarios' => ['Usuarios y autenticación',1,1], 'personas' => ['Personas',1,1], 'contexto' => ['Contexto electoral',1,1], 'seguridad' => ['Seguridad',1,1], 'estructura' => ['Estructura organizacional',0,1], 'mensajeria' => ['Mensajería',0,0], 'encuestas' => ['Encuestas',0,0], 'tareas_metas' => ['Tareas y metas',0,0], 'eventos' => ['Eventos',0,0], 'peticiones_incidencias' => ['Peticiones e incidencias',0,0], 'transporte' => ['Transporte',0,0], 'finanzas' => ['Finanzas',0,0], 'inventario' => ['Inventario',0,0], 'jornada_electoral' => ['Jornada Electoral',0,0]];
    $statement = $db->prepare('INSERT IGNORE INTO modulos VALUES (?,?,?,?)');
    foreach ($modules as $code => $values) $statement->execute([$code,...$values]);

    $permissions = [
        'personas.ver' => ['Ver personas','ambos',null], 'personas.crear' => ['Crear personas','ambos',null], 'personas.editar' => ['Editar personas','ambos',null],
        'personas.participar' => ['Administrar participaciones','ambos',null], 'personas.importar' => ['Importar personas','ambos',null],
        'estructura.ver' => ['Consultar estructura','electoral','estructura'], 'estructura.administrar' => ['Administrar estructuras','electoral','estructura'],
        'estructura.formar' => ['Incorporar integrantes','electoral','estructura'], 'estructura.transferir_integrante' => ['Transferir integrante','electoral','estructura'],
        'estructura.transferir_equipo' => ['Transferir equipo','electoral','estructura'], 'estructura.aprobar_transferencias' => ['Aprobar transferencias','electoral','estructura'],
        'niveles.administrar' => ['Configurar niveles','electoral','estructura'], 'usuarios.administrar' => ['Administrar cuentas y accesos','ambos',null],
        'permisos.administrar' => ['Configurar roles y permisos','ambos',null], 'modulos.seleccionar' => ['Seleccionar módulos autorizados','ambos',null],
    ];
    $statement = $db->prepare('INSERT IGNORE INTO permisos (id,permiso,descripcion,ambito,modulo,f_registro) VALUES (?,?,?,?,?,NOW())');
    foreach ($permissions as $code => [$name,$scope,$module]) $statement->execute([$code,$name,$name,$scope,$module]);
    $db->exec("UPDATE permisos SET ambito='global' WHERE id='superadmin'");
    // El rol administrador electoral recibe una base configurable, sin privilegio global.
    $statement = $db->prepare('INSERT INTO permisos_usuarios_tipo (uuid,permiso,tipo,valor,alcance,f_actualizacion) SELECT ?,?,2,1,?,NOW() WHERE NOT EXISTS (SELECT 1 FROM permisos_usuarios_tipo WHERE tipo=2 AND permiso=? AND proceso IS NULL)');
    foreach (array_keys($permissions) as $code) $statement->execute([random_bytes(16),$code,str_starts_with($code,'personas.') || $code==='usuarios.administrar' ? 'organizacion' : 'eleccion',$code]);
    // Conserva la administración de participaciones multielección existente sin conceder captura de fichas.
    $db->prepare("INSERT INTO permisos_usuarios_tipo (uuid,permiso,tipo,valor,alcance,f_actualizacion) SELECT ?,'personas.participar',3,1,'organizacion',NOW() WHERE NOT EXISTS (SELECT 1 FROM permisos_usuarios_tipo WHERE tipo=3 AND permiso='personas.participar' AND proceso IS NULL)")->execute([random_bytes(16)]);
};
