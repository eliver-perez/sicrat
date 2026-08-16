<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class ElectoralProcessRepository
{
    public function __construct(private PDO $db) {
    }

    public function getConnection() : PDO {
        return $this->db;
    }

    public function getTypes(): array {
        $sql = "
            SELECT
                te.id,
                te.clave,
                te.tipo,
                te.ambito,
                te.activo
            FROM tipos_eleccion te
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCharacters(): array {
        $sql = "
            SELECT
                ce.id,
                ce.clave,
                ce.caracter,
                ce.activo
            FROM caracter_eleccion ce
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAll(array $data): array {
        $sql = "
            SELECT
                pe.id,
                pe.uuid,
                o.organizacion,
                pe.proceso,
                te.tipo,
                ce.caracter,
                pe.estatus,
                COALESCE(r.nombre, 'N/D') registro,
                COALESCE(DATE_FORMAT(pe.f_registro, '%d/%m/%Y %r'), '') f_registro
            FROM procesos_electorales pe
                INNER JOIN tipos_eleccion te
                    ON pe.tipo = te.id
                INNER JOIN caracter_eleccion ce
                    ON pe.caracter = ce.id
                INNER JOIN organizaciones o
                    ON pe.organizacion = o.id
                LEFT JOIN usuarios r
                    ON pe.registro = r.id
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

    public function getElectoralProcessId($data): ?int {
        $stmt = $this->db->prepare("
            SELECT id
            FROM procesos_electorales
            WHERE uuid = :uuid
            LIMIT 1");
        $stmt->bindValue(':uuid', $data['uuid'], PDO::PARAM_LOB);
        $stmt->execute();
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    public function insertProcess(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO procesos_electorales (
                uuid,
                organizacion,
                proceso,
                tipo,
                caracter,
                f_eleccion,
                f_inicio,
                f_fin,
                estatus,
                registro,
                f_registro
            ) VALUES (
                :uuid,
                :organizacion,
                :proceso,
                :tipo,
                :caracter,
                :f_eleccion,
                :f_inicio,
                :f_fin,
                :estatus,
                :registro,
                NOW()
            )
        ");

        $stmt->bindValue(':uuid', $data['uuid'], PDO::PARAM_LOB);
        $stmt->bindValue(':organizacion', $data['organization'], PDO::PARAM_INT);
        $stmt->bindValue(':proceso', $data['process'], PDO::PARAM_STR);
        $stmt->bindValue(':tipo', $data['type'], PDO::PARAM_INT);
        $stmt->bindValue(':caracter', $data['character'], PDO::PARAM_INT);
        $stmt->bindValue(':f_eleccion', $data['election_date'], PDO::PARAM_STR);
        $stmt->bindValue(':f_inicio', $data['start_date'], PDO::PARAM_STR);
        $stmt->bindValue(':f_fin', $data['end_date'], PDO::PARAM_STR);
        $stmt->bindValue(':estatus', $data['status'], PDO::PARAM_INT);
        $stmt->bindValue(':registro', $data['uid'], PDO::PARAM_INT);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }
}