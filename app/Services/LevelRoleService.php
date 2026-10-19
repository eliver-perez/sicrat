<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Service;
use App\Repositories\UserAccessRepository;
use InvalidArgumentException;
use PDO;

/** Relación electoral unidireccional: nivel -> rol. Las escrituras usan la transacción del llamador. */
class LevelRoleService extends Service
{
    public const MISSING_ROLE = 'Este nivel no tiene un rol asociado. Configura el rol correspondiente antes de continuar.';

    public function __construct(private PDO $db) {}

    public function validateRole(int $role, int $organization): void
    {
        $query = $this->db->prepare("SELECT id FROM usuarios_tipos WHERE id=? AND codigo<>'superadmin' AND (organizacion IS NULL OR organizacion=?)");
        $query->execute([$role, $organization]);
        if (!$query->fetchColumn()) throw new InvalidArgumentException('El rol no está disponible para esta organización.');
    }

    public function roleForLevel(?int $level, int $process): int
    {
        $query = $this->db->prepare('SELECT n.rol_asociado,p.organizacion FROM niveles_organizacionales n JOIN procesos_electorales p ON p.id=n.proceso WHERE n.id=? AND n.proceso=? AND n.activo=1');
        $query->execute([$level, $process]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['rol_asociado'] === null) throw new InvalidArgumentException(self::MISSING_ROLE);
        $this->validateRole((int) $row['rol_asociado'], (int) $row['organizacion']);
        return (int) $row['rol_asociado'];
    }

    public function roleForPerson(int $person, int $process): ?int
    {
        $query = $this->db->prepare('SELECT ep.nivel FROM estructura_integrantes_procesos ep JOIN estructura e ON e.id=ep.integrante JOIN estructuras_organizacionales c ON c.id=e.contenedor JOIN estructuras_procesos cp ON cp.estructura=c.id AND cp.proceso=ep.proceso WHERE ep.persona=? AND ep.proceso=? AND ep.activo=1 AND e.activo=1 AND c.activo=1 AND cp.activo=1');
        $query->execute([$person, $process]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->roleForLevel($row['nivel'] === null ? null : (int) $row['nivel'], $process) : null;
    }

    public function synchronize(int $person, int $process, ?int $level): void
    {
        if (!$this->db->inTransaction()) throw new \LogicException('El cambio de nivel y rol requiere una transacción.');
        $query = $this->db->prepare('SELECT u.uuid,p.uuid proceso_uuid,up.activo,up.f_baja,up.tipo_usuario FROM usuarios u JOIN procesos_electorales p ON p.id=? AND p.organizacion=u.organizacion LEFT JOIN usuarios_procesos up ON up.usuario=u.id AND up.proceso=p.id WHERE u.persona=?');
        $query->execute([$process, $person]);
        $account = $query->fetch(PDO::FETCH_ASSOC);
        if (!$account) return;
        $role = $this->roleForLevel($level, $process);
        if ((int)($account['tipo_usuario'] ?? 0) === $role) return;
        // Cambiar el rol nunca reactiva un acceso electoral retirado ni concede uno nuevo activo.
        (new UserAccessService(new UserAccessRepository($this->db)))->assign($this->uuidBinaryToString($account['uuid']), [
            'process'=>$this->uuidBinaryToString($account['proceso_uuid']), 'type'=>$role,
            'active'=>(int) ($account['activo'] ?? 0) === 1 && $account['f_baja'] === null ? 1 : 0,
        ]);
    }
}
