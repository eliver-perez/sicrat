<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class PersonsRepository
{
    public function __construct(private PDO $db) {
    }

    public function getConnection() : PDO {
        return $this->db;
    }

    public function getAll(array $data): array {
        $sql = "
            SELECT
                p.id,
                p.uuid,
                TRIM(
                    CONCAT(
                        p.nombre, ' ',
                        COALESCE(p.paterno, ''), ' ',
                        COALESCE(p.materno, '')
                    )
                ) nombre,
                se.seccion,
                COALESCE(r.nombre, 'N/D') registro,
                COALESCE(DATE_FORMAT(p.f_registro, '%d/%m/%Y %r'), '') f_registro
            FROM personas p
                INNER JOIN secciones_electorales se
                    ON se.id = p.seccion
                LEFT JOIN usuarios r
                    ON r.id = p.registrado_por
            WHERE 1=1
        ";

        if($data['section'] != null)
            $sql .= " AND se.uuid = :seccion";

        $params = [];

        $fields = ["TRIM(
                        CONCAT(
                            p.nombre, ' ',
                            COALESCE(p.paterno, ''), ' ',
                            COALESCE(p.materno, '')
                        )
                    )",
                    'p.nombre',
                    'p.paterno',
                    'p.materno',
                    'se.seccion',
                    'r.registro'];

        $conditions = [];
        $params = [];

        foreach ($fields as $i => $field) {
            $param = "search_$i";
            $conditions[] = "$field LIKE :$param";
            $params[$param] = '%' . $data['search'] . '%';
        }

        $sql .= " AND (" . implode(' OR ', $conditions) . ")";

        $sql .= "
            ORDER BY p.paterno, p.nombre ASC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->db->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
        }

        if($data['section'] != null)
            $stmt->bindValue(':seccion', $data['section'], PDO::PARAM_STR);
        $stmt->bindValue(':limit', $data['limit'], PDO::PARAM_INT);
        $stmt->bindValue(':offset', $data['offset'], PDO::PARAM_INT);

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function sectionExists(array $data): bool {
        $stmt = $this->db->prepare("
            SELECT 1
            FROM secciones_electorales
            WHERE seccion = :section
                AND estado = :state
                AND municipio = :municipality
            LIMIT 1
        ");
        $stmt->bindParam(':section', $data['section']);
        $stmt->bindParam(':state', $data['state']);
        $stmt->bindParam(':municipality', $data['municipality']);
        $stmt->execute();

        return (bool) $stmt->fetchColumn();
    }

    public function insertPerson(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO personas (
                uuid,
                organizacion,
                seccion,
                genero,
                nombre,
                paterno,
                materno,
                registrado_por,
                f_registro
            ) VALUES (
                :uuid,
                :organizacion,
                :seccion,
                :genero,
                :nombre,
                :paterno,
                :materno,
                :registro,
                NOW()
            )
        ");

        $stmt->bindValue(':uuid', $data['uuid'], PDO::PARAM_LOB);
        $stmt->bindValue(':organizacion', $data['organization'], PDO::PARAM_INT);
        $stmt->bindValue(':seccion', $data['section'], PDO::PARAM_INT);
        $stmt->bindValue(':genero', $data['gender'], PDO::PARAM_STR);
        $stmt->bindValue(':nombre', $data['name'], PDO::PARAM_STR);
        $stmt->bindValue(':paterno', $data['last_name'], PDO::PARAM_STR);
        $stmt->bindValue(':materno', $data['last_name_2'], PDO::PARAM_STR);
        $stmt->bindValue(':registro', $data['uid'], PDO::PARAM_INT);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }
}