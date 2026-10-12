<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Core\HttpError;
use App\Repositories\ScopeRepository;
use App\Services\Common\ActorContext;
use App\Services\Common\ApprovalService;
use App\Services\Common\NotificationService;
use App\Services\Common\QueueService;
use App\Services\Common\Values;

class TaskService extends BaseService
{
	protected function entity(): string { return 'tareas'; }
	public function create(array $data): array
	{
		$process=$this->context->process($data['process'] ?? null); $context=$this->context->require('tareas',$process,'tareas.crear');
		$mode=Catalog::choice($data['mode'] ?? 'individual',['individual','colaborativa']);
		$start=Catalog::dateTime($data['start'] ?? date('Y-m-d H:i:s')); $deadline=Catalog::dateTime($data['deadline'] ?? null,true);
		if ($deadline!==null && $deadline<$start) throw new \InvalidArgumentException('La fecha límite debe ser posterior al inicio.');
		$assignments=Catalog::list($data['assignees'] ?? []); $subtasks=Catalog::list($data['subtasks'] ?? []);
		if (($mode==='individual' && (!$assignments || $subtasks)) || ($mode==='colaborativa' && (!$subtasks || $assignments))) throw new \InvalidArgumentException('Selecciona asignados para la tarea individual o subtareas para la colaborativa.');
		$priority=$this->repository->one('SELECT id FROM tareas_prioridades WHERE codigo=? AND activo=1',[$data['priority'] ?? 'NORMAL'])['id'] ?? throw new \InvalidArgumentException('Prioridad inválida.');
		$recurrence=$data['recurrence'] ?? null;
		if ($recurrence!==null) {
			if (!is_array($recurrence)) throw new \InvalidArgumentException('Recurrencia inválida.');
			$recurrence=['frequency'=>Catalog::choice($recurrence['frequency'] ?? null,['diario','semanal','mensual']),'every'=>Values::integer($recurrence['every'] ?? 1,1,31),'until'=>Values::date($recurrence['until'] ?? null)];
			if ($recurrence['until']<substr($start,0,10)) throw new \InvalidArgumentException('La recurrencia termina antes del inicio.');
		}
		return $this->repository->transaction(function() use($data,$context,$process,$mode,$start,$deadline,$assignments,$subtasks,$priority,$recurrence) {
			$person=$this->personReference($data['person'] ?? null,'tareas.crear',$process);
			$link=$this->linkedEntity($data['related_entity'] ?? null,$data['related_record'] ?? null,$process,'tareas.crear');
			$row=$this->newRow('tareas',['organizacion'=>$context['organization'],'proceso'=>$process,'persona'=>$person['id'] ?? ($link && $link['entity']==='personas' ? $link['record'] : null),'persona_proceso'=>$person['participation'] ?? null,'asignado_a'=>null,'creado_por'=>$context['actor'],'titulo'=>Values::text($data['title'] ?? null,200),'descripcion'=>Values::text($data['description'] ?? '',10000,true),'prioridad'=>$priority,'estatus'=>1,'estado'=>'pendiente','modalidad'=>$mode,'fecha_inicio'=>$start,'fecha_limite'=>$deadline,'f_registro'=>date('Y-m-d H:i:s'),'requiere_validacion'=>Values::integer($data['validation'] ?? 0,0,1),'recurrencia'=>$recurrence===null ? null : Values::json($recurrence),'ocurrencia'=>substr($start,0,10),'relacion_entidad'=>$link['entity'] ?? null,'relacion_id'=>$link['record'] ?? null,'permite_pdf'=>Values::integer($data['allow_pdf'] ?? 0,0,1)]);
			if (($data['approver'] ?? '')!=='') {
				$approver=$this->context->approver('tareas',$row,'tareas.validar',$data['approver']);
				$this->repository->execute('UPDATE tareas SET aprobador=? WHERE id=?',[$approver,$row['id']]);
			}
			$seen=[];
			foreach ($assignments as $reference) {
				$assigned=$this->context->person($reference,'tareas.crear',$process);
				if (isset($seen[$assigned['id']])) throw new \InvalidArgumentException('Hay una persona asignada más de una vez.'); $seen[$assigned['id']]=true;
				$this->repository->execute('INSERT INTO tareas_asignaciones (uuid,tarea,persona) VALUES (?,?,?)',[Values::binary(Values::uuid()),$row['id'],$assigned['id']]);
				$this->notify($assigned,$row);
			}
			foreach ($subtasks as $subtask) {
				if (!is_array($subtask)) throw new \InvalidArgumentException('Subtarea inválida.');
				$assigned=$this->personReference($subtask['person'] ?? null,'tareas.crear',$process);
				$this->repository->execute('INSERT INTO tareas_subtareas (uuid,tarea,titulo,persona) VALUES (?,?,?,?)',[Values::binary(Values::uuid()),$row['id'],Values::text($subtask['title'] ?? null,200),$assigned['id'] ?? null]);
				if ($assigned) $this->notify($assigned,$row);
			}
			return ['uuid'=>$row['uuid']];
		});
	}
	private function notify(array $person,array $task): void
	{
		$user=$this->repository->one('SELECT id FROM usuarios WHERE persona=? AND activo=1',[$person['id']]);
		if ($user && $this->context->allowed('tareas.ver',(int) $task['proceso'],(int) $user['id'])) (new NotificationService($this->repository))->internal((int) $user['id'],(int) $task['organizacion'],(int) $task['proceso'],'tarea_asignada','Nueva tarea asignada','Revisa tus tareas en la elección correspondiente.','tareas',$task['uuid'],'/operations/?module=tareas');
	}
	public function detail(string $uuid): array
	{
		$row=parent::detail($uuid); $row['recurrencia']=$row['recurrencia']===null ? null : json_decode($row['recurrencia'],true);
		$row['progress']=$this->progress($row); $row['items']=$this->items($uuid,[]);
		return $row;
	}
	private function itemTable(array $row): string { return $row['modalidad']==='individual' ? 'tareas_asignaciones' : 'tareas_subtareas'; }
	public function progress(array $row): array
	{
		$table=$this->itemTable($row);
		$result=$this->repository->one("SELECT COUNT(*) total,COALESCE(SUM(estado='completada'),0) completed,COALESCE(SUM(estado='validacion'),0) validation FROM $table WHERE tarea=?",[$row['id']]);
		return array_map('intval',$result);
	}
	public function items(string $uuid,array $filters): array
	{
		$row=$this->context->record('tareas',$uuid,'tareas.ver'); $table=$this->itemTable($row);
		$context=$this->context->require('tareas',(int) $row['proceso'],'tareas.ver'); $where='item.tarea=?'; $params=[$row['id']];
		if (!$this->context->broad($context,'tareas.ver')) {
			[$scope,$args]=(new ScopeRepository($this->repository->connection()))->predicate('tareas.ver',(int) $row['proceso']);
			$legacy=$table==='tareas_asignaciones' ? ' OR item.usuario_historico=?' : '';
			$where.=" AND (EXISTS (SELECT 1 FROM personas p WHERE p.id=item.persona AND ($scope)) OR (?=1 AND item.persona IS NULL)$legacy)";
			$params=[...$params,...$args,(int) $row['creado_por']===$context['actor'] ? 1 : 0];
			if ($legacy!=='') $params[]=in_array('propio',$context['effective']['scopes']['tareas.ver'] ?? [],true) ? $context['actor'] : 0;
		}
		$title=$table==='tareas_subtareas' ? 'item.titulo' : 'NULL';
		$result=$this->repository->page("SELECT item.id,item.uuid,item.estado,item.version,item.completada_en,item.aprobacion,approval.uuid approval_uuid,$title titulo,p.uuid persona_uuid,CONCAT_WS(' ',p.nombre,p.paterno,p.materno) persona_nombre FROM $table item LEFT JOIN personas p ON p.id=item.persona LEFT JOIN aprobaciones approval ON approval.id=item.aprobacion WHERE $where ORDER BY item.id",$params,$filters);
		foreach ($result['rows'] as &$item) { $item['uuid']=Values::uuidText($item['uuid']); if ($item['persona_uuid']!==null) $item['persona_uuid']=Values::uuidText($item['persona_uuid']); if ($item['approval_uuid']!==null) $item['approval_uuid']=Values::uuidText($item['approval_uuid']); }
		return $result;
	}
	public function authorizeItem(string $table,int $id,string $permission,?int $actor = null,?string $additional = null): array
	{
		Catalog::choice($table,['tareas_asignaciones','tareas_subtareas']);
		$item=$this->repository->one("SELECT * FROM $table WHERE id=?",[$id]) ?? throw new HttpError('Asignación no disponible.',404);
		$task=$this->context->record('tareas',(int) $item['tarea'],$permission,$actor); $actor ??= $this->context->actor();
		if ($item['persona']!==null) (new ScopeRepository($this->repository->connection()))->assertPerson((int) $item['persona'],$permission,(int) $task['proceso'],$actor);
		elseif ((int) $task['creado_por']!==$actor) {
			$effective=$this->context->require('tareas',(int) $task['proceso'],$permission,$actor);
			if (!$this->context->broad($effective,$permission) && (int) ($item['usuario_historico'] ?? 0)!==$actor) throw new HttpError('Subtarea fuera de tu alcance.',404);
		}
		if ($additional!==null) {
			$this->context->record('tareas',(int) $task['id'],$additional,$actor);
			if ($item['persona']!==null) (new ScopeRepository($this->repository->connection()))->assertPerson((int) $item['persona'],$additional,(int) $task['proceso'],$actor);
		}
		return [$item,$task];
	}
	public function state(string $uuid,array $data): array
	{
		return $this->repository->transaction(function() use($uuid,$data) {
			$row=$this->context->record('tareas',$uuid,'tareas.completar',null,true); $table=$this->itemTable($row);
			$item=$this->repository->one("SELECT * FROM $table WHERE uuid=? AND tarea=? FOR UPDATE",[Values::binary($data['item'] ?? null),$row['id']]) ?? throw new HttpError('Asignación no disponible.',404);
			$this->authorizeItem($table,(int) $item['id'],'tareas.completar'); $this->context->version($item,$data);
			if ($row['estado']==='cancelada' || $item['estado']==='validacion' || $item['estado']==='completada') throw new HttpError('La asignación ya fue cerrada o está pendiente de validación.',409);
			$state=Catalog::choice($data['state'] ?? null,['pendiente','en_proceso','completada']); $approval=null;
			if ($state==='completada' && (int) $row['requiere_validacion']===1) {
				$reference=$data['approver'] ?? ($row['aprobador'] ? Values::uuidText($this->repository->one('SELECT uuid FROM usuarios WHERE id=?',[$row['aprobador']])['uuid']) : null);
				$approver=$this->context->approver('tareas',[...$row,'responsable'=>$item['persona']],'tareas.validar',$reference);
				$approval=(new ApprovalService($this->repository))->create((int) $row['organizacion'],(int) $row['proceso'],$table,(int) $item['id'],$this->context->actor(),[$approver],['version'=>(int) $item['version']+1,'parent_version'=>(int) $row['version']],['titulo'=>$row['titulo'],'operacion'=>'Validar cierre de tarea']);
				$approvalId=$this->repository->one('SELECT id FROM aprobaciones WHERE uuid=?',[Values::binary($approval)])['id']; $state='validacion';
			} else $approvalId=null;
			$this->repository->execute("UPDATE $table SET estado=?,version=version+1,aprobacion=?,completada_en=? WHERE id=?",[$state,$approvalId,$state==='completada' ? date('Y-m-d H:i:s') : null,$item['id']]);
			$this->refreshState((int) $row['id']);
			$this->context->history('tareas',$row,'actualizar_estado',Values::text($data['reason'] ?? '',500,true),['estado'=>$item['estado']],['estado'=>$state]);
			return ['state'=>$state,'approval'=>$approval];
		});
	}
	public function refreshState(int $id): void
	{
		$row=$this->repository->raw('tareas',$id); $progress=$this->progress($row);
		$state=$progress['total']>0 && $progress['completed']===$progress['total'] ? 'completada' : ($progress['validation']>0 ? 'validacion' : 'en_proceso');
		$this->repository->execute('UPDATE tareas SET estado=?,estatus=?,f_finalizacion=? WHERE id=?',[$state,$state==='completada' ? 3 : 2,$state==='completada' ? date('Y-m-d H:i:s') : null,$id]);
	}
	public function update(string $uuid,array $data): void
	{
		$this->repository->transaction(function() use($uuid,$data) {
			$row=$this->context->record('tareas',$uuid,'tareas.editar',null,true); $this->context->version($row,$data);
			$table=$this->itemTable($row);
			foreach ($this->repository->rows("SELECT persona FROM $table WHERE tarea=? AND persona IS NOT NULL",[$row['id']]) as $assigned) $this->context->person((int) $assigned['persona'],'tareas.editar',(int) $row['proceso'],false);
			if ($this->repository->one("SELECT id FROM $table WHERE tarea=? AND estado='validacion' LIMIT 1",[$row['id']])) throw new HttpError('Resuelve o cancela las validaciones antes de editar.',409);
			$changes=['titulo'=>Values::text($data['title'] ?? $row['titulo'],200),'descripcion'=>Values::text($data['description'] ?? $row['descripcion'] ?? '',10000,true),'fecha_limite'=>Catalog::dateTime($data['deadline'] ?? $row['fecha_limite'],true)];
			if ($changes['fecha_limite']!==null && $changes['fecha_limite']<$row['fecha_inicio']) throw new \InvalidArgumentException('Fecha límite inválida.');
			if (($data['state'] ?? '')==='cancelada') { $changes['estado']='cancelada'; $changes['estatus']=4; }
			$this->repository->updateRecord('tareas',(int) $row['id'],$changes); $this->context->history('tareas',$row,'editar','',null,$changes);
		});
	}
	public static function occurrence(string $first,int $number,array $rule): string
	{
		$date=new \DateTimeImmutable($first); $every=(int) $rule['every'];
		if ($rule['frequency']!=='mensual') return $date->modify('+'.($number*$every*($rule['frequency']==='semanal' ? 7 : 1)).' days')->format('Y-m-d');
		$month=$date->modify('first day of this month')->modify('+'.($number*$every).' months');
		return $month->setDate((int) $month->format('Y'),(int) $month->format('m'),min((int) $date->format('d'),(int) $month->format('t')))->format('Y-m-d');
	}
	public function schedule(\DateTimeImmutable $now): int
	{
		$count=0; $last=0;
		do {
			$rows=$this->repository->rows("SELECT * FROM tareas WHERE recurrencia IS NOT NULL AND serie_recurrente IS NULL AND estado<>'cancelada' AND id>? ORDER BY id LIMIT 100",[$last]);
			foreach ($rows as $row) {
				$last=(int) $row['id']; $rule=json_decode($row['recurrencia'],true,512,JSON_THROW_ON_ERROR); $first=substr($row['fecha_inicio'],0,10);
				for ($n=1;$n<=10000;$n++) {
					$day=self::occurrence($first,$n,$rule); if ($day>$now->format('Y-m-d') || $day>$rule['until']) break;
					if ($this->repository->one('SELECT id FROM tareas WHERE serie_recurrente=? AND ocurrencia=?',[$row['id'],$day])) continue;
					try { $this->context->require('tareas',(int) $row['proceso'],'tareas.crear',(int) $row['creado_por']); }
					catch(HttpError) { break; }
					(new QueueService($this->repository))->enqueue('tarea_recurrente',['task'=>(int) $row['id'],'day'=>$day],(int) $row['organizacion'],(int) $row['proceso'],(int) $row['creado_por'],'tarea:'.$row['id'].':'.$day);
					if (++$count>=100) return $count;
				}
			}
		} while(count($rows)===100);
		return $count;
	}
	public function recur(int $id,string $day): void
	{
		Values::date($day);
		$this->repository->transaction(function() use($id,$day) {
			$row=$this->context->record('tareas',$id,'tareas.crear',null,true);
			if ($row['recurrencia']===null || $row['serie_recurrente']!==null || $row['estado']==='cancelada') throw new HttpError('La serie recurrente no está disponible.',409);
			if ($this->repository->one('SELECT id FROM tareas WHERE serie_recurrente=? AND ocurrencia=?',[$id,$day])) return;
			$rule=json_decode($row['recurrencia'],true); $valid=false;
			for ($n=1;$n<=10000;$n++) { $candidate=self::occurrence(substr($row['fecha_inicio'],0,10),$n,$rule); if ($candidate===$day) { $valid=true; break; } if ($candidate>$day) break; }
			if (!$valid || $day>$rule['until'] || $day>date('Y-m-d')) throw new \InvalidArgumentException('Ocurrencia inválida.');
			$table=$this->itemTable($row); $items=$this->repository->rows("SELECT item.*,p.uuid persona_uuid FROM $table item LEFT JOIN personas p ON p.id=item.persona WHERE tarea=? ORDER BY item.id LIMIT 101",[$id]);
			if (count($items)>100) throw new HttpError('La serie excede el límite de asignaciones.',422);
			$references=array_map(static fn($item)=>$item['persona_uuid']===null ? null : Values::uuidText($item['persona_uuid']),$items);
			$start=$day.substr($row['fecha_inicio'],10); $deadline=$row['fecha_limite']===null ? null : (new \DateTimeImmutable($start))->modify('+'.max(0,strtotime($row['fecha_limite'])-strtotime($row['fecha_inicio'])).' seconds')->format('Y-m-d H:i:s');
			$data=['process'=>$row['proceso'],'title'=>$row['titulo'],'description'=>$row['descripcion'],'priority'=>$this->repository->one('SELECT codigo FROM tareas_prioridades WHERE id=?',[$row['prioridad']])['codigo'],'start'=>$start,'deadline'=>$deadline,'mode'=>$row['modalidad'],'validation'=>$row['requiere_validacion'],'allow_pdf'=>$row['permite_pdf']];
			if ($row['modalidad']==='individual') $data['assignees']=$references;
			else $data['subtasks']=array_map(static fn($item,$reference)=>['title'=>$item['titulo'],'person'=>$reference],$items,$references);
			if ($row['persona']!==null) $data['person']=Values::uuidText($this->repository->one('SELECT uuid FROM personas WHERE id=?',[$row['persona']])['uuid']);
			if ($row['aprobador']!==null) $data['approver']=Values::uuidText($this->repository->one('SELECT uuid FROM usuarios WHERE id=?',[$row['aprobador']])['uuid']);
			if ($row['relacion_entidad']!==null) { $data['related_entity']=$row['relacion_entidad']; $data['related_record']=$row['relacion_entidad']==='personas' ? $row['relacion_id'] : Values::uuidText($this->repository->raw($row['relacion_entidad'],(int) $row['relacion_id'])['uuid']); }
			$created=$this->create($data); $this->repository->execute('UPDATE tareas SET serie_recurrente=? WHERE uuid=?',[$id,Values::binary($created['uuid'])]);
		});
	}
}
