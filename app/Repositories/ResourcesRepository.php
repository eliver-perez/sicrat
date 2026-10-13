<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\HttpError;
use App\Core\Pagination;
use App\Services\Common\Values;
use App\Services\Resources\Catalog;

class ResourcesRepository extends CommonRepository
{
	public function raw(string $entity,string|int $reference,bool $lock = false): array
	{
		$table=Catalog::entity($entity)['table'];
		$key=is_int($reference) ? 'id' : 'uuid';
		return $this->one("SELECT * FROM $table WHERE $key=?".($lock ? ' FOR UPDATE' : ''),[is_int($reference) ? $reference : Values::binary($reference)]) ?? throw new HttpError('Registro no disponible.',404);
	}

	public function lock(int $organization): void
	{
		// Orden común para finanzas, inventario y resolución de aprobaciones del recurso.
		$this->execute('INSERT INTO recursos_control (organizacion) VALUES (?) ON DUPLICATE KEY UPDATE organizacion=organizacion',[$organization]);
		$this->one('SELECT organizacion FROM recursos_control WHERE organizacion=? FOR UPDATE',[$organization]);
	}

	public function write(int $organization,callable $work): mixed {
		return $this->transaction(function() use($organization,$work) {
			$this->lock($organization);
			return $work();
		});
	}

	public function create(string $entity,array $data): array
	{
		$table=Catalog::entity($entity)['table'];
		$uuid=Values::uuid();
		$data=['uuid'=>Values::binary($uuid),...$data,'fecha'=>date('Y-m-d H:i:s')];
		$this->columns($data);
		$columns=implode(',',array_keys($data));
		$marks=implode(',',array_fill(0,count($data),'?'));
		try {
			$id=$this->insert("INSERT INTO $table ($columns) VALUES ($marks)",array_values($data));
		}
		catch(\PDOException $error) {
			if(($error->errorInfo[1] ?? null)===1062) throw new HttpError('La referencia o clave ya está registrada. Actualiza la consulta antes de repetir.',409);
			throw $error;
		}
		return $this->raw($entity,$id,true);
	}

	public function update(string $entity,int $id,array $data): void
	{
		$table=Catalog::entity($entity)['table'];
		$this->columns($data);
		$set=implode(',',array_map(static fn($column)=>$column.'=?',array_keys($data)));
		$this->execute("UPDATE $table SET $set,version=version+1 WHERE id=?",[...array_values($data),$id]);
	}

	private function columns(array $data): void {
		foreach(array_keys($data) as $column) if(!preg_match('/^[a-z_]+$/D',$column)) throw new \InvalidArgumentException('Columna interna inválida.');
	}

	public function folio(int $organization,string $entity): string
	{
		$prefix=Catalog::prefix($entity);
		$year=(int) date('Y');
		$this->execute('INSERT INTO recursos_folios (organizacion,entidad,anio) VALUES (?,?,?) ON DUPLICATE KEY UPDATE consecutivo=consecutivo',[$organization,$entity,$year]);
		$number=(int) $this->one('SELECT consecutivo FROM recursos_folios WHERE organizacion=? AND entidad=? AND anio=? FOR UPDATE',[$organization,$entity,$year])['consecutivo']+1;
		$this->execute('UPDATE recursos_folios SET consecutivo=? WHERE organizacion=? AND entidad=? AND anio=?',[$number,$organization,$entity,$year]);
		return $prefix.'-'.$year.'-'.str_pad((string) $number,6,'0',STR_PAD_LEFT);
	}

	public function page(string $sql,array $params,array $filters = []): array
	{
		$limit=Pagination::limit($filters['limit'] ?? 25);
		$offset=Pagination::offset($filters['offset'] ?? 0);
		$rows=$this->rows($sql.' LIMIT '.($limit+1).' OFFSET '.$offset,$params);
		return ['rows'=>array_slice($rows,0,$limit),'pagination'=>Pagination::response($rows,$limit,$offset)];
	}
}
