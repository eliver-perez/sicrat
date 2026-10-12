<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Core\Auth;
use App\Core\HttpError;
use App\Repositories\CommonRepository;
use App\Repositories\OperationsRepository;
use App\Services\Common\Values;

abstract class BaseService
{
	protected OperationsRepository $repository;
	protected Context $context;
	public function __construct(CommonRepository $repository)
	{
		$this->repository=new OperationsRepository($repository->connection()); $this->context=new Context($this->repository);
	}
	abstract protected function entity(): string;
	public function listing(array $filters): array
	{
		$entity=$this->entity(); $definition=Catalog::entity($entity); $process=$this->context->process($filters['process'] ?? null);
		[$where,$params]=$this->context->predicate($entity,$process,$definition['permission'].'.ver');
		if (($filters['search'] ?? '')!=='') {
			$search='%'.Values::text($filters['search'],150).'%';
			$where.=" AND (r.{$definition['name']} LIKE ?"; $params[]=$search;
			if (in_array($entity,['peticiones','incidencias'],true)) { $where.=' OR r.folio LIKE ?'; $params[]=$search; }
			$where.=')';
		}
		if (($filters['state'] ?? '')!=='') { $where.=' AND r.estado=?'; $params[]=Values::text($filters['state'],24); }
		if (($filters['person'] ?? '')!=='') { $person=$this->context->person($filters['person'],$definition['permission'].'.ver',$process,false); $where.=' AND r.persona=?'; $params[]=$person['id']; }
		$folio=in_array($entity,['peticiones','incidencias'],true) ? ',r.folio' : '';
		$result=$this->repository->page("SELECT r.id,r.uuid,r.{$definition['name']} titulo,r.estado,r.{$definition['date']} fecha,r.version$folio FROM $entity r WHERE $where ORDER BY r.id DESC",$params,$filters);
		foreach ($result['rows'] as &$row) $row['uuid']=Values::uuidText($row['uuid']);
		return $result;
	}
	public function detail(string $uuid): array { return $this->context->record($this->entity(),$uuid,Catalog::entity($this->entity())['permission'].'.ver'); }
	public function history(string $uuid,array $filters): array
	{
		$row=$this->detail($uuid);
		return $this->repository->page('SELECT h.accion,h.nota,h.fecha,u.nombre usuario FROM operaciones_historial h JOIN usuarios u ON u.id=h.usuario WHERE h.entidad=? AND h.registro=? ORDER BY h.id DESC',[$this->entity(),$row['id']],$filters);
	}
	public function comment(string $uuid,array $data): void
	{
		$entity=$this->entity(); $prefix=Catalog::entity($entity)['permission'];
		$permission=in_array($entity,['peticiones','incidencias'],true) ? $prefix.'.comentar' : $prefix.'.editar';
		$this->repository->transaction(function() use($entity,$uuid,$data,$permission) {
			$row=$this->context->record($entity,$uuid,$permission,null,true);
			if (in_array($row['estado'],['cerrada','cancelada','cancelado'],true)) throw new HttpError('El registro está cerrado.',409);
			$this->context->history($entity,$row,'comentar',Values::text($data['note'] ?? null,4000));
		});
	}
	public function files(string $uuid,array $filters): array
	{
		$row=$this->detail($uuid); $entity=$this->entity();
		(new \App\Services\Common\Access($this->repository))->context((int) $row['organizacion'],(int) $row['proceso'],'archivos.ver');
		$this->context->record($entity,(int) $row['id'],'archivos.ver');
		$result=$this->repository->page("SELECT uuid,nombre_original,mime_type,ancho,alto,f_registro FROM archivos WHERE entidad=? AND entidad_id=? AND estado='activo' ORDER BY id DESC",[$entity,$row['id']],$filters);
		foreach ($result['rows'] as &$file) $file['uuid']=Values::uuidText($file['uuid']); return $result;
	}
	protected function personReference(mixed $reference,string $permission,int $process,bool $participation = true): ?array
	{
		return $reference===null || $reference==='' ? null : $this->context->person($reference,$permission,$process,$participation);
	}
	protected function newRow(string $entity,array $data,string $note = ''): array
	{
		$created=$this->repository->insertRecord($entity,$data); $row=[...$data,...$created];
		$this->context->history($entity,$row,'crear',$note,null,['estado'=>$data['estado'] ?? 'registrada']);
		return $row;
	}
	protected function linkedEntity(mixed $entity,mixed $reference,int $process,string $permission): ?array
	{
		if ($entity===null || $entity==='') return null;
		$entity=Catalog::choice($entity,['personas','eventos','peticiones','incidencias','interacciones']);
		if ($entity==='personas') return ['entity'=>$entity,'record'=>(int) $this->context->person($reference,$permission,$process)['id']];
		$record=$this->context->record($entity,$reference,Catalog::entity($entity)['permission'].'.ver',null,false,$process);
		if ($entity!=='interacciones' && (int) $record['proceso']!==$process) throw new HttpError('La relación pertenece a otra elección.',422);
		return ['entity'=>$entity,'record'=>(int) $record['id']];
	}
}
