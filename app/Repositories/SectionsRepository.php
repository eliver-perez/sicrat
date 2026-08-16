<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class SectionsRepository
{
    public function __construct(private PDO $db) {
    }

    public function getConnection() : PDO {
        return $this->db;
    }

    public function getAll(array $data): array {
        $sql = "
            SELECT
                se.id,
                se.uuid,
                se.seccion,
                e.estado,
                m.municipio,
                se.distrito_local,
                se.distrito_federal,
                COALESCE(r.nombre, 'N/D') registro,
                COALESCE(DATE_FORMAT(se.f_registro, '%d/%m/%Y %r'), '') f_registro
            FROM secciones_electorales se
                INNER JOIN estados e
                    ON e.id = se.estado
                LEFT JOIN municipios m
                    ON m.id = se.municipio
                LEFT JOIN usuarios r
                    ON se.registro = r.id
            WHERE 1=1
        ";

        $params = [];

        $fields = ['se.seccion', 'se.distrito_local', 'se.distrito_federal', 'e.estado', 'm.municipio'];

        $conditions = [];
        $params = [];

        foreach ($fields as $i => $field) {
            $param = "search_$i";
            $conditions[] = "$field LIKE :$param";
            $params[$param] = '%' . $data['search'] . '%';
        }

        $sql .= " AND (" . implode(' OR ', $conditions) . ")";

        $sql .= "
            ORDER BY se.seccion ASC
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

    public function getSectionId($data): ?int {
        $stmt = $this->db->prepare("
            SELECT id
            FROM secciones_electorales
            WHERE uuid = :uuid
            LIMIT 1");
        $stmt->bindValue(':uuid', $data['uuid'], PDO::PARAM_LOB);
        $stmt->execute();
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
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

    public function insertSection(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO secciones_electorales (
                uuid,
                estado,
                municipio,
                seccion,
                distrito_local,
                distrito_federal,
                registro,
                f_registro
            ) VALUES (
                :uuid,
                :estado,
                :municipio,
                :seccion,
                :distrito_local,
                :distrito_federal,
                :registro,
                NOW()
            )
        ");

        $stmt->bindValue(':uuid', $data['uuid'], PDO::PARAM_LOB);
        $stmt->bindValue(':estado', $data['state'], PDO::PARAM_INT);
        $stmt->bindValue(':municipio', $data['municipality'], PDO::PARAM_INT);
        $stmt->bindValue(':seccion', $data['section'], PDO::PARAM_INT);
        $stmt->bindValue(':distrito_local', $data['local_district'], PDO::PARAM_INT);
        $stmt->bindValue(':distrito_federal', $data['federal_district'], PDO::PARAM_STR);
        $stmt->bindValue(':registro', $data['uid'], PDO::PARAM_INT);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }
}