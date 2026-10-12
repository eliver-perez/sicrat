<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class CommonRepository
{
	public function __construct(private PDO $db) {}
	public function connection(): PDO { return $this->db; }
	public function rows(string $sql, array $parameters = []): array
	{
		$statement = $this->db->prepare($sql);
		$statement->execute($parameters);
		return $statement->fetchAll(PDO::FETCH_ASSOC);
	}
	public function one(string $sql, array $parameters = []): ?array { return $this->rows($sql, $parameters)[0] ?? null; }
	public function execute(string $sql, array $parameters = []): int
	{
		$statement = $this->db->prepare($sql);
		$statement->execute($parameters);
		return $statement->rowCount();
	}
	public function insert(string $sql, array $parameters): int
	{
		$this->execute($sql, $parameters);
		return (int) $this->db->lastInsertId();
	}
	public function transaction(callable $work): mixed
	{
		$ownsTransaction = !$this->db->inTransaction();
		if ($ownsTransaction) $this->db->beginTransaction();
		try {
			$result = $work();
			if ($ownsTransaction) $this->db->commit();
			return $result;
		} catch (\Throwable $error) {
			if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
			throw $error;
		}
	}
}
