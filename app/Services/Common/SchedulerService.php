<?php

declare(strict_types=1);

namespace App\Services\Common;

use App\Core\HttpError;
use App\Repositories\CommonRepository;

class SchedulerService
{
	public function __construct(private CommonRepository $repository) {}
	public function run(?\DateTimeImmutable $now = null): array
	{
		$now ??= new \DateTimeImmutable();
		(new \App\Services\Operations\TaskService($this->repository))->schedule($now);
		(new \App\Services\Resources\Alerts($this->repository))->schedule($now);
		(new \App\Services\Jornada\Notifications($this->repository))->schedule($now);
		$actor=$this->repository->one("SELECT u.id FROM usuarios u JOIN usuarios_tipos t ON t.id=u.tipo_usuario WHERE t.codigo='superadmin' AND u.activo=1 ORDER BY u.id LIMIT 1")['id'] ?? throw new ServiceFailure('No existe Super Administrador activo para programar respaldos.',false);
		$policy=$this->repository->one('SELECT * FROM respaldos_politica WHERE id=1'); $day=$now->format('Y-m-d'); $scheduled=[];
		(new QueueService($this->repository))->enqueue('mantenimiento',[],null,null,(int) $actor,'mantenimiento:'.$day,-10,10);
		$types=[];
		if ((int) $policy['bd_diaria']) $types[]='bd';
		if ((int) $policy['archivos_full_dia']===(int) $now->format('w') || !$this->repository->one("SELECT id FROM respaldos WHERE tipo IN ('archivos_full','completo') AND estado='completado' LIMIT 1")) $types[]='archivos_full';
		elseif ((int) $policy['archivos_incremental']) $types[]='incremental';
		foreach ($types as $type) {
			try { $scheduled[]=(new BackupService($this->repository))->request($type,'programado:'.$type.':'.$day,(int) $actor); }
			catch (HttpError $error) { if ($error->statusCode!==409) throw $error; }
		}
		// Cron vuelve a invocarlo: cada tipo/fecha es idempotente y la siguiente copia espera la anterior.
		return $scheduled;
	}
}
