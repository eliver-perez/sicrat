<?php

declare(strict_types=1);

namespace App\Services\Common;

use App\Core\Auth;
use App\Core\HttpError;
use App\Core\Pagination;
use App\Repositories\CommonRepository;

class QueueService
{
	public function __construct(private CommonRepository $repository) {}
	public function enqueue(string $type,array $payload,?int $organization = null,?int $process = null,?int $actor = null,?string $idempotency = null,int $priority = 0,int $attempts = 3,?string $available = null): string
	{
		$type=Values::text($type,80); $priority=Values::integer($priority,-100,100); $attempts=Values::integer($attempts,1,10);
		if (Values::json(Sanitizer::clean($payload))!==Values::json($payload)) throw new \InvalidArgumentException('El payload debe contener referencias operativas sin secretos ni datos personales.');
		if (strlen(Values::json($payload))>65536) throw new \InvalidArgumentException('Payload demasiado grande.');
		$uuid=Values::uuid(); $key=$idempotency===null ? null : hash('sha256',$idempotency);
		try { $this->repository->execute('INSERT INTO trabajos (id,tipo,payload,organizacion,proceso,usuario,prioridad,max_intentos,disponible_en,idempotencia,fecha) VALUES (?,?,?,?,?,?,?,?,COALESCE(?,NOW()),?,NOW())',[$uuid,$type,Values::json($payload),$organization,$process,$actor,$priority,$attempts,$available,$key]); }
		catch (\PDOException $error) {
			if (($error->errorInfo[1] ?? 0)!==1062 || $key===null) throw $error;
			$existing=$this->repository->one('SELECT id,organizacion,proceso,usuario FROM trabajos WHERE tipo=? AND idempotencia=?',[$type,$key]);
			if (!$existing || $existing['organizacion']!=$organization || $existing['proceso']!=$process || $existing['usuario']!=$actor) throw new HttpError('Clave de idempotencia de otro contexto.',409);
			return $existing['id'];
		}
		return $uuid;
	}
	private function lockName(string $id): string { return 'sicrat:job:'.substr(hash('sha256',$this->repository->connection()->query('SELECT DATABASE()')->fetchColumn().$id),0,48); }
	private function namedLock(string $name,bool $acquire): bool
	{
		$row=$this->repository->one($acquire ? 'SELECT GET_LOCK(?,0) result' : 'SELECT RELEASE_LOCK(?) result',[$name]);
		return (int) ($row['result'] ?? 0)===1;
	}
	public function recover(): int
	{
		return $this->repository->transaction(function () {
			$count=0;
			foreach ($this->repository->rows("SELECT id,tipo,payload,intentos,max_intentos FROM trabajos WHERE estado='ejecutando' AND bloqueo_hasta<NOW() ORDER BY id LIMIT 100 FOR UPDATE") as $row) {
				// Un worker vivo conserva un bloqueo de conexión; expirar el lease no duplica su efecto.
				if ((int) ($this->repository->one('SELECT IS_FREE_LOCK(?) result',[$this->lockName($row['id'])])['result'] ?? 0)!==1) continue;
				if (!$this->namedLock($this->lockName($row['id']),true)) continue;
				try {
					$failed=(int) $row['intentos']>=(int) $row['max_intentos'];
					$this->repository->execute('UPDATE trabajos SET estado=?,worker=NULL,lease=NULL,bloqueo_hasta=NULL,disponible_en=NOW(),fin=?,error=? WHERE id=?',[$failed ? 'fallido' : 'reintento',$failed ? date('Y-m-d H:i:s') : null,'El worker perdió su bloqueo; requiere recuperación.',$row['id']]); $count++;
					if ($failed) $this->failedResource($row,'El worker terminó sin confirmar el resultado. Revisar antes de una nueva solicitud.');
				} finally { $this->namedLock($this->lockName($row['id']),false); }
			}
			return $count;
		});
	}
	public function claim(string $worker,?string $type = null,int $leaseSeconds = 900): ?array
	{
		$worker=Values::text($worker,100); $leaseSeconds=Values::integer($leaseSeconds,30,86400);
		$this->recover();
		return $this->repository->transaction(function () use ($worker,$type,$leaseSeconds) {
			$params=$type===null ? [] : [$type];
			$row=$this->repository->one("SELECT * FROM trabajos WHERE estado IN ('pendiente','reintento') AND disponible_en<=NOW()".($type===null ? '' : ' AND tipo=?').' ORDER BY prioridad DESC,disponible_en,id LIMIT 1 FOR UPDATE',$params);
			if (!$row || !$this->namedLock($this->lockName($row['id']),true)) return null;
			$lease=Values::uuid();
			try {
				$this->repository->execute("UPDATE trabajos SET estado='ejecutando',intentos=intentos+1,worker=?,lease=?,bloqueo_hasta=DATE_ADD(NOW(),INTERVAL $leaseSeconds SECOND),inicio=COALESCE(inicio,NOW()),error=NULL WHERE id=?",[$worker,$lease,$row['id']]);
			} catch (\Throwable $error) { $this->namedLock($this->lockName($row['id']),false); throw $error; }
			$row['lease']=$lease; $row['worker']=$worker; $row['intentos']=(int) $row['intentos']+1; $row['payload']=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
			return $row;
		});
	}
	public function heartbeat(array $job,int $seconds = 900): void
	{
		$seconds=Values::integer($seconds,30,86400);
		if (!$this->repository->execute("UPDATE trabajos SET bloqueo_hasta=DATE_ADD(NOW(),INTERVAL $seconds SECOND) WHERE id=? AND lease=? AND estado='ejecutando'",[$job['id'],$job['lease']])) throw new ServiceFailure('Se perdió el lease del trabajo.',false);
	}
	public function finish(array $job,?\Throwable $error = null): void
	{
		try {
			$permanent=$error instanceof HttpError && $error->statusCode>=400 && $error->statusCode<500;
			$retry=$error!==null && !$permanent && (!$error instanceof ServiceFailure || $error->retryable) && (int) $job['intentos']<(int) $job['max_intentos'];
			$state=$error===null ? 'completado' : ($retry ? 'reintento' : 'fallido');
			$delay=min(3600,30*(2**max(0,(int) $job['intentos']-1)));
			if (!$this->repository->execute("UPDATE trabajos SET estado=?,error=?,fin=?,disponible_en=DATE_ADD(NOW(),INTERVAL $delay SECOND),bloqueo_hasta=NULL,worker=NULL,lease=NULL WHERE id=? AND lease=? AND estado='ejecutando'",[$state,$error===null ? null : Sanitizer::error($error),$retry ? null : date('Y-m-d H:i:s'),$job['id'],$job['lease']])) throw new ServiceFailure('Se perdió el lease del trabajo.',false);
			if ($state==='fallido') $this->failedResource($job,Sanitizer::error($error));
		} finally { $this->namedLock($this->lockName($job['id']),false); }
	}
	private function failedResource(array $job,string $error): void
	{
		$payload=is_array($job['payload']) ? $job['payload'] : json_decode($job['payload'],true);
		foreach (['respaldo'=>['respaldos','respaldo_id','fallido'],'exportacion'=>['exportaciones','exportacion_id','fallida'],'restauracion'=>['restauraciones','restauracion_id','fallida']] as $type=>[$table,$key,$state]) if ($job['tipo']===$type) $this->repository->execute("UPDATE $table SET estado=?,error=? WHERE id=? AND estado IN ('pendiente','ejecutando')",[$state,$error,$payload[$key]]);
	}
	public function listing(array $filters): array
	{
		$organization=Auth::organizationId(); $process=Auth::processId(); $where='1=1'; $params=[];
		if (!Auth::isSuperAdmin()) {
			$effective=(new Access($this->repository))->broad($organization ?? 0,$process,'trabajos.ver');
			$where='organizacion=?'; $params[]=$organization;
			if (!in_array('organizacion',$effective['scopes']['trabajos.ver'],true)) { $where.=' AND proceso=?'; $params[]=$process; }
		} else (new Access($this->repository))->super();
		if (!empty($filters['state'])) { $where.=' AND estado=?'; $params[]=Values::text($filters['state'],16); }
		$limit=Pagination::limit($filters['limit'] ?? 25); $offset=Pagination::offset($filters['offset'] ?? 0);
		$rows=$this->repository->rows("SELECT id,tipo,estado,intentos,max_intentos,disponible_en,inicio,fin,error,(usuario=".(int) Auth::id().") own_job FROM trabajos WHERE $where ORDER BY fecha DESC,id LIMIT ".($limit+1).' OFFSET '.$offset,$params);
		return ['rows'=>array_slice($rows,0,$limit),'pagination'=>Pagination::response($rows,$limit,$offset),'summary'=>$this->repository->rows("SELECT estado,COUNT(*) total FROM trabajos WHERE $where GROUP BY estado",$params)];
	}
	public function administer(string $id,bool $retry): void
	{
		Values::binary($id);
		$this->repository->transaction(function () use ($id,$retry) {
			$job=$this->repository->one('SELECT * FROM trabajos WHERE id=? FOR UPDATE',[$id]) ?? throw new HttpError('Trabajo no disponible.',404);
			$actor=(new Access($this->repository))->actor();
			if ($job['organizacion']===null || in_array($job['tipo'],['respaldo','restauracion','verificar_respaldo','mantenimiento'],true)) (new Access($this->repository))->super();
			else {
				(new Access($this->repository))->context((int) $job['organizacion'],$job['proceso']===null ? null : (int) $job['proceso'],'trabajos.administrar');
				if ((int) $job['usuario']!==$actor) (new Access($this->repository))->super();
			}
			$payload=json_decode($job['payload'],true);
			if ($retry) {
				if ($job['estado']!=='fallido' || $job['tipo']==='restauracion') throw new HttpError('Este trabajo requiere una nueva solicitud y revisión.',409);
				if ($job['tipo']==='notificacion') {
					$delivery=$this->repository->one('SELECT estado FROM notificaciones_envios WHERE id=?',[$payload['envio_id']]);
					if (!$delivery || $delivery['estado']!=='fallido') throw new HttpError('No se reintenta un envío de resultado incierto o ya confirmado.',409);
				}
				$this->repository->execute("UPDATE trabajos SET estado='reintento',intentos=0,disponible_en=NOW(),fin=NULL,error=NULL WHERE id=?",[$id]);
			} else {
				if (!in_array($job['estado'],['pendiente','reintento'],true)) throw new HttpError('No se cancela un trabajo activo o terminado.',409);
				$this->repository->execute("UPDATE trabajos SET estado='cancelado',fin=NOW() WHERE id=?",[$id]);
				foreach (['respaldo'=>['respaldos','respaldo_id','cancelado'],'exportacion'=>['exportaciones','exportacion_id','cancelada'],'notificacion'=>['notificaciones_envios','envio_id','cancelado'],'restauracion'=>['restauraciones','restauracion_id','cancelada']] as $type=>[$table,$key,$state]) if ($job['tipo']===$type) $this->repository->execute("UPDATE $table SET estado=? WHERE id=? AND estado IN ('pendiente','fallido','fallida')",[$state,$payload[$key]]);
			}
			(new AuditService($this->repository))->record($job['organizacion']===null ? null : (int) $job['organizacion'],$job['proceso']===null ? null : (int) $job['proceso'],$retry ? 'reintentar' : 'cancelar','trabajos',$id,['estado'=>$job['estado']],['estado'=>$retry ? 'reintento' : 'cancelado']);
		});
	}
}
