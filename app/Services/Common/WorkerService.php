<?php

declare(strict_types=1);

namespace App\Services\Common;

use App\Repositories\CommonRepository;

class WorkerService
{
	private array $handlers=[];
	public function __construct(private CommonRepository $repository)
	{
		$this->register('notificacion',fn ($payload)=>(new NotificationService($repository))->deliver($payload['envio_id']));
		$this->register('exportacion',fn ($payload)=>(new ExportService($repository))->generate((int) $payload['exportacion_id']));
		$this->register('jornada_resumen',fn($payload)=>(new \App\Services\Jornada\Notifications($repository))->prepare((int)$payload['aviso'],(int)$payload['slot']));
		$this->register('respaldo',fn ($payload)=>(new BackupService($repository))->perform($payload['respaldo_id']));
		$this->register('restauracion',fn ($payload)=>(new RestoreService($repository))->perform($payload['restauracion_id']));
		$this->register('verificar_respaldo',fn ($payload,$job)=>(new BackupService($repository))->verify($payload['respaldo_id'],(int) $job['usuario']));
		$this->register('mantenimiento',function ($payload,$job) use ($repository) {
			(new Access($repository))->super((int) $job['usuario']);
			(new ApprovalService($repository))->expire();
			$repository->execute('DELETE FROM personas_importaciones WHERE expira_en<NOW() LIMIT 100');
			(new BackupService($repository))->retention((int) $job['usuario']);
		});
		$this->register('tarea_recurrente',function ($payload,$job) use ($repository) {
			(new ActorContext($repository))->run((int) $job['usuario'],(int) $job['proceso'],fn()=>(new \App\Services\Operations\TaskService($repository))->recur((int) $payload['task'],$payload['day']));
		});
		$this->register('recursos_alertas',function ($payload,$job) use ($repository) {
			(new Access($repository))->super((int) $job['usuario']);
			(new ActorContext($repository))->run((int) $job['usuario'],null,fn()=>(new \App\Services\Resources\Alerts($repository))->check($payload['dia']));
		});
		$this->register('mensaje_notificar',function ($payload,$job) use ($repository) {
			(new ActorContext($repository))->run((int) $job['usuario'],(int) $job['proceso'],fn()=>(new \App\Services\Operations\MessagingService($repository))->notify((int) $payload['message']));
		});
	}
	public function register(string $type,callable $handler): void { $this->handlers[$type]=$handler; }
	public function run(int $maximum = 10,int $seconds = 60,?string $type = null): int
	{
		$queue=new QueueService($this->repository); $worker=gethostname().':'.getmypid().':'.bin2hex(random_bytes(4)); $deadline=microtime(true)+$seconds; $count=0;
		while ($count<$maximum && microtime(true)<$deadline) {
			$job=$queue->claim($worker,$type); if (!$job) break;
			$error=null;
			try {
				if (!isset($this->handlers[$job['tipo']])) throw new ServiceFailure('Handler de trabajo no registrado.',false);
				($this->handlers[$job['tipo']])($job['payload'],$job);
			} catch (\Throwable $failure) { $error=$failure; }
			$queue->finish($job,$error); $count++;
		}
		return $count;
	}
}
