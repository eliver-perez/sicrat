<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\Authorization;
use App\Core\Auth;
use App\Core\HttpError;
use App\Core\Service;
use App\Repositories\ElectoralContextRepository;
use App\Repositories\ModuleRepository;
use App\Repositories\PermissionRepository;
use App\Repositories\PersonIdentityRepository;
use App\Repositories\ScopeRepository;
use App\Repositories\StructureRepository;
use InvalidArgumentException;
use Throwable;

class StructureService extends Service
{
    public function __construct(private StructureRepository $repository) {}

    private function transaction(callable $work): mixed
    {
        $db = $this->repository->connection(); $own = !$db->inTransaction();
        if ($own) { $db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED'); $db->beginTransaction(); }
        try { $result = $work(); if ($own) $db->commit(); return $result; }
        catch (Throwable $error) { if ($own && $db->inTransaction()) $db->rollBack(); throw $error; }
    }

    private function currentProcess(): int
    {
        $process = Auth::processId();
        if (!$process) throw new InvalidArgumentException('Selecciona una elección para trabajar con la estructura.');
        return $process;
    }

    private function election(string $uuid, int $organization): array
    {
        $election = (new ElectoralContextRepository($this->repository->connection()))->authorized($this->uuidStringToBinary($uuid),$organization);
        if ((int) $election['organizacion'] !== $organization) throw new InvalidArgumentException('La elección pertenece a otra organización.');
        return $election;
    }

    public function guard(array $container, string $permission, array $persons = [], ?int $actor = null): array
    {
        if (!(int) $container['activo']) throw new HttpError('La estructura está desactivada.',409);
        $elections = $this->repository->elections((int) $container['id']);
        if ($elections === []) throw new HttpError('La estructura no tiene elecciones activas asociadas.',409);
        $actor ??= Auth::id() ?? 0;
        foreach ($elections as $election) {
            (new ModuleRepository($this->repository->connection()))->require('estructura',(int) $election['id']);
            (new PermissionRepository($this->repository->connection()))->require($actor,$permission,(int) $election['id']);
            foreach ($persons as $person) (new ScopeRepository($this->repository->connection()))->assertPerson((int) $person,$permission,(int) $election['id'],$actor);
        }
        return $elections;
    }

    private function fullScope(string $permission, int $process): void
    {
        $effective = (new PermissionRepository($this->repository->connection()))->require(Auth::id() ?? 0,$permission,$process);
        if (!$effective['super'] && array_intersect(['eleccion','organizacion'],$effective['scopes'][$permission]) === []) throw new HttpError('Esta operación requiere alcance de elección u organización.',403);
    }

    public function index(array $data): array
    {
        $process = $this->currentProcess();
        (new ModuleRepository($this->repository->connection()))->require('estructura',$process);
        [$scope,$params] = (new ScopeRepository($this->repository->connection()))->predicate('estructura.ver',$process);
        $effective = (new PermissionRepository($this->repository->connection()))->require(Auth::id() ?? 0,'estructura.ver',$process);
        $whole = $effective['super'] || array_intersect(['eleccion','organizacion'],$effective['scopes']['estructura.ver']) !== [];
        $sql = 'SELECT c.* FROM estructuras_organizacionales c JOIN estructuras_procesos ep ON ep.estructura=c.id AND ep.proceso=? AND ep.activo=1 WHERE c.activo=1';
        $args = [$process];
        if (!$whole) { $sql .= " AND EXISTS (SELECT 1 FROM estructura e JOIN personas p ON p.id=e.persona WHERE e.contenedor=c.id AND e.activo=1 AND ($scope))"; $args = [...$args,...$params]; }
        $sql .= ' ORDER BY c.nombre,c.id LIMIT ' . (int) $data['limit'] . ' OFFSET ' . (int) $data['offset'];
        return array_map(fn ($row) => ['id'=>$this->uuidBinaryToString($row['uuid']),'name'=>$row['nombre'],'version'=>(int) $row['version'],'cloned'=>(bool) $row['clonada_de']],$this->repository->rows($sql,$args));
    }

    public function create(array $data): array
    {
        $name = $this->normalizeRequiredText($data['name'] ?? null,'Indica el nombre de la estructura.');
        if (mb_strlen($name)>150) throw new InvalidArgumentException('El nombre es demasiado largo.');
        $processes = $data['elections'] ?? [];
        if (!is_array($processes) || $processes === [] || count($processes)>100) throw new InvalidArgumentException('Selecciona entre una y cien elecciones.');
        $organization = $this->repository->rows('SELECT organizacion FROM procesos_electorales WHERE uuid=?',[$this->uuidStringToBinary((string) $processes[0])])[0]['organizacion'] ?? null;
        if ($organization === null) throw new HttpError('La elección no está disponible.',404);
        Authorization::assertOrganization((int) $organization);
        return $this->transaction(function () use ($name,$processes,$organization) {
            $this->repository->lockOrganization((int) $organization);
            $uuid = $this->generateUuidBinary();
            $id = $this->repository->insert('INSERT INTO estructuras_organizacionales (uuid,organizacion,nombre,creado_por,fecha) VALUES (?,?,?,?,NOW())',[$uuid,$organization,$name,Auth::id()]);
            foreach (array_unique($processes) as $processUuid) {
                $process = $this->election((string) $processUuid,(int) $organization);
                $this->fullScope('estructura.administrar',(int) $process['id']);
                (new ModuleRepository($this->repository->connection()))->require('estructura',(int) $process['id']);
                $this->repository->execute('INSERT INTO estructuras_procesos (estructura,proceso,fecha) VALUES (?,?,NOW())',[$id,$process['id']]);
                $this->repository->history($id,null,'asociacion',null,['election'=>$processUuid],(int) $process['id']);
            }
            return ['id'=>$this->uuidBinaryToString($uuid)];
        });
    }

    private function locked(string $uuid): array
    {
        $container = $this->repository->container($this->uuidStringToBinary($uuid));
        $this->repository->lockOrganization((int) $container['organizacion']);
        return $this->repository->container($this->uuidStringToBinary($uuid));
    }

    private function visible(array $container): int
    {
        $process = $this->currentProcess();
        if (!in_array($process,array_map(static fn ($pe) => (int) $pe['id'],$this->repository->elections((int) $container['id'])),true)) throw new HttpError('La estructura no pertenece a la elección seleccionada.',404);
        (new ModuleRepository($this->repository->connection()))->require('estructura',$process);
        (new PermissionRepository($this->repository->connection()))->require(Auth::id() ?? 0,'estructura.ver',$process);
        return $process;
    }

    private function mappedMember(array $member, int $process): array
    {
        $level = $this->repository->rows('SELECT n.uuid,n.nombre,n.orden FROM estructura_integrantes_procesos ep LEFT JOIN niveles_organizacionales n ON n.id=ep.nivel WHERE ep.integrante=? AND ep.proceso=?',[$member['id'],$process])[0] ?? [];
        return ['id'=>$this->uuidBinaryToString($member['uuid']),'person'=>$this->uuidBinaryToString($member['persona_uuid']),'code'=>$member['codigo_persona'],'name'=>$member['nombre'],
            'has_account'=>$member['usuario_uuid'] !== null,'account'=>$member['usuario_uuid'] ? $this->uuidBinaryToString($member['usuario_uuid']) : null,
            'active'=>(int) $member['activo'],'responsible'=>$member['responsable_uuid'] ? $this->uuidBinaryToString($member['responsable_uuid']) : null,
            'level'=>isset($level['uuid']) ? $this->uuidBinaryToString($level['uuid']) : null,'level_name'=>$level['nombre'] ?? null,'level_order'=>isset($level['orden']) ? (int) $level['orden'] : null,
            'direct_count'=>(int) $this->repository->rows('SELECT COUNT(*) total FROM estructura WHERE nodo_padre=? AND activo=1',[$member['id']])[0]['total'],
            'team_count'=>(int) $this->repository->rows('SELECT COUNT(*) total FROM estructura_relaciones r JOIN estructura e ON e.id=r.descendiente WHERE r.ancestro=? AND r.profundidad>0 AND e.activo=1',[$member['id']])[0]['total']];
    }

    public function show(string $uuid, array $page): array
    {
        $container = $this->repository->container($this->uuidStringToBinary($uuid)); $process = $this->visible($container);
        [$condition,$params] = (new ScopeRepository($this->repository->connection()))->predicate('estructura.ver',$process);
        $ids = $this->repository->rows("SELECT e.id FROM estructura e JOIN personas p ON p.id=e.persona WHERE e.contenedor=? AND ($condition) ORDER BY p.nombre,e.id LIMIT " . (int) $page['limit'] . ' OFFSET ' . (int) $page['offset'],[$container['id'],...$params]);
        $wanted = array_column($ids,'id');
        $members = $this->repository->members((int) $container['id'],false,$wanted);
        return ['id'=>$uuid,'name'=>$container['nombre'],'version'=>(int) $container['version'],'elections'=>array_map(fn ($pe) => ['id'=>$this->uuidBinaryToString($pe['uuid']),'name'=>$pe['proceso']],$this->repository->elections((int) $container['id'])),
            'members'=>array_map(fn ($row) => $this->mappedMember($row,$process),$members)];
    }

    public function detail(string $uuid, string $memberUuid, array $page = ['limit'=>101,'offset'=>0]): array
    {
        $container = $this->repository->container($this->uuidStringToBinary($uuid)); $process = $this->visible($container);
        $member = $this->repository->member($this->uuidStringToBinary($memberUuid),(int) $container['id']);
        (new ScopeRepository($this->repository->connection()))->assertPerson((int) $member['persona'],'estructura.ver',$process);
        [$condition,$params] = (new ScopeRepository($this->repository->connection()))->predicate('estructura.ver',$process);
        $limitSql = ' LIMIT ' . (int) $page['limit'] . ' OFFSET ' . (int) $page['offset'];
        $line = $this->repository->rows("SELECT e.uuid,p.uuid persona_uuid,TRIM(CONCAT_WS(' ',p.nombre,p.paterno,p.materno)) nombre,r.profundidad FROM estructura_relaciones r JOIN estructura e ON e.id=r.ancestro JOIN personas p ON p.id=e.persona WHERE r.descendiente=? AND r.profundidad>0 AND ($condition) ORDER BY r.profundidad" . $limitSql,[$member['id'],...$params]);
        $team = $this->repository->rows("SELECT e.uuid,TRIM(CONCAT_WS(' ',p.nombre,p.paterno,p.materno)) nombre,r.profundidad FROM estructura_relaciones r JOIN estructura e ON e.id=r.descendiente JOIN personas p ON p.id=e.persona WHERE r.ancestro=? AND r.profundidad>0 AND e.activo=1 AND ($condition) ORDER BY r.profundidad,e.id" . $limitSql,[$member['id'],...$params]);
        $full = $this->repository->members((int) $container['id'],false,[(int) $member['id']])[0];
        $map = fn ($row) => ['id'=>$this->uuidBinaryToString($row['uuid']),'name'=>$row['nombre'],'depth'=>(int) $row['profundidad']];
        $participations = (new PersonIdentityRepository($this->repository->connection()))->participations((int) $member['persona']);
        $levels = [];
        foreach ($this->repository->rows('SELECT pe.id,pe.uuid,n.uuid nivel_uuid FROM estructura_integrantes_procesos ep JOIN procesos_electorales pe ON pe.id=ep.proceso LEFT JOIN niveles_organizacionales n ON n.id=ep.nivel WHERE ep.integrante=?',[$member['id']]) as $projection) {
            try { (new PermissionRepository($this->repository->connection()))->require(Auth::id() ?? 0,'estructura.ver',(int) $projection['id']); $levels[$this->uuidBinaryToString($projection['uuid'])] = isset($projection['nivel_uuid']) ? $this->uuidBinaryToString($projection['nivel_uuid']) : null; }
            catch (HttpError $error) { if (!in_array($error->statusCode,[401,403],true)) throw $error; }
        }
        return [...$this->mappedMember($full,$process),'levels'=>$levels,'superiors'=>array_map($map,array_slice($line,0,$page['limit']-1)),'team'=>array_map($map,array_slice($team,0,$page['limit']-1)),
            'superiors_pagination'=>\App\Core\Pagination::response($line,$page['limit']-1,$page['offset']),'team_pagination'=>\App\Core\Pagination::response($team,$page['limit']-1,$page['offset']),
            'participations'=>array_map(fn ($pe) => ['id'=>$this->uuidBinaryToString($pe['uuid']),'name'=>$pe['proceso'],'active'=>(int) $pe['activo']],$participations)];
    }

    private function level(int $process, mixed $uuid, bool $activeOnly = true): ?int
    {
        if ($uuid === null || $uuid === '') {
            if ($activeOnly && $this->repository->rows('SELECT id FROM niveles_organizacionales WHERE proceso=? AND activo=1 LIMIT 1',[$process]) !== []) throw new InvalidArgumentException('Selecciona el nivel para cada elección asociada.');
            return null;
        }
        $rows = $this->repository->rows('SELECT id FROM niveles_organizacionales WHERE uuid=? AND proceso=?' . ($activeOnly ? ' AND activo=1' : ''),[$this->uuidStringToBinary((string) $uuid),$process]);
        if ($rows === []) throw new InvalidArgumentException('El nivel no está disponible en esta elección.');
        return (int) $rows[0]['id'];
    }

    private function projection(array $member, int $process, ?int $level): void
    {
        $existing = $this->repository->rows('SELECT integrante,activo FROM estructura_integrantes_procesos WHERE persona=? AND proceso=? AND activo=1 FOR UPDATE',[$member['persona'],$process]);
        if ((int) $member['activo']===1 && $existing !== [] && (int) $existing[0]['integrante']!==(int) $member['id']) throw new HttpError('La persona ya tiene una ubicación estructural activa en esta elección.',409);
        $this->repository->execute('INSERT INTO estructura_integrantes_procesos (persona,proceso,integrante,nivel,activo,fecha) VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE integrante=VALUES(integrante),nivel=VALUES(nivel),activo=VALUES(activo),fecha_baja=NULL',[$member['persona'],$process,$member['id'],$level,$member['activo']]);
    }

    public function validateHierarchy(int $container): void
    {
        $invalid = $this->repository->rows('SELECT e.id FROM estructura e JOIN estructura parent ON parent.id=e.nodo_padre JOIN estructura_integrantes_procesos child_ep ON child_ep.integrante=e.id AND child_ep.activo=1 JOIN estructura_integrantes_procesos parent_ep ON parent_ep.integrante=parent.id AND parent_ep.proceso=child_ep.proceso AND parent_ep.activo=1 JOIN niveles_organizacionales child_level ON child_level.id=child_ep.nivel JOIN niveles_organizacionales parent_level ON parent_level.id=parent_ep.nivel WHERE e.contenedor=? AND e.activo=1 AND (parent_level.orden>child_level.orden OR (parent_level.orden=child_level.orden AND parent_level.permite_mismo_nivel=0)) LIMIT 1',[$container]);
        if ($invalid !== []) throw new InvalidArgumentException('La relación de responsabilidad contradice el orden o la configuración del nivel.');
    }

    public function associate(string $uuid, array $data): void
    {
        $this->transaction(function () use ($uuid,$data) {
            $container = $this->locked($uuid); $this->guard($container,'estructura.administrar',array_column($this->repository->members((int) $container['id'],true),'persona'));
            $process = $this->election((string) ($data['election'] ?? ''),(int) $container['organizacion']);
            $this->fullScope('estructura.administrar',(int) $process['id']); (new ModuleRepository($this->repository->connection()))->require('estructura',(int) $process['id']);
            $active = $this->normalizeStatus($data['active'] ?? 1);
            $this->repository->execute('INSERT INTO estructuras_procesos (estructura,proceso,activo,fecha,fecha_baja) VALUES (?,?,?,NOW(),?) ON DUPLICATE KEY UPDATE activo=VALUES(activo),fecha_baja=VALUES(fecha_baja)',[$container['id'],$process['id'],$active,$active ? null : date('Y-m-d H:i:s')]);
            if ($active) foreach ($this->repository->members((int) $container['id'],true) as $member) $this->projection($member,(int) $process['id'],$this->level((int) $process['id'],$data['levels'][$this->uuidBinaryToString($member['uuid'])] ?? null));
            else $this->repository->execute('UPDATE estructura_integrantes_procesos ep JOIN estructura e ON e.id=ep.integrante SET ep.activo=0,ep.fecha_baja=NOW() WHERE e.contenedor=? AND ep.proceso=?',[$container['id'],$process['id']]);
            if ($this->repository->elections((int) $container['id'])===[]) throw new InvalidArgumentException('Debe conservarse al menos una elección asociada.');
            $this->validateHierarchy((int) $container['id']);
            $this->repository->history((int) $container['id'],null,$active ? 'asociacion' : 'desasociacion',null,$data,(int) $process['id']);
        });
    }

    private function promoteFirstIncorporation(array $container, array $parent, array $elections): void
    {
        $user = $this->repository->rows('SELECT persona FROM usuarios WHERE id=?',[Auth::id()])[0] ?? [];
        foreach ($elections as $process) {
            $level = $this->repository->rows('SELECT n.* FROM estructura_integrantes_procesos ep JOIN niveles_organizacionales n ON n.id=ep.nivel WHERE ep.integrante=? AND ep.proceso=?',[$parent['id'],$process['id']])[0] ?? null;
            if ($level === null || (int) $level['puede_reclutar']===1) continue;
            $config = $this->repository->rows('SELECT promocion_primera_incorporacion FROM procesos_estructura_config WHERE proceso=?',[$process['id']])[0] ?? [];
            $last = $this->repository->rows('SELECT MAX(orden) orden FROM niveles_organizacionales WHERE proceso=? AND activo=1',[$process['id']])[0]['orden'];
            $prior = $this->repository->rows("SELECT id FROM estructura_historial WHERE estructura=? AND accion='alta' AND JSON_EXTRACT(despues,'$.responsible')=? LIMIT 1",[$container['id'],(int) $parent['id']]);
            if ((int) ($config['promocion_primera_incorporacion'] ?? 0)!==1 || (int) ($user['persona'] ?? 0)!==(int) $parent['persona'] || (int) $level['orden']!==(int) $last || $prior!==[] || $this->repository->children((int) $parent['id'])!==[]) throw new HttpError('El responsable no tiene capacidad organizacional para incorporar integrantes.',403);
            $candidate = $this->repository->rows('SELECT * FROM niveles_organizacionales WHERE proceso=? AND activo=1 AND puede_reclutar=1 ORDER BY orden DESC LIMIT 1',[$process['id']])[0] ?? null;
            if (!$candidate) throw new InvalidArgumentException('No hay un nivel autorizado para reclutar.');
            $this->repository->execute('UPDATE estructura_integrantes_procesos SET nivel=? WHERE integrante=? AND proceso=?',[$candidate['id'],$parent['id'],$process['id']]);
            $this->repository->history((int) $container['id'],(int) $parent['id'],'promocion_primera_incorporacion',['level'=>$level['id']],['level'=>$candidate['id']],(int) $process['id']);
        }
    }

    public function add(string $uuid, array $data): array
    {
        return $this->transaction(function () use ($uuid,$data) {
            $container = $this->locked($uuid);
            $parent = isset($data['responsible']) && $data['responsible']!=='' ? $this->repository->member($this->uuidStringToBinary((string) $data['responsible']),(int) $container['id']) : null;
            if ($parent && !(int) $parent['activo']) throw new InvalidArgumentException('El responsable debe estar activo.');
            $elections = $this->guard($container,'estructura.formar',$parent ? [(int) $parent['persona']] : []);
            if (!$parent) foreach ($elections as $process) $this->fullScope('estructura.formar',(int) $process['id']);
            if ($parent) $this->promoteFirstIncorporation($container,$parent,$elections);
            $identity = new PersonIdentityRepository($this->repository->connection());
            if (!empty($data['person'])) $person = $identity->person($this->uuidStringToBinary((string) $data['person']),true);
            else {
                $organizationUuid = $this->repository->rows('SELECT uuid FROM organizaciones WHERE id=?',[$container['organizacion']])[0]['uuid'];
                $created = (new PersonIdentityService($identity))->create([...($data['new_person'] ?? []),'organization'=>$this->uuidBinaryToString($organizationUuid)]);
                if (!$created['created']) throw new HttpError('Hay coincidencias de identidad. Revisa las personas existentes antes de incorporar.',409);
                $person = $identity->person($this->uuidStringToBinary($created['uuid']),true);
            }
            if ((int) $person['organizacion']!==(int) $container['organizacion']) throw new InvalidArgumentException('La persona pertenece a otra organización.');
            // Reutilizar una ficha exige poder verla; alta rápida usa la protección de identidad de Fase 2.
            if (!empty($data['person'])) (new ScopeRepository($this->repository->connection()))->assertPerson((int) $person['id'],'personas.ver',$this->currentProcess());
            $existing = $this->repository->rows('SELECT * FROM estructura WHERE contenedor=? AND persona=?',[$container['id'],$person['id']]);
            if ($existing!==[]) throw new HttpError('La persona ya fue incorporada a esta estructura; utiliza su ficha para reactivarla.',409);
            $memberUuid = $this->generateUuidBinary();
            $id = $this->repository->insert('INSERT INTO estructura (uuid,contenedor,persona,nodo_padre,nivel,activo,f_asignacion) VALUES (?,?,?,?,1,1,NOW())',[$memberUuid,$container['id'],$person['id'],$parent['id'] ?? null]);
            $member = ['id'=>$id,'persona'=>$person['id'],'activo'=>1];
            foreach ($elections as $process) $this->projection($member,(int) $process['id'],$this->level((int) $process['id'],$data['levels'][$this->uuidBinaryToString($process['uuid'])] ?? null));
            $this->repository->addClosure($id,isset($parent['id']) ? (int) $parent['id'] : null);
            $selected = $data['participations'] ?? [];
            if (!is_array($selected) || count($selected)>100) throw new InvalidArgumentException('Participaciones inválidas.');
            $eligible = array_map(fn ($pe) => $this->uuidBinaryToString($pe['uuid']),$elections);
            foreach (array_unique($selected) as $selectedUuid) {
                if (!in_array($selectedUuid,$eligible,true)) throw new InvalidArgumentException('La participación no corresponde a las elecciones de esta estructura.');
                $process = $this->election((string) $selectedUuid,(int) $container['organizacion']);
                (new PermissionRepository($this->repository->connection()))->require(Auth::id() ?? 0,'personas.participar',(int) $process['id']);
                $identity->participate((int) $person['id'],(int) $process['id'],1);
            }
            $this->validateHierarchy((int) $container['id']);
            $this->repository->history((int) $container['id'],$id,'alta',null,['person'=>$person['id'],'responsible'=>$parent['id'] ?? null]);
            return ['id'=>$this->uuidBinaryToString($memberUuid),'person'=>$this->uuidBinaryToString($person['uuid'])];
        });
    }

    public function changeLevel(string $uuid, string $memberUuid, array $data): void
    {
        $this->transaction(function () use ($uuid,$memberUuid,$data) {
            $container = $this->locked($uuid); $member = $this->repository->member($this->uuidStringToBinary($memberUuid),(int) $container['id']);
            $elections = $this->guard($container,'estructura.administrar',[(int) $member['persona']]);
            foreach ($elections as $process) {
                $processUuid = $this->uuidBinaryToString($process['uuid']);
                if (!array_key_exists($processUuid,$data['levels'] ?? [])) continue;
                $level = $this->level((int) $process['id'],$data['levels'][$processUuid]);
                $previous = $this->repository->rows('SELECT nivel FROM estructura_integrantes_procesos WHERE integrante=? AND proceso=?',[$member['id'],$process['id']])[0]['nivel'] ?? null;
                $this->repository->execute('UPDATE estructura_integrantes_procesos SET nivel=? WHERE integrante=? AND proceso=?',[$level,$member['id'],$process['id']]);
                $this->repository->history((int) $container['id'],(int) $member['id'],'cambio_nivel',['level'=>$previous],['level'=>$level],(int) $process['id']);
            }
            $this->validateHierarchy((int) $container['id']);
        });
    }

    private function resolveMember(array $container, mixed $uuid): ?array
    {
        if ($uuid === null || $uuid === '') return null;
        $member = $this->repository->member($this->uuidStringToBinary((string) $uuid),(int) $container['id']);
        if (!(int) $member['activo']) throw new InvalidArgumentException('El responsable debe estar activo.');
        return $member;
    }

    private function handoff(array $container, array $member, ?array $replacement): void
    {
        $children = $this->repository->children((int) $member['id']);
        if ($children === []) return;
        if (!$replacement) throw new InvalidArgumentException('Selecciona quién asumirá el equipo directo antes de retirar al integrante o su capacidad.');
        if ($this->repository->descendant((int) $member['id'],(int) $replacement['id'])) throw new InvalidArgumentException('El reemplazo no puede pertenecer al equipo que se está reasignando.');
        foreach ($children as $child) {
            $this->repository->moveTeam((int) $child['id'],(int) $replacement['id']);
            $this->repository->history((int) $container['id'],(int) $child['id'],'cambio_responsable',['responsible'=>$member['id']],['responsible'=>$replacement['id']]);
        }
    }

    public function state(string $uuid, string $memberUuid, array $data): void
    {
        $this->transaction(function () use ($uuid,$memberUuid,$data) {
            $container = $this->locked($uuid); $member = $this->repository->member($this->uuidStringToBinary($memberUuid),(int) $container['id']);
            $elections = $this->guard($container,'estructura.administrar',[(int) $member['persona']]);
            $active = $this->normalizeStatus($data['active'] ?? null);
            if ($active===(int) $member['activo']) throw new HttpError('El integrante ya tiene ese estado. Utiliza una transferencia para cambiar su responsable.',409);
            if (!$active) {
                $replacement=$this->resolveMember($container,$data['replacement'] ?? null);
                if ($this->repository->children((int) $member['id'])!==[]) {
                    if (!$replacement) throw new InvalidArgumentException('Selecciona quién asumirá el equipo directo.');
                    $this->guard($container,'estructura.administrar',[...array_column($this->repository->team((int) $member['id']),'persona'),(int) $replacement['persona']]);
                    $this->guard($container,'estructura.transferir_equipo',[...array_column($this->repository->team((int) $member['id']),'persona'),(int) $replacement['persona']]);
                    foreach ($elections as $process) if ((int) ($this->repository->rows('SELECT requiere_aprobacion FROM procesos_estructura_config WHERE proceso=?',[$process['id']])[0]['requiere_aprobacion'] ?? 1)===1) throw new HttpError('Transfiere y aprueba primero los equipos directos antes de desactivar al responsable.',409);
                }
                // La baja conserva el último responsable y su línea histórica.
                $this->handoff($container,$member,$replacement);
            }
            else {
                $previousParent = $member['nodo_padre'] === null ? null : $this->repository->rows('SELECT uuid FROM estructura WHERE id=?',[$member['nodo_padre']])[0]['uuid'];
                $parentUuid = array_key_exists('responsible',$data) ? $data['responsible'] : ($previousParent ? $this->uuidBinaryToString($previousParent) : null);
                $parent = $this->resolveMember($container,$parentUuid);
                if (($parent['id'] ?? null)!=$member['nodo_padre']) throw new HttpError('Reactiva con el responsable anterior y utiliza una transferencia para cambiarlo. Si está inactivo, reactívalo primero.',409);
                if ($parent) $this->guard($container,'estructura.administrar',[(int) $parent['persona']]);
                foreach ($elections as $process) $this->projection([...$member,'activo'=>1],(int) $process['id'],$this->level((int) $process['id'],$data['levels'][$this->uuidBinaryToString($process['uuid'])] ?? null));
            }
            $this->repository->execute('UPDATE estructura SET activo=?,f_baja=? WHERE id=?',[$active,$active ? null : date('Y-m-d H:i:s'),$member['id']]);
            if (!$active) $this->repository->execute('UPDATE estructura_integrantes_procesos SET activo=0,fecha_baja=NOW() WHERE integrante=?',[$member['id']]);
            $this->validateHierarchy((int) $container['id']);
            $this->repository->history((int) $container['id'],(int) $member['id'],$active ? 'reactivacion' : 'baja',['active'=>$member['activo'],'responsible'=>$member['nodo_padre']],['active'=>$active]);
        });
    }

    public function withdrawCapacity(int $user, ?int $process = null): void
    {
        // Se usa dentro de la transacción del cambio de cuenta, rol o permiso.
        $params = [$user];
        $sql = 'SELECT DISTINCT e.id FROM estructura e JOIN usuarios u ON u.persona=e.persona JOIN estructura_integrantes_procesos ep ON ep.integrante=e.id AND ep.activo=1 WHERE u.id=? AND e.activo=1';
        if ($process !== null) { $sql .= ' AND ep.proceso=?'; $params[] = $process; }
        foreach ($this->repository->rows($sql,$params) as $member) if ($this->repository->children((int) $member['id'])!==[]) throw new HttpError('Resuelve primero quién asumirá el equipo directo mediante una transferencia o reasignación.',409);
    }

    private function move(array $container, array $member, ?array $destination, string $mode, ?array $replacement, string $reason): void
    {
        if ($destination && $this->repository->descendant((int) $member['id'],(int) $destination['id'])) throw new InvalidArgumentException('El cambio produciría un ciclo de responsabilidad.');
        if ($mode === 'integrante') $this->handoff($container,$member,$replacement);
        $this->repository->moveTeam((int) $member['id'],isset($destination['id']) ? (int) $destination['id'] : null);
        $this->validateHierarchy((int) $container['id']);
        $this->repository->history((int) $container['id'],(int) $member['id'],'transferencia_' . $mode,['responsible'=>$member['nodo_padre']],['responsible'=>$destination['id'] ?? null],null,$reason);
    }

    private function approver(array $container, array $member, ?array $destination, array $elections, array $affectedPersons): int
    {
        $candidates = [];
        if ($destination) $candidates = $this->repository->rows('SELECT u.id FROM estructura_relaciones origin JOIN estructura_relaciones destination ON destination.ancestro=origin.ancestro JOIN estructura superior ON superior.id=origin.ancestro JOIN usuarios u ON u.persona=superior.persona AND u.activo=1 WHERE origin.descendiente=? AND destination.descendiente=? AND superior.activo=1 ORDER BY origin.profundidad+destination.profundidad',[$member['id'],$destination['id']]);
        $configured = null;
        foreach ($elections as $process) {
            $value = $this->repository->rows('SELECT aprobador FROM procesos_estructura_config WHERE proceso=?',[$process['id']])[0]['aprobador'] ?? null;
            if ($value !== null) {
                if ($configured !== null && $configured!==(int) $value) throw new InvalidArgumentException('Las elecciones compartidas necesitan un aprobador compatible.');
                $configured = (int) $value;
            }
        }
        if ($configured !== null) $candidates = [['id'=>$configured],...$candidates];
        $candidates = [...$candidates,...$this->repository->rows("SELECT u.id FROM usuarios u JOIN usuarios_tipos ut ON ut.id=u.tipo_usuario WHERE ut.codigo='superadmin' AND u.activo=1 ORDER BY u.id")];
        foreach ($candidates as $candidate) {
            try { $this->guard($container,'estructura.aprobar_transferencias',$affectedPersons,(int) $candidate['id']); return (int) $candidate['id']; }
            catch (HttpError $error) { if (!in_array($error->statusCode,[401,403,404],true)) throw $error; }
        }
        throw new InvalidArgumentException('No existe un responsable autorizado para aprobar la transferencia.');
    }

    public function transfer(string $uuid, string $memberUuid, array $data): array
    {
        return $this->transaction(function () use ($uuid,$memberUuid,$data) {
            $container = $this->locked($uuid); $member = $this->repository->member($this->uuidStringToBinary($memberUuid),(int) $container['id']);
            if (!(int) $member['activo']) throw new InvalidArgumentException('El integrante debe estar activo.');
            $mode = $data['mode'] ?? 'integrante';
            if (!in_array($mode,['integrante','equipo'],true)) throw new InvalidArgumentException('Modalidad inválida.');
            $destination = $this->resolveMember($container,$data['destination'] ?? null); $replacement = $this->resolveMember($container,$data['replacement'] ?? null);
            $persons = array_column($this->repository->team((int) $member['id']),'persona');
            $elections = $this->guard($container,'estructura.transferir_' . $mode,[...$persons,...($destination ? [(int) $destination['persona']] : []),...($replacement ? [(int) $replacement['persona']] : [])]);
            $reason = $this->normalizeRequiredText($data['reason'] ?? null,'Indica el motivo de la transferencia.');
            if (mb_strlen($reason)>500) throw new InvalidArgumentException('El motivo es demasiado largo.');
            if ($destination && $this->repository->descendant((int) $member['id'],(int) $destination['id'])) throw new InvalidArgumentException('El cambio produciría un ciclo de responsabilidad.');
            if ($mode==='integrante' && $this->repository->children((int) $member['id'])!==[] && !$replacement) throw new InvalidArgumentException('Selecciona quién asumirá el equipo directo.');
            $approval = false;
            foreach ($elections as $process) $approval = $approval || (int) ($this->repository->rows('SELECT requiere_aprobacion FROM procesos_estructura_config WHERE proceso=?',[$process['id']])[0]['requiere_aprobacion'] ?? 1)===1;
            if (!$approval) { $this->move($container,$member,$destination,$mode,$replacement,$reason); return ['state'=>'aplicada']; }
            $approver = $this->approver($container,$member,$destination,$elections,[...$persons,...($destination ? [(int) $destination['persona']] : []),...($replacement ? [(int) $replacement['persona']] : [])]); $requestUuid = $this->generateUuidBinary();
            $this->repository->execute('INSERT INTO estructura_transferencias (uuid,estructura,integrante,origen,destino,reemplazo,modalidad,version,solicitante,aprobador,motivo,fecha) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())',[$requestUuid,$container['id'],$member['id'],$member['nodo_padre'],$destination['id'] ?? null,$replacement['id'] ?? null,$mode,$container['version'],Auth::id(),$approver,$reason]);
            // Solicitar no altera la versión estructural usada para detectar solicitudes obsoletas.
            $this->repository->execute("INSERT INTO estructura_historial (estructura,integrante,accion,usuario,despues,motivo,fecha) VALUES (?,?,'solicitud_transferencia',?,?,?,NOW())",[$container['id'],$member['id'],Auth::id(),json_encode(['request'=>$this->uuidBinaryToString($requestUuid),'approver'=>$approver],JSON_THROW_ON_ERROR),$reason]);
            (new \App\Services\Common\AuditService(new \App\Repositories\CommonRepository($this->repository->connection())))->record((int) $container['organizacion'],null,'solicitud_transferencia','estructura_transferencias',$this->uuidBinaryToString($requestUuid),null,['solicitante'=>Auth::id(),'aprobador'=>$approver,'version'=>$container['version']]);
            return ['id'=>$this->uuidBinaryToString($requestUuid),'state'=>'pendiente'];
        });
    }

    public function resolveTransfer(string $uuid, string $requestUuid, array $data): void
    {
        $this->transaction(function () use ($uuid,$requestUuid,$data) {
            $container = $this->locked($uuid);
            $request = $this->repository->rows('SELECT * FROM estructura_transferencias WHERE uuid=? AND estructura=? FOR UPDATE',[$this->uuidStringToBinary($requestUuid),$container['id']])[0] ?? null;
            if (!$request) throw new HttpError('La solicitud no está disponible.',404);
            \App\Services\Common\ApprovalState::pending($request['estado']);
            \App\Services\Common\ApprovalState::resolver((int) $request['aprobador'],Auth::id(),Auth::isSuperAdmin());
            $member = $this->repository->rows('SELECT * FROM estructura WHERE id=?',[$request['integrante']])[0];
            $destination = $request['destino'] ? $this->repository->rows('SELECT * FROM estructura WHERE id=?',[$request['destino']])[0] : null;
            $replacement = $request['reemplazo'] ? $this->repository->rows('SELECT * FROM estructura WHERE id=?',[$request['reemplazo']])[0] : null;
            $persons = [...array_column($this->repository->team((int) $member['id']),'persona'),...($replacement ? [(int) $replacement['persona']] : [])];
            $this->guard($container,'estructura.aprobar_transferencias',[...$persons,...($destination ? [(int) $destination['persona']] : [])]);
            $approved = $this->normalizeStatus($data['approved'] ?? null);
            $reason = $this->normalizeRequiredText($data['reason'] ?? null,'Indica el motivo de la resolución.');
            if (mb_strlen($reason)>500) throw new InvalidArgumentException('El motivo es demasiado largo.');
            if ($approved) {
                if ((int) $container['version']!==(int) $request['version']) throw new HttpError('La estructura cambió desde la solicitud. Solicita nuevamente la transferencia.',409);
                $this->guard($container,'estructura.transferir_' . $request['modalidad'],[...$persons,...($destination ? [(int) $destination['persona']] : [])],(int) $request['solicitante']);
                $this->move($container,$member,$destination,$request['modalidad'],$replacement,$request['motivo']);
            }
            $this->repository->execute('UPDATE estructura_transferencias SET estado=?,resuelto_por=?,motivo_resolucion=?,fecha_resolucion=NOW() WHERE id=?',[\App\Services\Common\ApprovalState::decision((bool) $approved),Auth::id(),$reason,$request['id']]);
            $this->repository->history((int) $container['id'],(int) $member['id'],$approved ? 'aprobacion_transferencia' : 'rechazo_transferencia',null,['request'=>$requestUuid],null,$reason);
        });
    }

    public function clone(string $uuid, array $data): array
    {
        return $this->transaction(function () use ($uuid,$data) {
            $source = $this->locked($uuid);
            $this->guard($source,'estructura.administrar',array_column($this->repository->members((int) $source['id'],true),'persona'));
            $created = $this->create($data); $target = $this->repository->container($this->uuidStringToBinary($created['id']));
            $this->repository->execute('UPDATE estructuras_organizacionales SET clonada_de=? WHERE id=?',[$source['id'],$target['id']]);
            $mapping = []; $original = $this->repository->members((int) $source['id']);
            foreach ($original as $member) $mapping[$member['id']] = $this->repository->insert('INSERT INTO estructura (uuid,contenedor,persona,nivel,nombre_nodo,activo,f_asignacion,f_baja) VALUES (?,?,?,1,?,?,?,?)',[$this->generateUuidBinary(),$target['id'],$member['persona'],$member['nombre_nodo'],$member['activo'],$member['f_asignacion'],$member['f_baja']]);
            foreach ($original as $member) if ($member['nodo_padre']!==null) $this->repository->execute('UPDATE estructura SET nodo_padre=? WHERE id=?',[$mapping[$member['nodo_padre']],$mapping[$member['id']]]);
            foreach ($this->repository->rows('SELECT r.* FROM estructura_relaciones r JOIN estructura e ON e.id=r.ancestro WHERE e.contenedor=?',[$source['id']]) as $relation) $this->repository->execute('INSERT INTO estructura_relaciones VALUES (?,?,?)',[$mapping[$relation['ancestro']],$mapping[$relation['descendiente']],$relation['profundidad']]);
            foreach ($this->repository->elections((int) $target['id']) as $process) foreach ($original as $member) {
                // El nivel es una configuración de la nueva elección; la copia de personas y relaciones siempre es completa.
                $levelUuid = $data['levels'][$this->uuidBinaryToString($process['uuid'])][$this->uuidBinaryToString($member['uuid'])] ?? null;
                $sourceLevel = $this->repository->rows('SELECT n.uuid,n.nombre,n.orden,n.activo,n.puede_reclutar,n.permite_mismo_nivel FROM estructura_integrantes_procesos ep JOIN niveles_organizacionales n ON n.id=ep.nivel WHERE ep.integrante=? ORDER BY ep.proceso=? DESC,ep.proceso LIMIT 1',[$member['id'],Auth::processId()])[0] ?? null;
                if ($levelUuid === null && $sourceLevel) {
                    $targetLevel = $this->repository->rows('SELECT uuid FROM niveles_organizacionales WHERE proceso=? AND orden=?',[$process['id'],$sourceLevel['orden']])[0] ?? null;
                    if (!$targetLevel) {
                        (new PermissionRepository($this->repository->connection()))->require(Auth::id() ?? 0,'niveles.administrar',(int) $process['id']);
                        $newUuid = $this->generateUuidBinary();
                        $this->repository->execute('INSERT INTO niveles_organizacionales (uuid,proceso,nombre,orden,activo,puede_reclutar,permite_mismo_nivel,editado_por,fecha) VALUES (?,?,?,?,?,?,?,?,NOW())',[$newUuid,$process['id'],$sourceLevel['nombre'],$sourceLevel['orden'],$sourceLevel['activo'],$sourceLevel['puede_reclutar'],$sourceLevel['permite_mismo_nivel'],Auth::id()]);
                        $targetLevel = ['uuid'=>$newUuid];
                    }
                    $levelUuid = $this->uuidBinaryToString($targetLevel['uuid']);
                }
                $this->projection([...$member,'id'=>$mapping[$member['id']]],(int) $process['id'],$this->level((int) $process['id'],$levelUuid,(bool) $member['activo']));
            }
            foreach ($original as $member) {
                $snapshotLevels = $this->repository->rows('SELECT pe.proceso,n.nombre,n.orden,n.activo,n.puede_reclutar,n.permite_mismo_nivel FROM estructura_integrantes_procesos ep JOIN procesos_electorales pe ON pe.id=ep.proceso LEFT JOIN niveles_organizacionales n ON n.id=ep.nivel WHERE ep.integrante=?',[$member['id']]);
                $this->repository->history((int) $target['id'],$mapping[$member['id']],'clonacion_integrante',null,['source_member'=>$this->uuidBinaryToString($member['uuid']),'person'=>$member['persona'],'responsible'=>$member['nodo_padre'],'active'=>$member['activo'],'levels'=>$snapshotLevels]);
            }
            $this->validateHierarchy((int) $target['id']);
            $this->repository->history((int) $target['id'],null,'clonacion',null,['source'=>$uuid,'members'=>count($mapping)]);
            return $created;
        });
    }

    public function history(string $uuid, array $page): array
    {
        $container = $this->repository->container($this->uuidStringToBinary($uuid)); $process = $this->visible($container);
        // El historial detallado puede contener información de todo el equipo.
        $this->fullScope('estructura.administrar',$process);
        return $this->repository->rows('SELECT h.id,h.accion,h.antes,h.despues,h.motivo,h.fecha,u.usuario FROM estructura_historial h LEFT JOIN usuarios u ON u.id=h.usuario WHERE h.estructura=? ORDER BY h.id DESC LIMIT ' . (int) $page['limit'] . ' OFFSET ' . (int) $page['offset'],[$container['id']]);
    }

    public function requests(string $uuid, array $page): array
    {
        $container = $this->repository->container($this->uuidStringToBinary($uuid)); $this->visible($container);
        return array_map(fn ($row) => ['id'=>$this->uuidBinaryToString($row['uuid']),'state'=>$row['estado'],'mode'=>$row['modalidad'],'reason'=>$row['motivo'],'date'=>$row['fecha'],'can_resolve'=>Auth::isSuperAdmin() || (int) $row['aprobador']===Auth::id()],$this->repository->rows('SELECT * FROM estructura_transferencias WHERE estructura=? AND (solicitante=? OR aprobador=? OR ?=1) ORDER BY id DESC LIMIT ' . (int) $page['limit'] . ' OFFSET ' . (int) $page['offset'],[$container['id'],Auth::id(),Auth::id(),Auth::isSuperAdmin() ? 1 : 0]));
    }
}
