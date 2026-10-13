<?php

declare(strict_types=1);

namespace App\Services\Jornada;

use App\Core\Auth;
use App\Core\HttpError;
use App\Repositories\CommonRepository;
use App\Repositories\ModuleRepository;
use App\Services\Common\Access;
use App\Services\Common\Values;

class Context
{
    public readonly int $process;
    public readonly int $organization;
    public readonly int $actor;

    public function __construct(public readonly CommonRepository $repository)
    {
        $this->process = Auth::processId() ?? throw new HttpError('Selecciona una elección de trabajo.',422);
        $this->actor = (new Access($repository))->actor();
        $this->organization = (int)($repository->one('SELECT organizacion FROM procesos_electorales WHERE id=? AND estatus=1',[$this->process])['organizacion'] ?? throw new HttpError('Elección no disponible.',404));
    }
    public function enabled(): bool
    {
        foreach ((new ModuleRepository($this->repository->connection()))->catalog($this->organization,$this->process) as $module) if ($module['codigo']==='jornada_electoral') return (bool)$module['implementado'] && $module['habilitado'];
        return false;
    }
    public function require(string $permission, bool $write = false, ?int $actor = null): array
    {
        $effective = (new Access($this->repository))->context($this->organization,$this->process,$permission,$actor ?? $this->actor);
        if ($write && !$this->enabled()) throw new HttpError('Jornada Electoral está deshabilitada. El historial autorizado permanece disponible.',403);
        return $effective;
    }
    public function allowed(string $permission, ?int $actor = null): bool
    {
        try { $this->require($permission,false,$actor); return true; } catch (HttpError) { return false; }
    }
    public function broad(string $permission, bool $write = false): void
    {
        $effective = $this->require($permission,$write);
        if (!$effective['super'] && !array_intersect(['organizacion','eleccion'],$effective['scopes'][$permission] ?? [])) throw new HttpError('Esta configuración requiere alcance de elección u organización.',403);
    }
    public function lock(string $permission): array
    {
        // Primer acceso dentro de la transacción: lectura actual antes de cualquier snapshot.
        $config = $this->repository->one('SELECT * FROM jornada_config WHERE proceso=? FOR UPDATE',[$this->process]) ?? throw new HttpError('Configura primero la Jornada Electoral.',409);
        $this->repository->one('SELECT id FROM usuarios WHERE id=? FOR UPDATE',[$this->actor]);
        $this->repository->one('SELECT id FROM usuarios_procesos WHERE usuario=? AND proceso=? FOR UPDATE',[$this->actor,$this->process]);
        $this->require($permission,true);
        return $config;
    }
    public function predicate(string $permission, string $alias = 'jc', ?int $actor = null): array
    {
        $actor ??= $this->actor;
        $effective = $this->require($permission,false,$actor);
        $captureOnly="(EXISTS(SELECT 1 FROM jornada_capturistas mine WHERE mine.casilla=$alias.id AND mine.usuario=? AND mine.activo=1) OR EXISTS(SELECT 1 FROM jornada_resultados mine_result JOIN jornada_versiones mine_version ON mine_version.resultado=mine_result.id WHERE mine_result.casilla=$alias.id AND mine_version.usuario=?))";
        if ($effective['super']) return ["$alias.proceso=?",[$this->process]];
        if (array_intersect(['organizacion','eleccion'],$effective['scopes'][$permission] ?? [])) return $permission==='resultados.capturar' ? ["$alias.proceso=? AND $captureOnly",[$this->process,$actor,$actor]] : ["$alias.proceso=?",[$this->process]];
        $parts = []; $params = [$this->process];
        $scopes = $effective['scopes'][$permission] ?? [];
        if (array_intersect(['propio','directos','descendientes'],$scopes)) {
            [$people,$personParams] = (new \App\Repositories\ScopeRepository($this->repository->connection()))->predicate($permission,$this->process,'p',$actor);
            $parts[] = "EXISTS(SELECT 1 FROM jornada_capturistas cp JOIN usuarios u ON u.id=cp.usuario JOIN personas p ON p.id=u.persona WHERE cp.casilla=$alias.id AND cp.activo=1 AND ($people))";
            $params = [...$params,...$personParams];
            if (in_array('propio',$scopes,true)) { $parts[]="EXISTS(SELECT 1 FROM jornada_capturistas cp WHERE cp.casilla=$alias.id AND cp.usuario=? AND cp.activo=1)"; $params[]=$actor; }
            if (in_array('propio',$scopes,true)) { $parts[] = "EXISTS(SELECT 1 FROM jornada_resultados r JOIN jornada_versiones v ON v.resultado=r.id WHERE r.casilla=$alias.id AND v.usuario=?)"; $params[]=$actor; }
        }
        if (in_array('territorio',$scopes,true)) { $parts[]="EXISTS(SELECT 1 FROM casillas c JOIN usuarios_territorios t ON t.seccion=c.seccion WHERE c.id=$alias.casilla AND t.usuario=? AND t.proceso=?)"; $params[]=$actor; $params[]=$this->process; }
        $where="$alias.proceso=? AND (".($parts ? implode(' OR ',$parts) : '0=1').')';
        if($permission==='resultados.capturar') { $where.=" AND $captureOnly";$params=[...$params,$actor,$actor]; }
        return [$where,$params];
    }
    public function casilla(string $uuid, string $permission): array
    {
        [$scope,$params] = $this->predicate($permission);
        return $this->repository->one("SELECT jc.*,c.casilla nombre,c.tipo,s.seccion,s.id seccion_id,s.municipio,s.distrito_local,s.distrito_federal FROM jornada_casillas jc JOIN casillas c ON c.id=jc.casilla JOIN secciones_electorales s ON s.id=c.seccion WHERE jc.uuid=? AND ($scope)",[Values::binary($uuid),...$params]) ?? throw new HttpError('Casilla no disponible dentro de tu alcance.',404);
    }
    public function captureAllowed(array $casilla): void
    {
        $this->require('resultados.capturar',true);
        if (!(int)$casilla['activo']) throw new HttpError('La casilla está inactiva.',409);
        if (!$this->repository->one('SELECT id FROM jornada_capturistas WHERE casilla=? AND usuario=? AND activo=1',[$casilla['id'],$this->actor])) {
            $this->casilla(Values::uuidText($casilla['uuid']),'resultados.administrar');
        } else $this->casilla(Values::uuidText($casilla['uuid']),'resultados.capturar');
    }
    public function open(array $config): void
    {
        if (($config['estado'] ?? '')!=='abierta') throw new HttpError('La Jornada Electoral no está abierta para capturas.',409);
        if ($config['captura_desde']>gmdate('Y-m-d H:i:s')) throw new HttpError('Todavía no inicia el horario permitido de captura.',409);
    }
}
