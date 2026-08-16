<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class UsersTypesRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function getConnection() : PDO {
        return $this->db;
    }

    public function getAll(): array {
        $sql = "
            SELECT
                ut.id,
                ut.codigo,
                ut.tipo
            FROM usuarios_tipos ut
            WHERE ut.codigo != 'superadmin'
            ORDER BY ut.tipo
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getIdByCode(string $code): int {
        $sql = "
            SELECT
                ut.id
            FROM usuarios_tipos ut
            WHERE ut.codigo = :codigo
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':codigo', $code, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchColumn();
    }
}