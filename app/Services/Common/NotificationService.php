<?php

declare(strict_types=1);

namespace App\Services\Common;

use App\Core\Auth;
use App\Core\HttpError;
use App\Core\Pagination;
use App\Repositories\CommonRepository;
use App\Repositories\ScopeRepository;

class NotificationService
{
	public function __construct(private CommonRepository $repository) {}
	public function internal(int $recipient,?int $organization,?int $process,string $type,string $title,string $summary,?string $entity = null,?string $reference = null,?string $url = null): string
	{
		$user=$this->repository->one('SELECT u.organizacion,t.codigo FROM usuarios u JOIN usuarios_tipos t ON t.id=u.tipo_usuario WHERE u.id=? AND u.activo=1',[$recipient]) ?? throw new \InvalidArgumentException('Destinatario inválido.');
		if ($user['codigo']!=='superadmin' && ($organization===null || (int) $user['organizacion']!==$organization)) throw new \InvalidArgumentException('Destinatario de otra organización.');
		if ($process!==null && !$this->repository->one('SELECT id FROM procesos_electorales WHERE id=? AND organizacion=?',[$process,$organization])) throw new \InvalidArgumentException('Contexto de notificación inválido.');
		if ($url!==null && (!preg_match('#^/[a-zA-Z0-9/_?=&%.-]*$#D',$url) || str_starts_with($url,'//') || str_contains(rawurldecode($url),'..') || preg_match('/[\x00-\x20\\\\]/',rawurldecode($url)))) throw new \InvalidArgumentException('Enlace interno inválido.');
		$uuid=Values::uuid();
		$this->repository->execute('INSERT INTO notificaciones (uuid,usuario,organizacion,proceso,tipo,titulo,resumen,entidad,referencia,enlace,fecha) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())',[Values::binary($uuid),$recipient,$organization,$process,Values::text($type,80),Values::text($title,180),Values::text($summary,4000,true),$entity===null ? null : Values::text($entity,80),$reference===null ? null : Values::text($reference,190),$url]);
		return $uuid;
	}
	private function ownCondition(): array
	{
		$actor=(new Access($this->repository))->actor();
		if (Auth::isSuperAdmin()) return ['usuario=?',[$actor]];
		return ['usuario=? AND organizacion=? AND (proceso IS NULL OR EXISTS (SELECT 1 FROM usuarios_procesos up JOIN procesos_electorales pe ON pe.id=up.proceso WHERE up.usuario=notificaciones.usuario AND up.proceso=notificaciones.proceso AND up.activo=1 AND up.f_baja IS NULL AND pe.estatus=1 AND pe.organizacion=notificaciones.organizacion))',[$actor,Auth::organizationId()]];
	}
	public function listing(array $filters): array
	{
		[$where,$params]=$this->ownCondition();
		if (($filters['unread'] ?? '')==='1') $where.=' AND leida_en IS NULL';
		$limit=Pagination::limit($filters['limit'] ?? 25); $offset=Pagination::offset($filters['offset'] ?? 0);
		$rows=$this->repository->rows("SELECT uuid,tipo,titulo,resumen,entidad,referencia,enlace,fecha,leida_en FROM notificaciones WHERE $where ORDER BY id DESC LIMIT ".($limit+1).' OFFSET '.$offset,$params);
		foreach ($rows as &$row) $row['uuid']=Values::uuidText($row['uuid']);
		return ['rows'=>array_slice($rows,0,$limit),'pagination'=>Pagination::response($rows,$limit,$offset)];
	}
	public function read(array $uuids,bool $all = false): int
	{
		[$where,$params]=$this->ownCondition();
		if (!$all) {
			if (!$uuids || count($uuids)>100) throw new \InvalidArgumentException('Selecciona entre una y cien notificaciones.');
			$where.=' AND uuid IN ('.implode(',',array_fill(0,count($uuids),'?')).')'; $params=[...$params,...array_map([Values::class,'binary'],$uuids)];
		}
		return $this->repository->execute("UPDATE notificaciones SET leida_en=COALESCE(leida_en,NOW()) WHERE $where",$params);
	}
	public function configuration(int $organization,?array $data = null): array
	{
		$effective=(new Access($this->repository))->broad($organization,Auth::processId(),'notificaciones.administrar');
		if (!$effective['super'] && !in_array('organizacion',$effective['scopes']['notificaciones.administrar'],true)) throw new HttpError('La configuración requiere alcance de organización.',403);
		$before=$this->repository->one('SELECT email_habilitado,whatsapp_habilitado FROM notificaciones_config WHERE organizacion=?',[$organization]) ?? ['email_habilitado'=>0,'whatsapp_habilitado'=>0];
		if ($data!==null) $this->repository->transaction(function () use ($organization,$data,$before) {
			$after=['email_habilitado'=>Values::integer($data['email'] ?? null,0,1),'whatsapp_habilitado'=>Values::integer($data['whatsapp'] ?? null,0,1)];
			$this->repository->execute('INSERT INTO notificaciones_config (organizacion,email_habilitado,whatsapp_habilitado,editado_por,fecha) VALUES (?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE email_habilitado=VALUES(email_habilitado),whatsapp_habilitado=VALUES(whatsapp_habilitado),editado_por=VALUES(editado_por),fecha=NOW()',[$organization,...array_values($after),Auth::id()]);
			(new AuditService($this->repository))->record($organization,null,'configurar','notificaciones',$organization,$before,$after);
		});
		return $this->repository->one('SELECT email_habilitado,whatsapp_habilitado FROM notificaciones_config WHERE organizacion=?',[$organization]) ?? $before;
	}
	private function contact(int $person,string $channel,int $organization): string
	{
		$type=$channel==='email' ? 'EMAIL' : 'WHATSAPP';
		// No se deduce opt-in del teléfono personal. El canal requiere consentimiento fechado y verificación.
		$contact=$this->repository->one('SELECT c.valor FROM canales_contacto c JOIN canales_contacto_tipos t ON t.id=c.tipo JOIN personas p ON p.id=c.persona WHERE c.persona=? AND p.organizacion=? AND t.codigo=? AND t.activo=1 AND c.permite_contacto=1 AND c.verificado=1 AND c.f_consentimiento IS NOT NULL ORDER BY c.principal DESC,c.id DESC LIMIT 1',[$person,$organization,$type]);
		if (!$contact) throw new ServiceFailure('Falta un canal verificado con consentimiento vigente.',false);
		$revocation=$this->repository->one("SELECT c.otorgado FROM consentimientos c JOIN consentimientos_tipos t ON t.id=c.tipo WHERE c.persona=? AND t.codigo=? ORDER BY c.f_registro DESC,c.id DESC LIMIT 1",[$person,$type]);
		if ($revocation && !(int) $revocation['otorgado']) throw new ServiceFailure('El consentimiento de contacto fue revocado.',false);
		$recipient=$contact['valor'];
		if ($channel==='email' ? !filter_var($recipient,FILTER_VALIDATE_EMAIL) : !preg_match('/^\+?[1-9]\d{7,14}$/',$recipient)) throw new ServiceFailure('Medio de contacto inválido.',false);
		return $channel==='whatsapp' ? ltrim($recipient,'+') : $recipient;
	}
	public function outgoing(int $person,int $organization,?int $process,string $channel,array $content,string $idempotency,?string $template = null): string
	{
		if (!in_array($channel,['email','whatsapp'],true)) throw new \InvalidArgumentException('Canal inválido.');
		$actor=(new Access($this->repository))->actor();
		(new Access($this->repository))->context($organization,$process,'notificaciones.administrar');
		(new ScopeRepository($this->repository->connection()))->assertPerson($person,'notificaciones.administrar',$process);
		if ($channel==='email') $content=['title'=>Values::text($content['title'] ?? null,180),'summary'=>Values::text($content['summary'] ?? null,4000)];
		else {
			$template=Values::text($template,128);
			$allowed=array_filter(explode(',',(string) env('WHATSAPP_OPERATIONAL_TEMPLATES','')));
			if (!in_array($template,$allowed,true)) throw new ServiceFailure('La plantilla no está autorizada para notificaciones operativas.',false);
			$language=Values::text($content['language'] ?? null,12); if (!preg_match('/^[a-z]{2}(_[A-Z]{2})?$/',$language)) throw new \InvalidArgumentException('Idioma inválido.');
			$parameters=$content['parameters'] ?? []; if (!is_array($parameters) || count($parameters)>10) throw new \InvalidArgumentException('Parámetros inválidos.');
			$content=['language'=>$language,'parameters'=>array_map(fn ($value)=>Values::text($value,500),array_values($parameters))];
		}
		$config=$this->repository->one('SELECT * FROM notificaciones_config WHERE organizacion=?',[$organization]);
		if (!$config || !(int) $config[$channel.'_habilitado']) throw new ServiceFailure('El canal externo está deshabilitado.',false);
		$recipient=$this->contact($person,$channel,$organization); $key=hash('sha256',$organization.':'.($process ?? 'global').':'.$person.':'.$channel.':'.$idempotency);
		return $this->repository->transaction(function () use ($person,$organization,$process,$channel,$content,$template,$actor,$recipient,$key) {
			$existing=$this->repository->one('SELECT id FROM notificaciones_envios WHERE idempotencia=?',[$key]); if ($existing) return $existing['id'];
			$id=Values::uuid();
			$this->repository->execute('INSERT INTO notificaciones_envios (id,organizacion,proceso,persona,canal,destinatario,plantilla,contenido,usuario,idempotencia,fecha) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())',[$id,$organization,$process,$person,$channel,$recipient,$template,Values::json($content),$actor,$key]);
			(new QueueService($this->repository))->enqueue('notificacion',['envio_id'=>$id],$organization,$process,$actor,'envio:'.$id);
			return $id;
		});
	}
	public function deliver(string $id,?NotificationProvider $provider = null): void
	{
		(new OperationLock($this->repository))->run('envio:'.$id,function () use ($id,$provider) {
			$delivery=$this->repository->one('SELECT * FROM notificaciones_envios WHERE id=?',[$id]) ?? throw new ServiceFailure('Envío no disponible.',false);
			if ($delivery['estado']==='enviado') return;
			if (in_array($delivery['estado'],['enviando','incierto'],true)) throw new ServiceFailure('Envío de resultado incierto; requiere revisión para evitar duplicarlo.',false);
			$attempted=false;
			try {
				$config=$this->repository->one('SELECT * FROM notificaciones_config WHERE organizacion=?',[$delivery['organizacion']]);
				$process=$delivery['proceso']===null ? null : (int) $delivery['proceso'];
				(new Access($this->repository))->context((int) $delivery['organizacion'],$process,'notificaciones.administrar',(int) $delivery['usuario']);
				(new ScopeRepository($this->repository->connection()))->assertPerson((int) $delivery['persona'],'notificaciones.administrar',$process,(int) $delivery['usuario']);
				if (!$config || !(int) $config[$delivery['canal'].'_habilitado'] || $this->contact((int) $delivery['persona'],$delivery['canal'],(int) $delivery['organizacion'])!==$delivery['destinatario']) throw new ServiceFailure('El canal o consentimiento cambió antes del envío.',false);
				if ($delivery['canal']==='whatsapp' && !in_array($delivery['plantilla'],explode(',',(string) env('WHATSAPP_OPERATIONAL_TEMPLATES','')),true)) throw new ServiceFailure('La plantilla operativa dejó de estar autorizada.',false);
				$delivery['contenido']=json_decode($delivery['contenido'],true,512,JSON_THROW_ON_ERROR);
				(new \App\Services\Jornada\Notifications($this->repository))->authorizeDelivery($id);
				$actualProvider=$provider ?? ($delivery['canal']==='email' ? new SmtpProvider() : new WhatsAppProvider());
				$actualProvider->assertReady();
				$this->repository->execute("UPDATE notificaciones_envios SET estado='enviando',intentos=intentos+1,error=NULL WHERE id=?",[$id]);
				$attempted=true; $external=$actualProvider->send($delivery);
				$this->repository->execute("UPDATE notificaciones_envios SET estado='enviado',externo_id=?,enviado_en=NOW(),error=NULL WHERE id=?",[$external,$id]);
			} catch (\Throwable $error) {
				$this->repository->execute('UPDATE notificaciones_envios SET estado=?,error=? WHERE id=?',[$attempted ? 'incierto' : 'fallido',Sanitizer::error($error),$id]);
				throw new ServiceFailure($attempted ? 'No se confirmó el envío externo; requiere revisión antes de reenviar.' : Sanitizer::error($error),false);
			}
		});
	}
}
