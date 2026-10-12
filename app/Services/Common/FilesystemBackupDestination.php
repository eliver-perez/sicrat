<?php

declare(strict_types=1);

namespace App\Services\Common;

class FilesystemBackupDestination implements BackupDestination
{
	private PrivateStorage $storage;
	public function __construct(?string $root = null) { $this->storage=new PrivateStorage($root,true); }
	public function path(string $relative,bool $createParent = false): string { return $this->storage->path($relative,$createParent); }
	public function available(): ?float { $space=@disk_free_space($this->storage->root()); return $space===false ? null : $space; }
	public function remove(string $relative): void { $this->storage->remove($relative); }
	public function label(): string { return 'Destino privado configurado'; }
}
