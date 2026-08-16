<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class UsersRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function getConnection() : PDO {
        return $this->db;
    }

    public function getAll(array $data): array
    {
        $sql = "
            SELECT
                u.id,
                u.uuid,
                u.email,
                u.usuario,
                TRIM(
                    CONCAT(
                        u.nombre, ' ',
                        COALESCE(u.paterno, ''), ' ',
                        COALESCE(u.materno, '')
                    )
                ) nombre,
                ut.tipo,
                u.activo,
                COALESCE(DATE_FORMAT(u.f_registro, '%d/%m/%Y %r'), '') f_registro,
                COALESCE(DATE_FORMAT(u.f_ultima_conexion, '%d/%m/%Y %r'), '') f_ultima_conexion
            FROM usuarios u
                LEFT JOIN usuarios_tipos ut
                    ON ut.id = u.tipo_usuario
        ";

        $params = [];

        if ($data['search'] !== null && $data['search'] !== '') {
            $sql .= " AND usuario LIKE :search";
            $params['search'] = '%' . $data['search'] . '%';
        }

        $sql .= " ORDER BY u.paterno, u.nombre ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getUserIdByUuid($uuid): ?int {
        $stmt = $this->db->prepare("
            SELECT id
            FROM usuarios
            WHERE uuid = :uuid
            LIMIT 1");
        $stmt->bindValue(':uuid', $uuid, PDO::PARAM_LOB);
        $stmt->execute();
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    public function getUserTypeCodeById(int $id): ?string {
        $stmt = $this->db->prepare("
            SELECT ut.codigo
            FROM usuarios u
                INNER JOIN usuarios_tipos ut
                    ON u.tipo_usuario = ut.id
            WHERE u.id = :id
            LIMIT 1");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchColumn();
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT
                p.id,
                CONCAT(COALESCE(p.nombre, ''),
                        COALESCE(CONCAT(' ', p.paterno), ''),
                        COALESCE(CONCAT(' ', p.materno), '')) nombre,
                CONCAT(COALESCE(p.calle, ''),
                        COALESCE(CONCAT(' ', p.num_ext), ''),
                        COALESCE(CONCAT(', ', p.num_int), ', '),
                        COALESCE(CONCAT(', ', c.colonia), ', '),
                        COALESCE(CONCAT(', ', m.municipio), ', '),
                        COALESCE(e.estado, '')) domicilio,
                p.email,
                p.telefono,
                p.estatus,
                p.f_registro,
                p.f_actualizacion
            FROM personal p
                LEFT JOIN colonias c
                    ON p.colonia = c.id
                LEFT JOIN municipios m
                    ON c.municipio = m.id
                LEFT JOIN estados e
                    ON m.estado = e.id
            WHERE p.id = :id
        ");

        $stmt->execute([
            'id' => $id,
        ]);

        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function userExists(string $email): bool {
        $stmt = $this->db->prepare("
            SELECT 1
            FROM usuarios
            WHERE email = :email
            LIMIT 1
        ");

        $stmt->execute([
            'email' => $email
        ]);

        return (bool) $stmt->fetchColumn();
    }

    public function insertUser(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO usuarios (
                uuid,
                organizacion,
                usuario,
                email,
                nombre,
                paterno,
                materno,
                password_hash,
                tipo_usuario,
                activo,
                registro,
                f_registro
            ) VALUES (
                :uuid,
                :organizacion,
                :usuario,
                :email,
                :nombre,
                :paterno,
                :materno,
                :password_hash,
                :tipo_usuario,
                1,
                :registro,
                NOW()
            )
        ");

        $stmt->bindParam('uuid', $data['uuid'], PDO::PARAM_LOB);
        $stmt->bindParam('organizacion', $data['organization'], PDO::PARAM_INT);
        $stmt->bindParam('usuario', $data['username'], PDO::PARAM_STR);
        $stmt->bindParam('email', $data['email'], PDO::PARAM_STR);
        $stmt->bindParam('nombre', $data['name'], PDO::PARAM_STR);
        $stmt->bindParam('paterno', $data['last_name'], PDO::PARAM_STR);
        $stmt->bindParam('materno', $data['second_last_name'], PDO::PARAM_STR);
        $stmt->bindParam('password_hash', $data['password'], PDO::PARAM_STR);
        $stmt->bindParam('tipo_usuario', $data['user_type'], PDO::PARAM_INT);
        $stmt->bindParam('registro', $data['uid'], PDO::PARAM_INT);

        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }
    
    public function insertUserProcess(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO usuarios_procesos (
                usuario,
                proceso,
                tipo_usuario,
                activo,
                f_registro
            ) VALUES (
                :usuario,
                :proceso,
                :tipo_usuario,
                1,
                NOW()
            )
        ");
        $stmt->bindParam('usuario', $data['user'], PDO::PARAM_INT);
        $stmt->bindParam('proceso', $data['process'], PDO::PARAM_INT);
        $stmt->bindParam('tipo_usuario', $data['user_type'], PDO::PARAM_INT);
        $stmt->execute();
        return (int) $this->db->lastInsertId();
    }
}