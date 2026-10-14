<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\HttpError;
use App\Repositories\CommonRepository;
use App\Repositories\PermissionRepository;
use App\Services\Common\AuditService;
use App\Services\Common\Values;

final class GeographyService
{
    public function __construct(private CommonRepository $repository) {}
    public function boothTypes(): array
    {
        return $this->repository->rows('SELECT codigo,nombre FROM casillas_tipos WHERE activo=1 ORDER BY nombre');
    }
    public function boothType(mixed $value): string
    {
        $code=Values::text($value,20);
        return $this->repository->one('SELECT codigo FROM casillas_tipos WHERE codigo=? AND activo=1',[$code])['codigo'] ?? throw new \InvalidArgumentException('Selecciona un tipo de casilla activo.');
    }
    public function lockContext(): void
    {
        // Mismo orden que Jornada y administración: organización, elección, catálogo físico.
        if(Auth::isSuperAdmin())$this->repository->rows('SELECT id FROM organizaciones ORDER BY id FOR UPDATE');
        else $this->repository->one('SELECT id FROM organizaciones WHERE id=? FOR UPDATE',[Auth::organizationId()]);
        if(Auth::processId())$this->repository->one('SELECT id FROM procesos_electorales WHERE id=? FOR UPDATE',[Auth::processId()]);
    }
    public function process(): int {return Auth::processId() ?? throw new HttpError('Selecciona una elección de trabajo.',422);}
    public function authorize(string $kind,bool $write=false): void
    {
        if(Auth::isSuperAdmin())return;
        $permission=$kind.'.'.($write ? 'administrar' : 'ver');
        $effective=(new PermissionRepository($this->repository->connection()))->effective(Auth::id() ?? 0,$this->process());
        if($kind!=='secciones' || $write || !array_intersect(['personas.ver','personas.crear','personas.editar','permisos.administrar'],$effective['rights'])) {
            if(!in_array($permission,$effective['rights'],true))throw new HttpError('No tienes permiso para administrar este catálogo.',403);
            if(!array_intersect(['eleccion','organizacion'],$effective['scopes'][$permission] ?? []))throw new HttpError('El catálogo requiere alcance de elección.',403);
        }
    }
    public function assertSection(int $id,bool $active=true): void
    {
        if(Auth::isSuperAdmin())return;
        if(!$this->repository->one('SELECT seccion FROM secciones_procesos WHERE proceso=? AND seccion=?'.($active ? ' AND activo=1' : ''),[$this->process(),$id]))throw new HttpError('Sección no disponible en esta elección.',404);
    }
    public function attachSection(int $section,?int $process=null): void
    {
        $process ??= Auth::processId();if(!$process)return;
        $this->repository->execute('INSERT INTO secciones_procesos(proceso,seccion,activo,registrado_por,fecha) VALUES(?,?,1,?,NOW()) ON DUPLICATE KEY UPDATE activo=1',[$process,$section,Auth::id()]);
    }
    public function attachBooth(int $booth,int $section,?int $process=null): void
    {
        $process ??= $this->process();$this->attachSection($section,$process);
        $this->repository->execute('INSERT INTO casillas_procesos(proceso,casilla,activo,registrado_por,fecha) VALUES(?,?,1,?,NOW()) ON DUPLICATE KEY UPDATE activo=1',[$process,$booth,Auth::id()]);
    }
    public function sectionsScope(): array
    {
        $this->authorize('secciones');
        return Auth::isSuperAdmin() ? ['1=1',[]] : ['EXISTS(SELECT 1 FROM secciones_procesos sp WHERE sp.seccion=se.id AND sp.proceso=? AND sp.activo=1)',[$this->process()]];
    }
    public function section(string $uuid): array
    {
        $this->authorize('secciones');$row=$this->repository->one('SELECT s.*,sp.activo FROM secciones_electorales s LEFT JOIN secciones_procesos sp ON sp.seccion=s.id AND sp.proceso=? WHERE s.uuid=?',[Auth::processId() ?? 0,Values::binary($uuid)]) ?? throw new HttpError('Sección no disponible.',404);
        $this->assertSection((int)$row['id'],false);$row['shared']=(bool)$this->repository->one('SELECT proceso FROM secciones_procesos WHERE seccion=? AND proceso<>? LIMIT 1',[$row['id'],Auth::processId() ?? 0]);$row['history_locked']=(bool)$this->repository->one('SELECT jc.id FROM jornada_casillas jc JOIN casillas c ON c.id=jc.casilla JOIN jornada_resultados r ON r.casilla=jc.id JOIN jornada_versiones v ON v.resultado=r.id WHERE c.seccion=? LIMIT 1',[$row['id']]);$row['id']=$uuid;$row['uuid']=$uuid;return $row;
    }
    public function saveSection(string $uuid,array $data): array
    {
        $this->authorize('secciones',true);
        return $this->repository->transaction(function() use($uuid,$data){
            $this->lockContext();$this->authorize('secciones',true);
            $row=$this->repository->one('SELECT * FROM secciones_electorales WHERE uuid=? FOR UPDATE',[Values::binary($uuid)]) ?? throw new HttpError('Sección no disponible.',404);$this->assertSection((int)$row['id'],false);
            if(!Auth::isSuperAdmin() && $this->repository->one('SELECT proceso FROM secciones_procesos WHERE seccion=? AND proceso<>? LIMIT 1',[$row['id'],$this->process()]))throw new HttpError('La sección pertenece a varias elecciones. Los datos físicos compartidos requieren administración global.',409);
            if($this->repository->one('SELECT v.id FROM jornada_versiones v JOIN jornada_resultados r ON r.id=v.resultado JOIN jornada_casillas jc ON jc.id=r.casilla JOIN casillas c ON c.id=jc.casilla WHERE c.seccion=? LIMIT 1',[$row['id']]))throw new HttpError('La sección tiene actas y conserva su configuración histórica.',409);
            $changes=['estado'=>Values::integer($data['state'] ?? $row['estado'],1),'municipio'=>Values::integer($data['municipality'] ?? $row['municipio'],1),'seccion'=>Values::integer($data['section'] ?? $row['seccion'],1),'distrito_local'=>array_key_exists('local_district',$data) ? (empty($data['local_district']) ? null : Values::integer($data['local_district'])) : $row['distrito_local'],'distrito_federal'=>array_key_exists('federal_district',$data) ? (empty($data['federal_district']) ? null : Values::integer($data['federal_district'])) : $row['distrito_federal']];
            if(!$this->repository->one('SELECT id FROM municipios WHERE id=? AND estado=?',[$changes['municipio'],$changes['estado']]))throw new \InvalidArgumentException('El municipio no pertenece al estado seleccionado.');
            if($this->repository->one('SELECT id FROM secciones_electorales WHERE estado=? AND seccion=? AND id<>?',[$changes['estado'],$changes['seccion'],$row['id']]))throw new HttpError('Ya existe esa sección en el estado.',409);
            $this->repository->execute('UPDATE secciones_electorales SET estado=?,municipio=?,seccion=?,distrito_local=?,distrito_federal=? WHERE id=?',[...array_values($changes),$row['id']]);
            (new AuditService($this->repository))->record((int)$this->repository->one('SELECT organizacion FROM procesos_electorales WHERE id=?',[$this->process()])['organizacion'],$this->process(),'editar','secciones_electorales',(int)$row['id'],$row,$changes);return $this->section($uuid);
        });
    }
    public function booths(array $filters): array
    {
        $this->authorize('casillas');$params=[];$where='1=1';
        if(!Auth::isSuperAdmin()){$where='EXISTS(SELECT 1 FROM casillas_procesos cp WHERE cp.casilla=c.id AND cp.proceso=? AND cp.activo=1)';$params[]=$this->process();}
        if(!empty($filters['search'])){$where.=' AND (c.casilla LIKE ? OR CAST(s.seccion AS CHAR) LIKE ?)';$search='%'.Values::text($filters['search'],100).'%';$params=[...$params,$search,$search];}
        $limit=\App\Core\Pagination::limit($filters['limit'] ?? 25);$offset=\App\Core\Pagination::offset($filters['offset'] ?? 0);
        $rows=$this->repository->rows("SELECT c.*,s.seccion FROM casillas c JOIN secciones_electorales s ON s.id=c.seccion WHERE $where ORDER BY s.seccion,c.id LIMIT ".($limit+1).' OFFSET '.$offset,$params);
        $page=['rows'=>array_slice($rows,0,$limit),'pagination'=>\App\Core\Pagination::response($rows,$limit,$offset)];
        foreach($page['rows'] as &$row){$row['uuid']=Values::uuidText($row['uuid']);$row['label']='Sección '.$row['seccion'].' · '.$row['casilla'];}unset($row);return $page;
    }
    public function booth(string $uuid): array
    {
        $this->authorize('casillas');$row=$this->repository->one('SELECT c.*,s.seccion numero_seccion,s.uuid section_uuid FROM casillas c JOIN secciones_electorales s ON s.id=c.seccion WHERE c.uuid=?',[Values::binary($uuid)]) ?? throw new HttpError('Casilla no disponible.',404);
        if(!Auth::isSuperAdmin() && !$this->repository->one('SELECT casilla FROM casillas_procesos WHERE casilla=? AND proceso=?',[$row['id'],$this->process()]))throw new HttpError('Casilla no disponible en esta elección.',404);
        $row['history_locked']=(bool)$this->repository->one('SELECT jc.id FROM jornada_casillas jc JOIN jornada_resultados r ON r.casilla=jc.id JOIN jornada_versiones v ON v.resultado=r.id WHERE jc.casilla=? LIMIT 1',[$row['id']]);$row['uuid']=$uuid;$row['section_uuid']=Values::uuidText($row['section_uuid']);$row['shared']=(bool)$this->repository->one('SELECT proceso FROM casillas_procesos WHERE casilla=? AND proceso<>? LIMIT 1',[$row['id'],Auth::processId() ?? 0]);return $row;
    }
    public function saveBooth(?string $uuid,array $data): array
    {
        $this->authorize('casillas',true);
        return $this->repository->transaction(function() use($uuid,$data){
            $this->lockContext();$this->authorize('casillas',true);
            $before=$uuid ? $this->booth($uuid) : null;
            if($before){$this->repository->one('SELECT id FROM casillas WHERE id=? FOR UPDATE',[$before['id']]);if(!Auth::isSuperAdmin() && $this->repository->one('SELECT proceso FROM casillas_procesos WHERE casilla=? AND proceso<>? LIMIT 1',[$before['id'],$this->process()]))throw new HttpError('La casilla es compartida. Los datos físicos requieren administración global.',409);}
            $section=isset($data['section']) ? $this->repository->one('SELECT id FROM secciones_electorales WHERE uuid=?',[Values::binary($data['section'])]) : ['id'=>$before['seccion'] ?? null];
            if(!$section || !$section['id'])throw new \InvalidArgumentException('Selecciona una sección.');$this->assertSection((int)$section['id']);
            $values=['seccion'=>(int)$section['id'],'casilla'=>Values::text($data['name'] ?? $before['casilla'] ?? null,60),'tipo'=>isset($data['type']) || !$before ? $this->boothType($data['type'] ?? null) : $before['tipo'],'ubicacion'=>Values::text($data['location'] ?? $before['ubicacion'] ?? '',255,true),'domicilio'=>Values::text($data['address'] ?? $before['domicilio'] ?? '',255,true)];
            if($before && $this->repository->one('SELECT id FROM casillas WHERE seccion=? AND casilla=? AND tipo=? AND id<>?',[$values['seccion'],$values['casilla'],$values['tipo'],$before['id']]))throw new HttpError('Ya existe una casilla con esos datos en la sección.',409);
            if($before){if($this->repository->one('SELECT v.id FROM jornada_versiones v JOIN jornada_resultados r ON r.id=v.resultado JOIN jornada_casillas jc ON jc.id=r.casilla WHERE jc.casilla=? LIMIT 1',[$before['id']]))throw new HttpError('La casilla tiene actas; se conserva su configuración histórica.',409);$this->repository->execute('UPDATE casillas SET seccion=?,casilla=?,tipo=?,ubicacion=?,domicilio=? WHERE id=?',[...array_values($values),$before['id']]);$id=(int)$before['id'];}
            else{$this->repository->one('SELECT id FROM secciones_electorales WHERE id=? FOR UPDATE',[$section['id']]);$existing=$this->repository->one('SELECT id,uuid FROM casillas WHERE seccion=? AND casilla=? AND tipo=?',[$values['seccion'],$values['casilla'],$values['tipo']]);if($existing){$id=(int)$existing['id'];$uuid=Values::uuidText($existing['uuid']);}else{$uuid=Values::uuid();$id=$this->repository->insert('INSERT INTO casillas(uuid,seccion,casilla,tipo,ubicacion,domicilio,registro,f_registro) VALUES(?,?,?,?,?,?,?,NOW())',[Values::binary($uuid),...array_values($values),Auth::id()]);}}
            if($before && Auth::isSuperAdmin())foreach($this->repository->rows('SELECT proceso FROM casillas_procesos WHERE casilla=? AND activo=1',[$id]) as $association)$this->attachSection((int)$section['id'],(int)$association['proceso']);
            $this->attachSection((int)$section['id']);$this->repository->execute('INSERT INTO casillas_procesos(proceso,casilla,activo,registrado_por,fecha) VALUES(?,?,1,?,NOW()) ON DUPLICATE KEY UPDATE activo=1',[$this->process(),$id,Auth::id()]);
            $organization=(int)$this->repository->one('SELECT organizacion FROM procesos_electorales WHERE id=?',[$this->process()])['organizacion'];(new AuditService($this->repository))->record($organization,$this->process(),$before ? 'editar' : 'crear','casillas',$id,$before,$values);return $this->booth($uuid);
        });
    }
}
