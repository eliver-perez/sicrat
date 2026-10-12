<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Auth\Authorization;
use App\Core\Auth;
use App\Core\HttpError;
use PDO;

class PersonImportRepository
{
    public function __construct(private PDO $db) {}
    public function connection(): PDO { return $this->db; }
    public function acquire(string $id): void
    {
        $statement = $this->db->prepare('SELECT GET_LOCK(?,5)');
        $statement->execute(['sicrat-import:' . $id]);
        if (!(int) $statement->fetchColumn()) throw new HttpError('La importación está siendo procesada. Intenta nuevamente.',409);
    }
    public function release(string $id): void
    {
        $statement = $this->db->prepare('SELECT RELEASE_LOCK(?)');
        $statement->execute(['sicrat-import:' . $id]);
    }
    public function firstPending(string $id, bool $forApplication = false): ?int
    {
        $sql = $forApplication ? "SELECT MIN(fila) FROM personas_importacion_filas WHERE importacion=? AND estado IN ('listo','invalido')"
            : "SELECT MIN(fila) FROM personas_importacion_filas WHERE importacion=? AND estado='pendiente'";
        $statement = $this->db->prepare($sql);
        $statement->execute([$id]);
        $value = $statement->fetchColumn();
        return $value === null || $value === false ? null : (int) $value;
    }
    public function organizationUuid(int $id): string
    {
        $statement = $this->db->prepare('SELECT uuid FROM organizaciones WHERE id=?');
        $statement->execute([$id]);
        return $statement->fetchColumn();
    }
    public function create(string $id, int $organization, array $headers): void
    {
        $statement = $this->db->prepare('INSERT INTO personas_importaciones (id,organizacion,usuario,encabezados,f_registro,expira_en) VALUES (?,?,?,?,NOW(),DATE_ADD(NOW(),INTERVAL 1 DAY))');
        $statement->execute([$id,$organization,Auth::id(),json_encode($headers, JSON_THROW_ON_ERROR)]);
    }
    public function get(string $id): array
    {
        $statement = $this->db->prepare('SELECT * FROM personas_importaciones WHERE id=? AND usuario=? AND expira_en>NOW()');
        $statement->execute([$id,Auth::id()]);
        $import = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$import) throw new HttpError('La importación no está disponible o expiró.',404);
        Authorization::assertOrganization((int) $import['organizacion']);
        $effective = (new PermissionRepository($this->db))->require(Auth::id() ?? 0,'personas.importar',Auth::processId());
        if (!$effective['super'] && !in_array('organizacion',$effective['scopes']['personas.importar'],true)) throw new HttpError('La importación requiere alcance de organización para revisar identidades y coincidencias.',403);
        return $import;
    }
    public function rows(string $id, int $after, int $limit = 100): array
    {
        $statement = $this->db->prepare('SELECT * FROM personas_importacion_filas WHERE importacion=? AND fila>? ORDER BY fila LIMIT ' . min(100, max(1,$limit)));
        $statement->execute([$id,$after]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
    public function addRow(string $id, int $number, array $values): void
    {
        $statement = $this->db->prepare('INSERT INTO personas_importacion_filas (importacion,fila,datos) VALUES (?,?,?)');
        $statement->execute([$id,$number,json_encode($values,JSON_THROW_ON_ERROR)]);
    }
    public function updateImport(string $id, array $fields): void
    {
        $sets = array_map(static fn ($column) => "$column=?", array_keys($fields));
        $statement = $this->db->prepare('UPDATE personas_importaciones SET ' . implode(',',$sets) . ' WHERE id=?');
        $statement->execute([...array_values($fields),$id]);
    }
    public function updateRow(string $id, int $number, array $fields): void
    {
        $sets = array_map(static fn ($column) => "$column=?", array_keys($fields));
        $statement = $this->db->prepare('UPDATE personas_importacion_filas SET ' . implode(',',$sets) . ' WHERE importacion=? AND fila=?');
        $statement->execute([...array_values($fields),$id,$number]);
    }
    public function fileMatches(string $id, int $number, array $fields, bool $approximate): array
    {
        $conditions = []; $params = [$id,$number];
        foreach (['curp','clave_elector','telefono','email'] as $column) {
            if (!$approximate && !in_array($column,['curp','clave_elector'],true)) continue;
            if (isset($fields[$column]) && $fields[$column] !== '') { $conditions[] = "$column=?"; $params[] = $fields[$column]; }
        }
        if ($approximate && isset($fields['nombre_completo']) && $fields['nombre_completo'] !== '') { $conditions[]='nombre_completo=?'; $params[]=$fields['nombre_completo']; }
        if ($conditions === []) return [];
        $statement = $this->db->prepare('SELECT fila,curp,clave_elector,nombre_completo FROM personas_importacion_filas WHERE importacion=? AND fila<? AND (' . implode(' OR ',$conditions) . ') ORDER BY fila LIMIT 20');
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
    public function summary(string $id): array
    {
        $statement = $this->db->prepare('SELECT estado,COUNT(*) cantidad FROM personas_importacion_filas WHERE importacion=? GROUP BY estado');
        $statement->execute([$id]);
        $summary = array_fill_keys(['pendiente','listo','decision','invalido','nuevo','vinculado','completado','omitido','rechazado','error'],0);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) $summary[$row['estado']] = (int) $row['cantidad'];
        return $summary;
    }
    public function plannedSummary(string $id): array
    {
        $statement = $this->db->prepare("SELECT CASE
            WHEN estado='invalido' THEN 'rejected'
            WHEN estado='pendiente' THEN 'pending'
            WHEN estado='decision' THEN 'unresolved'
            ELSE COALESCE(JSON_UNQUOTE(JSON_EXTRACT(decision, '$.action')), 'new')
            END accion, COUNT(*) cantidad
            FROM personas_importacion_filas WHERE importacion=? GROUP BY accion");
        $statement->execute([$id]);
        $summary = array_fill_keys(['new','existing','fill','skip','rejected','pending','unresolved'],0);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) $summary[$row['accion']] = (int) $row['cantidad'];
        return $summary;
    }

    public function sourceResult(string $id, int $row): ?array
    {
        $statement = $this->db->prepare('SELECT resultado FROM personas_importacion_filas WHERE importacion=? AND fila=?');
        $statement->execute([$id,$row]);
        $value = $statement->fetchColumn();
        return $value ? json_decode($value,true,512,JSON_THROW_ON_ERROR) : null;
    }
    public function remove(string $id): void
    {
        $statement = $this->db->prepare('DELETE FROM personas_importaciones WHERE id=?');
        $statement->execute([$id]);
    }
    public function purgeExpired(): void { $this->db->exec('DELETE FROM personas_importaciones WHERE expira_en<=NOW() LIMIT 1'); }
}
