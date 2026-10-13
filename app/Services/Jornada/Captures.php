<?php

declare(strict_types=1);

namespace App\Services\Jornada;

use App\Core\HttpError;
use App\Repositories\CommonRepository;
use App\Services\Common\AuditService;
use App\Services\Common\FileService;
use App\Services\Common\Values;

class Captures
{
    private Context $context;
    public function __construct(private CommonRepository $repository) { $this->context=new Context($repository); }
    private function booth(string $uuid): array
    {
        $ctx=$this->context;
        return $this->repository->one('SELECT * FROM jornada_casillas WHERE uuid=? AND proceso=? FOR UPDATE',[Values::binary($uuid),$ctx->process]) ?? throw new HttpError('Casilla no disponible.',404);
    }
    private function key(mixed $value): string
    {
        $key=Values::text($value,80);
        if(!preg_match('/^[a-zA-Z0-9:_-]+$/D',$key)) throw new \InvalidArgumentException('Clave de operación inválida.');
        return $key;
    }
    public function draft(array $data): array
    {
        $ctx=$this->context; $permission=!empty($data['correccion']) ? 'resultados.corregir' : 'resultados.capturar'; $ctx->require($permission,true); $key=$this->key($data['clave'] ?? null);
        return $this->repository->transaction(function() use($ctx,$permission,$data,$key) {
            $config=$ctx->lock($permission);$ctx->open($config);
            $booth=$this->booth($data['casilla'] ?? '');
            if($permission==='resultados.corregir') $ctx->casilla($data['casilla'],$permission); else $ctx->captureAllowed($booth);
            $before=$this->repository->one('SELECT * FROM jornada_borradores WHERE proceso=? AND usuario=? AND clave=?',[$ctx->process,$ctx->actor,$key]);
            if($before) {
                if((int)$before['casilla']!==(int)$booth['id']) throw new HttpError('La clave ya corresponde a otra casilla.',409);
                return ['uuid'=>Values::uuidText($before['uuid'])];
            }
            $uuid=Values::uuid();$this->repository->insert('INSERT INTO jornada_borradores(uuid,organizacion,proceso,casilla,usuario,fecha,clave) VALUES(?,?,?,?,?,UTC_TIMESTAMP(),?)',[Values::binary($uuid),$ctx->organization,$ctx->process,$booth['id'],$ctx->actor,$key]);
            return ['uuid'=>$uuid];
        });
    }
    private function result(array $booth): array
    {
        $ctx=$this->context;
        $this->repository->execute('INSERT IGNORE INTO jornada_resultados(uuid,organizacion,proceso,casilla) VALUES(?,?,?,?)',[random_bytes(16),$ctx->organization,$ctx->process,$booth['id']]);
        return $this->repository->one('SELECT * FROM jornada_resultados WHERE casilla=? FOR UPDATE',[$booth['id']]);
    }
    private function expected(array $result,array $data): void
    {
        if(!array_key_exists('actual_esperada',$data)) throw new \InvalidArgumentException('Se requiere la versión vigente revisada, incluso cuando no existe ninguna.');
        $actual=$result['actual_version']===null ? null : $this->repository->one('SELECT uuid FROM jornada_versiones WHERE id=? FOR UPDATE',[$result['actual_version']])['uuid'];
        $expected=$data['actual_esperada']===null ? null : Values::binary($data['actual_esperada']);
        if($actual!==$expected) throw new HttpError('La versión vigente cambió. Recarga y compara antes de decidir.',409);
    }
    private function photo(array $data,array $booth,?array $draft,bool $correction): ?int
    {
        if(empty($data['foto'])) return null;
        $photo=$this->repository->one("SELECT a.*,b.casilla,b.usuario,b.estado borrador_estado FROM archivos a JOIN jornada_borradores b ON a.entidad='jornada_borradores' AND b.id=a.entidad_id WHERE a.uuid=? AND a.organizacion=? AND a.proceso=? AND a.estado='activo'",[Values::binary($data['foto']),$this->context->organization,$this->context->process]) ?? throw new HttpError('Acta no disponible.',404);
        if((int)$photo['casilla']!==(int)$booth['id'] || !str_starts_with($photo['mime_type'],'image/')) throw new HttpError('El acta debe pertenecer a esta casilla.',404);
        if(!$correction && (!$draft || (int)$photo['entidad_id']!==(int)$draft['id'] || (int)$photo['subido_por']!==$this->context->actor)) throw new HttpError('El acta no corresponde al borrador del capturista.',403);
        if($correction) (new FileService($this->repository))->authorized($data['foto'],'normal',false);
        return (int)$photo['id'];
    }
    private function diffs(int $version,?int $old): array
    {
        $before=$old ? array_column($this->repository->rows('SELECT opcion,votos FROM jornada_votos WHERE version=?',[$old]),'votos','opcion') : [];
        $changes=[];
        foreach($this->repository->rows('SELECT v.opcion,o.nombre,v.votos FROM jornada_votos v JOIN jornada_opciones o ON o.id=v.opcion WHERE v.version=? ORDER BY o.orden',[$version]) as $row) {
            $previous=(int)($before[$row['opcion']] ?? 0);$value=(int)$row['votos'];
            if($previous!==$value) $changes[]=['opcion'=>$row['nombre'],'antes'=>$previous,'despues'=>$value,'diferencia'=>$value-$previous];
        }
        return $changes;
    }
    private function publish(array $result,int $version,string $state): void
    {
        if($result['actual_version']!==null && (int)$result['actual_version']!==$version) $this->repository->execute("UPDATE jornada_versiones SET estado='sustituida' WHERE id=?",[$result['actual_version']]);
        $this->repository->execute('UPDATE jornada_versiones SET estado=? WHERE id=?',[$state,$version]);
        $this->repository->execute('UPDATE jornada_resultados SET actual_version=?,revision=revision+1 WHERE id=?',[$version,$result['id']]);
    }
    public function submit(array $data,bool $correction=false): array
    {
        $ctx=$this->context;$permission=$correction ? 'resultados.corregir' : 'resultados.capturar';$ctx->require($permission,true);
        $key=$this->key($data['clave'] ?? null);
        return $this->repository->transaction(function() use($ctx,$permission,$data,$key,$correction) {
            $config=$ctx->lock($permission);$ctx->open($config);
            $booth=$this->booth($data['casilla'] ?? '');
            if($correction) $ctx->casilla($data['casilla'],$permission); else $ctx->captureAllowed($booth);
            $result=$this->result($booth);
            $options=$this->repository->rows('SELECT id,uuid FROM jornada_opciones WHERE proceso=? AND activo=1 ORDER BY orden',[$ctx->process]);
            $votes=Votes::exact($options,$data['votos'] ?? null);$total=array_sum($votes);
            $note=Values::text($data['notas'] ?? '',2000,true);
            $reason=$correction ? Values::text($data['motivo'] ?? null,500) : Values::text($data['motivo'] ?? '',500,true);
            $draft=null;
            if(!$correction || !empty($data['borrador'])) $draft=$this->repository->one('SELECT * FROM jornada_borradores WHERE uuid=? AND proceso=? AND usuario=? FOR UPDATE',[Values::binary($data['borrador'] ?? null),$ctx->process,$ctx->actor]) ?? throw new HttpError('Borrador no disponible.',404);
            if($draft && (int)$draft['casilla']!==(int)$booth['id']) throw new HttpError('El borrador pertenece a otra casilla.',404);
            $photo=$this->photo($data,$booth,$draft,$correction);
            if((int)$config['foto_obligatoria'] && !$photo) throw new \InvalidArgumentException('Esta elección exige una fotografía del acta.');
            $validate=$correction && Values::integer($data['validar'] ?? 0,0,1)===1;
            $signature=hash('sha256',Values::json([$booth['id'],$votes,$photo,$note,$reason,$correction,$validate]));
            $previous=$this->repository->one('SELECT * FROM jornada_versiones WHERE proceso=? AND usuario=? AND clave=? FOR UPDATE',[$ctx->process,$ctx->actor,$key]);
            if($previous) {
                if(!hash_equals($previous['firma'],$signature)) throw new HttpError('La clave de operación ya se utilizó con otro contenido.',409);
                return ['uuid'=>Values::uuidText($previous['uuid']),'estado'=>$previous['estado'],'numero'=>(int)$previous['numero'],'total'=>(int)$previous['total'],'repetida'=>true];
            }
            if($draft && $draft['estado']!=='abierto') throw new HttpError('Este borrador ya fue enviado; crea una captura nueva.',409);
            if($correction) { $this->expected($result,$data); if($validate) $ctx->casilla($data['casilla'],'resultados.validar'); }
            $number=(int)$result['consecutivo']+1;$uuid=Values::uuid();
            $initial=$number===1 && !$correction;
            $state=$initial && $config['politica_inicial']==='preliminar' ? 'preliminar' : 'pendiente';
            if($validate) $state='validada';
            $id=$this->repository->insert('INSERT INTO jornada_versiones(uuid,organizacion,proceso,resultado,numero,usuario,fecha,origen,estado,base_version,borrador,foto,notas,motivo,total,firma,clave) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(),?,?,?,?,?,?,?,?,?,?)',[Values::binary($uuid),$ctx->organization,$ctx->process,$result['id'],$number,$ctx->actor,$correction ? 'correccion' : 'captura',$state,$result['actual_version'],$draft['id'] ?? null,$photo,$note,$reason,$total,$signature,$key]);
            foreach($votes as $option=>$value) $this->repository->execute('INSERT INTO jornada_votos(version,opcion,votos,proceso) VALUES(?,?,?,?)',[$id,$option,$value,$ctx->process]);
            $this->repository->execute('UPDATE jornada_resultados SET consecutivo=?,revision=revision+1 WHERE id=?',[$number,$result['id']]);
            if($draft) $this->repository->execute("UPDATE jornada_borradores SET estado='enviado' WHERE id=?",[$draft['id']]);
            if(in_array($state,['preliminar','validada'],true)) $this->publish($result,$id,$state);
            $diffs=$this->diffs($id,$result['actual_version']===null ? null : (int)$result['actual_version']);
            if($validate) $this->decision($result,$id,'aprobar',$reason,$diffs,$id);
            (new AuditService($this->repository))->record($ctx->organization,$ctx->process,$correction ? 'corregir' : 'capturar','jornada_versiones',$id,null,['numero'=>$number,'estado'=>$state,'total'=>$total,'foto'=>$photo,'motivo'=>$reason],['cambios'=>$diffs]);
            (new Notifications($this->repository))->event($initial ? 'captura' : 'propuesta',$state==='pendiente' ? 'Acta pendiente de validación' : 'Acta recibida','Sección y casilla con una nueva versión de resultados; estado: '.$state.'.',[$ctx->actor],$data['casilla']);
            return ['uuid'=>$uuid,'numero'=>$number,'estado'=>$state,'total'=>$total,'segunda'=>!$initial,'repetida'=>false];
        });
    }
    private function decision(array $result,int $version,string $action,string $reason,array $diffs,?int $new): void
    {
        $ctx=$this->context;
        $this->repository->execute('INSERT INTO jornada_decisiones(uuid,organizacion,proceso,resultado,version_resultado,usuario,accion,motivo,anterior,nueva,cambios,fecha) VALUES(?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())',[random_bytes(16),$ctx->organization,$ctx->process,$result['id'],$version,$ctx->actor,$action,$reason,$result['actual_version'],$new,Values::json($diffs)]);
    }
    public function decide(string $uuid,array $data): array
    {
        $ctx=$this->context;$ctx->require('resultados.validar',true);$reason=Values::text($data['motivo'] ?? null,500);
        $approved=Values::integer($data['aprobar'] ?? null,0,1)===1;
        return $this->repository->transaction(function() use($uuid,$data,$approved,$reason,$ctx) {
            // Validar pendientes también después del cierre es una decisión explícita autorizada.
            $ctx->lock('resultados.validar');
            $version=$this->repository->one('SELECT * FROM jornada_versiones WHERE uuid=? AND proceso=? FOR UPDATE',[Values::binary($uuid),$ctx->process]) ?? throw new HttpError('Versión no disponible.',404);
            $result=$this->repository->one('SELECT * FROM jornada_resultados WHERE id=? FOR UPDATE',[$version['resultado']]);
            $booth=$this->repository->one('SELECT uuid FROM jornada_casillas WHERE id=?',[$result['casilla']]);$ctx->casilla(Values::uuidText($booth['uuid']),'resultados.validar');
            if(!in_array($version['estado'],['pendiente','preliminar'],true)) throw new HttpError('Esta versión ya fue resuelta o sustituida.',409);
            $this->expected($result,$data);
            if($version['estado']==='preliminar' && (int)$result['actual_version']!==(int)$version['id']) throw new HttpError('La versión preliminar ya no está vigente.',409);
            $diffs=$this->diffs((int)$version['id'],$result['actual_version']===null ? null : (int)$result['actual_version']);
            if($approved) $this->publish($result,(int)$version['id'],'validada');
            else {
                // Rechazar una preliminar vigente exige una corrección: nunca se elimina su contribución a escondidas.
                if($version['estado']==='preliminar') throw new HttpError('Para cambiar la preliminar vigente crea una corrección; puedes rechazar propuestas pendientes.',409);
                $this->repository->execute("UPDATE jornada_versiones SET estado='rechazada' WHERE id=?",[$version['id']]);
            }
            $this->decision($result,(int)$version['id'],$approved ? 'aprobar' : 'rechazar',$reason,$diffs,$approved ? (int)$version['id'] : ($result['actual_version']===null ? null : (int)$result['actual_version']));
            (new AuditService($this->repository))->record($ctx->organization,$ctx->process,$approved ? 'validar' : 'rechazar','jornada_versiones',(int)$version['id'],['estado'=>$version['estado']],['estado'=>$approved ? 'validada' : 'rechazada','motivo'=>$reason],['cambios'=>$diffs]);
            (new Notifications($this->repository))->event('decision','Revisión de acta resuelta','Versión '.$version['numero'].' '.($approved ? 'validada' : 'rechazada').'.',[(int)$version['usuario']],Values::uuidText($booth['uuid']));
            return ['uuid'=>$uuid,'estado'=>$approved ? 'validada' : 'rechazada'];
        });
    }
}
