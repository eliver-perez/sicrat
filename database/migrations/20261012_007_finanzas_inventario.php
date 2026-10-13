<?php

declare(strict_types=1);

use App\Core\Migrations;

return static function(PDO $db,Migrations $schema): void {
	// La base anterior no contiene contabilidad ni inventario. No se inventan saldos históricos.
	$base="id INT AUTO_INCREMENT PRIMARY KEY, uuid BINARY(16) NOT NULL UNIQUE, organizacion INT NOT NULL, proceso INT NULL, creado_por INT NOT NULL, fecha DATETIME NOT NULL, version INT NOT NULL DEFAULT 1";
	$context="FOREIGN KEY(organizacion) REFERENCES organizaciones(id), FOREIGN KEY(proceso) REFERENCES procesos_electorales(id), FOREIGN KEY(creado_por) REFERENCES usuarios(id)";
	$document="$base, folio VARCHAR(40) NOT NULL, estado VARCHAR(24) NOT NULL, UNIQUE(organizacion,folio), INDEX(organizacion,proceso,estado,id)";
	$tables=[
		'recursos_control'=>"organizacion INT PRIMARY KEY, FOREIGN KEY(organizacion) REFERENCES organizaciones(id)",
		'recursos_folios'=>"organizacion INT NOT NULL, entidad VARCHAR(40) NOT NULL, anio INT NOT NULL, consecutivo INT NOT NULL DEFAULT 0, PRIMARY KEY(organizacion,entidad,anio), FOREIGN KEY(organizacion) REFERENCES organizaciones(id)",
		'recursos_historial'=>"id INT AUTO_INCREMENT PRIMARY KEY, organizacion INT NOT NULL, proceso INT NULL, entidad VARCHAR(40) NOT NULL, registro INT NOT NULL, usuario INT NOT NULL, accion VARCHAR(48) NOT NULL, motivo VARCHAR(500) NOT NULL, antes JSON NULL, despues JSON NULL, fecha DATETIME NOT NULL, INDEX(entidad,registro,id), FOREIGN KEY(organizacion) REFERENCES organizaciones(id), FOREIGN KEY(proceso) REFERENCES procesos_electorales(id), FOREIGN KEY(usuario) REFERENCES usuarios(id)",
		'finanzas_categorias'=>"$base, nombre VARCHAR(120) NOT NULL, descripcion VARCHAR(500) NULL, tipo VARCHAR(16) NOT NULL, activo TINYINT NOT NULL DEFAULT 1, UNIQUE(organizacion,tipo,nombre), $context",
		'finanzas_metodos'=>"$base, nombre VARCHAR(100) NOT NULL, tipo VARCHAR(16) NOT NULL, activo TINYINT NOT NULL DEFAULT 1, UNIQUE(organizacion,nombre), $context",
		'finanzas_cuentas'=>"$base, nombre VARCHAR(120) NOT NULL, descripcion VARCHAR(500) NULL, activo TINYINT NOT NULL DEFAULT 1, UNIQUE(organizacion,nombre), $context",
		'finanzas_politicas'=>"$base, contexto INT NOT NULL, nombre VARCHAR(120) NOT NULL, configuracion JSON NOT NULL, UNIQUE(organizacion,contexto), $context",
		'finanzas_presupuestos'=>"$document, nombre VARCHAR(180) NOT NULL, categoria INT NULL, evento INT NULL, inicio DATE NOT NULL, fin DATE NOT NULL, monto DECIMAL(14,2) NOT NULL, $context, FOREIGN KEY(categoria) REFERENCES finanzas_categorias(id), FOREIGN KEY(evento) REFERENCES eventos(id), CHECK(monto>=0)",
		'finanzas_presupuestos_procesos'=>"presupuesto INT NOT NULL, proceso INT NOT NULL, PRIMARY KEY(presupuesto,proceso), FOREIGN KEY(presupuesto) REFERENCES finanzas_presupuestos(id), FOREIGN KEY(proceso) REFERENCES procesos_electorales(id)",
		'finanzas_presupuestos_revisiones'=>"id INT AUTO_INCREMENT PRIMARY KEY, uuid BINARY(16) NOT NULL UNIQUE, presupuesto INT NOT NULL, transferencia BINARY(16) NULL, usuario INT NOT NULL, tipo VARCHAR(16) NOT NULL, motivo VARCHAR(500) NOT NULL, antes DECIMAL(14,2) NOT NULL, despues DECIMAL(14,2) NOT NULL, version INT NOT NULL, fecha DATETIME NOT NULL, UNIQUE(presupuesto,version), FOREIGN KEY(presupuesto) REFERENCES finanzas_presupuestos(id), FOREIGN KEY(usuario) REFERENCES usuarios(id)",
		'finanzas_gastos'=>"$document, tipo VARCHAR(16) NOT NULL DEFAULT 'ordinario', categoria INT NOT NULL, concepto VARCHAR(180) NOT NULL, descripcion TEXT NULL, beneficiario INT NULL, proveedor VARCHAR(180) NULL, monto DECIMAL(14,2) NOT NULL, fecha_operacion DATE NOT NULL, presupuesto INT NULL, comprometido TINYINT NOT NULL DEFAULT 0, distribucion VARCHAR(16) NOT NULL DEFAULT 'montos', aprobacion INT NULL, autorizacion JSON NULL, autorizado_en DATETIME NULL, relacion_entidad VARCHAR(40) NULL, relacion_id INT NULL, referencia VARCHAR(120) NULL, $context, FOREIGN KEY(categoria) REFERENCES finanzas_categorias(id), FOREIGN KEY(beneficiario) REFERENCES personas(id), FOREIGN KEY(presupuesto) REFERENCES finanzas_presupuestos(id), FOREIGN KEY(aprobacion) REFERENCES aprobaciones(id), CHECK(monto>0)",
		'finanzas_gastos_distribuciones'=>"gasto INT NOT NULL, proceso INT NOT NULL, monto DECIMAL(14,2) NOT NULL, porcentaje DECIMAL(5,2) NULL, PRIMARY KEY(gasto,proceso), INDEX(proceso,gasto), FOREIGN KEY(gasto) REFERENCES finanzas_gastos(id), FOREIGN KEY(proceso) REFERENCES procesos_electorales(id), CHECK(monto>=0)",
		'finanzas_anticipos'=>"$document, beneficiario INT NOT NULL, responsable INT NOT NULL, concepto VARCHAR(180) NOT NULL, monto DECIMAL(14,2) NOT NULL, vence DATE NOT NULL, cuenta INT NULL, metodo INT NULL, entregado_en DATETIME NULL, aprobacion INT NULL, autorizacion JSON NULL, relacion_entidad VARCHAR(40) NULL, relacion_id INT NULL, $context, FOREIGN KEY(beneficiario) REFERENCES personas(id), FOREIGN KEY(responsable) REFERENCES personas(id), FOREIGN KEY(cuenta) REFERENCES finanzas_cuentas(id), FOREIGN KEY(metodo) REFERENCES finanzas_metodos(id), FOREIGN KEY(aprobacion) REFERENCES aprobaciones(id), CHECK(monto>0)",
		'finanzas_reembolsos'=>"$document, beneficiario INT NOT NULL, concepto VARCHAR(180) NOT NULL, categoria INT NOT NULL, solicitado DECIMAL(14,2) NOT NULL, autorizado DECIMAL(14,2) NULL, motivo_resolucion VARCHAR(500) NULL, gasto INT NULL UNIQUE, presupuesto INT NULL, relacion_entidad VARCHAR(40) NULL, relacion_id INT NULL, $context, FOREIGN KEY(beneficiario) REFERENCES personas(id), FOREIGN KEY(categoria) REFERENCES finanzas_categorias(id), FOREIGN KEY(gasto) REFERENCES finanzas_gastos(id), FOREIGN KEY(presupuesto) REFERENCES finanzas_presupuestos(id), CHECK(solicitado>0), CHECK(autorizado IS NULL OR (autorizado>=0 AND autorizado<=solicitado))",
		'finanzas_pagos'=>"$document, gasto INT NOT NULL, cuenta INT NULL, metodo INT NULL, anticipo INT NULL, monto DECIMAL(14,2) NOT NULL, signo SMALLINT NOT NULL DEFAULT 1, reversa_de INT NULL UNIQUE, idempotencia VARCHAR(80) CHARACTER SET ascii NOT NULL, referencia VARCHAR(120) NULL, motivo VARCHAR(500) NOT NULL, fecha_operacion DATE NOT NULL, UNIQUE(organizacion,idempotencia), INDEX(gasto,id), INDEX(anticipo,id), $context, FOREIGN KEY(gasto) REFERENCES finanzas_gastos(id), FOREIGN KEY(cuenta) REFERENCES finanzas_cuentas(id), FOREIGN KEY(metodo) REFERENCES finanzas_metodos(id), FOREIGN KEY(anticipo) REFERENCES finanzas_anticipos(id), FOREIGN KEY(reversa_de) REFERENCES finanzas_pagos(id), CHECK(monto>0), CHECK(signo IN (-1,1)), CHECK((cuenta IS NULL)<>(anticipo IS NULL))",
		'finanzas_devoluciones'=>"$document, anticipo INT NOT NULL, monto DECIMAL(14,2) NOT NULL, motivo VARCHAR(500) NOT NULL, idempotencia VARCHAR(80) CHARACTER SET ascii NOT NULL, UNIQUE(organizacion,idempotencia), $context, FOREIGN KEY(anticipo) REFERENCES finanzas_anticipos(id), CHECK(monto>0)",
		'finanzas_ingresos'=>"$document, categoria INT NOT NULL, concepto VARCHAR(180) NOT NULL, fuente VARCHAR(180) NOT NULL, monto DECIMAL(14,2) NOT NULL, cuenta INT NULL, metodo INT NULL, fecha_operacion DATE NOT NULL, notas TEXT NULL, $context, FOREIGN KEY(categoria) REFERENCES finanzas_categorias(id), FOREIGN KEY(cuenta) REFERENCES finanzas_cuentas(id), FOREIGN KEY(metodo) REFERENCES finanzas_metodos(id), CHECK(monto>0)",
		'finanzas_transferencias'=>"$document, origen INT NOT NULL, destino INT NOT NULL, monto DECIMAL(14,2) NOT NULL, motivo VARCHAR(500) NOT NULL, idempotencia VARCHAR(80) CHARACTER SET ascii NOT NULL, UNIQUE(organizacion,idempotencia), $context, FOREIGN KEY(origen) REFERENCES finanzas_cuentas(id), FOREIGN KEY(destino) REFERENCES finanzas_cuentas(id), CHECK(origen<>destino), CHECK(monto>0)",
		'finanzas_caja'=>"id INT AUTO_INCREMENT PRIMARY KEY, organizacion INT NOT NULL, proceso INT NULL, cuenta INT NOT NULL, tipo VARCHAR(32) NOT NULL, registro INT NOT NULL, signo SMALLINT NOT NULL, monto DECIMAL(14,2) NOT NULL, fecha DATE NOT NULL, usuario INT NOT NULL, UNIQUE(tipo,registro,cuenta), INDEX(organizacion,proceso,fecha,id), FOREIGN KEY(organizacion) REFERENCES organizaciones(id), FOREIGN KEY(proceso) REFERENCES procesos_electorales(id), FOREIGN KEY(cuenta) REFERENCES finanzas_cuentas(id), FOREIGN KEY(usuario) REFERENCES usuarios(id), CHECK(signo IN (-1,1)), CHECK(monto>0)",
		'inventario_categorias'=>"$base, nombre VARCHAR(120) NOT NULL, descripcion VARCHAR(500) NULL, activo TINYINT NOT NULL DEFAULT 1, UNIQUE(organizacion,nombre), $context",
		'inventario_origenes'=>"$base, nombre VARCHAR(120) NOT NULL, descripcion VARCHAR(500) NULL, clase VARCHAR(16) NOT NULL, activo TINYINT NOT NULL DEFAULT 1, UNIQUE(organizacion,nombre), $context",
		'inventario_articulos'=>"$base, nombre VARCHAR(180) NOT NULL, codigo VARCHAR(40) NOT NULL, categoria INT NOT NULL, unidad VARCHAR(40) NOT NULL, descripcion VARCHAR(500) NULL, minimo DECIMAL(14,3) NOT NULL DEFAULT 0, costo_referencia DECIMAL(14,2) NULL, activo TINYINT NOT NULL DEFAULT 1, UNIQUE(organizacion,codigo), $context, FOREIGN KEY(categoria) REFERENCES inventario_categorias(id), CHECK(minimo>=0)",
		'inventario_almacenes'=>"$base, nombre VARCHAR(180) NOT NULL, ubicacion VARCHAR(300) NULL, descripcion VARCHAR(500) NULL, activo TINYINT NOT NULL DEFAULT 1, validar_mermas TINYINT NOT NULL DEFAULT 0, UNIQUE(organizacion,nombre), $context",
		'inventario_almacenes_usuarios'=>"almacen INT NOT NULL, usuario INT NOT NULL, PRIMARY KEY(almacen,usuario), FOREIGN KEY(almacen) REFERENCES inventario_almacenes(id), FOREIGN KEY(usuario) REFERENCES usuarios(id)",
		'inventario_minimos'=>"articulo INT NOT NULL, almacen INT NOT NULL, cantidad DECIMAL(14,3) NOT NULL, PRIMARY KEY(articulo,almacen), FOREIGN KEY(articulo) REFERENCES inventario_articulos(id), FOREIGN KEY(almacen) REFERENCES inventario_almacenes(id), CHECK(cantidad>=0)",
		'inventario_movimientos'=>"$document, articulo INT NOT NULL, almacen INT NOT NULL, tipo VARCHAR(24) NOT NULL, origen_catalogo INT NULL, cantidad DECIMAL(14,3) NOT NULL, valor DECIMAL(14,2) NOT NULL, responsable INT NULL, evento INT NULL, relacion_entidad VARCHAR(40) NULL, relacion_id INT NULL, gasto INT NULL, operacion BINARY(16) NOT NULL, origen INT NULL, reversa_de INT NULL UNIQUE, idempotencia VARCHAR(80) CHARACTER SET ascii NOT NULL, motivo VARCHAR(500) NOT NULL, fecha_operacion DATE NOT NULL, aprobacion INT NULL, UNIQUE(organizacion,idempotencia), INDEX(articulo,almacen,estado,id), INDEX(evento,id), $context, FOREIGN KEY(articulo) REFERENCES inventario_articulos(id), FOREIGN KEY(almacen) REFERENCES inventario_almacenes(id), FOREIGN KEY(origen_catalogo) REFERENCES inventario_origenes(id), FOREIGN KEY(responsable) REFERENCES personas(id), FOREIGN KEY(evento) REFERENCES eventos(id), FOREIGN KEY(gasto) REFERENCES finanzas_gastos(id), FOREIGN KEY(origen) REFERENCES inventario_movimientos(id), FOREIGN KEY(reversa_de) REFERENCES inventario_movimientos(id), FOREIGN KEY(aprobacion) REFERENCES aprobaciones(id), CHECK(cantidad<>0)",
		'finanzas_aportaciones'=>"$document, tipo VARCHAR(16) NOT NULL, donante INT NULL, donante_externo VARCHAR(180) NULL, concepto VARCHAR(180) NOT NULL, ingreso INT NULL UNIQUE, articulo INT NULL, almacen INT NULL, cantidad DECIMAL(14,3) NULL, valor DECIMAL(14,2) NULL, recepcion VARCHAR(16) NULL, movimiento INT NULL UNIQUE, $context, FOREIGN KEY(donante) REFERENCES personas(id), FOREIGN KEY(ingreso) REFERENCES finanzas_ingresos(id), FOREIGN KEY(articulo) REFERENCES inventario_articulos(id), FOREIGN KEY(almacen) REFERENCES inventario_almacenes(id), FOREIGN KEY(movimiento) REFERENCES inventario_movimientos(id)",
		'recursos_avisos'=>"clave CHAR(64) CHARACTER SET ascii PRIMARY KEY, notificacion BINARY(16) NOT NULL, fecha DATETIME NOT NULL, FOREIGN KEY(notificacion) REFERENCES notificaciones(uuid)",
	];
	// Permite reanudar el DDL. Las tablas ajenas con nombres coincidentes deben revisarse, no sobrescribirse.
	$existing=[];
	foreach ($tables as $table=>$columns) {
		$statement=$db->prepare('SELECT engine FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
		$statement->execute([$table]);
		$engine=$statement->fetchColumn();
		if (!$engine) continue;
		if(strtolower($engine)!=='innodb') throw new RuntimeException('Motor de tabla incompatible: '.$table.'.');
		$existing[$table]=true;
		preg_match_all('/(?:^|,\s*)([a-z_]+)\s+(INT|BIGINT|BINARY|VARCHAR|DECIMAL|TINYINT|DATETIME|DATE|JSON|SMALLINT|CHAR)(?:\((\d+)(?:,(\d+))?\))?/',$columns,$definitions,PREG_SET_ORDER);
		foreach ($definitions as $definition) {
			$statement=$db->prepare('SELECT data_type,numeric_precision,numeric_scale,character_maximum_length FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
			$statement->execute([$table,$definition[1]]);
			$metadata=$statement->fetch(PDO::FETCH_ASSOC);
			$type=strtolower($definition[2]);
			if($metadata && in_array($type,['binary','varchar','char'],true) && (int)$metadata['character_maximum_length']!==(int)$definition[3]) throw new RuntimeException('Longitud de columna incompatible: '.$table.'.'.$definition[1].'.');
			if (!$metadata || ($metadata['data_type']!==$type && !($type==='json' && $metadata['data_type']==='longtext')) || ($type==='decimal' && ((int) $metadata['numeric_precision']!==(int) $definition[3] || (int) $metadata['numeric_scale']!==(int) $definition[4]))) throw new RuntimeException('Tabla o columna incompatible: '.$table.'.'.$definition[1].'. Revisar antes de migrar.');
		}
		preg_match_all('/FOREIGN KEY\(([a-z_]+)\) REFERENCES ([a-z_]+)\(([a-z_]+)\)/',$columns,$foreignKeys,PREG_SET_ORDER);
		foreach($foreignKeys as [, $column,$parent,$parentColumn]) {
			$statement=$db->prepare('SELECT 1 FROM information_schema.key_column_usage WHERE table_schema=DATABASE() AND table_name=? AND column_name=? AND referenced_table_name=? AND referenced_column_name=?');
			$statement->execute([$table,$column,$parent,$parentColumn]);
			if(!$statement->fetchColumn()) throw new RuntimeException('Integridad referencial incompatible: '.$table.'.'.$column.'.');
		}
		preg_match_all('/(?:PRIMARY KEY|UNIQUE)\(([a-z_,]+)\)/',$columns,$keys,PREG_SET_ORDER);
		foreach($definitions as $definition) if(preg_match('/(?:^|,\s*)'.preg_quote($definition[1],'/').'\s+[^,]+?\bUNIQUE\b/',$columns)) $keys[]=['',$definition[1]];
		$statement=$db->prepare("SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') columns_list FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND non_unique=0 GROUP BY index_name");
		$statement->execute([$table]);
		$unique=$statement->fetchAll(PDO::FETCH_COLUMN);
		foreach($keys as $key) if(!in_array($key[1],$unique,true)) throw new RuntimeException('Clave única incompatible: '.$table.'.'.$key[1].'.');
	}
	// Una reanudación preparada también se somete a integridad de contexto antes de cualquier DDL.
	foreach (array_keys($existing) as $table) if ($schema->columnExists($table,'proceso') && $schema->columnExists($table,'organizacion')) {
		if ($db->query("SELECT r.organizacion FROM $table r JOIN procesos_electorales pe ON pe.id=r.proceso WHERE pe.organizacion<>r.organizacion LIMIT 1")->fetchColumn()!==false) throw new RuntimeException('Contexto histórico incompatible: '.$table.'.');
	}
	$checks=[
		'finanzas_gastos_distribuciones'=>['finanzas_gastos', 'SELECT d.gasto FROM finanzas_gastos_distribuciones d JOIN finanzas_gastos g ON g.id=d.gasto JOIN procesos_electorales pe ON pe.id=d.proceso WHERE pe.organizacion<>g.organizacion LIMIT 1'],
		'finanzas_presupuestos_procesos'=>['finanzas_presupuestos', 'SELECT d.presupuesto FROM finanzas_presupuestos_procesos d JOIN finanzas_presupuestos b ON b.id=d.presupuesto JOIN procesos_electorales pe ON pe.id=d.proceso WHERE pe.organizacion<>b.organizacion LIMIT 1'],
		'inventario_movimientos'=>['inventario_articulos', 'SELECT m.id FROM inventario_movimientos m JOIN inventario_articulos a ON a.id=m.articulo JOIN inventario_almacenes s ON s.id=m.almacen WHERE a.organizacion<>m.organizacion OR s.organizacion<>m.organizacion LIMIT 1'],
		'finanzas_pagos'=>['finanzas_gastos', 'SELECT p.id FROM finanzas_pagos p JOIN finanzas_gastos g ON g.id=p.gasto LEFT JOIN finanzas_anticipos a ON a.id=p.anticipo LEFT JOIN finanzas_cuentas c ON c.id=p.cuenta WHERE p.organizacion<>g.organizacion OR (a.id IS NOT NULL AND a.organizacion<>p.organizacion) OR (c.id IS NOT NULL AND c.organizacion<>p.organizacion) LIMIT 1'],
	];
	foreach ($checks as $table=>[$parent,$query]) if (isset($existing[$table],$existing[$parent]) && $db->query($query)->fetchColumn()!==false) throw new RuntimeException('Relación histórica incompatible: '.$table.'.');
	foreach ($tables as $table=>$columns) $db->exec("CREATE TABLE IF NOT EXISTS $table ($columns) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
	$permissions=[
		'finanzas.ver'=>'Consultar finanzas','finanzas.gastos.crear'=>'Registrar gastos','finanzas.gastos.editar'=>'Editar gastos sin pagos','finanzas.gastos.aprobar'=>'Autorizar gastos','finanzas.gastos.pagar'=>'Registrar y revertir pagos','finanzas.gastos.cancelar'=>'Cancelar gastos sin pagos',
		'finanzas.presupuestos.ver'=>'Consultar presupuestos','finanzas.presupuestos.administrar'=>'Revisar y transferir presupuestos','finanzas.administrar'=>'Configurar categorías, cuentas y políticas','finanzas.ingresos.ver'=>'Consultar ingresos y aportaciones','finanzas.ingresos.crear'=>'Registrar ingresos y aportaciones',
		'finanzas.anticipos.crear'=>'Solicitar y entregar anticipos','finanzas.anticipos.aprobar'=>'Autorizar anticipos','finanzas.anticipos.comprobar'=>'Comprobar y devolver anticipos','finanzas.reembolsos.solicitar'=>'Solicitar reembolsos','finanzas.reembolsos.aprobar'=>'Revisar reembolsos',
		'inventario.ver'=>'Consultar inventario','inventario.articulos'=>'Administrar artículos','inventario.entradas'=>'Registrar entradas y devoluciones','inventario.salidas'=>'Registrar salidas','inventario.transferir'=>'Transferir entre almacenes','inventario.ajustar'=>'Ajustar y revertir existencias','inventario.administrar'=>'Administrar almacenes y categorías',
	];
	$insert=$db->prepare("INSERT IGNORE INTO permisos (id,permiso,descripcion,ambito,modulo,f_registro) VALUES (?,?,?,'ambos',?,NOW())");
	$grant=$db->prepare("INSERT INTO permisos_usuarios_tipo (uuid,permiso,tipo,valor,alcance,f_actualizacion) SELECT ?,?,2,1,'organizacion',NOW() WHERE NOT EXISTS (SELECT 1 FROM permisos_usuarios_tipo WHERE tipo=2 AND permiso=? AND proceso IS NULL)");
	foreach ($permissions as $code=>$label) {
		$insert->execute([$code,$label,$label,str_starts_with($code,'inventario.') ? 'inventario' : 'finanzas']);
		$grant->execute([random_bytes(16),$code,$code]);
	}
	$db->exec("UPDATE modulos SET implementado=1 WHERE codigo IN ('finanzas','inventario')");
};
