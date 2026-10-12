<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Auth;
use App\Core\HttpError;
use PDO;

class ElectoralContextRepository
{
    public function __construct(private PDO $db) {}

    public function available(int $limit = 100, int $offset = 0): array
    {
        $sql = 'SELECT pe.id, pe.uuid, pe.organizacion, pe.proceso, o.organizacion organizacion_nombre,
                       up.id acceso_id, ut.id tipo_id, ut.codigo, ut.tipo
                FROM procesos_electorales pe JOIN organizaciones o ON o.id = pe.organizacion ';
        if (Auth::isSuperAdmin()) {
            $sql .= ' LEFT JOIN usuarios_procesos up ON up.proceso = pe.id AND up.usuario = :usuario AND up.activo=1 AND up.f_baja IS NULL
                      LEFT JOIN usuarios_tipos ut ON ut.id = up.tipo_usuario WHERE pe.estatus=1';
        } else {
            $sql .= ' JOIN usuarios_procesos up ON up.proceso = pe.id
                      JOIN usuarios_tipos ut ON ut.id = up.tipo_usuario
                      JOIN usuarios u ON u.id = up.usuario
                      WHERE pe.estatus=1 AND up.usuario=:usuario AND up.activo=1 AND up.f_baja IS NULL
                      AND pe.organizacion=u.organizacion';
        }
        $statement = $this->db->prepare($sql . ' ORDER BY o.organizacion, pe.proceso, pe.id LIMIT :limite OFFSET :inicio');
        $statement->bindValue(':usuario', Auth::id(), PDO::PARAM_INT);
        $statement->bindValue(':limite', $limit, PDO::PARAM_INT);
        $statement->bindValue(':inicio', $offset, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function authorized(string $binaryUuid, ?int $organization = null, bool $active = true): array
    {
        $statement = $this->db->prepare('SELECT * FROM procesos_electorales WHERE uuid = ?');
        $statement->execute([$binaryUuid]);
        $process = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$process || ($active && (int) $process['estatus'] !== 1)
            || ($organization !== null && (int) $process['organizacion'] !== $organization)) {
            throw new HttpError('La elección no está disponible.', 404);
        }
        if (!Auth::isSuperAdmin()) {
            $statement = $this->db->prepare('SELECT up.id, up.tipo_usuario FROM usuarios_procesos up JOIN usuarios u ON u.id=up.usuario WHERE up.proceso=? AND up.usuario=? AND up.activo=1 AND up.f_baja IS NULL AND u.organizacion=?');
            $statement->execute([$process['id'], Auth::id(), $process['organizacion']]);
            $access = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$access) throw new HttpError('La elección no está disponible.', 404);
            $process['access_id'] = (int) $access['id'];
        }
        return $process;
    }
}
