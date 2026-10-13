<?php

declare(strict_types=1);

namespace App\Services\Resources;

use App\Core\Auth;
use App\Core\HttpError;
use App\Repositories\CommonRepository;
use App\Services\Common\ApprovalService;
use App\Services\Common\Values;

class AdvanceService extends BaseService
{
	// El estado de comprobación se deriva del saldo, sin sobrescribir la autorización histórica.
	public function statePredicate(string $state): string
	{
		$applied="COALESCE((SELECT SUM(p.monto*p.signo) FROM finanzas_pagos p WHERE p.anticipo=r.id),0)+COALESCE((SELECT SUM(d.monto) FROM finanzas_devoluciones d WHERE d.anticipo=r.id),0)";
		$active="r.entregado_en IS NOT NULL AND r.estado<>'cancelado'";
		return match($state) {
			'comprobado'=>"$active AND ($applied)=r.monto",
			'vencido'=>"$active AND ($applied)<r.monto AND r.vence<CURRENT_DATE()",
			'parcial'=>"$active AND ($applied)>0 AND ($applied)<r.monto AND r.vence>=CURRENT_DATE()",
			'pendiente'=>"$active AND ($applied)=0 AND r.vence>=CURRENT_DATE()",
		};
	}

	public function __construct(CommonRepository $repository) {
		parent::__construct($repository,'anticipos');
	}

	public function returned(int $id): int {
		return Amount::parse($this->repository->one('SELECT COALESCE(SUM(monto),0) monto FROM finanzas_devoluciones WHERE anticipo=?',[$id])['monto'],2,true);
	}

	public function balance(int $id): array
	{
		$row=$this->repository->raw('anticipos',$id);
		$justified=Amount::signed($this->repository->one('SELECT COALESCE(SUM(monto*signo),0) monto FROM finanzas_pagos WHERE anticipo=?',[$id])['monto']);
		$returned=$this->returned($id);
		$pending=Amount::parse($row['monto'])-$justified-$returned;
		$state=$row['estado'];
		if($row['entregado_en']!==null && $state!=='cancelado') $state=$pending===0 ? 'comprobado' : ($row['vence']<date('Y-m-d') ? 'vencido' : ($justified+$returned>0 ? 'parcial' : 'pendiente'));
		return ['comprobado'=>Amount::format($justified),'devuelto'=>Amount::format($returned),'saldo_comprobar'=>Amount::format($pending),'estado_calculado'=>$state];
	}

	protected function enrich(array $row): array
	{
		$row=parent::enrich($row);
		$row=[...$row,...$this->balance((int) $row['id'])];
		if($row['aprobacion']!==null) $row['aprobacion_uuid']=Values::uuidText($this->repository->one('SELECT uuid FROM aprobaciones WHERE id=?',[$row['aprobacion']])['uuid']);
		return $row;
	}

	public function create(array $data): array
	{
		$ctx=$this->creation($data);
		return $this->repository->write($ctx['organizacion'],function() use($ctx,$data) {
			$person=$this->context->person($data['beneficiario'] ?? null,'finanzas.anticipos.crear',$ctx['proceso'] ?? Auth::processId(),$ctx['organizacion']) ?? throw new \InvalidArgumentException('Selecciona beneficiario.');
			$responsible=$this->context->person($data['responsable'] ?? $data['beneficiario'],'finanzas.anticipos.crear',$ctx['proceso'] ?? Auth::processId(),$ctx['organizacion']);
			$row=$this->repository->create('anticipos',[...$this->document($ctx,'registrado'),'beneficiario'=>$person,'responsable'=>$responsible,'concepto'=>Values::text($data['concepto'] ?? null,180),'monto'=>Amount::format(Amount::parse($data['monto'] ?? null)),'vence'=>Values::date($data['vence'] ?? null),...$this->context->related($data,$ctx['proceso'],$ctx['organizacion'])]);
			$this->context->history('anticipos',$row,'crear','',null,['monto'=>$row['monto'],'vence'=>$row['vence']]);
			$row=(new PolicyService($this->repository))->submit('anticipos',$row,Amount::parse($row['monto']));
			return Context::present($this->enrich($row));
		});
	}

	public function deliver(string $uuid,array $data): array
	{
		return $this->repository->write($this->context->organization(),function() use($uuid,$data) {
			$row=$this->context->record('anticipos',$uuid,'finanzas.anticipos.crear',true);
			if($row['entregado_en']!==null) return Context::present($this->enrich($row));
			if($row['estado']!=='aprobado') throw new HttpError('El anticipo requiere autorización antes de entregarse.',409);
			$account=$this->reference('cuentas',$data['cuenta'] ?? null,(int) $row['organizacion']) ?? throw new \InvalidArgumentException('Selecciona cuenta.');
			$method=$this->reference('metodos',$data['metodo'] ?? null,(int) $row['organizacion']) ?? throw new \InvalidArgumentException('Selecciona método.');
			$date=Values::date($data['fecha_operacion'] ?? date('Y-m-d'));
			$this->repository->update('anticipos',(int) $row['id'],['cuenta'=>$account,'metodo'=>$method,'entregado_en'=>date('Y-m-d H:i:s'),'estado'=>'pendiente']);
			(new TreasuryService($this->repository))->cash($row,'anticipo',(int) $row['id'],$account,-1,Amount::parse($row['monto']),$date);
			$this->context->history('anticipos',$row,'entregar',Values::text($data['motivo'] ?? 'Entrega de anticipo autorizado',500),null,['monto'=>$row['monto']]);
			return $this->detail($uuid);
		});
	}

	public function assertJustification(int $id,array $expense,int $amount): void
	{
		$row=$this->context->record('anticipos',$id,'finanzas.anticipos.comprobar',true);
		if($row['entregado_en']===null || $row['estado']==='cancelado' || $row['organizacion']!==$expense['organizacion'] || $row['proceso']!==$expense['proceso'] || (int) $row['beneficiario']!==(int) $expense['beneficiario']) throw new HttpError('El gasto no corresponde al anticipo entregado.',422);
		if($expense['tipo']==='reembolso') throw new HttpError('Un reembolso no comprueba un anticipo.',422);
		if($amount>Amount::signed($this->balance($id)['saldo_comprobar'])) throw new HttpError('La comprobación excede el saldo del anticipo.',409);
	}

	public function justify(string $uuid,array $data): array
	{
		return $this->repository->write($this->context->organization(),function() use($uuid,$data) {
			$row=$this->context->record('anticipos',$uuid,'finanzas.anticipos.comprobar',true);
			$payment=(new ExpenseService($this->repository))->pay($data['gasto'] ?? '',$data,(int) $row['id']);
			$this->context->history('anticipos',$row,'comprobar',Values::text($data['motivo'] ?? 'Comprobación con gasto autorizado',500),null,['pago'=>$payment['folio'],'monto'=>$payment['monto']]);
			return $this->detail($uuid);
		});
	}

	public function returnMoney(string $uuid,array $data): array
	{
		return $this->repository->write($this->context->organization(),function() use($uuid,$data) {
			$row=$this->context->record('anticipos',$uuid,'finanzas.anticipos.comprobar',true);
			$amount=Amount::parse($data['monto'] ?? null);
			$key=Values::text($data['idempotencia'] ?? null,80);
			$existing=$this->repository->one('SELECT * FROM finanzas_devoluciones WHERE organizacion=? AND idempotencia=?',[$row['organizacion'],$key]);
			if($existing) {
				if((int) $existing['anticipo']!==(int) $row['id'] || $existing['monto']!==Amount::format($amount)) throw new HttpError('Referencia usada para otra devolución.',409);
				return $this->detail($uuid);
			}
			if($row['entregado_en']===null || $row['estado']==='cancelado' || $amount>Amount::signed($this->balance((int) $row['id'])['saldo_comprobar'])) throw new HttpError('La devolución excede el saldo entregado.',409);
			$reason=Values::text($data['motivo'] ?? null,500);
			$service=new BaseService($this->repository,'devoluciones');
			$returned=$this->repository->create('devoluciones',['organizacion'=>$row['organizacion'],'proceso'=>$row['proceso'],'creado_por'=>$this->context->actor(),'folio'=>$this->repository->folio((int) $row['organizacion'],'devoluciones'),'estado'=>'aplicado','anticipo'=>$row['id'],'monto'=>Amount::format($amount),'motivo'=>$reason,'idempotencia'=>$key]);
			(new TreasuryService($this->repository))->cash($row,'devolucion',(int) $returned['id'],(int) $row['cuenta'],1,$amount,Values::date($data['fecha_operacion'] ?? date('Y-m-d')));
			$this->context->history('anticipos',$row,'devolver',$reason,null,['monto'=>$returned['monto']]);
			return $this->detail($uuid);
		});
	}

	public function cancel(string $uuid,array $data): array
	{
		return $this->repository->write($this->context->organization(),function() use($uuid,$data) {
			$row=$this->context->record('anticipos',$uuid,'finanzas.anticipos.crear',true);
			$reason=Values::text($data['motivo'] ?? null,500);
			if($row['entregado_en']!==null && (Amount::signed($this->balance((int) $row['id'])['saldo_comprobar'])!==0 || Amount::signed($this->balance((int) $row['id'])['comprobado'])!==0)) throw new HttpError('El anticipo entregado sólo se cancela tras devolver íntegramente el saldo, sin comprobaciones.',409);
			if($row['estado']==='validacion') (new ApprovalService($this->repository))->withdrawForResource((int) $row['aprobacion'],'finanzas_anticipos',(int) $row['id'],'finanzas.anticipos.crear',$reason);
			$this->repository->update('anticipos',(int) $row['id'],['estado'=>'cancelado']);
			$this->context->history('anticipos',$row,'cancelar',$reason,null,['estado'=>'cancelado']);
			return $this->detail($uuid);
		});
	}
}
