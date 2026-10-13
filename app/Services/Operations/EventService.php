<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Core\HttpError;
use App\Repositories\ScopeRepository;
use App\Services\Common\Values;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\Output\QRGdImagePNG;

class EventService extends BaseService
{
	protected function entity(): string { return 'eventos'; }
	public function types(array $filters,?array $data = null): array
	{
		$process=$this->context->process($filters['process'] ?? $data['process'] ?? null);
		$context=$this->context->require('eventos',$process,$data===null ? 'eventos.ver' : 'eventos.administrar');
		if ($data!==null) {
			$name=Values::text($data['name'] ?? null,100); $uuid=Values::uuid();
			$this->repository->transaction(function() use($process,$context,$name,$uuid) {
				$id=$this->repository->insert('INSERT INTO eventos_tipos (uuid,organizacion,proceso,nombre) VALUES (?,?,?,?)',[Values::binary($uuid),$context['organization'],$process,$name]);
				(new \App\Services\Common\AuditService($this->repository))->record($context['organization'],$process,'crear','eventos_tipos',$id,null,['nombre'=>$name]);
			});
		}
		$result=$this->repository->page('SELECT uuid,nombre,activo FROM eventos_tipos WHERE proceso=? ORDER BY nombre,id',[$process],$filters);
		foreach ($result['rows'] as &$type) $type['uuid']=Values::uuidText($type['uuid']); return $result;
	}
	public function create(array $data): array
	{
		$process=$this->context->process($data['process'] ?? null); $context=$this->context->require('eventos',$process,'eventos.crear');
		return $this->repository->transaction(function() use($process,$context,$data) {
			$type=$this->repository->one('SELECT id FROM eventos_tipos WHERE uuid=? AND proceso=? AND activo=1',[Values::binary($data['type'] ?? null),$process]) ?? throw new HttpError('Tipo de evento no disponible.',422);
			$mode=Catalog::choice($data['attendance'] ?? 'individual',['individual','agregado','ninguno']);
			$start=Catalog::dateTime($data['start'] ?? null); $end=Catalog::dateTime($data['end'] ?? null,true);
			if ($end!==null && $end<$start) throw new \InvalidArgumentException('El evento termina antes de comenzar.');
			$references=Catalog::list($data['responsibles'] ?? []); $persons=[];
			foreach ($references as $reference) { $person=$this->context->person($reference,'eventos.crear',$process); $persons[$person['id']]=$person; }
			$row=$this->newRow('eventos',['organizacion'=>$context['organization'],'proceso'=>$process,'evento'=>Values::text($data['title'] ?? null,180),'descripcion'=>Values::text($data['description'] ?? '',10000,true),'tipo_operativo'=>$type['id'],'control_participantes'=>$mode,'lugar'=>Values::text($data['place'] ?? '',255,true),'fecha_inicio'=>$start,'fecha_fin'=>$end,'creado_por'=>$context['actor'],'responsable'=>array_key_first($persons),'estado'=>'programado','notas'=>Values::text($data['notes'] ?? '',4000,true),'permite_externos'=>$mode==='individual' ? Values::integer($data['external'] ?? 0,0,1) : 0,'qr_habilitado'=>$mode==='individual' ? Values::integer($data['qr'] ?? 0,0,1) : 0]);
			foreach ($persons as $person) {
				$this->repository->execute('INSERT INTO eventos_responsables (evento,persona) VALUES (?,?)',[$row['id'],$person['id']]);
				$this->repository->execute('INSERT INTO operaciones_personas (entidad,registro,persona) VALUES (\'eventos\',?,?)',[$row['id'],$person['id']]);
			}
			return ['uuid'=>$row['uuid']];
		});
	}
	public function detail(string $uuid): array
	{
		$row=parent::detail($uuid);
		$row['type_name']=$row['tipo_operativo']===null ? 'Histórico' : $this->repository->one('SELECT nombre FROM eventos_tipos WHERE id=?',[$row['tipo_operativo']])['nombre'];
		$context=$this->context->require('eventos',(int) $row['proceso'],'eventos.ver');
		[$scope,$params]=(new ScopeRepository($this->repository->connection()))->predicate('eventos.ver',(int) $row['proceso']);
		$visible=$this->context->broad($context,'eventos.ver') ? '1=1' : "(($scope) OR (a.persona_proceso IS NULL AND ?=1))";
		$params=$this->context->broad($context,'eventos.ver') ? [] : [...$params,(int) $row['creado_por']===$context['actor'] ? 1 : 0];
		$row['attendance_summary']=$row['control_participantes']==='individual' ? $this->repository->rows("SELECT a.estado,COUNT(*) cantidad FROM eventos_participantes a LEFT JOIN personas_procesos pp ON pp.id=a.persona_proceso LEFT JOIN personas p ON p.id=pp.persona WHERE a.evento=? AND ($visible) GROUP BY a.estado",[$row['id'],...$params]) : ['cantidad'=>$row['cantidad_asistentes']];
		$row['inventory_summary']=(new \App\Services\Resources\Related($this->repository))->eventInventory((int) $row['id'],(int) $row['proceso']);
		$row['financial_references']=(new \App\Services\Resources\Related($this->repository))->financial('eventos',(int) $row['id'],(int) $row['proceso']);
		return $row;
	}
	public function update(string $uuid,array $data): void
	{
		$this->repository->transaction(function() use($uuid,$data) {
			$row=$this->context->record('eventos',$uuid,'eventos.editar',null,true); $this->context->version($row,$data);
			$state=Catalog::choice($data['state'] ?? $row['estado'],['programado','en_curso','finalizado','cancelado']);
			if ($row['estado']==='cancelado') throw new HttpError('El evento fue cancelado.',409);
			$start=Catalog::dateTime($data['start'] ?? $row['fecha_inicio']); $end=Catalog::dateTime($data['end'] ?? $row['fecha_fin'],true);
			if ($end!==null && $end<$start) throw new \InvalidArgumentException('Periodo inválido.');
			$changes=['evento'=>Values::text($data['title'] ?? $row['evento'],180),'descripcion'=>Values::text($data['description'] ?? $row['descripcion'] ?? '',10000,true),'lugar'=>Values::text($data['place'] ?? $row['lugar'] ?? '',255,true),'fecha_inicio'=>$start,'fecha_fin'=>$end,'estado'=>$state,'notas'=>Values::text($data['notes'] ?? $row['notas'] ?? '',4000,true)];
			$this->repository->updateRecord('eventos',(int) $row['id'],$changes); $this->context->history('eventos',$row,$state==='cancelado' ? 'cancelar' : 'editar','',['estado'=>$row['estado']],['estado'=>$state]);
		});
	}
	public function participants(string $uuid,array $filters): array
	{
		$row=$this->context->record('eventos',$uuid,'eventos.ver');
		if ($row['control_participantes']!=='individual') return ['rows'=>[],'pagination'=>['limit'=>25,'offset'=>0,'has_more'=>false]];
		$context=$this->context->require('eventos',(int) $row['proceso'],'eventos.ver'); $where='a.evento=?'; $params=[$row['id']];
		if (!$this->context->broad($context,'eventos.ver')) { [$scope,$arguments]=(new ScopeRepository($this->repository->connection()))->predicate('eventos.ver',(int) $row['proceso']); $where.=" AND (($scope) OR (a.persona_proceso IS NULL AND ?=1))"; $params=[...$params,...$arguments,(int) $row['creado_por']===$context['actor'] ? 1 : 0]; }
		if (($filters['state'] ?? '')!=='') { $where.=' AND a.estado=?'; $params[]=Catalog::choice($filters['state'],['invitado','confirmado','asistio','no_asistio','cancelo']); }
		$result=$this->repository->page("SELECT a.uuid,a.estado,a.fecha,a.asistencia_en,COALESCE(CONCAT_WS(' ',p.nombre,p.paterno,p.materno),a.nombre_externo) nombre,a.nombre_externo, a.contacto_externo,p.uuid persona_uuid FROM eventos_participantes a LEFT JOIN personas_procesos pp ON pp.id=a.persona_proceso LEFT JOIN personas p ON p.id=pp.persona WHERE $where ORDER BY a.id",$params,$filters);
		foreach ($result['rows'] as &$participant) { $participant['uuid']=Values::uuidText($participant['uuid']); if ($participant['persona_uuid']!==null) $participant['persona_uuid']=Values::uuidText($participant['persona_uuid']); if ($participant['nombre_externo']!==null) $participant['nombre']=$participant['nombre_externo']; }
		return $result;
	}
	public function attendance(string $uuid,array $data): array
	{
		return $this->repository->transaction(function() use($uuid,$data) {
			$row=$this->context->record('eventos',$uuid,'eventos.asistencia',null,true); $this->context->version($row,$data);
			if ($row['estado']==='cancelado') throw new HttpError('No se registra asistencia de un evento cancelado.',409);
			if ($row['control_participantes']==='ninguno') throw new HttpError('Este evento no lleva control de participantes.',422);
			if ($row['control_participantes']==='agregado') {
				$count=Values::integer($data['count'] ?? null,0,10000000);
				$this->repository->updateRecord('eventos',(int) $row['id'],['cantidad_asistentes'=>$count]); $this->context->history('eventos',$row,'registrar_conteo','',['cantidad'=>$row['cantidad_asistentes']],['cantidad'=>$count]); return ['count'=>$count];
			}
			$state=Catalog::choice($data['state'] ?? 'invitado',['invitado','confirmado','asistio','no_asistio','cancelo']); $participant=null;
			if (($data['participant'] ?? '')!=='') $participant=$this->repository->one('SELECT * FROM eventos_participantes WHERE uuid=? AND evento=? FOR UPDATE',[Values::binary($data['participant']),$row['id']]) ?? throw new HttpError('Participante no disponible.',404);
			if ($participant!==null) {
				if ($participant['persona_proceso']!==null) { $person=$this->repository->one('SELECT persona FROM personas_procesos WHERE id=?',[$participant['persona_proceso']]); $this->context->person((int) $person['persona'],'eventos.asistencia',(int) $row['proceso']); }
				else $this->externalAccess($row,'eventos.asistencia');
				$id=(int) $participant['id']; $reference=Values::uuidText($participant['uuid']);
				$this->repository->execute('UPDATE eventos_participantes SET estado=?,asistencia_en=?,registrado_por=? WHERE id=?',[$state,$state==='asistio' ? date('Y-m-d H:i:s') : null,$this->context->actor(),$id]);
			} else {
				$person=$this->personReference($data['person'] ?? null,'eventos.asistencia',(int) $row['proceso']);
				if ($person===null) { if (!(int) $row['permite_externos']) throw new HttpError('Este evento no admite invitados externos.',422); $this->externalAccess($row,'eventos.asistencia'); }
				if ($person!==null && $this->repository->one('SELECT id FROM eventos_participantes WHERE evento=? AND persona_proceso=?',[$row['id'],$person['participation']])) throw new HttpError('La persona ya participa en este evento. Actualiza su registro.',409);
				$reference=Values::uuid();
				$id=$this->repository->insert('INSERT INTO eventos_participantes (uuid,evento,persona_proceso,nombre_externo,contacto_externo,estado,registrado_por,fecha,asistencia_en) VALUES (?,?,?,?,?,?,?,NOW(),?)',[Values::binary($reference),$row['id'],$person['participation'] ?? null,$person===null ? Values::text($data['external_name'] ?? null,180) : null,$person===null ? Values::text($data['external_contact'] ?? '',150,true) : null,$state,$this->context->actor(),$state==='asistio' ? date('Y-m-d H:i:s') : null]);
				$participant=['persona_proceso'=>$person['participation'] ?? null];
			}
			if ($participant['persona_proceso']!==null) $this->repository->execute('INSERT INTO eventos_asistentes (evento,persona_proceso,invitado,confirmado,asistio,registrado_por) VALUES (?,?,1,?,?,?) ON DUPLICATE KEY UPDATE confirmado=VALUES(confirmado),asistio=VALUES(asistio),registrado_por=VALUES(registrado_por)',[$row['id'],$participant['persona_proceso'],$state==='confirmado' || $state==='asistio' ? 1 : 0,$state==='asistio' ? 1 : 0,$this->context->actor()]);
			$this->context->history('eventos',$row,'registrar_asistencia','',null,['estado'=>$state]); return ['uuid'=>$reference,'state'=>$state];
		});
	}
	private function externalAccess(array $row,string $permission): void
	{
		$context=$this->context->require('eventos',(int) $row['proceso'],$permission);
		if (!$this->context->broad($context,$permission) && (int) $row['creado_por']!==$context['actor']) throw new HttpError('Se requiere alcance amplio o ser creador para registrar externos.',403);
	}
	public function qr(string $uuid,string $participant): array
	{
		$row=$this->context->record('eventos',$uuid,'eventos.asistencia');
		if (!(int) $row['qr_habilitado'] || $row['control_participantes']!=='individual') throw new HttpError('El evento no utiliza QR.',422);
		$entry=$this->repository->one('SELECT a.*,pp.persona FROM eventos_participantes a LEFT JOIN personas_procesos pp ON pp.id=a.persona_proceso WHERE a.uuid=? AND a.evento=?',[Values::binary($participant),$row['id']]) ?? throw new HttpError('Participante no disponible.',404);
		if ($entry['persona']!==null) $this->context->person((int) $entry['persona'],'eventos.asistencia',(int) $row['proceso']); else $this->externalAccess($row,'eventos.asistencia');
		$url=base_url('/operations/?module=eventos&event='.$uuid.'&participant='.$participant);
		return ['image'=>(new QRCode(['outputInterface'=>QRGdImagePNG::class,'outputBase64'=>true,'scale'=>4]))->render($url),'url'=>$url];
	}
}
