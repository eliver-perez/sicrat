<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Core\HttpError;
use App\Repositories\ScopeRepository;
use App\Services\Common\AuditService;
use App\Services\Common\NotificationService;
use App\Services\Common\QueueService;
use App\Services\Common\Values;

class MessagingService extends BaseService
{
	protected function entity(): string { return 'conversaciones'; }
	public function policy(array $filters,?array $data = null): array
	{
		$process=$this->context->process($filters['process'] ?? $data['process'] ?? null); $permission=$data===null ? 'mensajes.ver' : 'mensajes.administrar_grupos';
		$context=$this->context->require('conversaciones',$process,$permission);
		$policy=$this->repository->one('SELECT politica FROM mensajeria_politicas WHERE proceso=?',[$process])['politica'] ?? 'coordinacion';
		if ($data!==null) {
			if (!$this->context->broad($context,$permission)) throw new HttpError('La política electoral requiere alcance de elección u organización.',403);
			$next=Catalog::choice($data['policy'] ?? null,['coordinacion','equipo','todos']);
			$this->repository->transaction(function() use($context,$process,$policy,$next) {
				$this->repository->execute('INSERT INTO mensajeria_politicas (proceso,politica,editado_por,fecha) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE politica=VALUES(politica),editado_por=VALUES(editado_por),fecha=NOW()',[$process,$next,$context['actor']]);
				(new AuditService($this->repository))->record($context['organization'],$process,'configurar','mensajeria',$process,['politica'=>$policy],['politica'=>$next]);
			}); $policy=$next;
		}
		return ['policy'=>$policy];
	}
	private function account(int|string $reference,int $process,string $permission,?int $actor = null): array
	{
		$actor ??= $this->context->actor(); $context=$this->context->require('conversaciones',$process,$permission,$actor);
		$user=$this->repository->one('SELECT u.*,t.codigo FROM usuarios u JOIN usuarios_tipos t ON t.id=u.tipo_usuario WHERE u.'.(is_int($reference) ? 'id' : 'uuid').'=? AND u.activo=1',[is_int($reference) ? $reference : Values::binary($reference)]) ?? throw new HttpError('Cuenta no disponible.',404);
		if ($user['codigo']!=='superadmin' && ((int) $user['organizacion']!==$context['organization'] || !$this->repository->one('SELECT usuario FROM usuarios_procesos WHERE usuario=? AND proceso=? AND activo=1 AND f_baja IS NULL',[$user['id'],$process]))) throw new HttpError('La cuenta no pertenece a esta elección.',404);
		if (!$this->context->broad($context,$permission)) {
			if ($user['persona']===null) { if ((int) $user['id']!==$actor) throw new HttpError('Cuenta fuera de tu alcance.',404); }
			else (new ScopeRepository($this->repository->connection()))->assertPerson((int) $user['persona'],$permission,$process,$actor);
		}
		return $user;
	}
	private function canContact(array $user,int $process,?int $actor = null): bool
	{
		$actor ??= $this->context->actor(); $context=$this->context->require('conversaciones',$process,'mensajes.enviar',$actor);
		if ($context['effective']['super']) return true;
		if ($this->context->allowed('mensajes.comunicacion_fuera_equipo',$process,$actor)) {
			try { $this->account((int) $user['id'],$process,'mensajes.comunicacion_fuera_equipo',$actor); return true; } catch(HttpError) {}
		}
		$policy=$this->repository->one('SELECT politica FROM mensajeria_politicas WHERE proceso=?',[$process])['politica'] ?? 'coordinacion';
		if ($policy==='todos') return true;
		$person=$context['effective']['user']['persona'];
		if ($person===null || $user['persona']===null) return false;
		if ($policy==='coordinacion') return $this->repository->one('SELECT own.integrante FROM estructura_integrantes_procesos own JOIN estructura_integrantes_procesos target ON target.proceso=own.proceso JOIN estructura_relaciones rel ON (rel.ancestro=own.integrante AND rel.descendiente=target.integrante) OR (rel.ancestro=target.integrante AND rel.descendiente=own.integrante) WHERE own.persona=? AND target.persona=? AND own.proceso=? AND own.activo=1 AND target.activo=1 AND rel.profundidad>0 LIMIT 1',[$person,$user['persona'],$process])!==null;
		return $this->repository->one('SELECT own.integrante FROM estructura_integrantes_procesos own JOIN estructura a ON a.id=own.integrante JOIN estructura_integrantes_procesos target ON target.proceso=own.proceso JOIN estructura b ON b.id=target.integrante WHERE own.persona=? AND target.persona=? AND own.proceso=? AND own.activo=1 AND target.activo=1 AND a.contenedor=b.contenedor LIMIT 1',[$person,$user['persona'],$process])!==null
			|| $this->repository->one('SELECT a.seccion FROM usuarios_territorios a JOIN usuarios_territorios b ON b.seccion=a.seccion AND b.proceso=a.proceso WHERE a.usuario=? AND b.usuario=? AND a.proceso=? LIMIT 1',[$actor,$user['id'],$process])!==null;
	}
	public function contacts(array $filters): array
	{
		$process=$this->context->process($filters['process'] ?? null); $permission=$filters['permission'] ?? 'mensajes.enviar';
		Catalog::choice($permission,['mensajes.enviar','mensajes.crear_grupos','mensajes.miembros']);
		$context=$this->context->require('conversaciones',$process,$permission);
		[$scope,$params]=(new ScopeRepository($this->repository->connection()))->predicate($permission,$process);
		$where='u.activo=1 AND u.id<>? AND ((u.organizacion=? AND EXISTS (SELECT 1 FROM usuarios_procesos up WHERE up.usuario=u.id AND up.proceso=? AND up.activo=1 AND up.f_baja IS NULL)) OR (t.codigo=\'superadmin\' AND ?=1))';
		$parameters=[$context['actor'],$context['organization'],$process,$context['effective']['super'] ? 1 : 0];
		if (!$this->context->broad($context,$permission)) { $where.=" AND ($scope)"; $parameters=[...$parameters,...$params]; }
		if (($filters['search'] ?? '')!=='') { $where.=' AND (u.nombre LIKE ? OR u.usuario LIKE ?)'; $search='%'.Values::text($filters['search'],100).'%'; $parameters[]=$search; $parameters[]=$search; }
		// Aplicar la política antes de la paginación mediante las relaciones reales del actor.
		if ($permission==='mensajes.enviar' && !$context['effective']['super']) {
			$policy=$this->repository->one('SELECT politica FROM mensajeria_politicas WHERE proceso=?',[$process])['politica'] ?? 'coordinacion';
			if ($policy!=='todos') {
				$own=(int) ($context['effective']['user']['persona'] ?? 0);
				$policyStart=strlen($where);
				if ($policy==='coordinacion') $where.=' AND EXISTS (SELECT 1 FROM estructura_integrantes_procesos own JOIN estructura_integrantes_procesos target ON target.proceso=own.proceso JOIN estructura_relaciones rel ON (rel.ancestro=own.integrante AND rel.descendiente=target.integrante) OR (rel.ancestro=target.integrante AND rel.descendiente=own.integrante) WHERE own.persona=? AND target.persona=u.persona AND own.proceso=? AND own.activo=1 AND target.activo=1 AND rel.profundidad>0)';
				else $where.=' AND (EXISTS (SELECT 1 FROM estructura_integrantes_procesos own JOIN estructura a ON a.id=own.integrante JOIN estructura_integrantes_procesos target ON target.proceso=own.proceso JOIN estructura b ON b.id=target.integrante WHERE own.persona=? AND target.persona=u.persona AND own.proceso=? AND own.activo=1 AND target.activo=1 AND a.contenedor=b.contenedor) OR EXISTS (SELECT 1 FROM usuarios_territorios x JOIN usuarios_territorios y ON y.proceso=x.proceso AND y.seccion=x.seccion WHERE x.usuario=? AND y.usuario=u.id AND x.proceso=?))';
				$parameters[]=$own; $parameters[]=$process;
				if ($policy==='equipo') { $parameters[]=$context['actor']; $parameters[]=$process; }
				if ($this->context->allowed('mensajes.comunicacion_fuera_equipo',$process)) {
					[$outside,$outsideParams]=(new ScopeRepository($this->repository->connection()))->predicate('mensajes.comunicacion_fuera_equipo',$process);
					$policyPredicate=substr($where,$policyStart+5);
					$where=substr($where,0,$policyStart)." AND (($policyPredicate) OR ($outside))"; $parameters=[...$parameters,...$outsideParams];
				}
			}
		}
		$result=$this->repository->page("SELECT u.uuid,u.nombre,u.usuario FROM usuarios u JOIN usuarios_tipos t ON t.id=u.tipo_usuario LEFT JOIN personas p ON p.id=u.persona WHERE $where ORDER BY u.nombre,u.id",$parameters,$filters);
		foreach ($result['rows'] as &$user) $user['uuid']=Values::uuidText($user['uuid']); return $result;
	}
	public function create(array $data): array
	{
		$process=$this->context->process($data['process'] ?? null); $type=Catalog::choice($data['type'] ?? 'DIRECTA',['DIRECTA','GRUPO','ESTRUCTURA']);
		$permission=['DIRECTA'=>'mensajes.enviar','GRUPO'=>'mensajes.crear_grupos','ESTRUCTURA'=>'mensajes.crear_grupos_estructura'][$type];
		$context=$this->context->require('conversaciones',$process,$permission);
		return $this->repository->transaction(function() use($context,$process,$type,$permission,$data) {
			$this->repository->one('SELECT id FROM organizaciones WHERE id=? FOR UPDATE',[$context['organization']]);
			$members=[]; $key=null; $structure=null; $level=null; $root=null; $mode=null;
			if ($type==='DIRECTA') {
				$user=$this->account($data['recipient'] ?? '',$process,$permission);
				if ((int) $user['id']===$context['actor'] || !$this->canContact($user,$process)) throw new HttpError('La política de contacto no permite esta conversación directa.',403);
				$pair=[$context['actor'],(int) $user['id']]; sort($pair); $key=implode(':',$pair);
				$existing=$this->repository->one('SELECT uuid FROM conversaciones WHERE proceso=? AND clave_directa=?',[$process,$key]);
				if ($existing) return ['uuid'=>Values::uuidText($existing['uuid'])];
				$members=[(int) $user['id']];
			} elseif ($type==='GRUPO') foreach (Catalog::list($data['members'] ?? []) as $reference) $members[]=(int) $this->account($reference,$process,$permission)['id'];
			else {
				$this->context->require('conversaciones',$process,'estructura.ver');
				$container=$this->repository->one('SELECT c.id FROM estructuras_organizacionales c JOIN estructuras_procesos ep ON ep.estructura=c.id AND ep.proceso=? AND ep.activo=1 WHERE c.uuid=? AND c.organizacion=? AND c.activo=1',[$process,Values::binary($data['structure'] ?? null),$context['organization']]) ?? throw new HttpError('Estructura no disponible.',404);
				$structure=(int) $container['id']; $mode=Catalog::choice($data['structure_mode'] ?? 'estructura',['estructura','equipo','nivel']);
				if ($mode==='nivel') $level=$this->repository->one('SELECT id FROM niveles_organizacionales WHERE uuid=? AND proceso=? AND activo=1',[Values::binary($data['level'] ?? null),$process])['id'] ?? throw new HttpError('Nivel no disponible.',404);
				if ($mode==='equipo') $root=$this->repository->one('SELECT e.id,e.persona FROM estructura e JOIN estructura_integrantes_procesos ep ON ep.integrante=e.id AND ep.proceso=? AND ep.activo=1 WHERE e.uuid=? AND e.contenedor=?',[$process,Values::binary($data['root'] ?? null),$structure]) ?? throw new HttpError('Equipo no disponible.',404);
				$guard=$this->context->require('conversaciones',$process,$permission);
				if (!$this->context->broad($guard,$permission)) {
					if ($mode!=='equipo' || !$root) throw new HttpError('Crear un grupo de estructura completa o nivel requiere alcance amplio.',403);
					$this->context->person((int) $root['persona'],$permission,$process,false);
					[$scope,$args]=(new ScopeRepository($this->repository->connection()))->predicate($permission,$process);
					if ($this->repository->one("SELECT e.id FROM estructura_relaciones rel JOIN estructura e ON e.id=rel.descendiente JOIN personas p ON p.id=e.persona WHERE rel.ancestro=? AND NOT ($scope) LIMIT 1",[$root['id'],...$args])) throw new HttpError('El grupo incluiría personas fuera de tu alcance.',403);
				}
			}
			$row=$this->newRow('conversaciones',['organizacion'=>$context['organization'],'proceso'=>$process,'tipo'=>$this->repository->one('SELECT id FROM conversaciones_tipos WHERE codigo=?',[$type])['id'],'titulo'=>Values::text($data['title'] ?? ($type==='DIRECTA' ? 'Conversación directa' : ''),150),'creado_por'=>$context['actor'],'f_registro'=>date('Y-m-d H:i:s'),'estado'=>'activa','anuncios'=>$type==='DIRECTA' ? 0 : Values::integer($data['announcements'] ?? 0,0,1),'estructura'=>$structure,'nivel'=>$level,'integrante_raiz'=>is_array($root) ? $root['id'] : null,'modo_estructura'=>$mode,'clave_directa'=>$key,'permite_pdf'=>Values::integer($data['allow_pdf'] ?? 0,0,1)]);
			if ($type!=='ESTRUCTURA') foreach (array_unique([$context['actor'],...$members]) as $member) $this->repository->execute('INSERT INTO conversaciones_usuarios (conversacion,usuario,administrador,visible_desde_id,incorporado_en) VALUES (?,?,?,0,NOW())',[$row['id'],$member,$member===$context['actor'] ? 1 : 0]);
			else $this->sync($row);
			return ['uuid'=>$row['uuid']];
		});
	}
	private function automaticQuery(array $row): array
	{
		$where='u.activo=1 AND u.organizacion=? AND ep.proceso=? AND ep.activo=1 AND e.activo=1 AND e.contenedor=? AND up.activo=1 AND up.f_baja IS NULL';
		// mensajes.ver es electoral: la plantilla, su override y las revocaciones siguen la precedencia de Fase 3.
		$where.=" AND (base.codigo='superadmin' OR ((EXISTS (SELECT 1 FROM permisos_usuarios_tipo pt WHERE pt.tipo=up.tipo_usuario AND pt.permiso='mensajes.ver' AND pt.contexto<>'global' AND pt.valor=1 AND (pt.proceso=ep.proceso OR (pt.proceso IS NULL AND NOT EXISTS (SELECT 1 FROM permisos_usuarios_tipo electoral_override WHERE electoral_override.tipo=pt.tipo AND electoral_override.permiso=pt.permiso AND electoral_override.proceso=ep.proceso AND electoral_override.contexto<>'global')))) OR EXISTS (SELECT 1 FROM permisos_usuarios grant_permission WHERE grant_permission.usuario=u.id AND grant_permission.permiso='mensajes.ver' AND grant_permission.proceso=ep.proceso AND grant_permission.valor=1 AND (grant_permission.organizacion IS NULL OR grant_permission.organizacion=u.organizacion))) AND NOT EXISTS (SELECT 1 FROM permisos_usuarios deny_permission WHERE deny_permission.usuario=u.id AND deny_permission.permiso='mensajes.ver' AND deny_permission.proceso=ep.proceso AND deny_permission.valor=0 AND (deny_permission.organizacion IS NULL OR deny_permission.organizacion=u.organizacion))))";
		$params=[$row['organizacion'],$row['proceso'],$row['estructura']];
		if ($row['modo_estructura']==='nivel') { $where.=' AND ep.nivel=?'; $params[]=$row['nivel']; }
		if ($row['modo_estructura']==='equipo') { $where.=' AND EXISTS (SELECT 1 FROM estructura_relaciones rel WHERE rel.ancestro=? AND rel.descendiente=e.id)'; $params[]=$row['integrante_raiz']; }
		return ["SELECT DISTINCT u.id FROM usuarios u JOIN usuarios_tipos base ON base.id=u.tipo_usuario JOIN usuarios_procesos up ON up.usuario=u.id JOIN estructura_integrantes_procesos ep ON ep.persona=u.persona AND ep.proceso=up.proceso JOIN estructura e ON e.id=ep.integrante WHERE $where",$params];
	}
	public function sync(array $row): void
	{
		if ($row['estructura']===null) return;
		$this->repository->transaction(function() use($row) {
			$this->repository->raw('conversaciones',(int) $row['id'],true);
			[$query,$params]=$this->automaticQuery($row);
			$next=(int) $this->repository->one('SELECT COALESCE(MAX(id),0)+1 next_id FROM mensajes WHERE conversacion=?',[$row['id']])['next_id'];
			$this->repository->execute("INSERT INTO conversaciones_usuarios (conversacion,usuario,administrador,visible_desde_id,incorporado_en) SELECT ?,eligible.id,IF(eligible.id=?,1,0),?,NOW() FROM ($query) eligible ON DUPLICATE KEY UPDATE visible_desde_id=IF(activo=0,VALUES(visible_desde_id),visible_desde_id),incorporado_en=IF(activo=0,NOW(),incorporado_en),activo=1",[$row['id'],$row['creado_por'],$next,...$params]);
			$this->repository->execute("UPDATE conversaciones_usuarios cu SET activo=0 WHERE cu.conversacion=? AND cu.activo=1 AND NOT EXISTS (SELECT 1 FROM ($query) eligible WHERE eligible.id=cu.usuario)",[$row['id'],...$params]);
		});
	}
	public function synchronizeOrganization(int $organization): void
	{
		// Admite el despliegue coordinado previo al DDL sin alterar las operaciones de Fases 1–4.
		if (!(new \App\Core\Migrations($this->repository->connection()))->columnExists('conversaciones','estructura')) return;
		$last=0;
		do {
			$rows=$this->repository->rows('SELECT * FROM conversaciones WHERE organizacion=? AND estructura IS NOT NULL AND id>? ORDER BY id LIMIT 100',[$organization,$last]);
			foreach ($rows as $row) { $last=(int) $row['id']; $this->sync($row); }
		} while(count($rows)===100);
	}
	public function synchronizeAll(): void
	{
		if (!(new \App\Core\Migrations($this->repository->connection()))->columnExists('conversaciones','estructura')) return;
		$last=0;
		do {
			$organizations=$this->repository->rows('SELECT DISTINCT organizacion FROM conversaciones WHERE estructura IS NOT NULL AND organizacion>? ORDER BY organizacion LIMIT 100',[$last]);
			foreach ($organizations as $organization) { $last=(int) $organization['organizacion']; $this->synchronizeOrganization($last); }
		} while(count($organizations)===100);
	}
	private function globalAccess(int $process,int $actor): bool
	{
		if (!$this->context->allowed('mensajes.administracion_global',$process,$actor)) return false;
		return $this->context->broad($this->context->require('conversaciones',$process,'mensajes.administracion_global',$actor),'mensajes.administracion_global');
	}
	private function conversation(string|int $reference,string $permission = 'mensajes.ver',?int $actor = null,bool $manage = false): array
	{
		$row=$this->repository->raw('conversaciones',$reference); $actor ??= $this->context->actor();
		$this->context->require('conversaciones',(int) $row['proceso'],$permission,$actor); $this->sync($row);
		$membership=$this->repository->one('SELECT * FROM conversaciones_usuarios WHERE conversacion=? AND usuario=? AND activo=1',[$row['id'],$actor]);
		$global=$this->globalAccess((int) $row['proceso'],$actor);
		$ownerAdmin=false;
		if ((int) $row['creado_por']===$actor && $this->context->allowed('mensajes.administrar_grupos',(int) $row['proceso'],$actor)) $ownerAdmin=$this->context->broad($this->context->require('conversaciones',(int) $row['proceso'],'mensajes.administrar_grupos',$actor),'mensajes.administrar_grupos');
		if (!$membership && !$global && !$ownerAdmin && !($manage && (int) $row['creado_por']===$actor)) throw new HttpError('Conversación no disponible.',404);
		$row['direct']=$this->repository->one('SELECT codigo FROM conversaciones_tipos WHERE id=?',[$row['tipo']])['codigo']==='DIRECTA';
		$row['membership']=$membership; $row['global']=$global; $row['owner_admin']=$ownerAdmin; $row['uuid']=Values::uuidText($row['uuid']); return $row;
	}
	public function detail(string $uuid): array
	{
		$row=$this->conversation($uuid); return ['uuid'=>$uuid,'id'=>$row['id'],'title'=>$row['titulo'],'state'=>$row['estado'],'announcements'=>(bool) $row['anuncios'],'automatic'=>$row['estructura']!==null,'direct'=>$row['direct'],'version'=>$row['version'],'membership'=>$row['membership'],'can_manage'=>(bool) ($row['global'] || $row['owner_admin'] || ($row['membership']['administrador'] ?? false))];
	}
	public function listing(array $filters): array
	{
		$process=$this->context->process($filters['process'] ?? null); $context=$this->context->require('conversaciones',$process,'mensajes.ver');
		// Sincronizar por clave y lotes; no se materializan miembros ni historial completo en PHP.
		$last=0;
		do {
			$groups=$this->repository->rows("SELECT * FROM conversaciones WHERE proceso=? AND estructura IS NOT NULL AND id>? ORDER BY id LIMIT 100",[$process,$last]);
			foreach ($groups as $group) { $last=(int) $group['id']; $this->sync($group); }
		} while(count($groups)===100);
		$where='r.proceso=? AND r.organizacion=? AND r.estado=\'activa\''; $params=[$process,$context['organization']];
		if (!$this->globalAccess($process,$context['actor'])) {
			$owner=$this->context->allowed('mensajes.administrar_grupos',$process) && $this->context->broad($this->context->require('conversaciones',$process,'mensajes.administrar_grupos'),'mensajes.administrar_grupos');
			$where.=' AND (cu.activo=1 OR (r.creado_por=? AND ?=1))'; $params[]=$context['actor']; $params[]=$owner ? 1 : 0;
		}
		if (($filters['search'] ?? '')!=='') { $where.=' AND r.titulo LIKE ?'; $params[]='%'.Values::text($filters['search'],150).'%'; }
		$result=$this->repository->page("SELECT r.id,r.uuid,r.titulo,r.estado,r.f_registro fecha,r.version,r.anuncios,r.estructura,cu.silenciada,cu.silenciada_hasta FROM conversaciones r LEFT JOIN conversaciones_usuarios cu ON cu.conversacion=r.id AND cu.usuario=? WHERE $where ORDER BY r.id DESC",[$context['actor'],...$params],$filters);
		foreach ($result['rows'] as &$row) $row['uuid']=Values::uuidText($row['uuid']); return $result;
	}
	public function members(string $uuid,array $filters): array
	{
		$row=$this->conversation($uuid);
		$result=$this->repository->page('SELECT u.uuid,u.nombre,u.usuario,cu.administrador,cu.incorporado_en FROM conversaciones_usuarios cu JOIN usuarios u ON u.id=cu.usuario WHERE conversacion=? AND cu.activo=1 ORDER BY u.nombre,u.id',[$row['id']],$filters);
		foreach ($result['rows'] as &$user) $user['uuid']=Values::uuidText($user['uuid']); return $result;
	}
	public function membership(string $uuid,array $data): void
	{
		$this->repository->transaction(function() use($uuid,$data) {
			$row=$this->conversation($uuid,'mensajes.miembros',null,true);
			$this->context->require('conversaciones',(int) $row['proceso'],'mensajes.administrar_grupos');
			$this->repository->raw('conversaciones',(int) $row['id'],true); $this->context->version($row,$data);
			if ($row['estructura']!==null || $row['direct']) throw new HttpError('Este tipo de conversación calcula o fija sus integrantes.',422);
			if (!$row['global'] && !($row['membership']['administrador'] ?? false) && (int) $row['creado_por']!==$this->context->actor()) throw new HttpError('Sólo administradores del grupo cambian integrantes.',403);
			$members=Catalog::list($data['members'] ?? []); if (!$members) throw new \InvalidArgumentException('Conserva al menos un integrante.');
			$ids=[]; foreach ($members as $reference) $ids[]=(int) $this->account($reference,(int) $row['proceso'],'mensajes.miembros')['id'];
			if (!in_array((int) $row['creado_por'],$ids,true)) throw new HttpError('El grupo debe conservar a su creador como administrador.',422);
			$next=(int) $this->repository->one('SELECT COALESCE(MAX(id),0)+1 next_id FROM mensajes WHERE conversacion=?',[$row['id']])['next_id'];
			foreach (array_unique($ids) as $user) $this->repository->execute('INSERT INTO conversaciones_usuarios (conversacion,usuario,administrador,visible_desde_id,incorporado_en) VALUES (?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE visible_desde_id=IF(activo=0,VALUES(visible_desde_id),visible_desde_id),incorporado_en=IF(activo=0,NOW(),incorporado_en),activo=1',[$row['id'],$user,$user===(int) $row['creado_por'] ? 1 : 0,$next]);
			$this->repository->execute('UPDATE conversaciones_usuarios SET activo=0 WHERE conversacion=? AND usuario NOT IN ('.implode(',',array_fill(0,count($ids),'?')).')',[$row['id'],...$ids]);
			$this->repository->updateRecord('conversaciones',(int) $row['id'],['anuncios'=>Values::integer($data['announcements'] ?? $row['anuncios'],0,1)]);
			$this->context->history('conversaciones',$row,'cambiar_integrantes','',null,['integrantes'=>count(array_unique($ids))]);
		});
	}
	public function messages(string $uuid,array $filters): array
	{
		$row=$this->conversation($uuid); $where='m.conversacion=? AND m.id>=?'; $params=[$row['id'],$row['membership']['visible_desde_id'] ?? 0];
		if (($filters['before'] ?? '')!=='') { $where.=' AND m.id<?'; $params[]=Values::integer($filters['before']); }
		if (($filters['search'] ?? '')!=='') { $where.=' AND m.eliminado=0 AND m.mensaje LIKE ?'; $params[]='%'.Values::text($filters['search'],150).'%'; }
		$result=$this->repository->page("SELECT m.id,m.uuid,m.usuario,m.mensaje,m.f_envio,m.eliminado,u.nombre remitente,l.leido_en FROM mensajes m JOIN usuarios u ON u.id=m.usuario LEFT JOIN mensajes_lecturas l ON l.mensaje=m.id AND l.usuario=? WHERE $where ORDER BY m.id DESC",[$this->context->actor(),...$params],$filters);
		foreach ($result['rows'] as &$message) {
			$message['uuid']=Values::uuidText($message['uuid']);
			if ((int) $message['eliminado']) { $message['mensaje']='Mensaje moderado'; $message['attachments']=[]; }
			else { $message['attachments']=$this->repository->rows("SELECT uuid,nombre_original,mime_type FROM archivos WHERE entidad='mensajes' AND entidad_id=? AND estado='activo' ORDER BY id LIMIT 50",[$message['id']]); foreach ($message['attachments'] as &$file) $file['uuid']=Values::uuidText($file['uuid']); }
		}
		return $result;
	}
	public function send(string $uuid,array $data): array
	{
		return $this->repository->transaction(function() use($uuid,$data) {
			$raw=$this->repository->raw('conversaciones',$uuid,true); $row=$this->conversation((int) $raw['id'],'mensajes.enviar');
			if ($row['estado']!=='activa') throw new HttpError('La conversación está cerrada.',409);
			if ((int) $row['anuncios'] && !$row['global'] && !$row['owner_admin'] && !($row['membership']['administrador'] ?? false)) throw new HttpError('Este grupo permite publicar únicamente a administradores autorizados.',403);
			if ($row['direct']) {
				$peer=$this->repository->one('SELECT usuario FROM conversaciones_usuarios WHERE conversacion=? AND usuario<>? AND activo=1',[$row['id'],$this->context->actor()]);
				if (!$peer || !$this->canContact($this->account((int) $peer['usuario'],(int) $row['proceso'],'mensajes.enviar'),(int) $row['proceso'])) throw new HttpError('La política actual no permite este contacto directo.',403);
			}
			$content=Values::text($data['text'] ?? null,8000); $key=isset($data['idempotency']) ? Values::text($data['idempotency'],80) : null;
			if ($key!==null) {
				$existing=$this->repository->one('SELECT uuid,mensaje FROM mensajes WHERE conversacion=? AND usuario=? AND idempotencia=?',[$row['id'],$this->context->actor(),$key]);
				if ($existing) { if ($existing['mensaje']!==$content) throw new HttpError('La clave de envío ya fue usada con otro contenido.',409); return ['uuid'=>Values::uuidText($existing['uuid'])]; }
			}
			$reference=Values::uuid(); $id=$this->repository->insert('INSERT INTO mensajes (uuid,conversacion,usuario,mensaje,f_envio,idempotencia) VALUES (?,?,?,?,NOW(),?)',[Values::binary($reference),$row['id'],$this->context->actor(),$content,$key]);
			$this->repository->execute('INSERT INTO mensajes_destinatarios (mensaje,usuario) SELECT ?,cu.usuario FROM conversaciones_usuarios cu JOIN usuarios u ON u.id=cu.usuario WHERE cu.conversacion=? AND cu.activo=1 AND u.activo=1',[$id,$row['id']]);
			$this->repository->execute('INSERT IGNORE INTO mensajes_lecturas (mensaje,usuario,leido_en) VALUES (?,?,NOW())',[$id,$this->context->actor()]);
			(new QueueService($this->repository))->enqueue('mensaje_notificar',['message'=>$id],(int) $row['organizacion'],(int) $row['proceso'],$this->context->actor(),'mensaje:'.$id);
			return ['uuid'=>$reference,'id'=>$id];
		});
	}
	public function read(string $uuid,array $data): int
	{
		$row=$this->conversation($uuid); $references=Catalog::list($data['messages'] ?? []);
		if (!$references) throw new \InvalidArgumentException('Selecciona mensajes visibles.');
		$values=array_map([Values::class,'binary'],$references); $marks=implode(',',array_fill(0,count($values),'?'));
		$actor=$this->context->actor();
		return $this->repository->transaction(function() use($row,$values,$marks,$actor) {
			$affected=$this->repository->execute("INSERT IGNORE INTO mensajes_lecturas (mensaje,usuario,leido_en) SELECT m.id,?,NOW() FROM mensajes m JOIN mensajes_destinatarios d ON d.mensaje=m.id AND d.usuario=? LEFT JOIN mensajes_lecturas l ON l.mensaje=m.id AND l.usuario=? WHERE m.conversacion=? AND m.id>=? AND m.uuid IN ($marks) AND l.mensaje IS NULL",[$actor,$actor,$actor,$row['id'],$row['membership']['visible_desde_id'] ?? 0,...$values]);
			$this->repository->execute('UPDATE conversaciones_usuarios SET ultimo_mensaje_leido=(SELECT MAX(m.id) FROM mensajes m JOIN mensajes_lecturas l ON l.mensaje=m.id AND l.usuario=? WHERE m.conversacion=?),leido_en=IF(? > 0,NOW(),leido_en) WHERE conversacion=? AND usuario=?',[$actor,$row['id'],$affected,$row['id'],$actor]);
			return $affected;
		});
	}
	public function receipts(string $uuid,string $reference,array $filters): array
	{
		$row=$this->conversation($uuid); $message=$this->repository->one('SELECT * FROM mensajes WHERE uuid=? AND conversacion=? AND id>=?',[Values::binary($reference),$row['id'],$row['membership']['visible_desde_id'] ?? 0]) ?? throw new HttpError('Mensaje no disponible.',404);
		if ((int) $message['usuario']!==$this->context->actor() && !$row['global'] && !($row['membership']['administrador'] ?? false)) throw new HttpError('Sólo remitente o administradores consultan las lecturas.',403);
		return $this->repository->page('SELECT u.nombre,l.leido_en FROM mensajes_destinatarios d JOIN usuarios u ON u.id=d.usuario LEFT JOIN mensajes_lecturas l ON l.mensaje=d.mensaje AND l.usuario=d.usuario WHERE d.mensaje=? AND d.usuario<>? ORDER BY u.nombre,u.id',[$message['id'],$message['usuario']],$filters);
	}
	public function mute(string $uuid,array $data): void
	{
		$row=$this->conversation($uuid); $muted=Values::integer($data['muted'] ?? null,0,1); $until=Catalog::dateTime($data['until'] ?? null,true);
		if ($until!==null && $until<=date('Y-m-d H:i:s')) throw new \InvalidArgumentException('El silencio temporal debe terminar en el futuro.');
		if (!$row['membership']) throw new HttpError('Sólo integrantes silencian su conversación.',403);
		$this->repository->execute('UPDATE conversaciones_usuarios SET silenciada=?,silenciada_hasta=? WHERE conversacion=? AND usuario=?',[$muted,$muted ? $until : null,$row['id'],$this->context->actor()]);
	}
	public function authorizeMessage(int $id,int $organization,?int $process,string $operation,int $actor,?string $additional = null): void
	{
		$message=$this->repository->one('SELECT * FROM mensajes WHERE id=? AND eliminado=0',[$id]) ?? throw new HttpError('Mensaje no disponible.',404);
		$permission=$operation==='write' ? 'mensajes.adjuntar_archivos' : 'mensajes.ver'; $row=$this->conversation((int) $message['conversacion'],$permission,$actor);
		if ((int) $row['organizacion']!==$organization || (int) $row['proceso']!==$process || (int) $message['id']<(int) ($row['membership']['visible_desde_id'] ?? 0)) throw new HttpError('Adjunto no disponible.',404);
		if ($operation==='write' && (int) $message['usuario']!==$actor && !$row['global']) throw new HttpError('Sólo remitente autorizado agrega o elimina adjuntos.',403);
		if ($operation==='write' && $row['anuncios'] && !($row['membership']['administrador'] ?? false) && !$row['global'] && !$row['owner_admin']) throw new HttpError('No puedes adjuntar en este anuncio.',403);
		if ($additional!==null) $this->context->require('conversaciones',(int) $row['proceso'],$additional,$actor);
	}
	public function moderate(string $uuid,string $reference,array $data): void
	{
		$this->repository->transaction(function() use($uuid,$reference,$data) {
			$row=$this->conversation($uuid,'mensajes.moderar');
			$this->repository->raw('conversaciones',(int) $row['id'],true);
			if (!$row['global'] && !$row['owner_admin'] && !($row['membership']['administrador'] ?? false)) throw new HttpError('La moderación requiere administración de la conversación.',403);
			$message=$this->repository->one('SELECT id FROM mensajes WHERE uuid=? AND conversacion=? AND id>=?',[Values::binary($reference),$row['id'],$row['membership']['visible_desde_id'] ?? 0]) ?? throw new HttpError('Mensaje no disponible.',404);
			$reason=Values::text($data['reason'] ?? null,500);
			$this->repository->execute('UPDATE mensajes SET eliminado=1,moderado_por=?,moderado_en=NOW(),motivo_moderacion=? WHERE id=?',[$this->context->actor(),$reason,$message['id']]);
			$this->context->history('conversaciones',$row,'moderar',$reason,null,['mensaje'=>$reference]);
		});
	}
	public function notify(int $id): void
	{
		$message=$this->repository->one('SELECT * FROM mensajes WHERE id=? AND eliminado=0',[$id]) ?? throw new HttpError('Mensaje no disponible.',404);
		$row=$this->conversation((int) $message['conversacion'],'mensajes.enviar'); $last=0;
		do {
			$users=$this->repository->rows('SELECT cu.usuario FROM conversaciones_usuarios cu JOIN mensajes_destinatarios d ON d.usuario=cu.usuario AND d.mensaje=? WHERE cu.conversacion=? AND cu.activo=1 AND cu.usuario<>? AND cu.usuario>? AND cu.visible_desde_id<=? AND (cu.silenciada=0 OR (cu.silenciada_hasta IS NOT NULL AND cu.silenciada_hasta<=NOW())) ORDER BY cu.usuario LIMIT 100',[$id,$row['id'],$message['usuario'],$last,$id]);
			foreach ($users as $user) {
				$last=(int) $user['usuario'];
				try { $this->conversation((int) $row['id'],'mensajes.ver',$last); } catch(HttpError) { continue; }
				$this->repository->transaction(function() use($message,$id,$row,$last) {
					$this->repository->raw('conversaciones',(int) $row['id'],true);
					if ($this->repository->one('SELECT usuario FROM mensajes_avisos WHERE mensaje=? AND usuario=?',[$id,$last])) return;
					$notification=(new NotificationService($this->repository))->internal($last,(int) $row['organizacion'],(int) $row['proceso'],'mensaje_interno','Nuevo mensaje interno','Tienes un mensaje en una conversación autorizada.','conversaciones',$row['uuid'],'/operations/?module=conversaciones');
					$this->repository->execute('INSERT INTO mensajes_avisos (mensaje,usuario,notificacion) VALUES (?,?,?)',[$id,$last,Values::binary($notification)]);
				});
			}
		} while(count($users)===100);
	}
	public function outgoing(array $data): string
	{
		$process=$this->context->process($data['process'] ?? null); $context=$this->context->require('conversaciones',$process,'mensajes.enviar');
		$person=$this->context->person($data['person'] ?? null,'mensajes.enviar',$process,false);
		$content=$data['content'] ?? []; if (!is_array($content)) throw new \InvalidArgumentException('Contenido inválido.');
		return (new NotificationService($this->repository))->outgoing((int) $person['id'],$context['organization'],$process,Catalog::choice($data['channel'] ?? null,['email','whatsapp']),$content,Values::text($data['idempotency'] ?? null,80),$data['template'] ?? null);
	}
}
