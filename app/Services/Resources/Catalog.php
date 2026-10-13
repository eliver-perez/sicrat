<?php

declare(strict_types=1);

namespace App\Services\Resources;

final class Catalog
{
	public const ENTITIES=[
		'categorias'=>['finanzas_categorias','finanzas.ver','finanzas.administrar','Categorías financieras'],
		'metodos'=>['finanzas_metodos','finanzas.ver','finanzas.administrar','Métodos de pago'],
		'cuentas'=>['finanzas_cuentas','finanzas.ver','finanzas.administrar','Cuentas y fondos'],
		'politicas'=>['finanzas_politicas','finanzas.administrar','finanzas.administrar','Políticas de autorización'],
		'presupuestos'=>['finanzas_presupuestos','finanzas.presupuestos.ver','finanzas.presupuestos.administrar','Presupuestos'],
		'gastos'=>['finanzas_gastos','finanzas.ver','finanzas.gastos.crear','Gastos'],
		'pagos'=>['finanzas_pagos','finanzas.ver','finanzas.gastos.pagar','Pagos'],
		'anticipos'=>['finanzas_anticipos','finanzas.ver','finanzas.anticipos.crear','Anticipos comprobables'],
		'reembolsos'=>['finanzas_reembolsos','finanzas.ver','finanzas.reembolsos.solicitar','Reembolsos'],
		'ingresos'=>['finanzas_ingresos','finanzas.ingresos.ver','finanzas.ingresos.crear','Ingresos'],
		'aportaciones'=>['finanzas_aportaciones','finanzas.ingresos.ver','finanzas.ingresos.crear','Aportaciones y donaciones'],
		'transferencias'=>['finanzas_transferencias','finanzas.ver','finanzas.administrar','Transferencias de fondos'],
		'devoluciones'=>['finanzas_devoluciones','finanzas.ver','finanzas.anticipos.comprobar','Devoluciones de anticipos'],
		'categorias_inventario'=>['inventario_categorias','inventario.ver','inventario.administrar','Categorías de artículos'],
		'origenes'=>['inventario_origenes','inventario.ver','inventario.administrar','Orígenes de movimiento'],
		'articulos'=>['inventario_articulos','inventario.ver','inventario.articulos','Artículos'],
		'almacenes'=>['inventario_almacenes','inventario.ver','inventario.administrar','Almacenes'],
		'movimientos'=>['inventario_movimientos','inventario.ver','inventario.entradas','Movimientos de inventario'],
	];
	public static function entity(string $entity): array
	{
		[$table,$read,$write,$title]=self::ENTITIES[$entity] ?? throw new \InvalidArgumentException('Recurso no disponible.');
		return ['table'=>$table,'read'=>$read,'write'=>$write,'title'=>$title,'module'=>str_starts_with($table,'inventario_') ? 'inventario' : 'finanzas'];
	}

	public static function fromTable(string $table): string {
		foreach(self::ENTITIES as $key=>$row) if($row[0]===$table) return $key;
		throw new \InvalidArgumentException('Entidad propietaria desconocida.');
	}

	public static function prefix(string $entity): string {
		return ['presupuestos'=>'PRE','gastos'=>'GAS','pagos'=>'PAG','anticipos'=>'ANT','reembolsos'=>'REE','ingresos'=>'ING','aportaciones'=>'APO','transferencias'=>'TRA','devoluciones'=>'DEV','movimientos'=>'MOV'][$entity] ?? throw new \InvalidArgumentException('Folio no disponible.');
	}
}
