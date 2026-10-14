<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Core\HttpError;
use App\Services\Common\Values;

final class CaseCategories extends BaseService
{
    protected function entity(): string {return 'peticiones';}
    public function categories(string $kind,array $filters): array
    {
        Catalog::choice($kind,['peticiones','incidencias']);$process=$this->context->process();
        $permission=$kind.'.ver';
        foreach(['ver','crear','editar','categorias'] as $action)if($this->context->allowed($kind.'.'.$action,$process)){$permission=$kind.'.'.$action;break;}
        $this->context->require($kind,$process,$permission);
        $where='organizacion=? AND proceso=? AND tipo=?';$parameters=[$this->repository->one('SELECT organizacion FROM procesos_electorales WHERE id=?',[$process])['organizacion'],$process,$kind];
        if(($filters['active'] ?? '')==='1')$where.=' AND activo=1';
        if(!empty($filters['search'])){$where.=' AND nombre LIKE ?';$parameters[]='%'.Values::text($filters['search'],100).'%';}
        $page=$this->repository->page("SELECT id,uuid,nombre,activo FROM casos_categorias WHERE $where ORDER BY nombre,id",$parameters,$filters);
        foreach($page['rows'] as &$row)$row['uuid']=Values::uuidText($row['uuid']);unset($row);return $page;
    }
    public function save(string $kind,?string $uuid,array $data): array
    {
        Catalog::choice($kind,['peticiones','incidencias']);$process=$this->context->process();$ctx=$this->context->require($kind,$process,$kind.'.categorias');
        if(!$this->context->broad($ctx,$kind.'.categorias'))throw new HttpError('La configuración requiere alcance de elección.',403);
        return $this->repository->transaction(function() use($kind,$uuid,$data,$ctx,$process){
            $this->repository->one('SELECT id FROM procesos_electorales WHERE id=? FOR UPDATE',[$process]);$this->context->require($kind,$process,$kind.'.categorias');
            $before=$uuid ? $this->repository->one('SELECT * FROM casos_categorias WHERE uuid=? AND proceso=? AND tipo=?',[Values::binary($uuid),$process,$kind]) ?? throw new HttpError('Categoría no disponible.',404) : null;
            $name=preg_replace('/\s+/u',' ',Values::text($data['name'] ?? null,100));$active=Values::integer($data['active'] ?? 1,0,1);
            if($this->repository->one('SELECT id FROM casos_categorias WHERE proceso=? AND tipo=? AND nombre=? AND id<>?',[$process,$kind,$name,$before['id'] ?? 0]))throw new HttpError('Ya existe esa categoría en esta elección.',409);
            if($before){$this->repository->execute('UPDATE casos_categorias SET nombre=?,activo=? WHERE id=?',[$name,$active,$before['id']]);$id=(int)$before['id'];}
            else{$uuid=Values::uuid();$id=$this->repository->insert('INSERT INTO casos_categorias(uuid,organizacion,proceso,tipo,nombre,activo,registrado_por,fecha) VALUES(?,?,?,?,?,?,?,NOW())',[Values::binary($uuid),$ctx['organization'],$process,$kind,$name,$active,$ctx['actor']]);}
            (new \App\Services\Common\AuditService($this->repository))->record($ctx['organization'],$process,$before ? 'editar' : 'crear','casos_categorias',$id,$before,['nombre'=>$name,'activo'=>$active]);return ['uuid'=>$uuid];
        });
    }
    public function resolve(string $kind,int $process,array $data,?array $previous=null): array
    {
        if(!isset($data['category_id']) && !isset($data['category']) && $previous)return ['categoria_id'=>$previous['categoria_id'],'categoria'=>$previous['categoria']];
        if(!empty($data['category_id']))$row=$this->repository->one('SELECT * FROM casos_categorias WHERE uuid=? AND proceso=? AND tipo=?',[Values::binary($data['category_id']),$process,$kind]);
        else {
            $name=Values::text($data['category'] ?? 'General',100);
            if($name==='General'){$organization=(int)$this->repository->one('SELECT organizacion FROM procesos_electorales WHERE id=?',[$process])['organizacion'];$this->repository->execute('INSERT IGNORE INTO casos_categorias(uuid,organizacion,proceso,tipo,nombre,fecha) VALUES(?,?,?,?,?,NOW())',[random_bytes(16),$organization,$process,$kind,'General']);}
            $row=$this->repository->one('SELECT * FROM casos_categorias WHERE nombre=? AND proceso=? AND tipo=?',[$name,$process,$kind]);
        }
        if(!$row || (!(int)$row['activo'] && (int)($previous['categoria_id'] ?? 0)!==(int)$row['id']))throw new \InvalidArgumentException('Selecciona una categoría activa del catálogo de esta elección.');
        return ['categoria_id'=>(int)$row['id'],'categoria'=>$row['nombre']];
    }
}
