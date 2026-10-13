<?php

declare(strict_types=1);

namespace App\Services\Resources;

use App\Core\Auth;
use App\Core\HttpError;
use App\Repositories\CommonRepository;
use App\Services\Common\ApprovalService;
use App\Services\Common\Values;

class ExpenseService extends BaseService
{
	public function __construct(CommonRepository $repository) {
		parent::__construct($repository,'gastos');
	}

	public function balance(int $id): array
	{
		$row=$this->repository->raw('gastos',$id);
		$paid=Amount::signed($this->repository->one('SELECT COALESCE(SUM(monto*signo),0) monto FROM finanzas_pagos WHERE gasto=?',[$id])['monto']);
		return ['pagado'=>Amount::format($paid),'saldo'=>Amount::format(Amount::parse($row['monto'])-$paid)];
	}

	protected function enrich(array $row): array
	{
		$row=parent::enrich($row);
		$row=[...$row,...$this->balance((int) $row['id'])];
		$row['elecciones']=$this->repository->rows('SELECT pe.uuid,pe.proceso nombre,d.monto,d.porcentaje FROM finanzas_gastos_distribuciones d JOIN procesos_electorales pe ON pe.id=d.proceso WHERE d.gasto=? ORDER BY d.proceso',[$row['id']]);
		foreach($row['elecciones'] as &$election) $election=Context::present($election);
		$row['pagos']=$this->repository->rows('SELECT uuid,folio,monto,signo,fecha_operacion,referencia,anticipo FROM finanzas_pagos WHERE gasto=? ORDER BY id DESC LIMIT 100',[$row['id']]);
		foreach($row['pagos'] as &$payment) $payment=Context::present($payment);
		foreach(['categoria'=>'categorias','presupuesto'=>'presupuestos'] as $key=>$entity) if($row[$key]!==null) {
			$ref=$this->repository->raw($entity,(int) $row[$key]);
			$row[$key.'_uuid']=Values::uuidText($ref['uuid']);
			$row[$key.'_nombre']=$ref['nombre'];
		}
		if($row['beneficiario']!==null) {
			$person=$this->repository->one("SELECT uuid,CONCAT_WS(' ',nombre,paterno,materno) nombre FROM personas WHERE id=?",[$row['beneficiario']]);
			$row['beneficiario_uuid']=Values::uuidText($person['uuid']);
			$row['beneficiario_nombre']=$person['nombre'];
		}
		if($row['aprobacion']!==null) $row['aprobacion_uuid']=Values::uuidText($this->repository->one('SELECT uuid FROM aprobaciones WHERE id=?',[$row['aprobacion']])['uuid']);
		return $row;
	}

	private function fields(array $data,array $ctx,string $permission): array
	{
		$amount=Amount::parse($data['monto'] ?? null);
		$category=$this->reference('categorias',$data['categoria'] ?? null,$ctx['organizacion']) ?? throw new \InvalidArgumentException('Selecciona categoría.');
		if($this->repository->raw('categorias',$category)['tipo']!=='gasto') throw new \InvalidArgumentException('Se requiere categoría de gasto.');
		$beneficiary=$this->context->person($data['beneficiario'] ?? null,$permission,$ctx['proceso'] ?? Auth::processId(),$ctx['organizacion']);
		$supplier=Values::text($data['proveedor'] ?? '',180,true);
		if($beneficiary===null && $supplier==='') throw new \InvalidArgumentException('Indica proveedor o beneficiario.');
		$kind=$data['tipo'] ?? 'ordinario';
		if(!in_array($kind,['ordinario','compra','apoyo','viatico','reembolso'],true)) throw new \InvalidArgumentException('Tipo de gasto inválido.');
		$fields=['categoria'=>$category,'concepto'=>Values::text($data['concepto'] ?? null,180),'descripcion'=>Values::text($data['descripcion'] ?? '',4000,true),'beneficiario'=>$beneficiary,'proveedor'=>$supplier,'monto'=>Amount::format($amount),'fecha_operacion'=>Values::date($data['fecha_operacion'] ?? date('Y-m-d')),'presupuesto'=>$this->reference('presupuestos',$data['presupuesto'] ?? null,$ctx['organizacion']),'tipo'=>$kind,'distribucion'=>$data['distribucion'] ?? 'montos','referencia'=>Values::text($data['referencia'] ?? '',120,true),...$this->context->related($data,$ctx['proceso'],$ctx['organizacion'])];
		$elections=Allocation::resolve($this->context,$data,$amount,$ctx['organizacion'],$ctx['proceso'],$permission);
		if($beneficiary!==null) foreach($elections as $election) $this->context->person($beneficiary,$permission,(int) $election['proceso'],$ctx['organizacion']);
		return [$fields,$elections];
	}

	private function allocate(int $id,array $elections): void
	{
		$this->repository->execute('DELETE FROM finanzas_gastos_distribuciones WHERE gasto=?',[$id]);
		foreach($elections as $row) $this->repository->execute('INSERT INTO finanzas_gastos_distribuciones (gasto,proceso,monto,porcentaje) VALUES (?,?,?,?)',[$id,$row['proceso'],$row['monto'],$row['porcentaje']]);
	}

	public function create(array $data): array
	{
		if(($data['tipo'] ?? '')==='reembolso') throw new \InvalidArgumentException('Registra la solicitud desde Reembolsos.');
		$ctx=$this->creation($data);
		return $this->repository->write($ctx['organizacion'],function() use($ctx,$data) {
			$row=$this->createExpense($data,$ctx,'finanzas.gastos.crear');
			if(Values::integer($data['comprometer'] ?? 0,0,1)) $row=$this->commitRow($row);
			if(Values::integer($data['autorizar'] ?? 0,0,1)) $row=$this->submitRow($row);
			return Context::present($this->enrich($row));
		});
	}
	// Uso interno del reembolso ya revisado: mantiene al solicitante original como capturista.
	public function createExpense(array $data,array $ctx,string $permission): array
	{
		[$fields,$elections]=$this->fields($data,$ctx,$permission);
		$row=$this->repository->create('gastos',[...$this->document($ctx,'registrado'),...$fields]);
		$this->allocate((int) $row['id'],$elections);
		$this->context->history('gastos',$row,'crear','',null,$fields);
		return $row;
	}

	private function commitRow(array $row): array
	{
		if($row['estado']==='cancelado') throw new HttpError('El gasto está cancelado.',409);
		(new BudgetService($this->repository))->assertExpense($row,$row['presupuesto']===null ? null : (int) $row['presupuesto'],$this->repository->rows('SELECT proceso FROM finanzas_gastos_distribuciones WHERE gasto=?',[$row['id']]),Amount::parse($row['monto']),(int) $row['id']);
		if(!(int) $row['comprometido']) {
			$this->repository->update('gastos',(int) $row['id'],['comprometido'=>1,'estado'=>'comprometido']);
			$this->context->history('gastos',$row,'comprometer','',null,['monto'=>$row['monto']]);
		}
		return $this->repository->raw('gastos',(int) $row['id']);
	}

	private function submitRow(array $row): array
	{
		if(!in_array($row['estado'],['registrado','comprometido','correccion'],true)) throw new HttpError('El gasto ya tiene una autorización vigente o está cancelado.',409);
		$row=$this->commitRow($row);
		return (new PolicyService($this->repository))->submit('gastos',$row,Amount::parse($row['monto']));
	}

	public function commit(string $uuid): array {
		return $this->change($uuid,fn($row)=>$this->commitRow($row));
	}

	public function submit(string $uuid): array {
		return $this->change($uuid,fn($row)=>$this->submitRow($row));
	}

	private function change(string $uuid,callable $work): array
	{
		return $this->repository->write($this->context->organization(),function() use($uuid,$work) {
			$row=$this->context->record('gastos',$uuid,'finanzas.gastos.crear',true);
			$row=$work($row);
			return Context::present($this->enrich($row));
		});
	}

	private function withdraw(array $row,string $permission,string $reason): void
	{
		if($row['aprobacion']!==null && $this->repository->one("SELECT id FROM aprobaciones WHERE id=? AND estado='pendiente'",[$row['aprobacion']])) (new ApprovalService($this->repository))->withdrawForResource((int) $row['aprobacion'],'finanzas_gastos',(int) $row['id'],$permission,$reason);
	}

	public function update(string $uuid,array $data): array
	{
		return $this->repository->write($this->context->organization(),function() use($uuid,$data) {
			$row=$this->context->record('gastos',$uuid,'finanzas.gastos.editar',true);
			$this->context->version($row,$data);
			if($row['estado']==='cancelado' || $this->repository->one('SELECT id FROM finanzas_pagos WHERE gasto=? LIMIT 1',[$row['id']])) throw new HttpError('El gasto con historia de pagos conserva sus importes. Registra una corrección separada.',409);
			if($row['tipo']==='reembolso') throw new HttpError('El reembolso autorizado conserva la revisión de su solicitud.',409);
			$definitions=$this->repository->rows('SELECT proceso,monto,porcentaje FROM finanzas_gastos_distribuciones WHERE gasto=?',[$row['id']]);
			$ctx=['organizacion'=>(int) $row['organizacion'],'proceso'=>$row['proceso']===null ? null : (int) $row['proceso'],'creado_por'=>(int) $row['creado_por']];
			if(!isset($data['elecciones']) && count($definitions)===1 && $row['distribucion']==='montos') $definitions[0]['monto']=$data['monto'] ?? $row['monto'];
			[$fields,$elections]=$this->fields([...$row,'elecciones'=>$definitions,...$data],$ctx,'finanzas.gastos.editar');
			if(empty($data['relacion']) && !Values::integer($data['quitar_relacion'] ?? 0,0,1)) {
				$fields['relacion_entidad']=$row['relacion_entidad'];
				$fields['relacion_id']=$row['relacion_id'];
			}
			if((int) $row['comprometido']) (new BudgetService($this->repository))->assertExpense([...$row,...$fields],$fields['presupuesto'],$elections,Amount::parse($fields['monto']),(int) $row['id']);
			$reason=Values::text($data['motivo'] ?? null,500);
			$material=array_intersect_key($fields,array_flip(['monto','categoria','concepto','beneficiario','proveedor','tipo','presupuesto']));
			$before=array_intersect_key($row,$material);
			$regenerate=array_intersect_key($row,$fields)!==$fields || $definitions!==$elections;
			if(!$regenerate) return Context::present($this->enrich($row));
			if($regenerate) $this->withdraw($row,'finanzas.gastos.editar',$reason);
			$this->repository->update('gastos',(int) $row['id'],[...$fields,...($regenerate ? ['estado'=>(int) $row['comprometido'] ? 'comprometido' : 'registrado','aprobacion'=>null,'autorizacion'=>null,'autorizado_en'=>null] : [])]);
			$this->allocate((int) $row['id'],$elections);
			$this->context->history('gastos',$row,'editar',$reason,$before,$material);
			$new=$this->repository->raw('gastos',(int) $row['id']);
			if($regenerate && in_array($row['estado'],['validacion','aprobado'],true)) $new=$this->submitRow($new);
			return Context::present($this->enrich($new));
		});
	}

	public function cancel(string $uuid,array $data): array
	{
		return $this->repository->write($this->context->organization(),function() use($uuid,$data) {
			$row=$this->context->record('gastos',$uuid,'finanzas.gastos.cancelar',true);
			$reason=Values::text($data['motivo'] ?? null,500);
			if($row['estado']==='cancelado') return Context::present($row);
			if(Amount::signed($this->balance((int) $row['id'])['pagado'])!==0) throw new HttpError('Revierte los pagos antes de cancelar el gasto.',409);
			if($this->repository->one("SELECT id FROM inventario_movimientos WHERE gasto=? AND estado='aplicado' LIMIT 1",[$row['id']])) throw new HttpError('El gasto tiene recepciones de inventario; conserva la compra y registra el ajuste correspondiente.',409);
			$this->withdraw($row,'finanzas.gastos.cancelar',$reason);
			$this->repository->update('gastos',(int) $row['id'],['estado'=>'cancelado','comprometido'=>0]);
			$this->context->history('gastos',$row,'cancelar',$reason,['estado'=>$row['estado']],['estado'=>'cancelado']);
			$this->refresh((int) $row['id']);
			return $this->detail($uuid);
		});
	}

	public function refresh(int $id): void
	{
		$row=$this->repository->raw('gastos',$id,true);
		$balance=$this->balance($id);
		$paid=Amount::signed($balance['pagado']);
		$state=$row['estado'];
		if($paid>0) {
			$state=Amount::signed($balance['saldo'])===0 ? 'pagado' : 'parcial';
			if($state!==$row['estado']) $this->repository->update('gastos',$id,['estado'=>$state]);
		}
		elseif(in_array($state,['parcial','pagado'],true)) {
			$state='aprobado';
			$this->repository->update('gastos',$id,['estado'=>$state]);
		}
		if($row['tipo']==='reembolso') {
			$claim=$this->repository->one('SELECT id,estado FROM finanzas_reembolsos WHERE gasto=?',[$id]);
			if($claim && $claim['estado']!==$state) $this->repository->update('reembolsos',(int) $claim['id'],['estado'=>$state]);
		}
	}

	public function pay(string $uuid,array $data,?int $advance = null): array
	{
		return $this->repository->write($this->context->organization(),function() use($uuid,$data,$advance) {
			$permission=$advance===null ? 'finanzas.gastos.pagar' : 'finanzas.anticipos.comprobar';
			$row=$this->context->record('gastos',$uuid,$permission,true);
			$amount=Amount::parse($data['monto'] ?? null);
			$key=Values::text($data['idempotencia'] ?? null,80);
			if(!preg_match('/^[a-zA-Z0-9:_.-]+$/D',$key)) throw new \InvalidArgumentException('Referencia de operación inválida.');
			$existing=$this->repository->one('SELECT * FROM finanzas_pagos WHERE organizacion=? AND idempotencia=?',[$row['organizacion'],$key]);
			$account=$advance===null ? $this->reference('cuentas',$data['cuenta'] ?? null,(int) $row['organizacion']) : null;
			$method=$advance===null ? $this->reference('metodos',$data['metodo'] ?? null,(int) $row['organizacion']) : null;
			if($advance===null && ($account===null || $method===null)) throw new \InvalidArgumentException('Selecciona cuenta y método de pago.');
			if($existing) {
				if((int) $existing['gasto']!==(int) $row['id'] || $existing['monto']!==Amount::format($amount) || $existing['cuenta']!==$account || $existing['anticipo']!==$advance || (int) $existing['signo']!==1) throw new HttpError('La referencia ya corresponde a otra operación.',409);
				return Context::present($existing);
			}
			if(!in_array($row['estado'],['aprobado','parcial'],true) || $row['autorizacion']===null) throw new HttpError('El gasto necesita autorización vigente antes de pagarse.',409);
			if($amount>Amount::signed($this->balance((int) $row['id'])['saldo'])) throw new HttpError('El pago excede el saldo pendiente.',409);
			if($advance!==null) (new AdvanceService($this->repository))->assertJustification($advance,$row,$amount);
			$payment=$this->repository->create('pagos',[...$this->document(['organizacion'=>$row['organizacion'],'proceso'=>$row['proceso'],'creado_por'=>$this->context->actor()],'aplicado'),'gasto'=>$row['id'],'cuenta'=>$account,'metodo'=>$method,'anticipo'=>$advance,'monto'=>Amount::format($amount),'idempotencia'=>$key,'referencia'=>Values::text($data['referencia'] ?? '',120,true),'motivo'=>Values::text($data['motivo'] ?? ($advance===null ? 'Pago de gasto autorizado' : 'Comprobación de anticipo'),500),'fecha_operacion'=>Values::date($data['fecha_operacion'] ?? date('Y-m-d'))]);
			if($advance===null) (new TreasuryService($this->repository))->cash($payment,'pago',(int) $payment['id'],$account,-1,$amount,$payment['fecha_operacion']);
			$this->refresh((int) $row['id']);
			$this->context->history('pagos',$payment,'pagar',$payment['motivo'],null,['monto'=>$payment['monto'],'gasto'=>$row['folio'],'anticipo'=>$advance]);
			return Context::present($payment);
		});
	}

	public function reversePayment(string $uuid,array $data): array
	{
		return $this->repository->write($this->context->organization(),function() use($uuid,$data) {
			$payment=$this->context->record('pagos',$uuid,'finanzas.gastos.pagar',true);
			$row=$this->context->record('gastos',(int) $payment['gasto'],'finanzas.gastos.pagar',true);
			$reason=Values::text($data['motivo'] ?? null,500);
			if((int) $payment['signo']!==1 || $this->repository->one('SELECT id FROM finanzas_pagos WHERE reversa_de=?',[$payment['id']])) throw new HttpError('El pago ya está revertido o es una reversión.',409);
			if($payment['anticipo']!==null) {
				$advance=$this->context->record('anticipos',(int) $payment['anticipo'],'finanzas.anticipos.comprobar',true);
				if((new AdvanceService($this->repository))->returned((int) $advance['id'])>0) throw new HttpError('El anticipo tiene devoluciones; registra una corrección separada.',409);
			}
			$reverse=$this->repository->create('pagos',[...$this->document(['organizacion'=>$payment['organizacion'],'proceso'=>$payment['proceso'],'creado_por'=>$this->context->actor()],'aplicado'),'gasto'=>$row['id'],'cuenta'=>$payment['cuenta'],'metodo'=>$payment['metodo'],'anticipo'=>$payment['anticipo'],'monto'=>$payment['monto'],'signo'=>-1,'reversa_de'=>$payment['id'],'idempotencia'=>'reversa:'.$uuid,'motivo'=>$reason,'fecha_operacion'=>Values::date($data['fecha_operacion'] ?? date('Y-m-d'))]);
			if($payment['cuenta']!==null) (new TreasuryService($this->repository))->cash($reverse,'pago_reversa',(int) $reverse['id'],(int) $payment['cuenta'],1,Amount::parse($payment['monto']),$reverse['fecha_operacion']);
			$this->refresh((int) $row['id']);
			$this->context->history('pagos',$reverse,'revertir',$reason,null,['monto'=>$reverse['monto'],'pago'=>$payment['folio']]);
			return Context::present($reverse);
		});
	}
}
