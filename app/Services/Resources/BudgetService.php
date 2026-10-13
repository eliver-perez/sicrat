<?php

declare(strict_types=1);

namespace App\Services\Resources;

use App\Core\Auth;
use App\Core\HttpError;
use App\Repositories\CommonRepository;
use App\Services\Common\Values;

class BudgetService extends BaseService
{
	public function __construct(CommonRepository $repository) {
		parent::__construct($repository,'presupuestos');
	}

	public function totals(int $id): array
	{
		$row=$this->repository->raw('presupuestos',$id);
		$committed=$this->repository->one("SELECT COALESCE(SUM(monto),0) monto FROM finanzas_gastos WHERE presupuesto=? AND comprometido=1 AND estado<>'cancelado'",[$id])['monto'];
		$paid=$this->repository->one('SELECT COALESCE(SUM(p.monto*p.signo),0) monto FROM finanzas_pagos p JOIN finanzas_gastos g ON g.id=p.gasto WHERE g.presupuesto=?',[$id])['monto'];
		return ['presupuestado'=>$row['monto'],'comprometido'=>$committed,'pagado'=>$paid,'disponible'=>Amount::format(Amount::parse($row['monto'],2,true)-Amount::parse($committed,2,true))];
	}

	protected function enrich(array $row): array
	{
		$row=parent::enrich($row);
		$row=[...$row,...$this->totals((int) $row['id'])];
		$row['elecciones']=$this->repository->rows('SELECT pe.uuid,pe.proceso nombre FROM finanzas_presupuestos_procesos bp JOIN procesos_electorales pe ON pe.id=bp.proceso WHERE bp.presupuesto=? ORDER BY pe.id',[$row['id']]);
		foreach($row['elecciones'] as &$election) $election=Context::present($election);
		$row['revisiones']=$this->repository->rows('SELECT r.uuid,r.tipo,r.motivo,r.antes,r.despues,r.version,r.fecha,u.nombre actor FROM finanzas_presupuestos_revisiones r JOIN usuarios u ON u.id=r.usuario WHERE r.presupuesto=? ORDER BY r.id DESC LIMIT 100',[$row['id']]);
		foreach($row['revisiones'] as &$revision) $revision=Context::present($revision);
		return $row;
	}

	private function revision(array $row,int $after,string $type,string $reason,?string $transfer = null): void
	{
		$this->repository->execute('INSERT INTO finanzas_presupuestos_revisiones (uuid,presupuesto,transferencia,usuario,tipo,motivo,antes,despues,version,fecha) VALUES (?,?,?,?,?,?,?,?,?,NOW())',[Values::binary(Values::uuid()),$row['id'],$transfer===null ? null : Values::binary($transfer),$this->context->actor(),$type,$reason,$row['monto'],Amount::format($after),$row['version']]);
	}

	public function create(array $data): array
	{
		$ctx=$this->creation($data);
		$amount=Amount::parse($data['monto'] ?? null,2,true);
		$start=Values::date($data['inicio'] ?? null);
		$end=Values::date($data['fin'] ?? null);
		if($end<$start) throw new \InvalidArgumentException('Periodo inválido.');
		return $this->repository->write($ctx['organizacion'],function() use($ctx,$data,$amount,$start,$end) {
			$permission='finanzas.presupuestos.administrar';
			if(!$this->context->broad($this->context->require('presupuestos',$permission,$ctx['proceso'] ?? Auth::processId(),true),$permission)) throw new HttpError('Se requiere alcance de elección u organización.',403);
			$category=$this->reference('categorias',$data['categoria'] ?? null,$ctx['organizacion']);
			if($category!==null && $this->repository->raw('categorias',$category)['tipo']!=='gasto') throw new \InvalidArgumentException('El presupuesto requiere categoría de gasto.');
			$event=null;
			if(!empty($data['evento'])) $event=$this->context->related(['relacion_entidad'=>'eventos','relacion'=>$data['evento']],$ctx['proceso'],$ctx['organizacion'])['relacion_id'];
			$elections=$data['elecciones'] ?? ($ctx['proceso']===null ? [] : [$ctx['proceso']]);
			if(!is_array($elections) || count($elections)>100) throw new \InvalidArgumentException('Elecciones inválidas.');
			$ids=[];
			foreach($elections as $reference) {
				$id=$this->context->process($reference);
				if($id===null || isset($ids[$id])) throw new \InvalidArgumentException('Elección repetida.');
				$this->context->require('presupuestos',$permission,$id,true,null,$ctx['organizacion']);
				$ids[$id]=true;
			}
			if($ctx['proceso']!==null && !isset($ids[$ctx['proceso']])) throw new \InvalidArgumentException('Incluye la elección principal.');
			$row=$this->repository->create('presupuestos',[...$this->document($ctx,'activo'),'nombre'=>Values::text($data['nombre'] ?? null,180),'categoria'=>$category,'evento'=>$event,'inicio'=>$start,'fin'=>$end,'monto'=>Amount::format($amount)]);
			foreach(array_keys($ids) as $id) $this->repository->execute('INSERT INTO finanzas_presupuestos_procesos VALUES (?,?)',[$row['id'],$id]);
			$initial=$row;
			$initial['monto']='0.00';
			$reason=Values::text($data['motivo'] ?? 'Presupuesto inicial',500);
			$this->revision($initial,$amount,'inicial',$reason);
			$this->context->history('presupuestos',$row,'crear',$reason,null,['monto'=>$row['monto']]);
			return Context::present($this->enrich($row));
		});
	}

	public function revise(string $uuid,array $data): array
	{
		return $this->repository->write($this->context->organization(),function() use($uuid,$data) {
			$row=$this->context->record('presupuestos',$uuid,'finanzas.presupuestos.administrar',true);
			$this->context->version($row,$data);
			$after=Amount::parse($data['monto'] ?? null,2,true);
			$committed=Amount::parse($this->totals((int) $row['id'])['comprometido'],2,true);
			if($after<$committed) throw new HttpError('El presupuesto no puede quedar por debajo de lo comprometido.',409);
			$reason=Values::text($data['motivo'] ?? null,500);
			$this->repository->update('presupuestos',(int) $row['id'],['monto'=>Amount::format($after)]);
			$new=$this->repository->raw('presupuestos',(int) $row['id']);
			$new['monto']=$row['monto'];
			$this->revision($new,$after,$after>=Amount::parse($row['monto'],2,true) ? 'ampliacion' : 'reduccion',$reason);
			$this->context->history('presupuestos',$row,'revisar',$reason,['monto'=>$row['monto']],['monto'=>Amount::format($after)]);
			return $this->detail($uuid);
		});
	}

	public function transfer(string $uuid,array $data): array
	{
		return $this->repository->write($this->context->organization(),function() use($uuid,$data) {
			$origin=$this->context->record('presupuestos',$uuid,'finanzas.presupuestos.administrar',true);
			$dest=$this->context->record('presupuestos',$data['destino'] ?? '', 'finanzas.presupuestos.administrar',true);
			$amount=Amount::parse($data['monto'] ?? null);
			$reason=Values::text($data['motivo'] ?? null,500);
			if($origin['id']===$dest['id'] || $origin['organizacion']!==$dest['organizacion']) throw new \InvalidArgumentException('Destino inválido.');
			if($amount>Amount::signed($this->totals((int) $origin['id'])['disponible'])) throw new HttpError('La transferencia excede el disponible.',409);
			$operation=Values::uuid();
			foreach([[$origin,-$amount],[$dest,$amount]] as [$row,$delta]) {
				$after=Amount::add(Amount::parse($row['monto'],2,true),$delta);
				$this->repository->update('presupuestos',(int) $row['id'],['monto'=>Amount::format($after)]);
				$old=$row;
				$old['version']++;
				$this->revision($old,$after,'transferencia',$reason,$operation);
				$this->context->history('presupuestos',$row,'transferir',$reason,['monto'=>$row['monto']],['monto'=>Amount::format($after),'transferencia'=>$operation]);
			}
			return ['operacion'=>$operation];
		});
	}

	public function assertExpense(array $expense,?int $budget,array $elections,int $amount,int $exclude = 0): void
	{
		if($budget===null) return;
		$row=$this->context->record('presupuestos',$budget,'finanzas.presupuestos.ver');
		if($row['estado']!=='activo' || (int) $row['organizacion']!==(int) $expense['organizacion'] || ($row['categoria']!==null && (int) $row['categoria']!==(int) $expense['categoria']) || $expense['fecha_operacion']<$row['inicio'] || $expense['fecha_operacion']>$row['fin']) throw new HttpError('El gasto no corresponde al presupuesto seleccionado.',422);
		$allowed=$this->context->elections('presupuestos',$budget);
		if($allowed && array_diff(array_column($elections,'proceso'),$allowed)) throw new HttpError('El presupuesto no contempla todas las elecciones del gasto.',422);
		if($row['evento']!==null && (($expense['relacion_entidad'] ?? null)!=='eventos' || (int) ($expense['relacion_id'] ?? 0)!==(int) $row['evento'])) throw new HttpError('El gasto debe corresponder al evento presupuestado.',422);
		$committed=Amount::parse($this->repository->one("SELECT COALESCE(SUM(monto),0) monto FROM finanzas_gastos WHERE presupuesto=? AND comprometido=1 AND estado<>'cancelado' AND id<>?",[$budget,$exclude])['monto'],2,true);
		if(Amount::add($committed,$amount)>Amount::parse($row['monto'],2,true)) throw new HttpError('El gasto excede el presupuesto disponible.',409);
	}
}
