<?php

declare(strict_types=1);

namespace App\Auth;

use PDO;

class SessionValidator
{
    public function __construct(private PDO $db)
    {
    }

    // La BD es la autoridad: no se confía en tipos, organización ni permisos de la cookie PHP.
    public function validate(): bool
    {
        $userId = $_SESSION['SICRAT_ID'] ?? null;
        $token = $_SESSION['SICRAT_AUTH_TOKEN'] ?? null;
        $salt = env('HMAC_SALT');
        if (!is_int($userId) || $userId <= 0 || !is_string($token) || strlen($token) !== 64
            || !ctype_xdigit($token) || !is_string($salt) || $salt === '') {
            return false;
        }

        $statement = $this->db->prepare("
            SELECT s.id sesion_id, u.id, u.uuid, u.organizacion, u.email, u.nombre,
                   u.paterno, u.materno, ut.id tipo_id, ut.codigo tipo_codigo, ut.tipo
            FROM usuarios_sesiones s
                INNER JOIN usuarios u ON u.id = s.usuario
                INNER JOIN usuarios_tipos ut ON ut.id = u.tipo_usuario
            WHERE s.usuario = :usuario AND s.token_hash = :token
                AND s.destruida_en IS NULL AND s.expira_en > :ahora AND u.activo = 1
            LIMIT 1
        ");
        $statement->bindValue(':usuario', $userId, PDO::PARAM_INT);
        $statement->bindValue(':token', hash_hmac('sha256', hex2bin($token), $salt, true), PDO::PARAM_LOB);
        $statement->bindValue(':ahora', date('Y-m-d H:i:s'));
        $statement->execute();
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return false;
        }

        $organization = $user['organizacion'] === null ? null : (int) $user['organizacion'];
        $roleId = $_SESSION['SICRAT_USER_ROLE'] ?? null;
        $role = null;
        if ($roleId !== null) {
            $statement = $this->db->prepare("
                SELECT up.id, up.proceso, pe.proceso proceso_nombre, pe.organizacion, ut.id tipo_id, ut.codigo, ut.tipo
                FROM usuarios_procesos up
                    INNER JOIN procesos_electorales pe ON pe.id = up.proceso
                    INNER JOIN usuarios_tipos ut ON ut.id = up.tipo_usuario
                WHERE up.id = :rol AND up.usuario = :usuario
                    AND up.activo = 1 AND up.f_baja IS NULL AND pe.estatus = 1
                LIMIT 1
            ");
            $statement->execute(['rol' => $roleId, 'usuario' => $userId]);
            $role = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$role || ($user['tipo_codigo'] !== 'superadmin' && ($organization === null || $organization !== (int) $role['organizacion']))) {
                return false;
            }
            $organization = (int) $role['organizacion'];
        }

        $inherited = [];
        if ($role) {
            $statement = $this->db->prepare('SELECT permiso, valor FROM permisos_usuarios_tipo WHERE tipo = :tipo');
            $statement->execute(['tipo' => $role['tipo_id']]);
            $inherited = $statement->fetchAll(PDO::FETCH_ASSOC);
            // Super Administrador es un privilegio de cuenta; un tipo electoral no lo concede.
            if ($user['tipo_codigo'] !== 'superadmin') {
                $inherited = array_values(array_filter($inherited, static fn (array $permission): bool => $permission['permiso'] !== 'superadmin'));
            }
        }
        $statement = $this->db->prepare('SELECT permiso, valor FROM permisos_usuarios WHERE usuario = :usuario AND (organizacion IS NULL OR organizacion = :organizacion)');
        $statement->execute(['usuario' => $userId, 'organizacion' => $organization]);
        $individual = $statement->fetchAll(PDO::FETCH_ASSOC);

        $_SESSION['SICRAT_UUID'] = (new \App\Services\AuthService())->uuidBinaryToString($user['uuid']);
        $_SESSION['SICRAT_GLOBAL_TYPE_CODE'] = $user['tipo_codigo'];
        $_SESSION['SICRAT_ORGANIZATION_ID'] = $organization;
        if ($user['tipo_codigo'] === 'superadmin') {
            // La selección facilita el trabajo pero no recorta la autorización global.
            $selected = $_SESSION['SICRAT_ORGANIZATION_PROCESS_ID'] ?? null;
            $statement = $this->db->prepare('SELECT id, proceso FROM procesos_electorales WHERE id = ? AND estatus = 1');
            $statement->execute([$selected]);
            $selectedProcess = $statement->fetch(PDO::FETCH_ASSOC);
            $_SESSION['SICRAT_ORGANIZATION_PROCESS_ID'] = $selectedProcess ? (int) $selected : null;
            $_SESSION['SICRAT_ORGANIZATION_PROCESS'] = $selectedProcess['proceso'] ?? null;
        } else {
            $_SESSION['SICRAT_ORGANIZATION_PROCESS_ID'] = $role ? (int) $role['proceso'] : null;
            $_SESSION['SICRAT_ORGANIZATION_PROCESS'] = $role['proceso_nombre'] ?? null;
        }
        $_SESSION['SICRAT_USER_TYPE_ID'] = (int) ($role['tipo_id'] ?? $user['tipo_id']);
        $_SESSION['SICRAT_USER_TYPE_CODE'] = $role['codigo'] ?? $user['tipo_codigo'];
        $_SESSION['SICRAT_USER_TYPE'] = $role['tipo'] ?? $user['tipo'];
        $_SESSION['SICRAT_NAME'] = trim(implode(' ', [$user['nombre'], $user['paterno'] ?? '', $user['materno'] ?? '']));
        $_SESSION['SICRAT_EMAIL'] = $user['email'];
        $_SESSION['SICRAT_RIGHTS'] = Authorization::effectivePermissions($inherited, $individual);

        $statement = $this->db->prepare('UPDATE usuarios_sesiones SET ultima_actividad = NOW() WHERE id = :id');
        $statement->bindValue(':id', $user['sesion_id'], PDO::PARAM_LOB);
        $statement->execute();
        $_SESSION['SICRAT_LAST_ACTIVITY'] = time();
        return true;
    }
}
