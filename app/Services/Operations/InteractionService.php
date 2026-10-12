<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Core\HttpError;
use App\Services\Common\Values;

class InteractionService extends BaseService
{
	protected function entity(): string { return 'interacciones'; }
	public function create(array $data): array
	{
		$processes=Catalog::list($data['processes'] ?? [$this->context->process($data['process'] ?? null)],20);
		if (!$processes) throw new \InvalidArgumentException('Selecciona una o varias elecciones.');
		$processes=array_values(array_unique(array_map(fn($process)=>$this->context->process($process),$processes)));
		return $this->repository->transaction(function() use($data,$processes) {
			$person=null; $organization=null;
			foreach ($processes as $process) {
				$context=$this->context->require('interacciones',$process,'interacciones.crear');
				$current=$this->context->person($data['person'] ?? null,'interacciones.crear',$process);
				if ($organization!==null && $organization!==$context['organization']) throw new HttpError('Las elecciones deben pertenecer a la misma organización.',422);
				$organization=$context['organization']; $person ??= $current;
			}
		$type=$this->repository->one('SELECT id FROM tipos_interaccion WHERE codigo=?',[$data['type'] ?? 'OTRO'])['id'] ?? throw new \InvalidArgumentException('Tipo de interacción inválido.');
			$date=Catalog::dateTime($data['date'] ?? date('Y-m-d H:i:s'));
			if ($date>date('Y-m-d H:i:s')) throw new \InvalidArgumentException('Una interacción realizada no puede estar fechada en el futuro.');
			$task=null;
			if (($data['task'] ?? '')!=='') {
				$task=$this->context->record('tareas',$data['task'],'tareas.ver');
				if ((int) $task['organizacion']!==$organization || !in_array((int) $task['proceso'],$processes,true)) throw new HttpError('La tarea debe corresponder a una de las elecciones relacionadas.',422);
			}
			$row=$this->newRow('interacciones',['organizacion'=>$organization,'proceso'=>$processes[0],'persona'=>$person['id'],'persona_proceso'=>$person['participation'],'tipo'=>$type,'usuario'=>$this->context->actor(),'resultado'=>Values::text($data['result'] ?? null,150),'notas'=>Values::text($data['notes'] ?? '',10000,true),'f_interaccion'=>$date,'f_registro'=>date('Y-m-d H:i:s'),'tarea'=>$task['id'] ?? null,'estado'=>'registrada']);
			foreach ($processes as $process) $this->repository->execute('INSERT INTO interacciones_procesos (interaccion,proceso) VALUES (?,?)',[$row['id'],$process]);
			$taskState=null;
			if (Values::integer($data['complete_task'] ?? 0,0,1)===1) {
				if ($task===null || ($data['task_item'] ?? '')==='') throw new \InvalidArgumentException('Selecciona la tarea y su asignación para completarla.');
				$taskState=(new TaskService($this->repository))->state($task['uuid'],['item'=>$data['task_item'],'state'=>'completada','reason'=>'Cierre solicitado al registrar una interacción.']);
			}
			return ['uuid'=>$row['uuid'],'task'=>$taskState];
		});
	}
	public function detail(string $uuid): array
	{
		$process=$this->context->process(); $row=$this->context->record('interacciones',$uuid,'interacciones.ver',null,false,$process);
		$row['processes']=[];
		foreach ($this->repository->rows('SELECT p.id,p.proceso nombre FROM interacciones_procesos ip JOIN procesos_electorales p ON p.id=ip.proceso WHERE ip.interaccion=? ORDER BY p.id',[$row['id']]) as $candidate) {
			try { $this->context->record('interacciones',(int) $row['id'],'interacciones.ver',null,false,(int) $candidate['id']); $row['processes'][]=$candidate; }
			catch(HttpError) {}
		}
		$row['type']=$this->repository->one('SELECT tipo FROM tipos_interaccion WHERE id=?',[$row['tipo']])['tipo'];
		$row['persona_nombre']=$this->repository->one("SELECT CONCAT_WS(' ',nombre,paterno,materno) nombre FROM personas WHERE id=?",[$row['persona']])['nombre'];
		$row['proceso']=$process;
		unset($row['persona_proceso']);
		if ($row['tarea']!==null) {
			try { $task=$this->context->record('tareas',(int) $row['tarea'],'tareas.ver'); $row['task_uuid']=$task['uuid']; }
			catch(HttpError) { $row['tarea']=null; }
		}
		return $row;
	}
}
