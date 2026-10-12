<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\HttpError;
use App\Core\Pagination;
use App\Services\Common\Values;
use App\Services\Operations\Catalog;

class OperationsRepository extends CommonRepository
{
	public function raw(string $entity,string|int $reference,bool $lock = false): array
	{
		Catalog::entity($entity);
		$key=is_int($reference) ? 'id' : 'uuid'; $value=is_int($reference) ? $reference : Values::binary($reference);
		return $this->one("SELECT * FROM $entity WHERE $key=?".($lock ? ' FOR UPDATE' : ''),[$value]) ?? throw new HttpError('Registro no disponible.',404);
	}
	public function page(string $sql,array $parameters,array $filters = []): array
	{
		$limit=Pagination::limit($filters['limit'] ?? 25); $offset=Pagination::offset($filters['offset'] ?? 0);
		$rows=$this->rows($sql.' LIMIT '.($limit+1).' OFFSET '.$offset,$parameters);
		return ['rows'=>array_slice($rows,0,$limit),'pagination'=>Pagination::response($rows,$limit,$offset)];
	}
	public function insertRecord(string $entity,array $data): array
	{
		Catalog::entity($entity); $uuid=Values::uuid(); $data=['uuid'=>Values::binary($uuid),...$data];
		$columns=implode(',',array_keys($data)); $marks=implode(',',array_fill(0,count($data),'?'));
		$id=$this->insert("INSERT INTO $entity ($columns) VALUES ($marks)",array_values($data));
		return ['id'=>$id,'uuid'=>$uuid];
	}
	public function updateRecord(string $entity,int $id,array $data): void
	{
		Catalog::entity($entity); $set=implode(',',array_map(static fn($column)=>$column.'=?',array_keys($data)));
		$this->execute("UPDATE $entity SET $set,version=version+1 WHERE id=?",[...array_values($data),$id]);
	}
	public function folio(int $organization,string $entity): string
	{
		Catalog::entity($entity); $year=(int) date('Y');
		$this->execute('INSERT INTO operaciones_folios (organizacion,entidad,anio) VALUES (?,?,?) ON DUPLICATE KEY UPDATE consecutivo=consecutivo',[$organization,$entity,$year]);
		$row=$this->one('SELECT consecutivo FROM operaciones_folios WHERE organizacion=? AND entidad=? AND anio=? FOR UPDATE',[$organization,$entity,$year]);
		$number=(int) $row['consecutivo']+1;
		$this->execute('UPDATE operaciones_folios SET consecutivo=? WHERE organizacion=? AND entidad=? AND anio=?',[$number,$organization,$entity,$year]);
		return ($entity==='peticiones' ? 'PET' : 'INC').'-'.$year.'-'.str_pad((string) $number,6,'0',STR_PAD_LEFT);
	}
}
