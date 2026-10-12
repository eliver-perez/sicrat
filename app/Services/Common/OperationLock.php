<?php

declare(strict_types=1);

namespace App\Services\Common;

use App\Core\HttpError;
use App\Repositories\CommonRepository;

final class OperationLock
{
	public function __construct(private CommonRepository $repository) {}
	public function run(string $operation,callable $work): mixed
	{
		$name='sicrat:'.substr(hash('sha256',$this->repository->connection()->query('SELECT DATABASE()')->fetchColumn().':'.$operation),0,48);
		if ((int) ($this->repository->one('SELECT GET_LOCK(?,0) result',[$name])['result'] ?? 0)!==1) throw new HttpError('Existe una operación incompatible en ejecución.',409);
		$handle=null;
		try {
			// El bloqueo físico sobrevive a una caída de la conexión PDO durante un proceso nativo.
			$storage=new PrivateStorage(); $path=$storage->path('locks/'.hash('sha256',$name).'.lock',true);
			$handle=fopen($path,'c');
			if (!$handle || !flock($handle,LOCK_EX | LOCK_NB)) throw new HttpError('Existe una operación incompatible en ejecución.',409);
			chmod($path,0600); return $work();
		} finally {
			if (is_resource($handle)) { flock($handle,LOCK_UN); fclose($handle); }
			$this->repository->one('SELECT RELEASE_LOCK(?) result',[$name]);
		}
	}
}
