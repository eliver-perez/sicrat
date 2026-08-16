<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class GenderRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function getAll(): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                id,
                codigo,
                genero
            FROM generos
            ORDER BY id ASC
        ");

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function existsById(string $id): bool
    {
        $stmt = $this->db->prepare("
            SELECT 1
            FROM generos
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute([
            'id' => $id
        ]);

        return (bool) $stmt->fetchColumn();
    }
}