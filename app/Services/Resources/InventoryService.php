<?php

declare(strict_types=1);

namespace App\Services\Resources;

use App\Core\Auth;
use App\Core\HttpError;
use App\Repositories\CommonRepository;
use App\Services\Common\ApprovalService;
use App\Services\Common\Access;
use App\Services\Common\Values;

class InventoryService extends BaseService
{
	public function __construct(CommonRepository $repository) {
		parent::__construct($repository,'movimientos');
	}

	public function stock(int $item,int $warehouse): array
	{
		$row=$this->repository->one("SELECT COALESCE(SUM(cantidad),0) cantidad,COALESCE(SUM(valor),0) valor FROM inventario_movimientos WHERE articulo=? AND almacen=? AND estado='aplicado'".($this->repository->connection()->inTransaction() ? ' FOR UPDATE' : ''),[$item,$warehouse]);
		$quantity=Amount::signed($row['cantidad'],3);
		$value=Amount::signed($row['valor']);
		return ['cantidad'=>$row['cantidad'],'valor'=>$row['valor'],'costo_promedio'=>Amount::format($quantity>0 ? Amount::ratio($value,1000,$quantity) : 0)];
	}

	private function warehouse(mixed $reference,string $permission,int $organization): array
	{
		$row=$this->context->record('almacenes',$reference,$permission,true);
		if((int) $row['organizacion']!==$organization || !(int) $row['activo']) throw new HttpError('Almacén no disponible.',404);
		return $row;
	}

	private function movement(array $ctx,array $fields): array
	{
		$row=$this->repository->create('movimientos',[...$this->document($ctx,$fields['estado'] ?? 'aplicado'),...$fields]);
		$this->context->history('movimientos',$row,$row['tipo'],$row['motivo'],null,['articulo'=>$row['articulo'],'almacen'=>$row['almacen'],'cantidad'=>$row['cantidad'],'valor'=>$row['valor'],'estado'=>$row['estado'],'operacion'=>Values::uuidText($row['operacion'])]);
		return $row;
	}

	private function valuation(int $item,int $warehouse,int $out): int
	{
		$stock=$this->stock($item,$warehouse);
		$quantity=Amount::signed($stock['cantidad'],3);
		$value=Amount::signed($stock['valor']);
		if($out>$quantity) throw new HttpError('Existencia insuficiente. No se permiten saldos negativos.',409);
		return $out===$quantity ? $value : Amount::ratio($value,$out,$quantity);
	}

	public function create(array $data): array
	{
		$type=$data['tipo'] ?? null;
		$entries=['compra','donacion','entrada','ajuste_entrada','otro_entrada'];
		$exits=['entrega','consumo','prestamo','perdida','dano','ajuste_salida','otro_salida'];
		if(!in_array($type,[...$entries,...$exits],true)) throw new \InvalidArgumentException('Tipo de movimiento inválido.');
		$in=in_array($type,$entries,true);
		$permission=str_starts_with($type,'ajuste') ? 'inventario.ajustar' : ($in ? 'inventario.entradas' : 'inventario.salidas');
		$ctx=$this->creation($data,$permission);
		return $this->repository->write($ctx['organizacion'],function() use($data,$type,$in,$permission,$ctx) {
			$item=$this->reference('articulos',$data['articulo'] ?? null,$ctx['organizacion']) ?? throw new \InvalidArgumentException('Selecciona artículo.');
			$warehouse=$this->warehouse($data['almacen'] ?? '',$permission,$ctx['organizacion']);
			$quantity=Amount::parse($data['cantidad'] ?? null,3);
			$key=Values::text($data['idempotencia'] ?? null,80);
			$existing=$this->repository->one('SELECT * FROM inventario_movimientos WHERE organizacion=? AND idempotencia=?',[$ctx['organizacion'],$key]);
			if($existing) {
				if((int) $existing['articulo']!==$item || (int) $existing['almacen']!==(int) $warehouse['id'] || $existing['tipo']!==$type || Amount::signed($existing['cantidad'],3)!==($in ? $quantity : -$quantity)) throw new HttpError('La referencia corresponde a otro movimiento.',409);
				return Context::present($existing);
			}
			$origin=$this->reference('origenes',$data['origen_catalogo'] ?? null,$ctx['organizacion']);
			if(str_starts_with($type,'otro_') && $origin===null) throw new \InvalidArgumentException('Selecciona un origen configurable.');
			if($origin!==null && $this->repository->raw('origenes',$origin)['clase']!==($in ? 'entrada' : 'salida')) throw new \InvalidArgumentException('El origen no corresponde a la dirección del movimiento.');
			$reason=Values::text($data['motivo'] ?? null,500);
			$responsible=$this->context->person($data['responsable'] ?? null,$permission,$ctx['proceso'] ?? Auth::processId(),$ctx['organizacion']);
			$event=null;
			if(!empty($data['evento'])) $event=$this->context->related(['relacion_entidad'=>'eventos','relacion'=>$data['evento']],$ctx['proceso'],$ctx['organizacion'])['relacion_id'];
			$related=$this->context->related($data,$ctx['proceso'],$ctx['organizacion']);
			if($related['relacion_entidad']==='eventos') { if($event!==null && $event!==$related['relacion_id']) throw new \InvalidArgumentException('Selecciona una sola referencia de evento.'); $event=$related['relacion_id']; }
			$expense=null;
			$value=$in ? Amount::ratio(Amount::parse($data['costo_unitario'] ?? '0',2,true),$quantity,1000) : $this->valuation($item,(int) $warehouse['id'],$quantity);
			if(!empty($data['gasto'])) {
				$gas=$this->context->record('gastos',$data['gasto'],'finanzas.ver');
				if($type!=='compra' || $gas['tipo']!=='compra' || $gas['estado']==='cancelado' || (int) $gas['organizacion']!==$ctx['organizacion']) throw new HttpError('La recepción necesita una compra compatible.',422);
				$expense=(int) $gas['id'];
				$received=Amount::signed($this->repository->one("SELECT COALESCE(SUM(valor),0) valor FROM inventario_movimientos WHERE gasto=? AND estado='aplicado'",[$expense])['valor']);
				if(Amount::add($received,$value)>Amount::parse($gas['monto'])) throw new HttpError('El valor recibido excede el importe de la compra.',409);
			}
			$fields=[...$related,'origen_catalogo'=>$origin,'articulo'=>$item,'almacen'=>$warehouse['id'],'tipo'=>$type,'cantidad'=>Amount::format($in ? $quantity : -$quantity,3),'valor'=>Amount::format($in ? $value : -$value),'responsable'=>$responsible,'evento'=>$event,'gasto'=>$expense,'operacion'=>Values::binary(Values::uuid()),'idempotencia'=>$key,'motivo'=>$reason,'fecha_operacion'=>Values::date($data['fecha_operacion'] ?? date('Y-m-d'))];
			$approval=in_array($type,['perdida','dano'],true) && (int) $warehouse['validar_mermas']===1;
			if($approval) $fields['estado']='validacion';
			$row=$this->movement($ctx,$fields);
			if($approval) {
				$approver=$this->repository->one('SELECT id FROM usuarios WHERE uuid=? AND activo=1',[Values::binary($data['aprobador'] ?? null)])['id'] ?? throw new HttpError('Aprobador no disponible.',404);
				if((int) $approver===$this->context->actor()) throw new HttpError('La merma propia requiere otro aprobador.',403);
				$this->context->record('movimientos',(int) $row['id'],'inventario.ajustar',false,(int) $approver);
				$snapshot=['revision'=>(int) $row['version'],'solicitante'=>(int) $row['creado_por'],'cantidad'=>$row['cantidad'],'articulo'=>$item,'almacen'=>(int) $warehouse['id'],'aprobadores'=>[(int) $approver]];
				$uuid=(new ApprovalService($this->repository))->create($ctx['organizacion'],$ctx['proceso'],'inventario_movimientos',(int) $row['id'],$ctx['creado_por'],[(int) $approver],$snapshot,['operacion'=>'Validación de merma','folio'=>$row['folio']]);
				$id=$this->repository->one('SELECT id FROM aprobaciones WHERE uuid=?',[Values::binary($uuid)])['id'];
				$this->repository->execute('UPDATE inventario_movimientos SET aprobacion=? WHERE id=?',[$id,$row['id']]);
				(new Alerts($this->repository))->notify((int) $approver,$row,'Merma pendiente de validación','Revisa la solicitud de inventario asignada.','merma:'.$row['id']);
			}
			if($expense!==null) (new Alerts($this->repository))->notify((int) $this->repository->raw('gastos',$expense)['creado_por'],$row,'Compra recibida en almacén','Se registró una recepción física relacionada con la compra.','recepcion:'.$row['id']);
			return Context::present($this->repository->raw('movimientos',(int) $row['id']));
		});
	}

	public function transfer(array $data): array
	{
		$ctx=$this->creation($data,'inventario.transferir');
		return $this->repository->write($ctx['organizacion'],function() use($ctx,$data) {
			$item=$this->reference('articulos',$data['articulo'] ?? null,$ctx['organizacion']) ?? throw new \InvalidArgumentException('Selecciona artículo.');
			$origin=$this->warehouse($data['origen'] ?? '', 'inventario.transferir',$ctx['organizacion']);
			$dest=$this->warehouse($data['destino'] ?? '', 'inventario.transferir',$ctx['organizacion']);
			if($origin['id']===$dest['id']) throw new \InvalidArgumentException('Selecciona almacenes distintos.');
			$quantity=Amount::parse($data['cantidad'] ?? null,3);
			$key=Values::text($data['idempotencia'] ?? null,65);
			$existing=$this->repository->one('SELECT * FROM inventario_movimientos WHERE organizacion=? AND idempotencia=?',[$ctx['organizacion'],$key.':salida']);
			if($existing) {
				$entry=$this->repository->one('SELECT * FROM inventario_movimientos WHERE operacion=? AND tipo=\'transferencia_in\'',[$existing['operacion']]);
				if((int) $existing['articulo']!==$item || (int) $existing['almacen']!==(int) $origin['id'] || Amount::signed($existing['cantidad'],3)!==-$quantity || (int) ($entry['almacen'] ?? 0)!==(int) $dest['id']) throw new HttpError('La referencia corresponde a otra transferencia.',409);
				return ['operacion'=>Values::uuidText($existing['operacion'])];
			}
			$value=$this->valuation($item,(int) $origin['id'],$quantity);
			$operation=Values::uuid();
			$reason=Values::text($data['motivo'] ?? null,500);
			foreach([[$origin,-1,'transferencia_out',':salida'],[$dest,1,'transferencia_in',':entrada']] as [$warehouse,$sign,$type,$suffix]) $this->movement($ctx,['articulo'=>$item,'almacen'=>$warehouse['id'],'tipo'=>$type,'cantidad'=>Amount::format($sign*$quantity,3),'valor'=>Amount::format($sign*$value),'operacion'=>Values::binary($operation),'idempotencia'=>$key.$suffix,'motivo'=>$reason,'fecha_operacion'=>Values::date($data['fecha_operacion'] ?? date('Y-m-d'))]);
			return ['operacion'=>$operation];
		});
	}

	public function returnItems(string $uuid,array $data): array
	{
		return $this->repository->write($this->context->organization(),function() use($uuid,$data) {
			$row=$this->context->record('movimientos',$uuid,'inventario.entradas',true);
			$this->warehouse((int) $row['almacen'],'inventario.entradas',(int) $row['organizacion']);
			if($row['estado']!=='aplicado' || !in_array($row['tipo'],['entrega','consumo','prestamo'],true) || $this->repository->one('SELECT id FROM inventario_movimientos WHERE reversa_de=?',[$row['id']])) throw new HttpError('El movimiento no admite devoluciones.',409);
			$quantity=Amount::parse($data['cantidad'] ?? null,3);
			$key=Values::text($data['idempotencia'] ?? null,80);
			$existing=$this->repository->one('SELECT * FROM inventario_movimientos WHERE organizacion=? AND idempotencia=?',[$row['organizacion'],$key]);
			if($existing) {
				if((int) $existing['origen']!==(int) $row['id'] || $existing['cantidad']!==Amount::format($quantity,3)) throw new HttpError('Referencia usada para otra devolución.',409);
				return Context::present($existing);
			}
			$returned=$this->repository->one("SELECT COALESCE(SUM(cantidad),0) cantidad,COALESCE(SUM(valor),0) valor FROM inventario_movimientos WHERE origen=? AND tipo='devolucion' AND estado='aplicado'",[$row['id']]);
			$remaining=-Amount::signed($row['cantidad'],3)-Amount::signed($returned['cantidad'],3);
			if($quantity>$remaining) throw new HttpError('La devolución excede lo entregado pendiente de devolver.',409);
			$remainingValue=-Amount::signed($row['valor'])-Amount::signed($returned['valor']);
			$value=$quantity===$remaining ? $remainingValue : min($remainingValue,Amount::ratio(-Amount::signed($row['valor']),$quantity,-Amount::signed($row['cantidad'],3)));
			$ctx=['organizacion'=>$row['organizacion'],'proceso'=>$row['proceso'],'creado_por'=>$this->context->actor()];
			$return=$this->movement($ctx,['articulo'=>$row['articulo'],'almacen'=>$row['almacen'],'tipo'=>'devolucion','cantidad'=>Amount::format($quantity,3),'valor'=>Amount::format($value),'origen'=>$row['id'],'evento'=>$row['evento'],'relacion_entidad'=>$row['relacion_entidad'],'relacion_id'=>$row['relacion_id'],'responsable'=>$row['responsable'],'operacion'=>Values::binary(Values::uuid()),'idempotencia'=>$key,'motivo'=>Values::text($data['motivo'] ?? null,500),'fecha_operacion'=>Values::date($data['fecha_operacion'] ?? date('Y-m-d'))]);
			return Context::present($return);
		});
	}

	public function reverse(string $uuid,array $data): array
	{
		return $this->repository->write($this->context->organization(),function() use($uuid,$data) {
			$row=$this->context->record('movimientos',$uuid,'inventario.ajustar',true);
			$reason=Values::text($data['motivo'] ?? null,500);
			$rows=str_starts_with($row['tipo'],'transferencia_') ? $this->repository->rows('SELECT * FROM inventario_movimientos WHERE operacion=? ORDER BY cantidad ASC',[$row['operacion']]) : [$row];
			$operation=Values::uuid();
			foreach($rows as $original) {
				$this->warehouse((int) $original['almacen'],'inventario.ajustar',(int) $row['organizacion']);
				if($original['estado']!=='aplicado' || $original['reversa_de']!==null || $original['origen']!==null || $this->repository->one('SELECT id FROM inventario_movimientos WHERE reversa_de=? OR origen=? LIMIT 1',[$original['id'],$original['id']])) throw new HttpError('El movimiento tiene devoluciones o una reversión; conserva su historia.',409);
				$quantity=Amount::signed($original['cantidad'],3);
				$value=Amount::signed($original['valor']);
				$stock=$this->stock((int) $original['articulo'],(int) $original['almacen']);
				if(Amount::signed($stock['cantidad'],3)-$quantity<0 || Amount::signed($stock['valor'])-$value<0) throw new HttpError('Las existencias recibidas ya fueron utilizadas. Registra un ajuste autorizado.',409);
				if($quantity>0 && !str_starts_with($original['tipo'],'transferencia_') && $this->repository->one("SELECT id FROM inventario_movimientos WHERE articulo=? AND almacen=? AND id>? AND cantidad<0 AND estado='aplicado' LIMIT 1",[$original['articulo'],$original['almacen'],$original['id']])) throw new HttpError('La entrada tiene salidas posteriores; conserva la recepción y registra un ajuste.',409);
				$this->movement(['organizacion'=>$original['organizacion'],'proceso'=>$original['proceso'],'creado_por'=>$this->context->actor()],['articulo'=>$original['articulo'],'almacen'=>$original['almacen'],'tipo'=>'reversa','cantidad'=>Amount::format(-$quantity,3),'valor'=>Amount::format(-$value),'gasto'=>$original['gasto'],'evento'=>$original['evento'],'relacion_entidad'=>$original['relacion_entidad'],'relacion_id'=>$original['relacion_id'],'reversa_de'=>$original['id'],'operacion'=>Values::binary($operation),'idempotencia'=>'reversa:'.Values::uuidText($original['uuid']),'motivo'=>$reason,'fecha_operacion'=>Values::date($data['fecha_operacion'] ?? date('Y-m-d'))]);
			}
			return ['operacion'=>$operation];
		});
	}

	public function decision(array $approval,string $state,int $actor): void
	{
		$row=$this->repository->raw('movimientos',(int) $approval['entidad_id'],true);
		$snapshot=json_decode($approval['regla'],true,512,JSON_THROW_ON_ERROR);
		if((int) $row['aprobacion']!==(int) $approval['id'] || (int) $row['version']!==(int) $snapshot['revision']) throw new HttpError('La merma cambió; requiere nueva validación.',409);
		if($state==='cancelada' && !$this->context->enabled('inventario',(int) $row['organizacion'],$row['proceso']===null ? Auth::processId() : (int) $row['proceso'])) throw new HttpError('El módulo deshabilitado no permite cancelar la validación de merma.',403);
		$fields=['estado'=>$state==='aprobada' ? 'aplicado' : 'rechazado'];
		if($state==='aprobada') {
			if($actor===(int) $row['creado_por']) throw new HttpError('No se permite autorizar la merma propia.',403);
			$this->context->record('movimientos',(int) $row['id'],'inventario.ajustar',true,$actor);
			$this->warehouse((int) $row['almacen'],'inventario.ajustar',(int) $row['organizacion']);
			$fields['valor']=Amount::format(-$this->valuation((int) $row['articulo'],(int) $row['almacen'],-Amount::signed($row['cantidad'],3)));
		}
		$this->repository->update('movimientos',(int) $row['id'],$fields);
		$this->context->history('movimientos',$row,'resolver_merma','',null,$fields,$actor);
		(new Alerts($this->repository))->notify((int) $row['creado_por'],$row,'Merma revisada','Consulta el resultado de la validación.','merma_resultado:'.$row['id']);
	}

	public function stocks(array $filters = []): array
	{
		[$where,$params]=$this->context->predicate('almacenes','inventario.ver',Auth::processId());
		$where=str_replace('r.','warehouse.',$where);
		$search='';
		if(!empty($filters['search'])) {
			$search=' AND (item.nombre LIKE ? OR item.codigo LIKE ?)';
			$value='%'.Values::text($filters['search'],150,true).'%';
			$params[]=$value;
			$params[]=$value;
		}
		$sql="SELECT item.uuid articulo_uuid,item.nombre articulo,item.codigo,item.unidad,warehouse.uuid almacen_uuid,warehouse.nombre almacen,COALESCE(stock.cantidad,0) cantidad,COALESCE(stock.valor,0) valor,COALESCE(minimum.cantidad,item.minimo) minimo FROM inventario_articulos item JOIN inventario_almacenes warehouse ON warehouse.organizacion=item.organizacion LEFT JOIN (SELECT articulo,almacen,SUM(cantidad) cantidad,SUM(valor) valor FROM inventario_movimientos WHERE estado='aplicado' GROUP BY articulo,almacen) stock ON stock.articulo=item.id AND stock.almacen=warehouse.id LEFT JOIN inventario_minimos minimum ON minimum.articulo=item.id AND minimum.almacen=warehouse.id WHERE ($where)$search";
		if(!empty($filters['bajo_minimo'])) $sql.=' AND COALESCE(stock.cantidad,0)<COALESCE(minimum.cantidad,item.minimo)';
		$result=$this->repository->page($sql.' ORDER BY item.nombre,warehouse.nombre,item.id,warehouse.id',$params,$filters);
		foreach($result['rows'] as &$row) {
			foreach(['articulo_uuid','almacen_uuid'] as $key) $row[$key]=Values::uuidText($row[$key]);
			$quantity=Amount::signed((string) $row['cantidad'],3);
			$row['costo_promedio']=Amount::format($quantity>0 ? Amount::ratio(Amount::signed((string) $row['valor']),1000,$quantity) : 0);
			$row['bajo_minimo']=$quantity<Amount::parse((string) $row['minimo'],3,true);
		}
		return $result;
	}

	public function minimum(array $data): array
	{
		$ctx=$this->creation($data,'inventario.articulos');
		return $this->repository->write($ctx['organizacion'],function() use($ctx,$data) {
			$item=$this->reference('articulos',$data['articulo'] ?? null,$ctx['organizacion']) ?? throw new \InvalidArgumentException('Selecciona artículo.');
			$warehouse=$this->warehouse($data['almacen'] ?? '', 'inventario.articulos',$ctx['organizacion']);
			$quantity=Amount::format(Amount::parse($data['cantidad'] ?? null,3,true),3);
			$this->repository->execute('INSERT INTO inventario_minimos VALUES (?,?,?) ON DUPLICATE KEY UPDATE cantidad=VALUES(cantidad)',[$item,$warehouse['id'],$quantity]);
			$this->context->history('articulos',$this->repository->raw('articulos',$item),'minimo_almacen',Values::text($data['motivo'] ?? null,500),null,['cantidad'=>$quantity,'almacen'=>$warehouse['nombre']]);
			return ['cantidad'=>$quantity];
		});
	}
}
