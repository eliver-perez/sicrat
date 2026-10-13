<?php

declare(strict_types=1);

namespace App\Services\Jornada;

use App\Core\HttpError;
use App\Core\Pagination;
use App\Repositories\CommonRepository;
use App\Services\Common\ActorContext;
use App\Services\Common\NotificationService;
use App\Services\Common\QueueService;
use App\Services\Common\Values;

class Notifications
{
    public function __construct(private CommonRepository $repository) {}
    public function event(string $type,string $title,string $summary,array $recipients=[],?string $booth=null): void
    {
        $ctx=new Context($this->repository);
        $permission=$booth ? 'resultados.validar' : 'jornada.configurar';
        $forced=$recipients ? ' OR u.id IN('.implode(',',array_fill(0,count($recipients),'?')).')' : '';
        $users=$this->repository->rows("SELECT u.id FROM usuarios u JOIN usuarios_tipos t ON t.id=u.tipo_usuario WHERE u.activo=1 AND (t.codigo='superadmin' OR (u.organizacion=? AND EXISTS(SELECT 1 FROM usuarios_procesos up WHERE up.usuario=u.id AND up.proceso=? AND up.activo=1 AND up.f_baja IS NULL AND (EXISTS(SELECT 1 FROM permisos_usuarios_tipo rp WHERE rp.tipo=up.tipo_usuario AND rp.permiso=? AND rp.valor=1 AND (rp.proceso IS NULL OR rp.proceso=up.proceso)) OR EXISTS(SELECT 1 FROM permisos_usuarios individual WHERE individual.usuario=u.id AND individual.organizacion=u.organizacion AND individual.permiso=? AND individual.valor=1 AND (individual.proceso IS NULL OR individual.proceso=up.proceso)) $forced))))",[$ctx->organization,$ctx->process,$permission,$permission,...$recipients]);
        foreach($users as $user) {
            $id=(int)$user['id'];
            $authorized=in_array($id,$recipients,true);
            if(!$authorized) {
                try {
                    $effective=$ctx->require($permission,false,$id);
                    if($booth) { [$where,$params]=$ctx->predicate($permission,'jc',$id);$authorized=(bool)$this->repository->one("SELECT id FROM jornada_casillas jc WHERE jc.uuid=? AND ($where)",[Values::binary($booth),...$params]); }
                    else $authorized=$effective['super'] || (bool)array_intersect(['organizacion','eleccion'],$effective['scopes'][$permission] ?? []);
                } catch(HttpError) { }
            }
            if($authorized) (new NotificationService($this->repository))->internal($id,$ctx->organization,$ctx->process,'jornada.'.$type,$title,$summary,'jornada',$booth,'/jornada/'.($booth ? '?casilla='.$booth : ''));
        }
    }
    public function settings(array $filters=[],?array $data=null): array
    {
        $ctx=new Context($this->repository);$ctx->broad('jornada.configurar',$data!==null);$ctx->broad('notificaciones.administrar',$data!==null);
        if($data!==null) {
            $user=$this->repository->one('SELECT id,persona FROM usuarios WHERE uuid=? AND organizacion=? AND activo=1',[Values::binary($data['usuario'] ?? null),$ctx->organization]) ?? throw new HttpError('Destinatario administrativo no disponible.',404);
            if(!$user['persona']) throw new \InvalidArgumentException('El destinatario requiere una persona vinculada y un canal con consentimiento.');
            $channel=$data['canal'] ?? '';$mode=$data['modo'] ?? '';
            if(!in_array($channel,['email','whatsapp'],true) || !in_array($mode,['preliminar','validado'],true)) throw new \InvalidArgumentException('Canal o modo inválido.');
            $include=[];foreach(['incluir_totales','incluir_avance','incluir_estado'] as $key)$include[$key]=Values::integer($data[$key] ?? 1,0,1);if(!array_sum($include))throw new \InvalidArgumentException('Selecciona al menos un contenido para el resumen.');
            $frequency=Values::integer($data['frecuencia'] ?? null,5,1440);$active=Values::integer($data['activo'] ?? 1,0,1);
            $this->recipient($ctx,(int)$user['id'],$mode);
            $template=$channel==='whatsapp' ? Values::text($data['plantilla'] ?? null,128) : null;
            $language=$channel==='whatsapp' ? Values::text($data['idioma'] ?? 'es_MX',12) : null;
            $this->repository->transaction(function() use($ctx,$user,$channel,$mode,$frequency,$active,$template,$language,$include) {
                $ctx->lock('jornada.configurar');$ctx->broad('notificaciones.administrar',true);$this->recipient($ctx,(int)$user['id'],$mode);
                $this->repository->execute('INSERT INTO jornada_avisos(uuid,organizacion,proceso,usuario,canal,modo,frecuencia,plantilla,idioma,activo,creado_por,incluir_totales,incluir_avance,incluir_estado) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE modo=VALUES(modo),frecuencia=VALUES(frecuencia),incluir_totales=VALUES(incluir_totales),incluir_avance=VALUES(incluir_avance),incluir_estado=VALUES(incluir_estado),plantilla=VALUES(plantilla),idioma=VALUES(idioma),activo=VALUES(activo),creado_por=VALUES(creado_por),version=version+1',[random_bytes(16),$ctx->organization,$ctx->process,$user['id'],$channel,$mode,$frequency,$template,$language,$active,$ctx->actor,...array_values($include)]);
                (new \App\Services\Common\AuditService($this->repository))->record($ctx->organization,$ctx->process,'configurar','jornada_avisos',$ctx->process,null,['usuario'=>$user['id'],'canal'=>$channel,'modo'=>$mode,'frecuencia'=>$frequency,'activo'=>$active]);
            });
        }
        $limit=Pagination::limit($filters['limit'] ?? 25);$offset=Pagination::offset($filters['offset'] ?? 0);
        $rows=$this->repository->rows('SELECT a.*,u.nombre FROM jornada_avisos a JOIN usuarios u ON u.id=a.usuario WHERE a.proceso=? ORDER BY a.id LIMIT '.($limit+1).' OFFSET '.$offset,[$ctx->process]);
        foreach($rows as &$row) $row['uuid']=Values::uuidText($row['uuid']);unset($row);
        return ['rows'=>array_slice($rows,0,$limit),'pagination'=>Pagination::response($rows,$limit,$offset)];
    }
    private function recipient(Context $ctx,int $user,string $mode): void
    {
        foreach($mode==='preliminar' ? ['resultados.ver','resultados.ver_preliminares'] : ['resultados.ver'] as $permission) {
            $effective=$ctx->require($permission,false,$user);
            if(!$effective['super'] && !array_intersect(['organizacion','eleccion'],$effective['scopes'][$permission] ?? [])) throw new HttpError('El resumen externo de elección requiere un destinatario autorizado con alcance de elección.',403);
        }
    }
    public function schedule(?\DateTimeImmutable $now=null): int
    {
        $now ??= new \DateTimeImmutable();$count=0;
        foreach($this->repository->rows("SELECT a.* FROM jornada_avisos a JOIN jornada_config c ON c.proceso=a.proceso WHERE a.activo=1 AND c.estado='abierta'") as $setting) {
            $slot=(int)floor($now->getTimestamp()/((int)$setting['frecuencia']*60));
            (new QueueService($this->repository))->enqueue('jornada_resumen',['aviso'=>(int)$setting['id'],'slot'=>$slot],(int)$setting['organizacion'],(int)$setting['proceso'],(int)$setting['creado_por'],'jornada:'.$setting['id'].':'.$slot);$count++;
        }
        return $count;
    }
    public function prepare(int $id,int $slot): void
    {
        $setting=$this->repository->one('SELECT a.*,u.persona FROM jornada_avisos a JOIN usuarios u ON u.id=a.usuario AND u.activo=1 WHERE a.id=? AND a.activo=1',[$id]) ?? throw new HttpError('Aviso administrativo deshabilitado.',403);
        (new ActorContext($this->repository))->run((int)$setting['creado_por'],(int)$setting['proceso'],function() use($setting,$slot) {
            $ctx=new Context($this->repository);$ctx->broad('jornada.configurar',true);$ctx->broad('notificaciones.administrar',true);$this->recipient($ctx,(int)$setting['usuario'],$setting['modo']);
            $summary=(new Queries($this->repository))->summary(['modo'=>$setting['modo']]);
            $totals=array_map(static fn($row)=>$row['nombre'].': '.$row['votos'],array_slice($summary['candidaturas'],0,8));
            $progress=$summary['progreso'];
            $body=$summary['leyenda'];
            if((int)$setting['incluir_estado'])$body.=' Estado de Jornada: '.($this->repository->one('SELECT estado FROM jornada_config WHERE proceso=?',[$ctx->process])['estado']).'.';
            if((int)$setting['incluir_avance'])$body.=' Casillas capturadas: '.$progress['capturadas'].'/'.$progress['esperadas'].'. Validadas: '.$progress['validadas'].'.';
            if((int)$setting['incluir_totales'])$body.=' Total de acta: '.$summary['total_acta'].'. '.implode('; ',$totals).(count($summary['candidaturas'])>8 ? '; consulta el informe para las demás candidaturas' : '').'.';
            $body.=' Actualización UTC: '.gmdate('Y-m-d H:i:s').'.';
            $content=$setting['canal']==='email' ? ['title'=>'SICRAT · Resumen administrativo '.$setting['modo'],'summary'=>$body] : ['language'=>$setting['idioma'],'parameters'=>[mb_substr($body,0,500)]];
            $this->repository->transaction(function() use($ctx,$setting,$slot,$content) {
                $envio=(new NotificationService($this->repository))->outgoing((int)$setting['persona'],$ctx->organization,$ctx->process,$setting['canal'],$content,'jornada:'.$setting['id'].':'.$slot,$setting['plantilla']);
                $this->repository->execute('INSERT IGNORE INTO jornada_envios(envio,aviso,modo,version) VALUES(?,?,?,?)',[$envio,$setting['id'],$setting['modo'],$setting['version']]);
            });
        });
    }
    public function authorizeDelivery(string $delivery): void
    {
        $setting=$this->repository->one('SELECT a.*,e.usuario emisor,j.modo enviado_modo,j.version aviso_version,u.persona persona_actual,e.persona persona_enviada,u.activo cuenta_activa FROM jornada_envios j JOIN jornada_avisos a ON a.id=j.aviso JOIN usuarios u ON u.id=a.usuario JOIN notificaciones_envios e ON e.id=j.envio WHERE j.envio=?',[$delivery]);
        if(!$setting) return;
        (new ActorContext($this->repository))->run((int)$setting['emisor'],(int)$setting['proceso'],function() use($setting) {
            $ctx=new Context($this->repository);$ctx->broad('jornada.configurar',true);$ctx->broad('notificaciones.administrar',true);
            if(!(int)$setting['activo'] || !(int)$setting['cuenta_activa'] || $setting['persona_actual']!==$setting['persona_enviada'] || $setting['enviado_modo']!==$setting['modo'] || (int)$setting['version']!==(int)$setting['aviso_version']) throw new HttpError('El aviso o destinatario cambió después de programar el resumen.',403);
            $this->recipient($ctx,(int)$setting['usuario'],$setting['modo']);
        });
    }
}
