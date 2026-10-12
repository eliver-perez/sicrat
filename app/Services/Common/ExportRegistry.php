<?php

declare(strict_types=1);

namespace App\Services\Common;

use App\Core\HttpError;
use App\Repositories\AccountScopeRepository;
use App\Repositories\CommonRepository;
use App\Repositories\ModuleRepository;
use App\Repositories\ScopeRepository;

class ExportRegistry
{
	private array $exporters=[];
	public function __construct(private CommonRepository $repository)
	{
		$this->register('personas','Personas',['Código','Nombre','Apellido paterno','Apellido materno','Sección','Correo','Teléfono'],fn ($context)=>$this->persons($context));
		$this->register('participaciones','Participaciones',['Código','Nombre','Elección','Activo'],fn ($context)=>$this->participations($context));
		$this->register('estructura','Estructura',['Código','Nombre','Estructura','Responsable','Nivel','Activo'],fn ($context)=>$this->structure($context));
		$this->register('usuarios_accesos','Usuarios_y_accesos',['Usuario','Nombre','Cuenta activa','Elección','Rol electoral','Acceso activo','Persona'],fn ($context)=>$this->accounts($context));
	}
	public function register(string $type,string $title,array $columns,callable $rows,bool $inPackage = true): void { $this->exporters[$type]=['title'=>$title,'columns'=>$columns,'rows'=>$rows,'package'=>$inPackage]; }
	public function packageTypes(): array { return array_keys(array_filter($this->exporters,static fn ($definition)=>$definition['package'])); }
	public function descriptor(string $type): array { return $this->exporters[$type] ?? throw new \InvalidArgumentException('Exportador no disponible.'); }
	public function authorize(array $context,string $permission): void
	{
		(new Access($this->repository))->context($context['organization'],$context['process'],$permission,$context['actor']);
	}
	public function rows(string $type,array $context): iterable
	{
		$exporter=$this->descriptor($type); yield from ($exporter['rows'])($context);
	}
	private function batches(string $sql,array $params): iterable
	{
		$last=0;
		do {
			$rows=$this->repository->rows($sql.' AND source_id>? ORDER BY source_id LIMIT 250',[...$params,$last]);
			foreach ($rows as $row) { $last=(int) $row['source_id']; yield $row; }
		} while (count($rows)===250);
	}
	private function personFilter(array $context,string $permission): array
	{
		$this->authorize($context,$permission);
		[$where,$params]=(new ScopeRepository($this->repository->connection()))->predicate($permission,$context['process'],'p',$context['actor']);
		[$exportScope,$exportParams]=(new ScopeRepository($this->repository->connection()))->predicate($context['scope_permission'] ?? 'exportaciones.generar',$context['process'],'p',$context['actor']);
		$where="p.organizacion=? AND ($where) AND ($exportScope)"; $params=[$context['organization'],...$params,...$exportParams];
		if (($context['filters']['search'] ?? '')!=='') { $where.=" AND (p.codigo_persona LIKE ? OR CONCAT_WS(' ',p.nombre,p.paterno,p.materno) LIKE ?)"; $search='%'.$context['filters']['search'].'%'; $params[]=$search; $params[]=$search; }
		if (($context['filters']['section'] ?? null)!==null) { $where.=' AND p.seccion=?'; $params[]=$context['filters']['section']; }
		return [$where,$params];
	}
	private function persons(array $context): iterable
	{
		[$where,$params]=$this->personFilter($context,'personas.ver');
		$sql="SELECT p.id source_id,p.codigo_persona,p.nombre,p.paterno,p.materno,s.seccion,p.email,p.telefono FROM personas p LEFT JOIN secciones_electorales s ON s.id=p.seccion WHERE $where";
		// Alias source_id no existe en WHERE; la subconsulta permite paginar por clave sin OFFSET creciente.
		yield from $this->batches('SELECT * FROM ('.$sql.') dataset WHERE 1=1',$params);
	}
	private function participations(array $context): iterable
	{
		if ($context['process']===null) throw new \InvalidArgumentException('Selecciona una elección para participaciones.');
		[$where,$params]=$this->personFilter($context,'personas.ver');
		$sql="SELECT pp.id source_id,p.codigo_persona,p.nombre,pe.proceso,pp.activo FROM personas_procesos pp JOIN personas p ON p.id=pp.persona JOIN procesos_electorales pe ON pe.id=pp.proceso WHERE $where AND pp.proceso=?";
		yield from $this->batches('SELECT * FROM ('.$sql.') dataset WHERE 1=1',[...$params,$context['process']]);
	}
	private function structure(array $context): iterable
	{
		if ($context['process']===null) throw new \InvalidArgumentException('Selecciona una elección para estructura.');
		(new ModuleRepository($this->repository->connection()))->require('estructura',$context['process']);
		[$where,$params]=$this->personFilter($context,'estructura.ver');
		$sql="SELECT e.id source_id,p.codigo_persona,p.nombre,c.nombre estructura,parent.codigo_persona responsable,n.nombre nivel,ep.activo FROM estructura e JOIN estructuras_organizacionales c ON c.id=e.contenedor JOIN estructuras_procesos association ON association.estructura=c.id AND association.proceso=? AND association.activo=1 JOIN estructura_integrantes_procesos ep ON ep.integrante=e.id AND ep.proceso=association.proceso JOIN personas p ON p.id=e.persona LEFT JOIN estructura boss ON boss.id=e.nodo_padre LEFT JOIN personas parent ON parent.id=boss.persona LEFT JOIN niveles_organizacionales n ON n.id=ep.nivel WHERE $where";
		yield from $this->batches('SELECT * FROM ('.$sql.') dataset WHERE 1=1',[$context['process'],...$params]);
	}
	private function accounts(array $context): iterable
	{
		$this->authorize($context,'usuarios.administrar');
		[$scope,$params]=(new AccountScopeRepository($this->repository->connection()))->predicate('usuarios.administrar',$context['process']);
		[$exportScope,$exportParams]=(new AccountScopeRepository($this->repository->connection()))->predicate($context['scope_permission'] ?? 'exportaciones.generar',$context['process']);
		$sql="SELECT u.id source_id,u.usuario,u.nombre,u.activo,pe.proceso,t.tipo rol,up.activo acceso_activo,p.codigo_persona FROM usuarios u LEFT JOIN usuarios_procesos up ON up.usuario=u.id AND up.proceso=? LEFT JOIN procesos_electorales pe ON pe.id=up.proceso LEFT JOIN usuarios_tipos t ON t.id=up.tipo_usuario LEFT JOIN personas p ON p.id=u.persona WHERE u.organizacion=? AND ($scope) AND ($exportScope)";
		$parameters=[$context['process'],$context['organization'],...$params,...$exportParams];
		if (($context['filters']['search'] ?? '')!=='') { $sql.=' AND (u.usuario LIKE ? OR u.nombre LIKE ?)'; $search='%'.$context['filters']['search'].'%'; $parameters[]=$search; $parameters[]=$search; }
		yield from $this->batches('SELECT * FROM ('.$sql.') dataset WHERE 1=1',$parameters);
	}
}
