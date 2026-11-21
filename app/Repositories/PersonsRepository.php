<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use App\Auth\Authorization;

class PersonsRepository
{
    public function __construct(private PDO $db) {
    }

    public function getConnection() : PDO {
        return $this->db;
    }

    public function getAll(array $data): array {
        $sql = "
            SELECT
                p.id,
                p.uuid,
                p.codigo_persona, p.telefono, p.telefono_alternativo, p.email, p.genero, p.f_nacimiento,
                TRIM(
                    CONCAT(
                        p.nombre, ' ',
                        COALESCE(p.paterno, ''), ' ',
                        COALESCE(p.materno, '')
                    )
                ) nombre,
                se.seccion,
                COALESCE(r.nombre, 'N/D') registro,
                COALESCE(DATE_FORMAT(p.f_registro, '%d/%m/%Y %r'), '') f_registro
            FROM personas p
                LEFT JOIN secciones_electorales se
                    ON se.id = p.seccion
                LEFT JOIN usuarios r
                    ON r.id = p.registrado_por
            WHERE 1=1
        ";

        $params = [];
        $sql .= $this->filters($data, $params);
        $stmt = $this->db->prepare($sql . ' ORDER BY p.paterno,p.nombre,p.id LIMIT ' . (int)$data['limit'] . ' OFFSET ' . (int)$data['offset']);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function filters(array $data, array &$params, bool $nameOnly = false): string
    {
        [$condition, $params] = (new ScopeRepository($this->db))->predicate('personas.ver', \App\Core\Auth::processId());
        $sql = " AND ($condition)";
        $organization = Authorization::scopeOrganizationId();
        if ($organization !== null) {
            $sql .= ' AND p.organizacion=?';
            $params[] = $organization;
        }
        if ($data['section'] !== null) {
            $sql .= ' AND se.uuid=?';
            $params[] = $data['section'];
        }
        $search = trim($data['search'] ?? '');
        if ($search === '') return $sql;
        if ($nameOnly) {
            $params[] = "%$search%";
            return $sql . " AND CONCAT_WS(' ',p.nombre,p.paterno,p.materno) COLLATE utf8mb4_unicode_ci LIKE ?";
        }
        return $sql . $this->search($search, $params);
    }

    private function search(string $search, array &$params): string
    {
        $fields = ["TRIM(
                        CONCAT(
                            p.nombre, ' ',
                            COALESCE(p.paterno, ''), ' ',
                            COALESCE(p.materno, '')
                        )
                    )",
                    // Comparar nombres y códigos sin distinguir acentos ni mayúsculas.
                    'CONVERT(p.codigo_persona USING utf8mb4) COLLATE utf8mb4_unicode_ci',
                    'p.nombre',
                    'p.paterno',
                    'p.materno',
                    'se.seccion',
                    'r.nombre'];

        $conditions = [];
        foreach ($fields as $field) {
            $conditions[] = "CONVERT(($field) USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?";
            $params[] = '%' . $search . '%';
        }
        return ' AND (' . implode(' OR ', $conditions) . ')';
    }

    public function mapRows(array $data, int $organization, int $process, ?array $structure = null): array
    {
        $params = [];
        $filters = $this->filters($data, $params, $structure !== null);
        // Misma dirección que la ficha, incluso cuando existen domicilios históricos.
        $from = $structure ? $structure['from'] . ' JOIN personas p ON p.id=e.persona JOIN personas_domicilios d ON d.persona=p.id'
            : ' FROM personas_domicilios d JOIN personas p ON p.id=d.persona';
        $from .= " LEFT JOIN secciones_electorales se ON se.id=p.seccion LEFT JOIN usuarios r ON r.id=p.registrado_por
            WHERE " . PersonMapRepository::ADDRESS_FILTER . " AND p.organizacion=?";
        $area = [$data['south'],$data['north'],$data['west'],$data['east'],$organization];
        if ($structure) {
            $from .= ' AND ' . $structure['where'];
            $params = [$structure['params'][0],...$area,...array_slice($structure['params'],1),...$params];
        } else {
            $from .= ' AND (EXISTS (SELECT 1 FROM personas_procesos pp WHERE pp.persona=p.id AND pp.proceso=? AND pp.activo=1)
                OR EXISTS (SELECT 1 FROM estructura_integrantes_procesos ep WHERE ep.persona=p.id AND ep.proceso=? AND ep.activo=1))';
            $params = [...$area,$process,$process,...$params];
        }
        $from .= $filters;
        $columns = [
            'name'=>"TRIM(CONCAT_WS(' ',p.nombre,p.paterno,p.materno))",
            'code'=>'p.codigo_persona',
            'section'=>'se.seccion',
        ];
        if ($structure) $columns['level'] = 'level.nombre';
        return (new PersonMapRepository($this->db))->rows($data,$from,$params,$columns);
    }

    public function mapBranches(array $structure, int $organization, string $search, string $sort, int $offset, array $selected): array
    {
        $name = "TRIM(CONCAT_WS(' ',p.nombre,p.paterno,p.materno))";
        $from = $structure['from'] . ' JOIN personas p ON p.id=e.persona WHERE ' . $structure['where'] . ' AND p.organizacion=?';
        $params = [...$structure['params'],$organization];
        if ($search !== '') {
            $from .= " AND ($name COLLATE utf8mb4_unicode_ci LIKE ? OR CONVERT(p.codigo_persona USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?)";
            $params = [...$params,"%$search%","%$search%"];
        }
        $statement = $this->db->prepare('SELECT COUNT(*)' . $from);
        $statement->execute($params);
        $total = (int)$statement->fetchColumn();
        $matches = [];
        if ($selected !== []) {
            $slots = implode(',',array_fill(0,count($selected),'?'));
            $statement = $this->db->prepare('SELECT e.uuid' . $from . " AND e.uuid IN ($slots)");
            $statement->execute([...$params,...$selected]);
            $matches = $statement->fetchAll(PDO::FETCH_COLUMN);
            $from .= " AND e.uuid NOT IN ($slots)";
            $params = [...$params,...$selected];
        }
        $order = $sort === 'level' ? 'level_name IS NULL,level_name COLLATE utf8mb4_unicode_ci,' : '';
        $statement = $this->db->prepare("SELECT e.uuid,p.uuid person_uuid,$name name,level.nombre level_name" . $from
            . " ORDER BY {$order}name COLLATE utf8mb4_unicode_ci,e.id LIMIT 26 OFFSET " . $offset);
        $statement->execute($params);
        return ['rows'=>$statement->fetchAll(PDO::FETCH_ASSOC),'total'=>$total,'selected_matches'=>$matches];
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

    public function insertPerson(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO personas (
                uuid,
                organizacion,
                seccion,
                genero,
                nombre,
                paterno,
                materno,
                registrado_por,
                f_registro
            ) VALUES (
                :uuid,
                :organizacion,
                :seccion,
                :genero,
                :nombre,
                :paterno,
                :materno,
                :registro,
                NOW()
            )
        ");

        $stmt->bindValue(':uuid', $data['uuid'], PDO::PARAM_LOB);
        $stmt->bindValue(':organizacion', $data['organization'], PDO::PARAM_INT);
        $stmt->bindValue(':seccion', $data['section'], PDO::PARAM_INT);
        $stmt->bindValue(':genero', $data['gender'], PDO::PARAM_STR);
        $stmt->bindValue(':nombre', $data['name'], PDO::PARAM_STR);
        $stmt->bindValue(':paterno', $data['last_name'], PDO::PARAM_STR);
        $stmt->bindValue(':materno', $data['last_name_2'], PDO::PARAM_STR);
        $stmt->bindValue(':registro', $data['uid'], PDO::PARAM_INT);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }
    public function genderExists(string $gender): bool {
        $statement = $this->db->prepare('SELECT 1 FROM generos WHERE id = :genero');
        $statement->execute(['genero' => $gender]);
        return (bool) $statement->fetchColumn();
    }
}