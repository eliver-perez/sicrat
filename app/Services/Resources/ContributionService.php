<?php

declare(strict_types=1);

namespace App\Services\Resources;

use App\Core\Auth;
use App\Core\HttpError;
use App\Repositories\CommonRepository;
use App\Services\Common\Values;

class ContributionService extends BaseService
{
	public function __construct(CommonRepository $repository) {
		parent::__construct($repository,'aportaciones');
	}

	protected function enrich(array $row): array
	{
		$row=parent::enrich($row);
		if($row['ingreso']!==null) {
			$income=$this->repository->raw('ingresos',(int)$row['ingreso']);
			$row['monto']=$income['monto'];
			$row['ingreso_uuid']=Values::uuidText($income['uuid']);
			$row['fecha_operacion']=$income['fecha_operacion'];
		}
		return $row;
	}

	public function create(array $data): array
	{
		$ctx=$this->creation($data);
		return $this->repository->write($ctx['organizacion'],function() use($ctx,$data) {
			$type=$data['tipo'] ?? null;
			if(!in_array($type,['monetaria','especie'],true)) throw new \InvalidArgumentException('Tipo de aportación inválido.');
			$person=$this->context->person($data['donante'] ?? null,'finanzas.ingresos.crear',$ctx['proceso'] ?? Auth::processId(),$ctx['organizacion']);
			$external=Values::text($data['donante_externo'] ?? '',180,true);
			if($person===null && $external==='') throw new \InvalidArgumentException('Indica donante.');
			$fields=['tipo'=>$type,'donante'=>$person,'donante_externo'=>$external,'concepto'=>Values::text($data['concepto'] ?? null,180)];
			$state='pendiente';
			if($type==='monetaria') {
				$income=(new TreasuryService($this->repository))->create([...$data,'fuente'=>$external!=='' ? $external : 'Aportación de persona registrada']);
				$fields['ingreso']=$income['id'];
				$state=$income['estado'];
			}
			else {
				$mode=$data['recepcion'] ?? 'posterior';
				if(!in_array($mode,['directa','posterior'],true)) throw new \InvalidArgumentException('Recepción inválida.');
				$this->context->require('movimientos','inventario.entradas',$ctx['proceso'] ?? Auth::processId(),true);
				$item=$this->reference('articulos',$data['articulo'] ?? null,$ctx['organizacion']) ?? throw new \InvalidArgumentException('Selecciona artículo.');
				$warehouse=$this->context->record('almacenes',$data['almacen'] ?? '', 'inventario.entradas',true);
				if((int) $warehouse['organizacion']!==$ctx['organizacion']) throw new HttpError('Almacén de otro contexto.',404);
				$fields=[...$fields,'articulo'=>$item,'almacen'=>$warehouse['id'],'cantidad'=>Amount::format(Amount::parse($data['cantidad'] ?? null,3),3),'valor'=>Amount::format(Amount::parse($data['costo_unitario'] ?? '0',2,true)),'recepcion'=>$mode];
			}
			$row=$this->repository->create('aportaciones',[...$this->document($ctx,$state),...$fields]);
			$this->context->history('aportaciones',$row,'registrar','',null,['tipo'=>$type,'recepcion'=>$row['recepcion'] ?? null,'estado'=>$state]);
			return Context::present($row);
		});
	}

	public function validate(string $uuid,array $data): array
	{
		return $this->repository->write($this->context->organization(),function() use($uuid,$data) {
			$row=$this->context->record('aportaciones',$uuid,'finanzas.ingresos.crear',true);
			if($row['tipo']!=='especie' || $row['estado']!=='pendiente') throw new HttpError('La donación ya fue validada o no es en especie.',409);
			$reason=Values::text($data['motivo'] ?? null,500);
			$this->repository->update('aportaciones',(int) $row['id'],['estado'=>'validada']);
			$this->context->history('aportaciones',$row,'validar',$reason,null,['estado'=>'validada']);
			if($row['recepcion']==='directa') return $this->receive($uuid,$data);
			(new Alerts($this->repository))->pendingReceipt($row,date('Y-m-d'));
			return $this->detail($uuid);
		});
	}

	public function receive(string $uuid,array $data): array
	{
		return $this->repository->write($this->context->organization(),function() use($uuid,$data) {
			// La recepción confirma una donación validada y requiere los dos módulos habilitados.
			$row=$this->context->record('aportaciones',$uuid,'finanzas.ingresos.crear',true);
			$this->context->require('movimientos','inventario.entradas',$row['proceso']===null ? Auth::processId() : (int) $row['proceso'],true,null,(int) $row['organizacion']);
			if($row['movimiento']!==null) return $this->detail($uuid);
			if($row['tipo']!=='especie' || $row['estado']!=='validada') throw new HttpError('Primero valida administrativamente la donación.',409);
			$item=$this->repository->raw('articulos',(int) $row['articulo']);
			$warehouse=$this->repository->raw('almacenes',(int) $row['almacen']);
			$movement=(new InventoryService($this->repository))->create(['proceso'=>$row['proceso']===null ? 'organizacion' : (int) $row['proceso'],'tipo'=>'donacion','articulo'=>Values::uuidText($item['uuid']),'almacen'=>Values::uuidText($warehouse['uuid']),'cantidad'=>$row['cantidad'],'costo_unitario'=>$row['valor'],'motivo'=>Values::text($data['motivo'] ?? 'Recepción de donación validada',500),'idempotencia'=>'donacion:'.$uuid,'fecha_operacion'=>$data['fecha_operacion'] ?? date('Y-m-d')]);
			$this->repository->update('aportaciones',(int) $row['id'],['movimiento'=>$movement['id'],'estado'=>'recibida']);
			$this->context->history('aportaciones',$row,'recibir',Values::text($data['motivo'] ?? 'Recepción de donación validada',500),null,['movimiento'=>$movement['folio']]);
			return $this->detail($uuid);
		});
	}

	public function cancel(string $uuid,array $data): array
	{
		return $this->repository->write($this->context->organization(),function() use($uuid,$data) {
			$row=$this->context->record('aportaciones',$uuid,'finanzas.ingresos.crear',true);
			$reason=Values::text($data['motivo'] ?? null,500);
			if($row['movimiento']!==null) throw new HttpError('La donación tiene recepción física; conserva la aportación y registra un ajuste de inventario.',409);
			if($row['ingreso']!==null) {
				$income=$this->repository->raw('ingresos',(int) $row['ingreso']);
				(new TreasuryService($this->repository))->cancel(Values::uuidText($income['uuid']),$data);
				return $this->detail($uuid);
			}
			$this->repository->update('aportaciones',(int) $row['id'],['estado'=>'cancelado']);
			$this->context->history('aportaciones',$row,'cancelar',$reason,null,['estado'=>'cancelado']);
			return $this->detail($uuid);
		});
	}
}
