<?php

declare(strict_types=1);

use App\Core\Migrations;

return static function (PDO $db,Migrations $schema): void {
	// Los conflictos se rechazan antes del DDL; no se elimina ni reubica historia.
	$checks=[
		'Participación de otra organización'=>'SELECT pp.id FROM personas_procesos pp JOIN personas p ON p.id=pp.persona JOIN procesos_electorales pe ON pe.id=pp.proceso WHERE p.organizacion<>pe.organizacion LIMIT 1',
		'Interacciones de otro contexto'=>'SELECT i.id FROM interacciones i JOIN personas_procesos pp ON pp.id=i.persona_proceso JOIN personas p ON p.id=pp.persona JOIN procesos_electorales pe ON pe.id=pp.proceso WHERE p.organizacion<>pe.organizacion LIMIT 1',
		'Aplicaciones de encuesta de otra elección'=>'SELECT a.id FROM encuestas_aplicaciones a JOIN personas_procesos pp ON pp.id=a.persona_proceso JOIN encuestas e ON e.id=a.encuesta WHERE pp.proceso<>e.proceso LIMIT 1',
		'Respuestas incompatibles'=>'SELECT r.id FROM encuestas_respuestas r JOIN encuestas_aplicaciones a ON a.id=r.aplicacion JOIN encuestas_preguntas q ON q.id=r.pregunta LEFT JOIN encuestas_opciones o ON o.id=r.opcion WHERE q.encuesta<>a.encuesta OR (r.opcion IS NOT NULL AND o.pregunta<>q.id) LIMIT 1',
		'Asistencia de otra elección'=>'SELECT a.evento FROM eventos_asistentes a JOIN eventos e ON e.id=a.evento JOIN personas_procesos pp ON pp.id=a.persona_proceso WHERE e.proceso<>pp.proceso LIMIT 1',
		'Membresía huérfana'=>'SELECT cu.conversacion FROM conversaciones_usuarios cu LEFT JOIN conversaciones c ON c.id=cu.conversacion LEFT JOIN usuarios u ON u.id=cu.usuario LEFT JOIN mensajes m ON m.id=cu.ultimo_mensaje_leido WHERE c.id IS NULL OR u.id IS NULL OR (cu.ultimo_mensaje_leido IS NOT NULL AND (m.id IS NULL OR m.conversacion<>cu.conversacion)) LIMIT 1',
		'Membresía de otra organización'=>"SELECT cu.conversacion FROM conversaciones_usuarios cu JOIN conversaciones c ON c.id=cu.conversacion JOIN procesos_electorales pe ON pe.id=c.proceso JOIN usuarios u ON u.id=cu.usuario JOIN usuarios_tipos t ON t.id=u.tipo_usuario WHERE u.organizacion<>pe.organizacion AND t.codigo<>'superadmin' LIMIT 1",
		'Remitente histórico incompatible'=>"SELECT m.id FROM mensajes m JOIN conversaciones c ON c.id=m.conversacion JOIN procesos_electorales pe ON pe.id=c.proceso JOIN usuarios u ON u.id=m.usuario JOIN usuarios_tipos t ON t.id=u.tipo_usuario WHERE u.organizacion<>pe.organizacion AND t.codigo<>'superadmin' LIMIT 1",
		'Capturista histórico de encuesta incompatible'=>"SELECT a.id FROM encuestas_aplicaciones a JOIN encuestas e ON e.id=a.encuesta JOIN procesos_electorales pe ON pe.id=e.proceso JOIN usuarios u ON u.id=a.aplicado_por JOIN usuarios_tipos t ON t.id=u.tipo_usuario WHERE u.organizacion<>pe.organizacion AND t.codigo<>'superadmin' LIMIT 1",
		'Asignado histórico de tarea incompatible'=>"SELECT r.id FROM tareas r JOIN procesos_electorales pe ON pe.id=r.proceso JOIN usuarios u ON u.id=r.asignado_a JOIN usuarios_tipos t ON t.id=u.tipo_usuario WHERE u.organizacion<>pe.organizacion AND t.codigo<>'superadmin' LIMIT 1",
		'Capturista de asistencia huérfano'=>'SELECT a.evento FROM eventos_asistentes a LEFT JOIN usuarios u ON u.id=a.registrado_por WHERE u.id IS NULL LIMIT 1',
	];
	foreach (['tareas'=>'creado_por','eventos'=>'creado_por','incidencias'=>'reportado_por','conversaciones'=>'creado_por','metas'=>'asignado_por'] as $table=>$creator) $checks['Actor histórico incompatible: '.$table]="SELECT r.id FROM $table r JOIN procesos_electorales pe ON pe.id=r.proceso JOIN usuarios u ON u.id=r.$creator JOIN usuarios_tipos t ON t.id=u.tipo_usuario WHERE u.organizacion<>pe.organizacion AND t.codigo<>'superadmin' LIMIT 1";
	$checks['Responsable histórico de incidencia incompatible']="SELECT r.id FROM incidencias r JOIN procesos_electorales pe ON pe.id=r.proceso JOIN usuarios u ON u.id=r.asignado_a JOIN usuarios_tipos t ON t.id=u.tipo_usuario WHERE u.organizacion<>pe.organizacion AND t.codigo<>'superadmin' LIMIT 1";
	$checks['Estructura histórica de meta incompatible']='SELECT m.id FROM metas m JOIN estructura e ON e.id=m.estructura JOIN personas p ON p.id=e.persona JOIN procesos_electorales pe ON pe.id=m.proceso WHERE p.organizacion<>pe.organizacion LIMIT 1';
	$checks['Tareas de otra elección']='SELECT t.id FROM tareas t JOIN personas_procesos pp ON pp.id=t.persona_proceso WHERE t.proceso<>pp.proceso LIMIT 1';
	foreach ($checks as $message=>$query) if ($db->query($query)->fetchColumn()!==false) throw new RuntimeException($message.'. Revisar los datos antes de migrar.');
	$add=static function(string $table,array $columns) use($db,$schema): void {
		foreach ($columns as $name=>$definition) if (!$schema->columnExists($table,$name)) $db->exec("ALTER TABLE $table ADD $name $definition");
	};
	$context=['uuid'=>'BINARY(16) NULL','organizacion'=>'INT NULL','persona'=>'INT NULL','estado'=>"VARCHAR(24) NOT NULL DEFAULT 'pendiente'",'version'=>'INT NOT NULL DEFAULT 1'];
	$add('interacciones',[...$context,'proceso'=>'INT NULL','tarea'=>'INT NULL']);
	$add('encuestas',[...$context,'serie'=>'INT NULL','numero_version'=>'INT NOT NULL DEFAULT 1','creado_por'=>'INT NULL','fecha'=>'DATETIME NULL','publicada_en'=>'DATETIME NULL','inicio'=>'DATETIME NULL','fin'=>'DATETIME NULL','frecuencia'=>"VARCHAR(12) NOT NULL DEFAULT 'unica'",'intervalo'=>'INT NOT NULL DEFAULT 1','unidad'=>"VARCHAR(12) NOT NULL DEFAULT 'dias'",'aprobacion'=>'INT NULL']);
	$add('encuestas_preguntas',['codigo'=>'VARCHAR(60) NULL','configuracion'=>'JSON NULL']);
	$add('encuestas_aplicaciones',['uuid'=>'BINARY(16) NULL','persona_encuestada'=>'INT NULL','capturado_por'=>'INT NULL','modalidad'=>"VARCHAR(16) NOT NULL DEFAULT 'historica'",'estado'=>"VARCHAR(16) NOT NULL DEFAULT 'enviada'",'enviada_en'=>'DATETIME NULL','revision'=>'INT NOT NULL DEFAULT 1']);
	$add('tareas',[...$context,'modalidad'=>"VARCHAR(16) NOT NULL DEFAULT 'individual'",'fecha_inicio'=>'DATETIME NULL','requiere_validacion'=>'TINYINT NOT NULL DEFAULT 0','aprobador'=>'INT NULL','recurrencia'=>'JSON NULL','serie_recurrente'=>'INT NULL','ocurrencia'=>'DATE NULL','relacion_entidad'=>'VARCHAR(40) NULL','relacion_id'=>'INT NULL','permite_pdf'=>'TINYINT NOT NULL DEFAULT 0']);
	$db->exec('ALTER TABLE tareas MODIFY asignado_a INT NULL');
	$add('metas',[...$context,'titulo'=>'VARCHAR(180) NULL','origen'=>"VARCHAR(12) NOT NULL DEFAULT 'manual'",'indicador'=>"VARCHAR(40) NOT NULL DEFAULT 'historico'",'modalidad'=>"VARCHAR(16) NOT NULL DEFAULT 'equipo'",'responsable'=>'INT NULL','padre'=>'INT NULL','progreso_manual'=>'INT NOT NULL DEFAULT 0','fecha'=>'DATETIME NULL']);
	$db->exec('ALTER TABLE metas MODIFY estructura INT NULL');
	$add('eventos',[...$context,'tipo_operativo'=>'INT NULL','control_participantes'=>"VARCHAR(16) NOT NULL DEFAULT 'individual'",'permite_externos'=>'TINYINT NOT NULL DEFAULT 0','cantidad_asistentes'=>'INT NULL','responsable'=>'INT NULL','notas'=>'TEXT NULL','qr_habilitado'=>'TINYINT NOT NULL DEFAULT 0']);
	$add('incidencias',[...$context,'folio'=>'VARCHAR(40) NULL','responsable'=>'INT NULL','evento'=>'INT NULL','requiere_validacion'=>'TINYINT NOT NULL DEFAULT 0','aprobador'=>'INT NULL','aprobacion'=>'INT NULL','resolucion'=>'TEXT NULL','permisos_capturista'=>'JSON NULL','permite_pdf'=>'TINYINT NOT NULL DEFAULT 0','categoria'=>'VARCHAR(100) NULL']);
	$add('personas_procesos',['necesidad_transporte'=>"VARCHAR(16) NULL"]);
	$add('conversaciones',['organizacion'=>'INT NULL','estado'=>"VARCHAR(16) NOT NULL DEFAULT 'activa'",'anuncios'=>'TINYINT NOT NULL DEFAULT 0','estructura'=>'INT NULL','nivel'=>'INT NULL','integrante_raiz'=>'INT NULL','modo_estructura'=>'VARCHAR(16) NULL','clave_directa'=>'VARCHAR(80) CHARACTER SET ascii NULL','version'=>'INT NOT NULL DEFAULT 1','permite_pdf'=>'TINYINT NOT NULL DEFAULT 0']);
	$add('conversaciones_usuarios',['administrador'=>'TINYINT NOT NULL DEFAULT 0','visible_desde_id'=>'INT NOT NULL DEFAULT 0','incorporado_en'=>'DATETIME NULL','silenciada'=>'TINYINT NOT NULL DEFAULT 0','silenciada_hasta'=>'DATETIME NULL','leido_en'=>'DATETIME NULL']);
	$add('mensajes',['uuid'=>'BINARY(16) NULL','idempotencia'=>'VARCHAR(80) CHARACTER SET ascii NULL','moderado_por'=>'INT NULL','moderado_en'=>'DATETIME NULL','motivo_moderacion'=>'VARCHAR(500) NULL']);
	$tables=[
		'interacciones_procesos'=>"interaccion INT NOT NULL, proceso INT NOT NULL, PRIMARY KEY(interaccion,proceso), INDEX(proceso,interaccion), FOREIGN KEY(interaccion) REFERENCES interacciones(id), FOREIGN KEY(proceso) REFERENCES procesos_electorales(id)",
		'tareas_asignaciones'=>"id INT AUTO_INCREMENT PRIMARY KEY, uuid BINARY(16) NOT NULL UNIQUE, tarea INT NOT NULL, persona INT NULL, usuario_historico INT NULL, estado VARCHAR(24) NOT NULL DEFAULT 'pendiente', version INT NOT NULL DEFAULT 1, aprobacion INT NULL, completada_en DATETIME NULL, UNIQUE(tarea,persona), INDEX(persona,estado,tarea), FOREIGN KEY(tarea) REFERENCES tareas(id), FOREIGN KEY(persona) REFERENCES personas(id), FOREIGN KEY(usuario_historico) REFERENCES usuarios(id), FOREIGN KEY(aprobacion) REFERENCES aprobaciones(id)",
		'tareas_subtareas'=>"id INT AUTO_INCREMENT PRIMARY KEY, uuid BINARY(16) NOT NULL UNIQUE, tarea INT NOT NULL, titulo VARCHAR(200) NOT NULL, persona INT NULL, estado VARCHAR(24) NOT NULL DEFAULT 'pendiente', version INT NOT NULL DEFAULT 1, aprobacion INT NULL, completada_en DATETIME NULL, INDEX(tarea,estado,id), FOREIGN KEY(tarea) REFERENCES tareas(id), FOREIGN KEY(persona) REFERENCES personas(id), FOREIGN KEY(aprobacion) REFERENCES aprobaciones(id)",
		'operaciones_historial'=>"id INT AUTO_INCREMENT PRIMARY KEY, organizacion INT NOT NULL, proceso INT NOT NULL, entidad VARCHAR(40) NOT NULL, registro INT NOT NULL, usuario INT NOT NULL, accion VARCHAR(40) NOT NULL, nota TEXT NOT NULL, fecha DATETIME NOT NULL, INDEX(entidad,registro,id), FOREIGN KEY(organizacion) REFERENCES organizaciones(id), FOREIGN KEY(proceso) REFERENCES procesos_electorales(id), FOREIGN KEY(usuario) REFERENCES usuarios(id)",
		'encuestas_correcciones'=>"id INT AUTO_INCREMENT PRIMARY KEY, aplicacion INT NOT NULL, usuario INT NOT NULL, motivo VARCHAR(500) NOT NULL, antes JSON NOT NULL, despues JSON NOT NULL, fecha DATETIME NOT NULL, INDEX(aplicacion,id), FOREIGN KEY(aplicacion) REFERENCES encuestas_aplicaciones(id), FOREIGN KEY(usuario) REFERENCES usuarios(id)",
		'eventos_tipos'=>"id INT AUTO_INCREMENT PRIMARY KEY, uuid BINARY(16) NOT NULL UNIQUE, organizacion INT NOT NULL, proceso INT NOT NULL, nombre VARCHAR(100) NOT NULL, activo TINYINT NOT NULL DEFAULT 1, UNIQUE(proceso,nombre), FOREIGN KEY(organizacion) REFERENCES organizaciones(id), FOREIGN KEY(proceso) REFERENCES procesos_electorales(id)",
		'eventos_participantes'=>"id INT AUTO_INCREMENT PRIMARY KEY, uuid BINARY(16) NOT NULL UNIQUE, evento INT NOT NULL, persona_proceso INT NULL, nombre_externo VARCHAR(180) NULL, contacto_externo VARCHAR(150) NULL, estado VARCHAR(16) NOT NULL DEFAULT 'invitado', registrado_por INT NOT NULL, fecha DATETIME NOT NULL, asistencia_en DATETIME NULL, UNIQUE(evento,persona_proceso), INDEX(evento,estado,id), FOREIGN KEY(evento) REFERENCES eventos(id), FOREIGN KEY(persona_proceso) REFERENCES personas_procesos(id), FOREIGN KEY(registrado_por) REFERENCES usuarios(id)",
		'eventos_responsables'=>"evento INT NOT NULL, persona INT NOT NULL, PRIMARY KEY(evento,persona), FOREIGN KEY(evento) REFERENCES eventos(id), FOREIGN KEY(persona) REFERENCES personas(id)",
		'peticiones'=>"id INT AUTO_INCREMENT PRIMARY KEY, uuid BINARY(16) NOT NULL UNIQUE, organizacion INT NOT NULL, proceso INT NOT NULL, persona INT NULL, solicitante_externo VARCHAR(180) NULL, contacto_externo VARCHAR(150) NULL, capturado_por INT NOT NULL, categoria VARCHAR(100) NOT NULL, descripcion TEXT NOT NULL, prioridad SMALLINT NOT NULL, estado VARCHAR(24) NOT NULL DEFAULT 'abierta', responsable INT NULL, fecha DATETIME NOT NULL, resolucion TEXT NULL, folio VARCHAR(40) NOT NULL, version INT NOT NULL DEFAULT 1, requiere_validacion TINYINT NOT NULL DEFAULT 0, aprobador INT NULL, aprobacion INT NULL, permisos_capturista JSON NULL, permite_pdf TINYINT NOT NULL DEFAULT 0, UNIQUE(organizacion,folio), INDEX(proceso,responsable,estado,id), FOREIGN KEY(organizacion) REFERENCES organizaciones(id), FOREIGN KEY(proceso) REFERENCES procesos_electorales(id), FOREIGN KEY(persona) REFERENCES personas(id), FOREIGN KEY(capturado_por) REFERENCES usuarios(id), FOREIGN KEY(responsable) REFERENCES personas(id), FOREIGN KEY(prioridad) REFERENCES tareas_prioridades(id), FOREIGN KEY(aprobador) REFERENCES usuarios(id), FOREIGN KEY(aprobacion) REFERENCES aprobaciones(id)",
		'operaciones_personas'=>"entidad VARCHAR(40) NOT NULL, registro INT NOT NULL, persona INT NOT NULL, PRIMARY KEY(entidad,registro,persona), INDEX(persona,entidad,registro), FOREIGN KEY(persona) REFERENCES personas(id)",
		'operaciones_folios'=>"organizacion INT NOT NULL, entidad VARCHAR(40) NOT NULL, anio INT NOT NULL, consecutivo INT NOT NULL DEFAULT 0, PRIMARY KEY(organizacion,entidad,anio), FOREIGN KEY(organizacion) REFERENCES organizaciones(id)",
		'transporte'=>"id INT AUTO_INCREMENT PRIMARY KEY, uuid BINARY(16) NOT NULL UNIQUE, organizacion INT NOT NULL, proceso INT NOT NULL, persona INT NOT NULL, persona_proceso INT NOT NULL UNIQUE, coordinador INT NULL, proveedor INT NULL, proveedor_externo VARCHAR(180) NULL, observaciones_logisticas TEXT NULL, fecha DATETIME NOT NULL, editado_por INT NOT NULL, confirmado_por INT NULL, confirmado_en DATETIME NULL, observacion_confirmacion VARCHAR(500) NULL, version INT NOT NULL DEFAULT 1, INDEX(proceso,coordinador,id), FOREIGN KEY(organizacion) REFERENCES organizaciones(id), FOREIGN KEY(proceso) REFERENCES procesos_electorales(id), FOREIGN KEY(persona) REFERENCES personas(id), FOREIGN KEY(persona_proceso) REFERENCES personas_procesos(id), FOREIGN KEY(coordinador) REFERENCES personas(id), FOREIGN KEY(proveedor) REFERENCES personas(id), FOREIGN KEY(editado_por) REFERENCES usuarios(id), FOREIGN KEY(confirmado_por) REFERENCES usuarios(id)",
		'mensajes_lecturas'=>"mensaje INT NOT NULL, usuario INT NOT NULL, leido_en DATETIME NOT NULL, PRIMARY KEY(mensaje,usuario), INDEX(usuario,mensaje), FOREIGN KEY(mensaje) REFERENCES mensajes(id), FOREIGN KEY(usuario) REFERENCES usuarios(id)",
		'mensajes_destinatarios'=>"mensaje INT NOT NULL, usuario INT NOT NULL, PRIMARY KEY(mensaje,usuario), INDEX(usuario,mensaje), FOREIGN KEY(mensaje) REFERENCES mensajes(id), FOREIGN KEY(usuario) REFERENCES usuarios(id)",
		'mensajes_avisos'=>"mensaje INT NOT NULL, usuario INT NOT NULL, notificacion BINARY(16) NOT NULL, PRIMARY KEY(mensaje,usuario), FOREIGN KEY(mensaje) REFERENCES mensajes(id), FOREIGN KEY(usuario) REFERENCES usuarios(id), FOREIGN KEY(notificacion) REFERENCES notificaciones(uuid)",
		'mensajeria_politicas'=>"proceso INT PRIMARY KEY, politica VARCHAR(16) NOT NULL DEFAULT 'coordinacion', editado_por INT NOT NULL, fecha DATETIME NOT NULL, FOREIGN KEY(proceso) REFERENCES procesos_electorales(id), FOREIGN KEY(editado_por) REFERENCES usuarios(id)",
	];
	foreach ($tables as $name=>$columns) $db->exec("CREATE TABLE IF NOT EXISTS $name ($columns) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
	// UUID y nuevos campos se rellenan por lotes, manteniendo todas las columnas originales.
	foreach (['interacciones','encuestas','tareas','metas','eventos','incidencias','encuestas_aplicaciones','mensajes'] as $table) {
		$last=0;
		do {
			$rows=$db->query("SELECT id FROM $table WHERE uuid IS NULL AND id>$last ORDER BY id LIMIT 100")->fetchAll(PDO::FETCH_COLUMN);
			foreach ($rows as $id) { $last=(int) $id; $db->prepare("UPDATE $table SET uuid=? WHERE id=? AND uuid IS NULL")->execute([random_bytes(16),$id]); }
		} while ($rows!==[]);
		if (!$schema->indexExists($table,'uq_operativo_uuid')) $db->exec("ALTER TABLE $table ADD UNIQUE KEY uq_operativo_uuid(uuid)");
		$db->exec("ALTER TABLE $table MODIFY uuid BINARY(16) NOT NULL");
	}
	$db->exec('UPDATE interacciones i JOIN personas_procesos pp ON pp.id=i.persona_proceso JOIN procesos_electorales pe ON pe.id=pp.proceso SET i.persona=pp.persona,i.proceso=pp.proceso,i.organizacion=pe.organizacion WHERE i.organizacion IS NULL');
	$db->exec('INSERT IGNORE INTO interacciones_procesos SELECT i.id,pp.proceso FROM interacciones i JOIN personas_procesos pp ON pp.id=i.persona_proceso');
	foreach (['encuestas','tareas','metas','eventos','incidencias','conversaciones'] as $table) $db->exec("UPDATE $table r JOIN procesos_electorales p ON p.id=r.proceso SET r.organizacion=p.organizacion WHERE r.organizacion IS NULL");
	$db->exec("UPDATE encuestas SET serie=id,estado=IF(activo=1,'publicada','archivada') WHERE serie IS NULL");
	$db->exec("UPDATE encuestas_preguntas SET codigo=CONCAT('p',id) WHERE codigo IS NULL");
	$db->exec('UPDATE encuestas_aplicaciones a JOIN personas_procesos pp ON pp.id=a.persona_proceso SET a.persona_encuestada=pp.persona,a.capturado_por=a.aplicado_por,a.enviada_en=a.f_aplicacion WHERE a.persona_encuestada IS NULL');
	$db->exec('UPDATE tareas t LEFT JOIN personas_procesos pp ON pp.id=t.persona_proceso SET t.persona=pp.persona WHERE t.persona IS NULL');
	$db->exec('UPDATE incidencias i LEFT JOIN usuarios u ON u.id=i.asignado_a SET i.responsable=u.persona,categoria=COALESCE(categoria,\'Histórica\') WHERE i.folio IS NULL');
	$db->exec("UPDATE incidencias i JOIN incidencias_estatus s ON s.id=i.estatus SET i.estado=CASE s.codigo WHEN 'RESUELTA' THEN 'cerrada' WHEN 'DESCARTADA' THEN 'cancelada' WHEN 'EN_ATENCION' THEN 'en_atencion' ELSE 'abierta' END WHERE i.folio IS NULL");
	$db->exec("UPDATE metas m JOIN metas_tipos t ON t.id=m.tipo SET m.titulo=CONCAT('Meta histórica: ',t.tipo),m.estado='activa' WHERE m.titulo IS NULL");
	$db->exec("UPDATE metas m JOIN estructura e ON e.id=m.estructura SET m.responsable=e.persona,m.persona=e.persona WHERE m.responsable IS NULL AND m.indicador='historico'");
	$db->exec("UPDATE eventos SET estado='programado' WHERE estado='pendiente'");
	$db->exec("UPDATE personas_procesos SET necesidad_transporte=IF(requiere_transporte=1,'requiere','no_definido') WHERE necesidad_transporte IS NULL");
	$db->exec("ALTER TABLE personas_procesos MODIFY necesidad_transporte VARCHAR(16) NOT NULL DEFAULT 'no_definido'");
	$db->exec('UPDATE conversaciones_usuarios cu JOIN conversaciones c ON c.id=cu.conversacion SET cu.administrador=IF(cu.usuario=c.creado_por,1,0),cu.incorporado_en=c.f_registro WHERE cu.incorporado_en IS NULL');
	// Las proyecciones adicionales preservan estados y referencias históricos.
	do {
		$rows=$db->query('SELECT t.*,u.persona asignado_persona,e.codigo FROM tareas t JOIN tareas_estatus e ON e.id=t.estatus LEFT JOIN usuarios u ON u.id=t.asignado_a WHERE t.asignado_a IS NOT NULL AND NOT EXISTS (SELECT 1 FROM tareas_asignaciones a WHERE a.tarea=t.id) ORDER BY t.id LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
		foreach ($rows as $row) {
			$state=strtolower($row['codigo']); $db->prepare('INSERT INTO tareas_asignaciones (uuid,tarea,persona,usuario_historico,estado,completada_en) VALUES (?,?,?,?,?,?)')->execute([random_bytes(16),$row['id'],$row['asignado_persona'],$row['asignado_a'],$state,$row['f_finalizacion']]);
			$db->prepare('UPDATE tareas SET estado=? WHERE id=?')->execute([$state,$row['id']]);
		}
	} while (count($rows)===100);
	do {
		$rows=$db->query('SELECT a.* FROM eventos_asistentes a WHERE NOT EXISTS (SELECT 1 FROM eventos_participantes n WHERE n.evento=a.evento AND n.persona_proceso=a.persona_proceso) ORDER BY a.evento,a.persona_proceso LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
		foreach ($rows as $row) $db->prepare('INSERT INTO eventos_participantes (uuid,evento,persona_proceso,estado,registrado_por,fecha) VALUES (?,?,?,?,?,NOW())')->execute([random_bytes(16),$row['evento'],$row['persona_proceso'],$row['asistio'] ? 'asistio' : ($row['confirmado'] ? 'confirmado' : 'invitado'),$row['registrado_por']]);
	} while (count($rows)===100);
	$indexes=[
		'interacciones'=>['ix_interaccion_cronologia'=>'(persona,f_interaccion,id)'],
		'encuestas'=>['uq_encuesta_version'=>'UNIQUE (serie,numero_version)','ix_encuesta_contexto'=>'(proceso,estado,id)'],
		'encuestas_preguntas'=>['uq_pregunta_codigo'=>'UNIQUE (encuesta,codigo)'],
		'encuestas_aplicaciones'=>['ix_encuesta_frecuencia'=>'(persona_encuestada,estado,enviada_en,encuesta)','ix_encuesta_resultados'=>'(encuesta,estado,id)'],
		'encuestas_respuestas'=>['ix_respuesta_pregunta'=>'(pregunta,aplicacion)'],
		'tareas'=>['ix_tarea_contexto'=>'(proceso,estado,id)','uq_tarea_ocurrencia'=>'UNIQUE (serie_recurrente,ocurrencia)'],
		'metas'=>['ix_meta_padre'=>'(padre,id)','ix_meta_contexto'=>'(proceso,fecha_inicio,fecha_fin,id)'],
		'eventos'=>['ix_evento_contexto'=>'(proceso,fecha_inicio,id)'],
		'incidencias'=>['ix_incidencia_contexto'=>'(proceso,responsable,estado,id)','uq_incidencia_folio'=>'UNIQUE (organizacion,folio)'],
		'conversaciones'=>['uq_conversacion_directa'=>'UNIQUE (proceso,clave_directa)','ix_conversacion_contexto'=>'(proceso,id)'],
		'conversaciones_usuarios'=>['ix_conversacion_usuario'=>'(usuario,activo,conversacion)'],
		'mensajes'=>['uq_mensaje_idempotente'=>'UNIQUE (conversacion,usuario,idempotencia)'],
		'personas_procesos'=>['ix_necesidad_transporte'=>'(proceso,necesidad_transporte,id)'],
	];
	foreach ($indexes as $table=>$definitions) foreach ($definitions as $name=>$definition) if (!$schema->indexExists($table,$name)) { $unique=str_starts_with($definition,'UNIQUE '); $columns=$unique ? substr($definition,7) : $definition; $db->exec("ALTER TABLE $table ADD ".($unique ? 'UNIQUE KEY ' : 'INDEX ')."$name $columns"); }
	// Folios de historia: prefijo reservado, sin consumir el contador de nuevas solicitudes.
	$db->exec("UPDATE incidencias SET folio=CONCAT('INC-H-',SUBSTRING(HEX(uuid),1,12)) WHERE folio IS NULL");
	$foreign=[
		'interacciones'=>['organizacion'=>'organizaciones','proceso'=>'procesos_electorales','persona'=>'personas','tarea'=>'tareas'],
		'encuestas'=>['organizacion'=>'organizaciones','serie'=>'encuestas','creado_por'=>'usuarios','aprobacion'=>'aprobaciones'],
		'encuestas_aplicaciones'=>['persona_encuestada'=>'personas','capturado_por'=>'usuarios'],
		'tareas'=>['organizacion'=>'organizaciones','persona'=>'personas','aprobador'=>'usuarios','serie_recurrente'=>'tareas'],
		'metas'=>['organizacion'=>'organizaciones','persona'=>'personas','responsable'=>'personas','padre'=>'metas'],
		'eventos'=>['organizacion'=>'organizaciones','responsable'=>'personas','tipo_operativo'=>'eventos_tipos'],
		'incidencias'=>['organizacion'=>'organizaciones','persona'=>'personas','responsable'=>'personas','evento'=>'eventos','aprobador'=>'usuarios','aprobacion'=>'aprobaciones'],
		'conversaciones'=>['organizacion'=>'organizaciones','estructura'=>'estructuras_organizacionales','nivel'=>'niveles_organizacionales','integrante_raiz'=>'estructura'],
		'mensajes'=>['moderado_por'=>'usuarios'],
		'conversaciones_usuarios'=>['conversacion'=>'conversaciones','usuario'=>'usuarios','ultimo_mensaje_leido'=>'mensajes'],
	];
	foreach ($foreign as $table=>$columns) foreach ($columns as $column=>$target) {
		$name='fk_op_'.$table.'_'.$column;
		$statement=$db->prepare('SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND constraint_name=?'); $statement->execute([$name]);
		if (!$statement->fetchColumn()) $db->exec("ALTER TABLE $table ADD CONSTRAINT $name FOREIGN KEY ($column) REFERENCES $target(id)");
	}
	$db->exec("INSERT IGNORE INTO modulos VALUES ('interacciones','Interacciones',0,1)");
	$db->exec("UPDATE modulos SET implementado=1 WHERE codigo IN ('mensajeria','encuestas','tareas_metas','eventos','peticiones_incidencias','transporte')");
	$actions=[
		'interacciones'=>['ver','crear'], 'encuestas'=>['ver','crear','publicar','aprobar','aplicar','ver_resultados','administrar','corregir'],
		'tareas'=>['ver','crear','editar','completar','validar'], 'metas'=>['ver','crear','editar','registrar'],
		'eventos'=>['ver','crear','editar','asistencia','administrar'],
		'peticiones'=>['ver','crear','editar','comentar','transferir','cancelar','cerrar','validar'],
		'incidencias'=>['ver','crear','editar','comentar','transferir','cancelar','cerrar','validar'],
		'transporte'=>['ver','editar','confirmar'],
		'mensajes'=>['ver','enviar','adjuntar_archivos','crear_grupos','administrar_grupos','miembros','crear_grupos_estructura','comunicacion_fuera_equipo','administracion_global','moderar'],
	];
	$modules=['interacciones'=>'interacciones','encuestas'=>'encuestas','tareas'=>'tareas_metas','metas'=>'tareas_metas','eventos'=>'eventos','peticiones'=>'peticiones_incidencias','incidencias'=>'peticiones_incidencias','transporte'=>'transporte','mensajes'=>'mensajeria'];
	$insert=$db->prepare('INSERT IGNORE INTO permisos (id,permiso,descripcion,ambito,modulo,f_registro) VALUES (?,?,?,\'electoral\',?,NOW())');
	$grant=$db->prepare('INSERT INTO permisos_usuarios_tipo (uuid,permiso,tipo,valor,alcance,f_actualizacion) SELECT ?,?,2,1,\'eleccion\',NOW() WHERE NOT EXISTS (SELECT 1 FROM permisos_usuarios_tipo WHERE tipo=2 AND permiso=? AND proceso IS NULL)');
	foreach ($actions as $entity=>$codes) foreach ($codes as $action) {
		$code=$entity.'.'.$action; $label=ucfirst(str_replace('_',' ',$action)).' '.($entity==='mensajes' ? 'mensajes' : $entity);
		$insert->execute([$code,$label,$label,$modules[$entity]]);
		if (!in_array($code,['mensajes.comunicacion_fuera_equipo','mensajes.administracion_global'],true)) $grant->execute([random_bytes(16),$code,$code]);
	}
};
