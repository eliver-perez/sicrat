<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Auth\Authorization;
use App\Core\HttpError;
use PDO;

class UserAccessRepository
{
    public function __construct(private PDO $db) {}
    public function connection(): PDO { return $this->db; }
    public function user(string $uuid): array
    {
        $statement = $this->db->prepare('SELECT u.id, u.organizacion, u.tipo_usuario, ut.codigo tipo_codigo, u.persona, p.uuid persona_uuid, p.codigo_persona FROM usuarios u JOIN usuarios_tipos ut ON ut.id=u.tipo_usuario LEFT JOIN personas p ON p.id=u.persona WHERE u.uuid=?');
        $statement->execute([$uuid]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$user) throw new HttpError('La cuenta no está disponible.', 404);
        if ($user['organizacion'] === null && !\App\Core\Auth::isSuperAdmin()) throw new HttpError('La cuenta no está disponible.', 404);
        if ($user['organizacion'] !== null) Authorization::assertOrganization((int) $user['organizacion']);
        return $user;
    }
    public function accesses(int $user): array
    {
        $statement = $this->db->prepare('SELECT pe.uuid, pe.proceso, up.tipo_usuario, ut.tipo, up.activo FROM usuarios_procesos up JOIN procesos_electorales pe ON pe.id=up.proceso JOIN usuarios_tipos ut ON ut.id=up.tipo_usuario WHERE up.usuario=? ORDER BY pe.proceso,pe.id');
        $statement->execute([$user]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
    public function typeCode(int $type): ?string
    {
        $statement = $this->db->prepare('SELECT codigo FROM usuarios_tipos WHERE id=?');
        $statement->execute([$type]);
        $code = $statement->fetchColumn();
        return $code === false ? null : (string) $code;
    }
    public function assign(int $user, int $process, int $type, int $active): void
    {
        $statement = $this->db->prepare('INSERT INTO usuarios_procesos (usuario,proceso,tipo_usuario,activo,f_registro,f_baja) VALUES (?,?,?,?,NOW(),?) ON DUPLICATE KEY UPDATE tipo_usuario=VALUES(tipo_usuario),activo=VALUES(activo),f_baja=VALUES(f_baja)');
        $statement->execute([$user,$process,$type,$active,$active === 1 ? null : date('Y-m-d H:i:s')]);
    }
    public function link(int $user, ?int $person): void
    {
        $statement = $this->db->prepare('UPDATE usuarios SET persona=?,f_actualizacion=NOW() WHERE id=?');
        $statement->execute([$person,$user]);
    }
}
