<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Core\HttpError;
use App\Repositories\PersonIdentityRepository;
use App\Repositories\PersonMapRepository;
use App\Repositories\ScopeRepository;
use App\Services\Common\Values;

class TransportService extends BaseService
{
	private const SOURCE = 'FROM personas_procesos pp JOIN personas p ON p.id=pp.persona
		LEFT JOIN transporte t ON t.persona_proceso=pp.id';
	private const PEOPLE = ' LEFT JOIN personas co ON co.id=t.coordinador
		LEFT JOIN personas provider ON provider.id=t.proveedor';
	private const SECTION = ' LEFT JOIN secciones_electorales s ON s.id=COALESCE(pp.seccion,p.seccion)';
	private const NAME = "TRIM(CONCAT_WS(' ',p.nombre,p.paterno,p.materno))";
	private const COORDINATOR = "NULLIF(TRIM(CONCAT_WS(' ',co.nombre,co.paterno,co.materno)),'')";
	private const PROVIDER = "COALESCE(NULLIF(t.proveedor_externo,''),NULLIF(TRIM(CONCAT_WS(' ',provider.nombre,provider.paterno,provider.materno)),''))";
	private const COORDINATION = "CASE WHEN pp.necesidad_transporte='requiere'
		THEN IF(t.coordinador IS NULL,'pendiente','coordinada') END";


	protected function entity(): string { return 'transporte'; }
	public static function sectionNumber(mixed $number): int
	{
		if (is_string($number) && preg_match('/^[0-9]{1,4}$/D',$number)) $number=(int) $number;
		return Values::integer($number,1,9999);
	}

	public function criteria(array $filters,?int $actor = null): array
	{
		$process=$this->context->process($filters['process'] ?? null); $context=$this->context->require('transporte',$process,'transporte.ver',$actor);
		[$scope,$parameters]=(new ScopeRepository($this->repository->connection()))->predicate('transporte.ver',$process,'p',$actor);
		$where="pp.proceso=? AND pp.activo=1 AND p.organizacion=? AND ($scope)"; $parameters=[$process,$context['organization'],...$parameters];
		if (($filters['search'] ?? '')!=='') { $where.=" AND (p.codigo_persona LIKE ? OR CONCAT_WS(' ',p.nombre,p.paterno,p.materno) LIKE ?)"; $search='%'.Values::text($filters['search'],150).'%'; $parameters[]=$search; $parameters[]=$search; }
		if (($filters['section'] ?? '')!=='') { $where.=' AND COALESCE(pp.seccion,p.seccion)=?'; $parameters[]=Values::integer($filters['section']); }
		if (($filters['section_number'] ?? '')!=='') {
			$where.=' AND EXISTS (SELECT 1 FROM secciones_electorales section_row
				WHERE section_row.id=COALESCE(pp.seccion,p.seccion) AND section_row.seccion=?)';
			$parameters[]=self::sectionNumber($filters['section_number']);
		}
		$coordinator=$filters['coordinator'] ?? '';
		if ($coordinator==='none') $where.=' AND t.coordinador IS NULL';
		elseif ($coordinator!=='') {
			$where.=' AND EXISTS (SELECT 1 FROM personas responsible
				WHERE responsible.id=t.coordinador AND responsible.uuid=? AND responsible.organizacion=p.organizacion)';
			$parameters[]=Values::binary($coordinator);
		}
		if (($filters['coordination'] ?? '')!=='') {
			$coordination=Catalog::choice($filters['coordination'],['pendiente','coordinada']);
			$where.=' AND '.self::COORDINATION.'=?';
			$parameters[]=$coordination;
		}
		if (($filters['structure'] ?? '')!=='') {
			$structure=$this->repository->one('SELECT id FROM estructuras_organizacionales WHERE uuid=? AND organizacion=?',[Values::binary($filters['structure']),$context['organization']]) ?? throw new HttpError('Estructura no disponible.',404);
			$where.=' AND EXISTS (SELECT 1 FROM estructura_integrantes_procesos ep JOIN estructura e ON e.id=ep.integrante WHERE ep.persona=p.id AND ep.proceso=pp.proceso AND ep.activo=1 AND e.contenedor=?)'; $parameters[]=$structure['id'];
		}
		if (($filters['state'] ?? '')!=='') {
			$state=Catalog::choice($filters['state'],['no_definido','requiere','propio','pendiente','coordinada']);
			if ($state==='pendiente' || $state==='coordinada') $where.=" AND pp.necesidad_transporte='requiere' AND t.coordinador IS ".($state==='pendiente' ? 'NULL' : 'NOT NULL');
			else { $where.=' AND pp.necesidad_transporte=?'; $parameters[]=$state; }
		}
		return [$where,$parameters,$context];
	}
	// Las acciones se comprueban por persona con el mismo alcance que las escrituras y la ficha.
	private function actionScope(array $context,string $permission): array
	{
		try {
			return (new ScopeRepository($this->repository->connection()))->predicate(
				$permission,$context['process'],'p',$context['actor']
			);
		} catch (HttpError $error) {
			if ($error->statusCode!==403) throw $error;
			return ['0=1',[]];
		}
	}

	public function listing(array $filters): array
	{
		[$where,$parameters,$context]=$this->criteria($filters);
		[$edit,$editParams]=$this->actionScope($context,'transporte.editar');
		$result=$this->repository->page(
			'SELECT pp.id,p.uuid,p.codigo_persona,'.self::NAME.' titulo,pp.necesidad_transporte estado,
				t.version,t.fecha,t.confirmado_en,'.self::COORDINATOR.' coordinador,'.self::PROVIDER.' proveedor,
				COALESCE(pp.seccion,p.seccion) seccion,s.seccion section_number,'.self::COORDINATION." coordination,
				($edit) can_coordinate ".self::SOURCE.self::PEOPLE.self::SECTION." WHERE $where ORDER BY pp.id DESC",
			[...$editParams,...$parameters],$filters
		);
		foreach ($result['rows'] as &$row) $row['uuid']=Values::uuidText($row['uuid']);
		return $result;
	}

	public function summary(array $filters): array
	{
		[$where,$parameters]=$this->criteria($filters);
		$totals=$this->repository->rows('SELECT pp.necesidad_transporte estado,COUNT(*) cantidad,
			SUM(t.coordinador IS NOT NULL) coordinadas '.self::SOURCE." WHERE $where GROUP BY pp.necesidad_transporte",$parameters);
		$groups=$this->repository->page('SELECT s.seccion,pp.necesidad_transporte estado,COUNT(*) cantidad '
			.self::SOURCE.self::SECTION." WHERE $where GROUP BY s.seccion,pp.necesidad_transporte ORDER BY s.seccion,pp.necesidad_transporte",$parameters,$filters);
		return ['totals'=>$totals,'territories'=>$groups];
	}

	public function map(array $filters): array
	{
		// La vista del mapa siempre corresponde a la elección de trabajo.
		[$where,$parameters,$context]=$this->criteria([...$filters,'process'=>null]);
		$db=$this->repository->connection();
		if (($filters['view'] ?? null)==='initial') {
			return (new PersonIdentityRepository($db))->mapSettings($context['organization']);
		}
		$area=PersonMapRepository::viewport($filters);
		[$open,$openParams]=$this->actionScope($context,'personas.ver');
		[$edit,$editParams]=$this->actionScope($context,'transporte.editar');
		$columns=[
			'name'=>self::NAME,
			'section'=>'s.seccion',
			'need'=>'pp.necesidad_transporte',
			'coordinator'=>self::COORDINATOR,
			'provider'=>self::PROVIDER,
			'coordination'=>self::COORDINATION,
			'can_open'=>"CASE WHEN $open THEN 1 ELSE 0 END",
			'can_coordinate'=>"CASE WHEN $edit THEN 1 ELSE 0 END",
		];
		$from=self::SOURCE.self::PEOPLE.self::SECTION.' JOIN personas_domicilios d ON d.persona=p.id
			WHERE '.PersonMapRepository::ADDRESS_FILTER." AND ($where)";
		$rows=(new PersonMapRepository($db))->rows($area,$from,
			[...$openParams,...$editParams,$area['south'],$area['north'],$area['west'],$area['east'],...$parameters],$columns
		);
		return PersonMapRepository::result($rows,$area['list'],array_keys($columns));
	}

	public function coordinators(array $filters): array
	{
		[$where,$parameters]=$this->criteria([]);
		$search='%'.Values::text($filters['search'] ?? '',100,true).'%';
		$result=$this->repository->page("SELECT DISTINCT co.uuid,co.codigo_persona,".self::COORDINATOR.' nombre '
			.self::SOURCE.' JOIN personas co ON co.id=t.coordinador AND co.organizacion=p.organizacion '
			."WHERE ($where) AND (co.codigo_persona LIKE ? OR ".self::COORDINATOR.' LIKE ?) ORDER BY nombre,co.uuid',
			[...$parameters,$search,$search],$filters);
		foreach ($result['rows'] as &$row) $row['uuid']=Values::uuidText($row['uuid']);
		return $result;
	}

	public function detail(string $uuid): array
	{
		$process=$this->context->process(); $context=$this->context->require('transporte',$process,'transporte.ver'); $person=$this->context->person($uuid,'transporte.ver',$process);
		$participation=$this->repository->one('SELECT necesidad_transporte FROM personas_procesos WHERE id=?',[$person['participation']]);
		$row=$this->repository->one('SELECT * FROM transporte WHERE persona_proceso=?',[$person['participation']]) ?? [];
		if (isset($row['uuid'])) $row['logistics_uuid']=Values::uuidText($row['uuid']);
		foreach (['coordinador','proveedor'] as $field) if (($row[$field] ?? null)!==null) {
			$related=$this->repository->one("SELECT uuid,CONCAT_WS(' ',nombre,paterno,materno) nombre FROM personas WHERE id=?",[$row[$field]]);
			$row[$field.'_uuid']=Values::uuidText($related['uuid']); $row[$field.'_nombre']=$related['nombre'];
		}
		[$edit,$editParams]=$this->actionScope($context,'transporte.editar');
		[$confirm,$confirmParams]=$this->actionScope($context,'transporte.confirmar');
		$row['actions']=$this->repository->one("SELECT ($edit) can_coordinate,($confirm) can_confirm FROM personas p WHERE p.id=?",
			[...$editParams,...$confirmParams,$person['id']]);
		$row['financial_references']=isset($row['id']) ? (new \App\Services\Resources\Related($this->repository))->financial('transporte',(int) $row['id'],$process) : [];
		return [...$row,'uuid'=>$uuid,'persona_nombre'=>trim($person['nombre'].' '.$person['paterno'].' '.$person['materno']),'necesidad'=>$participation['necesidad_transporte']];
	}
	public function update(string $uuid,array $data): array
	{
		$process=$this->context->process($data['process'] ?? null); $context=$this->context->require('transporte',$process,'transporte.editar');
		return $this->repository->transaction(function() use($uuid,$process,$context,$data) {
			$person=$this->context->person($uuid,'transporte.editar',$process);
			$participation=$this->repository->one('SELECT * FROM personas_procesos WHERE id=? FOR UPDATE',[$person['participation']]);
			$row=$this->repository->one('SELECT * FROM transporte WHERE persona_proceso=? FOR UPDATE',[$participation['id']]);
			if ($row!==null) $this->context->version($row,$data);
			$need=Catalog::choice($data['need'] ?? $participation['necesidad_transporte'],['no_definido','requiere','propio']);
			$coordinator=array_key_exists('coordinator',$data) ? $this->personReference($data['coordinator'],'transporte.editar',$process) : null;
			$provider=array_key_exists('provider',$data) ? $this->personReference($data['provider'],'transporte.editar',$process,false) : null;
			$changes=['coordinador'=>array_key_exists('coordinator',$data) ? ($coordinator['id'] ?? null) : ($row['coordinador'] ?? null),'proveedor'=>array_key_exists('provider',$data) ? ($provider['id'] ?? null) : ($row['proveedor'] ?? null),'proveedor_externo'=>Values::text($data['external_provider'] ?? $row['proveedor_externo'] ?? '',180,true),'observaciones_logisticas'=>Values::text($data['notes'] ?? $row['observaciones_logisticas'] ?? '',4000,true),'editado_por'=>$context['actor'],'fecha'=>date('Y-m-d H:i:s')];
			if ($changes['proveedor']!==null && $changes['proveedor_externo']!=='') throw new \InvalidArgumentException('Selecciona proveedor registrado o describe uno externo.');
			$this->repository->execute('UPDATE personas_procesos SET necesidad_transporte=?,requiere_transporte=?,f_actualizacion=NOW() WHERE id=?',[$need,$need==='requiere' ? 1 : 0,$participation['id']]);
			if ($row===null) $row=$this->repository->insertRecord('transporte',['organizacion'=>$context['organization'],'proceso'=>$process,'persona'=>$person['id'],'persona_proceso'=>$participation['id'],...$changes]);
			else $this->repository->updateRecord('transporte',(int) $row['id'],$changes);
			$this->context->history('transporte',[...$row,'organizacion'=>$context['organization'],'proceso'=>$process],'actualizar_logistica','',['necesidad'=>$participation['necesidad_transporte']],['necesidad'=>$need,'coordinador'=>$changes['coordinador']]);
			return ['uuid'=>$uuid,'need'=>$need];
		});
	}
	public function confirm(string $uuid,array $data): void
	{
		$process=$this->context->process($data['process'] ?? null); $this->context->require('transporte',$process,'transporte.confirmar');
		if (Values::integer($data['confirmed'] ?? 0,0,1)!==1) throw new \InvalidArgumentException('La confirmación requiere una decisión explícita.');
		$this->repository->transaction(function() use($uuid,$process,$data) {
			$person=$this->context->person($uuid,'transporte.confirmar',$process);
			$row=$this->repository->one('SELECT * FROM transporte WHERE persona_proceso=? FOR UPDATE',[$person['participation']]) ?? throw new HttpError('Registra primero la necesidad y coordinación logística.',422);
			$this->context->version($row,$data); $date=Catalog::dateTime($data['date'] ?? date('Y-m-d H:i:s'));
			if ($date>date('Y-m-d H:i:s')) throw new \InvalidArgumentException('La confirmación no puede estar en el futuro.');
			$this->repository->updateRecord('transporte',(int) $row['id'],['confirmado_por'=>$this->context->actor(),'confirmado_en'=>$date,'observacion_confirmacion'=>Values::text($data['notes'] ?? '',500,true)]);
			$this->context->history('transporte',$row,'confirmar_traslado','',null,['fecha'=>$date,'voluntaria'=>true]);
		});
	}
}
