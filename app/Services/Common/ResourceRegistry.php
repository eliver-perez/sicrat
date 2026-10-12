<?php

declare(strict_types=1);

namespace App\Services\Common;

use App\Core\HttpError;
use App\Repositories\CommonRepository;
use App\Repositories\ScopeRepository;

class ResourceRegistry
{
	private array $owners = [];
	private array $filters = [];
	private array $decisions = [];
	private array $filePolicies = [];
	private array $locks = [];
	public function __construct(private CommonRepository $repository)
	{
		$this->register('personas',function (int $record,int $organization,?int $process,string $operation,int $actor,?string $permission = null): void {
			if (!$this->repository->one('SELECT id FROM personas WHERE id=? AND organizacion=?',[$record,$organization])) throw new HttpError('Recurso no disponible.',404);
			(new ScopeRepository($this->repository->connection()))->assertPerson($record,$operation==='read' ? 'personas.ver' : 'personas.editar',$process,$actor);
			if ($permission!==null) (new ScopeRepository($this->repository->connection()))->assertPerson($record,$permission,$process,$actor);
		}, function (?int $process,int $actor,string $permission): array {
			[$scope,$parameters]=(new ScopeRepository($this->repository->connection()))->predicate($permission,$process,'p',$actor);
			[$ownerScope,$ownerParameters]=(new ScopeRepository($this->repository->connection()))->predicate('personas.ver',$process,'p',$actor);
			return ["EXISTS (SELECT 1 FROM personas p WHERE p.id=a.entidad_id AND ($scope) AND ($ownerScope))",[...$parameters,...$ownerParameters]];
		});
		$this->register('exportaciones',function (int $record,int $organization,?int $process,string $operation,int $actor): void {
			if ($operation!=='read') throw new HttpError('El resultado de exportación es administrado por su servicio.',403);
			(new ExportService($this->repository))->authorizeResult($record,$actor);
		});
		\App\Services\Operations\Resources::register($this,$repository);
	}
	public function register(string $entity, callable $authorize,?callable $listingPredicate = null): void
	{
		$this->owners[$entity]=$authorize;
		if ($listingPredicate!==null) $this->filters[$entity]=$listingPredicate;
	}
	public function listingPredicate(?int $process,int $actor,string $permission): array
	{
		$parts=[]; $parameters=[];
		foreach ($this->filters as $entity=>$filter) {
			[$condition,$params]=$filter($process,$actor,$permission);
			$parts[]="(a.entidad=? AND ($condition))"; $parameters[]=$entity; $parameters=[...$parameters,...$params];
		}
		return [$parts ? implode(' OR ',$parts) : '0=1',$parameters];
	}
	public function authorize(string $entity,int $record,int $organization,?int $process,string $operation,int $actor,?string $permission = null): void
	{
		if (!isset($this->owners[$entity])) throw new HttpError('No existe autorización para el recurso propietario.',403);
		($this->owners[$entity])($record,$organization,$process,$operation,$actor,$permission);
	}
	public function onDecision(string $entity,callable $callback): void { $this->decisions[$entity]=$callback; }
	public function onLock(string $entity,callable $callback): void { $this->locks[$entity]=$callback; }
	public function lock(string $entity,int $record): void
	{
		if (isset($this->locks[$entity])) ($this->locks[$entity])($record);
	}
	public function decision(array $approval,string $state,int $actor): void
	{
		if (isset($this->decisions[$approval['entidad']])) ($this->decisions[$approval['entidad']])($approval,$state,$actor);
	}
	public function filePolicy(string $entity,callable $callback): void { $this->filePolicies[$entity]=$callback; }
	public function validateFile(string $entity,int $record,string $mime): void
	{
		if (isset($this->filePolicies[$entity])) ($this->filePolicies[$entity])($record,$mime);
	}
}
