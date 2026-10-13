<?php

declare(strict_types=1);

namespace App\Services\Common;

use App\Core\Auth;
use App\Core\HttpError;
use App\Core\Pagination;
use App\Repositories\CommonRepository;

class ExportService
{
	private ExportRegistry $registry;
	private PrivateStorage $storage;
	public function __construct(private CommonRepository $repository,?ExportRegistry $registry = null,?PrivateStorage $storage = null)
	{
		$this->registry=$registry ?? new ExportRegistry($repository); $this->storage=$storage ?? new PrivateStorage();
	}
	private function context(array $row): array
	{
		return ['organization'=>(int) $row['organizacion'],'process'=>$row['proceso']===null ? null : (int) $row['proceso'],'actor'=>(int) $row['solicitante'],'filters'=>json_decode($row['filtros'],true)];
	}
	public function request(int $organization,?int $process,string $type,string $format,array $filters): string
	{
		if (!in_array($format,['pdf','xlsx','csv'],true) && !($type==='paquete' && $format==='zip')) throw new \InvalidArgumentException('Formato de exportación no permitido.');
		$actor=(new Access($this->repository))->actor();
		$clean=['search'=>isset($filters['search']) ? Values::text($filters['search'],150,true) : '', 'section'=>empty($filters['section']) ? null : Values::integer($filters['section'])];
		$clean=[...$clean,...\App\Services\Operations\Exports::filters($type,$filters),...\App\Services\Resources\Exports::filters($type,$filters),...\App\Services\Jornada\Exports::filters($type,$filters)];
		$context=['organization'=>$organization,'process'=>$process,'actor'=>$actor,'filters'=>$clean];
		$this->registry->authorize($context,'exportaciones.generar');
		$types=$type==='paquete' ? $this->registry->packageTypes() : [$type];
		// Abrir el generador provoca autorización aunque el conjunto esté vacío.
		foreach ($types as $exporter) { foreach ($this->registry->rows($exporter,$context) as $row) break; }
		return $this->repository->transaction(function () use ($organization,$process,$type,$format,$actor,$clean) {
			$uuid=Values::uuid();
			$id=$this->repository->insert('INSERT INTO exportaciones (uuid,solicitante,organizacion,proceso,tipo,formato,filtros,fecha,expira_en) VALUES (?,?,?,?,?,?,?,NOW(),DATE_ADD(NOW(),INTERVAL 7 DAY))',[Values::binary($uuid),$actor,$organization,$process,$type,$format,Values::json($clean)]);
			(new QueueService($this->repository))->enqueue('exportacion',['exportacion_id'=>$id],$organization,$process,$actor,'exportacion:'.$id);
			(new AuditService($this->repository))->record($organization,$process,'solicitar','exportaciones',$id,null,['tipo'=>$type,'formato'=>$format,'filtros'=>$clean]);
			return $uuid;
		});
	}
	private function signature(string $type,array $context): string
	{
		$hash=hash_init('sha256');
		foreach ($this->registry->rows($type,$context) as $row) hash_update($hash,(string) $row['source_id']."\n");
		return hash_final($hash);
	}
	private function write(string $type,string $format,string $path,array $context,string $generator): string
	{
		$hash=hash_init('sha256');
		$rows=(function () use ($type,$context,$hash) {
			foreach ($this->registry->rows($type,$context) as $row) { hash_update($hash,(string) $row['source_id']."\n"); unset($row['source_id']); yield array_values($row); }
		})();
		(new ExportWriter())->write($format,$path,$this->registry->descriptor($type),$rows,[...$context,'generator'=>$generator,'filters'=>array_filter($context['filters'],fn ($value,$key)=>!str_starts_with($key,'_'),ARRAY_FILTER_USE_BOTH)]);
		$signature=hash_final($hash);
		if (!hash_equals($signature,$this->signature($type,$context))) throw new ServiceFailure('El alcance cambió durante la exportación.',false);
		return $signature;
	}
	public function generate(int $id): void
	{
		$row=$this->repository->one('SELECT * FROM exportaciones WHERE id=?',[$id]) ?? throw new ServiceFailure('Exportación no disponible.',false);
		if ($row['estado']==='completada' && $row['archivo']!==null) return;
		$context=$this->context($row);
		(new ActorContext($this->repository))->run($context['actor'],$context['process'],function () use ($row,$context) {
			$this->registry->authorize($context,'exportaciones.generar');
			$generator=$this->repository->one('SELECT nombre FROM usuarios WHERE id=?',[$context['actor']])['nombre'];
			$path=$this->storage->temporary(); $parts=[]; $signatures=[];
			$this->repository->execute("UPDATE exportaciones SET estado='ejecutando',error=NULL WHERE id=?",[$row['id']]);
			try {
				if ($row['tipo']==='paquete') {
					$zip=new \ZipArchive(); if ($zip->open($path,\ZipArchive::OVERWRITE)!==true) throw new ServiceFailure('No se pudo crear el paquete.');
					try {
						foreach ($this->registry->packageTypes() as $type) {
							$part=$this->storage->temporary(); $parts[]=$part;
							$signatures[$type]=$this->write($type,'xlsx',$part,$context,$generator);
							$zip->addFile($part,'Datos del cliente/'.$this->registry->descriptor($type)['title'].'.xlsx');
						}
						$zip->addEmptyDir('Datos del cliente/Reportes'); $zip->addEmptyDir('Datos del cliente/Archivos');
						$index=$this->storage->temporary(); $parts[]=$index; $handle=fopen($index,'wb');
						$privateHash=hash_init('sha256');
						try {
							fputcsv($handle,['Archivo','Nombre original'],',','"','');
							foreach ((new FileService($this->repository,$this->storage))->packageSources($context['organization'],$context['process'],$context['filters']) as $file) {
								$name=$file['uuid'].'.'.pathinfo($file['path'],PATHINFO_EXTENSION);
								$zip->addFile($file['path'],'Datos del cliente/Archivos/'.$name);
								fputcsv($handle,[$name,ExportWriter::safeCell($file['original_name'])],',','"',''); hash_update($privateHash,$file['uuid']."\n");
							}
						} finally { fclose($handle); }
						$zip->addFile($index,'Datos del cliente/Archivos/Indice.csv'); $signatures['_private_files']=hash_final($privateHash);
					} finally { $zip->close(); }
				} else $signatures[$row['tipo']]=$this->write($row['tipo'],$row['formato'],$path,$context,$generator);
				$this->registry->authorize($context,'exportaciones.generar');
				foreach ($signatures as $type=>$signature) if (!hash_equals($signature,$type==='_private_files' ? $this->privateSignature($context) : $this->signature($type,$context))) throw new ServiceFailure('El alcance cambió durante la exportación.',false);
				$mime=match ($row['formato']) { 'pdf'=>'application/pdf','csv'=>'text/csv','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','zip'=>'application/zip' };
				$this->repository->transaction(function () use ($row,$context,$path,$mime,$signatures) {
					$file=(new FileService($this->repository,$this->storage))->generated($path,$context['organization'],$context['process'],(int) $row['id'],$context['actor'],$row['tipo'].'.'.$row['formato'],$mime);
					$this->repository->execute("UPDATE exportaciones SET estado='completada',archivo=?,filtros=? WHERE id=?",[$file,Values::json([...$context['filters'],'_authorized_ids'=>$signatures]),$row['id']]);
					(new AuditService($this->repository))->record($context['organization'],$context['process'],'completar','exportaciones',(int) $row['id'],null,['archivo'=>$file],[],$context['actor']);
				});
			} catch (\Throwable $error) { $this->repository->execute("UPDATE exportaciones SET estado='fallida',error=? WHERE id=?",[Sanitizer::error($error),$row['id']]); throw $error; }
			finally { foreach ([$path,...$parts] as $temporary) if (is_file($temporary)) unlink($temporary); }
		});
	}
	public function authorizeResult(int $id,int $actor): array
	{
		$row=$this->repository->one("SELECT * FROM exportaciones WHERE id=? AND solicitante=? AND estado='completada' AND expira_en>NOW()",[$id,$actor]) ?? throw new HttpError('Exportación no disponible.',404);
		$context=$this->context($row);
		$this->registry->authorize($context,'exportaciones.descargar'); $this->registry->authorize($context,'exportaciones.generar');
		(new ActorContext($this->repository))->run($actor,$context['process'],function () use ($context) {
			foreach ($context['filters']['_authorized_ids'] ?? [] as $type=>$signature) {
				if ($type==='_private_files') { if (!hash_equals($signature,$this->privateSignature($context))) throw new HttpError('Cambió el acceso a archivos privados del paquete.',403); continue; }
				if (!hash_equals($signature,$this->signature($type,$context)) || !hash_equals($signature,$this->signature($type,[...$context,'scope_permission'=>'exportaciones.descargar']))) throw new HttpError('El alcance o conjunto autorizado cambió. Genera una nueva exportación.',403);
			}
		});
		return $row;
	}
	private function privateSignature(array $context): string
	{
		$hash=hash_init('sha256');
		foreach ((new FileService($this->repository,$this->storage))->packageSources($context['organization'],$context['process'],$context['filters']) as $file) hash_update($hash,$file['uuid']."\n");
		return hash_final($hash);
	}
	public function download(string $uuid): array
	{
		$row=$this->repository->one("SELECT id,archivo FROM exportaciones WHERE uuid=? AND solicitante=? AND estado='completada' AND archivo IS NOT NULL AND expira_en>NOW()",[Values::binary($uuid),(new Access($this->repository))->actor()]) ?? throw new HttpError('Exportación no disponible.',404);
		$file=$this->repository->one('SELECT uuid FROM archivos WHERE id=?',[$row['archivo']]);
		return (new FileService($this->repository,$this->storage))->authorized(Values::uuidText($file['uuid']));
	}
	public function listing(array $filters): array
	{
		$actor=(new Access($this->repository))->actor(); $limit=Pagination::limit($filters['limit'] ?? 25); $offset=Pagination::offset($filters['offset'] ?? 0);
		$rows=$this->repository->rows('SELECT uuid,tipo,formato,estado,fecha,expira_en,error FROM exportaciones WHERE solicitante=? ORDER BY id DESC LIMIT '.($limit+1).' OFFSET '.$offset,[$actor]);
		foreach ($rows as &$row) $row['uuid']=Values::uuidText($row['uuid']);
		return ['rows'=>array_slice($rows,0,$limit),'pagination'=>Pagination::response($rows,$limit,$offset)];
	}
}
