<?php

declare(strict_types=1);

namespace App\Services\Jornada;

use App\Core\HttpError;
use App\Repositories\CommonRepository;
use App\Services\Common\ResourceRegistry;
use App\Services\Common\Values;

final class Resources
{
    public static function register(ResourceRegistry $registry,CommonRepository $repository): void
    {
        $registry->register('jornada_borradores',static function(int $id,int $organization,?int $process,string $operation,int $actor,?string $permission=null) use($repository) {
            $draft=$repository->one('SELECT b.*,jc.uuid casilla_uuid FROM jornada_borradores b JOIN jornada_casillas jc ON jc.id=b.casilla WHERE b.id=? AND b.organizacion=? AND b.proceso=?',[$id,$organization,$process]) ?? throw new HttpError('Acta no disponible.',404);
            $ctx=new Context($repository);
            if($ctx->process!==$process) throw new HttpError('Selecciona la elección del acta.',404);
            $booth=Values::uuidText($draft['casilla_uuid']);
            if($operation==='write') {
                if((int)$draft['usuario']!==$actor || $draft['estado']!=='abierto' || $repository->one("SELECT v.id FROM jornada_versiones v JOIN archivos a ON a.id=v.foto WHERE a.entidad='jornada_borradores' AND a.entidad_id=? LIMIT 1",[$id])) throw new HttpError('La fotografía de una versión enviada no puede alterarse.',403);
                $config=$repository->one('SELECT * FROM jornada_config WHERE proceso=?',[$process]);$ctx->open($config ?? []);
                if($ctx->allowed('resultados.corregir',$actor)) $ctx->casilla($booth,'resultados.corregir');
                else $ctx->captureAllowed($repository->one('SELECT * FROM jornada_casillas WHERE id=?',[$draft['casilla']]));
                if(!$ctx->enabled()) throw new HttpError('Jornada Electoral está deshabilitada.',403);
            } else {
                $query=new Queries($repository);$ctx->casilla($booth,$query->permission());
                $versions=$repository->rows("SELECT DISTINCT v.* FROM jornada_versiones v LEFT JOIN archivos a ON a.id=v.foto WHERE v.borrador=? OR (a.entidad='jornada_borradores' AND a.entidad_id=?)",[$id,$id]);
                if($versions && !array_filter($versions,fn($version)=>$query->rawAllowed($version))) throw new HttpError('No tienes permiso para consultar esta fotografía.',403);
                if(!$versions && (int)$draft['usuario']!==$actor) throw new HttpError('El borrador es privado del capturista.',403);
            }
            if($permission!==null) { [$where,$params]=$ctx->predicate($permission,'jc',$actor);if(!$repository->one("SELECT id FROM jornada_casillas jc WHERE jc.id=? AND ($where)",[$draft['casilla'],...$params])) throw new HttpError('Acta fuera del alcance del permiso solicitado.',404); }
        },static function(?int $process,int $actor,string $permission) use($repository) {
            if($process===null) return ['0=1',[]];
            try {
                $ctx=new Context($repository);$query=new Queries($repository);
                [$where,$params]=$ctx->predicate($query->permission(),'jc',$actor);[$files,$fileParams]=$ctx->predicate($permission,'jc',$actor);
                return ["EXISTS(SELECT 1 FROM jornada_borradores b JOIN jornada_casillas jc ON jc.id=b.casilla WHERE b.id=a.entidad_id AND ($where) AND ($files) AND (b.usuario=? OR EXISTS(SELECT 1 FROM jornada_versiones v WHERE v.borrador=b.id AND v.estado='validada')))",[...$params,...$fileParams,$actor]];
            } catch(HttpError) { return ['0=1',[]]; }
        });
        $registry->onLock('jornada_borradores',static function(int $id) use($repository) {
            $repository->one('SELECT proceso FROM jornada_config WHERE proceso=? FOR UPDATE',[\App\Core\Auth::processId()]);
            $repository->one('SELECT id FROM jornada_borradores WHERE id=? FOR UPDATE',[$id]);
        });
        $registry->filePolicy('jornada_borradores',static function(int $id,string $mime) { if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)) throw new \InvalidArgumentException('Las actas sólo admiten fotografías JPEG, PNG o WebP.'); });
        foreach(['jornada_partidos','jornada_candidaturas'] as $table) {
            $registry->register($table,static function(int $id,int $organization,?int $process,string $operation,int $actor,?string $permission=null) use($repository,$table) {
                $ctx=new Context($repository);
                if($ctx->process!==$process || $ctx->organization!==$organization || !$repository->one("SELECT id FROM $table WHERE id=? AND proceso=?",[$id,$process])) throw new HttpError('Imagen electoral no disponible.',404);
                if($operation==='write') {
                    $ctx->broad('jornada.configurar',true);
                    if($repository->one('SELECT id FROM jornada_versiones WHERE proceso=? LIMIT 1',[$process])) throw new HttpError('La imagen de configuración está protegida por capturas existentes.',409);
                } else $ctx->require((new Queries($repository))->permission());
                if($permission) $ctx->require($permission,false,$actor);
            });
            $registry->filePolicy($table,static function(int $id,string $mime) { if(!str_starts_with($mime,'image/')) throw new \InvalidArgumentException('El catálogo electoral sólo admite imágenes.'); });
            $registry->onLock($table,static function(int $id) use($repository,$table) {
                $repository->one('SELECT proceso FROM jornada_config WHERE proceso=? FOR UPDATE',[\App\Core\Auth::processId()]);
                $repository->one("SELECT id FROM $table WHERE id=? FOR UPDATE",[$id]);
            });
        }
    }
}
