<?php

declare(strict_types=1);

namespace App\Services\Common;

use App\Core\Auth;
use App\Core\HttpError;
use App\Core\Pagination;
use App\Repositories\CommonRepository;

class BackupService
{
	private BackupDestination $destination;
	private PrivateStorage $files;
	private DatabaseDump $database;
	public function __construct(private CommonRepository $repository,?BackupDestination $destination = null,?PrivateStorage $files = null,?DatabaseDump $database = null)
	{
		$this->destination=$destination ?? new FilesystemBackupDestination(); $this->files=$files ?? new PrivateStorage(); $this->database=$database ?? new DatabaseDump();
	}
	public function request(string $type,?string $idempotency = null,?int $actor = null): string
	{
		$actor ??= (new Access($this->repository))->actor(); (new Access($this->repository))->super($actor);
		if (!in_array($type,['bd','archivos_full','incremental','completo'],true)) throw new \InvalidArgumentException('Tipo de respaldo inválido.');
		return (new OperationLock($this->repository))->run('backup-solicitud',function () use ($type,$idempotency,$actor) {
			if ($idempotency!==null) {
				$job=$this->repository->one("SELECT payload FROM trabajos WHERE tipo='respaldo' AND idempotencia=?",[hash('sha256',$idempotency)]);
				if ($job) return json_decode($job['payload'],true)['respaldo_id'];
			}
			if ($this->repository->one("SELECT id FROM respaldos WHERE estado IN ('pendiente','ejecutando') LIMIT 1") || $this->repository->one("SELECT id FROM restauraciones WHERE estado IN ('pendiente','ejecutando') LIMIT 1")) throw new HttpError('Existe un respaldo o restauración pendiente/en ejecución.',409);
			return $this->repository->transaction(function () use ($type,$idempotency,$actor) {
				$id=Values::uuid(); $this->repository->execute('INSERT INTO respaldos (id,tipo,usuario,fecha) VALUES (?,?,?,NOW())',[$id,$type,$actor]);
				(new QueueService($this->repository))->enqueue('respaldo',['respaldo_id'=>$id],null,null,$actor,$idempotency ?? 'respaldo:'.$id,10,1);
				(new AuditService($this->repository))->record(null,null,'solicitar','respaldos',$id,null,['tipo'=>$type],[],$actor); return $id;
			});
		});
	}
	public function policy(?array $data = null): array
	{
		(new Access($this->repository))->super();
		$before=$this->repository->one('SELECT bd_diaria,archivos_full_dia,archivos_incremental,retencion_dias FROM respaldos_politica WHERE id=1');
		if ($data!==null) $this->repository->transaction(function () use ($data,$before) {
			$after=['bd_diaria'=>Values::integer($data['database_daily'] ?? null,0,1),'archivos_full_dia'=>Values::integer($data['full_weekday'] ?? null,0,6),'archivos_incremental'=>Values::integer($data['incremental_daily'] ?? null,0,1),'retencion_dias'=>Values::integer($data['retention_days'] ?? null,1,3650)];
			$this->repository->execute('UPDATE respaldos_politica SET bd_diaria=?,archivos_full_dia=?,archivos_incremental=?,retencion_dias=?,editado_por=?,fecha=NOW() WHERE id=1',[...array_values($after),Auth::id()]);
			(new AuditService($this->repository))->record(null,null,'configurar','respaldos_politica',1,$before,$after);
		});
		return $this->repository->one('SELECT bd_diaria,archivos_full_dia,archivos_incremental,retencion_dias FROM respaldos_politica WHERE id=1');
	}
	public function perform(string $id): void
	{
		(new OperationLock($this->repository))->run('almacenamiento',function () use ($id) {
			$row=$this->repository->one('SELECT * FROM respaldos WHERE id=?',[$id]) ?? throw new ServiceFailure('Respaldo no disponible.',false);
			(new Access($this->repository))->super((int) $row['usuario']); if ($row['estado']==='completado') return;
			$this->repository->execute("UPDATE respaldos SET estado='ejecutando',inicio=NOW(6),error=NULL WHERE id=?",[$id]);
			$temporary=[]; $final=null;
			try {
				$archive=new BackupArchive($this->files); $hasFiles=$row['tipo']!=='bd'; $hasDatabase=in_array($row['tipo'],['bd','completo'],true);
				$snapshot=$hasFiles ? $archive->snapshot() : []; $previous=null;
				if ($row['tipo']==='incremental') {
					$previous=$this->repository->one("SELECT * FROM respaldos WHERE estado='completado' AND tipo IN ('archivos_full','incremental','completo') ORDER BY fin DESC,id DESC LIMIT 1");
					if (!$previous) throw new ServiceFailure('Se necesita un respaldo completo antes del incremental.',false);
					$this->verifiedChain($previous['id']);
				}
				$before=$previous ? json_decode($previous['manifiesto'],true)['files'] : []; $changed=[];
				foreach ($snapshot as $relative=>$metadata) if (!isset($before[$relative]) || !hash_equals($before[$relative]['hash'],$metadata['hash'])) $changed[]=$relative;
				$deleted=array_keys(array_diff_key($before,$snapshot));
				$base=$previous ? ($previous['base_id'] ?? $previous['id']) : null;
				$manifest=['version'=>1,'id'=>$id,'type'=>$row['tipo'],'base'=>$base,'previous'=>$previous['id'] ?? null,'created'=>date('c'),'files'=>$snapshot,'changed'=>$changed,'deleted'=>$deleted];
				$required=67108864; foreach ($changed as $relative) $required+=$snapshot[$relative]['size']*4;
				if ($hasDatabase) {
					$engine=$this->repository->one("SELECT COUNT(*) total,COALESCE(SUM(data_length+index_length),0) size FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' AND engine<>'InnoDB'");
					if ((int) $engine['total']>0) throw new ServiceFailure('El respaldo consistente requiere tablas InnoDB.',false);
					$estimate=$this->repository->one('SELECT COALESCE(SUM(data_length+index_length),0) size FROM information_schema.tables WHERE table_schema=DATABASE()'); $required+=(int) $estimate['size']*6;
				}
				$space=$this->destination->available(); if ($space!==null && $space<$required) throw new ServiceFailure('Espacio insuficiente para crear el respaldo.',false);
				$workSpace=@disk_free_space($this->files->root()); if ($workSpace!==false && $workSpace<$required) throw new ServiceFailure('Espacio insuficiente para preparar el respaldo.',false);
				$dump=null;
				if ($hasDatabase) { $dump=$this->files->temporary(); $temporary[]=$dump; $this->database->dump($dump); $manifest['database']=['hash'=>hash_file('sha256',$dump),'size'=>filesize($dump)]; }
				$zip=$this->files->temporary(); $temporary[]=$zip; $archive->create($zip,$manifest,$dump); $archive->verify($zip,$manifest);
				$encrypted=(string) env('BACKUP_ENCRYPTION_KEY','')!=='';
				$relative='respaldos/'.$id.($encrypted ? '.zip.enc' : '.zip'); $final=$this->destination->path($relative,true);
				if ($encrypted) {
					$crypto=new BackupCrypto((string) env('BACKUP_ENCRYPTION_KEY')); $crypto->encrypt($zip,$final);
					$check=$this->files->temporary(); $temporary[]=$check; unlink($check); $crypto->decrypt($final,$check); $archive->verify($check,$manifest);
				}
				else { if (!copy($zip,$final)) throw new ServiceFailure('No se pudo guardar el respaldo.'); chmod($final,0600); }
				$this->repository->transaction(function () use ($id,$row,$base,$previous,$manifest,$relative,$final,$encrypted) {
					$this->repository->execute("UPDATE respaldos SET estado='completado',base_id=?,anterior_id=?,manifiesto=?,ruta=?,sha256=?,tamanio=?,cifrado=?,fin=NOW(6),verificado_en=NOW() WHERE id=?",[$base,$previous['id'] ?? null,Values::json($manifest),$relative,hash_file('sha256',$final),filesize($final),(int) $encrypted,$id]);
					(new AuditService($this->repository))->record(null,null,'completar','respaldos',$id,null,['tipo'=>$row['tipo'],'base'=>$base,'cifrado'=>$encrypted],[],(int) $row['usuario']);
				});
			} catch (\Throwable $error) {
				if ($final && is_file($final)) unlink($final);
				$this->repository->execute("UPDATE respaldos SET estado='fallido',error=?,fin=NOW(6) WHERE id=?",[Sanitizer::error($error),$id]);
				foreach ($this->repository->rows("SELECT u.id FROM usuarios u JOIN usuarios_tipos t ON t.id=u.tipo_usuario WHERE t.codigo='superadmin' AND u.activo=1") as $super) (new NotificationService($this->repository))->internal((int) $super['id'],null,null,'respaldo_fallido','Falló un respaldo',Sanitizer::error($error),'respaldos',$id,'/services/');
				throw $error;
			} finally { foreach ($temporary as $path) if (is_file($path)) unlink($path); }
		});
	}
	public function unpacked(array $row,callable $work): mixed
	{
		if ($row['estado']!=='completado' || !$row['ruta'] || !$row['sha256']) throw new ServiceFailure('Respaldo incompleto.',false);
		$path=$this->destination->path($row['ruta']);
		if (!is_file($path) || filesize($path)!=(int) $row['tamanio'] || !hash_equals($row['sha256'],hash_file('sha256',$path))) throw new ServiceFailure('Hash del respaldo incorrecto o archivo faltante.',false);
		$temporary=null;
		try {
			if ((int) $row['cifrado']) { $temporary=$this->files->temporary(); unlink($temporary); (new BackupCrypto((string) env('BACKUP_ENCRYPTION_KEY','')))->decrypt($path,$temporary); $path=$temporary; }
			$manifest=json_decode($row['manifiesto'],true,512,JSON_THROW_ON_ERROR); (new BackupArchive($this->files))->verify($path,$manifest);
			return $work($path,$manifest);
		} finally { if ($temporary && is_file($temporary)) unlink($temporary); }
	}
	public function verifiedChain(string $id): array
	{
		$chain=[]; $visited=[]; $current=$id;
		while ($current!==null) {
			if (isset($visited[$current]) || count($chain)>3660) throw new ServiceFailure('Cadena de respaldos cíclica o excesiva.',false); $visited[$current]=true;
			$row=$this->repository->one('SELECT * FROM respaldos WHERE id=?',[$current]) ?? throw new ServiceFailure('Falta un respaldo requerido por la cadena.',false);
			$this->unpacked($row,static fn ()=>null); $chain[]=$row; $current=$row['anterior_id'];
		}
		$chain=array_reverse($chain); $last=end($chain);
		if ($last['tipo']==='incremental' && (!in_array($chain[0]['tipo'],['archivos_full','completo'],true) || $last['base_id']!==$chain[0]['id'])) throw new ServiceFailure('Falta la base completa del incremental.',false);
		$state=[];
		foreach ($chain as $row) {
			$manifest=json_decode($row['manifiesto'],true);
			if ($manifest['id']!==$row['id'] || $manifest['base']!==$row['base_id'] || $manifest['previous']!==$row['anterior_id']) throw new ServiceFailure('Relaciones del manifiesto incompatibles.',false);
			foreach ($manifest['deleted'] as $relative) unset($state[$relative]);
			foreach ($manifest['changed'] as $relative) $state[$relative]=$manifest['files'][$relative];
			ksort($state); $expected=$manifest['files']; ksort($expected);
			// Las fechas pueden cambiar sin cambiar contenido; se comparan hashes y tamaños de la reconstrucción.
			if (array_keys($state)!==array_keys($expected)) throw new ServiceFailure('La cadena no reconstruye el manifiesto.',false);
			foreach ($state as $relative=>$metadata) if ($metadata['hash']!==$expected[$relative]['hash'] || $metadata['size']!==$expected[$relative]['size']) throw new ServiceFailure('La cadena contiene un cambio faltante.',false);
		}
		return $chain;
	}
	public function verify(string $id,?int $actor = null): void
	{
		(new Access($this->repository))->super($actor); $this->verifiedChain($id);
		$this->repository->execute('UPDATE respaldos SET verificado_en=NOW() WHERE id=?',[$id]);
		(new AuditService($this->repository))->record(null,null,'verificar','respaldos',$id,null,['integridad'=>'correcta'],[],$actor);
	}
	public function protect(string $id,bool $protected): void
	{
		(new Access($this->repository))->super();
		if (!$this->repository->one('SELECT id FROM respaldos WHERE id=?',[$id])) throw new HttpError('Respaldo no disponible.',404);
		$this->repository->execute('UPDATE respaldos SET protegido=? WHERE id=?',[(int) $protected,$id]);
		(new AuditService($this->repository))->record(null,null,'proteger','respaldos',$id,null,['protegido'=>$protected]);
	}
	public function retention(?int $actor = null): int
	{
		$actor ??= (new Access($this->repository))->actor(); (new Access($this->repository))->super($actor);
		return (new OperationLock($this->repository))->run('almacenamiento',function () use ($actor) {
			$days=(int) $this->repository->one('SELECT retencion_dias FROM respaldos_politica WHERE id=1')['retencion_dias'];
			$rows=$this->repository->rows("SELECT id,base_id,anterior_id,ruta,estado,protegido,fecha FROM respaldos WHERE estado<>'eliminado' ORDER BY fecha DESC,id DESC");
			$map=array_column($rows,null,'id'); $keep=[]; $cutoff=time()-$days*86400;
			foreach ($rows as $row) if ((int) $row['protegido'] || strtotime($row['fecha'])>=$cutoff || in_array($row['estado'],['pendiente','ejecutando'],true)) $keep[$row['id']]=true;
			foreach ($this->repository->rows("SELECT respaldo,previo_id FROM restauraciones WHERE estado IN ('pendiente','ejecutando')") as $restore) { $keep[$restore['respaldo']]=true; if ($restore['previo_id']) $keep[$restore['previo_id']]=true; }
			foreach (array_keys($keep) as $id) { $visited=[]; while ($id!==null && isset($map[$id]) && !isset($visited[$id])) { $visited[$id]=true; $keep[$id]=true; if ($map[$id]['base_id']!==null) $keep[$map[$id]['base_id']]=true; $id=$map[$id]['anterior_id']; } }
			$count=0;
			foreach ($rows as $row) if (!isset($keep[$row['id']])) {
				if ($row['ruta']) $this->destination->remove($row['ruta']);
				$this->repository->execute("UPDATE respaldos SET estado='eliminado' WHERE id=?",[$row['id']]);
				(new AuditService($this->repository))->record(null,null,'retencion','respaldos',$row['id'],['estado'=>$row['estado']],['estado'=>'eliminado'],[],$actor); $count++;
			}
			return $count;
		});
	}
	public function listing(array $filters): array
	{
		(new Access($this->repository))->super(); $where="estado<>'eliminado'"; $params=[];
		foreach (['state'=>'estado','type'=>'tipo'] as $filter=>$column) if (!empty($filters[$filter])) { $where.=" AND $column=?"; $params[]=Values::text($filters[$filter],16); }
		$limit=Pagination::limit($filters['limit'] ?? 25); $offset=Pagination::offset($filters['offset'] ?? 0);
		$rows=$this->repository->rows("SELECT id,tipo,estado,base_id,anterior_id,tamanio,cifrado,protegido,fecha,inicio,fin,TIMESTAMPDIFF(SECOND,inicio,fin) duracion,verificado_en,error FROM respaldos WHERE $where ORDER BY fecha DESC,id DESC LIMIT ".($limit+1).' OFFSET '.$offset,$params);
		$summary=$this->repository->one("SELECT COUNT(*) cantidad,COALESCE(SUM(tamanio),0) utilizado,MAX(CASE WHEN estado='completado' THEN fin ELSE NULL END) ultimo FROM respaldos WHERE estado<>'eliminado'");
		return ['rows'=>array_slice($rows,0,$limit),'pagination'=>Pagination::response($rows,$limit,$offset),'summary'=>[...$summary,'available'=>$this->destination->available(),'destination'=>$this->destination->label()]];
	}
}
