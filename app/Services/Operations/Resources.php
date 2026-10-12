<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Core\HttpError;
use App\Repositories\CommonRepository;
use App\Repositories\OperationsRepository;
use App\Services\Common\ResourceRegistry;

final class Resources
{
	public static function register(ResourceRegistry $registry,CommonRepository $repository): void
	{
		$operations=new OperationsRepository($repository->connection()); $context=new Context($operations);
		foreach (['interacciones','encuestas','tareas','metas','eventos','peticiones','incidencias'] as $entity) {
			$registry->onLock($entity,static fn(int $id)=>$operations->raw($entity,$id,true));
			$authorize=static function(int $id,int $organization,?int $process,string $operation,int $actor,?string $additional = null) use($entity,$context): void {
				$prefix=Catalog::entity($entity)['permission'];
				if ($operation==='read') $permissions=[$additional==='aprobaciones.resolver' ? ($entity==='encuestas' ? 'encuestas.aprobar' : $prefix.'.validar') : $prefix.'.ver'];
				else $permissions=match($entity) { 'encuestas'=>['encuestas.crear'], 'tareas'=>['tareas.editar','tareas.completar','tareas.crear'], 'interacciones'=>['interacciones.crear'], default=>[$prefix.'.editar',$prefix.'.crear',$prefix.'.transferir',$prefix.'.cerrar'] };
				$row=null;
				foreach ($permissions as $permission) {
					if (!$context->allowed($permission,(int) $process,$actor)) continue;
					try { $row=$context->record($entity,$id,$permission,$actor,false,$process); break; } catch(HttpError) {}
				}
				if (!$row || (int) $row['organizacion']!==$organization || ($entity!=='interacciones' && (int) $row['proceso']!==$process)) throw new HttpError('Recurso operativo no disponible.',404);
				if ($additional!==null) $context->record($entity,$id,$additional,$actor,false,$process);
			};
			$registry->register($entity,$authorize,static function(?int $process,int $actor,string $permission) use($entity,$context): array {
				if ($process===null) return ['0=1',[]];
				try {
					$business=$permission==='aprobaciones.resolver' ? ($entity==='encuestas' ? 'encuestas.aprobar' : Catalog::entity($entity)['permission'].'.validar') : Catalog::entity($entity)['permission'].'.ver';
					[$where,$params]=$context->predicate($entity,$process,$business,$actor);
					[$extra,$extraParams]=$context->predicate($entity,$process,$permission,$actor);
					return ["EXISTS (SELECT 1 FROM $entity r WHERE r.id=a.entidad_id AND ($where) AND ($extra))",[...$params,...$extraParams]];
				} catch(HttpError) { return ['0=1',[]]; }
			});
			$registry->filePolicy($entity,static function(int $id,string $mime) use($entity,$operations): void {
				$row=$operations->raw($entity,$id);
				if (!str_starts_with($mime,'image/') && !($mime==='application/pdf' && (int) ($row['permite_pdf'] ?? 0)===1)) throw new HttpError('Este recurso sólo admite evidencias de imagen.',422);
			});
			if (in_array($entity,['encuestas','peticiones','incidencias'],true)) $registry->onDecision($entity,static fn($approval,$state,$actor)=>ApprovalTransitions::apply($operations,$approval,$state,$actor));
		}
		foreach (['tareas_asignaciones','tareas_subtareas'] as $entity) {
			$registry->onLock($entity,static function(int $id) use($operations,$entity): void {
				$item=$operations->one("SELECT tarea FROM $entity WHERE id=?",[$id]) ?? throw new HttpError('Asignación no disponible.',404);
				$operations->raw('tareas',(int) $item['tarea'],true);
				$operations->one("SELECT id FROM $entity WHERE id=? FOR UPDATE",[$id]);
			});
			$registry->register($entity,static function(int $id,int $organization,?int $process,string $operation,int $actor,?string $additional = null) use($operations,$entity,$context): void {
				$permission=$additional==='aprobaciones.resolver' ? 'tareas.validar' : ($operation==='write' ? 'tareas.completar' : 'tareas.ver');
				(new TaskService($operations))->authorizeItem($entity,$id,$permission,$actor,$additional);
				$row=$operations->one("SELECT t.organizacion,t.proceso FROM $entity item JOIN tareas t ON t.id=item.tarea WHERE item.id=?",[$id]);
				if (!$row || (int) $row['organizacion']!==$organization || (int) $row['proceso']!==$process) throw new HttpError('Asignación no disponible.',404);
			},static function(?int $process,int $actor,string $permission) use($operations,$entity,$context): array {
				if ($process===null) return ['0=1',[]];
				try { [$where,$params]=$context->predicate('tareas',$process,'tareas.ver',$actor); [$extra,$extraParams]=$context->predicate('tareas',$process,$permission,$actor); return ["EXISTS (SELECT 1 FROM $entity item JOIN tareas r ON r.id=item.tarea WHERE item.id=a.entidad_id AND ($where) AND ($extra))",[...$params,...$extraParams]]; }
				catch(HttpError) { return ['0=1',[]]; }
			});
			$registry->onDecision($entity,static fn($approval,$state,$actor)=>ApprovalTransitions::apply($operations,$approval,$state,$actor));
		}
		$registry->onLock('mensajes',static function(int $id) use($operations): void {
			$message=$operations->one('SELECT conversacion FROM mensajes WHERE id=?',[$id]) ?? throw new HttpError('Mensaje no disponible.',404);
			$operations->raw('conversaciones',(int) $message['conversacion'],true);
		});
		$registry->register('mensajes',static fn(int $id,int $organization,?int $process,string $operation,int $actor,?string $permission = null)=>(new MessagingService($operations))->authorizeMessage($id,$organization,$process,$operation,$actor,$permission));
		$registry->filePolicy('mensajes',static function(int $id,string $mime) use($operations): void {
			$row=$operations->one('SELECT c.permite_pdf FROM mensajes m JOIN conversaciones c ON c.id=m.conversacion WHERE m.id=?',[$id]);
			if (!str_starts_with($mime,'image/') && !($mime==='application/pdf' && (int) ($row['permite_pdf'] ?? 0)===1)) throw new HttpError('La conversación sólo admite imágenes.',422);
		});
	}
}
