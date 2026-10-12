<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Core\HttpError;
use App\Repositories\ScopeRepository;
use App\Services\Common\Values;

class GoalService extends BaseService
{
	public const INDICATORS=[
		'personas_incorporadas'=>['label'=>'Personas incorporadas','entity'=>'interacciones','permission'=>'personas.ver','module'=>null],
		'interacciones_realizadas'=>['label'=>'Interacciones realizadas','entity'=>'interacciones','permission'=>'interacciones.ver','module'=>'interacciones'],
		'tareas_completadas'=>['label'=>'Tareas completadas','entity'=>'tareas','permission'=>'tareas.ver','module'=>'tareas_metas'],
		'encuestas_aplicadas'=>['label'=>'Encuestas aplicadas','entity'=>'encuestas','permission'=>'encuestas.ver_resultados','module'=>'encuestas'],
		'asistencias_registradas'=>['label'=>'Asistencias registradas','entity'=>'eventos','permission'=>'eventos.ver','module'=>'eventos'],
	];
	protected function entity(): string { return 'metas'; }
	public function indicators(): array
	{
		$process=$this->context->process(); $this->context->require('metas',$process,'metas.ver'); $available=[];
		foreach (self::INDICATORS as $code=>$definition) {
			try { $this->metricAccess($code,$process); $available[]=['code'=>$code,'label'=>$definition['label']]; } catch(HttpError) {}
		}
		return $available;
	}
	private function metricAccess(string $indicator,int $process): string
	{
		$definition=self::INDICATORS[$indicator] ?? throw new \InvalidArgumentException('Indicador automático inválido.');
		(new \App\Services\Common\Access($this->repository))->context((int) $this->repository->one('SELECT organizacion FROM procesos_electorales WHERE id=?',[$process])['organizacion'],$process,$definition['permission']);
		if ($definition['module']!==null) (new \App\Repositories\ModuleRepository($this->repository->connection()))->require($definition['module'],$process);
		return $definition['permission'];
	}
	private function metricScope(string $permission,int $process): array
	{
		$effective=(new \App\Repositories\PermissionRepository($this->repository->connection()))->require($this->context->actor(),$permission,$process);
		if ($effective['super'] || array_intersect(['eleccion','organizacion'],$effective['scopes'][$permission])!==[]) return ['1=1',[]];
		return (new ScopeRepository($this->repository->connection()))->predicate($permission,$process);
	}
	public function create(array $data): array
	{
		$process=$this->context->process($data['process'] ?? null); $context=$this->context->require('metas',$process,'metas.crear');
		return $this->repository->transaction(function() use($process,$context,$data) {
			$origin=Catalog::choice($data['origin'] ?? 'manual',['manual','automatica']); $mode=Catalog::choice($data['mode'] ?? 'individual',['individual','equipo','general']);
			$indicator=$origin==='automatica' ? Catalog::choice($data['indicator'] ?? null,array_keys(self::INDICATORS)) : Values::text($data['indicator'] ?? 'Unidades registradas',40);
			if ($origin==='automatica') $this->metricAccess($indicator,$process);
			$start=Values::date($data['start'] ?? null); $end=Values::date($data['end'] ?? null);
			if ($end<$start) throw new \InvalidArgumentException('Periodo inválido.');
			$responsible=$this->personReference($data['responsible'] ?? null,'metas.crear',$process); $member=null;
			if ($mode!=='general' && $responsible===null) throw new \InvalidArgumentException('Selecciona responsable de la meta.');
			if ($mode==='equipo') {
				$member=$this->repository->one('SELECT ep.integrante FROM estructura_integrantes_procesos ep WHERE ep.persona=? AND ep.proceso=? AND ep.activo=1',[$responsible['id'],$process])['integrante'] ?? throw new HttpError('El responsable debe integrar una estructura activa en esta elección.',422);
			}
			if ($mode==='general' && !$this->context->broad($context,'metas.crear')) throw new HttpError('Las metas generales requieren alcance amplio.',403);
			$quantity=Values::integer($data['quantity'] ?? null,1,1000000000); $parent=null;
			if (($data['parent'] ?? '')!=='') {
				$parent=$this->context->record('metas',$data['parent'],'metas.crear',null,true);
				if ((int) $parent['proceso']!==$process || $parent['origen']!==$origin || $parent['indicador']!==$indicator || $parent['fecha_inicio']!==$start || $parent['fecha_fin']!==$end || $responsible===null) throw new \InvalidArgumentException('Las distribuciones conservan elección, origen, indicador, periodo y un responsable.');
				if ((int) $parent['progreso_manual']!==0) throw new HttpError('Antes de distribuir una meta manual, concilia su avance propio con las nuevas distribuciones.',409);
				if ($parent['estado']!=='activa') throw new HttpError('La meta padre fue cancelada.',409);
				$allocated=(int) $this->repository->one('SELECT COALESCE(SUM(cantidad),0) total FROM metas WHERE padre=?',[$parent['id']])['total'];
				if ($allocated+$quantity>(int) $parent['cantidad']) throw new HttpError('La distribución excede la meta padre.',422);
				if ($this->repository->one('SELECT id FROM metas WHERE padre=? AND responsable=?',[$parent['id'],$responsible['id']])) throw new HttpError('Ese responsable ya tiene una distribución.',409);
				if ($parent['modalidad']==='individual' && (int) $parent['responsable']!==(int) $responsible['id']) throw new HttpError('La distribución sale de la responsabilidad de la meta padre.',422);
				if ($parent['modalidad']==='equipo' && !$this->repository->one('SELECT rel.ancestro FROM estructura_relaciones rel JOIN estructura e ON e.id=rel.descendiente WHERE rel.ancestro=? AND e.persona=?',[$parent['estructura'],$responsible['id']])) throw new HttpError('La distribución debe permanecer dentro del equipo padre.',422);
				$depth=0; $ancestor=$parent;
				while ($ancestor['padre']!==null) { if (++$depth>=20) throw new \InvalidArgumentException('Máximo veinte niveles de distribución.'); $ancestor=$this->repository->raw('metas',(int) $ancestor['padre']); }
			}
			$type=$this->repository->one("SELECT id FROM metas_tipos WHERE codigo='REGISTROS'")['id'];
			$row=$this->newRow('metas',['organizacion'=>$context['organization'],'proceso'=>$process,'titulo'=>Values::text($data['title'] ?? null,180),'origen'=>$origin,'indicador'=>$indicator,'modalidad'=>$mode,'responsable'=>$responsible['id'] ?? null,'persona'=>$responsible['id'] ?? null,'estructura'=>$member,'cantidad'=>$quantity,'fecha_inicio'=>$start,'fecha_fin'=>$end,'asignado_por'=>$context['actor'],'tipo'=>$type,'padre'=>$parent['id'] ?? null,'estado'=>'activa','fecha'=>date('Y-m-d H:i:s')]);
			return ['uuid'=>$row['uuid']];
		});
	}
	private function subjects(array $row): array
	{
		if ($row['modalidad']==='general') return ['1=1',[]];
		if ($row['modalidad']==='individual') return ['p.id=?',[$row['responsable']]];
		return ['EXISTS (SELECT 1 FROM estructura_relaciones rel JOIN estructura e ON e.id=rel.descendiente JOIN estructura_integrantes_procesos ep ON ep.integrante=e.id AND ep.proceso=? AND ep.activo=1 WHERE rel.ancestro=? AND e.persona=p.id)',[$row['proceso'],$row['estructura']]];
	}
	public function progress(array $row,?string $extraPermission = null): int
	{
		$process=(int) $row['proceso'];
		if ($row['origen']==='manual') {
			[$scope,$parameters]=$this->context->predicate('metas',$process,'metas.ver');
			$sql="WITH RECURSIVE children AS (SELECT id FROM metas WHERE id=? UNION ALL SELECT m.id FROM metas m JOIN children c ON m.padre=c.id) SELECT COALESCE(SUM(r.progreso_manual),0) total FROM children c JOIN metas r ON r.id=c.id WHERE NOT EXISTS (SELECT 1 FROM metas child WHERE child.padre=r.id) AND ($scope)";
			if ($extraPermission!==null) { [$extra,$args]=$this->context->predicate('metas',$process,$extraPermission); $sql.=" AND ($extra)"; $parameters=[...$parameters,...$args]; }
			return (int) $this->repository->one($sql,[$row['id'],...$parameters])['total'];
		}
		$sourcePermission=$this->metricAccess($row['indicador'],$process);
		[$scope,$params]=$this->metricScope('metas.ver',$process);
		[$sourceScope,$sourceParams]=$this->metricScope($sourcePermission,$process);
		[$subjects,$subjectParams]=$this->subjects($row);
		$sql=match($row['indicador']) {
			'personas_incorporadas'=>'SELECT pp.id source_id,pp.persona persona,pp.f_registro fecha,pp.proceso FROM personas_procesos pp',
			'interacciones_realizadas'=>'SELECT i.id source_id,i.persona,i.f_interaccion fecha,ip.proceso FROM interacciones i JOIN interacciones_procesos ip ON ip.interaccion=i.id',
			'tareas_completadas'=>"SELECT DISTINCT t.id source_id,COALESCE(a.persona,s.persona,t.persona,u.persona) persona,t.f_finalizacion fecha,t.proceso FROM tareas t LEFT JOIN tareas_asignaciones a ON a.tarea=t.id LEFT JOIN tareas_subtareas s ON s.tarea=t.id LEFT JOIN usuarios u ON u.id=t.creado_por WHERE t.estado='completada'",
			'encuestas_aplicadas'=>"SELECT a.id source_id,a.persona_encuestada persona,a.enviada_en fecha,e.proceso FROM encuestas_aplicaciones a JOIN encuestas e ON e.id=a.encuesta WHERE a.estado='enviada'",
			'asistencias_registradas'=>"SELECT a.id source_id,pp.persona,a.asistencia_en fecha,e.proceso FROM eventos_participantes a JOIN eventos e ON e.id=a.evento LEFT JOIN personas_procesos pp ON pp.id=a.persona_proceso WHERE a.estado='asistio'",
		};
		$parameters=[$process,$row['fecha_inicio'].' 00:00:00',$row['fecha_fin'].' 23:59:59',...$params,...$sourceParams,...$subjectParams];
		$query="SELECT COUNT(DISTINCT source.source_id) total FROM ($sql) source LEFT JOIN personas p ON p.id=source.persona WHERE source.proceso=? AND source.fecha>=? AND source.fecha<=? AND ($scope) AND ($sourceScope) AND ($subjects)";
		if ($extraPermission!==null) { [$extra,$arguments]=$this->metricScope($extraPermission,$process); $query.=" AND ($extra)"; $parameters=[...$parameters,...$arguments]; }
		return (int) $this->repository->one($query,$parameters)['total'];
	}
	public function detail(string $uuid): array
	{
		$row=parent::detail($uuid); $row['progress']=$this->progress($row); $row['indicator_label']=self::INDICATORS[$row['indicador']]['label'] ?? $row['indicador'];
		$row['origin_label']=$row['origen']==='manual' ? 'Avance capturado manualmente' : 'Calculado con registros del sistema';
		return $row;
	}
	public function children(string $uuid,array $filters): array
	{
		$row=$this->context->record('metas',$uuid,'metas.ver'); [$where,$params]=$this->context->predicate('metas',(int) $row['proceso'],'metas.ver');
		$result=$this->repository->page("SELECT r.uuid,r.titulo,r.cantidad,r.origen,p.nombre responsable FROM metas r LEFT JOIN personas p ON p.id=r.responsable WHERE r.padre=? AND ($where) ORDER BY r.id",[$row['id'],...$params],$filters);
		foreach ($result['rows'] as &$child) $child['uuid']=Values::uuidText($child['uuid']); return $result;
	}
	public function recordProgress(string $uuid,array $data): void
	{
		$this->repository->transaction(function() use($uuid,$data) {
			$row=$this->context->record('metas',$uuid,'metas.registrar',null,true); $this->context->version($row,$data);
			if ($row['estado']!=='activa') throw new HttpError('La meta fue cancelada.',409);
			if ($row['origen']!=='manual') throw new HttpError('Una meta automática se calcula; no admite avance manual.',422);
			if ($this->repository->one('SELECT id FROM metas WHERE padre=? LIMIT 1',[$row['id']])) throw new HttpError('Captura el avance en las distribuciones; la meta padre lo suma sin duplicarlo.',422);
			$progress=Values::integer($data['progress'] ?? null,0,1000000000); $reason=Values::text($data['reason'] ?? null,500);
			$this->repository->updateRecord('metas',(int) $row['id'],['progreso_manual'=>$progress]); $this->context->history('metas',$row,'registrar_avance',$reason,['cantidad'=>$row['progreso_manual']],['cantidad'=>$progress,'origen'=>'manual']);
		});
	}
	public function update(string $uuid,array $data): void
	{
		$this->repository->transaction(function() use($uuid,$data) {
			$row=$this->context->record('metas',$uuid,'metas.editar',null,true); $this->context->version($row,$data);
			$this->repository->updateRecord('metas',(int) $row['id'],['titulo'=>Values::text($data['title'] ?? $row['titulo'],180),'estado'=>Catalog::choice($data['state'] ?? $row['estado'],['activa','cancelada'])]);
			$this->context->history('metas',$row,'editar');
		});
	}
}
