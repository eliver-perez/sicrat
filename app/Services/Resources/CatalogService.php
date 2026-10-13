<?php

declare(strict_types=1);

namespace App\Services\Resources;

use App\Core\Auth;
use App\Core\HttpError;
use App\Repositories\CommonRepository;
use App\Services\Common\Values;

class CatalogService extends BaseService
{
	public function __construct(CommonRepository $repository,string $entity) {
		parent::__construct($repository,$entity);
		if(!in_array($entity,['categorias','metodos','cuentas','categorias_inventario','origenes','articulos','almacenes'],true)) throw new \InvalidArgumentException('Catálogo no disponible.');
	}

	private function fields(array $data,int $organization): array
	{
		$fields=['nombre'=>Values::text($data['nombre'] ?? null,120),'activo'=>Values::integer($data['activo'] ?? 1,0,1)];
		if($this->entity!=='metodos') $fields['descripcion']=Values::text($data['descripcion'] ?? '',500,true);
		if($this->entity==='categorias') {
			$type=$data['tipo'] ?? 'gasto';
			if(!in_array($type,['gasto','ingreso'],true)) throw new \InvalidArgumentException('Tipo de categoría inválido.');
			$fields['tipo']=$type;
		}
		if($this->entity==='origenes') {
			$class=$data['clase'] ?? null;
			if(!in_array($class,['entrada','salida'],true)) throw new \InvalidArgumentException('Clase de origen inválida.');
			$fields['clase']=$class;
		}
		if($this->entity==='metodos') {
			$type=$data['tipo'] ?? 'efectivo';
			if(!in_array($type,['efectivo','transferencia','tarjeta','otro'],true)) throw new \InvalidArgumentException('Método de pago inválido.');
			$fields['tipo']=$type;
		}
		if($this->entity==='articulos') $fields=[...$fields,'codigo'=>Values::text($data['codigo'] ?? null,40),'categoria'=>$this->reference('categorias_inventario',$data['categoria'] ?? null,$organization) ?? throw new \InvalidArgumentException('Selecciona categoría.'),'unidad'=>Values::text($data['unidad'] ?? null,40),'minimo'=>Amount::format(Amount::parse($data['minimo'] ?? '0',3,true),3),'costo_referencia'=>isset($data['costo_referencia']) && $data['costo_referencia']!=='' ? Amount::format(Amount::parse($data['costo_referencia'],2,true)) : null];
		if($this->entity==='almacenes') $fields=[...$fields,'ubicacion'=>Values::text($data['ubicacion'] ?? '',300,true),'validar_mermas'=>Values::integer($data['validar_mermas'] ?? 0,0,1)];
		return $fields;
	}

	private function warehouseUsers(array $row,array $data): void
	{
		if($this->entity!=='almacenes' || !isset($data['responsables'])) return;
		$references=$data['responsables'];
		if(!is_array($references) || count($references)>100) throw new \InvalidArgumentException('Responsables inválidos.');
		$users=[];
		foreach($references as $reference) {
			$user=$this->repository->one('SELECT id FROM usuarios WHERE uuid=? AND organizacion=? AND activo=1',[Values::binary($reference),$row['organizacion']]) ?? throw new HttpError('Responsable no disponible.',404);
			$users[(int) $user['id']]=true;
		}
		$this->repository->execute('DELETE FROM inventario_almacenes_usuarios WHERE almacen=?',[$row['id']]);
		foreach(array_keys($users) as $id) $this->repository->execute('INSERT INTO inventario_almacenes_usuarios VALUES (?,?)',[$row['id'],$id]);
	}

	public function create(array $data): array
	{
		$ctx=$this->creation($data);
		$ctx['proceso']=null;
		$permission=Catalog::entity($this->entity)['write'];
		if(!$this->context->broad($this->context->require($this->entity,$permission,Auth::processId(),true),$permission,true)) throw new HttpError('Se requiere alcance de organización para administrar este catálogo.',403);
		return $this->repository->write($ctx['organizacion'],function() use($ctx,$data) {
			$row=$this->repository->create($this->entity,[...$ctx,...$this->fields($data,$ctx['organizacion'])]);
			$this->warehouseUsers($row,$data);
			$this->context->history($this->entity,$row,'crear','',null,$this->fields($data,$ctx['organizacion']));
			return Context::present($row);
		});
	}

	public function update(string $uuid,array $data): array
	{
		$organization=$this->context->organization();
		return $this->repository->write($organization,function() use($uuid,$data) {
			$permission=Catalog::entity($this->entity)['write'];
			$row=$this->context->record($this->entity,$uuid,$permission,true);
			$this->context->version($row,$data);
			if(!$this->context->broad($this->context->require($this->entity,$permission,Auth::processId(),true),$permission,true)) throw new HttpError('Se requiere alcance de organización.',403);
			$fields=$this->fields([...$row,...$data],(int) $row['organizacion']);
			if($this->entity==='categorias' && $fields['tipo']!==$row['tipo'] && $this->repository->one('SELECT id FROM finanzas_gastos WHERE categoria=? UNION SELECT id FROM finanzas_ingresos WHERE categoria=? LIMIT 1',[$row['id'],$row['id']])) throw new HttpError('La categoría usada conserva su tipo; crea otra categoría.',409);
			if($this->entity==='articulos' && $fields['unidad']!==$row['unidad'] && $this->repository->one('SELECT id FROM inventario_movimientos WHERE articulo=? LIMIT 1',[$row['id']])) throw new HttpError('La unidad de un artículo con movimientos conserva su significado histórico.',409);
			if($this->entity==='origenes' && $fields['clase']!==$row['clase'] && $this->repository->one('SELECT id FROM inventario_movimientos WHERE origen_catalogo=? LIMIT 1',[$row['id']])) throw new HttpError('El origen utilizado conserva su clase histórica.',409);
			$this->repository->update($this->entity,(int) $row['id'],$fields);
			$this->warehouseUsers($row,$data);
			$this->context->history($this->entity,$row,'editar',Values::text($data['motivo'] ?? null,500),array_intersect_key($row,$fields),$fields);
			return Context::present($this->repository->raw($this->entity,(int) $row['id']));
		});
	}

	protected function enrich(array $row): array
	{
		$row=parent::enrich($row);
		if($this->entity==='cuentas') $row['saldo']=($this->repository->one('SELECT COALESCE(SUM(signo*monto),0) saldo FROM finanzas_caja WHERE cuenta=?',[$row['id']]))['saldo'];
		if($this->entity==='articulos') $row['categoria_uuid']=Values::uuidText($this->repository->raw('categorias_inventario',(int) $row['categoria'])['uuid']);
		if($this->entity==='almacenes') {
			$row['responsables']=$this->repository->rows('SELECT u.uuid,u.nombre FROM inventario_almacenes_usuarios au JOIN usuarios u ON u.id=au.usuario WHERE au.almacen=? ORDER BY u.nombre',[$row['id']]);
			foreach($row['responsables'] as &$user) $user=Context::present($user);
		}
		return $row;
	}
}
