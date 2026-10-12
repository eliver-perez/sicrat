<?php

declare(strict_types=1);

namespace App\Services\Common;

class DatabaseDump
{
	public function __construct(private ?array $configuration = null) {}
	public function configuration(): array
	{
		return $this->configuration ?? ['host'=>(string) env('DB_HOST'),'port'=>(int) env('DB_PORT',3306),'database'=>(string) env('DB_DATABASE'),'username'=>(string) env('DB_USERNAME'),'password'=>(string) env('DB_PASSWORD')];
	}
	public function database(): string { return $this->configuration()['database']; }
	private function binary(bool $restore): string
	{
		$configured=(string) env($restore ? 'MYSQL_CLIENT_BINARY' : 'MYSQL_DUMP_BINARY','');
		$names=$restore ? ['mysql','mariadb'] : ['mysqldump','mariadb-dump'];
		$candidates=$configured==='' ? [] : [$configured];
		foreach (['/usr/bin','/usr/local/bin','/opt/homebrew/bin','/Applications/XAMPP/xamppfiles/bin'] as $directory) foreach ($names as $name) $candidates[]=$directory.'/'.$name;
		foreach ($candidates as $candidate) if (is_file($candidate) && is_executable($candidate)) return $candidate;
		throw new ServiceFailure('No se encontró herramienta nativa de respaldo/restauración.',false);
	}
	private function credentials(string $directory): string
	{
		$configuration=$this->configuration();
		$escape=static fn ($text)=>'"'.str_replace(["\\","\"","\n","\r"],["\\\\","\\\"","\\n","\\r"],(string) $text).'"';
		$file=$directory.'/credentials-'.bin2hex(random_bytes(16)).'.cnf';
		$handle=fopen($file,'x'); if (!$handle) throw new ServiceFailure('No se pudo preparar credencial temporal.'); chmod($file,0600);
		fwrite($handle,"[client]\n".implode("\n",array_map(fn ($key)=>$key.'='.$escape($configuration[$key]),['host','port','username','password'])) . "\n"); fclose($handle);
		// El nombre de opción del cliente es user, no username.
		$content=file_get_contents($file); file_put_contents($file,str_replace("\nusername=","\nuser=",$content));
		return $file;
	}
	private function run(array $command,string $input,string $output,string $directory): void
	{
		$errorFile=$directory.'/native-error-'.bin2hex(random_bytes(12));
		$process=proc_open($command,[0=>['file',$input,'r'],1=>['file',$output,'w'],2=>['file',$errorFile,'w']],$pipes,null,null,['bypass_shell'=>true]);
		if (!is_resource($process)) throw new ServiceFailure('No se pudo iniciar la herramienta nativa.');
		try {
			chmod($errorFile,0600); $deadline=time()+Values::integer(env('BACKUP_NATIVE_TIMEOUT',1800),30,14400);
			do { $status=proc_get_status($process); if (!$status['running']) break; if (time()>$deadline) { proc_terminate($process); throw new ServiceFailure('La herramienta nativa excedió el tiempo permitido.'); } usleep(100000); } while (true);
			$code=$status['exitcode']; $closed=proc_close($process); $process=null;
			if ($code!==0 && $closed!==0) throw new ServiceFailure('La herramienta nativa falló. Revisar credenciales, permisos y compatibilidad.',false);
		} finally { if (is_resource($process)) { proc_terminate($process); proc_close($process); } if (is_file($errorFile)) unlink($errorFile); }
	}
	public function dump(string $path): void
	{
		$config=$this->configuration(); if (!preg_match('/^[a-zA-Z0-9_]+$/D',$config['database'])) throw new ServiceFailure('Nombre de base inválido.',false);
		$directory=dirname($path); $credentials=$this->credentials($directory);
		try {
			$this->run([$this->binary(false),'--defaults-extra-file='.$credentials,'--single-transaction','--quick','--hex-blob','--no-tablespaces','--routines','--triggers','--events',$config['database']],'/dev/null',$path,$directory);
			chmod($path,0600); if (!is_file($path) || filesize($path)<50) throw new ServiceFailure('Respaldo de base vacío.',false);
		} finally { if (is_file($credentials)) unlink($credentials); }
	}
	public function restore(string $source,string $database): void
	{
		if (!preg_match('/^[a-zA-Z0-9_]+$/D',$database)) throw new ServiceFailure('Nombre de base inválido.',false);
		$directory=dirname($source); $credentials=$this->credentials($directory); $output=$directory.'/restore-output-'.bin2hex(random_bytes(12));
		try { $this->run([$this->binary(true),'--defaults-extra-file='.$credentials,'--binary-mode',$database],$source,$output,$directory); }
		finally { foreach ([$credentials,$output] as $file) if (is_file($file)) unlink($file); }
	}
}
