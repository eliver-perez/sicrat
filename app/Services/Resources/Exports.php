<?php

declare(strict_types=1);

namespace App\Services\Resources;

use App\Core\HttpError;
use App\Repositories\CommonRepository;
use App\Repositories\ResourcesRepository;
use App\Services\Common\ExportRegistry;
use App\Services\Common\Values;

final class Exports
{
	public const TYPES=['finanzas_presupuestos'=>'presupuestos','finanzas_gastos'=>'gastos','finanzas_pagos'=>'pagos','finanzas_ingresos'=>'ingresos','finanzas_anticipos'=>'anticipos','finanzas_reembolsos'=>'reembolsos','finanzas_aportaciones'=>'aportaciones','inventario_articulos'=>'articulos','inventario_almacenes'=>'almacenes','inventario_movimientos'=>'movimientos'];
	public static function filters(string $type,array $filters): array
	{
		if(!isset(self::TYPES[$type]) && !in_array($type,['finanzas_flujo','inventario_existencias'],true)) return [];
		$clean=[];
		foreach(['estado','desde','hasta','categoria','bajo_minimo','tipo','articulo','almacen','search'] as $key) if(isset($filters[$key]) && $filters[$key]!=='') $clean[$key]=in_array($key,['categoria','articulo','almacen'],true) ? Values::uuidText(Values::binary($filters[$key])) : Values::text((string) $filters[$key],$key==='search' ? 150 : 36);
		return $clean;
	}

	public static function register(ExportRegistry $registry,CommonRepository $repository): void
	{
		foreach(self::TYPES as $type=>$entity) $registry->register($type,Catalog::entity($entity)['title'],self::columns($entity),static fn($ctx)=>self::rows($repository,$entity,$ctx),false);
		$registry->register('finanzas_flujo','Flujo de caja',['Ingresos recibidos','Pagos reales','Anticipos entregados','Devoluciones','Flujo neto','Moneda'],static function($ctx) use($repository): iterable {
			$context=new Context(new ResourcesRepository($repository->connection()));
			$scope=$ctx['scope_permission'] ?? 'exportaciones.generar';
			$effective=$context->require('ingresos',$scope,$ctx['process'],false,$ctx['actor'],$ctx['organization']);
			if(!$context->broad($effective,$scope,$ctx['process']===null)) throw new HttpError('El reporte agregado requiere alcance de elección u organización.',403);
			$flow=(new TreasuryService($repository))->flow([...$ctx['filters'],'proceso'=>$ctx['process'] ?? 'organizacion']);
			yield ['source_id'=>'flujo:'.$ctx['organization'].':'.($ctx['process'] ?? 0),...$flow];
		},false);
		$registry->register('inventario_existencias','Existencias por almacén',['Artículo','Código','Unidad','Almacén','Existencia','Valor administrativo','Mínimo','Costo promedio','Bajo mínimo'],static function($ctx) use($repository): iterable {
			$context=new Context(new ResourcesRepository($repository->connection()));
			$scope=$ctx['scope_permission'] ?? 'exportaciones.generar';
			$where=$context->require('almacenes',$scope,$ctx['process'],false,$ctx['actor'],$ctx['organization']);
			if(!$context->broad($where,$scope)) throw new HttpError('La exportación de existencias requiere alcance de elección u organización, además de acceso al almacén.',403);
			$offset=0;
			do {
				$result=(new InventoryService($repository))->stocks([...$ctx['filters'],'offset'=>$offset,'limit'=>100]);
				foreach($result['rows'] as $row) {
					$id=$row['articulo_uuid'].':'.$row['almacen_uuid'];
					unset($row['articulo_uuid'],$row['almacen_uuid']);
					yield ['source_id'=>$id,...$row];
				}
				$offset+=100;
			}
			while($result['pagination']['has_more']);
		},false);
	}

	private static function columns(string $entity): array
	{
		return match($entity) {
			'presupuestos'=>['Folio','Presupuesto','Categoría','Inicio','Fin','Presupuestado','Comprometido','Pagado','Disponible'],
			'gastos'=>['Folio','Concepto','Categoría','Beneficiario','Tipo','Estado','Fecha','Monto','Pagado','Saldo'],
			'pagos'=>['Folio','Gasto','Concepto','Fecha','Monto','Signo','Motivo'],
			'ingresos'=>['Folio','Concepto','Fuente','Categoría','Estado','Fecha','Monto'],
			'anticipos'=>['Folio','Concepto','Beneficiario','Estado','Vencimiento','Entregado','Comprobado','Devuelto','Saldo por comprobar'],
			'reembolsos'=>['Folio','Concepto','Beneficiario','Estado','Solicitado','Autorizado','Pagado'],
			'aportaciones'=>['Folio','Concepto','Tipo','Estado','Cantidad','Importe monetario'],
			'articulos'=>['Código','Artículo','Categoría','Unidad','Mínimo','Activo'],
			'almacenes'=>['Almacén','Ubicación','Activo'],
			'movimientos'=>['Folio','Artículo','Almacén','Tipo','Estado','Fecha','Cantidad','Valor administrativo','Motivo'],
		};
	}

	private static function rows(CommonRepository $repository,string $entity,array $ctx): iterable
	{
		$resources=new ResourcesRepository($repository->connection());
		$context=new Context($resources);
		$scope=$ctx['scope_permission'] ?? 'exportaciones.generar';
		$context->require($entity,Catalog::entity($entity)['read'],$ctx['process'],false,$ctx['actor'],$ctx['organization']);
		$offset=0;
		$service=Factory::service($repository,$entity);
		do {
			$result=$service->listing([...$ctx['filters'],'_scope_permission'=>$scope,'offset'=>$offset,'limit'=>100,'proceso'=>$ctx['process'] ?? 'organizacion']);
			foreach($result['rows'] as $row) {
				try {
					$context->record($entity,(int) $row['id'],$scope,false,$ctx['actor']);
				} catch(HttpError) {
					continue;
				}
				$keys=match($entity) {
					'presupuestos'=>['folio','nombre','categoria_nombre','inicio','fin','presupuestado','comprometido','pagado','disponible'],'gastos'=>['folio','concepto','categoria_nombre','beneficiario_nombre','tipo','estado','fecha_operacion','monto','pagado','saldo'],'pagos'=>['folio','gasto_folio','concepto','fecha_operacion','monto','signo','motivo'],'ingresos'=>['folio','concepto','fuente','categoria_nombre','estado','fecha_operacion','monto'],'anticipos'=>['folio','concepto','beneficiario_nombre','estado_calculado','vence','monto','comprobado','devuelto','saldo_comprobar'],'reembolsos'=>['folio','concepto','beneficiario_nombre','estado','solicitado','autorizado','pagado'],'aportaciones'=>['folio','concepto','tipo','estado','cantidad','monto'],'articulos'=>['codigo','nombre','categoria_nombre','unidad','minimo','activo'],'almacenes'=>['nombre','ubicacion','activo'],'movimientos'=>['folio','articulo_nombre','almacen_nombre','tipo','estado','fecha_operacion','cantidad','valor','motivo'],
				};
				$values=['source_id'=>$row['id']];
				foreach($keys as $key) $values[$key]=$row[$key] ?? '';
				yield $values;
			}
			$offset+=100;
		}
		while($result['pagination']['has_more']);
	}
}
