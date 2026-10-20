<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Service;
use App\Auth\Authorization;
use App\Core\Security\PasswordPolicy;
use App\Repositories\UsersRepository;
use App\Repositories\OrganizationsRepository;
use App\Repositories\ElectoralProcessRepository;
use InvalidArgumentException;
use RuntimeException;

class UsersService extends Service
{
    public function __construct(
        private UsersRepository $usersRepository,
        private OrganizationsRepository $organizationsRepository,
        private ElectoralProcessRepository $electoralProcessRepository
    ) {
    }

    public function getAll(array $data): array {
        try {
            $uid = $this->normalizeRequiredInt($data['uid'] ?? null, 'No existe una sesion activa.');

            $data = $this->usersRepository->getAll([
                'search'                        => $data['search'] !== '' ? $data['search'] : null,
                'limit'                         => $data['limit'],
                'offset'                        => $data['offset'],
                'status'                        => $data['status']
            ]);
            $users = array();

            foreach($data as $d) {
                array_push($users, array(
                    'id'                        => $this->uuidBinaryToString($d['uuid']),
                    'name'                      => $d['nombre'] ?? '',
                    'username'                  => $d['usuario'] ?? '',
                    'email'                     => $d['email'] ?? '',
                    'type'                      => $d['tipo'] ?? '',
                    'active'                    => $d['activo'] ?? 0,
                    'registered_date'           => $d['f_registro'] ?? '',
                    'last_active_date'          => $d['f_ultima_conexion'] ?? ''
                ));
            }

            return $users;
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    public function create(array $data): ?array {
        Authorization::requireOperation('users.create');
        $uid = $this->normalizeRequiredInt($data['uid'] ?? null, 'No existe una sesion activa.');

        $username = $this->normalizeRequiredText($data['username'] ?? null, 'Es necesario capturar un usuario.');
        $password = PasswordPolicy::newPassword($data['password'] ?? null);
        $organization = $this->normalizeOptionalText($data['organization'] ?? null);

        $process = $this->normalizeOptionalText($data['process'] ?? null);

        $email = $this->normalizeOptionalText($data['email'] ?? null);
        $status = $this->normalizeStatus($data['status'] ?? 1);

        $name = $this->normalizeRequiredText($data['name'] ?? null, 'Es necesario capturar el nombre.');
        $last_name = $this->normalizeOptionalText($data['last_name'] ?? null);
        $second_last_name = $this->normalizeOptionalText($data['second_last_name'] ?? null);
        foreach ([[$username,60],[$name,40],[$email ?? '',150]] as [$value,$maximum]) if (mb_strlen($value)>$maximum) throw new InvalidArgumentException('El usuario, nombre o correo supera la longitud permitida.');
        foreach ([$last_name,$second_last_name] as $value) if ($value !== null && mb_strlen($value)>40) throw new InvalidArgumentException('El apellido supera la longitud permitida.');

        $user_exists = $this->usersRepository->userExists($username);
        if($user_exists)
            throw new InvalidArgumentException("El nombre de usuario ya existe.");

        if ($email !== null) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('El correo electrónico no es válido.');
            }
        }

        $password_hash = $this->encrypt_hash($password);

        $organizationId = null;
        $processId = null;
        if ($organization !== null && $organization !== 'N/A') {
            $organizationId = $this->organizationsRepository->getOrganizationId([
                'uuid' => $this->uuidStringToBinary($organization)
            ]);
            if ($organizationId === null) {
                throw new InvalidArgumentException('La organización no existe.');
            }
        }
        if ($process !== null && $process !== 'N/A') {
            $processId = $this->electoralProcessRepository->getElectoralProcessId([
                'uuid' => $this->uuidStringToBinary($process)
            ]);
            if ($organizationId === null || $processId === null
                || !$this->electoralProcessRepository->belongsToOrganization($processId, $organizationId)) {
                throw new InvalidArgumentException('El proceso no pertenece a la organización seleccionada.');
            }
        }
        $registerProcess = $processId !== null;

        $conn = $this->usersRepository->getConnection();
        if (!\App\Core\Auth::isSuperAdmin()) $organizationId ??= \App\Core\Auth::organizationId();
        $conn->beginTransaction();
        try {
            if ($organizationId !== null) $conn->prepare('SELECT id FROM organizaciones WHERE id=? FOR UPDATE')->execute([$organizationId]);
            $managerPermissions = (new \App\Repositories\PermissionRepository($conn))->require(\App\Core\Auth::id() ?? 0,'usuarios.administrar',$processId ?? \App\Core\Auth::processId());
            if (!$managerPermissions['super'] && empty($data['person']) && array_intersect(['eleccion','organizacion'],$managerPermissions['scopes']['usuarios.administrar'])===[]) throw new \App\Core\HttpError('Selecciona una persona dentro de tu alcance para crear su cuenta.',403);
            if (isset($data['person']) && $data['person'] !== '') {
                $person = (new \App\Repositories\PersonIdentityRepository($conn))->person($this->uuidStringToBinary((string) $data['person']),true);
                if ($organizationId === null || (int) $person['organizacion']!==$organizationId) throw new InvalidArgumentException('La persona debe pertenecer a la organización de la cuenta.');
                (new \App\Repositories\ScopeRepository($conn))->assertPerson((int) $person['id'],'usuarios.administrar',$processId ?? \App\Core\Auth::processId());
                $existing=$conn->prepare('SELECT id FROM usuarios WHERE persona=? FOR UPDATE'); $existing->execute([$person['id']]);
                if ($existing->fetchColumn()) throw new \App\Core\HttpError('Esta persona ya tiene una cuenta SICRAT.',409);
            }
            $derivedRole = null;
            if (isset($person)) {
                $targetProcess = $processId ?? \App\Core\Auth::processId();
                if ($targetProcess !== null) {
                    $derivedRole = (new LevelRoleService($conn))->roleForPerson(
                        (int) $person['id'],
                        $targetProcess
                    );
                }
                if ($derivedRole !== null && $processId === null) {
                    $processId = $targetProcess;
                    $registerProcess = true;
                }
            }
            $user_type = $derivedRole ?? $this->normalizeRequiredInt($data['user_type'] ?? null, 'Es necesario seleccionar el tipo de usuario.');
            if (!$this->usersRepository->userTypeExists($user_type)) throw new InvalidArgumentException('El tipo de usuario no es válido.');
            $roleOwner=$conn->prepare('SELECT organizacion FROM usuarios_tipos WHERE id=?'); $roleOwner->execute([$user_type]); $typeOrganization=$roleOwner->fetchColumn();
            if ($typeOrganization !== null && (int) $typeOrganization!==($organizationId ?? \App\Core\Auth::organizationId())) throw new InvalidArgumentException('El rol pertenece a otra organización.');
            if (!\App\Core\Auth::isSuperAdmin()) {
                $organizationId ??= \App\Core\Auth::organizationId();
                if ($organizationId === null) throw new InvalidArgumentException('La cuenta debe pertenecer a una organización.');
                Authorization::assertOrganization($organizationId);
                $role = $conn->prepare('SELECT codigo,organizacion FROM usuarios_tipos WHERE id=?'); $role->execute([$user_type]); $typeRow=$role->fetch(\PDO::FETCH_ASSOC);
                if (!$typeRow || $typeRow['codigo']==='superadmin' || ($typeRow['organizacion']!==null && (int) $typeRow['organizacion']!==$organizationId)) throw new InvalidArgumentException('El rol no está disponible para esta organización.');
                (new \App\Repositories\PermissionRepository($conn))->require(\App\Core\Auth::id() ?? 0,'usuarios.administrar',$processId ?? \App\Core\Auth::processId());
            }
            $userUuid = $this->generateUuidBinary();
            $userId = $this->usersRepository->insertUser([
                    'uuid'                          => $userUuid,
                    'organization'                  => $organizationId,
                    'username'                      => $username,
                    'email'                         => $email,
                    'name'                          => $name,
                    'last_name'                     => $last_name,
                    'second_last_name'              => $second_last_name,
                    'password'                      => $password_hash,
                    'status'                        => $status,
                    'user_type'                     => $user_type,
                    'uid'                           => $uid,
                ]);
            
            if (isset($person)) $conn->prepare('UPDATE usuarios SET persona=? WHERE id=?')->execute([$person['id'],$userId]);
            if($registerProcess) {
                $this->usersRepository->insertUserProcess([
                    'user'                          => $userId,
                    'process'                       => $processId,
                    'user_type'                     => $user_type
                ]);
            }
            // El rol automático proviene exclusivamente del nivel validado en servidor.
            // La comparación de privilegios sigue aplicándose a la selección manual.
            $hasManualRole = $derivedRole === null;
            if (!\App\Core\Auth::isSuperAdmin() && $hasManualRole) {
                $permissions = new \App\Repositories\PermissionRepository($conn);
                $base = $permissions->effective($userId, null);
                $managerGlobal = $permissions->effective(\App\Core\Auth::id(), null);

                foreach ($base['rights'] as $permission) {
                    if (!in_array($permission, $managerGlobal['rights'], true)) {
                        throw new \App\Core\HttpError(
                            'No puedes asignar un tipo de cuenta con permisos globales superiores a los tuyos.',
                            403
                        );
                    }
                }

                if ($processId !== null) {
                    $manager = $permissions->effective(\App\Core\Auth::id(), $processId);
                    $assigned = $permissions->effective($userId, $processId);

                    foreach (\App\Repositories\PermissionRepository::delegableRights($assigned['rights']) as $permission) {
                        if (!in_array($permission, $manager['rights'], true)) {
                            throw new \App\Core\HttpError('No puedes asignar un rol con permisos superiores a los tuyos.', 403);
                        }
                        foreach ($assigned['scopes'][$permission] as $scope) {
                            $managerScopes = $manager['scopes'][$permission];
                            if (!in_array('organizacion', $managerScopes, true) && !in_array($scope, $managerScopes, true)) {
                                throw new \App\Core\HttpError('No puedes asignar un rol con mayor alcance.', 403);
                            }
                        }
                    }
                }
            }
            (new \App\Services\Common\AuditService(new \App\Repositories\CommonRepository($conn)))->record($organizationId,$processId,'alta','usuarios',$userId,null,['tipo'=>$user_type,'activo'=>$status,'persona'=>$person['id'] ?? null]);
            if ($organizationId!==null) (new \App\Services\Operations\MessagingService(new \App\Repositories\CommonRepository($conn)))->synchronizeOrganization($organizationId);
            $conn->commit();
            return [
                'id'                            => $this->uuidBinaryToString($userUuid)
            ];
        } catch (\Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }
}
