<?php

declare(strict_types=1);

namespace App\Services\Resources;

use App\Core\Auth;
use App\Core\HttpError;
use App\Repositories\CommonRepository;
use App\Repositories\ResourcesRepository;
use App\Services\Common\Values;

class BaseService
{
	protected ResourcesRepository $repository;
	protected Context $context;
	public function __construct(CommonRepository $repository,protected string $entity)
	{
		Catalog::entity($entity);
		$this->repository=new ResourcesRepository($repository->connection());
		$this->context=new Context($this->repository);
	}

	protected function creation(array $data,?string $permission = null): array
	{
		$process=$this->context->process($data['proceso'] ?? null);
		$permission ??= Catalog::entity($this->entity)['write'];
		$ctx=$this->context->require($this->entity,$permission,$process ?? Auth::processId(),true);
		if ($process===null && !$this->context->broad($ctx,$permission,true)) throw new HttpError('El registro de organización requiere alcance de organización.',403);
		return ['organizacion'=>$ctx['organization'],'proceso'=>$process,'creado_por'=>$ctx['actor']];
	}

	protected function document(array $context,string $state): array {
		return [...$context,'folio'=>$this->repository->folio($context['organizacion'],$this->entity),'estado'=>$state];
	}

	protected function reference(string $entity,mixed $reference,int $organization,bool $active = true): ?int
	{
		if ($reference===null || $reference==='') return null;
		$row=$this->repository->raw($entity,$reference);
		if ((int) $row['organizacion']!==$organization || ($active && isset($row['activo']) && !(int) $row['activo'])) throw new HttpError('Referencia no disponible.',404);
		return (int) $row['id'];
	}

	public function listing(array $filters = [],?string $permission = null): array
	{
		$permission ??= Catalog::entity($this->entity)['read'];
		$process=$this->context->process($filters['proceso'] ?? null);
		[$where,$params]=$this->context->predicate($this->entity,$permission,$process ?? Auth::processId());
		if(isset($filters['_scope_permission'])) {
			[$extra,$args]=$this->context->predicate($this->entity,$filters['_scope_permission'],$process ?? Auth::processId());
			$where.=' AND ('.$extra.')';
			$params=[...$params,...$args];
		}
		$table=Catalog::entity($this->entity)['table'];
		if (($filters['search'] ?? '')!=='') {
			$search='%'.Values::text($filters['search'],150,true).'%';
			$name=in_array($this->entity,['categorias','metodos','cuentas','politicas','presupuestos','articulos','almacenes','categorias_inventario','origenes'],true) ? 'nombre' : (in_array($this->entity,['gastos','anticipos','reembolsos','ingresos','aportaciones'],true) ? 'concepto' : 'folio');
			$document=in_array($this->entity,['presupuestos','gastos','anticipos','reembolsos','ingresos','aportaciones','pagos','transferencias','movimientos'],true);
			$where.=$document && $name!=='folio' ? " AND (r.$name LIKE ? OR r.folio LIKE ?)" : " AND r.$name LIKE ?";
			$params[]=$search;
			if($document && $name!=='folio') $params[]=$search;
		}
		foreach(['estado','categoria','desde','hasta'] as $key) if(isset($filters[$key]) && $filters[$key]!=='') {
			if ($key==='estado' && $this->entity==='anticipos' && in_array($filters[$key],['pendiente','parcial','comprobado','vencido'],true)) {
				$where.=' AND ('.(new AdvanceService($this->repository))->statePredicate($filters[$key]).')';
			}
			elseif ($key==='estado') {
				$column=$this->hasState() ? 'estado' : 'activo';
				$where.=" AND r.$column=?";
				$params[]=Values::text((string) $filters[$key],24);
			}
			elseif($key==='categoria') {
				if(!in_array($this->entity,['gastos','presupuestos','ingresos','reembolsos','articulos'],true)) throw new \InvalidArgumentException('Filtro de categoría no disponible.');
				$related=$this->entity==='articulos' ? 'categorias_inventario' : 'categorias';
				$id=$this->reference($related,$filters[$key],$this->context->organization(),false);
				$where.=' AND r.categoria=?';
				$params[]=$id;
			}
			else {
				$dateColumn=in_array($this->entity,['gastos','pagos','ingresos','movimientos'],true) ? 'fecha_operacion' : 'fecha';
				$where.=' AND r.'.$dateColumn.' '.($key==='desde' ? '>=' : '<').' ?';
				$params[]=$key==='desde' ? Values::date($filters[$key]) : (new \DateTimeImmutable(Values::date($filters[$key])))->modify('+1 day')->format('Y-m-d');
			}
		}
		if(!empty($filters['tipo'])) {
			if(!in_array($this->entity,['gastos','movimientos','aportaciones'],true)) throw new \InvalidArgumentException('Filtro de tipo no disponible.');
			$where.=' AND r.tipo=?';
			$params[]=Values::text($filters['tipo'],24);
		}
		foreach(['articulo'=>'articulos','almacen'=>'almacenes'] as $key=>$entity) if(!empty($filters[$key])) {
			if($this->entity!=='movimientos') throw new \InvalidArgumentException('Filtro de inventario no disponible.');
			$reference=$this->context->record($entity,$filters[$key],$permission);
			$where.=" AND r.$key=?";
			$params[]=$reference['id'];
		}
		$joins=' LEFT JOIN usuarios creator ON creator.id=r.creado_por';
		$extra=',creator.nombre creador';
		if(in_array($this->entity,['gastos','presupuestos','ingresos','reembolsos','articulos'],true)) {
			$cat=$this->entity==='articulos' ? 'inventario_categorias' : 'finanzas_categorias';
			$joins.=" LEFT JOIN $cat cat ON cat.id=r.categoria";
			$extra.=',cat.nombre categoria_nombre';
		}
		if(in_array($this->entity,['gastos','anticipos','reembolsos'],true)) {
			$joins.=' LEFT JOIN personas person ON person.id=r.beneficiario';
			$extra.=",CONCAT_WS(' ',person.nombre,person.paterno,person.materno) beneficiario_nombre";
		}
		if($this->entity==='movimientos') {
			$joins.=' JOIN inventario_articulos art ON art.id=r.articulo JOIN inventario_almacenes store ON store.id=r.almacen';
			$extra.=',art.nombre articulo_nombre,store.nombre almacen_nombre';
		}
		if($this->entity==='pagos') {
			$joins.=' JOIN finanzas_gastos gas ON gas.id=r.gasto';
			$extra.=',gas.folio gasto_folio,gas.concepto concepto';
		}
		if(isset($filters['activo']) && !$this->hasState()) {
			$where.=' AND r.activo=?';
			$params[]=Values::integer($filters['activo'],0,1);
		}
		$result=$this->repository->page("SELECT r.*$extra FROM $table r $joins WHERE $where ORDER BY r.id DESC",$params,$filters);
		foreach($result['rows'] as &$row) $row=Context::present($this->enrich($row));
		return $result;
	}

	protected function hasState(): bool {
		return !in_array($this->entity,['categorias','metodos','cuentas','politicas','articulos','almacenes','categorias_inventario','origenes'],true);
	}

	protected function enrich(array $row): array
	{
		if(isset($row['categoria'])) {
			$category=$this->repository->raw($this->entity==='articulos' ? 'categorias_inventario' : 'categorias',(int) $row['categoria']);
			$row['categoria_nombre']=$category['nombre'];
			$row['categoria_uuid']=Values::uuidText($category['uuid']);
		}
		foreach(['beneficiario','responsable','donante'] as $key) if(isset($row[$key])) {
			$person=$this->repository->one("SELECT uuid,CONCAT_WS(' ',nombre,paterno,materno) nombre FROM personas WHERE id=?",[$row[$key]]);
			if($person) {
				$row[$key.'_nombre']=$person['nombre'];
				$row[$key.'_uuid']=Values::uuidText($person['uuid']);
			}
		}
		foreach(['cuenta'=>'cuentas','metodo'=>'metodos'] as $key=>$entity) if(isset($row[$key])) $row[$key.'_nombre']=$this->repository->raw($entity,(int) $row[$key])['nombre'];
		return $row;
	}

	public function detail(string $uuid): array {
		return Context::present($this->enrich($this->context->record($this->entity,$uuid,Catalog::entity($this->entity)['read'])));
	}

	public function history(string $uuid,array $filters): array
	{
		$row=$this->context->record($this->entity,$uuid,Catalog::entity($this->entity)['read']);
		$result=$this->repository->page('SELECT h.accion,h.motivo,h.antes,h.despues,h.fecha,u.nombre actor FROM recursos_historial h JOIN usuarios u ON u.id=h.usuario WHERE h.entidad=? AND h.registro=? ORDER BY h.id DESC',[Catalog::entity($this->entity)['table'],$row['id']],$filters);
		foreach($result['rows'] as &$entry) $entry=Context::present($entry);
		return $result;
	}

	public function files(string $uuid,array $filters): array
	{
		$row=$this->context->record($this->entity,$uuid,Catalog::entity($this->entity)['read']);
		$this->context->record($this->entity,$uuid,'archivos.ver');
		$result=$this->repository->page("SELECT uuid,nombre_original,mime_type,tamanio FROM archivos WHERE entidad=? AND entidad_id=? AND estado='activo' ORDER BY id DESC",[Catalog::entity($this->entity)['table'],$row['id']],$filters);
		foreach($result['rows'] as &$entry) $entry=Context::present($entry);
		return $result;
	}

	public function create(array $data): array {
		throw new HttpError('Usa la acción específica para este recurso.',405);
	}

	public function update(string $uuid,array $data): array {
		throw new HttpError('Este registro conserva su historial; usa su acción específica.',405);
	}
}
