<?php

declare(strict_types=1);

namespace App\Services\Resources;

use App\Core\HttpError;
use App\Repositories\CommonRepository;
use App\Repositories\ResourcesRepository;
use App\Services\Common\ActorContext;
use App\Services\Common\NotificationService;
use App\Services\Common\QueueService;
use App\Services\Common\Values;

class Alerts
{
	private ResourcesRepository $repository;
	public function __construct(CommonRepository $repository) {
		$this->repository=new ResourcesRepository($repository->connection());
	}

	public function notify(int $recipient,array $row,string $title,string $summary,string $key): void
	{
		if(!$this->repository->one('SELECT id FROM usuarios WHERE id=? AND activo=1',[$recipient])) return;
		$this->repository->write((int) $row['organizacion'],function() use($recipient,$row,$title,$summary,$key) {
			$signature=hash('sha256',$row['organizacion'].':'.$recipient.':'.$key);
			if($this->repository->one('SELECT clave FROM recursos_avisos WHERE clave=?',[$signature])) return;
			$url=str_starts_with($key,'minimo:') ? '/resources/?module=inventario&tab=existencias' : (str_starts_with($key,'merma') ? '/resources/?module=inventario&tab=movimientos' : (str_starts_with($key,'aprobacion:') || str_starts_with($key,'resultado:') ? '/services/' : '/resources/?module=finanzas'));
			$uuid=(new NotificationService($this->repository))->internal($recipient,(int) $row['organizacion'],$row['proceso']===null ? null : (int) $row['proceso'],'recursos',$title,$summary,null,null,$url);
			$this->repository->execute('INSERT INTO recursos_avisos VALUES (?,?,NOW())',[$signature,Values::binary($uuid)]);
		});
	}

	public function pendingReceipt(array $row,string $day): void
	{
		$context=new Context($this->repository);
		try {
			$context->record('aportaciones',(int)$row['id'],'finanzas.ingresos.ver',false,(int)$row['creado_por']);
			$this->notify((int)$row['creado_por'],$row,'Donación pendiente de recepción','La donación validada espera confirmación física del almacén.','recepcion_pendiente:'.$row['id'].':'.$day);
		} catch(HttpError) {
		}
	}

	public function schedule(\DateTimeImmutable $now): void
	{
		$actor=$this->repository->one("SELECT u.id FROM usuarios u JOIN usuarios_tipos t ON t.id=u.tipo_usuario WHERE u.activo=1 AND t.codigo='superadmin' ORDER BY u.id LIMIT 1")['id'] ?? null;
		if($actor===null) return;
		(new QueueService($this->repository))->enqueue('recursos_alertas',['dia'=>$now->format('Y-m-d')],null,null,(int) $actor,'recursos-alertas:'.$now->format('Y-m-d'),-5,3);
	}

	public function check(string $day): void
	{
		Values::date($day);
		$context=new Context($this->repository);
		$last=0;
		do {
			$rows=$this->repository->rows("SELECT * FROM finanzas_anticipos WHERE entregado_en IS NOT NULL AND estado<>'cancelado' AND id>? ORDER BY id LIMIT 100",[$last]);
			foreach($rows as $row) {
				$last=(int) $row['id'];
				if(!$context->enabled('finanzas',(int) $row['organizacion'],$row['proceso']===null ? null : (int) $row['proceso'])) continue;
				$due=new \DateTimeImmutable($row['vence']);
				$dates=[$due->modify('-3 days')->format('Y-m-d'),$due->format('Y-m-d'),$due->modify('+7 days')->format('Y-m-d')];
				if(!in_array($day,$dates,true) || Amount::signed((new AdvanceService($this->repository))->balance((int) $row['id'])['saldo_comprobar'])===0) continue;
				try {
					$context->record('anticipos',(int) $row['id'],'finanzas.ver',false,(int) $row['creado_por']);
					$this->notify((int) $row['creado_por'],$row,'Anticipo pendiente de comprobación','Revisa el anticipo y su fecha de vencimiento.','anticipo:'.$row['id'].':'.$day);
				} catch(HttpError) {
				}
			}
		}
		while(count($rows)===100);
		$last=0;
		do {
			$donations=$this->repository->rows("SELECT r.*,DATE((SELECT MAX(h.fecha) FROM recursos_historial h WHERE h.entidad='finanzas_aportaciones' AND h.registro=r.id AND h.accion='validar')) validada_en FROM finanzas_aportaciones r WHERE r.tipo='especie' AND r.estado='validada' AND r.movimiento IS NULL AND r.id>? ORDER BY r.id LIMIT 100",[$last]);
			foreach($donations as $row) {
				$last=(int)$row['id'];
				if(!$row['validada_en'] || !$context->enabled('finanzas',(int)$row['organizacion'],$row['proceso']===null ? null : (int)$row['proceso']) || !$context->enabled('inventario',(int)$row['organizacion'],$row['proceso']===null ? null : (int)$row['proceso'])) continue;
				$validated=new \DateTimeImmutable($row['validada_en']);
				if(in_array($day,[$row['validada_en'],$validated->modify('+3 days')->format('Y-m-d'),$validated->modify('+7 days')->format('Y-m-d')],true)) $this->pendingReceipt($row,$day);
			}
		}
		while(count($donations)===100);
		$last=0;
		do {
			$warehouses=$this->repository->rows('SELECT * FROM inventario_almacenes WHERE activo=1 AND id>? ORDER BY id LIMIT 100',[$last]);
			foreach($warehouses as $warehouse) {
				$last=(int) $warehouse['id'];
				$elections=$this->repository->rows('SELECT id FROM procesos_electorales WHERE organizacion=? AND estatus=1',[$warehouse['organizacion']]);
				$recipients=$this->repository->rows('SELECT u.id FROM usuarios u WHERE u.organizacion=? AND u.activo=1',[$warehouse['organizacion']]);
				foreach($recipients as $user) foreach($elections as $election) {
					if(!$context->enabled('inventario',(int) $warehouse['organizacion'],(int) $election['id'])) continue;
					try {
						(new ActorContext($this->repository))->run((int) $user['id'],(int) $election['id'],function() use($warehouse,$user,$day) {
							$context=new Context($this->repository);
							$context->record('almacenes',(int) $warehouse['id'],'inventario.ver');
							$page=0;
							do {
								$result=(new InventoryService($this->repository))->stocks(['bajo_minimo'=>1,'offset'=>$page,'limit'=>100]);
								foreach($result['rows'] as $stock) {
									if($stock['almacen_uuid']!==Values::uuidText($warehouse['uuid'])) continue;
									$this->notify((int) $user['id'],[...$warehouse,'proceso'=>\App\Core\Auth::processId()],'Existencia bajo el mínimo','Revisa los artículos con existencias por debajo del mínimo configurado.','minimo:'.$stock['articulo_uuid'].':'.$stock['almacen_uuid'].':'.$stock['cantidad'].':'.$stock['minimo']);
								}
								$page+=100;
							}
							while($result['pagination']['has_more']);
						});
						break;
					} catch(HttpError) {
					}
				}
			}
		}
		while(count($warehouses)===100);
	}
}
