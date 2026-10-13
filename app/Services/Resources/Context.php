<?php

declare(strict_types=1);

namespace App\Services\Resources;

use App\Core\Auth;
use App\Core\HttpError;
use App\Repositories\ResourcesRepository;
use App\Repositories\ModuleRepository;
use App\Repositories\PermissionRepository;
use App\Repositories\ScopeRepository;
use App\Services\Common\Access;
use App\Services\Common\AuditService;
use App\Services\Common\Values;

class Context
{
	public function __construct(public readonly ResourcesRepository $repository) {
	}

	public function actor(): int {
		return Auth::id() ?? throw new HttpError('No autenticado.',401);
	}

	public function process(mixed $reference = null): ?int
	{
		if ($reference==='organizacion') return null;
		$reference ??= Auth::processId();
		if ($reference===null) return null;
		return is_int($reference) ? $reference : (int) ($this->repository->one('SELECT id FROM procesos_electorales WHERE uuid=?',[Values::binary($reference)])['id'] ?? throw new HttpError('Elección no disponible.',404));
	}

	public function organization(?int $process = null): int
	{
		$process ??= Auth::processId();
		if ($process!==null) return (int) ($this->repository->one('SELECT organizacion FROM procesos_electorales WHERE id=? AND estatus=1',[$process])['organizacion'] ?? throw new HttpError('Elección no disponible.',404));
		return Auth::organizationId() ?? throw new HttpError('Selecciona una organización mediante la elección de trabajo.',422);
	}

	public function enabled(string $module,int $organization,?int $process): bool
	{
		foreach ((new ModuleRepository($this->repository->connection()))->catalog($organization,$process) as $row) if ($row['codigo']===$module) return (bool) $row['implementado'] && $row['habilitado'];
		return false;
	}

	public function require(string $entity,string $permission,?int $process = null,bool $write = false,?int $actor = null,?int $organization = null): array
	{
		$organization ??= $this->organization($process);
		$actor ??= $this->actor();
		$effective=(new Access($this->repository))->context($organization,$process,$permission,$actor);
		if ($write && !$this->enabled(Catalog::entity($entity)['module'],$organization,$process)) throw new HttpError('El módulo está deshabilitado. El historial permanece disponible en modo de consulta.',403);
		return ['organization'=>$organization,'process'=>$process,'actor'=>$actor,'effective'=>$effective];
	}

	public function broad(array $context,string $permission,bool $organizationOnly = false): bool
	{
		return $context['effective']['super'] || array_intersect($organizationOnly ? ['organizacion'] : ['organizacion','eleccion'],$context['effective']['scopes'][$permission] ?? [])!==[];
	}

	public function allowed(string $permission,?int $process = null,?int $actor = null): bool
	{
		try {
			(new PermissionRepository($this->repository->connection()))->require($actor ?? $this->actor(),$permission,$process ?? Auth::processId());
			return true;
		} catch(HttpError) {
			return false;
		}
	}

	private function subject(string $entity,string $permission,?int $process,int $actor,array $context): array
	{
		if ($this->broad($context,$permission,$process===null)) return ['1=1',[]];
		if (in_array($entity,['categorias','metodos','categorias_inventario','origenes','articulos'],true)) return ['1=1',[]];
		if (in_array($entity,['presupuestos','cuentas','politicas','transferencias','almacenes'],true)) return ['0=1',[]];
		[$scope,$parameters]=(new ScopeRepository($this->repository->connection()))->predicate($permission,$process,'p',$actor);
		$person=match($entity) {
			'gastos','anticipos','reembolsos'=>'beneficiario','aportaciones'=>'donante','movimientos'=>'responsable',default=>null
		};
		$parts=[];
		$params=[];
		if ($person!==null) {
			$parts[]="EXISTS (SELECT 1 FROM personas p WHERE p.id=r.$person AND ($scope))";
			$params=$parameters;
		}
		$parts[]="EXISTS (SELECT 1 FROM usuarios u JOIN personas p ON p.id=u.persona WHERE u.id=r.creado_por AND ($scope))";
		$params=[...$params,...$parameters];
		if (in_array('propio',$context['effective']['scopes'][$permission] ?? [],true)) {
			$parts[]='r.creado_por=?';
			$params[]=$actor;
		}
		if ($entity==='pagos') {
			$parts[]="EXISTS (SELECT 1 FROM finanzas_gastos g JOIN personas p ON p.id=g.beneficiario WHERE g.id=r.gasto AND ($scope))";
			$params=[...$params,...$parameters];
		}
		if ($entity==='devoluciones') {
			$parts[]="EXISTS (SELECT 1 FROM finanzas_anticipos ant JOIN personas p ON p.id=ant.beneficiario WHERE ant.id=r.anticipo AND ($scope))";
			$params=[...$params,...$parameters];
		}
		return ['('.implode(' OR ',$parts).')',$params];
	}

	public function predicate(string $entity,string $permission,?int $process = null,?int $actor = null,?int $organization = null,bool $filterProcess = true): array
	{
		$actor ??= $this->actor();
		$context=$this->require($entity,$permission,$process,false,$actor,$organization);
		$organization=$context['organization'];
		[$subject,$params]=$this->subject($entity,$permission,$process,$actor,$context);
		$where='r.organizacion=? AND ('.$subject.')';
		$parameters=[$organization,...$params];
		$isOrganization=in_array($entity,['categorias','metodos','cuentas','categorias_inventario','origenes','articulos','almacenes','movimientos'],true);
		if ($filterProcess && $process!==null && !$isOrganization) {
			if ($entity==='gastos') $where.=' AND (r.proceso=? OR EXISTS (SELECT 1 FROM finanzas_gastos_distribuciones d WHERE d.gasto=r.id AND d.proceso=?)'.($this->broad($context,$permission,true) ? ' OR r.proceso IS NULL' : '').')';
			elseif ($entity==='presupuestos') $where.=' AND (r.proceso=? OR EXISTS (SELECT 1 FROM finanzas_presupuestos_procesos d WHERE d.presupuesto=r.id AND d.proceso=?)'.($this->broad($context,$permission,true) ? ' OR r.proceso IS NULL' : '').')';
			else $where.=' AND (r.proceso=?'.($this->broad($context,$permission,true) ? ' OR r.proceso IS NULL' : '').')';
			$parameters[]=$process;
			if(in_array($entity,['gastos','presupuestos'],true)) $parameters[]=$process;
		}
		// Un gasto compartido sólo se entrega cuando todas sus elecciones y alcances están autorizados.
		if (in_array($entity,['gastos','presupuestos'],true) && !$context['effective']['super']) {
			$association=$entity==='gastos' ? 'finanzas_gastos_distribuciones' : 'finanzas_presupuestos_procesos';
			$key=$entity==='gastos' ? 'gasto' : 'presupuesto';
			$branches=[];
			$arguments=[];
			foreach ($this->repository->rows('SELECT id FROM procesos_electorales WHERE organizacion=? AND estatus=1',[$organization]) as $election) {
				try {
					$ctx=$this->require($entity,$permission,(int) $election['id'],false,$actor,$organization);
					[$condition,$values]=$this->subject($entity,$permission,(int) $election['id'],$actor,$ctx);
					$branches[]='(d.proceso=? AND ('.$condition.'))';
					$arguments=[...$arguments,$election['id'],...$values];
				} catch(HttpError) {
				}
			}
			$where.=" AND NOT EXISTS (SELECT 1 FROM $association d WHERE d.$key=r.id AND NOT (".($branches ? implode(' OR ',$branches) : '0=1').'))';
			$parameters=[...$parameters,...$arguments];
		}
		if ($entity==='almacenes' && str_starts_with($permission,'inventario.') && !$this->broad($context,$permission,true)) {
			$where.=' AND EXISTS (SELECT 1 FROM inventario_almacenes_usuarios au WHERE au.almacen=r.id AND au.usuario=?)';
			$parameters[]=$actor;
			$where=str_replace('(0=1)','(1=1)',$where);
		}
		if ($entity==='movimientos' && str_starts_with($permission,'inventario.') && !$this->broad($context,$permission,true)) {
			$where.=' AND EXISTS (SELECT 1 FROM inventario_almacenes_usuarios au WHERE au.almacen=r.almacen AND au.usuario=?)';
			$parameters[]=$actor;
		}
		if($entity==='movimientos' && str_starts_with($permission,'inventario.') && !$this->broad($context,$permission,true)) {
			$where.=' AND (r.proceso=? OR r.proceso IS NULL)';
			$parameters[]=$process;
		}
		if($entity==='pagos') {
			[$parent,$args]=$this->predicate('gastos',$permission,$process,$actor,$organization,false);
			$parent=str_replace('r.','g.',$parent);
			$where.=" AND EXISTS (SELECT 1 FROM finanzas_gastos g WHERE g.id=r.gasto AND ($parent))";
			$parameters=[...$parameters,...$args];
		}
		return [$where,$parameters];
	}

	public function record(string $entity,string|int $reference,string $permission,bool $write = false,?int $actor = null): array
	{
		$row=$this->repository->raw($entity,$reference,$write);
		$actor ??= $this->actor();
		if($write && (int) $row['organizacion']!==$this->organization()) throw new HttpError('Selecciona la organización del recurso para modificarlo.',404);
		$process=$row['proceso']===null ? Auth::processId() : (int) $row['proceso'];
		$effective=$this->require($entity,$permission,$process,$write,$actor,(int) $row['organizacion']);
		if($row['proceso']===null && !in_array($entity,['categorias','metodos','categorias_inventario','origenes','articulos','almacenes','movimientos','politicas'],true) && !$this->broad($effective,$permission,true)) throw new HttpError('El recurso de organización requiere alcance de organización.',403);
		[$where,$params]=$this->predicate($entity,$permission,$process,$actor,(int) $row['organizacion'],false);
		$table=Catalog::entity($entity)['table'];
		if (!$this->repository->one("SELECT r.id FROM $table r WHERE r.id=? AND ($where)".($write ? ' FOR UPDATE' : ''),[$row['id'],...$params])) throw new HttpError('Registro fuera de tu alcance.',404);
		if ($write && in_array($entity,['gastos','presupuestos'],true)) foreach($this->elections($entity,(int) $row['id']) as $election) $this->require($entity,$permission,$election,true,$actor,(int) $row['organizacion']);
		return $row;
	}

	public function elections(string $entity,int $id): array
	{
		return array_map('intval',array_column($this->repository->rows($entity==='gastos' ? 'SELECT proceso FROM finanzas_gastos_distribuciones WHERE gasto=? ORDER BY proceso' : 'SELECT proceso FROM finanzas_presupuestos_procesos WHERE presupuesto=? ORDER BY proceso',[$id]),'proceso'));
	}

	public function person(mixed $reference,string $permission,?int $process,int $organization,?int $actor = null): ?int
	{
		if ($reference===null || $reference==='') return null;
		$row=$this->repository->one('SELECT id FROM personas WHERE organizacion=? AND '.(is_int($reference) ? 'id' : 'uuid').'=?',[$organization,is_int($reference) ? $reference : Values::binary($reference)]) ?? throw new HttpError('Persona no disponible.',404);
		(new ScopeRepository($this->repository->connection()))->assertPerson((int) $row['id'],$permission,$process,$actor ?? $this->actor());
		return (int) $row['id'];
	}

	public function related(array $data,?int $process,int $organization): array
	{
		$entity=$data['relacion_entidad'] ?? null;
		$reference=$data['relacion'] ?? null;
		if (!$entity || !$reference) return ['relacion_entidad'=>null,'relacion_id'=>null];
		if (!in_array($entity,['eventos','peticiones','incidencias','tareas','transporte'],true) || $process===null) throw new \InvalidArgumentException('Relación operativa inválida.');
		$context=new \App\Services\Operations\Context(new \App\Repositories\OperationsRepository($this->repository->connection()));
		$row=$context->record($entity,$reference,\App\Services\Operations\Catalog::entity($entity)['permission'].'.ver',null,false,$process);
		if ((int) $row['organizacion']!==$organization) throw new HttpError('Referencia de otra organización.',404);
		return ['relacion_entidad'=>$entity,'relacion_id'=>(int) $row['id']];
	}

	public function history(string $entity,array $row,string $action,string $reason,?array $before = null,?array $after = null,?int $actor = null): void
	{
		$actor ??= $this->actor();
		$reason=Values::text($reason,500,true);
		$this->repository->execute('INSERT INTO recursos_historial (organizacion,proceso,entidad,registro,usuario,accion,motivo,antes,despues,fecha) VALUES (?,?,?,?,?,?,?,?,?,NOW())',[$row['organizacion'],$row['proceso'],Catalog::entity($entity)['table'],$row['id'],$actor,$action,$reason,$before===null ? null : Values::json($before),$after===null ? null : Values::json($after)]);
		(new AuditService($this->repository))->record((int) $row['organizacion'],$row['proceso']===null ? null : (int) $row['proceso'],$action,Catalog::entity($entity)['table'],(int) $row['id'],$before,$after,[],$actor);
	}

	public function version(array $row,array $data): void {
		if(isset($data['version']) && Values::integer($data['version'])!==(int) $row['version']) throw new HttpError('El registro cambió. Actualiza antes de guardar.',409);
	}

	public static function present(array $row): array
	{
		foreach($row as $key=>$value) if(is_string($value) && (strlen($value)===16 && ($key==='uuid' || in_array($key,['operacion','transferencia'],true)))) $row[$key]=Values::uuidText($value);
		foreach(['autorizacion','configuracion','antes','despues'] as $key) if(isset($row[$key]) && is_string($row[$key])) $row[$key]=json_decode($row[$key],true,512,JSON_THROW_ON_ERROR);
		return $row;
	}
}
