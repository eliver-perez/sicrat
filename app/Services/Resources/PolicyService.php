<?php

declare(strict_types=1);

namespace App\Services\Resources;

use App\Core\Auth;
use App\Core\HttpError;
use App\Repositories\CommonRepository;
use App\Services\Common\Access;
use App\Services\Common\ApprovalService;
use App\Services\Common\Values;

class PolicyService extends BaseService
{
	public function __construct(CommonRepository $repository) {
		parent::__construct($repository,'politicas');
	}

	private function configuration(array $data): array
	{
		$mode=$data['modo'] ?? null;
		if(!in_array($mode,['sin_aprobacion','todos','por_monto'],true)) throw new \InvalidArgumentException('Modo de autorización inválido.');
		$ranges=$data['rangos'] ?? [];
		if(!is_array($ranges) || count($ranges)>50) throw new \InvalidArgumentException('Rangos inválidos.');
		if($mode==='sin_aprobacion') return ['modo'=>$mode,'rangos'=>[]];
		if(!$ranges || ($mode==='todos' && count($ranges)!==1)) throw new \InvalidArgumentException('Define los rangos de autorización.');
		$clean=[];
		$previous=-1;
		foreach($ranges as $index=>$range) {
			$from=Amount::parse($range['desde'] ?? '0',2,true);
			$to=isset($range['hasta']) && $range['hasta']!=='' ? Amount::parse($range['hasta'],2,true) : null;
			if($from!==$previous+1 || ($to!==null && $to<$from) || ($to===null && $index!==count($ranges)-1)) throw new \InvalidArgumentException('Los rangos deben ser consecutivos, iniciar en cero y terminar sin límite superior.');
			$steps=$range['pasos'] ?? [];
			if(!is_array($steps) || count($steps)<1 || count($steps)>20) throw new \InvalidArgumentException('Cada rango requiere entre uno y veinte pasos.');
			$cleanSteps=[];
			foreach($steps as $step) {
				$type=$step['tipo'] ?? null;
				if(!in_array($type,['usuario','rol','permiso'],true)) throw new \InvalidArgumentException('Tipo de aprobador inválido.');
				$reference=Values::text($step['referencia'] ?? null,128);
				if($type==='usuario') Values::binary($reference);
				elseif($type==='rol' && !$this->repository->one("SELECT id FROM usuarios_tipos WHERE codigo=? AND codigo<>'superadmin'",[$reference])) throw new \InvalidArgumentException('Rol de aprobación desconocido.');
				elseif($type==='permiso' && !$this->repository->one("SELECT id FROM permisos WHERE id=? AND ambito IN ('ambos','electoral')",[$reference])) throw new \InvalidArgumentException('Permiso de aprobación desconocido.');
				$cleanSteps[]=['tipo'=>$type,'referencia'=>$reference];
			}
			$clean[]=['desde'=>Amount::format($from),'hasta'=>$to===null ? null : Amount::format($to),'pasos'=>$cleanSteps];
			$previous=$to ?? Amount::MAX;
		}
		if($previous!==Amount::MAX || ($mode==='todos' && ($clean[0]['desde']!=='0.00' || $clean[0]['hasta']!==null))) throw new \InvalidArgumentException('La política debe cubrir todos los importes.');
		return ['modo'=>$mode,'rangos'=>$clean];
	}

	public function create(array $data): array
	{
		$ctx=$this->creation($data);
		$permission='finanzas.administrar';
		if(!$this->context->broad($this->context->require('politicas',$permission,$ctx['proceso'] ?? Auth::processId(),true),$permission,$ctx['proceso']===null)) throw new HttpError('Alcance insuficiente para configurar políticas.',403);
		$config=$this->configuration($data);
		return $this->repository->write($ctx['organizacion'],function() use($ctx,$data,$config) {
			$row=$this->repository->create('politicas',[...$ctx,'contexto'=>$ctx['proceso'] ?? 0,'nombre'=>Values::text($data['nombre'] ?? 'Política de autorización',120),'configuracion'=>Values::json($config)]);
			$this->context->history('politicas',$row,'crear','',null,$config);
			return Context::present($row);
		});
	}

	public function update(string $uuid,array $data): array
	{
		return $this->repository->write($this->context->organization(),function() use($uuid,$data) {
			$row=$this->context->record('politicas',$uuid,'finanzas.administrar',true);
			$this->context->version($row,$data);
			$config=$this->configuration($data);
			$this->repository->update('politicas',(int) $row['id'],['nombre'=>Values::text($data['nombre'] ?? $row['nombre'],120),'configuracion'=>Values::json($config)]);
			$this->context->history('politicas',$row,'editar',Values::text($data['motivo'] ?? null,500),json_decode($row['configuracion'],true),$config);
			return $this->detail($uuid);
		});
	}

	public static function approverPermission(string $entity,array $row): string
	{
		return $entity==='anticipos' ? 'finanzas.anticipos.aprobar' : ($entity==='movimientos' ? 'inventario.ajustar' : (($row['tipo'] ?? '')==='reembolso' ? 'finanzas.reembolsos.aprobar' : 'finanzas.gastos.aprobar'));
	}

	private function approvers(array $configuration,string $entity,array $row,int $amount): array
	{
		$range=null;
		foreach($configuration['rangos'] as $candidate) if($amount>=Amount::parse($candidate['desde'],2,true) && ($candidate['hasta']===null || $amount<=Amount::parse($candidate['hasta'],2,true))) {
			$range=$candidate;
			break;
		}
		if(!$range) throw new HttpError('No hay rango aplicable. Revisa la política.',422);
		$selected=[];
		$process=$row['proceso']===null ? Auth::processId() : (int) $row['proceso'];
		$business=self::approverPermission($entity,$row);
		foreach($range['pasos'] as $step) {
			$parameters=[];
			$where='u.activo=1 AND (u.organizacion=? OR t.codigo=\'superadmin\') AND u.id<>?';
			$parameters=[$row['organizacion'],$row['creado_por']];
			if($step['tipo']==='usuario') {
				$where.=' AND u.uuid=?';
				$parameters[]=Values::binary($step['referencia']);
			}
			elseif($step['tipo']==='rol') {
				$where.=' AND EXISTS (SELECT 1 FROM usuarios_procesos up JOIN usuarios_tipos role ON role.id=up.tipo_usuario WHERE up.usuario=u.id AND up.proceso=? AND up.activo=1 AND up.f_baja IS NULL AND role.codigo=?)';
				$parameters[]=$process;
				$parameters[]=$step['referencia'];
			}
			$found=null;
			foreach($this->repository->rows("SELECT u.id FROM usuarios u JOIN usuarios_tipos t ON t.id=u.tipo_usuario WHERE $where ORDER BY u.id",$parameters) as $user) {
				try {
					$this->context->record($entity,(int) $row['id'],$business,false,(int) $user['id']);
					(new Access($this->repository))->context((int) $row['organizacion'],$process,'aprobaciones.resolver',(int) $user['id']);
					if($step['tipo']==='permiso') (new Access($this->repository))->context((int) $row['organizacion'],$process,$step['referencia'],(int) $user['id']);
					$found=(int) $user['id'];
					break;
				} catch(HttpError) {
				}
			}
			if($found===null) throw new HttpError('No hay aprobador autorizado distinto del solicitante para un paso de la política.',422);
			$selected[]=$found;
		}
		return ['rango'=>$range,'aprobadores'=>$selected];
	}

	public function submit(string $entity,array $row,int $amount): array
	{
		$process=$row['proceso']===null ? null : (int) $row['proceso'];
		$policy=$this->repository->one('SELECT * FROM finanzas_politicas WHERE organizacion=? AND contexto IN (0,?) ORDER BY contexto DESC LIMIT 1',[$row['organizacion'],$process ?? 0]);
		$config=$policy ? json_decode($policy['configuracion'],true,512,JSON_THROW_ON_ERROR) : ['modo'=>'sin_aprobacion','rangos'=>[]];
		$snapshot=['politica'=>$policy ? Values::uuidText($policy['uuid']) : null,'version_politica'=>$policy['version'] ?? null,'configuracion'=>$config,'importe'=>Amount::format($amount),'revision'=>(int) $row['version']+1,'solicitante'=>(int) $row['creado_por']];
		if($config['modo']!=='sin_aprobacion') $snapshot=[...$snapshot,...$this->approvers($config,$entity,$row,$amount)];
		$state=$config['modo']==='sin_aprobacion' ? 'aprobado' : 'validacion';
		$fields=['estado'=>$state,'autorizacion'=>Values::json($snapshot),'aprobacion'=>null];
		if($entity==='gastos') $fields['autorizado_en']=$state==='aprobado' ? date('Y-m-d H:i:s') : null;
		$this->repository->update($entity,(int) $row['id'],$fields);
		$row=$this->repository->raw($entity,(int) $row['id']);
		if($state==='validacion') {
			$uuid=(new ApprovalService($this->repository))->create((int) $row['organizacion'],$process,Catalog::entity($entity)['table'],(int) $row['id'],(int) $row['creado_por'],$snapshot['aprobadores'],$snapshot,['operacion'=>'Autorización financiera','folio'=>$row['folio']]);
			$id=$this->repository->one('SELECT id FROM aprobaciones WHERE uuid=?',[Values::binary($uuid)])['id'];
			$table=Catalog::entity($entity)['table'];
			$this->repository->execute("UPDATE $table SET aprobacion=? WHERE id=?",[$id,$row['id']]);
			foreach(array_unique($snapshot['aprobadores']) as $recipient) (new Alerts($this->repository))->notify($recipient,$row,'Autorización financiera pendiente','Revisa la solicitud asignada en Servicios y notificaciones.','aprobacion:'.$uuid.':'.$recipient);
		}
		$this->context->history($entity,$row,$state==='aprobado' ? 'autorizar_sin_revision' : 'solicitar_autorizacion','',null,$snapshot);
		return $this->repository->raw($entity,(int) $row['id']);
	}

	public function decision(array $approval,string $state,int $actor): void
	{
		$entity=Catalog::fromTable($approval['entidad']);
		$row=$this->repository->raw($entity,(int) $approval['entidad_id'],true);
		if((int) ($row['aprobacion'] ?? 0)!==(int) $approval['id']) throw new HttpError('La autorización fue sustituida por otra versión.',409);
		$snapshot=json_decode($approval['regla'],true,512,JSON_THROW_ON_ERROR);
		if((int) $snapshot['revision']!==(int) $row['version']) throw new HttpError('Cambió el registro; solicita nueva autorización.',409);
		if($state==='cancelada' && !$this->context->enabled('finanzas',(int) $row['organizacion'],$row['proceso']===null ? Auth::processId() : (int) $row['proceso'])) throw new HttpError('El módulo deshabilitado no permite cancelar la autorización financiera.',403);
		if($state==='aprobada') {
			if($actor===(int) $row['creado_por']) throw new HttpError('No se permite aprobar la solicitud propia.',403);
			$this->context->record($entity,(int) $row['id'],self::approverPermission($entity,$row),true,$actor);
		}
		$fields=['estado'=>$state==='aprobada' ? 'aprobado' : 'correccion'];
		if($entity==='gastos') $fields['autorizado_en']=$state==='aprobada' ? date('Y-m-d H:i:s') : null;
		$this->repository->update($entity,(int) $row['id'],$fields);
		$this->context->history($entity,$row,'resolver_autorizacion','',null,['estado'=>$fields['estado']],$actor);
		(new Alerts($this->repository))->notify((int) $row['creado_por'],$row,'Autorización financiera resuelta','Consulta el resultado de la autorización.','resultado:'.$approval['id']);
		if($entity==='gastos') (new ExpenseService($this->repository))->refresh((int) $row['id']);
	}
}
