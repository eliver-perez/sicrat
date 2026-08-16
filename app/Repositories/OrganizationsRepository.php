<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class OrganizationsRepository
{
    public function __construct(private PDO $db) {
    }

    public function getConnection() : PDO {
        return $this->db;
    }

    public function getAll(array $data): array {
        $sql = "
            SELECT
                o.id,
                o.uuid,
                o.organizacion,
                o.contacto,
                o.telefono,
                o.email,
                o.activo,
                COALESCE(r.nombre, 'N/D') registro,
                COALESCE(DATE_FORMAT(o.f_registro, '%d/%m/%Y %r'), '') f_registro
            FROM organizaciones o
                LEFT JOIN usuarios r
                    ON o.registro = r.id
            WHERE 1 = 1
        ";

        $params = [];

        $fields = ['o.organizacion', 'o.contacto', 'o.telefono', 'o.email', 'r.nombre'];

        $conditions = [];
        $params = [];

        foreach ($fields as $i => $field) {
            $param = "search_$i";
            $conditions[] = "$field LIKE :$param";
            $params[$param] = '%' . $data['search'] . '%';
        }

        $sql .= " AND (" . implode(' OR ', $conditions) . ")";

        $sql .= "
            ORDER BY o.organizacion ASC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->db->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
        }

        $stmt->bindValue(':limit', $data['limit'], PDO::PARAM_INT);
        $stmt->bindValue(':offset', $data['offset'], PDO::PARAM_INT);

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getOrganizationId($data): ?int {
        $stmt = $this->db->prepare("
            SELECT id
            FROM organizaciones
            WHERE uuid = :uuid
            LIMIT 1");
        $stmt->bindValue(':uuid', $data['uuid'], PDO::PARAM_LOB);
        $stmt->execute();
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    public function insertOrganization(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO organizaciones (
                uuid,
                organizacion,
                contacto,
                telefono,
                email,
                activo,
                registro,
                f_registro
            ) VALUES (
                :uuid,
                :organizacion,
                :contacto,
                :telefono,
                :email,
                :activo,
                :registro,
                NOW()
            )
        ");

        $stmt->bindValue(':uuid', $data['uuid'], PDO::PARAM_LOB);
        $stmt->bindValue(':organizacion', $data['organization'], PDO::PARAM_STR);
        $stmt->bindValue(':contacto', $data['contact'], PDO::PARAM_STR);
        $stmt->bindValue(':telefono', $data['phone'], PDO::PARAM_STR);
        $stmt->bindValue(':email', $data['email'], PDO::PARAM_STR);
        $stmt->bindValue(':activo', $data['active'], PDO::PARAM_INT);
        $stmt->bindValue(':registro', $data['uid'], PDO::PARAM_INT);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    public function getOrganizationData(array $data): ?array {
        $sql = "
            SELECT
                o.id,
                o.uuid,
                o.organizacion,
                o.contacto,
                o.telefono,
                o.email,
                o.activo,
                COALESCE(r.nombre, 'N/D') registro,
                COALESCE(DATE_FORMAT(o.f_registro, '%d/%m/%Y %r'), '') f_registro
            FROM organizaciones o
                LEFT JOIN usuarios r
                    ON o.registro = r.id
            WHERE o.uuid = :uuid
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':uuid', $data['uuid'], PDO::PARAM_LOB);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function getOrganizationProcesses(array $data) {
        $sql = "
            SELECT
                pe.id,
                pe.uuid,
                o.organizacion,
                pe.proceso,
                te.tipo,
                ce.caracter,
                COALESCE(DATE_FORMAT(pe.f_eleccion, '%d/%m/%Y %r'), '') f_eleccion,
                COALESCE(DATE_FORMAT(pe.f_inicio, '%d/%m/%Y %r'), '') f_inicio,
                COALESCE(DATE_FORMAT(pe.f_fin, '%d/%m/%Y %r'), '') f_fin,
                pe.estatus,
                COALESCE(r.nombre, 'N/D') registro,
                COALESCE(DATE_FORMAT(pe.f_registro, '%d/%m/%Y %r'), '') f_registro
            FROM procesos_electorales pe
                INNER JOIN organizaciones o
                    ON pe.organizacion = o.id
                INNER JOIN tipos_eleccion te
                    ON pe.tipo = te.id
                INNER JOIN caracter_eleccion ce
                    ON pe.caracter = ce.id
                LEFT JOIN usuarios r
                    ON pe.registro = r.id
            WHERE o.uuid = :uuid
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':uuid', $data['uuid'], PDO::PARAM_LOB);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}