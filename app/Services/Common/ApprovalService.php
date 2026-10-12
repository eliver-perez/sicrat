<?php

declare(strict_types=1);

namespace App\Services\Common;

use App\Core\Auth;
use App\Core\HttpError;
use App\Core\Pagination;
use App\Repositories\CommonRepository;

class ApprovalService
{
	public function __construct(private CommonRepository $repository,private ?ResourceRegistry $owners = null) { $this->owners ??= new ResourceRegistry($repository); }
	// Punto de integración interno: cada módulo entrega pasos explícitos y su snapshot, después de validar su regla.
	public function create(int $organization,?int $process,string $entity,int $record,int $requester,array $approvers,array $snapshot,array $information = [],?string $expires = null): string
	{
		$this->owners->authorize($entity,$record,$organization,$process,'write',$requester);
		if (count($approvers)<1 || count($approvers)>20) throw new \InvalidArgumentException('Se requieren entre uno y veinte pasos.');
		$access = new Access($this->repository);
		foreach ($approvers as $approver) {
			$access->context($organization,$process,'aprobaciones.resolver',Values::integer($approver));
			$this->owners->authorize($entity,$record,$organization,$process,'read',(int) $approver,'aprobaciones.resolver');
		}
		if ($expires!==null && (!preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/',$expires) || strtotime($expires)<=time())) throw new \InvalidArgumentException('Caducidad inválida.');
		return $this->repository->transaction(function () use ($organization,$process,$entity,$record,$requester,$approvers,$snapshot,$information,$expires) {
			$uuid = Values::uuid();
			$id=$this->repository->insert('INSERT INTO aprobaciones (uuid,organizacion,proceso,entidad,entidad_id,solicitante,regla,informacion,fecha,vence_en) VALUES (?,?,?,?,?,?,?,?,NOW(),?)',[Values::binary($uuid),$organization,$process,Values::text($entity,80),$record,$requester,Values::json(Sanitizer::clean($snapshot)),Values::json(Sanitizer::clean($information)),$expires]);
			foreach (array_values($approvers) as $position=>$approver) $this->repository->execute('INSERT INTO aprobaciones_pasos (aprobacion,orden,aprobador) VALUES (?,?,?)',[$id,$position+1,$approver]);
			(new AuditService($this->repository))->record($organization,$process,'solicitar','aprobaciones',$id,null,['pasos'=>count($approvers),'regla'=>$snapshot],[], $requester);
			return $uuid;
		});
	}
	private function view(array $row,int $actor): void
	{
		(new Access($this->repository))->context((int) $row['organizacion'],$row['proceso']===null ? null : (int) $row['proceso'],'aprobaciones.ver',$actor);
		$isParticipant=(int) $row['solicitante']===$actor || $this->repository->one('SELECT orden FROM aprobaciones_pasos WHERE aprobacion=? AND aprobador=?',[$row['id'],$actor]);
		if (!$isParticipant) (new Access($this->repository))->broad((int) $row['organizacion'],$row['proceso']===null ? null : (int) $row['proceso'],'aprobaciones.ver',$actor);
		$this->owners->authorize($row['entidad'],(int) $row['entidad_id'],(int) $row['organizacion'],$row['proceso']===null ? null : (int) $row['proceso'],'read',$actor,'aprobaciones.ver');
	}
	public function detail(string $uuid): array
	{
		$row=$this->repository->one('SELECT * FROM aprobaciones WHERE uuid=?',[Values::binary($uuid)]) ?? throw new HttpError('Solicitud no disponible.',404);
		$this->view($row,(new Access($this->repository))->actor());
		$row['uuid']=$uuid; $row['regla']=json_decode($row['regla'],true); $row['informacion']=json_decode($row['informacion'] ?? 'null',true);
		$row['steps']=$this->repository->rows('SELECT orden,aprobador,estado,resuelto_por,motivo,fecha FROM aprobaciones_pasos WHERE aprobacion=? ORDER BY orden',[$row['id']]);
		return $row;
	}
	public function resolve(string $uuid,bool $approved,string $reason): void
	{
		$actor=(new Access($this->repository))->actor(); $reason=Values::text($reason,500);
		$this->repository->transaction(function () use ($uuid,$approved,$reason,$actor) {
			$row=$this->repository->one('SELECT * FROM aprobaciones WHERE uuid=? FOR UPDATE',[Values::binary($uuid)]) ?? throw new HttpError('Solicitud no disponible.',404);
			ApprovalState::pending($row['estado']);
			if ($row['vence_en']!==null && strtotime($row['vence_en'])<=time()) throw new HttpError('La solicitud ha expirado.',409);
			$process=$row['proceso']===null ? null : (int) $row['proceso'];
			$effective=(new Access($this->repository))->context((int) $row['organizacion'],$process,'aprobaciones.resolver',$actor);
			$this->owners->authorize($row['entidad'],(int) $row['entidad_id'],(int) $row['organizacion'],$process,'read',$actor,'aprobaciones.resolver');
			$step=$this->repository->one("SELECT * FROM aprobaciones_pasos WHERE aprobacion=? AND estado='pendiente' ORDER BY orden LIMIT 1 FOR UPDATE",[$row['id']]);
			ApprovalState::resolver((int) $step['aprobador'],$actor,$effective['super']);
			$state=ApprovalState::decision($approved);
			$this->repository->execute('UPDATE aprobaciones_pasos SET estado=?,resuelto_por=?,motivo=?,fecha=NOW() WHERE aprobacion=? AND orden=?',[$state,$actor,$reason,$row['id'],$step['orden']]);
			$pending=$this->repository->one("SELECT orden FROM aprobaciones_pasos WHERE aprobacion=? AND estado='pendiente' LIMIT 1",[$row['id']]);
			if (!$approved || !$pending) $this->repository->execute('UPDATE aprobaciones SET estado=?,resuelta_en=NOW() WHERE id=?',[$state,$row['id']]);
			(new AuditService($this->repository))->record((int) $row['organizacion'],$process,$approved ? 'aprobar' : 'rechazar','aprobaciones',(int) $row['id'],['estado'=>'pendiente'],['estado'=>$pending && $approved ? 'pendiente' : $state,'paso'=>$step['orden'],'motivo'=>$reason]);
		});
	}
	public function cancel(string $uuid,string $reason): void
	{
		$actor=(new Access($this->repository))->actor();
		$this->repository->transaction(function () use ($uuid,$reason,$actor) {
			$row=$this->repository->one('SELECT * FROM aprobaciones WHERE uuid=? FOR UPDATE',[Values::binary($uuid)]) ?? throw new HttpError('Solicitud no disponible.',404);
			$this->view($row,$actor); ApprovalState::pending($row['estado']);
			if ((int) $row['solicitante']!==$actor) (new Access($this->repository))->super($actor);
			$this->repository->execute("UPDATE aprobaciones SET estado='cancelada',resuelta_en=NOW() WHERE id=?",[$row['id']]);
			(new AuditService($this->repository))->record((int) $row['organizacion'],$row['proceso']===null ? null : (int) $row['proceso'],'cancelar','aprobaciones',(int) $row['id'],null,['motivo'=>Values::text($reason,500)]);
		});
	}
	public function expire(): int
	{
		return $this->repository->transaction(function () {
			$rows=$this->repository->rows("SELECT * FROM aprobaciones WHERE estado='pendiente' AND vence_en<=NOW() LIMIT 100 FOR UPDATE");
			foreach ($rows as $row) {
				$this->repository->execute("UPDATE aprobaciones SET estado='expirada',resuelta_en=NOW() WHERE id=?",[$row['id']]);
				(new AuditService($this->repository))->record((int) $row['organizacion'],$row['proceso']===null ? null : (int) $row['proceso'],'expirar','aprobaciones',(int) $row['id']);
			}
			return count($rows);
		});
	}
	public function listing(array $filters): array
	{
		$actor=(new Access($this->repository))->actor(); $process=Auth::processId(); $organization=Auth::organizationId();
		if ($organization===null) { (new Access($this->repository))->super(); $where='1=1'; $params=[]; }
		else { (new Access($this->repository))->context($organization,$process,'aprobaciones.ver'); $where='organizacion=?'; $params=[$organization]; }
		// El listado siempre se restringe a participantes; el detalle puede autorizar administradores de alcance amplio.
		if (!Auth::isSuperAdmin()) { $where.=' AND (solicitante=? OR EXISTS (SELECT 1 FROM aprobaciones_pasos s WHERE s.aprobacion=a.id AND s.aprobador=?))'; $params[]=$actor; $params[]=$actor; }
		if ($process!==null) { $where.=' AND proceso=?'; $params[]=$process; }
		if (!Auth::isSuperAdmin()) {
			[$scope,$scopeParameters]=$this->owners->listingPredicate($process,$actor,'aprobaciones.ver');
			$where.=" AND ($scope)"; $params=[...$params,...$scopeParameters];
		}
		$limit=Pagination::limit($filters['limit'] ?? 25); $offset=Pagination::offset($filters['offset'] ?? 0);
		$rows=$this->repository->rows("SELECT id,uuid,estado,entidad,entidad_id,fecha,solicitante FROM aprobaciones a WHERE $where ORDER BY id DESC LIMIT ".($limit+1).' OFFSET '.$offset,$params);
		foreach ($rows as &$row) $row['uuid']=Values::uuidText($row['uuid']);
		return ['rows'=>array_slice($rows,0,$limit),'pagination'=>Pagination::response($rows,$limit,$offset)];
	}
}
