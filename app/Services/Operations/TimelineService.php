<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Core\Auth;
use App\Core\HttpError;
use App\Services\Common\Values;

class TimelineService extends BaseService
{
	protected function entity(): string { return 'interacciones'; }
	public function activity(string $uuid,array $filters): array
	{
		$process=$this->context->process($filters['process'] ?? null);
		$person=$this->context->person($uuid,'personas.ver',$process,false);
		$parts=["SELECT CONCAT('participacion-',pp.id) referencia,'participacion' tipo,COALESCE(pp.f_actualizacion,pp.f_registro) fecha,IF(pp.activo=1,'Participación activa','Participación retirada') titulo FROM personas_procesos pp WHERE pp.persona=? AND pp.proceso=?"];
		$params=[$person['id'],$process];
		foreach (['interacciones','tareas','eventos','peticiones','incidencias'] as $entity) {
			$definition=Catalog::entity($entity);
			try { [$where,$arguments]=$this->context->predicate($entity,$process,$definition['permission'].'.ver'); } catch(HttpError) { continue; }
			$related=match($entity) {
				'tareas'=>'(r.persona=? OR r.relacion_entidad=\'personas\' AND r.relacion_id=? OR EXISTS (SELECT 1 FROM tareas_asignaciones item WHERE item.tarea=r.id AND item.persona=?) OR EXISTS (SELECT 1 FROM tareas_subtareas item WHERE item.tarea=r.id AND item.persona=?))',
				'eventos'=>'EXISTS (SELECT 1 FROM eventos_participantes attendee JOIN personas_procesos pp ON pp.id=attendee.persona_proceso WHERE attendee.evento=r.id AND pp.persona=?)',
				default=>'r.persona=?',
			};
			$count=$entity==='tareas' ? 4 : 1;
			$parts[]="SELECT CONCAT('$entity-',r.id) referencia,'$entity' tipo,r.{$definition['date']} fecha,LEFT(r.{$definition['name']},250) titulo FROM $entity r WHERE ($where) AND ($related)";
			$params=[...$params,...$arguments,...array_fill(0,$count,$person['id'])];
		}
		$result=$this->repository->page('SELECT * FROM ('.implode(' UNION ALL ',$parts).') activity ORDER BY fecha DESC,referencia DESC',$params,$filters);
		return ['process'=>$process,...$result];
	}
}
