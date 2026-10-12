<?php

declare(strict_types=1);

namespace App\Services\Common;

use App\Core\Auth;
use App\Core\HttpError;
use App\Core\Pagination;
use App\Repositories\CommonRepository;

class AuditService
{
	public function __construct(private CommonRepository $repository) {}
	public function record(?int $organization, ?int $process, string $action, string $entity, int|string|null $record, mixed $before = null, mixed $after = null, array $information = [], ?int $actor = null): void
	{
		if ($process!==null) {
			$context = $this->repository->one('SELECT organizacion FROM procesos_electorales WHERE id=?',[$process]);
			if (!$context || (int) $context['organizacion']!==$organization) throw new \InvalidArgumentException('Contexto de auditoría incompatible.');
		}
		$ip = $_SERVER['REMOTE_ADDR'] ?? null;
		$this->repository->execute('INSERT INTO auditoria (organizacion,proceso,usuario,accion,entidad,entidad_id,referencia,datos_anteriores,datos_nuevos,informacion,ip,user_agent,f_registro) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())',[
			$organization,$process,$actor ?? Auth::id(),Values::text($action,50),Values::text($entity,80),is_int($record) ? $record : null,$record===null ? null : Values::text((string) $record,190),
			$before===null ? null : Values::json(Sanitizer::clean($before)),$after===null ? null : Values::json(Sanitizer::clean($after)),Values::json(Sanitizer::clean($information)),
			$ip && filter_var($ip,FILTER_VALIDATE_IP) ? $ip : null,isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string) Sanitizer::clean($_SERVER['HTTP_USER_AGENT']),0,500) : null,
		]);
	}
	public function listing(array $filters): array
	{
		$access = new Access($this->repository);
		$allProcesses=($filters['process'] ?? '')==='all';
		$process = $allProcesses ? null : (isset($filters['process']) && $filters['process']!=='' ? Values::integer($filters['process']) : Auth::processId());
		$organization = isset($filters['organization']) && $filters['organization']!=='' ? Values::integer($filters['organization']) : Auth::organizationId();
		$where = []; $parameters = [];
		try { $access->super(); $super = true; } catch (HttpError) { $super = false; }
		if (!$super) {
			if ($organization===null) throw new HttpError('Organización requerida.',403);
			$effective = $access->broad($organization,$allProcesses ? Auth::processId() : $process,'auditoria.ver');
			if ($allProcesses && !in_array('organizacion',$effective['scopes']['auditoria.ver'],true)) throw new HttpError('Consultar todos los contextos requiere alcance de organización.',403);
			if (!in_array('organizacion',$effective['scopes']['auditoria.ver'],true)) {
				$where[] = 'proceso=?'; $parameters[] = $process;
			}
		}
		if ($organization!==null) { $where[]='organizacion=?'; $parameters[]=$organization; }
		if ($process!==null) { $where[]='proceso=?'; $parameters[]=$process; }
		foreach (['user'=>'usuario','action'=>'accion','entity'=>'entidad'] as $key=>$column) if (isset($filters[$key]) && $filters[$key]!=='') { $where[]="$column=?"; $parameters[]=$key==='user' ? Values::integer($filters[$key]) : Values::text($filters[$key],$key==='action' ? 50 : 80); }
		if (!empty($filters['from'])) { $where[]='f_registro>=?'; $parameters[]=Values::date($filters['from']).' 00:00:00'; }
		if (!empty($filters['to'])) { $where[]='f_registro<?'; $parameters[]=(new \DateTimeImmutable(Values::date($filters['to'])))->modify('+1 day')->format('Y-m-d'); }
		$limit=Pagination::limit($filters['limit'] ?? 25); $offset=Pagination::offset($filters['offset'] ?? 0);
		$rows=$this->repository->rows('SELECT * FROM auditoria WHERE '.($where ? implode(' AND ',$where) : '1=1').' ORDER BY id DESC LIMIT '.($limit+1).' OFFSET '.$offset,$parameters);
		foreach ($rows as &$row) foreach (['datos_anteriores','datos_nuevos','informacion'] as $column) $row[$column]=$row[$column]===null ? null : Sanitizer::clean(json_decode($row[$column],true));
		return ['rows'=>array_slice($rows,0,$limit),'pagination'=>Pagination::response($rows,$limit,$offset)];
	}
}
