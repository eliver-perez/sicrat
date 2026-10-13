<?php

declare(strict_types=1);

namespace App\Services\Resources;

use App\Core\Auth;
use App\Repositories\CommonRepository;
use App\Repositories\ResourcesRepository;

final class Overview
{
	private ResourcesRepository $repository;
	private Context $context;
	public function __construct(CommonRepository $repository)
	{
		$this->repository=new ResourcesRepository($repository->connection());
		$this->context=new Context($this->repository);
	}

	private function scope(string $entity): array
	{
		return $this->context->predicate($entity,Catalog::entity($entity)['read'],Auth::processId());
	}

	private function count(string $entity,string $extra = '1=1'): int
	{
		[$where,$params]=$this->scope($entity);
		$table=Catalog::entity($entity)['table'];
		return (int) $this->repository->one("SELECT COUNT(*) total FROM $table r WHERE ($where) AND ($extra)",$params)['total'];
	}

	public function summary(string $module): array
	{
		$permission=$module==='inventario' ? 'inventario.ver' : 'finanzas.ver';
		$ctx=$this->context->require($module==='inventario' ? 'articulos' : 'gastos',$permission,Auth::processId());
		$cards=$module==='inventario' ? $this->inventory() : $this->finance();
		return ['module'=>$module,'enabled'=>$this->context->enabled($module,$ctx['organization'],Auth::processId()),'cards'=>$cards,'currency'=>$module==='finanzas' ? 'MXN' : null,'scope_note'=>'Información correspondiente a tu alcance y elección de trabajo.'];
	}

	private function finance(): array
	{
		$budget='0.00';
		$available='0.00';
		if($this->context->allowed('finanzas.presupuestos.ver')) {
			[$where,$params]=$this->scope('presupuestos');
			$totals=$this->repository->one("SELECT COALESCE(SUM(r.monto),0) presupuestado,COALESCE(SUM(r.monto-COALESCE(committed.monto,0)),0) disponible FROM finanzas_presupuestos r LEFT JOIN (SELECT presupuesto,SUM(monto) monto FROM finanzas_gastos WHERE comprometido=1 AND estado<>'cancelado' GROUP BY presupuesto) committed ON committed.presupuesto=r.id WHERE ($where)",$params);
			$budget=$totals['presupuestado'];
			$available=$totals['disponible'];
		}
		[$where,$params]=$this->scope('gastos');
		$expenses=$this->repository->one("SELECT COALESCE(SUM(CASE WHEN r.comprometido=1 AND r.estado<>'cancelado' THEN r.monto ELSE 0 END),0) comprometido,COALESCE(SUM(paid.monto),0) pagado FROM finanzas_gastos r LEFT JOIN (SELECT gasto,SUM(monto*signo) monto FROM finanzas_pagos GROUP BY gasto) paid ON paid.gasto=r.id WHERE ($where)",$params);
		$cards=[['label'=>'Presupuestado','value'=>$budget],['label'=>'Comprometido','value'=>$expenses['comprometido']],['label'=>'Pagado','value'=>$expenses['pagado']],['label'=>'Disponible en presupuestos','value'=>$available]];
		$income='0.00';
		if($this->context->allowed('finanzas.ingresos.ver')) {
			[$where,$params]=$this->scope('ingresos');
			$income=$this->repository->one("SELECT COALESCE(SUM(r.monto),0) monto FROM finanzas_ingresos r WHERE ($where) AND r.estado='recibido'",$params)['monto'];
		}
		$cards[]=['label'=>'Ingresos recibidos','value'=>$income];
		[$where,$params]=$this->scope('anticipos');
		$pending=$this->repository->one("SELECT COALESCE(SUM(r.monto-COALESCE(paid.monto,0)-COALESCE(returned.monto,0)),0) monto FROM finanzas_anticipos r LEFT JOIN (SELECT anticipo,SUM(monto*signo) monto FROM finanzas_pagos WHERE anticipo IS NOT NULL GROUP BY anticipo) paid ON paid.anticipo=r.id LEFT JOIN (SELECT anticipo,SUM(monto) monto FROM finanzas_devoluciones GROUP BY anticipo) returned ON returned.anticipo=r.id WHERE ($where) AND r.entregado_en IS NOT NULL AND r.estado<>'cancelado'",$params)['monto'];
		$cards[]=['label'=>'Anticipos por comprobar','value'=>$pending];
		$cards[]=['label'=>'Reembolsos pendientes','value'=>(string) $this->count('reembolsos',"r.estado NOT IN ('pagado','rechazado','cancelado')")];
		foreach($cards as $index=>&$card) $card['monetary']=$index<count($cards)-1;
		unset($card);
		return $cards;
	}

	private function inventory(): array
	{
		$cards=[];
		foreach(['articulos'=>'Artículos','almacenes'=>'Almacenes','movimientos'=>'Movimientos registrados'] as $entity=>$label) $cards[]=['label'=>$label,'value'=>(string) $this->count($entity)];
		[$where,$params]=$this->scope('almacenes');
		$where=str_replace('r.','warehouse.',$where);
		$low=$this->repository->one("SELECT COUNT(*) total FROM inventario_articulos item JOIN inventario_almacenes warehouse ON warehouse.organizacion=item.organizacion LEFT JOIN (SELECT articulo,almacen,SUM(cantidad) cantidad FROM inventario_movimientos WHERE estado='aplicado' GROUP BY articulo,almacen) stock ON stock.articulo=item.id AND stock.almacen=warehouse.id LEFT JOIN inventario_minimos thresholds ON thresholds.articulo=item.id AND thresholds.almacen=warehouse.id WHERE ($where) AND COALESCE(stock.cantidad,0)<COALESCE(thresholds.cantidad,item.minimo)",$params);
		$cards[]=['label'=>'Existencias bajo mínimo','value'=>(string) $low['total']];
		$cards[]=['label'=>'Pérdidas y daños pendientes','value'=>(string) $this->count('movimientos',"r.estado='validacion' AND r.tipo IN ('perdida','dano')")];
		return $cards;
	}
}
