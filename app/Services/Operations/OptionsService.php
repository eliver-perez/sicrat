<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Core\HttpError;
use App\Repositories\ScopeRepository;
use App\Services\Common\Values;

class OptionsService extends BaseService
{
	protected function entity(): string { return 'interacciones'; }
	public function people(string $entity,array $filters): array
	{
		$definition=Catalog::entity($entity); $process=$this->context->process($filters['process'] ?? null);
		$action=Catalog::choice($filters['action'] ?? ($entity==='transporte' ? 'editar' : ($entity==='encuestas' ? 'aplicar' : ($entity==='conversaciones' ? 'enviar' : 'crear'))),['crear','editar','transferir','asistencia','aplicar','enviar','ver']);
		$permission=$definition['permission'].'.'.$action; $context=$this->context->require($entity,$process,$permission);
		[$where,$params]=(new ScopeRepository($this->repository->connection()))->predicate($permission,$process);
		$where='p.organizacion=? AND ('.$where.')'; $params=[$context['organization'],...$params];
		if (($filters['search'] ?? '')!=='') { $where.=" AND (p.codigo_persona LIKE ? OR CONCAT_WS(' ',p.nombre,p.paterno,p.materno) LIKE ?)"; $term='%'.Values::text($filters['search'],100).'%'; $params[]=$term; $params[]=$term; }
		if (($filters['participating'] ?? '1')!=='0') { $where.=' AND EXISTS (SELECT 1 FROM personas_procesos pp WHERE pp.persona=p.id AND pp.proceso=? AND pp.activo=1)'; $params[]=$process; }
		$result=$this->repository->page("SELECT p.uuid,p.codigo_persona,CONCAT_WS(' ',p.nombre,p.paterno,p.materno) nombre FROM personas p WHERE $where ORDER BY p.nombre,p.id",$params,$filters);
		foreach ($result['rows'] as &$person) $person['uuid']=Values::uuidText($person['uuid']); return $result;
	}
	public function elections(array $filters): array
	{
		$process=$this->context->process(); $context=$this->context->require('interacciones',$process,'interacciones.crear');
		$result=$this->repository->page('SELECT id,uuid,proceso nombre FROM procesos_electorales WHERE organizacion=? AND estatus=1 AND proceso LIKE ? ORDER BY id',[$context['organization'],'%'.Values::text($filters['search'] ?? '',100,true).'%'],$filters);
		$rows=[];
		foreach ($result['rows'] as $row) {
			try { $this->context->require('interacciones',(int) $row['id'],'interacciones.crear'); }
			catch (HttpError) { continue; }
			$rows[]=['uuid'=>Values::uuidText($row['uuid']),'nombre'=>$row['nombre']];
		}
		return ['rows'=>$rows,'pagination'=>$result['pagination']];
	}
	public function approvers(string $entity,array $filters): array
	{
		Catalog::choice($entity,['encuestas','tareas','peticiones','incidencias']); $process=$this->context->process(); $prefix=Catalog::entity($entity)['permission'];
		$action=$filters['action'] ?? ($entity==='encuestas' ? 'crear' : ($entity==='tareas' ? 'completar' : 'cerrar'));
		Catalog::choice($action,['crear','completar','cerrar']); $context=$this->context->require($entity,$process,$prefix.'.'.$action);
		$permission=$entity==='encuestas' ? 'encuestas.aprobar' : $entity.'.validar';
		$rows=$this->repository->page("SELECT u.id,u.uuid,u.nombre FROM usuarios u JOIN usuarios_tipos t ON t.id=u.tipo_usuario WHERE u.activo=1 AND u.id<>? AND (t.codigo='superadmin' OR (u.organizacion=? AND EXISTS (SELECT 1 FROM usuarios_procesos up WHERE up.usuario=u.id AND up.proceso=? AND up.activo=1 AND up.f_baja IS NULL))) AND u.nombre LIKE ? ORDER BY u.nombre,u.id",[$context['actor'],$context['organization'],$process,'%'.Values::text($filters['search'] ?? '',100,true).'%'],$filters);
		$eligible=[];
		foreach ($rows['rows'] as $row) if ($this->context->allowed($permission,$process,(int) $row['id']) && $this->context->allowed('aprobaciones.resolver',$process,(int) $row['id'])) $eligible[]=['uuid'=>Values::uuidText($row['uuid']),'nombre'=>$row['nombre']];
		return ['rows'=>$eligible,'pagination'=>$rows['pagination']];
	}
}
