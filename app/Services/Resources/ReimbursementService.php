<?php

declare(strict_types=1);

namespace App\Services\Resources;

use App\Core\Auth;
use App\Core\HttpError;
use App\Repositories\CommonRepository;
use App\Services\Common\Values;

class ReimbursementService extends BaseService
{
	public function __construct(CommonRepository $repository) {
		parent::__construct($repository,'reembolsos');
	}

	protected function enrich(array $row): array
	{
		$row=parent::enrich($row);
		if($row['gasto']!==null) {
			$expense=$this->repository->raw('gastos',(int) $row['gasto']);
			$row['gasto_uuid']=Values::uuidText($expense['uuid']);
			$row['gasto_folio']=$expense['folio'];
			$row=[...$row,...(new ExpenseService($this->repository))->balance((int) $expense['id'])];
		}
		return $row;
	}

	public function create(array $data): array
	{
		$ctx=$this->creation($data);
		return $this->repository->write($ctx['organizacion'],function() use($ctx,$data) {
			$person=$this->context->person($data['beneficiario'] ?? null,'finanzas.reembolsos.solicitar',$ctx['proceso'] ?? Auth::processId(),$ctx['organizacion']) ?? throw new \InvalidArgumentException('Selecciona beneficiario.');
			$category=$this->reference('categorias',$data['categoria'] ?? null,$ctx['organizacion']) ?? throw new \InvalidArgumentException('Selecciona categoría.');
			if($this->repository->raw('categorias',$category)['tipo']!=='gasto') throw new \InvalidArgumentException('Categoría incompatible.');
			$row=$this->repository->create('reembolsos',[...$this->document($ctx,'solicitado'),'beneficiario'=>$person,'concepto'=>Values::text($data['concepto'] ?? null,180),'categoria'=>$category,'solicitado'=>Amount::format(Amount::parse($data['solicitado'] ?? null)),'presupuesto'=>$this->reference('presupuestos',$data['presupuesto'] ?? null,$ctx['organizacion']),...$this->context->related($data,$ctx['proceso'],$ctx['organizacion'])]);
			$this->context->history('reembolsos',$row,'solicitar','',null,['solicitado'=>$row['solicitado']]);
			return Context::present($row);
		});
	}

	public function review(string $uuid,array $data): array
	{
		return $this->repository->write($this->context->organization(),function() use($uuid,$data) {
			$row=$this->context->record('reembolsos',$uuid,'finanzas.reembolsos.aprobar',true);
			if($this->context->actor()===(int) $row['creado_por']) throw new HttpError('La solicitud propia debe revisarla otra cuenta.',403);
			if($row['estado']!=='solicitado') throw new HttpError('La solicitud ya tiene una revisión.',409);
			$amount=Amount::parse($data['autorizado'] ?? null,2,true);
			$reason=Values::text($data['motivo'] ?? null,500);
			if($amount>Amount::parse($row['solicitado'])) throw new \InvalidArgumentException('El autorizado no puede exceder lo solicitado.');
			$expense=null;
			$state='rechazado';
			if($amount>0) {
				$service=new ExpenseService($this->repository);
				$expense=$service->createExpense(['tipo'=>'reembolso','categoria'=>(int) $row['categoria'],'beneficiario'=>(int) $row['beneficiario'],'concepto'=>$row['concepto'],'descripcion'=>$reason,'monto'=>Amount::format($amount),'fecha_operacion'=>date('Y-m-d'),'presupuesto'=>$row['presupuesto']===null ? null : (int) $row['presupuesto']],['organizacion'=>(int) $row['organizacion'],'proceso'=>$row['proceso']===null ? null : (int) $row['proceso'],'creado_por'=>(int) $row['creado_por']],'finanzas.reembolsos.aprobar');
				$expense=[...$expense,'relacion_entidad'=>$row['relacion_entidad'],'relacion_id'=>$row['relacion_id']];
				$this->repository->update('gastos',(int) $expense['id'],['relacion_entidad'=>$row['relacion_entidad'],'relacion_id'=>$row['relacion_id']]);
				(new BudgetService($this->repository))->assertExpense($expense,$expense['presupuesto']===null ? null : (int) $expense['presupuesto'],$this->repository->rows('SELECT proceso FROM finanzas_gastos_distribuciones WHERE gasto=?',[$expense['id']]),$amount,(int) $expense['id']);
				$this->repository->update('gastos',(int) $expense['id'],['comprometido'=>1,'estado'=>'comprometido']);
				$expense=$this->repository->raw('gastos',(int) $expense['id']);
				$expense=(new PolicyService($this->repository))->submit('gastos',$expense,$amount);
				$state=$expense['estado'];
			}
			$this->repository->update('reembolsos',(int) $row['id'],['autorizado'=>Amount::format($amount),'motivo_resolucion'=>$reason,'gasto'=>$expense['id'] ?? null,'estado'=>$state]);
			$this->context->history('reembolsos',$row,'revisar',$reason,['solicitado'=>$row['solicitado']],['autorizado'=>Amount::format($amount),'estado'=>$state]);
			(new Alerts($this->repository))->notify((int) $row['creado_por'],$row,'Reembolso revisado','Consulta el importe autorizado y su estado.','reembolso:'.$row['id']);
			return $this->detail($uuid);
		});
	}

	public function pay(string $uuid,array $data): array
	{
		$row=$this->context->record('reembolsos',$uuid,'finanzas.gastos.pagar');
		if($row['gasto']===null) throw new HttpError('El reembolso no tiene importe autorizado.',409);
		$expense=$this->repository->raw('gastos',(int) $row['gasto']);
		return (new ExpenseService($this->repository))->pay(Values::uuidText($expense['uuid']),$data);
	}
}
