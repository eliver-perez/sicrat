<?php

declare(strict_types=1);

namespace App\Services\Common;

use App\Core\Auth;
use App\Core\HttpError;
use App\Repositories\CommonRepository;
use App\Repositories\PermissionRepository;

final class Access
{
	public function __construct(private CommonRepository $repository) {}
	public function actor(): int { return Auth::id() ?? throw new HttpError('No autenticado.',401); }
	public function super(?int $actor = null): void
	{
		$user = $this->repository->one('SELECT u.activo,t.codigo FROM usuarios u JOIN usuarios_tipos t ON t.id=u.tipo_usuario WHERE u.id=?',[$actor ?? $this->actor()]);
		if (!$user || !(int) $user['activo'] || $user['codigo']!=='superadmin') throw new HttpError('Operación exclusiva del Super Administrador.',403);
	}
	public function context(int $organization, ?int $process, string $permission, ?int $actor = null): array
	{
		$effective = (new PermissionRepository($this->repository->connection()))->require($actor ?? $this->actor(),$permission,$process);
		if (!$effective['super'] && (int) $effective['user']['organizacion']!==$organization) throw new HttpError('Recurso no disponible.',404);
		if (!$this->repository->one('SELECT id FROM organizaciones WHERE id=?',[$organization])) throw new HttpError('Organización no disponible.',404);
		if ($process!==null && !$this->repository->one('SELECT id FROM procesos_electorales WHERE id=? AND organizacion=? AND estatus=1',[$process,$organization])) throw new HttpError('Elección no disponible.',404);
		return $effective;
	}
	public function broad(int $organization, ?int $process, string $permission, ?int $actor = null): array
	{
		$effective = $this->context($organization,$process,$permission,$actor);
		$scopes = $effective['scopes'][$permission] ?? [];
		if (!$effective['super'] && !in_array('organizacion',$scopes,true) && !($process!==null && in_array('eleccion',$scopes,true))) throw new HttpError('Se requiere alcance de elección u organización.',403);
		return $effective;
	}
}
