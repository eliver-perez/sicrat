<?php

declare(strict_types=1);

namespace App\Services\Jornada;

use App\Core\HttpError;
use App\Core\Pagination;
use App\Repositories\CommonRepository;
use App\Services\Common\AuditService;
use App\Services\Common\Values;

class Configuration
{
    public const CATALOGS = ['partidos'=>'jornada_partidos','candidaturas'=>'jornada_candidaturas','coaliciones'=>'jornada_coaliciones','opciones'=>'jornada_opciones'];
    private Context $context;
    public function __construct(private CommonRepository $repository) { $this->context=new Context($repository); }
    private function audit(string $action,string $entity,int $id,?array $before,array $after): void
    {
        (new AuditService($this->repository))->record($this->context->organization,$this->context->process,$action,$entity,$id,$before,$after);
    }
    public function view(): array
    {
        $ctx=$this->context;
        (new Queries($this->repository))->permission();
        $config=$this->repository->one('SELECT j.*,pe.proceso eleccion,pe.f_eleccion fecha_eleccion FROM jornada_config j JOIN procesos_electorales pe ON pe.id=j.proceso WHERE j.proceso=?',[$ctx->process]);
        $catalogs=[];
        foreach(self::CATALOGS as $type=>$table) {
            $catalogs[$type]=$this->repository->rows("SELECT * FROM $table WHERE proceso=? ORDER BY ".($type==='opciones' ? 'orden' : 'nombre'),[$ctx->process]);
            foreach($catalogs[$type] as &$row) {
                $row['uuid']=Values::uuidText($row['uuid']);
                if(in_array($type,['partidos','candidaturas'],true)) {
                    $image=$row[$type==='partidos' ? 'logo' : 'foto'];
                    $file=$image ? $this->repository->one("SELECT uuid FROM archivos WHERE id=? AND estado='activo'",[$image]) : null;
                    $row['imagen_uuid']=$file ? Values::uuidText($file['uuid']) : null;
                }
                if($type==='coaliciones') $row['partidos']=array_column($this->repository->rows('SELECT partido FROM jornada_coalicion_partidos WHERE coalicion=?',[$row['id']]),'partido');
            } unset($row);
        }
        if($config) { $config['captura_local']=(new \DateTimeImmutable($config['captura_desde'],new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($config['zona']))->format('Y-m-d\TH:i'); }
        return ['configuration'=>$config,'catalogs'=>$catalogs,'enabled'=>$ctx->enabled(),'checklist'=>$this->checklist(),'locked'=>(bool)$this->repository->one('SELECT id FROM jornada_versiones WHERE proceso=? LIMIT 1',[$ctx->process])];
    }
    public static function utc(mixed $value,mixed $zone): string
    {
        $zone=Values::text($zone,64);
        if(!in_array($zone,\DateTimeZone::listIdentifiers(),true)) throw new \InvalidArgumentException('Selecciona una zona horaria IANA válida.');
        $local=Values::text($value,16);
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$local,new \DateTimeZone($zone));
        if(!$date || $date->format('Y-m-d\TH:i')!==$local) throw new \InvalidArgumentException('Fecha u hora local inválida.');
        foreach([-7200,-3600,3600,7200] as $offset) if($date->setTimestamp($date->getTimestamp()+$offset)->format('Y-m-d\TH:i')===$local) throw new \InvalidArgumentException('La hora es ambigua por un cambio horario; elige otra hora.');
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    private function mutable(): void
    {
        if($this->repository->one('SELECT id FROM jornada_versiones WHERE proceso=? LIMIT 1',[$this->context->process])) throw new HttpError('La configuración del acta está protegida porque ya existen capturas. Se conserva el historial.',409);
    }
    public function save(array $data): array
    {
        $ctx=$this->context; $ctx->broad('jornada.configurar',true);
        $zone=Values::text($data['zona'] ?? null,64); $start=self::utc($data['captura_local'] ?? null,$zone);
        $policy=$data['politica_inicial'] ?? ''; $denominator=$data['denominador'] ?? '';
        if(!in_array($policy,['preliminar','validar'],true) || !in_array($denominator,['total_acta','candidaturas','seleccionadas'],true)) throw new \InvalidArgumentException('Política inicial o denominador inválido.');
        $photo=Values::integer($data['foto_obligatoria'] ?? 0,0,1);
        $this->repository->transaction(function() use($ctx,$zone,$start,$policy,$denominator,$photo,$data) {
            $this->repository->execute("INSERT IGNORE INTO jornada_config(proceso,organizacion,zona,captura_desde,editado_por,fecha) VALUES(?,?,?,?,?,UTC_TIMESTAMP())",[$ctx->process,$ctx->organization,$zone,$start,$ctx->actor]);
            $before=$ctx->lock('jornada.configurar'); $ctx->broad('jornada.configurar',true); $this->mutable();
            if((int)($data['version'] ?? 1)!==(int)$before['version']) throw new HttpError('La configuración cambió. Recarga antes de guardar.',409);
            $this->repository->execute('UPDATE jornada_config SET zona=?,captura_desde=?,politica_inicial=?,foto_obligatoria=?,denominador=?,editado_por=?,fecha=UTC_TIMESTAMP(),version=version+1 WHERE proceso=?',[$zone,$start,$policy,$photo,$denominator,$ctx->actor,$ctx->process]);
            if(isset($data['fecha_eleccion'])) $this->repository->execute('UPDATE procesos_electorales SET f_eleccion=?,f_actualizacion=NOW() WHERE id=?',[Values::date($data['fecha_eleccion']),$ctx->process]);
            $this->audit('configurar','jornada_config',$ctx->process,$before,['zona'=>$zone,'captura_desde'=>$start,'politica_inicial'=>$policy,'foto_obligatoria'=>$photo,'denominador'=>$denominator]);
        });
        return $this->view();
    }
    public function reference(string $type,mixed $uuid,bool $active=true): ?int
    {
        if($uuid===null || $uuid==='') return null;
        $table=self::CATALOGS[$type];
        return (int)($this->repository->one("SELECT id FROM $table WHERE uuid=? AND proceso=?".($active ? ' AND activo=1' : ''),[Values::binary($uuid),$this->context->process])['id'] ?? throw new HttpError('Referencia electoral no disponible.',404));
    }
    public function catalog(string $type,array $data,?string $uuid=null): array
    {
        $table=self::CATALOGS[$type] ?? throw new \InvalidArgumentException('Catálogo inválido.');
        $ctx=$this->context; $ctx->broad('jornada.configurar',true);
        return $this->repository->transaction(function() use($type,$table,$data,$uuid,$ctx) {
            $ctx->lock('jornada.configurar'); $ctx->broad('jornada.configurar',true); $this->mutable();
            $before=$uuid ? ($this->repository->one("SELECT * FROM $table WHERE uuid=? AND proceso=?",[Values::binary($uuid),$ctx->process]) ?? throw new HttpError('Registro no disponible.',404)) : null;
            $values=['nombre'=>Values::text($data['nombre'] ?? null,180),'activo'=>Values::integer($data['activo'] ?? 1,0,1)];
            if($type==='partidos') {
                $values['siglas']=Values::text($data['siglas'] ?? null,40);
                $values['color']=empty($data['color']) ? null : Values::text($data['color'],7);
                if($values['color']!==null && !preg_match('/^#[a-f0-9]{6}$/i',$values['color'])) throw new \InvalidArgumentException('Color inválido.');
            }
            if($type==='candidaturas') $values['cargo']=Values::text($data['cargo'] ?? null,120);
            $imageField=match($type) {'partidos'=>'logo','candidaturas'=>'foto',default=>null};
            if($imageField && array_key_exists($imageField,$data)) {
                if(!$before) throw new \InvalidArgumentException('Guarda primero el catálogo antes de asociar su imagen.');
                $values[$imageField]=empty($data[$imageField]) ? null : (int)($this->repository->one("SELECT id FROM archivos WHERE uuid=? AND entidad=? AND entidad_id=? AND organizacion=? AND proceso=? AND estado='activo' AND mime_type LIKE 'image/%'",[Values::binary($data[$imageField]),$table,$before['id'],$ctx->organization,$ctx->process])['id'] ?? throw new HttpError('Imagen no disponible para este catálogo.',404));
            }
            if(in_array($type,['coaliciones','opciones'],true)) $values['candidatura']=$this->reference('candidaturas',$data['candidatura'] ?? null);
            if($type==='opciones') {
                $values += ['tipo'=>Values::text($data['tipo'] ?? null,24),'orden'=>Values::integer($data['orden'] ?? null,1,10000), 'partido'=>$this->reference('partidos',$data['partido'] ?? null),'coalicion'=>$this->reference('coaliciones',$data['coalicion'] ?? null),'suma_candidatura'=>Values::integer($data['suma_candidatura'] ?? 0,0,1),'en_denominador'=>Values::integer($data['en_denominador'] ?? 1,0,1),'clasificacion'=>Values::text($data['clasificacion'] ?? 'valido',16)];
                if(!in_array($values['tipo'],['partido','coalicion','combinacion','independiente','nulos','no_registrados','otro'],true) || !in_array($values['clasificacion'],['valido','nulo','otro'],true)) throw new \InvalidArgumentException('Tipo o clasificación inválida.');
                if($values['suma_candidatura'] && !$values['candidatura']) throw new \InvalidArgumentException('Una fila que suma a candidatura requiere una candidatura.');
                if($values['coalicion']) {
                    $coalition=$this->repository->one('SELECT candidatura FROM jornada_coaliciones WHERE id=?',[$values['coalicion']]);
                    if($coalition['candidatura']!==null && (int)$coalition['candidatura']!==(int)$values['candidatura']) throw new \InvalidArgumentException('La candidatura no coincide con la coalición.');
                }
            }
            if($this->repository->one("SELECT id FROM $table WHERE proceso=? AND nombre=? AND id<>?",[$ctx->process,$values['nombre'],$before['id'] ?? 0])) throw new HttpError('Ya existe un registro con ese nombre en esta elección.',409);
            if($type==='opciones' && $this->repository->one('SELECT id FROM jornada_opciones WHERE proceso=? AND orden=? AND id<>?',[$ctx->process,$values['orden'],$before['id'] ?? 0])) throw new HttpError('Ese orden ya está ocupado por otra fila del acta.',409);
            $uuid ??= Values::uuid();
            if($before) $this->repository->execute("UPDATE $table SET ".implode(',',array_map(static fn($key)=>$key.'=?',array_keys($values))).' WHERE id=?',[...array_values($values),$before['id']]);
            else $this->repository->insert("INSERT INTO $table(uuid,organizacion,proceso,".implode(',',array_keys($values)).') VALUES('.implode(',',array_fill(0,count($values)+3,'?')).')',[Values::binary($uuid),$ctx->organization,$ctx->process,...array_values($values)]);
            $id=$this->reference($type,$uuid,false);
            if($type==='coaliciones') {
                $parties=$data['partidos'] ?? []; if(!is_array($parties) || count(array_unique($parties))<2 || count($parties)>30) throw new \InvalidArgumentException('La coalición requiere al menos dos partidos distintos.');
                $ids=array_map(fn($party)=>$this->reference('partidos',$party),array_unique($parties));
                $this->repository->execute('DELETE FROM jornada_coalicion_partidos WHERE coalicion=?',[$id]);
                foreach($ids as $party) $this->repository->execute('INSERT INTO jornada_coalicion_partidos VALUES(?,?,?)',[$id,$party,$ctx->process]);
            }
            $this->audit($before ? 'editar' : 'crear',$table,$id,$before,$values);
            return ['uuid'=>$uuid];
        });
    }
    public function checklist(): array
    {
        $process=$this->context->process;
        $options=$this->repository->rows('SELECT candidatura,suma_candidatura FROM jornada_opciones WHERE proceso=? AND activo=1',[$process]);
        $casillas=(int)$this->repository->one('SELECT COUNT(*) total FROM jornada_casillas WHERE proceso=? AND activo=1',[$process])['total'];
        $unassigned=(int)$this->repository->one('SELECT COUNT(*) total FROM jornada_casillas jc WHERE jc.proceso=? AND jc.activo=1 AND NOT EXISTS(SELECT 1 FROM jornada_capturistas cp JOIN usuarios u ON u.id=cp.usuario JOIN usuarios_procesos up ON up.usuario=u.id AND up.proceso=jc.proceso AND up.activo=1 AND up.f_baja IS NULL WHERE cp.casilla=jc.id AND cp.activo=1 AND u.activo=1)',[$process])['total'];
        $invalid=(int)$this->repository->one('SELECT COUNT(*) total FROM jornada_opciones o LEFT JOIN jornada_partidos p ON p.id=o.partido LEFT JOIN jornada_coaliciones c ON c.id=o.coalicion LEFT JOIN jornada_candidaturas candidate ON candidate.id=o.candidatura WHERE o.proceso=? AND o.activo=1 AND ((o.partido IS NOT NULL AND p.activo=0) OR (o.coalicion IS NOT NULL AND c.activo=0) OR (o.candidatura IS NOT NULL AND candidate.activo=0))',[$process])['total'];
        $covered=[];
        foreach($this->repository->rows('SELECT DISTINCT cp.usuario FROM jornada_capturistas cp JOIN usuarios u ON u.id=cp.usuario JOIN usuarios_procesos up ON up.usuario=u.id AND up.proceso=cp.proceso WHERE cp.proceso=? AND cp.activo=1 AND u.activo=1 AND up.activo=1 AND up.f_baja IS NULL',[$process]) as $user) {
            try { [$where,$params]=$this->context->predicate('resultados.capturar','jc',(int)$user['usuario']);
                foreach($this->repository->rows("SELECT jc.id FROM jornada_casillas jc JOIN jornada_capturistas cp ON cp.casilla=jc.id AND cp.usuario=? AND cp.activo=1 WHERE jc.activo=1 AND ($where)",[$user['usuario'],...$params]) as $booth)$covered[$booth['id']]=true;
            } catch(HttpError) { }
        }
        $permissions=$casillas>0 && count($covered)===$casillas;
        return ['opciones'=>count($options)>0 && $invalid===0,'casillas'=>$casillas>0,'asignaciones'=>$casillas>0 && $unassigned===0,'permisos'=>$permissions,'casillas_sin_capturista'=>$unassigned,'listo'=>count($options)>0 && $invalid===0 && $casillas>0 && $unassigned===0 && $permissions];
    }
    public function state(string $action,array $data): array
    {
        $permission=match($action) {'abrir'=>'jornada.abrir','cerrar'=>'jornada.cerrar','reabrir'=>'jornada.reabrir',default=>throw new \InvalidArgumentException('Acción inválida.')};
        $ctx=$this->context; $ctx->broad($permission,true);
        $this->repository->transaction(function() use($ctx,$permission,$action,$data) {
            $before=$ctx->lock($permission); $ctx->broad($permission,true);
            if((int)($data['version'] ?? 0)!==(int)$before['version']) throw new HttpError('El estado cambió. Recarga la Jornada Electoral.',409);
            $expected=match($action) {'abrir'=>'preparacion','cerrar'=>'abierta','reabrir'=>'cerrada'};
            if($before['estado']!==$expected) throw new HttpError('La transición no corresponde al estado actual.',409);
            if($action!=='cerrar' && !$this->checklist()['listo']) throw new HttpError('Completa el checklist de acta, casillas y capturistas antes de abrir.',409);
            $reason=Values::text($data['motivo'] ?? null,500);
            $state=$action==='cerrar' ? 'cerrada' : 'abierta';
            $field=$action==='cerrar' ? 'cerrado_en' : 'abierto_en';
            $this->repository->execute("UPDATE jornada_config SET estado=?,motivo=?,editado_por=?,fecha=UTC_TIMESTAMP(),version=version+1,$field=UTC_TIMESTAMP() WHERE proceso=?",[$state,$reason,$ctx->actor,$ctx->process]);
            $this->audit($action,'jornada_config',$ctx->process,$before,['estado'=>$state,'motivo'=>$reason]);
            (new Notifications($this->repository))->event('estado','Jornada Electoral '.$state,'Cambio de estado registrado: '.$reason);
        });
        return $this->view();
    }
    public function booths(array $filters): array
    {
        $this->context->broad('jornada.configurar');
        $limit=Pagination::limit($filters['limit'] ?? 25);$offset=Pagination::offset($filters['offset'] ?? 0);
        $search='%'.Values::text($filters['search'] ?? '',150,true).'%';
        $scope=\App\Core\Auth::isSuperAdmin() ? '1=1' : 'EXISTS(SELECT 1 FROM casillas_procesos cp WHERE cp.casilla=c.id AND cp.proceso=? AND cp.activo=1)';$parameters=\App\Core\Auth::isSuperAdmin() ? [] : [$this->context->process];
        $rows=$this->repository->rows('SELECT c.id,c.uuid,c.casilla nombre,c.tipo,s.seccion,s.uuid seccion_uuid FROM casillas c JOIN secciones_electorales s ON s.id=c.seccion WHERE ('.$scope.') AND (CAST(s.seccion AS CHAR) LIKE ? OR c.casilla LIKE ?) ORDER BY s.seccion,c.id LIMIT '.($limit+1).' OFFSET '.$offset,[...$parameters,$search,$search]);
        foreach($rows as &$row) { $row['uuid']=$row['uuid']===null ? null : Values::uuidText($row['uuid']); $row['seccion_uuid']=Values::uuidText($row['seccion_uuid']);$row['label']='Sección '.$row['seccion'].' · '.$row['nombre'].' ('.$row['tipo'].')'; } unset($row);
        return ['rows'=>array_slice($rows,0,$limit),'pagination'=>Pagination::response($rows,$limit,$offset)];
    }
    public function associate(array $data): array
    {
        $ctx=$this->context;$ctx->broad('jornada.configurar',true);
        return $this->repository->transaction(function() use($ctx,$data) {
            $ctx->lock('jornada.configurar');$ctx->broad('jornada.configurar',true);$this->mutable();
            if(isset($data['fisica'])) {
                $numeric=is_int($data['fisica']) || (is_string($data['fisica']) && ctype_digit($data['fisica']));
                $physical=$this->repository->one('SELECT id FROM casillas WHERE '.($numeric ? 'id' : 'uuid').'=? FOR UPDATE',[$numeric ? Values::integer($data['fisica']) : Values::binary($data['fisica'])]) ?? throw new HttpError('Casilla física no disponible.',404);
                $id=(int)$physical['id'];
                if(!\App\Core\Auth::isSuperAdmin() && !$this->repository->one('SELECT casilla FROM casillas_procesos WHERE casilla=? AND proceso=? AND activo=1',[$id,$ctx->process]))throw new HttpError('Casilla no disponible en esta elección.',404);
            } else {
                $section=$this->repository->one('SELECT id FROM secciones_electorales WHERE uuid=? FOR UPDATE',[Values::binary($data['seccion'] ?? null)]) ?? throw new HttpError('Sección no disponible.',404);
                (new \App\Services\GeographyService($this->repository))->assertSection((int)$section['id']);
                $name=Values::text($data['nombre'] ?? null,60);$type=(new \App\Services\GeographyService($this->repository))->boothType($data['tipo'] ?? null);
                $physical=$this->repository->one('SELECT id FROM casillas WHERE seccion=? AND casilla=? AND tipo=? FOR UPDATE',[$section['id'],$name,$type]);
                $id=$physical ? (int)$physical['id'] : $this->repository->insert('INSERT INTO casillas(uuid,seccion,tipo,casilla,ubicacion,domicilio,registro,f_registro) VALUES(?,?,?,?,?,?,?,NOW())',[random_bytes(16),$section['id'],$type,$name,Values::text($data['ubicacion'] ?? '',255,true),Values::text($data['domicilio'] ?? '',255,true),$ctx->actor]);
            }
            $physicalSection=(int)$this->repository->one('SELECT seccion FROM casillas WHERE id=?',[$id])['seccion'];(new \App\Services\GeographyService($this->repository))->attachBooth($id,$physicalSection,$ctx->process);
            $existing=$this->repository->one('SELECT id,uuid FROM jornada_casillas WHERE proceso=? AND casilla=?',[$ctx->process,$id]);
            if($existing) return ['uuid'=>Values::uuidText($existing['uuid'])];
            $uuid=Values::uuid();
            $link=$this->repository->insert('INSERT INTO jornada_casillas(uuid,organizacion,proceso,casilla) VALUES(?,?,?,?)',[Values::binary($uuid),$ctx->organization,$ctx->process,$id]);
            $this->audit('asociar','jornada_casillas',$link,null,['casilla'=>$id]);
            return ['uuid'=>$uuid];
        });
    }
    public function assign(array $data): array
    {
        $ctx = $this->context;
        $ctx->broad('jornada.asignar_capturistas', true);
        $casillas = $data['casillas'] ?? [];
        $users = $data['usuarios'] ?? [];
        if (!is_array($casillas) || !is_array($users) || !$casillas || !$users
            || count($casillas) > 100 || count($users) > 100) {
            throw new \InvalidArgumentException('Selecciona entre una y cien casillas y cuentas.');
        }
        $active = Values::integer($data['activo'] ?? 1, 0, 1);

        return $this->repository->transaction(function () use ($ctx, $casillas, $users, $active) {
            $ctx->lock('jornada.asignar_capturistas');
            $ctx->broad('jornada.asignar_capturistas', true);
            $count = 0;
            foreach (array_unique($users) as $uuid) {
                $user = $this->repository->one(
                    'SELECT u.id FROM usuarios u JOIN usuarios_procesos up ON up.usuario=u.id
                     WHERE u.uuid=? AND u.organizacion=? AND u.activo=1
                       AND up.proceso=? AND up.activo=1 AND up.f_baja IS NULL FOR UPDATE',
                    [Values::binary($uuid), $ctx->organization, $ctx->process]
                ) ?? throw new HttpError('La cuenta requiere acceso activo a esta elección.', 404);

                // La autorización de asignar corresponde al actor autenticado.
                // Captures conserva la validación del permiso y alcance del capturista al capturar.
                foreach (array_unique($casillas) as $booth) {
                    $casilla = $ctx->casilla($booth, 'jornada.asignar_capturistas');
                    $before = $this->repository->one(
                        'SELECT * FROM jornada_capturistas WHERE casilla=? AND usuario=?',
                        [$casilla['id'], $user['id']]
                    );
                    $this->repository->execute(
                        'INSERT INTO jornada_capturistas
                            (uuid,organizacion,proceso,casilla,usuario,activo,asignado_por,fecha,retirado_en)
                         VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(),IF(?=0,UTC_TIMESTAMP(),NULL))
                         ON DUPLICATE KEY UPDATE activo=VALUES(activo),asignado_por=VALUES(asignado_por),
                            retirado_en=VALUES(retirado_en)',
                        [random_bytes(16), $ctx->organization, $ctx->process, $casilla['id'],
                            $user['id'], $active, $ctx->actor, $active]
                    );
                    $id = (int) $this->repository->one(
                        'SELECT id FROM jornada_capturistas WHERE casilla=? AND usuario=?',
                        [$casilla['id'], $user['id']]
                    )['id'];
                    $this->audit($active ? 'asignar' : 'retirar', 'jornada_capturistas', $id, $before, [
                        'usuario' => $user['id'],
                        'casilla' => $casilla['id'],
                        'activo' => $active,
                    ]);
                    $count++;
                }
            }
            return ['asignaciones' => $count];
        });
    }
}
