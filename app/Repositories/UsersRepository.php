<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use App\Auth\Authorization;

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
        $sql = "SELECT u.id,
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
                    WHERE 1 = 1
        ";

        $params = [];
        $organization = Authorization::scopeOrganizationId();
        if ($organization !== null) {
            $sql .= ' AND u.organizacion = :organizacion';
            $params['organizacion'] = $organization;
        }

        if ($data['search'] !== null && $data['search'] !== '') {
            $sql .= " AND u.usuario LIKE :search";
            $params['search'] = '%' . $data['search'] . '%';
        }

        $sql .= " ORDER BY u.paterno, u.nombre, u.id ASC LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $data['limit'], PDO::PARAM_INT);
        $stmt->bindValue(':offset', $data['offset'], PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getUserData(array $data): ?array
    {
        $sql = "SELECT u.id,
                        u.uuid,
                        u.email,
                        u.usuario,
                        u.password_hash,
                        TRIM(
                            CONCAT(
                                u.nombre, ' ',
                                COALESCE(u.paterno, ''), ' ',
                                COALESCE(u.materno, '')
                            )
                        ) nombre,
                        ut.tipo,
                        TRIM(
                            CONCAT(
                                r.nombre, ' ',
                                COALESCE(r.paterno, ''), ' ',
                                COALESCE(r.materno, '')
                            )
                        ) registro,
                        u.activo,
                        COALESCE(DATE_FORMAT(u.f_registro, '%d/%m/%Y %r'), '') f_registro,
                        COALESCE(DATE_FORMAT(u.f_ultima_conexion, '%d/%m/%Y %r'), '') f_ultima_conexion
                    FROM usuarios u
                        INNER JOIN usuarios_tipos ut
                            ON ut.id = u.tipo_usuario
                        LEFT JOIN usuarios r
                            ON r.id = u.registro
                    WHERE u.uuid = :uuid";

        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':uuid', $data['uuid'], PDO::PARAM_LOB);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
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

    public function getUserUuid($id) {
        $stmt = $this->db->prepare("
            SELECT uuid
            FROM usuarios
            WHERE id = :id
            LIMIT 1");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $id = $stmt->fetchColumn();

        return $id !== false ? $id : null;
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
        $stmt = $this->db->prepare('SELECT id, uuid, organizacion, usuario, nombre, email, activo FROM usuarios WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            Authorization::assertOrganization($user['organizacion'] === null ? null : (int) $user['organizacion']);
        }
        return $user ?: null;
    }

    public function userExists(string $username): bool {
        $stmt = $this->db->prepare("
            SELECT 1
            FROM usuarios
            WHERE usuario = :usuario
            LIMIT 1
        ");

        $stmt->execute([
            'usuario' => $username
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
                :activo,
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
        $stmt->bindValue('activo', $data['status'], PDO::PARAM_INT);

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
                :activo,
                NOW()
            )
        ");
        $stmt->bindParam('usuario', $data['user'], PDO::PARAM_INT);
        $stmt->bindParam('proceso', $data['process'], PDO::PARAM_INT);
        $stmt->bindParam('tipo_usuario', $data['user_type'], PDO::PARAM_INT);
        $stmt->bindValue('activo', 1, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $this->db->lastInsertId();
    }
    
    public function updatePassword(array $data) {
        $stmt = $this->db->prepare("
            UPDATE usuarios SET password_hash = :password WHERE uuid = :uuid
        ");
        $stmt->bindParam('password', $data['password'], PDO::PARAM_STR);
        $stmt->bindParam('uuid', $data['uuid'], PDO::PARAM_LOB);
        $stmt->execute();
    }
    public function userTypeExists(int $type): bool {
        $statement = $this->db->prepare('SELECT 1 FROM usuarios_tipos WHERE id = :tipo');
        $statement->execute(['tipo' => $type]);
        return (bool) $statement->fetchColumn();
    }
}