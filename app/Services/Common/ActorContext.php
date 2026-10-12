<?php

declare(strict_types=1);

namespace App\Services\Common;

use App\Repositories\CommonRepository;
use App\Repositories\PermissionRepository;

final class ActorContext
{
	public function __construct(private CommonRepository $repository) {}
	// Sólo el worker utiliza este contexto: las facultades se releen en BD, nunca del payload.
	public function run(int $actor,?int $process,callable $work): mixed
	{
		$effective=(new PermissionRepository($this->repository->connection()))->effective($actor,$process);
		$global=$this->repository->one('SELECT t.codigo FROM usuarios u JOIN usuarios_tipos t ON t.id=u.tipo_usuario WHERE u.id=?',[$actor]);
		$previous=$_SESSION ?? [];
		$_SESSION=['SICRAT_ID'=>$actor,'SICRAT_ORGANIZATION_ID'=>$effective['user']['organizacion'],'SICRAT_ORGANIZATION_PROCESS_ID'=>$process,'SICRAT_GLOBAL_TYPE_CODE'=>$global['codigo'],'SICRAT_RIGHTS'=>$effective['rights'],'SICRAT_PERMISSION_SCOPES'=>$effective['scopes']];
		try { return $work(); } finally { $_SESSION=$previous; }
	}
}
