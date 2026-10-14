<?php

declare(strict_types=1);

namespace App\Services\Resources;

use App\Core\Auth;
use App\Core\HttpError;
use App\Repositories\CommonRepository;
use App\Repositories\ResourcesRepository;
use App\Repositories\ScopeRepository;
use App\Services\Common\Values;

final class Options
{
	private ResourcesRepository $repository;
	private Context $context;
	public function __construct(CommonRepository $repository) {
		$this->repository=new ResourcesRepository($repository->connection());
		$this->context=new Context($this->repository);
	}

	public function listing(array $filters): array
	{
		$type=$filters['tipo'] ?? null;
		$permission=Values::text($filters['permiso'] ?? 'finanzas.ver',128);
		if(!preg_match('/^(finanzas|inventario)\./D',$permission)) throw new \InvalidArgumentException('Permiso de opciones inválido.');
		$process=Auth::processId();
		$organization=$this->context->organization();
		$entity=str_starts_with($permission,'inventario.') ? 'articulos' : 'gastos';
		$ctx=$this->context->require($entity,$permission,$process);
		$search='%'.Values::text($filters['search'] ?? '',150,true).'%';
		$params=[$organization];
		if($type==='personas') {
			[$where,$args]=(new ScopeRepository($this->repository->connection()))->predicate($permission,$process,'p');
			$sql="SELECT p.uuid,CONCAT_WS(' ',p.nombre,p.paterno,p.materno) nombre,p.codigo_persona FROM personas p WHERE p.organizacion=? AND ($where) AND (CONCAT_WS(' ',p.nombre,p.paterno,p.materno) LIKE ? OR p.codigo_persona LIKE ?) ORDER BY p.nombre,p.id";
			$params=[...$params,...$args,$search,$search];
		}
		elseif($type==='usuarios') {
			if(!$this->context->broad($ctx,$permission)) throw new HttpError('Seleccionar cuentas requiere alcance amplio.',403);
			$sql='SELECT u.uuid,u.nombre FROM usuarios u WHERE u.organizacion=? AND u.activo=1 AND u.nombre LIKE ? ORDER BY u.nombre,u.id';
			$params[]=$search;
		}
		elseif($type==='roles') {
			if(!$this->context->broad($ctx,$permission)) throw new HttpError('Seleccionar roles requiere alcance amplio.',403);
			$sql="SELECT codigo uuid,tipo nombre FROM usuarios_tipos WHERE (organizacion=? OR organizacion IS NULL) AND codigo<>'superadmin' AND tipo LIKE ? ORDER BY tipo,id";
			$params[]=$search;
		}
		elseif(in_array($type,['eventos','tareas','peticiones','incidencias','transporte'],true)) {
			$ops=new \App\Services\Operations\Context(new \App\Repositories\OperationsRepository($this->repository->connection()));
			$definition=\App\Services\Operations\Catalog::entity($type);
			[$where,$args]=$ops->predicate($type,(int) $process,$definition['permission'].'.ver');
			$name=$definition['name'];
			$joins='';
			if($type==='transporte') {
				$joins=' JOIN personas p ON p.id=r.persona';
				$label="CONCAT_WS(' ',p.nombre,p.paterno,p.materno)";
			} else $label="r.$name";
			$sql="SELECT r.uuid,$label nombre FROM $type r $joins WHERE ($where) AND $label LIKE ? ORDER BY r.id DESC";
			$params=[...$args,$search];
		}
		elseif($type==='elecciones') {
			$result=$this->repository->page('SELECT uuid,proceso nombre,id FROM procesos_electorales WHERE organizacion=? AND estatus=1 AND proceso LIKE ? ORDER BY proceso,id',[$organization,$search],$filters);
			$result['rows']=array_values(array_filter($result['rows'],fn($row)=>$this->context->allowed($permission,(int) $row['id'])));
			foreach($result['rows'] as &$row) {
				unset($row['id']);
				$row=Context::present($row);
			}
			return $result;
		} elseif(isset(Catalog::ENTITIES[$type])) {
			$definition=Catalog::entity($type);
			$table=$definition['table'];
			$name=in_array($type,['categorias','metodos','cuentas','politicas','presupuestos','articulos','almacenes','categorias_inventario','origenes'],true) ? 'nombre' : 'concepto';
			$where='r.organizacion=?';
            if($type==='categorias'){if(str_starts_with($permission,'finanzas.ingresos.'))$where.=" AND r.tipo='ingreso'";elseif(str_starts_with($permission,'finanzas.gastos.') || str_starts_with($permission,'finanzas.reembolsos.') || str_starts_with($permission,'finanzas.presupuestos.'))$where.=" AND r.tipo='gasto'";}
			$params=[$organization];
			if(in_array($type,['presupuestos','gastos','anticipos','reembolsos'],true)) {
				[$where,$params]=$this->context->predicate($type,$permission,$process);
			}
			if($type==='almacenes') {
				[$where,$params]=$this->context->predicate($type,$permission,$process);
			}
			$active=in_array($type,['categorias','metodos','cuentas','articulos','almacenes','categorias_inventario','origenes'],true) ? ' AND r.activo=1' : '';
			$sql="SELECT r.uuid,r.$name nombre FROM $table r WHERE ($where)$active AND r.$name LIKE ? ORDER BY r.$name,r.id";
			$params[]=$search;
		} else throw new \InvalidArgumentException('Opciones no disponibles.');
		if(!empty($filters['reference'])) {
			$sql='SELECT * FROM ('.$sql.') authorized_options WHERE uuid=?';
			$params[]=$type==='roles' ? Values::text($filters['reference'],40) : Values::binary($filters['reference']);
		}
		$result=$this->repository->page($sql,$params,$filters);
		foreach($result['rows'] as &$row) if($type!=='roles') $row=Context::present($row);
		return $result;
	}
}
