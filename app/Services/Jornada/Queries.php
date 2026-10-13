<?php

declare(strict_types=1);

namespace App\Services\Jornada;

use App\Core\HttpError;
use App\Core\Pagination;
use App\Repositories\CommonRepository;
use App\Services\Common\Values;

class Queries
{
    public readonly Context $context;
    public function __construct(private CommonRepository $repository) { $this->context=new Context($repository); }
    public function permission(): string
    {
        foreach(['resultados.ver','resultados.capturar','resultados.validar','jornada.asignar_capturistas','jornada.configurar'] as $permission) {
            if(!$this->context->allowed($permission))continue;
            if(str_starts_with($permission,'jornada.')) { try { $this->context->broad($permission); }catch(HttpError) { continue; } }
            return $permission;
        }
        throw new HttpError('No tienes permiso para consultar Jornada Electoral.',403);
    }
    public function capabilities(): array
    {
        $ctx=$this->context;$this->permission();
        $rights=[];
        foreach(['jornada.configurar','jornada.asignar_capturistas','jornada.abrir','jornada.cerrar','jornada.reabrir','resultados.capturar','resultados.ver','resultados.ver_preliminares','resultados.validar','resultados.corregir','resultados.administrar','resultados.exportar','archivos.ver','archivos.subir','notificaciones.administrar','exportaciones.generar'] as $permission) {
            if(!$ctx->allowed($permission)) continue;
            if(str_starts_with($permission,'jornada.') || $permission==='notificaciones.administrar') { try { $ctx->broad($permission); } catch(HttpError) { continue; } }
            $rights[]=$permission;
        }
        return ['rights'=>$rights,'enabled'=>$ctx->enabled(),'process_id'=>$ctx->process,'organization_id'=>$ctx->organization,'actor'=>$ctx->actor,'configuration'=>$this->repository->one('SELECT j.*,pe.proceso eleccion,pe.f_eleccion fecha_eleccion FROM jornada_config j JOIN procesos_electorales pe ON pe.id=j.proceso WHERE j.proceso=?',[$ctx->process])];
    }
    public function accounts(array $filters): array
    {
        $ctx=$this->context;
        $assignmentWhere='';$assignmentParams=[];
        if(($filters['assigned'] ?? '')==='1') {
            [$scope,$scopeParams]=$ctx->predicate($this->permission());
            $assignmentWhere=' AND EXISTS(SELECT 1 '.$this->joins().' JOIN jornada_capturistas account_cp ON account_cp.casilla=jc.id AND account_cp.usuario=u.id AND account_cp.activo=1 WHERE ('.$scope.'))';
            $assignmentParams=$scopeParams;
        } elseif(!$ctx->allowed('jornada.asignar_capturistas')) $ctx->broad('jornada.configurar');else $ctx->broad('jornada.asignar_capturistas');
        $limit=Pagination::limit($filters['limit'] ?? 25);$offset=Pagination::offset($filters['offset'] ?? 0);$search='%'.Values::text($filters['search'] ?? '',150,true).'%';
        $rows=$this->repository->rows('SELECT u.uuid,u.id,u.usuario,u.nombre FROM usuarios u JOIN usuarios_procesos up ON up.usuario=u.id AND up.proceso=? AND up.activo=1 AND up.f_baja IS NULL WHERE u.organizacion=? AND u.activo=1 AND (u.nombre LIKE ? OR u.usuario LIKE ?)'.$assignmentWhere.' ORDER BY u.nombre,u.id LIMIT '.($limit+1).' OFFSET '.$offset,[$ctx->process,$ctx->organization,$search,$search,...$assignmentParams]);
        $visible=[];
        foreach(array_slice($rows,0,$limit) as $row) { $row['uuid']=Values::uuidText($row['uuid']);$row['label']=$row['usuario'].' · '.$row['nombre'];$row['can_capture']=$ctx->allowed('resultados.capturar',(int)$row['id']);$visible[]=$row; }
        return ['rows'=>$visible,'pagination'=>Pagination::response($rows,$limit,$offset)];
    }
    public function territories(array $filters): array
    {
        $ctx=$this->context;$type=$filters['type'] ?? 'seccion';$limit=Pagination::limit($filters['limit'] ?? 25);$offset=Pagination::offset($filters['offset'] ?? 0);$search='%'.Values::text($filters['search'] ?? '',150,true).'%';
        if(($filters['catalogue'] ?? '')==='1') { $ctx->broad('jornada.configurar');$where='1=1';$params=[];$joins='FROM secciones_electorales s LEFT JOIN municipios m ON m.id=s.municipio'; }
        else { [$where,$params]=$ctx->predicate($this->permission());$joins=$this->joins(); }
        if(!in_array($type,['seccion','municipio'],true)) throw new \InvalidArgumentException('Territorio inválido.');
        $column=$type==='seccion' ? 's.id' : 's.municipio';$name=$type==='seccion' ? "CONCAT('Sección ',s.seccion,' · ',COALESCE(m.municipio,''))" : 'm.municipio';
        $rows=$this->repository->rows("SELECT DISTINCT $column id,$name nombre".($type==='seccion' && ($filters['catalogue'] ?? '')==='1' ? ',s.uuid' : '')." $joins WHERE ($where) AND ($name LIKE ?) ORDER BY nombre,id LIMIT ".($limit+1).' OFFSET '.$offset,[...$params,$search]);
        foreach($rows as &$row) if(isset($row['uuid']))$row['uuid']=Values::uuidText($row['uuid']);unset($row);
        return ['rows'=>array_slice($rows,0,$limit),'pagination'=>Pagination::response($rows,$limit,$offset)];
    }
    public function filter(array $filters,string $permission,string|array|null $extraPermission=null): array
    {
        [$where,$params]=$this->context->predicate($permission);
        foreach((array)$extraPermission as $permissionCode) { [$extra,$values]=$this->context->predicate($permissionCode);$where.=" AND ($extra)";$params=[...$params,...$values]; }
        if(($filters['mis'] ?? '')==='1') { $where.=' AND EXISTS(SELECT 1 FROM jornada_capturistas mine WHERE mine.casilla=jc.id AND mine.usuario=? AND mine.activo=1)';$params[]=$this->context->actor; }
        if(($filters['search'] ?? '')!=='') {
            $search='%'.Values::text($filters['search'],150,true).'%';$where.=' AND (CAST(s.seccion AS CHAR) LIKE ? OR c.casilla LIKE ? OR m.municipio LIKE ?)';$params=[...$params,$search,$search,$search];
        }
        foreach(['seccion'=>'s.id','municipio'=>'s.municipio','distrito_local'=>'s.distrito_local','distrito_federal'=>'s.distrito_federal'] as $key=>$column) if(!empty($filters[$key])) { $where.=" AND $column=?";$params[]=Values::integer($filters[$key]); }
        if(!empty($filters['usuario'])) { $where.=' AND EXISTS(SELECT 1 FROM jornada_capturistas filter_cp JOIN usuarios filter_u ON filter_u.id=filter_cp.usuario WHERE filter_cp.casilla=jc.id AND filter_cp.activo=1 AND filter_u.uuid=?)';$params[]=Values::binary($filters['usuario']); }
        if(!empty($filters['estado'])) {
            $state=$filters['estado'];
            if(!in_array($state,['sin_captura','pendiente','preliminar','validada','con_propuestas'],true)) throw new \InvalidArgumentException('Filtro de estado inválido.');
            $where.=match($state) {'sin_captura'=>' AND COALESCE(r.consecutivo,0)=0','pendiente'=>" AND r.actual_version IS NULL AND r.consecutivo>0",'con_propuestas'=>" AND EXISTS(SELECT 1 FROM jornada_versiones proposal WHERE proposal.resultado=r.id AND proposal.estado='pendiente')",default=>' AND current_v.estado=?'};
            if(in_array($state,['preliminar','validada'],true)) $params[]=$state;
        }
        return [$where,$params];
    }
    public function joins(): string
    {
        return 'FROM jornada_casillas jc JOIN casillas c ON c.id=jc.casilla JOIN secciones_electorales s ON s.id=c.seccion LEFT JOIN municipios m ON m.id=s.municipio LEFT JOIN jornada_resultados r ON r.casilla=jc.id LEFT JOIN jornada_versiones current_v ON current_v.id=r.actual_version';
    }
    public function listing(array $filters,?string $permission=null,string|array|null $extraPermission=null): array
    {
        $permission ??= !empty($filters['administrar']) && $this->context->allowed('jornada.configurar') ? 'jornada.configurar' : $this->permission();
        [$where,$params]=$this->filter($filters,$permission,$extraPermission);
        $limit=Pagination::limit($filters['limit'] ?? 25);$offset=Pagination::offset($filters['offset'] ?? 0);
        $rows=$this->repository->rows("SELECT jc.id,jc.uuid,jc.activo,c.tipo,c.casilla nombre,s.id seccion_id,s.seccion,s.municipio,m.municipio municipio_nombre,s.distrito_local,s.distrito_federal,r.actual_version,r.consecutivo,current_v.estado,current_v.fecha ultima_actualizacion,current_v.numero, (SELECT COUNT(*) FROM jornada_versiones v WHERE v.resultado=r.id AND v.estado='pendiente') propuestas,(SELECT GROUP_CONCAT(u.nombre ORDER BY u.nombre SEPARATOR ', ') FROM jornada_capturistas cp JOIN usuarios u ON u.id=cp.usuario WHERE cp.casilla=jc.id AND cp.activo=1) capturistas,(SELECT MAX(v.fecha) FROM jornada_versiones v WHERE v.resultado=r.id) ultima_captura,(SELECT a.uuid FROM archivos a WHERE a.id=current_v.foto AND a.estado='activo') foto_uuid ".$this->joins()." WHERE $where ORDER BY s.seccion,c.casilla,jc.id LIMIT ".($limit+1).' OFFSET '.$offset,$params);
        foreach($rows as &$row) {
            $row['uuid']=Values::uuidText($row['uuid']);$row['foto_uuid']=$row['foto_uuid']===null ? null : Values::uuidText($row['foto_uuid']);
            $row['estado'] ??= (int)$row['consecutivo']>0 ? 'pendiente' : 'sin_captura';
            $row['label']='Sección '.$row['seccion'].' · '.$row['nombre'].' ('.$row['tipo'].')';
        }unset($row);
        return ['rows'=>array_slice($rows,0,$limit),'pagination'=>Pagination::response($rows,$limit,$offset)];
    }
    public function rawAllowed(array $version): bool
    {
        if((int)$version['usuario']===$this->context->actor) return true;
        $booth=$this->repository->one('SELECT jc.uuid FROM jornada_resultados r JOIN jornada_casillas jc ON jc.id=r.casilla WHERE r.id=?',[$version['resultado']]);
        $permissions=['resultados.validar'];
        if(in_array($version['estado'],['preliminar','sustituida'],true)) $permissions[]='resultados.ver_preliminares';
        foreach($permissions as $permission) {
            try { $this->context->casilla(Values::uuidText($booth['uuid']),$permission);return true; }catch(HttpError) { }
        }
        if($version['estado']==='validada') return true;
        if($version['estado']==='sustituida') return (bool)$this->repository->one("SELECT id FROM jornada_decisiones WHERE version_resultado=? AND accion='aprobar' LIMIT 1",[$version['id']]);
        return false;
    }
    public function booth(string $uuid): array
    {
        $ctx=$this->context;$booth=$ctx->casilla($uuid,$this->permission());
        $booth['uuid']=$uuid;
        $result=$this->repository->one('SELECT * FROM jornada_resultados WHERE casilla=?',[$booth['id']]);
        $booth['actual_esperada']=$result && $result['actual_version'] ? Values::uuidText($this->repository->one('SELECT uuid FROM jornada_versiones WHERE id=?',[$result['actual_version']])['uuid']) : null;
        $booth['capturas']=(int)($result['consecutivo'] ?? 0);
        $booth['asignaciones']=$this->repository->rows('SELECT cp.activo,cp.fecha,cp.retirado_en,u.nombre,u.usuario,u.uuid FROM jornada_capturistas cp JOIN usuarios u ON u.id=cp.usuario WHERE cp.casilla=? ORDER BY cp.activo DESC,u.nombre',[$booth['id']]);
        foreach($booth['asignaciones'] as &$row) $row['uuid']=Values::uuidText($row['uuid']);unset($row);
        $booth['opciones']=$this->repository->rows('SELECT o.id,o.uuid,o.nombre,o.tipo,o.orden FROM jornada_opciones o WHERE o.proceso=? AND o.activo=1 ORDER BY o.orden',[$ctx->process]);
        foreach($booth['opciones'] as &$option) $option['uuid']=Values::uuidText($option['uuid']);unset($option);
        $booth['vigente']=$booth['actual_esperada'] ? $this->version($booth['actual_esperada']) : null;
        $booth['puede_capturar']=false;
        try { $ctx->captureAllowed([...$booth,'uuid'=>Values::binary($uuid)]);$ctx->open($this->repository->one('SELECT * FROM jornada_config WHERE proceso=?',[$ctx->process]) ?? []);$booth['puede_capturar']=true; } catch(HttpError) { }
        return $booth;
    }
    public function version(string $uuid): array
    {
        $version=$this->repository->one('SELECT v.*,jc.uuid casilla_uuid,c.casilla,s.seccion,u.nombre capturista,r.actual_version FROM jornada_versiones v JOIN jornada_resultados r ON r.id=v.resultado JOIN jornada_casillas jc ON jc.id=r.casilla JOIN casillas c ON c.id=jc.casilla JOIN secciones_electorales s ON s.id=c.seccion JOIN usuarios u ON u.id=v.usuario WHERE v.uuid=? AND v.proceso=?',[Values::binary($uuid),$this->context->process]) ?? throw new HttpError('Versión no disponible.',404);
        $this->context->casilla(Values::uuidText($version['casilla_uuid']),$this->permission());
        $version['visible']=$this->rawAllowed($version);$version['uuid']=$uuid;$version['casilla_uuid']=Values::uuidText($version['casilla_uuid']);$version['vigente']=(int)$version['actual_version']===(int)$version['id'];
        $version['votos']=$version['visible'] ? $this->repository->rows('SELECT o.nombre,o.uuid,o.tipo,o.orden,v.votos FROM jornada_votos v JOIN jornada_opciones o ON o.id=v.opcion WHERE v.version=? ORDER BY o.orden',[$version['id']]) : [];
        foreach($version['votos'] as &$vote) $vote['uuid']=Values::uuidText($vote['uuid']);unset($vote);
        $version['foto_uuid']=null;
        if($version['visible'] && $version['foto']) { $file=$this->repository->one("SELECT uuid FROM archivos WHERE id=? AND estado='activo'",[$version['foto']]);$version['foto_uuid']=$file ? Values::uuidText($file['uuid']) : null; }
        $version['decisiones']=$this->repository->rows('SELECT d.accion,d.motivo,d.fecha,u.nombre revisor,d.cambios FROM jornada_decisiones d JOIN usuarios u ON u.id=d.usuario WHERE d.version_resultado=? ORDER BY d.id',[$version['id']]);
        foreach($version['decisiones'] as &$decision) { $decision['cambios']=$version['visible'] ? json_decode($decision['cambios'],true) : []; }unset($decision);
        if(!$version['visible']) { $version['total']=null;$version['foto']=null;$version['notas']=null;$version['motivo']=null; }
        unset($version['firma'],$version['clave']);
        return $version;
    }
    public function history(string $uuid,array $filters): array
    {
        $booth=$this->context->casilla($uuid,$this->permission());
        $limit=Pagination::limit($filters['limit'] ?? 25);$offset=Pagination::offset($filters['offset'] ?? 0);
        $rows=$this->repository->rows('SELECT v.id,v.uuid FROM jornada_versiones v JOIN jornada_resultados r ON r.id=v.resultado WHERE r.casilla=? ORDER BY v.numero DESC LIMIT '.($limit+1).' OFFSET '.$offset,[$booth['id']]);
        return ['rows'=>array_map(fn($row)=>$this->version(Values::uuidText($row['uuid'])),array_slice($rows,0,$limit)),'pagination'=>Pagination::response($rows,$limit,$offset)];
    }
    public function summary(array $filters,string|array|null $extraPermission=null): array
    {
        return $this->repository->transaction(fn()=>$this->calculateSummary($filters,$extraPermission));
    }
    private function calculateSummary(array $filters,string|array|null $extraPermission=null): array
    {
        $ctx=$this->context;$ctx->require('resultados.ver');$mode=$filters['modo'] ?? 'validado';
        if(!in_array($mode,['preliminar','validado'],true)) throw new \InvalidArgumentException('Modo inválido.');
        [$where,$params]=$this->filter($filters,'resultados.ver',$extraPermission);
        if($mode==='preliminar') { [$pre,$extra]=$ctx->predicate('resultados.ver_preliminares');$where.=" AND ($pre)";$params=[...$params,...$extra]; }
        $base=$this->joins()." WHERE ($where) AND jc.activo=1";
        $progress=$this->repository->one("SELECT COUNT(*) esperadas,SUM(COALESCE(r.consecutivo,0)>0) capturadas,SUM(COALESCE(r.consecutivo,0)=0) pendientes,SUM(current_v.estado='preliminar') preliminares,SUM(current_v.estado='validada') validadas,SUM(r.consecutivo>0 AND r.actual_version IS NULL) pendientes_iniciales,SUM(EXISTS(SELECT 1 FROM jornada_versiones pv WHERE pv.resultado=r.id AND pv.estado='pendiente')) casillas_con_propuestas,MAX((SELECT MAX(v.fecha) FROM jornada_versiones v WHERE v.resultado=r.id)) ultima_actualizacion $base",$params);
        foreach($progress as $key=>$value) if($key!=='ultima_actualizacion') $progress[$key]=(int)$value;
        $progress['porcentaje_captura']=$progress['esperadas'] ? round(100*$progress['capturadas']/$progress['esperadas'],2) : 0;
        $progress['porcentaje_validacion']=$progress['esperadas'] ? round(100*$progress['validadas']/$progress['esperadas'],2) : 0;
        $states=$mode==='preliminar' ? "('preliminar','validada')" : "('validada')";
        $options=$this->repository->rows('SELECT o.id,o.uuid,o.nombre,o.tipo,o.candidatura,o.suma_candidatura,o.en_denominador,o.clasificacion,p.color FROM jornada_opciones o LEFT JOIN jornada_partidos p ON p.id=o.partido WHERE o.proceso=? AND o.activo=1 ORDER BY o.orden',[$ctx->process]);
        $raw=array_column($this->repository->rows("SELECT votes.opcion,SUM(votes.votos) votos FROM jornada_votos votes JOIN jornada_versiones counted ON counted.id=votes.version WHERE counted.id IN(SELECT current_v.id $base AND current_v.estado IN $states) GROUP BY votes.opcion",$params),'votos','opcion');
        $config=$this->repository->one('SELECT denominador,zona FROM jornada_config WHERE proceso=?',[$ctx->process]);$definition=$config['denominador'] ?? 'total_acta';
        $denominator=0;$total=0;$classifications=['valido'=>0,'nulo'=>0,'otro'=>0];$candidates=[];
        foreach($options as &$option) {
            $option['uuid']=Values::uuidText($option['uuid']);$option['votos']=(int)($raw[$option['id']] ?? 0);$total+=$option['votos'];$classifications[$option['clasificacion']]+=$option['votos'];
            if($definition==='total_acta' || ($definition==='candidaturas' && (int)$option['suma_candidatura']) || ($definition==='seleccionadas' && (int)$option['en_denominador'])) $denominator+=$option['votos'];
            if((int)$option['suma_candidatura'] && $option['candidatura']) $candidates[$option['candidatura']]=($candidates[$option['candidatura']] ?? 0)+$option['votos'];
        }unset($option);
        $candidateRows=$this->repository->rows('SELECT id,uuid,nombre,cargo FROM jornada_candidaturas WHERE proceso=? ORDER BY nombre',[$ctx->process]);
        foreach($candidateRows as &$candidate) { $candidate['uuid']=Values::uuidText($candidate['uuid']);$candidate['votos']=$candidates[$candidate['id']] ?? 0;$candidate['porcentaje']=$denominator ? round(100*$candidate['votos']/$denominator,4) : 0; }unset($candidate);
        foreach($options as &$option) $option['porcentaje']=$denominator ? round(100*$option['votos']/$denominator,4) : 0;unset($option);
        $contributing=(int)$this->repository->one("SELECT COUNT(*) total $base AND current_v.estado IN $states",$params)['total'];
        return ['modo'=>$mode,'leyenda'=>$mode==='preliminar' ? 'Preliminar interno: versiones vigentes preliminares y validadas, sin sumar propuestas ni historial.' : 'Validado interno: únicamente versiones vigentes validadas.', 'progreso'=>$progress,'casillas_contribuyentes'=>$contributing,'denominador'=>$denominator,'criterio_denominador'=>$definition,'total_acta'=>$total,'clasificaciones'=>$classifications,'opciones'=>$options,'candidaturas'=>$candidateRows,'zona'=>$config['zona'] ?? 'UTC'];
    }
}
