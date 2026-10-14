<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Core\HttpError;
use App\Repositories\CommonRepository;
use App\Services\Common\ApprovalService;
use App\Services\Common\Values;

class CaseService extends BaseService
{
	public function __construct(CommonRepository $repository,private string $kind) { Catalog::choice($kind,['peticiones','incidencias']); parent::__construct($repository); }
	protected function entity(): string { return $this->kind; }
	public function create(array $data): array
	{
		$entity=$this->kind; $process=$this->context->process($data['process'] ?? null); $context=$this->context->require($entity,$process,$entity.'.crear');
		return $this->repository->transaction(function() use($entity,$process,$context,$data) {
			$category=(new CaseCategories($this->repository))->resolve($entity,$process,$data);
			$person=$this->personReference($data['person'] ?? null,$entity.'.crear',$process);
			$responsible=$this->personReference($data['responsible'] ?? null,$entity.'.crear',$process);
			$priority=$this->repository->one('SELECT id FROM tareas_prioridades WHERE codigo=? AND activo=1',[$data['priority'] ?? 'NORMAL'])['id'] ?? throw new \InvalidArgumentException('Prioridad inválida.');
			$record=['organizacion'=>$context['organization'],'proceso'=>$process,'persona'=>$person['id'] ?? null,'descripcion'=>Values::text($data['description'] ?? null,10000),'categoria'=>Values::text($data['category'] ?? 'General',100),'prioridad'=>$priority,'responsable'=>$responsible['id'] ?? null,'estado'=>'abierta','folio'=>$this->repository->folio($context['organization'],$entity),'requiere_validacion'=>Values::integer($data['validation'] ?? 0,0,1),'permite_pdf'=>Values::integer($data['allow_pdf'] ?? 0,0,1)];
			if ($entity==='peticiones') {
				$record['capturado_por']=$context['actor']; $record['fecha']=date('Y-m-d H:i:s');
				$record['solicitante_externo']=$person===null ? Values::text($data['external_name'] ?? null,180) : null;
				$record['contacto_externo']=$person===null ? Values::text($data['external_contact'] ?? '',150,true) : null;
			} else {
				$record['tipo']=$this->repository->one('SELECT id FROM incidencias_tipos WHERE codigo=? AND activo=1',[$data['type'] ?? 'OTRA'])['id'] ?? throw new \InvalidArgumentException('Tipo de incidencia inválido.');
				$record['estatus']=1; $record['reportado_por']=$context['actor']; $record['f_reporte']=date('Y-m-d H:i:s');
				$event=($data['event'] ?? '')!=='' ? $this->context->record('eventos',$data['event'],'eventos.ver') : null;
				if ($event!==null && (int) $event['proceso']!==$process) throw new HttpError('El evento corresponde a otra elección.',422);
				$record['evento']=$event['id'] ?? null;
			}
			$row=$this->newRow($entity,[...$record,...$category]);
			foreach (Catalog::list($data['related_people'] ?? []) as $reference) {
				$related=$this->context->person($reference,$entity.'.crear',$process);
				$this->repository->execute('INSERT IGNORE INTO operaciones_personas (entidad,registro,persona) VALUES (?,?,?)',[$entity,$row['id'],$related['id']]);
			}
			if (($data['approver'] ?? '')!=='') {
				$approver=$this->context->approver($entity,$row,$entity.'.validar',$data['approver']);
				$this->repository->execute("UPDATE $entity SET aprobador=? WHERE id=?",[$approver,$row['id']]);
			}
			return ['uuid'=>$row['uuid'],'folio'=>$row['folio']];
		});
	}
	public function detail(string $uuid): array
	{
		$row=parent::detail($uuid); $row['permisos_capturista']=$row['permisos_capturista']===null ? null : json_decode($row['permisos_capturista'],true);
		$category=$row['categoria_id'] ? $this->repository->one('SELECT uuid,nombre FROM casos_categorias WHERE id=?',[$row['categoria_id']]) : null;
		$row['categoria_uuid']=$category ? Values::uuidText($category['uuid']) : null;$row['categoria_nombre']=$category['nombre'] ?? $row['categoria'];
		$row['approval_uuid']=$row['aprobacion'] ? Values::uuidText($this->repository->one('SELECT uuid FROM aprobaciones WHERE id=?',[$row['aprobacion']])['uuid']) : null;
		foreach (['persona','responsable'] as $field) $row[$field.'_nombre']=$row[$field]===null ? null : $this->repository->one("SELECT CONCAT_WS(' ',nombre,paterno,materno) nombre FROM personas WHERE id=?",[$row[$field]])['nombre'];
		$row['capturista_nombre']=$this->repository->one('SELECT nombre FROM usuarios WHERE id=?',[$row[Catalog::entity($this->kind)['creator']]])['nombre'];
		return $row;
	}
	public function update(string $uuid,array $data): void
	{
		$entity=$this->kind;
		$this->repository->transaction(function() use($entity,$uuid,$data) {
			$row=$this->context->record($entity,$uuid,$entity.'.editar',null,true); $this->context->version($row,$data);
			if (in_array($row['estado'],['cerrada','cancelada','validacion'],true)) throw new HttpError('Resuelve la validación o revisa el cierre antes de editar.',409);
			$changes=['descripcion'=>Values::text($data['description'] ?? $row['descripcion'],10000),...(new CaseCategories($this->repository))->resolve($entity,(int)$row['proceso'],$data,$row)];
			if (isset($data['state'])) $changes['estado']=Catalog::choice($data['state'],['abierta','en_atencion','correccion']);
			$this->repository->updateRecord($entity,(int) $row['id'],$changes); $this->context->history($entity,$row,'editar',Values::text($data['reason'] ?? '',4000,true),['estado'=>$row['estado'],'categoria'=>$row['categoria'],'categoria_id'=>$row['categoria_id'],'descripcion'=>$row['descripcion']],$changes);
		});
	}
	public function reassign(string $uuid,array $data): void
	{
		$entity=$this->kind;
		$this->repository->transaction(function() use($entity,$uuid,$data) {
			$row=$this->context->record($entity,$uuid,$entity.'.transferir',null,true); $this->context->version($row,$data);
			if (in_array($row['estado'],['cerrada','cancelada'],true)) throw new HttpError('El registro está cerrado.',409);
			$person=$this->context->person($data['responsible'] ?? null,$entity.'.transferir',(int) $row['proceso']);
			$permissions=$data['retained'] ?? ['ver'=>true]; if (!is_array($permissions) || count($permissions)>5) throw new \InvalidArgumentException('Permisos conservados inválidos.');
			$clean=[];
			foreach ($permissions as $action=>$value) {
				Catalog::choice($action,['ver','comentar','editar','transferir','cancelar']); $enabled=Values::integer($value,0,1)===1;
				if ($enabled) $this->context->record($entity,(int) $row['id'],$entity.'.'.$action);
				$clean[$action]=$enabled;
			}
			$reason=Values::text($data['reason'] ?? null,500);
			if ($row['aprobacion']!==null) (new ApprovalService($this->repository))->withdrawForResource((int) $row['aprobacion'],$entity,(int) $row['id'],$entity.'.transferir','Reasignación: '.$reason);
			$this->repository->updateRecord($entity,(int) $row['id'],['responsable'=>$person['id'],'permisos_capturista'=>Values::json($clean),'aprobador'=>null,'estado'=>'en_atencion','aprobacion'=>null]);
			$this->context->history($entity,$row,'reasignar',$reason,['responsable'=>$row['responsable'],'estado'=>$row['estado']],['responsable'=>$person['id'],'permisos_capturista'=>$clean,'estado'=>'en_atencion']);
		});
	}
	public function close(string $uuid,array $data): array
	{
		$entity=$this->kind;
		return $this->repository->transaction(function() use($entity,$uuid,$data) {
			$row=$this->context->record($entity,$uuid,$entity.'.cerrar',null,true); $this->context->version($row,$data);
			if (in_array($row['estado'],['cerrada','cancelada','validacion'],true)) throw new HttpError('El registro está cerrado o pendiente de validación.',409);
			$reason=Values::text($data['resolution'] ?? null,4000);
			$changes=['resolucion'=>$reason,'estado'=>'cerrada']; $approval=null;
			if ((int) $row['requiere_validacion']) {
				$reference=$data['approver'] ?? ($row['aprobador'] ? Values::uuidText($this->repository->one('SELECT uuid FROM usuarios WHERE id=?',[$row['aprobador']])['uuid']) : null);
				$approver=$this->context->approver($entity,$row,$entity.'.validar',$reference);
				$approval=(new ApprovalService($this->repository))->create((int) $row['organizacion'],(int) $row['proceso'],$entity,(int) $row['id'],$this->context->actor(),[$approver],['version'=>(int) $row['version']+1,'responsable'=>$row['responsable']],['titulo'=>$row['folio'],'operacion'=>'Validar cierre']);
				$changes['estado']='validacion'; $changes['aprobacion']=$this->repository->one('SELECT id FROM aprobaciones WHERE uuid=?',[Values::binary($approval)])['id'];
			} elseif ($entity==='incidencias') { $changes['f_resolucion']=date('Y-m-d H:i:s'); $changes['estatus']=4; }
			$this->repository->updateRecord($entity,(int) $row['id'],$changes); $this->context->history($entity,$row,'solicitar_cierre',$reason,['estado'=>$row['estado']],['estado'=>$changes['estado']]);
			return ['state'=>$changes['estado'],'approval'=>$approval];
		});
	}
	public function cancel(string $uuid,array $data): void
	{
		$entity=$this->kind;
		$this->repository->transaction(function() use($entity,$uuid,$data) {
			$row=$this->context->record($entity,$uuid,$entity.'.cancelar',null,true); $this->context->version($row,$data);
			if ($row['estado']==='cerrada' || $row['estado']==='cancelada') throw new HttpError('El registro ya terminó.',409);
			if ($row['aprobacion']!==null) throw new HttpError('Cancela la validación pendiente antes de cancelar el registro.',409);
			$this->repository->updateRecord($entity,(int) $row['id'],['estado'=>'cancelada']); $this->context->history($entity,$row,'cancelar',Values::text($data['reason'] ?? null,500),['estado'=>$row['estado']],['estado'=>'cancelada']);
		});
	}
}
