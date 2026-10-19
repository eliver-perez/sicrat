<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\Authorization;
use App\Core\Service;
use App\Repositories\UserAccessRepository;
use App\Repositories\PersonIdentityRepository;
use App\Repositories\ElectoralContextRepository;
use InvalidArgumentException;

class UserAccessService extends Service
{
    public function __construct(private UserAccessRepository $repository) {}
    public function detail(string $uuid): array
    {
        Authorization::requireOperation('users.read');
        $user = $this->repository->user($this->uuidStringToBinary($uuid));
        return ['person' => $user['persona_uuid'] ? $this->uuidBinaryToString($user['persona_uuid']) : null, 'person_code' => $user['codigo_persona'],
            'accesses' => array_map(fn ($row) => ['process' => $this->uuidBinaryToString($row['uuid']), 'name' => $row['proceso'], 'type' => (int) $row['tipo_usuario'], 'type_name' => $row['tipo'], 'active' => (int) $row['activo']], $this->repository->accesses((int) $user['id']))];
    }
    public function assign(string $uuid, array $data): void
    {
        Authorization::requireOperation('users.create');
        $user = $this->repository->user($this->uuidStringToBinary($uuid));
        if ($user['organizacion'] === null) throw new InvalidArgumentException('La cuenta necesita una organización para recibir accesos electorales.');
        $processUuid = $data['process'] ?? null;
        if (!is_string($processUuid)) throw new InvalidArgumentException('Selecciona una elección.');
        $active = $this->normalizeStatus($data['active'] ?? 1);
        $process = (new ElectoralContextRepository($this->repository->connection()))->authorized($this->uuidStringToBinary($processUuid), (int) $user['organizacion'], $active === 1);
        $type = $this->normalizeRequiredInt($data['type'] ?? null, 'Selecciona un tipo de usuario.');
        $typeCode = $this->repository->typeCode($type);
        if ($typeCode === 'superadmin' && $user['tipo_codigo'] !== 'superadmin') throw new InvalidArgumentException('Super Administrador es un tipo global de cuenta, no un acceso electoral.');
        if ($typeCode === null) throw new InvalidArgumentException('Tipo de usuario inválido.');
        $db = $this->repository->connection();
        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) $db->beginTransaction();
        try {
            $db->prepare('SELECT id FROM organizaciones WHERE id=? FOR UPDATE')->execute([$user['organizacion']]);
            $permissions = new \App\Repositories\PermissionRepository($db);
            $manager = $permissions->require(\App\Core\Auth::id() ?? 0,'usuarios.administrar',(int) $process['id']);
            (new \App\Repositories\AccountScopeRepository($db))->assertUser((int) $user['id'],'usuarios.administrar',(int) $process['id']);
            $role = $db->prepare('SELECT organizacion FROM usuarios_tipos WHERE id=?'); $role->execute([$type]); $roleOrganization = $role->fetchColumn();
            if ($roleOrganization !== null && (int) $roleOrganization !== (int) $user['organizacion']) throw new InvalidArgumentException('El rol pertenece a otra organización.');
            try { $before = $permissions->effective((int) $user['id'],(int) $process['id']); } catch (\App\Core\HttpError) { $before = ['super'=>false,'rights'=>[],'scopes'=>[]]; }
            $this->repository->assign((int) $user['id'], (int) $process['id'], $type, $active);
            try { $after = $permissions->effective((int) $user['id'],(int) $process['id']); } catch (\App\Core\HttpError) { $after = ['super'=>false,'rights'=>[],'scopes'=>[]]; }
            if ($active && !$manager['super']) foreach (\App\Repositories\PermissionRepository::delegableRights($after['rights']) as $permission) {
                $newScopes = array_diff($after['scopes'][$permission],$before['scopes'][$permission] ?? []);
                if (in_array($permission,$before['rights'],true) && $newScopes===[]) continue;
                if (!in_array($permission,$manager['rights'],true)) throw new \App\Core\HttpError('No puedes asignar un rol con permisos superiores a los tuyos.',403);
                foreach ($newScopes as $scope) if (!in_array('organizacion',$manager['scopes'][$permission],true) && !in_array($scope,$manager['scopes'][$permission],true)) throw new \App\Core\HttpError('No puedes asignar un rol con mayor alcance.',403);
            }
            if (!$active || (($before['super'] || in_array('estructura.formar',$before['rights'],true)) && !$after['super'] && !in_array('estructura.formar',$after['rights'],true))) (new StructureService(new \App\Repositories\StructureRepository($db)))->withdrawCapacity((int) $user['id'],(int) $process['id']);
            (new \App\Services\Common\AuditService(new \App\Repositories\CommonRepository($db)))->record((int) $user['organizacion'],(int) $process['id'],'acceso_electoral','usuarios',(int) $user['id'],['rights'=>$before['rights'],'scopes'=>$before['scopes']],['tipo'=>$type,'activo'=>$active,'rights'=>$after['rights'],'scopes'=>$after['scopes']]);
            if ($ownsTransaction) $db->commit();
        } catch (\Throwable $error) { if ($ownsTransaction && $db->inTransaction()) $db->rollBack(); throw $error; }
    }
    public function link(string $uuid, ?string $personUuid): void
    {
        Authorization::requireOperation('users.create');
        $user = $this->repository->user($this->uuidStringToBinary($uuid));
        $personId = null;
        if ($personUuid !== null && $personUuid !== '') {
            $person = (new PersonIdentityRepository($this->repository->connection()))->person($this->uuidStringToBinary($personUuid));
            if ($user['organizacion'] === null || (int) $person['organizacion'] !== (int) $user['organizacion']) throw new InvalidArgumentException('La persona y la cuenta deben pertenecer a la misma organización.');
            $personId = (int) $person['id'];
        }
        $db = $this->repository->connection(); $db->beginTransaction();
        try {
            if ($user['organizacion'] !== null) $db->prepare('SELECT id FROM organizaciones WHERE id=? FOR UPDATE')->execute([$user['organizacion']]);
            foreach ($this->repository->accesses((int) $user['id']) as $access) {
                if (!(int) $access['activo']) continue;
                $target = (new ElectoralContextRepository($db))->authorized($access['uuid'],$user['organizacion'] === null ? null : (int) $user['organizacion']);
                (new \App\Repositories\AccountScopeRepository($db))->assertUser((int) $user['id'],'usuarios.administrar',(int) $target['id']);
                if ($personId !== null) (new \App\Repositories\ScopeRepository($db))->assertPerson($personId,'usuarios.administrar',(int) $target['id']);
            }
            if ($personId !== null) {
                (new \App\Repositories\ScopeRepository($db))->assertPerson($personId,'usuarios.administrar',\App\Core\Auth::processId());
                $statement = $db->prepare('SELECT id FROM usuarios WHERE persona=? AND id<>? FOR UPDATE'); $statement->execute([$personId,$user['id']]);
                if ($statement->fetchColumn()) throw new \App\Core\HttpError('Esta persona ya tiene una cuenta SICRAT.',409);
            }
            if ($user['persona'] !== null && (int) $user['persona']!==$personId) (new StructureService(new \App\Repositories\StructureRepository($db)))->withdrawCapacity((int) $user['id']);
            $this->repository->link((int) $user['id'], $personId);
            (new \App\Services\Common\AuditService(new \App\Repositories\CommonRepository($db)))->record($user['organizacion']===null ? null : (int) $user['organizacion'],null,'vincular_persona','usuarios',(int) $user['id'],['persona'=>$user['persona']],['persona'=>$personId]);
            $db->commit();
        } catch (\PDOException $error) {
            if ($db->inTransaction()) $db->rollBack();
            if (($error->errorInfo[1] ?? 0)===1062) throw new \App\Core\HttpError('Esta persona ya tiene una cuenta SICRAT.',409);
            throw $error;
        } catch (\Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    }

    public function state(string $uuid, array $data): void
    {
        Authorization::requireOperation('users.create');
        $user = $this->repository->user($this->uuidStringToBinary($uuid));
        if (!\App\Core\Auth::isSuperAdmin()) throw new \App\Core\HttpError('La deshabilitación global de cuentas corresponde al Super Administrador. Puedes retirar su acceso a esta elección.',403);
        $active = $this->normalizeStatus($data['active'] ?? null);
        if ((int) $user['id']===\App\Core\Auth::id() && !$active) throw new InvalidArgumentException('No puedes deshabilitar tu propia cuenta.');
        $db=$this->repository->connection(); $db->beginTransaction();
        try {
            if ($user['organizacion'] !== null) $db->prepare('SELECT id FROM organizaciones WHERE id=? FOR UPDATE')->execute([$user['organizacion']]);
            if (!$active) (new StructureService(new \App\Repositories\StructureRepository($db)))->withdrawCapacity((int) $user['id']);
            $db->prepare('UPDATE usuarios SET activo=?,f_actualizacion=NOW() WHERE id=?')->execute([$active,$user['id']]);
            if ($user['organizacion']!==null) (new \App\Services\Operations\MessagingService(new \App\Repositories\CommonRepository($db)))->synchronizeOrganization((int) $user['organizacion']);
            (new \App\Services\Common\AuditService(new \App\Repositories\CommonRepository($db)))->record($user['organizacion']===null ? null : (int) $user['organizacion'],null,'estado','usuarios',(int) $user['id'],['activo'=>$user['activo']],['activo'=>$active]);
            $db->commit();
        } catch (\Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    }
}
