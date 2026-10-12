<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Core\HttpError;
use App\Repositories\OperationsRepository;
use App\Services\Common\AuditService;

final class ApprovalTransitions
{
	// Callback del motor común dentro de su transacción; nunca se aplican aprobaciones obsoletas.
	public static function apply(OperationsRepository $repository,array $approval,string $state,int $actor): void
	{
		$entity=$approval['entidad']; $id=(int) $approval['entidad_id']; $context=new Context($repository);
		$task=in_array($entity,['tareas_asignaciones','tareas_subtareas'],true);
		$row=$task ? $repository->one("SELECT * FROM $entity WHERE id=? FOR UPDATE",[$id]) : $repository->raw($entity,$id,true);
		if (!$row || (int) ($row['aprobacion'] ?? 0)!==(int) $approval['id']) throw new HttpError('La solicitud ya no corresponde al estado actual.',409);
		$snapshot=json_decode($approval['regla'],true,512,JSON_THROW_ON_ERROR);
		$parent=$task ? $repository->raw('tareas',(int) $row['tarea'],true) : $row;
		$domain=$task ? 'tareas' : $entity;
		if ($state==='aprobada' || $state==='rechazada') {
			if ((int) $row['version']!==(int) ($snapshot['version'] ?? 0) || ($task && (int) $parent['version']!==(int) ($snapshot['parent_version'] ?? 0))) throw new HttpError('El registro cambió desde la solicitud. Cancela y solicita otra validación.',409);
			$business=$domain==='encuestas' ? 'encuestas.aprobar' : $domain.'.validar';
			$context->record($domain,(int) $parent['id'],$business,$actor);
			$requestPermission=$domain==='encuestas' ? 'encuestas.crear' : ($task ? 'tareas.completar' : $domain.'.cerrar');
			$context->record($domain,(int) $parent['id'],$requestPermission,(int) $approval['solicitante']);
			if ($task) (new TaskService($repository))->authorizeItem($entity,$id,'tareas.completar',(int) $approval['solicitante']);
		}
		$next=$state==='aprobada' ? ($domain==='encuestas' ? 'publicada' : ($task ? 'completada' : 'cerrada')) : ($domain==='encuestas' ? 'borrador' : 'correccion');
		if ($task) {
			$repository->execute("UPDATE $entity SET estado=?,aprobacion=NULL,version=version+1,completada_en=? WHERE id=?",[$next,$state==='aprobada' ? date('Y-m-d H:i:s') : null,$id]);
			(new TaskService($repository))->refreshState((int) $parent['id']);
		} else {
			$data=['estado'=>$next,'aprobacion'=>null];
			if ($domain==='encuestas' && $state==='aprobada') $data['publicada_en']=date('Y-m-d H:i:s');
			if ($domain==='incidencias' && $state==='aprobada') $data['f_resolucion']=date('Y-m-d H:i:s');
			$repository->updateRecord($domain,$id,$data);
		}
		$repository->execute('INSERT INTO operaciones_historial (organizacion,proceso,entidad,registro,usuario,accion,nota,fecha) VALUES (?,?,?,?,?,?,?,NOW())',[$parent['organizacion'],$parent['proceso'],$domain,$parent['id'],$actor,'validacion','Solicitud '.$state.'.']);
		(new AuditService($repository))->record((int) $parent['organizacion'],(int) $parent['proceso'],'validar',$domain,(int) $parent['id'],['estado'=>$row['estado']],['estado'=>$next,'solicitud'=>$state],[],$actor);
	}
}
