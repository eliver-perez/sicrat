<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Auth\Authorization;
use App\Core\HttpError;
use PDO;

class StructureRepository
{
    public function __construct(private PDO $db) {}
    public function connection(): PDO { return $this->db; }

    public function rows(string $sql, array $params = []): array
    {
        $statement = $this->db->prepare($sql); $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
    public function execute(string $sql, array $params = []): int
    {
        $statement = $this->db->prepare($sql); $statement->execute($params);
        return $statement->rowCount();
    }
    public function insert(string $sql, array $params): int { $this->execute($sql,$params); return (int) $this->db->lastInsertId(); }

    public function container(string $uuid): array
    {
        $rows = $this->rows('SELECT * FROM estructuras_organizacionales WHERE uuid=?',[$uuid]);
        if ($rows === []) throw new HttpError('La estructura no está disponible.',404);
        Authorization::assertOrganization((int) $rows[0]['organizacion']);
        return $rows[0];
    }
    public function lockOrganization(int $organization): void
    {
        $this->rows('SELECT id FROM organizaciones WHERE id=? FOR UPDATE',[$organization]);
    }
    public function elections(int $container): array
    {
        return $this->rows('SELECT pe.*,ep.activo asociacion_activa FROM estructuras_procesos ep JOIN procesos_electorales pe ON pe.id=ep.proceso WHERE ep.estructura=? AND ep.activo=1 ORDER BY pe.id',[$container]);
    }
    public function member(string $uuid, int $container): array
    {
        $rows = $this->rows('SELECT * FROM estructura WHERE uuid=? AND contenedor=?',[$uuid,$container]);
        if ($rows === []) throw new HttpError('El integrante no está disponible.',404);
        return $rows[0];
    }
    public function members(int $container, bool $activeOnly = false, ?array $ids = null): array
    {
        if ($ids === []) return [];
        $filter = $ids === null ? '' : ' AND e.id IN (' . implode(',',array_fill(0,count($ids),'?')) . ')';
        return $this->rows('SELECT e.*,p.uuid persona_uuid,p.codigo_persona,TRIM(CONCAT_WS(" ",p.nombre,p.paterno,p.materno)) nombre,u.uuid usuario_uuid,parent.uuid responsable_uuid FROM estructura e JOIN personas p ON p.id=e.persona LEFT JOIN usuarios u ON u.persona=p.id LEFT JOIN estructura parent ON parent.id=e.nodo_padre WHERE e.contenedor=?' . ($activeOnly ? ' AND e.activo=1' : '') . $filter . ' ORDER BY p.nombre,e.id',[$container,...($ids ?? [])]);
    }
    public function descendant(int $ancestor, int $candidate): bool
    {
        return $this->rows('SELECT 1 FROM estructura_relaciones WHERE ancestro=? AND descendiente=?',[$ancestor,$candidate]) !== [];
    }
    public function children(int $member): array { return $this->rows('SELECT * FROM estructura WHERE nodo_padre=? AND activo=1 ORDER BY id',[$member]); }
    public function team(int $member): array { return $this->rows('SELECT e.* FROM estructura_relaciones r JOIN estructura e ON e.id=r.descendiente WHERE r.ancestro=? AND e.activo=1 ORDER BY r.profundidad,e.id',[$member]); }

    public function addClosure(int $member, ?int $parent): void
    {
        $this->execute('INSERT INTO estructura_relaciones (ancestro,descendiente,profundidad) VALUES (?,?,0)',[$member,$member]);
        if ($parent !== null) $this->execute('INSERT INTO estructura_relaciones (ancestro,descendiente,profundidad) SELECT ancestro,?,profundidad+1 FROM estructura_relaciones WHERE descendiente=?',[$member,$parent]);
    }

    public function moveTeam(int $member, ?int $parent): void
    {
        if ($parent !== null && $this->descendant($member,$parent)) throw new \InvalidArgumentException('El cambio produciría un ciclo de responsabilidad.');
        // Sólo se reemplaza la frontera externa. Las relaciones internas permanecen intactas.
        $this->execute('DELETE rel FROM estructura_relaciones rel JOIN estructura_relaciones team ON team.ancestro=? AND team.descendiente=rel.descendiente LEFT JOIN estructura_relaciones internal ON internal.ancestro=? AND internal.descendiente=rel.ancestro WHERE internal.descendiente IS NULL',[$member,$member]);
        if ($parent !== null) $this->execute('INSERT INTO estructura_relaciones (ancestro,descendiente,profundidad) SELECT superior.ancestro,team.descendiente,superior.profundidad+team.profundidad+1 FROM estructura_relaciones superior CROSS JOIN estructura_relaciones team WHERE superior.descendiente=? AND team.ancestro=?',[$parent,$member]);
        $this->execute('UPDATE estructura SET nodo_padre=? WHERE id=?',[$parent,$member]);
    }

    public function history(int $container, ?int $member, string $action, mixed $before, mixed $after, ?int $process = null, ?string $reason = null): void
    {
        $this->execute('INSERT INTO estructura_historial (estructura,integrante,proceso,accion,usuario,antes,despues,motivo,fecha) VALUES (?,?,?,?,?,?,?,?,NOW())',[$container,$member,$process,$action,\App\Core\Auth::id(),$before === null ? null : json_encode($before,JSON_THROW_ON_ERROR),$after === null ? null : json_encode($after,JSON_THROW_ON_ERROR),$reason]);
        $organization = (int) $this->rows('SELECT organizacion FROM estructuras_organizacionales WHERE id=?',[$container])[0]['organizacion'];
        $elections = array_map('intval',array_column($this->elections($container),'id'));
        (new \App\Services\Common\AuditService(new CommonRepository($this->db)))->record($organization,$process,$action,'estructura',$member ?? $container,$before,$after,['contenedor'=>$container,'elecciones'=>$elections,'motivo'=>$reason]);
        $this->execute('UPDATE estructuras_organizacionales SET version=version+1 WHERE id=?',[$container]);
        (new \App\Services\Operations\MessagingService(new CommonRepository($this->db)))->synchronizeOrganization($organization);
    }
}
