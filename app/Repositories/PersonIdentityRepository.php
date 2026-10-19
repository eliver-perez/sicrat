<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Auth\Authorization;
use App\Core\HttpError;
use PDO;

class PersonIdentityRepository
{
    public function __construct(private PDO $db) {}
    public function connection(): PDO { return $this->db; }

    public function organization(string $uuid): int
    {
        $statement = $this->db->prepare('SELECT id FROM organizaciones WHERE uuid = ?');
        $statement->execute([$uuid]);
        $id = $statement->fetchColumn();
        if ($id === false) throw new HttpError('La organización no está disponible.', 404);
        Authorization::assertOrganization((int) $id);
        return (int) $id;
    }

    public function person(string $uuid, bool $lock = false): array
    {
        $statement = $this->db->prepare('SELECT p.*, o.uuid organizacion_uuid, s.uuid seccion_uuid FROM personas p JOIN organizaciones o ON o.id=p.organizacion LEFT JOIN secciones_electorales s ON s.id=p.seccion WHERE p.uuid=?' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$uuid]);
        $person = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$person) throw new HttpError('La persona no está disponible.', 404);
        Authorization::assertOrganization((int) $person['organizacion']);
        return $person;
    }

    public function sectionId(string $value): int
    {
        if (ctype_digit($value)) {
            $statement = $this->db->prepare('SELECT id FROM secciones_electorales WHERE seccion=? ORDER BY id LIMIT 2');
            $statement->execute([(int) $value]);
            $ids = $statement->fetchAll(PDO::FETCH_COLUMN);
            if (count($ids) !== 1) throw new \InvalidArgumentException('La sección no existe o corresponde a varios estados. Selecciónala desde la ficha.');
            return (int) $ids[0];
        }
        if (!is_uuid($value)) throw new \InvalidArgumentException('La sección no es válida.');
        $statement = $this->db->prepare('SELECT id FROM secciones_electorales WHERE uuid=?');
        $statement->execute([hex2bin(str_replace('-', '', $value))]);
        $id = $statement->fetchColumn();
        if ($id === false) throw new \InvalidArgumentException('La sección no existe.');
        return (int) $id;
    }

    public function genderExists(string $value): bool
    {
        $statement = $this->db->prepare('SELECT 1 FROM generos WHERE id=?');
        $statement->execute([$value]);
        return (bool) $statement->fetchColumn();
    }

    public function neighborhoodExists(int $value): bool
    {
        $statement = $this->db->prepare('SELECT 1 FROM colonias WHERE id=?');
        $statement->execute([$value]);
        return (bool) $statement->fetchColumn();
    }

    public function policy(int $organization, bool $lock = false): array
    {
        Authorization::assertOrganization($organization);
        $statement = $this->db->prepare('SELECT curp_unica, clave_elector_unica FROM organizaciones WHERE id=?' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$organization]);
        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    public function setPolicy(int $organization, int $curp, int $key): void
    {
        $statement = $this->db->prepare('UPDATE organizaciones SET curp_unica=?, clave_elector_unica=?, f_actualizacion=NOW() WHERE id=?');
        $before = $this->policy($organization);
        $statement->execute([$curp, $key, $organization]);
        $this->audit($organization, null, 'configurar', 'identidad_politica', $organization, $before, ['curp_unica'=>$curp,'clave_elector_unica'=>$key]);
    }

    public function mapSettings(int $organization): array
    {
        $statement=$this->db->prepare('SELECT latitud latitude,longitud longitude,zoom FROM organizaciones_mapa WHERE organizacion=?');
        $statement->execute([$organization]);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        return $row ? ['latitude'=>(float)$row['latitude'],'longitude'=>(float)$row['longitude'],'zoom'=>(int)$row['zoom']] : ['latitude'=>23.6345,'longitude'=>-102.5528,'zoom'=>5];
    }

    public function setMapSettings(int $organization, array $settings): void
    {
        $before=$this->mapSettings($organization);
        $statement=$this->db->prepare('INSERT INTO organizaciones_mapa (organizacion,latitud,longitud,zoom,editado_por,fecha) VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE latitud=VALUES(latitud),longitud=VALUES(longitud),zoom=VALUES(zoom),editado_por=VALUES(editado_por),fecha=NOW()');
        $statement->execute([$organization,$settings['latitude'],$settings['longitude'],$settings['zoom'],\App\Core\Auth::id()]);
        $this->audit($organization,null,'configurar','organizaciones_mapa',$organization,$before,$settings);
    }

    public function matches(int $organization, array $fields, bool $approximate, ?int $exclude = null): array
    {
        $conditions = [];
        $params = [$organization];
        foreach (['curp', 'clave_elector'] as $column) {
            if (isset($fields[$column]) && $fields[$column] !== '') { $conditions[] = "$column = ?"; $params[] = $fields[$column]; }
        }
        if ($approximate) {
            foreach (['telefono', 'email'] as $column) {
                if (isset($fields[$column]) && $fields[$column] !== '') { $conditions[] = "$column = ?"; $params[] = $fields[$column]; }
            }
            $conditions[] = "TRIM(CONCAT_WS(' ', nombre, NULLIF(paterno,''), NULLIF(materno,''))) COLLATE utf8mb4_unicode_ci = ?";
            $params[] = trim(implode(' ', array_filter([$fields['nombre'], $fields['paterno'] ?? null, $fields['materno'] ?? null],static fn($value)=>$value!==null && $value!=='')));
        }
        if ($conditions === []) return [];
        $sql = 'SELECT id, uuid, codigo_persona, nombre, paterno, materno, curp, clave_elector, telefono, email FROM personas p WHERE organizacion=? AND (' . implode(' OR ', $conditions) . ')';
        if ($exclude !== null) { $sql .= ' AND id<>?'; $params[] = $exclude; }
        [$scope, $scopeParams] = (new ScopeRepository($this->db))->predicate('personas.ver', \App\Core\Auth::processId());
        $sql .= ' AND (' . $scope . ')'; $params = [...$params, ...$scopeParams];
        // Las coincidencias de identificadores deben aparecer antes de homónimos frecuentes.
        $priority = [];
        foreach (['curp', 'clave_elector'] as $column) {
            if (isset($fields[$column]) && $fields[$column] !== '') { $priority[] = "$column = ?"; $params[] = $fields[$column]; }
        }
        $order = $priority === [] ? 'id' : 'CASE WHEN ' . implode(' OR ', $priority) . ' THEN 0 ELSE 1 END, id';
        $statement = $this->db->prepare($sql . ' ORDER BY ' . $order . ' LIMIT 21');
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function identifierExists(int $organization, string $column, string $value, ?int $exclude): bool
    {
        if (!in_array($column, ['curp', 'clave_elector'], true)) throw new \InvalidArgumentException('Campo inválido.');
        $statement = $this->db->prepare("SELECT 1 FROM personas WHERE organizacion=? AND $column=? AND id<>? LIMIT 1 FOR UPDATE");
        $statement->execute([$organization, $value, $exclude ?? 0]);
        return (bool) $statement->fetchColumn();
    }

    public function insert(array $fields): int
    {
        $columns = array_keys($fields);
        $statement = $this->db->prepare('INSERT INTO personas (' . implode(',', $columns) . ', f_registro) VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ', NOW())');
        $statement->execute(array_values($fields));
        $id = (int) $this->db->lastInsertId();
        $this->audit((int) $fields['organizacion'], null, 'alta', 'personas', $id, null, $fields);
        return $id;
    }

    public function update(int $id, array $fields): void
    {
        if ($fields === []) return;
        $beforeStatement = $this->db->prepare('SELECT * FROM personas WHERE id=?'); $beforeStatement->execute([$id]); $before = $beforeStatement->fetch(PDO::FETCH_ASSOC);
        $sets = array_map(static fn (string $column): string => $column . '=?', array_keys($fields));
        $statement = $this->db->prepare('UPDATE personas SET ' . implode(',', $sets) . ', f_actualizacion=NOW() WHERE id=?');
        $statement->execute([...array_values($fields), $id]);
        $this->audit((int) $before['organizacion'], null, 'editar', 'personas', $id, array_intersect_key($before,$fields), $fields);
    }

    public function address(int $person, bool $lock = false): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM personas_domicilios WHERE persona=? ORDER BY principal DESC,id LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$person]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function saveAddress(int $person, array $fields): void
    {
        $previous = $this->address($person, true);
        $columns = array_keys($fields);
        if ($previous) {
            $statement = $this->db->prepare('UPDATE personas_domicilios SET ' . implode(',', array_map(static fn ($column) => "$column=?", $columns)) . ' WHERE id=?');
            $statement->execute([...array_values($fields), $previous['id']]);
        } else {
            $statement = $this->db->prepare('INSERT INTO personas_domicilios (persona,' . implode(',', $columns) . ') VALUES (?,' . implode(',', array_fill(0, count($columns), '?')) . ')');
            $statement->execute([$person, ...array_values($fields)]);
        }
        $organizationStatement = $this->db->prepare('SELECT organizacion FROM personas WHERE id=?'); $organizationStatement->execute([$person]);
        $this->audit((int) $organizationStatement->fetchColumn(),null,'domicilio','personas',$person,$previous,$fields);
    }

    public function participations(int $person): array
    {
        $sql = 'SELECT pp.activo, pp.f_registro, pp.f_actualizacion, pe.id, pe.uuid, pe.proceso, pe.estatus FROM personas_procesos pp JOIN procesos_electorales pe ON pe.id=pp.proceso WHERE pp.persona=?';
        $params = [$person];
        if (!\App\Core\Auth::isSuperAdmin()) {
            $sql .= ' AND EXISTS (SELECT 1 FROM usuarios_procesos up JOIN usuarios u ON u.id=up.usuario WHERE up.proceso=pe.id AND up.usuario=? AND up.activo=1 AND up.f_baja IS NULL AND u.organizacion=pe.organizacion)';
            $params[] = \App\Core\Auth::id();
        }
        $statement = $this->db->prepare($sql . ' ORDER BY pe.proceso, pe.id');
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function participate(int $person, int $process, int $active): void
    {
        $repository = new CommonRepository($this->db);
        $repository->transaction(function () use ($person,$process,$active,$repository) {
            $before = $repository->one('SELECT activo FROM personas_procesos WHERE persona=? AND proceso=?',[$person,$process]);
            $organization = (int) $repository->one('SELECT organizacion FROM personas WHERE id=?',[$person])['organizacion'];
            $statement = $this->db->prepare('INSERT INTO personas_procesos (persona,proceso,activo,f_registro) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE activo=VALUES(activo), f_actualizacion=NOW()');
            $statement->execute([$person, $process, $active]);
            $this->audit($organization,$process,'participacion','personas',$person,$before,['activo'=>$active]);
        });
    }

    private function audit(int $organization, ?int $process, string $action, string $entity, int $record, mixed $before, mixed $after): void
    {
        (new \App\Services\Common\AuditService(new CommonRepository($this->db)))->record($organization,$process,$action,$entity,$record,$before,$after);
    }
}
