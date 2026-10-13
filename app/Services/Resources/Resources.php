<?php

declare(strict_types=1);

namespace App\Services\Resources;

use App\Core\Auth;
use App\Core\HttpError;
use App\Repositories\CommonRepository;
use App\Repositories\ResourcesRepository;
use App\Services\Common\ResourceRegistry;

final class Resources
{
	public static function register(ResourceRegistry $registry,CommonRepository $repository): void
	{
		$resources=new ResourcesRepository($repository->connection());
		$context=new Context($resources);
		foreach(Catalog::ENTITIES as $entity=>[$table,$read,$write]) {
			$registry->onLock($table,static function(int $id) use($resources,$entity): void {
				$row=$resources->raw($entity,$id);
				$resources->lock((int) $row['organizacion']);
				$resources->raw($entity,$id,true);
			});
			$registry->register($table,static function(int $id,int $organization,?int $process,string $operation,int $actor,?string $additional = null) use($resources,$context,$entity,$read,$write): void {
				$row=$resources->raw($entity,$id);
				if((int) $row['organizacion']!==$organization || ($row['proceso']!==null && (int) $row['proceso']!==$process)) throw new HttpError('Recurso no disponible.',404);
				if($additional==='archivos.eliminar') throw new HttpError('Las evidencias financieras y de inventario conservan su historial.',403);
				$business=$operation==='write' ? $write : $read;
				if($entity==='gastos' && $operation==='write' && $row['tipo']==='reembolso') $business='finanzas.reembolsos.solicitar';
				if($entity==='movimientos' && $operation==='write') $business=str_starts_with($row['tipo'],'ajuste') ? 'inventario.ajustar' : (Amount::signed($row['cantidad'],3)<0 ? 'inventario.salidas' : 'inventario.entradas');
				if($additional==='aprobaciones.resolver') {
					$business=PolicyService::approverPermission($entity,$row);
					if((int) $row['creado_por']===$actor) throw new HttpError('La solicitud propia requiere otro aprobador.',403);
					if(($row['aprobacion'] ?? null)!==null) {
						$approval=$resources->one('SELECT estado FROM aprobaciones WHERE id=?',[$row['aprobacion']]);
						if(($approval['estado'] ?? '')==='pendiente') {
							$step=$resources->one("SELECT aprobador FROM aprobaciones_pasos WHERE aprobacion=? AND estado='pendiente' ORDER BY orden LIMIT 1",[$row['aprobacion']]);
							if((int) ($step['aprobador'] ?? 0)!==$actor) throw new HttpError('Sólo el aprobador asignado puede resolver el paso financiero.',403);
						}
					}
					// Resolver una autorización es una escritura del módulo, también si el permiso común permite leer historia.
					$context->require($entity,$business,$process ?? Auth::processId(),true,$actor,$organization);
				}
				if($operation==='write') {
					$choices=match($entity) {
						'gastos'=>$row['tipo']==='reembolso' ? ['finanzas.reembolsos.solicitar','finanzas.reembolsos.aprobar'] : ['finanzas.gastos.crear','finanzas.gastos.editar','finanzas.gastos.pagar','finanzas.gastos.cancelar'], 'anticipos'=>['finanzas.anticipos.crear','finanzas.anticipos.comprobar'], 'reembolsos'=>['finanzas.reembolsos.solicitar','finanzas.reembolsos.aprobar'], 'pagos'=>$row['anticipo']!==null ? ['finanzas.anticipos.comprobar'] : [$business],default=>[$business]
					};
					$authorized=false;
					$last=null;
					foreach($choices as $candidate) {
						try {
							$context->record($entity,$id,$candidate,true,$actor);
							$authorized=true;
							break;
						} catch(HttpError $error) {
							$last=$error;
						}
					}
					if(!$authorized) throw $last ?? new HttpError('Recurso fuera de alcance.',404);
				} else $context->record($entity,$id,$business,false,$actor);
				if($operation==='write' && in_array($row['estado'] ?? '',['cancelado','rechazado'],true)) throw new HttpError('El registro cerrado no admite nuevas evidencias.',409);
				if($additional!==null) $context->record($entity,$id,$additional,$operation==='write',$actor);
			},static function(?int $process,int $actor,string $permission) use($resources,$context,$entity,$read,$table): array {
				try {
					[$where,$params]=$context->predicate($entity,$read,$process,$actor);
					[$extra,$args]=$context->predicate($entity,$permission,$process,$actor);
					return ["EXISTS (SELECT 1 FROM $table r WHERE r.id=a.entidad_id AND ($where) AND ($extra))",[...$params,...$args]];
				} catch(HttpError) {
					return ['0=1',[]];
				}
			});
			$registry->filePolicy($table,static function(int $id,string $mime): void {
				if(!str_starts_with($mime,'image/') && $mime!=='application/pdf') throw new HttpError('Sólo imágenes y PDF administrativos.',422);
			});
			if(in_array($entity,['gastos','anticipos'],true)) $registry->onDecision($table,static fn($approval,$state,$actor)=>(new PolicyService($resources))->decision($approval,$state,$actor));
			if($entity==='movimientos') $registry->onDecision($table,static fn($approval,$state,$actor)=>(new InventoryService($resources))->decision($approval,$state,$actor));
		}
	}
}
