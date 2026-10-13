<?php

declare(strict_types=1);

namespace App\Services\Resources;

use App\Core\HttpError;
use App\Repositories\CommonRepository;
use App\Repositories\ResourcesRepository;

final class Related
{
	public function __construct(private CommonRepository $repository) {
	}

	public function financial(string $entity,int $id,int $process): array
	{
		$result=[];
		$resources=new ResourcesRepository($this->repository->connection());
		$context=new Context($resources);
		if(!$context->allowed('finanzas.ver',$process) || !(int) ($resources->one("SELECT implementado FROM modulos WHERE codigo='finanzas'")['implementado'] ?? 0)) return [];
		foreach(['gastos','anticipos','reembolsos'] as $type) {
			try {
				[$where,$params]=$context->predicate($type,'finanzas.ver',$process);
				$table=Catalog::entity($type)['table'];
				$rows=$resources->rows("SELECT r.uuid,r.folio,r.concepto,r.estado FROM $table r WHERE ($where) AND r.relacion_entidad=? AND r.relacion_id=? ORDER BY r.id DESC LIMIT 25",[...$params,$entity,$id]);
				foreach($rows as $row) $result[]=['tipo'=>$type,...Context::present($row)];
			} catch(HttpError) {
			}
		}
		return $result;
	}

	public function eventInventory(int $id,int $process): array
	{
		$resources=new ResourcesRepository($this->repository->connection());
		$context=new Context($resources);
		if(!$context->allowed('inventario.ver',$process) || !(int) ($resources->one("SELECT implementado FROM modulos WHERE codigo='inventario'")['implementado'] ?? 0)) return [];
		try {
			[$where,$params]=$context->predicate('movimientos','inventario.ver',$process);
			return $resources->rows("SELECT item.nombre articulo,item.unidad,r.tipo,SUM(ABS(r.cantidad)) cantidad FROM inventario_movimientos r JOIN inventario_articulos item ON item.id=r.articulo WHERE ($where) AND r.evento=? AND r.estado='aplicado' AND NOT EXISTS (SELECT 1 FROM inventario_movimientos reversal WHERE reversal.reversa_de=r.id) AND r.tipo IN ('entrega','consumo','prestamo','devolucion','perdida','dano') GROUP BY item.id,item.nombre,item.unidad,r.tipo ORDER BY item.nombre,r.tipo",[...$params,$id]);
		} catch(HttpError) {
			return [];
		}
	}
}
