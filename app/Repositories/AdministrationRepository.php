<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class AdministrationRepository
{
    public function __construct(private PDO $db) {}
    public function connection(): PDO { return $this->db; }
    public function rows(string $sql, array $params = []): array
    {
        $statement = $this->db->prepare($sql); $statement->execute($params); return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
    public function execute(string $sql, array $params): int
    {
        $statement = $this->db->prepare($sql); $statement->execute($params); return $statement->rowCount();
    }
}
