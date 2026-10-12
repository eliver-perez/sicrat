<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Core\Auth;
use App\Core\HttpError;
use App\Repositories\OperationsRepository;
use App\Repositories\ModuleRepository;
use App\Repositories\PermissionRepository;
use App\Repositories\ScopeRepository;
use App\Services\Common\Access;
use App\Services\Common\AuditService;
use App\Services\Common\Values;

class Context
{
	public function __construct(public readonly OperationsRepository $repository) {}
	public function actor(): int { return Auth::id() ?? throw new HttpError('No autenticado.',401); }
	public function process(mixed $process = null): int
	{
		$process ??= Auth::processId();
		if (is_string($process) && str_contains($process,'-')) $process=$this->repository->one('SELECT id FROM procesos_electorales WHERE uuid=?',[Values::binary($process)])['id'] ?? throw new HttpError('Elección no disponible.',404);
		return Values::integer($process);
	}
	public function require(string $entity,int $process,string $permission,?int $actor = null): array
	{
		$definition=Catalog::entity($entity);
		$organization=$this->repository->one('SELECT organizacion FROM procesos_electorales WHERE id=? AND estatus=1',[$process])['organizacion'] ?? throw new HttpError('Elección no disponible.',404);
		$user=$this->repository->one('SELECT u.organizacion,t.codigo FROM usuarios u JOIN usuarios_tipos t ON t.id=u.tipo_usuario WHERE u.id=? AND u.activo=1',[$actor ?? $this->actor()]) ?? throw new HttpError('Cuenta no disponible.',401);
		if ($user['codigo']!=='superadmin' && (int) $user['organizacion']!==(int) $organization) throw new HttpError('Elección no disponible.',404);
		$effective=(new Access($this->repository))->context((int) $organization,$process,$permission,$actor ?? $this->actor());
		(new ModuleRepository($this->repository->connection()))->require($definition['module'],$process);
		return ['organization'=>(int) $organization,'process'=>$process,'actor'=>$actor ?? $this->actor(),'effective'=>$effective];
	}
	public function allowed(string $permission,int $process,?int $actor = null): bool
	{
		try { (new PermissionRepository($this->repository->connection()))->require($actor ?? $this->actor(),$permission,$process); return true; }
		catch (HttpError) { return false; }
	}
	public function person(mixed $reference,string $permission,int $process,bool $participation = true,?int $actor = null): array
	{
		$person=$this->repository->one('SELECT * FROM personas WHERE '.(is_int($reference) || (is_string($reference) && ctype_digit($reference)) ? 'id' : 'uuid').'=?',[is_int($reference) || (is_string($reference) && ctype_digit($reference)) ? Values::integer($reference) : Values::binary($reference)]) ?? throw new HttpError('Persona no disponible.',404);
		(new ScopeRepository($this->repository->connection()))->assertPerson((int) $person['id'],$permission,$process,$actor ?? $this->actor());
		$organization=$this->repository->one('SELECT organizacion FROM procesos_electorales WHERE id=?',[$process])['organizacion'] ?? null;
		if ((int) $person['organizacion']!==(int) $organization) throw new HttpError('Persona de otro contexto.',404);
		$person['participation']=$this->repository->one('SELECT id FROM personas_procesos WHERE persona=? AND proceso=? AND activo=1',[$person['id'],$process])['id'] ?? null;
		if ($participation && $person['participation']===null) throw new HttpError('La persona necesita participación activa en esta elección.',422);
		return $person;
	}
	public function broad(array $context,string $permission): bool
	{
		return $context['effective']['super'] || array_intersect(['eleccion','organizacion'],$context['effective']['scopes'][$permission] ?? [])!==[];
	}
	public function predicate(string $entity,int $process,string $permission,?int $actor = null): array
	{
		$context=$this->require($entity,$process,$permission,$actor); $actor=$context['actor'];
		$where='r.organizacion=? AND '.($entity==='interacciones' ? 'EXISTS (SELECT 1 FROM interacciones_procesos ip WHERE ip.interaccion=r.id AND ip.proceso=?)' : 'r.proceso=?');
		$parameters=[$context['organization'],$process];
		$creator=Catalog::entity($entity)['creator'];
		if (!$this->broad($context,$permission)) {
			[$scope,$params]=(new ScopeRepository($this->repository->connection()))->predicate($permission,$process,'p',$actor);
			$parts=["EXISTS (SELECT 1 FROM personas p WHERE p.id=r.persona AND ($scope))"]; $arguments=$params;
			$parts[]="EXISTS (SELECT 1 FROM usuarios creator JOIN personas p ON p.id=creator.persona WHERE creator.id=r.$creator AND ($scope))"; $arguments=[...$arguments,...$params];
			// Una definición publicada se puede aplicar; sus respuestas siguen filtradas por persona.
			if ($entity==='encuestas' && in_array($permission,['encuestas.ver','encuestas.aplicar','encuestas.ver_resultados','encuestas.corregir'],true)) $parts[]="r.estado='publicada'";
			if (in_array('propio',$context['effective']['scopes'][$permission] ?? [],true)) { $parts[]="r.$creator=?"; $arguments[]=$actor; }
			if (in_array($entity,['peticiones','incidencias','metas','eventos'],true)) { $parts[]="EXISTS (SELECT 1 FROM personas p WHERE p.id=r.responsable AND ($scope))"; $arguments=[...$arguments,...$params]; }
			if ($entity==='tareas') foreach (['tareas_asignaciones','tareas_subtareas'] as $table) { $parts[]="EXISTS (SELECT 1 FROM $table assigned JOIN personas p ON p.id=assigned.persona WHERE assigned.tarea=r.id AND ($scope))"; $arguments=[...$arguments,...$params]; }
			if ($entity==='tareas' && in_array('propio',$context['effective']['scopes'][$permission] ?? [],true)) { $parts[]='EXISTS (SELECT 1 FROM tareas_asignaciones historical WHERE historical.tarea=r.id AND historical.usuario_historico=?)'; $arguments[]=$actor; }
			if ($entity==='incidencias' && in_array('propio',$context['effective']['scopes'][$permission] ?? [],true)) { $parts[]='r.asignado_a=?'; $arguments[]=$actor; }
			if ($entity==='eventos' && $permission==='eventos.ver') { $parts[]="EXISTS (SELECT 1 FROM eventos_participantes attendee JOIN personas_procesos pp ON pp.id=attendee.persona_proceso JOIN personas p ON p.id=pp.persona WHERE attendee.evento=r.id AND ($scope))"; $arguments=[...$arguments,...$params]; }
			$parts[]="EXISTS (SELECT 1 FROM operaciones_personas related JOIN personas p ON p.id=related.persona WHERE related.entidad=? AND related.registro=r.id AND ($scope))"; $arguments=[...$arguments,$entity,...$params];
			$where.=' AND ('.implode(' OR ',$parts).')'; $parameters=[...$parameters,...$arguments];
		}
		if (in_array($entity,['peticiones','incidencias'],true) && !$context['effective']['super']) {
			$action=explode('.',$permission)[1] ?? 'ver'; $action=$action==='cerrar' ? 'editar' : (in_array($action,['ver','comentar','editar','transferir','cancelar'],true) ? $action : 'ver');
			$where.=" AND (r.$creator<>? OR r.permisos_capturista IS NULL OR COALESCE(JSON_EXTRACT(r.permisos_capturista,'$.$action'),0)=true OR r.responsable=?)";
			$parameters[]=$actor; $parameters[]=$context['effective']['user']['persona'] ?? 0;
		}
		return [$where,$parameters];
	}
	public function record(string $entity,string|int $reference,string $permission,?int $actor = null,bool $lock = false,?int $process = null): array
	{
		$row=$this->repository->raw($entity,$reference,$lock); $process ??= (int) $row['proceso'];
		[$where,$params]=$this->predicate($entity,$process,$permission,$actor);
		if (!$this->repository->one("SELECT r.id FROM $entity r WHERE r.id=? AND ($where)",[$row['id'],...$params])) throw new HttpError('Registro fuera de tu alcance.',404);
		$row['uuid']=Values::uuidText($row['uuid']); return $row;
	}
	public function history(string $entity,array $row,string $action,string $note = '',?array $before = null,?array $after = null): void
	{
		$this->repository->execute('INSERT INTO operaciones_historial (organizacion,proceso,entidad,registro,usuario,accion,nota,fecha) VALUES (?,?,?,?,?,?,?,NOW())',[$row['organizacion'],$row['proceso'],$entity,$row['id'],$this->actor(),$action,Values::text($note,4000,true)]);
		(new AuditService($this->repository))->record((int) $row['organizacion'],(int) $row['proceso'],$action,$entity,(int) $row['id'],$before,$after);
	}
	public function version(array $row,array $data): void
	{
		if (isset($data['version']) && Values::integer($data['version'])!==(int) $row['version']) throw new HttpError('El registro cambió. Actualiza la pantalla antes de guardar.',409);
	}
	public function approver(string $entity,array $row,string $permission,mixed $reference = null): int
	{
		$process=(int) $row['proceso'];
		if ($reference!==null && $reference!=='') {
			$user=$this->repository->one('SELECT id FROM usuarios WHERE uuid=?',[Values::binary($reference)]) ?? throw new HttpError('Aprobador no disponible.',404);
			$candidates=[$user['id']];
		} else {
			$subject=$row['responsable'] ?? $row['persona'] ?? null;
			$candidates=$subject===null ? [] : array_column($this->repository->rows('SELECT u.id FROM estructura_integrantes_procesos own JOIN estructura_relaciones rel ON rel.descendiente=own.integrante AND rel.profundidad>0 JOIN estructura ancestor ON ancestor.id=rel.ancestro JOIN usuarios u ON u.persona=ancestor.persona AND u.activo=1 WHERE own.persona=? AND own.proceso=? AND own.activo=1 ORDER BY rel.profundidad',[$subject,$process]),'id');
			$candidates=[...$candidates,...array_column($this->repository->rows("SELECT u.id FROM usuarios u JOIN usuarios_tipos t ON t.id=u.tipo_usuario WHERE u.activo=1 AND t.codigo='superadmin' ORDER BY u.id"),'id')];
		}
		foreach ($candidates as $candidate) {
			if ((int) $candidate===$this->actor()) continue;
			try {
				$this->require($entity,$process,$permission,(int) $candidate);
				$this->record($entity,(int) $row['id'],$permission,(int) $candidate);
				(new Access($this->repository))->context((int) $row['organizacion'],$process,'aprobaciones.resolver',(int) $candidate);
				return (int) $candidate;
			} catch (HttpError $error) { if ($reference!==null && $reference!=='') throw $error; }
		}
		throw new HttpError('No existe un aprobador autorizado distinto del solicitante. Configura uno antes de solicitar validación.',422);
	}
}
