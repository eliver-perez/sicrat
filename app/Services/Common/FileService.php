<?php

declare(strict_types=1);

namespace App\Services\Common;

use App\Core\HttpError;
use App\Repositories\CommonRepository;

class FileService
{
	private PrivateStorage $storage;
	private ResourceRegistry $owners;
	public function __construct(private CommonRepository $repository,?PrivateStorage $storage = null,?ResourceRegistry $owners = null)
	{
		$this->storage=$storage ?? new PrivateStorage(); $this->owners=$owners ?? new ResourceRegistry($repository);
	}
	public function upload(array $file,int $organization,?int $process,string $entity,int $record): array
	{
		$actor=(new Access($this->repository))->actor();
		$effective=(new Access($this->repository))->context($organization,$process,'archivos.subir');
		$this->owners->authorize($entity,$record,$organization,$process,'write',$actor);
		if (!$effective['super']) (new \App\Repositories\ScopeRepository($this->repository->connection()))->assertPerson($entity==='personas' ? $record : 0,'archivos.subir',$process);
		$source=$file['tmp_name'] ?? '';
		if (($file['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_string($source) || !is_uploaded_file($source)) throw new \InvalidArgumentException('Carga inválida.');
		$clientPath=$file['full_path'] ?? $file['name'] ?? '';
		if (!is_string($clientPath) || $clientPath!==basename(str_replace('\\','/',$clientPath))) throw new \InvalidArgumentException('La carga no admite rutas del cliente.');
		return $this->ingestUpload($source,Values::text($file['name'] ?? null,255),$organization,$process,$entity,$record,$actor);
	}
	// El origen interno lo usa el motor de exportación, nunca una ruta recibida del navegador.
	public function ingestUpload(string $source,string $name,int $organization,?int $process,string $entity,int $record,int $actor): array
	{
		if ($name!==basename(str_replace('\\','/',$name)) || str_contains($name,'..') || preg_match('/[\x00-\x1f\x7f]/',$name) || preg_match('/\.(php\d*|phtml|phar|exe|sh|js|html|svg|mp4|mov|avi|webm)(\.|$)/i',$name)) throw new \InvalidArgumentException('Nombre de archivo no permitido.');
		$size=is_file($source) ? filesize($source) : false;
		if (!$size || $size>Values::integer(env('PRIVATE_FILE_MAX_BYTES',10485760),1024,52428800)) throw new \InvalidArgumentException('Archivo vacío o tamaño excedido.');
		$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($source);
		$extension=strtolower(pathinfo($name,PATHINFO_EXTENSION));
		$allowed=['image/jpeg'=>['jpg','jpeg'],'image/png'=>['png'],'image/webp'=>['webp'],'application/pdf'=>['pdf']];
		if (!isset($allowed[$mime]) || !in_array($extension,$allowed[$mime],true)) throw new \InvalidArgumentException('El tipo real y la extensión del archivo no están permitidos.');
		if ($mime==='application/pdf') {
			$handle=fopen($source,'rb'); $header=fread($handle,8); fseek($handle,max(0,$size-1024)); $tail=stream_get_contents($handle); fclose($handle);
			if (!str_starts_with($header,'%PDF-') || !str_contains($tail,'%%EOF')) throw new \InvalidArgumentException('Documento PDF inválido.');
		}
		return (new OperationLock($this->repository))->run('almacenamiento',function () use ($source,$name,$organization,$process,$entity,$record,$actor,$mime) {
			$uuid=Values::uuid(); $normal='archivos/'.$uuid.'/normal.'.($mime==='application/pdf' ? 'pdf' : 'webp');
			$paths=[$normal]; $variants=[];
			try {
				if ($mime==='application/pdf') { if (!copy($source,$this->storage->path($normal,true))) throw new ServiceFailure('No se pudo guardar el documento.'); chmod($this->storage->path($normal),0600); $variants['normal']=['path'=>$normal,'mime'=>$mime,'width'=>null,'height'=>null]; }
				else {
					$thumb='archivos/'.$uuid.'/thumb.webp'; $paths[]=$thumb;
					$processor=new ImageProcessor((int) env('IMAGE_MAX_DIMENSION',1920),(int) env('IMAGE_WEBP_QUALITY',80),(int) env('IMAGE_THUMB_DIMENSION',320));
					$dimensions=$processor->process($source,$this->storage->path($normal,true),$this->storage->path($thumb,true));
					foreach (['normal'=>$normal,'thumb'=>$thumb] as $variant=>$path) $variants[$variant]=['path'=>$path,'mime'=>'image/webp',...$dimensions[$variant]];
				}
				$id=$this->store($uuid,$organization,$process,$entity,$record,$actor,$name,$variants,$mime==='application/pdf' ? 'DOCUMENTO' : 'EVIDENCIA');
				return ['uuid'=>$uuid,'mime'=>$variants['normal']['mime'],'size'=>filesize($this->storage->path($normal)),'width'=>$variants['normal']['width'],'height'=>$variants['normal']['height']];
			} catch (\Throwable $error) { foreach ($paths as $path) $this->storage->remove($path); throw $error; }
		});
	}
	public function generated(string $source,int $organization,?int $process,int $export,int $actor,string $name,string $mime): int
	{
		if (!in_array($mime,['application/pdf','text/csv','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/zip'],true)) throw new \InvalidArgumentException('Formato de exportación inválido.');
		return (new OperationLock($this->repository))->run('almacenamiento',function () use ($source,$organization,$process,$export,$actor,$name,$mime) {
			$uuid=Values::uuid(); $path='archivos/'.$uuid.'/normal.'.pathinfo($name,PATHINFO_EXTENSION);
			if (!copy($source,$this->storage->path($path,true))) throw new ServiceFailure('No se pudo guardar la exportación.'); chmod($this->storage->path($path),0600);
			try { return $this->store($uuid,$organization,$process,'exportaciones',$export,$actor,$name,['normal'=>['path'=>$path,'mime'=>$mime,'width'=>null,'height'=>null]],'DOCUMENTO'); }
			catch (\Throwable $error) { $this->storage->remove($path); throw $error; }
		});
	}
	private function store(string $uuid,int $organization,?int $process,string $entity,int $record,int $actor,string $name,array $variants,string $type): int
	{
		return $this->repository->transaction(function () use ($uuid,$organization,$process,$entity,$record,$actor,$name,$variants,$type) {
			$normal=$variants['normal']; $path=$this->storage->path($normal['path']);
			$typeId=$this->repository->one('SELECT id FROM archivos_tipos WHERE codigo=?',[$type])['id'];
			$id=$this->repository->insert("INSERT INTO archivos (uuid,organizacion,proceso,entidad,entidad_id,tipo,nombre_original,ruta,mime_type,tamanio,sha256,estado,subido_por,ancho,alto,f_registro) VALUES (?,?,?,?,?,?,?,?,?,?,?,'activo',?,?,?,NOW())",[Values::binary($uuid),$organization,$process,$entity,$record,$typeId,$name,$normal['path'],$normal['mime'],filesize($path),hash_file('sha256',$path),$actor,$normal['width'],$normal['height']]);
			foreach ($variants as $variant=>$info) { $path=$this->storage->path($info['path']); $this->repository->execute('INSERT INTO archivos_variantes (archivo,variante,ruta,mime,tamanio,sha256,ancho,alto) VALUES (?,?,?,?,?,?,?,?)',[$id,$variant,$info['path'],$info['mime'],filesize($path),hash_file('sha256',$path),$info['width'],$info['height']]); }
			(new AuditService($this->repository))->record($organization,$process,'subir','archivos',$id,null,['tipo'=>$type,'entidad'=>$entity,'registro'=>$record],[], $actor);
			return $id;
		});
	}
	public function authorized(string $uuid,string $variant = 'normal',bool $audit = true): array
	{
		if (!in_array($variant,['normal','thumb'],true)) throw new \InvalidArgumentException('Variante inválida.');
		$row=$this->repository->one("SELECT a.*,v.ruta variante_ruta,v.mime,v.tamanio variante_tamanio,v.sha256 variante_hash FROM archivos a JOIN archivos_variantes v ON v.archivo=a.id AND v.variante=? WHERE a.uuid=? AND a.estado='activo'",[$variant,Values::binary($uuid)]) ?? throw new HttpError('Archivo no disponible.',404);
		$actor=(new Access($this->repository))->actor(); $process=$row['proceso']===null ? null : (int) $row['proceso'];
		$permission=$row['entidad']==='exportaciones' ? 'exportaciones.descargar' : 'archivos.ver';
		(new Access($this->repository))->context((int) $row['organizacion'],$process,$permission,$actor);
		$this->owners->authorize($row['entidad'],(int) $row['entidad_id'],(int) $row['organizacion'],$process,'read',$actor);
		if ($row['entidad']==='personas') (new \App\Repositories\ScopeRepository($this->repository->connection()))->assertPerson((int) $row['entidad_id'],$permission,$process,$actor);
		$path=$this->storage->path($row['variante_ruta']);
		if (!is_file($path) || filesize($path)!=(int) $row['variante_tamanio'] || !hash_equals($row['variante_hash'],hash_file('sha256',$path))) throw new HttpError('Archivo no disponible o integridad inválida.',404);
		if ($audit) (new AuditService($this->repository))->record((int) $row['organizacion'],$process,'descargar','archivos',(int) $row['id'],null,null,['variante'=>$variant]);
		$name=$row['mime']==='image/webp' ? pathinfo($row['nombre_original'],PATHINFO_FILENAME).'.webp' : $row['nombre_original'];
		return ['path'=>$path,'mime'=>$row['mime'],'size'=>(int) $row['variante_tamanio'],'name'=>$name,'original_name'=>$row['nombre_original'],'attachment'=>!str_starts_with($row['mime'],'image/')];
	}
	public function packageSources(int $organization,?int $process,array $filters = []): iterable
	{
		$last=0; $filter=''; $parameters=[];
		if (($filters['search'] ?? '')!=='') { $filter.=' AND (p.codigo_persona LIKE ? OR CONCAT_WS(\' \',p.nombre,p.paterno,p.materno) LIKE ?)'; $search='%'.$filters['search'].'%'; $parameters[]=$search; $parameters[]=$search; }
		if (($filters['section'] ?? null)!==null) { $filter.=' AND p.seccion=?'; $parameters[]=$filters['section']; }
		do {
			$rows=$this->repository->rows("SELECT a.id,a.uuid FROM archivos a LEFT JOIN personas p ON a.entidad='personas' AND p.id=a.entidad_id WHERE a.organizacion=? AND (a.proceso IS NULL OR a.proceso <=> ?) AND a.entidad<>'exportaciones' AND a.estado='activo' AND a.id>? $filter ORDER BY a.id LIMIT 100",[$organization,$process,$last,...$parameters]);
			foreach ($rows as $row) {
				$last=(int) $row['id']; $uuid=Values::uuidText($row['uuid']);
				try { $file=$this->authorized($uuid,'normal',false); yield ['uuid'=>$uuid,...$file]; }
				catch (HttpError $error) { if (!in_array($error->statusCode,[403,404],true)) throw $error; }
			}
		} while (count($rows)===100);
	}
	public function delete(string $uuid): void
	{
		$row=$this->repository->one("SELECT * FROM archivos WHERE uuid=? AND estado='activo'",[Values::binary($uuid)]) ?? throw new HttpError('Archivo no disponible.',404);
		$actor=(new Access($this->repository))->actor(); $process=$row['proceso']===null ? null : (int) $row['proceso'];
		(new Access($this->repository))->context((int) $row['organizacion'],$process,'archivos.eliminar');
		$this->owners->authorize($row['entidad'],(int) $row['entidad_id'],(int) $row['organizacion'],$process,'write',$actor);
		if ($row['entidad']==='personas') (new \App\Repositories\ScopeRepository($this->repository->connection()))->assertPerson((int) $row['entidad_id'],'archivos.eliminar',$process);
		$this->repository->transaction(function () use ($row,$process) {
			$this->repository->execute("UPDATE archivos SET estado='eliminado' WHERE id=?",[$row['id']]);
			(new AuditService($this->repository))->record((int) $row['organizacion'],$process,'baja','archivos',(int) $row['id'],['estado'=>'activo'],['estado'=>'eliminado']);
		});
	}
}
