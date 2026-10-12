<?php

declare(strict_types=1);

namespace App\Services\Common;

class PrivateStorage
{
	private string $root;
	public function __construct(?string $root = null,bool $backup = false)
	{
		$project=dirname(__DIR__,3);
		$root ??= (string) env($backup ? 'BACKUP_STORAGE_PATH' : 'PRIVATE_STORAGE_PATH',$backup ? dirname($project).'/sicrat-private-backups' : $project.'/storage/private');
		if ($root==='' || !str_starts_with($root,'/') || str_contains($root,"\0")) throw new ServiceFailure('El almacenamiento privado requiere una ruta absoluta.',false);
		if (preg_match('#/(\.|\.\.)(/|$)#',$root)) throw new ServiceFailure('La ruta de almacenamiento debe ser canónica.',false);
		$ancestor=$root;
		while (!file_exists($ancestor)) { if (is_link($ancestor)) throw new ServiceFailure('Destino privado inválido.',false); $ancestor=dirname($ancestor); }
		$resolved=realpath($ancestor).substr($root,strlen($ancestor));
		$public=realpath($project.'/public'); $repository=realpath($project);
		if (($public && ($resolved===$public || str_starts_with($resolved,$public.'/'))) || ($backup && ($resolved===$repository || str_starts_with($resolved,$repository.'/')))) throw new ServiceFailure('Destino privado dentro de una ubicación pública o del repositorio.',false);
		if (!is_dir($root) && !mkdir($root,0700,true)) throw new ServiceFailure('No se pudo preparar el almacenamiento privado.',false);
		$this->root=realpath($root) ?: throw new ServiceFailure('Destino privado inválido.',false);
		$public=realpath($project.'/public'); $repository=realpath($project);
		if (($public && ($this->root===$public || str_starts_with($this->root,$public.'/'))) || ($backup && ($this->root===$repository || str_starts_with($this->root,$repository.'/')))) throw new ServiceFailure('Destino privado dentro de una ubicación pública o del repositorio.',false);
		chmod($this->root,0700);
	}
	public function root(): string { return $this->root; }
	public function path(string $relative,bool $createParent = false): string
	{
		if (!preg_match('#^[a-zA-Z0-9_-]+(/[a-zA-Z0-9_.-]+)*$#D',$relative) || str_contains($relative,'..')) throw new ServiceFailure('Ruta privada inválida.',false);
		$path=$this->root.'/'.$relative; $parent=dirname($path);
		$check=$this->root;
		foreach (explode('/',$relative) as $component) { $check.='/'.$component; if (is_link($check)) throw new ServiceFailure('El almacenamiento no admite enlaces simbólicos.',false); }
		if ($createParent && !is_dir($parent) && !mkdir($parent,0700,true)) throw new ServiceFailure('No se pudo preparar el directorio privado.',false);
		$realParent=realpath($parent);
		if ($realParent!==false && $realParent!==$this->root && !str_starts_with($realParent,$this->root.'/')) throw new ServiceFailure('Ruta fuera del almacenamiento privado.',false);
		return $path;
	}
	public function temporary(): string
	{
		$path=$this->path('temporales/'.bin2hex(random_bytes(16)).'.tmp',true);
		$handle=fopen($path,'x'); if (!$handle) throw new ServiceFailure('No se pudo crear temporal privado.'); fclose($handle); chmod($path,0600);
		return $path;
	}
	public function remove(string $relative): void { $path=$this->path($relative); if (is_file($path) && !unlink($path)) throw new ServiceFailure('No se pudo eliminar el archivo privado.'); }
}
