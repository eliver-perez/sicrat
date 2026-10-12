<?php

declare(strict_types=1);

namespace App\Services\Common;

class BackupArchive
{
	public function __construct(private PrivateStorage $files) {}
	public function snapshot(): array
	{
		$result=[]; $directory=$this->files->path('archivos');
		if (!is_dir($directory)) return [];
		$iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			if ($file->isLink() || !$file->isFile()) throw new ServiceFailure('Objeto no regular en almacenamiento privado.',false);
			$relative=substr($file->getPathname(),strlen($this->files->root())+1); $path=$this->files->path($relative);
			$result[$relative]=['hash'=>hash_file('sha256',$path),'size'=>filesize($path),'time'=>filemtime($path)];
		}
		ksort($result); return $result;
	}
	public function create(string $path,array $manifest,?string $dump = null): void
	{
		$zip=new \ZipArchive(); if ($zip->open($path,\ZipArchive::CREATE | \ZipArchive::OVERWRITE)!==true) throw new ServiceFailure('No se pudo crear el respaldo.');
		try {
			$zip->addFromString('manifest.json',Values::json($manifest));
			foreach ($manifest['changed'] as $relative) $zip->addFile($this->files->path($relative),'files/'.$relative);
			if ($dump!==null) $zip->addFile($dump,'database.sql');
		} finally { $zip->close(); }
		chmod($path,0600);
	}
	private function hashEntry(\ZipArchive $zip,string $entry): array
	{
		$stream=$zip->getStream($entry); if (!$stream) throw new ServiceFailure('Falta un archivo del manifiesto.',false);
		$hash=hash_init('sha256'); $size=0;
		try { while (!feof($stream)) { $chunk=fread($stream,1048576); if ($chunk===false) throw new ServiceFailure('No se pudo verificar el archivo.',false); hash_update($hash,$chunk); $size+=strlen($chunk); } }
		finally { fclose($stream); }
		return ['hash'=>hash_final($hash),'size'=>$size];
	}
	public function verify(string $path,array $manifest): void
	{
		$zip=new \ZipArchive(); if ($zip->open($path,\ZipArchive::RDONLY)!==true) throw new ServiceFailure('Respaldo ZIP inválido.',false);
		try {
			$encoded=$zip->getFromName('manifest.json'); if (!is_string($encoded) || $encoded!==Values::json($manifest)) throw new ServiceFailure('Manifiesto inválido o alterado.',false);
			$expected=['manifest.json'=>true];
			foreach ($manifest['files'] as $relative=>$metadata) $this->files->path($relative);
			foreach ($manifest['deleted'] as $relative) $this->files->path($relative);
			foreach ($manifest['changed'] as $relative) {
				$this->files->path($relative); $entry='files/'.$relative; $expected[$entry]=true;
				$actual=$this->hashEntry($zip,$entry); $metadata=$manifest['files'][$relative] ?? null;
				if (!$metadata || $actual['size']!==(int) $metadata['size'] || !hash_equals($metadata['hash'],$actual['hash'])) throw new ServiceFailure('Hash o tamaño de archivo incorrecto.',false);
			}
			if (isset($manifest['database'])) {
				$expected['database.sql']=true; $actual=$this->hashEntry($zip,'database.sql');
				if ($actual['size']!==$manifest['database']['size'] || !hash_equals($manifest['database']['hash'],$actual['hash'])) throw new ServiceFailure('Hash del respaldo de BD incorrecto.',false);
			}
			if ($zip->numFiles!==count($expected)) throw new ServiceFailure('Entradas ajenas al manifiesto.',false);
			for ($index=0;$index<$zip->numFiles;$index++) { $entry=$zip->getNameIndex($index); if (!isset($expected[$entry])) throw new ServiceFailure('Entrada ZIP no autorizada.',false); }
		} finally { $zip->close(); }
	}
	public function extract(string $path,array $manifest,PrivateStorage $target,?string $dumpTarget = null): void
	{
		$this->verify($path,$manifest); $zip=new \ZipArchive(); $zip->open($path,\ZipArchive::RDONLY);
		try {
			foreach ($manifest['deleted'] as $relative) $target->remove($relative);
			foreach ($manifest['changed'] as $relative) $this->copyEntry($zip,'files/'.$relative,$target->path($relative,true));
			if ($dumpTarget!==null && isset($manifest['database'])) $this->copyEntry($zip,'database.sql',$dumpTarget);
		} finally { $zip->close(); }
	}
	private function copyEntry(\ZipArchive $zip,string $entry,string $target): void
	{
		$input=$zip->getStream($entry); $output=fopen($target,'wb'); if (!$input || !$output) throw new ServiceFailure('No se pudo reconstruir un archivo.'); chmod($target,0600);
		try { if (stream_copy_to_stream($input,$output)===false) throw new ServiceFailure('No se pudo reconstruir un archivo.'); }
		finally { fclose($input); fclose($output); }
	}
}
