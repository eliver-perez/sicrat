<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Core\HttpError;
use App\Repositories\CommonRepository;
use App\Repositories\OperationsRepository;
use App\Repositories\ScopeRepository;
use App\Services\Common\ExportRegistry;
use App\Services\Common\Values;

final class Exports
{
	public const TYPES=['interacciones','encuestas_resultados','tareas','metas','eventos','asistencias','peticiones','incidencias','transporte'];
	public static function register(ExportRegistry $registry,CommonRepository $repository): void
	{
		$definitions=[
			'interacciones'=>['Interacciones',['Fecha','Código de persona','Persona','Tipo','Resultado','Notas']],
			'encuestas_resultados'=>['Resultados_de_encuesta',['Encuesta','Versión','Pregunta','Opción','Conteo','Porcentaje']],
			'tareas'=>['Tareas',['Título','Modalidad','Estado','Inicio','Fecha límite','Completadas','Total']],
			'metas'=>['Metas',['Título','Indicador','Origen','Inicio','Fin','Objetivo','Avance']],
			'eventos'=>['Eventos',['Evento','Tipo','Inicio','Lugar','Control de participantes','Estado','Conteo agregado']],
			'asistencias'=>['Asistencias',['Evento','Nombre','Estado','Fecha de asistencia']],
			'peticiones'=>['Peticiones',['Folio','Categoría','Solicitante','Capturista','Responsable','Estado','Fecha','Resolución']],
			'incidencias'=>['Incidencias',['Folio','Categoría','Persona relacionada','Capturista','Responsable','Estado','Fecha','Resolución']],
			'transporte'=>['Logistica_de_transporte',['Código','Persona','Necesidad','Coordinador','Proveedor','Observaciones']],
		];
		foreach ($definitions as $type=>[$title,$columns]) $registry->register($type,$title,$columns,static fn($context)=>self::rows(new OperationsRepository($repository->connection()),$type,$context),false);
	}
	public static function filters(string $type,array $filters): array
	{
		if (!in_array($type,self::TYPES,true)) return [];
		$clean=[];
		if ($type==='transporte' && ($filters['structure'] ?? '')!=='') $clean['structure']=Values::uuidText(Values::binary($filters['structure']));
		if ($type==='encuestas_resultados') $clean['survey']=Values::uuidText(Values::binary($filters['survey'] ?? null));
		foreach (['from','to'] as $date) if (($filters[$date] ?? '')!=='') $clean[$date]=Values::date($filters[$date]);
		if (($filters['state'] ?? '')!=='') $clean['state']=Values::text($filters['state'],24);
		return $clean;
	}
	private static function personScope(OperationsRepository $repository,int $process,string $permission,int $actor): array
	{
		$effective=(new \App\Repositories\PermissionRepository($repository->connection()))->require($actor,$permission,$process);
		if ($effective['super'] || array_intersect(['organizacion','eleccion'],$effective['scopes'][$permission])!==[]) return ['1=1',[]];
		return (new ScopeRepository($repository->connection()))->predicate($permission,$process,'p',$actor);
	}
	public static function rows(OperationsRepository $repository,string $type,array $context): iterable
	{
		if ($context['process']===null) throw new \InvalidArgumentException('Selecciona una elección para exportar operación.');
		$process=(int) $context['process']; $actor=(int) $context['actor']; $guard=new Context($repository);
		$entity=$type==='asistencias' ? 'eventos' : ($type==='encuestas_resultados' ? 'encuestas' : $type);
		$business=$type==='encuestas_resultados' ? 'encuestas.ver_resultados' : Catalog::entity($entity)['permission'].'.ver';
		$guard->require($entity,$process,$business,$actor);
		$extra=$context['scope_permission'] ?? 'exportaciones.generar'; $filters=$context['filters'];
		if ($type==='encuestas_resultados') {
			$survey=$repository->raw('encuestas',$filters['survey']);
			if ((int) $survey['proceso']!==$process || (int) $survey['organizacion']!==$context['organization']) throw new HttpError('La encuesta pertenece a otro contexto de exportación.',404);
			$service=new SurveyService($repository); $result=$service->results($filters['survey'],[...$filters,'scope_permission'=>$extra]);
			$index=0;
			foreach ($result['questions'] as $question) {
				$values=$question['values'] ?: [['label'=>'Respuestas capturadas','count'=>$question['answered'],'percent'=>$result['responses'] ? round(100*$question['answered']/$result['responses'],2) : 0]];
				foreach ($values as $value) yield ['source_id'=>$result['signature'].':'.++$index,'encuesta'=>$result['survey'],'version'=>$result['version'],'pregunta'=>$question['title'],'opcion'=>$value['label'],'cantidad'=>$value['count'],'porcentaje'=>$value['percent']];
			}
			return;
		}
		if ($type==='transporte') {
			[$where,$scopeParams]=(new TransportService($repository))->criteria([...$filters,'process'=>$process],$actor); [$export,$exportParams]=self::personScope($repository,$process,$extra,$actor);
			$sql="SELECT pp.id source_id,p.codigo_persona,CONCAT_WS(' ',p.nombre,p.paterno,p.materno) persona,pp.necesidad_transporte necesidad,CONCAT_WS(' ',co.nombre,co.paterno,co.materno) coordinador,COALESCE(t.proveedor_externo,CONCAT_WS(' ',provider.nombre,provider.paterno,provider.materno)) proveedor,t.observaciones_logisticas FROM personas_procesos pp JOIN personas p ON p.id=pp.persona LEFT JOIN transporte t ON t.persona_proceso=pp.id LEFT JOIN personas co ON co.id=t.coordinador LEFT JOIN personas provider ON provider.id=t.proveedor WHERE ($where) AND ($export)";
			$params=[...$scopeParams,...$exportParams]; $source='pp.id';
		} else {
			[$where,$params]=$guard->predicate($entity,$process,$business,$actor); [$extraScope,$extraParams]=$guard->predicate($entity,$process,$extra,$actor); $where.=" AND ($extraScope)"; $params=[...$params,...$extraParams];
			if (($filters['search'] ?? '')!=='') { $where.=' AND (r.'.Catalog::entity($entity)['name'].' LIKE ?'.(in_array($entity,['peticiones','incidencias'],true) ? ' OR r.folio LIKE ?' : '').')'; $params[]='%'.$filters['search'].'%'; if (in_array($entity,['peticiones','incidencias'],true)) $params[]='%'.$filters['search'].'%'; }
			if (($filters['state'] ?? '')!=='') { $where.=' AND r.estado=?'; $params[]=$filters['state']; }
			if (($filters['from'] ?? '')!=='') { $where.=' AND r.'.Catalog::entity($entity)['date'].'>=?'; $params[]=$filters['from'].' 00:00:00'; }
			if (($filters['to'] ?? '')!=='') { $where.=' AND r.'.Catalog::entity($entity)['date'].'<=?'; $params[]=$filters['to'].' 23:59:59'; }
			$source='r.id';
			$sql=match($type) {
				'interacciones'=>"SELECT r.id source_id,r.f_interaccion,p.codigo_persona,CONCAT_WS(' ',p.nombre,p.paterno,p.materno) persona,t.tipo,r.resultado,r.notas FROM interacciones r JOIN personas p ON p.id=r.persona JOIN tipos_interaccion t ON t.id=r.tipo WHERE $where",
				'tareas'=>"SELECT r.id source_id,r.titulo,r.modalidad,r.estado,r.fecha_inicio,r.fecha_limite FROM tareas r WHERE $where",
				'metas'=>"SELECT r.id source_id,r.uuid,r.titulo,r.indicador,r.origen,r.fecha_inicio,r.fecha_fin,r.cantidad FROM metas r WHERE $where",
				'eventos'=>"SELECT r.id source_id,r.evento,COALESCE(t.nombre,'Histórico') tipo,r.fecha_inicio,r.lugar,r.control_participantes,r.estado,r.cantidad_asistentes FROM eventos r LEFT JOIN eventos_tipos t ON t.id=r.tipo_operativo WHERE $where",
				'peticiones','incidencias'=>"SELECT r.id source_id,r.folio,r.categoria,".($type==='peticiones' ? "COALESCE(CONCAT_WS(' ',p.nombre,p.paterno,p.materno),r.solicitante_externo)" : "CONCAT_WS(' ',p.nombre,p.paterno,p.materno)")." solicitante,u.nombre capturista,CONCAT_WS(' ',responsible.nombre,responsible.paterno,responsible.materno) responsable,r.estado,r.".Catalog::entity($entity)['date']." fecha,r.resolucion FROM $entity r LEFT JOIN personas p ON p.id=r.persona JOIN usuarios u ON u.id=r.".Catalog::entity($entity)['creator']." LEFT JOIN personas responsible ON responsible.id=r.responsable WHERE $where",
				'asistencias'=>"SELECT a.id source_id,r.evento,COALESCE(a.nombre_externo,CONCAT_WS(' ',p.nombre,p.paterno,p.materno)) persona,a.estado,a.asistencia_en FROM eventos r JOIN eventos_participantes a ON a.evento=r.id LEFT JOIN personas_procesos pp ON pp.id=a.persona_proceso LEFT JOIN personas p ON p.id=pp.persona WHERE $where",
			};
			if ($type==='asistencias') {
				[$people,$args]=self::personScope($repository,$process,'eventos.ver',$actor); [$exports,$exportArgs]=self::personScope($repository,$process,$extra,$actor);
				$sql.=" AND ($people) AND ($exports)"; $params=[...$params,...$args,...$exportArgs]; $source='a.id';
			}
		}
		$last=0;
		do {
			$rows=$repository->rows($sql." AND $source>? ORDER BY $source LIMIT 250",[...$params,$last]);
			foreach ($rows as $row) {
				$last=(int) $row['source_id'];
				if ($type==='tareas') {
					$task=$repository->raw('tareas',$last); $progress=(new TaskService($repository))->progress($task); $row['completadas']=$progress['completed']; $row['total']=$progress['total'];
				} elseif ($type==='metas') { unset($row['uuid']); $goal=$repository->raw('metas',$last); $row['indicador']=GoalService::INDICATORS[$goal['indicador']]['label'] ?? $goal['indicador']; $row['avance']=(new GoalService($repository))->progress($goal,$extra); }
				foreach (['estado','modalidad','origen','control_participantes','necesidad'] as $field) if (isset($row[$field])) $row[$field]=self::label($row[$field]);
				yield $row;
			}
		} while(count($rows)===250);
	}
	private static function label(string $value): string
	{
		return ['no_definido'=>'No definido','requiere'=>'Requiere transporte','propio'=>'Transporte propio','validacion'=>'Pendiente de validación','correccion'=>'Requiere corrección','asistio'=>'Asistió','no_asistio'=>'No asistió','ninguno'=>'Sin control de participantes','agregado'=>'Conteo agregado','automatica'=>'Calculada automáticamente','manual'=>'Capturada manualmente'][$value] ?? ucfirst(str_replace('_',' ',$value));
	}
}
