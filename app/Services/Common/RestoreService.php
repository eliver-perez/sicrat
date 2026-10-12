<?php

declare(strict_types=1);

namespace App\Services\Common;

use App\Core\Auth;
use App\Core\HttpError;
use App\Repositories\CommonRepository;

class RestoreService
{
	private BackupService $backups;
	private PrivateStorage $files;
	private DatabaseDump $database;
	public function __construct(private CommonRepository $repository,?BackupService $backups = null,?PrivateStorage $files = null,?DatabaseDump $database = null)
	{
		$this->backups=$backups ?? new BackupService($repository); $this->files=$files ?? new PrivateStorage(); $this->database=$database ?? new DatabaseDump();
	}
	private function destinations(array $row): array
	{
		$manifest=json_decode($row['manifiesto'],true);
		$database=isset($manifest['database']) ? (string) env('RESTORE_DATABASE','') : null;
		$files=$row['tipo']!=='bd' ? (string) env('RESTORE_FILES_PATH','') : null;
		if (($database!==null && !preg_match('/^[a-zA-Z0-9_]+$/D',$database)) || ($files!==null && ($files==='' || !str_starts_with($files,'/')))) throw new ServiceFailure('Configura explícitamente los destinos privados de restauración.',false);
		if ($database!==null && $database===$this->database->database()) throw new ServiceFailure('La restauración de la base activa debe realizarse mediante el procedimiento CLI offline.',false);
		if ($files!==null) {
			$target=new PrivateStorage($files);
			if ($target->root()===$this->files->root() || str_starts_with($target->root(),$this->files->root().'/') || str_starts_with($this->files->root(),$target->root().'/')) throw new ServiceFailure('El destino de ensayo debe ser independiente del almacenamiento activo.',false);
		}
		return ['database'=>$database,'files'=>$files];
	}
	public function preview(string $id): array
	{
		(new Access($this->repository))->super(); $chain=[]; $seen=[]; $current=$id;
		while ($current!==null) {
			if (isset($seen[$current]) || count($chain)>3660) throw new ServiceFailure('Cadena inválida.',false); $seen[$current]=true;
			$part=$this->repository->one("SELECT * FROM respaldos WHERE id=? AND estado='completado' AND verificado_en IS NOT NULL",[$current]) ?? throw new ServiceFailure('Se requiere una cadena completa y verificada.',false);
			$chain[]=$part; $current=$part['anterior_id'];
		}
		$chain=array_reverse($chain); $row=end($chain); $destinations=$this->destinations($row);
		$manifest=json_decode($row['manifiesto'],true);
		return ['backup'=>$id,'type'=>$row['tipo'],'files'=>count($manifest['files']),'database'=>isset($manifest['database']),'chain'=>array_column($chain,'id'),'target'=>'Destinos de restauración configurados, independientes de la instalación activa','confirmation'=>'RESTAURAR '.$id,'review'=>hash('sha256',Values::json([$id,$row['sha256'],$destinations,Auth::id()]))];
	}
	public function request(string $id,string $confirmation,string $review): string
	{
		$preview=$this->preview($id);
		if (!hash_equals($preview['confirmation'],$confirmation) || !hash_equals($preview['review'],$review)) throw new HttpError('Revisa y confirma explícitamente esta restauración.',422);
		return (new OperationLock($this->repository))->run('backup-solicitud',function () use ($id) {
			if ($this->repository->one("SELECT id FROM respaldos WHERE estado IN ('pendiente','ejecutando') LIMIT 1") || $this->repository->one("SELECT id FROM restauraciones WHERE estado IN ('pendiente','ejecutando') LIMIT 1")) throw new HttpError('Existe una operación incompatible.',409);
			$row=$this->repository->one('SELECT * FROM respaldos WHERE id=?',[$id]); $destinations=$this->destinations($row);
			return $this->repository->transaction(function () use ($id,$destinations) {
				$restore=Values::uuid();
				$this->repository->execute('INSERT INTO restauraciones (id,respaldo,usuario,destino,fecha) VALUES (?,?,?,?,NOW())',[$restore,$id,Auth::id(),Values::json($destinations)]);
				(new QueueService($this->repository))->enqueue('restauracion',['restauracion_id'=>$restore],null,null,Auth::id(),'restauracion:'.$restore,50,1);
				(new AuditService($this->repository))->record(null,null,'confirmar','restauraciones',$restore,null,['respaldo'=>$id,'instalacion_activa'=>false]); return $restore;
			});
		});
	}
	public function perform(string $id): void
	{
		(new OperationLock($this->repository))->run('almacenamiento',function () use ($id) {
			$row=$this->repository->one('SELECT * FROM restauraciones WHERE id=?',[$id]) ?? throw new ServiceFailure('Restauración no disponible.',false);
			(new Access($this->repository))->super((int) $row['usuario']); if ($row['estado']==='completada') return;
			$chain=$this->backups->verifiedChain($row['respaldo']); $last=end($chain); $targets=json_decode($row['destino'],true);
			if ($targets!==$this->destinations($last)) throw new ServiceFailure('La configuración de destino cambió; vuelve a revisar la restauración.',false);
			$this->repository->execute("UPDATE restauraciones SET estado='ejecutando' WHERE id=?",[$id]);
			$dump=null; $stage=null; $target=null;
			try {
				if ($targets['files']!==null) { $stage=$targets['files'].'-stage-'.$id; $target=new PrivateStorage($stage); }
				foreach ($chain as $backup) $this->backups->unpacked($backup,function ($path,$manifest) use ($targets,&$dump,$target) {
					if (isset($manifest['database']) && $targets['database']!==null) { $dump=$this->files->temporary(); }
					(new BackupArchive($this->files))->extract($path,$manifest,$target ?? $this->files,$dump);
				});
				if ($targets['files']!==null) {
					$actual=(new BackupArchive($target))->snapshot(); $expected=json_decode($last['manifiesto'],true)['files'];
					if (array_keys($actual)!==array_keys($expected)) throw new ServiceFailure('La restauración no reconstruyó todos los archivos.',false);
					foreach ($actual as $relative=>$metadata) if ($metadata['hash']!==$expected[$relative]['hash'] || $metadata['size']!==$expected[$relative]['size']) throw new ServiceFailure('Hash reconstruido inválido.',false);
				}
				if ($dump!==null) {
					// Copia previa del destino, conservada privada antes de cualquier sobreescritura.
					$config=$this->database->configuration(); $config['database']=$targets['database']; $prior=$this->files->temporary();
					(new DatabaseDump($config))->dump($prior);
					$this->database->restore($dump,$targets['database']);
					$this->journal($id,['prior_database'=>basename($prior),'state'=>'database_restored']);
				}
				if ($targets['files']!==null) {
					$prior=$targets['files'].'-previous-'.$id;
					if (is_dir($targets['files']) && !rename($targets['files'],$prior)) throw new ServiceFailure('No se pudo conservar el directorio anterior.',false);
					if (!rename($stage,$targets['files'])) { if (is_dir($prior)) rename($prior,$targets['files']); throw new ServiceFailure('No se pudo activar el directorio restaurado.',false); }
					$stage=null;
				}
				$this->repository->execute("UPDATE restauraciones SET estado='completada',fin=NOW(),error=NULL WHERE id=?",[$id]);
				(new AuditService($this->repository))->record(null,null,'completar','restauraciones',$id,null,['respaldo'=>$row['respaldo']],[],(int) $row['usuario']);
			} catch (\Throwable $error) { $this->repository->execute("UPDATE restauraciones SET estado='fallida',fin=NOW(),error=? WHERE id=?",[Sanitizer::error($error),$id]); throw $error; }
			finally { if ($dump && is_file($dump)) unlink($dump); if ($stage && is_dir($stage)) $this->removeStage($stage); }
		});
	}
	private function journal(string $id,array $data): void
	{
		$path=$this->files->path('restauraciones/'.$id.'.json',true); file_put_contents($path,Values::json($data)); chmod($path,0600);
	}
	public function offlineActive(string $id,string $priorId,string $confirmation,int $actor): void
	{
		if (PHP_SAPI!=='cli' || !Maintenance::active()) throw new ServiceFailure('La restauración activa requiere CLI y mantenimiento explícito.',false);
		(new Access($this->repository))->super($actor);
		if (!hash_equals('RESTAURAR INSTALACION '.$id,$confirmation)) throw new ServiceFailure('Confirmación offline inválida.',false);
		(new OperationLock($this->repository))->run('almacenamiento',function () use ($id,$priorId,$actor) {
			$chain=$this->backups->verifiedChain($id); $last=end($chain); $manifest=json_decode($last['manifiesto'],true);
			$prior=$this->repository->one("SELECT * FROM respaldos WHERE id=? AND estado='completado' AND protegido=1 AND verificado_en IS NOT NULL AND fin>=DATE_SUB(NOW(),INTERVAL 30 MINUTE)",[$priorId]) ?? throw new ServiceFailure('Se requiere un respaldo previo protegido, verificado y reciente.',false);
			$this->backups->verifiedChain($priorId); $priorManifest=json_decode($prior['manifiesto'],true);
			$restoreDatabase=isset($manifest['database']); $restoreFiles=$last['tipo']!=='bd';
			if (($restoreDatabase && !isset($priorManifest['database'])) || ($restoreFiles && !in_array($prior['tipo'],['archivos_full','completo'],true))) throw new ServiceFailure('El respaldo previo no cubre todos los componentes a restaurar.',false);
			$restore=Values::uuid(); $stage=null; $target=null; $dump=null;
			(new AuditService($this->repository))->record(null,null,'confirmar_offline','restauraciones',$restore,null,['respaldo'=>$id,'previo'=>$priorId,'bd'=>$restoreDatabase,'archivos'=>$restoreFiles],[],$actor);
			$this->journal($restore,['backup'=>$id,'prior'=>$priorId,'state'=>'confirmada','date'=>date('c'),'actor'=>$actor]);
			try {
				if ($restoreFiles) { $stage=$this->files->root().'/restauracion-stage-'.$restore; $target=new PrivateStorage($stage); }
				foreach ($chain as $backup) $this->backups->unpacked($backup,function ($path,$part) use ($target,$restoreDatabase,&$dump) {
					if ($restoreDatabase && isset($part['database'])) $dump=$this->files->temporary();
					(new BackupArchive($this->files))->extract($path,$part,$target ?? $this->files,$dump);
				});
				if ($restoreDatabase) $this->database->restore($dump,$this->database->database());
				if ($restoreFiles) {
					$active=$this->files->path('archivos'); $previous=$this->files->path('restauraciones/'.$restore.'/archivos-previos',true);
					if (is_dir($active) && !rename($active,$previous)) throw new ServiceFailure('No se pudo conservar el directorio activo.',false);
					if (!is_dir($target->path('archivos'))) mkdir($target->path('archivos'),0700);
					if (!rename($target->path('archivos'),$active)) { if (is_dir($previous)) rename($previous,$active); throw new ServiceFailure('No se pudo activar el almacenamiento restaurado.',false); }
				}
				$this->journal($restore,['backup'=>$id,'prior'=>$priorId,'state'=>'completada','date'=>date('c'),'actor'=>$actor]);
				(new AuditService($this->repository))->record(null,null,'restaurar_offline','restauraciones',$restore,null,['respaldo'=>$id,'previo'=>$priorId],[],$actor);
			} catch (\Throwable $error) { $this->journal($restore,['backup'=>$id,'prior'=>$priorId,'state'=>'fallida','error'=>Sanitizer::error($error),'date'=>date('c'),'actor'=>$actor]); throw $error; }
			finally { if ($dump && is_file($dump)) unlink($dump); if ($stage && is_dir($stage)) $this->removeStage($stage); }
		});
	}
	private function removeStage(string $stage): void
	{
		$iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stage,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($iterator as $file) { if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); } rmdir($stage);
	}
}
